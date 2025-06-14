$(document).ready(function() {
    // Tab switching
    $('ul.tabs li').click(function() {
        var tab_id = $(this).attr('data-tab');

        $('ul.tabs li').removeClass('current');
        $('.tab-content').removeClass('current');

        $(this).addClass('current');
        $("#" + tab_id).addClass('current');
    });
    
    // Handle entity selection
    $('.entity-select').change(function() {
        var selectedValue = $(this).val();
        var formFields = $(this).closest('.entity-selection').find('.new-entity-form');
        
        if (selectedValue) {
            // An existing entity was selected, disable the new entity form
            formFields.find('input, textarea').prop('disabled', true);
            formFields.css('opacity', '0.5');
        } else {
            // No entity selected, enable the new entity form
            formFields.find('input, textarea').prop('disabled', false);
            formFields.css('opacity', '1');
        }
    });
});