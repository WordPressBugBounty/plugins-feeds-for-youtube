<?php

namespace SmashBalloon\YouTubeFeed\Services\Admin\Settings;

use SmashBalloon\YouTubeFeed\Abilities\SBY_Pro_Upsell;
use Smashballoon\Customizer\Container;
use Smashballoon\Customizer\Feed_Builder;
use Smashballoon\Customizer\Feed_Saver;
use SmashBalloon\YouTubeFeed\Helpers\Util;
use SmashBalloon\YouTubeFeed\Pro\SBY_CPT;
use SmashBalloon\YouTubeFeed\SBY_GDPR_Integrations;
use SmashBalloon\YouTubeFeed\SBY_Settings;

class SettingsPage extends BaseSettingPage {
	protected $has_assets = true;
	protected $has_menu = true;
	protected $template_file = 'settings.index';

	/**
	 * @var SBY_Settings
	 */
	private $settings;

	/**
	 * @var Feed_Saver
	 */
	private $feed_saver;

	public function __construct( Feed_Saver $feed_saver, SBY_Settings $settings) {
		$this->page_title = __( 'Settings', 'feeds-for-youtube' );
		$this->menu_title = __( 'Settings', 'feeds-for-youtube' );
		$this->menu_slug  = 'settings';
		$this->menu_position  = 1;
		$this->menu_position_free_version  = 1;

		$this->settings   = $settings;
		$this->feed_saver = $feed_saver;
	}

	public function register() {
		parent::register();

		add_action( 'wp_ajax_sby_update_settings', [ $this, 'handle_settings_update' ] );
		add_action( 'wp_ajax_sby_install_wpconsent', [ $this, 'ajax_install_wpconsent' ] );
		add_action( 'wp_ajax_sby_activate_wpconsent', [ $this, 'ajax_activate_wpconsent' ] );
		add_filter( 'sby_localized_settings', [ $this, 'filter_settings_object' ] );
	}

	public function handle_settings_update() {
		check_ajax_referer( 'sby-admin', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error();
		}

		$sanitization_skip = [
			'custom_js'
		];

		unset( $_POST['nonce'] );

		if ( ! current_user_can( 'unfiltered_html' ) ) {
			unset($_POST['custom_css']);
			unset($_POST['custom_js']);
		}

		// A stale tab must not re-enable MCP Write Access an admin has since turned off.
		// Applied to $_POST itself so the sanitising loop below cannot put the key back.
		$_POST = \SmashBalloon\YouTubeFeed\Abilities\SBY_Write_Gate::reconcile_posted_toggle( $_POST );

		$update_array = $_POST;

		$this->handle_single_video_settings( $update_array );

		foreach ( $_POST as $item => $value ) {
			if ( ! in_array( $item, $sanitization_skip ) ) {
				$update_array[ $item ] = sanitize_text_field( $value );
			}
		}

		wp_clear_scheduled_hook('sby_feed_update');

		if ( $this->settings->update_settings( $update_array ) ) {
			// The stored toggle, so the page can resync after a refused stale ON.
			wp_send_json_success( [ 'aiWriteEnabled' => \SmashBalloon\YouTubeFeed\Abilities\SBY_Write_Gate::stored_enabled() ] );
		} else {
			wp_send_json_error();
		}
	}

	private function handle_single_video_settings( &$settings ) {
		$prefix      = "single_video_info_";
		$includes    = [];
		$post_status = "draft";

		foreach ( $settings as $key => $setting ) {
			if ( false !== strpos( $key, $prefix ) ) {
				$setting_name = str_replace( $prefix, "", $key );
				if ( ( $setting_name !== 'post_status' ) ) {
					if ( $setting === "true" ) {
						$includes[] = $setting_name;
					}
				} else {
					$post_status = $setting;
				}
				unset( $settings[ $key ] );
			}
		}

		update_option( SBY_CPT . '_settings', [
			"include"     => $includes,
			"post_status" => $post_status
		] );
	}

