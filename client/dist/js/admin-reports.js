(function () {
    'use strict';

    function initCharts() {
        if (typeof window.Chart === 'undefined') {
            return;
        }

        document.querySelectorAll('canvas[data-abtesting-chart]').forEach(function (canvas) {
            if (canvas.dataset.abtestingReady) {
                return;
            }

            try {
                var config = JSON.parse(canvas.getAttribute('data-abtesting-chart'));
                // Assign colours if Chart.js defaults are enough; still set a readable borderWidth.
                (config.data.datasets || []).forEach(function (dataset) {
                    dataset.borderWidth = dataset.borderWidth || 2;
                    dataset.fill = false;
                });
                // eslint-disable-next-line no-new
                new window.Chart(canvas.getContext('2d'), config);
                canvas.dataset.abtestingReady = '1';
            } catch (e) {
                // Invalid payload — leave the canvas empty.
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initCharts);
    } else {
        initCharts();
    }

    // CMS tab switches may reveal charts after first paint.
    window.setTimeout(initCharts, 500);
})();
