<?php

namespace SmashBalloon\YouTubeFeed\Abilities;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Site-level switch for write abilities (off by default).
 *
 * The flag lives INSIDE the `sby_settings` option array (alongside
 * `ajaxtheme`, `customtemplates` and the other plugin-settings booleans), not
 * as its own top-level option — the same option every other settings toggle
 * uses (SBY_Settings::update_settings()). The UI control itself ("MCP Write
 * Access") is under Settings → General, not Advanced (Rex PR#741 review,
 * nit) — this docblock predates that move. Overridable in both directions by
 * a filter, same as the Instagram Feed pattern (AgDR-0503).
 *
 * @since SBY_ABILITIES_SINCE
 */
class SBY_Write_Gate
{
	const OPTION_KEY = 'sby_ai_write_enabled';
	const FILTER     = 'sby_abilities_allow_write';

	const SETTINGS_OPTION = 'sby_settings';

	/**
	 * Not a real setting: the value the Settings page was LOADED with for the
	 * toggle, round-tripped in the posted form so the server can tell "the user
	 * flipped the switcher" from "a stale tab posted back what it loaded". Always
	 * stripped before saving.
	 */
	const LOADED_KEY = 'sby_ai_write_enabled_loaded';

	/**
	 * Gate epoch: when the STORED toggle last went on -> off (microtime(true), as
	 * a 6-decimal string). State tokens minted at or before it are dead for good, so
	 * disabling MCP Write Access revokes every outstanding link and re-enabling
	 * later cannot revive one. Not autoloaded. Tracks the stored toggle only; the
	 * FILTER is evaluated live wherever the gate is checked, so a filter override
	 * needs no epoch.
	 */
	const EPOCH_OPTION = 'sb_abilities_sby_gate_epoch';

	/**
	 * Start tracking disables of the stored toggle. Called once at boot.
	 *
	 * @return void
	 */
	public static function register_hooks()
	{
		add_action('update_option_' . self::SETTINGS_OPTION, array(__CLASS__, 'on_settings_updated'), 10, 2);
		// Deleting the settings resets the toggle to off; a later re-add must not revive links.
		add_action('delete_option_' . self::SETTINGS_OPTION, array(__CLASS__, 'record_disable'));
	}

	/**
	 * @param mixed $old Previous settings value.
	 * @param mixed $new New settings value.
	 * @return void
	 */
	public static function on_settings_updated($old, $new)
	{
		if (self::stored_toggle($old) && !self::stored_toggle($new)) {
			self::record_disable();
		}
	}

	/**
	 * @return void
	 */
	public static function record_disable()
	{
		// Fixed 6-decimal string: a bare (string) cast keeps only ~14 significant digits and would drop the microseconds.
		update_option(self::EPOCH_OPTION, sprintf('%.6F', microtime(true)), false);
	}

	/**
	 * @return float When the stored toggle was last disabled; 0.0 if never.
	 */
	public static function disabled_since()
	{
		return (float) get_option(self::EPOCH_OPTION, 0);
	}

	/**
	 * @param mixed $settings
	 * @return bool
	 */
	private static function stored_toggle($settings)
	{
		return is_array($settings) && !empty($settings[self::OPTION_KEY]);
	}

	/**
	 * Only accept the write toggle from a Settings save when the user changed it in
	 * that tab.
	 *
	 * The Settings page posts back every field it loaded, so a tab opened while MCP
	 * Write Access was on would, on any later unrelated save, write `true` back over
	 * a toggle an admin has since turned off, silently re-enabling agent writes.
	 * The page carries the value it loaded the toggle with (LOADED_KEY); a posted ON
	 * counts only when the page loaded it OFF. Turning writes OFF is always honoured
	 * (so the disable still reaches update_option and records the gate epoch). A post
	 * with no marker (a page rendered by an older version) can only turn it OFF.
	 * Dropping the key makes the settings merge keep the stored value.
	 *
	 * @param array $posted Posted settings (flat key => value).
	 * @return array The same array, with LOADED_KEY removed and a stale ON removed.
	 */
	public static function reconcile_posted_toggle(array $posted)
	{
		$loaded = array_key_exists(self::LOADED_KEY, $posted)
			? filter_var($posted[self::LOADED_KEY], FILTER_VALIDATE_BOOLEAN)
			: null;
		unset($posted[self::LOADED_KEY]);

		if (array_key_exists(self::OPTION_KEY, $posted)) {
			$on = filter_var($posted[self::OPTION_KEY], FILTER_VALIDATE_BOOLEAN);
			if ($on && $loaded !== false) {
				unset($posted[self::OPTION_KEY]);
			}
		}
		return $posted;
	}

	/**
	 * The stored toggle, ignoring the filter. What the Settings page shows and
	 * what a save just left in the database.
	 *
	 * @return bool
	 */
	public static function stored_enabled()
	{
		return self::stored_toggle(get_option(self::SETTINGS_OPTION, array()));
	}

	/**
	 * Whether AI write access is currently turned on.
	 *
	 * @return bool
	 */
	public static function is_enabled()
	{
		$default = self::stored_toggle(get_option(self::SETTINGS_OPTION, array()));

		return (bool) apply_filters(self::FILTER, $default);
	}

	/**
	 * Gate check for a write ability's permission_callback.
	 *
	 * @return true|\WP_Error
	 */
	public static function check()
	{
		return self::is_enabled() ? true : SBY_Ability_Response::writes_disabled();
	}
}
