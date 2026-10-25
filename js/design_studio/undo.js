/* TEN Design Studio — per-session, in-memory undo/redo stack.
   DsUndo(getState, setState):
     push()    — snapshot current state (call BEFORE mutating); clears the redo stack
     undo()    — restore the most recent snapshot, apply via setState (immediate)
     redo()    — re-apply the most recently undone snapshot
     canUndo() / canRedo() — stack non-empty
     clear()   — drop all snapshots (e.g. on leaving the editor)
   Snapshots are deep JSON clones; nothing is persisted. */
(function () {
  "use strict";
  window.DsUndo = function (getState, setState) {
    var stack = [];
    var redo = [];
    return {
      push: function () {
        try {
          stack.push(JSON.stringify(getState()));
          if (stack.length > 100) { stack.shift(); }
          redo = [];   // a fresh change forks history — nothing to redo onto
        } catch (e) {}
      },
      undo: function () {
        if (!stack.length) { return false; }
        try { redo.push(JSON.stringify(getState())); } catch (e) {}
        var s = stack.pop();
        try { setState(JSON.parse(s)); return true; } catch (e) { return false; }
      },
      redo: function () {
        if (!redo.length) { return false; }
        try { stack.push(JSON.stringify(getState())); } catch (e) {}
        var s = redo.pop();
        try { setState(JSON.parse(s)); return true; } catch (e) { return false; }
      },
      canUndo: function () { return stack.length > 0; },
      canRedo: function () { return redo.length > 0; },
      clear: function () { stack = []; redo = []; }
    };
  };
})();
