(function () {
    'use strict';
    if (typeof Chart === 'undefined') return;
    const themeStyles = getComputedStyle(document.documentElement);
    Chart.defaults.color = themeStyles.getPropertyValue('--chart-text').trim();
    Chart.defaults.borderColor = themeStyles.getPropertyValue('--chart-grid').trim();
    const historyNode = document.querySelector('[data-pcm-history-payload]');
    if (historyNode) {
        const payload = JSON.parse(historyNode.textContent);
        const labels = payload.series.map(point => new Date(`${point.date}T00:00:00`).toLocaleDateString('pt-BR'));
        const line = (selector, datasets, suffix) => {
            const canvas = document.querySelector(selector); if (!canvas) return;
            new Chart(canvas, {type: 'line', data: {labels, datasets}, options: {responsive: true, maintainAspectRatio: false, interaction: {mode: 'index', intersect: false}, plugins: {tooltip: {callbacks: {label: context => `${context.dataset.label}: ${context.raw}${suffix}`}}}, scales: {y: {beginAtZero: true}}}});
        };
        line('[data-pcm-history="status"]', [
            ['Total de OS', 'total', '#2f80ed'], ['Em aberto', 'open', '#24527a'], ['Fechadas', 'completed', '#27ae60'], ['Canceladas', 'cancelled', '#d64545']
        ].map(item => ({label: item[0], data: payload.series.map(point => point[item[1]]), borderColor: item[2], backgroundColor: item[2], tension: .2})), ' OS');
        line('[data-pcm-history="efficiency"]', [{label: 'Eficiência', data: payload.series.map(point => point.efficiency), borderColor: '#7b61c9', backgroundColor: '#7b61c9', tension: .2}], '%');
        const equipment = document.querySelector('[data-pcm-history="equipment"]');
        if (equipment) new Chart(equipment, {type: 'bar', data: {labels: payload.equipment.map(row => row.label), datasets: [{data: payload.equipment.map(row => row.quantity), backgroundColor: '#356796', borderRadius: 5}]}, options: {responsive: true, maintainAspectRatio: false, indexAxis: 'y', plugins: {legend: {display: false}}, scales: {x: {beginAtZero: true, ticks: {precision: 0}}}}});
    }
    const sectorNode = document.querySelector('[data-pcm-sector-payload]');
    const sectorCanvas = document.querySelector('[data-pcm-history="sectors"]');
    if (sectorNode && sectorCanvas) {
        const sectors = JSON.parse(sectorNode.textContent);
        new Chart(sectorCanvas, {type: 'bar', data: {labels: sectors.map(row => row.name), datasets: [{data: sectors.map(row => row.efficiency), backgroundColor: '#356796', borderRadius: 5}]}, options: {responsive: true, maintainAspectRatio: false, plugins: {legend: {display: false}, tooltip: {callbacks: {label: context => `${Number(context.raw).toLocaleString('pt-BR', {maximumFractionDigits: 2})}%`}}}, scales: {y: {beginAtZero: true, max: 100}}}});
    }
}());
