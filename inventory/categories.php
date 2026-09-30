<?php
$cfg = [
    'table' => 'categories', 'title' => 'Categories', 'singular' => 'Category',
    'list' => 'categories.php', 'form' => 'category_form.php', 'icon' => 'fa-solid fa-tags',
    'fk' => 'category_id', 'filter' => 'category', 'status' => false, 'description' => true,
    'placeholder' => 'e.g. Beverages',
];
require __DIR__ . '/_master_list.php';
