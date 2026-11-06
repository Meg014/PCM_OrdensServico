const test = require('node:test'), assert = require('node:assert/strict'), fs = require('node:fs'), vm = require('node:vm');
const {format, toIso} = require('../../webroot/js/pcm-nonconformity-dates.js');
const source = fs.readFileSync('webroot/js/pcm-nonconformity-dates.js', 'utf8');
test('dates insert separators progressively and preserve Brazilian and ISO stored dates', () => {
    assert.deepEqual(['', '2', '20', '201', '2011', '20112', '20112026'].map(format), ['', '2', '20', '20/1', '20/11', '20/11/2', '20/11/2026']);
    assert.equal(format('2026-11-20'), '20/11/2026'); assert.equal(format('20/11/2026'), '20/11/2026');
});
test('real calendar validation includes leap years and rejects overflow and incomplete dates', () => {
    for (const date of ['31/02/2026', '29/02/2026', '29/02/1900', '31/04/2026', '00/11/2026', '20/13/2026', '20/11/0000', '20/11/26']) assert.equal(toIso(date), null, date);
    assert.equal(toIso('29/02/2024'), '2024-02-29'); assert.equal(toIso('29/02/2000'), '2000-02-29');
    assert.equal(toIso('20/11/2026'), '2026-11-20'); assert.equal(toIso(''), '');
});
function harness() {
    const handlers = {};
    vm.runInNewContext(source, {document: {querySelectorAll: () => [], addEventListener: (key, fn) => handlers[key] = fn}});
    const input = {value: '', name: 'actions[0][due_on]', selectionStart: 0, selectionEnd: 0, disabled: false,
        matches: key => key === '[data-nc-date]', setSelectionRange(start, end) { this.selectionStart = start; this.selectionEnd = end; },
        setCustomValidity(value) { this.message = value; }, reportValidity() { this.reported = true; }};
    return {handlers, input};
}
test('typing, replacing, deleting separators and clearing retain an editable caret', () => {
    const {handlers, input} = harness();
    input.value = '20112026'; input.setSelectionRange(8, 8); handlers.input({target: input});
    assert.equal(input.value, '20/11/2026'); assert.equal(input.selectionStart, 10); assert.equal(input.message, '');
    input.value = '21/11/2026'; input.setSelectionRange(2, 2); handlers.input({target: input}); assert.equal(input.selectionStart, 2);
    input.setSelectionRange(3, 3); let prevented = false;
    handlers.beforeinput({target: input, inputType: 'deleteContentBackward', preventDefault() { prevented = true; }});
    assert(prevented); assert.equal(input.value, '21/12/026'); assert.equal(input.selectionStart, 1);
    input.value = ''; input.setSelectionRange(0, 0); handlers.input({target: input}); assert.equal(input.value, ''); assert.equal(input.message, '');
});
test('invalid dates block submission, while native and AJAX FormData send ISO without changing the display', () => {
    const {handlers, input} = harness(); const form = {elements: [input]};
    input.value = '31/02/2026'; let blocked = false, stopped = false;
    handlers.submit({target: form, preventDefault() { blocked = true; }, stopImmediatePropagation() { stopped = true; }});
    assert(blocked && stopped && input.reported); assert.match(input.message, /DD\/MM\/AAAA/);
    input.value = '29/02/2024'; const data = new Map(); handlers.formdata({target: form, formData: data});
    assert.equal(data.get(input.name), '2024-02-29'); assert.equal(input.value, '29/02/2024');
    input.value = ''; handlers.formdata({target: form, formData: data}); assert.equal(data.get(input.name), '');
    input.disabled = true; input.value = '20/11/2026'; handlers.formdata({target: form, formData: data}); assert.equal(data.get(input.name), '');
});
test('public theme follows PCM preference and safely handles unavailable storage', () => {
    const theme = fs.readFileSync('webroot/js/pcm-nc-consultation-theme.js', 'utf8');
    for (const value of ['light', 'dark', null]) {
        const document = {documentElement: {dataset: {}}}; vm.runInNewContext(theme, {document, localStorage: {getItem: () => value}});
        assert.equal(document.documentElement.dataset.theme, value === 'dark' ? 'dark' : 'light');
        assert.equal(document.documentElement.dataset.bsTheme, document.documentElement.dataset.theme);
    }
    const document = {documentElement: {dataset: {}}}; vm.runInNewContext(theme, {document, localStorage: {getItem() { throw Error('blocked'); }}});
    assert.equal(document.documentElement.dataset.theme, 'light');
});
