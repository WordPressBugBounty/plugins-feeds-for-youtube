<?php

namespace Smashballoon\Customizer;

class Feed_Locator
{
    /**
     * @var Config
     */
    private $config;
    private $table;
    public function __construct(\Smashballoon\Customizer\Config $config)
    {
        $this->config = $config;
        $this->table = $this->config->plugin_slug . '_feed_locator';
    }
    public function legacy_feed_locator_query($args)
    {
        global $wpdb;
        $feed_locator_table_name = $wpdb->prefix . $this->table;
        // Resolve group_by against an allow-list of real columns; an unknown
        // value yields no GROUP BY. Identifiers cannot be bound as placeholders.
        $group_by = '';
        if (isset($args['group_by'])) {
            $allowed_group_by = array('shortcode_atts', 'post_id', 'feed_id', 'html_location');
            if (in_array($args['group_by'], $allowed_group_by, \true)) {
                $group_by = 'GROUP BY ' . $args['group_by'];
            }
        }
        $location_string = 'content';
        if (isset($args['html_location'])) {
            $locations = array_map('esc_sql', $args['html_location']);
            $location_string = implode("', '", $locations);
        }
        // Bind the shortcode_atts / feed_id predicate as %s, like count() and
        // feed_locator_query() do. shortcode_atts wins when both are present,
        // preserving the previous behaviour.
        $predicate = '';
        $predicate_value = null;
        if (isset($args['shortcode_atts'])) {
            $predicate = 'AND shortcode_atts = %s';
            $predicate_value = $args['shortcode_atts'];
        } elseif (isset($args['feed_id'])) {
            $predicate = 'AND feed_id = %s';
            $predicate_value = $args['feed_id'];
        }
        $page = 0;
        if (isset($args['page'])) {
            $page = (int) $args['page'] - 1;
            unset($args['page']);
        }
        $offset = max(0, $page * \Smashballoon\Customizer\DB::RESULTS_PER_PAGE);
        $limit = \Smashballoon\Customizer\DB::RESULTS_PER_PAGE;
        // The NOT LIKE '*%' literal is escaped as '*%%' so prepare() does not
        // read %' as a malformed placeholder specifier.
        $sql = "\r\n\t\t\tSELECT *\r\n\t\t\tFROM {$feed_locator_table_name}\r\n\t\t\tWHERE feed_id NOT LIKE '*%%'\r\n\t\t  \tAND html_location IN ( '{$location_string}' )\r\n\t\t  \t{$predicate}\r\n\t\t  \t{$group_by}\r\n\t\t  \tLIMIT %d\r\n\t\t\tOFFSET %d;";
        $prepare_args = array();
        if (null !== $predicate_value) {
            $prepare_args[] = $predicate_value;
        }
        $prepare_args[] = $limit;
        $prepare_args[] = $offset;
        $results = $wpdb->get_results($wpdb->prepare($sql, $prepare_args), \ARRAY_A);
        return $results;
    }
    public function count($args)
    {
        global $wpdb;
        $feed_locator_table_name = $wpdb->prefix . $this->table;
        if (isset($args['shortcode_atts'])) {
            $results = $wpdb->get_results($wpdb->prepare("\r\n\t\t\tSELECT COUNT(*) AS num_entries\r\n            FROM {$feed_locator_table_name}\r\n            WHERE shortcode_atts = %s\r\n            ", $args['shortcode_atts']), \ARRAY_A);
        } else {
            $results = $wpdb->get_results($wpdb->prepare("\r\n\t\t\tSELECT COUNT(*) AS num_entries\r\n            FROM {$feed_locator_table_name}\r\n            WHERE feed_id = %s\r\n            ", $args['feed_id']), \ARRAY_A);
        }
        if (isset($results[0]['num_entries'])) {
            return (int) $results[0]['num_entries'];
        }
        return 0;
    }
    public function feed_locator_query($args)
    {
        global $wpdb;
        $feed_locator_table_name = $wpdb->prefix . $this->table;
        $group_by = '';
        if (isset($args['group_by'])) {
            $group_by = 'GROUP BY ' . esc_sql($args['group_by']);
        }
        $location_string = 'content';
        if (isset($args['html_location'])) {
            $locations = array_map('esc_sql', $args['html_location']);
            $location_string = implode("', '", $locations);
        }
        $page = 0;
        if (isset($args['page'])) {
            $page = (int) $args['page'] - 1;
            unset($args['page']);
        }
        $offset = max(0, $page * \Smashballoon\Customizer\DB::RESULTS_PER_PAGE);
        if (isset($args['shortcode_atts'])) {
            $results = $wpdb->get_results($wpdb->prepare("\r\n\t\t\tSELECT *\r\n\t\t\tFROM {$feed_locator_table_name}\r\n\t\t\tWHERE shortcode_atts = %s\r\n\t\t  \tAND html_location IN ( '{$location_string}' )\r\n\t\t  \t{$group_by}\r\n\t\t  \tLIMIT %d\r\n\t\t\tOFFSET %d;", $args['shortcode_atts'], \Smashballoon\Customizer\DB::RESULTS_PER_PAGE, $offset), \ARRAY_A);
        } else {
            $results = $wpdb->get_results($wpdb->prepare("\r\n\t\t\tSELECT *\r\n\t\t\tFROM {$feed_locator_table_name}\r\n\t\t\tWHERE feed_id = %s\r\n\t\t  \tAND html_location IN ( '{$location_string}' )\r\n\t\t  \t{$group_by}\r\n\t\t  \tLIMIT %d\r\n\t\t\tOFFSET %d;", $args['feed_id'], \Smashballoon\Customizer\DB::RESULTS_PER_PAGE, $offset), \ARRAY_A);
        }
        return $results;
    }
    /**
     * A custom table stores locations
     */
    public function create_table()
    {
        global $wpdb;
        $feed_locator_table_name = $wpdb->prefix . $this->table;
        if ($wpdb->get_var("show tables like '{$feed_locator_table_name}'") != $feed_locator_table_name) {
            $sql = "CREATE TABLE " . $feed_locator_table_name . " (\r\n\t\t\t\tid BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,\r\n                feed_id VARCHAR(50) DEFAULT '' NOT NULL,\r\n                post_id BIGINT(20) UNSIGNED NOT NULL,\r\n                html_location VARCHAR(50) DEFAULT 'unknown' NOT NULL,\r\n                shortcode_atts LONGTEXT NOT NULL,\r\n                last_update DATETIME\r\n            );";
            $wpdb->query($sql);
        }
        $error = $wpdb->last_error;
        $query = $wpdb->last_query;
        $had_error = \false;
        if ($wpdb->get_var("show tables like '{$feed_locator_table_name}'") != $feed_locator_table_name) {
            $had_error = \true;
        }
        if (!$had_error) {
            $wpdb->query("ALTER TABLE {$feed_locator_table_name} ADD INDEX feed_id (feed_id)");
            $wpdb->query("ALTER TABLE {$feed_locator_table_name} ADD INDEX post_id (post_id)");
        }
    }
    public function update_legacy_to_builder($args)
    {
        global $wpdb;
        $data = array('feed_id' => '*' . $args['new_feed_id'], 'shortcode_atts' => '{"feed":"' . $args['new_feed_id'] . '"}');
        $affected = $wpdb->query($wpdb->prepare("UPDATE {$this->table}\r\n         \t\t\t\tSET feed_id = %s, shortcode_atts = %s", $data['feed_id'], $data['shortcode_atts']));
        return $affected;
    }
}
