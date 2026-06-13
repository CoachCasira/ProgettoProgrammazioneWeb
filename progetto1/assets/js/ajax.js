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
            Array.prototype.slice.call(currentBlock.attributes).forEach(function (attribute) {
                if (!newBlock.hasAttribute(attribute.name)) {
                    currentBlock.removeAttribute(attribute.name);
                }
            });
            Array.prototype.slice.call(newBlock.attributes).forEach(function (attribute) {
                currentBlock.setAttribute(attribute.name, attribute.value);
            });
            currentBlock.removeAttribute('data-results-view-ready');
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

    function isContainerNearScrollBottom(container) {
        return container.scrollHeight - container.scrollTop - container.clientHeight < 180;
    }

    function isContainerNearScrollTop(container) {
        return container.scrollTop < 60;
    }

    function loadMoreRows(container, direction) {
        var loadDirection = direction === 'prev' ? 'prev' : 'next';
        var hasRows = loadDirection === 'prev' ? container.dataset.hasPrev === '1' : container.dataset.hasMore === '1';

        if (container.dataset.loadingRows === 'true' || !hasRows) {
            return;
        }

        var formSelector = container.dataset.lazyForm;
        var form = formSelector ? document.querySelector(formSelector) : null;
        var lists = container.querySelectorAll('[data-lazy-list]');

        if (!form || lists.length === 0 || !window.fetch || !window.FormData) {
            return;
        }

        container.dataset.loadingRows = 'true';
        container.classList.add('table-loading-more');

        var limit = parseInt(container.dataset.limit || '50', 10);
        if (!Number.isFinite(limit) || limit <= 0) {
            limit = 50;
        }

        var offset = 0;
        if (loadDirection === 'prev') {
            var currentPrevOffset = parseInt(container.dataset.prevOffset || '0', 10);
            if (!Number.isFinite(currentPrevOffset) || currentPrevOffset <= 0) {
                currentPrevOffset = 0;
            }
            offset = Math.max(0, currentPrevOffset - limit);
        } else {
            offset = parseInt(container.dataset.nextOffset || '0', 10);
            if (!Number.isFinite(offset) || offset < 0) {
                offset = 0;
            }
        }

        var oldScrollHeight = container.scrollHeight;
        var oldScrollTop = container.scrollTop;
        var request = buildRequest(form, {
            ajax_rows: '1',
            offset: String(offset),
            limit: String(limit),
            direction: loadDirection
        });

        fetch(request.url, request.options)
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('Risposta non valida');
                }
                return response.json();
            })
            .then(function (payload) {
                if (payload && (payload.html || payload.table_html)) {
                    lists.forEach(function (list) {
                        var listType = list.dataset.lazyList || 'cards';
                        var fragment = listType === 'table' ? (payload.table_html || '') : (payload.html || '');

                        if (!fragment) {
                            return;
                        }

                        if (loadDirection === 'prev') {
                            list.insertAdjacentHTML('afterbegin', fragment);
                        } else {
                            list.insertAdjacentHTML('beforeend', fragment);
                        }
                    });

                    if (loadDirection === 'prev') {
                        container.scrollTop = oldScrollTop + (container.scrollHeight - oldScrollHeight);
                    }
                    refreshDynamicBehaviors();
                }

                if (payload && Object.prototype.hasOwnProperty.call(payload, 'has_more')) {
                    container.dataset.hasMore = payload.has_more ? '1' : '0';
                }
                if (payload && Object.prototype.hasOwnProperty.call(payload, 'has_prev')) {
                    container.dataset.hasPrev = payload.has_prev ? '1' : '0';
                }
                if (payload && Object.prototype.hasOwnProperty.call(payload, 'next_offset')) {
                    container.dataset.nextOffset = String(payload.next_offset);
                }
                if (payload && Object.prototype.hasOwnProperty.call(payload, 'prev_offset')) {
                    container.dataset.prevOffset = String(payload.prev_offset);
                }
                if (payload && Object.prototype.hasOwnProperty.call(payload, 'total_count')) {
                    container.dataset.totalCount = String(payload.total_count);
                }

                var viewRoot = container.closest('[data-results-view-root="true"]');
                if (window.ProgWeb && typeof window.ProgWeb.updateResultsNavigation === 'function') {
                    window.ProgWeb.updateResultsNavigation(viewRoot);
                }
                if (window.ProgWeb && typeof window.ProgWeb.updateResultsScrollTopControl === 'function') {
                    window.ProgWeb.updateResultsScrollTopControl(viewRoot);
                }
            })
            .catch(function () {
                if (loadDirection === 'prev') {
                    container.dataset.hasPrev = '0';
                } else {
                    container.dataset.hasMore = '0';
                }
            })
            .finally(function () {
                container.dataset.loadingRows = 'false';
                container.classList.remove('table-loading-more');
            });
    }


    function updateContainerMetadataFromPayload(container, payload, fallbackLoadedCount) {
        if (!container || !payload) {
            return;
        }

        if (Object.prototype.hasOwnProperty.call(payload, 'has_more')) {
            container.dataset.hasMore = payload.has_more ? '1' : '0';
        }
        if (Object.prototype.hasOwnProperty.call(payload, 'has_prev')) {
            container.dataset.hasPrev = payload.has_prev ? '1' : '0';
        } else {
            container.dataset.hasPrev = '0';
        }
        if (Object.prototype.hasOwnProperty.call(payload, 'next_offset')) {
            container.dataset.nextOffset = String(payload.next_offset);
        } else if (typeof fallbackLoadedCount === 'number') {
            container.dataset.nextOffset = String(fallbackLoadedCount);
        }
        if (Object.prototype.hasOwnProperty.call(payload, 'prev_offset')) {
            container.dataset.prevOffset = String(payload.prev_offset);
        } else {
            container.dataset.prevOffset = '0';
        }
        if (Object.prototype.hasOwnProperty.call(payload, 'total_count')) {
            container.dataset.totalCount = String(payload.total_count);
        }
    }

    function resetResultsToFirstBlock(container) {
        var formSelector = container ? container.dataset.lazyForm : '';
        var form = formSelector ? document.querySelector(formSelector) : null;
        var lists = container ? container.querySelectorAll('[data-lazy-list]') : [];

        if (!container || !form || lists.length === 0 || !window.fetch || !window.FormData) {
            return Promise.resolve(false);
        }
        if (container.dataset.loadingRows === 'true' || container.dataset.resettingRows === 'true') {
            return Promise.resolve(false);
        }

        var limit = parseInt(container.dataset.limit || '50', 10);
        if (!Number.isFinite(limit) || limit <= 0) {
            limit = 50;
        }

        container.dataset.resettingRows = 'true';
        container.dataset.loadingRows = 'true';
        container.classList.add('table-loading-more', 'results-returning-top');

        var request = buildRequest(form, {
            ajax_rows: '1',
            offset: '0',
            limit: String(limit),
            direction: 'next'
        });

        return fetch(request.url, request.options)
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('Risposta non valida');
                }
                return response.json();
            })
            .then(function (payload) {
                if (!payload || (!payload.html && !payload.table_html)) {
                    return false;
                }

                lists.forEach(function (list) {
                    var listType = list.dataset.lazyList || 'cards';
                    var fragment = listType === 'table' ? (payload.table_html || '') : (payload.html || '');
                    list.innerHTML = fragment;
                });

                updateContainerMetadataFromPayload(container, payload, Math.max(0, lists[0] ? lists[0].children.length : 0));
                container.scrollTop = 0;
                refreshDynamicBehaviors();

                var viewRoot = container.closest('[data-results-view-root="true"]');
                if (window.ProgWeb && typeof window.ProgWeb.updateResultsNavigation === 'function') {
                    window.ProgWeb.updateResultsNavigation(viewRoot);
                }
                if (window.ProgWeb && typeof window.ProgWeb.updateResultsScrollTopControl === 'function') {
                    window.ProgWeb.updateResultsScrollTopControl(viewRoot);
                }
                return true;
            })
            .catch(function () {
                return false;
            })
            .finally(function () {
                container.dataset.resettingRows = 'false';
                container.dataset.loadingRows = 'false';
                container.classList.remove('table-loading-more', 'results-returning-top');
            });
    }

    function initLazyTables(root) {
        var scope = root || document;
        scope.querySelectorAll('[data-lazy-container="true"]:not([data-lazy-ready="true"])').forEach(function (container) {
            container.dataset.lazyReady = 'true';
            container.addEventListener('scroll', function () {
                if (container.dataset.loadingRows === 'true') {
                    return;
                }
                if (container.dataset.hasPrev === '1' && isContainerNearScrollTop(container)) {
                    loadMoreRows(container, 'prev');
                    return;
                }
                if (container.dataset.hasMore === '1' && isContainerNearScrollBottom(container)) {
                    loadMoreRows(container, 'next');
                }
            }, { passive: true });
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

        var submitter = event.submitter || document.activeElement;
        if (submitter && submitter.matches && submitter.matches('[data-export-submit="true"], button[name="export_csv"], input[name="export_csv"]')) {
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
    window.ProgWeb.loadMoreRows = loadMoreRows;
    window.ProgWeb.resetResultsToFirstBlock = resetResultsToFirstBlock;
    document.addEventListener('DOMContentLoaded', function () {
        initLazyTables(document);
    });
}());