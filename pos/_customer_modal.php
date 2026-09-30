<?php
// Add / Edit Customer modal shared by New Sale, Checkout and Customers.
// Driven by PosCustomer in assets/js/pos.js.
?>
<div class="modal fade pos-modal" id="posCustomerModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form class="modal-content" id="posCustomerForm" novalidate>
      <div class="modal-header"><h5 class="modal-title" id="pcTitle">Add Customer</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
      <div class="modal-body">
        <input type="hidden" name="id" id="pcId">
        <input type="hidden" name="customer_type" id="pcType" value="individual">
        <div class="pos-seg" id="pcSeg">
          <button type="button" data-type="individual" class="active">Individual</button>
          <button type="button" data-type="company">Company</button>
        </div>
        <div class="row g-3">
          <div class="col-sm-6"><label class="form-label" for="pcName"><span id="pcNameLabel">Full Name</span> <span class="text-danger">*</span></label><input type="text" class="form-control" name="name" id="pcName" required maxlength="150"></div>
          <div class="col-sm-6"><label class="form-label" for="pcMobile">Mobile Number <span class="text-danger">*</span></label><input type="tel" class="form-control" name="mobile" id="pcMobile" required maxlength="13" inputmode="tel"></div>
          <div class="col-12"><label class="form-label" for="pcEmail">Email</label><input type="email" class="form-control" name="email" id="pcEmail" placeholder="name@example.com" maxlength="150"></div>
          <div class="col-12"><label class="form-label" for="pcAddress">Address</label><textarea class="form-control" name="address" id="pcAddress" rows="3" maxlength="255"></textarea></div>
          <div class="col-sm-6"><label class="form-label" for="pcGstin">GSTIN (Optional)</label><input type="text" class="form-control text-uppercase" name="gstin" id="pcGstin" maxlength="15" placeholder="29ABCDE1234F1Z5"></div>
          <div class="col-sm-6"><label class="form-label" for="pcGroup">Customer Group</label>
            <select class="form-select" name="customer_group" id="pcGroup">
              <?php foreach (['Retail', 'Wholesale', 'Commercial', 'Distributor', 'Dealer', 'Government', 'Non Profit', 'Individual'] as $g): ?><option><?= e($g) ?></option><?php endforeach; ?>
            </select></div>
          <div class="col-sm-6"><label class="form-label" for="pcCredit">Credit Limit (<?= e(setting('currency_symbol', '$')) ?>)</label><input type="number" min="0" step="0.01" class="form-control" name="credit_limit" id="pcCredit" value="0"></div>
          <?php if (can_manage_module('pos')): ?>
          <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="make_default" value="1" id="pcDefault"><label class="form-check-label" for="pcDefault">Save as default customer</label></div></div>
          <?php endif; ?>
        </div>
        <div class="alert alert-danger py-2 mt-3 mb-0" id="pcError" hidden></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light border btn-lg-pos" data-bs-dismiss="modal">Cancel</button>
        <button type="submit" class="btn btn-brand btn-lg-pos" id="pcSave">Save Customer</button>
      </div>
    </form>
  </div>
</div>
