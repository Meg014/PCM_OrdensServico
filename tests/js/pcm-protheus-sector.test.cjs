const {test} = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');

test('sector refresh keeps filters and last rendered content on failure', async () => {
    const content = {textContent: 'Última carteira válida', replaceChildren() { throw Error('Should not replace'); }};
    const notice = {textContent: ''};
    const updated = {textContent: 'Horário da consulta válida'};
    let refresh, interval, requested;
    const root = {dataset: {url: '/pcm/setor/ELETRI/data?status=FECHADA&page=2'},
        querySelector: key => ({'[data-sector-content]': content, '[data-sector-notice]': notice, '[data-sector-updated]': updated})[key]};
    vm.runInNewContext(fs.readFileSync('webroot/js/pcm-protheus-sector.js', 'utf8'), {
        document: {querySelector: key => key === '[data-protheus-sector]' ? root : {textContent: '{}'}},
        window: {setInterval(fn, ms) { refresh = fn; interval = ms; }},
        AbortController, setTimeout: () => 1, clearTimeout() {},
        fetch: async url => { requested = url; return {ok: false}; },
    });
    assert.equal(interval, 300000);
    await refresh();
    assert.equal(requested, root.dataset.url);
    assert.equal(content.textContent, 'Última carteira válida');
    assert.equal(updated.textContent, 'Horário da consulta válida');
    assert.match(notice.textContent, /Mantida a última/);
});
