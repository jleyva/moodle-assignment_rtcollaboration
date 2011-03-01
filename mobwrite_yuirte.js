/**
 * @fileoverview This client-side code interfaces with YUI 2 RICH TEXT EDITOR.
 * @author juanleyvadelgado@gmail.com (Juan Leyva Delgado) based on work done by fraser@google.com (Neil Fraser)
 */

/**
 * Checks to see if the provided node is still part of the DOM.
 * @param {Node} node DOM node to verify.
 * @return {boolean} Is this node part of a DOM?
 * @private
 */
mobwrite.validNode_ = function(node) {
  while (node.parentNode) {
    node = node.parentNode;
  }
  // The topmost node should be type 9, a document.
  return node.nodeType == 9;
};


// YUI RICH TEXT EDITOR


/**
 * Constructor of shared object representing a YUI rte
 * @param {Node} node A textarea, text or password input.
 * @constructor
 */
mobwrite.shareYUIrte = function( id, textarea, yuiEditor) {
  this.yuiEditor = yuiEditor;  
  this.element = textarea;
  // Call our prototype's constructor.
  mobwrite.shareObj.apply(this, [id]);
  
  this.setClientText('');

  // Don't highlight incoming text on the first update.
  this.firstEdit = true;
  
};


// The textarea shared object's parent is a shareObj.
mobwrite.shareYUIrte.prototype = new mobwrite.shareObj('');


/**
 * Retrieve the user's text.
 * @return {string} Plaintext content.
 */
mobwrite.shareYUIrte.prototype.getClientText = function() {
  if (!mobwrite.validNode_(this.element)) {
    mobwrite.unshare(this.file);
  }
  // From the editor to the text area
  this.yuiEditor.saveHTML();
  var text = mobwrite.shareYUIrte.normalizeLinebreaks_(this.element.value);
  if (this.element.type == 'text') {
    // Numeric data should use overwrite mode.
    this.mergeChanges = !text.match(/^\s*-?[\d.,]+\s*$/);
  }
  return text;
};


/**
 * Set the user's text.
 * @param {string} text New text
 */
mobwrite.shareYUIrte.prototype.setClientText = function(text) {
  this.element.value = text;
  this.yuiEditor.setEditorHTML(text);
  this.fireChange(this.element);
};


/**
 * Modify the user's plaintext by applying a series of patches against it.
 * @param {Array.<patch_obj>} patches Array of Patch objects.
 */
mobwrite.shareYUIrte.prototype.patchClientText = function(patches) {
  // Set some constants which tweak the matching behaviour.
  // Maximum distance to search from expected location.
  this.dmp.Match_Distance = 1000;
  // At what point is no match declared (0.0 = perfection, 1.0 = very loose)
  this.dmp.Match_Threshold = 0.6;

  var oldClientText = this.getClientText();
  var cursor = this.captureCursor_();
  // Pack the cursor offsets into an array to be adjusted.
  // See http://neil.fraser.name/writing/cursor/
  var offsets = [];
  if (cursor) {
    offsets[0] = cursor.startOffset;
    if ('endOffset' in cursor) {
      offsets[1] = cursor.endOffset;
    }
  }
  var newClientText = this.patch_apply_(patches, oldClientText, offsets);
  // Set the new text only if there is a change to be made.
  if (oldClientText != newClientText) {
    this.setClientText(newClientText);
    if (cursor) {
      // Unpack the offset array.
      cursor.startOffset = offsets[0];
      if (offsets.length > 1) {
        cursor.endOffset = offsets[1];
        if (cursor.startOffset >= cursor.endOffset) {
          cursor.collapsed = true;
        }
      }
      this.restoreCursor_(cursor);
    }
  }
};


/**
 * Merge a set of patches onto the text.  Return a patched text.
 * @param {Array.<patch_obj>} patches Array of patch objects.
 * @param {string} text Old text.
 * @param {Array.<number>} offsets Offset indices to adjust.
 * @return {string} New text.
 */