	private function get_next_cron_schedule() {
		$timestamp = wp_next_scheduled('sby_feed_update');
		$date = new \DateTime();
		$date->setTimestamp($timestamp);
		$date->setTimezone(wp_timezone());
		$date_string = $date->format('h:i');
		$settings = $this->settings->get_settings();
		$interval = !empty($settings['cache_cron_interval']) ? $settings['cache_cron_interval'] : '1hour';
		$am_pm = !empty($settings['cache_cron_am_pm']) ? $settings['cache_cron_am_pm'] : 'AM';

		switch($interval) {
			case '30mins':
				$interval_string = __('every 30 minutes', 'feeds-for-youtube');
				break;
			case '12hours':
				$interval_string = 'every 12 hours';
				break;
			case '24hours':
				$interval_string = 'every 24 hours';
				break;
			default:
				$interval_string = __('every hour', 'feeds-for-youtube');
		}

		return sprintf(__('<strong>Next check: %s %s (%s)</strong> - Note: Clicking "Clear All Caches" will reset this schedule.', 'feeds-for-youtube'), $date_string, strtoupper($am_pm), $interval_string);
	}

	public function ajax_install_wpconsent() {
		Util::ajaxPreflightChecks();

		if ( ! current_user_can( 'install_plugins' ) ) {
			wp_send_json_error( __( 'You do not have permission to install plugins.', 'feeds-for-youtube' ) );
		}

		$download_url = 'https://downloads.wordpress.org/plugin/wpconsent-cookies-banner-privacy-suite.zip';
		SetupPage::install_single_plugin( $download_url );

		$plugin_file = 'wpconsent-cookies-banner-privacy-suite/wpconsent.php';
		if ( file_exists( WP_PLUGIN_DIR . '/' . $plugin_file ) ) {
			delete_transient( 'wpconsent_activation_redirect' );
			delete_transient( 'wpconsent_onboarding_redirect' );
			update_option( 'wpconsent_activated', array( 'wpconsent' => time(), 'version' => '0' ) );
			wp_send_json_success( array(
				'msg'       => __( 'WPConsent installed and activated.', 'feeds-for-youtube' ),
				'installed' => true,
				'activated' => is_plugin_active( $plugin_file ),
			) );
		}

		wp_send_json_error( __( 'Could not install WPConsent.', 'feeds-for-youtube' ) );
	}

	public function ajax_activate_wpconsent() {
		Util::ajaxPreflightChecks();

		if ( ! current_user_can( 'activate_plugins' ) ) {
			wp_send_json_error( __( 'You do not have permission to activate plugins.', 'feeds-for-youtube' ) );
		}

		$plugin_file = 'wpconsent-cookies-banner-privacy-suite/wpconsent.php';
		$activated = activate_plugin( $plugin_file );

		if ( ! is_wp_error( $activated ) ) {
			delete_transient( 'wpconsent_activation_redirect' );
			delete_transient( 'wpconsent_onboarding_redirect' );
			update_option( 'wpconsent_activated', array( 'wpconsent' => time(), 'version' => '0' ) );
			wp_send_json_success( array(
				'msg'       => __( 'WPConsent activated.', 'feeds-for-youtube' ),
				'activated' => true,
			) );
		}

		wp_send_json_error( __( 'Could not activate WPConsent.', 'feeds-for-youtube' ) );
	}

	public function filter_settings_object( $settings ) {
		$settings['settings'] = $this->settings->get_settings();
		$settings['sources']  = $this->feed_saver->get_source_list();
		$settings['sbyIsPro'] = (bool) \sby_is_pro();
		$settings['user_can_unfiltered_html'] = \current_user_can( 'unfiltered_html' ) ? true : false;
		$settings['feeds']  = Container::getInstance()->get(Feed_Builder::class)->get_feed_list();
		$settings['next_cron'] = $this->get_next_cron_schedule();
		$settings['connect_site_parameters'] = sby_builder_pro()->oauth_connet_parameters();

		// Expose the shared Consent (data-sharing / notifications) flags + links
		// so the React Data Controls tab can seed its initial state. Persistence
		// itself is handled by the Consent package (window.sbcConsent), not
		// sby_settings. Shared with the onboarding Setup page via the base class.
		$settings = array_merge( $settings, $this->consent_settings( 'settings', true ) );

		$settings['activeGdprPlugin'] = SBY_GDPR_Integrations::gdpr_plugins_active();

		// AI Agents / MCP Write Access are Abilities-API surfaces. Both editions
		// instantiate SBY_Abilities (youtube-feed.php / youtube-feed-pro.php), so
		// the card is offered wherever the core Abilities API exists (WP 6.9+) and
		// hidden otherwise -- a site on WP 6.8 would otherwise see an "Install
		// WPVibe" card and a write-consent toggle with no abilities behind them
		// (AgDR-9005 §4). The `available` flag lets the React side
		// (GeneralSettings.js) skip rendering both boxes rather than relying on
		// the PHP omission alone.
		//
		// The gate + payload are split into the two static methods below
		// (abilities_available() / build_ai_agents_settings()) so
		// tests/free-build-gate-test.php can exercise the real code path under
		// both "WP without the Abilities API" and "WP with it" with no WP bootstrap.
		$ai_agents_settings = self::build_ai_agents_settings( [ $this, 'get_ai_agents_data' ] );
		$settings['aiAgents'] = $ai_agents_settings['aiAgents'];
		$settings['mcpWrite'] = $ai_agents_settings['mcpWrite'];

		return $settings;
	}

