<?php
/**
 * Upgrade to the Pro version
 *
 * @since 4.0
 */

namespace SmashBalloon\YouTubeFeed\Admin;


class SBY_Upgrader {

	/**
	 * URL where licensing is done
	 */
	const STORE_URL = 'https://smashballoon.com/';

	/**
	 * URL to connect to Smash Balloon App and upgrade to Pro
	 */
	const UPGRADE_URL = 'https://connect.smashballoon.com/activate/index.php';

	/**
	 * Check the license key URL
	 */
	const CHECK_URL = 'https://connect.smashballoon.com/activate/check.php';

	const NAME = 'youtube';

	const SLUG = 'youtube-feed-pro/youtube-feed-pro.php';

	const REDIRECT = 'youtube-feed-settings';

	const INSTALL_INSTRUCTIONS = 'https://smashballoon.com/doc/setting-up-the-feeds-for-youtube-pro-wordpress-plugin/?youtube&utm_campaign=youtube-free&utm_source=settings&utm_medium=freetopro&utm_content=Upgrade Manually';


	/**
	 * AJAX hooks for creating the redirect
	 *
	 * @since 4.0
	 */
	public function hooks() {
		add_action( 'wp_ajax_nopriv_sby_run_one_click_upgrade', array( 'SmashBalloon\YouTubeFeed\Admin\SBY_Upgrader', 'install_upgrade' ) );
		add_action( 'wp_ajax_sby_maybe_upgrade_redirect', array( 'SmashBalloon\YouTubeFeed\Admin\SBY_Upgrader', 'maybe_upgrade_redirect' ) );
	}

	/**
	 * Connect to licensing API to get download URL for Pro version
	 *
	 * @param $license_data
	 *
	 * @return bool|mixed|null
	 *
	 * @since 4.0
	 */
	public static function get_version_info( $license_data, $license_key ) {
		$api_params = array(
			'edd_action' => 'get_version',
			'license'    => $license_key,
			'item_name'  => isset( $license_data['item_name'] ) ? $license_data['item_name'] : false,
			'item_id'    => isset( $license_data['item_id'] ) ? $license_data['item_id'] : false,
			'version'    => '0',
			'slug'       => self::SLUG,
			'author'     => 'SmashBalloon',
			'url'        => home_url(),
			'beta'       => false,
			'nocache'    => '1'
		);

		$api_url = trailingslashit( self::STORE_URL );

		$request = wp_remote_post(
			$api_url,
			array(
				'timeout'   => 15,
				'sslverify' => true,
				'body'      => $api_params,
			)
		);

		if ( ! is_wp_error( $request ) ) {
			$version_info = json_decode( wp_remote_retrieve_body( $request ) );
			return $version_info;
		}

		return false;
	}

