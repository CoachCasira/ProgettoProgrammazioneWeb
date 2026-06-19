(function () {
    var liveSearchTimer = null;
    var lastRequestId = 0;
    var LIVE_DELAY_MS = 450;
    var activeSearchController = null;
    var activeCountController = null;
    var lastCountRequestId = 0;
    var COUNT_CACHE_PREFIX = 'progweb:telefonate-count:v60:';
    var firstBlockSnapshots = new WeakMap();
    var lastBlockSnapshots = new WeakMap();
    var lastBlockPrefetches = new WeakMap();

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

    function refreshDynamicBehaviors(root, options) {
        if (window.ProgWeb && typeof window.ProgWeb.initDynamicBehaviors === 'function') {
            window.ProgWeb.initDynamicBehaviors(root || document, options || {});
        }
    }

    function updateFromResponse(html, selector, scrollToTop, countPayload) {
        var parser = new DOMParser();
        var doc = parser.parseFromString(html, 'text/html');
        var newBlock = doc.querySelector(selector);
        var currentBlock = document.querySelector(selector);

        if (!newBlock || !currentBlock) {
            return false;
        }

        if (countPayload && Object.prototype.hasOwnProperty.call(countPayload, 'total_count')) {
            var newContainer = newBlock.querySelector('[data-lazy-container="true"]');
            var total = parseInt(countPayload.total_count, 10);
            if (newContainer && Number.isFinite(total) && total >= 0) {
                newContainer.dataset.totalCount = String(total);
                newContainer.dataset.countPending = '0';
            }
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

        /* La risposta AJAX contiene già i valori correnti del form.
           Inizializziamo i nuovi nodi senza rileggere lo stato salvato nella
           sessione: altrimenti il form appena sostituito si auto-invia di nuovo
           e genera un ciclo continuo di richieste GET. */
        refreshDynamicBehaviors(currentBlock, { fromAjaxUpdate: true });
        return true;
    }

    function buildCountCacheKey(form) {
        if (!form || form.id !== 'telefonate-filter' || !window.FormData) {
            return '';
        }

        var ignoredFields = {
            ajax_rows: true,
            count_only: true,
            direction: true,
            export_csv: true,
            export_excel: true,
            jump_last: true,
            limit: true,
            offset: true,
            ordine: true,
            reverse_offset: true,
            skip_count: true
        };
        var entries = [];
        var data = new FormData(form);

        data.forEach(function (value, key) {
            if (ignoredFields[key] || (typeof File !== 'undefined' && value instanceof File)) {
                return;
            }
            entries.push([key, String(value)]);
        });

        entries.sort(function (first, second) {
            var firstKey = first[0] + '\u0000' + first[1];
            var secondKey = second[0] + '\u0000' + second[1];
            return firstKey.localeCompare(secondKey);
        });

        return COUNT_CACHE_PREFIX + encodeURIComponent(JSON.stringify(entries));
    }

    function telefonateNeedsDeferredCount(form) {
        if (!form || form.id !== 'telefonate-filter') {
            return false;
        }

        return [
            'contratto',
            'stato_numero',
            'piano',
            'data_da',
            'data_a',
            'ora_da',
            'ora_a',
            'durata_ore',
            'durata_min',
            'durata_sec',
            'costo_max'
        ].some(function (name) {
            var field = form.elements.namedItem(name);
            return field && String(field.value || '').trim() !== '';
        });
    }

    function readCachedCount(form) {
        var key = buildCountCacheKey(form);
        if (!key || !window.sessionStorage) {
            return null;
        }

        try {
            var value = parseInt(window.sessionStorage.getItem(key) || '', 10);
            return Number.isFinite(value) && value >= 0 ? value : null;
        } catch (error) {
            return null;
        }
    }

    function storeCachedCount(form, total) {
        var key = buildCountCacheKey(form);
        if (!key || !window.sessionStorage || !Number.isFinite(total) || total < 0) {
            return;
        }

        try {
            window.sessionStorage.setItem(key, String(total));
        } catch (error) {
            // La cache è solo un'ottimizzazione: il conteggio continua a funzionare senza di essa.
        }
    }

    function applyDeferredCountPayload(form, targetSelector, payload, searchRequestId) {
        if ((searchRequestId && searchRequestId !== lastRequestId)
            || !payload
            || !Object.prototype.hasOwnProperty.call(payload, 'total_count')) {
            return false;
        }

        var refreshedTarget = document.querySelector(targetSelector);
        var refreshedContainer = refreshedTarget ? refreshedTarget.querySelector('[data-lazy-container="true"]') : null;
        if (!refreshedContainer) {
            return false;
        }

        var total = parseInt(payload.total_count, 10);
        if (!Number.isFinite(total) || total < 0) {
            return false;
        }

        refreshedContainer.dataset.totalCount = String(total);
        refreshedContainer.dataset.countPending = '0';
        storeCachedCount(form, total);

        var viewRoot = refreshedContainer.closest('[data-results-view-root="true"]');
        if (window.ProgWeb && typeof window.ProgWeb.updateResultsNavigation === 'function') {
            window.ProgWeb.updateResultsNavigation(viewRoot);
        }
        if (window.ProgWeb && typeof window.ProgWeb.prefetchLastResultsBlock === 'function') {
            window.ProgWeb.prefetchLastResultsBlock(refreshedContainer);
        }
        return true;
    }

    function requestDeferredCount(form, searchRequestId) {
        if (!form || form.id !== 'telefonate-filter' || !window.fetch || !window.FormData) {
            return Promise.resolve(null);
        }

        var cachedCount = readCachedCount(form);
        if (cachedCount !== null) {
            return Promise.resolve({ total_count: cachedCount, cached: true });
        }

        if (activeCountController && typeof activeCountController.abort === 'function') {
            activeCountController.abort();
        }
        activeCountController = typeof AbortController !== 'undefined' ? new AbortController() : null;
        var countRequestId = lastCountRequestId + 1;
        lastCountRequestId = countRequestId;

        var request = buildRequest(form, {
            count_only: '1',
            skip_count: '0'
        });
        if (activeCountController) {
            request.options.signal = activeCountController.signal;
        }

        return fetch(request.url, request.options)
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('Conteggio non disponibile');
                }
                return response.json();
            })
            .then(function (payload) {
                if (countRequestId !== lastCountRequestId || (searchRequestId && searchRequestId !== lastRequestId)) {
                    return null;
                }
                return payload;
            })
            .catch(function (error) {
                if (error && error.name === 'AbortError') {
                    return null;
                }
                // I risultati rimangono utilizzabili anche se il totale tarda o fallisce.
                return null;
            })
            .finally(function () {
                if (countRequestId === lastCountRequestId) {
                    activeCountController = null;
                }
            });
    }

    function refreshDeferredCount(form, targetSelector, searchRequestId, preparedPromise) {
        if (!form || form.id !== 'telefonate-filter' || !window.fetch || !window.FormData) {
            return;
        }

        var target = document.querySelector(targetSelector);
        var container = target ? target.querySelector('[data-lazy-container="true"][data-count-pending="1"]') : null;
        if (!container) {
            return;
        }

        var countPromise = preparedPromise || requestDeferredCount(form, searchRequestId);
        countPromise.then(function (payload) {
            applyDeferredCountPayload(form, targetSelector, payload, searchRequestId);
        });
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

        if (activeSearchController && typeof activeSearchController.abort === 'function') {
            activeSearchController.abort();
        }
        if (activeCountController && typeof activeCountController.abort === 'function') {
            activeCountController.abort();
            activeCountController = null;
        }
        activeSearchController = typeof AbortController !== 'undefined' ? new AbortController() : null;

        var request = buildRequest(form);
        if (activeSearchController) {
            request.options.signal = activeSearchController.signal;
        }

        /* Il COUNT parte insieme alla query del primo blocco, non dopo. In questo
           modo, con filtri complessi, il totale è spesso già pronto quando il DOM
           viene aggiornato e il contatore non passa visibilmente da "1-12" a
           "1-12 di ..." alcuni secondi più tardi. */
        var preparedCountPromise = telefonateNeedsDeferredCount(form)
            ? requestDeferredCount(form, requestId)
            : null;

        if (!isLiveSearch) {
            setFormLoading(form, true);
            target.classList.add('ajax-loading');
        }

        var rowsPromise = fetch(request.url, request.options)
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('Risposta non valida');
                }
                return response.text();
            });

        var completeResponsePromise = preparedCountPromise
            ? Promise.all([rowsPromise, preparedCountPromise]).then(function (values) {
                return { html: values[0], countPayload: values[1] };
            })
            : rowsPromise.then(function (html) {
                return { html: html, countPayload: null };
            });

        completeResponsePromise
            .then(function (result) {
                if (requestId !== lastRequestId) {
                    return;
                }

                var scrollToTop = !isLiveSearch && targetSelector === '.content';
                if (!updateFromResponse(result.html, targetSelector, scrollToTop, result.countPayload)) {
                    showAjaxError(target);
                    return;
                }
                if (result.countPayload && Object.prototype.hasOwnProperty.call(result.countPayload, 'total_count')) {
                    storeCachedCount(form, parseInt(result.countPayload.total_count, 10));
                } else {
                    refreshDeferredCount(form, targetSelector, requestId);
                }
                document.dispatchEvent(new CustomEvent('progweb:ajax-results-updated', {
                    detail: {
                        formId: form.id || '',
                        targetSelector: targetSelector,
                        liveSearch: Boolean(isLiveSearch)
                    }
                }));
            })
            .catch(function (error) {
                if (error && error.name === 'AbortError') {
                    return;
                }
                if (requestId === lastRequestId) {
                    showAjaxError(target);
                }
            })
            .finally(function () {
                if (requestId === lastRequestId) {
                    activeSearchController = null;
                }
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
        /* Avviamo il caricamento prima che l'utente raggiunga davvero il fondo:
           il blocco successivo arriva mentre sta ancora leggendo gli ultimi record
           visibili e lo scorrimento risulta molto più continuo. */
        var preloadDistance = Math.max(320, Math.round(container.clientHeight * 0.55));
        return container.scrollHeight - container.scrollTop - container.clientHeight < preloadDistance;
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
        /* Evitiamo di mostrare il messaggio per richieste rapide. Se la risposta
           impiega più di un istante, compare un indicatore compatto senza
           spostare il contenuto già visibile. */
        var loadingIndicatorTimer = window.setTimeout(function () {
            if (container.dataset.loadingRows === 'true') {
                container.classList.add('table-loading-more');
            }
        }, 520);

        var limit = parseInt(container.dataset.limit || '50', 10);
        if (!Number.isFinite(limit) || limit <= 0) {
            limit = 50;
        }

        var offset = 0;
        var fromEnd = loadDirection === 'prev' && container.dataset.fromEnd === '1';
        var reverseOffset = 0;
        if (fromEnd) {
            reverseOffset = parseInt(container.dataset.reverseOffset || '0', 10);
            if (!Number.isFinite(reverseOffset) || reverseOffset < 0) {
                reverseOffset = 0;
            }
        } else if (loadDirection === 'prev') {
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
        var oldScrollLeft = container.scrollLeft;

        /* Durante l'aggiunta di righe in cima manteniamo la posizione tramite
           la differenza di altezza del contenuto. L'operazione avviene nello
           stesso task dell'inserimento, prima del frame successivo: in questo
           modo la testata non lampeggia e la riga osservata resta immobile. */
        if (loadDirection === 'prev') {
            container.classList.add('results-preserving-scroll');
        }

        var request = buildRequest(form, {
            ajax_rows: '1',
            offset: String(offset),
            limit: String(limit),
            direction: loadDirection,
            skip_count: '1',
            jump_last: fromEnd ? '1' : '0',
            reverse_offset: String(reverseOffset)
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
                        var insertedHeight = container.scrollHeight - oldScrollHeight;
                        container.scrollTop = oldScrollTop + Math.max(0, insertedHeight);
                        container.scrollLeft = oldScrollLeft;

                        refreshDynamicBehaviors();

                        /* Se il collegamento dei comportamenti dinamici modifica di
                           pochi pixel l'altezza delle righe, compensiamo subito nello
                           stesso task, senza correzioni ritardate visibili. */
                        var settledHeight = container.scrollHeight - oldScrollHeight;
                        container.scrollTop = oldScrollTop + Math.max(0, settledHeight);
                        container.scrollLeft = oldScrollLeft;
                    } else {
                        refreshDynamicBehaviors();
                        container.scrollLeft = oldScrollLeft;
                    }
                }

                /* I metadati rappresentano l'intervallo complessivamente caricato,
                   non soltanto l'ultimo blocco ricevuto. Quando aggiungiamo righe in
                   fondo conserviamo l'offset iniziale; quando le aggiungiamo in cima
                   conserviamo l'offset finale. In questo modo il contatore non salta
                   erroneamente da 1-12 a 13-24 dopo un semplice scroll. */
                if (loadDirection === 'prev') {
                    if (payload && Object.prototype.hasOwnProperty.call(payload, 'has_prev')) {
                        container.dataset.hasPrev = payload.has_prev ? '1' : '0';
                    }
                    if (fromEnd) {
                        var total = parseInt(container.dataset.totalCount || '0', 10);
                        var consumedFromEnd = payload && Object.prototype.hasOwnProperty.call(payload, 'reverse_offset')
                            ? parseInt(payload.reverse_offset, 10)
                            : reverseOffset + (lists[0] ? lists[0].children.length : 0);
                        if (!Number.isFinite(consumedFromEnd) || consumedFromEnd < 0) {
                            consumedFromEnd = reverseOffset;
                        }
                        container.dataset.reverseOffset = String(consumedFromEnd);
                        if (Number.isFinite(total) && total > 0) {
                            container.dataset.prevOffset = String(Math.max(0, total - consumedFromEnd));
                            container.dataset.nextOffset = String(total);
                        }
                    } else if (payload && Object.prototype.hasOwnProperty.call(payload, 'prev_offset') && payload.prev_offset !== null) {
                        container.dataset.prevOffset = String(payload.prev_offset);
                    }
                } else {
                    if (payload && Object.prototype.hasOwnProperty.call(payload, 'has_more')) {
                        container.dataset.hasMore = payload.has_more ? '1' : '0';
                    }
                    if (payload && Object.prototype.hasOwnProperty.call(payload, 'next_offset')) {
                        container.dataset.nextOffset = String(payload.next_offset);
                    }
                }
                if (payload && Object.prototype.hasOwnProperty.call(payload, 'total_count')) {
                    container.dataset.totalCount = String(payload.total_count);
                }

                if (loadDirection === 'next') {
                    /* Disattiviamo qualsiasi scroll anchoring residuo del browser:
                       l'aggiunta del blocco successivo non deve spostare la riga che
                       l'utente stava osservando. */
                    container.scrollTop = oldScrollTop;
                    window.requestAnimationFrame(function () {
                        container.scrollTop = oldScrollTop;
                    });
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
                window.clearTimeout(loadingIndicatorTimer);
                container.dataset.loadingRows = 'false';
                container.classList.remove('table-loading-more', 'results-preserving-scroll');
            });
    }


    function collectLazyListFragments(lists) {
        var fragments = {};
        lists.forEach(function (list) {
            fragments[list.dataset.lazyList || 'cards'] = list.innerHTML;
        });
        return fragments;
    }

    function applyLazyListFragments(lists, fragments) {
        lists.forEach(function (list) {
            var listType = list.dataset.lazyList || 'cards';
            if (Object.prototype.hasOwnProperty.call(fragments, listType)) {
                list.innerHTML = fragments[listType];
            }
        });
    }

    function captureFirstBlockSnapshot(container) {
        if (!container
            || container.dataset.fromEnd === '1'
            || parseInt(container.dataset.prevOffset || '0', 10) !== 0) {
            return;
        }

        var lists = container.querySelectorAll('[data-lazy-list]');
        if (lists.length === 0) {
            return;
        }

        firstBlockSnapshots.set(container, {
            fragments: collectLazyListFragments(lists),
            nextOffset: container.dataset.nextOffset || String(lists[0].children.length),
            hasMore: container.dataset.hasMore === '1'
        });
    }

    function restoreFirstBlockSnapshot(container) {
        var snapshot = container ? firstBlockSnapshots.get(container) : null;
        var lists = container ? container.querySelectorAll('[data-lazy-list]') : [];
        if (!snapshot || lists.length === 0) {
            return false;
        }

        applyLazyListFragments(lists, snapshot.fragments);
        container.dataset.prevOffset = '0';
        container.dataset.nextOffset = String(snapshot.nextOffset);
        container.dataset.hasPrev = '0';
        container.dataset.hasMore = snapshot.hasMore ? '1' : '0';
        delete container.dataset.fromEnd;
        delete container.dataset.reverseOffset;
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
    }

    function applyLastBlockSnapshot(container) {
        var snapshot = container ? lastBlockSnapshots.get(container) : null;
        var lists = container ? container.querySelectorAll('[data-lazy-list]') : [];
        var total = container ? parseInt(container.dataset.totalCount || '0', 10) : 0;

        if (!snapshot
            || lists.length === 0
            || !Number.isFinite(total)
            || (snapshot.total !== null && snapshot.total !== total)) {
            return false;
        }

        applyLazyListFragments(lists, snapshot.fragments);
        var loadedCount = Math.max(0, lists[0] ? lists[0].children.length : 0);
        container.dataset.nextOffset = String(total);
        container.dataset.hasMore = '0';
        container.dataset.prevOffset = String(Math.max(0, total - loadedCount));
        container.dataset.hasPrev = total > loadedCount ? '1' : '0';
        container.dataset.fromEnd = '1';
        container.dataset.reverseOffset = String(loadedCount);

        refreshDynamicBehaviors();
        container.scrollTop = Math.max(0, container.scrollHeight - container.clientHeight);

        var viewRoot = container.closest('[data-results-view-root="true"]');
        if (window.ProgWeb && typeof window.ProgWeb.updateResultsNavigation === 'function') {
            window.ProgWeb.updateResultsNavigation(viewRoot);
        }
        if (window.ProgWeb && typeof window.ProgWeb.updateResultsScrollTopControl === 'function') {
            window.ProgWeb.updateResultsScrollTopControl(viewRoot);
        }
        return true;
    }

    function prefetchLastResultsBlock(container) {
        if (!container || !container.isConnected || !window.fetch || !window.FormData) {
            return Promise.resolve(false);
        }

        var viewRoot = container.closest('[data-results-view-root="true"]');
        var viewKey = viewRoot ? (viewRoot.dataset.viewKey || '') : '';
        if (viewKey !== 'telefonate') {
            return Promise.resolve(false);
        }

        var totalKnown = container.dataset.countPending !== '1' && container.dataset.totalCount !== '';
        var total = totalKnown ? parseInt(container.dataset.totalCount || '0', 10) : null;
        var limit = parseInt(container.dataset.limit || '50', 10);
        if ((totalKnown && (!Number.isFinite(total) || total <= 0)) || container.dataset.hasMore !== '1') {
            return Promise.resolve(false);
        }
        if (!Number.isFinite(limit) || limit <= 0) {
            limit = 50;
        }

        var cached = lastBlockSnapshots.get(container);
        if (cached && (cached.total === null || total === null || cached.total === total)) {
            return Promise.resolve(true);
        }

        var pending = lastBlockPrefetches.get(container);
        if (pending) {
            return pending;
        }

        var formSelector = container.dataset.lazyForm || '';
        var form = formSelector ? document.querySelector(formSelector) : null;
        if (!form) {
            return Promise.resolve(false);
        }

        var request = buildRequest(form, {
            ajax_rows: '1',
            offset: '0',
            limit: String(limit),
            direction: 'next',
            skip_count: '1',
            jump_last: '1',
            reverse_offset: '0'
        });

        var prefetchPromise = fetch(request.url, request.options)
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('Ultimo blocco non disponibile');
                }
                return response.json();
            })
            .then(function (payload) {
                if (!container.isConnected || !payload || (!payload.html && !payload.table_html)) {
                    return false;
                }

                lastBlockSnapshots.set(container, {
                    total: total,
                    fragments: {
                        cards: payload.html || '',
                        table: payload.table_html || ''
                    }
                });
                return true;
            })
            .catch(function () {
                return false;
            })
            .finally(function () {
                lastBlockPrefetches.delete(container);
            });

        lastBlockPrefetches.set(container, prefetchPromise);
        return prefetchPromise;
    }


    function jumpResultsToLastBlock(container) {
        var formSelector = container ? container.dataset.lazyForm : '';
        var form = formSelector ? document.querySelector(formSelector) : null;
        var lists = container ? container.querySelectorAll('[data-lazy-list]') : [];

        if (!container || !form || lists.length === 0 || !window.fetch || !window.FormData) {
            return Promise.resolve(false);
        }
        if (container.dataset.loadingRows === 'true' || container.dataset.jumpingLast === 'true') {
            return Promise.resolve(false);
        }

        var total = parseInt(container.dataset.totalCount || '0', 10);
        var limit = parseInt(container.dataset.limit || '50', 10);
        if (!Number.isFinite(total) || total <= 0) {
            return Promise.resolve(false);
        }
        if (!Number.isFinite(limit) || limit <= 0) {
            limit = 50;
        }

        var lastOffset = Math.max(0, total - limit);
        var viewRoot = container.closest('[data-results-view-root="true"]');
        var viewKey = viewRoot ? (viewRoot.dataset.viewKey || '') : '';
        /* La tabella Telefonata contiene milioni di righe e usa la lettura
           inversa indicizzata per raggiungere la fine senza un OFFSET enorme.
           Numeri telefonici e SIM, invece, devono usare l'ordinamento normale
           con l'offset reale dell'ultimo blocco: in questo modo l'ultima pagina
           contiene davvero i numeri disattivati e le SIM disattivate, anziche
           riproporre il primo blocco marcandolo erroneamente come ultimo. */
        var useReverseLastPage = viewKey === 'telefonate';

        if (useReverseLastPage && applyLastBlockSnapshot(container)) {
            return Promise.resolve(true);
        }

        var pendingLastBlock = useReverseLastPage ? lastBlockPrefetches.get(container) : null;
        if (pendingLastBlock) {
            return pendingLastBlock.then(function () {
                if (applyLastBlockSnapshot(container)) {
                    return true;
                }
                return jumpResultsToLastBlock(container);
            });
        }

        container.dataset.jumpingLast = 'true';
        container.dataset.loadingRows = 'true';
        container.classList.add('table-loading-more', 'results-jumping-last');

        var request = buildRequest(form, {
            ajax_rows: '1',
            offset: useReverseLastPage ? '0' : String(lastOffset),
            limit: String(limit),
            direction: 'next',
            skip_count: '1',
            jump_last: useReverseLastPage ? '1' : '0',
            reverse_offset: '0'
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

                if (useReverseLastPage) {
                    lastBlockSnapshots.set(container, {
                        total: total,
                        fragments: {
                            cards: payload.html || '',
                            table: payload.table_html || ''
                        }
                    });
                }

                lists.forEach(function (list) {
                    var listType = list.dataset.lazyList || 'cards';
                    var fragment = listType === 'table' ? (payload.table_html || '') : (payload.html || '');
                    list.innerHTML = fragment;
                });

                var loadedCount = Math.max(0, lists[0] ? lists[0].children.length : 0);
                updateContainerMetadataFromPayload(container, payload, loadedCount);
                container.dataset.nextOffset = String(total);
                container.dataset.hasMore = '0';

                if (useReverseLastPage) {
                    /* Telefonate: proseguiamo a ritroso dalla coda, evitando
                       OFFSET di milioni di righe durante la risalita. */
                    container.dataset.prevOffset = String(Math.max(0, total - loadedCount));
                    container.dataset.hasPrev = total > loadedCount ? '1' : '0';
                    container.dataset.fromEnd = '1';
                    container.dataset.reverseOffset = String(loadedCount);
                } else {
                    /* Numeri e SIM: l'ultimo blocco e stato richiesto mediante
                       il suo offset reale e rimane nell'ordinamento naturale.
                       La risalita puo quindi usare la normale paginazione. */
                    container.dataset.prevOffset = String(lastOffset);
                    container.dataset.hasPrev = lastOffset > 0 ? '1' : '0';
                    delete container.dataset.fromEnd;
                    delete container.dataset.reverseOffset;
                }
                refreshDynamicBehaviors();

                container.scrollTop = Math.max(0, container.scrollHeight - container.clientHeight);
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
                container.dataset.jumpingLast = 'false';
                container.dataset.loadingRows = 'false';
                container.classList.remove('table-loading-more', 'results-jumping-last');
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

        var cachedPageScrollX = window.pageXOffset || document.documentElement.scrollLeft || 0;
        var cachedPageScrollY = window.pageYOffset || document.documentElement.scrollTop || 0;
        if (restoreFirstBlockSnapshot(container)) {
            window.scrollTo(cachedPageScrollX, cachedPageScrollY);
            window.requestAnimationFrame(function () {
                container.scrollTop = 0;
                window.scrollTo(cachedPageScrollX, cachedPageScrollY);
            });
            return Promise.resolve(true);
        }

        var limit = parseInt(container.dataset.limit || '50', 10);
        if (!Number.isFinite(limit) || limit <= 0) {
            limit = 50;
        }

        /* Conserviamo la posizione della pagina: sostituire il blocco mentre il
           contenitore è in fondo può attivare lo scroll anchoring del browser e
           spostare l'intera pagina. */
        var pageScrollX = window.pageXOffset || document.documentElement.scrollLeft || 0;
        var pageScrollY = window.pageYOffset || document.documentElement.scrollTop || 0;

        container.dataset.resettingRows = 'true';
        container.dataset.loadingRows = 'true';
        container.dataset.suppressLazyUntil = String(Date.now() + 1200);
        delete container.dataset.fromEnd;
        delete container.dataset.reverseOffset;
        container.classList.add('table-loading-more', 'results-returning-top');

        var request = buildRequest(form, {
            ajax_rows: '1',
            offset: '0',
            limit: String(limit),
            direction: 'next',
            skip_count: '1',
            jump_last: '0',
            reverse_offset: '0'
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

                var loadedCount = Math.max(0, lists[0] ? lists[0].children.length : 0);

                /* Il salto al primo blocco deve sempre azzerare gli offset. Non ci
                   affidiamo ai metadati precedenti, che appartenevano all'ultimo
                   blocco e potevano far apparire 13-24 dopo il ritorno. */
                container.dataset.prevOffset = '0';
                container.dataset.nextOffset = String(loadedCount);
                container.dataset.hasPrev = '0';
                if (Object.prototype.hasOwnProperty.call(payload, 'has_more')) {
                    container.dataset.hasMore = payload.has_more ? '1' : '0';
                } else {
                    container.dataset.hasMore = loadedCount >= limit ? '1' : '0';
                }
                if (Object.prototype.hasOwnProperty.call(payload, 'total_count')) {
                    container.dataset.totalCount = String(payload.total_count);
                }

                container.scrollTop = 0;
                refreshDynamicBehaviors();
                captureFirstBlockSnapshot(container);

                var viewRoot = container.closest('[data-results-view-root="true"]');
                var stabilizeAtTop = function () {
                    container.scrollTop = 0;
                    window.scrollTo(pageScrollX, pageScrollY);
                    if (window.ProgWeb && typeof window.ProgWeb.updateResultsNavigation === 'function') {
                        window.ProgWeb.updateResultsNavigation(viewRoot);
                    }
                    if (window.ProgWeb && typeof window.ProgWeb.updateResultsScrollTopControl === 'function') {
                        window.ProgWeb.updateResultsScrollTopControl(viewRoot);
                    }
                };

                window.requestAnimationFrame(function () {
                    stabilizeAtTop();
                    window.requestAnimationFrame(stabilizeAtTop);
                });
                window.setTimeout(stabilizeAtTop, 120);
                return true;
            })
            .catch(function () {
                return false;
            })
            .finally(function () {
                container.dataset.resettingRows = 'false';
                container.dataset.loadingRows = 'false';
                container.classList.remove('table-loading-more', 'results-returning-top');
                window.setTimeout(function () {
                    delete container.dataset.suppressLazyUntil;
                }, 950);
            });
    }


    function restoreResultsBlock(container, position) {
        var formSelector = container ? container.dataset.lazyForm : '';
        var form = formSelector ? document.querySelector(formSelector) : null;
        var lists = container ? container.querySelectorAll('[data-lazy-list]') : [];

        if (!container || !form || lists.length === 0 || !window.fetch || !window.FormData) {
            return Promise.resolve({ ok: false, startOffset: 0 });
        }
        if (container.dataset.loadingRows === 'true') {
            return Promise.resolve({ ok: false, startOffset: parseInt(container.dataset.prevOffset || '0', 10) || 0 });
        }

        var anchorOffset = Math.max(0, parseInt((position && position.anchorOffset) || '0', 10) || 0);
        var limit = parseInt(container.dataset.limit || '50', 10);
        if (!Number.isFinite(limit) || limit <= 0) {
            limit = 50;
        }

        var total = parseInt(container.dataset.totalCount || ((position && position.totalCount) || '0'), 10);
        if (!Number.isFinite(total) || total < 0) {
            total = 0;
        }

        var viewRoot = container.closest('[data-results-view-root="true"]');
        var viewKey = viewRoot ? (viewRoot.dataset.viewKey || '') : '';
        var useReverse = viewKey === 'telefonate' && position && position.fromEnd && total > 0;
        var reverseOffset = useReverse ? Math.max(0, total - anchorOffset - limit) : 0;

        container.dataset.loadingRows = 'true';
        container.dataset.restoringSessionRows = 'true';
        container.dataset.suppressLazyUntil = String(Date.now() + 1200);
        container.classList.add('table-loading-more', 'results-restoring-session');

        var request = buildRequest(form, {
            ajax_rows: '1',
            offset: useReverse ? '0' : String(anchorOffset),
            limit: String(limit),
            direction: 'next',
            skip_count: '1',
            jump_last: useReverse ? '1' : '0',
            reverse_offset: String(reverseOffset)
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
                    return { ok: false, startOffset: anchorOffset };
                }

                lists.forEach(function (list) {
                    var listType = list.dataset.lazyList || 'cards';
                    var fragment = listType === 'table' ? (payload.table_html || '') : (payload.html || '');
                    list.innerHTML = fragment;
                });

                var loadedCount = Math.max(0, lists[0] ? lists[0].children.length : 0);
                var startOffset;

                if (useReverse) {
                    startOffset = Math.max(0, total - reverseOffset - loadedCount);
                    container.dataset.prevOffset = String(startOffset);
                    container.dataset.nextOffset = String(Math.min(total, startOffset + loadedCount));
                    container.dataset.hasPrev = payload.has_prev ? '1' : (startOffset > 0 ? '1' : '0');
                    container.dataset.hasMore = startOffset + loadedCount < total ? '1' : '0';
                    container.dataset.fromEnd = '1';
                    container.dataset.reverseOffset = String(
                        Object.prototype.hasOwnProperty.call(payload, 'reverse_offset')
                            ? payload.reverse_offset
                            : reverseOffset + loadedCount
                    );
                } else {
                    startOffset = Object.prototype.hasOwnProperty.call(payload, 'prev_offset') && payload.prev_offset !== null
                        ? Math.max(0, parseInt(payload.prev_offset, 10) || 0)
                        : anchorOffset;
                    container.dataset.prevOffset = String(startOffset);
                    container.dataset.nextOffset = String(
                        Object.prototype.hasOwnProperty.call(payload, 'next_offset') && payload.next_offset !== null
                            ? payload.next_offset
                            : startOffset + loadedCount
                    );
                    container.dataset.hasPrev = payload.has_prev ? '1' : (startOffset > 0 ? '1' : '0');
                    container.dataset.hasMore = payload.has_more ? '1' : '0';
                    delete container.dataset.fromEnd;
                    delete container.dataset.reverseOffset;
                }

                if (Object.prototype.hasOwnProperty.call(payload, 'total_count')) {
                    container.dataset.totalCount = String(payload.total_count);
                } else if (total > 0) {
                    container.dataset.totalCount = String(total);
                }

                container.scrollTop = 0;
                refreshDynamicBehaviors();

                if (window.ProgWeb && typeof window.ProgWeb.updateResultsNavigation === 'function') {
                    window.ProgWeb.updateResultsNavigation(viewRoot);
                }
                if (window.ProgWeb && typeof window.ProgWeb.updateResultsScrollTopControl === 'function') {
                    window.ProgWeb.updateResultsScrollTopControl(viewRoot);
                }

                return { ok: true, startOffset: startOffset };
            })
            .catch(function () {
                return { ok: false, startOffset: anchorOffset };
            })
            .finally(function () {
                container.dataset.loadingRows = 'false';
                container.dataset.restoringSessionRows = 'false';
                container.classList.remove('table-loading-more', 'results-restoring-session');
                window.setTimeout(function () {
                    delete container.dataset.suppressLazyUntil;
                }, 900);
            });
    }

    function initLazyTables(root) {
        var scope = root || document;
        scope.querySelectorAll('[data-lazy-container="true"]:not([data-lazy-ready="true"])').forEach(function (container) {
            container.dataset.lazyReady = 'true';
            captureFirstBlockSnapshot(container);

            window.setTimeout(function () {
                prefetchLastResultsBlock(container);
            }, 180);

            container.addEventListener('scroll', function () {
                var suppressLazyUntil = parseInt(container.dataset.suppressLazyUntil || '0', 10);
                if (container.dataset.loadingRows === 'true' || (Number.isFinite(suppressLazyUntil) && Date.now() < suppressLazyUntil)) {
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
        if (type === 'date' || type === 'time'
                || field.hasAttribute('data-custom-date-value')
                || field.hasAttribute('data-custom-time-value')) {
            scheduleLiveSearch(form, 120);
        }
    });

    window.ProgWeb = window.ProgWeb || {};
    window.ProgWeb.initLazyTables = initLazyTables;
    window.ProgWeb.loadMoreRows = loadMoreRows;
    window.ProgWeb.resetResultsToFirstBlock = resetResultsToFirstBlock;
    window.ProgWeb.jumpResultsToLastBlock = jumpResultsToLastBlock;
    window.ProgWeb.restoreResultsBlock = restoreResultsBlock;
    window.ProgWeb.prefetchLastResultsBlock = prefetchLastResultsBlock;
    document.addEventListener('DOMContentLoaded', function () {
        initLazyTables(document);
        var form = document.querySelector('#telefonate-filter');
        var targetSelector = form ? (form.dataset.updateTarget || '.content') : '';
        if (form && targetSelector) {
            refreshDeferredCount(form, targetSelector, 0);
        }
    });
}());