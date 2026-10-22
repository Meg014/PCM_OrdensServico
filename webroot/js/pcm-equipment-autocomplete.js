(() => {
    'use strict';
    const group = document.querySelector('[data-equipment-autocomplete]');
    if (!group) return;
    const input = group.querySelector('[data-search]');
    const selected = group.querySelector('[data-selected]');
    const branch = group.querySelector('[data-branch]');
    const list = group.querySelector('[role="listbox"]');
    const message = group.querySelector('[role="status"]');
    let timer, controller, version = 0, active = -1;
    const close = () => {
        list.hidden = true;
        input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant');
        active = -1;
    };
    const choose = item => {
        // Invalidate outstanding responses before committing the exact identity.
        ++version;
        clearTimeout(timer);
        controller?.abort();
        input.value = `${item.code} — ${item.name || 'Sem nome'}`;
        selected.value = item.code;
        branch.value = item.branch;
        message.textContent = `Selecionado: ${input.value} · Filial ${item.branch || 'compartilhada'}`;
        close();
        input.focus();
    };
    input.addEventListener('input', () => {
        clearTimeout(timer);
        controller?.abort();
        const current = ++version;
        selected.value = '';
        branch.value = '';
        close();
        list.replaceChildren();
        const term = input.value.trim();
        if (term.length < 2) {
            message.textContent = 'Digite pelo menos 2 caracteres para ver sugestões. A pesquisa por trecho continua disponível.';
            return;
        }
        message.textContent = 'Pesquisando…';
        timer = setTimeout(async () => {
            controller = new AbortController();
            try {
                const url = new URL(group.dataset.lookup, window.location.origin);
                url.searchParams.set('q', term);
                const response = await fetch(url, {signal: controller.signal, credentials: 'same-origin', cache: 'no-store',
                    headers: {'Accept': 'application/json'}});
                if (current !== version) return;
                if (response.redirected || !response.headers.get('content-type')?.includes('application/json')) {
                    throw new Error('Entre novamente no sistema para pesquisar.');
                }
                const payload = await response.json();
                if (current !== version) return;
                if (!response.ok) throw new Error(payload.error || 'Não foi possível pesquisar agora.');
                list.replaceChildren();
                payload.results.forEach((item, index) => {
                    const option = document.createElement('button');
                    option.type = 'button';
                    option.className = 'list-group-item list-group-item-action';
                    option.id = `${list.id}-${index}`;
                    option.setAttribute('role', 'option');
                    option.setAttribute('aria-selected', 'false');
                    option.textContent = `${item.code} — ${item.name || 'Sem nome'} · Filial ${item.branch || 'compartilhada'}`;
                    option.addEventListener('mousedown', event => event.preventDefault());
                    option.addEventListener('click', () => choose(item));
                    list.append(option);
                });
                list.hidden = payload.results.length === 0;
                input.setAttribute('aria-expanded', String(!list.hidden));
                message.textContent = list.hidden ? 'Nenhum equipamento encontrado. Tente outro código ou nome.'
                    : 'Selecione um equipamento usando as setas e Enter ou clique. Sem seleção, a pesquisa será por trecho.';
            } catch (error) {
                if (current === version && error.name !== 'AbortError') {
                    close();
                    message.textContent = error.message;
                }
            }
        }, 250);
    });
    input.addEventListener('keydown', event => {
        if (event.key === 'Escape') { close(); return; }
        const options = [...list.children];
        if (list.hidden || !options.length) return;
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            active = (active + (event.key === 'ArrowDown' ? 1 : -1) + options.length) % options.length;
            options.forEach((option, index) => option.setAttribute('aria-selected', String(index === active)));
            input.setAttribute('aria-activedescendant', options[active].id);
            options[active].scrollIntoView({block: 'nearest'});
        } else if (event.key === 'Enter' && active >= 0) {
            event.preventDefault();
            options[active].click();
        }
    });
    group.addEventListener('focusout', event => {
        if (!group.contains(event.relatedTarget)) {
            ++version;
            clearTimeout(timer);
            controller?.abort();
            close();
        }
    });
})();
