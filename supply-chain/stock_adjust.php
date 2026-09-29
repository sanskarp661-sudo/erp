<?php
// The single-product "New Stock Movement" form was replaced by the
// multi-line Stock Entry document; keep old links and bookmarks working.
require_once __DIR__ . '/../includes/auth.php';
require_module_edit('supply-chain');
redirect('/supply-chain/stock_entry_form.php');
