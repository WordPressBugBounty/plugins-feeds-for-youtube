<?php

namespace SmashBalloon\YouTubeFeed\Abilities\Services;

use SmashBalloon\YouTubeFeed\Helpers\Util;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Typed allowlist of the settings exposed through the Abilities API.
 *
 * This is the single source of truth for what an AI agent may read or write.
 * Anything not listed here is never exposed — connected accounts, access
 * tokens, the API key, license data, custom CSS/JS and usage tracking are
 * deliberately absent. Ported from Instagram Feed's SBI_Settings_Schema
 * (AgDR-0503); tables swapped for YouTube Feed's own key set.
 *
 * Per-feed keys are NOT a 1:1 map onto SBY_Feed_Saver::settings_defaults() —
 * that table is missing several keys this schema exposes (feedtemplate, cols,
 * colsmobile, headerstyle, customheadertext, colorpalette,
 * subscribehovercolor, buttonhovercolor), so SBY_Feed_Settings_Service layers
 * a small supplemental defaults table (sourced from
 * SBY_Settings::get_public_db_settings_keys()'s own literal values, since
 * that method only returns key names) on top of settings_defaults() before
 * calling cast_out(). Global keys map onto the single `sby_settings` option
 * (see sby_settings_defaults() / sby_get_database_settings()), which is
 * similarly missing `enqueue_js_in_head` — SBY_Global_Settings_Service layers
 * the same way. Note that in this plugin BOTH per-feed and global settings are
 * read with `sby_settings` as a fallback source, but only the
 * global_settings() table here is treated as a plugin-wide setting for the
 * Abilities API — per-feed values always come from the feed's own stored
 * `settings` JSON (see SBY_Feed_Settings_Service).
 *
 * @since SBY_ABILITIES_SINCE
 */
class SBY_Settings_Schema
{
	const MAX_STRING_LENGTH = 500;

	/**
	 * Coupled per-feed groups (SBR-195 QA §4 A4): setting the trigger key to its
	 * trigger value requires at least one companion key to resolve to a real
	 * value (this write or already stored), else the state is unsatisfiable —
	 * `colorpalette: custom` with no custom colour renders identically to the
	 * inherited palette, with no signal to the agent. Mirrors Facebook's
	 * CFF_Settings_Schema::COUPLED_GROUPS (AgDR-9004 requirement 2), but YouTube
	 * renders partial custom palettes gracefully (sby-functions.php emits CSS
	 * only for non-empty custom colours), so the threshold here is "at least
	 * one" companion rather than Facebook's "all".
	 *
	 * This is the PRO source of truth; read it through coupled_groups(), which
	 * drops the keys the free edition does not expose (customtextcolor2).
	 *
	 * @var array<string, array{trigger: string, requires: string[]}>
	 */
	const COUPLED_GROUPS = array(
		'colorpalette' => array(
			'trigger'  => 'custom',
			'requires' => array(
				'custombgcolor1',
				'customtextcolor1',
				'customtextcolor2',
				'customlinkcolor1',
				'custombuttoncolor1',
				'custombuttoncolor2',
			),
		),
	);

	/**
	 * Hex colour (#rgb, #rgba, #rrggbb, #rrggbbaa) OR a CSS rgb()/rgba() value
	 * — or empty (plugin default). The rgb()/rgba() alternative exists because
	 * the plugin's OWN default feed template stores one: the built-in
	 * `subscribehovercolor` default is 'rgb(255, 255, 255, .25)'
	 * (SBY_Feed_Templates_Settings.php), not a hex string. A hex-only pattern
	 * makes that legitimate, plugin-authored value fail BOTH directions: as
	 * the schema's OUTPUT pattern it 500s get-feed on a stock feed the moment
	 * `cast_out()` tries to pass it through, and if `cast_out()` instead falls
	 * back to $default to dodge that, an agent reading the field sees a
	 * fictional '' instead of the colour actually rendered (AgDR-9004
	 * requirement 1). One pattern used by cast_out(), validate_in() and
	 * to_json_properties() keeps read and write honoring the same contract.
	 */
	// Quantifiers bounded (\s{0,8}, \.\d{1,8}) rather than left unbounded — SonarCloud
	// S5852 (ReDoS false positive; Hakim confirmed no catastrophic-backtracking group
	// exists here, but the bound is free and clears the New Code gate). No value this
	// pattern needs to match uses more than a couple of whitespace/fraction digits.
	const COLOR_PATTERN = '^(#([0-9a-fA-F]{3,4}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})'
		. '|rgba?\(\s{0,8}\d{1,3}%?\s{0,8},\s{0,8}\d{1,3}%?\s{0,8},\s{0,8}\d{1,3}%?\s{0,8}'
		. '(,\s{0,8}[01]?(\.\d{1,8})?\s{0,8})?\))?$';

