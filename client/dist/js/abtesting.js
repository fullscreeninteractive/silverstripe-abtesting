/**
 * Frontend helpers for fullscreeninteractive/silverstripe-abtesting.
 *
 * window.SSABTesting.assignment — current page assignment (if any)
 * window.SSABTesting.trackConversion(experimentKey, options)
 * window.SSABTesting.onEvent(callback) — subscribe to local exposure/conversion events
 */
(function (window, document) {
    'use strict';

    var api = window.SSABTesting = window.SSABTesting || {};
    var listeners = [];

    api.onEvent = function (callback) {
        if (typeof callback === 'function') {
            listeners.push(callback);
        }
    };

    function emit(type, detail) {
        listeners.forEach(function (callback) {
            try {
                callback(type, detail);
            } catch (e) {
                // Swallow listener errors so tracking never breaks the page.
            }
        });

        if (typeof window.CustomEvent === 'function') {
            document.dispatchEvent(new CustomEvent('ssabtesting:' + type, { detail: detail }));
        }
    }

    api.trackConversion = function (experiment, options) {
        options = options || {};
        var body = {
            experiment: experiment,
            assertiveness: typeof options.assertiveness === 'number' ? options.assertiveness : 100,
            custom: options.custom || null
        };

        return fetch('/abtesting/convert', {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify(body)
        }).then(function (response) {
            return response.json().then(function (data) {
                if (response.ok) {
                    emit('conversion', data);
                }
                return data;
            });
        });
    };

    if (api.assignment) {
        emit('exposure', api.assignment);
    }

    // Optional: declare conversions via data attributes.
    document.addEventListener('click', function (event) {
        var target = event.target.closest('[data-ab-convert]');
        if (!target) {
            return;
        }

        var experiment = target.getAttribute('data-ab-convert');
        if (!experiment) {
            return;
        }

        api.trackConversion(experiment, {
            assertiveness: parseInt(target.getAttribute('data-ab-assertiveness') || '100', 10)
        });
    });
})(window, document);
