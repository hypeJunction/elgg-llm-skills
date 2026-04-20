define('myplugin/widget', function (require) {

	var elgg = require('elgg');
	var $ = require('jquery');
	var hooks = require('elgg/hooks');
	require('elgg/init');
	require('jquery.colorbox');

	var widget = {
		getOptions: function (opts) {
			if (!$.isPlainObject(opts)) {
				opts = {};
			}

			var settings = {
				current: elgg.echo('js:widget:current', ['{current}', '{total}']),
				xhrError: elgg.echo('error:default'),
				opacity: 0.5,
			};

			elgg.provide('elgg.ui.widget');

			if ($.isPlainObject(elgg.ui.widget.deprecated_settings)) {
				$.extend(settings, elgg.ui.widget.deprecated_settings, opts);
			} else {
				$.extend(settings, opts);
			}

			return hooks.trigger('getOptions', 'ui.widget', null, settings);
		},

		open: function (opts) {
			widget.getOptions(opts);
		},
	};

	return widget;
});
