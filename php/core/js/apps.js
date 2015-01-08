/**
 * ownCloud - core
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later. See the COPYING file.
 *
 * @author Bernhard Posselt <dev@bernhard-posselt.com>
 * @copyright Bernhard Posselt 2014
 */

(function (document, $, exports) {

	'use strict';

	var dynamicSlideToggleEnabled = false;

	exports.Apps = {
		enableDynamicSlideToggle: function () {
			dynamicSlideToggleEnabled = true;
		}
	};

	/**
	 * Provides a way to slide down a target area through a button and slide it
	 * up if the user clicks somewhere else. Used for the news app settings and
	 * add new field.
	 *
	 * Usage:
	 * <button data-apps-slide-toggle=".slide-area">slide</button>
	 * <div class=".slide-area" class="hidden">I'm sliding up</div>
	 */
	var registerAppsSlideToggle = function () {
		var buttons = $('[data-apps-slide-toggle]');

		$(document).click(function (event) {

			if (dynamicSlideToggleEnabled) {
				buttons = $('[data-apps-slide-toggle]');
			}

			buttons.each(function (index, button) {

				var areaSelector = $(button).data('apps-slide-toggle');
				var area = $(areaSelector);

				function hideArea() {
					area.slideUp(function() {
						area.trigger(new $.Event('hide'));
					});
				}
				function showArea() {
					area.slideDown(function() {
						area.trigger(new $.Event('show'));
					});
				}

				// do nothing if the area is animated
				if (!area.is(':animated')) {

					// button toggles the area
					if (button === event.target) {
						if (area.is(':visible')) {
							hideArea();
						} else {
							showArea();
						}

					// all other areas that have not been clicked but are open
					// should be slid up
					} else {
						var closest = $(event.target).closest(areaSelector);
						if (area.is(':visible') && closest[0] !== area[0]) {
							hideArea();
						}
					}
				}
			});

		});
	};


	$(document).ready(function () {
		registerAppsSlideToggle();
	});

}(document, jQuery, OC));
