<?php
$cfg = [
    'table' => 'item_categories', 'title' => 'Item Categories', 'singular' => 'Item Category',
    'list' => 'item_categories.php', 'form' => 'item_category_form.php', 'icon' => 'fa-solid fa-layer-group',
    'fk' => 'item_category_id', 'filter' => 'item_category', 'status' => true, 'description' => false,
    'placeholder' => 'e.g. Finished Goods',
];
require __DIR__ . '/_master_list.php';
