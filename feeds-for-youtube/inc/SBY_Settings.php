<?php
namespace SmashBalloon\YouTubeFeed;

use Smashballoon\Customizer\Feed_Saver;

class SBY_Settings {
	/**
	 * @var array
	 */
	protected $atts;

	/**
	 * @var array
	 */
	protected $db;

	/**
	 * @var array
	 */
	protected $settings;

	/**
	 * @var array
	 */
	protected $feed_type_and_terms;

	/**
	 * @var array
	 */
	protected $connected_accounts;

	/**
	 * @var array
	 */
	protected $connected_accounts_in_feed;

	/**
	 * @var string
	 */
	protected $transient_name;

	/**
	 * SBY_Settings constructor.
	 *
	 * Overwritten in the Pro version.
	 *
	 * @param array $atts shortcode settings
	 * @param array $db settings from the wp_options table
	 */
	public function __construct( $atts, $db, $preview_settings = false ) {
		$atts = is_array( $atts ) ? $atts : array();

		// Keep only the attributes a shortcode or block is meant to set. The
		// wp_parse_args() merge below otherwise lets any caller-supplied key
		// override the saved setting — including the style values echoed into a
		// <style> element — reachable by naming a feed id that does not resolve.
		// Every path that resolves a feed (shortcode render, AJAX handlers, cron
		// updates) constructs this class, so they all filter identically and the
		// transient name derived from these settings stays consistent (SMASH-1799).
		$atts = self::filter_allowed_atts( $atts );

		if ( ! empty( $atts['feed'] ) && $atts['feed'] !== 'legacy' ) {
			$this->settings = self::get_settings_by_feed_id( $atts['feed'], $preview_settings );

			if ( ! empty( $this->settings ) ) {
				$this->settings['customizer'] = isset($atts['customizer']) && $atts['customizer'] == true ? true : false;
				$this->settings['feed'] = intval( $atts['feed'] );
			}
		}

		if ( ! empty( $atts['feed'] ) && $atts['feed'] === 'legacy' ) {
			$this->settings = $preview_settings;

			if ( ! empty( $this->settings ) ) {
				$this->settings['customizer'] = isset($atts['customizer']) && $atts['customizer'] == true ? true : false;
			}
		}

		// convert string 'false' and 'true' to booleans
		foreach ( $atts as $key => $value ) {
			if ( $value === 'false' ) {
				$atts[ $key ] = false;
			} elseif ( $value === 'true' ) {
				$atts[ $key ] = true;
			}
		}

		$this->atts = $atts;
		$this->db   = $db;

		$this->connected_accounts = isset( $db['connected_accounts'] ) ? $db['connected_accounts'] : array();

		if ( ! empty( $this->db['api_key'] ) ) {
			$this->connected_accounts = array(
				'own' => array(
					'access_token' => '',
					'refresh_token' => '',
					'channel_id' => '',
					'username' => '',
					'is_valid' => true,
					'last_checked' => '',
					'profile_picture' => '',
					'privacy' => '',
					'expires' => '2574196927',
					'api_key' => $this->db['api_key']
				)
			);
		}

		if ( empty( $this->settings ) ) {
			$this->settings = wp_parse_args( $atts, $db );
		}

		if ( empty( $this->connected_accounts ) ) {
			$this->settings['showheader'] = false;
			$this->connected_accounts = array( 'rss_only' => true );
		}

		$this->settings['nummobile'] = $this->settings['num'];
		$this->settings['ajaxtheme'] = $this->db['ajaxtheme'];
		if ( empty( $atts['caching_type'] ) ) {
			$this->settings['caching_type'] = 'background';
		}
		if ( ! empty( $atts['cachetime'] ) ) {
			$this->settings['caching_type'] = 'page';
		}
		if ( ! empty( $atts['showpast'] ) ) {
			$this->settings['showpast'] = (bool)$atts['showpast'];
		}

		$this->after_settings_set();
	}

	protected function after_settings_set() {

	}

	/**
	 * Get settings or legacy settings depending on feed type
	 *
	 * @since 2.0
	 *
	 * @param array $atts
	 * @return array $settings
	 */
	public function maybe_get_settings_or_legacy_settings( $atts ) {
		if ( !empty( $atts['feed'] ) ) {
			$settings = $this->get_settings();
		} else {
			$settings = self::get_legacy_feed_settings( $atts );
		}

		$settings['global_settings'] = sby_get_database_settings();

		return $settings;
	}