	/**
	 * Per-feed settings. `tier` names the license feature (from
	 * \Smashballoon\Customizer\YouTube_License_Tier::features_list()) that
	 * must be available for the key to be writable (null = every tier).
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function feed_settings()
	{
		return self::for_edition(self::pro_feed_settings());
	}

	/**
	 * The full (Pro) per-feed table, before edition filtering.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function pro_feed_settings()
	{
		return array(
			'feed_name' => self::text('Internal feed name shown in the Feed Builder list.'),
			// type + the per-type source key below (channel/playlist/favorites/search/live/
			// single) only work as a MATCHED SET: the plugin resolves the feed's videos from
			// whichever key matches the current `type`, in a fixed per-type priority order
			// (see SBY_Settings_Pro::set_feed_type_and_terms()). Writing `type` alone through
			// the generic setter can blank a working feed to 0 videos (it changes which key is
			// consulted before that key has a value); writing a source key alone while `type`
			// still names a different source is silently ignored by the plugin's own resolution
			// order. Both are read-only here (AgDR-9004 requirement 2) — the ONLY way to change
			// what a feed shows is the `set-feed-type-and-source` ability, which validates the
			// whole set together and refuses an incomplete pair. Twitter's
			// `set-feed-type-and-terms` is the canonical shape this ports.
			'type' => self::choice(
				array('channel', 'playlist', 'search', 'favorites', 'single', 'live'),
				'Feed source type. Read-only — change it with set-feed-type-and-source, '
					. 'never directly: setting it alone (without its matching source value) '
					. 'can blank the feed.',
				array(
					'writable'    => false,
					// Free edition (AgDR-9005): only `channel` is settable. Reads keep the
					// full enum so a feed downgraded from Pro reads back the type it
					// actually stores. Values mirror
					// SBY_Feed_Settings_Service::TYPE_TIER_FEATURES (pinned by
					// tests/abilities-free-edition-schema-test.php).
					'tier_values' => array(
						'playlist'  => 'playlist_feeds',
						'search'    => 'search_feeds',
						'favorites' => 'favorites_feeds',
						'single'    => 'single_feeds',
						'live'      => 'live_feeds',
					),
				)
			),
			'channel' => self::text(
				'Channel handle, ID or username (used when type is "channel"). Read-only — '
					. 'change it with set-feed-type-and-source.',
				array('writable' => false)
			),
			// playlist/favorites/search/live/single below are the Pro-only per-type source keys
			// (tiers playlist_feeds/favorites_feeds/search_feeds/live_feeds/single_feeds). They are
			// tagged `free_inert`, NOT `tier`: a `tier` tag would make Pro emit x-license-feature on
			// them (and tier-check `showpast`), changing Pro's contract for no reason. The free
			// filter drops both tags identically (AgDR-9005).
			'playlist' => self::text(
				'Playlist ID (used when type is "playlist"). Read-only — change it with '
					. 'set-feed-type-and-source.',
				array('writable' => false, 'free_inert' => true)
			),
			'favorites' => self::text(
				'Channel handle or ID whose liked/favorited videos are shown (used when type is '
					. '"favorites"). Read-only — change it with set-feed-type-and-source.',
				array('writable' => false, 'free_inert' => true)
			),
			'search' => self::text(
				'Search term(s), comma-separated (used when type is "search"). Read-only — '
					. 'change it with set-feed-type-and-source.',
				array('writable' => false, 'free_inert' => true)
			),
			'live' => self::text(
				'Channel handle or ID to pull live streams from (used when type is "live"). '
					. 'Read-only — change it with set-feed-type-and-source.',
				array('writable' => false, 'free_inert' => true)
			),
			'single' => self::text(
				'Video ID(s), comma-separated (used when type is "single"). Read-only — '
					. 'change it with set-feed-type-and-source.',
				array('writable' => false, 'free_inert' => true)
			),
			'layout' => self::choice(
				array('grid', 'carousel', 'list', 'gallery'),
				'Feed layout. ("widget" is a feed TEMPLATE preset — see feedtemplate — not a '
					. 'layout value; the plugin\'s layout toggle never offers it.)',
				// Free edition (AgDR-9005): carousel is upsell-gated in the Customizer
				// (checkExtension => 'feedLayout'), so free writes drop it. Reads keep it
				// because the shared front-end JS still renders a stored carousel.
				array('tier_values' => array('carousel' => 'carousel_feeds'))
			),
			// SBR-500 (Alex's PR#741 architecture audit, finding 3): feedtemplate is
			// read-only here on PURPOSE. Switching templates is a Feed Builder / create-feed
			// action, not a generic settings edit -- create-feed sets it through its own
			// dedicated, tier-gated path (SBY_Feed_Settings_Service::template_settings_dropping_to_default(),
			// gated on `feeds_templates`) which does NOT consult this table's `writable`
			// flag, so create-feed keeps working unaffected by this change. What this
			// DOES close: update_feed()/create_feed()'s generic `settings` loop both key
			// off `SBY_Settings_Schema::writable()`, and before this fix feedtemplate was
			// writable there too -- so `settings: {feedtemplate: ...}` on EITHER ability
			// could silently overwrite the tier-gated value create_feed() had already
			// resolved, or get accepted by update_feed() with no warning even though
			// nothing in the render path re-reads it after creation (it's inert on
			// update, not a paid unlock, but still a false "it worked"). Read remains
			// unaffected -- `writable => false` only changes what `writable()` filters
			// out of the WRITE schema; get-feed-settings still reads this key normally.
			'feedtemplate' => self::choice(
				array('default', 'carousel', 'cards', 'list', 'gallery', 'latest_video', 'showcase_carousel', 'widget'),
				'Feed template preset. Read-only here -- switch templates via create-feed '
					. '(tier-gated) or the Feed Builder.',
				// Free edition: template selection is upsell-only (the builder skips it
				// without `feeds_templates`), so every free feed is `default`.
				array('writable' => false, 'free_inert' => true)
			),
			// AgDR-9004 requirement 1 / AgDR-9005 "no invented bounds": the live
			// `num` control (Customizer\Tabs\Customize_Tab, "Number of Videos"
			// field) declares no maximum, so this schema must not invent one
			// either (Rex PR#741 review, P5).
			'num' => self::integer_min(1, 'Number of videos on desktop.'),
			// free_inert: SBY_Settings::__construct() forces nummobile = num in the free edition.
			'nummobile' => self::integer_min(1, 'Number of videos on mobile.', array('free_inert' => true)),
			'cols' => self::integer(1, 10, 'Columns on desktop (grid layout).'),
			'colsmobile' => self::integer(1, 10, 'Columns on mobile (grid layout).'),
			// Derived from Customizer\Tabs\Settings_Tab's "Sort Posts by" toggleset
			// (inc/Builder/Tabs/SBY_Settings_Tab is dead code — SBY_Feed_Builder,
			// its only consumer, is never instantiated; Rex PR#741 review, P2).
			// 'likes' was removed here (Rex PR#741 review, B4/P1): it was never a
			// real option in that control, and neither SBY_Feed::sort_posts() nor
			// SBY_Feed_Pro implements it, so writing it was a silent no-op. It was
			// also never reachable through this schema's own write path before
			// this fix landed (the Abilities API PR is unreleased), so there is no
			// "legally stored" value to preserve on read.
			'sortby' => self::choice(
				array('none', 'random'),
				'Video sort order (none = newest first).'
			),
			// Derived from Customizer\Tabs\Customize_Tab's "When does video play?"
			// select. 'lightbox'/'newpage' were never real options;
			// the real second value is 'onclick'.
			'playvideo' => self::choice(
				array('automatically', 'onclick'),
				'How a clicked video plays.'
			),
			// Derived from SBY_Customize_Tab::get_nested_images_videos_controls() — the
			// Resolution select. These are the plugin's own thumbnail-size vocabulary, not the
			// YouTube Data API's thumbnail keys (default/medium/high/standard/maxres); the
			// previous enum invented values ('default', 'high', 'standard', 'maxres') the
			// plugin's own UI never offers and never reads (see
			// SBY_Display_Elements::get_optimum_media_url()).
			'imageres' => self::choice(
				array('auto', 'thumb', 'medium', 'full'),
				'Thumbnail resolution.'
			),
			'itemspacing' => self::integer(0, 100, 'Spacing between videos, in px.'),
			'showheader' => self::boolean('Show the feed header.'),
			// Derived from SBY_Display_Elements::display_header() — the ACTUAL front-end
			// rendering branch, not the Feed Builder's headerstyle toggleset (which also
			// offers 'boxed'/'centered'). Outside the live customizer preview, display_header()
			// only has branches for 'standard' (renders the avatar/name header) and 'text'
			// (renders customheadertext); 'boxed'/'centered' hit neither branch and render
			// nothing on the live site — they are legacy toggleset options with no current
			// front-end effect.
			'headerstyle' => self::choice(array('standard', 'text'), 'Header style.'),
			'headersize' => self::choice(array('small', 'medium', 'large'), 'Header size.'),
			'headeroutside' => self::boolean('Place the header outside the feed container.'),
			'customheadertext' => self::text('Text-style header content.'),
			'showdescription' => self::boolean('Show video descriptions.'),
			'showbutton' => self::boolean('Show the Load More button.'),
			'buttontext' => self::text('Load More button label.'),
			'showsubscribe' => self::boolean('Show the Subscribe button.'),
			'subscribetext' => self::text('Subscribe button label.'),
			// showpast only applies to the `live` type (live_feeds tier); free_inert, not `tier`,
			// for the same reason as the per-type source keys above.
			'showpast' => self::boolean('Include past (already ended) live streams.', array('free_inert' => true)),
			// free_inert: templates/item-free.php renders no like count.
			'showlikes' => self::boolean('Show like counts on videos.', array('free_inert' => true)),
			'colorpalette' => self::choice(array('inherit', 'light', 'dark', 'custom'), 'Color palette.'),
			// Custom-palette colours (SBR-195 QA §4 A4) — used when colorpalette is
			// "custom" (see COUPLED_GROUPS). Exposed so an agent selecting the custom
			// palette can actually supply its colours instead of landing an inert state.
			'custombgcolor1' => self::color('Custom palette background colour (hex). Applies when colorpalette is "custom".'),
			'customtextcolor1' => self::color('Custom palette primary text colour (hex). Applies when colorpalette is "custom".'),
			// free_inert: it styles `.sby_info .sby_meta`, which item-free.php never renders.
			'customtextcolor2' => self::color(
				'Custom palette secondary text colour (hex). Applies when colorpalette is "custom".',
				array('free_inert' => true)
			),
			'customlinkcolor1' => self::color('Custom palette link colour (hex). Applies when colorpalette is "custom".'),
			'custombuttoncolor1' => self::color('Custom palette button colour (hex). Applies when colorpalette is "custom".'),
			'custombuttoncolor2' => self::color('Custom palette button text colour (hex). Applies when colorpalette is "custom".'),
			'background' => self::color('Feed background colour (hex).'),
			'headercolor' => self::color('Header text colour (hex).'),
			'subscribecolor' => self::color('Subscribe button background (hex).'),
			'subscribehovercolor' => self::color('Subscribe button hover background (hex).'),
			'subscribetextcolor' => self::color('Subscribe button text colour (hex).'),
			'buttoncolor' => self::color('Load More button background (hex).'),
			'buttonhovercolor' => self::color('Load More button hover background (hex).'),
			'buttontextcolor' => self::color('Load More button text colour (hex).'),
			// --- Carousel layout (carousel_feeds tier — Basic and up) ---
			'carouselcols' => self::integer(
				1,
				10,
				'Carousel columns on desktop.',
				array('tier' => 'carousel_feeds')
			),
			'carouselcolsmobile' => self::integer(
				1,
				10,
				'Carousel columns on mobile.',
				array('tier' => 'carousel_feeds')
			),
			'carouselarrows' => self::boolean(
				'Show carousel navigation arrows.',
				array('tier' => 'carousel_feeds')
			),
			'carouselautoplay' => self::boolean('Autoplay the carousel.', array('tier' => 'carousel_feeds')),
			'carouselpag' => self::boolean(
				'Show carousel pagination dots.',
				array('tier' => 'carousel_feeds')
			),
			// --- Call to action (call_to_actions tier — Plus and up) ---
			// Derived from Styling_Tab::call_to_action() — the Type select — plus
			// SBY_Feed_Pro::get_options_attributes()'s handling of the value: 'related' and
			// 'link' branch to different behaviour, 'default' (missing from the previous enum,
			// so previously UNSETTABLE by an agent) falls through to neither — i.e. no
			// call-to-action, the plugin's own "YouTube Default" option.
			'cta' => self::choice(
				array('related', 'link', 'default'),
				'Call-to-action type shown per video. "link" requires linkurl to also be set — '
					. 'set both together with set-feed-type-and-source is not required, but a '
					. 'write that leaves cta=link with an empty linkurl is reported in `warnings`.', // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
				array('tier' => 'call_to_actions')
			),
			'linktext' => self::text('Call-to-action link label.', array('tier' => 'call_to_actions')),
			'linkurl' => self::url(
				'Call-to-action link URL. Accepts http://, https://, mailto: or tel: — a value '
					. 'with no scheme (e.g. "example.com") is normalised to an explicit http:// '
					. 'URL, and get-feed always reflects exactly what is stored (no read/write '
					. 'mismatch). Only meaningful when cta is "link".',
				array('tier' => 'call_to_actions')
			),
			// Derived from Styling_Tab::call_to_action() — the "Open link in" select. 'new' was
			// never a real option; the real second value is 'newwindow'.
			'linkopentype' => self::choice(
				array('same', 'newwindow'),
				'Whether the call-to-action link opens in the same or a new tab.',
				array('tier' => 'call_to_actions')
			),
			'linkcolor' => self::color(
				'Call-to-action link colour (hex).',
				array('tier' => 'call_to_actions')
			),
			'linktextcolor' => self::color(
				'Call-to-action link text colour (hex).',
				array('tier' => 'call_to_actions')
			),
		);
	}

	/**
	 * Global (plugin-wide) settings stored in the sby_settings option.
	 *
	 * The `api_key`, `connected_accounts`, license and usage-tracking keys
	 * live in the SAME option but are deliberately absent here — they are
	 * credentials or telemetry, not customer-configurable behaviour.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function global_settings()
	{
		return self::for_edition(self::pro_global_settings());
	}

	/**
	 * The full (Pro) global table, before edition filtering.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	private static function pro_global_settings()
	{
		return array(
			'caching_type' => self::choice(array('background', 'page'), 'How feeds are refreshed.'),
			'cache_time' => self::integer(
				1,
				999,
				'Cache duration (page caching only), paired with cache_time_unit.'
			),
			'cache_time_unit' => self::choice(array('minutes', 'hours', 'days'), 'Unit for cache_time.'),
			'gdpr' => self::choice(
				array('auto', 'yes', 'no'),
				'GDPR mode, as set in Settings. "auto" turns it on only when a supported consent plugin '
				. 'is active, "yes" always on, "no" always off. While on, feeds skip third-party requests '
				. '(YouTube thumbnails, players) until the visitor consents. It affects legal compliance, '
				. 'so confirm the change with the site owner first.'
			),
			'disable_resize' => self::boolean('Disable local image resizing/storage.'),
			'favor_local' => self::boolean('Prefer locally-stored thumbnail images over the YouTube CDN.'),
			'ajaxtheme' => self::boolean(
				'Load feeds via AJAX (AJAX theme loading fix, for themes that break the initial ' . 'load).'
			),
			'ajax_post_load' => self::boolean('Load the initial videos via AJAX.'),
			'enqueue_css_in_shortcode' => self::boolean('Enqueue CSS only where the shortcode is used.'),
			'enqueue_js_in_head' => self::boolean('Enqueue JS in the head instead of the footer.'),
			'disable_js_image_loading' => self::boolean('Disable JavaScript image loading.'),
			'customtemplates' => self::boolean(
				'Allow custom feed templates added to the active theme. Read-only for AI '
				. 'assistants: enabling it turns on a theme-file-inclusion path (sby_get_feed_template_part()), '
				. 'change it in Settings.',
				array('writable' => false)
			),
			'disablecdn' => self::boolean('Disable the Smash Balloon CDN.'),
			'allowcookies' => self::boolean('Allow cookies to be set by the feed.'),
		);
	}

	/**
	 * Coupled groups for the active edition: free drops the keys it does not
	 * expose (customtextcolor2) from `requires`, so a free write is never
	 * refused for want of a colour it cannot set.
	 *
	 * @param bool|null $is_pro Edition override; null = the running edition.
	 * @return array<string, array{trigger: string, requires: string[]}>
	 */
	public static function coupled_groups($is_pro = null)
	{
		$is_pro = $is_pro === null ? Util::isPro() : (bool) $is_pro;
		if ($is_pro) {
			return self::COUPLED_GROUPS;
		}
		$exposed = self::for_edition(self::pro_feed_settings(), false);
		$groups  = array();
		foreach (self::COUPLED_GROUPS as $trigger_key => $group) {
			$group['requires'] = array_values(array_filter(
				$group['requires'],
				function ($key) use ($exposed) {
					return isset($exposed[$key]);
				}
			));
			$groups[$trigger_key] = $group;
		}
		return $groups;
	}

