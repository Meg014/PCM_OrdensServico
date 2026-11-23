(() => {
    'use strict';
    const scope = document.getElementById('filter-service-scope');
    const service = document.getElementById('filter-checklist-service');
    if (!scope || !service) return;
    const update = () => {
        service.disabled = scope.value !== 'checklist';
        if (service.disabled) service.value = '';
    };
    scope.addEventListener('change', update);
    update();
})();
