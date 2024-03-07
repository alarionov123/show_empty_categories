<?php

use Tygh\Enum\VendorStatuses;
use Tygh\Enum\YesNo;
use Tygh\Registry;
use Tygh\Enum\ObjectStatuses;
use Tygh\Enum\NotificationSeverity;

if (!defined("BOOTSTRAP")) {
    die("Access denied");
}
/**
 * Returns category statues back to default once the add-on is disabled / uninstalled
 *
 * @return void
 */
function fn_show_empty_categories_return_to_default()
{
    $list_hidden_categories = db_get_hash_array("SELECT category_id, menu_id FROM ?:empty_categories", 'category_id');

    foreach ($list_hidden_categories as $category_id => $menu_id) {
        fn_change_category_status($category_id, ObjectStatuses::ACTIVE);

        fn_tools_update_status(
            [
                'table' => 'static_data',
                'status' => ObjectStatuses::ACTIVE,
                'id_name' => 'param_id',
                'id' => $menu_id,
                'show_error_notice' => false,
            ]
        );
    }
}

/**
 * Defines the notification message on add-on's status update. If the new add-on status is set to any from active - triggers
 * @param $new_status
 * @return void
 * @see fn_show_empty_categories_return_to_default
 *
 */
function fn_settings_actions_addons_show_empty_categories($new_status)
{
    if ($new_status !== ObjectStatuses::ACTIVE) {
        fn_show_empty_categories_return_to_default();

        fn_set_notification(NotificationSeverity::WARNING, __('warning'), __('empty_categories.return_to_default'));
    } elseif ($new_status === ObjectStatuses::ACTIVE) {
        fn_set_notification(NotificationSeverity::NOTICE, __('notice'), __('instructions_to_sync'));
    }
}

/**
 * The "get_categories" hook handler.
 *
 * Adds new fields for categories retrieval
 *
 * @param $fields
 *
 * @see \fn_get_categories()
 *
 * @return void
 */
function fn_show_empty_categories_get_categories(&$fields)
{
    $fields[] = '?:categories.product_count';
    $fields[] = '?:categories.show_when_empty';
}

/**
 * Makes a database request to update the products count per category
 *
 * @param $condition
 * @param $join
 * @return mixed
 */
function fn_show_empty_categories_product_count_actualization($cat_ids, $condition, $join = '')
{
    return db_query(
        "UPDATE ?:categories SET ?:categories.product_count = (
            SELECT COUNT(DISTINCT ?:products.product_id) FROM ?:products_categories $join $condition
        ) WHERE ?:categories.category_id IN (?n)",
        $cat_ids
    );
}

/**
 * Categories synchronization. Updates status of categories
 *
 * @param $category_ids
 * @return void
 */
function fn_show_empty_categories_sync($category_ids)
{
    $condition = db_quote(' WHERE ?:products_categories.category_id = ?:categories.category_id');

    if (Registry::get("settings.General.show_out_of_stock_products") === YesNo::YES) {
        $condition .= db_quote(" AND ?:products.amount != 0");
    }
    $condition .= db_quote(' AND ?:products.status IN ("A")');

    $join = db_quote(' LEFT JOIN ?:products ON ?:products_categories.product_id = ?:products.product_id');

    if (fn_allowed_for('MULTIVENDOR')) {
        $join .= db_quote(' LEFT JOIN ?:companies ON ?:products .company_id = ?:companies.company_id');
        $condition .= db_quote(' AND ?:companies.status IN ("A")');
    }

    fn_show_empty_categories_product_count_actualization($category_ids, $condition, $join);

    if (!YesNo::toBool(Registry::get('addons.show_empty_categories.hide_parent_categories'))) {
        $category_ids = fn_show_empty_categories_exclude_parents($category_ids);
    }

    $categories_list = db_get_hash_array(
        'SELECT category_id, product_count, show_when_empty, parent_id FROM ?:categories WHERE category_id IN (?n)',
        'category_id',
        $category_ids
    );
    fn_set_progress('step_scale', sizeof($categories_list));
    fn_set_progress('parts', 1);

    foreach ($categories_list as $item => $category) {
        fn_set_progress('echo', __('processing') . ' ' . __('category') . '&nbsp;<b>#' . $item . '</b>...');
        if ((int)$category['product_count'] === 0) {
            fn_change_category_status($category['category_id'], ObjectStatuses::HIDDEN);

            if (YesNo::toBool(Registry::get('addons.show_empty_categories.hide_menu_items'))) {
                fn_show_empty_categories_menu_items(ObjectStatuses::HIDDEN, [$category['category_id']]);
            } else {
                fn_show_empty_categories_menu_items(ObjectStatuses::ACTIVE, [$category['category_id']]);
            }

            db_query('REPLACE INTO ?:empty_categories ?e', $category);
        } else {
            $empty_categories = db_get_fields('SELECT category_id FROM ?:empty_categories');

            if (in_array($category['category_id'], $empty_categories)) {
                fn_change_category_status($category['category_id'], ObjectStatuses::ACTIVE);
                fn_change_category_status($category['parent_id'], ObjectStatuses::ACTIVE);
                fn_show_empty_categories_menu_items(ObjectStatuses::ACTIVE, [$category['category_id'], $category['parent_id']]);

                db_query('DELETE FROM ?:empty_categories WHERE category_id = ?i', $category['category_id']);
            }
        }
    }

    fn_set_notification('N', __('notice'), __('text_categories_updated'));
}

