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
  var msgBound = false;

  // Pages this block can be previewed inside. Built-in blocks use their allowed
  // pages from the registry; custom (blk_*) blocks can go on any page.
  function previewPages(type) {
    var slugs = (DS.isCustomBlock(type) || !DS.REG[type]) ? DS.PAGES : (DS.REG[type].pages || []);
    return slugs.map(function (s) { return { slug: s, label: (DS.PAGE_LABELS[s] || s) }; });
  }

  DS.renderBlockEditor = function (params) {
    var type = params && params.type;
    var name = (type && DS.REG[type] && DS.REG[type].label) || (params && params.name) || type || "block";
    bs = { type: type, name: name, store: { templates: {}, settings: {}, names: {} }, html: "", undo: null, lastHtml: "", dirty: false };
    DS.setDirty(false);

    // Preview can show this block on its own, or inside one of the pages it can
    // appear on (so you see it in real context). "Block only" is the default.
    var pvOpts = '<option value="">Block only</option>' +
      previewPages(type).map(function (p) {
        return '<option value="' + DS.esc(p.slug) + '">In: ' + DS.esc(p.label) + '</option>';
      }).join("");

    var root = document.getElementById("dsBlock");
    root.innerHTML =
      '<header class="ds-topbar">' +
        '<button class="ds-back" id="dsBeBack"><i class="fas fa-arrow-left"></i> Design home</button>' +
        '<h1>' + DS.esc(name) + '</h1>' +
        '<div class="ds-grow"></div>' +
        '<div class="ds-seg" aria-label="Preview device">' +
          '<button data-dev="desktop" class="' + (DS.ds.device === "desktop" ? "on" : "") + '">Desktop</button>' +
          '<button data-dev="mobile" class="' + (DS.ds.device === "mobile" ? "on" : "") + '">Mobile</button>' +
        '</div>' +
        '<button class="ds-btn ds-btn-ghost" id="dsBeContent"><i class="fas fa-sliders"></i> Content</button>' +
        '<button class="ds-btn ds-btn-ghost" id="dsBeHtml"><i class="fas fa-code"></i> Edit HTML</button>' +
        '<button class="ds-btn ds-btn-ghost" id="dsBeUndo"><i class="fas fa-rotate-left"></i> Undo</button>' +
        '<button class="ds-btn ds-btn-ghost" id="dsBeRedo"><i class="fas fa-rotate-right"></i> Redo</button>' +
        '<button class="ds-btn ds-btn-ghost" id="dsBeHistory"><i class="fas fa-clock-rotate-left"></i> History</button>' +
        '<select class="ds-be-pvctx" id="dsBePvCtx" title="What to show when you click Preview">' + pvOpts + '</select>' +
        '<button class="ds-btn ds-btn-ghost" id="dsBePreview"><i class="fas fa-up-right-from-square"></i> Preview</button>' +
        '<button class="ds-btn ds-btn-primary" id="dsBeSave"><i class="fas fa-floppy-disk"></i> Save</button>' +
      '</header>' +
      '<div class="ds-be">' +
        '<div class="ds-be-preview"><div class="ds-be-label">Preview</div><iframe id="dsBePv" title="Live preview"></iframe></div>' +
        '<div class="ds-be-split" id="dsBeSplit" title="Drag to resize the preview"></div>' +
        '<div class="ds-be-editor" id="dsBeEditor"></div>' +
      '</div>';
    wireBeSplitter(root);

    root.querySelector("#dsBeBack").addEventListener("click", function () {
      function go(save) {
        clearTimeout(saveTimer);
        bs.closing = true;                                  // stop any late autosave from re-writing the draft
        if (DS.HtmlEditor.cancelPending) { DS.HtmlEditor.cancelPending(); }
        bs.dirty = false; DS.setDirty(false);
        if (save) {
          saveNow().then(function () {
            var html = ""; try { html = (DS.HtmlEditor.getHtml && DS.HtmlEditor.getHtml()) || ""; } catch (e) {}
            DS.addHistory("block", bs.type, { template: html || bs.html || "", settings: bs.store.settings[bs.type] || {} });
            if (bs.undo) { bs.undo.clear(); } DS.dsGo("home");
          }).catch(function (e) { DS.toast(e.message); });
        } else {
          // Discard: the editor autosaves to the draft while you work, so leaving
          // must put the block back to how it was when this editing session began —
          // otherwise the unsaved edits would linger (cached) next time it is opened.
          restoreOriginal().then(function () { if (bs.undo) { bs.undo.clear(); } DS.dsGo("home"); })
            .catch(function () { if (bs.undo) { bs.undo.clear(); } DS.dsGo("home"); });
        }
      }
      if (bs && bs.dirty) { DS.confirmLeave(function () { go(true); }, function () { go(false); }); }
      else { clearTimeout(saveTimer); if (bs.undo) { bs.undo.clear(); } DS.dsGo("home"); }
    });
    root.querySelector("#dsBeHtml").addEventListener("click", function () {
      var btn = this;
      var src = (DS.HtmlEditor.toggleSource) ? DS.HtmlEditor.toggleSource() : false;
      btn.classList.toggle("ds-on", src);
      btn.innerHTML = src ? '<i class="fas fa-eye"></i> Visual' : '<i class="fas fa-code"></i> Edit HTML';
    });
    root.querySelector("#dsBePreview").addEventListener("click", function () {
      var sel = root.querySelector("#dsBePvCtx");
      var page = sel ? sel.value : "";
      // In-page preview shows the block in context (if that page's layout uses it);
      // empty value previews the block on its own.
      window.open(page ? DS.previewUrl({ page: page }) : DS.previewUrl({ block: type }), "_blank");
    });
    root.querySelector("#dsBeUndo").addEventListener("click", function () {
      if (bs.undo && bs.undo.canUndo()) { bs.undo.undo(); DS.toast("Reverted"); } else { DS.toast("Nothing to undo"); }
    });
    root.querySelector("#dsBeRedo").addEventListener("click", function () {
      if (bs.undo && bs.undo.canRedo()) { bs.undo.redo(); DS.toast("Redone"); } else { DS.toast("Nothing to redo"); }
    });
    root.querySelector("#dsBeSave").addEventListener("click", function () {
      saveNow().then(function () {
        var html = ""; try { html = (DS.HtmlEditor.getHtml && DS.HtmlEditor.getHtml()) || ""; } catch (e) {}
        DS.addHistory("block", bs.type, { template: html || bs.html || "", settings: bs.store.settings[bs.type] || {} });
        bs.dirty = false; DS.setDirty(false);
        captureOriginal();     // this saved state is now the baseline a later Discard reverts to
        DS.toast("Saved"); previewReload();
      }).catch(function (e) { DS.toast(e.message); });
    });
    root.querySelectorAll(".ds-seg button").forEach(function (b) {
      b.addEventListener("click", function () {
        DS.ds.device = b.dataset.dev;
        root.querySelectorAll(".ds-seg button").forEach(function (x) { x.classList.toggle("on", x === b); });
        // The toggle is also a real edit mode: switch the editor to design the
        // chosen device (Mobile = phone-width canvas + phone-only overrides).
        if (DS.HtmlEditor.setDevice) { DS.HtmlEditor.setDevice(DS.ds.device); }
        previewReload();
      });
    });
    root.querySelector("#dsBeContent").addEventListener("click", function () { openContentDialog(type); });
    root.querySelector("#dsBeHistory").addEventListener("click", function () {
      DS.openItemHistory("block", type, function (snap) {
        if (!snap) { return; }
        var tpl = snap.template || "";
        bs.undo.push(); bs.html = tpl; bs.lastHtml = tpl; bs.store.templates[bs.type] = tpl;
        if (snap.settings) { bs.store.settings[bs.type] = snap.settings; }
        DS.HtmlEditor.setHtml(tpl); saveNow().then(previewReload);
      });
    });

    bs.undo = DsUndo(function () { return bs.html; }, function (h) {
      bs.html = h; bs.lastHtml = h; bs.store.templates[bs.type] = h;
      DS.HtmlEditor.setHtml(h); saveAndRefresh();
    });

    DS.HtmlEditor.mount(document.getElementById("dsBeEditor"), { host: DS.ds.host, type: type, device: DS.ds.device });
    DS.HtmlEditor.onChange(onEditorChange);
    // Cross-pane highlight: selecting in the editor outlines the same element in
    // the live Preview pane.
    DS.HtmlEditor.onSelect(function (selector) {
      var f = document.getElementById("dsBePv");
      if (f && f.contentWindow) {
        try { f.contentWindow.postMessage({ source: "ten-design-editor", action: "select_el", blockIdx: 0, selector: selector }, "*"); } catch (e) {}
      }
    });
    // Clicking an element in the Preview selects it in the editor.
    if (!msgBound) {
      msgBound = true;
      window.addEventListener("message", function (e) {
        var d = e.data;
        if (!d || d.source !== "ten-design" || !bs) { return; }
        if (d.action === "select" && d.selector) { DS.HtmlEditor.selectBySelector(d.selector); }
      });
    }

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
      bs.hadStored = (saved != null && saved !== "");
      if (bs.hadStored) { startEditor(saved); return; }
      // No stored template: load the built-in default via the same-origin proxy,
      // with a timeout fallback so the editor never stays blank.
      var done = false, fallback = '<div class="dl-block-custom"><p>Start typing here…</p></div>';
      var timer = setTimeout(function () { if (!done && bs.type === type) { done = true; startEditor(fallback); } }, 5000);
      fetch("ajax/design_layout.php?action=block_default&pub=" + encodeURIComponent(DS.ds.pub) + "&type=" + encodeURIComponent(type))
        .then(function (r) { return r.text(); })
        .then(function (txt) { if (!done && bs.type === type) { done = true; clearTimeout(timer); startEditor(txt && txt.trim() ? txt : fallback); } })
        .catch(function () { if (!done && bs.type === type) { done = true; clearTimeout(timer); startEditor(fallback); } });
    }).catch(function (e) { DS.toast(e.message); startEditor('<div class="dl-block-custom"><p>Start typing here…</p></div>'); });
  }

  function startEditor(html) {
    bs.html = html; bs.lastHtml = html;
    captureOriginal(html);     // snapshot the session's starting point for Discard
    DS.HtmlEditor.setHtml(html);
    previewReload();
  }

  // Remember the current saved state so an exit-without-saving can roll back to it.
  function captureOriginal(html) {
    if (html == null) { try { html = (DS.HtmlEditor.getHtml && DS.HtmlEditor.getHtml()) || bs.html; } catch (e) { html = bs.html; } }
    var settings = {};
    try { settings = JSON.parse(JSON.stringify(bs.store.settings[bs.type] || {})); } catch (e) { settings = bs.store.settings[bs.type] || {}; }
    bs.original = { template: html, settings: settings };
  }

  // Put the block back to the captured starting point (used on Discard) and persist
  // it, so the draft no longer holds the unsaved edits made this session.
  function restoreOriginal() {
    clearTimeout(saveTimer);
    if (!bs || !bs.original) { return Promise.resolve(); }
    if (bs.hadStored) { bs.store.templates[bs.type] = bs.original.template; }
    else { delete bs.store.templates[bs.type]; }
    bs.store.settings[bs.type] = bs.original.settings;
    bs.dirty = false; DS.setDirty(false);
    return DS.api("save_draft", { page: "_blocks", device: "desktop",
      layout_json: JSON.stringify({ templates: bs.store.templates, settings: bs.store.settings, names: bs.store.names }) });
  }

  function onEditorChange(html) {
    if (!bs || bs.closing) { return; }
    if (html === bs.lastHtml) { return; }
    bs.undo.push();
    bs.lastHtml = html; bs.html = html;
    bs.store.templates[bs.type] = html;
    bs.dirty = true; DS.setDirty(true);   // save guard (#3)
    saveAndRefresh();
  }

  function previewReload() {
    var f = document.getElementById("dsBePv");
    if (!f) { return; }
    var wrap = f.parentNode;
    if (wrap) { wrap.classList.toggle("ds-mobile", DS.ds.device === "mobile"); }
    f.src = DS.previewUrl({ block: bs.type });
  }

  // Drag the divider to resize the preview pane (the editor below takes the rest).
  // The chosen height is remembered across sessions.
  function wireBeSplitter(root) {
    var split = root.querySelector("#dsBeSplit");
    var be = root.querySelector(".ds-be");
    var preview = root.querySelector(".ds-be-preview");
    if (!split || !be || !preview) { return; }
    try { var saved = localStorage.getItem("ds_be_preview_h"); if (saved) { preview.style.flex = "0 0 " + saved; } } catch (e) {}
    split.addEventListener("mousedown", function (e) {
      e.preventDefault();
      document.body.classList.add("ds-be-resizing");
      function mv(ev) {
        var rect = be.getBoundingClientRect();
        var h = ev.clientY - rect.top;
        var min = 120, max = rect.height - 160;
        h = Math.max(min, Math.min(max, h));
        preview.style.flex = "0 0 " + h + "px";
      }
      function up() {
        document.removeEventListener("mousemove", mv);
        document.removeEventListener("mouseup", up);
        document.body.classList.remove("ds-be-resizing");
        try { localStorage.setItem("ds_be_preview_h", Math.round(preview.getBoundingClientRect().height) + "px"); } catch (e) {}
      }
      document.addEventListener("mousemove", mv);
      document.addEventListener("mouseup", up);
    });
  }

  function humanizeKey(k) { return k.replace(/_/g, " ").replace(/^\w/, function (c) { return c.toUpperCase(); }); }

  // Edit the block's content settings (tagline, headings, counts, etc.). These
  // are the {{placeholders}} the template fills in. Saved to the shared _blocks
  // global settings so they apply wherever the block is used.
  function openContentDialog(type) {
    var defs = (DS.REG[type] && DS.REG[type].defaults) || {};
    var keys = Object.keys(defs).filter(function (k) { return k !== "styles"; });
    if (!keys.length) { DS.toast("This block has no content fields to set"); return; }
    DS.api("block_settings", { type: type }).then(function (r) {
      var cur = r.settings || {};
      var body = keys.map(function (k) {
        var dv = defs[k], v = (cur[k] !== undefined ? cur[k] : dv), lab = DS.esc(humanizeKey(k));
        if (typeof dv === "boolean") {
          return '<label class="ds-f ds-f-chk"><input type="checkbox" data-k="' + k + '"' + (v ? " checked" : "") + '> ' + lab + '</label>';
        }
        if (typeof dv === "number") {
          return '<label class="ds-f"><span>' + lab + '</span><input type="number" data-k="' + k + '" value="' + DS.esc(v) + '"></label>';
        }
        return '<label class="ds-f"><span>' + lab + '</span><input type="text" data-k="' + k + '" value="' + DS.esc(v) + '"></label>';
      }).join("");
      var dlg = DS.dialog({ title: "Content — " + ((DS.REG[type] && DS.REG[type].label) || type),
        body: body + '<div class="ds-dlg-actions"><button class="ds-btn ds-btn-primary" id="dsCtSave">Save content</button></div>' });
      dlg.el.querySelector("#dsCtSave").addEventListener("click", function () {
        var vals = {};
        dlg.el.querySelectorAll("[data-k]").forEach(function (i) {
          var dv = defs[i.dataset.k];
          vals[i.dataset.k] = (typeof dv === "boolean") ? i.checked : (typeof dv === "number" ? (i.value === "" ? dv : Number(i.value)) : i.value);
        });
        if (cur.styles) { vals.styles = cur.styles; }
        bs.store.settings[type] = Object.assign({}, bs.store.settings[type] || {}, vals);
        saveNow().then(function () { DS.toast("Content saved"); previewReload(); }).catch(function (e) { DS.toast(e.message); });
        dlg.close();
      });
    }).catch(function (e) { DS.toast(e.message); });
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
    if (bs && bs.closing) { return; }                 // don't autosave while leaving
    saveTimer = setTimeout(function () {
      if (bs && bs.closing) { return; }
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
    }, 300);
  }
})();
