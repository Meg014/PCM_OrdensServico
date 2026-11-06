(() => {
    'use strict';
    const selector = '[data-nc-date]';
    const format = value => {
        if (/^\d{4}-\d{2}-\d{2}$/.test(value)) value = value.slice(8) + value.slice(5, 7) + value.slice(0, 4);
        const digits = value.replace(/\D/g, '').slice(0, 8);
        return digits.slice(0, 2) + (digits.length > 2 ? '/' + digits.slice(2, 4) : '') + (digits.length > 4 ? '/' + digits.slice(4) : '');
    };
    const toIso = value => {
        if (value === '') return '';
        if (!/^\d{2}\/\d{2}\/\d{4}$/.test(value)) return null;
        const [day, month, year] = value.split('/').map(Number);
        const leap = year % 4 === 0 && (year % 100 !== 0 || year % 400 === 0);
        const days = [31, leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
        return year >= 1 && month >= 1 && month <= 12 && day >= 1 && day <= days[month - 1]
            ? value.slice(6) + '-' + value.slice(3, 5) + '-' + value.slice(0, 2) : null;
    };
    if (typeof module !== 'undefined' && module.exports) { module.exports = {format, toIso}; return; }
    const validate = input => {
        const valid = toIso(input.value) !== null;
        input.setCustomValidity(valid ? '' : 'Informe uma data real no formato DD/MM/AAAA.');
        return valid;
    };
    const mask = input => {
        const raw = input.value;
        const digitsBeforeCaret = raw.slice(0, input.selectionStart ?? raw.length).replace(/\D/g, '').length;
        input.value = format(raw);
        let caret = 0, count = 0;
        while (caret < input.value.length && count < digitsBeforeCaret) { if (/\d/.test(input.value[caret])) count++; caret++; }
        if (/^\d{4}-\d{2}-\d{2}$/.test(raw)) caret = input.value.length;
        input.setSelectionRange(caret, caret);
        validate(input);
    };
    document.querySelectorAll(selector).forEach(input => { input.value = format(input.value); validate(input); });
    document.addEventListener('input', event => { if (event.target.matches(selector) && !event.isComposing) mask(event.target); });
    document.addEventListener('beforeinput', event => {
        const input = event.target;
        if (!input.matches(selector) || input.selectionStart !== input.selectionEnd) return;
        const caret = input.selectionStart;
        // Delete a digit across a separator, rather than leaving Backspace/Delete stuck on a slash.
        if (event.inputType === 'deleteContentBackward' && input.value[caret - 1] === '/') {
            event.preventDefault(); input.value = input.value.slice(0, caret - 2) + input.value.slice(caret);
            input.setSelectionRange(caret - 2, caret - 2); mask(input);
        } else if (event.inputType === 'deleteContentForward' && input.value[caret] === '/') {
            event.preventDefault(); input.value = input.value.slice(0, caret) + input.value.slice(caret + 2);
            input.setSelectionRange(caret, caret); mask(input);
        }
    });
    const fields = form => [...form.elements].filter(input => input.matches(selector) && !input.disabled);
    document.addEventListener('submit', event => {
        if (event.submitter?.formNoValidate) return;
        const invalid = fields(event.target).find(input => !validate(input));
        if (invalid) { event.preventDefault(); event.stopImmediatePropagation(); invalid.reportValidity(); }
    }, true);
    // Covers native form submissions and the existing AJAX FormData of quick-edit rows.
    // The visible field stays in Brazilian format, including after validation/network failures.
    document.addEventListener('formdata', event => {
        for (const input of fields(event.target)) {
            const value = toIso(input.value);
            if (value !== null && input.name) event.formData.set(input.name, value);
        }
    });
})();
