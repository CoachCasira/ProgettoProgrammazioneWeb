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

    function initAlerts(root) {
        var scope = root || document;
        scope.querySelectorAll('.alert-success:not([data-alert-ready="true"])').forEach(function (message) {
            message.dataset.alertReady = 'true';
            window.setTimeout(function () {
                message.classList.add('alert-soft-hidden');
            }, 6000);
        });
    }

    function applySimState(form, state) {
        form.querySelectorAll('[data-sim-state-value]').forEach(function (button) {
            var isActive = button.dataset.simStateValue === state;
            button.classList.toggle('active', isActive);
            button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
        });

        form.querySelectorAll('[data-state-field]').forEach(function (fieldGroup) {
            var allowedStates = (fieldGroup.dataset.stateField || '').split(',');
            var shouldShow = allowedStates.indexOf(state) !== -1;
            fieldGroup.classList.toggle('is-hidden', !shouldShow);

            fieldGroup.querySelectorAll('input, select, textarea').forEach(function (field) {
                field.disabled = !shouldShow;
                if (field.matches && field.matches('input[data-clearable="true"]')) {
                    updateClearButton(field);
                }
            });
        });

        updateStickyLayout();
    }

    function initSimStateControls(root) {
        var scope = root || document;
        scope.querySelectorAll('form[data-sim-state-filter="true"]:not([data-sim-state-ready="true"])').forEach(function (form) {
            form.dataset.simStateReady = 'true';
            var stateInput = form.querySelector('input[name="stato"]');
            if (!stateInput) {
                return;
            }

            applySimState(form, stateInput.value || 'attive');

            form.querySelectorAll('[data-sim-state-value]').forEach(function (button) {
                button.addEventListener('click', function () {
                    var newState = button.dataset.simStateValue;
                    if (!newState || stateInput.value === newState) {
                        return;
                    }

                    stateInput.value = newState;
                    form.querySelectorAll('[data-state-dependent-input]').forEach(function (field) {
                        field.value = '';
                    });
                    applySimState(form, newState);

                    var submitEvent = new Event('submit', { bubbles: true, cancelable: true });
                    form.dispatchEvent(submitEvent);
                });
            });
        });
    }


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
        if (form.dataset.crudBlocked === 'true') {
            return false;
        }

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
    }

    function clearLinkedSimData(form, keepField) {
        var codeField = form.querySelector('[data-sim-code-lookup="true"]');
        var phoneField = form.querySelector('[data-phone-lookup="true"]');
        var typeField = form.querySelector('select[name="tipoSIM"]');
        var activationField = form.querySelector('[data-auto-activation-date="true"]');
        var deactivationField = form.querySelector('[data-deactivation-date="true"]');

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
        var isDisabled = Boolean(disabled);
        form.dataset.crudBlocked = isDisabled ? 'true' : 'false';
        form.classList.toggle('crud-form-blocked', isDisabled);

        form.querySelectorAll('[data-crud-dependent="true"]').forEach(function (field) {
            field.disabled = isDisabled;
            field.classList.toggle('input-disabled', isDisabled);
            if (isDisabled && field.matches('input[data-clearable="true"]')) {
                updateClearButton(field);
            }
        });

        form.querySelectorAll('[data-crud-submit="true"]').forEach(function (button) {
            button.disabled = isDisabled;
            button.classList.toggle('btn-disabled', isDisabled);
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
            clearFieldError(codeField);
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
                    setCrudDependentFieldsState(form, true);
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
            } else {
                activationField.value = '';
                deactivationField.removeAttribute('min');
            }
            clearFieldError(phoneField);
            return;
        }

        if (!isDigits(value)) {
            if (mode === 'create') {
                clearLinkedSimData(form, phoneField);
            } else {
                activationField.value = '';
                deactivationField.removeAttribute('min');
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
                    } else {
                        activationField.value = '';
                        deactivationField.removeAttribute('min');
                    }
                    setFieldError(phoneField, payload && payload.message ? payload.message : 'Il numero indicato non risulta utilizzabile per questa operazione.');
                    return;
                }

                clearFieldError(phoneField);
                if (mode === 'create') {
                    setCrudDependentFieldsState(form, false);
                    applyLinkedSimData(form, payload);
                } else {
                    activationField.value = payload.dataAttivazione || '';
                    deactivationField.setAttribute('min', payload.dataMinimaDisattivazione || payload.dataAttivazione || '');
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
                    if (field.matches('[data-validation="digits"]')) {
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
                        if (activationField) {
                            activationField.value = '';
                        }
                        if (deactivationField) {
                            deactivationField.removeAttribute('min');
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
    function initDynamicBehaviors(root) {
        var scope = root || document;
        initAlerts(scope);
        initSimStateControls(scope);
        initClearableInputs(scope);
        initSimCrudForms(scope);
        updateStickyLayout();
        if (window.ProgWeb && typeof window.ProgWeb.initLazyTables === 'function') {
            window.ProgWeb.initLazyTables(scope);
        }
    }

    window.ProgWeb = window.ProgWeb || {};
    window.ProgWeb.initDynamicBehaviors = initDynamicBehaviors;

    document.addEventListener('submit', function (event) {
        var form = event.target;
        if (!(form instanceof HTMLFormElement) || !form.matches('form[data-sim-crud-form="true"]')) {
            return;
        }
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
    });
}());
