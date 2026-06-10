<?php // phpcs:disable PSR12.Classes.OpeningBraceSpace.Found,PSR12.ControlStructures.ControlStructureSpacing.SpaceBeforeCloseBrace,PSR12.ControlStructures.ControlStructureSpacing.SpacingAfterOpenBrace,PSR1.Methods.CamelCapsMethodName.NotCamelCaps,PSR2.Classes.ClassDeclaration.OpenBraceNewLine,PSR2.Methods.FunctionCallSignature.SpaceAfterOpenBracket,PSR2.Methods.FunctionCallSignature.SpaceBeforeCloseBracket,Squiz.Classes.ValidClassName.NotCamelCaps,Squiz.Commenting.FunctionComment.Missing,Squiz.Functions.MultiLineFunctionDeclaration.BraceOnSameLine

namespace SmashBalloon\YouTubeFeed\Blocks;

use SmashBalloon\YoutubeFeed\Vendor\Smashballoon\Framework\Packages\Blocks\SB_Elementor_Feed_Widget;
use SmashBalloon\YouTubeFeed\Builder\SBY_Db;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
	return;
}

class SBY_Modern_Elementor_Widget extends SB_Elementor_Feed_Widget {

	protected function get_widget_name() {
		return 'sb-youtube-feed';
	}

	protected function get_widget_title() {
		return __( 'YouTube Feed', 'feeds-for-youtube' );
	}

	protected function get_widget_icon() {
		return 'sb-elem-icon sb-elem-youtube';
	}

	protected function get_shortcode_tag() {
		return 'youtube-feed';
	}

	protected function get_feeds_options() {
		return SBY_Db::elementor_feeds_query();
	}

	protected function get_text_domain() {
		return 'feeds-for-youtube';
	}

	protected function get_script_deps() {
		return array( 'sbyscripts', 'sby-elementor-preview', 'sb-elementor-editor' );
	}

	protected function get_style_deps() {
		return array( 'sby_common_styles', 'sby_styles', 'sb-elementor-editor' );
	}

	protected function get_output_filter() {
		return 'sby_output';
	}
}
