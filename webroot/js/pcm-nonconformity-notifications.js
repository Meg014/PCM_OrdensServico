(() => {
    'use strict';
    const container = document.querySelector('[data-nc-notifications]');
    if (!container) return;
    let pending = false;
    const refresh = async () => {
        if (pending || document.hidden || container.querySelector('details[open]')) return;
        pending = true;
        try {
            const response = await fetch(container.dataset.url, {credentials: 'same-origin', cache: 'no-store', signal: AbortSignal.timeout(10000)});
            if (response.ok && !response.redirected && response.headers.get('content-type')?.includes('text/html')) container.innerHTML = await response.text();
        } catch (_) { /* Keep the server-rendered bell available during network failures. */ }
        finally { pending = false; }
    };
    setInterval(refresh, 60000);
    document.addEventListener('visibilitychange', refresh);
    document.addEventListener('click', event => { if (!container.contains(event.target)) container.querySelector('details')?.removeAttribute('open'); });
    document.addEventListener('keydown', event => { if (event.key === 'Escape') container.querySelector('details')?.removeAttribute('open'); });
})();
