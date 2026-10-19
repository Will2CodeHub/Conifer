/* TEN Design Studio — Page Editor.
   Structure-only: add / remove / move blocks on one page, live preview, autosave,
   session undo. The top menu (site_header) and footer are the page frame: they are
   locked here (no move/remove) and their content is edited in the Block Editor.
   Templates (kind:'template') edit the shared page frame + page margins. */
(function () {
  "use strict";
  var DS = window.DS = window.DS || {};
  var st = null;          // { slug, kind, name, layout, undo }
  var saveTimer = null;
  var msgBound = false;

  function arrayMove(a, from, to) { var x = a.splice(from, 1)[0]; a.splice(to, 0, x); }
  function isFrame(type) { return !!DS.FRAME_BLOCKS[type]; }
  function blockLabel(type) { return (DS.REG[type] && DS.REG[type].label) || type; }

  // --------------------------------------------------------------- scroll
  function bindMessages() {
    if (msgBound) { return; }
    msgBound = true;
    window.addEventListener("message", function (e) {
      var d = e.data;
      if (!d || d.source !== "ten-design" || !st) { return; }
      if (d.action === "scroll") { st.scrollY = d.y || 0; }
    });
  }

  // =============================================================== render
  DS.renderPageEditor = function (params) {
    bindMessages();
    var kind = (params && params.kind) || "page";
    var slug = params && params.slug;
    var name = (params && params.name) || (DS.PAGE_LABELS[slug] || slug);
    st = { slug: slug, kind: kind, name: name, layout: { theme: {}, blocks: [] },
           undo: null, scrollY: 0 };
    st.undo = DsUndo(function () { return st.layout; },
      function (l) { st.layout = l; renderRail(); refreshPreview(); scheduleSave(0); });

    if (kind === "template") { return renderTemplateEditor(params); }

    var root = document.getElementById("dsPage");
    root.innerHTML =
      topbar(name) +
      '<div class="ds-pe">' +
        '<aside class="ds-pe-rail">' +
          '<div class="ds-pe-sec"><h3>Add a block</h3><div class="ds-pal" id="dsPal"></div></div>' +
          '<div class="ds-pe-sec"><h3>Blocks on this page</h3><ul class="ds-blocklist" id="dsBlockList"></ul>' +
            '<p class="ds-rail-hint">Drag to reorder. The top menu and footer are the page frame — edit their contents from Blocks on the home screen.</p>' +
          '</div>' +
        '</aside>' +
        '<main class="ds-pe-preview"><iframe id="dsPvFrame" title="Live preview"></iframe></main>' +
      '</div>';
    wireTopbar(root, name);
    renderPalette();

    DS.api("load", { page: slug, device: "desktop" }).then(function (j) {
      var layout = j.layout;
      if (!layout || !layout.blocks) { layout = { theme: {}, blocks: [] }; }
      st.layout = layout;
      renderRail();
      refreshPreview();
    }).catch(function (e) { DS.toast(e.message); });
  };

  function topbar(name) {
    var ds = DS.ds;
    return '<header class="ds-topbar">' +
      '<button class="ds-back" id="dsPeBack"><i class="fas fa-arrow-left"></i> Back</button>' +
      '<h1>' + DS.esc(name) + '</h1>' +
      '<div class="ds-grow"></div>' +
      '<button class="ds-btn ds-btn-ghost" id="dsPeUndo"><i class="fas fa-rotate-left"></i> Undo</button>' +
      '<div class="ds-seg" aria-label="Preview device">' +
        '<button data-dev="desktop" class="' + (ds.device === "desktop" ? "on" : "") + '">Desktop</button>' +
        '<button data-dev="mobile" class="' + (ds.device === "mobile" ? "on" : "") + '">Mobile</button>' +
      '</div>' +
      '<button class="ds-btn ds-btn-ghost" id="dsPePreview"><i class="fas fa-up-right-from-square"></i> Preview</button>' +
    '</header>';
  }

  function wireTopbar(root, name) {
    root.querySelector("#dsPeBack").addEventListener("click", function () { if (st && st.undo) { st.undo.clear(); } history.back(); });
    root.querySelector("#dsPeUndo").addEventListener("click", function () {
      if (st.undo.canUndo()) { st.undo.undo(); DS.toast("Reverted"); } else { DS.toast("Nothing to undo"); }
    });
    root.querySelector("#dsPePreview").addEventListener("click", function () {
      window.open(DS.previewUrl({ page: st.slug, device: DS.ds.device }), "_blank");
    });
    root.querySelectorAll(".ds-seg button").forEach(function (b) {
      b.addEventListener("click", function () {
        DS.ds.device = b.dataset.dev;
        root.querySelectorAll(".ds-seg button").forEach(function (x) { x.classList.toggle("on", x === b); });
        refreshPreview();
      });
    });
  }

  // --------------------------------------------------------------- palette
  function renderPalette() {
    var pal = document.getElementById("dsPal");
    if (!pal) { return; }
    var slug = st.slug;
    var builtin = DS.PAGES.indexOf(slug) !== -1;
    pal.innerHTML = "";
    Object.keys(DS.REG).forEach(function (type) {
      if (isFrame(type)) { return; }
      var allowed = builtin ? (DS.REG[type].pages.indexOf(slug) !== -1) : true;
      if (!allowed) { return; }
      var b = document.createElement("button");
      b.className = "ds-pal-item"; b.type = "button";
      b.innerHTML = '<i class="fas fa-plus"></i> ' + DS.esc(DS.REG[type].label);
      b.addEventListener("click", function () { addBlock(type); });
      pal.appendChild(b);
    });
  }

  // --------------------------------------------------------------- rail list
  function renderRail() {
    var list = document.getElementById("dsBlockList");
    if (!list) { return; }
    list.innerHTML = "";
    var blocks = st.layout.blocks || [];
    blocks.forEach(function (b, i) {
      var frame = isFrame(b.type);
      var li = document.createElement("li");
      li.className = "ds-blk" + (frame ? " ds-blk-frame" : "");
      li.draggable = !frame;
      li.dataset.i = i;
      li.innerHTML =
        '<span class="ds-blk-grip"><i class="fas fa-grip-vertical"></i></span>' +
        '<span class="ds-blk-name">' + DS.esc(blockLabel(b.type)) + '</span>' +
        (frame
          ? '<span class="ds-blk-lock"><i class="fas fa-lock"></i> Frame</span>'
          : '<span class="ds-blk-acts">' +
              '<button data-a="up" title="Move up"><i class="fas fa-chevron-up"></i></button>' +
              '<button data-a="down" title="Move down"><i class="fas fa-chevron-down"></i></button>' +
              '<button data-a="del" title="Remove"><i class="fas fa-trash"></i></button>' +
            '</span>');
      list.appendChild(li);

      if (!frame) {
        li.querySelector('[data-a="up"]').addEventListener("click", function () { moveDir(i, -1); });
        li.querySelector('[data-a="down"]').addEventListener("click", function () { moveDir(i, 1); });
        li.querySelector('[data-a="del"]').addEventListener("click", function () { removeBlock(i); });
        li.addEventListener("dragstart", function (e) { e.dataTransfer.setData("text/plain", String(i)); li.classList.add("ds-drag"); });
        li.addEventListener("dragend", function () { li.classList.remove("ds-drag"); });
        li.addEventListener("dragover", function (e) { e.preventDefault(); li.classList.add("ds-over"); });
        li.addEventListener("dragleave", function () { li.classList.remove("ds-over"); });
        li.addEventListener("drop", function (e) {
          e.preventDefault(); li.classList.remove("ds-over");
          var from = parseInt(e.dataTransfer.getData("text/plain"), 10);
          dropMove(from, i);
        });
      }
    });
  }

  function bodyRange() {
    var b = st.layout.blocks || [];
    var min = (b.length && isFrame(b[0].type)) ? 1 : 0;
    var max = (b.length && isFrame(b[b.length - 1].type)) ? b.length - 2 : b.length - 1;
    return { min: min, max: max };
  }

  // --------------------------------------------------------------- mutations
  function addBlock(type) {
    st.undo.push();
    var b = st.layout.blocks || (st.layout.blocks = []);
    var last = b.length - 1;
    var insert = (b.length && isFrame(b[last].type)) ? last : b.length; // above footer
    b.splice(insert, 0, { type: type, settings: DS.REG[type] ? DS.clone(DS.REG[type].defaults) : {} });
    renderRail(); scheduleSave(0, true);
  }

  function moveDir(i, dir) {
    var b = st.layout.blocks;
    var t = i + dir;
    if (t < 0 || t >= b.length) { return; }
    if (isFrame(b[i].type) || isFrame(b[t].type)) { return; }
    st.undo.push();
    arrayMove(b, i, t);
    renderRail(); scheduleSave(0, true);
  }

  function dropMove(from, to) {
    var b = st.layout.blocks;
    if (from === to || isFrame(b[from].type)) { return; }
    var r = bodyRange();
    if (to < r.min) { to = r.min; }
    if (to > r.max) { to = r.max; }
    st.undo.push();
    arrayMove(b, from, to);
    renderRail(); scheduleSave(0, true);
  }

  function removeBlock(i) {
    if (isFrame(st.layout.blocks[i].type)) { return; }
    st.undo.push();
    st.layout.blocks.splice(i, 1);
    renderRail(); scheduleSave(0, true);
  }

  // --------------------------------------------------------------- save + preview
  function scheduleSave(delay, refreshAfter) {
    clearTimeout(saveTimer);
    saveTimer = setTimeout(function () {
      DS.api("save_draft", { page: st.slug, device: "desktop", layout_json: JSON.stringify(st.layout) })
        .then(function () { if (refreshAfter) { refreshPreview(); } })
        .catch(function (e) { DS.toast(e.message); });
    }, delay || 0);
  }

  function refreshPreview() {
    var f = document.getElementById("dsPvFrame");
    if (!f) { return; }
    var url = DS.previewUrl({ page: st.slug, device: DS.ds.device });
    url += "&scroll=" + Math.round(st.scrollY || 0);
    f.src = url;
  }

  // =============================================================== template editor
  function renderTemplateEditor(params) {
    var id = (params && params.id) || st.slug;
    var root = document.getElementById("dsPage");
    DS.api("list_templates", {}).then(function (r) {
      var tpl = (r.templates || []).filter(function (t) { return t.id === id; })[0];
      if (!tpl) { root.innerHTML = '<div class="ds-stub"><p>Template not found.</p></div>'; return; }
      var ps = tpl.page_style || {};
      root.innerHTML =
        '<header class="ds-topbar">' +
          '<button class="ds-back" id="dsTBack"><i class="fas fa-arrow-left"></i> Back</button>' +
          '<h1>Template — ' + DS.esc(tpl.name) + '</h1>' +
        '</header>' +
        '<div class="ds-tpl">' +
          '<div class="ds-tpl-card">' +
            '<h3>Page frame</h3>' +
            '<p class="ds-dlg-say">Every page based on this template shows this top menu and footer. Their contents are edited from Blocks on the home screen.</p>' +
            '<div class="ds-frame-row"><i class="fas fa-lock"></i> ' + DS.esc(blockLabel((tpl.header && tpl.header.type) || "site_header")) + ' <span class="ds-tile-meta">top of every page</span></div>' +
            '<div class="ds-frame-row"><i class="fas fa-lock"></i> ' + DS.esc(blockLabel((tpl.footer && tpl.footer.type) || "footer")) + ' <span class="ds-tile-meta">bottom of every page</span></div>' +
          '</div>' +
          '<div class="ds-tpl-card">' +
            '<h3>Page margins &amp; width</h3>' +
            styleField("Maximum width", "container_max", ps.container_max || "1200px") +
            styleField("Side gutter", "gutter", ps.gutter || "24px") +
            styleField("Outer margin", "margin", ps.margin || "0") +
            styleField("Inner padding", "padding", ps.padding || "0") +
            '<label class="ds-f"><span>Background</span><input type="color" data-k="background" value="' + DS.esc(toHex(ps.background || "#ffffff")) + '"></label>' +
            '<div class="ds-dlg-actions"><button class="ds-btn ds-btn-primary" id="dsTSave">Save template</button></div>' +
            '<p class="ds-dlg-say" id="dsTmsg"></p>' +
          '</div>' +
        '</div>';
      root.querySelector("#dsTBack").addEventListener("click", function () { history.back(); });
      root.querySelector("#dsTSave").addEventListener("click", function () {
        var card = root;
        var style = {};
        card.querySelectorAll("[data-k]").forEach(function (inp) { style[inp.dataset.k] = inp.value; });
        var all = r.templates.map(function (t) {
          if (t.id === id) { var c = DS.clone(t); c.page_style = Object.assign({}, c.page_style || {}, style); return c; }
          return t;
        });
        DS.api("save_templates", { meta_json: JSON.stringify({ templates: all }) })
          .then(function () { DS.toast("Template saved"); })
          .catch(function (e) { DS.toast(e.message); });
      });
    }).catch(function (e) { root.innerHTML = '<div class="ds-stub"><p>' + DS.esc(e.message) + '</p></div>'; });
  }

  function styleField(label, key, val) {
    return '<label class="ds-f"><span>' + DS.esc(label) + '</span><input type="text" data-k="' + key + '" value="' + DS.esc(val) + '"></label>';
  }
  function toHex(v) { return (/^#([0-9a-f]{6})$/i.test(String(v).trim())) ? v : "#ffffff"; }
})();
