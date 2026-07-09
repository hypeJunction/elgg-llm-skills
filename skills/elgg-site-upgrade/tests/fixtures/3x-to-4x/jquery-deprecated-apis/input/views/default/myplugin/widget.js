define('myplugin/widget', function(require) {
    var $ = require('jquery');

    $(document).ready(function() {
        $('.foo').bind('click', function() {});
        $('.bar').unbind('click');
        var arr = $.isArray(x);
        var data = $.parseJSON(str);
        $('.list').delegate('li', 'click', function() {});
    });
});
