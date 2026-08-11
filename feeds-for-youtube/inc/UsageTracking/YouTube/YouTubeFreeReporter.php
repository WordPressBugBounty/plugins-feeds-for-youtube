<?php
/**
 * YouTube reporter for Smash Usage Tracking — Free plugin variant.
 *
 * Collects configuration and dynamic metrics for Feeds for YouTube (Free).
 * License methods return free/null values since the Free plugin has no EDD
 * license infrastructure; YouTubeProReporter overrides them.
 *
 * @package SmashBalloon\YouTubeFeed\UsageTracking\YouTube
 */

namespace SmashBalloon\YouTubeFeed\UsageTracking\YouTube;

use SmashBalloon\YouTubeFeed\UsageTracking\Config;
use SmashBalloon\YouTubeFeed\UsageTracking\EventRecorder;
use SmashBalloon\YouTubeFeed\UsageTracking\ReporterInterface;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class YouTubeFreeReporter implements ReporterInterface {

	/**
	 * Payload schema version. 1.1 added feeds{} and features_enabled{},
	 * 1.2 added environment{} — this reporter sends all of them, so it declares
	 * 1.2 rather than the 1.0 the schema originally shipped with.
	 */
	const SCHEMA_VERSION = '1.2';

	/**
	 * True sby_feeds row count, set by get_all_feed_data() alongside the
	 * capped sample so get_feeds_summary() can report an honest total.
	 *
	 * @var int
	 */
	private $feeds_total_count = 0;

	/**
	 * Plugin slug for payload root. This is the backend plugin enum value —
	 * do not change it.
	 *
	 * @return string
	 */
	public function get_plugin_slug() {
		return 'youtube';
	}

	/**
	 * Schema version for the report payload.
	 *
	 * @return string
	 */
	public function get_schema_version() {
		return self::SCHEMA_VERSION;
	}

	/**
	 * Configuration snapshot (environment, settings, sources, feeds, features).
	 *
	 * @return array
	 */
	public function get_configuration_snapshot() {
		$global_settings = $this->get_global_settings();

		// Single DB scan — reused for latest sample, summary, feed types and features map.
		$all_feed_data = $this->get_all_feed_data();

		$feeds_summary = $this->get_feeds_summary( $all_feed_data );

		return array(
			'environment'      => $this->get_environment(),
			'global_settings'  => $global_settings,
			'sources'          => $this->get_sources_summary(),
			'latest_10_feeds'  => $this->get_latest_feeds( $all_feed_data ),
			'feeds'            => $feeds_summary,
			// The backend has a dedicated youtube_snapshots.feed_types column fed
			// from configuration_snapshot.feed_types, so the same map is emitted
			// at the top level rather than only nested under feeds.by_type.
			'feed_types'       => $feeds_summary['by_type'],
			'features_enabled' => $this->get_features_enabled( $all_feed_data, $global_settings ),
			'version'          => defined( 'SBYVER' ) ? SBYVER : '',
			'license_tier'     => $this->get_license_tier(),
			'license_status'   => $this->get_license_status(),
			'license_expires'  => $this->get_license_expires(),
			'license_item_id'  => $this->get_license_item_id(),
		);
	}

	/**
	 * Dynamic metrics for the given period.
	 *
	 * period_start / period_end are deliberately NOT included here: the backend
	 * has no validation rule for them inside dynamic_metrics and would silently
	 * discard them, and the payload builder already sets them at the top level.
	 *
	 * @param string|int $period_start Start of period (ISO 8601 or timestamp).
	 * @param string|int $period_end   End of period (ISO 8601 or timestamp).
	 * @return array
	 */
	public function get_dynamic_metrics( $period_start, $period_end ) {
		$ts_start = is_numeric( $period_start ) ? (int) $period_start : (int) strtotime( $period_start );
		$ts_end   = is_numeric( $period_end ) ? (int) $period_end : (int) strtotime( $period_end );
		// period_end is a Y-m-d date; strtotime() yields MIDNIGHT AT ITS START,
		// which would exclude the entire final day of the period from every
		// timestamp filter (and leave a permanent gap between weekly windows).
		if ( ! is_numeric( $period_end ) && $ts_end > 0 ) {
			$ts_end += DAY_IN_SECONDS - 1;
		}

		return array(
			'performance'      => $this->get_performance_metrics(),
			'errors'           => $this->get_error_metrics( $ts_start, $ts_end ),
			'events'           => $this->get_events_for_period( $ts_start, $ts_end ),
			'days_active'      => $this->get_days_active( $period_start, $period_end ),
			'session_duration' => $this->get_session_duration(),
		);
	}

	/**
	 * Environment data (WP, PHP, theme, locale, multisite, install age).
	 *
	 * @return array
	 */
	private function get_environment() {
		$install_ts = null;
		$statuses   = get_option( 'sby_statuses', array() );
		if ( is_array( $statuses ) && ! empty( $statuses['first_install'] ) && is_numeric( $statuses['first_install'] ) ) {
			$install_ts = (int) $statuses['first_install'];
		}
		$install_age_days = $install_ts ? max( 0, (int) ((time() - $install_ts) / DAY_IN_SECONDS) ) : 0;

		$theme      = wp_get_theme();
		$theme_name = $theme->exists() ? $theme->get( 'Name' ) : '';

		return array(
			'wp_version'           => get_bloginfo( 'version' ),
			'php_version'          => PHP_VERSION,
			'active_theme'         => $theme_name,
			'locale'               => get_locale(),
			'multisite'            => is_multisite(),
			'site_count'           => is_multisite() ? (int) get_blog_count() : 1,
			'active_plugins_count' => count(
				array_unique(
					array_merge(
						(array) get_option( 'active_plugins', array() ),
						array_keys( (array) get_site_option( 'active_sitewide_plugins', array() ) )
					)
				)
			),
			'install_age_days'     => $install_age_days,
		);
	}

	/**
	 * Whitelist of global setting keys to report from the sby_settings option.
	 *
	 * Only non-identifying configuration keys are listed — api_key,
	 * connected_accounts, custom_css/custom_js bodies and every *text string
	 * are deliberately excluded.
	 *
	 * @var string[]
	 */
	private static $global_settings_whitelist = array(
		'usagetracking',
		'preserve_settings',
		'customtemplates',
		'ajaxtheme',
		'gdpr',
		'allowcookies',
		'disablecdn',
		'caching_type',
		'cache_time',
		'cache_time_unit',
		'backup_cache_enabled',
		'disable_resize',
		'favor_local',
		'eagerload',
		'enqueue_js_in_head',
	);

	/**
	 * Global YouTube Feed settings, whitelisted.
	 *
	 * @return array
	 */
	private function get_global_settings() {
		$settings = get_option( 'sby_settings', array() );
		if ( ! is_array( $settings ) ) {
			$settings = array();
		}

		$out = array();
		foreach ( self::$global_settings_whitelist as $key ) {
			if ( ! array_key_exists( $key, $settings ) ) {
				continue;
			}
			$value = $settings[ $key ];
			if ( is_array( $value ) || is_scalar( $value ) ) {
				$out[ $key ] = $value;
			}
		}

		// Only whether custom CSS/JS is in use is reported, never the code itself.
		$out['has_custom_css'] = ! empty( $settings['custom_css'] );
		$out['has_custom_js']  = ! empty( $settings['custom_js'] );

		return $out;
	}

	/**
	 * Sources summary (connected accounts, account types, API key presence).
	 *
	 * This whole subtree is free-form to the backend, so extra keys are safe.
	 *
	 * @return array
	 */
	private function get_sources_summary() {
		global $wpdb;

		$sources_table = $wpdb->prefix . 'sby_sources';
		$table_exists  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $sources_table ) ) === $sources_table;

		$connected_count = 0;
		$by_account_type = array();

		if ( $table_exists ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name derived from $wpdb->prefix, not user input.
			$connected_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$sources_table}" );

			$rows = $wpdb->get_results(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name derived from $wpdb->prefix, not user input.
				"SELECT account_type, COUNT(*) AS total FROM {$sources_table} GROUP BY account_type",
				ARRAY_A
			);
			foreach ( (array) $rows as $row ) {
				$type                     = isset( $row['account_type'] ) && '' !== $row['account_type'] ? (string) $row['account_type'] : 'unknown';
				$by_account_type[ $type ] = (int) $row['total'];
			}
		}

		$sby_settings = get_option( 'sby_settings', array() );
		if ( ! is_array( $sby_settings ) ) {
			$sby_settings = array();
		}

		$legacy_accounts = isset( $sby_settings['connected_accounts'] ) && is_array( $sby_settings['connected_accounts'] )
			? count( $sby_settings['connected_accounts'] )
			: 0;

		return array(
			'connected_accounts_count'        => $connected_count,
			'by_account_type'                 => $by_account_type,
			'api_key_set'                     => (bool) ! empty( $sby_settings['api_key'] ),
			'legacy_connected_accounts_count' => $legacy_accounts,
		);
	}

	/**
	 * Whitelist of feed setting keys to track.
	 *
	 * Never includes api_key, access_token, connected_accounts, custom_css,
	 * custom_js or any *text string.
	 *
	 * @var string[]
	 */
	private static $feed_settings_whitelist = array(
		'type',
		'layout',
		'feedtemplate',
		'num',
		'nummobile',
		'gridcols',
		'gridcolsmobile',
		'gallerycols',
		'carouselcols',
		'carouselarrows',
		'carouselpag',
		'carouselautoplay',
		'showheader',
		'headerstyle',
		'headeroutside',
		'showsubscribe',
		'showbutton',
		'showdescription',
		'showlikes',
		'showsubscribers',
		'enablelightbox',
		'enablecomments',
		'customtemplates',
		'usecustomsearch',
		'showpast',
		'sortby',
		'playvideo',
		'hidevideos',
	);

	/**
	 * Feed settings reported as a COUNT of entries rather than their values.
	 * Word-filter lists are operator-authored free text that can plausibly
	 * contain personal data; the dashboard only needs adoption depth, and
	 * features_enabled.word_filter already captures whether it's used at all.
	 * (hidevideos stays whitelisted above — it is a list of video IDs, not
	 * free text.)
	 *
	 * @var string[]
	 */
	private static $feed_settings_counted = array(
		'includewords',
		'excludewords',
	);

	/**
	 * Load every feed's decoded settings plus feed_name, sorted newest-first.
	 *
	 * One query only — the result is reused for latest_10_feeds, feeds,
	 * feed_types and features_enabled.
	 *
	 * @return array[]
	 */
	private function get_all_feed_data() {
		global $wpdb;

		// Note: the plugin defines no SBY_*_TABLE constants, so table names are
		// literal. inc/Builder/SBY_Db.php also contains dead Instagram
		// copy-paste querying sbi_* tables — the sby_* tables are the real ones.
		$table        = $wpdb->prefix . 'sby_feeds';
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;

		if ( ! $table_exists ) {
			return array();
		}

		// Honest total, independent of the 500-row sample cap below.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name derived from $wpdb->prefix, not user input.
		$this->feeds_total_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" );

		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name derived from $wpdb->prefix, not user input.
			"SELECT feed_name, settings FROM {$table} ORDER BY last_modified DESC LIMIT 500",
			ARRAY_A
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$decoded = ! empty( $row['settings'] ) ? json_decode( $row['settings'], true ) : array();
			$out[]   = array(
				'feed_name' => isset( $row['feed_name'] ) ? sanitize_text_field( (string) $row['feed_name'] ) : '',
				'settings'  => is_array( $decoded ) ? $decoded : array(),
			);
		}

		return $out;
	}

	/**
	 * Latest 10 feeds with whitelisted settings. The count matches the
	 * latest_10_feeds payload key and the backend snapshot column of the
	 * same name, so the key stays accurate and the wire contract is intact.
	 *
	 * @param array[] $all_feed_data From get_all_feed_data().
	 * @return array
	 */
	private function get_latest_feeds( array $all_feed_data ) {
		$feeds = array();
		foreach ( array_slice( $all_feed_data, 0, 10 ) as $row ) {
			$feed_name = $row['feed_name'];
			if ( strlen( $feed_name ) > 255 ) {
				$feed_name = substr( $feed_name, 0, 255 );
			}
			$feeds[] = array(
				'feed_name' => $feed_name,
				'settings'  => $this->pick_whitelisted_settings( $row['settings'] ),
			);
		}
		return $feeds;
	}

	/**
	 * Aggregate feed type and layout distribution across ALL feeds.
	 *
	 * @param array[] $all_feed_data From get_all_feed_data().
	 * @return array
	 */
	private function get_feeds_summary( array $all_feed_data ) {
		$by_type   = array();
		$by_layout = array();

		foreach ( $all_feed_data as $row ) {
			$s = $row['settings'];
			// Feed types: channel | playlist | favorites | search | live | single.
			$type   = isset( $s['type'] ) && '' !== $s['type'] ? (string) $s['type'] : 'channel';
			$layout = isset( $s['layout'] ) && '' !== $s['layout'] ? (string) $s['layout'] : 'grid';

			$by_type[ $type ]     = ($by_type[ $type ] ?? 0) + 1;
			$by_layout[ $layout ] = ($by_layout[ $layout ] ?? 0) + 1;
		}

		return array(
			// The true row count — the distributions below are computed over
			// the newest-500 sample from get_all_feed_data().
			'total_count' => max( $this->feeds_total_count, count( $all_feed_data ) ),
			'by_type'     => $by_type,
			'by_layout'   => $by_layout,
		);
	}

	/**
	 * Flat boolean feature map for the dashboard's feature adoption page.
	 *
	 * A flag is true when ANY feed uses the feature.
	 *
	 * @param array[] $all_feed_data   From get_all_feed_data().
	 * @param array   $global_settings From get_global_settings().
	 * @return array<string,bool>
	 */
	private function get_features_enabled( array $all_feed_data, array $global_settings ) {
		$feed_flags = array(
			'carousel_layout'   => false,
			'gallery_layout'    => false,
			'carousel_autoplay' => false,
			'carousel_arrows'   => false,
			'show_header'       => false,
			'header_outside'    => false,
			'load_more'         => false,
			'show_subscribe'    => false,
			'lightbox'          => false,
			'comments'          => false,
			'custom_search'     => false,
			'word_filter'       => false,
			'custom_templates'  => false,
			'gdpr'              => false,
		);

		foreach ( $all_feed_data as $row ) {
			$s = $row['settings'];

			if ( ! $feed_flags['carousel_layout'] && isset( $s['layout'] ) && 'carousel' === $s['layout'] ) {
				$feed_flags['carousel_layout'] = true;
			}
			if ( ! $feed_flags['gallery_layout'] && isset( $s['layout'] ) && 'gallery' === $s['layout'] ) {
				$feed_flags['gallery_layout'] = true;
			}
			if ( ! $feed_flags['carousel_autoplay'] && ! empty( $s['carouselautoplay'] ) ) {
				$feed_flags['carousel_autoplay'] = true;
			}
			if ( ! $feed_flags['carousel_arrows'] && ! empty( $s['carouselarrows'] ) ) {
				$feed_flags['carousel_arrows'] = true;
			}
			if ( ! $feed_flags['show_header'] && ! empty( $s['showheader'] ) ) {
				$feed_flags['show_header'] = true;
			}
			if ( ! $feed_flags['header_outside'] && ! empty( $s['headeroutside'] ) ) {
				$feed_flags['header_outside'] = true;
			}
			if ( ! $feed_flags['load_more'] && ! empty( $s['showbutton'] ) ) {
				$feed_flags['load_more'] = true;
			}
			if ( ! $feed_flags['show_subscribe'] && ! empty( $s['showsubscribe'] ) ) {
				$feed_flags['show_subscribe'] = true;
			}
			if ( ! $feed_flags['lightbox'] && ! empty( $s['enablelightbox'] ) ) {
				$feed_flags['lightbox'] = true;
			}
			if ( ! $feed_flags['comments'] && ! empty( $s['enablecomments'] ) ) {
				$feed_flags['comments'] = true;
			}
			if ( ! $feed_flags['custom_search'] && ! empty( $s['usecustomsearch'] ) ) {
				$feed_flags['custom_search'] = true;
			}
			if ( ! $feed_flags['word_filter']
				&& ( ! empty( $s['includewords'] ) || ! empty( $s['excludewords'] ) || ! empty( $s['hidevideos'] ) ) ) {
				$feed_flags['word_filter'] = true;
			}
			if ( ! $feed_flags['custom_templates'] && ! empty( $s['customtemplates'] ) ) {
				$feed_flags['custom_templates'] = true;
			}
			// 'gdpr' is a tri-state string defaulting to 'auto' (detect a consent
			// plugin). Only an explicit 'yes' counts as the feature being enabled.
			if ( ! $feed_flags['gdpr'] && isset( $s['gdpr'] ) && 'yes' === $s['gdpr'] ) {
				$feed_flags['gdpr'] = true;
			}

			if ( ! in_array( false, $feed_flags, true ) ) {
				break;
			}
		}

		return array_merge(
			$feed_flags,
			array(
				'preserve_settings' => ! empty( $global_settings['preserve_settings'] ),
			)
		);
	}

	/**
	 * Return only whitelisted feed settings, scalars and arrays only.
	 *
	 * @param array $settings Raw feed settings.
	 * @return array
	 */
	private function pick_whitelisted_settings( array $settings ) {
		$out = array();
		foreach ( self::$feed_settings_whitelist as $key ) {
			if ( ! array_key_exists( $key, $settings ) ) {
				continue;
			}
			$value = $settings[ $key ];
			if ( is_array( $value ) || is_scalar( $value ) ) {
				$out[ $key ] = $value;
			}
		}
		foreach ( self::$feed_settings_counted as $key ) {
			if ( ! array_key_exists( $key, $settings ) ) {
				continue;
			}
			$value = $settings[ $key ];
			if ( is_array( $value ) ) {
				$out[ $key . '_count' ] = count( $value );
			} elseif ( is_string( $value ) && '' !== trim( $value ) ) {
				$out[ $key . '_count' ] = count( array_filter( array_map( 'trim', explode( ',', $value ) ) ) );
			}
		}
		return $out;
	}

	// ── License methods ───────────────────────────────────────────────────────
	// The Free plugin has no EDD license infrastructure. These return static
	// values so the payload is always consistent and the dashboard can correctly
	// segment free vs paid sites. YouTubeProReporter overrides all four.

	/** @return string */
	protected function get_license_tier() {
		return 'free';
	}

	/** @return null */
	protected function get_license_status() {
		return null;
	}

	/** @return null */
	protected function get_license_expires() {
		return null;
	}

	/** @return null */
	protected function get_license_item_id() {
		return null;
	}

	// ── Metrics methods ───────────────────────────────────────────────────────

	/**
	 * Performance metrics (feed caches count, YouTube API quota errors).
	 *
	 * avg_render_ms and cache_hit_ratio are always null: this plugin has no
	 * render-time timing and no cache hit/miss instrumentation, so there is no
	 * honest value to report. Sending null keeps the key shape stable for the
	 * backend rather than reporting a fabricated 0.
	 *
	 * @return array
	 */
	private function get_performance_metrics() {
		global $wpdb;

		$cache_table  = $wpdb->prefix . 'sby_feed_caches';
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $cache_table ) ) === $cache_table;

		$feed_caches_count = 0;
		if ( $table_exists ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name derived from $wpdb->prefix, not user input.
			$feed_caches_count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$cache_table}" );
		}

		$counters = $this->get_error_counters();

		return array(
			'feed_caches_count' => $feed_caches_count,
			'quota_errors'      => (int) $counters['quota'],
			'avg_render_ms'     => null,
			'cache_hit_ratio'   => null,
		);
	}

	/**
	 * Normalised live error counter map from Config::OPTION_ERROR_COUNTERS.
	 *
	 * This option is accumulated by the sby_api_error listener and is the
	 * source of truth for error counts.
	 *
	 * @return array<string,int>
	 */
	private function get_error_counters() {
		$categories = array( 'quota', 'rate_limit', 'auth', 'permission', 'not_found', 'server', 'network', 'other' );

		$stored = get_option( Config::OPTION_ERROR_COUNTERS, array() );
		if ( ! is_array( $stored ) ) {
			$stored = array();
		}

		$out = array();
		foreach ( $categories as $key ) {
			$out[ $key ] = isset( $stored[ $key ] ) ? (int) $stored[ $key ] : 0;
		}

		return $out;
	}

	/**
	 * Map a sby_errors type key onto one of the reported error categories.
	 *
	 * @param string $type One of accesstoken, api, connection, upload_dir.
	 * @return string
	 */
	private function map_error_type( $type ) {
		$map = array(
			'accesstoken' => 'auth',
			'api'         => 'other',
			'connection'  => 'network',
			'upload_dir'  => 'other',
		);

		return isset( $map[ $type ] ) ? $map[ $type ] : 'other';
	}

	/**
	 * Error metrics.
	 *
	 * Counts come from Config::OPTION_ERROR_COUNTERS, NOT from the sby_errors
	 * option. sby_errors is a map keyed by error type (accesstoken, api,
	 * connection, upload_dir) and SBY_Posts_Manager::add_error() OVERWRITES the
	 * entry for a type on every new error, so it holds at most ~4 entries ever.
	 * It is a "current state" snapshot, not a log, and cannot serve as a failure
	 * counter. This deliberately differs from the other Smash Balloon plugins,
	 * which use count($errors) because their error stores really are append-only
	 * lists. sby_errors is used here only to build the `latest` sample, and each
	 * entry is period-scoped via the "Error timestamp: <ts>" element that
	 * SBY_Posts_Manager::add_error() appends — entries older than the reporting
	 * period are skipped rather than re-reported every week. Entries without a
	 * parseable timestamp are included rather than silently dropped.
	 *
	 * @param int $ts_start Period start timestamp (0 = no lower bound).
	 * @param int $ts_end   Period end timestamp (0 = no upper bound).
	 * @return array
	 */
	private function get_error_metrics( $ts_start = 0, $ts_end = 0 ) {
		$counters = $this->get_error_counters();

		$errors = get_option( 'sby_errors', array() );
		if ( ! is_array( $errors ) ) {
			$errors = array();
		}

		$latest = array();
		foreach ( $errors as $type => $messages ) {
			if ( count( $latest ) >= 10 ) {
				break;
			}

			// Each type's value is an array of message strings; flatten to one line.
			if ( is_array( $messages ) ) {
				$parts = array();
				foreach ( $messages as $part ) {
					if ( is_scalar( $part ) ) {
						$parts[] = (string) $part;
					}
				}
				$raw = implode( ' | ', $parts );
			} elseif ( is_scalar( $messages ) ) {
				$raw = (string) $messages;
			} else {
				$raw = '';
			}

			// Period-scope on the timestamp add_error() appends to each entry.
			if ( preg_match( '/Error timestamp:\s*(\d+)/', $raw, $m ) ) {
				$err_ts = (int) $m[1];
				if ( ( $ts_start > 0 && $err_ts < $ts_start ) || ( $ts_end > 0 && $err_ts > $ts_end ) ) {
					continue;
				}
			}

			$category = $this->map_error_type( is_string( $type ) ? $type : '' );

			$latest[] = array(
				'category' => $category,
				'message'  => $this->sanitize_error_message( wp_strip_all_tags( $raw ), 300 ),
				'critical' => in_array( $category, array( 'auth', 'permission' ), true ),
			);
		}

		return array(
			'api_failures'   => (int) array_sum( $counters ),
			'by_type'        => $counters,
			'critical_count' => (int) $counters['auth'] + (int) $counters['permission'],
			'source_errors'  => $this->get_source_error_count(),
			'latest'         => $latest,
		);
	}

	/**
	 * Number of sources currently carrying an error.
	 *
	 * The sby_sources.error column is `text NOT NULL default ''`, so "no error"
	 * is the empty string.
	 *
	 * @return int
	 */
	private function get_source_error_count() {
		global $wpdb;

		$sources_table = $wpdb->prefix . 'sby_sources';
		$table_exists  = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $sources_table ) ) === $sources_table;

		if ( ! $table_exists ) {
			return 0;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name derived from $wpdb->prefix, not user input.
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$sources_table} WHERE error != ''" );
	}

	/**
	 * Strip tokens and truncate error message.
	 *
	 * Every message that leaves the site must pass through this.
	 *
	 * @param string $message Raw message.
	 * @param int    $max_len Maximum length before truncation.
	 * @return string
	 */
	private function sanitize_error_message( $message, $max_len = 300 ) {
		// Bare `key` is in the list because it is the credential parameter the
		// YouTube Data API actually uses (SBY_API_Connect::set_url() sends
		// key=<api_key> on Free). The optional quote before the delimiter
		// matches JSON-shaped bodies ("access_token":"..."). preg_replace()
		// returns null on backtrack-limit failure — fail toward '' rather than
		// the raw message.
		$message = (string) preg_replace(
			'/\b(access_token|accesstoken|api_key|api_secret|client_id|client_secret|consumer_key|consumer_secret|secret_key|auth_token|refresh_token|private_key|token|key)["\']?\s*[=:]\s*["\']?[^\s&"\'\\\\,\]}\)]{4,}["\']?/i',
			'$1=[REDACTED]',
			(string) $message
		);
		$message = (string) preg_replace( '/\bBearer\s+[A-Za-z0-9\-._~+\/]+=*/i', 'Bearer [REDACTED]', $message );
		// Shape-based fallbacks for the credential formats Google issues, so a
		// key or token mentioned in prose with no key=value structure is still
		// caught: AIza… API keys, ya29… / 1//… OAuth tokens.
		$message = (string) preg_replace( '/\bAIza[0-9A-Za-z_\-]{35}/', '[REDACTED_API_KEY]', $message );
		$message = (string) preg_replace( '/\b(ya29|1\/\/)[0-9A-Za-z._\-]{10,}/', '[REDACTED_TOKEN]', $message );
		if ( strlen( $message ) > $max_len ) {
			$message = substr( $message, 0, $max_len ) . '...';
		}
		return $message;
	}

	/**
	 * Days active in the given period.
	 *
	 * @param string|int $period_start Start of period.
	 * @param string|int $period_end   End of period.
	 * @return int
	 */
	private function get_days_active( $period_start, $period_end ) {
		$dates = get_option( Config::OPTION_ACTIVE_DATES, array() );
		if ( ! is_array( $dates ) || empty( $dates ) ) {
			return 0;
		}
		$count = 0;
		$start = strtotime( $period_start );
		$end   = strtotime( $period_end );
		foreach ( $dates as $d ) {
			$ts = strtotime( $d );
			if ( false !== $ts && $ts >= $start && $ts <= $end ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Average of last recorded session durations in seconds.
	 *
	 * @return int
	 */
	private function get_session_duration() {
		$durations = get_option( Config::OPTION_SESSION_DURATIONS, array() );
		if ( ! is_array( $durations ) || empty( $durations ) ) {
			return 0;
		}
		return (int) round( array_sum( $durations ) / count( $durations ) );
	}

	/**
	 * Event counts and last_date for each event in the period.
	 *
	 * @param int $ts_start Period start timestamp.
	 * @param int $ts_end   Period end timestamp.
	 * @return array
	 */
	private function get_events_for_period( $ts_start, $ts_end ) {
		unset( $ts_start, $ts_end );

		$events = get_option( EventRecorder::OPTION_NAME, array() );
		if ( ! is_array( $events ) ) {
			return array();
		}

		// The store has held the name-keyed {count,last_date} map since the
		// feature first shipped — no version ever wrote timestamped list
		// entries, so there is no legacy-list branch. A format sniff keyed on
		// the FIRST entry was also unsound: EventRecorder appends name-keyed
		// entries regardless, and a mixed store would silently hide them, while
		// reset_reported_metrics() (which subtracts by event-name key) could
		// never clear numeric-keyed rows — a double-report machine.
		//
		// Accumulate-then-clear format: report all stored events regardless
		// of last_date. The period parameters are payload metadata only — filtering
		// by last_date would silently exclude events recorded today and, combined
		// with the post-send reset, cause permanent data loss for those events.
		$out = array();
		foreach ( $events as $name => $value ) {
			if ( ! is_string( $name ) || '' === $name ) {
				continue;
			}
			if ( is_array( $value ) && isset( $value['count'] ) ) {
				$last_date    = isset( $value['last_date'] ) && is_string( $value['last_date'] ) ? $value['last_date'] : null;
				$out[ $name ] = array(
					'count'     => (int) $value['count'],
					'last_date' => $last_date,
				);
				continue;
			}
			if ( is_numeric( $value ) ) {
				$out[ $name ] = array(
					'count'     => (int) $value,
					'last_date' => null,
				);
			}
		}

		return $out;
	}
}
