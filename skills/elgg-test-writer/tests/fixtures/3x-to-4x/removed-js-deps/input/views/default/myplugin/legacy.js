define('myplugin/legacy', ['jquery', 'jquery-treeview', 'jquery-form'], function($) {
    // Using removed dependencies

    // jquery-treeview
    $('#tree').treeview();

    // jquery-form plugin
    $('#myForm').ajaxForm({
        success: function(response) {
            console.log(response);
        }
    });
});

require(['elgg/widgets'], function(widgets) {
    widgets.init();
});
