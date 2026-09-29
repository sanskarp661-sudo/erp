/* Main dashboard: Sales Overview + Sales by Category charts, clickable
 * table rows, and the top bar's live search (Ctrl+K). Data comes from
 * dashboard.php as window.DASHBOARD_DATA. */
function initDashboard(data) {
  var cur = data.currency || '';
  // Charts need Chart.js from the CDN; if it didn't load, keep the rest working.
  var hasChart = typeof Chart !== 'undefined';

  function fmtMoney(v) {
    return cur + Number(v || 0).toLocaleString('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
  }
  function fmtCompact(v) {
    v = Number(v || 0);
    var abs = Math.abs(v);
    if (abs >= 1e7) return cur + (v / 1e7).toFixed(2).replace(/\.?0+$/, '') + 'Cr';
    if (abs >= 1e5) return cur + (v / 1e5).toFixed(2).replace(/\.?0+$/, '') + 'L';
    if (abs >= 1e3) return cur + (v / 1e3).toFixed(1).replace(/\.0$/, '') + 'K';
    return cur + v.toFixed(0);
  }
  function remember(key, val) { try { localStorage.setItem('dash.' + key, val); } catch (e) {} }
  function recall(key) { try { return localStorage.getItem('dash.' + key); } catch (e) { return null; } }

  // ---------------- Sales Overview ----------------
  var rangeSel = document.getElementById('salesRange');
  var metricSel = document.getElementById('salesMetric');
  var summaryEl = document.getElementById('salesSummary');
  var salesCanvas = document.getElementById('salesChart');
  var salesChart = null;

  function seriesFor(range, metric) {
    var s = data.sales[range];
    if (metric === 'orders') return s.orders.slice();
    if (metric === 'aov') return s.sales.map(function (v, i) { return s.orders[i] ? v / s.orders[i] : 0; });
    return s.sales.slice();
  }

  function renderSales() {
    var range = rangeSel.value, metric = metricSel.value;
    var s = data.sales[range];
    var values = seriesFor(range, metric);
    var isCount = metric === 'orders';
    var totalSales = s.sales.reduce(function (a, b) { return a + b; }, 0);
    var totalOrders = s.orders.reduce(function (a, b) { return a + b; }, 0);
    var headline = metric === 'orders' ? totalOrders + ' orders'
      : metric === 'aov' ? fmtMoney(totalOrders ? totalSales / totalOrders : 0) + ' avg. per order'
      : fmtMoney(totalSales) + ' total';
    summaryEl.textContent = headline + ' in ' + rangeSel.options[rangeSel.selectedIndex].text.toLowerCase();

    var cfgData = {
      labels: s.labels,
      datasets: [{
        label: metricSel.options[metricSel.selectedIndex].text,
        data: values,
        borderColor: '#2563eb',
        backgroundColor: function (ctx) {
          var area = ctx.chart.chartArea;
          if (!area) return 'rgba(37,99,235,.08)';
          var g = ctx.chart.ctx.createLinearGradient(0, area.top, 0, area.bottom);
          g.addColorStop(0, 'rgba(37,99,235,.18)');
          g.addColorStop(1, 'rgba(37,99,235,0)');
          return g;
        },
        fill: true,
        tension: .4,
        borderWidth: 2,
        pointRadius: s.labels.length > 31 ? 0 : 3,
        pointHoverRadius: 5,
        pointBackgroundColor: '#2563eb'
      }]
    };
    var fmtVal = function (v) { return isCount ? Number(v).toLocaleString('en-IN') : fmtMoney(v); };
    if (salesChart) {
      salesChart.data = cfgData;
      salesChart.options.plugins.tooltip.callbacks.label = function (c) { return ' ' + fmtVal(c.parsed.y); };
      salesChart.options.scales.y.ticks.callback = function (v) { return isCount ? v : fmtCompact(v); };
      salesChart.options.scales.y.ticks.precision = isCount ? 0 : undefined;
      salesChart.update();
      return;
    }
    salesChart = new Chart(salesCanvas, {
      type: 'line',
      data: cfgData,
      options: {
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { display: false },
          tooltip: {
            backgroundColor: '#fff', titleColor: '#111827', bodyColor: '#111827',
            borderColor: '#e5e7eb', borderWidth: 1, padding: 10, displayColors: false,
            callbacks: { label: function (c) { return ' ' + fmtVal(c.parsed.y); } }
          }
        },
        scales: {
          x: { grid: { display: false }, ticks: { maxTicksLimit: 8, color: '#6b7280' } },
          y: { beginAtZero: true, grid: { color: '#eef1f6' }, ticks: { color: '#6b7280', precision: isCount ? 0 : undefined, callback: function (v) { return isCount ? v : fmtCompact(v); } } }
        }
      }
    });
  }

  if (hasChart && rangeSel && metricSel && salesCanvas) {
    var savedRange = recall('range'), savedMetric = recall('metric');
    if (savedRange && data.sales[savedRange]) rangeSel.value = savedRange;
    if (savedMetric && metricSel.querySelector('option[value="' + savedMetric + '"]')) metricSel.value = savedMetric;
    rangeSel.addEventListener('change', function () { remember('range', rangeSel.value); renderSales(); });
    metricSel.addEventListener('change', function () { remember('metric', metricSel.value); renderSales(); });
    renderSales();
  }

  // ---------------- Sales by Category ----------------
  var periodSel = document.getElementById('categoryPeriod');
  var catCanvas = document.getElementById('categoryChart');
  var legendEl = document.getElementById('categoryLegend');
  var totalEl = document.getElementById('categoryTotal');
  var donutWrap = document.querySelector('.dash-donut');
  var emptyEl = document.getElementById('categoryEmpty');
  var catChart = null;

  function renderCategory() {
    var c = data.category[periodSel.value];
    var has = c.items.length > 0;
    donutWrap.classList.toggle('d-none', !has);
    emptyEl.classList.toggle('d-none', has);
    totalEl.textContent = fmtCompact(c.total);
    legendEl.innerHTML = '';
    c.items.forEach(function (it) {
      var li = document.createElement('li');
      var a = document.createElement('a');
      a.href = it.url;
      a.title = fmtMoney(it.value);
      var dot = document.createElement('span');
      dot.className = 'dash-legend-dot';
      dot.style.background = it.color;
      var name = document.createElement('span');
      name.className = 'dash-legend-name';
      name.textContent = it.label;
      var pct = document.createElement('span');
      pct.className = 'dash-legend-pct';
      pct.textContent = it.pct + '%';
      a.appendChild(dot); a.appendChild(name); a.appendChild(pct);
      li.appendChild(a);
      legendEl.appendChild(li);
    });
    var cfgData = {
      labels: c.items.map(function (i) { return i.label; }),
      datasets: [{ data: c.items.map(function (i) { return i.value; }), backgroundColor: c.items.map(function (i) { return i.color; }), borderWidth: 3, borderColor: '#fff', hoverOffset: 4 }]
    };
    if (catChart) { catChart.data = cfgData; catChart.update(); return; }
    catChart = new Chart(catCanvas, {
      type: 'doughnut',
      data: cfgData,
      options: {
        cutout: '68%',
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: { callbacks: { label: function (ctx) { return ' ' + ctx.label + ': ' + fmtMoney(ctx.parsed); } } }
        },
        onClick: function (evt, els) {
          if (!els.length) return;
          var item = data.category[periodSel.value].items[els[0].index];
          if (item) window.location.href = item.url;
        }
      }
    });
  }

  if (hasChart && periodSel && catCanvas) {
    var savedPeriod = recall('period');
    if (savedPeriod && data.category[savedPeriod]) periodSel.value = savedPeriod;
    periodSel.addEventListener('change', function () { remember('period', periodSel.value); renderCategory(); });
    renderCategory();
  }

  // ---------------- Clickable table rows ----------------
  document.querySelectorAll('.dash-row-link').forEach(function (row) {
    row.addEventListener('click', function (e) {
      if (e.target.closest('a')) return;
      window.location.href = row.getAttribute('data-href');
    });
  });

  // ---------------- Top bar search ----------------
  var input = document.getElementById('globalSearch');
  var box = document.getElementById('globalSearchResults');
  if (!input || !box) return;
  var form = input.form;
  var timer = null, seq = 0, active = -1;

  document.addEventListener('keydown', function (e) {
    if ((e.ctrlKey || e.metaKey) && (e.key === 'k' || e.key === 'K')) {
      e.preventDefault();
      input.focus();
      input.select();
    }
  });

  function links() { return Array.prototype.slice.call(box.querySelectorAll('a')); }
  function close() { box.hidden = true; active = -1; }
  function setActive(i) {
    var ls = links();
    ls.forEach(function (l) { l.classList.remove('active'); });
    if (!ls.length) { active = -1; return; }
    active = (i + ls.length) % ls.length;
    ls[active].classList.add('active');
    ls[active].scrollIntoView({ block: 'nearest' });
  }

  function render(res) {
    box.innerHTML = '';
    if (!res.groups.length) {
      var none = document.createElement('div');
      none.className = 'topbar-search-empty';
      none.textContent = 'No matches for "' + res.q + '"';
      box.appendChild(none);
    }
    res.groups.forEach(function (g) {
      var h = document.createElement('div');
      h.className = 'topbar-search-group';
      h.innerHTML = '<i class="' + g.icon + '"></i> ';
      h.appendChild(document.createTextNode(g.label));
      box.appendChild(h);
      g.items.forEach(function (it) {
        var a = document.createElement('a');
        a.href = it.url;
        var t = document.createElement('span');
        t.textContent = it.title;
        var s = document.createElement('small');
        s.textContent = it.sub;
        a.appendChild(t); a.appendChild(s);
        box.appendChild(a);
      });
    });
    var more = document.createElement('a');
    more.href = res.more_url;
    more.className = 'topbar-search-more';
    more.textContent = 'See all results for "' + res.q + '"';
    box.appendChild(more);
    box.hidden = false;
    active = -1;
  }

  input.addEventListener('input', function () {
    var q = input.value.trim();
    clearTimeout(timer);
    if (q.length < 2) { close(); return; }
    timer = setTimeout(function () {
      var my = ++seq;
      fetch(form.action + '?format=json&q=' + encodeURIComponent(q), { credentials: 'same-origin' })
        .then(function (r) { return r.json(); })
        .then(function (res) { if (my === seq && input.value.trim() === q) render(res); })
        .catch(function () {});
    }, 200);
  });

  input.addEventListener('keydown', function (e) {
    if (box.hidden) return;
    if (e.key === 'ArrowDown') { e.preventDefault(); setActive(active + 1); }
    else if (e.key === 'ArrowUp') { e.preventDefault(); setActive(active - 1); }
    else if (e.key === 'Escape') { close(); }
    else if (e.key === 'Enter' && active >= 0) { e.preventDefault(); links()[active].click(); }
  });

  document.addEventListener('click', function (e) {
    if (!form.contains(e.target)) close();
  });
  input.addEventListener('focus', function () {
    if (box.childNodes.length && input.value.trim().length >= 2) box.hidden = false;
  });
}
