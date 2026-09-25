const {test} = require('node:test');
const assert = require('node:assert/strict');
const {readFileSync} = require('node:fs');
const vm = require('node:vm');
const script = readFileSync('webroot/js/pcm-protheus-order.js', 'utf8');

// Minimal DOM double: textContent only, no browser/network/database dependency.
class Element {
    constructor(tag = 'div') {
        this.tag = tag;
        this.children = [];
        this.text = '';
        this.style = {};
        this.dataset = {};
        this.listeners = {};
        this.attributes = {};
    }
    set textContent(value) { this.text = String(value); this.children = []; }
    get textContent() { return this.text + this.children.map(child => child.textContent).join(' '); }
    set innerHTML(_value) { throw new Error('Untrusted HTML insertion'); }
    append(...children) { this.children.push(...children); }
    replaceChildren(...children) { this.children = children; this.text = ''; }
    setAttribute(name, value) { this.attributes[name] = value; }
    addEventListener(name, callback) { this.listeners[name] = callback; }
    showModal() { this.open = true; }
    close() { this.open = false; this.listeners.close?.(); }
}
const all = element => [element, ...element.children.flatMap(all)];
const tick = () => new Promise(resolve => setImmediate(resolve));
function harness(fetchReply) {
    const targets = Object.fromEntries(['detail', 'history', 'dialog', 'selected', 'close'].map(key => [key, new Element()]));
    const root = new Element();
    root.dataset.url = '/pcm/os/42/protheus';
    root.querySelector = selector => targets[selector.match(/data-protheus-(\w+)/)[1]];
    const calls = [];
    const timers = [];
    vm.runInNewContext(script, {
        document: {querySelector: () => root, createElement: tag => new Element(tag), createTextNode: text => {
            const textNode = new Element('#text'); textNode.textContent = text; return textNode;
        }},
        window: {location: {origin: 'https://pcm.example.invalid'}},
        Node: Element, URL, AbortController,
        setTimeout: callback => { timers.push(callback); return timers.length; }, clearTimeout: () => {},
        fetch: async (url, options) => {
            calls.push({url: String(url), options});
            return fetchReply(url, options);
        },
    });
    return {targets, calls, timers};
}
const ok = payload => ({ok: true, redirected: false, headers: {get: () => 'application/json'}, json: async () => payload});
const detail = {
    state: 'available', number: '004368', branch: '01', origin_date: '2026-09-23',
    description: '<script>alert("xss")</script>\n□\u0000',
    maintenance: {equipment_code: 'MEL 80 115', equipment_name: 'MOTOR ROSCA RO-02 - SILO 01',
        service_code: 'ELEPRE', service_name: 'PREVENTIVA ELETRICA', actual_end_date: '2026-08-18'},
    labor: [{professional: 'DAMIAO GONCALVES', code: '008382', hours: '1.00', unit: 'H'},
        {professional: 'Outro profissional', code: '000001', hours: '0', unit: 'H'}],
    materials: [{code: '002075', description: 'ROLAMENTO 6203', quantity: '1', unit: 'UN'},
        {code: '000110', description: 'ROLAMENTO 6203 ZZ C3', quantity: '1', unit: 'UN'}],
};
const history = {state: 'available', equipment_code: 'MEL 80 115', page: 1, has_more: true, orders: [
    {TJ_ORDEM: '004368', reference_date: '2026-08-18', TJ_SERVICO: 'ELEPRE', service_name: 'PREVENTIVA ELETRICA', TJ_SITUACA: 'L', TJ_TERMINO: 'S'},
]};

test('renders text safely, preserves multiple entries and loads only requested history/detail', async () => {
    const h = harness(url => ok(url.searchParams.get('part') === 'history'
        ? {history: {...history, page: 2}} : {detail, history}));
    await tick();
    assert.equal(h.calls.length, 1);
    assert.match(h.targets.detail.textContent, /DAMIAO GONCALVES/);
    assert.match(h.targets.detail.textContent, /Outro profissional/);
    assert.match(h.targets.detail.textContent, /ROLAMENTO 6203 ZZ C3/);
    assert.match(h.targets.detail.textContent, /<script>/);
    assert.match(h.targets.detail.textContent, /Descrição do serviço/);
    assert.match(h.targets.detail.textContent, /Data de origem 23\/09\/2026/);
    assert.doesNotMatch(h.targets.detail.textContent, /Registrada em|24\/09\/2026 11:44/);
    assert.doesNotMatch(h.targets.detail.textContent, /O que foi feito|□|\u0000/);
    assert.equal(all(h.targets.detail).some(el => el.tag === 'script'), false);
    assert.match(h.targets.history.textContent, /18\/08\/2026/);
    assert.equal(all(h.targets.detail).filter(el => el.tag === 'span' && el.textContent === 'Fonte: Protheus').length, 3);
    assert.equal(all(h.targets.history).filter(el => el.tag === 'span' && el.textContent === 'Fonte: Protheus').length, 1);
    all(h.targets.history).find(el => el.tag === 'button' && el.textContent === 'Ver mais').listeners.click();
    await tick();
    assert.match(h.calls[1].url, /part=history&page=2/);
    all(h.targets.history).find(el => el.tag === 'button' && el.textContent === 'Abrir OS 004368').listeners.click();
    await tick();
    assert.match(h.calls[2].url, /part=detail&os=004368/);
    assert.equal(h.targets.dialog.open, true);
    assert.match(h.targets.selected.textContent, /OS 004368/);
    assert.equal(h.calls.length, 3);
});

test('network failure, invalid response and expired login only show the public fallback', async () => {
    for (const reply of [() => {throw new Error('SQLSTATE password=secret');},
        () => ({ok: true, redirected: true}), () => ({ok: false})]) {
        const h = harness(reply);
        await tick();
        assert.equal(h.targets.detail.textContent, 'Detalhes do Protheus temporariamente indisponíveis.');
        assert.equal(h.targets.history.textContent, '');
        assert.doesNotMatch(h.targets.detail.textContent, /Fonte: Protheus/);
        assert.doesNotMatch(h.targets.detail.textContent, /SQLSTATE|secret/);
        assert.equal(h.targets.detail.attributes['aria-busy'], 'false');
    }
});

test('browser timeout aborts the optional request and retains the PCM page', async () => {
    const h = harness((_url, options) => new Promise((_resolve, reject) => {
        options.signal.addEventListener('abort', () => reject(new Error('timeout')));
    }));
    h.timers[0]();
    await tick();
    assert.equal(h.calls[0].options.signal.aborted, true);
    assert.equal(h.targets.detail.textContent, 'Detalhes do Protheus temporariamente indisponíveis.');
});
