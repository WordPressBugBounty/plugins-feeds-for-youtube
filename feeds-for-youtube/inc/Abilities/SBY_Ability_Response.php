<?php

namespace SmashBalloon\YouTubeFeed\Abilities;

use WP_Error;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * WP_Error factories shared by every YouTube Feed ability.
 *
 * Every error carries an HTTP status in `data.status` so the core Abilities
 * REST controller and the MCP adapter surface the right code.
 *
 * @since SBY_ABILITIES_SINCE
 */
class SBY_Ability_Response
{
	/**
	 * Make caller-supplied text safe to put inside an error message: tags
	 * stripped, control characters and runs of whitespace collapsed, length
	 * capped. Error messages reach agents and, through them, other UIs, so input
	 * is never echoed raw (SBR-578 QA F1).
	 *
	 * @param mixed $value Input as sent.
	 * @param int   $max   Maximum characters kept.
	 * @return string
	 */
	public static function safe_echo($value, $max = 60)
	{
		$text = is_scalar($value) ? (string) $value : '';
		// phpcs:ignore WordPress.WP.AlternativeFunctions.strip_tags_strip_tags -- fallback outside WordPress
		$text = function_exists('wp_strip_all_tags') ? wp_strip_all_tags($text) : strip_tags($text);
		$text = (string) preg_replace('/[\\s\\x00-\\x1F\\x7F]+/u', ' ', $text);
		$text = trim($text);
		$text = str_replace(array('<', '>', '"', '`'), '', $text);
		if (function_exists('mb_strlen') && mb_strlen($text) > $max) {
			$text = mb_substr($text, 0, $max) . '...';
		}
		return $text;
	}

	/**
	 * A caller lacks the capability to manage YouTube Feed settings.
	 *
	 * @return \WP_Error
	 */
	public static function forbidden()
	{
		return new WP_Error(
			'sby_forbidden',
			__('You do not have permission to manage YouTube Feed settings.', 'feeds-for-youtube'),
			array('status' => 403)
		);
	}

	/**
	 * The AI write gate is off.
	 *
	 * @return \WP_Error
	 */
	public static function writes_disabled()
	{
		return new WP_Error(
			'sby_writes_disabled',
			__(
				'AI write access is disabled. Enable "MCP Write Access" under YouTube Feed → Settings → General.', // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
				'feeds-for-youtube'
			),
			array('status' => 403)
		);
	}

	/**
	 * The caller passed a missing or non-positive feed_id.
	 *
	 * @return \WP_Error
	 */
	public static function invalid_feed_id()
	{
		return new WP_Error(
			'sby_invalid_feed_id',
			__('A valid feed_id is required.', 'feeds-for-youtube'),
			array('status' => 400)
		);
	}

	/**
	 * No feed exists with the given id.
	 *
	 * @param int $feed_id Feed id that could not be found.
	 * @return \WP_Error
	 */
	public static function feed_not_found($feed_id)
	{
		return new WP_Error(
			'sby_feed_not_found',
			sprintf(
				/* translators: %d: feed id */
				__('Feed %d was not found.', 'feeds-for-youtube'),
				(int) $feed_id
			),
			array('status' => 404)
		);
	}
}
