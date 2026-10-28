<?php

namespace SmashBalloon\YouTubeFeed\Abilities;

use SmashBalloon\YouTubeFeed\Builder\SBY_Db;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * Browser-side confirmation for ability-requested feed deletion.
 *
 * The `request-feed-deletion` ability never deletes anything: it returns a
 * link to this screen. Deletion happens only after the logged-in admin sees
 * the feed's name here and clicks Confirm — capability check, admin nonce and
 * single-use state token all verified on the POST.
 *
 * @since SBY_ABILITIES_SINCE
 */
class SBY_Delete_Confirmation
{
	/**
	 * Hook the confirmation screen and its POST handler.
	 *
	 * @return void
	 */
	public function hooks()
	{
		add_action('admin_init', array($this, 'maybe_render'), 2);
		add_action('admin_post_sby_abilities_delete_feed', array($this, 'handle_delete'));
	}

	/**
	 * Render the confirmation interstitial when the state token checks out.
	 *
	 * @return void
	 */
	public function maybe_render()
	{
		if (
			empty($_GET['page']) || $_GET['page'] !== 'sby-feed-builder'
			|| empty($_GET['sby_abilities_confirm_delete']) || empty($_GET['sby_abilities_state'])
			|| !is_string($_GET['sby_abilities_confirm_delete']) || !is_string($_GET['sby_abilities_state'])
		) {
			return;
		}
		if (!sby_current_user_can('manage_youtube_feed_options')) {
			return;
		}
		$feed_id = absint($_GET['sby_abilities_confirm_delete']);
		$token   = sanitize_key($_GET['sby_abilities_state']);
		// n2 (Rex round 2): re-check the write gate here too, matching SBY_Connect_Bootstrap's own
		// revocation check — a link minted before the toggle was turned off should show the
		// revocation on the confirmation screen, not only fail later at the POST.
		// Capability was checked above, so only an authorised user reaches this;
		// tokens are user-bound, so this can only burn the caller's own link.
		if (!SBY_Write_Gate::is_enabled()) {
			self::revoke_pending($token, $feed_id);
			wp_die(
				esc_html__('AI write access has been disabled since this link was created.', 'feeds-for-youtube'),
				esc_html__('YouTube Feed', 'feeds-for-youtube'),
				array('response' => 403, 'back_link' => true)
			);
		}
		if (!$feed_id || !SBY_State_Token::peek($token, 'delete_feed_' . $feed_id)) {
			wp_die(
				esc_html__(
					'This deletion link is invalid or has expired. Ask your AI assistant for a new one.',
					'feeds-for-youtube'
				),
				esc_html__('YouTube Feed', 'feeds-for-youtube'),
				array('response' => 403, 'back_link' => true)
			);
		}
		$rows = SBY_Db::feeds_query(array('id' => $feed_id));
		if (empty($rows)) {
			wp_die(
				esc_html__('This feed no longer exists.', 'feeds-for-youtube'),
				esc_html__('YouTube Feed', 'feeds-for-youtube'),
				array('response' => 404, 'back_link' => true)
			);
		}
		$feed_name  = $rows[0]['feed_name'];
		$cancel     = admin_url('admin.php?page=sby-feed-builder');
		$review_url = admin_url('admin.php?page=sby-feed-builder&feed_id=' . $feed_id);

		// Minimal interstitial, rendered before the builder app boots.
		// phpcs:disable Generic.Files.LineLength.TooLong -- inline template/CSS.
		header('Content-Type: text/html; charset=' . get_option('blog_charset'));
		header('X-Frame-Options: SAMEORIGIN');
		header('Referrer-Policy: no-referrer');
		?>
		<!DOCTYPE html>
		<html <?php language_attributes(); ?>>
		<head>
			<meta charset="utf-8">
			<meta name="viewport" content="width=device-width, initial-scale=1">
			<title><?php esc_html_e('Confirm feed deletion', 'feeds-for-youtube'); ?></title>
			<style>
				body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f0f0f1;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
				.sby-confirm{background:#fff;border:1px solid #c3c4c7;border-radius:8px;box-shadow:0 1px 3px rgba(0,0,0,.1);max-width:480px;padding:32px;text-align:center}
				.sby-confirm h1{font-size:18px;margin:0 0 12px}
				.sby-confirm p{color:#50575e;margin:0 0 24px}
				.sby-confirm .actions{display:flex;gap:12px;justify-content:center}
				.sby-confirm button{background:#d63638;border:none;border-radius:4px;color:#fff;cursor:pointer;font-size:14px;padding:10px 18px}
				.sby-confirm .actions a{align-items:center;border:1px solid #c3c4c7;border-radius:4px;color:#2c3338;display:inline-flex;font-size:14px;padding:10px 18px;text-decoration:none}
				.sby-confirm .meta{background:#f6f7f7;border-radius:4px;color:#50575e;font-size:13px;margin:0 0 24px;padding:12px}
				.sby-confirm .meta code{background:transparent}
				.sby-confirm .meta a{color:#2271b1}
			</style>
		</head>
		<body>
			<div class="sby-confirm">
				<h1><?php esc_html_e('Delete this YouTube feed?', 'feeds-for-youtube'); ?></h1>
				<p>
					<?php
					printf(
						/* translators: 1: feed name, 2: feed id */
						esc_html__('An AI assistant asked to delete the feed %1$s (ID %2$d). This removes the feed and its caches; the connected channel is not affected. This cannot be undone.', 'feeds-for-youtube'),
						'<strong>' . esc_html($feed_name) . '</strong>',
						(int) $feed_id
					);
					?>
				</p>
				<p class="meta">
					<?php
					printf(
						/* translators: 1: shortcode, 2: opening anchor tag, 3: closing anchor tag */
						esc_html__('Shortcode: %1$s — %2$sreview this feed in the Feed Builder%3$s before deciding.', 'feeds-for-youtube'),
						'<code>[youtube-feed feed=' . (int) $feed_id . ']</code>',
						'<a href="' . esc_url($review_url) . '" target="_blank" rel="noopener">',
						'</a>'
					);
					?>
				</p>
				<div class="actions">
					<form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
						<input type="hidden" name="action" value="sby_abilities_delete_feed">
						<input type="hidden" name="feed_id" value="<?php echo (int) $feed_id; ?>">
						<input type="hidden" name="state" value="<?php echo esc_attr($token); ?>">
						<?php wp_nonce_field('sby_abilities_delete_feed'); ?>
						<button type="submit"><?php esc_html_e('Delete feed', 'feeds-for-youtube'); ?></button>
					</form>
					<a href="<?php echo esc_url($cancel); ?>"><?php esc_html_e('Cancel', 'feeds-for-youtube'); ?></a>
				</div>
			</div>
		</body>
		</html>
		<?php
		// phpcs:enable Generic.Files.LineLength.TooLong
		exit;
	}

	/**
	 * Handle the confirmation POST: verify, then delete the feed.
	 *
	 * @return void
	 */
	public function handle_delete()
	{
		if (!sby_current_user_can('manage_youtube_feed_options')) {
			wp_die(
				esc_html__('You do not have permission to do this.', 'feeds-for-youtube'),
				'',
				array('response' => 403)
			);
		}
		check_admin_referer('sby_abilities_delete_feed');

		$feed_id = isset($_POST['feed_id']) && is_scalar($_POST['feed_id']) ? absint($_POST['feed_id']) : 0;
		$token   = isset($_POST['state']) && is_string($_POST['state']) ? sanitize_key($_POST['state']) : '';

		// If AI write access was revoked after the link was minted, honour the
		// revocation: the whole flow exists because an agent asked. The token is
		// burned (not just refused) so re-enabling the gate later cannot revive it.
		if (!SBY_Write_Gate::is_enabled()) {
			self::revoke_pending($token, $feed_id);
			wp_die(
				esc_html__('AI write access has been disabled since this link was created.', 'feeds-for-youtube'),
				'',
				array('response' => 403)
			);
		}

		if (!$feed_id || !SBY_State_Token::consume($token, 'delete_feed_' . $feed_id)) {
			wp_die(
				esc_html__('This deletion link is invalid or has expired.', 'feeds-for-youtube'),
				'',
				array('response' => 403)
			);
		}

		// Same rows SBY_Db::delete_feeds_query() removes (it is an AJAX endpoint
		// that echoes the feed list and dies, so the delete is replicated here for
		// a redirect-based flow instead of calling it directly). That native
		// function also clears `sbi_feed_caches` — the IG-prefixed table, evidently
		// a copy-paste leftover from Instagram Feed. SBY_Cache::clear() (the class
		// the plugin's OWN feed-save/preview paths actually call to invalidate a
		// single feed's cache) reads and writes `sby_feed_caches` only, so this
		// deliberately does NOT replicate the `sbi_feed_caches` delete (Alex's
		// PR#741 architecture audit, finding 4): on a site without Instagram Feed
		// installed that table doesn't exist, and with WP_DEBUG_DISPLAY on, $wpdb
		// prints the resulting DB error before wp_safe_redirect() below, which then
		// fails with "headers already sent". `sby_feed_caches` is the table that
		// actually matters and is always present, so clearing it alone is both
		// correct and safe. Filed as a native-plugin bug follow-up separately.
		global $wpdb;
		$feeds_table      = $wpdb->prefix . 'sby_feeds';
		$sby_caches_table = $wpdb->prefix . 'sby_feed_caches';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are $wpdb->prefix + constant.
		$wpdb->query($wpdb->prepare("DELETE FROM {$feeds_table} WHERE id = %d", $feed_id));
		// SBY_Cache::clear() also writes `{feed_id}_CUSTOMIZER` and
		// `{feed_id}_CUSTOMIZER_MODMODE<n>` rows (the Feed Builder preview's own
		// cache slots) — a bare `feed_id = %s` here would leave those orphaned.
		// Both the feed-id prefix AND the literal `_CUSTOMIZER` suffix are escaped
		// through esc_like() (Alex's PR#741 architecture audit, finding 4): an
		// unescaped `_` is a single-char LIKE wildcard, so a bare `'_CUSTOMIZER%'`
		// literal would also match e.g. `1239CUSTOMIZER%` for feed id 123.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$sby_caches_table} WHERE feed_id = %s OR feed_id LIKE %s",
				$feed_id,
				$wpdb->esc_like((string) $feed_id) . $wpdb->esc_like('_CUSTOMIZER') . '%'
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		/**
		 * Fires after a feed was deleted through the ability-initiated,
		 * browser-confirmed flow.
		 *
		 * @param int $feed_id
		 */
		do_action('sby_abilities_feed_deleted', $feed_id);

		wp_safe_redirect(admin_url('admin.php?page=sby-feed-builder&sby_abilities_feed_deleted=' . $feed_id));
		exit;
	}

	/**
	 * Permanently kill a deletion link because the write gate refused it.
	 * Callers MUST have passed the capability check first. consume() only
	 * succeeds for a token minted by the current user for this exact action, and
	 * revoke_group() only touches the current user's slot, so an unauthorised or
	 * different user cannot burn anyone else's link.
	 *
	 * @param string $token
	 * @param int    $feed_id
	 * @return void
	 */
	private static function revoke_pending($token, $feed_id)
	{
		if ($feed_id && $token !== '') {
			SBY_State_Token::consume($token, 'delete_feed_' . $feed_id);
		}
		// Also clear the single-pending-deletion slot (and the token it points at).
		SBY_State_Token::revoke_group('delete');
	}
}
