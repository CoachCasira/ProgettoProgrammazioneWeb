(function () {
    var liveSearchTimer = null;
    var lastRequestId = 0;
    var LIVE_DELAY_MS = 300;

    function buildRequest(form, extraFields) {
        var method = (form.getAttribute('method') || 'GET').toUpperCase();
        var formData = new FormData(form);
        var url = form.getAttribute('action') || window.location.href;
        var options = {
            method: method,
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        };

        if (extraFields) {
            Object.keys(extraFields).forEach(function (key) {
                formData.set(key, extraFields[key]);
            });
        }

        if (method === 'GET') {
            var separator = url.indexOf('?') === -1 ? '?' : '&';
            url += separator + new URLSearchParams(formData).toString();
        } else {
            options.body = formData;
        }

        return {
            url: url,
            options: options
        };
    }

    function setFormLoading(form, isLoading) {
        var submitButtons = form.querySelectorAll('button[type="submit"], input[type="submit"]');

        submitButtons.forEach(function (button) {
            button.disabled = isLoading;

            if (isLoading) {
                button.dataset.originalText = button.textContent;
                button.textContent = 'Caricamento...';
            } else if (button.dataset.originalText) {
                button.textContent = button.dataset.originalText;
                delete button.dataset.originalText;
            }
        });
    }

    function showAjaxError(target) {
        var message = document.createElement('div');
        message.className = 'alert alert-error';
        message.textContent = 'Operazione non completata. Controllare la connessione e riprovare.';
        target.innerHTML = '';
        target.appendChild(message);
    }

    function refreshDynamicBehaviors() {
        if (window.ProgWeb && typeof window.ProgWeb.initDynamicBehaviors === 'function') {
            window.ProgWeb.initDynamicBehaviors(document);
        }
    }

    function updateFromResponse(html, selector, scrollToTop) {
        var parser = new DOMParser();
        var doc = parser.parseFromString(html, 'text/html');
        var newBlock = doc.querySelector(selector);
        var currentBlock = document.querySelector(selector);

        if (!newBlock || !currentBlock) {
            return false;
        }

        if (selector === '.content') {
            currentBlock.innerHTML = newBlock.innerHTML;

            if (scrollToTop) {
                window.scrollTo({ top: 0, behavior: 'smooth' });
            }
        } else {
            currentBlock.innerHTML = newBlock.innerHTML;
            currentBlock.className = newBlock.className;
            Array.prototype.slice.call(newBlock.attributes).forEach(function (attribute) {
                currentBlock.setAttribute(attribute.name, attribute.value);
            });
        }

        refreshDynamicBehaviors();
        return true;
    }

    function submitAjaxForm(form, isLiveSearch) {
        var targetSelector = form.dataset.updateTarget || '.content';
        var target = document.querySelector(targetSelector);

        if (!target || !window.fetch || !window.DOMParser || !window.FormData) {
            form.submit();
            return;
        }

        var requestId = lastRequestId + 1;
        lastRequestId = requestId;

        var request = buildRequest(form);

        if (!isLiveSearch) {
            setFormLoading(form, true);
            target.classList.add('ajax-loading');
        }

        fetch(request.url, request.options)
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('Risposta non valida');
                }
                return response.text();
            })
            .then(function (html) {
                if (requestId !== lastRequestId) {
                    return;
                }

                var scrollToTop = !isLiveSearch && targetSelector === '.content';
                if (!updateFromResponse(html, targetSelector, scrollToTop)) {
                    showAjaxError(target);
                }
            })
            .catch(function () {
                if (requestId === lastRequestId) {
                    showAjaxError(target);
                }
            })
            .finally(function () {
                if (requestId === lastRequestId && !isLiveSearch) {
                    setFormLoading(form, false);
                    var refreshedTarget = document.querySelector(targetSelector);
                    if (refreshedTarget) {
                        refreshedTarget.classList.remove('ajax-loading');
                    }
                }
            });
    }

    function scheduleLiveSearch(form, delay) {
        if (liveSearchTimer) {
            clearTimeout(liveSearchTimer);
        }

        liveSearchTimer = setTimeout(function () {
            submitAjaxForm(form, true);
        }, typeof delay === 'number' ? delay : LIVE_DELAY_MS);
    }

    function loadMoreRows(container) {
        if (container.dataset.loadingRows === 'true' || container.dataset.hasMore !== '1') {
            return;
        }

        var formSelector = container.dataset.lazyForm;
        var form = formSelector ? document.querySelector(formSelector) : null;
        var tbody = container.querySelector('tbody');

        if (!form || !tbody || !window.fetch || !window.FormData) {
            return;
        }

        container.dataset.loadingRows = 'true';
        container.classList.add('table-loading-more');

        var limit = container.dataset.limit || '50';
        var offset = container.dataset.nextOffset || '0';
        var request = buildRequest(form, {
            ajax_rows: '1',
            offset: offset,
            limit: limit
        });

        fetch(request.url, request.options)
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('Risposta non valida');
                }
                return response.json();
            })
            .then(function (payload) {
                if (payload && payload.html) {
                    tbody.insertAdjacentHTML('beforeend', payload.html);
                }
                container.dataset.hasMore = payload && payload.has_more ? '1' : '0';
                container.dataset.nextOffset = payload && payload.next_offset ? String(payload.next_offset) : container.dataset.nextOffset;
            })
            .catch(function () {
                container.dataset.hasMore = '0';
            })
            .finally(function () {
                container.dataset.loadingRows = 'false';
                container.classList.remove('table-loading-more');
            });
    }

    function initLazyTables(root) {
        var scope = root || document;
        scope.querySelectorAll('.table-container[data-lazy-container="true"]:not([data-lazy-ready="true"])').forEach(function (container) {
            container.dataset.lazyReady = 'true';
            container.addEventListener('scroll', function () {
                var distanceFromBottom = container.scrollHeight - container.scrollTop - container.clientHeight;
                if (distanceFromBottom < 160) {
                    loadMoreRows(container);
                }
            });
        });
    }

    document.addEventListener('submit', function (event) {
        var form = event.target;

        if (!(form instanceof HTMLFormElement)) {
            return;
        }

        var isAjaxForm = form.matches('form[data-ajax-form="true"], form[data-ajax-content="true"]');
        if (!isAjaxForm || !window.fetch || !window.DOMParser) {
            return;
        }

        event.preventDefault();
        submitAjaxForm(form, false);
    });

    document.addEventListener('input', function (event) {
        var field = event.target;

        if (!(field instanceof HTMLInputElement)) {
            return;
        }

        var form = field.closest('form[data-ajax-form="true"][data-live-search="true"]');
        if (!form) {
            return;
        }

        var type = (field.getAttribute('type') || 'text').toLowerCase();
        if (type === 'text' || type === 'search' || type === 'number') {
            scheduleLiveSearch(form);
        }
    });

    document.addEventListener('change', function (event) {
        var field = event.target;

        if (!(field instanceof HTMLInputElement || field instanceof HTMLSelectElement)) {
            return;
        }

        var form = field.closest('form[data-ajax-form="true"][data-live-search="true"]');
        if (!form) {
            return;
        }

        if (field instanceof HTMLSelectElement) {
            scheduleLiveSearch(form, 120);
            return;
        }

        var type = (field.getAttribute('type') || '').toLowerCase();
        if (type === 'date' || type === 'time') {
            scheduleLiveSearch(form, 120);
        }
    });

    window.ProgWeb = window.ProgWeb || {};
    window.ProgWeb.initLazyTables = initLazyTables;
    document.addEventListener('DOMContentLoaded', function () {
        initLazyTables(document);
    });
}());