	/**
	 * Whether the Abilities-API surfaces (AI Agents card, MCP Write Access
	 * toggle) are available: the core Abilities API is registered (edition-
	 * agnostic, WP 6.9+). Static and side-effect-free, so it's directly
	 * testable (see tests/free-build-gate-test.php).
	 *
	 * @return bool
	 */
	public static function abilities_available() {
		return \SmashBalloon\YouTubeFeed\Abilities\SBY_Abilities::is_available();
	}

	/**
	 * Build the `aiAgents` / `mcpWrite` settings payload. Static, taking the
	 * Pro-only data source as a callable rather than calling
	 * $this->get_ai_agents_data() directly, so the free-edition branch --
	 * the one a merge-blocking test needs to exercise -- never touches
	 * get_ai_agents_data()'s WP dependencies (get_wpvibe_status(), wp_kses(),
	 * admin_url(), ...); it's simply never invoked when $available is false.
	 *
	 * @param callable $ai_agents_data_provider fn(): array, i.e. get_ai_agents_data().
	 * @return array{aiAgents: array, mcpWrite: array}
	 */
	public static function build_ai_agents_settings( $ai_agents_data_provider ) {
		if ( ! self::abilities_available() ) {
			return [
				'aiAgents' => [ 'available' => false ],
				'mcpWrite' => [ 'available' => false ],
			];
		}

		return [
			'aiAgents' => array_merge( call_user_func( $ai_agents_data_provider ), [ 'available' => true ] ),
			// `title`/`helpText` are deliberately NOT localized here (Alex's PR#741
			// architecture audit, finding 6): AiAssistantSection.js already hard-codes
			// its own `__()` strings for this box and never reads this payload, so a
			// second translated copy here was a silent fork-and-drift risk with no
			// reader. `available` is kept -- it's the merge-blocking gate's own
			// payload shape and tests/free-build-gate-test.php asserts against it.
			'mcpWrite' => [
				'available' => true,
			],
		];
	}

	/**
	 * Determine WPVibe's install/activation status.
	 *
	 * Scans the installed-plugins list for a plugin whose folder is
	 * `vibe-ai/`, rather than assuming a specific main-file name -- a
	 * plugin's wp.org slug and its main file are not guaranteed to match.
	 *
	 * @return array{status: string, basename: string|null} status is one
	 *               of 'not_installed' | 'inactive' | 'active'.
	 */
	private function get_wpvibe_status() {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$wpvibe_basename = null;
		foreach ( array_keys( get_plugins() ) as $plugin_file ) {
			if ( strtok( $plugin_file, '/' ) === 'vibe-ai' ) {
				$wpvibe_basename = $plugin_file;
				break;
			}
		}

		if ( null === $wpvibe_basename ) {
			return [
				'status'   => 'not_installed',
				'basename' => null,
			];
		}

		return [
			'status'   => is_plugin_active( $wpvibe_basename ) ? 'active' : 'inactive',
			'basename' => $wpvibe_basename,
		];
	}

	/**
	 * Data for the "Do more with AI on Pro" strip in the AI Agents card.
	 *
	 * Free edition only; returns an empty array in Pro. Feature names come from
	 * the free upgrade CTA's own list (SBY_Pro_Upsell::BANNER_FEATURES), so the
	 * strip carries no copy of its own beyond the title and button label. React
	 * renders every value as text (no raw-HTML sink).
	 *
	 * @return array{title: string, features: string[], button: string, newTab: string, url: string}|array{}
	 */
	public static function get_ai_pro_upsell() {
		if ( ! SBY_Pro_Upsell::is_free() ) {
			return [];
		}
		return [
			'title'    => __( 'Do more with AI on Pro', 'feeds-for-youtube' ),
			'features' => SBY_Pro_Upsell::banner_feature_names(),
			'button'   => __( 'Upgrade to Pro', 'feeds-for-youtube' ),
			'newTab'   => __( '(opens in a new tab)', 'feeds-for-youtube' ),
			'url'      => esc_url_raw( SBY_Pro_Upsell::upgrade_url( 'ai-agents-card', 'settings' ) ),
		];
	}

