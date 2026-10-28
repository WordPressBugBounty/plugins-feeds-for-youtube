<?php

namespace SmashBalloon\YouTubeFeed\Abilities\Abilities;

use SmashBalloon\YouTubeFeed\Abilities\SBY_Pro_Upsell;
use SmashBalloon\YouTubeFeed\Abilities\Services\SBY_Feed_Settings_Service;
use SmashBalloon\YouTubeFeed\Helpers\Util;
use SmashBalloon\YouTubeFeed\Abilities\Services\SBY_Settings_Schema;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Ability: youtube-feed/create-feed
 *
 * @since SBY_ABILITIES_SINCE
 */
class SBY_Create_Feed extends SBY_Ability
{
	/**
	 * Ability slug.
	 *
	 * @return string
	 */
	public function slug()
	{
		return 'create-feed';
	}

	/**
	 * Args for wp_register_ability().
	 *
	 * @return array
	 */
	public function args()
	{
		$writable = SBY_Settings_Schema::writable(SBY_Settings_Schema::feed_settings());
		// feed_name has a dedicated top-level property; a nested copy would be
		// silently written into the settings blob instead of the feeds row.
		unset($writable['feed_name']);

		return array(
			'label'               => __('Create a YouTube Feed', 'feeds-for-youtube'),
			'description'         => (Util::isPro()
				? __('Creates a new feed like the Feed Builder does: an optional feed template, optional quick settings, and one or more already-connected channels (see list-sources) as sources — required. Returns the new feed id and its shortcode. Requires AI write access.', 'feeds-for-youtube') // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
				: __('Creates a new feed like the Feed Builder does: optional quick settings and one source — required. The source is a channel id (starts with "UC") when the site has a YouTube API key saved in Settings, or an already-connected channel (see list-sources). A feed in this edition has one source. Returns the new feed id and its shortcode. Requires AI write access.', 'feeds-for-youtube')) . SBY_Pro_Upsell::description_suffix(), // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'feed_name'    => array(
						'type'        => 'string',
						'maxLength'   => 200,
						'description' => 'Optional name; defaults to the first source channel.',
					),
					'feedtemplate' => array(
						'type'        => 'string',
						'enum'        => SBY_Feed_Settings_Service::FEED_TEMPLATES,
						'description' => Util::isPro()
							? 'Optional feed template preset.'
							: 'Optional feed template preset. This edition applies "default" only; '
								. 'any other value is ignored and reported in `warnings`.',
					),
					'sources'      => array(
						'type'        => 'array',
						'items'       => array('type' => 'string', 'maxLength' => 100),
						'minItems'    => 1,
						'maxItems'    => 10,
						'description' => Util::isPro()
							? 'One or more already-connected channels (see list-sources) as sources — required.'
							: 'Exactly one source — required: a channel id (starts with "UC") when the site '
								. 'has a YouTube API key, or an already-connected channel (see list-sources). More than one is a Pro '
								. 'feature and is refused with an upgrade link.',
					),
					'settings'     => array(
						'type'                 => 'object',
						'properties'           => SBY_Settings_Schema::to_json_properties($writable),
						'additionalProperties' => false,
						'description'          => 'Quick settings applied on top of the template.',
					),
				),
				'additionalProperties' => false,
				'required'             => array('sources'),
			),
			'output_schema'       => array(
				'type'       => 'object',
				'properties' => array(
					'feed_id'   => array('type' => 'integer'),
					'feed_name' => array('type' => 'string'),
					'shortcode' => array('type' => 'string'),
					'updated'   => array('type' => 'array', 'items' => array('type' => 'string')),
					'ignored'   => array('type' => 'object'),
					// AgDR-9004 requirement 3 — see SBY_Ability::write_output_schema()'s
					// `warnings` doc block for the full rationale.
					'warnings'  => array('type' => 'array', 'items' => array('type' => 'string')),
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
	 * @param mixed $input Ability input.
	 * @return array|\WP_Error
	 */
	public function execute($input = null)
	{
		return (new SBY_Feed_Settings_Service())->create_feed(
			$this->normalize_input($input),
			array(SBY_Update_Feed_Settings::class, 'tier_check')
		);
	}
}