	/**
	 * Get legacy feed settings
	 *
	 * @since 2.0
	 *
	 * @param array $atts
	 * @return array $legacy_settings
	 */
	public static function get_legacy_feed_settings( $atts = array() ) {
		$legacy_settings = get_option( 'sby_legacy_feed_settings', false );
		if(false === $legacy_settings) {
			$legacy_settings = sby_get_database_settings();
		} else {
			$legacy_settings = json_decode( $legacy_settings, true );
		}

		if ( $atts && count( $atts ) > 0 ) {
			// Same allowlist as the constructor merge: this legacy path also parses
			// caller-supplied atts over stored settings, so an unfiltered key would
			// override the saved value here even after the main merge is gated (SMASH-1799).
			$atts = self::filter_allowed_atts( $atts );
			$legacy_settings = wp_parse_args( $atts, $legacy_settings );
			$legacy_settings = self::filter_legacy_shortcode_atts( $atts, $legacy_settings );
		}

		return $legacy_settings;
	}

	/**
	 * Filter legacy feed shortcode atts
	 *
	 * @since 2.0
	 *
	 * @param array $atts
	 * @param array $legacy_settings
	 *
	 * @return array $legacy_shortcode
	 */
	public static function filter_legacy_shortcode_atts( $atts, $legacy_settings ) {
		if ( isset($atts['gridcols']) && $atts['layout'] === 'grid' ) {
			$legacy_settings['cols'] = $legacy_settings['gridcols'];
		}
		if ( isset($atts['gridcolsmobile'])  && $atts['layout'] === 'grid' ) {
			$legacy_settings['colsmobile'] = $legacy_settings['gridcolsmobile'];
		}
		if ( isset($atts['gallerycols']) && $atts['layout'] === 'gallery' ) {
			$legacy_settings['cols'] = $legacy_settings['gallerycols'];
		}
		if ( isset($atts['gallerycolsmobile'])  && $atts['layout'] === 'gallery' ) {
			$legacy_settings['colsmobile'] = $legacy_settings['gallerycolsmobile'];
		}
		if ( isset($atts['carouselcols']) && $atts['layout'] === 'carousel' ) {
			$legacy_settings['cols'] = $legacy_settings['carouselcols'];
		}
		if ( isset($atts['carouselcolsmobile'])  && $atts['layout'] === 'carousel' ) {
			$legacy_settings['colsmobile'] = $legacy_settings['carouselcolsmobile'];
		}
		$legacy_settings['nummobile'] = $legacy_settings['num'];

		return $legacy_settings;
	}

	/**
	 * Attribute names a shortcode or block may set.
	 *
	 * Built from the plugin's own catalog of feed settings plus the
	 * feed-resolving and customizer plumbing keys internal callers pass
	 * ('feed' from the embed integrations, 'id'/'channel'/'playlist'/etc. for
	 * legacy source resolution, 'customizer'/'feedtype' from the feed builder
	 * preview, 'cachetime' injected by ShortcodeService::check_cron_status()).
	 * custom_css and custom_js are removed: only the wp-admin settings page
	 * sets them, and letting a shortcode attribute supply a CSS/JS blob would
	 * hand document-wide style/script control to any role that can publish a
	 * shortcode (SMASH-1799).
	 *
	 * @since 2.7.1
	 *
	 * @return array
	 */
	public static function get_allowed_shortcode_atts() {
		$allowed = array_merge(
			self::get_public_db_settings_keys(),
			array(
				// resolves which saved feed or API source to display
				'feed',
				'id',
				'search',
				'live',
				'playlist',
				'favorites',
				'single',
				// customizer preview plumbing, see Feed_Builder::add_customizer_att()
				'customizer',
				'feedtype',
				// legacy page-load caching att, also injected by ShortcodeService::check_cron_status()
				'cachetime',
				// pro filtering/moderation settings a legacy shortcode could set
				'includewords',
				'excludewords',
				'hidevideos',
				'whitelist',
				// legacy per-layout column atts, see filter_legacy_shortcode_atts()
				'gridcols',
				'gridcolsmobile',
				'gallerycols',
				'gallerycolsmobile',
				// Per-feed subscribe-button colours. These are plain style settings that a
				// shortcode could set before the allowlist, and they are absent from
				// get_public_db_settings_keys() only because that list is hand-maintained.
				// Dropping them would silently fall back to the saved value with no error, and
				// they carry no privilege: both sinks — sby_custom_feed_styles() and the
				// data_channel_header_colors payload in templates/feed.php — now validate them
				// with sby_css_color(), which is the control that actually bounds them. (SMASH-1799)
				'subscribelinkcolorbg',
				'subscribebtnprimarycolor',
				'subscribebtnsecondarycolor',
				'subscribebtntextcolor',
			)
		);

		$allowed = array_diff( $allowed, array( 'custom_css', 'custom_js' ) );

		return apply_filters( 'sby_allowed_shortcode_atts', array_values( $allowed ) );
	}

