<?php

namespace SmashBalloon\YouTubeFeed\Abilities\Abilities;

use SmashBalloon\YouTubeFeed\Abilities\Services\SBY_Source_Service;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Ability: youtube-feed/get-connect-url
 *
 * The Settings page's own "Add Source" button is a POST form (see
 * SourcesSection.js) submitted straight to the OAuth relay
 * (connect.smashballoon.com/auth/yt/) carrying a session nonce — the same
 * shape Instagram Feed uses (AgDR-0503), not a bare-GET redirect. The agent
 * hands the returned wp-admin link to the human; that page
 * (SBY_Connect_Bootstrap) consumes the single-use state token and
 * auto-submits the SAME POST with a fresh session nonce, so the relay
 * round-trip runs through the plugin's native path. The agent never sees
 * Google credentials.
 *
 * @since SBY_ABILITIES_SINCE
 */
class SBY_Get_Connect_Url extends SBY_Ability
{
	/**
	 * Ability slug.
	 *
	 * @return string
	 */
	public function slug()
	{
		return 'get-connect-url';
	}

	/**
	 * Args for wp_register_ability().
	 *
	 * @return array
	 */
	public function args()
	{
		return array(
			'label'               => __('Get a YouTube channel-connect link', 'feeds-for-youtube'),
			'description'         => __('Returns a single-use link (valid 15 minutes) the site admin opens in their browser to connect a YouTube channel via Google OAuth. The assistant never touches Google credentials. After connecting, use list-sources to see the new channel.', 'feeds-for-youtube'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
			// Null-tolerant: POST abilities must define an input schema when any
			// input arrives; a bodyless POST passes null.
			'input_schema'        => array(
				'type'                 => array('object', 'null'),
				'additionalProperties' => false,
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'connect_url'  => array('type' => 'string'),
					'expires_in'   => array('type' => 'integer'),
					'instructions' => array('type' => 'string'),
				),
			),
			'execute_callback'    => array($this, 'execute'),
			'permission_callback' => array($this, 'can_write'),
			'meta'                => $this->write_meta(false, false),
		);
	}

	/**
	 * Execute the ability.
	 *
	 * @return array|\WP_Error
	 */
	public function execute()
	{
		return (new SBY_Source_Service())->get_connect_url();
	}
}
