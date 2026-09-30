/*
 * POS screens (New Sale, Checkout, Customers). The server re-prices every
 * cart on checkout (includes/pos.php: pos_price_cart); priceCart() here
 * mirrors that maths so the live totals match what gets charged.
 */
(function () {
  'use strict';

  var U = {
    cfg: { currency: '', inr: true },
    num: function (v) { v = parseFloat(v); return isFinite(v) ? v : 0; },
    r2: function (v) { var s = v < 0 ? -1 : 1; return s * Math.round(Math.abs(v) * 100 + 1e-6) / 100; },
    fmt: function (n) {
      n = U.r2(n);
      var neg = n < 0, parts = Math.abs(n).toFixed(2).split('.'), i = parts[0];
      if (U.cfg.inr) {
        if (i.length > 3) i = i.slice(0, -3).replace(/\B(?=(\d{2})+(?!\d))/g, ',') + ',' + i.slice(-3);
      } else {
        i = i.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
      }
      return (neg ? '-' : '') + i + '.' + parts[1];
    },
    money: function (n) { return (n < 0 ? '-' : '') + U.cfg.currency + U.fmt(Math.abs(n)); },
    esc: function (s) {
      return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
    },
    rateLabel: function (r) { return String(U.r2(r)).replace(/\.0+$/, ''); },
    post: function (url, data) {
      var fd = new FormData();
      Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
      return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' })
        .then(function (r) { return r.json().catch(function () { return { ok: false, error: 'Unexpected server response (' + r.status + ').' }; }); })
        .catch(function () { return { ok: false, error: 'Network error. Check the connection and try again.' }; });
    },
    toast: function (msg, tone) {
      var el = document.getElementById('posToast');
      if (!el || !window.bootstrap) { alert(msg); return; }
      el.className = 'toast align-items-center border-0 text-bg-' + (tone || 'dark');
      document.getElementById('posToastBody').textContent = msg;
      bootstrap.Toast.getOrCreateInstance(el, { delay: 3500 }).show();
    },
    store: {
      get: function (k) { try { return JSON.parse(localStorage.getItem(k) || 'null'); } catch (e) { return null; } },
      set: function (k, v) { try { localStorage.setItem(k, JSON.stringify(v)); } catch (e) { /* storage off */ } },
      del: function (k) { try { localStorage.removeItem(k); } catch (e) { /* storage off */ } }
    }
  };
  window.PosUtil = U;
  var pill = document.getElementById('posOnline');
  if (pill && pill.dataset.currency !== undefined) {
    U.cfg = { currency: pill.dataset.currency, inr: pill.dataset.inr === '1' };
  }

  // Online / offline pill in the POS topbar.
  function watchOnline() {
    var pill = document.getElementById('posOnline');
    if (!pill) return;
    function upd() {
      var on = navigator.onLine !== false;
      pill.classList.toggle('offline', !on);
      pill.querySelector('.txt').textContent = on ? 'Online' : 'Offline';
    }
    window.addEventListener('online', upd);
    window.addEventListener('offline', upd);
    upd();
  }
  document.addEventListener('DOMContentLoaded', watchOnline);

  /* ------------------------------------------------------------------ */
  /* Add / Edit Customer modal                                          */
  /* ------------------------------------------------------------------ */
  var PosCustomer = {
    apiUrl: '', csrf: '', onSaved: null,
    init: function (apiUrl, csrf) {
      var self = this, form = document.getElementById('posCustomerForm');
      if (!form) return;
      self.apiUrl = apiUrl; self.csrf = csrf;
      document.querySelectorAll('#pcSeg button').forEach(function (b) {
        b.addEventListener('click', function () { self.setType(b.getAttribute('data-type')); });
      });
      form.addEventListener('submit', function (e) {
        e.preventDefault();
        var err = document.getElementById('pcError');
        err.hidden = true;
        var data = { action: 'save_customer', csrf_token: self.csrf };
        new FormData(form).forEach(function (v, k) { data[k] = v; });
        var btn = document.getElementById('pcSave');
        btn.disabled = true;
        U.post(self.apiUrl, data).then(function (res) {
          btn.disabled = false;
          if (!res.ok) { err.textContent = res.error || 'Could not save the customer.'; err.hidden = false; return; }
          bootstrap.Modal.getOrCreateInstance(document.getElementById('posCustomerModal')).hide();
          if (self.onSaved) self.onSaved(res.customer);
        });
      });
    },
    setType: function (t) {
      document.getElementById('pcType').value = t;
      document.querySelectorAll('#pcSeg button').forEach(function (b) { b.classList.toggle('active', b.getAttribute('data-type') === t); });
      document.getElementById('pcNameLabel').textContent = t === 'company' ? 'Company Name' : 'Full Name';
    },
    open: function (c, onSaved) {
      this.onSaved = onSaved;
      c = c || {};
      document.getElementById('pcTitle').textContent = c.id ? 'Edit Customer' : 'Add Customer';
      document.getElementById('pcId').value = c.id || '';
      document.getElementById('pcName').value = c.name || '';
      document.getElementById('pcMobile').value = c.mobile || '';
      document.getElementById('pcEmail').value = c.email || '';
      document.getElementById('pcAddress').value = c.address || '';
      document.getElementById('pcGstin').value = c.gstin || '';
      document.getElementById('pcGroup').value = c.group || 'Retail';
      document.getElementById('pcCredit').value = c.credit != null && c.credit !== '' ? c.credit : 0;
      var def = document.getElementById('pcDefault');
      if (def) def.checked = false;
      document.getElementById('pcError').hidden = true;
      this.setType(c.type === 'company' ? 'company' : 'individual');
      bootstrap.Modal.getOrCreateInstance(document.getElementById('posCustomerModal')).show();
      setTimeout(function () { document.getElementById('pcName').focus(); }, 300);
    }
  };
  window.PosCustomer = PosCustomer;

  /* ------------------------------------------------------------------ */
  /* New Sale                                                           */
  /* ------------------------------------------------------------------ */
  var S = {
    cfg: null, byId: {}, cart: null, cats: new Set(), brands: new Set(), editIndex: -1, filtersOpen: false,
    catNames: {}, brandNames: {},

    init: function (cfg) {
      var self = this;
      self.cfg = cfg;
      U.cfg = cfg;
      cfg.products.forEach(function (p) { self.byId[p.id] = p; });
      cfg.categories.forEach(function (c) { self.catNames[c.id] = c.name; });
      cfg.brands.forEach(function (b) { self.brandNames[b.id] = b.name; });
      PosCustomer.init(cfg.apiUrl, cfg.csrf);

      var saved = U.store.get(cfg.storageKey);
      self.cart = self.blank();
      if (saved && Array.isArray(saved.lines)) self.cart = Object.assign(self.blank(), saved);
      if (cfg.preload) {
        if (cfg.preload.keep_lines) {
          self.cart.customer_id = cfg.preload.customer_id;
        } else {
          self.cart = Object.assign(self.blank(), cfg.preload);
        }
      }
      // Drop lines for products no longer sellable here.
      self.cart.lines = self.cart.lines.filter(function (l) { return self.byId[l.product_id] && l.qty > 0; });
      if (self.cart.exchange_return_id && !cfg.exchangeCredits[self.cart.exchange_return_id]) self.cart.exchange_return_id = null;

      self.setupPriceRange();
      self.renderCustomers();
      self.renderChips();
      self.renderFilterLists();
      self.bind();
      self.renderGrid();
      self.renderCart();
    },

    blank: function () {
      return { customer_id: this.cfg.walkInId, discount: 0, lines: [], held_id: null, exchange_return_id: null };
    },

    save: function () { U.store.set(this.cfg.storageKey, this.cart); },

    /* ---- pricing (mirror of pos_price_cart) ---- */
    priceCart: function () {
      var self = this, lines = [], taxableTotal = 0;
      self.cart.lines.forEach(function (l, idx) {
        var p = self.byId[l.product_id];
        if (!p) return;
        var price = l.price != null ? U.num(l.price) : p.price;
        var gross = U.r2(l.qty * price), v = U.num(l.disc_value), disc = 0;
        if (v > 0) disc = l.disc_type === 'amount' ? Math.min(U.r2(v), gross) : U.r2(gross * Math.min(v, 100) / 100);
        var rate = l.tax_rate != null ? U.num(l.tax_rate) : p.tax;
        var f = p.incl ? 1 + rate / 100 : 1;
        var subEx = U.r2(gross / f), discEx = U.r2(disc / f);
        var x = { idx: idx, l: l, p: p, price: price, gross: gross, disc: disc, rate: rate, subEx: subEx, discEx: discEx, taxable: U.r2(subEx - discEx) };
        taxableTotal += x.taxable;
        lines.push(x);
      });
      taxableTotal = U.r2(taxableTotal);
      var add = Math.min(Math.max(0, U.r2(U.num(self.cart.discount))), taxableTotal), alloc = 0, tax = 0, subtotal = 0, itemDisc = 0, rates = {};
      lines.forEach(function (x, i) {
        var share = 0;
        if (add > 0 && taxableTotal > 0) {
          share = i === lines.length - 1 ? U.r2(add - alloc) : U.r2(add * x.taxable / taxableTotal);
          alloc += share;
        }
        x.net = U.r2(x.taxable - share);
        x.tax = U.r2(x.net * x.rate / 100);
        x.total = U.r2(x.net + x.tax);
        tax += x.tax; subtotal += x.subEx; itemDisc += x.discEx;
        rates[U.rateLabel(x.rate)] = true;
      });
      tax = U.r2(tax);
      var net = U.r2(taxableTotal - add), unr = U.r2(net + tax), ro = self.cfg.roundOff ? U.r2(Math.round(unr) - unr) : 0;
      return { lines: lines, subtotal: U.r2(subtotal), itemDisc: U.r2(itemDisc), add: add, net: net, tax: tax, roundOff: ro, grand: U.r2(unr + ro), rates: Object.keys(rates) };
    },

    qtyInCart: function (pid, exceptIdx) {
      return this.cart.lines.reduce(function (s, l, i) { return s + (l.product_id === pid && i !== exceptIdx ? l.qty : 0); }, 0);
    },

    addProduct: function (p, qty) {
      qty = qty || 1;
      if (p.stockItem && this.qtyInCart(p.id) + qty > p.stock) {
        U.toast(p.stock > 0 ? 'Only ' + p.stock + ' of ' + p.name + ' in stock.' : p.name + ' is out of stock at this store.', 'danger');
        return;
      }
      var line = this.cart.lines.find(function (l) { return l.product_id === p.id; });
      if (line) line.qty += qty;
      else this.cart.lines.push({ product_id: p.id, qty: qty, price: null, disc_type: 'percent', disc_value: 0, tax_rate: null });
      this.renderCart();
      var el = document.querySelector('.pos-line[data-idx="' + this.cart.lines.indexOf(line || this.cart.lines[this.cart.lines.length - 1]) + '"]');
      if (el) el.scrollIntoView({ block: 'nearest' });
    },

    /* ---- product grid ---- */
    matches: function (p, q) {
      var self = this, f = self.filterState();
      if (q) {
        var hay = (p.name + ' ' + p.sku + ' ' + (self.brandNames[p.brand] || '') + ' ' + (self.catNames[p.cat] || '') + ' ' + p.codes.join(' ')).toLowerCase();
        if (q.split(/\s+/).some(function (w) { return hay.indexOf(w) === -1; })) return false;
      }
      if (self.cats.size && !self.cats.has(p.cat)) return false;
      if (self.brands.size && !self.brands.has(p.brand)) return false;
      if (p.price < f.min || p.price > f.max) return false;
      var inStock = !p.stockItem || p.stock > 0;
      if (inStock && !f.inStock) return false;
      if (!inStock && !f.outStock) return false;
      return true;
    },

    filterState: function () {
      var min = U.num(document.getElementById('posPriceMin').value);
      var maxV = document.getElementById('posPriceMax').value;
      return {
        min: min, max: maxV === '' ? Infinity : U.num(maxV),
        inStock: document.getElementById('posInStock').checked,
        outStock: document.getElementById('posOutStock').checked
      };
    },

    renderGrid: function () {
      var self = this, q = document.getElementById('posSearch').value.trim().toLowerCase();
      var list = self.cfg.products.filter(function (p) { return self.matches(p, q); });
      var shown = list.slice(0, 240), html = '';
      shown.forEach(function (p) {
        var out = p.stockItem && p.stock <= 0;
        html += '<div class="pos-prod' + (out ? ' out' : '') + '" data-id="' + p.id + '" title="' + U.esc(p.name) + '">' +
          '<div class="pos-prod-img">' + (p.image ? '<img src="' + U.esc(p.image) + '" alt="" loading="lazy">' : '<i class="fa-solid fa-box"></i>') + '</div>' +
          '<div class="pos-prod-body"><div class="pos-prod-name">' + U.esc(p.name) + '</div>' +
          '<div class="pos-prod-stock">' + (p.stockItem ? (out ? '<span class="text-danger">Out of stock</span>' : p.stock + ' in stock') : 'Service') + ' · ' + U.esc(p.sku) + '</div>' +
          '<div class="pos-prod-foot"><span class="pos-prod-price">' + U.money(p.price) + '</span>' + (out ? '' : '<button type="button" class="pos-add">Add</button>') + '</div></div></div>';
      });
      if (list.length > shown.length) html += '<div class="pos-empty" style="grid-column:1/-1">Showing ' + shown.length + ' of ' + list.length + ' products. Search to narrow it down.</div>';
      document.getElementById('posGrid').innerHTML = html;
      document.getElementById('posGridEmpty').hidden = list.length > 0;
      var n = self.cats.size + self.brands.size + (self.priceFiltered() ? 1 : 0) + (document.getElementById('posOutStock').checked || !document.getElementById('posInStock').checked ? 1 : 0);
      var badge = document.getElementById('posFilterCount');
      badge.hidden = n === 0;
      badge.textContent = n;
      self.syncChips();
    },

    renderChips: function () {
      var self = this, cats = self.cfg.categories, top = cats.slice(0, 6), rest = cats.slice(6);
      var html = '<button type="button" class="pos-chip" data-cat="">All</button>';
      top.forEach(function (c) { html += '<button type="button" class="pos-chip" data-cat="' + c.id + '">' + U.esc(c.name) + '</button>'; });
      if (rest.length) {
        html += '<div class="dropdown"><button type="button" class="pos-chip dropdown-toggle" data-bs-toggle="dropdown" id="posMoreChip">More</button><ul class="dropdown-menu">';
        rest.forEach(function (c) { html += '<li><a class="dropdown-item" href="#" data-cat="' + c.id + '">' + U.esc(c.name) + '</a></li>'; });
        html += '</ul></div>';
      }
      document.getElementById('posChips').innerHTML = html;
    },

    syncChips: function () {
      var self = this, one = self.cats.size === 1 ? Array.from(self.cats)[0] : null;
      document.querySelectorAll('#posChips [data-cat]').forEach(function (b) {
        var c = b.getAttribute('data-cat');
        b.classList.toggle('active', c === '' ? self.cats.size === 0 : one === parseInt(c, 10));
      });
      var more = document.getElementById('posMoreChip');
      if (more) {
        var inMore = one && !document.querySelector('#posChips button[data-cat="' + one + '"]');
        more.classList.toggle('active', !!inMore);
        more.textContent = inMore ? self.catNames[one] : 'More';
      }
      document.querySelectorAll('#posFilterCats input').forEach(function (i) { i.checked = self.cats.has(parseInt(i.value, 10)); });
    },

    renderFilterLists: function () {
      var self = this, catCount = {}, brandCount = {};
      self.cfg.products.forEach(function (p) {
        if (p.cat) catCount[p.cat] = (catCount[p.cat] || 0) + 1;
        if (p.brand) brandCount[p.brand] = (brandCount[p.brand] || 0) + 1;
      });
      function list(items, counts, set, name) {
        if (!items.length) return '<div class="small text-muted">None set up yet</div>';
        return items.map(function (x) {
          return '<div class="form-check"><input class="form-check-input" type="checkbox" value="' + x.id + '" id="' + name + x.id + '"' + (set.has(x.id) ? ' checked' : '') + '>' +
            '<label class="form-check-label" for="' + name + x.id + '">' + U.esc(x.name) + '</label><span class="cnt">' + (counts[x.id] || 0) + '</span></div>';
        }).join('');
      }
      document.getElementById('posFilterCats').innerHTML = list(self.cfg.categories, catCount, self.cats, 'fc');
      document.getElementById('posFilterBrands').innerHTML = list(self.cfg.brands, brandCount, self.brands, 'fb');
    },

    setupPriceRange: function () {
      var max = this.cfg.products.reduce(function (m, p) { return Math.max(m, p.price); }, 0);
      this.priceCeil = Math.max(100, Math.ceil(max / 100) * 100);
      var a = document.getElementById('posPriceMinR'), b = document.getElementById('posPriceMaxR');
      [a, b].forEach(function (r) { r.min = 0; r.max = this.priceCeil; }, this);
      a.value = 0; b.value = this.priceCeil;
      document.getElementById('posPriceMin').placeholder = '0';
      document.getElementById('posPriceMax').placeholder = U.fmt(this.priceCeil).replace(/\.00$/, '');
      this.paintRange();
    },

    priceFiltered: function () {
      return document.getElementById('posPriceMin').value !== '' || document.getElementById('posPriceMax').value !== '';
    },

    paintRange: function () {
      var a = U.num(document.getElementById('posPriceMinR').value), b = U.num(document.getElementById('posPriceMaxR').value), c = this.priceCeil || 1;
      var fill = document.getElementById('posRangeFill');
      fill.style.left = (Math.min(a, b) / c * 100) + '%';
      fill.style.width = (Math.abs(b - a) / c * 100) + '%';
    },

    /* ---- cart ---- */
    renderCustomers: function () {
      var self = this, sel = document.getElementById('posCustomer');
      sel.innerHTML = self.cfg.customers.map(function (c) {
        return '<option value="' + c.id + '">' + U.esc(c.name) + (c.mobile ? ' · ' + U.esc(c.mobile) : '') + '</option>';
      }).join('');
      if (!self.cfg.customers.some(function (c) { return c.id === self.cart.customer_id; })) self.cart.customer_id = self.cfg.walkInId;
      sel.value = self.cart.customer_id;
      document.getElementById('posEditCustomer').hidden = self.cart.customer_id === self.cfg.walkInId;
    },

    renderCart: function () {
      var self = this, t = self.priceCart(), html = '';
      t.lines.forEach(function (x) {
        var p = x.p, meta = [];
        if (x.disc > 0) meta.push('Disc ' + (x.l.disc_type === 'percent' ? U.rateLabel(x.l.disc_value) + '%' : U.money(x.disc)));
        if (x.l.price != null) meta.push('Price changed');
        if (x.l.tax_rate != null) meta.push(self.cfg.taxLabel + ' ' + U.rateLabel(x.rate) + '%');
        html += '<div class="pos-line" data-idx="' + x.idx + '">' +
          '<div class="pos-line-thumb">' + (p.image && self.cfg.showImages ? '<img src="' + U.esc(p.image) + '" alt="">' : '<i class="fa-solid fa-box"></i>') + '</div>' +
          '<div class="pos-line-info" data-edit="' + x.idx + '" title="Edit item"><div class="pos-line-name">' + U.esc(p.name) + '</div>' +
          '<div class="pos-line-price">' + U.money(x.price) + '</div>' + (meta.length ? '<div class="pos-line-meta">' + U.esc(meta.join(' · ')) + '</div>' : '') + '</div>' +
          '<div class="pos-qty"><button type="button" data-dec="' + x.idx + '" aria-label="Decrease">−</button><input type="number" min="1" value="' + x.l.qty + '" data-qty="' + x.idx + '" aria-label="Quantity"><button type="button" data-inc="' + x.idx + '" aria-label="Increase">+</button></div>' +
          '<button type="button" class="pos-trash" data-del="' + x.idx + '" aria-label="Remove"><i class="fa-regular fa-trash-can"></i></button></div>';
      });
      document.getElementById('posLines').innerHTML = html;
      var count = self.cart.lines.reduce(function (s, l) { return s + l.qty; }, 0);
      document.getElementById('posCartCount').textContent = count;
      document.getElementById('posCartEmpty').hidden = self.cart.lines.length > 0;
      document.getElementById('posSubtotal').textContent = U.money(t.subtotal);
      document.getElementById('posItemDiscRow').hidden = t.itemDisc <= 0;
      document.getElementById('posItemDisc').textContent = '- ' + U.money(t.itemDisc);
      var dIn = document.getElementById('posDiscount');
      if (dIn && document.activeElement !== dIn) dIn.value = U.num(self.cart.discount).toFixed(2);
      var dShown = document.getElementById('posDiscShown');
      if (dShown) dShown.textContent = U.money(t.add);
      document.getElementById('posTaxLabel').textContent = self.cfg.taxLabel + (t.rates.length === 1 ? ' (' + t.rates[0] + '%)' : '');
      document.getElementById('posTax').textContent = U.money(t.tax);
      document.getElementById('posRoundRow').hidden = !self.cfg.roundOff;
      document.getElementById('posRound').textContent = U.money(t.roundOff);
      document.getElementById('posGrand').textContent = U.money(t.grand);
      document.getElementById('posPayAmt').textContent = U.money(t.grand);
      document.getElementById('posPay').disabled = !self.cart.lines.length || self.cfg.needsShift;
      document.getElementById('posHold').disabled = !self.cart.lines.length;

      var note = document.getElementById('posExchangeNote'), credit = self.cart.exchange_return_id ? self.cfg.exchangeCredits[self.cart.exchange_return_id] : null;
      note.hidden = !credit;
      if (credit) note.innerHTML = '<i class="fa-solid fa-right-left"></i> Exchange credit ' + U.money(credit.amount) + ' (' + U.esc(credit.return_no) + ') applies at checkout. <a href="#" id="posDropCredit" class="text-danger">Remove</a>';
      self.save();
      self.lastTotals = t;
    },

    setQty: function (idx, qty) {
      var l = this.cart.lines[idx];
      if (!l) return;
      var p = this.byId[l.product_id];
      qty = Math.floor(qty);
      if (qty <= 0) { this.cart.lines.splice(idx, 1); this.renderCart(); return; }
      if (p.stockItem && this.qtyInCart(p.id, idx) + qty > p.stock) {
        U.toast('Only ' + p.stock + ' of ' + p.name + ' in stock.', 'danger');
        qty = Math.max(1, p.stock - this.qtyInCart(p.id, idx));
      }
      l.qty = qty;
      this.renderCart();
    },

    clearCart: function (ask) {
      if (ask && this.cart.lines.length && !confirm('Clear all items from the cart?')) return;
      this.cart = this.blank();
      document.getElementById('posCustomer').value = this.cart.customer_id;
      document.getElementById('posEditCustomer').hidden = true;
      this.renderCart();
    },

    handleCode: function (code) {
      code = String(code || '').trim();
      if (!code) return false;
      var lc = code.toLowerCase(), self = this;
      var hit = self.cfg.products.find(function (p) {
        return p.codes.some(function (c) { return c.toLowerCase() === lc; }) || (self.cfg.matchSku && p.sku.toLowerCase() === lc);
      });
      if (hit && self.cfg.autoAdd) { self.addProduct(hit, 1); return true; }
      if (hit) { document.getElementById('posSearch').value = hit.sku; self.renderGrid(); return true; }
      U.toast('No product with code "' + code + '".', 'warning');
      return false;
    },

    /* ---- Edit Item modal ---- */
    openItem: function (idx) {
      var self = this, l = self.cart.lines[idx], p = self.byId[l.product_id];
      self.editIndex = idx;
      document.getElementById('piThumb').innerHTML = p.image ? '<img src="' + U.esc(p.image) + '" alt="">' : '<i class="fa-solid fa-box fa-2x"></i>';
      document.getElementById('piName').textContent = p.name;
      document.getElementById('piSku').textContent = 'SKU: ' + p.sku;
      document.getElementById('piQty').value = l.qty;
      document.getElementById('piStock').textContent = p.stockItem ? p.stock + ' in stock at this store' : 'Not a stock item';
      var price = document.getElementById('piPrice');
      price.value = (l.price != null ? U.num(l.price) : p.price).toFixed(2);
      var canPrice = self.cfg.allowOverride && p.editable;
      price.disabled = !canPrice;
      document.getElementById('piPriceHint').textContent = !canPrice ? 'Price changes are off for this item' : (p.incl ? 'Price includes ' + self.cfg.taxLabel : (p.minPrice > 0 ? 'Minimum ' + U.money(p.minPrice) : ''));
      var canDisc = self.cfg.allowDiscount && p.disc;
      document.getElementById('piDiscType').value = l.disc_type || 'percent';
      document.getElementById('piDiscValue').value = U.num(l.disc_value) || '';
      document.getElementById('piDiscType').disabled = !canDisc;
      document.getElementById('piDiscValue').disabled = !canDisc;
      document.getElementById('piDiscValue').placeholder = canDisc ? '0' : 'Not allowed';
      var rate = l.tax_rate != null ? U.num(l.tax_rate) : p.tax, slabs = self.cfg.taxSlabs.slice();
      if (slabs.indexOf(p.tax) === -1) slabs.push(p.tax);
      slabs.sort(function (a, b) { return a - b; });
      document.getElementById('piTax').innerHTML = slabs.map(function (s) {
        return '<option value="' + s + '"' + (U.r2(s) === U.r2(rate) ? ' selected' : '') + '>' + U.esc(self.cfg.taxLabel) + ' ' + U.rateLabel(s) + '%' + (s === p.tax ? ' (default)' : '') + '</option>';
      }).join('');
      document.getElementById('piError').textContent = '';
      self.calcItem();
      bootstrap.Modal.getOrCreateInstance(document.getElementById('posItemModal')).show();
    },

    readItem: function () {
      var p = this.byId[this.cart.lines[this.editIndex].product_id];
      var r = {
        qty: Math.floor(U.num(document.getElementById('piQty').value)),
        price: U.r2(U.num(document.getElementById('piPrice').value)),
        disc_type: document.getElementById('piDiscType').value,
        disc_value: U.num(document.getElementById('piDiscValue').value),
        tax_rate: U.num(document.getElementById('piTax').value),
        p: p
      };
      var gross = U.r2(r.qty * r.price);
      r.disc = r.disc_value > 0 ? (r.disc_type === 'amount' ? Math.min(U.r2(r.disc_value), gross) : U.r2(gross * Math.min(r.disc_value, 100) / 100)) : 0;
      var after = gross - r.disc, f = p.incl ? 1 + r.tax_rate / 100 : 1, net = U.r2(after / f);
      r.total = U.r2(net + U.r2(net * r.tax_rate / 100));
      r.gross = gross;
      return r;
    },

    calcItem: function () {
      var r = this.readItem();
      document.getElementById('piDiscUnit').textContent = r.disc_type === 'amount' ? this.cfg.currency : '%';
      document.getElementById('piDiscAmount').value = U.fmt(r.disc);
      document.getElementById('piTotal').textContent = U.money(r.total);
    },

    saveItem: function () {
      var self = this, r = self.readItem(), p = r.p, err = document.getElementById('piError'), l = self.cart.lines[self.editIndex];
      if (r.qty < 1) { err.textContent = 'Quantity must be at least 1.'; return; }
      if (p.stockItem && self.qtyInCart(p.id, self.editIndex) + r.qty > p.stock) { err.textContent = 'Only ' + p.stock + ' in stock.'; return; }
      if (r.price < 0) { err.textContent = 'Price cannot be negative.'; return; }
      if (p.minPrice > 0 && r.price < p.minPrice && Math.abs(r.price - p.price) > 0.004) { err.textContent = 'Price cannot go below ' + U.money(p.minPrice) + '.'; return; }
      if (r.disc_type === 'percent' && r.disc_value > 100) { err.textContent = 'Discount cannot be more than 100%.'; return; }
      var pct = r.gross > 0 ? r.disc / r.gross * 100 : 0, cap = p.maxDisc > 0 ? p.maxDisc : 0;
      if (self.cfg.cashierCap > 0) cap = cap > 0 ? Math.min(cap, self.cfg.cashierCap) : self.cfg.cashierCap;
      if (cap > 0 && pct > cap + 0.001) { err.textContent = 'Discount cannot exceed ' + U.rateLabel(cap) + '% on this item.'; return; }
      l.qty = r.qty;
      l.price = Math.abs(r.price - p.price) > 0.004 ? r.price : null;
      l.disc_type = r.disc_type;
      l.disc_value = r.disc_value > 0 ? r.disc_value : 0;
      l.tax_rate = Math.abs(r.tax_rate - p.tax) > 0.001 ? r.tax_rate : null;
      bootstrap.Modal.getOrCreateInstance(document.getElementById('posItemModal')).hide();
      self.renderCart();
    },

    /* ---- camera scanning ---- */
    startCamera: function () {
      var self = this, video = document.getElementById('posScanVideo'), msg = document.getElementById('posScanMsg');
      if (!self.cfg.camera || !('BarcodeDetector' in window) || !navigator.mediaDevices) {
        msg.textContent = 'A USB or Bluetooth barcode scanner works anywhere on this screen. Camera scanning needs Chrome or Edge; you can also type the code:';
        return;
      }
      navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } }).then(function (stream) {
        self.stream = stream;
        video.srcObject = stream;
        video.hidden = false;
        video.play();
        msg.textContent = 'Point the camera at the barcode, or type it below.';
        var detector = new window.BarcodeDetector();
        self.scanTimer = setInterval(function () {
          if (video.readyState < 2) return;
          detector.detect(video).then(function (codes) {
            if (codes.length && self.handleCode(codes[0].rawValue)) {
              bootstrap.Modal.getOrCreateInstance(document.getElementById('posScanModal')).hide();
            }
          }).catch(function () { /* frame not ready */ });
        }, 350);
      }).catch(function () {
        msg.textContent = 'Camera is not available (permission denied or no camera). Use a scanner or type the code:';
      });
    },

    stopCamera: function () {
      if (this.scanTimer) clearInterval(this.scanTimer);
      if (this.stream) this.stream.getTracks().forEach(function (t) { t.stop(); });
      this.stream = null;
      document.getElementById('posScanVideo').hidden = true;
    },

    bind: function () {
      var self = this, $ = function (id) { return document.getElementById(id); };

      $('posGrid').addEventListener('click', function (e) {
        var card = e.target.closest('.pos-prod');
        if (!card || card.classList.contains('out')) return;
        self.addProduct(self.byId[parseInt(card.getAttribute('data-id'), 10)], 1);
      });

      $('posSearch').addEventListener('input', function () { self.renderGrid(); });
      $('posSearch').addEventListener('keydown', function (e) {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        var v = this.value.trim();
        if (!v) return;
        var lc = v.toLowerCase();
        var exact = self.cfg.products.some(function (p) { return p.codes.some(function (c) { return c.toLowerCase() === lc; }) || (self.cfg.matchSku && p.sku.toLowerCase() === lc); });
        if (exact && self.cfg.scanner) {
          self.handleCode(v);
          this.value = '';
          self.renderGrid();
          return;
        }
        var q = lc, list = self.cfg.products.filter(function (p) { return self.matches(p, q) && (!p.stockItem || p.stock > 0); });
        if (list.length === 1) { self.addProduct(list[0], 1); this.value = ''; self.renderGrid(); }
      });

      // Hardware scanners type fast and end with Enter; catch that anywhere on the page.
      var buf = '', last = 0;
      document.addEventListener('keydown', function (e) {
        if (!self.cfg.scanner) return;
        var t = e.target;
        if (t && (t.tagName === 'INPUT' || t.tagName === 'TEXTAREA' || t.tagName === 'SELECT' || t.isContentEditable)) return;
        if (document.querySelector('.modal.show')) return;
        var now = Date.now();
        if (now - last > 80) buf = '';
        last = now;
        if (e.key === 'Enter') {
          if (buf.length >= 3) { e.preventDefault(); self.handleCode(buf); }
          buf = '';
        } else if (e.key.length === 1) {
          buf += e.key;
        }
      });

      $('posChips').addEventListener('click', function (e) {
        var b = e.target.closest('[data-cat]');
        if (!b) return;
        e.preventDefault();
        var c = b.getAttribute('data-cat');
        self.cats = new Set(c === '' ? [] : [parseInt(c, 10)]);
        self.renderGrid();
      });

      $('posFilterToggle').addEventListener('click', function () {
        self.filtersOpen = !self.filtersOpen;
        $('posFilters').hidden = !self.filtersOpen;
        $('posSale').classList.toggle('with-filters', self.filtersOpen);
        this.classList.toggle('active', self.filtersOpen);
      });
      $('posFilterCats').addEventListener('change', function (e) {
        var v = parseInt(e.target.value, 10);
        if (e.target.checked) self.cats.add(v); else self.cats.delete(v);
        self.renderGrid();
      });
      $('posFilterBrands').addEventListener('change', function (e) {
        var v = parseInt(e.target.value, 10);
        if (e.target.checked) self.brands.add(v); else self.brands.delete(v);
        self.renderGrid();
      });
      ['posInStock', 'posOutStock'].forEach(function (id) { $(id).addEventListener('change', function () { self.renderGrid(); }); });
      ['posPriceMinR', 'posPriceMaxR'].forEach(function (id) {
        $(id).addEventListener('input', function () {
          var a = U.num($('posPriceMinR').value), b = U.num($('posPriceMaxR').value);
          $('posPriceMin').value = Math.min(a, b) > 0 ? Math.min(a, b) : '';
          $('posPriceMax').value = Math.max(a, b) < self.priceCeil ? Math.max(a, b) : '';
          self.paintRange();
          self.renderGrid();
        });
      });
      ['posPriceMin', 'posPriceMax'].forEach(function (id) {
        $(id).addEventListener('input', function () {
          $('posPriceMinR').value = $('posPriceMin').value === '' ? 0 : U.num($('posPriceMin').value);
          $('posPriceMaxR').value = $('posPriceMax').value === '' ? self.priceCeil : U.num($('posPriceMax').value);
          self.paintRange();
          self.renderGrid();
        });
      });
      $('posClearFilters').addEventListener('click', function () {
        self.cats.clear(); self.brands.clear();
        $('posPriceMin').value = ''; $('posPriceMax').value = '';
        $('posPriceMinR').value = 0; $('posPriceMaxR').value = self.priceCeil;
        $('posInStock').checked = true; $('posOutStock').checked = false;
        self.paintRange();
        self.renderFilterLists();
        self.renderGrid();
      });

      $('posLines').addEventListener('click', function (e) {
        var t;
        if ((t = e.target.closest('[data-inc]'))) { var i = +t.getAttribute('data-inc'); self.setQty(i, self.cart.lines[i].qty + 1); }
        else if ((t = e.target.closest('[data-dec]'))) { var j = +t.getAttribute('data-dec'); self.setQty(j, self.cart.lines[j].qty - 1); }
        else if ((t = e.target.closest('[data-del]'))) { self.cart.lines.splice(+t.getAttribute('data-del'), 1); self.renderCart(); }
        else if ((t = e.target.closest('[data-edit]'))) { self.openItem(+t.getAttribute('data-edit')); }
      });
      $('posLines').addEventListener('change', function (e) {
        var t = e.target.closest('[data-qty]');
        if (t) self.setQty(+t.getAttribute('data-qty'), U.num(t.value));
      });

      $('posCustomer').addEventListener('change', function () {
        self.cart.customer_id = parseInt(this.value, 10);
        $('posEditCustomer').hidden = self.cart.customer_id === self.cfg.walkInId;
        self.save();
      });
      function upsertCustomer(c) {
        var row = { id: c.id, name: c.name, mobile: c.mobile || c.phone, email: c.email, type: c.customer_type, group: c.customer_group, gstin: c.gstin, credit: c.credit_limit, address: c.address };
        var i = self.cfg.customers.findIndex(function (x) { return x.id === row.id; });
        if (i === -1) self.cfg.customers.push(row); else self.cfg.customers[i] = row;
        self.cart.customer_id = row.id;
        self.renderCustomers();
        self.save();
        U.toast('Customer ' + row.name + ' saved.', 'success');
      }
      $('posNewCustomer').addEventListener('click', function () { PosCustomer.open(null, upsertCustomer); });
      $('posEditCustomer').addEventListener('click', function () {
        var c = self.cfg.customers.find(function (x) { return x.id === self.cart.customer_id; });
        PosCustomer.open(c, upsertCustomer);
      });

      if ($('posApplyDiscount')) {
        var apply = function () { self.cart.discount = Math.max(0, U.r2(U.num($('posDiscount').value))); self.renderCart(); };
        $('posApplyDiscount').addEventListener('click', apply);
        $('posDiscount').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); apply(); } });
      }

      $('posClear').addEventListener('click', function () { self.clearCart(true); });
      $('posClearTop').addEventListener('click', function () { self.clearCart(true); });
      $('posCart').addEventListener('click', function (e) {
        if (e.target.id === 'posDropCredit') { e.preventDefault(); self.cart.exchange_return_id = null; self.renderCart(); }
      });

      $('posHold').addEventListener('click', function () {
        if (!self.cart.lines.length) return;
        var btn = this;
        btn.disabled = true;
        U.post(self.cfg.apiUrl, { action: 'hold', csrf_token: self.cfg.csrf, cart: JSON.stringify(self.cart) }).then(function (res) {
          btn.disabled = false;
          if (!res.ok) { U.toast(res.error || 'Could not hold the order.', 'danger'); return; }
          self.clearCart(false);
          U.toast('Order held as ' + res.hold_no + '. Find it under Held Orders.', 'success');
        });
      });

      $('posPay').addEventListener('click', function () {
        if (!self.cart.lines.length) return;
        self.save();
        $('posCheckoutCart').value = JSON.stringify(self.cart);
        $('posCheckoutForm').submit();
      });

      ['piQty', 'piPrice', 'piDiscType', 'piDiscValue', 'piTax'].forEach(function (id) {
        $(id).addEventListener('input', function () { self.calcItem(); });
        $(id).addEventListener('change', function () { self.calcItem(); });
      });
      $('piInc').addEventListener('click', function () { $('piQty').value = Math.floor(U.num($('piQty').value)) + 1; self.calcItem(); });
      $('piDec').addEventListener('click', function () { $('piQty').value = Math.max(1, Math.floor(U.num($('piQty').value)) - 1); self.calcItem(); });
      $('piUpdate').addEventListener('click', function () { self.saveItem(); });
      $('piRemove').addEventListener('click', function () {
        self.cart.lines.splice(self.editIndex, 1);
        bootstrap.Modal.getOrCreateInstance($('posItemModal')).hide();
        self.renderCart();
      });

      if ($('posScanBtn')) {
        $('posScanBtn').addEventListener('click', function () { bootstrap.Modal.getOrCreateInstance($('posScanModal')).show(); });
        $('posScanModal').addEventListener('shown.bs.modal', function () { $('posScanCode').value = ''; $('posScanCode').focus(); self.startCamera(); });
        $('posScanModal').addEventListener('hidden.bs.modal', function () { self.stopCamera(); $('posSearch').focus(); });
        var addCode = function () { if (self.handleCode($('posScanCode').value)) bootstrap.Modal.getOrCreateInstance($('posScanModal')).hide(); };
        $('posScanAdd').addEventListener('click', addCode);
        $('posScanCode').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); addCode(); } });
      }
    }
  };
  window.PosSale = S;

  /* ------------------------------------------------------------------ */
  /* Checkout                                                           */
  /* ------------------------------------------------------------------ */
  var C = {
    init: function (cfg) {
      var self = this, $ = function (id) { return document.getElementById(id); };
      self.cfg = cfg;
      U.cfg = cfg;
      self.method = cfg.modes[0] || 'cash';
      self.due = cfg.due;
      PosCustomer.init(cfg.apiUrl, cfg.csrf);

      document.querySelectorAll('.pos-method').forEach(function (b) {
        b.addEventListener('click', function () { self.setMethod(b.getAttribute('data-method')); });
      });
      $('coReceived').addEventListener('input', function () { self.fresh = false; self.calc(); });
      document.querySelectorAll('.pos-quick button').forEach(function (b) {
        b.addEventListener('click', function () {
          var add = U.num(b.getAttribute('data-amt'));
          var cur = self.fresh ? 0 : U.num($('coReceived').value);
          self.fresh = false;
          $('coReceived').value = U.r2(cur + add).toFixed(2);
          self.calc();
        });
      });
      $('coExact') && $('coExact').addEventListener('click', function () { $('coReceived').value = self.due.toFixed(2); self.fresh = true; self.calc(); });
      $('coAddSplit').addEventListener('click', function () { self.addSplit('', ''); });
      $('coSplitRows').addEventListener('input', function () { self.calc(); });
      $('coSplitRows').addEventListener('change', function () { self.calc(); });
      $('coSplitRows').addEventListener('click', function (e) {
        var b = e.target.closest('[data-rm]');
        if (b) { b.closest('.pos-split-row').remove(); self.calc(); }
      });

      var sel = $('coCustomer');
      sel.addEventListener('change', function () { self.setCustomer(parseInt(sel.value, 10)); });
      function saved(c) {
        var row = { id: c.id, name: c.name, mobile: c.mobile || c.phone, email: c.email, type: c.customer_type, group: c.customer_group, gstin: c.gstin, credit: c.credit_limit, address: c.address };
        var i = cfg.customers.findIndex(function (x) { return x.id === row.id; });
        if (i === -1) cfg.customers.push(row); else cfg.customers[i] = row;
        var opt = sel.querySelector('option[value="' + row.id + '"]');
        if (!opt) { opt = document.createElement('option'); opt.value = row.id; sel.appendChild(opt); }
        opt.textContent = row.name + (row.mobile ? ' · ' + row.mobile : '');
        sel.value = row.id;
        self.setCustomer(row.id);
      }
      $('coNewCustomer').addEventListener('click', function () { PosCustomer.open(null, saved); });
      $('coEditCustomer').addEventListener('click', function () {
        PosCustomer.open(cfg.customers.find(function (x) { return x.id === self.customerId(); }), saved);
      });

      $('coForm').addEventListener('submit', function (e) {
        var pays = self.payments(), paid = pays.reduce(function (s, p) { return s + p.amount; }, 0);
        if (self.method === 'upi' && cfg.cashfree && $('coCashfree') && $('coCashfree').checked) {
          e.preventDefault();
          self.startCashfree();
          return;
        }
        if (paid < self.due - 0.009) {
          if (self.customerId() === cfg.walkInId) {
            e.preventDefault();
            alert('Walk-in sales must be paid in full. Pick or add a named customer to leave a balance due.');
            return;
          }
          if (!confirm('Only ' + U.money(paid) + ' received. Leave ' + U.money(self.due - paid) + ' as balance due on ' + self.customerName() + '\'s account?')) { e.preventDefault(); return; }
        }
        $('coPayments').value = JSON.stringify(pays);
        $('coSubmit').disabled = true;
        $('coSubmit').innerHTML = '<span class="spinner-border spinner-border-sm"></span> Completing...';
      });

      self.fresh = true;
      self.setMethod(self.method);
      self.setCustomer(self.customerId());
    },

    customerId: function () { return parseInt(document.getElementById('coCustomer').value, 10); },
    customerName: function () {
      var id = this.customerId(), c = this.cfg.customers.find(function (x) { return x.id === id; });
      return c ? c.name : 'the customer';
    },
    setCustomer: function (id) {
      var cart = JSON.parse(document.getElementById('coCart').value);
      cart.customer_id = id;
      document.getElementById('coCart').value = JSON.stringify(cart);
      document.getElementById('coEditCustomer').hidden = id === this.cfg.walkInId;
      var c = this.cfg.customers.find(function (x) { return x.id === id; }) || {};
      document.getElementById('coCustName').textContent = c.name || '';
      document.getElementById('coCustMeta').textContent = [c.mobile, c.email].filter(Boolean).join(' · ');
      document.getElementById('coCustAv').textContent = (c.name || '?').trim().charAt(0).toUpperCase();
      // Keep the saved cart on New Sale in step with the customer picked here.
      var stored = U.store.get(this.cfg.storageKey);
      if (stored) { stored.customer_id = id; U.store.set(this.cfg.storageKey, stored); }
    },

    setMethod: function (m) {
      var $ = function (id) { return document.getElementById(id); };
      this.method = m;
      document.querySelectorAll('.pos-method').forEach(function (b) { b.classList.toggle('active', b.getAttribute('data-method') === m); });
      $('coSingle').hidden = m === 'split';
      $('coSplit').hidden = m !== 'split';
      $('coCashOnly').hidden = m !== 'cash';
      $('coRefWrap').hidden = m === 'cash' || m === 'split';
      $('coRefLabel').textContent = { card: 'Card last 4 digits / approval code', upi: 'UPI transaction ID', wallet: 'Wallet transaction ID' }[m] || 'Reference';
      if ($('coCfWrap')) $('coCfWrap').hidden = m !== 'upi';
      if (m === 'split' && !document.querySelector('#coSplitRows .pos-split-row')) {
        this.addSplit('cash', '');
        this.addSplit(this.cfg.modes[1] || 'card', '');
      }
      if (m !== 'cash') { $('coReceived').value = this.due.toFixed(2); this.fresh = true; }
      $('coReceived').readOnly = m !== 'cash';
      if (m === 'cash' && this.fresh) $('coReceived').value = this.due.toFixed(2);
      this.calc();
    },

    addSplit: function (method, amount) {
      var modes = this.cfg.modes, row = document.createElement('div');
      row.className = 'pos-split-row';
      row.innerHTML = '<select class="form-select form-select-sm" data-f="method">' + modes.map(function (m) {
        return '<option value="' + m + '"' + (m === method ? ' selected' : '') + '>' + { cash: 'Cash', card: 'Card', upi: 'UPI', wallet: 'Wallet' }[m] + '</option>';
      }).join('') + '</select><input type="number" step="0.01" min="0" class="form-control form-control-sm" data-f="amount" placeholder="Amount" value="' + amount + '">' +
        '<input type="text" class="form-control form-control-sm" data-f="reference" placeholder="Reference (optional)" maxlength="120">' +
        '<button type="button" class="btn btn-sm btn-light border" data-rm aria-label="Remove">×</button>';
      document.getElementById('coSplitRows').appendChild(row);
      if (amount === '') {
        var used = this.payments(true).reduce(function (s, p) { return s + p.amount; }, 0);
        row.querySelector('[data-f=amount]').value = Math.max(0, U.r2(this.due - used)).toFixed(2);
      }
      this.calc();
    },

    payments: function (splitOnly) {
      var out = [];
      if (this.method === 'split' || splitOnly) {
        document.querySelectorAll('#coSplitRows .pos-split-row').forEach(function (r) {
          var a = U.r2(U.num(r.querySelector('[data-f=amount]').value));
          if (a > 0) out.push({ method: r.querySelector('[data-f=method]').value, amount: a, reference: r.querySelector('[data-f=reference]').value });
        });
        return out;
      }
      var amt = U.r2(U.num(document.getElementById('coReceived').value));
      if (amt > 0) out.push({ method: this.method, amount: amt, reference: document.getElementById('coRef').value });
      return out;
    },

    calc: function () {
      var $ = function (id) { return document.getElementById(id); };
      var pays = this.payments(), paid = U.r2(pays.reduce(function (s, p) { return s + p.amount; }, 0));
      var cash = pays.filter(function (p) { return p.method === 'cash'; }).reduce(function (s, p) { return s + p.amount; }, 0);
      var change = Math.max(0, U.r2(paid - this.due)), balance = Math.max(0, U.r2(this.due - paid));
      var err = '';
      if (paid - cash > this.due + 0.009) err = 'Card / UPI / Wallet cannot be more than ' + U.money(this.due) + '.';
      $('coChange').value = U.fmt(change);
      $('coChangeLabel').textContent = balance > 0 ? 'Balance Due (' + this.cfg.currency + ')' : 'Change (' + this.cfg.currency + ')';
      if (balance > 0) $('coChange').value = U.fmt(balance);
      $('coChange').classList.toggle('text-danger', balance > 0);
      $('coSplitInfo').textContent = this.method === 'split' ? 'Received ' + U.money(paid) + ' of ' + U.money(this.due) + (balance > 0 ? ' · ' + U.money(balance) + ' left' : '') : '';
      $('coError').textContent = err;
      $('coSubmit').disabled = !!err || (this.cfg.cashfreeBusy === true);
    },

    startCashfree: function () {
      var self = this, $ = function (id) { return document.getElementById(id); };
      var phone = ($('coCfPhone').value || '').trim();
      if (!/^[6-9][0-9]{9}$/.test(phone)) { alert('Enter the customer\'s 10-digit mobile number for the UPI request.'); return; }
      $('coSubmit').disabled = true;
      U.post(self.cfg.cashfreeCreateUrl, { csrf_token: self.cfg.csrf, cart: $('coCart').value, customer_phone: phone }).then(function (res) {
        if (!res.reference) { $('coSubmit').disabled = false; alert(res.error || 'Could not start the Cashfree payment.'); return; }
        var box = $('coCfPanel');
        box.hidden = false;
        $('coCfQr').innerHTML = '';
        if (res.mode === 'orders' && window.Cashfree) {
          window.Cashfree({ mode: res.cashfree_env === 'production' ? 'production' : 'sandbox' }).checkout({ paymentSessionId: res.payment_session_id, redirectTarget: '_modal' });
          $('coCfQr').textContent = 'Complete the payment in the Cashfree window.';
        } else if (res.link_url && window.QRCode) {
          new window.QRCode($('coCfQr'), { text: res.link_url, width: 200, height: 200 });
        }
        self.cfPoll = setInterval(function () {
          fetch(self.cfg.cashfreeStatusUrl + '?reference=' + encodeURIComponent(res.reference), { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (d) {
            if (d.status === 'paid' && d.redirect) { clearInterval(self.cfPoll); window.location.href = d.redirect; }
            else if (d.status === 'failed' || d.status === 'expired') { clearInterval(self.cfPoll); box.hidden = true; $('coSubmit').disabled = false; alert('The UPI payment did not go through. Try again or pick another method.'); }
          });
        }, 3000);
      });
    }
  };
  window.PosCheckout = C;
})();