	/**
	 * Build the AI Agents card data for the General tab -- WPVibe's
	 * current status plus the four display states the React card renders.
	 *
	 * Install/activation is handled by the plugin's existing generic addon
	 * AJAX actions (sby_install_addon / sby_activate_addon, registered in
	 * AboutPage), the same actions the About page's plugin cards already
	 * use -- no new install surface is introduced for WPVibe.
	 *
	 * @return array
	 */
	private function get_ai_agents_data() {
		$wpvibe            = $this->get_wpvibe_status();
		$wpvibe_org_url    = 'https://wordpress.org/plugins/vibe-ai/';
		/* translators: %s: URL to the WPVibe plugin on WordPress.org */
		$wpvibe_description = sprintf(
			__( 'Manage YouTube Feed from your favorite AI agent.<br>Works through <a href="%s" target="_blank" rel="noopener">WPVibe</a>, a free plugin you install once.', 'feeds-for-youtube' ),
			esc_url( $wpvibe_org_url )
		);

		$states = [
			'not_installed' => [
				'heading'     => __( 'Use YouTube Feed with Claude, ChatGPT and more.', 'feeds-for-youtube' ),
				'description' => $wpvibe_description,
				'button'      => __( 'Install WPVibe for free', 'feeds-for-youtube' ),
			],
			'inactive'       => [
				'heading'     => __( 'Use YouTube Feed with Claude, ChatGPT and more.', 'feeds-for-youtube' ),
				'description' => $wpvibe_description,
				'button'      => __( 'Activate WPVibe', 'feeds-for-youtube' ),
			],
			'active'         => [
				'heading'     => __( 'WPVibe is connected to YouTube Feed.', 'feeds-for-youtube' ),
				'description' => __( 'Manage YouTube Feed from your favorite AI agent.<br>Connect Claude, ChatGPT, Cursor or any MCP client in WPVibe.', 'feeds-for-youtube' ),
				'button'      => __( 'Open WPVibe', 'feeds-for-youtube' ),
			],
			'installFailed'  => [
				'heading'     => __( 'Use YouTube Feed with Claude, ChatGPT and more.', 'feeds-for-youtube' ),
				'description' => $wpvibe_description,
				'button'      => __( 'Try again', 'feeds-for-youtube' ),
				/* translators: %s: URL to the Plugins screen */
				'error'       => sprintf( __( 'Couldn\'t install WPVibe. <a href="%s">Install it from the Plugins screen</a> instead.', 'feeds-for-youtube' ), esc_url( admin_url( 'plugins.php' ) ) ),
			],
		];

		// `heading` / `description` / `error` reach the browser through
		// html-react-parser's parse() (AiAgentsSection.js), a raw-HTML sink --
		// translators supply the <br>/<a> markup above, so wp_kses with a
		// minimal allowlist (rather than esc_html, which would print the tags
		// literally) is the fix this class of string needs.
		$allowed_html = [
			'a'      => [
				'href'   => true,
				'target' => true,
				'rel'    => true,
			],
			'br'     => [],
			'strong' => [],
		];
		foreach ( $states as $state_key => $state ) {
			foreach ( [ 'heading', 'description', 'error' ] as $markup_key ) {
				if ( isset( $state[ $markup_key ] ) ) {
					$states[ $state_key ][ $markup_key ] = wp_kses( $state[ $markup_key ], $allowed_html );
				}
			}
		}

		return [
			'title'              => __( 'AI Agents', 'feeds-for-youtube' ),
			'appsLabel'          => __( 'Works with ChatGPT, Cursor and Claude', 'feeds-for-youtube' ),
			// "Do more with AI on Pro" strip under the MCP Write Access box (AgDR-9006).
			// Free edition only: an empty array in Pro (both editions ship the same JS
			// bundle), which AiProUpsell treats as "render nothing".
			'proUpsell'          => self::get_ai_pro_upsell(),
			'status'             => $wpvibe['status'],
			'basename'           => $wpvibe['basename'],
			'installDownloadUrl' => 'https://downloads.wordpress.org/plugin/vibe-ai.zip',
			'adminUrl'           => admin_url( 'admin.php?page=vibe-ai' ),
			'states'             => $states,
		];
	}
}