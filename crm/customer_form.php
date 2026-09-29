<?php
require_once __DIR__ . '/../includes/auth.php';
require_module_edit('crm');

$id = (int)input('id');
$activeTab = 'details';

$customer = [
    'id' => 0, 'customer_code' => '', 'name' => '', 'customer_type' => 'company', 'customer_group' => '', 'company' => '',
    'territory' => '', 'industry' => '', 'website' => '', 'status' => 'active',
    'contact_person' => '', 'designation' => '', 'email' => '', 'phone' => '', 'mobile' => '', 'alt_email' => '',
    'preferred_contact' => 'any', 'address' => '',
    'gstin' => '', 'pan' => '', 'gst_category' => '', 'place_of_supply' => '', 'tax_template_id' => '', 'tax_exempt' => 0,
    'exemption_certificate_no' => '',
    'price_list_id' => '', 'currency' => setting('currency_code', 'INR'), 'sales_person_id' => '', 'sales_channel' => '',
    'market_segment' => '', 'region' => '', 'shipping_partner_id' => '', 'delivery_terms' => '',
    'credit_limit' => '', 'payment_terms_template_id' => '', 'payment_method' => '', 'credit_hold' => 0, 'bypass_credit_check' => 0,
    'lead_source' => '', 'referred_by' => '', 'campaign' => '', 'customer_since' => today(), 'tags' => '', 'notes' => '',
    'created_at' => null,
];
$addresses = [];

if ($id) {
    $stmt = db()->prepare('SELECT * FROM customers WHERE id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        flash('danger', 'Customer not found.');
        redirect('/crm/customers.php');
    }
    $customer = array_merge($customer, array_map(fn($v) => $v ?? '', $row));
    $stmt = db()->prepare('SELECT * FROM customer_addresses WHERE customer_id = ? ORDER BY is_default DESC, id');
    $stmt->execute([$id]);
    $addresses = $stmt->fetchAll();
    // Customers created before the address book only have the legacy
    // free-text address: offer it as the first row so saving keeps it.
    if (!$addresses && trim((string)$customer['address']) !== '') {
        $addresses[] = ['id' => '', 'label' => 'Billing', 'address_line' => $customer['address'], 'city' => '', 'state' => '', 'pincode' => '',
            'country' => 'India', 'contact_person' => '', 'contact_phone' => '', 'contact_email' => '', 'is_default' => 1];
    }
}

$customerTypes = ['company' => 'Company', 'individual' => 'Individual'];
$customerGroups = ['Commercial', 'Retail', 'Wholesale', 'Distributor', 'Dealer', 'Government', 'Non Profit', 'Individual'];
$industries = ['Manufacturing', 'Retail', 'Wholesale & Distribution', 'Construction', 'Automotive', 'Healthcare', 'Pharmaceuticals',
    'Education', 'Hospitality', 'IT & Software', 'Financial Services', 'Agriculture', 'Logistics', 'Textiles', 'Food & Beverage', 'Other'];
$statuses = ['active' => 'Active', 'inactive' => 'Inactive', 'blocked' => 'Blocked'];
$preferredContacts = ['any' => 'Any', 'email' => 'Email', 'phone' => 'Phone', 'whatsapp' => 'WhatsApp'];
$gstCategories = ['registered_business' => 'Registered Business', 'unregistered_business' => 'Unregistered Business', 'consumer' => 'Consumer', 'overseas' => 'Overseas', 'sez' => 'SEZ'];
$salesChannels = ['Direct', 'Online Store', 'Marketplace', 'Retail', 'Distributor', 'POS'];
$deliveryTermsList = ['EXW', 'FOB', 'CIF', 'DAP', 'DDP', 'CPT'];
$paymentMethods = ['Bank Transfer', 'Cash', 'Cheque', 'UPI', 'Card'];
$leadSources = ['Website', 'Referral', 'Campaign', 'Cold Call', 'Exhibition / Trade Show', 'Social Media', 'Walk-in', 'Existing Customer', 'Other'];
// GST state codes, so a GSTIN's first two digits can pick the Place of Supply.
$gstStates = [
    '01' => 'Jammu and Kashmir', '02' => 'Himachal Pradesh', '03' => 'Punjab', '04' => 'Chandigarh', '05' => 'Uttarakhand',
    '06' => 'Haryana', '07' => 'Delhi', '08' => 'Rajasthan', '09' => 'Uttar Pradesh', '10' => 'Bihar', '11' => 'Sikkim',
    '12' => 'Arunachal Pradesh', '13' => 'Nagaland', '14' => 'Manipur', '15' => 'Mizoram', '16' => 'Tripura', '17' => 'Meghalaya',
    '18' => 'Assam', '19' => 'West Bengal', '20' => 'Jharkhand', '21' => 'Odisha', '22' => 'Chhattisgarh', '23' => 'Madhya Pradesh',
    '24' => 'Gujarat', '26' => 'Dadra and Nagar Haveli and Daman and Diu', '27' => 'Maharashtra', '29' => 'Karnataka', '30' => 'Goa',
    '31' => 'Lakshadweep', '32' => 'Kerala', '33' => 'Tamil Nadu', '34' => 'Puducherry', '35' => 'Andaman and Nicobar Islands',
    '36' => 'Telangana', '37' => 'Andhra Pradesh', '38' => 'Ladakh', '97' => 'Other Territory',
];
$placesOfSupply = [];
foreach ($gstStates as $code => $state) {
    $placesOfSupply[$code] = $code . '-' . $state;
}

