<?php

namespace SmashBalloon\YouTubeFeed\Abilities\Abilities;

use SmashBalloon\YouTubeFeed\Abilities\SBY_Ability_Response;
use SmashBalloon\YouTubeFeed\Abilities\SBY_Pro_Upsell;
use SmashBalloon\YouTubeFeed\Abilities\Services\SBY_Feed_Settings_Service;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Ability: youtube-feed/set-feed-type-and-source
 *
 * The ONLY writable path to a feed's `type` and its matching source value
 * (channel/playlist/favorites/search/live/single are all read-only on
 * update-feed-settings — see SBY_Settings_Schema::feed_settings()). Changing
 * a feed's source is a coupled operation in this plugin: the value that
 * actually drives what videos are fetched depends on BOTH which `type` is
 * set and which key currently holds a value, per a fixed per-type priority
 * order (SBY_Settings_Pro::set_feed_type_and_terms()). Writing either half
 * alone through a generic setter can blank a working feed to 0 videos (type
 * changed, no matching source yet) or silently do nothing (source written,
 * but a different key still wins for the current type) — AgDR-9004's A1/C1
 * findings. This ability validates the whole set together, always writes
 * exactly the ONE key that wins for the chosen type, and clears every other
 * per-type key so no stale sibling value can resurface later.
 *
 * Ported from the pattern Twitter Feed's `set-feed-type-and-terms` ability
 * established (AgDR-9004) — canonical for this coupling class, copied rather
 * than reinvented. Destructive by annotation: changing the source changes
 * what a feed shows, so MCP clients that honour annotations (WPVibe) show
 * their approval panel.
 *
 * @since SBY_ABILITIES_SINCE
 */
class SBY_Set_Feed_Sources extends SBY_Ability
{
	/**
	 * Ability slug.
	 *
	 * @return string
	 */
	public function slug()
	{
		return 'set-feed-type-and-source';
	}

	/**
	 * Args for wp_register_ability().
	 *
	 * @return array
	 */
	public function args()
	{
		$types = SBY_Feed_Settings_Service::source_types();
		$has_raw = false;
		foreach ($types as $spec) {
			$has_raw = $has_raw || $spec['kind'] === 'raw';
		}
		$is_free = SBY_Pro_Upsell::is_free();

		$properties = array(
			'feed_id' => array('type' => 'integer', 'minimum' => 1),
			// The write enum is the FULL type list in both editions: free refuses a
			// non-channel type plugin-side (sby_invalid_value + upgrade hint, AgDR-9006)
			// instead of core answering a bare 400 before execute() runs, so the Pro hint
			// is reachable. Same approach as create-feed's feedtemplate. Deliberate
			// deviation from AgDR-9005 §1's `['channel']`. The description says which
			// values the free edition accepts, so the list does not read as an offer.
			'type'    => array(
				'type'        => 'string',
				'enum'        => array_keys(SBY_Feed_Settings_Service::SOURCE_TYPES),
				'description' => $is_free
					? 'This edition supports only "channel". The other values are Pro feed types '
						. 'and are refused with an upgrade link.'
					: 'The feed type.',
			),
			'sources' => array(
				'type'        => 'array',
				'items'       => array('type' => 'string', 'maxLength' => 100),
				'minItems'    => 0,
				'maxItems'    => 10,
				'description' => $has_raw
					? 'Already-connected channel ids or usernames. Required '
						. '(at least one) when type is channel, favorites, or live.'
					: 'Exactly one source (more than one is a Pro feature): a channel id (starts with "UC") '
						. 'when the site has a YouTube API key, or an already-connected channel id '
						. 'or username. Required.',
			),
		);
		// `terms` only does anything for the raw types (playlist/search/single), which
		// the free edition does not have (AgDR-9005). Free still ACCEPTS it, so a
		// request that sends `terms` for a Pro type reaches execute() and gets the Pro
		// refusal, instead of core's bare additionalProperties 400.
		if ($has_raw || $is_free) {
			$properties['terms'] = array(
				'type'        => 'string',
				'maxLength'   => 500,
				'description' => $has_raw
					? 'Comma-separated playlist id(s), search term(s), or '
						. 'video id(s). Required when type is playlist, search, or single.'
					: 'Pro feed types only (playlist, search, single video); not used in this edition.',
			);
		}

		return array(
			'label'               => __('Set what a YouTube Feed shows', 'feeds-for-youtube'),
			'description'         => $has_raw
				? __(
					'Sets the source type and matching value for a feed: channel/favorites/live '
						. '(sources: one or more already-connected channel ids or usernames — see '
						. 'list-sources; use get-connect-url to connect a new one first), or '
						. 'playlist/search/single (terms: comma-separated playlist id(s), search '
						. 'term(s), or video id(s)). Only pass sources OR terms, matching the type. '
						. 'Requires AI write access.',
					'feeds-for-youtube'
				) // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
				: __(
					'Sets which channel a feed shows: type "channel" with one source (sources: a channel id '
						. 'that starts with "UC" when the site has a YouTube API key saved in Settings, or an '
						. 'already-connected channel id or username — see list-sources; use get-connect-url to '
						. 'connect an account). This edition supports channel feeds with a single source only: '
						. 'playlist, favorites, live, search and single-video feeds, and combining several '
						. 'sources, are Pro features (see get-pro-features). Requires AI write access.',
					'feeds-for-youtube'
				),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => $properties,
				'required'             => array('feed_id', 'type'),
				'additionalProperties' => false,
			),
			'output_schema'       => $this->write_output_schema(
				array(
					'feed_id' => array('type' => 'integer'),
					'type'    => array('type' => 'string'),
					'sources' => array('type' => 'array', 'items' => array('type' => 'string')),
					'terms'   => array('type' => 'string'),
				)
			),
			'execute_callback'    => array($this, 'execute'),
			'permission_callback' => array($this, 'can_write'),
			'meta'                => $this->write_meta(true, true),
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
		$type = isset($input['type']) ? (string) $input['type'] : '';
		$sources = isset($input['sources']) && is_array($input['sources'])
			? array_map('strval', $input['sources'])
			: array();
		$terms  = isset($input['terms']) ? (string) $input['terms'] : '';
		$result = (new SBY_Feed_Settings_Service())->set_feed_type_and_source($feed_id, $type, $sources, $terms);

		return $result === null ? SBY_Ability_Response::feed_not_found($feed_id) : $result;
	}
}
