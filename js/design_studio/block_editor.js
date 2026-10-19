/* TEN Design Studio — Block Editor.
   Two panes: PREVIEW on top (the block rendered with real content, updates as you
   edit) and the EDITING pane below (the HTML editor). The </> HTML toolbar button
   toggles the editing pane between the visual view and raw HTML — in place, not a
   separate pane. Edits save to the shared _blocks store and revert with Undo. */
(function () {
  "use strict";
  var DS = window.DS = window.DS || {};
  var bs = null;   // { type, name, store, html, undo, lastHtml }
  var saveTimer = null;

  DS.renderBlockEditor = function (params) {
    var type = params && params.type;
    var name = (type && DS.REG[type] && DS.REG[type].label) || (params && params.name) || type || "block";
    bs = { type: type, name: name, store: { templates: {}, settings: {}, names: {} }, html: "", undo: null, lastHtml: "" };

    var root = document.getElementById("dsBlock");
    root.innerHTML =
      '<header class="ds-topbar">' +
        '<button class="ds-back" id="dsBeBack"><i class="fas fa-arrow-left"></i> Design home</button>' +
        '<h1>' + DS.esc(name) + '</h1>' +
        '<div class="ds-grow"></div>' +
        '<button class="ds-btn ds-btn-ghost" id="dsBeUndo"><i class="fas fa-rotate-left"></i> Undo</button>' +
        '<button class="ds-btn ds-btn-ghost" id="dsBeHistory"><i class="fas fa-clock-rotate-left"></i> History</button>' +
        '<button class="ds-btn ds-btn-ghost" id="dsBePreview"><i class="fas fa-up-right-from-square"></i> Preview</button>' +
        '<button class="ds-btn ds-btn-primary" id="dsBeSave"><i class="fas fa-floppy-disk"></i> Save</button>' +
      '</header>' +
      '<div class="ds-be">' +
        '<div class="ds-be-preview"><div class="ds-be-label">Preview</div><iframe id="dsBePv" title="Live preview"></iframe></div>' +
        '<div class="ds-be-editor" id="dsBeEditor"></div>' +
      '</div>';

    root.querySelector("#dsBeBack").addEventListener("click", function () { if (bs.undo) { bs.undo.clear(); } DS.dsGo("home"); });
    root.querySelector("#dsBePreview").addEventListener("click", function () { window.open(DS.previewUrl({ block: type }), "_blank"); });
    root.querySelector("#dsBeUndo").addEventListener("click", function () {
      if (bs.undo && bs.undo.canUndo()) { bs.undo.undo(); DS.toast("Reverted"); } else { DS.toast("Nothing to undo"); }
    });
    root.querySelector("#dsBeSave").addEventListener("click", function () {
      saveNow().then(function () { DS.toast("Saved"); previewReload(); }).catch(function (e) { DS.toast(e.message); });
    });
    root.querySelector("#dsBeHistory").addEventListener("click", function () {
      DS.openItemHistory("block", type, function (res) {
        var tpl = res.template || "";
        bs.undo.push(); bs.html = tpl; bs.lastHtml = tpl; bs.store.templates[bs.type] = tpl;
        DS.HtmlEditor.setHtml(tpl); saveNow().then(previewReload);
      });
    });

    bs.undo = DsUndo(function () { return bs.html; }, function (h) {
      bs.html = h; bs.lastHtml = h; bs.store.templates[bs.type] = h;
      DS.HtmlEditor.setHtml(h); saveAndRefresh();
    });

    DS.HtmlEditor.mount(document.getElementById("dsBeEditor"), { host: DS.ds.host, type: type });
    DS.HtmlEditor.onChange(onEditorChange);

    loadTemplate(type);
  };

  // PHP's json_decode turns an empty {} into []. An array with string keys
  // serializes back to [] (JSON.stringify ignores non-index props), silently
  // dropping the template. Coerce every store to a plain object.
  function toObj(v) { return (v && typeof v === "object" && !Array.isArray(v)) ? v : {}; }

  function loadTemplate(type) {
    DS.api("load", { page: "_blocks", device: "desktop" }).then(function (j) {
      var layout = j.layout || {};
      bs.store = { templates: toObj(layout.templates), settings: toObj(layout.settings), names: toObj(layout.names) };
      var saved = bs.store.templates[type];
      if (saved != null && saved !== "") { startEditor(saved); }
      else {
        fetch("https://" + DS.ds.host + "/design/?block=" + encodeURIComponent(type) + "&tpl_default=1")
          .then(function (r) { return r.text(); })
          .then(function (txt) { if (bs.type === type) { startEditor(txt || "<div>Empty block</div>"); } })
          .catch(function () { startEditor("<div>Empty block</div>"); });
      }
    }).catch(function (e) { DS.toast(e.message); startEditor("<div>Empty block</div>"); });
  }

  function startEditor(html) {
    bs.html = html; bs.lastHtml = html;
    DS.HtmlEditor.setHtml(html);
    previewReload();
  }

  function onEditorChange(html) {
    if (html === bs.lastHtml) { return; }
    bs.undo.push();
    bs.lastHtml = html; bs.html = html;
    bs.store.templates[bs.type] = html;
    saveAndRefresh();
  }

  function previewReload() {
    var f = document.getElementById("dsBePv");
    if (f) { f.src = DS.previewUrl({ block: bs.type }); }
  }

  // Explicit, immediate save (the Save button). Autosave also runs on change.
  function saveNow() {
    var cur = "";
    try { cur = (DS.HtmlEditor.getHtml && DS.HtmlEditor.getHtml()) || ""; } catch (e) {}
    if (!cur) { cur = bs.html || ""; }
    if (cur) { bs.store.templates[bs.type] = cur; }
    return DS.api("save_draft", { page: "_blocks", device: "desktop",
      layout_json: JSON.stringify({ templates: bs.store.templates, settings: bs.store.settings, names: bs.store.names }) });
  }

  // Debounced: persist the _blocks store THEN reload the preview so it shows the
  // edit. Preserves settings + names so nothing is dropped.
  function saveAndRefresh() {
    clearTimeout(saveTimer);
    saveTimer = setTimeout(function () {
      // Read the current editor content directly — robust against any async
      // reset of block state during the no-stored-template (cross-origin) load.
      var cur = "";
      try { cur = (DS.HtmlEditor.getHtml && DS.HtmlEditor.getHtml()) || ""; } catch (e) {}
      if (!cur) { cur = bs.html || ""; }
      if (!cur) { return; }                       // never clobber a good template with empty
      bs.store.templates[bs.type] = cur;
      DS.api("save_draft", { page: "_blocks", device: "desktop",
        layout_json: JSON.stringify({ templates: bs.store.templates, settings: bs.store.settings, names: bs.store.names }) })
        .then(previewReload)
        .catch(function (e) { DS.toast(e.message); previewReload(); });
    }, 450);
  }
})();
