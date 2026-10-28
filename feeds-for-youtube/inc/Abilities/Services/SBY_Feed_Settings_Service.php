<?php

namespace SmashBalloon\YouTubeFeed\Abilities\Services;

use SmashBalloon\YouTubeFeed\Abilities\Abilities\SBY_Update_Feed_Settings;
use SmashBalloon\YouTubeFeed\Abilities\SBY_Pro_Upsell;
use SmashBalloon\YouTubeFeed\Builder\SBY_Db;
use SmashBalloon\YouTubeFeed\Builder\SBY_Feed_Saver;
use SmashBalloon\YouTubeFeed\Builder\SBY_Feed_Templates_Settings;
use SmashBalloon\YouTubeFeed\Helpers\Util;
use SmashBalloon\YouTubeFeed\SBY_Cache;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Reads and writes Feed Builder feeds for the Abilities API.
 *
 * Wraps SBY_Db / SBY_Feed_Saver and REDACTS everything an agent must not see:
 * connected accounts live in `sby_settings['connected_accounts']` and embed
 * access/refresh tokens (see SBY_Source_Service), so this service resolves a
 * feed's channel ids to that array itself and never returns the raw entries.
 *
 * Ported from Instagram Feed's SBI_Feed_Settings_Service (AgDR-0503).
 *
 * @since SBY_ABILITIES_SINCE
 */
class SBY_Feed_Settings_Service
{
	const MAX_PER_PAGE = 50;

	/**
	 * Per-feed keys whose change alters which videos are fetched from YouTube.
	 *
	 * @var string[]
	 */
	const RETRIEVAL_KEYS = array('num', 'nummobile', 'sortby', 'type', 'channel', 'id', 'showpast', 'imageres');

	/**
	 * Every stored key any `type` can use to hold its source value, across all
	 * six types. AgDR-9004 requirement 2: `type` plus these keys only work as a
	 * MATCHED SET — the plugin's own resolution order
	 * (SBY_Settings_Pro::set_feed_type_and_terms()) checks several of them in a
	 * fixed priority per type (e.g. for type=channel, `id` wins over `channel`;
	 * for type=favorites, `id` wins over `favorites` wins over `channel`).
	 * set_feed_type_and_source() always writes ONLY the first-priority key for
	 * the chosen type and clears every other key in this list, so the stored
	 * row can never have two source keys disagreeing about what the feed shows
	 * (the exact class of bug behind Louis's A1 finding — set-feed-sources
	 * silently left `channel` set while writing `id`, so `channel` write
	 * "succeeded" while `id` kept winning at render time).
	 *
	 * @var string[]
	 */
	const SOURCE_KEYS = array(
		'id', 'channel', 'favorites', 'live', 'playlist', 'search', 'single', 'usecustomsearch', 'customsearch',
	);

	/**
	 * Per-type source resolution. `account` types (channel/favorites/live) take
	 * a list of ALREADY-CONNECTED channel handles/ids, resolved through
	 * SBY_Source_Service the same way the original set-feed-sources ability
	 * did; `raw` types (playlist/search/single) take a free-text `terms`
	 * string the plugin uses directly (no connected-account concept applies).
	 * `store_key` is the FIRST-PRIORITY stored key SBY_Settings_Pro checks for
	 * that type — writing there and clearing every other SOURCE_KEYS entry
	 * guarantees the resolution order can never surface a stale sibling value.
	 *
	 * @var array<string, array{kind: string, store_key: string}>
	 */
	const SOURCE_TYPES = array(
		'channel'   => array('kind' => 'account', 'store_key' => 'id'),
		'favorites' => array('kind' => 'account', 'store_key' => 'id'),
		'live'      => array('kind' => 'account', 'store_key' => 'id'),
		'playlist'  => array('kind' => 'raw', 'store_key' => 'playlist'),
		'search'    => array('kind' => 'raw', 'store_key' => 'search'),
		'single'    => array('kind' => 'raw', 'store_key' => 'single'),
	);

	/**
	 * Licence-tier feature each feed `type` requires, per
	 * \Smashballoon\Customizer\YouTube_License_Tier::features_list(). `channel`
	 * is intentionally absent -- it's on the free tier, so no check applies.
	 * The UI locks these same types behind the same features (Customize_Tab /
	 * Settings_Tab's `checkExtensionPopup`); set-feed-type-and-source had no
	 * equivalent check, so a Basic licence could configure a Plus/Elite feed
	 * type through the agent path (SBR-195 review, planner P4/B3).
	 *
	 * @var array<string, string>
	 */
	const TYPE_TIER_FEATURES = array(
		'favorites' => 'favorites_feeds',
		'playlist'  => 'playlist_feeds',
		'single'    => 'single_feeds',
		'search'    => 'search_feeds',
		'live'      => 'live_feeds',
	);

	/**
	 * Combining more than one source into a single feed is a licence-gated
	 * OPERATION (not an optional input with a safe default, unlike
	 * `feedtemplate` above) -- \Smashballoon\Customizer\YouTube_License_Tier
	 * ::features_list()'s `combine_feeds` (basic tier+). Both create-feed
	 * and set-feed-type-and-source accepted up to 10 sources
	 * (SBY_Create_Feed / SBY_Set_Feed_Sources input schemas) with no licence
	 * check at all. Per the family rule (Rex, via the Twitter/TikTok ports;
	 * TikTok's `combine_feed_sources` is the same shape), a gated OPERATION
	 * returns 403 sby_tier_locked before any write, unlike the drop+warn
	 * treatment `feedtemplate` gets.
	 *
	 * @var string
	 */
	const MULTI_SOURCE_TIER_FEATURE = 'combine_feeds';