	/**
	 * Filter a table down to what the given edition exposes (AgDR-9005).
	 *
	 * Pro: returned untouched. Free:
	 *  - rows carrying `tier` (licence-gated) or `free_inert` (Pro-only but not
	 *    tier-gated, so a write would be a false success) are removed outright
	 *    -- Pro-only keys are ABSENT in free, never `feature_unavailable`;
	 *  - rows carrying `tier_values` (Pro-only enum values) keep the full enum
	 *    as `read_enum` (a feed downgraded from Pro reads back what it stores)
	 *    while the write `enum` loses those values;
	 *  - the tier metadata itself is stripped, so it can never reach output.
	 *
	 * @param array     $table  A raw (Pro) table.
	 * @param bool|null $is_pro Edition override; null = the running edition.
	 * @return array<string, array<string, mixed>>
	 */
	public static function for_edition(array $table, $is_pro = null)
	{
		$is_pro = $is_pro === null ? Util::isPro() : (bool) $is_pro;
		if ($is_pro) {
			return $table;
		}
		$out = array();
		foreach ($table as $key => $def) {
			if (isset($def['tier']) || !empty($def['free_inert'])) {
				continue;
			}
			if (isset($def['tier_values'])) {
				$def['read_enum'] = $def['enum'];
				$def['enum']      = array_values(array_diff($def['enum'], array_keys($def['tier_values'])));
			}
			unset($def['tier_values'], $def['free_inert']);
			$out[$key] = $def;
		}
		return $out;
	}

