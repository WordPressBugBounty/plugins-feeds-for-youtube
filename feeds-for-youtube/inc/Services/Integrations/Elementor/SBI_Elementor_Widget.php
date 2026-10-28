<?php

// phpcs:disable Generic.ControlStructures.InlineControlStructure.NotAllowed,PSR12.Classes.OpeningBraceSpace.Found
// phpcs:disable PSR12.Files.ImportStatement.LeadingSlash,PSR1.Methods.CamelCapsMethodName.NotCamelCaps
// phpcs:disable PSR2.Classes.ClassDeclaration.OpenBraceNewLine,Squiz.Classes.ValidClassName.NotCamelCaps
// phpcs:disable Squiz.Commenting.ClassComment.WrongStyle,Squiz.Commenting.FunctionComment.Missing
// phpcs:disable Squiz.Commenting.InlineComment.InvalidEndChar
// phpcs:disable Squiz.Functions.MultiLineFunctionDeclaration.BraceOnSameLine
// phpcs:disable PSR1.Files.SideEffects.FoundWithSymbols

namespace SmashBalloon\YouTubeFeed\Services\Integrations\Elementor;

use \Elementor\Widget_Base;
use \Elementor\Controls_Manager;

if (!defined('ABSPATH'))
exit; // Exit if accessed directly

class SBI_Elementor_Widget extends Widget_Base {

	public function get_name() {
		return 'sbi-widget';
	}
	public function get_title() {
		return esc_html__('Instagram Feed', 'feeds-for-youtube');
	}
	public function get_icon() {
		return 'sb-elem-icon sb-elem-inactive sb-elem-instagram';
	}
	public function get_categories() {
		return array('smash-balloon');
	}
	public function show_in_panel() {
		return false;
	}
	public function hide_on_search() {
		return true;
	}
	public function get_script_depends() {
		return [
		'sby-elementor-handler'
		];
	}
}
