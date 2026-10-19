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
      function (l) { st.layout = l; renderRail(); scheduleSave(0, true); });  // save THEN refresh, so the revert actually shows

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
          '<div class="ds-pe-sec" id="dsPeTplSec"></div>' +
        '</aside>' +
        '<main class="ds-pe-preview"><iframe id="dsPvFrame" title="Live preview"></iframe></main>' +
      '</div>';
    wireTopbar(root, name);
    renderPalette();
    loadTemplateAssign(slug);

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
      '<button class="ds-back" id="dsPeBack"><i class="fas fa-arrow-left"></i> Design home</button>' +
      '<h1>' + DS.esc(name) + '</h1>' +
      '<div class="ds-grow"></div>' +
      '<button class="ds-btn ds-btn-ghost" id="dsPeUndo"><i class="fas fa-rotate-left"></i> Undo</button>' +
      '<button class="ds-btn ds-btn-ghost" id="dsPeHistory"><i class="fas fa-clock-rotate-left"></i> History</button>' +
      '<div class="ds-seg" aria-label="Preview device">' +
        '<button data-dev="desktop" class="' + (ds.device === "desktop" ? "on" : "") + '">Desktop</button>' +
        '<button data-dev="mobile" class="' + (ds.device === "mobile" ? "on" : "") + '">Mobile</button>' +
      '</div>' +
      '<button class="ds-btn ds-btn-ghost" id="dsPePreview"><i class="fas fa-up-right-from-square"></i> Preview</button>' +
      '<button class="ds-btn ds-btn-primary" id="dsPeSave"><i class="fas fa-floppy-disk"></i> Save</button>' +
    '</header>';
  }

  function wireTopbar(root, name) {
    root.querySelector("#dsPeBack").addEventListener("click", function () {
      clearTimeout(saveTimer);                   // flush any pending layout edit before leaving
      DS.api("save_draft", { page: st.slug, device: "desktop", layout_json: JSON.stringify(st.layout) }).catch(function () {});
      if (st && st.undo) { st.undo.clear(); } DS.dsGo("home");
    });
    root.querySelector("#dsPeUndo").addEventListener("click", function () {
      if (st.undo.canUndo()) { st.undo.undo(); DS.toast("Reverted"); } else { DS.toast("Nothing to undo"); }
    });
    root.querySelector("#dsPePreview").addEventListener("click", function () {
      window.open(DS.previewUrl({ page: st.slug, device: DS.ds.device }), "_blank");
    });
    root.querySelector("#dsPeSave").addEventListener("click", function () {
      DS.api("save_draft", { page: st.slug, device: "desktop", layout_json: JSON.stringify(st.layout) })
        .then(function () { DS.toast("Saved"); refreshPreview(); }).catch(function (e) { DS.toast(e.message); });
    });
    root.querySelector("#dsPeHistory").addEventListener("click", function () {
      DS.openItemHistory("page", st.slug, function (res) {
        if (res.layout && res.layout.blocks) {
          st.undo.push(); st.layout = res.layout; renderRail(); scheduleSave(0, true);
        } else { DS.toast("That version has nothing for this page"); }
      });
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

  // --------------------------------------------------------------- assign a template to this page
  function loadTemplateAssign(slug) {
    var sec = document.getElementById("dsPeTplSec");
    if (!sec) { return; }
    Promise.all([DS.api("list_templates", {}), DS.api("list_assign", {}), DS.api("list_pages", {})]).then(function (r) {
      var tpls = r[0].templates || [], assign = r[1].assign || {};
      var custom = {}; (r[2].pages || []).forEach(function (p) { custom[p.slug] = p.template; });
      var cur = (slug in assign) ? assign[slug] : (custom[slug] || "");
      var opts = '<option value="">No template (page keeps its own header &amp; footer)</option>' +
        tpls.map(function (t) { return '<option value="' + DS.esc(t.id) + '"' + (cur === t.id ? ' selected' : '') + '>' + DS.esc(t.name) + '</option>'; }).join("");
      sec.innerHTML = '<h3>Page template</h3><select class="ds-pe-assign" id="dsTplAssign">' + opts + '</select>' +
        '<p class="ds-rail-hint">Apply a template to use its shared header, footer and page width. While a template is applied, this page’s own top menu and footer are hidden.</p>';
      sec.querySelector("#dsTplAssign").addEventListener("change", function () {
        DS.api("assign_template", { slug: slug, template: this.value })
          .then(function () { DS.toast("Template updated"); refreshPreview(); })
          .catch(function (e) { DS.toast(e.message); });
      });
    }).catch(function () {});
  }

  // =============================================================== template editor (a real editor)
  var ts = null;  // { id, tpl, all }
  var tplSaveTimer = null;

  function renderTemplateEditor(params) {
    var id = (params && params.id) || st.slug;
    var root = document.getElementById("dsPage");
    DS.api("list_templates", {}).then(function (r) {
      var tpl = (r.templates || []).filter(function (t) { return t.id === id; })[0];
      if (!tpl) { root.innerHTML = '<div class="ds-stub"><p>Template not found.</p></div>'; return; }
      ts = { id: id, tpl: DS.clone(tpl), all: r.templates };
      renderTpl(root);
    }).catch(function (e) { root.innerHTML = '<div class="ds-stub"><p>' + DS.esc(e.message) + '</p></div>'; });
  }

  function numOf(v, def) { var m = String(v == null ? "" : v).match(/-?\d+/); return m ? parseInt(m[0], 10) : def; }

  function renderTpl(root) {
    var tpl = ts.tpl, ps = tpl.page_style || (tpl.page_style = {});
    var hasHdr = tpl.header && tpl.header.type, hasFtr = tpl.footer && tpl.footer.type;
    var delBtn = ts.id === "tpl_default" ? "" : '<button class="ds-btn ds-btn-danger" id="dsTDel"><i class="fas fa-trash"></i> Delete template</button>';
    root.innerHTML =
      '<header class="ds-topbar">' +
        '<button class="ds-back" id="dsTBack"><i class="fas fa-arrow-left"></i> Design home</button>' +
        '<h1>Template — ' + DS.esc(tpl.name) + '</h1>' +
        '<div class="ds-grow"></div>' + delBtn +
        '<button class="ds-btn ds-btn-ghost" id="dsTPreview"><i class="fas fa-up-right-from-square"></i> Preview</button>' +
      '</header>' +
      '<div class="ds-pe">' +
        '<aside class="ds-pe-rail">' +
          '<div class="ds-pe-sec"><h3>Page frame</h3>' +
            frameRow("header", "Header (top)", hasHdr, tpl.header) +
            frameRow("footer", "Footer (bottom)", hasFtr, tpl.footer) +
            '<p class="ds-rail-hint">The header and footer appear on every page using this template. Edit their contents from Blocks on the home screen.</p>' +
          '</div>' +
          '<div class="ds-pe-sec"><h3>Page size &amp; spacing</h3>' +
            sliderRow("Maximum width", "container_max", 700, 1920, numOf(ps.container_max, 1200), "px") +
            sliderRow("Side gutter", "gutter", 0, 80, numOf(ps.gutter, 24), "px") +
            sliderRow("Space above &amp; below", "padding", 0, 120, numOf(ps.padding, 0), "px") +
            '<label class="ds-f"><span>Background colour</span><input type="color" id="dsTbg" value="' + DS.esc(toHex(ps.background || "#ffffff")) + '"></label>' +
          '</div>' +
        '</aside>' +
        '<main class="ds-pe-preview"><iframe id="dsTplPv" title="Template preview"></iframe></main>' +
      '</div>';

    root.querySelector("#dsTBack").addEventListener("click", function () { DS.dsGo("home"); });
    root.querySelector("#dsTPreview").addEventListener("click", function () {
      window.open("https://" + DS.ds.host + "/design/?preview=1&template=" + encodeURIComponent(ts.id) + "&_=" + Date.now(), "_blank");
    });
    if (root.querySelector("#dsTDel")) {
      root.querySelector("#dsTDel").addEventListener("click", deleteTemplate);
    }
    // frame add/remove
    root.querySelectorAll("[data-frame-act]").forEach(function (b) {
      b.addEventListener("click", function () {
        var slot = b.getAttribute("data-slot"), act = b.getAttribute("data-frame-act");
        if (act === "remove") { tpl[slot] = null; }
        else { tpl[slot] = { type: slot === "header" ? "site_header" : "footer", settings: {} }; }
        saveTpl(); renderTpl(root);
      });
    });
    // sliders
    root.querySelectorAll("input[type=range][data-ps]").forEach(function (sl) {
      sl.addEventListener("input", function () {
        var out = root.querySelector("#" + sl.id + "_out"); if (out) { out.textContent = sl.value + "px"; }
        ps[sl.dataset.ps] = (sl.dataset.ps === "padding") ? (sl.value + "px 0") : (sl.value + "px");
        saveTpl();
      });
    });
    root.querySelector("#dsTbg").addEventListener("input", function () { ps.background = this.value; saveTpl(); });
    refreshTplPreview();
  }

  function frameRow(slot, label, present, block) {
    if (present) {
      return '<div class="ds-blk"><span class="ds-blk-name">' + DS.esc(label) + ': ' + DS.esc(blockLabel(block.type)) + '</span>' +
        '<span class="ds-blk-acts"><button data-frame-act="remove" data-slot="' + slot + '" title="Remove"><i class="fas fa-trash"></i></button></span></div>';
    }
    return '<div class="ds-blk ds-blk-frame"><span class="ds-blk-name" style="color:#94a3b8">' + DS.esc(label) + ': none</span>' +
      '<span class="ds-blk-acts"><button class="ds-addframe" data-frame-act="add" data-slot="' + slot + '"><i class="fas fa-plus"></i> Add</button></span></div>';
  }

  function sliderRow(label, key, min, max, val, unit) {
    var id = "sl_" + key;
    return '<div class="ds-slider-row"><label>' + label + ' <span class="ds-slider-out" id="' + id + '_out">' + val + unit + '</span></label>' +
      '<input type="range" id="' + id + '" data-ps="' + key + '" min="' + min + '" max="' + max + '" value="' + val + '"></div>';
  }

  function refreshTplPreview() {
    var f = document.getElementById("dsTplPv");
    if (f) { f.src = "https://" + DS.ds.host + "/design/?preview=1&template=" + encodeURIComponent(ts.id) + "&_=" + Date.now(); }
  }

  function saveTpl() {
    var all = ts.all.map(function (t) { return t.id === ts.id ? ts.tpl : t; });
    clearTimeout(tplSaveTimer);
    tplSaveTimer = setTimeout(function () {
      DS.api("save_templates", { meta_json: JSON.stringify({ templates: all }) })
        .then(refreshTplPreview)
        .catch(function (e) { DS.toast(e.message); });
    }, 350);
  }

  function deleteTemplate() {
    if (!confirm("Delete this template? Pages using it will fall back to no template.")) { return; }
    DS.api("delete_template", { id: ts.id })
      .then(function () { DS.toast("Template deleted"); DS.dsGo("home"); })
      .catch(function (e) { DS.toast(e.message); });
  }

  function toHex(v) { return (/^#([0-9a-f]{6})$/i.test(String(v).trim())) ? v : "#ffffff"; }
})();