	/**
	 * Settings (and single values) Pro has that the free edition does not expose,
	 * DERIVED from the edition filter so it cannot drift from it (AgDR-9006).
	 *
	 * A key the free filter drops (`tier` / `free_inert`) is listed by name; a
	 * Pro-only enum value (`tier_values`, e.g. layout=carousel) as `key=value`.
	 * Covers per-feed and global tables. Read-only Pro keys are included: they
	 * exist in Pro and are absent here, which is what an agent asking "can I
	 * set X?" needs to know.
	 *
	 * @return string[]
	 */
	public static function pro_only_settings()
	{
		$out = array();
		foreach (array(self::pro_feed_settings(), self::pro_global_settings()) as $pro_table) {
			$free_table = self::for_edition($pro_table, false);
			foreach ($pro_table as $key => $def) {
				if (!isset($free_table[$key])) {
					$out[] = $key;
					continue;
				}
				if (isset($def['tier_values'])) {
					foreach (array_keys($def['tier_values']) as $value) {
						$out[] = $key . '=' . $value;
					}
				}
			}
		}
		return array_values(array_unique($out));
	}

	/**
	 * Schema-entry builders. Each table row is a small associative array;
	 * these keep the rows to one line and the shape in one place.
	 *
	 * `$extra` carries the optional per-row flags: `tier` (license feature
	 * required to write the key) and `writable` (false = read-only).
	 *
	 * @param string $description Human/agent-facing description.
	 * @param array  $extra       Optional `tier` / `writable` flags.
	 * @return array<string, mixed>
	 */
	private static function text($description, array $extra = array())
	{
		return array_merge(array('type' => 'string', 'description' => $description), $extra);
	}

