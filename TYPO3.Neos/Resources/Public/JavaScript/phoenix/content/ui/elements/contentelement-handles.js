/**
 */
define(
	[
		'jquery',
		'text!phoenix/templates/content/ui/contentelementHandles.html',
		'phoenix/content/ui/elements/new-contentelement-popover-content'
	],
	function ($, template, ContentElementPopoverContent) {
		if (window._requirejsLoadingTrace) window._requirejsLoadingTrace.push('phoenix/content/ui/contentelement-handles');

		return Ember.View.extend({
			template: Ember.Handlebars.compile(template),

			_element: null,

			$newAfterPopoverContent: null,

			_collection: null,

			popoverPosition: 'right',

			didInsertElement: function() {
				var that = this;

					// TODO find a way to calculate the width of the button toolbar
				this.$().css({
					left: this.get('_element').offset().left + this.get('_element').width() - 140
				});

				this.$newAfterPopoverContent = $('<div />', {id: this.get(Ember.GUID_KEY)});
				console.log(this.$().find('.action-new-after'));

				this.$().find('.action-new-after').popover({
					content: this.$newAfterPopoverContent,
					preventLeft: (this.get('popoverPosition')==='left' ? false : true),
					preventRight: (this.get('popoverPosition')==='right' ? false : true),
					preventTop: (this.get('popoverPosition')==='top' ? false : true),
					preventBottom: (this.get('popoverPosition')==='bottom' ? false : true),
					zindex: 10090,
					closeEvent: function() {
						that.set('pressed', false);
					},
					openEvent: function() {
						that.onPopoverOpen.call(that);
					}
				});
			},

			remove: function() {

			},

			cut: function() {

			},

			copy: function() {

			},

			pasteAfter: function() {

			},

			newAfter: function() {
				var that = this;
				this.$().find('.action-new-after').trigger('showPopover');
			},

			onPopoverOpen: function() {
				var groups = {};

				_.each(this.get('_collection').options.definition.range, function(contentType) {
					var type = this.get('_collection').options.vie.types.get(contentType);
					type.metadata.contentType = type.id.substring(1, type.id.length - 1).replace(T3.ContentModule.TYPO3_NAMESPACE, '');

					if (type.metadata.group) {
						if (!groups[type.metadata.group]) {
							groups[type.metadata.group] = {
								name: type.metadata.group,
								children: []
							};
						}
						groups[type.metadata.group].children.push(type.metadata);
					}
				}, this);

					// Make the data object an array for usage in #each helper
				var data = []
				for (var group in groups) {
					data.push(groups[group]);
				}

				ContentElementPopoverContent.create({
					_options: this.get('_collection').options,
					_index: this.get('_index'),
					data: data
				}).replaceIn(this.$newAfterPopoverContent);
			},

			willDestroyElement: function() {
				this.$().find('.action-new-after').trigger('hidePopover');
			}

		});
	}
);