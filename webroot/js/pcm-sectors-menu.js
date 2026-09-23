(() => {
    const root = document.querySelector('[data-sectors-menu]');
    if (!root) return;
    const list = root.querySelector('[data-sectors-items]');
    let busy = false, loaded = false;
    const notice = text => {
        const li = document.createElement('li'), span = document.createElement('span');
        span.className = 'dropdown-item-text text-body-secondary'; span.textContent = text;
        li.append(span); list.replaceChildren(li);
    };
    root.addEventListener('show.bs.dropdown', async () => {
        if (busy || loaded) return;
        busy = true; notice('Consultando setores…');
        const controller = new AbortController(), timer = setTimeout(() => controller.abort(), 10000);
        try {
            const response = await fetch(root.dataset.url, {signal: controller.signal, credentials: 'same-origin', cache: 'no-store', headers: {'Accept': 'application/json'}});
            if (!response.ok || response.redirected) throw Error('Unavailable');
            const data = await response.json();
            if (!data.available || !Array.isArray(data.areas)) throw Error('Unavailable');
            const nodes = data.areas.map(area => {
                const url = new URL(area.url, window.location.origin);
                if (url.origin !== window.location.origin || typeof area.name !== 'string') throw Error('Invalid');
                const li = document.createElement('li'), a = document.createElement('a');
                a.className = 'dropdown-item'; a.href = url.href; a.textContent = area.name;
                li.append(a); return li;
            });
            if (nodes.length) list.replaceChildren(...nodes); else notice('Nenhum setor encontrado no Protheus.');
            loaded = true;
        } catch (_) { notice('Setores temporariamente indisponíveis. Abra novamente para tentar.'); }
        finally { clearTimeout(timer); busy = false; }
    });
})();