	/**
	 * A hex-colour string (see COLOR_PATTERN).
	 *
	 * @param string $description Human/agent-facing description.
	 * @param array  $extra       Optional `tier` / `writable` flags.
	 * @return array<string, mixed>
	 */
	private static function color($description, array $extra = array())
	{
		return array_merge(
			array('type' => 'string', 'format' => 'color', 'description' => $description),
			$extra
		);
	}

	/**
	 * An http(s) URL string.
	 *
	 * @param string $description Human/agent-facing description.
	 * @param array  $extra       Optional `tier` / `writable` flags.
	 * @return array<string, mixed>
	 */
	private static function url($description, array $extra = array())
	{
		return array_merge(
			array('type' => 'string', 'format' => 'url', 'description' => $description),
			$extra
		);
	}

	/**
	 * A string constrained to a fixed set of values.
	 *
	 * @param string[] $enum        Allowed values; the first is the fallback.
	 * @param string   $description Human/agent-facing description.
	 * @param array    $extra       Optional `tier` / `writable` flags.
	 * @return array<string, mixed>
	 */
	private static function choice(array $enum, $description, array $extra = array())
	{
		return array_merge(
			array('type' => 'string', 'enum' => $enum, 'description' => $description),
			$extra
		);
	}

	/**
	 * An integer clamped to [$minimum, $maximum].
	 *
	 * @param int    $minimum     Inclusive lower bound.
	 * @param int    $maximum     Inclusive upper bound.
	 * @param string $description Human/agent-facing description.
	 * @param array  $extra       Optional `tier` / `writable` flags.
	 * @return array<string, mixed>
	 */
	private static function integer($minimum, $maximum, $description, array $extra = array())
	{
		return array_merge(
			array(
				'type'        => 'integer',
				'minimum'     => $minimum,
				'maximum'     => $maximum,
				'description' => $description
			),
			$extra
		);
	}

