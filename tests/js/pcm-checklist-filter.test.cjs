const {test} = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');
const source = fs.readFileSync(path.join(__dirname, '../../webroot/js/pcm-checklist-filter.js'), 'utf8');

test('checklist selection follows service type and clears stale values', () => {
    let change;
    const scope = {value: 'checklist', addEventListener(event, handler) {
        assert.equal(event, 'change');
        change = handler;
    }};
    const service = {value: '000123', disabled: false};
    vm.runInNewContext(source, {document: {getElementById(id) {
        return id === 'filter-service-scope' ? scope : service;
    }}});
    assert.equal(service.disabled, false);
    assert.equal(service.value, '000123');
    scope.value = '';
    change();
    assert.equal(service.disabled, true);
    assert.equal(service.value, '');
    scope.value = 'checklist';
    change();
    assert.equal(service.disabled, false);
    assert.equal(service.value, '');
});

test('other pages do not need checklist controls', () => {
    assert.doesNotThrow(() => vm.runInNewContext(source, {document: {getElementById() { return null; }}}));
});
