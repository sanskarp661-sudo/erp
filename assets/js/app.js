document.addEventListener('DOMContentLoaded', function () {
  var toggle = document.getElementById('sidebarToggle');
  var sidebar = document.getElementById('sidebar');
  if (toggle && sidebar) {
    toggle.addEventListener('click', function () {
      sidebar.classList.toggle('open');
    });
  }

  // Confirm before any destructive action (delete buttons/links/forms).
  document.querySelectorAll('[data-confirm]').forEach(function (el) {
    el.addEventListener('click', function (e) {
      if (!confirm(el.getAttribute('data-confirm') || 'Are you sure?')) {
        e.preventDefault();
      }
    });
  });

  // Simple client-side table search: <input data-table-search="#tableId">
  document.querySelectorAll('[data-table-search]').forEach(function (input) {
    var table = document.querySelector(input.getAttribute('data-table-search'));
    if (!table) return;
    input.addEventListener('input', function () {
      var q = input.value.trim().toLowerCase();
      table.querySelectorAll('tbody tr').forEach(function (row) {
        row.style.display = row.textContent.toLowerCase().indexOf(q) > -1 ? '' : 'none';
      });
    });
  });

  // Dynamic line-item tables shared by Sales Order / Purchase Order / Invoice forms.
  // Markup contract:
  //   <div class="line-items" data-total-target="#totalEl">
  //     <table><tbody>
  //       <tr data-row>
  //         <td><select class="js-product"><option data-price="12.50">...</option></select></td>
  //         <td><input class="js-qty" type="number" value="1"></td>
  //         <td><input class="js-price" type="number"></td>
  //         <td class="js-subtotal"></td>
  //         <td><button type="button" class="js-remove-row">x</button></td>
  //       </tr>
  //     </tbody></table>
  //     <button type="button" class="js-add-row">Add row</button>
  //   </div>
  document.querySelectorAll('.line-items').forEach(function (wrap) {
    var tbody = wrap.querySelector('tbody');

    function recalc() {
      var total = 0;
      wrap.querySelectorAll('tr[data-row]').forEach(function (row) {
        var qty = parseFloat(row.querySelector('.js-qty')?.value || 0);
        var price = parseFloat(row.querySelector('.js-price')?.value || 0);
        var sub = (isNaN(qty) ? 0 : qty) * (isNaN(price) ? 0 : price);
        var subEl = row.querySelector('.js-subtotal');
        if (subEl) subEl.textContent = sub.toFixed(2);
        total += sub;
      });
      var totalEl = document.querySelector(wrap.getAttribute('data-total-target'));
      if (totalEl) totalEl.textContent = total.toFixed(2);
      var totalInput = document.querySelector(wrap.getAttribute('data-total-input') || '');
      if (totalInput) totalInput.value = total.toFixed(2);
    }

    wrap.addEventListener('input', recalc);

    wrap.addEventListener('change', function (e) {
      if (e.target.classList.contains('js-product')) {
        var row = e.target.closest('tr[data-row]');
        var opt = e.target.options[e.target.selectedIndex];
        var price = opt ? opt.getAttribute('data-price') : null;
        var priceInput = row ? row.querySelector('.js-price') : null;
        if (priceInput && price !== null) priceInput.value = price;
      }
      recalc();
    });

    wrap.addEventListener('click', function (e) {
      var addBtn = e.target.closest('.js-add-row');
      if (addBtn && tbody) {
        var rows = tbody.querySelectorAll('tr[data-row]');
        var clone = rows[rows.length - 1].cloneNode(true);
        clone.querySelectorAll('input').forEach(function (inp) {
          if (inp.classList.contains('js-qty')) inp.value = 1;
          else inp.value = '';
        });
        clone.querySelectorAll('select').forEach(function (sel) { sel.selectedIndex = 0; });
        var sub = clone.querySelector('.js-subtotal');
        if (sub) sub.textContent = '0.00';
        tbody.appendChild(clone);
        recalc();
        return;
      }
      var rmBtn = e.target.closest('.js-remove-row');
      if (rmBtn) {
        var rows2 = tbody.querySelectorAll('tr[data-row]');
        if (rows2.length > 1) {
          rmBtn.closest('tr[data-row]').remove();
          recalc();
        }
      }
    });

    recalc();
  });
});
