<?php
$cfg = [
    'table' => 'brands', 'title' => 'Brands', 'singular' => 'Brand',
    'list' => 'brands.php', 'form' => 'brand_form.php', 'icon' => 'fa-solid fa-copyright',
    'fk' => 'brand_id', 'filter' => 'brand', 'status' => true, 'description' => false,
    'placeholder' => 'e.g. Tata',
];
require __DIR__ . '/_master_list.php';
