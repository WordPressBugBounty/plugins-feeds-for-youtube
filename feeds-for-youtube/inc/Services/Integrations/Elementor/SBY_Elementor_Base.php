<?php

namespace SmashBalloon\YouTubeFeed\Services\Integrations\Elementor;

use SmashBalloon\YouTubeFeed\Services\Integrations\Elementor\SBY_Elementor_Control;
use SmashBalloon\YouTubeFeed\Services\Integrations\Elementor\SBY_Elementor_Widget;
use SmashBalloon\YouTubeFeed\Helpers\Util;

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
	}

	public function register_controls($controls_manager)
	{
		$controls_manager->register(new SBY_Elementor_Control());
	}

	public function register_widgets($widgets_manager)
	{
		$widgets_manager->register(new SBY_Elementor_Widget());

		$installed_plugins = sby_get_installed_plugin_info();
		unset($installed_plugins['youtube']);

		foreach($installed_plugins as $plugin) {
			if (!$plugin['installed']){
				$plugin_class = str_replace('.','\\', self::NAME_SPACE) . $plugin['class'];
				$widgets_manager->register(new $plugin_class());
			}
		}
	}

	public function register_frontend_scripts(){
		$css_common_file = Util::getPluginAssets('css', 'sb-youtube-common');
		$css_file = sby_is_pro() ? Util::getPluginAssets('css', 'sb-youtube') : Util::getPluginAssets('css', 'sb-youtube-free');

		wp_enqueue_style('sby_common_styles', $css_common_file, array(), SBYVER);
		wp_enqueue_style('sby_styles', $css_file, array(), SBYVER);

		wp_enqueue_style(
			'sby_styles', 
			$css_common_file,
			array(), 
			SBYVER
		);

		wp_enqueue_style(
			'sby_styles', 
			$css_file,
			array(), 
			SBYVER
		);

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
			'isCustomizer' => false
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
	}

	public function register_frontend_styles()
	{
		$css_common_file = Util::getPluginAssets('css', 'sb-youtube-common');
		$css_file = sby_is_pro() ? Util::getPluginAssets('css', 'sb-youtube') : Util::getPluginAssets('css', 'sb-youtube-free');

		wp_register_style(
			'sby-common-styles',
			SBY_PLUGIN_URL . 'css/' . $css_common_file,
			array(),
			SBYVER
		);

		wp_register_style(
			'sby-styles',
			SBY_PLUGIN_URL . 'css/' . $css_file,
			array(),
			SBYVER
		);
	}

	public function enqueue_frontend_styles()
	{
		wp_enqueue_style('sby-common-styles');
		wp_enqueue_style('sby-styles');
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
	}

}