	/**
	 * 403 for attaching more than one source without the combine_feeds
	 * feature. Shared by create_feed() and set_feed_type_and_source() so the
	 * error code/message can't drift between the two entry points.
	 *
	 * @return \WP_Error
	 */
	private static function multiple_sources_tier_error()
	{
		if (SBY_Pro_Upsell::is_free()) {
			// Free: the edition is the cause, and a feed has exactly one source here. Name the
			// feature in words, not by its internal licence slug (the hint carries the upgrade URL).
			return new \WP_Error(
				'sby_tier_locked',
				__('A feed in this edition has one source, so more than one cannot be added.', 'feeds-for-youtube')
					. self::hint_suffix(SBY_Pro_Upsell::hint_for_multiple_sources()),
				array('status' => 403)
			);
		}

		return new \WP_Error(
			'sby_tier_locked',
			sprintf(
				/* translators: %s: the gated feature's internal name, e.g. combine_feeds */
				__('Combining more than one source into a feed needs a higher licence tier (%s). Upgrade your license, or ask a site admin to.', 'feeds-for-youtube'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
				self::MULTI_SOURCE_TIER_FEATURE
			),
			array('status' => 403)
		);
	}

	/**
	 * A Pro upgrade hint as an error-message suffix (AgDR-9006): ' <hint>', or ''
	 * when there is none (Pro edition, or a refusal that is not Pro-caused).
	 *
	 * @param string $hint From SBY_Pro_Upsell, '' when none.
	 * @return string
	 */
	private static function hint_suffix($hint)
	{
		return $hint === '' ? '' : ' ' . $hint;
	}

	/**
	 * Like hint_suffix(), but closes the sentence the message ended without a full
	 * stop ("... one of: channel" + hint): '. <hint>', or '' when there is no hint.
	 *
	 * @param string $hint From SBY_Pro_Upsell, '' when none.
	 * @return string
	 */
	private static function pro_refusal_suffix($hint)
	{
		return $hint === '' ? '' : '. ' . $hint;
	}

	/**
	 * Feed templates the builder ships (SBY_Feed_Templates_Settings).
	 *
	 * @var string[]
	 */
	const FEED_TEMPLATES = array(
		'default',
		'carousel',
		'cards',
		'list',
		'gallery',
		'latest_video',
		'showcase_carousel',
		'widget',
	);

	/**
	 * Source types the running edition can set (AgDR-9005). Free has `channel`
	 * only: SBY_Settings::set_feed_type_and_terms() resolves nothing else, and
	 * the builder offers the rest as upsells. Pro keeps SOURCE_TYPES whole.
	 *
	 * @param bool|null $is_pro Edition override; null = the running edition.
	 * @return array<string, array{kind: string, store_key: string}>
	 */
	public static function source_types($is_pro = null)
	{
		$is_pro = $is_pro === null ? Util::isPro() : (bool) $is_pro;
		return $is_pro ? self::SOURCE_TYPES : array_intersect_key(self::SOURCE_TYPES, array('channel' => true));
	}

	/**
	 * Feed templates the running edition applies. Free applies `default` only:
	 * the builder skips template selection without `feeds_templates`. The
	 * create-feed INPUT still accepts the full FEED_TEMPLATES list, so a
	 * non-default request is dropped to default WITH a warning (the plugin-side
	 * refusal AgDR-9006 hangs its upgrade hint on) instead of a bare 400.
	 *
	 * @param bool|null $is_pro Edition override; null = the running edition.
	 * @return string[]
	 */
	public static function feed_templates($is_pro = null)
	{
		$is_pro = $is_pro === null ? Util::isPro() : (bool) $is_pro;
		return $is_pro ? self::FEED_TEMPLATES : array('default');
	}

	/**
	 * Feed-schema defaults, layered over SBY_Feed_Saver::settings_defaults().
	 *
	 * That method is missing several keys SBY_Settings_Schema::feed_settings()
	 * exposes (feedtemplate, cols, colsmobile, headerstyle, customheadertext,
	 * colorpalette, subscribehovercolor, buttonhovercolor). Without a fallback,
	 * cast_out() would treat a feed that never wrote one of those keys as
	 * missing and fall back to `0` / the enum's first entry — e.g. `cols`
	 * reports `1` when the plugin actually renders the built-in default of
	 * `3` — a read-then-write hazard: an agent reading `cols: 1` and writing
	 * back "just add a header" would silently narrow the feed to one column.
	 *
	 * The correct values below are the plugin's own literals from
	 * SBY_Settings::get_public_db_settings_keys() — that method returns only
	 * `array_keys($public)`, so the values it holds are not reachable through
	 * a public API and are reproduced here instead of called.
	 * settings_defaults() values win wherever both tables define a key.
	 *
	 * @return array<string, mixed>
	 */
	private static function feed_defaults()
	{
		$supplemental = array(
			'feedtemplate'        => 'default',
			'cols'                => 3,
			'colsmobile'          => 2,
			'headerstyle'         => 'standard',
			'customheadertext'    => __('We are on YouTube', 'feeds-for-youtube'),
			'colorpalette'        => 'inherit',
			'subscribehovercolor' => '',
			'buttonhovercolor'    => '',
			// The per-type source keys (AgDR-9004 requirement 2 exposure) default to
			// '' — a feed only ever has ONE of these populated, matching whichever
			// `type` it currently has (SBY_Settings_Pro::set_feed_type_and_terms()).
			'playlist'            => '',
			'favorites'           => '',
			'search'              => '',
			'live'                => '',
			'single'              => '',
		);

		return array_merge($supplemental, SBY_Feed_Saver::settings_defaults());
	}

	/**
	 * List feeds, paginated.
	 *
	 * @param int    $page 1-based.
	 * @param int    $per_page Videos per page.
	 * @param string $status publish|draft|trash|all (default publish, matching WPForms list-forms).
	 * @return array{feeds: array, total: int, page: int, per_page: int, total_pages: int}
	 */
	public function list_feeds($page = 1, $per_page = 20, $status = 'publish')
	{
		global $wpdb;

		$page     = max(1, (int) $page);
		$per_page = min(self::MAX_PER_PAGE, max(1, (int) $per_page));
		$offset   = ($page - 1) * $per_page;
		$table    = $wpdb->prefix . 'sby_feeds';

		$status = in_array($status, array('publish', 'draft', 'trash', 'all'), true) ? $status : 'publish';
		// Table name is $wpdb->prefix + a literal, never user input; $sql below is
		// always the output of $wpdb->prepare() in one of these two branches.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		if ($status === 'all') {
			$sql = $wpdb->prepare(
				"SELECT id, feed_name, feed_title, settings, status, last_modified
				FROM {$table} ORDER BY id ASC LIMIT %d OFFSET %d",
				$per_page,
				$offset
			);
		} else {
			$sql = $wpdb->prepare(
				"SELECT id, feed_name, feed_title, settings, status, last_modified
				FROM {$table} WHERE status = %s ORDER BY id ASC LIMIT %d OFFSET %d",
				$status,
				$per_page,
				$offset
			);
		}
		$rows = $wpdb->get_results($sql, ARRAY_A);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared

		$feeds = array();
		foreach ((array) $rows as $row) {
			$settings = json_decode($row['settings'], true);
			$settings = is_array($settings) ? $settings : array();
			$feeds[]  = array(
				'id'            => (int) $row['id'],
				'feed_name'     => (string) $row['feed_name'],
				'feed_title'    => (string) $row['feed_title'],
				'type'          => isset($settings['type']) ? (string) $settings['type'] : 'channel',
				'layout'        => isset($settings['layout']) ? (string) $settings['layout'] : 'grid',
				'status'        => (string) $row['status'],
				'last_modified' => (string) $row['last_modified'],
				'shortcode'     => '[youtube-feed feed=' . (int) $row['id'] . ']',
			);
		}

		if ($status === 'all') {
			$total = SBY_Db::feeds_count();
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is $wpdb->prefix + constant.
			$total = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE status = %s", $status));
		}

		return array(
			'feeds'       => $feeds,
			'total'       => $total,
			'page'        => $page,
			'per_page'    => $per_page,
			'total_pages' => (int) ceil($total / $per_page),
		);
	}

