<?php

namespace SmashBalloon\YouTubeFeed\Abilities\Abilities;

use SmashBalloon\YouTubeFeed\Abilities\SBY_Ability_Response;
use SmashBalloon\YouTubeFeed\Abilities\SBY_Write_Gate;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Base class for one YouTube Feed ability.
 *
 * Subclasses return the wp_register_ability() args; the shared pieces
 * (namespace, capability check, input normalisation, read-only meta) live here
 * so every ability fences authorization the same way. Ported from Instagram
 * Feed's SBI_Ability (AgDR-0503).
 *
 * @since SBY_ABILITIES_SINCE
 */
abstract class SBY_Ability
{
	const NAMESPACE_PREFIX = 'youtube-feed';
	const CATEGORY         = 'youtube-feed';

	/**
	 * Slug after the namespace.
	 *
	 * @return string Slug after the namespace, e.g. `get-feed`.
	 */
	abstract public function slug();

	/**
	 * Args for wp_register_ability().
	 *
	 * @return array Args for wp_register_ability() (without `category`).
	 */
	abstract public function args();

	/**
	 * Fully-qualified ability name.
	 *
	 * @return string Fully-qualified ability name.
	 */
	public function name()
	{
		return self::NAMESPACE_PREFIX . '/' . $this->slug();
	}

	/**
	 * Register this ability with core.
	 *
	 * @return void
	 */
	public function register()
	{
		$args             = $this->args();
		$args['category'] = self::CATEGORY;
		wp_register_ability($this->name(), $args);
	}

	/**
	 * Same gate as the Feed Builder and Settings pages, including the
	 * `sby_settings_pages_capability` filter contract.
	 *
	 * @return true|\WP_Error
	 */
	public function can_manage()
	{
		return sby_current_user_can('manage_youtube_feed_options') ? true : SBY_Ability_Response::forbidden();
	}

	/**
	 * Core hands abilities null|array|object depending on transport.
	 *
	 * @param mixed $input Raw ability input.
	 * @return array
	 */
	protected function normalize_input($input)
	{
		if (is_object($input)) {
			$input = get_object_vars($input);
		}
		return is_array($input) ? $input : array();
	}

	/**
	 * Shared `meta` block for a read-only ability.
	 *
	 * @param bool $mcp_public Whether to advertise the ability over MCP discovery.
	 * @return array
	 */
	protected function read_meta($mcp_public = true)
	{
		return array(
			'annotations'  => array(
				'readonly'    => true,
				'destructive' => false,
				'idempotent'  => true,
			),
			'show_in_rest' => true,
			'mcp'          => array('public' => (bool) $mcp_public),
		);
	}

	/**
	 * Write gate first, then capability — same order as WPForms / Instagram Feed.
	 *
	 * @return true|\WP_Error
	 */
	public function can_write()
	{
		$gate = SBY_Write_Gate::check();
		if (is_wp_error($gate)) {
			return $gate;
		}
		return $this->can_manage();
	}

	/**
	 * Shared `meta` block for a write ability.
	 *
	 * @param bool $destructive Whether the write is destructive.
	 * @param bool $idempotent  Whether repeating the write is safe.
	 * @return array
	 */
	protected function write_meta($destructive, $idempotent)
	{
		return array(
			'annotations'  => array(
				'readonly'    => false,
				'destructive' => (bool) $destructive,
				'idempotent'  => (bool) $idempotent,
			),
			'show_in_rest' => true,
			// Hidden from MCP discovery while writes are disabled (agents should not
			// be shown a tool they cannot use); REST stays capability-gated.
			'mcp'          => array('public' => SBY_Write_Gate::is_enabled()),
		);
	}

	/**
	 * Shared `updated`/`ignored` output-schema tail for a write ability.
	 *
	 * @param array $extra Extra top-level output properties.
	 * @return array
	 */
	protected function write_output_schema(array $extra = array())
	{
		return array(
			'type'       => 'object',
			'properties' => array_merge(
				$extra,
				array(
					'updated'  => array(
						'type'        => 'array',
						'items'       => array('type' => 'string'),
						'description' => 'Keys that were written.',
					),
					'ignored'  => array(
						'type'        => 'object',
						'description' => 'Keys not written, with the reason: '
							. 'read_only, feature_unavailable or unknown_setting.',
					),
					// AgDR-9004 requirement 3: signal partial success instead of a bare
					// true/false. Populated whenever a requested change was
					// accepted-but-inert (e.g. cta='link' with no linkurl), a coupled
					// precondition went unmet, a value fell back to a default, or an
					// input was silently normalised (e.g. linkurl gained a scheme) —
					// so a caller gets a signal instead of a false "it worked".
					'warnings' => array(
						'type'        => 'array',
						'items'       => array('type' => 'string'),
						'description' => 'Human-readable signals for an accepted-but-inert '
							. 'change, an unmet coupled precondition, or a value that fell '
							. 'back to a default. Empty when nothing needs attention.',
					),
				)
			),
		);
	}
}
