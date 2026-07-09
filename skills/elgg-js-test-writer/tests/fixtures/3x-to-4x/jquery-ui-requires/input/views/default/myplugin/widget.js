define('myplugin/widget', ['jquery'], function($) {
    // jQuery UI methods used without explicit requires
    $('.list').sortable({
        axis: 'y',
        handle: '.drag-handle'
    });

    $('.item').draggable({
        containment: 'parent'
    });

    $('#datepicker').datepicker({
        dateFormat: 'yy-mm-dd'
    });
});
