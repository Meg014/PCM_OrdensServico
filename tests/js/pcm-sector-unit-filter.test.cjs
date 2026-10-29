const {test} = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const source = fs.readFileSync('webroot/js/pcm-sector-unit-filter.js', 'utf8');
const names = ['equipment_q', 'bem', 'bem_filial', 'date_start', 'date_end'];
function harness(unit = 'factory', values = {}, storage = new Map()) {
    const inputs = Object.fromEntries(names.map(name => [name, {value: values[name] || ''}]));
    const links = ['', 'admin', 'factory', 'mill', 'external', 'sectors'].map(choice => ({
        dataset: {unitChoice: choice}, href: `/pcm/setor/ELETRI?unit=${choice}&equipment=CONFIRMED&date_start=2026-10-01`,
        addEventListener(_type, fn) { this.click = fn; },
    }));
    const form = {dataset: {unit}, elements: {namedItem: name => inputs[name]}, submit() { throw Error('Draft must not submit'); }};
    const notice = {hidden: true, textContent: ''};
    const selectors = {'[data-sector-unit-filter]': {querySelectorAll: () => links}, '[data-sector-filter-form]': form, '[data-sector-draft-notice]': notice};
    vm.runInNewContext(source, {document: {querySelector: key => selectors[key]}, window: {
        location: {pathname: '/pcm/setor/ELETRI'}, sessionStorage: {
            getItem: key => storage.get(key), setItem: (key, value) => storage.set(key, value), removeItem: key => storage.delete(key),
        },
    }});
    return {inputs, links, form, notice, storage};
}

test('unit links preserve pending fields without submitting them as confirmed GET filters', () => {
    const pending = {equipment_q: 'RED ainda não aplicado', bem: '', bem_filial: '', date_start: 'data inválida', date_end: '29/10/2026'};
    const first = harness('factory', pending);
    const link = first.links.find(item => item.dataset.unitChoice === 'mill');
    const href = link.href;
    link.click({button: 0, preventDefault() { throw Error('Native navigation must remain immediate'); }});
    assert.equal(link.href, href);
    assert.match(link.href, /equipment=CONFIRMED/);
    assert.doesNotMatch(link.href, /RED|inválida/);
    const next = harness('mill', {equipment_q: 'CONFIRMED', bem: 'CONFIRMED', bem_filial: '01', date_start: '01/10/2026'}, first.storage);
    for (const name of names) assert.equal(next.inputs[name].value, pending[name]);
    assert.equal(next.notice.hidden, false);
    assert.match(next.notice.textContent, /dados exibidos usam os filtros já confirmados/);
    assert.equal(next.storage.size, 0);
});

test('confirmed equipment identity survives a unit switch with its branch and dates', () => {
    const applied = {equipment_q: 'RED 80 047', bem: 'RED 80 047', bem_filial: '01', date_start: '01/10/2026', date_end: '29/10/2026'};
    const first = harness('factory', applied);
    first.links.find(item => item.dataset.unitChoice === 'external').click({button: 0});
    const next = harness('external', applied, first.storage);
    assert.equal(next.inputs.bem.value, 'RED 80 047');
    assert.equal(next.inputs.bem_filial.value, '01');
    assert.equal(next.notice.hidden, true);
});

test('drafts do not leak to a different unit and modified clicks do not affect storage', () => {
    const first = harness();
    first.links[0].click({button: 0, ctrlKey: true});
    assert.equal(first.storage.size, 0);
    first.links[3].click({button: 0});
    const next = harness('admin', {}, first.storage);
    assert.equal(next.notice.hidden, true);
    assert.equal(next.storage.size, 0);
});

test('invalid stored drafts are ignored without blocking the page', () => {
    const storage = new Map([['pcm-sector-draft:/pcm/setor/ELETRI', 'invalid JSON']]);
    assert.doesNotThrow(() => harness('factory', {}, storage));
    assert.equal(storage.size, 0);
});
