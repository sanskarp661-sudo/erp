<?php
$cfg = [
    'table' => 'uom', 'title' => 'Units of Measure', 'singular' => 'Unit',
    'list' => 'uom.php', 'form' => 'uom_form.php', 'icon' => 'fa-solid fa-ruler',
    'fk' => null, 'filter' => 'unit', 'status' => true, 'description' => false,
    'status_help' => "Inactive units stay on any product that already uses them, but won't appear in the picker for new products.",
    'placeholder' => 'e.g. pcs, kg, box, litre',
];
require __DIR__ . '/_master_form.php';