	/**
	 * Read one feed, redacted, by id.
	 *
	 * @param int $feed_id Feed id.
	 * @return array|null Null when the feed does not exist.
	 */
	public function get_feed($feed_id)
	{
		$feed_id = (int) $feed_id;
		if ($feed_id <= 0) {
			return null;
		}

		$rows = SBY_Db::feeds_query(array('id' => $feed_id));
		if (empty($rows)) {
			return null;
		}
		$row     = $rows[0];
		$stored  = json_decode($row['settings'], true);
		$stored  = is_array($stored) ? $stored : array();

		$defaults = self::feed_defaults();
		$schema   = SBY_Settings_Schema::feed_settings();
		$out      = array();
		foreach ($schema as $key => $def) {
			$out[$key] = SBY_Settings_Schema::cast_out(
				$def,
				isset($stored[$key]) ? $stored[$key] : null,
				isset($defaults[$key]) ? $defaults[$key] : null
			);
		}
		$out['feed_name'] = (string) $row['feed_name'];

		return array(
			'id'            => $feed_id,
			'feed_title'    => (string) $row['feed_title'],
			'type'          => isset($stored['type']) ? (string) $stored['type'] : 'channel',
			'status'        => (string) $row['status'],
			'last_modified' => (string) $row['last_modified'],
			'shortcode'     => '[youtube-feed feed=' . $feed_id . ']',
			'sources'       => $this->redact_sources($this->channel_ids_from_stored($stored)),
			'settings'      => $out,
			'warnings'      => $this->downgrade_warnings($out),
		);
	}

	/**
	 * A5: cta='link' with an empty linkurl is a reachable-but-inert state (the
	 * button renders with nowhere to go). Only reported where the edition
	 * exposes the key: a Pro cta stored on a downgraded feed is not something a
	 * free caller can act on.
	 *
	 * @param array $schema   Edition-filtered feed_settings() table.
	 * @param array $settings Resulting settings row.
	 * @return string[]
	 */
	private static function cta_link_warnings(array $schema, array $settings)
	{
		$cta     = isset($settings['cta']) ? $settings['cta'] : '';
		$linkurl = isset($settings['linkurl']) ? $settings['linkurl'] : '';
		if (!isset($schema['cta']) || $cta !== 'link' || trim((string) $linkurl) !== '') {
			return array();
		}
		return array(
			__(
				"cta is 'link' but linkurl is empty — the call-to-action button will have no destination link.", // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
				'feeds-for-youtube'
			),
		);
	}

