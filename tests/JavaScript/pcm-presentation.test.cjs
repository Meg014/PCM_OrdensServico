const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const path = require('node:path');

const nodes = new Map();
const timers = [];
const screen = (key, emergency, scheduled, offseason) => ({
    key, title: key, open: 10, completed: 20, preventive: 1, corrective: 8, improvement: 1,
    emergency, scheduled, offseason,
});
const payload = {updated_at: null, screens: [screen('Geral', 2, 3, 4), screen('Setor', 5, 6, 7)]};
const root = {
    dataset: {presentationUrl: '/pcm/apresentacao/data', exitUrl: '/pcm'},
    querySelector(selector) {
        if (!nodes.has(selector)) nodes.set(selector, {textContent: '', addEventListener() {}});
        return nodes.get(selector);
    },
};
const document = {
    querySelector(selector) {
        return selector === '[data-pcm-presentation]' ? root : {textContent: JSON.stringify(payload)};
    },
    body: {classList: {add() {}}}, documentElement: {}, addEventListener() {},
};
const window = {
    setInterval(callback, milliseconds) { timers.push({callback, milliseconds}); return timers.length; },
    clearInterval() {}, location: {},
};
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '../../webroot/js/pcm-presentation.js'), 'utf8'), {
    document, window, Intl, fetch: async () => ({ok: true, json: async () => payload}),
});
const text = (key) => nodes.get(`[data-presentation-${key}]`).textContent;
assert.equal(text('emergency'), '2');
assert.equal(text('scheduled'), '3');
assert.equal(text('offseason'), '4');
const rotation = timers.find((timer) => timer.milliseconds === 1000);
for (let second = 0; second < 15; second++) rotation.callback();
assert.equal(text('title'), 'Setor');
assert.equal(text('emergency'), '5');
assert.equal(text('scheduled'), '6');
assert.equal(text('offseason'), '7');
assert.equal(text('position'), 'Tela 2 de 2');
assert.equal(text('countdown'), '15');
assert.ok(timers.find((timer) => timer.milliseconds === 30000));
console.log('TV: counters and 15-second rotation passed.');
