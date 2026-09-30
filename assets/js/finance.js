// Finance charts: every <canvas data-fin-chart='{...}'> becomes a Chart.js
// chart. The PHP side (fin_chart()) passes {type, labels, datasets, ...};
// this file adds the shared look (rounded bars, soft grid, legends).
(function () {
  function shortNum(v) {
    var a = Math.abs(v);
    var inr = (window.FIN_CURRENCY || 'INR') === 'INR';
    if (inr && a >= 1e7) return (v / 1e7).toFixed(a >= 1e8 ? 0 : 1).replace(/\.0$/, '') + 'Cr';
    if (inr && a >= 1e4) return (v / 1e5).toFixed(a >= 1e6 ? 0 : 1).replace(/\.0$/, '') + 'L';
    if (a >= 1e6) return (v / 1e6).toFixed(1).replace(/\.0$/, '') + 'M';
    if (a >= 1e3) return (v / 1e3).toFixed(0) + 'K';
    return String(v);
  }
  function fullNum(v) {
    return Number(v).toLocaleString(window.FIN_CURRENCY === 'INR' || !window.FIN_CURRENCY ? 'en-IN' : undefined, { maximumFractionDigits: 2 });
  }

  function build(canvas) {
    var cfg = JSON.parse(canvas.getAttribute('data-fin-chart'));
    var type = cfg.type || 'bar';
    var isDonut = type === 'doughnut';
    cfg.datasets.forEach(function (ds) {
      if (type === 'bar' && ds.type !== 'line') {
        ds.borderRadius = ds.borderRadius !== undefined ? ds.borderRadius : 4;
        ds.maxBarThickness = ds.maxBarThickness || 26;
      }
      if (ds.type === 'line' || type === 'line') {
        ds.tension = 0.3; ds.pointRadius = 3; ds.borderWidth = 2; ds.fill = false;
      }
      if (isDonut) { ds.borderWidth = 2; ds.borderColor = '#fff'; }
    });
    var options = {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: cfg.legend !== false && !isDonut, position: 'bottom', labels: { usePointStyle: true, pointStyle: 'circle', boxWidth: 8, padding: 16 } },
        tooltip: { callbacks: { label: function (ctx) { var v = isDonut ? ctx.parsed : ctx.parsed.y; return ' ' + (ctx.dataset.label || ctx.label) + ': ' + (window.FIN_SYMBOL || '') + fullNum(v); } } }
      }
    };
    if (isDonut) {
      options.cutout = '68%';
    } else {
      options.scales = {
        x: { grid: { display: false }, ticks: { color: '#475569' } },
        y: { beginAtZero: true, grid: { color: '#eef1f6' }, ticks: { color: '#64748b', callback: shortNum } }
      };
    }
    if (cfg.valueLabels) {
      // Print each bar's value above it (aging charts).
      options.layout = { padding: { top: 22 } };
      cfg.plugins = [{
        id: 'valueLabels',
        afterDatasetsDraw: function (chart) {
          var ctx = chart.ctx;
          ctx.save();
          ctx.font = '600 12px system-ui, sans-serif';
          ctx.fillStyle = '#1f2937';
          ctx.textAlign = 'center';
          chart.getDatasetMeta(0).data.forEach(function (bar, i) {
            var v = chart.data.datasets[0].data[i];
            ctx.fillText((window.FIN_SYMBOL || '') + fullNum(v), bar.x, bar.y - 6);
          });
          ctx.restore();
        }
      }];
    }
    new Chart(canvas, { type: type, data: { labels: cfg.labels, datasets: cfg.datasets }, options: options, plugins: cfg.plugins || [] });
  }

  document.addEventListener('DOMContentLoaded', function () {
    if (typeof Chart === 'undefined') return;
    document.querySelectorAll('canvas[data-fin-chart]').forEach(build);
  });
})();