	/**
	 * Drops attributes that are not on the shortcode/block allowlist. Dropped
	 * keys simply fall back to the saved setting rather than erroring. Applied
	 * to both settings merges so the page render, the AJAX handlers, and cron
	 * updates all resolve a feed from the same filtered attribute set (SMASH-1799).
	 *
	 * @since 2.7.1
	 *
	 * @param array $atts
	 *
	 * @return array
	 */
	public static function filter_allowed_atts( $atts ) {
		if ( ! is_array( $atts ) || empty( $atts ) ) {
			return array();
		}

		return array_intersect_key( $atts, array_flip( self::get_allowed_shortcode_atts() ) );
	}

	/**
	 * Get Settings By Feed ID
	 *
	 * @since 2.0
	 */
	public static function get_settings_by_feed_id( $feed_id, $preview_settings = false ) {
		global $wpdb;

		if ( is_array( $preview_settings ) ) {
			return $preview_settings;
		}

		if ( intval( $feed_id ) < 1 ) {
			return false;
		}
		$container = Container::get_instance();
		$feed_saver = $container->get(Feed_Saver::class);
		$feed_saver->set_feed_id( $feed_id );

		return $feed_saver->get_feed_settings();
	}

	/**
	 * @return array
	 *
	 * @since 1.0
	 */
	public function get_settings() {
		return $this->settings;
	}

