(() => {
    'use strict';

    const marker = document.querySelector('[data-pcm-current-version]');
    if (!marker || marker.dataset.autoRefreshInitialized === 'true') {
        return;
    }

    marker.dataset.autoRefreshInitialized = 'true';
    const versionUrl = marker.dataset.versionUrl;
    let currentImportId = marker.dataset.currentImportId || null;
    let requestInProgress = false;

    const checkCurrentVersion = async () => {
        if (requestInProgress) {
            return;
        }

        requestInProgress = true;
        try {
            const response = await fetch(versionUrl, {
                cache: 'no-store',
                headers: {'Accept': 'application/json'},
            });
            if (!response.ok) {
                return;
            }

            const version = await response.json();
            if (!version || !Object.prototype.hasOwnProperty.call(version, 'import_id')) {
                return;
            }
            const nextImportId = version.import_id === null ? null : String(version.import_id);
            if (nextImportId !== currentImportId) {
                window.location.reload();
            }
        } catch (_error) {
            // A temporary endpoint or network failure is retried on the next interval.
        } finally {
            requestInProgress = false;
        }
    };

    window.setInterval(checkCurrentVersion, 30_000);
})();