$error = '';

if (is_post()) {
    csrf_verify();
    $activeTab = input('active_tab') ?: 'details';
    $pick = fn(string $key, array $allowed, $default) => array_key_exists(input($key), $allowed) ? input($key) : $default;
    $inList = fn(string $key, array $allowed) => in_array(input($key), $allowed, true) ? input($key) : '';

    $customer = array_merge($customer, [
        'id' => $id,
        'customer_code' => strtoupper(input('customer_code')),
        'name' => input('name'),
        'customer_type' => $pick('customer_type', $customerTypes, 'company'),
        'customer_group' => $inList('customer_group', $customerGroups),
        'company' => input('company'),
        'territory' => input('territory'),
        'industry' => $inList('industry', $industries),
        'website' => input('website'),
        'status' => $pick('status', $statuses, 'active'),
        'contact_person' => input('contact_person'),
        'designation' => input('designation'),
        'email' => input('email'),
        'phone' => input('phone'),
        'mobile' => input('mobile'),
        'alt_email' => input('alt_email'),
        'preferred_contact' => $pick('preferred_contact', $preferredContacts, 'any'),
        'gstin' => strtoupper(input('gstin')),
        'pan' => strtoupper(input('pan')),
        'gst_category' => $pick('gst_category', $gstCategories, ''),
        'place_of_supply' => $inList('place_of_supply', $placesOfSupply),
        'tax_template_id' => (int)input('tax_template_id') ?: '',
        'tax_exempt' => input('tax_exempt') ? 1 : 0,
        'exemption_certificate_no' => input('exemption_certificate_no'),
        'price_list_id' => (int)input('price_list_id') ?: '',
        'currency' => strtoupper(input('currency')),
        'sales_person_id' => (int)input('sales_person_id') ?: '',
        'sales_channel' => $inList('sales_channel', $salesChannels),
        'market_segment' => input('market_segment'),
        'region' => input('region'),
        'shipping_partner_id' => (int)input('shipping_partner_id') ?: '',
        'delivery_terms' => $inList('delivery_terms', $deliveryTermsList),
        'credit_limit' => input('credit_limit'),
        'payment_terms_template_id' => (int)input('payment_terms_template_id') ?: '',
        'payment_method' => $inList('payment_method', $paymentMethods),
        'credit_hold' => input('credit_hold') ? 1 : 0,
        'bypass_credit_check' => input('bypass_credit_check') ? 1 : 0,
        'lead_source' => $inList('lead_source', $leadSources),
        'referred_by' => input('referred_by'),
        'campaign' => input('campaign'),
        'customer_since' => input('customer_since'),
        'tags' => input('tags'),
        'notes' => input('notes'),
    ]);
    // A GSTIN embeds the PAN (characters 3-12).
    if ($customer['pan'] === '' && strlen($customer['gstin']) === 15) {
        $customer['pan'] = substr($customer['gstin'], 2, 10);
    }

    $addresses = [];
    $addrIds = $_POST['addr_id'] ?? [];
    $defaultIdx = (string)input('addr_default');
    foreach ((array)($_POST['addr_line'] ?? []) as $i => $line) {
        $line = trim((string)$line);
        $field = fn(string $k) => trim((string)($_POST[$k][$i] ?? ''));
        if ($line === '') {
            continue;
        }
        $addresses[] = [
            'id' => (int)($addrIds[$i] ?? 0) ?: '',
            'label' => $field('addr_label') ?: 'Address',
            'address_line' => $line,
            'city' => $field('addr_city'), 'state' => $field('addr_state'), 'pincode' => $field('addr_pincode'),
            'country' => $field('addr_country') ?: 'India',
            'contact_person' => $field('addr_contact_person'), 'contact_phone' => $field('addr_contact_phone'),
            'contact_email' => $field('addr_contact_email'),
            'is_default' => $defaultIdx === (string)$i ? 1 : 0,
        ];
    }
    if ($addresses && !array_filter(array_column($addresses, 'is_default'))) {
        $addresses[0]['is_default'] = 1;
    }

    $fail = function (string $msg, string $tab) use (&$error, &$activeTab) {
        if ($error === '') {
            $error = $msg;
            $activeTab = $tab;
        }
    };
    if ($customer['name'] === '') {
        $fail('Customer name is required.', 'details');
    }
    if ($customer['customer_code'] !== '') {
        $stmt = db()->prepare('SELECT id FROM customers WHERE customer_code = ? AND id <> ?');
        $stmt->execute([$customer['customer_code'], $id]);
        if ($stmt->fetchColumn()) {
            $fail('Customer code ' . $customer['customer_code'] . ' is already used by another customer.', 'details');
        }
    }
    foreach (['email' => 'Email', 'alt_email' => 'Alternate email'] as $k => $label) {
        if ($customer[$k] !== '' && !filter_var($customer[$k], FILTER_VALIDATE_EMAIL)) {
            $fail($label . ' is not a valid email address.', 'contact');
        }
    }
    foreach ($addresses as $a) {
        if ($a['contact_email'] !== '' && !filter_var($a['contact_email'], FILTER_VALIDATE_EMAIL)) {
            $fail('Address "' . $a['label'] . '" has an invalid contact email.', 'contact');
        }
    }
    if ($customer['gstin'] !== '' && !preg_match('/^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z][1-9A-Z]Z[0-9A-Z]$/', $customer['gstin'])) {
        $fail('GSTIN must be 15 characters, e.g. 27AAPFU0939F1ZV.', 'tax');
    }
    if ($customer['pan'] !== '' && !preg_match('/^[A-Z]{5}[0-9]{4}[A-Z]$/', $customer['pan'])) {
        $fail('PAN must be 10 characters, e.g. AAPFU0939F.', 'tax');
    }
    if (in_array($customer['gst_category'], ['registered_business', 'sez'], true) && $customer['gstin'] === '') {
        $fail('A GSTIN is required for the ' . $gstCategories[$customer['gst_category']] . ' GST category.', 'tax');
    }
    if ($customer['currency'] !== '' && !preg_match('/^[A-Z]{3}$/', $customer['currency'])) {
        $fail('Currency must be a 3-letter code such as INR.', 'sales');
    }
    if ($customer['credit_limit'] !== '' && (!is_numeric($customer['credit_limit']) || (float)$customer['credit_limit'] < 0)) {
        $fail('Credit limit must be a positive amount, or blank for no limit.', 'credit');
    }
    if ($customer['customer_since'] !== '' && !DateTime::createFromFormat('Y-m-d', $customer['customer_since'])) {
        $fail('Customer Since must be a valid date.', 'more');
    }

    if ($error === '') {
        $n = fn($v) => $v === '' ? null : $v;
        $cols = ['customer_code', 'name', 'customer_type', 'customer_group', 'company', 'territory', 'industry', 'website', 'status',
            'contact_person', 'designation', 'email', 'phone', 'mobile', 'alt_email', 'preferred_contact',
            'gstin', 'pan', 'gst_category', 'place_of_supply', 'tax_template_id', 'tax_exempt', 'exemption_certificate_no',
            'price_list_id', 'currency', 'sales_person_id', 'sales_channel', 'market_segment', 'region', 'shipping_partner_id', 'delivery_terms',
            'credit_limit', 'payment_terms_template_id', 'payment_method', 'credit_hold', 'bypass_credit_check',
            'lead_source', 'referred_by', 'campaign', 'customer_since', 'tags', 'notes', 'address'];
        // Kept as-is when there's an address row; sync_customer_address()
        // below rewrites it from the default row. No rows: no address.
        if (!$addresses) {
            $customer['address'] = '';
        }
        $vals = array_map(fn($c) => $n($customer[$c]), $cols);

        $pdo = db();
        $pdo->beginTransaction();
        try {
            if ($id) {
                $pdo->prepare('UPDATE customers SET ' . implode(', ', array_map(fn($c) => "$c = ?", $cols)) . ' WHERE id = ?')
                    ->execute([...$vals, $id]);
            } else {
                $pdo->prepare('INSERT INTO customers (' . implode(', ', $cols) . ') VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')
                    ->execute($vals);
                $id = (int)$pdo->lastInsertId();
                if ($customer['customer_code'] === '') {
                    $pdo->prepare("UPDATE customers SET customer_code = CONCAT('CUST-', LPAD(id, 5, '0')) WHERE id = ?")->execute([$id]);
                }
            }

            // Address book: rows removed from the table are deleted (orders
            // that pointed at them fall back to NULL via ON DELETE SET NULL).
            $keepIds = array_filter(array_map(fn($a) => (int)$a['id'], $addresses));
            $del = 'DELETE FROM customer_addresses WHERE customer_id = ?';
            if ($keepIds) {
                $del .= ' AND id NOT IN (' . implode(',', $keepIds) . ')';
            }
            $pdo->prepare($del)->execute([$id]);
            $upd = $pdo->prepare('UPDATE customer_addresses SET label=?, address_line=?, city=?, state=?, pincode=?, country=?, contact_person=?, contact_phone=?, contact_email=?, is_default=? WHERE id=? AND customer_id=?');
            $ins = $pdo->prepare('INSERT INTO customer_addresses (customer_id, label, address_line, city, state, pincode, country, contact_person, contact_phone, contact_email, is_default) VALUES (?,?,?,?,?,?,?,?,?,?,?)');
            foreach ($addresses as $a) {
                $row = [$a['label'], $a['address_line'], $n($a['city']), $n($a['state']), $n($a['pincode']), $a['country'],
                    $n($a['contact_person']), $n($a['contact_phone']), $n($a['contact_email']), $a['is_default']];
                if ($a['id']) {
                    $upd->execute([...$row, $a['id'], $id]);
                } else {
                    $ins->execute([$id, ...$row]);
                }
            }
            sync_customer_address($id);
            $pdo->commit();
        } catch (Throwable $ex) {
            $pdo->rollBack();
            throw $ex;
        }
        flash('success', $customer['id'] ? 'Customer updated.' : 'Customer created.');
        redirect('/crm/customers.php');
    }
}

