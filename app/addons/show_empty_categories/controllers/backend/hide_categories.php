<?php

use Tygh\Enum\YesNo;
use Tygh\Registry;

defined('BOOTSTRAP') or die('Access denied');

if ($mode === 'sync') {
    if (YesNo::toBool(Registry::get('addons.show_empty_categories.default_value_for_cat'))) {
        return fn_show_empty_categories_activate_all();
    }

    $category_ids = db_get_fields("SELECT category_id FROM ?:categories WHERE show_when_empty = 'N'");

    fn_show_empty_categories_sync($category_ids);
}
