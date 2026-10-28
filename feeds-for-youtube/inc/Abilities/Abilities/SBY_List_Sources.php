<?php

namespace SmashBalloon\YouTubeFeed\Abilities\Abilities;

use SmashBalloon\YouTubeFeed\Abilities\Services\SBY_Source_Service;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Ability: youtube-feed/list-sources
 *
 * @since SBY_ABILITIES_SINCE
 */
class SBY_List_Sources extends SBY_Ability
{
	/**
	 * Ability slug.
	 *
	 * @return string
	 */
	public function slug()
	{
		return 'list-sources';
	}

	/**
	 * Args for wp_register_ability().
	 *
	 * @return array
	 */
	public function args()
	{
		return array(
			'label'               => __('List connected YouTube channels', 'feeds-for-youtube'),
			'description'         => __('Lists the YouTube channels this site can use as a source: connected accounts (channel id, username, expiry date) and, when a YouTube API key is saved in Settings, the channels its feeds already use (connection: api_key). With an API key any channel id (starts with "UC") also works as a source. Never returns access or refresh tokens.', 'feeds-for-youtube'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
			// No input_schema: a bare GET passes null input, which an object schema rejects.
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'sources' => array(
						'type'  => 'array',
						'items' => array(
							'type'       => 'object',
							'properties' => array(
								'channel_id' => array('type' => 'string'),
								'username'   => array('type' => 'string'),
								'privacy'    => array('type' => 'string'),
								'expires'    => array('type' => 'string'),
								'is_valid'   => array('type' => 'boolean'),
								'connection' => array(
									'type'        => 'string',
									'description' => 'Free edition with an API key only: oauth or api_key.',
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
	 * @return array|\WP_Error
	 */
	public function execute()
	{
		return array('sources' => (new SBY_Source_Service())->list_sources());
	}
}
