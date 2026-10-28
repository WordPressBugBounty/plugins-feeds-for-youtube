<?php

namespace SmashBalloon\YouTubeFeed\Abilities;

use SmashBalloon\YouTubeFeed\Abilities\Services\SBY_Settings_Schema;
use SmashBalloon\YouTubeFeed\Builder\SBY_Feed_Builder;
use SmashBalloon\YouTubeFeed\Helpers\Util;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Pro upsell surfaces for the FREE edition's Abilities API (AgDR-9006).
 *
 * One place that knows three things, so every surface stays consistent and the
 * copy never drifts from the rest of the plugin:
 *   1. the upgrade URL + UTM convention  -> upgrade_url()
 *   2. which Pro features exist, and which settings they unlock
 *                                         -> features() / pro_only_settings()
 *   3. the one-line "this is Pro" hint attached to Pro-caused refusals
 *                                         -> hint() / hint_for_feature() / hint_for_feed_type() / hint_for_multiple_sources()
 *
 * Feature names are NOT restated here: they are read from the free upgrade CTA's
 * own list (SBY_Feed_Builder::get_free_cta_features(), the `sbiFreeCTAFeatures`
 * bullets), so a copy change there flows through. That list carries names only,
 * so a feature's `description` is an empty string. The Pro-only setting set is
 * DERIVED from the edition filter (SBY_Settings_Schema::pro_only_settings());
 * only the map of "feature -> settings it unlocks" lives here, and tests pin it
 * against that derived set and the free schema.
 *
 * UTM convention (one rule, so every port matches):
 *   - utm_source = `abilities` for API responses, `settings` for the admin card;
 *   - utm_medium = the feature id, i.e. the CTA list key in kebab-case
 *     (see feature_medium()), for any link tied to one feature;
 *   - utm_medium = `ai-agents-card` for the banner, `get-pro-features` for that
 *     ability's top-level upgrade_url and `describe-settings-schema` for the
 *     `pro_only` block;
 *   - refusals no CTA feature covers use a fixed medium: `feed-types` (non-channel
 *     source type), `multiple-sources` (combining channels), `pro-features` (any
 *     other Pro-only setting).
 *
 * Free edition only. Every public method is inert in a Pro build.
 *
 * @since SBY_ABILITIES_SINCE
 */
class SBY_Pro_Upsell
{
	/** The plugin's existing lite-upgrade landing page (the one LicenseService's upgradeUrl uses). */
	const BASE_URL = 'https://smashballoon.com/youtube-feed/youtube-lite-upgrade/';

	/** Matches `sby_utm_campaign()` and every other free-edition upgrade link. */
	const UTM_CAMPAIGN = 'youtube-free';

	/** Fixed utm_medium values for refusals that no CTA feature covers. */
	const MEDIUM_FEED_TYPES       = 'feed-types';
	const MEDIUM_MULTIPLE_SOURCES = 'multiple-sources';
	const MEDIUM_OTHER            = 'pro-features';

	/**
	 * CTA list ids that are perks of buying Pro, not Pro features, so they are
	 * not offered as features.
	 *
	 * @var string[]
	 */
	const PERK_IDS = array('guarantee', 'support');

	/**
	 * Pro-only settings, grouped by the CTA feature that unlocks them.
	 *
	 * Keys are the SBY_Feed_Builder::get_free_cta_features() ids. Values are keys
	 * that exist in Pro and are ABSENT from the free schema (AgDR-9005), or
	 * `key=value` for a Pro-only value of a key free does expose
	 * (layout=carousel). Features with no per-feed setting (filter, lightbox) map
	 * to an empty list: never invent a key for them. Every token must appear in
	 * SBY_Settings_Schema::pro_only_settings(); the test pins that.
	 *
	 * @var array<string, string[]>
	 */
	const PRO_SETTINGS_BY_FEATURE = array(
		'filter'      => array(),
		'lightbox'    => array(),
		'layouts'     => array(
			'layout=carousel', 'feedtemplate', 'carouselcols', 'carouselcolsmobile',
			'carouselarrows', 'carouselautoplay', 'carouselpag',
		),
		'videoplayer' => array('type=live', 'live', 'showpast'),
	);

	/**
	 * Pro features the upgrade CTA list does not name but the abilities refuse:
	 * more feed types and combining sources. Ids double as the utm_medium of the
	 * refusal hints (MEDIUM_FEED_TYPES / MEDIUM_MULTIPLE_SOURCES). Every setting
	 * token must appear in SBY_Settings_Schema::pro_only_settings(); the test pins that.
	 *
	 * @var array<string, string[]>
	 */
	const EXTRA_FEATURE_SETTINGS = array(
		'feed-types'       => array('type=playlist', 'type=favorites', 'type=live', 'type=search', 'type=single'),
		'multiple-sources' => array(),
	);

