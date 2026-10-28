<?php

namespace SmashBalloon\YouTubeFeed\Abilities;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Browser-side start of an ability-initiated channel connect.
 *
 * The Settings page never *links* to the OAuth relay: its "Add Source" form
 * (SourcesSection.js) auto-submits a POST carrying a browser-session `nonce`
 * (wp_create_nonce('sby_con'), minted server-side by
 * Feed_Builder::oauth_connet_parameters() on page render) plus return_uri,
 * email, pro and version fields. This page reproduces that exact POST.
 * `get-connect-url` hands the admin a wp-admin URL carrying only our
 * single-use state token; landing here (capability-checked, token consumed)
 * calls the SAME native `oauth_connet_parameters()` helper to mint a FRESH
 * session nonce and the rest of the fields, overriding only `return_uri` to
 * point at the Settings page (rather than the Feed Builder) so the admin
 * sees the new channel land — then auto-submits, so the relay round-trip
 * runs through the plugin's native path, completely unchanged.
 *
 * The state token never leaves this site: the relay only ever sees the same
 * fields the Settings page's own Add Source button sends it.
 *
 * @since SBY_ABILITIES_SINCE
 */
class SBY_Connect_Bootstrap
{
	/**
	 * Hook the bootstrap page onto admin_init.
	 *
	 * @return void
	 */
	public function hooks()
	{
		add_action('admin_init', array($this, 'maybe_render'), 1);
	}

	/**
	 * Render the auto-submitting connect page when the state token checks out.
	 *
	 * @return void
	 */
	public function maybe_render()
	{
		if (
			empty($_GET['page']) || $_GET['page'] !== 'sby-feed-builder'
			|| empty($_GET['sby_abilities_connect']) || !is_string($_GET['sby_abilities_connect'])
		) {
			return;
		}
		if (!sby_current_user_can('manage_youtube_feed_options')) {
			return;
		}
		// Revocation parity with SBY_Delete_Confirmation::handle_delete(): if AI
		// write access was turned off after this link was minted, honour the
		// revocation rather than completing a connect an agent is no longer
		// allowed to have requested.
		$token = sanitize_key($_GET['sby_abilities_connect']);
		// The token is consumed (burned) when the gate refuses, so turning write
		// access back on later cannot revive a link the admin was told is dead.
		// Capability was checked above and tokens are user-bound, so this can
		// only burn the current user's own link.
		if (!SBY_Write_Gate::is_enabled()) {
			SBY_State_Token::consume($token, 'connect');
			wp_die(
				esc_html__('AI write access has been disabled since this link was created.', 'feeds-for-youtube'),
				esc_html__('YouTube Feed', 'feeds-for-youtube'),
				array('response' => 403, 'back_link' => true)
			);
		}
		if (!SBY_State_Token::consume($token, 'connect')) {
			wp_die(
				esc_html__(
					'This connect link is invalid or has expired. Ask your AI assistant for a new one.',
					'feeds-for-youtube'
				),
				esc_html__('YouTube Feed', 'feeds-for-youtube'),
				array('response' => 403, 'back_link' => true)
			);
		}

		if (!class_exists('Smashballoon\\Customizer\\Feed_Builder')) {
			wp_die(
				esc_html__('The YouTube Feed connect flow is unavailable right now.', 'feeds-for-youtube'),
				esc_html__('YouTube Feed', 'feeds-for-youtube'),
				array('response' => 500, 'back_link' => true)
			);
		}

		// Same helper the Settings page itself calls to build
		// connect_site_parameters — minted HERE, in the browser session, with the
		// return_uri overridden to the Settings page (its own "Connected YouTube
		// Account" section), so the admin watches the channel get added where
		// channels are managed instead of the Feed Builder.
		$fields               = \Smashballoon\Customizer\Feed_Builder::oauth_connet_parameters();
		$connect_site_url     = $fields['connect_site_url'];
		unset($fields['connect_site_url']);
		$fields['return_uri'] = admin_url('admin.php?page=youtube-feed-settings');

		header('Content-Type: text/html; charset=' . get_option('blog_charset'));
		header('X-Frame-Options: SAMEORIGIN');
		header('Referrer-Policy: no-referrer');
		// phpcs:disable Generic.Files.LineLength.TooLong -- inline template/CSS.
		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="<?php bloginfo('charset'); ?>">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title><?php esc_html_e('Connecting to YouTube…', 'feeds-for-youtube'); ?></title>
			<style>
				body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f0f0f1;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
				.sby-connect{background:#fff;border:1px solid #c3c4c7;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.1);max-width:440px;padding:32px;text-align:center}
				.sby-connect h1{font-size:18px;margin:0 0 12px}
				.sby-connect p{color:#50575e;margin:0 0 24px}
				.sby-connect button{background:#FF0000;border:none;border-radius:4px;color:#fff;cursor:pointer;font-size:14px;padding:10px 18px}
			</style>
		</head>
		<body>
			<div class="sby-connect">
				<h1><?php esc_html_e('Connecting to YouTube…', 'feeds-for-youtube'); ?></h1>
				<p><?php esc_html_e('Redirecting you to log in with Google. Afterwards you will return here to see the connected channel. Your AI assistant never sees your credentials.', 'feeds-for-youtube'); ?></p>
				<form id="sby-abilities-connect" method="post" action="<?php echo esc_url($connect_site_url); ?>">
					<?php foreach ($fields as $name => $value) : ?>
						<input type="hidden" name="<?php echo esc_attr($name); ?>" value="<?php echo esc_attr($value); ?>">
					<?php endforeach; ?>
					<noscript><button type="submit"><?php esc_html_e('Continue to Google login', 'feeds-for-youtube'); ?></button></noscript>
				</form>
				<script>document.getElementById('sby-abilities-connect').submit();</script>
			</div>
		</body>
		</html>
		<?php
		// phpcs:enable Generic.Files.LineLength.TooLong
		exit;
	}
}
