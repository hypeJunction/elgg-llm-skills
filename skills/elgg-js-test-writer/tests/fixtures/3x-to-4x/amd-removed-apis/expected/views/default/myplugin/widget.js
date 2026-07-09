define('myplugin/widget', function (require) {

	var elgg = require('elgg');
	var $ = require('jquery');
	var hooks = require('elgg/hooks');
	var i18n = require('elgg/i18n');
	require('jquery.colorbox');

	var widget = {
		getOptions: function (opts) {
			if (!$.isPlainObject(opts)) {
				opts = {};
			}

			var settings = {
				current: i18n.echo('js:widget:current', ['{current}', '{total}']),
				xhrError: i18n.echo('error:default'),
				opacity: 0.5,
			};

			$.extend(settings, opts);

			return hooks.trigger('getOptions', 'ui.widget', null, settings);
		},

		open: function (opts) {
			widget.getOptions(opts);
		},
	};

	return widget;
});
