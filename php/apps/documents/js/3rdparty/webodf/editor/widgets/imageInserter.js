/**
 * @license
 * Copyright (C) 2012-2013 KO GmbH <copyright@kogmbh.com>
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

/*global define,require,document,Image,FileReader,window,runtime,ops */

define("webodf/editor/widgets/imageInserter", [
    "dijit/form/Button",
    "webodf/editor/EditorSession"],

    function (Button, EditorSession) {
        "use strict";

        var ImageInserter = function (callback) {
            var self = this,
                widget = {},
                insertImageButton,
                editorSession,
                fileLoader;


            /**
             * @param {!string} content  as datauri
             * @param {!string} mimetype
             * @return {undefined}
             */
            function insertImageOnceLoaded(mimetype, content) {
                var hiddenImage = new Image();

                hiddenImage.style.position = "absolute";
                hiddenImage.style.left = "-99999px";
                document.body.appendChild(hiddenImage);
                hiddenImage.onload = function () {
                    // remove the data:image/jpg;base64, bit
                    content = content.substring(content.indexOf(",") + 1);
                    if (editorSession) {
                        editorSession.insertImage(mimetype, content, hiddenImage.width, hiddenImage.height);
                    }
                    // clean up
                    document.body.removeChild(hiddenImage);
                    self.onToolDone();
                };
                hiddenImage.src = content;
            }

            function fileSelectHandler(evt) {
                var file, files, reader;
                files = (evt.target && evt.target.files) || (evt.dataTransfer && evt.dataTransfer.files);
                if (files && files.length === 1) {
                    file = files[0];
                    reader = new FileReader();
                    reader.onloadend = function () {
                        if (reader.readyState === 2) {
                            insertImageOnceLoaded(file.type, reader.result);
                        } else {
                            runtime.log("Image could not be loaded");
                            self.onToolDone();
                        }
                    };
                    reader.readAsDataURL(file);
                }
            }

            function createFileLoader() {
                var form = document.createElement("form"),
                    input = document.createElement("input");
                form.appendChild(input);
                form.id = "imageForm";
                form.style.display = "none";
                input.id = "imageLoader";
                input.setAttribute("type", "file");
                input.setAttribute("accept", "image/*");
                input.addEventListener("change", fileSelectHandler, false);
                document.body.appendChild(form);
                return {input: input, form: form};
            }

            insertImageButton = new Button({
                label: runtime.tr("Insert Image"),
                disabled: true,
                showLabel: false,
                iconClass: "dijitEditorIcon dijitEditorIconInsertImage",
                onClick: function () {
                    if (!fileLoader) {
                        fileLoader = createFileLoader();
                    }
                    fileLoader.form.reset();
                    fileLoader.input.click();
                }
            });

            widget.children = [insertImageButton];
            widget.startup = function () {
                widget.children.forEach(function (element) {
                    element.startup();
                });
            };

            widget.placeAt = function (container) {
                widget.children.forEach(function (element) {
                    element.placeAt(container);
                });
                return widget;
            };

            function handleCursorMoved(cursor) {
                var disabled = cursor.getSelectionType() === ops.OdtCursor.RegionSelection;
                // LO/AOO pops up the picture/frame option dialog if image is selected when pressing the button
                // Since we only support inline images, disable the button for now.
                insertImageButton.setAttribute('disabled', disabled);
            }

            this.setEditorSession = function (session) {
                if (editorSession) {
                    editorSession.unsubscribe(EditorSession.signalCursorMoved, handleCursorMoved);
                }
                editorSession = session;
                if (editorSession) {
                    editorSession.subscribe(EditorSession.signalCursorMoved, handleCursorMoved);
                }
                widget.children.forEach(function (element) {
                    element.setAttribute("disabled", !session);
                });
            };

            this.onToolDone = function () {};

            callback(widget);
        };

        return ImageInserter;
    });
