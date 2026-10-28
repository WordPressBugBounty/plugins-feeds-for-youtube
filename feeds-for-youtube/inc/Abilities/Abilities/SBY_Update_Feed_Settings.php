<?php

namespace SmashBalloon\YouTubeFeed\Abilities\Abilities;

use SmashBalloon\YouTubeFeed\Abilities\SBY_Ability_Response;
use SmashBalloon\YouTubeFeed\Abilities\SBY_Pro_Upsell;
use SmashBalloon\YouTubeFeed\Helpers\Util;
use SmashBalloon\YouTubeFeed\Abilities\Services\SBY_Feed_Settings_Service;
use SmashBalloon\YouTubeFeed\Abilities\Services\SBY_Settings_Schema;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Ability: youtube-feed/update-feed-settings
 *
 * Gated by SBY_Write_Gate, then capability, then a per-key license-tier check:
 * tier-locked keys are not written and come back in `ignored` as
 * `feature_unavailable`.
 *
 * @since SBY_ABILITIES_SINCE
 */
class SBY_Update_Feed_Settings extends SBY_Ability
{
	/**
	 * Ability slug.
	 *
	 * @return string
	 */
	public function slug()
	{
		return 'update-feed-settings';
	}

	/**
	 * Args for wp_register_ability().
	 *
	 * @return array
	 */
	public function args()
	{
		$writable = SBY_Settings_Schema::writable(SBY_Settings_Schema::feed_settings());
		$props    = array(
			'feed_id'  => array(
				'type'        => 'integer',
				'minimum'     => 1,
				'description' => 'Feed id from list-feeds.',
			),
			'settings' => array(
				'type'                 => 'object',
				'properties'           => SBY_Settings_Schema::to_json_properties($writable),
				'minProperties'        => 1,
				'additionalProperties' => false,
				'description'          => 'Settings to change. Only the keys you pass are changed; '
					. 'other stored values are re-saved as-is.',
			),
		);

		return array(
			'label'               => __('Update a YouTube Feed', 'feeds-for-youtube'),
			'description'         => __('Changes one or more settings of a feed (layout, columns, video count, header, buttons, colours). Only the keys you pass are changed. Requires AI write access to be enabled in YouTube Feed → Settings → General. Returns updated and ignored keys.', 'feeds-for-youtube') . SBY_Pro_Upsell::description_suffix(), // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => $props,
				'required'             => array('feed_id', 'settings'),
				'additionalProperties' => false,
			),
			'output_schema'       => $this->write_output_schema(array('feed_id' => array('type' => 'integer'))),
			'execute_callback'    => array($this, 'execute'),
			'permission_callback' => array($this, 'can_write'),
			'meta'                => $this->write_meta(false, true),
		);
	}

	/**
	 * Execute the ability.
	 *
	 * @param mixed $input Ability input.
	 * @return array|\WP_Error
	 */
	public function execute($input = null)
	{
		$input   = $this->normalize_input($input);
		$feed_id = isset($input['feed_id']) ? (int) $input['feed_id'] : 0;
		if ($feed_id <= 0) {
			return SBY_Ability_Response::invalid_feed_id();
		}
		$settings = isset($input['settings']) ? $this->normalize_input($input['settings']) : array();

		$service = new SBY_Feed_Settings_Service();
		$result  = $service->update_feed($feed_id, $settings, array($this, 'is_feature_available'));

		return $result === null ? SBY_Ability_Response::feed_not_found($feed_id) : $result;
	}

	/**
	 * Same gate the Feed Builder applies: tier feature present.
	 *
	 * @param string $feature Feature slug from YouTube_License_Tier::features_list().
	 * @return bool
	 */
	public function is_feature_available($feature)
	{
		return self::tier_check($feature);
	}

	/**
	 * Same gate the Feed Builder applies, reusable by other abilities.
	 *
	 * @param string $feature Feature slug from YouTube_License_Tier::features_list().
	 * @return bool
	 */
	public static function tier_check($feature)
	{
		// The free edition has no tier features: its stored licence state is
		// unreliable after a Pro-to-free downgrade (a stale tier can survive in the
		// licence option), so key off the edition, never the stored tier (AgDR-9005).
		if (!Util::isPro()) {
			return false;
		}
		if (!class_exists('Smashballoon\\Customizer\\YouTube_License_Tier')) {
			return true; // No tier concept available.
		}
		$tier = new \Smashballoon\Customizer\YouTube_License_Tier();
		return $tier->is_feature_available($feature);
	}
}
