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
    screens: [{key: 'general', title: 'PCM', safra_open: 2, safra_completed: 3, offseason_open: 4, offseason_completed: 3},
        {key: 'area:ELETRI', title: '<script>bad</script>', safra_open: 1, safra_completed: 2, offseason_open: 0, offseason_completed: 3}]};
function harness(initial = payload, presentation = false) {
    const nodes = Object.fromEntries(['notice', 'count', 'updated', 'title', 'position'].map(key => [key, new Element()]));
    const group = new Element(); group.dataset.dashboardCard = 'safra_open';
    const root = {dataset: {url: '/pcm/data?filial=01', presentation: String(presentation)},
        querySelector: selector => nodes[selector.match(/data-dashboard-(.*)\]/)[1]], querySelectorAll: () => [group]};
    const intervals = []; const calls = [];
    const state = {reply: payload};
    vm.runInNewContext(script, {document: {body: {classList: {add: () => {}}}, documentElement: {}, querySelector: selector => selector === '[data-protheus-dashboard]' ? root : {textContent: JSON.stringify(initial)}, createElement: () => new Element()},
        window: {setInterval: (fn, delay) => intervals.push({fn, delay})}, Intl, Date, AbortController,
        setTimeout: () => 1, clearTimeout: () => {}, fetch: async (url, options) => {
            calls.push({url, options});
            if (state.reply instanceof Error) throw state.reply;
            return {ok: true, redirected: false, headers: {get: () => 'application/json'}, json: async () => state.reply};
        }});
    return {nodes, group, intervals, calls, state};
}
test('one five-minute refresh preserves filters and renders seasonal counts', async () => {
    const h = harness();
    assert.equal(h.nodes.count.textContent, '12');
    assert.equal(h.intervals[0].delay, 300000);
    assert.equal(h.group.textContent, '2');
    assert.equal(h.calls.length, 0);
    await h.intervals[0].fn();
    assert.equal(h.calls.length, 1);
    assert.equal(h.calls[0].url, '/pcm/data?filial=01');
    assert.match(h.nodes.updated.textContent, /consulta ao Protheus/);
});
test('failure or incomplete payload keeps last count, timestamp and cards', async () => {
    const h = harness();
    const date = h.nodes.updated.textContent;
    for (const bad of [Error('SQLSTATE secret'), {available: false}, {...payload, screens: [{key: 'general', title: 'PCM', safra_open: 'bad'}]}]) {
        h.state.reply = bad;
        await h.intervals[0].fn();
        assert.equal(h.nodes.count.textContent, '12');
        assert.equal(h.nodes.updated.textContent, date);
        assert.equal(h.group.textContent, '2');
        assert.match(h.nodes.notice.textContent, /última consulta válida/);
        assert.doesNotMatch(h.nodes.notice.textContent, /SQLSTATE|secret/);
    }
});
test('presentation rotates general and areas and preserves screen on refresh', async () => {
    const h = harness(payload, true);
    assert.equal(h.intervals[1].delay, 15000);
    h.intervals[1].fn();
    assert.equal(h.nodes.title.textContent, '<script>bad</script>');
    assert.equal(h.group.textContent, '1');
    await h.intervals[0].fn();
    assert.equal(h.group.textContent, '1');
    assert.equal(h.nodes.position.textContent, 'Tela 2 de 2');
});
test('first failure does not invent zero and recovers on next refresh', async () => {
    const h = harness({available: false});
    assert.notEqual(h.nodes.count.textContent, '0');
    await h.intervals[0].fn();
    assert.equal(h.nodes.count.textContent, '12');
    assert.equal(h.nodes.notice.textContent, '');
});
