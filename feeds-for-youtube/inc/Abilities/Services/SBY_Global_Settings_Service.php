<?php

namespace SmashBalloon\YouTubeFeed\Abilities\Services;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Reads and writes plugin-wide settings for the Abilities API, restricted to
 * the SBY_Settings_Schema::global_settings() allowlist.
 *
 * The native save path (SettingsPage::handle_settings_update() /
 * SBY_Settings::update_settings()) re-persists the WHOLE defaults-filled
 * `sby_settings` option on every save, because its input is the full
 * Settings-page form. We deliberately do NOT replay that shape here — merging
 * a synthetic full model risks clobbering unrelated options if our defaults
 * table ever drifts from the plugin's (rule: never call a full-model save
 * handler with a partial/synthetic model). Instead we merge only the
 * validated keys into the RAW stored option, the same minimal-merge pattern
 * Instagram Feed's SBI_Global_Settings_Service uses.
 *
 * @since SBY_ABILITIES_SINCE
 */
class SBY_Global_Settings_Service
{
	/**
	 * Global-schema defaults, layered over sby_settings_defaults().
	 *
	 * That function is missing `enqueue_js_in_head` (a real Advanced-tab
	 * toggle SBY_Settings_Schema::global_settings() exposes), so a fresh
	 * install that never saved Settings would read it back as a false
	 * "false" instead of the plugin's actual default. The value below
	 * matches the plugin's own effective default at the one place that
	 * actually consults this key — AssetsService::register_assets()
	 * (inc/Services/AssetsService.php:96):
	 * `isset($global_settings['enqueue_js_in_head']) ? ... : false`.
	 * sby_settings_defaults() values win wherever both tables define a key.
	 *
	 * @return array<string, mixed>
	 */
	private static function global_defaults()
	{
		$supplemental = array(
			'enqueue_js_in_head' => false,
		);

		return array_merge($supplemental, sby_settings_defaults());
	}

	/**
	 * Read the plugin-wide settings, redacted to the allowlist.
	 *
	 * @return array<string, mixed>
	 */
	public function get()
	{
		$all      = sby_get_database_settings();
		$defaults = self::global_defaults();
		$schema   = SBY_Settings_Schema::global_settings();
		$out      = array();
		foreach ($schema as $key => $def) {
			$out[$key] = SBY_Settings_Schema::cast_out(
				$def,
				isset($all[$key]) ? $all[$key] : null,
				isset($defaults[$key]) ? $defaults[$key] : null
			);
		}
		return $out;
	}

	/**
	 * Apply a partial update to the plugin-wide settings.
	 *
	 * @param array $partial key => value, already schema-validated by core.
	 * @return array{updated: string[], ignored: object, warnings: string[]}|\WP_Error
	 */
	public function update(array $partial)
	{
		$schema   = SBY_Settings_Schema::global_settings();
		$writable = SBY_Settings_Schema::writable($schema);
		$updated  = array();
		$ignored  = array();
		$clean    = array();

		foreach ($partial as $key => $value) {
			if (!isset($schema[$key])) {
				$ignored[$key] = 'unknown_setting';
				continue;
			}
			if (!isset($writable[$key])) {
				$ignored[$key] = 'read_only';
				continue;
			}
			$v = SBY_Settings_Schema::validate_in($schema[$key], $value, $key);
			if (is_wp_error($v)) {
				return $v;
			}
			$clean[$key] = $v;
		}

		if (empty($clean)) {
			return array('updated' => array(), 'ignored' => (object) $ignored, 'warnings' => array());
		}

		$settings = get_option('sby_settings', array());
		$settings = is_array($settings) ? $settings : array();
		foreach ($clean as $key => $v) {
			$settings[$key] = $v;
			$updated[]      = $key;
		}
		update_option('sby_settings', $settings);

		// D2 (SBR-195 QA §4) — conservative safety net, NOT exact parity with the
		// native save (Rex PR#741 review, follow-up P3: the earlier comment here
		// overstated it). SettingsPage::save_settings() clears the WP-Cron event
		// on every save, and CronUpdaterService::maybe_run_cron() reschedules it
		// from `cache_cron_interval` / `cache_cron_time` / `cache_cron_am_pm` —
		// the keys the native Caching UI (CachingSection.js) actually edits.
		// Those keys are not exposed to the Abilities API at all; the keys this
		// service DOES expose (caching_type, cache_time, cache_time_unit) are
		// real runtime settings (SBY_Settings.php) with no live UI, so an agent
		// changing them can't have altered the cron schedule either. Clearing
		// the hook here is harmless-but-currently-inert: maybe_run_cron()
		// reschedules from cache_cron_* on the next request regardless of what
		// this clear does. Kept as a forward-compatible safety net (a future
		// derive-pass that exposes cache_cron_* would make it load-bearing) —
		// see the follow-up ticket for aligning this schema with the live keys.
		$cache_keys = array('caching_type', 'cache_time', 'cache_time_unit');
		if (array_intersect($updated, $cache_keys)) {
			wp_clear_scheduled_hook('sby_feed_update');
		}

		/**
		 * Fires after an AI agent updated global settings through the Abilities API.
		 *
		 * @param string[] $updated Keys that were written.
		 */
		do_action('sby_abilities_global_settings_updated', $updated);

		$warnings = array();
		if (in_array('gdpr', $updated, true) && $clean['gdpr'] === 'no') {
			$warnings[] = 'GDPR mode is now "no": feeds load YouTube thumbnails and players before '
				. 'visitors consent. Tell the site owner, since this can affect legal compliance.';
		}

		return array('updated' => $updated, 'ignored' => (object) $ignored, 'warnings' => $warnings);
	}
}
