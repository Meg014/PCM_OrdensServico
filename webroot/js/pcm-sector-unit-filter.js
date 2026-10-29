(() => {
    'use strict';
    const choices = document.querySelector('[data-sector-unit-filter]');
    const form = document.querySelector('[data-sector-filter-form]');
    if (!choices || !form) return;
    const notice = document.querySelector('[data-sector-draft-notice]');
    const fields = ['equipment_q', 'bem', 'bem_filial', 'date_start', 'date_end'];
    const key = `pcm-sector-draft:${window.location.pathname}`;
    try {
        const stored = window.sessionStorage.getItem(key);
        window.sessionStorage.removeItem(key);
        const draft = stored ? JSON.parse(stored) : null;
        if (draft?.target === form.dataset.unit && draft.values && typeof draft.values === 'object') {
            let changed = false;
            fields.forEach(name => {
                const input = form.elements.namedItem(name);
                const value = draft.values[name];
                if (input && typeof value === 'string' && value.length <= 255) {
                    changed ||= input.value !== value;
                    input.value = value;
                }
            });
            if (changed && notice) {
                notice.hidden = false;
                notice.textContent = 'Campos digitados preservados. Os dados exibidos usam os filtros já confirmados; clique em Aplicar para confirmar as alterações.';
            }
        }
    } catch (_) { /* Storage is optional; confirmed GET filters still work. */ }
    choices.querySelectorAll('[data-unit-choice]').forEach(link => link.addEventListener('click', event => {
        if (event.defaultPrevented || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey || event.button > 0) return;
        const values = {};
        fields.forEach(name => { const input = form.elements.namedItem(name); if (input) values[name] = input.value; });
        try { window.sessionStorage.setItem(key, JSON.stringify({target: link.dataset.unitChoice, values})); } catch (_) { /* Native link remains usable. */ }
        // Follow the server-generated URL: draft equipment/dates are never submitted here.
    }));
})();
