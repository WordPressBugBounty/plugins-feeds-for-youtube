<?php

namespace SmashBalloon\YouTubeFeed\Services;

use Smashballoon\Stubs\Services\ServiceProvider;
use SmashBalloon\YouTubeFeed\Helpers\Util;
use SmashBalloon\YouTubeFeed\SBY_Feed;
use SmashBalloon\YouTubeFeed\SBY_Settings;

class AssetsService extends ServiceProvider {
	public function register() {
		add_action( 'wp_footer', [$this, 'sby_custom_js'] );
		add_action( 'wp_head', [$this, 'sby_custom_css'] );
		add_action( 'sby_enqueue_scripts', [$this, 'sby_scripts_enqueue'], 10, 1);
		add_action( 'wp_enqueue_scripts', [$this, 'sby_scripts_enqueue'], 10, 1);

	}
	/**
	 * Adds the ajax url and custom JavaScript to the page
	 */
	public function sby_custom_js() {
		global $sby_settings;

		$js = isset( $sby_settings['custom_js'] ) ? trim( $sby_settings['custom_js'] ) : '';

		echo '<!-- YouTube Feeds JS -->';
		echo "\r\n";
		echo '<script type="text/javascript">';
		echo "\r\n";

		if ( ! empty( $js ) ) {
			echo "\r\n";
			echo "if (typeof jQuery !== 'undefined') {  jQuery( document ).ready(function($) {";
			echo "\r\n";
			echo "window.sbyCustomJS = function(){";
			echo "\r\n";
			echo stripslashes($js);
			echo "\r\n";
			echo "}";
			echo "\r\n";
			echo "})};";
		}

		echo "\r\n";
		echo '</script>';
		echo "\r\n";
	}