	/**
	 * CTA ids of the features the AI Agents card banner names. Picked by key (not
	 * position) so reordering the CTA list cannot change the banner.
	 *
	 * @var string[]
	 */
	const BANNER_FEATURES = array('layouts', 'videoplayer', 'filter');

	/**
	 * Only the free edition promotes Pro. Guarded here so a future shared build
	 * can never surface any of this inside Pro.
	 *
	 * @return bool
	 */
	public static function is_free()
	{
		return !Util::isPro();
	}

	/**
	 * Upgrade link with the plugin's standard UTM set.
	 *
	 * @param string $medium What was shown (e.g. `get-pro-features`, `layouts`, `ai-agents-card`).
	 * @param string $source Surface: `abilities` for API responses, `settings` for the admin card.
	 * @return string
	 */
	public static function upgrade_url($medium, $source = 'abilities')
	{
		return self::BASE_URL
			. '?utm_campaign=' . self::UTM_CAMPAIGN
			. '&utm_source=' . rawurlencode((string) $source)
			. '&utm_medium=' . rawurlencode((string) $medium)
			. '&utm_content=UpgradeToPro';
	}

	/**
	 * The CTA list minus the perks: id => translated feature name. Empty outside
	 * the free edition.
	 *
	 * @return array<string, string>
	 */
	private static function feature_names()
	{
		if (!self::is_free() || !class_exists(SBY_Feed_Builder::class)) {
			return array();
		}
		return array_diff_key(SBY_Feed_Builder::get_free_cta_features(), array_flip(self::PERK_IDS));
	}

	/**
	 * Pro features as `[name, description, settings[]]`.
	 *
	 * Names come from the free upgrade CTA's list (which has no descriptions, so
	 * `description` is ''); `settings` from PRO_SETTINGS_BY_FEATURE. Empty outside
	 * the free edition.
	 *
	 * @return array<int, array{name: string, description: string, settings: string[]}>
	 */
	public static function features()
	{
		$out = array();
		foreach (self::feature_names() as $id => $name) {
			$out[] = array(
				'name'        => (string) $name,
				'description' => '',
				'settings'    => isset(self::PRO_SETTINGS_BY_FEATURE[$id])
					? self::PRO_SETTINGS_BY_FEATURE[$id]
					: array(),
			);
		}
		return array_merge($out, self::extra_features());
	}

	/**
	 * Pro features the abilities refuse that the upgrade CTA list does not name:
	 * the other feed types, and combining more than one source in a feed. Names and
	 * descriptions are for agents; settings are `key=value` tokens from
	 * pro_only_settings().
	 *
	 * @return array<int, array{name: string, description: string, settings: string[]}>
	 */
	public static function extra_features()
	{
		if (!self::is_free()) {
			return array();
		}
		return array(
			array(
				'name'        => self::feed_types_label(),
				'description' => __('Free feeds show one channel (type=channel). Pro adds playlist, favorites, live, search and single-video feeds. set-feed-type-and-source refuses those types in this edition.', 'feeds-for-youtube'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
				'settings'    => self::EXTRA_FEATURE_SETTINGS['feed-types'],
			),
			array(
				'name'        => self::multiple_sources_label(),
				'description' => __('Free feeds have a single source. Pro can combine several channels in one feed. create-feed and set-feed-type-and-source refuse more than one source in this edition.', 'feeds-for-youtube'), // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
				'settings'    => self::EXTRA_FEATURE_SETTINGS['multiple-sources'],
			),
		);
	}

	/**
	 * Name of the feed-types Pro feature.
	 *
	 * @return string
	 */
	public static function feed_types_label()
	{
		return __('Feed type selection (playlist, favorites, live, search, single video)', 'feeds-for-youtube');
	}

	/**
	 * Name of the multiple-sources Pro feature.
	 *
	 * @return string
	 */
	public static function multiple_sources_label()
	{
		return __('Combining multiple sources', 'feeds-for-youtube');
	}

	/**
	 * Every Pro-only setting key / `key=value`, derived from the edition filter.
	 * Drives the `pro_only` block of describe-settings-schema.
	 *
	 * @return string[] Empty outside the free edition.
	 */
	public static function pro_only_settings()
	{
		if (!self::is_free()) {
			return array();
		}
		return SBY_Settings_Schema::pro_only_settings();
	}

	/**
	 * The utm_medium for a feature: its CTA id in kebab-case (`videoplayer` stays as is).
	 *
	 * @param string $feature_id CTA list id.
	 * @return string
	 */
	public static function feature_medium($feature_id)
	{
		return strtolower(preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '-', (string) $feature_id));
	}

