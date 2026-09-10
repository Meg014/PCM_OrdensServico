(() => {
    'use strict';

    const root = document.documentElement;
    const toggle = document.querySelector('[data-pcm-theme-toggle]');
    const icon = toggle?.querySelector('[data-pcm-theme-icon]');

    const chartColors = () => {
        const styles = getComputedStyle(root);
        return {
            text: styles.getPropertyValue('--chart-text').trim(),
            grid: styles.getPropertyValue('--chart-grid').trim(),
            tooltip: styles.getPropertyValue('--chart-tooltip').trim(),
            tooltipText: styles.getPropertyValue('--chart-tooltip-text').trim(),
        };
    };

    const refreshCharts = () => {
        if (typeof Chart === 'undefined') {
            return;
        }
        const colors = chartColors();
        Chart.defaults.color = colors.text;
        Chart.defaults.borderColor = colors.grid;
        Object.values(Chart.instances).forEach((chart) => {
            Object.values(chart.options.scales || {}).forEach((scale) => {
                scale.ticks = {...(scale.ticks || {}), color: colors.text};
                scale.grid = {...(scale.grid || {}), color: colors.grid};
                scale.border = {...(scale.border || {}), color: colors.grid};
                if (scale.title) {
                    scale.title.color = colors.text;
                }
            });
            const plugins = chart.options.plugins || (chart.options.plugins = {});
            const legend = plugins.legend || (plugins.legend = {});
            legend.labels = {...(legend.labels || {}), color: colors.text};
            const tooltip = plugins.tooltip || (plugins.tooltip = {});
            tooltip.backgroundColor = colors.tooltip;
            tooltip.titleColor = colors.tooltipText;
            tooltip.bodyColor = colors.tooltipText;
            chart.update('none');
        });
    };

    const updateControl = (theme) => {
        if (!toggle || !icon) {
            return;
        }
        const dark = theme === 'dark';
        const label = dark ? 'Ativar tema claro' : 'Ativar tema escuro';
        toggle.title = label;
        toggle.setAttribute('aria-label', label);
        icon.textContent = dark ? '☀' : '☾';
    };

    const applyTheme = (theme, persist = false) => {
        root.dataset.theme = theme;
        root.dataset.bsTheme = theme;
        if (persist) {
            try {
                localStorage.setItem('pcm-theme', theme);
            } catch (_error) {
                // The selected theme still applies when browser storage is unavailable.
            }
        }
        updateControl(theme);
        refreshCharts();
        window.dispatchEvent(new CustomEvent('pcm:themechange', {detail: {theme}}));
    };

    const initialTheme = root.dataset.theme === 'light' ? 'light' : 'dark';
    updateControl(initialTheme);
    refreshCharts();

    toggle?.addEventListener('click', () => {
        applyTheme(root.dataset.theme === 'dark' ? 'light' : 'dark', true);
    });
})();