	/**
	 * An integer with only a lower bound. AgDR-9005 "no invented bounds": use
	 * this instead of integer() when the plugin's own control has no upper
	 * limit -- every read/write/schema site below already branches on
	 * `isset($def['maximum'])`, so simply omitting the key is honored
	 * end-to-end (no clamp on write, no clamp on read, no `maximum` in the
	 * JSON Schema properties).
	 *
	 * @param int    $minimum     Inclusive lower bound.
	 * @param string $description Human/agent-facing description.
	 * @param array  $extra       Optional `tier` / `writable` flags.
	 * @return array<string, mixed>
	 */
	private static function integer_min($minimum, $description, array $extra = array())
	{
		return array_merge(
			array(
				'type'        => 'integer',
				'minimum'     => $minimum,
				'description' => $description
			),
			$extra
		);
	}

	/**
	 * A boolean flag.
	 *
	 * @param string $description Human/agent-facing description.
	 * @param array  $extra       Optional `tier` / `writable` flags.
	 * @return array<string, mixed>
	 */
	private static function boolean($description, array $extra = array())
	{
		return array_merge(array('type' => 'boolean', 'description' => $description), $extra);
	}

	/**
	 * Build a JSON Schema `properties` map from a table (drops private keys).
	 *
	 * @param array $table      One of feed_settings() / global_settings().
	 * @param bool  $for_output True for an OUTPUT schema: enums widen to `read_enum`.
	 * @return array
	 */
	public static function to_json_properties(array $table, $for_output = false)
	{
		$out = array();
		foreach ($table as $key => $def) {
			$prop = array('type' => $def['type']);
			foreach (array('enum', 'minimum', 'maximum', 'description') as $k) {
				if (isset($def[$k])) {
					$prop[$k] = $def[$k];
				}
			}
			$prop = array_merge($prop, self::output_overrides($def, $for_output));
			if ($def['type'] === 'string' && !isset($def['enum'])) {
				$prop['maxLength'] = self::MAX_STRING_LENGTH;
			}
			if (isset($def['format']) && $def['format'] === 'color') {
				$prop['pattern']     = self::COLOR_PATTERN;
				$prop['description'] = (isset($prop['description']) ? $prop['description'] . ' ' : '')
					. 'Hex colour (#rgb, #rrggbb or #rrggbbaa), a CSS rgb()/rgba() value, '
					. 'or empty for the default.';
			}
			if (isset($def['format']) && $def['format'] === 'url') {
				$prop['format'] = 'uri';
			}
			if (isset($def['writable']) && $def['writable'] === false) {
				$prop['readOnly'] = true;
			}
			if (isset($def['tier'])) {
				$prop['x-license-feature'] = $def['tier'];
			}
			$out[$key] = $prop;
		}
		return $out;
	}