	/**
	 * Notes for a feed that stores a Pro value this edition does not apply
	 * (AgDR-9005 downgrade rule): the read returns the stored truth, and this
	 * says what the running edition actually renders. Always empty on Pro.
	 *
	 * @param array $settings Cast per-feed settings as returned by get_feed().
	 * @return string[]
	 */
	private function downgrade_warnings(array $settings)
	{
		if (Util::isPro()) {
			return array();
		}
		$warnings = array();
		if (isset($settings['type']) && $settings['type'] !== 'channel') {
			$warnings[] = sprintf(
				/* translators: %s: the stored feed type, e.g. playlist */
				__(
					'This feed is stored with type "%s", which needs YouTube Feed Pro. This edition fetches the feed from its channel source instead.', // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
					'feeds-for-youtube'
				),
				$settings['type']
			);
		}
		if (isset($settings['layout']) && $settings['layout'] === 'carousel') {
			$warnings[] = __(
				'This feed is stored with the carousel layout, which needs YouTube Feed Pro. It cannot be set from this edition; choose grid, gallery or list to change it.', // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
				'feeds-for-youtube'
			);
		}
		return $warnings;
	}

	/**
	 * Resolve a feed's connected-account channel ids from its stored settings.
	 *
	 * @param array $stored Raw stored feed settings (json_decode of the settings column).
	 * @return string[] Connected-account channel ids referenced by this feed.
	 */
	private function channel_ids_from_stored(array $stored)
	{
		if (!empty($stored['id'])) {
			return is_array($stored['id'])
				? array_map('strval', $stored['id'])
				: array_filter(array_map('trim', explode(',', (string) $stored['id'])));
		}
		if (!empty($stored['channel'])) {
			return is_array($stored['channel'])
				? array_map('strval', $stored['channel'])
				: array_filter(array_map('trim', explode(',', (string) $stored['channel'])));
		}
		return array();
	}

	/**
	 * Keep only what identifies the account. Never tokens.
	 *
	 * @param string[] $channel_ids Connected-account channel ids to look up.
	 * @return array<int, array{channel_id: string, username: string}>
	 */
	private function redact_sources(array $channel_ids)
	{
		$settings = get_option('sby_settings', array());
		$accounts = is_array($settings) && !empty($settings['connected_accounts'])
			? $settings['connected_accounts']
			: array();

		$out = array();
		foreach ($channel_ids as $channel_id) {
			$account = isset($accounts[$channel_id]) ? $accounts[$channel_id] : null;
			if ($account === null) {
				// Free edition with an API key: a feed made in the Feed Builder keeps a bare channel id/name.
				$described = SBY_Source_Service::describe_api_key_channel($channel_id);
				if ($described !== null) {
					$out[] = $described;
					continue;
				}
			}
			$out[]   = array(
				'channel_id' => (string) $channel_id,
				'username'   => is_array($account) && isset($account['username']) ? (string) $account['username'] : '',
			);
		}
		return $out;
	}

	/**
	 * Apply a partial update to one feed's settings.
	 *
	 * SBY_Feed_Saver::update() REPLACES the stored settings JSON with whatever
	 * was passed to set_data(), so we merge into the RAW stored row (never a
	 * hydrated/normalised array), run the same saver the Feed Builder uses,
	 * and clear the feed cache exactly like the builder's own preview/save
	 * flow (Feed_Saver_Manager::feed_customizer_fly_preview()).
	 *
	 * @param int      $feed_id Feed id.
	 * @param array    $partial key => value, already schema-validated by core.
	 * @param callable $tier_available fn(string $feature): bool.
	 * @return array{feed_id: int, updated: string[], ignored: object, warnings: string[]}|\WP_Error|null
	 *         Null when the feed does not exist.
	 */
	public function update_feed($feed_id, array $partial, $tier_available)
	{
		$feed_id = (int) $feed_id;
		if ($feed_id <= 0) {
			return null;
		}
		$rows = SBY_Db::feeds_query(array('id' => $feed_id));
		if (empty($rows)) {
			return null;
		}
		$row    = $rows[0];
		$stored = json_decode($row['settings'], true);
		$stored = is_array($stored) ? $stored : array();

		$schema   = SBY_Settings_Schema::feed_settings();
		$writable = SBY_Settings_Schema::writable($schema);
		$updated  = array();
		$ignored  = array();
		$clean    = array();
		$new_name = null;

		foreach ($partial as $key => $value) {
			if (!isset($schema[$key])) {
				$ignored[$key] = 'unknown_setting';
				continue;
			}
			if (!isset($writable[$key])) {
				$ignored[$key] = 'read_only';
				continue;
			}
			if (isset($schema[$key]['tier']) && !call_user_func($tier_available, $schema[$key]['tier'])) {
				$ignored[$key] = 'feature_unavailable';
				continue;
			}
			$v = SBY_Settings_Schema::validate_in($schema[$key], $value, $key);
			if (is_wp_error($v)) {
				return $v;
			}
			if ($key === 'feed_name') {
				$new_name  = $v;
				$updated[] = $key;
				continue;
			}
			// The builder stores scalars as strings and booleans as booleans.
			$clean[$key] = is_bool($v) ? $v : (string) $v;
		}

		if (empty($clean) && $new_name === null) {
			return array(
				'feed_id'  => $feed_id,
				'updated'  => array(),
				'ignored'  => (object) $ignored,
				'warnings' => array(),
			);
		}

		$merged   = array_merge($stored, $clean);

		// A4 (SBR-195 QA §4): refuse colorpalette=custom without any custom colour.
		$coupled_error = $this->check_coupled_groups($clean, $merged);
		if (is_wp_error($coupled_error)) {
			return $coupled_error;
		}

		// No upgrade hint for an ignored key: Pro-only keys are absent from the free input
		// schema, so core rejects them before this point (AgDR-9006 concedes that gap).
		$warnings = array();

		// A5: cta='link' with an empty linkurl is a reachable-but-inert state — the
		// button renders with nowhere to go. Not a coupled read-only pair (requirement
		// 2 is for SOURCE keys only), so both stay independently writable; this is
		// requirement 3's signal instead — warn rather than silently accept.
		$warnings = array_merge($warnings, self::cta_link_warnings($schema, $merged));
		// linkurl was normalised (a bare domain got an explicit scheme prepended, see
		// SBY_Settings_Schema::validate_in()) — what is stored differs from what was
		// typed, so a caller comparing its own input to the next get-feed read should
		// know why.
		if (
			isset($clean['linkurl'], $partial['linkurl'])
			&& is_scalar($partial['linkurl'])
			&& (string) $clean['linkurl'] !== trim((string) $partial['linkurl'])
		) {
			$warnings[] = sprintf(
				/* translators: %s: the URL actually stored */
				__("linkurl was normalised to '%s'.", 'feeds-for-youtube'),
				(string) $clean['linkurl']
			);
		}

		$saver = new SBY_Feed_Saver($feed_id);
		$saver->set_feed_name($new_name !== null ? $new_name : (string) $row['feed_name']);
		$saver->set_data($merged);
		// SBY_Db::feeds_update() returns $wpdb->update(): rows changed, or false on
		// error. An idempotent replay can legitimately change 0 rows.
		if ($saver->update_or_insert() === false) {
			return new \WP_Error(
				'sby_feed_save_failed',
				__('The feed could not be saved.', 'feeds-for-youtube'),
				array('status' => 500)
			);
		}
		$updated = array_merge($updated, array_keys($clean));

		if (class_exists('SmashBalloon\\YouTubeFeed\\SBY_Cache')) {
			$cache = new SBY_Cache((string) $feed_id);
			// 'all' only empties the rendered/header cache rows (local, cheap) so the
			// change shows immediately. 'posts' forces a YouTube API refetch, so it is
			// limited to keys that change WHAT is fetched — otherwise an agent looping
			// over cosmetic keys would burn YouTube Data API quota.
			$cache->clear('all');
			if (array_intersect(self::RETRIEVAL_KEYS, array_keys($clean))) {
				$cache->clear('posts');
			}
		}

		/**
		 * Fires after an AI agent updated a feed through the Abilities API.
		 *
		 * @param int      $feed_id
		 * @param string[] $updated Keys that were written.
		 */
		do_action('sby_abilities_feed_settings_updated', $feed_id, $updated);

		return array(
			'feed_id'  => $feed_id,
			'updated'  => $updated,
			'ignored'  => (object) $ignored,
			'warnings' => $warnings,
		);
	}

