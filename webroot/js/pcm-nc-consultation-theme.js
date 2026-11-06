(() => {
    'use strict';
    let theme = 'light';
    try { theme = localStorage.getItem('pcm-theme') === 'dark' ? 'dark' : 'light'; } catch (_) { /* Keep the PCM default. */ }
    document.documentElement.dataset.theme = theme;
    document.documentElement.dataset.bsTheme = theme;
})();
