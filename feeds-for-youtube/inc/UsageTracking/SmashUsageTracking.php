<?php
/**
 * Orchestrates Smash Usage Tracking for YouTube Feeds:
 * schedule, ensure site token, build payload, send.
 *
 * @package SmashBalloon\YouTubeFeed\UsageTracking
 */

namespace SmashBalloon\YouTubeFeed\UsageTracking;

use SmashBalloon\YouTubeFeed\UsageTracking\Core\RegisterSite;
use SmashBalloon\YouTubeFeed\UsageTracking\Core\Sender;
use SmashBalloon\YouTubeFeed\UsageTracking\Core\PayloadBuilder;
use SmashBalloon\YouTubeFeed\UsageTracking\Core\Scheduler;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SmashUsageTracking {

	/**
	 * Plugin-specific reporter.
	 *
	 * @var ReporterInterface
	 */
	private $reporter;

	/**
	 * Register-site client.
	 *
	 * @var RegisterSite
	 */
	private $register_site;

	/**
	 * Payload sender.
	 *
	 * @var Sender
	 */
	private $sender;

	/**
	 * Payload builder.
	 *
	 * @var PayloadBuilder
	 */
	private $payload_builder;

	/**
	 * Cron scheduler.
	 *
	 * @var Scheduler
	 */
	private $scheduler;


	/**
	 * AJAX action (without the wp_ajax_ prefix) => event name recorded when it runs.
	 *
	 * These are passive listeners: they attach at priority 5 so they run before the
	 * action's own handler (which ends the request with a JSON response) and must
	 * never emit output of their own. Because priority 5 runs BEFORE the primary
	 * handler's checks, record_action_event() replicates the same sby-admin
	 * nonce + capability check (non-dying) before its option write — otherwise
	 * any logged-in user could inflate event counters via bare admin-ajax POSTs.
	 *
	 * The legacy sbi_source_builder_update(_multiple) actions are deliberately
	 * NOT mapped: Instagram Feed registers the identical action names, so on a
	 * site running both plugins a listener here would record an Instagram
	 * source connection as a YouTube source_connected. The unambiguous
	 * sby_process_access_token / sby_manual_access_token cover this plugin.
	 *
	 * @var array<string,string>
	 */
	private static $event_actions = array(
		'sby_feed_saver_manager_delete_feeds'            => 'feed_deleted',
		'sby_feed_saver_manager_duplicate_feed'          => 'feed_duplicated',
		'sby_feed_refresh'                               => 'feed_refreshed',
		'sby_process_access_token'                       => 'source_connected',
		'sby_manual_access_token'                        => 'source_connected',
		'sby_add_api_key'                                => 'api_key_added',
		'verify_api_key'                                 => 'api_key_added',
		'sby_ca_after_remove_clicked'                    => 'source_deleted',
		'remove_connected_account'                       => 'source_deleted',
		'sby_update_settings'                            => 'settings_saved',
		'sby_clear_cache'                                => 'caches_cleared',
		'sby_feed_saver_manager_clear_single_feed_cache' => 'feed_cache_cleared',
		'sby_license_activation'                         => 'license_activated',
		'sby_license_deactivation'                       => 'license_deactivated',
		'sby_install_other_plugins'                      => 'plugin_installed',
		'sby_install_addon'                              => 'plugin_installed',
		'sby_activate_other_plugins'                     => 'plugin_activated',
		'sby_activate_addon'                             => 'plugin_activated',
		'sby_maybe_upgrade_redirect'                     => 'upgrade_initiated',
		'sby_process_wizard'                             => 'setup_wizard_completed',
		'sby_dismiss_wizard'                             => 'setup_wizard_dismissed',
	);

	/**
	 * Constructor.
	 *
	 * @param ReporterInterface $reporter Plugin-specific reporter.
	 */
	public function __construct( ReporterInterface $reporter ) {
		$this->reporter        = $reporter;
		$this->register_site   = new RegisterSite();
		$this->sender          = new Sender();
		$this->payload_builder = new PayloadBuilder( $reporter );
		$this->scheduler       = new Scheduler();
	}

	/**
	 * Register all hooks. Called from the service wrapper's register() method.
	 */
	public function init() {
		add_action( 'init', array( $this, 'maybe_schedule' ) );
		add_filter( 'cron_schedules', array( $this->scheduler, 'add_schedules' ) );
		add_action( Config::CRON_HOOK, array( $this, 'send_checkin' ) );

		add_action( 'current_screen', array( $this, 'maybe_record_active_day' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_session_script' ), 20 );
		add_action( 'wp_ajax_sby_smash_usage_record_session', array( $this, 'ajax_record_session' ) );

		foreach ( array_keys( self::$event_actions ) as $action ) {
			add_action( 'wp_ajax_' . $action, array( $this, 'record_action_event' ), 5 );
		}

		// Feed create and feed update share a single AJAX action, so the generic
		// map cannot distinguish them: a dedicated listener inspects the payload.
		add_action( 'wp_ajax_sby_feed_saver_manager_builder_update', array( $this, 'on_feed_saved' ), 5 );

		add_action( 'sby_api_error', array( $this, 'on_api_error' ), 10, 3 );

		if ( defined( 'WP_CLI' ) && WP_CLI && class_exists( '\WP_CLI' ) ) {
			\WP_CLI::add_command(
				'sby usage-preview',
				function () {
					if ( ! class_exists( '\WP_CLI' ) ) {
						return;
					}
					\WP_CLI::print_value( $this->get_payload_preview(), array( 'format' => 'json' ) );
				}
			);
		}
	}

	/**
	 * Non-dying replica of the primary AJAX handlers' checks. The listeners run
	 * at priority 5 — before the primary handler verifies the request — so each
	 * must gate its option write on a valid admin nonce and the plugin
	 * capability, without ending the request (the primary handler owns the
	 * response). The mapped actions verify different nonce actions
	 * ('sby-admin', 'sby_nonce', 'sbspf_nonce' depending on handler), so any
	 * of them is accepted here. The MiscService handlers additionally send
	 * their nonce in the sbspf_nonce REQUEST FIELD, not 'nonce' — without
	 * checking that field, every legitimate OAuth source-connect and
	 * account-delete would silently fail this guard and never be recorded.
	 *
	 * @return bool
	 */
	private function verify_admin_ajax_request() {
		$nonce_ok = false;
		foreach ( array( 'sby-admin', 'sby_nonce' ) as $nonce_action ) {
			if ( false !== check_ajax_referer( $nonce_action, 'nonce', false ) ) {
				$nonce_ok = true;
				break;
			}
		}
		if ( ! $nonce_ok && isset( $_REQUEST['sbspf_nonce'] ) ) {
			$sbspf_nonce = sanitize_text_field( wp_unslash( $_REQUEST['sbspf_nonce'] ) );
			$nonce_ok    = false !== wp_verify_nonce( $sbspf_nonce, 'sbspf_nonce' );
		}
		if ( ! $nonce_ok ) {
			return false;
		}
		if ( function_exists( 'sby_current_user_can' ) ) {
			return (bool) sby_current_user_can( 'manage_youtube_feed_options' );
		}
		return current_user_can( 'manage_options' );
	}

	/**
	 * Record the event mapped to the AJAX action currently running.
	 * Runs at priority 5 — must NOT send any response.
	 */
	public function record_action_event() {
		if ( ! $this->verify_admin_ajax_request() ) {
			return;
		}
		$action = (string) current_action();
		if ( 0 === strpos( $action, 'wp_ajax_' ) ) {
			$action = substr( $action, strlen( 'wp_ajax_' ) );
		}
		if ( isset( self::$event_actions[ $action ] ) ) {
			EventRecorder::record( self::$event_actions[ $action ] );
		}
	}

	/**
	 * Record feed_created or feed_saved for the shared builder-update action.
	 * Runs at priority 5 — must NOT send any response.
	 */
	public function on_feed_saved() {
		if ( ! $this->verify_admin_ajax_request() ) {
			return;
		}
		// Nonce verified above (non-dying), so reading the request field is safe.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$is_new_feed = ! empty( $_POST['new_insert'] );
		EventRecorder::record( $is_new_feed ? 'feed_created' : 'feed_saved' );
	}

	/**
	 * Map a YouTube API error reason to a counter category and record it.
	 *
	 * @param string $reason  API error reason code (e.g. quotaExceeded).
	 * @param mixed  $code    HTTP or API status code. Unused, accepted for hook parity.
	 * @param string $message Error message. Unused, accepted for hook parity.
	 */
	public function on_api_error( $reason = '', $code = 0, $message = '' ) {
		unset( $code, $message );

		$reason = is_string( $reason ) ? $reason : '';

		switch ( $reason ) {
			case 'quotaExceeded':
			case 'dailyLimitExceeded':
				$category = 'quota';
				break;
			case 'rateLimitExceeded':
			case 'userRateLimitExceeded':
				$category = 'rate_limit';
				break;
			case 'authError':
			case 'invalidCredentials':
			case 'unauthorized':
			// keyInvalid is the Free plugin's primary credential failure —
			// Free authenticates with key=<api_key>, not an OAuth token.
			case 'keyInvalid':
				$category = 'auth';
				break;
			case 'forbidden':
			case 'insufficientPermissions':
			case 'accessNotConfigured':
			case 'ipRefererBlocked':
				$category = 'permission';
				break;
			case 'notFound':
			case 'videoNotFound':
			case 'channelNotFound':
			case 'playlistNotFound':
				$category = 'not_found';
				break;
			case 'backendError':
			case 'internalError':
			case 'processingFailure':
			case 'SERVICE_UNAVAILABLE':
				$category = 'server';
				break;
			case 'network':
				$category = 'network';
				break;
			default:
				$category = 'other';
				break;
		}

		EventRecorder::increment_error_counter( $category );
	}

	/**
	 * Schedule cron if enabled and not already scheduled.
	 */
	public function maybe_schedule() {
		$this->scheduler->schedule();
	}

	/**
	 * Cron callback: ensure site token, build payload, send, update last_send.
	 */
	public function send_checkin() {
		if ( ! Config::is_enabled() ) {
			return;
		}

		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( 'smashballoon.com' === $host || '.smashballoon.com' === substr( (string) $host, -17 ) ) {
			return;
		}

		$opt       = get_option( Config::OPTION_TRACKING, array() );
		$last_send = is_array( $opt ) && isset( $opt['last_send'] ) ? (int) $opt['last_send'] : 0;
		// -6 days, not -1 week: last_send is stamped AFTER the send completes,
		// so with punctual cron the next weekly run fires slightly less than
		// 7 days later and an exact-week guard would skip every other run.
		if ( $last_send > strtotime( '-6 days' ) ) {
			return;
		}

		// The last_send guard alone can't stop two overlapping runners (e.g.
		// multi-server wp-cron) — it is only updated after the up-to-30s send
		// completes. A second concurrent send would double-report and then
		// double-subtract counters in reset_reported_metrics().
		if ( false !== get_transient( 'sby_smash_usage_sending_lock' ) ) {
			return;
		}
		set_transient( 'sby_smash_usage_sending_lock', 1, 2 * MINUTE_IN_SECONDS );

		$site_token = get_option( Config::OPTION_SITE_TOKEN, '' );
		if ( '' === $site_token || ! is_string( $site_token ) ) {
			$site_token = $this->register_site->register( $this->reporter );
			if ( null === $site_token ) {
				delete_transient( 'sby_smash_usage_sending_lock' );
				return;
			}
		}

		$period_end   = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );
		$period_start = gmdate( 'Y-m-d', time() - 7 * DAY_IN_SECONDS );

		// Snapshot the durations present before the payload is built so the
		// post-send reset removes exactly those entries (by value), even when
		// the store's 10-entry cap displaces old entries during the send.
		$durations_before = get_option( Config::OPTION_SESSION_DURATIONS, array() );
		$durations_before = is_array( $durations_before ) ? array_values( $durations_before ) : array();

		$payload = $this->payload_builder->build( $site_token, $period_start, $period_end );
		$code    = $this->sender->send( $payload );

		if ( $code >= 200 && $code < 300 ) {
			update_option( Config::OPTION_TRACKING, array( 'last_send' => time() ), false );
			$sent_events = isset( $payload['dynamic_metrics']['events'] ) && is_array( $payload['dynamic_metrics']['events'] )
				? $payload['dynamic_metrics']['events']
				: array();
			// The reported counters are the payload's own errors.by_type map, so read
			// them back off the payload rather than re-reading the option: anything
			// recorded after the payload was built must not be subtracted.
			$sent_counters = isset( $payload['errors']['by_type'] ) && is_array( $payload['errors']['by_type'] )
				? $payload['errors']['by_type']
				: array();
			$this->reset_reported_metrics( $sent_events, $sent_counters, $durations_before );
		} elseif ( $this->sender->last_error_rejected_token( $code ) ) {
			// The API rejected the site token (revoked/unknown). Drop it so the
			// next weekly run re-registers instead of retrying a dead token forever.
			delete_option( Config::OPTION_SITE_TOKEN );
		}

		delete_transient( 'sby_smash_usage_sending_lock' );
	}


	/**
	 * Clear reported dynamic metrics after a successful send: removes only the
	 * event keys that were included in the sent payload, subtracts only the
	 * error counts that were reported, and drops only the session durations
	 * that existed at build time — anything recorded during the send window
	 * rolls into the next period.
	 *
	 * @param array $sent_events        Events map from the sent payload.
	 * @param array $sent_counters      Error counters snapshot taken at build time.
	 * @param array $reported_durations Session-duration entries present at build time.
	 */
	private function reset_reported_metrics( array $sent_events, array $sent_counters, array $reported_durations = array() ) {
		if ( ! empty( $sent_events ) ) {
			$stored = get_option( EventRecorder::OPTION_NAME, array() );
			if ( is_array( $stored ) ) {
				foreach ( $sent_events as $key => $sent ) {
					if ( ! isset( $stored[ $key ] ) ) {
						continue;
					}

					// Subtract the reported count rather than unsetting the key: a
					// concurrent request can increment an event AFTER the payload was
					// built but BEFORE this runs, and unsetting would discard those
					// extra occurrences. Only drop the key once nothing is left.
					$sent_count   = is_array( $sent ) && isset( $sent['count'] ) ? (int) $sent['count'] : 0;
					$stored_count = is_array( $stored[ $key ] ) && isset( $stored[ $key ]['count'] )
						? (int) $stored[ $key ]['count']
						: 0;
					$remaining    = $stored_count - $sent_count;

					if ( $remaining > 0 ) {
						$stored[ $key ]['count'] = $remaining;
					} else {
						unset( $stored[ $key ] );
					}
				}
				update_option( EventRecorder::OPTION_NAME, $stored, false );
			}
		}
		// Do not wipe the event store when $sent_events is empty: the store may
		// contain valid events that were not included in this payload (e.g. recorded
		// after the payload was built). They should roll into the next period.

		if ( ! empty( $sent_counters ) ) {
			$stored_counters = get_option( Config::OPTION_ERROR_COUNTERS, array() );
			if ( is_array( $stored_counters ) ) {
				// Subtract rather than zero, for the same reason: errors recorded
				// while the payload was in flight must survive into the next period.
				foreach ( $sent_counters as $key => $sent_count ) {
					if ( ! isset( $stored_counters[ $key ] ) ) {
						continue;
					}
					$stored_counters[ $key ] = max( 0, (int) $stored_counters[ $key ] - (int) $sent_count );
				}
				update_option( Config::OPTION_ERROR_COUNTERS, $stored_counters, false );
			}
		}

		// Same concurrency care for durations: remove one occurrence of each
		// REPORTED value (multiset diff) rather than slicing by count — the
		// store keeps only the last 10 entries, so a count-based slice would
		// delete the new unreported sessions whenever the cap displaced old
		// reported ones during the send window.
		$durations = get_option( Config::OPTION_SESSION_DURATIONS, array() );
		$durations = is_array( $durations ) ? array_values( $durations ) : array();
		foreach ( $reported_durations as $reported ) {
			$idx = array_search( $reported, $durations, true );
			if ( false !== $idx ) {
				unset( $durations[ $idx ] );
			}
		}
		update_option( Config::OPTION_SESSION_DURATIONS, array_values( $durations ), false );
	}

	/**
	 * Unschedule cron (call when disabling tracking).
	 */
	public function unschedule() {
		$this->scheduler->unschedule();
	}

	/**
	 * Record active day and optionally settings_page_viewed when on an SBY admin page.
	 *
	 * @param \WP_Screen|null $screen Current screen (fallback gate only).
	 */
	public function maybe_record_active_day( $screen = null ) {
		if ( ! Config::is_enabled() ) {
			return;
		}
		if ( ! $this->is_plugin_admin_page( $screen ) ) {
			return;
		}

		EventRecorder::record_active_day();

		if ( $this->is_settings_page() ) {
			EventRecorder::record( 'settings_page_viewed' );
		}
	}

	/**
	 * Whether the current request is one of this plugin's admin pages.
	 *
	 * Prefers the plugin's own sby_is_admin_page() helper, which is loaded from
	 * inc/Admin/admin-functions.php during sby_init_components(). Falls back to
	 * screen-id matching if the helper is not available.
	 *
	 * @param \WP_Screen|null $screen Current screen, when available.
	 * @return bool
	 */
	private function is_plugin_admin_page( $screen = null ) {
		if ( function_exists( 'sby_is_admin_page' ) ) {
			return (bool) \sby_is_admin_page();
		}

		if ( ! $screen && function_exists( 'get_current_screen' ) ) {
			$screen = get_current_screen();
		}
		if ( ! $screen || ! isset( $screen->id ) ) {
			return false;
		}

		return false !== strpos( (string) $screen->id, 'sby' )
			|| false !== strpos( (string) $screen->id, 'youtube-feed' );
	}

	/**
	 * Whether the current request is the plugin settings page.
	 *
	 * @return bool
	 */
	private function is_settings_page() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only page check on an admin screen; no state change.
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		return 'youtube-feed-settings' === $page;
	}

	/**
	 * AJAX: record session duration from JS (seconds).
	 */
	public function ajax_record_session() {
		check_ajax_referer( 'sby_smash_usage_record_session', 'nonce' );
		// Same capability the plugin's own pages gate on: a user holding only
		// the custom manage_youtube_feed_options cap sees the admin pages and
		// gets the script enqueued, so manage_options alone would silently
		// drop every session they generate.
		$can = function_exists( 'sby_current_user_can' ) ? sby_current_user_can( 'manage_youtube_feed_options' ) : current_user_can( 'manage_options' );
		if ( ! $can || ! Config::is_enabled() ) {
			wp_send_json_error();
		}
		$seconds = isset( $_POST['duration_seconds'] ) ? (int) $_POST['duration_seconds'] : 0;
		EventRecorder::record_session_duration( $seconds );
		wp_send_json_success();
	}

	/**
	 * Enqueue the session-duration script on SBY admin pages.
	 */
	public function enqueue_session_script() {
		if ( ! $this->is_plugin_admin_page() ) {
			return;
		}
		$script_path = 'admin/js/smash-usage-session.js';
		$path        = defined( 'SBY_PLUGIN_DIR' ) ? trailingslashit( SBY_PLUGIN_DIR ) . $script_path : '';
		if ( '' === $path || ! file_exists( $path ) ) {
			return;
		}
		wp_enqueue_script(
			'sby-smash-usage-session',
			defined( 'SBY_PLUGIN_URL' ) ? trailingslashit( SBY_PLUGIN_URL ) . $script_path : '',
			array( 'jquery' ),
			defined( 'SBYVER' ) ? SBYVER : '1.0',
			true
		);
		wp_localize_script(
			'sby-smash-usage-session',
			'sbySmashUsageSession',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'sby_smash_usage_record_session' ),
			)
		);
	}

	/**
	 * Build the usage report payload without sending (for preview/debugging).
	 *
	 * @return array
	 */
	public function get_payload_preview() {
		$period_end   = gmdate( 'Y-m-d', time() - DAY_IN_SECONDS );
		$period_start = gmdate( 'Y-m-d', time() - 7 * DAY_IN_SECONDS );

		return $this->payload_builder->build( 'preview-no-api', $period_start, $period_end );
	}
}
