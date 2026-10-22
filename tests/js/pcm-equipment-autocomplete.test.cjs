const {test} = require('node:test');
const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const vm = require('node:vm');
const script = readFileSync('webroot/js/pcm-equipment-autocomplete.js', 'utf8');

class Element {
    constructor() { this.listeners = {}; this.attributes = {}; this.children = []; this.value = ''; this.hidden = true; }
    addEventListener(type, callback) { this.listeners[type] = callback; }
    setAttribute(key, value) { this.attributes[key] = value; }
    removeAttribute(key) { delete this.attributes[key]; }
    append(child) { this.children.push(child); }
    replaceChildren() { this.children = []; }
    focus() {}
    scrollIntoView() {}
    click() { this.listeners.click(); }
    set innerHTML(value) { throw new Error('HTML injection'); }
}
function harness(reply) {
    const input = new Element(), selected = new Element(), branch = new Element(), list = new Element(), message = new Element();
    list.id = 'equipment-options';
    const fields = {'[data-search]': input, '[data-selected]': selected, '[data-branch]': branch,
        '[role="listbox"]': list, '[role="status"]': message};
    const group = new Element();
    group.dataset = {lookup: '/pcm/ordens/equipamentos'};
    group.querySelector = key => fields[key];
    group.contains = item => Object.values(fields).includes(item);
    const timers = new Map(), calls = [];
    let counter = 0;
    vm.runInNewContext(script, {
        document: {querySelector: () => group, createElement: () => new Element()},
        window: {location: {origin: 'http://localhost:8765'}}, URL, AbortController,
        setTimeout: callback => { timers.set(++counter, callback); return counter; },
        clearTimeout: id => timers.delete(id),
        fetch: async (url, options) => { calls.push({url, options}); return reply(url, options); },
    });
    return {input, selected, branch, list, message, group, calls,
        type: text => { input.value = text; input.listeners.input(); },
        run: async () => { const callback = [...timers.values()].pop(); timers.clear(); if (callback) await callback(); },
        key: key => { let prevented = false; input.listeners.keydown({key, preventDefault: () => { prevented = true; }}); return prevented; }};
}
const ok = results => ({ok: true, redirected: false, headers: {get: () => 'application/json'}, json: async () => ({results})});

test('searches code/name, selects exact branch by keyboard and clears identity when edited', async () => {
    const h = harness(() => ok([{code: 'BOM 01', name: 'BOMBA <script>', branch: '02'}]));
    h.type('BOM');
    await h.run();
    assert.equal(h.calls[0].url.searchParams.get('q'), 'BOM');
    assert.equal(h.list.children[0].textContent, 'BOM 01 — BOMBA <script> · Filial 02');
    assert.equal(h.list.hidden, false);
    assert.equal(h.key('ArrowDown'), true);
    assert.equal(h.key('Enter'), true);
    assert.equal(h.selected.value, 'BOM 01');
    assert.equal(h.branch.value, '02');
    assert.equal(h.input.value, 'BOM 01 — BOMBA <script>');
    assert.equal(h.list.hidden, true);
    h.type('bomba');
    assert.equal(h.selected.value, '');
    assert.equal(h.branch.value, '');
    await h.run();
    assert.equal(h.calls[1].url.searchParams.get('q'), 'bomba');
    // Free text is intentionally allowed: no form submit interception or forced selection.
    assert.equal(h.input.listeners.submit, undefined);
});

test('empty results, short terms and Escape give accessible feedback', async () => {
    const h = harness(() => ok([]));
    h.type('b');
    await h.run();
    assert.equal(h.calls.length, 0);
    assert.match(h.message.textContent, /pelo menos 2/);
    h.type('inexistente');
    await h.run();
    assert.match(h.message.textContent, /Nenhum equipamento encontrado/);
    assert.equal(h.list.hidden, true);
    h.key('Escape');
    assert.equal(h.input.attributes['aria-expanded'], 'false');
});

test('a stale response cannot replace a later search or reopen a blurred list', async () => {
    let finish;
    const h = harness(() => new Promise(resolve => { finish = resolve; }));
    h.type('BOM');
    const pending = h.run();
    h.type('MOTOR');
    assert.equal(h.calls[0].options.signal.aborted, true);
    finish(ok([{code: 'OLD', name: 'Antigo', branch: '01'}]));
    await pending;
    assert.equal(h.list.children.length, 0);
    const second = h.run();
    h.group.listeners.focusout({relatedTarget: null});
    finish(ok([{code: 'LATE', name: 'Tardio', branch: '01'}]));
    await second;
    assert.equal(h.list.hidden, true);
    assert.equal(h.selected.value, '');
});

test('lookup failures and expired sessions show a public message without blocking free text', async () => {
    for (const reply of [() => ({redirected: true}),
        () => ({ok: false, headers: {get: () => 'application/json'}, json: async () => ({error: 'Consulta indisponível.'})})]) {
        const h = harness(reply);
        h.type('BOM');
        await h.run();
        assert.match(h.message.textContent, /Entre novamente|Consulta indisponível/);
        assert.equal(h.selected.value, '');
        assert.equal(h.input.value, 'BOM');
    }
});