$priceLists = db()->query("SELECT id, name, currency FROM price_lists WHERE status='active' ORDER BY is_default DESC, name")->fetchAll();
$taxTemplates = db()->query("SELECT id, name FROM tax_templates WHERE status='active' ORDER BY name")->fetchAll();
$paymentTermsTemplates = db()->query("SELECT id, name FROM payment_terms_templates WHERE status='active' ORDER BY name")->fetchAll();
$salesUsers = db()->query("SELECT id, name FROM users WHERE status='active' ORDER BY name")->fetchAll();
$shippingPartners = db()->query("SELECT id, name FROM shipping_partners WHERE status='active' ORDER BY name")->fetchAll();
$territories = db()->query("SELECT DISTINCT territory FROM customers WHERE territory IS NOT NULL AND territory <> '' ORDER BY territory")->fetchAll(PDO::FETCH_COLUMN);

$summary = null;
if ($customer['id']) {
    $stmt = db()->prepare("SELECT COUNT(*) orders, COALESCE(SUM(total_amount),0) ordered, MAX(order_date) last_order FROM sales_orders WHERE customer_id = ? AND status <> 'cancelled'");
    $stmt->execute([$customer['id']]);
    $summary = $stmt->fetch() + customer_credit_exposure((int)$customer['id']);
}
$creditLimit = $customer['credit_limit'] !== '' && is_numeric($customer['credit_limit']) ? (float)$customer['credit_limit'] : null;