	/**
	 * The plugin will output settings on the frontend for debugging purposes.
	 * Safe settings to display are added here.
	 *
	 * Overwritten in the Pro version.
	 *
	 * @return array
	 *
	 * @since 1.0
	 */
	public static function get_public_db_settings_keys() {
		$public = array(
			'type' => 'channel',
			'channel' => '',
			'num' => 9,
			'nummobile' => 9,
			'minnum' => 9,
			'widthresp' => true,
			'class' => '',
			'height' => '',
			'heightunit' => '%',
			'disablemobile' => false,
			'itemspacing' => 5,
			'itemspacingunit' => 'px',
			'background' => '',
			'headercolor' => '',
			'subscribecolor' => '',
			'subscribehovercolor' => '',
			'subscribetextcolor' => '',
			'buttoncolor' => '',
			'buttonhovercolor' => '',
			'buttontextcolor' => '',
			'layout' => 'grid',
			'feedtemplate' => 'default',
			'playvideo' => 'automatically',
			'sortby' => 'none',
			'imageres' => 'auto',
			'showheader' => true,
			'headerstyle' => 'standard',
			'customheadertext' => __( 'We are on YouTube', 'feeds-for-youtube' ),
			'customheadersize' => 'small',
			'customheadertextcolor' => '',
			'showdescription' => true,
			'showbutton' => true,
			'headersize' => 'small',
			'headeroutside' => false,
			'showsubscribe' => true,
			'buttontext' => __( 'Load More...', 'feeds-for-youtube' ),
			'subscribetext' => __( 'Subscribe', 'feeds-for-youtube' ),
			'caching_type' => 'page',
			'cache_time' => 1,
			'cache_time_unit' => 'hours',
			'backup_cache_enabled' => true,
			'resizeprocess' => 'background',
			'disable_resize' => true,
			'storage_process' => 'background',
			'favor_local' => false,
			'disable_js_image_loading' => false,
			'ajax_post_load' => false,
			'ajaxtheme' => false,
			'enqueue_css_in_shortcode' => false,
			'font_method' => 'svg',
			'customtemplates' => false,
			'cols' => 3,
			'colsmobile' => 2,
			'playerratio' => '9:16',
			'eagerload' => false,
			'custom_css' => '',
			'custom_js' => '',
			'gdpr' => 'auto',
			'disablecdn' => false,
			'allowcookies' => false,

			// pro only
			'usecustomsearch' => false,
			'headerchannel' => '',
			'customsearch' => '',
			'showpast' => true,
			'showlikes' => true,
			'carouselcols' => 3,
			'carouselcolsmobile' => 2,
			'carouselarrows' => true,
			'carouselpag' => true,
			'carouselautoplay' => false,
			'infoposition' => 'below',
			'include' => array( 'title', 'icon', 'user', 'date', 'countdown' ),
			'hoverinclude' => array( 'description', 'stats' ),
			'descriptionlength' => 150,
			'userelative' => true,
			'dateformat' => '0',
			'customdate' => '',
			'showsubscribers' => true,
			'enablelightbox' => true,
			'subscriberstext' => __( 'subscribers', 'feeds-for-youtube' ),
			'viewstext' => __( 'views', 'feeds-for-youtube' ),
			'agotext' => __( 'ago', 'feeds-for-youtube' ),
			'beforedatetext' => __( 'Streaming live', 'feeds-for-youtube' ),
			'beforestreamtimetext' => __( 'Streaming live in', 'feeds-for-youtube' ),
			'minutetext' => __( 'minute', 'feeds-for-youtube' ),
			'minutestext' => __( 'minutes', 'feeds-for-youtube' ),
			'hourstext' => __( 'hours', 'feeds-for-youtube' ),
			'thousandstext' => __( 'K', 'feeds-for-youtube' ),
			'millionstext' => __( 'M', 'feeds-for-youtube' ),
			'watchnowtext' => __( 'Watch Now', 'feeds-for-youtube' ),
			'cta' => 'related',
			'colorpalette' => 'inherit',
			'linktext' => __( 'Learn More', 'feeds-for-youtube' ),
			'linkurl' => '',
			'linkopentype' => 'same',
			'linkcolor' => '',
			'linktextcolor' => '',
			'videocardstyle' => 'regular',
			'videocardlayout' => 'vertical',
			'custombgcolor1'            => '',
			'customtextcolor1'          => '',
			'customtextcolor2'          => '',
			'customlinkcolor1'          => '',
			'custombuttoncolor1'        => '',
			'custombuttoncolor2'        => '',
			'boxedbgcolor'        		=> '#ffffff',
			'boxborderradius'        	=> '12',
			'enableboxshadow'        	=> false,
			'descriptiontextsize'		=> '13px',

			// Video elements color
			'playiconcolor'	        	=> '',
			'videotitlecolor'        	=> '',
			'videouserecolor'        	=> '',
			'videoviewsecolor'        	=> '',
			'videocountdowncolor'      	=> '',
			'videostatscolor'	      	=> '',
			'videodescriptioncolor'    	=> '',

			'enablesubscriberlink'     => true,
		);

		return array_keys( $public );
	}

	/**
	 * @return array
	 *
	 * @since 1.0
	 */
	public function get_connected_accounts() {
		return $this->connected_accounts;
	}

	/**
	 * @return array|bool
	 *
	 * @since 1.0
	 */
	public function get_connected_accounts_in_feed() {
		if ( isset( $this->connected_accounts_in_feed ) ) {
			return $this->connected_accounts_in_feed;
		} else {
			return false;
		}
	}

	/**
	 * @return bool|string
	 *
	 * @since 1.0
	 */
	public function get_transient_name() {
		if ( isset( $this->transient_name ) ) {
			return $this->transient_name;
		} else {
			return false;
		}
	}

	/**
	 * Uses the feed types and terms as well as as some
	 * settings to create a semi-unique feed id used for
	 * caching and other features.
	 *
	 * Overwritten in the Pro version.
	 *
	 * @param string $transient_name
	 *
	 * @since 1.0
	 */
	public function set_transient_name( $transient_name = '' ) {

		if ( ! empty( $transient_name ) ) {
			$this->transient_name = $transient_name;
		} elseif ( false && ! empty( $this->settings['feedid'] ) ) { // feed ID not yet applicable for transients
			$this->transient_name = 'sby_' . $this->settings['feedid'];
		} else {
			$feed_type_and_terms = $this->feed_type_and_terms;

			$sby_transient_name = 'sby_';

			if ( isset( $feed_type_and_terms['channels'] ) ) {
				foreach ( $feed_type_and_terms['channels'] as $term_and_params ) {
					$channel = $term_and_params['term'];
					$sby_transient_name .= $channel;
				}
			}

			$num = $this->settings['num'];

			$num_length = strlen( $num ) + 1;

			//Add both parts of the caching string together and make sure it doesn't exceed 45
			$sby_transient_name = self::truncate_transient_name( $sby_transient_name, 45 - $num_length );

			$sby_transient_name .= '#' . $num;

			$this->transient_name = $sby_transient_name;
		}

	}

