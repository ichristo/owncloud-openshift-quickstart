/**
 * @license
 * Copyright (C) 2013 KO GmbH <copyright@kogmbh.com>
 *
 * @licstart
 * The JavaScript code in this page is free software: you can redistribute it
 * and/or modify it under the terms of the GNU Affero General Public License
 * (GNU AGPL) as published by the Free Software Foundation, either version 3 of
 * the License, or (at your option) any later version.  The code is distributed
 * WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE.  See the GNU AGPL for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this code.  If not, see <http://www.gnu.org/licenses/>.
 *
 * As additional permission under GNU AGPL version 3 section 7, you
 * may distribute non-source (e.g., minimized or compacted) forms of
 * that code without the copy of the GNU GPL normally required by
 * section 4, provided you include this license notice and a URL
 * through which recipients can access the Corresponding Source.
 *
 * As a special exception to the AGPL, any HTML file which merely makes function
 * calls to this code, and for that purpose includes it by reference shall be
 * deemed a separate work for copyright law purposes. In addition, the copyright
 * holders of this code give you permission to combine this code with free
 * software libraries that are released under the GNU LGPL. You may copy and
 * distribute such a system following the terms of the GNU AGPL for this code
 * and the LGPL for the libraries. If you modify this code, you may extend this
 * exception to your version of the code, but you are not obligated to do so.
 * If you do not wish to do so, delete this exception statement from your
 * version.
 *
 * This license applies to this entire compilation.
 * @licend
 * @source: http://www.webodf.org/
 * @source: https://github.com/kogmbh/WebODF/
 */

/*global runtime,define,require,document,dijit */

define("webodf/editor/widgets/dialogWidgets/fontEffectsPane", [], function () {
    "use strict";

    var FontEffectsPane = function (callback) {
        var self = this,
            editorSession,
            contentPane,
            form,
            preview,
            textColorPicker,
            backgroundColorPicker,
            fontPicker;

        this.widget = function () {
            return contentPane;
        };

        this.value = function () {
            var textProperties = form.get('value'),
                textStyle = textProperties.textStyle;

            textProperties.fontWeight = (textStyle.indexOf('bold') !== -1)
                                            ? 'bold'
                                            : 'normal';
            textProperties.fontStyle = (textStyle.indexOf('italic') !== -1)
                                            ? 'italic'
                                            : 'normal';
            textProperties.underline = (textStyle.indexOf('underline') !== -1)
                                            ? 'solid'
                                            : 'none';

            delete textProperties.textStyle;
            return textProperties;
        };

        this.setStyle = function (styleName) {
            var style = editorSession.getParagraphStyleAttributes(styleName)['style:text-properties'],
                s_bold,
                s_italic,
                s_underline,
                s_fontSize,
                s_fontName,
                s_color,
                s_backgroundColor;

            if (style !== undefined) {
                s_bold = style['fo:font-weight'];
                s_italic = style['fo:font-style'];
                s_underline = style['style:text-underline-style'];
                s_fontSize = parseFloat(style['fo:font-size']);
                s_fontName = style['style:font-name'];
                s_color = style['fo:color'];
                s_backgroundColor = style['fo:background-color'];

                form.attr('value', {
                    fontName: s_fontName && s_fontName.length ? s_fontName : 'Arial',
                    fontSize: isNaN(s_fontSize) ? 12 : s_fontSize,
                    textStyle: [
                        s_bold,
                        s_italic,
                        s_underline === 'solid' ? 'underline' : undefined
                    ]
                });
                textColorPicker.set('value', s_color && s_color.length ? s_color : '#000000');
                backgroundColorPicker.set('value', s_backgroundColor && s_backgroundColor.length ? s_backgroundColor : '#ffffff');

            } else {
                // TODO: Use default style here
                form.attr('value', {
                    fontFamily: 'sans-serif',
                    fontSize: 12,
                    textStyle: []
                });
                textColorPicker.set('value', '#000000');
                backgroundColorPicker.set('value', '#ffffff');
            }

        };

        function init(cb) {
            require([
                "dojo",
                "dojo/ready",
                "dojo/dom-construct",
                "dijit/layout/ContentPane",
                "dojox/widget/ColorPicker",
                "webodf/editor/widgets/fontPicker"
            ], function (dojo, ready, domConstruct, ContentPane, ColorPicker, FontPicker) {
                var editorBase = dojo.config && dojo.config.paths &&
                            dojo.config.paths['webodf/editor'];
                runtime.assert(editorBase, "webodf/editor path not defined in dojoConfig");
                ready(function () {
                    contentPane = new ContentPane({
                        title: runtime.tr("Font Effects"),
                        href: editorBase+"/widgets/dialogWidgets/fontEffectsPane.html",
                        preload: true
                    });

                    contentPane.onLoad = function () {
                        var textColorTB = dijit.byId('textColorTB'),
                            backgroundColorTB = dijit.byId('backgroundColorTB');

                        form = dijit.byId('fontEffectsPaneForm');
                        runtime.translateContent(form.domNode);

                        preview = document.getElementById('previewText');
                        textColorPicker = dijit.byId('textColorPicker');
                        backgroundColorPicker = dijit.byId('backgroundColorPicker');

                        // Bind dojox widgets' values to invisible form elements, for easy parsing
                        textColorPicker.onChange = function (value) {
                            textColorTB.set('value', value);
                        };
                        backgroundColorPicker.onChange = function (value) {
                            backgroundColorTB.set('value', value);
                        };

                        fontPicker = new FontPicker(function (picker) {
                            picker.widget().startup();
                            document.getElementById('fontPicker').appendChild(picker.widget().domNode);
                            picker.widget().name = 'fontName';
                            picker.setEditorSession(editorSession);
                        });

                        // Automatically update preview when selections change
                        form.watch('value', function () {
                            if (form.value.textStyle.indexOf('bold') !== -1) {
                                preview.style.fontWeight = 'bold';
                            } else {
                                preview.style.fontWeight = 'normal';
                            }
                            if (form.value.textStyle.indexOf('italic') !== -1) {
                                preview.style.fontStyle = 'italic';
                            } else {
                                preview.style.fontStyle = 'normal';
                            }
                            if (form.value.textStyle.indexOf('underline') !== -1) {
                                preview.style.textDecoration = 'underline';
                            } else {
                                preview.style.textDecoration = 'none';
                            }

                            preview.style.fontSize = form.value.fontSize + 'pt';
                            preview.style.fontFamily = fontPicker.getFamily(form.value.fontName);
                            preview.style.color = form.value.color;
                            preview.style.backgroundColor = form.value.backgroundColor;
                        });
                    };

                    return cb();
                });
            });
        }

        this.setEditorSession = function(session) {
            editorSession = session;
            if (fontPicker) {
                fontPicker.setEditorSession(editorSession);
            }
        };

        init(function () {
            return callback(self);
        });
    };

    return FontEffectsPane;
});
