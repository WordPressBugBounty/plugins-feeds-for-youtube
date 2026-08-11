<?php
/**
 * Smash Usage Tracking configuration for YouTube Feeds.
 *
 * API URL and option names.
 *
 * @package SmashBalloon\YouTubeFeed\UsageTracking
 */

namespace SmashBalloon\YouTubeFeed\UsageTracking;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Config {

	/**
	 * Option key: last_send timestamp only. Consent lives in
	 * the sby_settings option under the 'usagetracking' key.
	 */
	const OPTION_TRACKING = 'sby_smash_usage_tracking';

	/**
	 * Option key: site token returned by the API.
	 */
	const OPTION_SITE_TOKEN = 'sby_smash_usage_tracking_site_token';

	/**
	 * Option key: schedule metadata.
	 */
	const OPTION_SCHEDULE = 'sby_smash_usage_tracking_schedule';

	/**
	 * Option key: dates when plugin was active (Y-m-d), for days_active metric.
	 */
	const OPTION_ACTIVE_DATES = 'sby_smash_usage_active_dates';

	/**
	 * Option key: last N session durations in seconds, for session_duration metric.
	 */
	const OPTION_SESSION_DURATIONS = 'sby_smash_usage_session_durations';

	/**
	 * Option key: API error counters keyed by category.
	 */
	const OPTION_ERROR_COUNTERS = 'sby_smash_usage_error_counters';

	/**
	 * Cron hook name.
	 */
	const CRON_HOOK = 'sby_smash_usage_tracking_cron';

	/**
	 * Max request timeout in seconds for usage report.
	 */
	const REQUEST_TIMEOUT = 30;

	/**
	 * Max payload size in bytes before send is skipped (default 2MB).
	 */
	const MAX_PAYLOAD_BYTES = 2097152;

	/**
	 * Register-site endpoint path (relative to API base).
	 */
	const REGISTER_SITE_PATH = '/v1/register-site';

	/**
	 * Usage report endpoint path (relative to API base).
	 */
	const USAGE_REPORT_PATH = '/v1/usage-report';

	/**
	 * Get the API base URL (filterable).
	 *
	 * @return string
	 */
	public static function get_api_url() {
		$url = defined( 'SBY_SMASH_USAGE_TRACKING_API_URL' ) ? SBY_SMASH_USAGE_TRACKING_API_URL : '';
		return (string) apply_filters( 'sby_smash_usage_tracking_api_url', $url );
	}

	/**
	 * Get full URL for register-site endpoint.
	 *
	 * @return string
	 */
	public static function get_register_site_url() {
		return rtrim( self::get_api_url(), '/' ) . self::REGISTER_SITE_PATH;
	}

	/**
	 * Get full URL for usage-report endpoint.
	 *
	 * @return string
	 */
	public static function get_usage_report_url() {
		return rtrim( self::get_api_url(), '/' ) . self::USAGE_REPORT_PATH;
	}

	/**
	 * Check if tracking is enabled.
	 *
	 * Consent is stored in the sby_settings array under 'usagetracking'.
	 * When the key is absent, defaults to enabled on Pro and disabled on Free.
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$sby_settings = get_option( 'sby_settings', array() );
		if ( is_array( $sby_settings ) && array_key_exists( 'usagetracking', $sby_settings ) ) {
			return (bool) $sby_settings['usagetracking'];
		}

		return defined( 'SBY_PRO' ) && SBY_PRO;
	}
}