	/**
	 * Resolve the requested feed template, tier-gate it, expand it into
	 * concrete settings, and re-verify anything the expansion changed.
	 *
	 * TEMPLATE GATE: a template other than `default` needs the
	 * `feeds_templates` feature (plus tier+). DROPS TO DEFAULT AND WARNS,
	 * never refuses the whole create-feed call: feedtemplate is an OPTIONAL
	 * input with a valid default, not the operation itself -- unlike, say,
	 * multiple sources, which IS the operation and correctly stays a 403.
	 * Same family rule Twitter Feed's port already follows; corrected here
	 * after an earlier draft of this fix wrongly 403'd on an unavailable
	 * template. Gated on `!== 'default'` only, so the free/off value itself
	 * is never mistaken for a feature to gate (the sibling TikTok fix's
	 * lesson: gate only preset values that ENABLE a tier feature).
	 *
	 * POST-EXPANSION RE-CHECK, SCOPED: re-checks ONLY the keys the expansion
	 * actually ADDED (this method never pre-merges a full defaults blob the
	 * way TikTok's port did, so every key a template function sets is new
	 * relative to the pre-expansion keys -- there is no "every default
	 * already present" hazard to scope around here) against a whole-key
	 * `tier` (e.g. carouselrows/carouselloop/carouseltime/... =>
	 * carousel_feeds, already declared on those keys in
	 * SBY_Settings_Schema). Expected to be a no-op for every shipped
	 * template: carousel_feeds is a BASIC-tier feature and tiers are
	 * cumulative, so any license that already cleared the feeds_templates
	 * gate above (plus tier or higher) already has it too. Belt-and-braces
	 * only -- same drop-to-default-and-warn treatment as the gate above,
	 * restricted to keys the EXPANSION changed so a schema entry unrelated
	 * to templates (e.g. call_to_actions on cta/linktext) is never
	 * re-litigated here.
	 *
	 * @param array                              $input          {feedtemplate?, ...}.
	 * @param array<string, array<string,mixed>> $schema         feed_settings() schema table.
	 * @param callable                           $tier_available fn(string $feature): bool.
	 * @return array{settings_data: array, ignored: array, warnings: array}
	 */
	private static function template_settings_dropping_to_default(array $input, array $schema, $tier_available)
	{
		$ignored  = array();
		$warnings = array();

		// Re-validate server-side: core only checks the JSON Schema `enum` on the
		// transport it received the request over (REST, or the MCP adapter);
		// PHP itself never re-checks it before this value drives
		// SBY_Feed_Templates_Settings::get_feed_settings_by_feed_templates()'s
		// dispatch, so this is the one write path in the module that trusts
		// core's schema validation instead of re-validating like every other key
		// does through SBY_Settings_Schema::validate_in().
		$requested_template = !empty($input['feedtemplate']) ? (string) $input['feedtemplate'] : '';
		$feed_template       = in_array($requested_template, self::FEED_TEMPLATES, true)
			? $requested_template
			: 'default';

		// Free applies `default` only (feed_templates()); a Pro licence is checked per tier.
		if (
			$feed_template !== 'default'
			&& (
				!in_array($feed_template, self::feed_templates(), true)
				|| !call_user_func($tier_available, 'feeds_templates')
			)
		) {
			$ignored['feedtemplate'] = 'feature_unavailable';
			$warnings[]              = self::template_fallback_warning($feed_template, 'feeds_templates');
			$feed_template            = 'default';
		}

		$settings_data      = array(
			'type'         => 'channel',
			'feedtemplate' => $feed_template,
		);
		$pre_expansion_keys = array_keys($settings_data);
		// Same template expansion the builder applies when a new feed is created.
		$settings_data = SBY_Feed_Templates_Settings::get_feed_settings_by_feed_templates($settings_data);

		foreach ($settings_data as $expanded_key => $expanded_value) {
			if (in_array($expanded_key, $pre_expansion_keys, true)) {
				continue; // Not something the expansion added.
			}
			$key_tier = isset($schema[$expanded_key]['tier']) ? $schema[$expanded_key]['tier'] : null;
			if ($key_tier === null || call_user_func($tier_available, $key_tier)) {
				continue;
			}
			$ignored['feedtemplate'] = 'feature_unavailable';
			$warnings[]              = self::template_fallback_warning($feed_template, $key_tier);
			$settings_data           = array(
				'type'         => 'channel',
				'feedtemplate' => 'default',
			);
			$settings_data = SBY_Feed_Templates_Settings::get_feed_settings_by_feed_templates($settings_data);
			break;
		}

		// A template dropped to default is a Pro-caused refusal (free applies `default`
		// only): one upgrade hint after the fallback warning(s) (AgDR-9006). Empty in Pro.
		if (isset($ignored['feedtemplate']) && SBY_Pro_Upsell::hint_for_feature('layouts') !== '') {
			$warnings[] = SBY_Pro_Upsell::hint_for_feature('layouts');
		}

		return array('settings_data' => $settings_data, 'ignored' => $ignored, 'warnings' => $warnings);
	}

