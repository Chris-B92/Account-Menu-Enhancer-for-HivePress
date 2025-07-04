jQuery(document).ready(function($) {
    console.log('AMEHP: Script loaded successfully');

    var $container = $('#amehp-custom-menu-items .amehp-menu-items');
    if (!$container.length) {
        console.error('AMEHP: Error - Menu items container not found');
        return;
    }

    var $template = $('#amehp-menu-item-template').html();
    if (!$template) {
        console.error('AMEHP: Error - Menu item template not found');
        return;
    }

    var index = $container.find('.amehp-menu-item').length;
    console.log('AMEHP: Found ' + index + ' existing menu items');

    function bindTypeChange($item) {
        $item.find('.amehp-type-field').on('change', function() {
            console.log('AMEHP: Type changed to: ' + $(this).val());
            var type = $(this).val();
            var $urlField = $item.find('.amehp-url-field');
            var $routeField = $item.find('.amehp-route-field');
            setTimeout(function() {
                if (type === 'url') {
                    $urlField.show();
                    $routeField.hide();
                } else {
                    $urlField.hide();
                    $routeField.show();
                }
            }, 100);
            validateForm();
        });
    }

    $container.find('.amehp-menu-item').each(function() {
        bindTypeChange($(this));
    });

    $('.amehp-add-menu-item').on('click', function() {
        var html = $template.replace(/{{INDEX}}/g, index);
        var $newItem = $(html);
        $container.append($newItem);
        bindTypeChange($newItem);
        index++;
        validateForm();
    });

    $container.on('click', '.amehp-remove-menu-item', function() {
        $(this).closest('.amehp-menu-item').remove();
        validateForm();
    });

    $container.on('input change', '.amehp-menu-item input, .amehp-menu-item select', function() {
        validateForm();
    });

    function validateForm() {
        var isValid = true;
        $container.find('.amehp-menu-item').each(function() {
            var $item = $(this);
            var type = $item.find('.amehp-type-field').val();
            var label = $item.find('input[name$="[label]"]').val();
            if (!label) {
                isValid = false;
                return false;
            }
            if (type === 'url') {
                if (!$item.find('.amehp-url-input').val()) {
                    isValid = false;
                }
            } else if (type === 'hivepress_route') {
                if (!$item.find('.amehp-route-select').val()) {
                    isValid = false;
                }
            }
        });
        $('#amehp-save-settings').prop('disabled', !isValid);
        console.log('AMEHP: Form validation - ' + (isValid ? 'Valid' : 'Invalid'));
    }

    validateForm();
});