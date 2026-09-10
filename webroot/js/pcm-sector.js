(function () {
    'use strict';
    const node = document.getElementById('pcm-chart-data');
    if (!node || typeof Chart === 'undefined') return;
    const themeStyles = getComputedStyle(document.documentElement);
    Chart.defaults.color = themeStyles.getPropertyValue('--chart-text').trim();
    Chart.defaults.borderColor = themeStyles.getPropertyValue('--chart-grid').trim();
    const data = JSON.parse(node.textContent);
    const create = (id, rows, color, filter, horizontal) => {
        const canvas = document.getElementById(id); if (!canvas) return;
        new Chart(canvas, {type: 'bar', data: {labels: rows.map(r => r.label), datasets: [{data: rows.map(r => r.quantity), backgroundColor: color, borderRadius: 5}]}, options: {responsive: true, maintainAspectRatio: false, indexAxis: horizontal ? 'y' : 'x', plugins: {legend: {display: false}, tooltip: {callbacks: {label: c => `${c.raw} OS (${Number(rows[c.dataIndex].percentage).toLocaleString('pt-BR', {maximumFractionDigits: 1})}%)`}}}, scales: {x: {beginAtZero: true, ticks: {precision: 0}}, y: {beginAtZero: true, ticks: {autoSkip: false}}}, onClick: (_event, elements) => { if (!filter || !elements.length) return; const url = new URL(window.location.href); url.searchParams.set(filter, rows[elements[0].index].key); url.searchParams.delete('page'); window.location.href = url.toString(); }}});
    };
    const statusColors = {'EM ABERTO': '#24527a', 'FECHADA': '#27ae60', 'CANCELADA': '#d64545'};
    create('statusChart', data.status, data.status.map(row => statusColors[row.key] || '#58708f'), 'status', false);
    create('maintenanceChart', data.maintenance, '#356796', null, false);
    create('equipmentChart', data.equipment, '#24527a', 'equipment', true);
    create('servicesChart', data.services, '#3c7a89', 'service', true);
    create('costCentersChart', data.costCenters, '#58708f', 'cost_center', true);
}());