	/**
	 * Human-readable warning for a feed template dropped to `default`
	 * because a required feature isn't available on the caller's tier.
	 *
	 * @param string $feed_template The template that was requested.
	 * @param string $feature       The missing feature slug.
	 * @return string
	 */
	private static function template_fallback_warning($feed_template, $feature)
	{
		return sprintf(
			/* translators: 1: the feed template that was requested, 2: the missing feature */
			__(
				"feedtemplate '%1\$s' needs a higher license tier (%2\$s); the feed was created with the default template instead.", // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
				'feeds-for-youtube'
			),
			$feed_template,
			$feature
		);
	}

	/**
	 * Create a feed the way the builder's new-feed path does: template
	 * defaults + validated quick settings + resolved sources.
	 *
	 * @param array    $input {feed_name?, feedtemplate?, sources?, settings?}.
	 * @param callable $tier_available fn(string $feature): bool.
	 * @return array|\WP_Error
	 */
	public function create_feed(array $input, $tier_available)
	{
		$schema  = SBY_Settings_Schema::feed_settings();
		$gated   = self::template_settings_dropping_to_default($input, $schema, $tier_available);
		$settings_data = $gated['settings_data'];
		$ignored       = $gated['ignored'];
		$warnings      = $gated['warnings'];

		$updated = array();
		if (!empty($input['settings']) && is_array($input['settings'])) {
			$writable = SBY_Settings_Schema::writable($schema);
			foreach ($input['settings'] as $key => $value) {
				if ($key === 'feed_name' || !isset($writable[$key])) {
					$ignored[$key] = isset($schema[$key]) ? 'read_only' : 'unknown_setting';
					continue;
				}
				if (isset($schema[$key]['tier']) && !call_user_func($tier_available, $schema[$key]['tier'])) {
					$ignored[$key] = 'feature_unavailable';
					continue;
				}
				$v = SBY_Settings_Schema::validate_in($schema[$key], $value, $key);
				if (is_wp_error($v)) {
					return $v;
				}
				$settings_data[$key] = is_bool($v) ? $v : (string) $v;
				$updated[]           = $key;
			}
		}

		// A4 (SBR-195 QA §4), same guard update_feed() applies: refuse a
		// colorpalette=custom write with no custom colour anywhere in this call.
		// create_feed() has a single write moment (there is no prior stored row
		// to fall back on), so $settings_data is both the "this write" set and
		// the resulting merged set -- unlike update_feed()'s $clean/$merged
		// split. Placed after the settings loop (so an explicit custom colour
		// passed in input['settings'] is already in $settings_data) and before
		// anything is persisted.
		$coupled_error = $this->check_coupled_groups($settings_data, $settings_data);
		if (is_wp_error($coupled_error)) {
			return $coupled_error;
		}

		$channel_usernames = array();
		if (!empty($input['sources']) && is_array($input['sources'])) {
			if (count($input['sources']) > 1 && !call_user_func($tier_available, self::MULTI_SOURCE_TIER_FEATURE)) {
				return self::multiple_sources_tier_error();
			}
			$resolved = (new SBY_Source_Service())->resolve_sources($input['sources']);
			if (!empty($resolved['missing'])) {
				return new \WP_Error(
					'sby_source_not_found',
					SBY_Source_Service::not_found_message($resolved['missing']),
					array('status' => 404)
				);
			}
			$settings_data['id'] = $resolved['ids'];
			foreach ($resolved['details'] as $d) {
				$channel_usernames[] = $d['username'];
			}
		}

		// SBR-500 (AgDR-9004 requirement 2): `type` and `channel` are both
		// read-only in $schema (see SBY_Settings_Schema), so `id` -- set two
		// lines above, only when `sources` resolved -- is the ONLY way this
		// method can ever give the feed a source. `type` therefore always
		// stays at its template default of 'channel', and a feed saved with
		// no `id` is type=channel with nothing to fetch: reachable through
		// this ability (no such gap exists in the builder's own create path,
		// which requires picking a source before the "Create" click ever
		// fires) but uncompletable -- opening it in the Feed Builder fatals,
		// because the customizer preview path assumes a source or a global
		// API key/connected account always exists. Refuse before that state
		// can be persisted, mirroring set_feed_type_and_source()'s coupled
		// type+source write.
		if (empty($settings_data['id'])) {
			return new \WP_Error(
				'sby_source_required',
				SBY_Source_Service::api_key_sources_enabled()
					? __(
						'A feed needs a channel. Pass `sources` with a channel id (starts with "UC") or an '
						. 'already-connected channel username (see list-sources); or use '
						. 'get-connect-url to connect one first.',
						'feeds-for-youtube'
					)
					: __(
						'A feed needs at least one connected channel. Pass `sources` with '
						. 'one or more already-connected channel ids or usernames (see '
						. 'list-sources), or use get-connect-url to connect one first.',
						'feeds-for-youtube'
					),
				array('status' => 400)
			);
		}

		$feed_name = !empty($input['feed_name'])
			? sanitize_text_field((string) $input['feed_name'])
			: SBY_Db::feeds_query_name(
				!empty($channel_usernames) ? $channel_usernames[0] : __('YouTube Feed', 'feeds-for-youtube')
			);

		// `false` is the plugin's own "no feed exists yet" sentinel — see
		// SBY_Feed_Saver::get_feed_id() / exists_in_database(), which both check
		// `=== false` explicitly, and IG's identical `new SBI_Feed_Saver(false)` on
		// its create-feed path. The constructor's `@param int` docblock predates
		// that sentinel and is inaccurate, not a real type error.
		$saver = new SBY_Feed_Saver(false);
		$saver->set_feed_name($feed_name);
		$saver->set_data($settings_data);
		if (!$saver->update_or_insert()) {
			return new \WP_Error(
				'sby_feed_save_failed',
				__('The feed could not be created.', 'feeds-for-youtube'),
				array('status' => 500)
			);
		}
		$feed_id = (int) $saver->get_feed_id();

		/** This action is documented in SBY_Feed_Settings_Service::update_feed(). */
		do_action('sby_abilities_feed_settings_updated', $feed_id, $updated);

		// Same A5 signal as update_feed(): cta='link' with no linkurl is
		// reachable-but-inert, even at creation time. $warnings may already
		// carry a feedtemplate fallback entry from earlier in this method --
		// appended to, never reset, here.
		$warnings = array_merge($warnings, self::cta_link_warnings($schema, $settings_data));

		return array(
			'feed_id'   => $feed_id,
			'feed_name' => $feed_name,
			'shortcode' => '[youtube-feed feed=' . $feed_id . ']',
			'updated'   => $updated,
			'ignored'   => (object) $ignored,
			'warnings'  => $warnings,
		);
	}

