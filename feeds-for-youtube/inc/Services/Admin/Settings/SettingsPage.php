<?php

namespace SmashBalloon\YouTubeFeed\Services\Admin\Settings;

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

		$update_array = $_POST;

		$this->handle_single_video_settings( $update_array );

		foreach ( $_POST as $item => $value ) {
			if ( ! in_array( $item, $sanitization_skip ) ) {
				$update_array[ $item ] = sanitize_text_field( $value );
			}
		}

		wp_clear_scheduled_hook('sby_feed_update');

		if ( $this->settings->update_settings( $update_array ) ) {
			wp_send_json_success();
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
		$settings['sbyIsPro'] = \sby_is_pro() ? true : false;
		$settings['user_can_unfiltered_html'] = \current_user_can( 'unfiltered_html' ) ? true : false;
		$settings['feeds']  = Container::getInstance()->get(Feed_Builder::class)->get_feed_list();
		$settings['next_cron'] = $this->get_next_cron_schedule();
		$settings['connect_site_parameters'] = sby_builder_pro()->oauth_connet_parameters();

		$wpconsent_file = 'wpconsent-cookies-banner-privacy-suite/wpconsent.php';
		$settings['wpconsentScreen'] = [
			'isPluginInstalled' => file_exists( WP_PLUGIN_DIR . '/' . $wpconsent_file ),
			'isPluginActive'    => is_plugin_active( $wpconsent_file ),
			'pluginFile'        => $wpconsent_file,
			'downloadUrl'       => 'https://downloads.wordpress.org/plugin/wpconsent-cookies-banner-privacy-suite.zip',
		];
		$settings['activeGdprPlugin'] = SBY_GDPR_Integrations::gdpr_plugins_active();

		return $settings;
	}
}