/**
 * Uses for gathering link in the add-on's detailed information
 *
 * @return string
 */
function fn_show_empty_categories_info()
{
    $sync_url = fn_url('hide_categories.sync');

    return __(
        'show_empty_categories_info.text_sync',
        [
            '[sync_url]' => $sync_url,
        ]
    );
}

/**
 * Hides category-assigned menu items
 *
 * @param $status_to
 * @param $category_ids
 * @return void
 */
function fn_show_empty_categories_menu_items($status_to, $category_ids)
{
    $menu_items_with_categories = db_get_hash_array(
        "SELECT param_id, param_3, status FROM ?:static_data WHERE param_3 <> ''",
        'param_id'
    );

    foreach ($menu_items_with_categories as $menu_id => $menu_item) {
        $menu_category_id = fn_explode(':', $menu_item['param_3']);
        if (in_array($menu_category_id[1], $category_ids)) {
            fn_tools_update_status(
                [
                    'table' => 'static_data',
                    'status' => $status_to,
                    'id_name' => 'param_id',
                    'id' => $menu_id,
                    'show_error_notice' => false,
                ]
            );
            db_query(
                'UPDATE ?:empty_categories SET menu_id = ?i WHERE category_id = ?i',
                $menu_id,
                $menu_category_id[1]
            );
        }
    }
}

/**
 * Excludes parent categories
 *
 * @param $category_ids
 * @return array
 */
function fn_show_empty_categories_exclude_parents($category_ids)
{
    $category_ids_result = [];
    $categories_info = fn_show_empty_categories_get_info($category_ids);
    $parent_cats = db_get_hash_array(
        "SELECT c.parent_id, GROUP_CONCAT(c.category_id) as categories
    FROM ?:categories c 
    JOIN ?:categories p ON c.parent_id = p.category_id
    WHERE c.category_id IN (?n)
    GROUP BY c.parent_id",
        'parent_id',
        $category_ids
    );

    foreach ($categories_info as $c_info) {
        if (
            empty($c_info)
            || (int)$c_info['parent_id'] === 0
            || array_key_exists($c_info['category_id'], $parent_cats)
        ) {
            continue;
        }

        $category_ids_result[] = $c_info['category_id'];

        if (isset($parent_cats[$c_info['parent_id']]) && (int)$c_info['product_count'] === 0) {
            $cat_arr = explode(',', $parent_cats[$c_info['parent_id']]['categories']);
            $filtered_categories = array_diff($cat_arr, array($c_info['category_id']));

            $filtered_categories = implode(',', $filtered_categories);

            $parent_cats[$c_info['parent_id']]['categories'] = $filtered_categories;
        }
    }

    foreach ($parent_cats as $parent_id => $cat_info) {
        if (empty($cat_info['categories'])) {
            $category_ids_result[] = $parent_id;
        }
    }
    return $category_ids_result;
}

/**
 * @param $category_ids
 * @return array
 */
function fn_show_empty_categories_get_info($category_ids)
{
    return db_get_array(
        "SELECT category_id, parent_id, product_count FROM ?:categories WHERE category_id IN (?n)",
        $category_ids
    );
}

/**
 * The "categories_update_product_count_post" hook handler.
 *
 * Removes data about stored empty categories from database if a product count of a category becomes greater than 0
 *
 * @param $category_ids
 *
 * @see \fn_update_product_count()
 *
 * @return void
 */
function fn_show_empty_categories_update_product_count_post($category_ids)
{
    if (empty($category_ids)) {
        return;
    }

    $condition = db_quote(' AND ?:empty_categories.category_id IN (?n)', $category_ids);

    db_query(
        'DELETE FROM ?:empty_categories
        WHERE EXISTS (
            SELECT 1 
            FROM ?:categories 
            WHERE ?:categories.category_id = ?:empty_categories.category_id
            AND ?:categories.product_count > 0
        )' . $condition
    );
}

/**
 * Activates all categories in the store
 *
 * @return bool
 */
function fn_show_empty_categories_activate_all()
{
    $categories = db_get_fields("SELECT category_id FROM ?:categories");

    db_query('UPDATE ?:categories SET status = "A" WHERE category_id IN (?n)', $categories);

    return fn_set_notification('N', __('notice'), __('text_categories_updated'));
}