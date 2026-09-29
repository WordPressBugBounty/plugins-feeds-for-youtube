<?php

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// composer autoload
require_once __DIR__ . '/vendor/autoload.php';

if (!defined('SBY_DBVERSION')) {
    define('SBY_DBVERSION', '1.4');
}

if (!defined('SBY_UPLOADS_NAME')) {
// Upload folder name for local image files for posts
    define('SBY_UPLOADS_NAME', 'sby-local-media');
}


if (!defined('SBY_ITEMS')) {
    // Name of the database table that contains instagram posts
    define('SBY_ITEMS', 'sby_items');
}

if (!defined('SBY_ITEMS_FEEDS')) {
    // Name of the database table that contains feed ids and the ids of posts
    define('SBY_ITEMS_FEEDS', 'sby_items_feeds');
}
if (!defined('SBY_CPT')) {
    // Name of the database table that contains feed ids and the ids of posts
    define('SBY_CPT', 'sby_videos');
}

if (!defined('SBY_PLUGIN_DIR')) {
    // Plugin Folder Path.
    define('SBY_PLUGIN_DIR', plugin_dir_path(__FILE__));
}

if (!defined('SBY_PLUGIN_URL')) {
    // Plugin Folder URL.
    define('SBY_PLUGIN_URL', plugin_dir_url(__FILE__));
}

if (!defined('SBY_MINIMUM_WALL_VERSION')) {
    define('SBY_MINIMUM_WALL_VERSION', '1.0');
}
if (!defined('SBY_FEED_LOCATOR')) {
    define('SBY_FEED_LOCATOR', 'sby_feed_locator');
}

if (!defined('CUSTOMIZER_ABSPATH')) {
    define('CUSTOMIZER_ABSPATH', __DIR__ . '/vendor/smashballoon/customizer/');
}

if (!defined('CUSTOMIZER_PLUGIN_URL')) {
    define('CUSTOMIZER_PLUGIN_URL', plugin_dir_url(__DIR__ . '/vendor/smashballoon/customizer/bootstrap.php'));
}

//Load .env variables
if (class_exists('Dotenv\Dotenv') && method_exists('Dotenv\Dotenv', 'createImmutable')) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->safeLoad();
}

// Initialize the deactivation feedback survey.
if (class_exists('\SmashBalloon\YoutubeFeed\Vendor\Smashballoon\Framework\Packages\Feedback\FeedbackManager')) {
    $sby_is_pro = defined('SBY_PRO') && SBY_PRO;
    \SmashBalloon\YoutubeFeed\Vendor\Smashballoon\Framework\Packages\Feedback\FeedbackManager::init([
        'plugin_slug'        => $sby_is_pro ? 'feeds-for-youtube-pro' : 'feeds-for-youtube',
        'plugin_name'        => $sby_is_pro ? 'Smash Balloon Feeds for YouTube Pro' : 'Smash Balloon Feeds for YouTube',
        'plugin_version'     => defined('SBYVER') ? SBYVER : '',
        'plugin_file'        => $sby_is_pro ? dirname(__FILE__) . '/youtube-feed-pro.php' : dirname(__FILE__) . '/youtube-feed.php',
        'support_url'        => 'https://smashballoon.com/support/?utm_campaign=' . ( $sby_is_pro ? 'youtube-pro' : 'youtube-free' ) . '&utm_source=support&utm_medium=support',
        'enable_help_widget' => true,
        'help_url'           => 'https://smashballoon.com/docs/youtube/',
        // Settings/Help/About/Single-Videos/Setup pages use the SBY_SLUG
        // ('youtube-feed') prefix, which isn't in the Help Widget's built-in
        // slug→prefix map (only 'sb-youtube'/'sby'/'feeds-for-youtube' are).
        // Without these the FAB only renders on the 'sby-feed-builder' page.
        'help_widget_screens' => [
            'youtube-feed-settings',
            'youtube-feed-support',
            'youtube-feed-about',
            'youtube-feed-single-videos',
            'youtube-feed-setup',
        ],
    ]);
}

// Initialize the shared About Us page. Deferred to plugins_loaded because
// bootstrap.php is required before the entry file defines SBY_PRO / SBYVER,
// so reading them at include time would misidentify a Pro install as Free and
// send an empty version. Runs at priority 20 (after sby_init() at priority 10)
// so SBY_MENU_SLUG is also defined by the time menu_parent is read below.
add_action( 'plugins_loaded', function () {
    if ( class_exists( '\SmashBalloon\YoutubeFeed\Vendor\Smashballoon\Framework\Packages\AboutUs\AboutUsManager' ) ) {
        $sby_is_pro = defined('SBY_PRO') && SBY_PRO;
        // Resolve the About Us menu capability the same way every other YouTube
        // Feed admin page does (sby-functions.php), so manage_youtube_feed_options
        // and the sby_settings_pages_capability filter govern About Us too.
        $sby_about_capability = current_user_can( 'manage_youtube_feed_options' ) ? 'manage_youtube_feed_options' : 'manage_options';
        $sby_about_capability = apply_filters( 'sby_settings_pages_capability', $sby_about_capability );
        \SmashBalloon\YoutubeFeed\Vendor\Smashballoon\Framework\Packages\AboutUs\AboutUsManager::init([
            'plugin_slug'    => $sby_is_pro ? 'youtube-feed-pro' : 'feeds-for-youtube',
            'plugin_name'    => $sby_is_pro ? 'Feeds for YouTube Pro' : 'Feeds for YouTube',
            'plugin_version' => defined('SBYVER') ? SBYVER : '',
            'plugin_file'    => SBY_PLUGIN_DIR . ( $sby_is_pro ? 'youtube-feed-pro.php' : 'youtube-feed.php' ),
            'menu_parent'    => defined('SBY_MENU_SLUG') ? SBY_MENU_SLUG : 'sby-feed-builder',
            'page_slug'      => 'youtube-feed-about',
            'menu_position'  => 4,
            'capability'     => $sby_about_capability,
            'is_pro'         => $sby_is_pro,
        ]);
    } elseif ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        // Framework package missing: the About Us menu will not register and no
        // fallback page exists, so surface it in debug builds instead of failing
        // silently.
        error_log( 'YouTube Feed: AboutUsManager not found, About Us page not registered.' );
    }
}, 20 );