	public function sby_custom_css() {
		global $sby_settings;

		$css = '';

		$css = isset( $sby_settings['custom_css'] ) ? trim( $sby_settings['custom_css'] ) : '';

		//Show CSS if an Admin (so can see Hide Photos link), if including Custom CSS or if hiding some photos
		if ( current_user_can( 'manage_youtube_feed_options' ) || current_user_can( 'manage_options' ) ||  ! empty( $css ) ) {

			echo '<!-- YouTube Feeds CSS -->';
			echo "\r\n";
			echo '<style type="text/css">';

			if ( ! empty( $css ) ){
				echo "\r\n";
				echo stripslashes($css);
			}

			if ( current_user_can( 'manage_youtube_feed_options' ) || current_user_can( 'manage_options' ) ){
				echo "\r\n";
				echo "#sby_mod_link, #sby_mod_error{ display: block !important; width: 100%; float: left; box-sizing: border-box; }";
			}

			echo "\r\n";
			echo '</style>';
			echo "\r\n";
		}

	}
	/**
	 * Makes the JavaScript file available and enqueues the stylesheet
	 * for the plugin
	 */
	public function sby_scripts_enqueue( $sby_settings ) {
		if ( ! doing_action( "sby_enqueue_scripts" ) && ! is_singular( SBY_CPT ) ) {
			return;
		}

		$database_settings = sby_get_database_settings();
		$global_settings      = ( new SBY_Settings( [], $database_settings ) )->get_settings();

		if ( ! is_array( $sby_settings ) ) {
			$sby_settings = $global_settings;
		}

		$js_file = Util::getPluginAssets('js', 'sb-youtube');

		$enqueue_in_head = isset($global_settings['enqueue_js_in_head']) ? $global_settings['enqueue_js_in_head'] : false;
		wp_register_script( 'sby_scripts', $js_file, array('jquery'), SBYVER, !$enqueue_in_head );


		$css_common_file = Util::getPluginAssets('css', 'sb-youtube-common');
		$css_file = sby_is_pro() ? Util::getPluginAssets('css', 'sb-youtube') : Util::getPluginAssets('css', 'sb-youtube-free');

		// SMASH-1378: design-token mirror providing --sb-focus-ring (+ the
		// reduced-motion duration override). Enqueued as a dependency of the
		// feed stylesheets so the :focus-visible ring in css/sb-youtube.css
		// always has the token defined ahead of it.
		wp_register_style( 'sby_tokens_local', trailingslashit( SBY_PLUGIN_URL ) . 'assets/tokens/sby-tokens-local.css', array(), SBYVER );

		if ( !empty( $sby_settings['enqueue_css_in_shortcode'] ) ) {
			wp_register_style( 'sby_common_styles', $css_common_file, array( 'sby_tokens_local' ), SBYVER );
			wp_register_style( 'sby_styles', $css_file, array( 'sby_tokens_local' ), SBYVER );
		} else {
			wp_enqueue_style( 'sby_tokens_local' );
			wp_enqueue_style( 'sby_common_styles', $css_common_file, array( 'sby_tokens_local' ), SBYVER );
			wp_enqueue_style( 'sby_styles', $css_file, array( 'sby_tokens_local' ), SBYVER );
		}

		$data = array(
			'isAdmin' => is_admin(),
			'adminAjaxUrl' => admin_url( 'admin-ajax.php' ),
			'placeholder' => trailingslashit( SBY_PLUGIN_URL ) . 'img/placeholder.png',
			'placeholderNarrow' => trailingslashit( SBY_PLUGIN_URL ) . 'img/placeholder-narrow.png',
			'lightboxPlaceholder' => trailingslashit( SBY_PLUGIN_URL ) . 'img/lightbox-placeholder.png',
			'lightboxPlaceholderNarrow' => trailingslashit( SBY_PLUGIN_URL ) . 'img/lightbox-placeholder-narrow.png',
			'autoplay' => $sby_settings['playvideo'] === 'automatically',
			'semiEagerload' => $sby_settings['eagerload'],
			'eagerload' => false,
			'nonce'	=> wp_create_nonce( 'sby_nonce' ),
			'isPro'	=> sby_is_pro(),
			'isCustomizer' => \sby_doing_customizer( $sby_settings ),
			// a11y (SMASH-1381): translated strings for the frontend feed's
			// carousel controls and load-more live-region announcements.
			'a11y' => array(
				'prevSlide'      => __( 'Previous slide', 'feeds-for-youtube' ),
				'nextSlide'      => __( 'Next slide', 'feeds-for-youtube' ),
				'goToSlide'      => __( 'Go to slide', 'feeds-for-youtube' ),
				/* translators: Announced to screen readers after one new video loads. */
				'oneVideoLoaded' => __( '1 new video loaded', 'feeds-for-youtube' ),
				/* translators: %s: number of newly loaded videos */
				'videosLoaded'   => __( '%s new videos loaded', 'feeds-for-youtube' ),
				'livePlayerTitle' => __( 'YouTube live video player', 'feeds-for-youtube' ),
				'showMoreDescription' => __( 'Show more description', 'feeds-for-youtube' ),
				'showLessDescription' => __( 'Show less description', 'feeds-for-youtube' ),
				// SMASH-1400: related-videos end-screen Replay button.
				/* translators: Visible label on the button that restarts the video that just ended. */
				'replay'              => __( 'Replay', 'feeds-for-youtube' ),
				'replayVideo'         => __( 'Replay video', 'feeds-for-youtube' ),
			)
		);
		//Pass option to JS file
		wp_localize_script('sby_scripts', 'sbyOptions', $data );
		wp_enqueue_style( 'sby_common_styles' );
		wp_enqueue_style( 'sby_styles' );
		wp_enqueue_script( 'sby_scripts' );

		self::maybe_enqueue_swipe_view( $sby_settings );
	}

