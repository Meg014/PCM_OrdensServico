const {test} = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
test('menu loads Protheus areas on opening and retries a failed request safely', async () => {
    const element = () => ({children: [], textContent: '', append(child) { this.children.push(child); }, replaceChildren(...children) { this.children = children; }});
    const list = element();
    let open, calls = 0;
    const root = {dataset: {url: '/pcm/setores/data'}, querySelector: () => list, addEventListener: (_event, fn) => { open = fn; }};
    vm.runInNewContext(fs.readFileSync('webroot/js/pcm-sectors-menu.js', 'utf8'), {
        document: {querySelector: () => root, createElement: element}, window: {location: {origin: 'https://pcm.invalid'}},
        URL, AbortController, setTimeout: () => 1, clearTimeout() {},
        fetch: async () => { calls++; return {ok: calls > 1, json: async () => ({available: true, areas: [
            {code: 'ELETRI', name: 'Elétrica', url: '/pcm/setor/ELETRI'},
            {code: 'NEW', name: 'NEW', url: '/pcm/setor/NEW'},
        ]})}; },
    });
    assert.equal(calls, 0);
    await open();
    assert.match(list.children[0].children[0].textContent, /indisponíveis/);
    await open();
    assert.equal(list.children[0].children[0].textContent, 'Elétrica');
    assert.equal(list.children[1].children[0].href, 'https://pcm.invalid/pcm/setor/NEW');
    await open();
    assert.equal(calls, 2);
});