mobwrite.shareYUIrte.prototype.patch_apply_ =
    function(patches, text, offsets) {
  if (patches.length == 0) {
    return text;
  }

  // Deep copy the patches so that no changes are made to originals.
  patches = this.dmp.patch_deepCopy(patches);
  var nullPadding = this.dmp.patch_addPadding(patches);
  text = nullPadding + text + nullPadding;

  this.dmp.patch_splitMax(patches);
  // delta keeps track of the offset between the expected and actual location
  // of the previous patch.  If there are patches expected at positions 10 and
  // 20, but the first patch was found at 12, delta is 2 and the second patch
  // has an effective expected position of 22.
  var delta = 0;
  for (var x = 0; x < patches.length; x++) {
    var expected_loc = patches[x].start2 + delta;
    var text1 = this.dmp.diff_text1(patches[x].diffs);
    var start_loc;
    var end_loc = -1;
    if (text1.length > this.dmp.Match_MaxBits) {
      // patch_splitMax will only provide an oversized pattern in the case of
      // a monster delete.
      start_loc = this.dmp.match_main(text,
          text1.substring(0, this.dmp.Match_MaxBits), expected_loc);
      if (start_loc != -1) {
        end_loc = this.dmp.match_main(text,
            text1.substring(text1.length - this.dmp.Match_MaxBits),
            expected_loc + text1.length - this.dmp.Match_MaxBits);
        if (end_loc == -1 || start_loc >= end_loc) {
          // Can't find valid trailing context.  Drop this patch.
          start_loc = -1;
        }
      }
    } else {
      start_loc = this.dmp.match_main(text, text1, expected_loc);
    }
    if (start_loc == -1) {
      // No match found.  :(
      if (mobwrite.debug) {
        window.console.warn('Patch failed: ' + patches[x]);
      }
      // Subtract the delta for this failed patch from subsequent patches.
      delta -= patches[x].length2 - patches[x].length1;
    } else {
      // Found a match.  :)
      if (mobwrite.debug) {
        window.console.info('Patch OK.');
      }
      delta = start_loc - expected_loc;
      var text2;
      if (end_loc == -1) {
        text2 = text.substring(start_loc, start_loc + text1.length);
      } else {
        text2 = text.substring(start_loc, end_loc + this.dmp.Match_MaxBits);
      }
      // Run a diff to get a framework of equivalent indices.
      var diffs = this.dmp.diff_main(text1, text2, false);
      if (text1.length > this.dmp.Match_MaxBits &&
          this.dmp.diff_levenshtein(diffs) / text1.length >
          this.dmp.Patch_DeleteThreshold) {
        // The end points match, but the content is unacceptably bad.
        if (mobwrite.debug) {
          window.console.warn('Patch contents mismatch: ' + patches[x]);
        }
      } else {
        var index1 = 0;
        var index2;
        for (var y = 0; y < patches[x].diffs.length; y++) {
          var mod = patches[x].diffs[y];
          if (mod[0] !== DIFF_EQUAL) {
            index2 = this.dmp.diff_xIndex(diffs, index1);
          }
          if (mod[0] === DIFF_INSERT) {  // Insertion
            text = text.substring(0, start_loc + index2) + mod[1] +
                   text.substring(start_loc + index2);
            for (var i = 0; i < offsets.length; i++) {
              if (offsets[i] + nullPadding.length > start_loc + index2) {
                offsets[i] += mod[1].length;
              }
            }
          } else if (mod[0] === DIFF_DELETE) {  // Deletion
            var del_start = start_loc + index2;
            var del_end = start_loc + this.dmp.diff_xIndex(diffs,
                index1 + mod[1].length);
            text = text.substring(0, del_start) + text.substring(del_end);
            for (var i = 0; i < offsets.length; i++) {
              if (offsets[i] + nullPadding.length > del_start) {
                if (offsets[i] + nullPadding.length < del_end) {
                  offsets[i] = del_start - nullPadding.length;
                } else {
                  offsets[i] -= del_end - del_start;
                }
              }
            }
          }
          if (mod[0] !== DIFF_DELETE) {
            index1 += mod[1].length;
          }
        }
      }
    }
  }
  // Strip the padding off.
  text = text.substring(nullPadding.length, text.length - nullPadding.length);
  return text;
};


/**
 * Record information regarding the current cursor.
 * @return {Object?} Context information of the cursor.
 * @private
 */
