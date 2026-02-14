// assets/js/forms.js
(function () {
    'use strict';

    function isFieldEmpty(input) {
        if (!input) return false;
        var tag = input.tagName.toLowerCase();
        var type = (input.type || '').toLowerCase();

        if (type === 'checkbox' || type === 'radio') {
            return !input.checked;
        }

        if (tag === 'select') {
            return !input.value;
        }

        var value = input.value;
        return !value || value.trim() === '';
    }

    function initFormValidation() {
        var forms = document.querySelectorAll('form.js-validate');
        if (!forms.length) return;

        forms.forEach(function (form) {
            // On submit, validate required fields
            form.addEventListener('submit', function (e) {
                var requiredFields = form.querySelectorAll('[data-required="1"]');
                if (!requiredFields.length) return;

                var firstInvalid = null;
                var checkedRadioGroups = {};

                requiredFields.forEach(function (field) {
                    var type = (field.type || '').toLowerCase();
                    
                    // For radio buttons, check if any in the group is checked
                    if (type === 'radio') {
                        var name = field.name;
                        if (!checkedRadioGroups.hasOwnProperty(name)) {
                            // Check if any radio in this group is checked
                            var groupChecked = form.querySelector('input[name="' + name + '"]:checked');
                            checkedRadioGroups[name] = !!groupChecked;
                        }
                        
                        if (!checkedRadioGroups[name]) {
                            field.classList.add('input-error');
                            if (!firstInvalid) {
                                firstInvalid = field;
                            }
                        } else {
                            field.classList.remove('input-error');
                        }
                    } else {
                        // For other field types, validate normally
                        if (isFieldEmpty(field)) {
                            field.classList.add('input-error');
                            if (!firstInvalid) {
                                firstInvalid = field;
                            }
                        } else {
                            field.classList.remove('input-error');
                        }
                    }
                });

                var globalError = form.querySelector('.form-error-global');

                if (firstInvalid) {
                    e.preventDefault();

                    if (!globalError) {
                        var div = document.createElement('div');
                        div.className = 'form-error form-error-global';
                        div.textContent = 'Please fill in the required fields.';
                        form.insertBefore(div, form.firstChild);
                    }

                    if (typeof firstInvalid.focus === 'function') {
                        firstInvalid.focus();
                    }
                } else if (globalError) {
                    globalError.parentNode.removeChild(globalError);
                }
            });

            // Remove error highlight once user types
            form.addEventListener('input', function (e) {
                var target = e.target;
                if (target && target.classList.contains('input-error')) {
                    target.classList.remove('input-error');
                }
            });
        });
    }

    document.addEventListener('DOMContentLoaded', initFormValidation);
})();
