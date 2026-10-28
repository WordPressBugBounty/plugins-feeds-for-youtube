<?php

namespace SmashBalloon\YouTubeFeed\Abilities\Abilities;

use SmashBalloon\YouTubeFeed\Abilities\Services\SBY_Global_Settings_Service;
use SmashBalloon\YouTubeFeed\Abilities\Services\SBY_Settings_Schema;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Ability: youtube-feed/get-global-settings
 *
 * @since SBY_ABILITIES_SINCE
 */
class SBY_Get_Global_Settings extends SBY_Ability
{
	/**
	 * Ability slug.
	 *
	 * @return string
	 */
	public function slug()
	{
		return 'get-global-settings';
	}

	/**
	 * Args for wp_register_ability().
	 *
	 * @return array
	 */
	public function args()
	{
		return array(
			'label'               => __('Get YouTube Feed global settings', 'feeds-for-youtube'),
			'description'         => __(
				'Returns the plugin-wide YouTube Feed settings (caching, image handling, script loading, GDPR). Never returns the API key, connected accounts, tokens or license data.', // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
				'feeds-for-youtube'
			),
			// No input_schema: a bare GET passes null input, which an object schema rejects.
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => SBY_Settings_Schema::to_json_properties(SBY_Settings_Schema::global_settings()),
			),
			'execute_callback'    => array($this, 'execute'),
			'permission_callback' => array($this, 'can_manage'),
			'meta'                => $this->read_meta(true),
		);
	}

	/**
	 * Execute the ability.
	 *
	 * @return array
	 */
	public function execute()
	{
		return (new SBY_Global_Settings_Service())->get();
	}
}
