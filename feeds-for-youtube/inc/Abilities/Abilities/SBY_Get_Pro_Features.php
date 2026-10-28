<?php

namespace SmashBalloon\YouTubeFeed\Abilities\Abilities;

use SmashBalloon\YouTubeFeed\Abilities\SBY_Pro_Upsell;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Ability: youtube-feed/get-pro-features
 *
 * Read-only, FREE edition only (SBY_Abilities::abilities() does not register it
 * in Pro). Lets an agent answer "what else can this plugin do?" or explain why
 * a request can't be done in free. Content is derived from the plugin's own
 * upsell data via SBY_Pro_Upsell (AgDR-9006), never restated here.
 *
 * @since SBY_ABILITIES_SINCE
 */
class SBY_Get_Pro_Features extends SBY_Ability
{
	/**
	 * Ability slug.
	 *
	 * @return string
	 */
	public function slug()
	{
		return 'get-pro-features';
	}

	/**
	 * Args for wp_register_ability().
	 *
	 * @return array
	 */
	public function args()
	{
		return array(
			'label'               => __('Get YouTube Feed Pro features', 'feeds-for-youtube'),
			'description'         => __(
				'Lists what the Pro edition adds to this free plugin, with the settings each feature unlocks and an upgrade link. Mention it to the user only when they ask for something the free edition cannot do, or ask what else the plugin can do.', // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
				'feeds-for-youtube'
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'edition'     => array('type' => 'string', 'enum' => array('free')),
					'upgrade_url' => array('type' => 'string'),
					'features'    => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'name'        => array('type' => 'string'),
								'description' => array(
									'type'        => 'string',
									'description' => 'May be empty: the upgrade CTA list carries names only.',
								),
								'settings'    => array(
									'type'        => 'array',
									'items'       => array('type' => 'string'),
									'description' => 'Pro-only setting keys (or key=value) this feature unlocks. '
										. 'May be empty.',
								),
							),
						),
					),
				),
			),
			'execute_callback'    => array($this, 'execute'),
			'permission_callback' => array($this, 'can_manage'),
			'meta'                => $this->read_meta(true),
		);
	}

	/**
	 * Execute the ability.
	 *
	 * @return array{edition: string, upgrade_url: string, features: array}
	 */
	public function execute()
	{
		return array(
			'edition'     => 'free',
			'upgrade_url' => SBY_Pro_Upsell::upgrade_url('get-pro-features'),
			'features'    => SBY_Pro_Upsell::features(),
		);
	}
}