	/**
	 * Ajax handler for grabbing the upgrade url.
	 *
	 * @since 4.0
	 */
	public static function maybe_upgrade_redirect() {
		$home_url = home_url();
		check_ajax_referer( 'sby-admin', 'nonce' );
		if ( ! sby_current_user_can( 'manage_youtube_feed_options' ) ) {
			wp_send_json_error();
		}

		// Check for permissions.
		if ( ! current_user_can( 'install_plugins' ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You are not allowed to install plugins.', 'feeds-for-youtube' ) ) );
		}
		if ( self::is_dev_url( home_url() ) ) {
			wp_send_json_success(
				array(
					'url' => self::INSTALL_INSTRUCTIONS,
				)
			);
		}
		// Check license key.
		$license = ! empty( $_POST['license_key'] ) ? sanitize_key( $_POST['license_key'] ) : '';
		if ( empty( $license ) ) {
			wp_send_json_error( array( 'message' => esc_html__( 'You are not licensed.', 'feeds-for-youtube' ) ) );
		}

		$args = array(
			'edd_action' => 'check_license',
			'license'    => $license,
			'item_name'  => urlencode( SBY_PLUGIN_EDD_NAME ), // the name of our product in EDD
		);
		$url  = add_query_arg( $args, SBY_STORE_URL );

		$remote_request_args = array(
			'timeout' => '20',
			'headers' => array(
				'referer' => home_url(),
			)
		);

		$response = wp_remote_get( $url, $remote_request_args );

		if ( ! is_wp_error( $response ) ) {
			$body = wp_remote_retrieve_body( $response );

			$license_data = json_decode( $body, true );

			if ( empty( $license_data ) ) {
				wp_send_json_error(
					array(
						'message' => esc_html( self::get_error_message( $license_data ) ),
					)
				);
			}

			if ( isset( $license_data['error'] ) && ! empty( $license_data['error'] ) ) {
				wp_send_json_error(
					array(
						'message' => self::get_error_message( $license_data ),
					)
				);
			}

			if ( $license_data['license'] !== 'valid' ) {
				wp_send_json_error(
					array(
						'message' => self::get_error_message( $license_data ),
					)
				);
			}

			update_option( 'sby_license_key', $license );
			update_option( 'sby_license_data', $license_data );
			update_option( 'sby_license_status', $license_data['license'] );

			// Redirect.
			$oth = hash( 'sha512', wp_rand() );
			$hashed_oth = hash_hmac( 'sha512', $oth, wp_salt() );

			update_option( 'sby_one_click_upgrade', $oth );
			$version      = '1.0';
			$version_info = self::get_version_info( $license_data, $license );
			$file         = '';
			if ( isset( $version_info->package ) ) {
				$file = $version_info->package;
			}
			$siteurl  = admin_url();
			$endpoint = admin_url( 'admin-ajax.php' );
			$redirect = admin_url( 'admin.php?page=' . self::REDIRECT );
			$url      = add_query_arg(
				array(
					'key'         => $license,
					'oth'         => $hashed_oth,
					'endpoint'    => $endpoint,
					'version'     => $version,
					'siteurl'     => $siteurl,
					'homeurl'     => $home_url,
					'redirect'    => rawurldecode( base64_encode( $redirect ) ),
					'file'        => rawurldecode( base64_encode( $file ) ),
					'plugin_name' => self::NAME,
				),
				self::UPGRADE_URL
			);
			wp_send_json_success(
				array(
					'url' => $url,
				)
			);

		}

		wp_send_json_error( array( 'message' => esc_html__( 'Could not connect.', 'feeds-for-youtube' ) ) );
	}

	/**
	 * Endpoint for one-click upgrade.
	 *
	 * @since 4.0
	 */
	public function install_upgrade() {
		$error = esc_html__( 'Could not install upgrade. Please download from smashballoon.com and install manually.', 'feeds-for-youtube' );
		// verify params present (oth & download link).
		$post_oth = ! empty( $_REQUEST['oth'] ) ? sanitize_text_field( $_REQUEST['oth'] ) : '';
		$post_url = ! empty( $_REQUEST['file'] ) ? $_REQUEST['file'] : '';

		if ( empty( $post_oth ) || empty( $post_url ) ) {
			wp_send_json_error( $error );
		}
		// Verify oth.
		$oth = get_option( 'sby_one_click_upgrade' );

		if ( empty( $oth ) ) {
			wp_send_json_error( $error );
		}

		if ( hash_hmac( 'sha512', $oth, wp_salt() ) !== $post_oth ) {
			wp_send_json_error( $error );
		}

		// Delete so cannot replay.
		delete_option( 'sby_one_click_upgrade' );
		// Set the current screen to avoid undefined notices.
		set_current_screen( self::REDIRECT );
		// Prepare variables.
		$url = esc_url_raw(
			add_query_arg(
				array(
					'page' => self::REDIRECT,
				),
				admin_url( 'admin.php' )
			)
		);

		// Verify pro not installed.
		$active = activate_plugin( self::SLUG, $url, false, true );
		if ( ! is_wp_error( $active ) ) {
			deactivate_plugins( plugin_basename( SBY_PLUGIN_DIR ) );
			wp_send_json_success( esc_html__( 'Plugin installed & activated.', 'feeds-for-youtube' ) );
		}

		$creds = request_filesystem_credentials( $url, '', false, false, null );
		// Check for file system permissions.
		if ( false === $creds ) {
			wp_send_json_error( $error );
		}
		if ( ! WP_Filesystem( $creds ) ) {
			wp_send_json_error( $error );
		}

		// We do not need any extra credentials if we have gotten this far, so let's install the plugin.
		$license = get_option( 'sby_license_key' );
		if ( empty( $license ) ) {
			wp_send_json_error( new \WP_Error( '403', esc_html__( 'You are not licensed.', 'feeds-for-youtube' ) ) );
		}

		// Do not allow WordPress to search/download translations, as this will break JS output.
		remove_action( 'upgrader_process_complete', array( 'Language_Pack_Upgrader', 'async_upgrade' ), 20 );
		// Create the plugin upgrader with our custom skin.
		require_once trailingslashit( SBY_PLUGIN_DIR ) . 'inc/Admin/PluginSilentUpgrader.php';
		require_once trailingslashit( SBY_PLUGIN_DIR ) . 'inc/Admin/PluginSilentUpgraderSkin.php';
		require_once trailingslashit( SBY_PLUGIN_DIR ) . 'inc/Admin/class-install-skin.php';
		$installer = new \SBY\Helpers\PluginSilentUpgrader( new \SBY_Install_Skin() );

		// Error check.
		if ( ! method_exists( $installer, 'install' ) || empty( $post_url ) ) {
			wp_send_json_error( $error );
		}

		$license_data = get_option( 'sby_license_data' );

		if ( ! empty( $license_data ) ) {
			$version_info = self::get_version_info( $license_data );

			$file = '';
			if ( isset( $version_info->package ) ) {
				$file = $version_info->package;
			}
		} else {
			wp_send_json_error( new \WP_Error( '403', esc_html__( 'You are not licensed.', 'feeds-for-youtube' ) ) );
		}

		if ( ! empty( $file ) ) {

			$installer->install( $file ); // phpcs:ignore
			// Check license key.
			// Flush the cache and return the newly installed plugin basename.
			wp_cache_flush();

			$plugin_basename = $installer->plugin_info();

			if ( $plugin_basename ) {
				deactivate_plugins( plugin_basename( SBY_PLUGIN_BASENAME ), true );

				// Activate the plugin silently.
				$activated = activate_plugin( $plugin_basename );

				if ( ! is_wp_error( $activated ) ) {
					wp_send_json_success( esc_html__( 'Plugin installed & activated.', 'feeds-for-youtube' ) );
				} else {
					// Reactivate the lite plugin if pro activation failed.
					$activated = activate_plugin( plugin_basename( SBY_PLUGIN_BASENAME ), '', false, true );
					wp_send_json_error( esc_html__( 'Pro version installed but needs to be activated from the Plugins page inside your WordPress admin.', 'feeds-for-youtube' ) );
				}
			}
		}

		wp_send_json_error( $error );
	}

	/**
	 * Whether or not it's likely to be a reachable URL for upgrade
	 *
	 * @param string $url
	 *
	 * @return bool
	 *
	 * @since 4.0
	 */
	public static function is_dev_url( $url = '' ) {
		$is_local_url = false;
		// Trim it up
		$url = strtolower( trim( $url ) );
		// Need to get the host...so let's add the scheme so we can use parse_url
		if ( false === strpos( $url, 'http://' ) && false === strpos( $url, 'https://' ) ) {
			$url = 'http://' . $url;
		}
		$url_parts = parse_url( $url );
		$host      = ! empty( $url_parts['host'] ) ? $url_parts['host'] : false;
		if ( ! empty( $url ) && ! empty( $host ) ) {
			if ( false !== ip2long( $host ) ) {
				if ( ! filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
					$is_local_url = true;
				}
			} elseif ( 'localhost' === $host ) {
				$is_local_url = true;
			}

			$tlds_to_check = array( '.local', ':8888', ':8080', ':8081', '.invalid', '.example', '.test' );
			foreach ( $tlds_to_check as $tld ) {
				if ( false !== strpos( $host, $tld ) ) {
					$is_local_url = true;
					break;
				}
			}
			if ( substr_count( $host, '.' ) > 1 ) {
				$subdomains_to_check = array();
				foreach ( $subdomains_to_check as $subdomain ) {
					$subdomain = str_replace( '.', '(.)', $subdomain );
					$subdomain = str_replace( array( '*', '(.)' ), '(.*)', $subdomain );
					if ( preg_match( '/^(' . $subdomain . ')/', $host ) ) {
						$is_local_url = true;
						break;
					}
				}
			}
		}
		return $is_local_url;
	}

	/**
	 * Handle API Response and check for an error.
	 *
	 * @param array $response
	 *
	 * @return string
	 *
	 * @since 4.0
	 */
	public static function get_error_message( $response ) {
		$message = '';
		if ( isset( $response['error'] ) ) {
			$error = sanitize_text_field( $response['error'] );
			switch ( $error ) {
				case 'expired':
					$message = __( 'This license is expired.', 'feeds-for-youtube' );
					break;
				default:
					$message = __( 'We encountered a problem unlocking the PRO features. Please install the PRO version manually.', 'feeds-for-youtube' );
			}
		}

		return $message;
	}

}
