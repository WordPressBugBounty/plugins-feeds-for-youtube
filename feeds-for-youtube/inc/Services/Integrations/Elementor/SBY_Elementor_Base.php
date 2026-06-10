<?php // phpcs:disable PSR1.Methods.CamelCapsMethodName.NotCamelCaps,PSR2.Classes.ClassDeclaration.CloseBraceAfterBody,PSR2.Files.EndFileNewline.TooMany,PSR2.Methods.FunctionCallSignature.SpaceAfterOpenBracket,PSR2.Methods.FunctionCallSignature.SpaceBeforeCloseBracket,Squiz.Classes.ValidClassName.NotCamelCaps,Squiz.Commenting.FunctionComment.Missing,Squiz.Commenting.InlineComment.InvalidEndChar,Squiz.Commenting.VariableComment.Missing,Squiz.Functions.MultiLineFunctionDeclaration.BraceOnSameLine,Squiz.Functions.MultiLineFunctionDeclaration.SpaceAfterFunction

namespace SmashBalloon\YouTubeFeed\Services\Integrations\Elementor;

use SmashBalloon\YouTubeFeed\Services\Integrations\Elementor\SBY_Elementor_Control;
use SmashBalloon\YouTubeFeed\Services\Integrations\Elementor\SBY_Elementor_Widget;
use SmashBalloon\YouTubeFeed\Blocks\SBY_Modern_Elementor_Widget;
use SmashBalloon\YouTubeFeed\Builder\SBY_Db;
use SmashBalloon\YouTubeFeed\Helpers\Util;
use SmashBalloon\YoutubeFeed\Vendor\Smashballoon\Framework\Packages\Blocks\RecommendedElementorWidgets;
use SmashBalloon\YoutubeFeed\Vendor\Smashballoon\Framework\Packages\Blocks\SB_Feed_Blocks_Registry;
use SmashBalloon\YoutubeFeed\Vendor\Smashballoon\Framework\Packages\Blocks\SB_Block_Utils;
use SmashBalloon\YoutubeFeed\Vendor\Smashballoon\Framework\Packages\Blocks\SB_Elementor_Editor_Assets;

class SBY_Elementor_Base
{
	const VERSION = SBYVER;
	const MINIMUM_ELEMENTOR_VERSION = '3.6.0';
	const MINIMUM_PHP_VERSION = '5.6';
	const NAME_SPACE = 'SmashBalloon.YouTubeFeed.Services.Integrations.Elementor.';
	private static $instance;

	public static function register()
	{
		if (!isset(self::$instance) && !self::$instance instanceof SBY_Elementor_Base) {
			self::$instance = new SBY_Elementor_Base();
			self::$instance->apply_hooks();
		}
		return self::$instance;
	}

	private function apply_hooks()
	{
		add_action('elementor/frontend/after_register_scripts', [$this, 'register_frontend_scripts']);
		add_action('elementor/frontend/after_register_styles', [$this, 'register_frontend_styles'], 10);
		add_action('elementor/frontend/after_enqueue_styles', [$this, 'enqueue_frontend_styles'], 10);
		add_action('elementor/controls/register', [$this, 'register_controls']);
		add_action('elementor/widgets/register', [$this,'register_widgets']);
		add_action('elementor/elements/categories_registered', [$this, 'add_smashballon_categories']);

		$recommended = new RecommendedElementorWidgets('youtube');
		$recommended->setup();

		$registry = SB_Feed_Blocks_Registry::instance();
		$registry->register_elementor_widget([
			'blockId'    => 'youtube',
			'widgetName' => 'sb-youtube-feed',
			'globalVar'  => 'sbyOptions',
			'feedInitFn' => 'sby_init',
		]);

		add_action('elementor/editor/after_enqueue_scripts', function() {
			SB_Elementor_Editor_Assets::enqueue_shared_elementor_styles(SBYVER);
		});
	}