	/**
	 * Swipe View viewer assets (PROTOTYPE -- SMASH-2021, epic SMASH-1840).
	 *
	 * Enqueued only when the feed being rendered could possibly host the viewer:
	 * a Shorts-type feed, on the `swipe` layout, in Pro, with the kill switch off.
	 * SBY_Feed::should_enqueue_swipe_view() owns that decision and its docblock
	 * explains why consent is deliberately NOT part of it.
	 *
	 * Called from inside sby_scripts_enqueue(), which fires on the
	 * `sby_enqueue_scripts` action -- so it receives the PER-SHORTCODE settings
	 * (ShortcodeService.php:33 passes them), not the global ones. That is the only
	 * point in the request where "is there a swipe-layout Shorts feed on this
	 * page" is answerable, which is why the check lives here rather than on
	 * `wp_enqueue_scripts` directly.
	 *
	 * A page with two feeds fires this twice. `wp_enqueue_script` is idempotent by
	 * handle, so a second call is a no-op -- but note the consequence for the
	 * localized payload below: `wp_localize_script` is LAST-WRITER-WINS, so only
	 * genuinely page-global values may ride it. Everything per-feed is read from
	 * the feed's own container attributes instead (see the binding design's Q5).
	 *
	 * @param array $sby_settings Resolved settings for the feed being rendered.
	 *
	 * @since 2.8.4 SMASH-2021
	 */
	public static function maybe_enqueue_swipe_view( $sby_settings ) {
		if ( ! SBY_Feed::should_enqueue_swipe_view( $sby_settings, sby_is_pro() ) ) {
			return;
		}

		$js  = Util::getPluginAssets( 'js', 'sby-swipeview' );
		$css = Util::getPluginAssets( 'css', 'sby-swipeview' );

		// Cache-busting version, and this is NOT premature polish -- it cost real
		// measurement time to discover.
		//
		// Every other asset here is registered with a bare SBYVER, so the browser
		// (and anything caching in front of the site) keys the file on a string that
		// does not change between two builds of the same plugin version. During this
		// prototype's browser pass that produced a genuinely misleading result: a
		// rebuilt bundle was deployed, a spot-check with a random query string
		// confirmed the new code was on the server, and the PAGE went on loading the
		// previous build from cache under the unchanged `?ver=2.8.3`. Measurements
		// were then taken against code that was not running.
		//
		// filemtime() of the built file makes the version change whenever the file
		// does, which is the property the version string was supposed to have.
		// Falls back to SBYVER alone if the path is unreadable, so a missing file
		// degrades to today's behaviour rather than to an empty version.
		$build_dir = trailingslashit( SBY_PLUGIN_DIR ) . 'public/build/';
		$stamp     = array();
		foreach ( array( 'js/sby-swipeview.js', 'css/sby-swipeview.css' ) as $rel ) {
			$path = $build_dir . $rel;
			if ( is_readable( $path ) ) {
				$stamp[] = (int) filemtime( $path );
			}
		}
		$asset_ver = empty( $stamp ) ? SBYVER : SBYVER . '.' . max( $stamp );

		// jQuery only -- the viewer uses it for the host-integration surface
		// (delegated handlers, feed traversal) and plain DOM APIs everywhere else.
		// Depends on sby_scripts because the viewer reads window.sbyOptions
		// (isCustomizer) and, where available, window.sby.feeds.
		wp_register_script( 'sby_swipeview', $js, array( 'jquery', 'sby_scripts' ), $asset_ver, true );
		wp_register_style( 'sby_swipeview', $css, array( 'sby_tokens_local' ), $asset_ver );

		// PAGE-GLOBAL values only -- see the last-writer-wins note above.
		//
		// Both are read TRUTHILY on the JS side, never `=== false`, because
		// WP_Scripts::localize() string-casts every scalar: PHP false arrives as ""
		// and true as "1". A strict comparison against a boolean this transport
		// cannot deliver fails in the worst available direction, and it shipped for
		// real in the sibling Instagram viewer as an always-on visitor console log.
		wp_localize_script( 'sby_swipeview', 'sbySwipeView', array(
			'killed' => (bool) apply_filters( 'sby_swipeview_disabled', false, $sby_settings ),
			'debug'  => (bool) ( defined( 'WP_DEBUG' ) && WP_DEBUG && apply_filters( 'sby_swipeview_debug', false ) ),
		) );

		wp_enqueue_style( 'sby_swipeview' );
		wp_enqueue_script( 'sby_swipeview' );
	}
}
