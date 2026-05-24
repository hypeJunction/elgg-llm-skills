define('myplugin/widget', function(require) {
    var $ = require('jquery');

    $(document).ready(function() {
        $('.foo').on('click', function() {});
        $('.bar').off('click');
        var arr = Array.isArray(x);
        var data = JSON.parse(str);
        $('.list').delegate('li', 'click', function() {});

        // Function.prototype.bind — NOT jQuery .bind() (Elgg 4 false-positive bug).
        // Must be left alone or runtime breaks.
        window.setTimeout(this.getLine.bind(this), 2000);

        // jQuery-conventional $-prefixed alias.
        var $el = $('.baz');
        $el.on('focus', function() {});
    });
});