	/**
	 * Change what a feed shows: set `type` and its matching source value
	 * together, atomically. This is the ONLY writable path to `type` and the
	 * per-type source keys (channel/playlist/favorites/search/live/single) —
	 * AgDR-9004 requirement 2, mirroring Twitter Feed's canonical
	 * `set-feed-type-and-terms`. Always writes exactly ONE source key (the
	 * type's first-priority key, see SOURCE_TYPES) and clears every other
	 * SOURCE_KEYS entry, so the plugin's own per-type resolution order can
	 * never surface a stale sibling value from a previous type.
	 *
	 * @param int      $feed_id Feed id.
	 * @param string   $type    One of array_keys(self::source_types()).
	 * @param string[] $sources Connected-account channel ids/usernames — used
	 *                          (and required, at least one) when $type's kind
	 *                          is 'account'.
	 * @param string   $terms   Comma-separated playlist id(s), search term(s),
	 *                          or video id(s) — used (and required) when
	 *                          $type's kind is 'raw'.
	 * @return array{feed_id: int, type: string, sources: string[], terms: string, warnings: string[]}|\WP_Error|null
	 *         Null when the feed does not exist.
	 */
	public function set_feed_type_and_source($feed_id, $type, array $sources, $terms)
	{
		$feed_id = (int) $feed_id;
		$rows    = SBY_Db::feeds_query(array('id' => $feed_id));
		if ($feed_id <= 0 || empty($rows)) {
			return null;
		}
		if (!isset(self::source_types()[$type])) {
			return new \WP_Error(
				'sby_invalid_value',
				sprintf(
					/* translators: %s: comma-separated allowed type values */
					__('type must be one of: %s', 'feeds-for-youtube'),
					implode(', ', array_keys(self::source_types()))
				) . self::pro_refusal_suffix(SBY_Pro_Upsell::hint_for_feed_type($type)),
				array('status' => 400)
			);
		}

		$tier_feature = isset(self::TYPE_TIER_FEATURES[$type]) ? self::TYPE_TIER_FEATURES[$type] : null;
		if ($tier_feature !== null && !SBY_Update_Feed_Settings::tier_check($tier_feature)) {
			return new \WP_Error(
				'sby_tier_locked',
				sprintf(
					/* translators: %s: feed type */
					__('The "%s" feed type is not available on your current licence.', 'feeds-for-youtube'),
					$type
				) . self::hint_suffix(SBY_Pro_Upsell::hint_for_feed_type($type)),
				array('status' => 403)
			);
		}

		$spec        = self::SOURCE_TYPES[$type];
		$store_key   = $spec['store_key'];
		$out_sources = array();
		$out_terms   = '';

		if ($spec['kind'] === 'account') {
			if (count($sources) > 1 && !SBY_Update_Feed_Settings::tier_check(self::MULTI_SOURCE_TIER_FEATURE)) {
				return self::multiple_sources_tier_error();
			}
			$resolved = (new SBY_Source_Service())->resolve_sources($sources);
			if (!empty($resolved['missing'])) {
				return new \WP_Error(
					'sby_source_not_found',
					SBY_Source_Service::not_found_message($resolved['missing']),
					array('status' => 404)
				);
			}
			if (empty($resolved['ids'])) {
				return new \WP_Error(
					'sby_invalid_value',
					__('At least one connected channel is required for this type.', 'feeds-for-youtube'),
					array('status' => 400)
				);
			}
			$out_sources = $resolved['ids'];
			$store_value = $resolved['ids'];
		} else {
			$clean_terms = sanitize_text_field((string) $terms);
			$term_list   = array_filter(
				array_map('trim', explode(',', $clean_terms)),
				function ($term) {
					return $term !== '';
				}
			);
			if (empty($term_list)) {
				return new \WP_Error(
					'sby_invalid_value',
					__('terms is required for this type and must not be empty.', 'feeds-for-youtube'),
					array('status' => 400)
				);
			}
			if (mb_strlen($clean_terms) > SBY_Settings_Schema::MAX_STRING_LENGTH) {
				return new \WP_Error(
					'sby_invalid_value',
					sprintf(
						/* translators: %d: character limit */
						__('terms must be %d characters or fewer.', 'feeds-for-youtube'),
						SBY_Settings_Schema::MAX_STRING_LENGTH
					),
					array('status' => 400)
				);
			}
			$out_terms   = implode(',', $term_list);
			$store_value = $out_terms;
		}

		$stored = json_decode($rows[0]['settings'], true);
		$stored = is_array($stored) ? $stored : array();
		foreach (self::SOURCE_KEYS as $sibling_key) {
			unset($stored[$sibling_key]);
		}
		$stored[$store_key] = $store_value;
		$stored['type']     = $type;
		// 'search' is the only type with a boolean sibling (usecustomsearch) that
		// would otherwise re-route resolution to `customsearch` even though both
		// were just cleared above — explicit false leaves no ambiguity.
		if ($type === 'search') {
			$stored['usecustomsearch'] = false;
		}

		$saver = new SBY_Feed_Saver($feed_id);
		$saver->set_feed_name((string) $rows[0]['feed_name']);
		$saver->set_data($stored);
		if ($saver->update_or_insert() === false) {
			return new \WP_Error(
				'sby_feed_save_failed',
				__('The feed could not be saved.', 'feeds-for-youtube'),
				array('status' => 500)
			);
		}

		if (class_exists('SmashBalloon\\YouTubeFeed\\SBY_Cache')) {
			$cache = new SBY_Cache((string) $feed_id);
			$cache->clear('all');
			$cache->clear('posts'); // source/type change always alters what is fetched.
		}

		/** This action is documented in SBY_Feed_Settings_Service::update_feed(). */
		do_action('sby_abilities_feed_settings_updated', $feed_id, array('type', $store_key));

		return array(
			'feed_id'  => $feed_id,
			'type'     => $type,
			'sources'  => $out_sources,
			'terms'    => $out_terms,
			'warnings' => array(),
		);
	}

