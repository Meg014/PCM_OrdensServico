(() => {
    'use strict';
    const root = document.querySelector('[data-protheus-order]');
    if (!root) return;
    const detail = root.querySelector('[data-protheus-detail]');
    const history = root.querySelector('[data-protheus-history]');
    const dialog = root.querySelector('[data-protheus-dialog]');
    const selected = root.querySelector('[data-protheus-selected]');
    const unavailable = 'Detalhes do Protheus temporariamente indisponíveis.';
    const requests = new Map();
    const node = (tag, text, className) => {
        const element = document.createElement(tag);
        if (text !== undefined) element.textContent = text;
        if (className) element.className = className;
        return element;
    };
    const value = input => input === null || input === undefined || input === '' ? '—' : String(input);
    const date = input => /^\d{4}-\d{2}-\d{2}$/.test(input || '') ? input.split('-').reverse().join('/') : '—';
    const dateTime = (d, t) => `${date(d)} · ${value(t)}`;
    const codeName = (code, name) => [code, name].filter(v => v !== null && v !== undefined && v !== '').join(' — ') || '—';
    const heading = (title, label = 'PROTHEUS') => {
        const block = node('div', undefined, 'pcm-section-title mt-4');
        const headingText = node('h2', title);
        headingText.append(node('span', 'Fonte: Protheus',
            'badge rounded-pill bg-secondary-subtle text-secondary-emphasis border fw-normal ms-2 align-middle'));
        block.append(node('p', label), headingText);
        return block;
    };
    const notice = (target, text = unavailable) => target.replaceChildren(node('p', text, 'text-body-secondary py-3'));
    const table = (headers, rows, empty) => {
        if (!rows.length) return node('p', empty, 'text-body-secondary');
        const wrapper = node('div', undefined, 'pcm-panel table-responsive');
        const grid = node('table', undefined, 'table align-middle mb-0');
        const head = node('thead');
        const header = node('tr');
        headers.forEach(text => {
            const th = node('th', text);
            th.scope = 'col';
            header.append(th);
        });
        head.append(header);
        const body = node('tbody');
        rows.forEach(cells => {
            const row = node('tr');
            cells.forEach(cell => {
                const td = node('td');
                td.append(cell instanceof Node ? cell : document.createTextNode(value(cell)));
                row.append(td);
            });
            body.append(row);
        });
        grid.append(head, body);
        wrapper.append(grid);
        return wrapper;
    };
    function renderDetail(target, data) {
        if (!data || data.state !== 'available') {
            notice(target, data?.state === 'not_found' ? 'Dados complementares não encontrados.' : unavailable);
            return;
        }
        const m = data.maintenance;
        const title = heading('Detalhes da manutenção', `PROTHEUS · OS ${value(data.number)} · FILIAL ${value(data.branch)}`);
        const card = node('section', undefined, 'pcm-detail-card');
        card.append(node('h3', 'O que foi feito', 'h5'));
        const description = node('p', value(data.description));
        description.style.whiteSpace = 'pre-wrap';
        card.append(description);
        const list = node('dl');
        const fields = [
            ['Equipamento', codeName(m.equipment_code, m.equipment_name)],
            ['Serviço', codeName(m.service_code, m.service_name)],
            ['Área', value(m.area)], ['Centro de custo', value(m.cost_center)],
            ['Manutenção planejada — início', dateTime(m.planned_start_date, m.planned_start_time)],
            ['Manutenção planejada — fim', dateTime(m.planned_end_date, m.planned_end_time)],
            ['Manutenção real — início', dateTime(m.actual_start_date, m.actual_start_time)],
            ['Manutenção real — fim', dateTime(m.actual_end_date, m.actual_end_time)],
        ];
        fields.forEach(([label, text]) => {
            const line = node('div');
            line.append(node('dt', label), node('dd', text));
            list.append(line);
        });
        card.append(list);
        const additional = node('details', undefined, 'mt-3');
        additional.append(node('summary', 'Datas gerais previstas e reais'));
        [['general_planned_start', 'Previsto — início'], ['general_planned_end', 'Previsto — fim'],
            ['general_actual_start', 'Real — início'], ['general_actual_end', 'Real — fim']].forEach(([key, label]) => {
            additional.append(node('p', `${label}: ${dateTime(m[key + '_date'], m[key + '_time'])}`, 'mb-1'));
        });
        card.append(additional);
        target.replaceChildren(title, card, heading('Mão de obra', 'QUEM EXECUTOU · QUANTIDADE REGISTRADA'));
        target.append(table(['Profissional', 'Código', 'Data início', 'Início', 'Data fim', 'Fim', 'Quantidade / horas', 'Unidade'],
            data.labor.map(row => [row.professional, row.code, date(row.date), row.start_time,
                date(row.end_date), row.end_time, row.hours, row.unit]), 'Nenhum apontamento de mão de obra disponível.'));
        target.append(heading('Materiais utilizados', 'MATERIAIS'), table(
            ['Código', 'Descrição', 'Quantidade', 'Unidade', 'Data / hora'],
            data.materials.map(row => [row.code, row.description, row.quantity, row.unit, dateTime(row.used_date, row.used_time)]),
            'Nenhum material disponível.'));
    }
    async function request(target, params) {
        requests.get(target)?.abort();
        const controller = new AbortController();
        requests.set(target, controller);
        target.setAttribute('aria-busy', 'true');
        const timer = setTimeout(() => controller.abort(), 15000);
        try {
            const url = new URL(root.dataset.url, window.location.origin);
            Object.entries(params).forEach(([key, val]) => url.searchParams.set(key, val));
            const response = await fetch(url, {signal: controller.signal, credentials: 'same-origin',
                headers: {'Accept': 'application/json'}, cache: 'no-store'});
            if (!response.ok || response.redirected || !response.headers.get('content-type')?.includes('application/json')) {
                throw new Error('Unavailable');
            }
            const data = await response.json();
            if (requests.get(target) !== controller) return;
            if (params.part === 'history') renderHistory(data.history);
            else {
                renderDetail(target, data.detail);
                if (params.part === 'all' && data.history) renderHistory(data.history);
            }
        } catch (_error) {
            if (requests.get(target) === controller) notice(target);
        } finally {
            clearTimeout(timer);
            if (requests.get(target) === controller) {
                target.setAttribute('aria-busy', 'false');
                requests.delete(target);
            }
        }
    }
    function renderHistory(data) {
        if (!data || data.state !== 'available') {
            notice(history);
            return;
        }
        history.replaceChildren(heading('Histórico de manutenção do equipamento', `PROTHEUS · ${data.equipment_code}`));
        history.append(table(['OS', 'Data de referência', 'Serviço', 'Área', 'Situação / término (originais)', 'Detalhe'],
            data.orders.map(row => {
                const button = node('button', 'Abrir OS ' + row.TJ_ORDEM, 'btn btn-sm btn-outline-secondary');
                button.type = 'button';
                button.addEventListener('click', () => {
                    notice(selected, 'Carregando OS ' + row.TJ_ORDEM + '…');
                    dialog.showModal();
                    request(selected, {part: 'detail', os: row.TJ_ORDEM});
                });
                return [row.TJ_ORDEM, date(row.reference_date), codeName(row.TJ_SERVICO, row.service_name),
                    row.TJ_CODAREA, `Situação: ${value(row.TJ_SITUACA)} · Término: ${value(row.TJ_TERMINO)}`, button];
            }), 'Nenhuma OS disponível nesta página.'));
        const nav = node('nav', undefined, 'd-flex align-items-center gap-3 mt-3');
        nav.setAttribute('aria-label', 'Páginas do histórico do equipamento');
        [[data.page > 1, data.page - 1, 'Anterior'], [data.has_more, data.page + 1, 'Ver mais']].forEach(([enabled, page, text]) => {
            if (!enabled) return;
            const button = node('button', text, 'btn btn-outline-secondary');
            button.type = 'button';
            button.addEventListener('click', () => { button.disabled = true; request(history, {part: 'history', page}); });
            nav.append(button);
        });
        nav.append(node('span', 'Página ' + data.page, 'small text-body-secondary'));
        history.append(nav);
    }
    root.querySelector('[data-protheus-close]').addEventListener('click', () => dialog.close());
    dialog.addEventListener('close', () => { requests.get(selected)?.abort(); requests.delete(selected); });
    request(detail, {part: 'all'});
})();