	public function register_controls($controls_manager)
	{
		$controls_manager->register(new SBY_Elementor_Control());
	}

	public function register_widgets($widgets_manager)
	{
		// Old widget - kept for backward compat (hidden via show_in_panel)
		$widgets_manager->register(new SBY_Elementor_Widget());
		// Modern widget from framework
		$widgets_manager->register(new SBY_Modern_Elementor_Widget());
	}

	public function register_frontend_scripts(){
		$feeds = SBY_Db::elementor_feeds_list();
		$data = array(
			'isAdmin' => is_admin(),
			'adminAjaxUrl' => admin_url('admin-ajax.php' ),
			'placeholder' => trailingslashit(SBY_PLUGIN_URL) . 'img/placeholder.png',
			'placeholderNarrow' => trailingslashit(SBY_PLUGIN_URL) . 'img/placeholder-narrow.png',
			'lightboxPlaceholder' => trailingslashit(SBY_PLUGIN_URL) . 'img/lightbox-placeholder.png',
			'lightboxPlaceholderNarrow' => trailingslashit(SBY_PLUGIN_URL) . 'img/lightbox-placeholder-narrow.png',
			'autoplay' => false,
			'semiEagerload' => false,
			'eagerload' => false,
			'nonce'	=> wp_create_nonce('sby_nonce'),
			'isPro'	=> sby_is_pro(),
			'resized_url' => Util::sby_get_resized_uploads_url(),
			'isCustomizer' => false,
			'feeds' => ! empty($feeds) ? $feeds : array(),
			'feed_url' => admin_url('admin.php?page=sby-feed-builder'),
			'is_pro_active' => sby_is_pro(),
		);

		wp_register_script(
			'sbyscripts',
			Util::getPluginAssets('js', 'sb-youtube'),
			array('jquery'),
			SBYVER,
			true
		);
		wp_localize_script( 'sbyscripts', 'sbyOptions', $data );

		$data_handler = array(
			'smashPlugins'  => sby_get_installed_plugin_info(),
			'nonce'         => wp_create_nonce('sby-admin'),
			'ajax_handler'      =>  admin_url('admin-ajax.php'),
		);

		wp_register_script(
			'sby-elementor-handler',
			Util::getPluginAssets('js', 'elementor-handler'),
			array('jquery'),
			SBYVER,
			true
		);

		wp_localize_script('sby-elementor-handler', 'sbHandler', $data_handler);

		wp_register_script(
			'sby-elementor-preview',
			Util::getPluginAssets('js', 'elementor-preview'),
			array('jquery'),
			SBYVER,
			true
		);

		SB_Feed_Blocks_Registry::instance()->enqueue_elementor_assets();
	}

	public function register_frontend_styles()
	{
		$css_common_file = Util::getPluginAssets('css', 'sb-youtube-common');
		$css_file = sby_is_pro() ? Util::getPluginAssets('css', 'sb-youtube') : Util::getPluginAssets('css', 'sb-youtube-free');

		wp_register_style(
			'sby_common_styles',
			$css_common_file,
			array(),
			SBYVER
		);

		wp_register_style(
			'sby_styles',
			$css_file,
			array(),
			SBYVER
		);
	}

	public function enqueue_frontend_styles()
	{
		wp_enqueue_style('sby_common_styles');
		wp_enqueue_style('sby_styles');
	}

	public function add_smashballon_categories($elements_manager)
	{
		$elements_manager->add_category(
			'smash-balloon',
			[
				'title' => esc_html__('Smash Balloon', 'feeds-for-youtube'),
				'icon' => 'fa fa-plug',
			]
		);

		// Framework category for modern widgets (different slug from legacy 'smash-balloon' above)
		$elements_manager->add_category(
			SB_Block_Utils::CATEGORY_SLUG,
			[
				'title' => esc_html__('Smash Balloon', 'feeds-for-youtube'),
				'icon' => 'fa fa-plug',
			]
		);
	}

}

