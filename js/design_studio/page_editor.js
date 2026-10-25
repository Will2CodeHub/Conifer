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
  // Frame blocks (top menu / footer) are locked on PAGES only. In a template the
  // whole layout is editable.
  function locked(type) { return st && st.kind !== "template" && isFrame(type); }
  function blockLabel(type) { return (DS.REG[type] && DS.REG[type].label) || type; }
  // History identity + snapshot for the current page or template.
  function histKind() { return st.kind === "template" ? "template" : "page"; }
  function histId() { return st.kind === "template" ? st.tplId : st.slug; }
  function histSnapshot() { return st.kind === "template" ? { blocks: st.layout.blocks, page_style: st.pageStyle } : st.layout; }
  // A template's block list (new 'blocks' array, or derived from legacy header/footer).
  function templateBlocks(tpl) {
    if (tpl.blocks && tpl.blocks.length) { return DS.clone(tpl.blocks); }
    var b = [];
    if (tpl.header && tpl.header.type) { b.push(DS.clone(tpl.header)); }
    if (tpl.footer && tpl.footer.type) { b.push(DS.clone(tpl.footer)); }
    return b;
  }

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
    var isT = kind === "template";
    st = { slug: slug, kind: kind, name: name, layout: { theme: {}, blocks: [] },
           undo: null, scrollY: 0, tplId: (params && params.id) || slug, pageStyle: {} };
    st.undo = DsUndo(function () { return st.layout; },
      function (l) { st.layout = l; renderRail(); scheduleSave(0, true); });  // save THEN refresh, so the revert actually shows

    var root = document.getElementById("dsPage");
    root.innerHTML =
      topbar(isT ? ("Template — " + name) : name) +
      '<div class="ds-pe">' +
        '<aside class="ds-pe-rail">' +
          '<div class="ds-pe-sec"><h3>Add a block</h3><div class="ds-pal" id="dsPal"></div></div>' +
          '<div class="ds-pe-sec"><h3>Blocks</h3><ul class="ds-blocklist" id="dsBlockList"></ul>' +
            '<p class="ds-rail-hint">' + (isT
              ? "Drag to reorder. Pages based on this template start from these blocks; edit each block’s content from Blocks on the home screen."
              : "Drag to reorder. The top menu and footer are the page frame — edit their contents from Blocks on the home screen.") + '</p>' +
          '</div>' +
          '<div class="ds-pe-sec" id="' + (isT ? "dsPeStyleSec" : "dsPeTplSec") + '"></div>' +
        '</aside>' +
        '<main class="ds-pe-preview"><iframe id="dsPvFrame" title="Live preview"></iframe></main>' +
      '</div>';
    wireTopbar(root, name);
    renderPalette();

    if (isT) {
      DS.api("list_templates", {}).then(function (r) {
        st.allTemplates = r.templates || [];
        var tpl = st.allTemplates.filter(function (t) { return t.id === st.tplId; })[0];
        if (!tpl) { root.innerHTML = '<div class="ds-stub"><p>Template not found.</p></div>'; return; }
        st.tpl = tpl;
        st.layout = { theme: {}, blocks: templateBlocks(tpl) };
        st.pageStyle = tpl.page_style || {};
        renderStyleSection(root);
        renderRail(); refreshPreview();
      }).catch(function (e) { DS.toast(e.message); });
    } else {
      loadTemplateAssign(slug);
      DS.api("load", { page: slug, device: "desktop" }).then(function (j) {
        var layout = j.layout;
        if (!layout || !layout.blocks) { layout = { theme: {}, blocks: [] }; }
        st.layout = layout;
        renderRail();
        refreshPreview();
      }).catch(function (e) { DS.toast(e.message); });
    }
  };

  function topbar(name) {
    var ds = DS.ds;
    return '<header class="ds-topbar">' +
      '<button class="ds-back" id="dsPeBack"><i class="fas fa-arrow-left"></i> Design home</button>' +
      '<h1>' + DS.esc(name) + '</h1>' +
      '<div class="ds-grow"></div>' +
      '<button class="ds-btn ds-btn-ghost" id="dsPeUndo"><i class="fas fa-rotate-left"></i> Undo</button>' +
      '<button class="ds-btn ds-btn-ghost" id="dsPeRedo"><i class="fas fa-rotate-right"></i> Redo</button>' +
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
      clearTimeout(saveTimer);                   // flush any pending edit before leaving
      saveLayout().catch(function () {});
      if (st && st.undo) { st.undo.clear(); } DS.dsGo("home");
    });
    root.querySelector("#dsPeUndo").addEventListener("click", function () {
      if (st.undo.canUndo()) { st.undo.undo(); DS.toast("Reverted"); } else { DS.toast("Nothing to undo"); }
    });
    root.querySelector("#dsPeRedo").addEventListener("click", function () {
      if (st.undo.canRedo()) { st.undo.redo(); DS.toast("Redone"); } else { DS.toast("Nothing to redo"); }
    });
    root.querySelector("#dsPePreview").addEventListener("click", function () {
      var url = st.kind === "template"
        ? ("https://" + DS.ds.host + "/design/?preview=1&template=" + encodeURIComponent(st.tplId) + "&_=" + Date.now())
        : DS.previewUrl({ page: st.slug, device: DS.ds.device });
      window.open(url, "_blank");
    });
    root.querySelector("#dsPeSave").addEventListener("click", function () {
      saveLayout().then(function () {
        DS.addHistory(histKind(), histId(), histSnapshot());   // record this Save in history
        DS.toast("Saved"); refreshPreview();
      }).catch(function (e) { DS.toast(e.message); });
    });
    var hist = root.querySelector("#dsPeHistory");
    if (hist) {
      hist.addEventListener("click", function () {
        DS.openItemHistory(histKind(), histId(), function (snap) {
          if (!snap) { DS.toast("That version is empty"); return; }
          if (st.kind === "template") {
            st.layout.blocks = snap.blocks || []; st.pageStyle = snap.page_style || {};
            renderRail(); renderStyleSection(); scheduleSave(0, true);
          } else if (snap.blocks) {
            st.undo.push(); st.layout = snap; renderRail(); scheduleSave(0, true);
          } else { DS.toast("That version has nothing for this page"); }
        });
      });
    }
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
      if (locked(type)) { return; }
      var allowed = (st.kind === "template" || !builtin) ? true : (DS.REG[type].pages.indexOf(slug) !== -1);
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
      var frame = locked(b.type);
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
    if (st.kind === "template") { return { min: 0, max: b.length - 1 }; } // full freedom in templates
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
    if (locked(b[i].type) || locked(b[t].type)) { return; }
    st.undo.push();
    arrayMove(b, i, t);
    renderRail(); scheduleSave(0, true);
  }

  function dropMove(from, to) {
    var b = st.layout.blocks;
    if (from === to || locked(b[from].type)) { return; }
    var r = bodyRange();
    if (to < r.min) { to = r.min; }
    if (to > r.max) { to = r.max; }
    st.undo.push();
    arrayMove(b, from, to);
    renderRail(); scheduleSave(0, true);
  }

  function removeBlock(i) {
    if (locked(st.layout.blocks[i].type)) { return; }
    st.undo.push();
    st.layout.blocks.splice(i, 1);
    renderRail(); scheduleSave(0, true);
  }

  // --------------------------------------------------------------- save + preview
  // Save the current layout — to the page draft, or (for a template) into the
  // template's own blocks + page_style in the _templates store.
  function saveLayout() {
    if (st.kind === "template") {
      var all = (st.allTemplates || []).map(function (t) {
        if (t.id === st.tplId) { var c = DS.clone(t); c.blocks = st.layout.blocks; c.page_style = st.pageStyle; delete c.header; delete c.footer; return c; }
        return t;
      });
      st.allTemplates = all;
      return DS.api("save_templates", { meta_json: JSON.stringify({ templates: all }) });
    }
    return DS.api("save_draft", { page: st.slug, device: "desktop", layout_json: JSON.stringify(st.layout) });
  }
  function scheduleSave(delay, refreshAfter) {
    clearTimeout(saveTimer);
    saveTimer = setTimeout(function () {
      saveLayout().then(function () { if (refreshAfter) { refreshPreview(); } }).catch(function (e) { DS.toast(e.message); });
    }, delay || 0);
  }

  function refreshPreview() {
    var f = document.getElementById("dsPvFrame");
    if (!f) { return; }
    var wrap = f.parentNode;
    if (wrap) { wrap.classList.toggle("ds-mobile", DS.ds.device === "mobile"); } // narrow phone frame
    if (st.kind === "template") {
      f.src = "https://" + DS.ds.host + "/design/?preview=1&template=" + encodeURIComponent(st.tplId) + "&device=" + DS.ds.device + "&_=" + Date.now();
      return;
    }
    f.src = DS.previewUrl({ page: st.slug, device: DS.ds.device }) + "&scroll=" + Math.round(st.scrollY || 0);
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

  // =============================================================== template page-style section
  function numOf(v, def) { var m = String(v == null ? "" : v).match(/-?\d+/); return m ? parseInt(m[0], 10) : def; }

  function renderStyleSection() {
    var sec = document.getElementById("dsPeStyleSec");
    if (!sec) { return; }
    var ps = st.pageStyle || (st.pageStyle = {});
    var delBtn = st.tplId === "tpl_default" ? "" :
      '<button class="ds-btn ds-btn-danger ds-sm" id="dsTDel" style="margin-top:12px"><i class="fas fa-trash"></i> Delete template</button>';
    sec.innerHTML =
      '<h3>Page size &amp; spacing</h3>' +
      sliderRow("Maximum width", "container_max", 700, 1920, numOf(ps.container_max, 1200), "px") +
      sliderRow("Side gutter", "gutter", 0, 80, numOf(ps.gutter, 24), "px") +
      sliderRow("Space above &amp; below", "padding", 0, 120, numOf(ps.padding, 0), "px") +
      '<label class="ds-f"><span>Background colour</span><input type="color" id="dsTbg" value="' + DS.esc(toHex(ps.background || "#ffffff")) + '"></label>' +
      delBtn;
    sec.querySelectorAll("input[type=range][data-ps]").forEach(function (sl) {
      sl.addEventListener("input", function () {
        var out = document.getElementById(sl.id + "_out"); if (out) { out.textContent = sl.value + "px"; }
        ps[sl.dataset.ps] = (sl.dataset.ps === "padding") ? (sl.value + "px 0") : (sl.value + "px");
        scheduleSave(250, true);
      });
    });
    sec.querySelector("#dsTbg").addEventListener("input", function () { ps.background = this.value; scheduleSave(250, true); });
    var del = sec.querySelector("#dsTDel");
    if (del) { del.addEventListener("click", deleteTemplate); }
  }

  function sliderRow(label, key, min, max, val, unit) {
    var id = "sl_" + key;
    return '<div class="ds-slider-row"><label>' + label + ' <span class="ds-slider-out" id="' + id + '_out">' + val + unit + '</span></label>' +
      '<input type="range" id="' + id + '" data-ps="' + key + '" min="' + min + '" max="' + max + '" value="' + val + '"></div>';
  }

  function deleteTemplate() {
    if (!confirm("Delete this template? Pages using it will fall back to no template.")) { return; }
    DS.api("delete_template", { id: st.tplId })
      .then(function () { DS.toast("Template deleted"); DS.dsGo("home"); })
      .catch(function (e) { DS.toast(e.message); });
  }

  function toHex(v) { return (/^#([0-9a-f]{6})$/i.test(String(v).trim())) ? v : "#ffffff"; }
})();