$sel = fn($a, $b) => (string)$a === (string)$b ? 'selected' : '';
$chk = fn($v) => !empty($v) ? 'checked' : '';
$statusBadge = ['active' => 'success', 'inactive' => 'secondary', 'blocked' => 'danger'];
if (!$addresses) {
    $addresses[] = ['id' => '', 'label' => 'Billing', 'address_line' => '', 'city' => '', 'state' => '', 'pincode' => '',
        'country' => 'India', 'contact_person' => '', 'contact_phone' => '', 'contact_email' => '', 'is_default' => 1];
}

$page_title = $customer['id'] ? 'Edit Customer' : 'New Customer';
require __DIR__ . '/../includes/header.php';
?>
<div class="card p-4">
  <?php if ($error): ?><div class="alert alert-danger"><?= e($error) ?></div><?php endif; ?>
  <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <h5 class="mb-0">
      <?= $customer['id'] ? e($customer['name']) . ' <span class="text-muted small">' . e($customer['customer_code']) . '</span>' : 'New Customer' ?>
      <span class="badge text-bg-<?= $statusBadge[$customer['status']] ?? 'secondary' ?> badge-status"><?= e($statuses[$customer['status']] ?? $customer['status']) ?></span>
      <?php if (!empty($customer['credit_hold'])): ?><span class="badge text-bg-warning badge-status">Credit Hold</span><?php endif; ?>
    </h5>
    <?php if ($customer['id']): ?>
      <div class="d-flex gap-2">
        <a href="<?= base_url('sales/order_form.php?customer_id=' . (int)$customer['id']) ?>" class="btn btn-sm btn-outline-brand"><i class="fa-solid fa-cart-plus"></i> New Sales Order</a>
        <a href="<?= base_url('print.php?doctype=customer&id=' . (int)$customer['id']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="fa-solid fa-print"></i> Print</a>
      </div>
    <?php endif; ?>
  </div>

  <ul class="nav nav-tabs mb-3" id="custTabs">
    <?php foreach (['details' => 'Details', 'contact' => 'Contact &amp; Address', 'tax' => 'Tax &amp; Compliance', 'sales' => 'Sales &amp; Pricing', 'credit' => 'Credit &amp; Payment', 'more' => 'More Info'] as $key => $label): ?>
      <li class="nav-item"><button class="nav-link <?= $activeTab === $key ? 'active' : '' ?>" id="tab-<?= $key ?>" data-tab="<?= $key ?>" data-bs-toggle="tab" data-bs-target="#pane-<?= $key ?>" type="button"><?= $label ?></button></li>
    <?php endforeach; ?>
  </ul>

  <form method="post" id="custForm">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= (int)$customer['id'] ?>">
    <input type="hidden" name="active_tab" id="activeTabInput" value="<?= e($activeTab) ?>">
    <div class="tab-content">

      <div class="tab-pane fade <?= $activeTab === 'details' ? 'show active' : '' ?>" id="pane-details">
        <h6 class="text-muted mb-3"><i class="fa-solid fa-user"></i> Customer Information</h6>
        <div class="row g-3 mb-3">
          <div class="col-sm-3">
            <label class="form-label">Customer Code</label>
            <input type="text" name="customer_code" class="form-control" maxlength="30" style="text-transform:uppercase" placeholder="Auto (CUST-00001)" value="<?= e($customer['customer_code']) ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">Customer Name <span class="text-danger">*</span></label>
            <input type="text" name="name" class="form-control" maxlength="150" required value="<?= e($customer['name']) ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">Customer Type</label>
            <select name="customer_type" id="customerTypeSelect" class="form-select">
              <?php foreach ($customerTypes as $val => $label): ?>
                <option value="<?= $val ?>" <?= $sel($customer['customer_type'], $val) ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-3">
            <label class="form-label">Customer Group</label>
            <select name="customer_group" class="form-select">
              <option value="">— Select group —</option>
              <?php foreach ($customerGroups as $g): ?>
                <option value="<?= e($g) ?>" <?= $sel($customer['customer_group'], $g) ?>><?= e($g) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-sm-3" id="companyField">
            <label class="form-label">Company / Legal Name</label>
            <input type="text" name="company" class="form-control" maxlength="150" value="<?= e($customer['company']) ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">Territory</label>
            <input type="text" name="territory" class="form-control" list="territoryList" placeholder="e.g. West India" value="<?= e($customer['territory']) ?>">
            <datalist id="territoryList"><?php foreach ($territories as $t): ?><option value="<?= e($t) ?>"><?php endforeach; ?></datalist>
          </div>
          <div class="col-sm-3">
            <label class="form-label">Industry</label>
            <select name="industry" class="form-select">
              <option value="">— Select industry —</option>
              <?php foreach ($industries as $ind): ?>
                <option value="<?= e($ind) ?>" <?= $sel($customer['industry'], $ind) ?>><?= e($ind) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-3">
            <label class="form-label">Status</label>
            <select name="status" class="form-select">
              <?php foreach ($statuses as $val => $label): ?>
                <option value="<?= $val ?>" <?= $sel($customer['status'], $val) ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">Inactive and blocked customers can't be picked on new sales orders or quotations.</div>
          </div>

          <div class="col-sm-3">
            <label class="form-label">Website</label>
            <input type="url" name="website" class="form-control" maxlength="150" placeholder="https://" value="<?= e($customer['website']) ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">Primary Email</label>
            <input type="email" name="email" class="form-control" maxlength="150" value="<?= e($customer['email']) ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">Primary Phone</label>
            <input type="text" name="phone" class="form-control" maxlength="40" value="<?= e($customer['phone']) ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">Customer Since</label>
            <input type="date" name="customer_since" class="form-control" value="<?= e($customer['customer_since']) ?>">
          </div>
        </div>
      </div>

      <div class="tab-pane fade <?= $activeTab === 'contact' ? 'show active' : '' ?>" id="pane-contact">
        <h6 class="text-muted mb-3"><i class="fa-solid fa-address-card"></i> Primary Contact</h6>
        <div class="row g-3 mb-4">
          <div class="col-sm-3">
            <label class="form-label">Contact Person</label>
            <input type="text" name="contact_person" class="form-control" maxlength="120" value="<?= e($customer['contact_person']) ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">Designation</label>
            <input type="text" name="designation" class="form-control" maxlength="100" value="<?= e($customer['designation']) ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">Mobile</label>
            <input type="text" name="mobile" class="form-control" maxlength="40" value="<?= e($customer['mobile']) ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">Alternate Email</label>
            <input type="email" name="alt_email" class="form-control" maxlength="150" value="<?= e($customer['alt_email']) ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">Preferred Contact Method</label>
            <select name="preferred_contact" class="form-select">
              <?php foreach ($preferredContacts as $val => $label): ?>
                <option value="<?= $val ?>" <?= $sel($customer['preferred_contact'], $val) ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <div class="d-flex justify-content-between align-items-center mb-2">
          <h6 class="text-muted mb-0"><i class="fa-solid fa-location-dot"></i> Addresses</h6>
          <button type="button" class="btn btn-sm btn-outline-brand" id="addAddressBtn"><i class="fa-solid fa-plus"></i> Add Address</button>
        </div>
        <p class="small text-muted mb-2">The default address is printed on invoices and pre-selected on sales orders. Rows with no address line are ignored.</p>
        <div class="table-responsive">
          <table class="table table-sm align-middle" id="addrTable">
            <thead><tr>
              <th style="width:60px">Default</th><th style="min-width:110px">Label</th><th style="min-width:220px">Address Line</th><th style="min-width:120px">City</th>
              <th style="min-width:150px">State</th><th style="min-width:90px">Pincode</th><th style="min-width:110px">Country</th>
              <th style="min-width:130px">Contact</th><th style="min-width:120px">Phone</th><th style="min-width:160px">Email</th><th></th>
            </tr></thead>
            <tbody>
            <?php foreach ($addresses as $i => $a): ?>
              <tr data-row>
                <td class="text-center"><input type="hidden" name="addr_id[]" value="<?= e($a['id']) ?>"><input type="radio" class="form-check-input js-addr-default" name="addr_default" value="<?= $i ?>" <?= $chk($a['is_default']) ?>></td>
                <td><input type="text" class="form-control form-control-sm" name="addr_label[]" maxlength="60" value="<?= e($a['label']) ?>"></td>
                <td><input type="text" class="form-control form-control-sm" name="addr_line[]" maxlength="255" value="<?= e($a['address_line']) ?>"></td>
                <td><input type="text" class="form-control form-control-sm" name="addr_city[]" maxlength="100" value="<?= e($a['city'] ?? '') ?>"></td>
                <td><input type="text" class="form-control form-control-sm" name="addr_state[]" maxlength="100" list="stateList" value="<?= e($a['state'] ?? '') ?>"></td>
                <td><input type="text" class="form-control form-control-sm" name="addr_pincode[]" maxlength="20" value="<?= e($a['pincode'] ?? '') ?>"></td>
                <td><input type="text" class="form-control form-control-sm" name="addr_country[]" maxlength="100" value="<?= e($a['country'] ?? 'India') ?>"></td>
                <td><input type="text" class="form-control form-control-sm" name="addr_contact_person[]" maxlength="120" value="<?= e($a['contact_person'] ?? '') ?>"></td>
                <td><input type="text" class="form-control form-control-sm" name="addr_contact_phone[]" maxlength="40" value="<?= e($a['contact_phone'] ?? '') ?>"></td>
                <td><input type="email" class="form-control form-control-sm" name="addr_contact_email[]" maxlength="150" value="<?= e($a['contact_email'] ?? '') ?>"></td>
                <td><button type="button" class="btn btn-sm btn-outline-danger js-addr-remove" title="Remove"><i class="fa-solid fa-xmark"></i></button></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
          <datalist id="stateList"><?php foreach ($gstStates as $s): ?><option value="<?= e($s) ?>"><?php endforeach; ?></datalist>
        </div>
      </div>

      <div class="tab-pane fade <?= $activeTab === 'tax' ? 'show active' : '' ?>" id="pane-tax">
        <h6 class="text-muted mb-3"><i class="fa-solid fa-scale-balanced"></i> Tax &amp; Compliance</h6>
        <div class="row g-3 mb-3">
          <div class="col-sm-3">
            <label class="form-label">GSTIN</label>
            <input type="text" name="gstin" id="gstinInput" class="form-control" maxlength="15" style="text-transform:uppercase" placeholder="27AAPFU0939F1ZV" value="<?= e($customer['gstin']) ?>">
            <div class="form-text">Fills PAN and Place of Supply for you.</div>
          </div>
          <div class="col-sm-3">
            <label class="form-label">PAN</label>
            <input type="text" name="pan" id="panInput" class="form-control" maxlength="10" style="text-transform:uppercase" value="<?= e($customer['pan']) ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">GST Category</label>
            <select name="gst_category" id="gstCategorySelect" class="form-select">
              <option value="">— Select —</option>
              <?php foreach ($gstCategories as $val => $label): ?>
                <option value="<?= $val ?>" <?= $sel($customer['gst_category'], $val) ?>><?= $label ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-3">
            <label class="form-label">Place of Supply</label>
            <select name="place_of_supply" id="placeOfSupplySelect" class="form-select">
              <option value="">— Select state —</option>
              <?php foreach ($placesOfSupply as $code => $pos): ?>
                <option value="<?= e($pos) ?>" data-code="<?= $code ?>" <?= $sel($customer['place_of_supply'], $pos) ?>><?= e($pos) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-sm-3">
            <label class="form-label">Default Tax Template</label>
            <select name="tax_template_id" class="form-select">
              <option value="">— None —</option>
              <?php foreach ($taxTemplates as $tt): ?>
                <option value="<?= (int)$tt['id'] ?>" <?= $sel($customer['tax_template_id'], $tt['id']) ?>><?= e($tt['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <div class="form-text">Applied to the Taxes tab of new sales orders.</div>
          </div>
          <div class="col-sm-3 d-flex align-items-center">
            <div class="form-check mt-3">
              <input type="checkbox" class="form-check-input" id="taxExemptChk" name="tax_exempt" value="1" <?= $chk($customer['tax_exempt']) ?>>
              <label class="form-check-label" for="taxExemptChk">Tax Exempt</label>
            </div>
          </div>
          <div class="col-sm-3" id="exemptionField">
            <label class="form-label">Exemption Certificate No.</label>
            <input type="text" name="exemption_certificate_no" class="form-control" maxlength="60" value="<?= e($customer['exemption_certificate_no']) ?>">
          </div>
        </div>
      </div>

      <div class="tab-pane fade <?= $activeTab === 'sales' ? 'show active' : '' ?>" id="pane-sales">
        <h6 class="text-muted mb-3"><i class="fa-solid fa-tags"></i> Sales Defaults</h6>
        <p class="small text-muted">These are filled in automatically when this customer is picked on a new sales order.</p>
        <div class="row g-3 mb-3">
          <div class="col-sm-3">
            <label class="form-label">Default Price List</label>
            <select name="price_list_id" id="priceListSelect" class="form-select">
              <option value="">— Company default —</option>
              <?php foreach ($priceLists as $pl): ?>
                <option value="<?= (int)$pl['id'] ?>" data-currency="<?= e($pl['currency']) ?>" <?= $sel($customer['price_list_id'], $pl['id']) ?>><?= e($pl['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-3">
            <label class="form-label">Billing Currency</label>
            <input type="text" name="currency" id="currencyInput" class="form-control" maxlength="3" style="text-transform:uppercase" value="<?= e($customer['currency']) ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">Sales Person</label>
            <select name="sales_person_id" class="form-select">
              <option value="">— Select sales person —</option>
              <?php foreach ($salesUsers as $u): ?>
                <option value="<?= (int)$u['id'] ?>" <?= $sel($customer['sales_person_id'], $u['id']) ?>><?= e($u['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-3">
            <label class="form-label">Sales Channel</label>
            <select name="sales_channel" class="form-select">
              <option value="">— Select sales channel —</option>
              <?php foreach ($salesChannels as $ch): ?>
                <option value="<?= e($ch) ?>" <?= $sel($customer['sales_channel'], $ch) ?>><?= e($ch) ?></option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="col-sm-3">
            <label class="form-label">Market Segment</label>
            <input type="text" name="market_segment" class="form-control" maxlength="100" value="<?= e($customer['market_segment']) ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">Region</label>
            <input type="text" name="region" class="form-control" maxlength="100" value="<?= e($customer['region']) ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">Preferred Shipping Partner</label>
            <select name="shipping_partner_id" class="form-select">
              <option value="">— None —</option>
              <?php foreach ($shippingPartners as $sp): ?>
                <option value="<?= (int)$sp['id'] ?>" <?= $sel($customer['shipping_partner_id'], $sp['id']) ?>><?= e($sp['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-3">
            <label class="form-label">Delivery Terms (Incoterms)</label>
            <select name="delivery_terms" class="form-select">
              <option value="">— Select incoterms —</option>
              <?php foreach ($deliveryTermsList as $term): ?>
                <option value="<?= $term ?>" <?= $sel($customer['delivery_terms'], $term) ?>><?= $term ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>

      <div class="tab-pane fade <?= $activeTab === 'credit' ? 'show active' : '' ?>" id="pane-credit">
        <div class="row g-3">
          <div class="col-lg-7">
            <h6 class="text-muted mb-3"><i class="fa-solid fa-credit-card"></i> Credit &amp; Payment Terms</h6>
            <div class="row g-3">
              <div class="col-sm-6">
                <label class="form-label">Credit Limit</label>
                <input type="number" step="0.01" min="0" name="credit_limit" class="form-control" placeholder="No limit" value="<?= e($customer['credit_limit']) ?>">
                <div class="form-text">Sales orders that would take the customer over this are refused.</div>
              </div>
              <div class="col-sm-6">
                <label class="form-label">Payment Terms Template</label>
                <select name="payment_terms_template_id" class="form-select">
                  <option value="">— None —</option>
                  <?php foreach ($paymentTermsTemplates as $pt): ?>
                    <option value="<?= (int)$pt['id'] ?>" <?= $sel($customer['payment_terms_template_id'], $pt['id']) ?>><?= e($pt['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-sm-6">
                <label class="form-label">Preferred Payment Method</label>
                <select name="payment_method" class="form-select">
                  <option value="">— Select —</option>
                  <?php foreach ($paymentMethods as $pm): ?>
                    <option value="<?= $pm ?>" <?= $sel($customer['payment_method'], $pm) ?>><?= $pm ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="col-sm-6">
                <div class="form-check mt-2"><input type="checkbox" class="form-check-input" id="creditHoldChk" name="credit_hold" value="1" <?= $chk($customer['credit_hold']) ?>><label class="form-check-label" for="creditHoldChk">Credit Hold (block saving sales orders)</label></div>
                <div class="form-check mt-2"><input type="checkbox" class="form-check-input" id="bypassChk" name="bypass_credit_check" value="1" <?= $chk($customer['bypass_credit_check']) ?>><label class="form-check-label" for="bypassChk">Bypass credit limit check</label></div>
              </div>
            </div>
          </div>
          <div class="col-lg-5">
            <div class="card p-3 h-100">
              <h6 class="text-muted mb-3"><i class="fa-solid fa-chart-simple"></i> Account Summary</h6>
              <?php if ($summary): ?>
                <table class="table table-sm mb-0">
                  <tr><td>Sales orders</td><td class="text-end"><?= (int)$summary['orders'] ?></td></tr>
                  <tr><td>Total ordered</td><td class="text-end"><?= money($summary['ordered']) ?></td></tr>
                  <tr><td>Total invoiced</td><td class="text-end"><?= money($summary['billed']) ?></td></tr>
                  <tr><td>Outstanding (unpaid invoices)</td><td class="text-end"><?= money($summary['outstanding']) ?></td></tr>
                  <tr><td>Ordered, not yet invoiced</td><td class="text-end"><?= money($summary['unbilled']) ?></td></tr>
                  <tr class="fw-semibold"><td>Available credit</td><td class="text-end <?= $creditLimit !== null && $creditLimit - $summary['exposure'] < 0 ? 'text-danger' : '' ?>"><?= $creditLimit === null ? 'No limit' : money($creditLimit - $summary['exposure']) ?></td></tr>
                  <tr><td>Last order</td><td class="text-end"><?= $summary['last_order'] ? e($summary['last_order']) : '—' ?></td></tr>
                </table>
              <?php else: ?>
                <p class="text-muted small mb-0">Balances appear here once the customer is saved and has orders or invoices.</p>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>

      <div class="tab-pane fade <?= $activeTab === 'more' ? 'show active' : '' ?>" id="pane-more">
        <h6 class="text-muted mb-3"><i class="fa-solid fa-circle-info"></i> Source &amp; Notes</h6>
        <div class="row g-3 mb-3">
          <div class="col-sm-3">
            <label class="form-label">Lead Source</label>
            <select name="lead_source" class="form-select">
              <option value="">— Select —</option>
              <?php foreach ($leadSources as $ls): ?>
                <option value="<?= e($ls) ?>" <?= $sel($customer['lead_source'], $ls) ?>><?= e($ls) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-3">
            <label class="form-label">Referred By</label>
            <input type="text" name="referred_by" class="form-control" maxlength="150" value="<?= e($customer['referred_by']) ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">Campaign</label>
            <input type="text" name="campaign" class="form-control" maxlength="150" value="<?= e($customer['campaign']) ?>">
          </div>
          <div class="col-sm-3">
            <label class="form-label">Tags</label>
            <input type="text" name="tags" class="form-control" maxlength="255" placeholder="Comma separated" value="<?= e($customer['tags']) ?>">
          </div>
          <div class="col-12">
            <label class="form-label">Internal Notes</label>
            <textarea name="notes" class="form-control" rows="4"><?= e($customer['notes']) ?></textarea>
          </div>
          <?php if ($customer['created_at']): ?>
            <div class="col-12 small text-muted">Created <?= e($customer['created_at']) ?></div>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <div class="page-actions mt-4">
      <button type="submit" class="btn btn-brand">Save</button>
      <a href="customers.php" class="btn btn-outline-secondary">Cancel</a>
    </div>
  </form>
</div>
<?php
$extra_js_inline = "
document.addEventListener('DOMContentLoaded', function () {
  var activeTabInput = document.getElementById('activeTabInput');
  document.querySelectorAll('#custTabs [data-tab]').forEach(function (btn) {
    btn.addEventListener('shown.bs.tab', function () { activeTabInput.value = btn.getAttribute('data-tab'); });
  });

  // A required field on a hidden tab would silently block submit: show its tab first.
  var form = document.getElementById('custForm');
  form.addEventListener('invalid', function (e) {
    var pane = e.target.closest('.tab-pane');
    if (pane && !pane.classList.contains('active')) {
      var btn = document.querySelector('[data-bs-target=\"#' + pane.id + '\"]');
      if (btn) bootstrap.Tab.getOrCreateInstance(btn).show();
    }
  }, true);

  var typeSel = document.getElementById('customerTypeSelect');
  var companyField = document.getElementById('companyField');
  function syncType() { companyField.style.display = typeSel.value === 'individual' ? 'none' : ''; }
  typeSel.addEventListener('change', syncType);
  syncType();

  var gstin = document.getElementById('gstinInput');
  var pan = document.getElementById('panInput');
  var pos = document.getElementById('placeOfSupplySelect');
  var gstCat = document.getElementById('gstCategorySelect');
  gstin.addEventListener('input', function () {
    var v = gstin.value.toUpperCase().trim();
    if (v.length >= 12 && /^[0-9]{2}[A-Z]{5}[0-9]{4}[A-Z]/.test(v)) pan.value = v.substr(2, 10);
    if (v.length >= 2) {
      var opt = pos.querySelector('option[data-code=\"' + v.substr(0, 2) + '\"]');
      if (opt) pos.value = opt.value;
    }
    if (v.length === 15 && !gstCat.value) gstCat.value = 'registered_business';
  });

  var exempt = document.getElementById('taxExemptChk');
  var exemptField = document.getElementById('exemptionField');
  function syncExempt() { exemptField.style.display = exempt.checked ? '' : 'none'; }
  exempt.addEventListener('change', syncExempt);
  syncExempt();

  var pl = document.getElementById('priceListSelect');
  var cur = document.getElementById('currencyInput');
  pl.addEventListener('change', function () {
    var o = pl.options[pl.selectedIndex];
    if (o && o.getAttribute('data-currency')) cur.value = o.getAttribute('data-currency');
  });

  var tbody = document.querySelector('#addrTable tbody');
  function renumber() {
    tbody.querySelectorAll('tr[data-row]').forEach(function (tr, i) { tr.querySelector('.js-addr-default').value = i; });
  }
  document.getElementById('addAddressBtn').addEventListener('click', function () {
    var rows = tbody.querySelectorAll('tr[data-row]');
    var clone = rows[rows.length - 1].cloneNode(true);
    clone.querySelectorAll('input[type=text], input[type=email], input[type=hidden]').forEach(function (inp) { inp.value = ''; });
    clone.querySelector('input[name=\"addr_country[]\"]').value = 'India';
    clone.querySelector('.js-addr-default').checked = false;
    tbody.appendChild(clone);
    renumber();
    clone.querySelector('input[name=\"addr_label[]\"]').focus();
  });
  tbody.addEventListener('click', function (e) {
    var rm = e.target.closest('.js-addr-remove');
    if (!rm) return;
    var rows = tbody.querySelectorAll('tr[data-row]');
    var tr = rm.closest('tr[data-row]');
    if (rows.length > 1) {
      var wasDefault = tr.querySelector('.js-addr-default').checked;
      tr.remove();
      if (wasDefault) tbody.querySelector('.js-addr-default').checked = true;
    } else {
      tr.querySelectorAll('input[type=text], input[type=email], input[type=hidden]').forEach(function (inp) { inp.value = ''; });
    }
    renumber();
  });
});
";
require __DIR__ . '/../includes/footer.php';
?>
