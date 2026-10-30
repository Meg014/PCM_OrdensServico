(() => {
    'use strict';
    const form = document.querySelector('[data-nc-complete-form]');
    if (form) {
        const items = form.querySelector('[data-nc-items]');
        const template = form.querySelector('[data-nc-item-template]');
        form.querySelector('[data-add-nc-item]').addEventListener('click', event => {
            event.preventDefault();
            if (items.querySelectorAll('[data-nc-item]').length >= 100) return;
            const index = Number(items.dataset.nextIndex || 0); items.dataset.nextIndex = String(index + 1);
            items.insertAdjacentHTML('beforeend', template.innerHTML.replaceAll('__INDEX__', String(index)));
            items.lastElementChild.querySelector('input:not([type=hidden])')?.focus();
        });
        items.addEventListener('click', event => {
            const button = event.target.closest('[data-remove-nc-item]');
            if (button && items.contains(button)) button.closest('[data-nc-item]').remove();
        });
    }
    document.addEventListener('submit', async event => {
        if (form && event.target === form) {
            const limit = Number(form.dataset.maxFields || 0);
            if (limit > 0 && [...new FormData(form).keys()].length > limit - 10) {
                event.preventDefault();
                form.querySelector('[data-nc-form-status]').textContent = 'Há muitos itens para uma operação. Reduza os itens não salvos e salve em etapas; os dados permanecem no formulário.';
            }
            return;
        }
        const quick = event.target.closest('[data-nc-quick-form]');
        if (!quick) return;
        event.preventDefault();
        if (quick.dataset.saving === '1') return;
        const row = quick.closest('[data-nc-row]');
        const status = row.querySelector('[data-nc-save-status]');
        quick.dataset.saving = '1'; status.textContent = 'Salvando…'; status.classList.remove('text-danger');
        const button = row.querySelector('button[type=submit]');
        const body = new FormData(quick); button.disabled = true;
        try {
            const response = await fetch(quick.action, {method: 'POST', body, credentials: 'same-origin', headers: {'Accept': 'application/json'}});
            if (response.redirected || !response.headers.get('content-type')?.includes('application/json')) throw new Error('Não foi possível salvar. Recarregue a página e verifique sua sessão.');
            const data = await response.json();
            if (!response.ok) throw new Error(data.message || 'Não foi possível salvar a linha.');
            quick.querySelector('[name=revision]').value = String(data.revision);
            row.dataset.deadlineTone = data.tone;
            const badge = row.querySelector('[data-nc-deadline]'); badge.textContent = data.deadline;
            const colors = {overdue:'danger',today:'danger',urgent:'warning',attention:'warning',completed:'success',on_time:'success',none:'secondary'};
            badge.className = `badge mt-2 text-bg-${colors[data.tone] || 'secondary'}`;
            status.textContent = 'Alterações salvas.';
        } catch (error) { status.textContent = error.message || 'Falha ao salvar. Seus dados continuam na linha.'; status.classList.add('text-danger'); }
        finally { quick.dataset.saving = '0'; button.disabled = false; }
    });
})();
