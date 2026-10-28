<?php

/**
 * Plugin file integrity guard.
 *
 * Host malware scanners have quarantined plugin files (SMASH-2242). Without
 * this guard a missing file is a fatal error on every request, wp-admin
 * included. With it the plugin stops loading, the rest of the site keeps
 * running, and admins see a notice naming the missing files.
 *
 * Loaded by both youtube-feed.php (Free) and youtube-feed-pro.php (Pro), so
 * it must not depend on anything outside this file.
 *
 * @package SmashBalloon\YouTubeFeed
 */

// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols

if (! defined('ABSPATH')) {
	exit;
}

if (! function_exists('sby_integrity_check')) {
	/**
	 * Halt the plugin if any file it loads during boot is missing.
	 *
	 * Checked up front because a missing require_once target is a fatal that
	 * try/catch cannot intercept, and the autoloader skips missing class files
	 * silently, which surfaces later as "Class not found".
	 *
	 * @param array $extra Edition-specific paths, relative to the plugin folder.
	 *
	 * @return bool True when files are missing and the plugin was halted.
	 */
	function sby_integrity_check($extra = array())
	{
		$files = array_merge(
			array(
				'activation.php',
				'bootstrap.php',
				'vendor/autoload.php',
				'inc/sby-functions.php',
				'inc/Admin/admin-functions.php',
				'inc/Admin/SBY_Notifications.php',
				'inc/Admin/SBY_New_User.php',
				'inc/Admin/SBY_Upgrader.php',
				'inc/Blocks/SBY_Blocks.php',
				'inc/Blocks/SBY_Modern_Elementor_Widget.php',
				'inc/Builder/SBY_Db.php',
				'inc/Customizer/Config.php',
				'inc/Customizer/DB.php',
				'inc/Customizer/ProxyProvider.php',
				'inc/Customizer/ShortcodePreviewProvider.php',
				'inc/Feed_Locator.php',
				'inc/SBY_Cron_Updater.php',
				'inc/SBY_Posts_Manager.php',
				'inc/Services/Admin/AdminServiceContainer.php',
				// Loaded later on elementor/widgets/register, outside sby_integrity_run().
				'inc/Services/Integrations/Elementor/SBY_Elementor_Base.php',
				'inc/Services/Integrations/Elementor/SBY_Elementor_Control.php',
				'inc/Services/Integrations/Elementor/SBY_Elementor_Widget.php',
				'inc/Services/ServiceContainer.php',
			),
			$extra
		);
		$dir     = dirname(__DIR__) . '/';
		$missing = array();

		foreach ($files as $file) {
			if (! file_exists($dir . $file)) {
				$missing[] = $file;
			}
		}

		if (empty($missing)) {
			return false;
		}

		sby_integrity_halt($missing);

		return true;
	}

	/**
	 * Run a boot step, halting cleanly instead of fataling if it throws
	 * (for example "Class not found" from a file removed mid-request).
	 *
	 * @param callable $step Boot step.
	 *
	 * @return void
	 */
	function sby_integrity_run($step)
	{
		try {
			$step();
		} catch (\Throwable $e) {
			sby_integrity_halt(array($e->getMessage()));
		}
	}

	/**
	 * Shut the plugin down cleanly and tell the site owner why.
	 *
	 * @param array $missing Missing paths, or a caught error message.
	 *
	 * @return void
	 */
	function sby_integrity_halt($missing)
	{
		if (defined('SBY_INTEGRITY_HALTED')) {
			return;
		}
		define('SBY_INTEGRITY_HALTED', true);

		$GLOBALS['sby_integrity_missing'] = array_values(array_unique((array) $missing));

		sby_integrity_unhook();

		// Keep [youtube-feed] shortcodes from printing as raw text.
		foreach (array('youtube-feed', 'youtube-feed-single', 'youtube-feed-search') as $tag) {
			if (! shortcode_exists($tag)) {
				add_shortcode($tag, '__return_empty_string');
			}
		}

		add_action('admin_notices', 'sby_integrity_admin_notice');
		add_action('network_admin_notices', 'sby_integrity_admin_notice');

		if (defined('WP_DEBUG') && WP_DEBUG) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log('Feeds for YouTube: plugin halted, missing: ' . implode(', ', $GLOBALS['sby_integrity_missing']));
		}
	}

	/**
	 * Remove the plugin's own named hook callbacks.
	 *
	 * Some callbacks (sby_admin_init) live in files loaded later in boot. If
	 * boot stops half way, WordPress would call an undefined function at hook
	 * dispatch and fatal anyway.
	 *
	 * @return void
	 */
	function sby_integrity_unhook()
	{
		global $wp_filter;

		if (! is_array($wp_filter)) {
			return;
		}

		foreach ($wp_filter as $hook => $hook_object) {
			if (! isset($hook_object->callbacks) || ! is_array($hook_object->callbacks)) {
				continue;
			}

			foreach ($hook_object->callbacks as $priority => $callbacks) {
				foreach ($callbacks as $callback) {
					$function = isset($callback['function']) ? $callback['function'] : null;

					if (
						is_string($function)
						&& 0 === strpos($function, 'sby_')
						&& 0 !== strpos($function, 'sby_integrity_')
					) {
						remove_filter($hook, $function, $priority);
					}
				}
			}
		}
	}

	/**
	 * Print the admin notice naming the missing files.
	 *
	 * @return void
	 */
	function sby_integrity_admin_notice()
	{
		if (! current_user_can('activate_plugins')) {
			return;
		}

		$missing = isset($GLOBALS['sby_integrity_missing']) ? (array) $GLOBALS['sby_integrity_missing'] : array();
		$folder  = basename(dirname(__DIR__));

		echo '<div class="notice notice-error sby-integrity-notice">';
		echo '<p><strong>Feeds for YouTube has stopped loading.</strong> ';
		echo 'Some of the plugin&#8217;s own files are no longer on the server, so the plugin has shut itself ';
		echo 'down to keep the rest of your site running. Reinstalling the plugin will put the files back. ';
		echo 'Your feeds, settings and license key are stored in the database and are not affected.</p>';

		if (! empty($missing)) {
			echo '<p>Missing from <code>wp-content/plugins/' . esc_html($folder) . '/</code>: <code>';
			echo implode('</code>, <code>', array_map('esc_html', $missing)) . '</code></p>';
		}

		echo '<p>If these files go missing again after a reinstall, ask your web host whether their ';
		echo 'malware scanner has quarantined them.</p>';
		echo '</div>';
	}
}
