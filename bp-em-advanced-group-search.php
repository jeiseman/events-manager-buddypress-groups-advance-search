<?php
/**
 * Plugin Name:       BuddyPress Events Manager - Advanced Group Search
 * Plugin URI:        https://#
 * Description:       Extends Events Manager to allow searching for events by a comma-separated list of group IDs or slugs, including exclusions.
 * Version:           1.0.0
 * Author:            Jon Eiseman
 * Author URI:        https://#
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain:       bp-em-advanced-group-search
 */

// If this file is called directly, abort.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Main setup function to replace old filters and add the new ones.
 */
function setup_custom_group_event_filters() {
    // Remove the original filter if it exists.
    if (function_exists('bp_em_group_events_get_default_search')) {
        remove_filter('em_events_get_default_search', 'bp_em_group_events_get_default_search', 1);
    }
    if (function_exists('bp_em_group_events_build_sql_conditions')) {
        remove_filter('em_events_build_sql_conditions', 'bp_em_group_events_build_sql_conditions', 1);
    }

    // Add our new, improved filters.
    add_filter('em_events_get_default_search', 'custom_bp_em_group_events_get_default_search', 1, 2);
    add_filter('em_events_build_sql_conditions', 'custom_bp_em_group_events_build_sql_conditions', 1, 2);
}
add_action('init', 'setup_custom_group_event_filters', 20); // Using priority 20 to ensure it runs after others.


/**
 * Parser Function: Handles group IDs, slugs, and special keywords.
 *
 * This version can parse a comma-separated list containing numeric group IDs
 * or string-based group slugs, converting slugs to IDs for the query.
 */
function custom_bp_em_group_events_get_default_search($searches, $array) {
    // 1. Pass through native Events Manager attributes first.
    if (array_key_exists('group__in', $array) && !array_key_exists('group__in', $searches)) {
        $searches['group__in'] = $array['group__in'];
    }
    if (array_key_exists('group__not_in', $array) && !array_key_exists('group__not_in', $searches)) {
        $searches['group__not_in'] = $array['group__not_in'];
    }

    // 2. If the 'group' attribute isn't set, we're done.
    if (!isset($array['group']) || !bp_is_active('groups')) {
        return $searches;
    }

    // Sanitize the input as a best practice.
    $group_attr = is_string($array['group']) ? sanitize_text_field($array['group']) : $array['group'];

    // 3. Handle special, non-list keywords.
    if ($group_attr === 'this' && is_numeric(bp_get_current_group_id())) {
        $searches['group_in'] = [bp_get_current_group_id()];
        unset($searches['group']);
        return $searches;
    }
    if ($group_attr === 'my' && is_user_logged_in()) {
        $searches['group'] = 'my';
        return $searches;
    }
    if ($group_attr === '0' || $group_attr === 0) {
        $searches['group_is_none'] = true;
        unset($searches['group']);
        return $searches;
    }

    // 4. Parse the comma-separated list of IDs and/or slugs.
    $group_ids_to_include = [];
    $group_ids_to_exclude = [];
    $items = explode(',', (string)$group_attr);

    foreach ($items as $item) {
        $trimmed_item = trim($item);
        if (empty($trimmed_item)) continue;

        $is_exclusion = strpos($trimmed_item, '-') === 0;
        $value = $is_exclusion ? substr($trimmed_item, 1) : $trimmed_item;
        $group_id = 0;

        if (is_numeric($value)) {
            $group_id = absint($value);
        } else {
            $group_id = groups_get_id($value);
        }

        if ($group_id > 0) {
            if ($is_exclusion) {
                $group_ids_to_exclude[] = $group_id;
            } else {
                $group_ids_to_include[] = $group_id;
            }
        }
    }

    // 5. Assign the parsed ID lists and any necessary keywords.
    if (!empty($group_ids_to_include)) $searches['group_in'] = $group_ids_to_include;
    if (!empty($group_ids_to_exclude)) $searches['group_notin'] = $group_ids_to_exclude;
    if (empty($group_ids_to_include) && !empty($group_ids_to_exclude)) $searches['group_must_exist'] = true;

    // 6. Unset the original 'group' key to prevent conflicts.
    if (!empty($group_ids_to_include) || !empty($group_ids_to_exclude)) unset($searches['group']);

    return $searches;
}


/**
 * SQL Builder Function: Handles our custom search keys AND native EM keys.
 */
function custom_bp_em_group_events_build_sql_conditions($conditions, $args) {
    // Handle group="0"
    if (!empty($args['group_is_none'])) {
        $conditions['group'] = "( `group_id` = 0 OR `group_id` IS NULL )";
    }

    // Handle group="-id"
    if (!empty($args['group_must_exist'])) {
        $conditions['group_exists'] = "( `group_id` IS NOT NULL AND `group_id` != 0 )";
    }

    // Handle IN conditions
    $include_ids = !empty($args['group_in']) ? $args['group_in'] : (!empty($args['group__in']) ? $args['group__in'] : []);
    if (!empty($include_ids) && is_array($include_ids)) {
        $ids_string = implode(',', array_map('absint', $include_ids));
        if (!empty($ids_string)) {
            $conditions['group'] = "( `group_id` IN ($ids_string) )";
        }
    }

    // Handle NOT IN conditions
    $exclude_ids = !empty($args['group_notin']) ? $args['group_notin'] : (!empty($args['group__not_in']) ? $args['group__not_in'] : []);
    if (!empty($exclude_ids) && is_array($exclude_ids)) {
        $ids_string = implode(',', array_map('absint', $exclude_ids));
        if (!empty($ids_string)) {
            $conditions['group_exclude'] = "( `group_id` NOT IN ($ids_string) )";
        }
    }

    // Handle 'my' groups
    if (!empty($args['group']) && $args['group'] == 'my') {
        if (is_user_logged_in()) {
            $groups = groups_get_user_groups(get_current_user_id());
            if (!empty($groups['groups'])) {
                $conditions['group'] = "( `group_id` IN (" . implode(',', $groups['groups']) . ") )";
            } else {
                $conditions['group'] = "( `group_id` IS NULL OR `group_id` = 0 )";
            }
        }
    }

    // Deal with private groups and events
    if (is_user_logged_in()) {
        $group_ids = BP_Groups_Member::get_group_ids(get_current_user_id());
        if (!empty($group_ids['groups'])) {
            $user_groups_string = implode(',', $group_ids['groups']);
            $conditions['group_privacy'] = "(`event_private`=0 OR (`event_private`=1 AND (`group_id` IS NULL OR `group_id` = 0)) OR (`event_private`=1 AND `group_id` IN ({$user_groups_string})))";
        } else {
            $conditions['group_privacy'] = "(`event_private`=0 OR (`event_private`=1 AND (`group_id` IS NULL OR `group_id` = 0)))";
        }
    }

    return $conditions;
}
