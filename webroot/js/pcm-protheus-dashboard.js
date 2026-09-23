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
        if (count) count.textContent = format.format(next.record_count);
        updated.textContent = `Dados atualizados em: ${date}` + (root.dataset.presentation === 'true' ? '' : ' · consulta ao Protheus');
        notice.textContent = '';
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
