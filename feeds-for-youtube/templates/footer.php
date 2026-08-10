<?php

// Don't load directly
use SmashBalloon\YouTubeFeed\SBY_Display_Elements;
use SmashBalloon\YouTubeFeed\SBY_Parse;

if ( ! defined( 'ABSPATH' ) ) {
	die( '-1' );
}
$subscribe_url = isset( $posts[0] ) ? SBY_Parse::get_channel_permalink( $posts[0] ) : '';

$sub_btn_style   = SBY_Display_Elements::get_subscribe_styles( $settings ); // style="background: rgb();color: rgb();"  already escaped
$sub_btn_classes = strpos( $sub_btn_style, 'background' ) !== false ? ' sby_custom' : '';
$show_subscribe_button = $settings['showsubscribe'];
$subscribe_button_text = $settings['subscribetext'];

$load_btn_style   = SBY_Display_Elements::get_load_button_styles( $settings ); // style="background: rgb();color: rgb();" already escaped
$load_btn_classes = strpos( $load_btn_style, 'background' ) !== false ? ' sby_custom' : '';
$load_button_text = __( $settings['buttontext'], 'feeds-for-youtube' );
?>
<div class="sby_footer">

<?php
// SMASH-1378 / WCAG 4.1.3 — polite status region. The Load More handler in
// js/sb-youtube.js announces how many new videos were appended so screen
// reader users get feedback that paginated content loaded. Visually hidden
// via the shared .sby-screenreader utility (css/sb-youtube-common.css).
?>
<div class="sby-screenreader" role="status" aria-live="polite" aria-atomic="true" data-sby-feed-status></div>

<?php if ( $use_pagination || sby_doing_customizer( $settings ) ) : ?>
    <button type="button" aria-label="<?php esc_attr_e( 'Load more content', 'feeds-for-youtube' ); ?>" class="sby_load_btn" <?php echo $load_btn_style; ?> <?php echo SBY_Display_Elements::get_button_data_attributes( $settings ); ?>>
        <span class="sby_btn_text" <?php echo SBY_Display_Elements::get_load_button_attribute( $settings ); ?>><?php echo esc_html( $load_button_text ); ?></span>
        <span class="sby_loader sby_hidden" style="background-color: rgb(255, 255, 255);"></span>
    </button>
<?php endif; ?>

<?php if ( ($first_username && $show_subscribe_button) || sby_doing_customizer( $settings ) ) : ?>
    <span 
        class="sby_follow_btn<?php echo esc_attr( $sub_btn_classes ); ?>" 
        <?php echo SBY_Display_Elements::get_subscribe_button_data_attributes( $settings ); ?>
    >
        <a 
            href="<?php echo esc_url( $subscribe_url ); ?>"
            <?php echo $sub_btn_style; ?> 
            target="_blank" 
            rel="noopener"
        >
            <?php echo SBY_Display_Elements::get_icon( 'youtube', $icon_type ); ?>
            <span <?php echo SBY_Display_Elements::get_subscribe_button_attribute( $settings ); ?> >
                <?php echo esc_html( $subscribe_button_text ); ?>
            </span>
        </a>
    </span>
<?php endif; ?>
</div>