	/**
	 * Cut a transient name down to the storage budget without making it ambiguous.
	 *
	 * The name is a concatenation of the resolved terms, so a plain substr() means the terms
	 * past the cut point still steer the fetch but no longer appear in the name. Two different
	 * term sets therefore produce the same name, and the nopriv handlers use that name as the
	 * value the caller must match with hash_equals() — so a caller could append an extra
	 * channel beyond the cut and have the fetched videos written into another feed's cache.
	 * Appending a digest of the full string makes the name unique to the terms that produced
	 * it, so a name can only be reproduced by the term set it belongs to.
	 *
	 * Names that already fit are returned byte-for-byte unchanged, so the overwhelming
	 * majority of feeds keep their existing transient and their existing sby_videos post
	 * associations. Only names that were previously truncated change, and those re-cache once.
	 *
	 * @since 2.7.1
	 *
	 * Shared by both editions so the digest math lives in one place — the Pro override needs
	 * the same treatment plus its filter words folded in, which it passes as $digest_extra.
	 *
	 * @param string $name         Full concatenated name.
	 * @param int    $budget       Maximum length available.
	 * @param string $digest_extra Extra material that steers the fetch without appearing
	 *                             verbatim in the name. Non-empty forces the digest.
	 *
	 * @return string
	 */
	public static function truncate_transient_name( $name, $budget, $digest_extra = '' ) {
		$budget = (int) $budget;

		// Lossy in either of two ways: the terms were cut to fit the budget, or the caller
		// passed material that shapes the fetch without appearing verbatim in the name (the
		// Pro filter words, which are reduced to three characters each). Either way the name
		// stops identifying the values that produced it, so it needs the digest.
		$is_lossy = strlen( $name ) > $budget || '' !== $digest_extra;

		if ( $budget < 1 || ! $is_lossy ) {
			return $name;
		}

		$digest = substr( md5( '' === $digest_extra ? $name : $name . '|' . $digest_extra ), 0, 6 );
		$keep   = $budget - ( strlen( $digest ) + 1 );

		if ( $keep < 1 ) {
			return substr( $digest, 0, $budget );
		}

		return substr( $name, 0, $keep ) . '!' . $digest;
	}

	/**
	 * @return array|bool
	 *
	 * @since 1.0
	 */
	public function get_feed_type_and_terms() {
		if ( isset( $this->feed_type_and_terms ) ) {
			return $this->feed_type_and_terms;
		} else {
			return false;
		}
	}

	public function feed_type_and_terms_display() {

		if ( ! isset( $this->feed_type_and_terms ) ) {
			return array();
		}
		$return = array();
		foreach ( $this->feed_type_and_terms as $feed_type => $type_terms ) {
			foreach ( $type_terms as $term ) {
				$return[] = $term['term'];
			}
		}
		return $return;

	}

