(function () {

    function updateStickyLayout() {
        var nav = document.querySelector('.main-nav');
        var searchFilter = document.querySelector('.sticky-data-panel .search-filter') || document.querySelector('.search-filter');
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

    function initDynamicBehaviors(root) {
        var scope = root || document;
        initAlerts(scope);
        initSimStateControls(scope);
        updateStickyLayout();
        if (window.ProgWeb && typeof window.ProgWeb.initLazyTables === 'function') {
            window.ProgWeb.initLazyTables(scope);
        }
    }

    window.ProgWeb = window.ProgWeb || {};
    window.ProgWeb.initDynamicBehaviors = initDynamicBehaviors;

    document.addEventListener('DOMContentLoaded', function () {
        initDynamicBehaviors(document);
        window.addEventListener('resize', updateStickyLayout);
        window.setTimeout(updateStickyLayout, 150);
    });
}());
