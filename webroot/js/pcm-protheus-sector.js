(() => {
    'use strict';
    const root = document.querySelector('[data-protheus-sector]');
    if (!root) return;
    const content = root.querySelector('[data-sector-content]');
    const notice = root.querySelector('[data-sector-notice]');
    const updated = root.querySelector('[data-sector-updated]');
    let busy = false;
    window.setInterval(async () => {
        if (busy) return;
        busy = true;
        const controller = new AbortController(), timer = setTimeout(() => controller.abort(), 15000);
        try {
            const response = await fetch(root.dataset.url, {signal: controller.signal, credentials: 'same-origin', cache: 'no-store', headers: {'Accept': 'application/json'}});
            if (!response.ok || response.redirected || !response.headers.get('content-type')?.includes('application/json')) throw Error('Unavailable');
            const data = await response.json();
            if (!data.available || typeof data.html !== 'string' || !Number.isFinite(Date.parse(data.queried_at)) || typeof data.queried_at_display !== 'string') throw Error('Incomplete');
            const fragment = document.createElement('template');
            // Same-origin CakePHP fragment; all database values are escaped in the server template.
            fragment.innerHTML = data.html;
            content.replaceChildren(fragment.content);
            updated.textContent = 'Dados atualizados em: ' + data.queried_at_display;
            notice.textContent = '';
        } catch (_) { notice.textContent = 'Protheus temporariamente indisponível. Mantida a última visualização válida, quando disponível.'; }
        finally { clearTimeout(timer); busy = false; }
    }, 300000);
})();
