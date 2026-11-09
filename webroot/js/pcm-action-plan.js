(() => {
    'use strict';
    const form = document.querySelector('[data-action-plan-form]');
    if (!form) return;
    const button = form.querySelector('[data-action-plan-download]');
    const label = button.querySelector('[data-action-plan-label]');
    const spinner = button.querySelector('[data-action-plan-spinner]');
    const status = document.querySelector('[data-action-plan-status]');
    let busy = false;
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (busy || !form.reportValidity()) return;
        busy = true; button.disabled = true; label.textContent = 'Gerando relatório...'; spinner.classList.remove('d-none');
        form.setAttribute('aria-busy', 'true'); status.textContent = ''; status.classList.remove('text-danger');
        let objectUrl;
        try {
            const url = new URL(form.action, location.href);
            url.search = new URLSearchParams(new FormData(form)).toString();
            const response = await fetch(url, {credentials: 'same-origin', headers: {'Accept': 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'}});
            if (response.status === 409) throw new Error('Já existe um relatório em geração para este usuário. Aguarde e tente novamente.');
            if (response.redirected || !response.headers.get('content-type')?.includes('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet')) {
                if (response.redirected) throw new Error('Sua sessão expirou. Recarregue a página e entre novamente.');
                throw new Error('Não foi possível gerar o relatório. Confira o período e tente novamente.');
            }
            if (!response.ok) throw new Error('Não foi possível gerar o relatório. Tente novamente.');
            const blob = await response.blob();
            if (!blob.size) throw new Error('O arquivo recebido está vazio. Tente novamente.');
            objectUrl = URL.createObjectURL(blob);
            const anchor = document.createElement('a'); anchor.href = objectUrl;
            const filename = response.headers.get('content-disposition')?.match(/filename="?([^";]+)"?/i)?.[1];
            anchor.download = filename?.replace(/[\\/]/g, '_') || 'plano-de-acao.xlsx';
            document.body.appendChild(anchor); anchor.click(); anchor.remove();
            status.textContent = 'Relatório gerado. O download foi iniciado.';
        } catch (error) {
            status.textContent = error.message || 'Falha na geração ou no download. Tente novamente.'; status.classList.add('text-danger');
        } finally {
            // The whole HTTP body is received before unlocking. No timer controls the busy state.
            // Keep the Blob alive until the browser has consumed its download URL.
            if (objectUrl) requestAnimationFrame(() => setTimeout(() => URL.revokeObjectURL(objectUrl), 1000));
            busy = false; button.disabled = false; label.textContent = 'Baixar Plano de Ação'; spinner.classList.add('d-none');
            form.removeAttribute('aria-busy');
        }
    });
})();