mobwrite.shareYUIrte.prototype.captureCursor_ = function() {
  if ('activeElement' in this.element && !this.element.activeElement) {
    // Safari specific code.
    // Restoring a cursor in an unfocused element causes the focus to jump.
    return null;
  }
  var padLength = this.dmp.Match_MaxBits / 2;  // Normally 16.
  var text = this.element.value;
  var cursor = {};
  if ('selectionStart' in this.element) {  // W3
    try {
      var selectionStart = this.element.selectionStart;
      var selectionEnd = this.element.selectionEnd;
    } catch (e) {
      // No cursor; the element may be "display:none".
      return null;
    }
    cursor.startPrefix = text.substring(selectionStart - padLength, selectionStart);
    cursor.startSuffix = text.substring(selectionStart, selectionStart + padLength);
    cursor.startOffset = selectionStart;
    cursor.collapsed = (selectionStart == selectionEnd);
    if (!cursor.collapsed) {
      cursor.endPrefix = text.substring(selectionEnd - padLength, selectionEnd);
      cursor.endSuffix = text.substring(selectionEnd, selectionEnd + padLength);
      cursor.endOffset = selectionEnd;
    }
  } else {  // IE
    // Walk up the tree looking for this textarea's document node.
    var doc = this.element;
    while (doc.parentNode) {
      doc = doc.parentNode;
    }
    if (!doc.selection || !doc.selection.createRange) {
      // Not IE?
      return null;
    }
    var range = doc.selection.createRange();
    if (range.parentElement() != this.element) {
      // Cursor not in this textarea.
      return null;
    }
    var newRange = doc.body.createTextRange();

    cursor.collapsed = (range.text == '');
    newRange.moveToElementText(this.element);
    if (!cursor.collapsed) {
      newRange.setEndPoint('EndToEnd', range);
      cursor.endPrefix = newRange.text;
      cursor.endOffset = cursor.endPrefix.length;
      cursor.endPrefix = cursor.endPrefix.substring(cursor.endPrefix.length - padLength);
    }
    newRange.setEndPoint('EndToStart', range);
    cursor.startPrefix = newRange.text;
    cursor.startOffset = cursor.startPrefix.length;
    cursor.startPrefix = cursor.startPrefix.substring(cursor.startPrefix.length - padLength);

    newRange.moveToElementText(this.element);
    newRange.setEndPoint('StartToStart', range);
    cursor.startSuffix = newRange.text.substring(0, padLength);
    if (!cursor.collapsed) {
      newRange.setEndPoint('StartToEnd', range);
      cursor.endSuffix = newRange.text.substring(0, padLength);
    }
  }

  // Record scrollbar locations
  if ('scrollTop' in this.element) {
    cursor.scrollTop = this.element.scrollTop / this.element.scrollHeight;
    cursor.scrollLeft = this.element.scrollLeft / this.element.scrollWidth;
  }

  // alert(cursor.startPrefix + '|' + cursor.startSuffix + ' ' +
  //     cursor.startOffset + '\n' + cursor.endPrefix + '|' +
  //     cursor.endSuffix + ' ' + cursor.endOffset + '\n' +
  //     cursor.scrollTop + ' x ' + cursor.scrollLeft);
  return cursor;
};


/**
 * Attempt to restore the cursor's location.
 * @param {Object} cursor Context information of the cursor.
 * @private
 */
