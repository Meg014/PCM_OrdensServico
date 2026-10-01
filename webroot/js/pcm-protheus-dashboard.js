(() => {
    'use strict';
    const root = document.querySelector('[data-protheus-dashboard]');
    const initial = document.querySelector('[data-dashboard-payload]');
    if (!root || !initial) return;
    const notice = root.querySelector('[data-dashboard-notice]');
    const count = root.querySelector('[data-dashboard-count]');
    const updated = root.querySelector('[data-dashboard-updated]');
    const title = root.querySelector('[data-dashboard-title]');
    const position = root.querySelector('[data-dashboard-position]');
    const cards = [...root.querySelectorAll('[data-dashboard-card]')];
    const analysisRoot = root.querySelector('[data-dashboard-analysis]');
    const analysisLists = new Map([...(analysisRoot?.querySelectorAll('[data-analysis-list]') ?? [])]
        .map(node => [node.dataset.analysisList, node]));
    const analysisStatus = [...(analysisRoot?.querySelectorAll('[data-analysis-status]') ?? [])];
    const analysisTotal = analysisRoot?.querySelector('[data-analysis-total]');
    const analysisCanvases = [...(root.querySelectorAll('[data-analysis-chart]') ?? [])];
    const analysisCharts = [];
    const format = new Intl.NumberFormat('pt-BR');
    let payload = null;
    let index = 0;
    let loading = false;
    const fail = () => {
        notice.textContent = 'Protheus temporariamente indisponível ou com dados que exigem validação.' +
            (payload ? ' Mantida a última consulta válida; os dados podem estar desatualizados.' : ' Não foi possível consultar os dados atuais.');
    };
    const screen = () => {
        if (!payload) return;
        const current = payload.screens[index];
        cards.forEach(card => { card.textContent = format.format(current[card.dataset.dashboardCard]); });
        if (root.dataset.presentation === 'true') {
            title.textContent = current.title;
            if (position) position.textContent = `Tela ${index + 1} de ${payload.screens.length}`;
        }
    };
    const render = next => {
        if (!next || next.available !== true || !Number.isSafeInteger(next.record_count) || next.record_count < 0 ||
            !next.queried_at || !Number.isFinite(Date.parse(next.queried_at)) || !Array.isArray(next.screens) || !next.screens.length) throw new Error('Invalid data');
        for (const item of next.screens) {
            if (typeof item.key !== 'string' || typeof item.title !== 'string') throw new Error('Invalid screen');
            for (const card of cards) {
                if (!Number.isSafeInteger(item[card.dataset.dashboardCard]) || item[card.dataset.dashboardCard] < 0) throw new Error('Invalid count');
            }
        }
        const date = new Intl.DateTimeFormat('pt-BR', {dateStyle: 'short', timeStyle: 'medium'}).format(new Date(next.queried_at));
        const currentKey = payload?.screens[index]?.key;
        index = Math.max(0, next.screens.findIndex(item => item.key === currentKey));
        payload = next;
        screen();
        if (root.dataset.presentation !== 'true' && analysisLists.size) renderAnalysis(next.analysis);
        if (count) count.textContent = format.format(next.record_count);
        updated.textContent = `Dados atualizados em: ${date}` + (root.dataset.presentation === 'true' ? '' : ' · consulta ao Protheus');
        notice.textContent = '';
    };
    const renderAnalysis = analysis => {
        if (!analysis || typeof analysis !== 'object' || !Number.isSafeInteger(analysis.total) || analysis.total < 0) throw new Error('Invalid analysis');
        if (analysisTotal) analysisTotal.textContent = format.format(analysis.total);
        for (const key of ['equipment', 'services', 'costCenters', 'maintenance', 'sectors']) {
            if (!Array.isArray(analysis[key])) throw new Error('Invalid analysis');
            const list = analysisLists.get(key);
            if (!list) continue;
            list.replaceChildren();
            for (const item of analysis[key]) {
                if (!Number.isSafeInteger(item.quantity) || item.quantity < 0) throw new Error('Invalid analysis count');
                const row = document.createElement('li');
                row.className = 'list-group-item d-flex justify-content-between align-items-start gap-3 px-0';
                if (key === 'equipment' || key === 'services' || key === 'costCenters') {
                    const link = document.createElement('a');
                    link.className = 'pcm-ranking-link';
                    const filterNames = {filial: 'filial', area: 'area', bem: 'bem', servico: 'servico',
                        tipo: 'tipo', situacao: 'situacao', termino: 'termino', unidade: 'unidade'};
                    const query = new URLSearchParams();
                    for (const [source, target] of Object.entries(filterNames)) {
                        if (payload.filters?.[source]) query.set(target, payload.filters[source]);
                    }
                    query.set('historico', '1');
                    if (key === 'equipment') {
                        query.set('filial', item.branch);
                        query.set('bem', item.code);
                    } else if (key === 'services') {
                        query.set('filial', item.branch);
                        query.set('servico', item.code);
                    } else {
                        query.set('centro', item.code);
                        query.set('centro_modo', item.mode);
                    }
                    link.href = `${root.dataset.ordersUrl}?${query}`;
                    link.textContent = ['equipment', 'services'].includes(key) ? `${item.code} — ${item.name}`
                        : (item.code || 'Sem centro de custo');
                    row.appendChild(link);
                } else {
                    const label = document.createElement('span');
                    label.textContent = item.label;
                    row.appendChild(label);
                }
                const quantity = document.createElement('strong');
                quantity.textContent = format.format(item.quantity);
                row.appendChild(quantity);
                list.appendChild(row);
            }
        }
        for (const node of analysisStatus) {
            const value = analysis.status?.[node.dataset.analysisStatus];
            if (!Number.isSafeInteger(value) || value < 0) throw new Error('Invalid status count');
            node.textContent = format.format(value);
        }
        drawAnalysisCharts(analysis);
    };
    const drawAnalysisCharts = analysis => {
        if (typeof Chart === 'undefined') return;
        analysisCharts.splice(0).forEach(chart => chart.destroy());
        const rows = {
            status: [
                {label: 'Finalizadas', quantity: analysis.status.completed},
                {label: 'Não finalizadas', quantity: analysis.status.open},
            ],
            maintenance: analysis.maintenance,
            equipment: analysis.equipment.map(item => ({label: `${item.code} — ${item.name}`, quantity: item.quantity})),
            services: analysis.services.map(item => ({label: `${item.code} — ${item.name}`, quantity: item.quantity})),
            costCenters: analysis.costCenters.map(item => ({label: item.code || 'Sem centro de custo', quantity: item.quantity})),
            sectors: analysis.sectors,
        };
        for (const canvas of analysisCanvases) {
            const key = canvas.dataset.analysisChart;
            const values = rows[key] ?? [];
            analysisCharts.push(new Chart(canvas, {type: 'bar', data: {
                labels: values.map(item => item.label),
                datasets: [{data: values.map(item => item.quantity), backgroundColor: '#356796', borderRadius: 5}],
            }, options: {responsive: true, maintainAspectRatio: false,
                indexAxis: ['equipment', 'services', 'costCenters', 'sectors'].includes(key) ? 'y' : 'x',
                plugins: {legend: {display: false}, tooltip: {callbacks: {label: context => {
                    const percentage = analysis.total > 0 ? (Number(context.raw) * 100 / analysis.total) : 0;
                    return `${format.format(context.raw)} O.S. (${percentage.toFixed(1)}%)`;
                }}}},
            }}));
        }
    };
    try { render(JSON.parse(initial.textContent)); } catch (_) { fail(); }
    const refresh = async () => {
        if (loading) return;
        loading = true;
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 15000);
        try {
            const response = await fetch(root.dataset.url, {signal: controller.signal, credentials: 'same-origin',
                cache: 'no-store', headers: {'Accept': 'application/json'}});
            if (!response.ok || response.redirected || !response.headers.get('content-type')?.includes('application/json')) throw new Error('Unavailable');
            render(await response.json());
        } catch (_) { fail(); } finally { clearTimeout(timer); loading = false; }
    };
    window.setInterval(refresh, 300000);
    if (root.dataset.presentation === 'true') {
        document.body.classList.add('pcm-presentation-mode');
        window.setInterval(() => { if (payload) { index = (index + 1) % payload.screens.length; screen(); } }, 15000);
        try { document.documentElement.requestFullscreen?.().catch(() => {}); } catch (_) { /* Optional. */ }
    }
})();
