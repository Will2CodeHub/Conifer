/* TEN Design Studio — per-session, in-memory undo stack.
   DsUndo(getState, setState):
     push()    — snapshot current state (call BEFORE mutating)
     undo()    — restore the most recent snapshot, apply via setState (immediate)
     canUndo() — stack non-empty
     clear()   — drop all snapshots (e.g. on leaving the editor)
   Snapshots are deep JSON clones; nothing is persisted. */
(function () {
  "use strict";
  window.DsUndo = function (getState, setState) {
    var stack = [];
    return {
      push: function () {
        try {
          stack.push(JSON.stringify(getState()));
          if (stack.length > 100) { stack.shift(); }
        } catch (e) {}
      },
      undo: function () {
        if (!stack.length) { return false; }
        var s = stack.pop();
        try { setState(JSON.parse(s)); return true; } catch (e) { return false; }
      },
      canUndo: function () { return stack.length > 0; },
      clear: function () { stack = []; }
    };
  };
})();
