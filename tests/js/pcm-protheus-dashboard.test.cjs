const {test} = require('node:test');
const assert = require('node:assert/strict');
const vm = require('node:vm');
const fs = require('node:fs');
const script = fs.readFileSync('webroot/js/pcm-protheus-dashboard.js', 'utf8');
class Element {
    constructor() { this.textContent = ''; this.children = []; this.style = {}; this.dataset = {}; }
    append(...children) { this.children.push(...children); }
    replaceChildren(...children) { this.children = children; }
    setAttribute() {}
    set innerHTML(_) { throw Error('Unsafe HTML'); }
}
const payload = {available: true, record_count: 12, queried_at: '2026-09-23T10:00:00-03:00',
    groups: {type: [{code: '<script>bad</script>', branch: '01', quantity: 12}]}};
function harness(initial = payload) {
    const nodes = Object.fromEntries(['notice', 'count', 'updated'].map(key => [key, new Element()]));
    const group = new Element(); group.dataset.dashboardGroup = 'type';
    const root = {dataset: {url: '/pcm/data?filial=01', presentation: 'false'},
        querySelector: selector => nodes[selector.match(/data-dashboard-(.*)\]/)[1]], querySelectorAll: () => [group]};
    const intervals = []; const calls = [];
    const state = {reply: payload};
    vm.runInNewContext(script, {document: {querySelector: selector => selector === '[data-protheus-dashboard]' ? root : {textContent: JSON.stringify(initial)}, createElement: () => new Element()},
        window: {setInterval: (fn, delay) => intervals.push({fn, delay})}, Intl, Date, AbortController,
        setTimeout: () => 1, clearTimeout: () => {}, fetch: async (url, options) => {
            calls.push({url, options});
            if (state.reply instanceof Error) throw state.reply;
            return {ok: true, redirected: false, headers: {get: () => 'application/json'}, json: async () => state.reply};
        }});
    return {nodes, group, intervals, calls, state};
}
test('one five-minute refresh preserves filters and renders raw codes as text', async () => {
    const h = harness();
    assert.equal(h.nodes.count.textContent, '12');
    assert.equal(h.intervals[0].delay, 300000);
    assert.match(h.group.children[0].children[0].textContent, /<script>bad<\/script>/);
    assert.equal(h.calls.length, 0);
    await h.intervals[0].fn();
    assert.equal(h.calls.length, 1);
    assert.equal(h.calls[0].url, '/pcm/data?filial=01');
    assert.match(h.nodes.updated.textContent, /consulta ao Protheus/);
});
test('failure or incomplete payload keeps last count, timestamp and rankings', async () => {
    const h = harness();
    const date = h.nodes.updated.textContent;
    const child = h.group.children[0];
    for (const bad of [Error('SQLSTATE secret'), {available: false}, {...payload, groups: {type: [{quantity: 'bad'}]}}]) {
        h.state.reply = bad;
        await h.intervals[0].fn();
        assert.equal(h.nodes.count.textContent, '12');
        assert.equal(h.nodes.updated.textContent, date);
        assert.equal(h.group.children[0], child);
        assert.match(h.nodes.notice.textContent, /última consulta válida/);
        assert.doesNotMatch(h.nodes.notice.textContent, /SQLSTATE|secret/);
    }
});
test('first failure does not invent zero and recovers on next refresh', async () => {
    const h = harness({available: false});
    assert.notEqual(h.nodes.count.textContent, '0');
    await h.intervals[0].fn();
    assert.equal(h.nodes.count.textContent, '12');
    assert.equal(h.nodes.notice.textContent, '');
});