	/**
	 * Names of the BANNER_FEATURES, in banner order, from the CTA list.
	 * Ids missing from the list are skipped.
	 *
	 * @return string[]
	 */
	public static function banner_feature_names()
	{
		$names = self::feature_names();
		$out   = array();
		foreach (self::BANNER_FEATURES as $id) {
			if (isset($names[$id])) {
				$out[] = (string) $names[$id];
			}
		}
		return $out;
	}

	/**
	 * Sentence appended to the write abilities' descriptions in the free edition,
	 * pointing agents at the Pro-only discovery paths. Empty in Pro, where it
	 * would be false.
	 *
	 * @return string With a leading space, or ''.
	 */
	public static function description_suffix()
	{
		if (!self::is_free()) {
			return '';
		}
		return ' ' . __('A setting that is not in this schema may be a Pro-only feature: call describe-settings-schema (pro_only) or get-pro-features.', 'feeds-for-youtube'); // phpcs:ignore Generic.Files.LineLength.TooLong -- single literal for make-pot
	}

	/**
	 * One-line hint for a refusal caused by a Pro-only feature.
	 *
	 * Only for refusals the PLUGIN itself makes (a dropped template, a refused
	 * source type or channel count). Core JSON-schema validation rejects Pro-only
	 * keys and values with a 400 before plugin code runs, so a hint there is
	 * unreachable.
	 *
	 * @internal Attach to `warnings[]` or a WP_Error message only; never to a
	 *           successful response or an unrelated error.
	 *
	 * @param string $feature_label Human name of the Pro feature; '' for a generic hint.
	 * @param string $medium        UTM medium (see the class docblock).
	 * @return string Empty outside the free edition.
	 */
	public static function hint($feature_label, $medium)
	{
		if (!self::is_free()) {
			return '';
		}
		if ($feature_label === '') {
			return sprintf(
				/* translators: %s: upgrade URL */
				__('This is a Pro feature. Upgrade: %s', 'feeds-for-youtube'),
				self::upgrade_url($medium)
			);
		}
		return sprintf(
			/* translators: 1: Pro feature name, 2: upgrade URL */
			__('%1$s is a Pro feature. Upgrade: %2$s', 'feeds-for-youtube'),
			$feature_label,
			self::upgrade_url($medium)
		);
	}

	/**
	 * Hint for a CTA feature id (e.g. `layouts`), or '' when the id is not a Pro
	 * feature or this is not the free edition.
	 *
	 * @param string $feature_id CTA list id.
	 * @return string
	 */
	public static function hint_for_feature($feature_id)
	{
		$names = self::feature_names();
		if (!isset($names[$feature_id])) {
			return '';
		}
		return self::hint((string) $names[$feature_id], self::feature_medium($feature_id));
	}

	/**
	 * Hint for a refused setting key (and optionally value), or '' when the
	 * refusal is not Pro-caused (a typo'd key gets no upsell). A Pro-only setting
	 * no feature owns gets the generic hint.
	 *
	 * @internal See hint().
	 *
	 * @param string $key   Setting key the caller sent.
	 * @param mixed  $value Value the caller sent (only used for `key=value` Pro values).
	 * @return string
	 */
	public static function hint_for_setting($key, $value = null)
	{
		$pro = self::pro_only_settings();
		if (in_array($key, $pro, true)) {
			$token = $key;
		} elseif (is_scalar($value) && in_array($key . '=' . $value, $pro, true)) {
			$token = $key . '=' . $value;
		} else {
			return '';
		}
		foreach (self::PRO_SETTINGS_BY_FEATURE as $id => $settings) {
			if (in_array($token, $settings, true)) {
				return self::hint_for_feature($id);
			}
		}
		return self::hint('', self::MEDIUM_OTHER);
	}

	/**
	 * Hint for a refused non-channel feed type (the free edition sets `channel`
	 * only). Uses the owning feature when the CTA list has one (type=live), else
	 * the generic hint under MEDIUM_FEED_TYPES. '' for a type that is not a Pro
	 * type (a typo gets no upsell) or outside the free edition.
	 *
	 * @param string $type The type the caller asked for.
	 * @return string
	 */
	public static function hint_for_feed_type($type)
	{
		if (!in_array('type=' . $type, self::pro_only_settings(), true)) {
			return '';
		}
		foreach (self::PRO_SETTINGS_BY_FEATURE as $id => $settings) {
			if (in_array('type=' . $type, $settings, true)) {
				return self::hint_for_feature($id);
			}
		}
		return self::hint(self::feed_types_label(), self::MEDIUM_FEED_TYPES);
	}

	/**
	 * Hint for the refusal to combine more than one channel into a feed.
	 *
	 * @return string '' outside the free edition.
	 */
	public static function hint_for_multiple_sources()
	{
		return self::hint(self::multiple_sources_label(), self::MEDIUM_MULTIPLE_SOURCES);
	}
}
