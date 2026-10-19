/* TEN Design Studio — Block Editor.
   Live preview of the block (real data) on top; the full-width HTML editor below.
   Edits save to the shared _blocks store (so a block changes everywhere it is used),
   reflect immediately in the preview, and can be reverted with session Undo. */
(function () {
  "use strict";
  var DS = window.DS = window.DS || {};
  var bs = null;   // { type, name, store:{templates,settings,names}, html, undo, lastHtml }
  var saveTimer = null;
  var msgBound = false;

  function bindMessages() {
    if (msgBound) { return; }
    msgBound = true;
    window.addEventListener("message", function (e) {
      var d = e.data;
      if (!d || d.source !== "ten-design" || !bs) { return; }
      if (d.action === "select") {
        var selr = d.elemSelector || d.selector;
        if (selr) { DS.HtmlEditor.focusElement(selr); }
      }
    });
  }

  DS.renderBlockEditor = function (params) {
    bindMessages();
    var type = params && params.type;
    var name = (type && DS.REG[type] && DS.REG[type].label) || (params && params.name) || type || "block";
    bs = { type: type, name: name, store: { templates: {}, settings: {}, names: {} }, html: "", undo: null, lastHtml: "" };

    var root = document.getElementById("dsBlock");
    root.innerHTML =
      '<header class="ds-topbar">' +
        '<button class="ds-back" id="dsBeBack"><i class="fas fa-arrow-left"></i> Back</button>' +
        '<h1>' + DS.esc(name) + '</h1>' +
        '<div class="ds-grow"></div>' +
        '<button class="ds-btn ds-btn-ghost" id="dsBeUndo"><i class="fas fa-rotate-left"></i> Undo</button>' +
        '<button class="ds-btn ds-btn-ghost" id="dsBePreview"><i class="fas fa-up-right-from-square"></i> Preview</button>' +
      '</header>' +
      '<div class="ds-be">' +
        '<div class="ds-be-preview"><iframe id="dsBePv" title="Live preview"></iframe></div>' +
        '<div class="ds-be-editor" id="dsBeEditor"></div>' +
      '</div>';

    root.querySelector("#dsBeBack").addEventListener("click", function () { if (bs.undo) { bs.undo.clear(); } history.back(); });
    root.querySelector("#dsBePreview").addEventListener("click", function () { window.open(DS.previewUrl({ block: type }), "_blank"); });
    root.querySelector("#dsBeUndo").addEventListener("click", function () {
      if (bs.undo && bs.undo.canUndo()) { bs.undo.undo(); DS.toast("Reverted"); } else { DS.toast("Nothing to undo"); }
    });

    bs.undo = DsUndo(function () { return bs.html; }, function (h) {
      bs.html = h; bs.lastHtml = h;
      bs.store.templates[bs.type] = h;
      DS.HtmlEditor.setHtml(h);
      saveAndRefresh();
    });

    DS.HtmlEditor.mount(document.getElementById("dsBeEditor"), { host: DS.ds.host, type: type });
    DS.HtmlEditor.onChange(onEditorChange);

    loadTemplate(type);
  };

  function loadTemplate(type) {
    DS.api("load", { page: "_blocks", device: "desktop" }).then(function (j) {
      var layout = j.layout || {};
      bs.store = {
        templates: layout.templates || {},
        settings: layout.settings || {},
        names: layout.names || {}
      };
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
    previewReload();                // existing draft/default already renders; just show it
  }

  function onEditorChange(html) {
    if (html === bs.lastHtml) { return; }
    bs.undo.push();                 // snapshot the state before this change
    bs.lastHtml = html; bs.html = html;
    bs.store.templates[bs.type] = html;
    saveAndRefresh();
  }

  function previewReload() {
    var f = document.getElementById("dsBePv");
    if (f) { f.src = DS.previewUrl({ block: bs.type }); }
  }

  // Debounced: persist the _blocks store, then reload the preview so it shows
  // the latest draft. Preserves settings + names so nothing is dropped.
  function saveAndRefresh() {
    clearTimeout(saveTimer);
    saveTimer = setTimeout(function () {
      DS.api("save_draft", { page: "_blocks", device: "desktop",
        layout_json: JSON.stringify({ templates: bs.store.templates, settings: bs.store.settings, names: bs.store.names }) })
        .then(previewReload)
        .catch(function (e) { DS.toast(e.message); previewReload(); });
    }, 400);
  }
})();
