<?php

namespace SmashBalloon\YouTubeFeed\Abilities\Abilities;

use SmashBalloon\YouTubeFeed\Abilities\SBY_Pro_Upsell;
use SmashBalloon\YouTubeFeed\Abilities\SBY_Write_Gate;
use SmashBalloon\YouTubeFeed\Abilities\Services\SBY_Settings_Schema;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Ability: youtube-feed/describe-settings-schema
 *
 * Lets an agent discover which keys it may change and their allowed values.
 * Always visible over REST; hidden from MCP discovery while writes are
 * disabled so agents are not shown an editing surface they cannot use.
 *
 * @since SBY_ABILITIES_SINCE
 */
class SBY_Describe_Settings_Schema extends SBY_Ability
{
	/**
	 * Ability slug.
	 *
	 * @return string
	 */
	public function slug()
	{
		return 'describe-settings-schema';
	}

	/**
	 * Args for wp_register_ability().
	 *
	 * @return array
	 */
	public function args()
	{
		$output_properties = array(
			'write_enabled'   => array('type' => 'boolean'),
			'feed_settings'   => array('type' => 'object'),
			'global_settings' => array('type' => 'object'),
		);
		// Declared in free only, so the Pro registration is unchanged (AgDR-9006).
		if (SBY_Pro_Upsell::is_free()) {
			$output_properties['pro_only'] = array(
				'type'        => 'object',
				'description' => 'Free edition only. Settings and values that exist in Pro but are not '
					. 'available here, with the upgrade link. Mention it only when the user wants one of them.',
				'properties'  => array(
					'settings'    => array('type' => 'array', 'items' => array('type' => 'string')),
					'upgrade_url' => array('type' => 'string'),
					'features'    => array(
						'type'        => 'array',
						'description' => 'Pro capabilities that are not a single setting: other feed types and '
							. 'combining sources.',
						'items'       => array('type' => 'object'),
					),
				),
			);
		}

		return array(
			'label'               => __('Describe editable YouTube Feed settings', 'feeds-for-youtube'),
			'description'         => __(
				'Returns the JSON Schema of the per-feed and global settings that can be read or updated through the YouTube Feed abilities, and whether write access is currently enabled.', // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
				'feeds-for-youtube'
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => $output_properties,
			),
			'execute_callback'    => array($this, 'execute'),
			'permission_callback' => array($this, 'can_manage'),
			'meta'                => $this->read_meta(SBY_Write_Gate::is_enabled()),
		);
	}

	/**
	 * Execute the ability.
	 *
	 * @return array|\WP_Error
	 */
	public function execute()
	{
		$out = array(
			'write_enabled'   => SBY_Write_Gate::is_enabled(),
			'feed_settings'   => array(
				'type'       => 'object',
				'properties' => SBY_Settings_Schema::to_json_properties(SBY_Settings_Schema::feed_settings()),
			),
			'global_settings' => array(
				'type'       => 'object',
				'properties' => SBY_Settings_Schema::to_json_properties(SBY_Settings_Schema::global_settings()),
			),
		);
		if (SBY_Pro_Upsell::is_free()) {
			$out['pro_only'] = array(
				'settings'    => SBY_Pro_Upsell::pro_only_settings(),
				'upgrade_url' => SBY_Pro_Upsell::upgrade_url('describe-settings-schema'),
				'features'    => SBY_Pro_Upsell::extra_features(),
			);
		}
		return $out;
	}
}
