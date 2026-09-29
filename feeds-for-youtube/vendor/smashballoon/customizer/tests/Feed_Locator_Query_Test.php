<?php

/**
 * Regression check for Feed_Locator::legacy_feed_locator_query().
 *
 * Framework-free self-check: run with `php tests/Feed_Locator_Query_Test.php`.
 * Fails loudly (assert) if a value carried in the shortcode_atts / feed_id
 * predicate or the group_by identifier can change the shape of the query.
 *
 * Covers the reported path: a value planted anonymously in
 * wp_sby_feed_locator.shortcode_atts is read back by the Feed Builder, which
 * feeds it straight into legacy_feed_locator_query() as $args['shortcode_atts'].
 */
namespace SmashBalloon\YoutubeFeed\Vendor;

if (!\defined('ARRAY_A')) {
    \define('ARRAY_A', 'ARRAY_A');
}
// esc_sql is a global WP function; the class calls it as a string callback.
function esc_sql($value)
{
    return \addslashes($value);
}
namespace Smashballoon\Customizer;

// --- Minimal WordPress + package stubs (no real WP / DB needed) -------------
class Config
{
    public $plugin_slug = 'sbc';
}
class DB
{
    const RESULTS_PER_PAGE = 20;
}
/**
 * Stand-in for $wpdb that mimics prepare()'s placeholder handling closely
 * enough to prove the payload stays inside a bound, escaped literal.
 */
class FakeWpdb
{
    public $prefix = 'wp_';
    public $last_query = '';
    public function prepare($query, $args)
    {
        if (!is_array($args)) {
            $args = array_slice(func_get_args(), 1);
        }
        $i = 0;
        return preg_replace_callback('/%[sd%]/', function ($m) use (&$i, $args) {
            if ('%%' === $m[0]) {
                return '%';
            }
            $value = $args[$i++];
            if ('%d' === $m[0]) {
                return (string) (int) $value;
            }
            return "'" . addslashes($value) . "'";
        }, $query);
    }
    public function get_results($query, $output)
    {
        $this->last_query = $query;
        return array();
    }
}
require __DIR__ . '/../app/Feed_Locator.php';
$wpdb = new \Smashballoon\Customizer\FakeWpdb();
$GLOBALS['wpdb'] = $wpdb;
$locator = new \Smashballoon\Customizer\Feed_Locator(new \Smashballoon\Customizer\Config());
// Wordfence-style breakout value, as it would arrive from the stored row.
$payload = "q' UNION SELECT 1,2,3,4,user_pass,NOW() FROM wp_users WHERE ID=1 AND 'a'!='";
// 1) shortcode_atts (the read-back path) is bound, not concatenated.
$locator->legacy_feed_locator_query(array('html_location' => array('content'), 'group_by' => 'shortcode_atts', 'shortcode_atts' => $payload));
$q = $wpdb->last_query;
assert(\false !== strpos($q, "q\\' UNION"));
// quote escaped -> stays a literal
assert(\false === strpos($q, "q' UNION"));
// no raw breakout
assert(\false !== strpos($q, 'GROUP BY shortcode_atts'));
// legit group_by kept
assert(\false !== strpos($q, "NOT LIKE '*%'"));
// %% collapsed to one %
// 2) feed_id predicate is bound too.
$locator->legacy_feed_locator_query(array('html_location' => array('content'), 'feed_id' => $payload));
assert(\false === strpos($wpdb->last_query, "q' UNION"));
// 3) A group_by outside the allow-list is dropped (no injected identifier).
$locator->legacy_feed_locator_query(array('html_location' => array('content'), 'group_by' => 'shortcode_atts; DROP TABLE wp_users; --', 'shortcode_atts' => 'x'));
assert(\false === strpos($wpdb->last_query, 'DROP TABLE'));
echo "Feed_Locator_Query_Test: OK\n";