mobwrite.shareYUIrte.prototype.restoreCursor_ = function(cursor) {
  // Set some constants which tweak the matching behaviour.
  // Maximum distance to search from expected location.
  this.dmp.Match_Distance = 1000;
  // At what point is no match declared (0.0 = perfection, 1.0 = very loose)
  this.dmp.Match_Threshold = 0.9;

  var padLength = this.dmp.Match_MaxBits / 2;  // Normally 16.
  var newText = this.element.value;

  // Find the start of the selection in the new text.
  var pattern1 = cursor.startPrefix + cursor.startSuffix;
  var pattern2, diff;
  var cursorStartPoint = this.dmp.match_main(newText, pattern1,
      cursor.startOffset - padLength);
  if (cursorStartPoint !== null) {
    pattern2 = newText.substring(cursorStartPoint,
                                 cursorStartPoint + pattern1.length);
    //alert(pattern1 + '\nvs\n' + pattern2);
    // Run a diff to get a framework of equivalent indicies.
    diff = this.dmp.diff_main(pattern1, pattern2, false);
    cursorStartPoint += this.dmp.diff_xIndex(diff, cursor.startPrefix.length);
  }

  var cursorEndPoint = null;
  if (!cursor.collapsed) {
    // Find the end of the selection in the new text.
    pattern1 = cursor.endPrefix + cursor.endSuffix;
    cursorEndPoint = this.dmp.match_main(newText, pattern1,
        cursor.endOffset - padLength);
    if (cursorEndPoint !== null) {
      pattern2 = newText.substring(cursorEndPoint,
                                   cursorEndPoint + pattern1.length);
      //alert(pattern1 + '\nvs\n' + pattern2);
      // Run a diff to get a framework of equivalent indicies.
      diff = this.dmp.diff_main(pattern1, pattern2, false);
      cursorEndPoint += this.dmp.diff_xIndex(diff, cursor.endPrefix.length);
    }
  }

  // Deal with loose ends
  if (cursorStartPoint === null && cursorEndPoint !== null) {
    // Lost the start point of the selection, but we have the end point.
    // Collapse to end point.
    cursorStartPoint = cursorEndPoint;
  } else if (cursorStartPoint === null && cursorEndPoint === null) {
    // Lost both start and end points.
    // Jump to the offset of start.
    cursorStartPoint = cursor.startOffset;
  }
  if (cursorEndPoint === null) {
    // End not known, collapse to start.
    cursorEndPoint = cursorStartPoint;
  }

  // Restore selection.
  if ('selectionStart' in this.element) {  // W3
    this.element.selectionStart = cursorStartPoint;
    this.element.selectionEnd = cursorEndPoint;
  } else {  // IE
    // Walk up the tree looking for this textarea's document node.
    var doc = this.element;
    while (doc.parentNode) {
      doc = doc.parentNode;
    }
    if (!doc.selection || !doc.selection.createRange) {
      // Not IE?
      return;
    }
    // IE's TextRange.move functions treat '\r\n' as one character.
    var snippet = this.element.value.substring(0, cursorStartPoint);
    var ieStartPoint = snippet.replace(/\r\n/g, '\n').length;

    var newRange = doc.body.createTextRange();
    newRange.moveToElementText(this.element);
    newRange.collapse(true);
    newRange.moveStart('character', ieStartPoint);
    if (!cursor.collapsed) {
      snippet = this.element.value.substring(cursorStartPoint, cursorEndPoint);
      var ieMidLength = snippet.replace(/\r\n/g, '\n').length;
      newRange.moveEnd('character', ieMidLength);
    }
    newRange.select();
  }

  // Restore scrollbar locations
  if ('scrollTop' in cursor) {
    this.element.scrollTop = cursor.scrollTop * this.element.scrollHeight;
    this.element.scrollLeft = cursor.scrollLeft * this.element.scrollWidth;
  }
};


/**
 * Ensure that all linebreaks are LF
 * @param {string} text Text with unknown line breaks
 * @return {string} Text with normalized linebreaks
 * @private
 */
mobwrite.shareYUIrte.normalizeLinebreaks_ = function(text) {
  return text.replace(/\r\n/g, '\n').replace(/\r/g, '\n');
};


/**
 * Handler to accept text fields as elements that can be shared.
 * If the element is a textarea, text or password input, create a new
 * sharing object.
 * @param {*} node Object or ID of object to share.
 * @return {Object?} A sharing object or null.
 */
mobwrite.shareYUIrte.shareHandler = function(el) {

// Add an iframe to the list of things to share.
  if (typeof el == 'string') {
    var textarea = document.getElementById(el.replace('_editor',''));        
       
  }
  var id = el;  

  return new mobwrite.shareYUIrte(id, textarea, mobwrite.yuiEditor);
};


// Register this shareHandler with MobWrite.
mobwrite.shareHandlers.push(mobwrite.shareYUIrte.shareHandler);

