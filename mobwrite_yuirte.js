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
 
 
 // http://new.davglass.com/files/yui/codeeditor/code-editor.js
 // https://gist.github.com/213360
 
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
  this.element.value = this.element.value.replace('<span id=""></span>','');
  var text = mobwrite.shareYUIrte.normalizeLinebreaks_(this.element.value.replace('<span id="cur"></span>',''));
  if (this.element.type == 'text') {
    // Numeric data should use overwrite mode.
    this.mergeChanges = !text.match(/^\s*-?[\d.,]+\s*$/);
  }
  return text;
};

mobwrite.shareYUIrte.prototype.getClientTextCursor = function() {
  if (!mobwrite.validNode_(this.element)) {
    mobwrite.unshare(this.file);
  }
  // From the editor to the text area
  this.yuiEditor.saveHTML();
  this.element.value = this.element.value.replace('<span id=""></span>','');
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

  var padLength = this.dmp.Match_MaxBits / 2;  // Normally 16.
  
  var text = this.getClientText();
  
  // Capture selections
  var sel = this.yuiEditor._getSelection(); 
  var range = this.yuiEditor._getRange();
  if(range && range.startOffset != range.endOffset){
      var selectionEndOffset = range.endOffset - range.startOffset;
  }
  
  // Cursor token at current cursor position
  this.yuiEditor.execCommand('inserthtml', '<span id="cur"></span>');  
  var textCursor = this.getClientTextCursor();
  
  var cursor = {};
  
  var selectionStart = textCursor.indexOf('<span id="cur"');
  
  var selectionEnd = selectionStart + selectionEndOffset;
  
    cursor.startPrefix = text.substring(selectionStart - padLength, selectionStart);
    cursor.startSuffix = text.substring(selectionStart, selectionStart + padLength);
    cursor.startOffset = selectionStart;
    cursor.collapsed = (selectionStart == selectionEnd);
    if (!cursor.collapsed) {
      cursor.endPrefix = text.substring(selectionEnd - padLength, selectionEnd);
      cursor.endSuffix = text.substring(selectionEnd, selectionEnd + padLength);
      cursor.endOffset = selectionEnd;
    }

  // Record scrollbar locations
  
  var doc = this.yuiEditor._getDoc(),
                body = doc.body,
                docEl = doc.documentElement;

  if (this.yuiEditor.browser.webkit) {
    cursor.scrollTop = docEl.scrollTop / docEl.scrollHeight;
    cursor.scrollLeft = docEl.scrollLeft / docEl.scrollWidth;
  }
  else{
    cursor.scrollTop = body.scrollTop / body.scrollHeight;
    cursor.scrollLeft = body.scrollLeft / body.scrollWidth;
  }
 
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
  
  var newText = this.getClientText();

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

  newText = newText.substring(0, cursorStartPoint) + '<span id="cur"></span>' + newText.substring(cursorStartPoint);
  this.yuiEditor.setEditorHTML(newText);
  var cur = this.yuiEditor._getDoc().getElementById('cur');
  cur.id = '';
  cur.innerHTML = '';
  this.yuiEditor._selectNode(cur);
  this.fireChange(this.element);
  
  // var sel = this.yuiEditor._getSelection(); 
  // var range = this.yuiEditor._getRange();
  // range.setStart(sel.anchorNode, range.startOffset);
  // range.setEnd(sel.anchorNode, range.startOffset + (cursorEndPoint - cursorStartPoint));
  // console.log(range.startOffset);
  // console.log(range.startOffset + (cursorEndPoint - cursorStartPoint));
  // sel.removeAllRanges();
  // sel.addRange(range);   
  
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
 * Handler to accept YUI editors as elements that can be shared.
 * If the element is in a list of RTE editors create a new object
 * @param {*} node Object or ID of object to share.
 * @return {Object?} A sharing object or null.
 */
mobwrite.shareYUIrte.shareHandler = function(el) {

  if (typeof el == 'string') {
    if('rteeditors' in mobwrite){
        for(var editor in mobwrite.rteeditors){
            if(editor == el){
                var id = el;                
                var textarea = document.getElementById(el.replace('_editor',''));        
                return new mobwrite.shareYUIrte(id, textarea, mobwrite.rteeditors[editor]);
            }
        }
    }   
  }
  
  return null;
};


// Register this shareHandler with MobWrite.
mobwrite.shareHandlers.push(mobwrite.shareYUIrte.shareHandler);

