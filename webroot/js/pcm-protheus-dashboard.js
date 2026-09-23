(() => {
    'use strict';
    const root = document.querySelector('[data-protheus-dashboard]');
    const initial = document.querySelector('[data-dashboard-payload]');
    if (!root || !initial) return;
    const notice = root.querySelector('[data-dashboard-notice]');
    const count = root.querySelector('[data-dashboard-count]');
    const updated = root.querySelector('[data-dashboard-updated]');
    const groups = [...root.querySelectorAll('[data-dashboard-group]')];
    const format = new Intl.NumberFormat('pt-BR');
    let valid = false;
    let loading = false;
    const fail = () => {
        notice.textContent = 'Protheus temporariamente indisponível.' +
            (valid ? ' Mantida a última consulta válida; os dados podem estar desatualizados.' : ' Não foi possível consultar os dados atuais.');
    };
    const node = (tag, text) => {
        const item = document.createElement(tag);
        item.textContent = text;
        return item;
    };
    const render = payload => {
        if (!payload || payload.available !== true || !Number.isSafeInteger(payload.record_count) || payload.record_count < 0 ||
            !payload.groups || !payload.queried_at || !Number.isFinite(Date.parse(payload.queried_at))) throw new Error('Invalid data');
        // Build and validate everything before replacing the last valid visualization.
        const contents = groups.map(target => {
            const rows = payload.groups[target.dataset.dashboardGroup] || [];
            if (!Array.isArray(rows) || rows.length > 10) throw new Error('Invalid groups');
            return rows.length ? rows.map(row => {
                if (!Number.isSafeInteger(row.quantity) || row.quantity < 0) throw new Error('Invalid quantity');
                const line = node('div', '');
                line.className = 'mb-3';
                const label = `Filial ${row.branch || '(em branco)'} · ${row.code || '(em branco)'}` +
                    (target.dataset.dashboardGroup === 'status_raw' ? ` / ${row.ending || '(em branco)'}` : '');
                line.append(node('div', `${label} — ${format.format(row.quantity)}`));
                const meter = document.createElement('meter');
                meter.min = 0;
                meter.max = Math.max(1, payload.record_count);
                meter.value = row.quantity;
                meter.style.width = '100%';
                meter.setAttribute('aria-label', label);
                line.append(meter);
                return line;
            }) : [node('p', 'Nenhuma OS para os filtros informados.')];
        });
        const date = new Intl.DateTimeFormat('pt-BR', {dateStyle: 'short', timeStyle: 'medium'}).format(new Date(payload.queried_at));
        groups.forEach((target, index) => target.replaceChildren(...contents[index]));
        count.textContent = format.format(payload.record_count);
        updated.textContent = `Dados atualizados em: ${date} · consulta ao Protheus`;
        notice.textContent = '';
        valid = true;
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
        const panels = [...root.querySelectorAll('[data-dashboard-panel]')];
        let position = 0;
        const rotate = () => panels.forEach((panel, index) => { panel.hidden = index !== position; });
        rotate();
        window.setInterval(() => { position = (position + 1) % panels.length; rotate(); }, 15000);
        try { document.documentElement.requestFullscreen?.().catch(() => {}); } catch (_) { /* Optional. */ }
    }
})();
