/**
 * jQuery plugin for micro templates
 *
 * Strings are automatically escaped, but that can be disabled by setting
 * escapeFunction to null.
 *
 * Usage examples:
 *
 *	var htmlStr = '<p>Bake, uncovered, until the {greasystuff} is melted and the {pasta} is heated through, about {min} minutes.</p>'
 *	$(htmlStr).octemplate({greasystuff: 'cheese', pasta: 'macaroni', min: 10});
 *
 * 	var htmlStr = '<p>Welcome back {user}</p>';
 *	$(htmlStr).octemplate({user: 'John Q. Public'}, {escapeFunction: null});
 *
 * Be aware that the target string must be wrapped in an HTML element for the
 * plugin to work. The following won't work:
 * 
 *      var textStr = 'Welcome back {user}';
 *      $(textStr).octemplate({user: 'John Q. Public'});
 *
 * For anything larger than one-liners, you can use a simple $.get() ajax
 * request to get the template, or you can embed them it the page using the
 * text/template type:
 *
 * <script id="contactListItemTemplate" type="text/template">
 *	<tr class="contact" data-id="{id}">
 *		<td class="name">
 *			<input type="checkbox" name="id" value="{id}" /><span class="nametext">{name}</span>
 *		</td>
 *		<td class="email">
 *			<a href="mailto:{email}">{email}</a>
 *		</td>
 *		<td class="phone">{phone}</td>
 *	</tr>
 * </script>
 *
 * var $tmpl = $('#contactListItemTemplate');
 * var contacts = // fetched in some ajax call
 *
 * $.each(contacts, function(idx, contact) {
 * 		$contactList.append(
 * 			$tmpl.octemplate({
 * 				id: contact.getId(),
 * 				name: contact.getDisplayName(),
 * 				email: contact.getPreferredEmail(),
 * 				phone: contact.getPreferredPhone(),
 * 			});
 * 		);
 * });
 */
(function( $ ) {
	/**
	* Object Template
	* Inspired by micro templating done by e.g. underscore.js
	*/
	var Template = {
		init: function(vars, options, elem) {
			// Mix in the passed in options with the default options
			this.vars = vars;
			this.options = $.extend({},this.options,options);

			this.elem = elem;
			var self = this;

			if(typeof this.options.escapeFunction === 'function') {
				var keys = Object.keys(this.vars);
				for (var key = 0; key < keys.length; key++) {
					if(typeof this.vars[keys[key]] === 'string') {
						this.vars[keys[key]] = self.options.escapeFunction(this.vars[keys[key]]);
					}
				}
			}

			var _html = this._build(this.vars);
			return $(_html);
		},
		// From stackoverflow.com/questions/1408289/best-way-to-do-variable-interpolation-in-javascript
		_build: function(o){
			var data = this.elem.attr('type') === 'text/template' ? this.elem.html() : outerHTML(this.elem.get(0));
			try {
				return data.replace(/{([^{}]*)}/g,
					function (a, b) {
						var r = o[b];
						return typeof r === 'string' || typeof r === 'number' ? r : a;
					}
				);
			} catch(e) {
				console.error(e, 'data:', data);
			}
		},
		options: {
			escapeFunction: escapeHTML
		}
	};

	$.fn.octemplate = function(vars, options) {
		var vars = vars ? vars : {};
		if(this.length) {
			var _template = Object.create(Template);
			return _template.init(vars, options, this);
		}
	};

})( jQuery );

