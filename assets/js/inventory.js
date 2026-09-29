/* Inventory module: overview charts (inventory/index.php) and clickable
 * table rows. Data comes from the page as the argument to
 * initInventoryOverview(). */
var INV_PALETTE = ['#2563eb', '#16a34a', '#f59e0b', '#7c3aed', '#0891b2', '#e11d48', '#64748b', '#ea580c'];

function invFormatters(cur) {
  return {
    money: function (v) {
      return cur + Number(v || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    },
    compact: function (v) {
      v = Number(v || 0);
      var abs = Math.abs(v);
      if (abs >= 1e7) return cur + (v / 1e7).toFixed(2).replace(/\.?0+$/, '') + 'Cr';
      if (abs >= 1e5) return cur + (v / 1e5).toFixed(2).replace(/\.?0+$/, '') + 'L';
      if (abs >= 1e3) return cur + (v / 1e3).toFixed(1).replace(/\.0$/, '') + 'K';
      return cur + v.toFixed(0);
    }
  };
}

function invRowLinks() {
  document.querySelectorAll('.dash-row-link').forEach(function (row) {
    row.addEventListener('click', function (e) {
      if (e.target.closest('a, button, form, input, label')) return;
      window.location.href = row.getAttribute('data-href');
    });
  });
}

function initInventoryOverview(data) {
  invRowLinks();
  if (typeof Chart === 'undefined') return;
  var fmt = invFormatters(data.currency || '');
  var tooltip = {
    backgroundColor: '#fff', titleColor: '#111827', bodyColor: '#111827',
    borderColor: '#e5e7eb', borderWidth: 1, padding: 10
  };

  // ---------------- Stock Movement ----------------
  var rangeSel = document.getElementById('flowRange');
  var canvas = document.getElementById('flowChart');
  var summary = document.getElementById('flowSummary');
  var flowChart = null;

  function sum(a) { return a.reduce(function (x, y) { return x + y; }, 0); }

  function renderFlow() {
    var s = data.flow[rangeSel.value];
    var totalIn = sum(s['in']), totalOut = sum(s.out);
    summary.textContent = totalIn.toLocaleString('en-IN') + ' units in, ' + totalOut.toLocaleString('en-IN') +
      ' units out in ' + rangeSel.options[rangeSel.selectedIndex].text.toLowerCase();
    var cfgData = {
      labels: s.labels,
      datasets: [
        { label: 'Stock In', data: s['in'], backgroundColor: '#2563eb', borderRadius: 4, maxBarThickness: 18 },
        { label: 'Stock Out', data: s.out, backgroundColor: '#c4b5fd', borderRadius: 4, maxBarThickness: 18 }
      ]
    };
    if (flowChart) { flowChart.data = cfgData; flowChart.update(); return; }
    flowChart = new Chart(canvas, {
      type: 'bar',
      data: cfgData,
      options: {
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { position: 'bottom', labels: { usePointStyle: true, pointStyle: 'circle', boxWidth: 8, color: '#374151' } },
          tooltip: tooltip
        },
        scales: {
          x: { grid: { display: false }, ticks: { maxTicksLimit: 10, color: '#6b7280' } },
          y: { beginAtZero: true, grid: { color: '#eef1f6' }, ticks: { color: '#6b7280', precision: 0 } }
        }
      }
    });
  }

  if (rangeSel && canvas) {
    try { var saved = localStorage.getItem('inv.flowRange'); if (saved && data.flow[saved]) rangeSel.value = saved; } catch (e) {}
    rangeSel.addEventListener('change', function () {
      try { localStorage.setItem('inv.flowRange', rangeSel.value); } catch (e) {}
      renderFlow();
    });
    renderFlow();
  }

  // ---------------- Stock Value by Category ----------------
  var catCanvas = document.getElementById('categoryChart');
  var legend = document.getElementById('categoryLegend');
  var totalEl = document.getElementById('categoryTotal');
  if (!catCanvas || !data.category.length) return;

  // Top 7 categories, the rest folded into "Other".
  var items = data.category.slice(0, 7);
  if (data.category.length > 7) {
    var rest = data.category.slice(7);
    items.push({ name: 'Other', value: sum(rest.map(function (r) { return r.value; })), url: 'products.php' });
  }
  var total = sum(items.map(function (i) { return i.value; }));
  totalEl.textContent = fmt.compact(total);
  totalEl.title = fmt.money(total);
  items.forEach(function (it, i) {
    it.color = INV_PALETTE[i % INV_PALETTE.length];
    var li = document.createElement('li');
    var a = document.createElement('a');
    a.href = it.url;
    a.title = fmt.money(it.value);
    var dot = document.createElement('span');
    dot.className = 'dash-legend-dot';
    dot.style.background = it.color;
    var name = document.createElement('span');
    name.className = 'dash-legend-name';
    name.textContent = it.name;
    var pct = document.createElement('span');
    pct.className = 'dash-legend-pct';
    pct.textContent = (total ? Math.round(it.value / total * 1000) / 10 : 0) + '%';
    a.appendChild(dot); a.appendChild(name); a.appendChild(pct);
    li.appendChild(a);
    legend.appendChild(li);
  });
  new Chart(catCanvas, {
    type: 'doughnut',
    data: {
      labels: items.map(function (i) { return i.name; }),
      datasets: [{ data: items.map(function (i) { return i.value; }), backgroundColor: items.map(function (i) { return i.color; }), borderWidth: 3, borderColor: '#fff', hoverOffset: 4 }]
    },
    options: {
      cutout: '68%',
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: { callbacks: { label: function (ctx) { return ' ' + ctx.label + ': ' + fmt.money(ctx.parsed); } } }
      },
      onClick: function (evt, els) {
        if (els.length && items[els[0].index]) window.location.href = items[els[0].index].url;
      }
    }
  });
}

/* Item Master form (inventory/product_form.php): remember the open tab so
 * a failed save re-opens it, and jump to the tab holding the first invalid
 * field instead of letting the browser block the save silently. */
function initItemForm() {
  var form = document.getElementById('itemForm');
  var hidden = document.getElementById('itemActiveTab');
  if (!form || !hidden) return;
  document.querySelectorAll('.inv-doc-tabs [data-tab]').forEach(function (btn) {
    btn.addEventListener('shown.bs.tab', function () { hidden.value = btn.getAttribute('data-tab'); });
  });
  var jumped = false;
  form.addEventListener('invalid', function (e) {
    if (jumped) return;
    var pane = e.target.closest('.tab-pane');
    if (!pane || pane.classList.contains('active')) return;
    var btn = document.querySelector('.inv-doc-tabs [data-bs-target="#' + pane.id + '"]');
    if (btn && window.bootstrap) {
      jumped = true;
      bootstrap.Tab.getOrCreateInstance(btn).show();
      setTimeout(function () { jumped = false; e.target.focus(); e.target.reportValidity(); }, 200);
    }
  }, true);
}
