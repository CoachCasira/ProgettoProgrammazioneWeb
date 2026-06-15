(function () {

    function updateStickyLayout() {
        var nav = document.querySelector('.main-nav');
        var searchFilter = document.querySelector('.sticky-data-panel > .search-filter') || document.querySelector('.search-filter');
        var navHeight = nav ? Math.ceil(nav.getBoundingClientRect().height) : 0;
        var filterHeight = searchFilter ? Math.ceil(searchFilter.getBoundingClientRect().height) : 0;

        if (navHeight > 0) {
            document.documentElement.style.setProperty('--nav-sticky-height', navHeight + 'px');
        }
        if (filterHeight > 0) {
            document.documentElement.style.setProperty('--search-filter-sticky-height', filterHeight + 'px');
        }
    }

    function getPageScrollOffset() {
        var nav = document.querySelector('.main-nav');
        var navHeight = nav ? Math.ceil(nav.getBoundingClientRect().height) : 0;
        return navHeight + 18;
    }

    function scrollPageToElement(element, behavior) {
        if (!element) {
            return;
        }

        var rect = element.getBoundingClientRect();
        var targetTop = window.pageYOffset + rect.top - getPageScrollOffset();
        window.scrollTo({
            top: Math.max(0, targetTop),
            behavior: behavior || 'smooth'
        });
    }

    function schedulePageScrollToElement(element, behavior) {
        if (!element) {
            return;
        }

        window.requestAnimationFrame(function () {
            scrollPageToElement(element, behavior || 'auto');
        });
        window.setTimeout(function () {
            scrollPageToElement(element, behavior || 'auto');
        }, 180);
        window.setTimeout(function () {
            scrollPageToElement(element, behavior || 'smooth');
        }, 520);
    }

    function initPageAutoFocus(root) {
        var scope = root || document;
        scope.querySelectorAll('[data-page-auto-focus]:not([data-page-auto-focus-ready="true"])').forEach(function (target) {
            target.dataset.pageAutoFocusReady = 'true';
            schedulePageScrollToElement(target, 'auto');
        });
    }

    function initAlerts(root) {
        var scope = root || document;
        scope.querySelectorAll('.alert-success:not([data-alert-ready="true"])').forEach(function (message) {
            message.dataset.alertReady = 'true';
            window.setTimeout(function () {
                message.classList.add('alert-soft-hidden');
                window.setTimeout(function () {
                    if (message.parentNode) {
                        message.remove();
                        updateStickyLayout();
                    }
                }, 450);
            }, 6000);
        });
    }

    function getSelectedSimStates(form) {
        var select = form.querySelector('[data-sim-state-select]');
        if (select) {
            if (select.value === 'attive' || select.value === 'disponibili' || select.value === 'disattive') {
                return [select.value];
            }
            return ['attive', 'disponibili', 'disattive'];
        }

        var checkboxes = Array.prototype.slice.call(form.querySelectorAll('[data-sim-state-checkbox]'));
        var selected = checkboxes.filter(function (checkbox) {
            return checkbox.checked;
        }).map(function (checkbox) {
            return checkbox.value;
        });

        if (selected.length === 0 && checkboxes.length > 0) {
            return ['attive', 'disponibili', 'disattive'];
        }

        return selected;
    }

    function simStatesKey(states) {
        if (!Array.isArray(states) || states.length !== 1) {
            return 'tutte';
        }
        return states[0] || 'tutte';
    }

    function simStatesLabel(states) {
        var labels = {
            attive: 'SIM in uso',
            disponibili: 'SIM disponibili',
            disattive: 'SIM disattivate'
        };
        if (!Array.isArray(states) || states.length === 0 || states.length === 3) {
            return 'Mostra tutte';
        }
        return states.map(function (state) {
            return labels[state] || '';
        }).filter(Boolean).join(' e ');
    }

    function updateSimMultiSelect(form, states) {
        var selectedStates = Array.isArray(states) ? states : getSelectedSimStates(form);
        var label = form.querySelector('[data-sim-multi-select-label]');
        var hiddenState = form.querySelector('[data-sim-state-hidden]');
        var allCheckbox = form.querySelector('[data-sim-state-all]');
        var explicitSelectedCount = Array.prototype.slice.call(form.querySelectorAll('[data-sim-state-checkbox]')).filter(function (checkbox) {
            return checkbox.checked;
        }).length;

        if (label) {
            label.textContent = simStatesLabel(selectedStates);
        }
        if (hiddenState) {
            hiddenState.value = simStatesKey(selectedStates);
        }
        if (allCheckbox) {
            allCheckbox.checked = explicitSelectedCount === 0 || explicitSelectedCount === 3;
        }

        var wrapper = form.querySelector('[data-sim-multi-select]');
        var resetButton = wrapper ? wrapper.querySelector('.multi-select-reset') : null;
        var canReset = selectedStates.length !== 3;
        if (wrapper) {
            wrapper.classList.toggle('has-reset-value', canReset);
        }
        if (resetButton) {
            resetButton.classList.toggle('is-visible', canReset);
            resetButton.setAttribute('aria-hidden', canReset ? 'false' : 'true');
            resetButton.tabIndex = canReset ? 0 : -1;
        }
    }

    function updateSimOrderOptions(form, selectedStates) {
        var order = form ? form.querySelector('#ordine_sim') : null;
        if (!order) {
            return;
        }

        var states = Array.isArray(selectedStates) ? selectedStates : getSelectedSimStates(form);
        var hasActive = states.indexOf('attive') !== -1;
        var hasDisabled = states.indexOf('disattive') !== -1;
        var hasAssociated = hasActive || hasDisabled;

        Array.prototype.forEach.call(order.options, function (option) {
            if (option.value === 'piu_chiamate') {
                option.hidden = !hasAssociated;
            } else if (option.value === 'attivate_recenti') {
                option.hidden = !hasActive;
            } else if (option.value === 'disattivate_recenti') {
                option.hidden = !hasDisabled;
            }
        });

        var selectedOption = order.selectedIndex >= 0 ? order.options[order.selectedIndex] : null;
        if (!hasAssociated || !selectedOption || selectedOption.hidden) {
            order.value = 'nessuno';
        }

        order.disabled = !hasAssociated;
        var group = order.closest('.sim-order-filter-group');
        if (group) {
            group.classList.toggle('is-filter-unavailable', !hasAssociated);
        }
        updateCustomSelect(order);
    }

    function applySimState(form, states) {
        var selectedStates = Array.isArray(states) ? states : getSelectedSimStates(form);
        var select = form.querySelector('[data-sim-state-select]');
        if (select && select.value === '') {
            select.value = 'tutte';
        }

        updateSimMultiSelect(form, selectedStates);

        form.querySelectorAll('[data-sim-state-checkbox]').forEach(function (checkbox) {
            var chip = checkbox.closest('.checkbox-chip');
            if (chip) {
                chip.classList.toggle('is-selected', checkbox.checked);
            }
        });

        form.querySelectorAll('[data-state-field]').forEach(function (fieldGroup) {
            var allowedStates = (fieldGroup.dataset.stateField || '').split(',').filter(Boolean);
            var shouldEnable = selectedStates.some(function (state) {
                return allowedStates.indexOf(state) !== -1;
            });

            /* I filtri non applicabili restano visibili per mantenere stabile il
               layout, ma diventano attenuati e non interattivi. */
            fieldGroup.classList.remove('is-hidden');
            fieldGroup.classList.toggle('is-filter-unavailable', !shouldEnable);

            fieldGroup.querySelectorAll('input, select, textarea').forEach(function (field) {
                field.disabled = !shouldEnable;
                if (!shouldEnable) {
                    if (field.tagName === 'SELECT') {
                        field.value = field.options.length ? field.options[0].value : '';
                    } else {
                        field.value = '';
                    }
                    field.dispatchEvent(new Event('change', { bubbles: false }));
                }
                if (field.matches && field.matches('input[data-clearable="true"]')) {
                    updateClearButton(field);
                }
                if (field.tagName === 'SELECT') {
                    updateCustomSelect(field);
                }
            });
        });

        updateSimOrderOptions(form, selectedStates);
        updateStickyLayout();
    }

    function closeSimMultiSelect(wrapper) {
        if (!wrapper) {
            return;
        }
        wrapper.classList.remove('is-open');
        var button = wrapper.querySelector('.multi-select-button');
        if (button) {
            button.setAttribute('aria-expanded', 'false');
        }
    }

    function initSimMultiSelect(form) {
        var wrapper = form.querySelector('[data-sim-multi-select]');
        if (!wrapper || wrapper.dataset.multiSelectReady === 'true') {
            return;
        }
        wrapper.dataset.multiSelectReady = 'true';
        var button = wrapper.querySelector('.multi-select-button');
        var resetButton = wrapper.querySelector('.multi-select-reset');
        if (!resetButton) {
            resetButton = document.createElement('button');
            resetButton.type = 'button';
            resetButton.className = 'custom-select-reset multi-select-reset';
            resetButton.setAttribute('aria-label', 'Ripristina stati SIM');
            resetButton.setAttribute('title', 'Ripristina stati SIM');
            resetButton.setAttribute('aria-hidden', 'true');
            resetButton.tabIndex = -1;
            wrapper.insertBefore(resetButton, wrapper.querySelector('.multi-select-menu'));
        }

        if (button) {
            button.addEventListener('click', function (event) {
                event.stopPropagation();
                var shouldOpen = !wrapper.classList.contains('is-open');
                document.querySelectorAll('.custom-select.is-open').forEach(closeCustomSelect);
                document.querySelectorAll('[data-sim-multi-select].is-open').forEach(closeSimMultiSelect);
                wrapper.classList.toggle('is-open', shouldOpen);
                button.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
            });
        }
        resetButton.addEventListener('click', function (event) {
            event.preventDefault();
            event.stopPropagation();
            var checkboxes = form.querySelectorAll('[data-sim-state-checkbox]');
            checkboxes.forEach(function (checkbox) {
                checkbox.checked = true;
            });
            var allCheckbox = form.querySelector('[data-sim-state-all]');
            if (allCheckbox) {
                allCheckbox.checked = true;
            }
            applySimState(form, ['attive', 'disponibili', 'disattive']);
            closeSimMultiSelect(wrapper);
            form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        });

        wrapper.addEventListener('click', function (event) {
            event.stopPropagation();
        });
    }

    function initSimStateControls(root) {
        var scope = root || document;
        scope.querySelectorAll('form[data-sim-state-filter="true"]:not([data-sim-state-ready="true"])').forEach(function (form) {
            form.dataset.simStateReady = 'true';
            var select = form.querySelector('[data-sim-state-select]');
            var checkboxes = form.querySelectorAll('[data-sim-state-checkbox]');

            initSimMultiSelect(form);
            applySimState(form, getSelectedSimStates(form));

            if (select) {
                select.addEventListener('change', function () {
                    applySimState(form, getSelectedSimStates(form));
                });
                return;
            }

            var allCheckbox = form.querySelector('[data-sim-state-all]');
            if (allCheckbox) {
                allCheckbox.addEventListener('change', function () {
                    checkboxes.forEach(function (checkbox) {
                        checkbox.checked = true;
                    });
                    applySimState(form, ['attive', 'disponibili', 'disattive']);

                    var submitEvent = new Event('submit', { bubbles: true, cancelable: true });
                    form.dispatchEvent(submitEvent);
                });
            }

            checkboxes.forEach(function (checkbox) {
                checkbox.addEventListener('change', function () {
                    var selectedStates = getSelectedSimStates(form);
                    applySimState(form, selectedStates);

                    var submitEvent = new Event('submit', { bubbles: true, cancelable: true });
                    form.dispatchEvent(submitEvent);
                });
            });
        });
    }

    function toggleCustomThreshold(select, focusInput) {
        if (!select) {
            return;
        }
        var formGroup = select.closest('.traffic-filter-group');
        var container = formGroup ? formGroup.querySelector('[data-custom-threshold-container]') : null;
        var input = formGroup ? formGroup.querySelector('[data-custom-threshold-input]') : null;
        var wrapper = select.nextElementSibling && select.nextElementSibling.classList && select.nextElementSibling.classList.contains('custom-select')
            ? select.nextElementSibling
            : null;
        var shouldShow = select.value === 'custom';

        if (formGroup) {
            formGroup.classList.toggle('threshold-is-custom', shouldShow);
        }
        if (wrapper) {
            wrapper.classList.toggle('custom-select-threshold-active', shouldShow);
        }
        if (container) {
            container.classList.toggle('is-hidden', !shouldShow);
            container.setAttribute('aria-hidden', shouldShow ? 'false' : 'true');
        }
        if (input) {
            input.disabled = !shouldShow;
            if (!shouldShow) {
                input.value = '';
            } else if (focusInput) {
                window.setTimeout(function () {
                    input.focus();
                    input.select();
                }, 0);
            }
            updateClearButton(input);
        }
        updateStickyLayout();
    }

    function initTrafficThresholdControls(root) {
        var scope = root || document;
        scope.querySelectorAll('[data-custom-threshold-select]:not([data-threshold-ready="true"])').forEach(function (select) {
            select.dataset.thresholdReady = 'true';
            toggleCustomThreshold(select, false);
            select.addEventListener('change', function () {
                toggleCustomThreshold(select, true);
            });
        });
    }

    function updatePhoneDateLabel(form) {
        var label = form ? form.querySelector('[data-phone-date-label]') : null;
        var status = form ? form.querySelector('#stato_numero') : null;
        if (!label || !status) {
            return;
        }
        label.textContent = status.value === 'disattivato' ? 'Disattivato dal/al:' : 'Attivato dal/al:';
    }

    function updatePhoneOrderOptions(form) {
        var status = form ? form.querySelector('#stato_numero') : null;
        var order = form ? form.querySelector('#ordine') : null;
        if (!status || !order) {
            return;
        }

        var activeRecent = Array.prototype.find.call(order.options, function (option) {
            return option.value === 'recenti';
        });
        var disabledRecent = Array.prototype.find.call(order.options, function (option) {
            return option.value === 'disattivati_recenti';
        });
        if (!activeRecent || !disabledRecent) {
            return;
        }

        activeRecent.hidden = status.value === 'disattivato';
        disabledRecent.hidden = status.value === 'attivo';

        if (status.value === 'disattivato' && order.value === 'recenti') {
            order.value = 'disattivati_recenti';
        } else if (status.value === 'attivo' && order.value === 'disattivati_recenti') {
            order.value = 'recenti';
        }

        updateCustomSelect(order);
    }

    function initPhoneDateLabelControls(root) {
        var scope = root || document;
        scope.querySelectorAll('form.contratti-filter-form:not([data-phone-date-label-ready="true"])').forEach(function (form) {
            form.dataset.phoneDateLabelReady = 'true';
            updatePhoneDateLabel(form);
            updatePhoneOrderOptions(form);
            var status = form.querySelector('#stato_numero');
            if (status) {
                status.addEventListener('change', function () {
                    updatePhoneDateLabel(form);
                    updatePhoneOrderOptions(form);
                });
            }
        });
    }

    var PHONE_RESIDUAL_PLAN_REQUIREMENTS = {
        credito_basso: 'ricarica',
        credito_disponibile: 'ricarica',
        minuti_bassi: 'consumo',
        minuti_disponibili: 'consumo'
    };

    function setSelectOptionDisabled(select, values, disabled) {
        if (!select) {
            return;
        }
        var valueSet = values || [];
        Array.prototype.forEach.call(select.options, function (option) {
            if (valueSet.indexOf(option.value) !== -1) {
                option.disabled = Boolean(disabled);
            }
        });
        updateCustomSelect(select);
    }

    function setDependencyLocked(select, locked, message) {
        if (!select) {
            return;
        }
        select.dataset.dependencyLocked = locked ? 'true' : 'false';
        if (locked) {
            select.dataset.dependencyMessage = message || '';
        } else {
            delete select.dataset.dependencyMessage;
        }
        updateCustomSelect(select);
    }

    function applyPhonePlanResidualDependencies(form, changedField) {
        if (!form) {
            return;
        }

        var planSelect = form.querySelector('#tipo');
        var residualSelect = form.querySelector('#residuo');
        if (!planSelect || !residualSelect) {
            return;
        }

        var requiredPlan = PHONE_RESIDUAL_PLAN_REQUIREMENTS[residualSelect.value] || '';
        var wasAutoSet = form.dataset.planAutoSet === 'true';

        if (requiredPlan) {
            if (!wasAutoSet) {
                /* Su un caricamento completo il piano è già stato normalizzato dal
                   server: non è una scelta manuale da conservare. Se invece il
                   residuo cambia nell'interfaccia, ricordiamo il piano precedente. */
                form.dataset.planPreviousValue = changedField === residualSelect
                    ? (planSelect.value || '')
                    : '';
            }
            form.dataset.planAutoSet = 'true';
            if (planSelect.value !== requiredPlan) {
                planSelect.value = requiredPlan;
            }

            /* Il filtro residuo deve restare completamente navigabile: l'utente
               può passare direttamente da un criterio sui minuti a uno sul credito.
               È il piano, già determinato dal residuo, a essere temporaneamente bloccato. */
            setSelectOptionDisabled(residualSelect, [
                'credito_basso',
                'credito_disponibile',
                'minuti_bassi',
                'minuti_disponibili'
            ], false);
            setDependencyLocked(
                planSelect,
                true,
                'Piano impostato automaticamente dal filtro Disponibilità del piano'
            );
        } else {
            if (wasAutoSet) {
                planSelect.value = form.dataset.planPreviousValue || '';
                delete form.dataset.planPreviousValue;
                delete form.dataset.planAutoSet;
            }
            setDependencyLocked(planSelect, false);

            /* Quando il piano è scelto manualmente, manteniamo visibili tutte le
               disponibilità ma rendiamo non selezionabili quelle incompatibili. */
            setSelectOptionDisabled(
                residualSelect,
                ['credito_basso', 'credito_disponibile'],
                planSelect.value === 'consumo'
            );
            setSelectOptionDisabled(
                residualSelect,
                ['minuti_bassi', 'minuti_disponibili'],
                planSelect.value === 'ricarica'
            );

            var selectedResidualOption = residualSelect.options[residualSelect.selectedIndex];
            if (selectedResidualOption && selectedResidualOption.disabled) {
                residualSelect.value = '';
            }
        }

        updateCustomSelect(planSelect);
        updateCustomSelect(residualSelect);
    }

    function initPhonePlanResidualDependencies(root) {
        var scope = root || document;
        scope.querySelectorAll('form[data-plan-residual-sync="true"]:not([data-plan-residual-ready="true"])').forEach(function (form) {
            form.dataset.planResidualReady = 'true';
            var planSelect = form.querySelector('#tipo');
            var residualSelect = form.querySelector('#residuo');
            if (!planSelect || !residualSelect) {
                return;
            }

            applyPhonePlanResidualDependencies(form, null);

            residualSelect.addEventListener('change', function () {
                applyPhonePlanResidualDependencies(form, residualSelect);
            });
            planSelect.addEventListener('change', function () {
                applyPhonePlanResidualDependencies(form, planSelect);
            });
        });
    }

    function initPhoneTrafficOrderControls(root) {
        var scope = root || document;
        scope.querySelectorAll('form.contratti-filter-form:not([data-traffic-order-ready="true"])').forEach(function (form) {
            /* Il filtro minimo di chiamate e l'ordinamento sono scelte autonome:
               selezionare una soglia non deve modificare "Mostra prima". */
            form.dataset.trafficOrderReady = 'true';
        });
    }

    function closeCustomSelect(wrapper) {
        if (!wrapper) {
            return;
        }
        wrapper.classList.remove('is-open');
        var button = wrapper.querySelector('.custom-select-button');
        if (button) {
            button.setAttribute('aria-expanded', 'false');
        }
    }

    function updateCustomSelect(select) {
        var wrapper = select.nextElementSibling;
        if (!wrapper || !wrapper.classList.contains('custom-select')) {
            return;
        }
        var buttonText = wrapper.querySelector('.custom-select-current');
        var selectedOption = select.options[select.selectedIndex];
        if (buttonText && selectedOption) {
            buttonText.textContent = selectedOption.textContent;
        }
        wrapper.querySelectorAll('.custom-select-option').forEach(function (optionButton) {
            var isSelected = optionButton.dataset.value === select.value;
            var nativeOption = Array.prototype.find.call(select.options, function (option) {
                return option.value === optionButton.dataset.value;
            });
            var isDisabled = Boolean(nativeOption && nativeOption.disabled);
            var isHidden = Boolean(nativeOption && nativeOption.hidden);
            optionButton.hidden = isHidden;
            optionButton.style.display = isHidden ? 'none' : '';
            optionButton.classList.toggle('is-selected', isSelected);
            optionButton.classList.toggle('is-disabled', isDisabled);
            optionButton.disabled = isDisabled;
            optionButton.setAttribute('aria-selected', isSelected ? 'true' : 'false');
            optionButton.setAttribute('aria-disabled', isDisabled ? 'true' : 'false');
        });

        var button = wrapper.querySelector('.custom-select-button');
        var isDependencyLocked = select.dataset.dependencyLocked === 'true';
        var isNativeDisabled = select.disabled;
        var isControlDisabled = isDependencyLocked || isNativeDisabled;
        wrapper.classList.toggle('is-dependency-locked', isDependencyLocked);
        wrapper.classList.toggle('is-disabled', isNativeDisabled);
        if (button) {
            button.disabled = isControlDisabled;
            button.setAttribute('aria-disabled', isControlDisabled ? 'true' : 'false');
            button.title = isDependencyLocked
                ? (select.dataset.dependencyMessage || 'Valore impostato automaticamente da un altro filtro')
                : '';
        }

        var resetButton = wrapper.querySelector('.custom-select-reset');
        var defaultValue = wrapper.dataset.defaultValue || '';
        var canReset = !isControlDisabled && select.value !== defaultValue;
        wrapper.classList.toggle('has-reset-value', canReset);
        if (resetButton) {
            resetButton.classList.toggle('is-visible', canReset);
            resetButton.setAttribute('aria-hidden', canReset ? 'false' : 'true');
            resetButton.tabIndex = canReset ? 0 : -1;
        }
    }

    function initScrollableSelects(root) {
        var scope = root || document;
        scope.querySelectorAll('.compact-filter-form select:not([data-scroll-select-ready="true"])').forEach(function (select) {
            select.dataset.scrollSelectReady = 'true';
            select.classList.add('native-select-hidden');

            var wrapper = document.createElement('div');
            wrapper.className = 'custom-select';
            if (select.hasAttribute('data-custom-threshold-select')) {
                wrapper.classList.add('custom-select-threshold');
            }

            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'custom-select-button';
            button.setAttribute('aria-haspopup', 'listbox');
            button.setAttribute('aria-expanded', 'false');

            var current = document.createElement('span');
            current.className = 'custom-select-current';
            button.appendChild(current);

            var arrow = document.createElement('span');
            arrow.className = 'custom-select-arrow';
            arrow.setAttribute('aria-hidden', 'true');
            arrow.textContent = '⌄';
            button.appendChild(arrow);

            var resetButton = document.createElement('button');
            resetButton.type = 'button';
            resetButton.className = 'custom-select-reset';
            resetButton.setAttribute('aria-label', 'Ripristina filtro');
            resetButton.setAttribute('title', 'Ripristina filtro');
            resetButton.setAttribute('aria-hidden', 'true');
            resetButton.tabIndex = -1;

            var menu = document.createElement('div');
            menu.className = 'custom-select-menu';
            menu.setAttribute('role', 'listbox');

            Array.prototype.slice.call(select.options).forEach(function (option) {
                var optionButton = document.createElement('button');
                optionButton.type = 'button';
                optionButton.className = 'custom-select-option';
                optionButton.dataset.value = option.value;
                optionButton.textContent = option.textContent;
                optionButton.setAttribute('role', 'option');
                optionButton.hidden = option.hidden;
                optionButton.style.display = option.hidden ? 'none' : '';
                optionButton.disabled = option.disabled;
                optionButton.classList.toggle('is-disabled', option.disabled);
                optionButton.setAttribute('aria-disabled', option.disabled ? 'true' : 'false');
                optionButton.addEventListener('click', function () {
                    if (optionButton.disabled || option.disabled) {
                        return;
                    }
                    select.value = option.value;
                    updateCustomSelect(select);
                    closeCustomSelect(wrapper);
                    select.dispatchEvent(new Event('change', { bubbles: true }));
                });
                menu.appendChild(optionButton);
            });

            wrapper.dataset.defaultValue = select.options.length > 0 ? select.options[0].value : '';
            wrapper.appendChild(button);
            wrapper.appendChild(resetButton);
            wrapper.appendChild(menu);
            select.insertAdjacentElement('afterend', wrapper);
            updateCustomSelect(select);

            button.addEventListener('click', function (event) {
                event.stopPropagation();
                var shouldOpen = !wrapper.classList.contains('is-open');
                document.querySelectorAll('.custom-select.is-open').forEach(closeCustomSelect);
                wrapper.classList.toggle('is-open', shouldOpen);
                button.setAttribute('aria-expanded', shouldOpen ? 'true' : 'false');
            });

            resetButton.addEventListener('click', function (event) {
                event.preventDefault();
                event.stopPropagation();
                select.value = wrapper.dataset.defaultValue || '';
                updateCustomSelect(select);
                closeCustomSelect(wrapper);
                select.dispatchEvent(new Event('change', { bubbles: true }));
            });

            select.addEventListener('change', function () {
                updateCustomSelect(select);
            });
        });
    }

    document.addEventListener('click', function (event) {
        if (event.target.closest && (event.target.closest('.custom-select') || event.target.closest('[data-sim-multi-select]'))) {
            return;
        }
        document.querySelectorAll('.custom-select.is-open').forEach(closeCustomSelect);
        document.querySelectorAll('[data-sim-multi-select].is-open').forEach(closeSimMultiSelect);
    });


    function updateClearButton(input) {
        var wrapper = input.parentElement;
        if (!wrapper || !wrapper.classList.contains('clearable-input')) {
            return;
        }

        var button = wrapper.querySelector('.clear-input-button');
        if (!button) {
            return;
        }

        var hasValue = input.value !== '';
        var canClear = !input.disabled && !input.readOnly;
        button.classList.toggle('is-visible', hasValue && canClear);
        button.setAttribute('aria-hidden', hasValue && canClear ? 'false' : 'true');
        button.tabIndex = hasValue && canClear ? 0 : -1;
    }

    function initClearableInputs(root) {
        var scope = root || document;
        scope.querySelectorAll('input[data-clearable="true"]:not([data-clear-ready="true"])').forEach(function (input) {
            if (input.type === 'hidden' || input.readOnly) {
                return;
            }

            input.dataset.clearReady = 'true';

            var wrapper = document.createElement('span');
            wrapper.className = 'clearable-input';
            input.parentNode.insertBefore(wrapper, input);
            wrapper.appendChild(input);

            var button = document.createElement('button');
            button.type = 'button';
            button.className = 'clear-input-button';
            button.setAttribute('aria-label', 'Svuota campo');
            button.setAttribute('title', 'Svuota campo');
            button.textContent = '×';
            wrapper.appendChild(button);

            input.addEventListener('input', function () {
                updateClearButton(input);
            });

            input.addEventListener('change', function () {
                updateClearButton(input);
            });

            button.addEventListener('click', function () {
                if (input.disabled || input.readOnly) {
                    return;
                }

                input.value = '';
                updateClearButton(input);
                input.focus();
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
            });

            updateClearButton(input);
        });

        scope.querySelectorAll('input[data-clearable="true"][data-clear-ready="true"]').forEach(updateClearButton);
    }


    function getFieldError(field) {
        var form = field.closest('form');
        if (!form || !field.name) {
            return null;
        }
        return form.querySelector('[data-field-error-for="' + field.name + '"]');
    }

    function setFieldError(field, message) {
        var error = getFieldError(field);
        field.classList.toggle('input-invalid', Boolean(message));
        field.setAttribute('aria-invalid', message ? 'true' : 'false');
        if (error) {
            error.textContent = message || '';
            error.classList.toggle('is-visible', Boolean(message));
        }
    }

    function clearFieldError(field) {
        setFieldError(field, '');
    }

    function isDigits(value) {
        return /^\d+$/.test(value);
    }

    function formatItalianDate(value) {
        if (!value || value.indexOf('-') === -1) {
            return value || '';
        }
        var parts = value.split('-');
        if (parts.length !== 3) {
            return value;
        }
        return parts[2] + '/' + parts[1] + '/' + parts[0];
    }

    function validateRequiredAndFormat(field) {
        if (field.disabled || field.readOnly && !field.hasAttribute('data-auto-activation-date')) {
            clearFieldError(field);
            return true;
        }

        var value = (field.value || '').trim();
        var requiredMessage = field.dataset.requiredMessage || 'Compilare questo campo.';
        var formatMessage = field.dataset.formatMessage || 'Il valore inserito non è valido.';

        if (field.hasAttribute('required') && value === '') {
            setFieldError(field, requiredMessage);
            return false;
        }

        if (field.dataset.validation === 'digits' && value !== '' && !isDigits(value)) {
            setFieldError(field, formatMessage);
            return false;
        }

        clearFieldError(field);
        return true;
    }

    function validateDeactivationDate(field) {
        if (!validateRequiredAndFormat(field)) {
            return false;
        }

        if (!field.value) {
            return true;
        }

        var min = field.getAttribute('min');
        var max = field.getAttribute('max');

        if (min && field.value < min) {
            setFieldError(field, 'La data di disattivazione non può essere precedente a ' + formatItalianDate(min) + '.');
            return false;
        }

        if (max && field.value > max) {
            setFieldError(field, 'La data di disattivazione non può essere successiva a ' + formatItalianDate(max) + '.');
            return false;
        }

        clearFieldError(field);
        return true;
    }

    function validateSimCrudField(field) {
        if (field.matches('[data-deactivation-date="true"]')) {
            return validateDeactivationDate(field);
        }
        return validateRequiredAndFormat(field);
    }

    function validateSimCrudForm(form) {
        var valid = true;
        form.querySelectorAll('input, select, textarea').forEach(function (field) {
            if (field.type === 'hidden' || field.disabled) {
                return;
            }
            if (!validateSimCrudField(field)) {
                valid = false;
            }
        });
        return valid;
    }

    function setSelectValue(select, value) {
        if (!select) {
            return;
        }
        select.value = value || '';
        validateRequiredAndFormat(select);
    }

    function setFieldValue(field, value) {
        if (!field) {
            return;
        }
        field.value = value || '';
        updateClearButton(field);
    }

    function setSystemRecoveredField(field, locked) {
        if (!field) {
            return;
        }

        field.disabled = Boolean(locked);
        field.classList.toggle('input-readonly', Boolean(locked));
        field.classList.toggle('input-disabled', Boolean(locked));
        field.setAttribute('aria-readonly', locked ? 'true' : 'false');

        if (field.matches && field.matches('input[data-clearable="true"]')) {
            updateClearButton(field);
        }
    }

    function setRecoveredSimFieldsState(form, locked) {
        var phoneField = form.querySelector('[data-phone-lookup="true"]');
        var typeField = form.querySelector('select[name="tipoSIM"]');
        var activationField = form.querySelector('[data-auto-activation-date="true"]');

        setSystemRecoveredField(phoneField, locked);
        setSystemRecoveredField(typeField, locked);
        setSystemRecoveredField(activationField, true);
    }

    function applyLinkedSimData(form, payload) {
        var codeField = form.querySelector('[data-sim-code-lookup="true"]');
        var phoneField = form.querySelector('[data-phone-lookup="true"]');
        var typeField = form.querySelector('select[name="tipoSIM"]');
        var activationField = form.querySelector('[data-auto-activation-date="true"]');
        var deactivationField = form.querySelector('[data-deactivation-date="true"]');

        if (payload.codice && codeField && !codeField.readOnly) {
            setFieldValue(codeField, payload.codice);
            clearFieldError(codeField);
        }
        if (payload.numero && phoneField) {
            setFieldValue(phoneField, payload.numero);
            clearFieldError(phoneField);
        }
        if (payload.tipoSIM && typeField) {
            setSelectValue(typeField, payload.tipoSIM);
        }
        if (activationField) {
            activationField.value = payload.dataAttivazione || '';
        }
        if (deactivationField) {
            deactivationField.setAttribute('min', payload.dataMinimaDisattivazione || payload.dataAttivazione || '');
            deactivationField.setAttribute('max', payload.dataMassimaDisattivazione || new Date().toISOString().slice(0, 10));
            validateDeactivationDate(deactivationField);
        }

        if ((form.dataset.formMode || 'edit') === 'create') {
            setRecoveredSimFieldsState(form, true);
        }
    }

    function clearLinkedSimData(form, keepField) {
        var codeField = form.querySelector('[data-sim-code-lookup="true"]');
        var phoneField = form.querySelector('[data-phone-lookup="true"]');
        var typeField = form.querySelector('select[name="tipoSIM"]');
        var activationField = form.querySelector('[data-auto-activation-date="true"]');
        var deactivationField = form.querySelector('[data-deactivation-date="true"]');

        if ((form.dataset.formMode || 'edit') === 'create') {
            setRecoveredSimFieldsState(form, false);
        }

        if (codeField && codeField !== keepField && !codeField.readOnly) {
            setFieldValue(codeField, '');
        }
        if (phoneField && phoneField !== keepField) {
            setFieldValue(phoneField, '');
        }
        if (typeField) {
            typeField.value = '';
        }
        if (activationField) {
            activationField.value = '';
        }
        if (deactivationField) {
            deactivationField.removeAttribute('min');
        }
    }

    function setCrudDependentFieldsState(form, disabled) {
        /* La presenza di una SIM non utilizzabile viene comunicata con un messaggio
           sotto il campo codice. Gli altri campi restano compilabili: l'utente può
           correggere il codice o completare il form senza trovare controlli bloccati. */
        form.dataset.crudBlocked = 'false';
        form.classList.remove('crud-form-blocked');
        form.querySelectorAll('[data-crud-dependent="true"]').forEach(function (field) {
            field.disabled = false;
            field.classList.remove('input-disabled');
            if (field.matches('input[data-clearable="true"]')) {
                updateClearButton(field);
            }
        });
        form.querySelectorAll('[data-crud-submit="true"]').forEach(function (button) {
            button.disabled = false;
            button.classList.remove('btn-disabled');
        });
    }

    function updateFromSimCode(form, codeField) {
        var lookupUrl = form.dataset.simLookupUrl;
        var value = (codeField.value || '').trim();

        if (!lookupUrl || form.dataset.formMode !== 'create') {
            return;
        }

        if (value === '') {
            setCrudDependentFieldsState(form, false);
            clearLinkedSimData(form, codeField);
            validateRequiredAndFormat(codeField);
            return;
        }

        if (!isDigits(value)) {
            setCrudDependentFieldsState(form, false);
            clearLinkedSimData(form, codeField);
            validateRequiredAndFormat(codeField);
            return;
        }

        fetch(lookupUrl + '&codice=' + encodeURIComponent(value), {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('Risposta non valida');
                }
                return response.json();
            })
            .then(function (payload) {
                if (!payload || payload.status !== 'attiva') {
                    clearLinkedSimData(form, codeField);
                    setCrudDependentFieldsState(form, false);
                    setFieldError(codeField, payload && payload.message ? payload.message : 'La SIM indicata non risulta in uso.');
                    return;
                }

                setCrudDependentFieldsState(form, false);
                clearFieldError(codeField);
                applyLinkedSimData(form, payload);
            })
            .catch(function () {
                setFieldError(codeField, 'Non è stato possibile controllare la SIM indicata. Riprovare.');
            });
    }

    function updateActivationFromNumber(form, phoneField) {
        var lookupUrl = form.dataset.lookupUrl;
        var activationField = form.querySelector('[data-auto-activation-date="true"]');
        var deactivationField = form.querySelector('[data-deactivation-date="true"]');
        var value = (phoneField.value || '').trim();
        var mode = form.dataset.formMode || 'edit';

        if (!lookupUrl || !activationField || !deactivationField) {
            return;
        }

        if (value === '') {
            if (mode === 'create') {
                clearLinkedSimData(form, phoneField);
            }
            validateRequiredAndFormat(phoneField);
            return;
        }

        if (!isDigits(value)) {
            if (mode === 'create') {
                clearLinkedSimData(form, phoneField);
            }
            validateRequiredAndFormat(phoneField);
            return;
        }

        fetch(lookupUrl + '&mode=' + encodeURIComponent(mode) + '&numero=' + encodeURIComponent(value), {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('Risposta non valida');
                }
                return response.json();
            })
            .then(function (payload) {
                if (!payload || !payload.exists || (mode === 'create' && !payload.hasActiveSim)) {
                    if (mode === 'create') {
                        clearLinkedSimData(form, phoneField);
                    }
                    setFieldError(phoneField, payload && payload.message ? payload.message : 'Il numero indicato non risulta utilizzabile per questa operazione.');
                    return;
                }

                clearFieldError(phoneField);
                if (mode === 'create') {
                    setCrudDependentFieldsState(form, false);
                    applyLinkedSimData(form, payload);
                } else {
                    deactivationField.setAttribute('min', activationField.value || '');
                    deactivationField.setAttribute('max', payload.dataMassimaDisattivazione || new Date().toISOString().slice(0, 10));
                    validateDeactivationDate(deactivationField);
                }
            })
            .catch(function () {
                setFieldError(phoneField, 'Non è stato possibile controllare il numero indicato. Riprovare.');
            });
    }

    function initSimCrudForms(root) {
        var scope = root || document;
        scope.querySelectorAll('form[data-sim-crud-form="true"]:not([data-sim-crud-ready="true"])').forEach(function (form) {
            form.dataset.simCrudReady = 'true';
            form.setAttribute('novalidate', 'novalidate');

            form.querySelectorAll('input, select, textarea').forEach(function (field) {
                if (field.type === 'hidden') {
                    return;
                }

                field.addEventListener('blur', function () {
                    field.dataset.touched = 'true';
                    validateSimCrudField(field);
                    if (field.matches('[data-sim-code-lookup="true"]')) {
                        updateFromSimCode(form, field);
                    }
                    if (field.matches('[data-phone-lookup="true"]')) {
                        updateActivationFromNumber(form, field);
                    }
                });

                field.addEventListener('change', function () {
                    validateSimCrudField(field);
                    if (field.matches('[data-sim-code-lookup="true"]')) {
                        updateFromSimCode(form, field);
                    }
                    if (field.matches('[data-phone-lookup="true"]')) {
                        updateActivationFromNumber(form, field);
                    }
                });

                field.addEventListener('input', function () {
                    if (field.dataset.touched === 'true' || field.matches('[data-validation="digits"]')) {
                        validateRequiredAndFormat(field);
                    }
                    if (field.matches('[data-sim-code-lookup="true"]')) {
                        setCrudDependentFieldsState(form, false);
                        clearLinkedSimData(form, field);
                    }
                    if (field.matches('[data-phone-lookup="true"]') && form.dataset.formMode === 'create') {
                        clearLinkedSimData(form, field);
                    }
                    if (field.matches('[data-phone-lookup="true"]') && form.dataset.formMode !== 'create') {
                        var activationField = form.querySelector('[data-auto-activation-date="true"]');
                        var deactivationField = form.querySelector('[data-deactivation-date="true"]');
                        if (deactivationField && activationField) {
                            deactivationField.setAttribute('min', activationField.value || '');
                        }
                    }
                });
            });

            var codeField = form.querySelector('[data-sim-code-lookup="true"]');
            var phoneField = form.querySelector('[data-phone-lookup="true"]');

            if (form.dataset.crudBlocked === 'true') {
                setCrudDependentFieldsState(form, true);
            }

            if (form.dataset.formMode === 'create') {
                if (codeField && codeField.value) {
                    updateFromSimCode(form, codeField);
                } else if (phoneField && phoneField.value) {
                    updateActivationFromNumber(form, phoneField);
                }
            } else if (phoneField && phoneField.value) {
                updateActivationFromNumber(form, phoneField);
            }
        });
    }

    function isCardModalExcluded(target) {
        return Boolean(target.closest('a, button, input, select, textarea, label, .card-detail-link, [data-card-modal-exclude="true"]'));
    }

    var cardModalPreviousFocus = null;
    var cardModalHistory = [];
    var cardModalRootIsCall = false;

    function getFocusableElements(container) {
        if (!container) {
            return [];
        }
        var selector = [
            'a[href]',
            'button:not([disabled])',
            'input:not([disabled])',
            'select:not([disabled])',
            'textarea:not([disabled])',
            '[tabindex]:not([tabindex="-1"])'
        ].join(',');

        return Array.prototype.filter.call(container.querySelectorAll(selector), function (element) {
            return element.offsetParent !== null || element === document.activeElement;
        });
    }

    function setBackgroundInert(active, overlay) {
        Array.prototype.forEach.call(document.body.children, function (child) {
            if (child === overlay) {
                return;
            }

            if (active) {
                if (child.dataset.cardModalInertApplied === 'true') {
                    return;
                }

                child.dataset.cardModalInertApplied = 'true';
                if (child.hasAttribute('inert')) {
                    child.dataset.cardModalHadInert = 'true';
                }
                if (child.hasAttribute('aria-hidden')) {
                    child.dataset.cardModalPreviousAriaHidden = child.getAttribute('aria-hidden') || '';
                }

                child.setAttribute('inert', '');
                child.setAttribute('aria-hidden', 'true');
                return;
            }

            if (child.dataset.cardModalInertApplied !== 'true') {
                return;
            }

            if (child.dataset.cardModalHadInert !== 'true') {
                child.removeAttribute('inert');
            }
            if (Object.prototype.hasOwnProperty.call(child.dataset, 'cardModalPreviousAriaHidden')) {
                child.setAttribute('aria-hidden', child.dataset.cardModalPreviousAriaHidden);
            } else {
                child.removeAttribute('aria-hidden');
            }

            delete child.dataset.cardModalInertApplied;
            delete child.dataset.cardModalHadInert;
            delete child.dataset.cardModalPreviousAriaHidden;
        });
    }

    function trapCardModalFocus(event) {
        if (event.key !== 'Tab') {
            return;
        }

        var overlay = document.querySelector('[data-card-modal="true"].is-visible');
        if (!overlay) {
            return;
        }

        var dialog = overlay.querySelector('.card-modal-dialog');
        var focusableElements = getFocusableElements(dialog);
        if (focusableElements.length === 0) {
            event.preventDefault();
            return;
        }

        var first = focusableElements[0];
        var last = focusableElements[focusableElements.length - 1];

        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
            return;
        }

        if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    }

    function ensureCardModal() {
        var existing = document.querySelector('[data-card-modal="true"]');
        if (existing) {
            return existing;
        }

        var overlay = document.createElement('div');
        overlay.className = 'card-modal-overlay';
        overlay.setAttribute('data-card-modal', 'true');
        overlay.setAttribute('aria-hidden', 'true');

        var dialog = document.createElement('div');
        dialog.className = 'card-modal-dialog';
        dialog.setAttribute('role', 'dialog');
        dialog.setAttribute('aria-modal', 'true');
        dialog.setAttribute('aria-label', 'Dettaglio scheda');

        var content = document.createElement('div');
        content.className = 'card-modal-content';
        content.setAttribute('data-card-modal-content', 'true');

        var footer = document.createElement('div');
        footer.className = 'card-modal-footer';

        var backButton = document.createElement('button');
        backButton.type = 'button';
        backButton.className = 'btn card-modal-back';
        backButton.setAttribute('data-card-modal-close', 'true');
        backButton.textContent = 'Indietro';

        footer.appendChild(backButton);
        dialog.appendChild(content);
        dialog.appendChild(footer);
        overlay.appendChild(dialog);
        document.body.appendChild(overlay);

        overlay.addEventListener('click', function (event) {
            if (event.target === overlay) {
                closeCardModal();
            }
        });
        overlay.addEventListener('keydown', trapCardModalFocus);

        backButton.addEventListener('click', goBackCardModal);

        return overlay;
    }

    function resetCardModalTransientState(card) {
        if (!card) {
            return card;
        }

        card.querySelectorAll('.is-loading').forEach(function (node) {
            node.classList.remove('is-loading');
        });
        card.querySelectorAll('[aria-busy="true"]').forEach(function (node) {
            node.setAttribute('aria-busy', 'false');
        });

        return card;
    }

    function getCardModalType(card) {
        if (!card || !card.classList) {
            return 'generic';
        }
        if (card.classList.contains('call-card')) {
            return 'call';
        }
        if (card.classList.contains('phone-card')) {
            return 'phone';
        }
        if (card.classList.contains('sim-card')) {
            return 'sim';
        }
        if (card.classList.contains('card-modal-card-group')) {
            return 'sim-group';
        }
        return 'generic';
    }

    function getCardModalIdentity(card) {
        var type = getCardModalType(card);
        var value = '';

        if (type === 'phone') {
            var phoneTitle = card.querySelector('.phone-card-header .card-title, .card-title');
            value = phoneTitle ? phoneTitle.textContent.trim() : '';
        } else if (type === 'sim') {
            value = card.getAttribute('data-sim-code') || '';
            if (!value) {
                var simTitle = card.querySelector('.card-title');
                value = simTitle ? simTitle.textContent.trim() : '';
            }
        } else if (type === 'call') {
            var callTitle = card.querySelector('.call-expanded-title');
            if (callTitle) {
                value = callTitle.textContent.trim();
            } else {
                var date = card.querySelector('.call-card-date span');
                var time = card.querySelector('.call-card-date strong');
                var number = card.querySelector('.call-number-link .card-title');
                value = [
                    date ? date.textContent.trim() : '',
                    time ? time.textContent.trim() : '',
                    number ? number.textContent.trim() : ''
                ].join('|');
            }
        }

        return type + ':' + value;
    }

    function cloneCardModalSnapshot(card) {
        return resetCardModalTransientState(card.cloneNode(true));
    }

    function updateCardModalHistory(currentCard, targetCard) {
        var currentSnapshot = cloneCardModalSnapshot(currentCard);

        if (!cardModalRootIsCall) {
            /* Manteniamo invariato il comportamento già usato tra Numero e SIM:
               una sola scheda precedente evita cronologie cicliche molto lunghe. */
            cardModalHistory = [currentSnapshot];
            return;
        }

        /* Quando la navigazione nasce da una chiamata conserviamo il percorso
           Chiamata -> Numero -> SIM. Se si torna su una scheda già presente
           (per esempio SIM -> Numero associato), eliminiamo il ciclo e il tasto
           Indietro riporta direttamente alla chiamata di partenza. */
        var path = cardModalHistory.concat([currentSnapshot]);
        var targetIdentity = getCardModalIdentity(targetCard);
        var repeatedIndex = -1;

        for (var index = 0; index < path.length; index += 1) {
            if (getCardModalIdentity(path[index]) === targetIdentity) {
                repeatedIndex = index;
                break;
            }
        }

        cardModalHistory = repeatedIndex >= 0 ? path.slice(0, repeatedIndex) : path;
    }

    function buildExpandedCallModalCard(card) {
        if (!card || !card.classList || !card.classList.contains('call-card') || card.classList.contains('call-card-expanded-detail')) {
            return card;
        }

        var dateNode = card.querySelector('.call-card-date span');
        var timeNode = card.querySelector('.call-card-date strong');
        var sourceNumberLink = card.querySelector('.call-number-link');
        var numberNode = sourceNumberLink ? sourceNumberLink.querySelector('.card-title') : null;
        var values = {};

        card.querySelectorAll('.call-detail-grid > div').forEach(function (item) {
            var label = item.querySelector('dt');
            var value = item.querySelector('dd');
            if (label && value) {
                values[label.textContent.trim().toLowerCase()] = value.textContent.trim();
            }
        });

        var dateText = dateNode ? dateNode.textContent.trim() : '';
        var timeText = timeNode ? timeNode.textContent.trim() : '';
        var numberText = numberNode ? numberNode.textContent.trim() : (sourceNumberLink ? sourceNumberLink.textContent.trim() : '');
        var durationText = values.durata || '';
        var planText = values.piano || '';
        var chargeText = values.addebito || '';

        var expanded = document.createElement('article');
        expanded.className = 'data-card call-card call-card-expanded-detail';

        var header = document.createElement('div');
        header.className = 'data-card-header call-expanded-header';

        var heading = document.createElement('div');
        var kicker = document.createElement('span');
        kicker.className = 'card-kicker';
        kicker.textContent = 'Chiamata effettuata';
        var title = document.createElement('h3');
        title.className = 'card-title call-expanded-title';
        title.textContent = dateText && timeText ? dateText + ' alle ' + timeText : (dateText || timeText || 'Dettaglio chiamata');
        heading.appendChild(kicker);
        heading.appendChild(title);

        var status = document.createElement('span');
        status.className = 'status-pill call-status-pill';
        status.textContent = 'Chiamata registrata';
        header.appendChild(heading);
        header.appendChild(status);

        var callerBanner;
        if (sourceNumberLink) {
            callerBanner = sourceNumberLink.cloneNode(false);
            callerBanner.className = 'call-caller-banner call-caller-banner-link';
            callerBanner.removeAttribute('role');
            callerBanner.removeAttribute('tabindex');
            callerBanner.setAttribute('aria-label', 'Apri il dettaglio del numero chiamante ' + numberText);
        } else {
            callerBanner = document.createElement('div');
            callerBanner.className = 'call-caller-banner call-caller-banner-static';
        }

        var callerLabel = document.createElement('span');
        callerLabel.textContent = 'Numero chiamante';
        var callerValue = document.createElement('strong');
        callerValue.className = 'call-modal-number-value';
        callerValue.textContent = numberText;
        callerBanner.appendChild(callerLabel);
        callerBanner.appendChild(callerValue);

        var primaryMetric = document.createElement('div');
        primaryMetric.className = 'call-expanded-primary-metric';
        var primaryLabel = document.createElement('span');
        primaryLabel.textContent = 'Durata della chiamata';
        var primaryValue = document.createElement('strong');
        primaryValue.textContent = durationText;
        primaryMetric.appendChild(primaryLabel);
        primaryMetric.appendChild(primaryValue);

        var details = document.createElement('dl');
        details.className = 'card-detail-grid call-expanded-detail-grid';

        function appendDetail(labelText, valueText, extraClass) {
            var item = document.createElement('div');
            item.className = 'card-detail-tile' + (extraClass ? ' ' + extraClass : '');
            var label = document.createElement('dt');
            label.textContent = labelText;
            var value = document.createElement('dd');
            value.textContent = valueText;
            item.appendChild(label);
            item.appendChild(value);
            details.appendChild(item);
        }

        appendDetail('Data della chiamata', dateText);
        appendDetail('Ora della chiamata', timeText);
        appendDetail('Piano tariffario', planText);
        appendDetail('Addebito', chargeText, 'call-charge-detail');

        expanded.appendChild(header);
        expanded.appendChild(callerBanner);
        expanded.appendChild(primaryMetric);
        expanded.appendChild(details);
        return expanded;
    }

    function prepareCardModalClone(card) {
        var clone = resetCardModalTransientState(card.cloneNode(true));
        clone = buildExpandedCallModalCard(clone);
        clone.classList.add('card-modal-card');
        clone.classList.remove('expandable-card');
        clone.removeAttribute('data-expandable-card');
        clone.removeAttribute('tabindex');
        clone.removeAttribute('role');
        clone.removeAttribute('aria-label');
        clone.querySelectorAll('[data-card-expand-ready]').forEach(function (node) {
            node.removeAttribute('data-card-expand-ready');
        });
        clone.querySelectorAll('[data-clickable-tile-ready]').forEach(function (node) {
            node.removeAttribute('data-clickable-tile-ready');
        });
        clone.querySelectorAll('[data-phone-card-modal-ready]').forEach(function (node) {
            node.removeAttribute('data-phone-card-modal-ready');
        });
        clone.querySelectorAll('[data-sim-card-modal-ready]').forEach(function (node) {
            node.removeAttribute('data-sim-card-modal-ready');
        });
        clone.querySelectorAll('[data-sim-history-modal-ready]').forEach(function (node) {
            node.removeAttribute('data-sim-history-modal-ready');
        });
        clone.querySelectorAll('[data-table-row-modal-ready]').forEach(function (node) {
            node.removeAttribute('data-table-row-modal-ready');
        });
        return clone;
    }



    function disableDisabledSimPhoneLinkInsideModal(card) {
        if (!card || !card.classList || !card.classList.contains('sim-card-disabled')) {
            return;
        }

        card.querySelectorAll('.sim-phone-tile').forEach(function (tile) {
            var link = tile.querySelector('a[data-phone-card-modal="true"], a.tile-overlay-link');
            if (!link) {
                return;
            }

            var replacement = document.createElement('span');
            replacement.className = link.className.replace('tile-overlay-link', 'tile-static-label').trim();
            replacement.textContent = link.textContent;
            replacement.setAttribute('aria-hidden', 'true');
            link.replaceWith(replacement);

            tile.classList.remove('card-detail-link');
            tile.classList.add('card-detail-static');
            tile.removeAttribute('data-clickable-tile-ready');
        });
    }

    function renderCardModalClone(overlay, clone) {
        var content = overlay.querySelector('[data-card-modal-content="true"]');
        if (!content) {
            return;
        }

        var preparedClone = prepareCardModalClone(clone);
        var dialog = overlay.querySelector('.card-modal-dialog');
        if (dialog) {
            dialog.classList.toggle('card-modal-dialog-call', preparedClone.classList.contains('call-card-expanded-detail'));
        }
        disableDisabledSimPhoneLinkInsideModal(preparedClone);
        content.innerHTML = '';
        content.appendChild(preparedClone);
        initPhoneCardModalLinks(preparedClone);
        initSimCardModalLinks(preparedClone);
        initSimHistoryModalLinks(preparedClone);
        initClickableDetailTiles(preparedClone);
        initTableRowModals(preparedClone);
    }

    function closeCardModal() {
        var overlay = document.querySelector('[data-card-modal="true"]');
        if (!overlay) {
            return;
        }

        cardModalHistory = [];
        cardModalRootIsCall = false;
        overlay.classList.remove('is-visible');
        overlay.setAttribute('aria-hidden', 'true');
        document.body.classList.remove('card-modal-open');
        setBackgroundInert(false, overlay);

        if (cardModalPreviousFocus && document.contains(cardModalPreviousFocus)) {
            var shouldAvoidRestoringVisualFocus = Boolean(cardModalPreviousFocus.closest('.data-card, .call-number-link, .card-detail-link, [data-phone-card-modal="true"], [data-sim-card-modal="true"], [data-sim-history-modal="true"]'));
            if (shouldAvoidRestoringVisualFocus) {
                cardModalPreviousFocus.blur();
            } else {
                cardModalPreviousFocus.focus({ preventScroll: true });
            }
        }
        cardModalPreviousFocus = null;
        if (document.activeElement && document.activeElement !== document.body && document.activeElement.closest && document.activeElement.closest('.data-card, .card-modal-overlay')) {
            document.activeElement.blur();
        }
    }

    function goBackCardModal() {
        var overlay = document.querySelector('[data-card-modal="true"]');
        if (!overlay || !overlay.classList.contains('is-visible')) {
            closeCardModal();
            return;
        }

        if (cardModalHistory.length > 0) {
            var previousCard = cardModalHistory.pop();
            renderCardModalClone(overlay, previousCard);
            var backButton = overlay.querySelector('[data-card-modal-close="true"]');
            if (backButton) {
                backButton.focus();
            }
            return;
        }

        closeCardModal();
    }

    function openCardModal(card) {
        var overlay = ensureCardModal();
        var content = overlay.querySelector('[data-card-modal-content="true"]');
        if (!content) {
            return;
        }

        var wasVisible = overlay.classList.contains('is-visible');
        if (wasVisible) {
            var currentCard = content.querySelector('.card-modal-card');
            if (currentCard) {
                updateCardModalHistory(currentCard, card);
            }
        } else {
            cardModalHistory = [];
            cardModalRootIsCall = getCardModalType(card) === 'call';
            if (document.activeElement instanceof HTMLElement) {
                cardModalPreviousFocus = document.activeElement;
            }
        }

        renderCardModalClone(overlay, card);

        document.body.classList.add('card-modal-open');
        overlay.setAttribute('aria-hidden', 'false');
        overlay.classList.add('is-visible');
        setBackgroundInert(true, overlay);

        var backButton = overlay.querySelector('[data-card-modal-close="true"]');
        if (backButton) {
            backButton.focus();
        }
    }

    function shouldKeepDefaultLinkNavigation(event) {
        return event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey;
    }

    function setPhoneCardLinkLoading(link, isLoading) {
        link.classList.toggle('is-loading', isLoading);
        link.setAttribute('aria-busy', isLoading ? 'true' : 'false');
    }

    function openPhoneCardFromCallLink(link) {
        if (!window.fetch || !window.URL) {
            window.location.href = link.href;
            return;
        }

        var requestUrl = new URL(link.href, window.location.href);
        requestUrl.searchParams.set('ajax_rows', '1');
        requestUrl.searchParams.set('limit', '1');
        requestUrl.searchParams.set('offset', '0');

        setPhoneCardLinkLoading(link, true);

        fetch(requestUrl.toString(), {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('Risposta non valida');
                }
                return response.json();
            })
            .then(function (payload) {
                var wrapper = document.createElement('div');
                wrapper.innerHTML = payload && payload.html ? payload.html : '';

                var phoneCard = wrapper.querySelector('.phone-card');
                if (!phoneCard) {
                    throw new Error('Numero non trovato');
                }

                openCardModal(phoneCard);
            })
            .catch(function () {
                window.location.href = link.href;
            })
            .finally(function () {
                setPhoneCardLinkLoading(link, false);
            });
    }

    function openSimCardFromLink(link) {
        if (!window.fetch || !window.URL) {
            window.location.href = link.href;
            return;
        }

        var requestUrl = new URL(link.href, window.location.href);
        requestUrl.searchParams.set('ajax_rows', '1');
        requestUrl.searchParams.set('limit', '1');
        requestUrl.searchParams.set('offset', '0');
        requestUrl.searchParams.set('stato', requestUrl.searchParams.get('stato') || 'disattive');

        setPhoneCardLinkLoading(link, true);

        fetch(requestUrl.toString(), {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('Risposta non valida');
                }
                return response.json();
            })
            .then(function (payload) {
                var wrapper = document.createElement('div');
                wrapper.innerHTML = payload && payload.html ? payload.html : '';

                var simCard = wrapper.querySelector('.sim-card');
                if (!simCard) {
                    throw new Error('SIM non trovata');
                }

                openCardModal(simCard);
            })
            .catch(function () {
                window.location.href = link.href;
            })
            .finally(function () {
                setPhoneCardLinkLoading(link, false);
            });
    }

    function openSimHistoryFromLink(link) {
        if (!window.fetch || !window.URL) {
            window.location.href = link.href;
            return;
        }

        var requestUrl = new URL(link.href, window.location.href);
        requestUrl.searchParams.set('ajax_rows', '1');
        requestUrl.searchParams.set('limit', '60');
        requestUrl.searchParams.set('offset', '0');
        requestUrl.searchParams.set('stato', 'disattive');

        setPhoneCardLinkLoading(link, true);

        fetch(requestUrl.toString(), {
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
            .then(function (response) {
                if (!response.ok) {
                    throw new Error('Risposta non valida');
                }
                return response.json();
            })
            .then(function (payload) {
                var wrapper = document.createElement('div');
                wrapper.innerHTML = payload && payload.html ? payload.html : '';
                var simCards = Array.prototype.slice.call(wrapper.querySelectorAll('.sim-card'));
                if (simCards.length === 0) {
                    throw new Error('SIM non trovate');
                }

                var group = document.createElement('div');
                group.className = 'card-modal-card-group';
                group.setAttribute('aria-label', 'SIM precedenti collegate al numero');
                simCards.forEach(function (card) {
                    card.classList.remove('expandable-card');
                    card.removeAttribute('data-expandable-card');
                    card.removeAttribute('tabindex');
                    card.removeAttribute('role');
                    card.removeAttribute('aria-label');
                    group.appendChild(card);
                });
                openCardModal(group);
            })
            .catch(function () {
                window.location.href = link.href;
            })
            .finally(function () {
                setPhoneCardLinkLoading(link, false);
            });
    }

    function initSimHistoryModalLinks(root) {
        var scope = root || document;
        scope.querySelectorAll('[data-sim-history-modal="true"]:not([data-sim-history-modal-ready="true"])').forEach(function (link) {
            link.dataset.simHistoryModalReady = 'true';
            link.addEventListener('click', function (event) {
                if (shouldKeepDefaultLinkNavigation(event)) {
                    return;
                }
                event.preventDefault();
                event.stopPropagation();
                openSimHistoryFromLink(link);
            });
        });
    }

    function initPhoneCardModalLinks(root) {
        var scope = root || document;
        scope.querySelectorAll('[data-phone-card-modal="true"]:not([data-phone-card-modal-ready="true"])').forEach(function (link) {
            link.dataset.phoneCardModalReady = 'true';

            link.addEventListener('click', function (event) {
                if (shouldKeepDefaultLinkNavigation(event)) {
                    return;
                }

                event.preventDefault();
                event.stopPropagation();
                openPhoneCardFromCallLink(link);
            });
        });
    }

    function initSimCardModalLinks(root) {
        var scope = root || document;
        scope.querySelectorAll('[data-sim-card-modal="true"]:not([data-sim-card-modal-ready="true"])').forEach(function (link) {
            link.dataset.simCardModalReady = 'true';

            link.addEventListener('click', function (event) {
                if (shouldKeepDefaultLinkNavigation(event)) {
                    return;
                }

                event.preventDefault();
                event.stopPropagation();
                openSimCardFromLink(link);
            });
        });
    }

    function countLoadedCards(container) {
        if (!container) {
            return 0;
        }
        return container.querySelectorAll('[data-lazy-list="true"] > .data-card').length;
    }

    function initSimReturnLinks(root) {
        var scope = root || document;
        scope.querySelectorAll('.action-disable-sim:not([data-sim-return-ready="true"]), .action-edit-sim:not([data-sim-return-ready="true"]), .action-delete-sim:not([data-sim-return-ready="true"])').forEach(function (link) {
            link.dataset.simReturnReady = 'true';
        });
    }

    function getResultListItemsAnyVisibility(list) {
        if (!list) {
            return [];
        }
        return Array.prototype.filter.call(list.children, function (child) {
            return child.nodeType === 1;
        });
    }

    function findCardForTableRow(row) {
        var viewRoot = row.closest('[data-results-view-root="true"]');
        if (!viewRoot) {
            return null;
        }

        var tableList = row.closest('[data-lazy-list="table"]');
        var cardList = viewRoot.querySelector('[data-view-panel="cards"] [data-lazy-list="cards"]');
        if (!tableList || !cardList) {
            return null;
        }

        /* La vista a schede è nascosta quando l'utente usa la vista tabellare.
           Per aprire comunque il dettaglio della riga, qui non possiamo usare
           getBoundingClientRect(): le card nascoste avrebbero altezza 0 e quindi
           non verrebbero trovate. Manteniamo l'allineamento riga-card per indice. */
        var rows = getResultListItemsAnyVisibility(tableList);
        var cards = getResultListItemsAnyVisibility(cardList);
        var index = rows.indexOf(row);
        if (index < 0 || !cards[index]) {
            return null;
        }

        return cards[index];
    }

    function isTableRowModalAction(target) {
        return Boolean(target.closest('a[data-phone-card-modal="true"], a[data-sim-card-modal="true"], a[data-sim-history-modal="true"], button, input, select, textarea, label, .table-action-group, .action-disable-sim, .action-edit-sim, .action-delete-sim, [data-card-modal-exclude="true"]'));
    }

    function initTableRowModals(root) {
        var scope = root || document;
        scope.querySelectorAll('[data-view-panel="table"] tbody[data-lazy-list="table"] tr:not([data-table-row-modal-ready="true"])').forEach(function (row) {
            row.dataset.tableRowModalReady = 'true';
            row.setAttribute('tabindex', '0');
            row.setAttribute('role', 'button');
            row.setAttribute('aria-label', 'Apri il dettaglio del record');

            row.addEventListener('click', function (event) {
                if (shouldKeepDefaultLinkNavigation(event) || isTableRowModalAction(event.target)) {
                    return;
                }

                var card = findCardForTableRow(row);
                if (!card) {
                    return;
                }

                event.preventDefault();
                event.stopPropagation();
                openCardModal(card);
            }, true);

            row.addEventListener('keydown', function (event) {
                if (event.key !== 'Enter' && event.key !== ' ') {
                    return;
                }
                if (isTableRowModalAction(event.target)) {
                    return;
                }

                var card = findCardForTableRow(row);
                if (!card) {
                    return;
                }

                event.preventDefault();
                event.stopPropagation();
                openCardModal(card);
            });
        });
    }

    function initExpandableCards(root) {
        var scope = root || document;
        scope.querySelectorAll('[data-expandable-card="true"]:not([data-card-expand-ready="true"])').forEach(function (card) {
            card.dataset.cardExpandReady = 'true';

            card.addEventListener('click', function (event) {
                if (isCardModalExcluded(event.target)) {
                    return;
                }
                openCardModal(card);
            });

            card.addEventListener('keydown', function (event) {
                if ((event.key !== 'Enter' && event.key !== ' ') || isCardModalExcluded(event.target)) {
                    return;
                }
                event.preventDefault();
                openCardModal(card);
            });
        });
    }


    var RESULTS_VIEW_STORAGE_KEY = 'progwebResultsView';

    function getStoredResultsView(root) {
        if (!window.localStorage) {
            return 'cards';
        }
        try {
            var globalView = window.localStorage.getItem(RESULTS_VIEW_STORAGE_KEY);
            if (globalView === 'table' || globalView === 'cards') {
                return globalView;
            }

            /* Migrazione morbida dalle prime versioni, dove la preferenza era salvata
               separatamente per ogni pagina. */
            var oldKey = root && root.dataset.viewKey ? 'progwebResultsView:' + root.dataset.viewKey : '';
            var oldView = oldKey ? window.localStorage.getItem(oldKey) : '';
            return oldView === 'table' ? 'table' : 'cards';
        } catch (error) {
            return 'cards';
        }
    }

    function storeResultsView(view) {
        if (!window.localStorage) {
            return;
        }
        try {
            window.localStorage.setItem(RESULTS_VIEW_STORAGE_KEY, view === 'table' ? 'table' : 'cards');
        } catch (error) {
            // Preferenza non persistibile: il cambio vista resta comunque valido nella pagina corrente.
        }
    }


    function getResultsScrollContainer(root) {
        return root ? root.querySelector('[data-lazy-container="true"]') : null;
    }

    function getActiveResultsList(root, view) {
        if (!root) {
            return null;
        }
        var normalizedView = view === 'table' ? 'table' : 'cards';
        var panel = root.querySelector('[data-view-panel="' + normalizedView + '"]');
        return panel ? panel.querySelector('[data-lazy-list]') : null;
    }

    function getTableHeaderHeightForRoot(root) {
        if (!root) {
            return 0;
        }
        var tablePanel = root.querySelector('[data-view-panel="table"]');
        var header = tablePanel ? tablePanel.querySelector('table.data-table thead') : null;
        if (!header) {
            return 0;
        }
        var rect = header.getBoundingClientRect();
        return rect && rect.height ? Math.ceil(rect.height) : 0;
    }

    function getResultListItems(list) {
        if (!list) {
            return [];
        }
        return Array.prototype.filter.call(list.children, function (child) {
            return child.nodeType === 1 && child.getBoundingClientRect().height > 0;
        });
    }

    function captureResultsScrollPosition(root) {
        var container = getResultsScrollContainer(root);
        if (!container) {
            return null;
        }

        var currentView = root.dataset.currentView === 'table' ? 'table' : 'cards';
        var list = getActiveResultsList(root, currentView);
        var items = getResultListItems(list);
        var maxScroll = Math.max(0, container.scrollHeight - container.clientHeight);
        var anchor = {
            view: currentView,
            sourceTableHeaderHeight: currentView === 'table' ? getTableHeaderHeightForRoot(root) : 0,
            ratio: maxScroll > 0 ? container.scrollTop / maxScroll : 0,
            scrollTop: container.scrollTop,
            itemIndex: null,
            itemDelta: 0
        };

        if (items.length === 0) {
            return anchor;
        }

        var containerRect = container.getBoundingClientRect();
        var referenceTop = containerRect.top + 12;
        for (var i = 0; i < items.length; i += 1) {
            var rect = items[i].getBoundingClientRect();
            if (rect.bottom >= referenceTop) {
                anchor.itemIndex = i;
                anchor.itemDelta = rect.top - containerRect.top;
                break;
            }
        }

        return anchor;
    }

    function restoreResultsScrollPosition(root, anchor) {
        if (!anchor) {
            return;
        }

        var container = getResultsScrollContainer(root);
        if (!container) {
            return;
        }

        function applyRestore() {
            var currentView = root.dataset.currentView === 'table' ? 'table' : 'cards';
            var list = getActiveResultsList(root, currentView);
            var items = getResultListItems(list);
            var maxScroll = Math.max(0, container.scrollHeight - container.clientHeight);

            if (anchor.itemIndex !== null && items[anchor.itemIndex]) {
                var containerRect = container.getBoundingClientRect();
                var itemRect = items[anchor.itemIndex].getBoundingClientRect();
                var desiredDelta = anchor.itemDelta;

                /* Quando si passa da schede a tabella, le righe hanno sopra l'intestazione
                   della tabella. Se non la consideriamo, il ripristino porta la prima riga
                   troppo in alto e l'header risulta coperto/tagliato. */
                if (anchor.view === 'cards' && currentView === 'table') {
                    desiredDelta += getTableHeaderHeightForRoot(root);
                } else if (anchor.view === 'table' && currentView === 'cards') {
                    desiredDelta -= (anchor.sourceTableHeaderHeight || 0);
                }
                desiredDelta = Math.max(8, desiredDelta);

                var delta = (itemRect.top - containerRect.top) - desiredDelta;
                container.scrollTop += delta;
                return;
            }

            if (maxScroll > 0) {
                container.scrollTop = Math.min(maxScroll, Math.max(0, anchor.ratio * maxScroll));
            } else {
                container.scrollTop = 0;
            }
        }

        window.requestAnimationFrame(applyRestore);
        window.setTimeout(applyRestore, 80);
    }

    function applyResultsView(root, view, persist) {
        var normalizedView = view === 'table' ? 'table' : 'cards';
        var toggle = root.querySelector('[data-view-toggle="true"]');
        var label = toggle ? toggle.querySelector('[data-view-toggle-text]') : null;
        var icon = toggle ? toggle.querySelector('.view-toggle-icon') : null;

        root.dataset.currentView = normalizedView;

        if (toggle) {
            toggle.setAttribute('aria-pressed', normalizedView === 'table' ? 'true' : 'false');
            toggle.setAttribute('title', normalizedView === 'table' ? 'Mostra i risultati come schede' : 'Mostra i risultati come tabella');
        }
        if (label) {
            label.textContent = normalizedView === 'table' ? 'Vista a schede' : 'Vista tabellare';
        }
        if (icon) {
            icon.textContent = normalizedView === 'table' ? '▦' : '▤';
        }
        if (persist) {
            storeResultsView(normalizedView);
        }
        updateResultsNavigation(root);
    }

    function applyResultsViewToAllRoots(view, persist) {
        document.querySelectorAll('[data-results-view-root="true"]').forEach(function (viewRoot) {
            applyResultsView(viewRoot, view, false);
        });
        if (persist) {
            storeResultsView(view);
        }
    }


    function getLoadedResultsCountForView(root) {
        var currentView = root && root.dataset.currentView === 'table' ? 'table' : 'cards';
        var list = getActiveResultsList(root, currentView);
        return getResultListItems(list).length;
    }

    function updateResultsNavigation(root) {
        if (!root) {
            return;
        }
        var nav = root.querySelector('[data-results-navigation="true"]');
        var container = getResultsScrollContainer(root);
        if (!nav || !container) {
            return;
        }

        var counter = nav.querySelector('[data-results-counter="true"]');
        var firstButton = nav.querySelector('[data-results-first="true"]');
        var prevButton = nav.querySelector('[data-results-page-prev="true"]');
        var nextButton = nav.querySelector('[data-results-page-next="true"]');
        var lastButton = nav.querySelector('[data-results-last="true"]');
        var totalRaw = container.dataset.totalCount || '';
        var countPending = container.dataset.countPending === '1';
        var totalKnown = !countPending && totalRaw !== '';
        var total = totalKnown ? parseInt(totalRaw, 10) : 0;
        var loaded = getLoadedResultsCountForView(root);
        var prevOffset = parseInt(container.dataset.prevOffset || '0', 10);
        var nextOffset = parseInt(container.dataset.nextOffset || String(prevOffset + loaded), 10);

        if (!Number.isFinite(total) || total < 0) {
            total = 0;
            totalKnown = false;
        }
        if (!Number.isFinite(prevOffset) || prevOffset < 0) {
            prevOffset = 0;
        }
        if (!Number.isFinite(nextOffset) || nextOffset < prevOffset) {
            nextOffset = prevOffset + loaded;
        }

        var start = loaded === 0 ? 0 : prevOffset + 1;
        var end = loaded === 0 ? 0 : (totalKnown ? Math.min(total, nextOffset) : nextOffset);

        if (counter) {
            if (loaded === 0 && (!totalKnown || total === 0)) {
                counter.textContent = '0 risultati';
            } else if (!totalKnown) {
                counter.textContent = start + '-' + end + ' risultati';
            } else {
                counter.textContent = start + '-' + end + ' di ' + total + ' risultati';
            }
        }

        var canMovePrev = container.scrollTop > 4 || container.dataset.hasPrev === '1';
        var canMoveNext = (container.scrollTop + container.clientHeight) < (container.scrollHeight - 4) || container.dataset.hasMore === '1';

        if (firstButton) {
            firstButton.disabled = !canMovePrev;
            firstButton.setAttribute('aria-disabled', canMovePrev ? 'false' : 'true');
        }
        if (prevButton) {
            prevButton.disabled = !canMovePrev;
            prevButton.setAttribute('aria-disabled', canMovePrev ? 'false' : 'true');
        }
        if (nextButton) {
            nextButton.disabled = !canMoveNext;
            nextButton.setAttribute('aria-disabled', canMoveNext ? 'false' : 'true');
        }
        if (lastButton) {
            var canJumpLast = totalKnown && canMoveNext;
            lastButton.disabled = !canJumpLast;
            lastButton.setAttribute('aria-disabled', canJumpLast ? 'false' : 'true');
            lastButton.title = totalKnown ? 'Vai all\'ultimo risultato' : 'Calcolo del totale in corso';
        }
    }

    function initResultsNavigationControls(root) {
        var scope = root || document;
        scope.querySelectorAll('[data-results-view-root="true"]').forEach(function (viewRoot) {
            var nav = viewRoot.querySelector('[data-results-navigation="true"]');
            var container = getResultsScrollContainer(viewRoot);
            if (!nav || !container) {
                return;
            }

            if (!nav.dataset.resultsNavigationReady) {
                nav.dataset.resultsNavigationReady = 'true';

                var firstButton = nav.querySelector('[data-results-first="true"]');
                var prevButton = nav.querySelector('[data-results-page-prev="true"]');
                var nextButton = nav.querySelector('[data-results-page-next="true"]');
                var lastButton = nav.querySelector('[data-results-last="true"]');

                if (firstButton) {
                    firstButton.addEventListener('click', function () {
                        if (firstButton.disabled || !(window.ProgWeb && typeof window.ProgWeb.resetResultsToFirstBlock === 'function')) {
                            return;
                        }
                        firstButton.classList.add('is-working');
                        window.ProgWeb.resetResultsToFirstBlock(container).finally(function () {
                            firstButton.classList.remove('is-working');
                            updateResultsNavigation(viewRoot);
                        });
                    });
                }

                if (prevButton) {
                    prevButton.addEventListener('click', function () {
                        if (prevButton.disabled) {
                            return;
                        }
                        var step = Math.max(240, Math.floor(container.clientHeight * 0.82));
                        var loadedExtraRows = false;
                        if (container.dataset.hasPrev === '1' && container.scrollTop < 80 && window.ProgWeb && typeof window.ProgWeb.loadMoreRows === 'function') {
                            window.ProgWeb.loadMoreRows(container, 'prev');
                            loadedExtraRows = true;
                        }
                        var move = function () {
                            container.scrollBy({ top: -step, behavior: 'smooth' });
                            window.setTimeout(function () { updateResultsNavigation(viewRoot); }, 220);
                        };
                        if (loadedExtraRows) {
                            window.setTimeout(move, 320);
                        } else {
                            move();
                        }
                    });
                }

                if (nextButton) {
                    nextButton.addEventListener('click', function () {
                        if (nextButton.disabled) {
                            return;
                        }
                        var step = Math.max(240, Math.floor(container.clientHeight * 0.82));
                        var loadedExtraRows = false;
                        if (container.dataset.hasMore === '1' && container.scrollTop + container.clientHeight >= container.scrollHeight - 120 && window.ProgWeb && typeof window.ProgWeb.loadMoreRows === 'function') {
                            window.ProgWeb.loadMoreRows(container, 'next');
                            loadedExtraRows = true;
                        }
                        var move = function () {
                            container.scrollBy({ top: step, behavior: 'smooth' });
                            window.setTimeout(function () { updateResultsNavigation(viewRoot); }, 220);
                        };
                        if (loadedExtraRows) {
                            window.setTimeout(move, 320);
                        } else {
                            move();
                        }
                    });
                }

                if (lastButton) {
                    lastButton.addEventListener('click', function () {
                        if (lastButton.disabled || !(window.ProgWeb && typeof window.ProgWeb.jumpResultsToLastBlock === 'function')) {
                            return;
                        }
                        lastButton.classList.add('is-working');
                        window.ProgWeb.jumpResultsToLastBlock(container).finally(function () {
                            lastButton.classList.remove('is-working');
                            updateResultsNavigation(viewRoot);
                        });
                    });
                }

                container.addEventListener('scroll', function () {
                    window.requestAnimationFrame(function () {
                        updateResultsNavigation(viewRoot);
                    });
                }, { passive: true });
            }

            updateResultsNavigation(viewRoot);
        });
    }

    function getResultsScrollTopThreshold(container) {
        if (!container) {
            return 0;
        }
        return Math.max(420, Math.floor(container.clientHeight * 0.65));
    }

    function updateResultsScrollTopControl(viewRoot) {
        if (!viewRoot) {
            return;
        }

        var container = getResultsScrollContainer(viewRoot);
        var button = viewRoot.querySelector('[data-results-scroll-top="true"]');
        if (!container || !button) {
            return;
        }

        var canScroll = container.scrollHeight > container.clientHeight + 24;
        var loadedResults = getLoadedResultsCountForView(viewRoot);
        var isRemoteBlock = container.dataset.fromEnd === '1' || parseInt(container.dataset.prevOffset || '0', 10) > 0;
        var shouldShow = (canScroll || isRemoteBlock)
            && (loadedResults >= 15 || isRemoteBlock)
            && (container.scrollTop > getResultsScrollTopThreshold(container) || isRemoteBlock);
        button.classList.toggle('is-visible', shouldShow);
        button.setAttribute('aria-hidden', shouldShow ? 'false' : 'true');
        button.tabIndex = shouldShow ? 0 : -1;
    }

    function initResultsScrollTopControls(root) {
        var scope = root || document;
        scope.querySelectorAll('[data-results-view-root="true"]').forEach(function (viewRoot) {
            var container = getResultsScrollContainer(viewRoot);
            if (!container) {
                return;
            }

            var button = viewRoot.querySelector('[data-results-scroll-top="true"]');
            if (!button) {
                button = document.createElement('button');
                button.type = 'button';
                button.className = 'results-scroll-top-button';
                button.setAttribute('data-results-scroll-top', 'true');
                button.setAttribute('aria-label', "Torna all'inizio dei risultati");
                button.setAttribute('title', "Torna all'inizio dei risultati");
                button.setAttribute('aria-hidden', 'true');
                button.tabIndex = -1;
                button.innerHTML = '<img src="assets/images/icons/scroll-top-arrow.png?v=1" class="results-scroll-top-icon" alt="" aria-hidden="true">';
                viewRoot.appendChild(button);
            }

            if (!button.querySelector('.results-scroll-top-icon')) {
                button.textContent = '';
                button.insertAdjacentHTML('beforeend', '<img src="assets/images/icons/scroll-top-arrow.png?v=1" class="results-scroll-top-icon" alt="" aria-hidden="true">');
            }

            if (button.dataset.resultsScrollTopReady !== 'true') {
                button.dataset.resultsScrollTopReady = 'true';
                button.addEventListener('click', function () {
                    if (container.dataset.returningTop === 'true') {
                        return;
                    }

                    container.dataset.returningTop = 'true';
                    button.disabled = true;
                    button.classList.add('is-working');

                    var needsFirstBlock = container.dataset.fromEnd === '1'
                        || container.dataset.hasPrev === '1'
                        || parseInt(container.dataset.prevOffset || '0', 10) > 0;

                    var resetPromise = Promise.resolve(false);
                    if (needsFirstBlock && window.ProgWeb && typeof window.ProgWeb.resetResultsToFirstBlock === 'function') {
                        /* Quando siamo molto lontani dall'inizio sostituiamo subito
                           il blocco corrente: uno scroll animato attraverso milioni
                           di record attiverebbe caricamenti intermedi inutili. */
                        resetPromise = window.ProgWeb.resetResultsToFirstBlock(container);
                    } else {
                        container.scrollTo({ top: 0, behavior: 'smooth' });
                    }

                    resetPromise.finally(function () {
                        container.scrollTop = 0;
                        window.requestAnimationFrame(function () {
                            container.scrollTop = 0;
                        });
                        window.setTimeout(function () {
                            delete container.dataset.returningTop;
                            button.disabled = false;
                            button.classList.remove('is-working');
                            updateResultsNavigation(viewRoot);
                            updateResultsScrollTopControl(viewRoot);
                        }, 180);
                    });
                });
            }

            if (container.dataset.resultsScrollTopReady !== 'true') {
                container.dataset.resultsScrollTopReady = 'true';
                container.addEventListener('scroll', function () {
                    window.requestAnimationFrame(function () {
                        updateResultsScrollTopControl(viewRoot);
                    });
                }, { passive: true });
            }

            updateResultsScrollTopControl(viewRoot);
        });
    }


    function initResultsBoundaryScrollChaining(root) {
        var scope = root || document;
        scope.querySelectorAll('[data-lazy-container="true"]:not([data-boundary-scroll-ready="true"])').forEach(function (container) {
            container.dataset.boundaryScrollReady = 'true';

            container.addEventListener('wheel', function (event) {
                if (event.ctrlKey || event.deltaY === 0 || container.dataset.loadingRows === 'true') {
                    return;
                }

                var maxScrollTop = Math.max(0, container.scrollHeight - container.clientHeight);
                if (maxScrollTop <= 1) {
                    return;
                }

                var atTop = container.scrollTop <= 1;
                var atBottom = container.scrollTop >= maxScrollTop - 1;
                var goingUp = event.deltaY < 0;
                var goingDown = event.deltaY > 0;

                if (goingUp && atTop) {
                    if (container.dataset.hasPrev === '1' && window.ProgWeb && typeof window.ProgWeb.loadMoreRows === 'function') {
                        event.preventDefault();
                        window.ProgWeb.loadMoreRows(container, 'prev');
                        return;
                    }
                    event.preventDefault();
                    window.scrollBy({ top: event.deltaY, left: 0, behavior: 'auto' });
                    return;
                }

                if (goingDown && atBottom) {
                    if (container.dataset.hasMore === '1' && window.ProgWeb && typeof window.ProgWeb.loadMoreRows === 'function') {
                        event.preventDefault();
                        window.ProgWeb.loadMoreRows(container, 'next');
                        return;
                    }
                    event.preventDefault();
                    window.scrollBy({ top: event.deltaY, left: 0, behavior: 'auto' });
                }
            }, { passive: false });
        });
    }

    function initResultsViewControls(root) {
        var scope = root || document;
        scope.querySelectorAll('[data-results-view-root="true"]:not([data-results-view-ready="true"])').forEach(function (viewRoot) {
            var initialView = getStoredResultsView(viewRoot);
            viewRoot.dataset.resultsViewReady = 'true';
            applyResultsView(viewRoot, initialView, false);

            var toggle = viewRoot.querySelector('[data-view-toggle="true"]');
            if (!toggle) {
                return;
            }

            toggle.addEventListener('click', function () {
                var scrollAnchor = captureResultsScrollPosition(viewRoot);
                var nextView = viewRoot.dataset.currentView === 'table' ? 'cards' : 'table';
                applyResultsViewToAllRoots(nextView, true);
                restoreResultsScrollPosition(viewRoot, scrollAnchor);
            });
        });
    }

    function initSingleResultGrids(root) {
        var scope = root || document;
        scope.querySelectorAll('.result-grid').forEach(function (grid) {
            var directCards = Array.prototype.filter.call(grid.children, function (child) {
                return child.classList && child.classList.contains('data-card');
            });
            var isSingle = directCards.length === 1;
            grid.classList.toggle('is-single-result-grid', isSingle);

            var scrollContainer = grid.closest('.cards-container');
            if (scrollContainer) {
                scrollContainer.classList.toggle('is-single-result-container', isSingle);
            }
        });
    }

    function activateTileLink(tile, event) {
        var link = tile.querySelector('a[href]');
        if (!link || shouldKeepDefaultLinkNavigation(event)) {
            return;
        }

        if (event.target.closest('button, input, select, textarea, label')) {
            return;
        }

        event.preventDefault();
        event.stopPropagation();

        if (link.matches('[data-sim-card-modal="true"]')) {
            openSimCardFromLink(link);
            return;
        }

        if (link.matches('[data-sim-history-modal="true"]')) {
            openSimHistoryFromLink(link);
            return;
        }

        if (link.matches('[data-phone-card-modal="true"]')) {
            openPhoneCardFromCallLink(link);
            return;
        }

        window.location.href = link.href;
    }

    function initClickableDetailTiles(root) {
        var scope = root || document;
        scope.querySelectorAll('.card-detail-link:not([data-clickable-tile-ready="true"])').forEach(function (tile) {
            var link = tile.querySelector('a[href]');
            if (!link) {
                return;
            }

            tile.dataset.clickableTileReady = 'true';
            tile.setAttribute('role', 'link');
            tile.setAttribute('tabindex', '0');
            if (link.getAttribute('title')) {
                tile.setAttribute('title', link.getAttribute('title'));
            }

            tile.addEventListener('click', function (event) {
                activateTileLink(tile, event);
            });

            tile.addEventListener('keydown', function (event) {
                if (event.key !== 'Enter' && event.key !== ' ') {
                    return;
                }
                if (event.target.closest('button, input, select, textarea, label')) {
                    return;
                }
                event.preventDefault();
                event.stopPropagation();

                if (link.matches('[data-sim-card-modal="true"]')) {
                    openSimCardFromLink(link);
                    return;
                }

                if (link.matches('[data-sim-history-modal="true"]')) {
                    openSimHistoryFromLink(link);
                    return;
                }

                if (link.matches('[data-phone-card-modal="true"]')) {
                    openPhoneCardFromCallLink(link);
                    return;
                }

                window.location.href = link.href;
            });
        });
    }


    function initDashboardQuickSearchReset(root) {
        var scope = root || document;
        var input = scope.querySelector('#ricerca_globale');

        if (!input || input.dataset.dashboardResetReady === 'true') {
            return;
        }

        input.dataset.dashboardResetReady = 'true';

        function clearPreviousResultsWhenEmpty() {
            if ((input.value || '').trim() !== '') {
                return;
            }

            var layout = input.closest('.dashboard-search-layout');
            if (!layout) {
                return;
            }

            var output = layout.querySelector('.dashboard-search-output');
            if (output) {
                output.remove();
            }

            layout.querySelectorAll('.dashboard-search-feedback').forEach(function (feedback) {
                feedback.remove();
            });
        }

        input.addEventListener('input', clearPreviousResultsWhenEmpty);
        input.addEventListener('change', clearPreviousResultsWhenEmpty);
    }


    /* =========================================================
       Stato dei filtri e posizione dei risultati nella sessione
       ========================================================= */
    var FILTER_SESSION_PREFIX = 'progweb:filter-session:v1:';
    var filterDefaultStates = new WeakMap();
    var pendingFilterRestores = Object.create(null);
    var filterSaveTimers = new WeakMap();
    var filterSessionGlobalsReady = false;

    function getFilterSessionStorageKey(form) {
        var key = form ? (form.dataset.filterSessionKey || '') : '';
        return key ? FILTER_SESSION_PREFIX + key : '';
    }

    function readFilterSessionState(form) {
        var key = getFilterSessionStorageKey(form);
        if (!key || !window.sessionStorage) {
            return null;
        }
        try {
            var parsed = JSON.parse(window.sessionStorage.getItem(key) || 'null');
            return parsed && typeof parsed === 'object' ? parsed : null;
        } catch (error) {
            window.sessionStorage.removeItem(key);
            return null;
        }
    }

    function writeFilterSessionState(form, state) {
        var key = getFilterSessionStorageKey(form);
        if (!key || !window.sessionStorage) {
            return;
        }
        try {
            window.sessionStorage.setItem(key, JSON.stringify(state));
        } catch (error) {
            /* Il sito resta pienamente utilizzabile anche se lo storage non e disponibile. */
        }
    }

    function removeFilterSessionState(form) {
        var key = getFilterSessionStorageKey(form);
        if (!key || !window.sessionStorage) {
            return;
        }
        try {
            window.sessionStorage.removeItem(key);
        } catch (error) {
            /* Nessuna azione necessaria. */
        }
    }

    function isPersistableFilterControl(control) {
        if (!control || !control.name || control.matches('[data-export-submit="true"]')) {
            return false;
        }
        var type = (control.type || '').toLowerCase();
        return ['submit', 'button', 'reset', 'image', 'file'].indexOf(type) === -1;
    }

    function captureFilterValues(form) {
        var values = Object.create(null);
        if (!form) {
            return values;
        }

        Array.prototype.forEach.call(form.elements, function (control) {
            if (!isPersistableFilterControl(control)) {
                return;
            }
            var name = control.name;
            if (!Object.prototype.hasOwnProperty.call(values, name)) {
                values[name] = [];
            }

            var type = (control.type || '').toLowerCase();
            if (type === 'checkbox' || type === 'radio') {
                if (control.checked) {
                    values[name].push(control.value);
                }
                return;
            }

            if (control instanceof HTMLSelectElement && control.multiple) {
                Array.prototype.forEach.call(control.options, function (option) {
                    if (option.selected) {
                        values[name].push(option.value);
                    }
                });
                return;
            }

            values[name] = [control.value || ''];
        });

        return values;
    }

    function applyFilterValues(form, values) {
        if (!form || !values || typeof values !== 'object') {
            return;
        }

        Array.prototype.forEach.call(form.elements, function (control) {
            if (!isPersistableFilterControl(control) || !Object.prototype.hasOwnProperty.call(values, control.name)) {
                return;
            }
            var savedValues = Array.isArray(values[control.name]) ? values[control.name] : [values[control.name]];
            var type = (control.type || '').toLowerCase();

            if (type === 'checkbox' || type === 'radio') {
                control.checked = savedValues.indexOf(control.value) !== -1;
                return;
            }

            if (control instanceof HTMLSelectElement && control.multiple) {
                Array.prototype.forEach.call(control.options, function (option) {
                    option.selected = savedValues.indexOf(option.value) !== -1;
                });
                return;
            }

            control.value = savedValues.length ? savedValues[0] : '';
        });
    }

    function stableFilterValuesString(values) {
        var normalized = {};
        Object.keys(values || {}).sort().forEach(function (key) {
            normalized[key] = (values[key] || []).slice().sort();
        });
        return JSON.stringify(normalized);
    }

    function updateFilterResetButton(form) {
        if (!form) {
            return;
        }
        var button = form.querySelector('[data-filter-reset="true"]');
        var defaults = filterDefaultStates.get(form);
        if (!button || !defaults) {
            return;
        }
        var isDefault = stableFilterValuesString(captureFilterValues(form)) === stableFilterValuesString(defaults);
        button.disabled = isDefault;
        button.classList.toggle('is-disabled', isDefault);
        button.setAttribute('aria-disabled', isDefault ? 'true' : 'false');
    }

    function getResultsRootForFilterForm(form) {
        var selector = form ? (form.dataset.updateTarget || '') : '';
        return selector ? document.querySelector(selector) : null;
    }

    function getActiveLazyList(container) {
        if (!container) {
            return null;
        }
        var viewRoot = container.closest('[data-results-view-root="true"]');
        var currentView = viewRoot ? (viewRoot.dataset.currentView || 'cards') : 'cards';
        var listType = currentView === 'table' ? 'table' : 'cards';
        return container.querySelector('[data-view-panel="' + currentView + '"] [data-lazy-list="' + listType + '"]')
            || container.querySelector('[data-lazy-list="' + listType + '"]');
    }

    function getResultsContentTop(container) {
        var rect = container.getBoundingClientRect();
        var viewRoot = container.closest('[data-results-view-root="true"]');
        if (viewRoot && viewRoot.dataset.currentView === 'table') {
            var head = container.querySelector('[data-view-panel="table"] thead');
            if (head) {
                return rect.top + head.getBoundingClientRect().height;
            }
        }
        return rect.top;
    }

    function captureFilterResultsPosition(form) {
        var resultsRoot = getResultsRootForFilterForm(form);
        var container = resultsRoot ? resultsRoot.querySelector('[data-lazy-container="true"]') : null;
        if (!container) {
            return {
                anchorOffset: 0,
                anchorViewportOffset: 0,
                scrollTop: 0,
                scrollLeft: 0,
                windowScrollX: window.pageXOffset || 0,
                windowScrollY: window.pageYOffset || 0
            };
        }

        var list = getActiveLazyList(container);
        var items = list ? Array.prototype.slice.call(list.children) : [];
        var contentTop = getResultsContentTop(container);
        var containerRect = container.getBoundingClientRect();
        var anchorIndex = 0;
        var anchorViewportOffset = 0;

        for (var index = 0; index < items.length; index += 1) {
            var itemRect = items[index].getBoundingClientRect();
            if (itemRect.bottom > contentTop + 1 && itemRect.top < containerRect.bottom) {
                anchorIndex = index;
                anchorViewportOffset = itemRect.top - contentTop;
                break;
            }
        }

        var loadedStart = parseInt(container.dataset.prevOffset || '0', 10);
        if (!Number.isFinite(loadedStart) || loadedStart < 0) {
            loadedStart = 0;
        }
        var totalCount = parseInt(container.dataset.totalCount || '0', 10);

        return {
            anchorOffset: loadedStart + anchorIndex,
            anchorViewportOffset: anchorViewportOffset,
            scrollTop: container.scrollTop,
            scrollLeft: container.scrollLeft,
            windowScrollX: window.pageXOffset || 0,
            windowScrollY: window.pageYOffset || 0,
            fromEnd: container.dataset.fromEnd === '1',
            totalCount: Number.isFinite(totalCount) ? totalCount : 0
        };
    }

    function saveFilterSessionState(form, preserveStoredPosition) {
        if (!form || form.dataset.sessionResetting === 'true') {
            return;
        }
        var previous = readFilterSessionState(form) || {};
        var position = preserveStoredPosition && previous.position
            ? previous.position
            : captureFilterResultsPosition(form);
        writeFilterSessionState(form, {
            fields: captureFilterValues(form),
            position: position,
            savedAt: Date.now()
        });
        updateFilterResetButton(form);
    }

    function scheduleFilterSessionSave(form) {
        if (!form || form.dataset.sessionRestoring === 'true' || form.dataset.sessionResetting === 'true') {
            return;
        }
        var previousTimer = filterSaveTimers.get(form);
        if (previousTimer) {
            window.clearTimeout(previousTimer);
        }
        var timer = window.setTimeout(function () {
            filterSaveTimers.delete(form);
            saveFilterSessionState(form, false);
        }, 180);
        filterSaveTimers.set(form, timer);
    }

    function synchronizeFilterFormUi(form) {
        if (!form) {
            return;
        }

        if (form.matches('.contratti-filter-form')) {
            delete form.dataset.planAutoSet;
            delete form.dataset.planPreviousValue;
            var plan = form.querySelector('#tipo');
            var residual = form.querySelector('#residuo');
            if (plan) {
                setDependencyLocked(plan, false);
            }
            if (residual) {
                setSelectOptionDisabled(residual, [
                    'credito_basso',
                    'credito_disponibile',
                    'minuti_bassi',
                    'minuti_disponibili'
                ], false);
            }
            applyPhonePlanResidualDependencies(form, null);
            updatePhoneDateLabel(form);
            updatePhoneOrderOptions(form);
            var threshold = form.querySelector('[data-custom-threshold-select]');
            if (threshold) {
                toggleCustomThreshold(threshold, false);
            }
        }

        if (form.matches('.sim-filter-form')) {
            applySimState(form, getSelectedSimStates(form));
        }

        form.querySelectorAll('select').forEach(function (select) {
            updateCustomSelect(select);
        });
        form.querySelectorAll('input[data-clearable="true"]').forEach(function (input) {
            updateClearButton(input);
        });
        updateFilterResetButton(form);
        updateStickyLayout();
    }

    function positionRestoredResults(form, position, startOffset) {
        return new Promise(function (resolve) {
            var resultsRoot = getResultsRootForFilterForm(form);
            var container = resultsRoot ? resultsRoot.querySelector('[data-lazy-container="true"]') : null;
            if (!container || !position) {
                resolve(false);
                return;
            }

            var list = getActiveLazyList(container);
            var items = list ? Array.prototype.slice.call(list.children) : [];
            var firstOffset = Number.isFinite(startOffset) ? startOffset : parseInt(container.dataset.prevOffset || '0', 10);
            var itemIndex = Math.max(0, Math.min(items.length - 1, (parseInt(position.anchorOffset || '0', 10) || 0) - firstOffset));

            container.scrollTop = 0;
            container.scrollLeft = parseInt(position.scrollLeft || '0', 10) || 0;

            window.requestAnimationFrame(function () {
                if (items.length && items[itemIndex]) {
                    var contentTop = getResultsContentTop(container);
                    var currentOffset = items[itemIndex].getBoundingClientRect().top - contentTop;
                    var desiredOffset = Number(position.anchorViewportOffset || 0);
                    container.scrollTop = Math.max(0, container.scrollTop + currentOffset - desiredOffset);
                } else {
                    container.scrollTop = Math.max(0, parseInt(position.scrollTop || '0', 10) || 0);
                }
                container.scrollLeft = Math.max(0, parseInt(position.scrollLeft || '0', 10) || 0);
                window.scrollTo(
                    Math.max(0, parseInt(position.windowScrollX || '0', 10) || 0),
                    Math.max(0, parseInt(position.windowScrollY || '0', 10) || 0)
                );

                window.requestAnimationFrame(function () {
                    container.scrollLeft = Math.max(0, parseInt(position.scrollLeft || '0', 10) || 0);
                    window.scrollTo(
                        Math.max(0, parseInt(position.windowScrollX || '0', 10) || 0),
                        Math.max(0, parseInt(position.windowScrollY || '0', 10) || 0)
                    );
                    resolve(true);
                });
            });
        });
    }

    function restoreFilterResultsPosition(form, position) {
        var resultsRoot = getResultsRootForFilterForm(form);
        var container = resultsRoot ? resultsRoot.querySelector('[data-lazy-container="true"]') : null;
        if (!container || !position) {
            return Promise.resolve(false);
        }

        var anchorOffset = Math.max(0, parseInt(position.anchorOffset || '0', 10) || 0);
        var restorePromise;
        if (anchorOffset > 0 && window.ProgWeb && typeof window.ProgWeb.restoreResultsBlock === 'function') {
            restorePromise = window.ProgWeb.restoreResultsBlock(container, position);
        } else {
            restorePromise = Promise.resolve({ ok: true, startOffset: 0 });
        }

        return restorePromise.then(function (result) {
            var startOffset = result && Number.isFinite(result.startOffset) ? result.startOffset : 0;
            return positionRestoredResults(form, position, startOffset).then(function () {
                return Boolean(result && result.ok !== false);
            });
        });
    }

    function handleFilterReset(form) {
        var defaults = filterDefaultStates.get(form);
        if (!defaults) {
            return;
        }

        form.dataset.sessionResetting = 'true';
        form.dataset.sessionRestoring = 'false';
        delete pendingFilterRestores[form.id];
        removeFilterSessionState(form);
        applyFilterValues(form, defaults);
        synchronizeFilterFormUi(form);

        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
        } else {
            form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
        }
    }

    function prepareFilterSessionForms(root) {
        var scope = root || document;
        scope.querySelectorAll('form[data-filter-session-key]:not([data-filter-session-ready="true"])').forEach(function (form) {
            form.dataset.filterSessionReady = 'true';
            filterDefaultStates.set(form, captureFilterValues(form));

            var saved = readFilterSessionState(form);
            if (saved && saved.fields) {
                applyFilterValues(form, saved.fields);
                pendingFilterRestores[form.id] = saved.position || null;
                form.dataset.sessionRestoring = 'true';
                form.dataset.filterSessionNeedsSync = 'true';
            }

            form.addEventListener('input', function () {
                updateFilterResetButton(form);
                scheduleFilterSessionSave(form);
            });
            form.addEventListener('change', function () {
                updateFilterResetButton(form);
                scheduleFilterSessionSave(form);
            });
            form.addEventListener('submit', function () {
                if (form.dataset.sessionResetting === 'true') {
                    return;
                }
                saveFilterSessionState(form, form.dataset.sessionRestoring === 'true');
            });

            var resetButton = form.querySelector('[data-filter-reset="true"]');
            if (resetButton) {
                resetButton.addEventListener('click', function (event) {
                    event.preventDefault();
                    handleFilterReset(form);
                });
            }
        });
    }

    function attachFilterPositionTracking(form) {
        var resultsRoot = getResultsRootForFilterForm(form);
        var container = resultsRoot ? resultsRoot.querySelector('[data-lazy-container="true"]') : null;
        if (!container || container.dataset.filterPositionReady === 'true') {
            return;
        }
        container.dataset.filterPositionReady = 'true';
        container.addEventListener('scroll', function () {
            scheduleFilterSessionSave(form);
        }, { passive: true });
    }

    function finalizeFilterSessionForms(root) {
        var scope = root || document;
        scope.querySelectorAll('form[data-filter-session-key]').forEach(function (form) {
            if (form.dataset.filterSessionNeedsSync === 'true') {
                synchronizeFilterFormUi(form);
                delete form.dataset.filterSessionNeedsSync;
            } else {
                updateFilterResetButton(form);
            }
            attachFilterPositionTracking(form);

            if (Object.prototype.hasOwnProperty.call(pendingFilterRestores, form.id)
                    && form.dataset.filterSessionRestoreStarted !== 'true') {
                form.dataset.filterSessionRestoreStarted = 'true';
                window.setTimeout(function () {
                    if (typeof form.requestSubmit === 'function') {
                        form.requestSubmit();
                    } else {
                        form.dispatchEvent(new Event('submit', { bubbles: true, cancelable: true }));
                    }
                }, 0);
            }
        });
    }

    function saveAllFilterSessionStates() {
        document.querySelectorAll('form[data-filter-session-key]').forEach(function (form) {
            if (form.dataset.sessionRestoring !== 'true' && form.dataset.sessionResetting !== 'true') {
                saveFilterSessionState(form, false);
            }
        });
    }

    function ensureFilterSessionGlobalListeners() {
        if (filterSessionGlobalsReady) {
            return;
        }
        filterSessionGlobalsReady = true;

        document.addEventListener('progweb:ajax-results-updated', function (event) {
            var detail = event.detail || {};
            var form = detail.formId ? document.getElementById(detail.formId) : null;
            if (!form || !form.matches('[data-filter-session-key]')) {
                return;
            }

            attachFilterPositionTracking(form);

            if (form.dataset.sessionResetting === 'true') {
                var resetRoot = getResultsRootForFilterForm(form);
                var resetContainer = resetRoot ? resetRoot.querySelector('[data-lazy-container="true"]') : null;
                if (resetContainer) {
                    resetContainer.scrollTop = 0;
                    resetContainer.scrollLeft = 0;
                }
                form.dataset.sessionResetting = 'false';
                delete form.dataset.filterSessionRestoreStarted;
                removeFilterSessionState(form);
                updateFilterResetButton(form);
                return;
            }

            if (Object.prototype.hasOwnProperty.call(pendingFilterRestores, form.id)) {
                var position = pendingFilterRestores[form.id];
                delete pendingFilterRestores[form.id];
                restoreFilterResultsPosition(form, position).finally(function () {
                    form.dataset.sessionRestoring = 'false';
                    delete form.dataset.filterSessionRestoreStarted;
                    saveFilterSessionState(form, false);
                });
                return;
            }

            saveFilterSessionState(form, false);
        });

        var windowScrollTimer = null;
        window.addEventListener('scroll', function () {
            if (windowScrollTimer) {
                window.clearTimeout(windowScrollTimer);
            }
            windowScrollTimer = window.setTimeout(saveAllFilterSessionStates, 220);
        }, { passive: true });
        window.addEventListener('pagehide', saveAllFilterSessionStates);
        document.addEventListener('visibilitychange', function () {
            if (document.visibilityState === 'hidden') {
                saveAllFilterSessionStates();
            }
        });
    }

    function initDynamicBehaviors(root) {
        var scope = root || document;
        ensureFilterSessionGlobalListeners();
        prepareFilterSessionForms(scope);
        initAlerts(scope);
        initScrollableSelects(scope);
        initPhonePlanResidualDependencies(scope);
        initTrafficThresholdControls(scope);
        initPhoneDateLabelControls(scope);
        initPhoneTrafficOrderControls(scope);
        initSimStateControls(scope);
        initClearableInputs(scope);
        initDashboardQuickSearchReset(scope);
        initSimCrudForms(scope);
        initPhoneCardModalLinks(scope);
        initSimCardModalLinks(scope);
        initSimHistoryModalLinks(scope);
        initSimReturnLinks(scope);
        initResultsViewControls(scope);
        initClickableDetailTiles(scope);
        initTableRowModals(scope);
        initExpandableCards(scope);
        initPageAutoFocus(scope);
        initSingleResultGrids(scope);
        initResultsNavigationControls(scope);
        initResultsScrollTopControls(scope);
        initResultsBoundaryScrollChaining(scope);
        updateStickyLayout();
        if (window.ProgWeb && typeof window.ProgWeb.initLazyTables === 'function') {
            window.ProgWeb.initLazyTables(scope);
        }
        finalizeFilterSessionForms(scope);
    }

    window.ProgWeb = window.ProgWeb || {};
    window.ProgWeb.initDynamicBehaviors = initDynamicBehaviors;
    window.ProgWeb.updateResultsNavigation = updateResultsNavigation;
    window.ProgWeb.updateResultsScrollTopControl = updateResultsScrollTopControl;

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.matches('form[data-sim-crud-form="true"]')) {
            return;
        }
        form.querySelectorAll('input, select, textarea').forEach(function (field) {
            if (field.type !== 'hidden') {
                field.dataset.touched = 'true';
            }
        });
        if (!validateSimCrudForm(form)) {
            event.preventDefault();
            event.stopImmediatePropagation();
            var firstInvalid = form.querySelector('.input-invalid');
            if (firstInvalid) {
                firstInvalid.focus();
            }
        }
    }, true);

    document.addEventListener('DOMContentLoaded', function () {
        initDynamicBehaviors(document);
        window.addEventListener('resize', updateStickyLayout);
        window.addEventListener('load', updateStickyLayout);
        window.setTimeout(updateStickyLayout, 100);
        window.setTimeout(updateStickyLayout, 350);
        window.setTimeout(updateStickyLayout, 800);
        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape') {
                closeCardModal();
            }
        });
    });
}());