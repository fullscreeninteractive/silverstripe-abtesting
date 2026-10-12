(function () {
    'use strict';

    function initCharts(root) {
        if (typeof window.Chart === 'undefined') {
            return;
        }

        var scope = root && root.querySelectorAll ? root : document;
        scope.querySelectorAll('canvas[data-abtesting-chart]').forEach(function (canvas) {
            if (canvas.dataset.abtestingReady) {
                return;
            }

            // Skip canvases in collapsed CMS tabs until they become visible.
            if (canvas.offsetParent === null && canvas.offsetWidth === 0) {
                return;
            }

            try {
                var config = JSON.parse(canvas.getAttribute('data-abtesting-chart'));
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

    function bindTabListeners() {
        document.addEventListener('click', function (event) {
            var tab = event.target.closest('.ui-tabs-anchor, [role="tab"], .ss-tabset a');
            if (!tab) {
                return;
            }
            window.setTimeout(function () {
                initCharts(document);
            }, 50);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            initCharts(document);
            bindTabListeners();
        });
    } else {
        initCharts(document);
        bindTabListeners();
    }

    // CMS React / legacy tab switches may reveal charts after first paint.
    window.setTimeout(function () {
        initCharts(document);
    }, 500);
    window.setTimeout(function () {
        initCharts(document);
    }, 1500);
})();