	/**
	 * Based on the settings related to retrieving post data from the API,
	 * this setting is used to make sure all endpoints needed for the feed are
	 * connected and stored for easily looping through when adding posts
	 *
	 * Overwritten in the Pro version.
	 *
	 * @since 1.0
	 */
	public function set_feed_type_and_terms() {
		//global $sby_posts_manager;

		$connected_accounts_in_feed = array();
		$feed_type_and_terms = array(
			'channels' => array()
		);

		if ( ! empty( $this->settings['id'] ) ) {
			$channel_array = is_array( $this->settings['id'] ) ? $this->settings['id'] : explode( ',', str_replace( ' ', '',  $this->settings['id'] ) );
			foreach ( $channel_array as $channel ) {
				if ( isset( $this->connected_accounts[ $channel ] ) ) {
					$feed_type_and_terms['channels'][] = array(
						'term' => $this->connected_accounts[ $channel ]['channel_id'],
						'params' => array(
							'channel_id' => $this->connected_accounts[ $channel ]['channel_id']
						)
					);
					$connected_accounts_in_feed[ $this->connected_accounts[ $channel ]['channel_id'] ] = $this->connected_accounts[ $channel ];
				}
			}

			if ( empty( $connected_accounts_in_feed ) ) {
				$an_account = array();
				foreach ( $this->connected_accounts as $account ) {
					if ( empty( $an_account ) ) {
						$an_account = $account;
					}
				}

				foreach ( $channel_array as $channel ) {
					$feed_type_and_terms['channels'][] = array(
						'term' => $channel,
						'params' => array(
							'channel_id' => $channel
						)
					);
					$connected_accounts_in_feed[ $channel ] = $an_account;
				}
			}

		} elseif ( ! empty( $this->settings['channel'] ) ) {
			$channel_array = is_array( $this->settings['channel'] ) ? $this->settings['channel'] : explode( ',', str_replace( ' ', '',  $this->settings['channel'] ) );

			$an_account = array();
			foreach ( $this->connected_accounts as $account ) {
				if ( empty( $an_account ) ) {
					$an_account = $account;
				}
			}

			foreach ( $channel_array as $channel ) {
				if ( strpos( $channel, 'UC' ) !== 0 ) {
					$channel_id = sby_get_channel_id_from_channel_name( $channel );
					if ( $channel_id ) {
						$feed_type_and_terms['channels'][] = array(
							'term' => $channel_id,
							'params' => array(
								'channel_id' => $channel_id
							)
						);
						$connected_accounts_in_feed[ $channel_id ] = $an_account;
					} else {
						$feed_type_and_terms['channels'][] = array(
							'term' => $channel,
							'params' => array(
								'channel_name' => $channel
							)
						);
						$connected_accounts_in_feed[ $channel ] = $an_account;
					}

				} else {
					$feed_type_and_terms['channels'][] = array(
						'term' => $channel,
						'params' => array(
							'channel_id' => $channel
						)
					);
					$connected_accounts_in_feed[ $channel ] = $an_account;
				}
			}

		} else {
			foreach ( $this->connected_accounts as $connected_account ) {
				if ( empty( $feed_type_and_terms['channels'] ) ) {
					$feed_type_and_terms['channels'][] = array(
						'term' => $connected_account['channel_id'],
						'params' => array(
							'channel_id' => $connected_account['channel_id']
						)
					);
					$connected_accounts_in_feed[ $connected_account['channel_id'] ] = $connected_account;
				}

			}
		}

		$this->connected_accounts_in_feed = $connected_accounts_in_feed;
		$this->feed_type_and_terms = $feed_type_and_terms;
	}

	/**
	 * @return float|int
	 *
	 * @since 1.0
	 */
	public function get_cache_time_in_seconds() {
		if ( $this->db['caching_type'] === 'background' ) {
			return SBY_CRON_UPDATE_CACHE_TIME;
		} else {
			//If the caching time doesn't exist in the database then set it to be 1 hour
			$cache_time = isset( $this->settings['cache_time'] ) ? (int)$this->settings['cache_time'] : 1;
			$cache_time_unit = isset( $this->settings['cache_time_unit'] ) ? $this->settings['cache_time_unit'] : 'hours';

			//Calculate the cache time in seconds
			if ( $cache_time_unit == 'minutes' ) $cache_time_unit = 60;
			if ( $cache_time_unit == 'hours' ) $cache_time_unit = 60*60;
			if ( $cache_time_unit == 'days' ) $cache_time_unit = 60*60*24;

			$cache_time = max( 900, $cache_time * $cache_time_unit );

			return $cache_time;
		}
	}

	public function update_settings($update_array = []) {
		if(!is_array($update_array)) {
			return false;
		}


		$updated = array_merge($this->settings, array_map(function ($value) {
			return $this->convert_value($value);
		}, $update_array));

		$result = update_option( 'sby_settings', $updated );

		// Schedule/unschedule the usage tracking cron based on consent.
		if ( array_key_exists( 'usagetracking', $updated ) ) {
			$scheduler = new \SmashBalloon\YouTubeFeed\UsageTracking\Core\Scheduler();
			if ( ! empty( $updated['usagetracking'] ) ) {
				$scheduler->schedule();
			} else {
				$scheduler->unschedule();
			}
		}

		return $result;
	}

	private function convert_value($value) {
		switch($value) {
			case 'true':
				return true;
			case 'false':
				return false;
			default:
				return $value;
		}
	}
}
