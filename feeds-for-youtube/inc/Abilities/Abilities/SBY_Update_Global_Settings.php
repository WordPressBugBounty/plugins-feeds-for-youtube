<?php

namespace SmashBalloon\YouTubeFeed\Abilities\Abilities;

use SmashBalloon\YouTubeFeed\Abilities\Services\SBY_Global_Settings_Service;
use SmashBalloon\YouTubeFeed\Abilities\Services\SBY_Settings_Schema;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Ability: youtube-feed/update-global-settings
 *
 * Gated by SBY_Write_Gate (off by default), then the same capability as the
 * Settings page. Unknown keys are rejected by core (additionalProperties:false);
 * read-only keys are reported in `ignored`.
 *
 * @since SBY_ABILITIES_SINCE
 */
class SBY_Update_Global_Settings extends SBY_Ability
{
	/**
	 * Ability slug.
	 *
	 * @return string
	 */
	public function slug()
	{
		return 'update-global-settings';
	}

	/**
	 * Args for wp_register_ability().
	 *
	 * @return array
	 */
	public function args()
	{
		$writable = SBY_Settings_Schema::writable(SBY_Settings_Schema::global_settings());

		return array(
			'label'               => __('Update YouTube Feed global settings', 'feeds-for-youtube'),
			'description'         => __('Updates one or more plugin-wide YouTube Feed settings (caching, image handling, script loading). Requires AI write access to be enabled in YouTube Feed → Settings → General. Returns the keys that were updated and the keys that were ignored.', 'feeds-for-youtube'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => SBY_Settings_Schema::to_json_properties($writable),
				'minProperties'        => 1,
				'additionalProperties' => false,
			),
			'output_schema'       => $this->write_output_schema(),
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
		return (new SBY_Global_Settings_Service())->update($this->normalize_input($input));
	}
}