	/**
	 * Output-schema overrides for one row: enums widen to the superset
	 * `read_enum` (stored truth, AgDR-9005). Empty for an input schema.
	 *
	 * @param array $def        Schema entry.
	 * @param bool  $for_output Whether an OUTPUT schema is being built.
	 * @return array<string, mixed>
	 */
	private static function output_overrides(array $def, $for_output)
	{
		return $for_output && isset($def['read_enum']) ? array('enum' => $def['read_enum']) : array();
	}

	/**
	 * Cast a raw stored value to the declared type for output.
	 *
	 * Stored data is not guaranteed to satisfy the schema (legacy installs,
	 * import/export, a bad save). Core validates our OUTPUT against the schema
	 * and fails the whole ability on one bad value, so anything that does not
	 * satisfy the declared enum / range falls back to $default (the plugin's
	 * own default for that key) rather than being passed through.
	 *
	 * @param array $def     Schema entry for the key.
	 * @param mixed $value   Raw stored value (null when missing).
	 * @param mixed $default Plugin default for the key.
	 * @return mixed
	 */
	public static function cast_out(array $def, $value, $default = null)
	{
		switch ($def['type']) {
			case 'boolean':
				// '' is a legitimate "off" in this plugin (legacy read paths write it),
				// so only a MISSING value takes the default.
				if ($value === null) {
					$value = $default;
				}
				return $value === true || $value === 'true' || $value === 1 || $value === '1' || $value === 'on';

			case 'integer':
				if ($value === null || $value === '' || !is_numeric($value)) {
					$value = is_numeric($default) ? $default : 0;
				}
				$int = (int) $value;
				// Clamp so the output always satisfies the declared range even if the
				// plugin default itself is ever out of spec.
				if (isset($def['minimum']) && $int < $def['minimum']) {
					$int = (int) $def['minimum'];
				}
				if (isset($def['maximum']) && $int > $def['maximum']) {
					$int = (int) $def['maximum'];
				}
				return $int;

			default:
				$str = is_scalar($value) ? (string) $value : '';

				// Clamp to the declared colour pattern, same reasoning as the integer
				// min/max clamp above: core validates our OUTPUT against the schema and
				// fails the WHOLE ability on one bad stored value. COLOR_PATTERN accepts
				// hex OR a CSS rgb()/rgba() value (AgDR-9004 B13) specifically because a
				// stored value can be the plugin's OWN default — e.g. the default feed
				// template's subscribehovercolor is 'rgb(255, 255, 255, .25)'
				// (SBY_Feed_Templates_Settings.php) — and a hex-only pattern would
				// silently report that as '' instead of the colour actually rendered.
				// This fallback now only fires for stored data that is neither: garbage
				// from a legacy install, a bad import, or a hand-edited row. '' always
				// satisfies COLOR_PATTERN (it's optional-empty), so it's always a safe
				// last resort even when $default itself doesn't match.
				$is_color = isset($def['format']) && $def['format'] === 'color';
				if ($is_color && !preg_match('/' . self::COLOR_PATTERN . '/', $str)) {
					$fallback_str = is_scalar($default) ? (string) $default : '';
					$str          = preg_match('/' . self::COLOR_PATTERN . '/', $fallback_str) ? $fallback_str : '';
				}

				// Reads validate against the superset enum when one exists, so a stored
				// Pro value survives a downgrade instead of being coerced (AgDR-9005).
				$read_enum = isset($def['read_enum']) ? $def['read_enum'] : (isset($def['enum']) ? $def['enum'] : null);
				if ($read_enum !== null && !in_array($str, $read_enum, true)) {
					$fallback = is_scalar($default) ? (string) $default : '';
					$str      = in_array($fallback, $read_enum, true) ? $fallback : (string) $read_enum[0];
				}

				// Same reasoning again for length: to_json_properties() only
				// declares maxLength on plain strings (never on an enum, which is
				// always short), so truncate rather than fail the whole ability on
				// an over-length stored value.
				if (!isset($def['enum']) && mb_strlen($str) > self::MAX_STRING_LENGTH) {
					$str = mb_substr($str, 0, self::MAX_STRING_LENGTH);
				}

				return $str;
		}
	}

