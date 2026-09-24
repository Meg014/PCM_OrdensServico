(() => {
    'use strict';
    const root = document.querySelector('[data-protheus-sector]');
    if (!root) return;
    const content = root.querySelector('[data-sector-content]');
    const notice = root.querySelector('[data-sector-notice]');
    const updated = root.querySelector('[data-sector-updated]');
    const charts = [];
    const filters = {status: 'status', maintenance: 'maintenance_type', equipment: 'equipment', services: 'service', costCenters: 'cost_center'};
    const draw = data => {
        if (typeof Chart === 'undefined') return;
        root.querySelectorAll('[data-sector-chart]').forEach(canvas => {
            const key = canvas.dataset.sectorChart, rows = data[key] || [];
            charts.push(new Chart(canvas, {type: 'bar', data: {labels: rows.map(r => r.label), datasets: [{data: rows.map(r => r.quantity), backgroundColor: '#356796', borderRadius: 5}]},
                options: {responsive: true, maintainAspectRatio: false, indexAxis: ['equipment', 'services', 'costCenters'].includes(key) ? 'y' : 'x',
                    plugins: {legend: {display: false}, tooltip: {callbacks: {label: c => `${c.raw} O.S. (${Number(rows[c.dataIndex].percentage).toFixed(1)}%)`}}},
                    onClick: (_e, elements) => { if (!elements.length) return; const row = rows[elements[0].index]; if (!row.key) return;
                        const url = new URL(window.location.href); url.searchParams.set(filters[key], row.key);
                        if (row.branch) url.searchParams.set('filial', row.branch);
                        url.searchParams.delete('page'); window.location.href = url.toString(); }
                }}));
        });
    };
    draw(JSON.parse(document.querySelector('[data-sector-charts]').textContent));
    let busy = false;
    window.setInterval(async () => {
        if (busy) return;
        busy = true;
        const controller = new AbortController(), timer = setTimeout(() => controller.abort(), 15000);
        try {
            const response = await fetch(root.dataset.url, {signal: controller.signal, credentials: 'same-origin', cache: 'no-store', headers: {'Accept': 'application/json'}});
            if (!response.ok || response.redirected || !response.headers.get('content-type')?.includes('application/json')) throw Error('Unavailable');
            const data = await response.json();
            if (!data.available || typeof data.html !== 'string' || !data.charts || !Number.isFinite(Date.parse(data.queried_at)) || typeof data.queried_at_display !== 'string') throw Error('Incomplete');
            for (const key of Object.keys(filters)) {
                if (!Array.isArray(data.charts[key]) || data.charts[key].some(row => !Number.isSafeInteger(row.quantity) || row.quantity < 0)) throw Error('Incomplete charts');
            }
            const fragment = document.createElement('template');
            // Same-origin CakePHP fragment; all database values are escaped in the server template.
            fragment.innerHTML = data.html;
            charts.splice(0).forEach(chart => chart.destroy());
            content.replaceChildren(fragment.content);
            draw(data.charts);
            updated.textContent = 'Dados atualizados em: ' + data.queried_at_display;
            notice.textContent = '';
        } catch (_) { notice.textContent = 'Protheus temporariamente indisponível. Mantida a última visualização válida, quando disponível.'; }
        finally { clearTimeout(timer); busy = false; }
    }, 300000);
})();
