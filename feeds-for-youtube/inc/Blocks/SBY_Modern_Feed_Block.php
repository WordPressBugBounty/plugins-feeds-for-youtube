<?php

namespace SmashBalloon\YouTubeFeed\Blocks;

use SmashBalloon\YoutubeFeed\Vendor\Smashballoon\Framework\Packages\Blocks\SB_Feed_Block;
use SmashBalloon\YouTubeFeed\Builder\SBY_Db;

// phpcs:disable PSR12.Classes.OpeningBraceSpace.Found,PSR1.Methods.CamelCapsMethodName.NotCamelCaps,PSR2.Classes.ClassDeclaration.OpenBraceNewLine,PSR2.Methods.FunctionCallSignature.SpaceAfterOpenBracket,PSR2.Methods.FunctionCallSignature.SpaceBeforeCloseBracket,Squiz.Classes.ValidClassName.NotCamelCaps,Squiz.Commenting.FunctionComment.Missing,Squiz.Functions.MultiLineFunctionDeclaration.BraceOnSameLine
class SBY_Modern_Feed_Block extends SB_Feed_Block {

	const EDITOR_ASSETS_PRIORITY = 25;

	protected function get_block_name() {
		return 'smashballoon/youtube-feed';
	}

	protected function get_shortcode_tag() {
		return 'youtube-feed';
	}

	protected function get_script_handle() {
		return 'sb-feed-blocks';
	}

	protected function get_text_domain() {
		return 'feeds-for-youtube';
	}

	protected function get_plugin_dir() {
		return trailingslashit( SBY_PLUGIN_DIR );
	}

	protected function get_enqueue_scripts_action() {
		return 'sby_enqueue_scripts';
	}

	protected function get_localize_var_name() {
		return 'sbYouTubeFeedBlock';
	}

	protected function get_feed_block_id() {
		return 'youtube';
	}

	protected function get_init_function() {
		return 'sby_init';
	}

	protected function get_block_dir() {
		return $this->get_plugin_dir() . 'vendor/smashballoon/framework/Packages/Blocks/dist/feed-blocks/youtube';
	}

	public function register_hooks() {
		parent::register_hooks();
		remove_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_assets' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_assets' ), self::EDITOR_ASSETS_PRIORITY );
	}

	protected function get_editor_localize_data() {
		$feeds = SBY_Db::elementor_feeds_list();

		return array(
			'feeds'    => ! empty( $feeds ) ? $feeds : array(),
			'feed_url' => admin_url( 'admin.php?page=sby-feed-builder' ),
			'nonce'    => wp_create_nonce( 'sby-blocks' ),
		);
	}
}