	/**
	 * Writable subset of a table (keys not flagged `writable => false`).
	 *
	 * @param array $table One of feed_settings() / global_settings().
	 * @return array
	 */
	public static function writable(array $table)
	{
		return array_filter(
			$table,
			function ($def) {
				return !isset($def['writable']) || $def['writable'] !== false;
			}
		);
	}

	/**
	 * Validate + normalise ONE incoming value for storage.
	 *
	 * Core has already validated the request against the JSON Schema, so this is
	 * the second, PHP-side line: it never trusts the transport. Returns the
	 * value to store, or a WP_Error (code sby_invalid_value, status 400).
	 *
	 * @param array  $def   Schema entry for the key.
	 * @param mixed  $value Raw incoming value.
	 * @param string $key   Setting key, for the error message.
	 * @return mixed|\WP_Error
	 */
	public static function validate_in(array $def, $value, $key)
	{
		$bad = function ($reason) use ($key) {
			return new \WP_Error(
				'sby_invalid_value',
				sprintf(
					/* translators: 1: setting key, 2: reason */
					__('Invalid value for %1$s: %2$s', 'feeds-for-youtube'),
					$key,
					$reason
				),
				array('status' => 400)
			);
		};

		switch ($def['type']) {
			case 'boolean':
				if (is_bool($value)) {
					return $value;
				}
				if (in_array($value, array('true', '1', 1, 'on'), true)) {
					return true;
				}
				if (in_array($value, array('false', '0', 0, 'off', ''), true)) {
					return false;
				}
				return $bad(__('expected a boolean', 'feeds-for-youtube'));

			case 'integer':
				if (!is_numeric($value) || (float) $value !== (float) (int) $value) {
					return $bad(__('expected an integer', 'feeds-for-youtube'));
				}
				$int = (int) $value;
				if (isset($def['minimum']) && $int < $def['minimum']) {
					return $bad(sprintf(__('minimum is %d', 'feeds-for-youtube'), $def['minimum']));
				}
				if (isset($def['maximum']) && $int > $def['maximum']) {
					return $bad(sprintf(__('maximum is %d', 'feeds-for-youtube'), $def['maximum']));
				}
				return $int;

			default:
				if (!is_scalar($value)) {
					return $bad(__('expected a string', 'feeds-for-youtube'));
				}
				$is_url = isset($def['format']) && $def['format'] === 'url';
				if ($is_url) {
					$raw = trim((string) $value);
					// A value with no scheme at all (e.g. "example.com") is normalised
					// to an explicit http:// URL — the same "type a bare domain, get a
					// working link" leniency a browser address bar gives — rather than
					// rejected outright. Doing it HERE (not silently inside
					// esc_url_raw(), which does not add scheme prefixes) means the
					// stored value is exactly what a subsequent get-feed/get-global-
					// settings read returns: no read-after-write mismatch (AgDR-9004
					// requirement 1). A value that already has a scheme is untouched.
					// Quantifier bounded to {0,31} (RFC 3986 caps a real scheme well
					// under 32 chars) rather than left unbounded — SonarCloud S5852
					// (ReDoS false positive; Hakim confirmed safe, bound is free).
					if ($raw !== '' && !preg_match('#^[a-z][a-z0-9+.\-]{0,31}:#i', $raw)) {
						$raw = 'http://' . $raw;
					}
					// esc_url_raw(), not sanitize_text_field(): a URL's query string
					// needs URL-specific escaping, not text escaping.
					$str = esc_url_raw($raw);
				} else {
					$str = sanitize_text_field((string) $value);
				}
				if (isset($def['enum']) && !in_array($str, $def['enum'], true)) {
					return $bad(sprintf(__('allowed values: %s', 'feeds-for-youtube'), implode(', ', $def['enum'])));
				}
				$is_color = isset($def['format']) && $def['format'] === 'color';
				if ($is_color && !preg_match('/' . self::COLOR_PATTERN . '/', $str)) {
					return $bad(
						__('expected a hex colour such as #408bd1, or a CSS rgb()/rgba() value', 'feeds-for-youtube')
					);
				}
				// esc_url_raw()'s OWN job stops at core's broader kses scheme allowlist
				// (http, https, mailto, tel, ...); this is the check that narrows it to
				// the schemes THIS contract promises. Not redundant with esc_url_raw()
				// above: that call only strips dangerous schemes (javascript:, data:,
				// ...) and would happily pass through an unlisted-but-harmless one.
				if ($is_url && $value !== '' && ($str === '' || !preg_match('#^(https?|mailto|tel):#i', $str))) {
					return $bad(__('expected an http://, https://, mailto: or tel: URL', 'feeds-for-youtube'));
				}
				if (mb_strlen($str) > self::MAX_STRING_LENGTH) {
					return $bad(
						sprintf(__('maximum length is %d characters', 'feeds-for-youtube'), self::MAX_STRING_LENGTH)
					);
				}
				return $str;
		}
	}
}
