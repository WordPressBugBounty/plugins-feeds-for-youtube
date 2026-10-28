<?php

namespace SmashBalloon\YouTubeFeed\Abilities\Services;

use SmashBalloon\YouTubeFeed\Abilities\SBY_Ability_Response;
use SmashBalloon\YouTubeFeed\Abilities\SBY_Pro_Upsell;
use SmashBalloon\YouTubeFeed\Abilities\SBY_State_Token;
use SmashBalloon\YouTubeFeed\Builder\SBY_Db;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Connected-account (source) operations for the Abilities API.
 *
 * IMPORTANT divergence from Instagram/Facebook/Twitter Feed: YouTube Feed Pro
 * does NOT store connected accounts in a dedicated sources table. The
 * `SBY_Db::source_query()` / `Smashballoon\Customizer\DB::source_query()`
 * paths target the `sbi_sources` / `sb_sources` tables, but the real
 * OAuth-connect completion handler (`sby_attempt_connection()` →
 * `SBY_Admin::connect_account()` → `sby_update_or_connect_account()`) never
 * inserts into either table — it writes the connected channel straight into
 * `sby_settings['connected_accounts']`, keyed by channel_id. The
 * `remove_connected_account` AJAX handler (SourcesService::ajax_remove_account())
 * confirms this: it reads/writes the same `connected_accounts` array. So THIS
 * array — not a DB table — is the real source of truth, and it embeds
 * `access_token` and `refresh_token` directly (structural exclusions, same
 * severity as Instagram Feed's per-row access_token).
 *
 * Always redacted: never returns access/refresh tokens. Connecting an account
 * remains a HUMAN step — this service only builds the connect link; the
 * OAuth handshake happens in the admin's own browser via the Smash Balloon
 * relay (connect.smashballoon.com).
 *
 * @since SBY_ABILITIES_SINCE
 */
class SBY_Source_Service
{
	/** A YouTube channel id: `UC` + 22 url-safe characters. */
	const CHANNEL_ID_PATTERN = '/^UC[A-Za-z0-9_-]{22}$/';

	/** Same floor the Feed Builder applies to call a key "set" (Feed_Builder::check_api_key_status()). */
	const MIN_API_KEY_LENGTH = 9;

	/** How many pages of feeds list_sources() scans for API-key channels. */
	const FEED_SCAN_PAGES = 5;

	/**
	 * Whether a channel id can be used as a source with an API key alone, no
	 * connected account: the FREE edition only (Pro keeps its connected-account
	 * contract) and only once a Settings -> API key is saved.
	 *
	 * The Feed Builder lets a user create a channel feed with just an API key:
	 * it does no server-side lookup of the channel, it stores the id (or name) in
	 * the feed and SBY_Settings::set_feed_type_and_terms() fetches it with the
	 * key at render time. "A key is set" is Feed_Builder::check_api_key_status()
	 * (`strlen >= 9`), mirrored here so this class does not depend on the
	 * Customizer package being loaded.
	 *
	 * @return bool
	 */
	public static function api_key_sources_enabled()
	{
		if (!SBY_Pro_Upsell::is_free()) {
			return false;
		}
		$settings = get_option('sby_settings', array());
		return is_array($settings)
			&& !empty($settings['api_key'])
			&& strlen((string) $settings['api_key']) >= self::MIN_API_KEY_LENGTH;
	}

	/**
	 * Channel id cached for a channel name/handle the plugin has already
	 * resolved once (the `sby_channel_ids` option the renderer fills). No API
	 * call is made here.
	 *
	 * @param string $name Channel name or @handle.
	 * @return string '' when unknown.
	 */
	private static function cached_channel_id($name)
	{
		if (!function_exists('sby_get_channel_id_from_channel_name')) {
			return '';
		}
		$id = sby_get_channel_id_from_channel_name(ltrim((string) $name, '@'));
		return is_string($id) && preg_match(self::CHANNEL_ID_PATTERN, $id) === 1 ? $id : '';
	}

	/**
	 * For a channel stored in a feed without a connected account (API-key feeds):
	 * the channel id and the best-known name, from the plugin's own name cache.
	 * Reads only; no API call. Free edition with an API key only, else null.
	 *
	 * @param string $stored The `id` / `channel` value as stored in the feed.
	 * @return array{channel_id: string, username: string}|null
	 */
	public static function describe_api_key_channel($stored)
	{
		if (!self::api_key_sources_enabled()) {
			return null;
		}
		$stored = trim((string) $stored);
		if (preg_match(self::CHANNEL_ID_PATTERN, $stored) === 1) {
			$cache = get_option('sby_channel_ids', array());
			$name  = is_array($cache) ? array_search($stored, $cache, true) : false;
			return array('channel_id' => $stored, 'username' => $name === false ? '' : (string) $name);
		}
		$cached = self::cached_channel_id($stored);
		return $cached === ''
			? null
			: array('channel_id' => $cached, 'username' => ltrim($stored, '@'));
	}

	/**
	 * Why a handle could not be used as a source, as an error-message sentence.
	 * Echoed input is made safe first (SBR-578 QA F1).
	 *
	 * @param string[] $missing Handles that did not resolve.
	 * @return string
	 */
	public static function not_found_message(array $missing)
	{
		$safe  = array_map(array(SBY_Ability_Response::class, 'safe_echo'), $missing);
		$shown = implode(', ', $safe);
		if (self::api_key_sources_enabled()) {
			return sprintf(
				/* translators: %s: comma-separated channel names as sent */
				__('These are not connected channels and not channel ids: %s. This site uses a YouTube API key, so pass the channel id (starts with "UC", 24 characters) or the name of a channel an existing feed already uses; or use get-connect-url to connect an account.', 'feeds-for-youtube'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
				$shown
			);
		}
		return sprintf(
			/* translators: %s: comma-separated account names as sent */
			__('These channels are not connected to this site: %s. Use get-connect-url to connect one first.', 'feeds-for-youtube'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
			$shown
		);
	}

	/**
	 * List the connected YouTube channels, redacted.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function list_sources()
	{
		$settings = get_option('sby_settings', array());
		$accounts = is_array($settings) && !empty($settings['connected_accounts'])
			? $settings['connected_accounts']
			: array();

		$out = array();
		foreach ((array) $accounts as $channel_id => $account) {
			if (!is_array($account)) {
				continue;
			}
			$out[] = array(
				'channel_id' => (string) $channel_id,
				'username'   => isset($account['username']) ? (string) $account['username'] : '',
				'privacy'    => isset($account['privacy']) ? (string) $account['privacy'] : '',
				'expires'    => isset($account['expires']) ? substr((string) $account['expires'], 0, 10) : '',
				'is_valid'   => !empty($account['is_valid']),
			);
		}
		return $this->add_api_key_channels($out);
	}

	/**
	 * Free edition with an API key: append the channels the site already uses
	 * through that key (cached names, channel ids in existing feeds), each marked
	 * `connection: api_key`. Any channel id (UC...) is accepted as a source in
	 * this mode, so this list is what is known, not the limit. Unchanged
	 * otherwise (so Pro, and a free site without a key, keep the exact old shape).
	 *
	 * @param array<int, array<string, mixed>> $sources Connected-account rows.
	 * @return array<int, array<string, mixed>>
	 */
	private function add_api_key_channels(array $sources)
	{
		if (!self::api_key_sources_enabled()) {
			return $sources;
		}
		foreach ($sources as $i => $row) {
			$sources[$i]['connection'] = 'oauth';
		}

		$seen = array();
		foreach ($sources as $row) {
			$seen[$row['channel_id']] = true;
		}
		$names = array();
		$cache = get_option('sby_channel_ids', array());
		foreach (is_array($cache) ? $cache : array() as $name => $id) {
			if (is_string($id) && preg_match(self::CHANNEL_ID_PATTERN, $id) === 1) {
				$names[$id] = (string) $name;
			}
		}

		$ids = array_keys($names);
		foreach ($this->channel_ids_in_feeds() as $id) {
			$ids[] = $id;
		}
		foreach (array_unique($ids) as $id) {
			if (isset($seen[$id])) {
				continue;
			}
			$seen[$id] = true;
			$sources[] = array(
				'channel_id' => $id,
				'username'   => isset($names[$id]) ? $names[$id] : '',
				'privacy'    => '',
				'expires'    => '',
				'is_valid'   => true,
				'connection' => 'api_key',
			);
		}
		return $sources;
	}

	/**
	 * Channel ids (UC...) stored in existing feeds' `id` / `channel` settings.
	 *
	 * @return string[]
	 */
	private function channel_ids_in_feeds()
	{
		$found = array();
		if (!class_exists(SBY_Db::class)) {
			return $found;
		}
		for ($page = 1; $page <= self::FEED_SCAN_PAGES; $page++) {
			$rows = SBY_Db::feeds_query(array('page' => $page));
			if (empty($rows)) {
				break;
			}
			foreach ($rows as $row) {
				$stored = isset($row['settings']) ? json_decode((string) $row['settings'], true) : null;
				if (!is_array($stored)) {
					continue;
				}
				foreach (array('id', 'channel') as $key) {
					if (empty($stored[$key])) {
						continue;
					}
					$values = is_array($stored[$key]) ? $stored[$key] : explode(',', (string) $stored[$key]);
					foreach ($values as $value) {
						$value = trim((string) $value);
						if (preg_match(self::CHANNEL_ID_PATTERN, $value) === 1) {
							$found[] = $value;
						}
					}
				}
			}
		}
		return $found;
	}

	/**
	 * Build the account-connect URL for the human to open.
	 *
	 * Points at OUR wp-admin bootstrap page (SBY_Connect_Bootstrap), which —
	 * in the admin's browser session — reproduces the exact POST the
	 * Settings page's "Add Source" form submits to the OAuth relay
	 * (connect.smashballoon.com/auth/yt/), reusing the plugin's own
	 * Feed_Builder::oauth_connet_parameters() so the fields never drift from
	 * the native flow, with a fresh session nonce and a return_uri pointed at
	 * the Settings page so the admin sees the new account. The single-use
	 * state token in this URL never leaves the site.
	 *
	 * @return array{connect_url: string, expires_in: int, instructions: string}|\WP_Error
	 */
	public function get_connect_url()
	{
		$token = SBY_State_Token::create('connect');
		if (is_wp_error($token)) {
			return $token;
		}

		return array(
			'connect_url'  => add_query_arg(
				array(
					'page'                  => 'sby-feed-builder',
					'sby_abilities_connect' => $token,
				),
				admin_url('admin.php')
			),
			'expires_in'   => SBY_State_Token::TTL,
			'instructions' => __('Open this link in the browser where you are logged into wp-admin. It forwards you to Google/YouTube to authorize the app; afterwards you return to YouTube Feed → Settings, where the newly connected channel appears under Connected YouTube Account. The link is single-use and expires in 15 minutes. The AI assistant never sees your Google credentials.', 'feeds-for-youtube'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
		);
	}

	/**
	 * Resolve channel ids or usernames to channel ids: connected accounts first;
	 * with a saved API key (free edition, see api_key_sources_enabled()) a plain
	 * channel id, or a name already resolved once (sby_channel_ids), also counts.
	 * No YouTube API call is made.
	 *
	 * @param string[] $handles usernames or channel ids.
	 * @return array{ids: string[], details: array<int, array{id: string, username: string}>, missing: string[]}
	 */
	public function resolve_sources(array $handles)
	{
		$settings = get_option('sby_settings', array());
		$accounts = is_array($settings) && !empty($settings['connected_accounts'])
			? $settings['connected_accounts']
			: array();

		$api_key_mode = self::api_key_sources_enabled();
		$ids          = array();
		$details      = array();
		$missing      = array();
		foreach ($handles as $handle) {
			$handle = trim((string) $handle);
			$found  = null;
			foreach ($accounts as $channel_id => $account) {
				if (
					(string) $channel_id === $handle
					|| (isset($account['username']) && strcasecmp((string) $account['username'], $handle) === 0)
				) {
					$found = (string) $channel_id;
					break;
				}
			}
			if ($found === null && $api_key_mode) {
				if (preg_match(self::CHANNEL_ID_PATTERN, $handle) === 1) {
					$found = $handle;
				} elseif ($handle !== '') {
					$cached = self::cached_channel_id($handle);
					$found  = $cached !== '' ? $cached : null;
				}
			}
			if ($found === null) {
				$missing[] = $handle;
				continue;
			}
			if (in_array($found, $ids, true)) {
				continue; // Two handles resolved to the same account.
			}
			$ids[]     = $found;
			// `?:` (not `isset(...) ? ... : $found`) so an empty-string username —
			// isset() is true, but there's nothing to show — also falls back to the
			// channel id, not silently to ''.
			$details[] = array(
				'id'       => $found,
				'username' => isset($accounts[$found]['username'])
					? ((string) $accounts[$found]['username'] ?: $found)
					: ($found !== $handle ? $handle : $found),
			);
		}
		return array('ids' => $ids, 'details' => $details, 'missing' => $missing);
	}
}
