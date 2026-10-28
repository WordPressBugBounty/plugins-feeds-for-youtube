<?php

namespace SmashBalloon\YouTubeFeed\Abilities\Abilities;

use SmashBalloon\YouTubeFeed\Abilities\SBY_Ability_Response;
use SmashBalloon\YouTubeFeed\Abilities\Services\SBY_Feed_Settings_Service;
use SmashBalloon\YouTubeFeed\Abilities\Services\SBY_Settings_Schema;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Ability: youtube-feed/get-feed
 *
 * @since SBY_ABILITIES_SINCE
 */
class SBY_Get_Feed extends SBY_Ability
{
	/**
	 * Ability slug.
	 *
	 * @return string
	 */
	public function slug()
	{
		return 'get-feed';
	}

	/**
	 * Args for wp_register_ability().
	 *
	 * @return array
	 */
	public function args()
	{
		return array(
			'label'               => __('Get a YouTube Feed', 'feeds-for-youtube'),
			'description'         => __(
				'Returns one feed\'s customer-configurable settings (layout, columns, video count, header, buttons, colors). Connected channels are reduced to channel_id and username; access and refresh tokens are never returned.', // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
				'feeds-for-youtube'
			),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'feed_id' => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'description' => 'Feed id from list-feeds.',
					),
				),
				'required'             => array('feed_id'),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'id'            => array('type' => 'integer'),
					'feed_title'    => array('type' => 'string'),
					'type'          => array('type' => 'string'),
					'status'        => array('type' => 'string'),
					'last_modified' => array('type' => 'string'),
					'shortcode'     => array('type' => 'string'),
					'sources'       => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'channel_id' => array('type' => 'string'),
								'username'   => array('type' => 'string'),
							),
						),
					),
					'settings'      => array(
						'type'       => 'object',
						// Output schema: enums widen to the stored-truth read enum (AgDR-9005).
						'properties' => SBY_Settings_Schema::to_json_properties(
							SBY_Settings_Schema::feed_settings(),
							true
						),
					),
					// AgDR-9005 downgrade rule: notes on stored values this edition does not apply.
					'warnings'      => array('type' => 'array', 'items' => array('type' => 'string')),
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
		$feed = (new SBY_Feed_Settings_Service())->get_feed($feed_id);
		return $feed === null ? SBY_Ability_Response::feed_not_found($feed_id) : $feed;
	}
}