	/**
	 * Refuse a write that sets a coupled trigger key to its trigger value
	 * without at least one companion resolving to a real value — either in this
	 * write or already stored (SBR-195 QA §4 A4). Without this an agent can set
	 * colorpalette=custom and land a feed that renders identically to the
	 * inherited palette, with no signal it is inert (AgDR-9004 requirement 2 —
	 * "refuses an incomplete pair"). YouTube renders partial custom palettes, so
	 * one companion is enough; see SBY_Settings_Schema::COUPLED_GROUPS.
	 *
	 * @param array $clean  Keys being written THIS call (already validated).
	 * @param array $merged Resulting settings row (stored merged with $clean).
	 * @return \WP_Error|null
	 */
	private function check_coupled_groups(array $clean, array $merged)
	{
		foreach (SBY_Settings_Schema::coupled_groups() as $trigger_key => $group) {
			if (!isset($clean[$trigger_key]) || $clean[$trigger_key] !== $group['trigger']) {
				continue; // Trigger not being set to its coupled value this call.
			}
			$has_one = false;
			foreach ($group['requires'] as $companion) {
				$value = isset($merged[$companion]) ? trim((string) $merged[$companion]) : '';
				if ($value !== '' && $value !== '#') {
					$has_one = true;
					break;
				}
			}
			if (!$has_one) {
				return new \WP_Error(
					'sby_incomplete_setting',
					sprintf(
						/* translators: 1: trigger key, 2: trigger value, 3: comma-separated companion keys */
						__(
							'Setting %1$s to "%2$s" requires at least one of these colours in the same write: %3$s.', // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
							'feeds-for-youtube'
						),
						$trigger_key,
						$group['trigger'],
						implode(', ', $group['requires'])
					),
					array('status' => 400)
				);
			}
		}
		return null;
	}
}
