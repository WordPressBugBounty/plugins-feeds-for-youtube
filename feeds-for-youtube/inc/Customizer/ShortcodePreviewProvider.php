<?php

namespace SmashBalloon\YouTubeFeed\Customizer;

use Smashballoon\Customizer\PreviewProvider;

class ShortcodePreviewProvider implements PreviewProvider {
	/**
	 * Render the builder preview for the customizer.
	 *
	 * This is the single chokepoint every vendored preview consumer flows through:
	 * Feed_Builder's two initial-page-load calls (legacy + normal) and
	 * Feed_Saver_Manager::feed_customizer_fly_preview()'s AJAX producer. The builder
	 * mounts the returned HTML as a Vue component TEMPLATE, so a `{{ }}` mustache in
	 * third-party YouTube text (video title, description, channel title/bio) is an
	 * evaluated JS expression in the administrator's session. Braces are not HTML
	 * metacharacters, so nothing on the render path (esc_html, esc_attr, wp_kses)
	 * touches them — neutralisation has to happen here.
	 *
	 * Comment-split (`{<!---->{`), never HTML entities: Vue 2's parser calls
	 * decodeHTMLCached() on the text node before parseText() looks for delimiters, so
	 * `&#123;&#123;` is decoded back to `{{` and tokenised as an interpolation anyway.
	 * Splitting the pair with an empty comment works one layer earlier — the comment
	 * ends the text node, so parseText() never sees two adjacent braces. Vue strips
	 * comment nodes from the render, so the visible text is unchanged. SMASH-1907.
	 *
	 * @param array      $attr     Shortcode/customizer attributes.
	 * @param array|bool $settings Preview settings, or false when unused.
	 * @return array|string Array{ header, feedInitOutput } in customizer mode, HTML string otherwise.
	 */
	public function render( $attr, $settings = false ) {
		$output = apply_filters( 'sby_render_shortcode', $attr, $settings );

		if ( is_array( $output ) ) {
			if ( isset( $output['feedInitOutput'] ) ) {
				$output['feedInitOutput'] = sby_neutralize_vue_delimiters( $output['feedInitOutput'] );
			}

			return $output;
		}

		if ( is_string( $output ) ) {
			return sby_neutralize_vue_delimiters( $output );
		}

		return $output;
	}
}
