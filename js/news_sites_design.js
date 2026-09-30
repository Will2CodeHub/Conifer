/* TEN Design editor — per-publication layout/theme/spacing modal.
   Exposes window.openDesignModal(publicationRow). Talks to ajax/design_layout.php.
   Desktop tab edits the full layout; Mobile tab edits a diff (hide blocks + theme
   overrides) so mobile "follows desktop but lighter". */
(function () {
  "use strict";

  // Registry mirror — keep in sync with lib/design_registry.php.
  var PAGES = ["front", "section", "article", "impressum", "contact", "about", "privacy", "terms", "disclaimer"];
  var PAGE_LABELS = {
    front: "Front page", section: "Section page", article: "Article page",
    impressum: "Impressum", contact: "Contact", about: "About us",
    privacy: "Privacy policy", terms: "Terms", disclaimer: "Disclaimer"
  };
  var REG = {
    site_header:      { label: "Site header",       pages: PAGES, defaults: { show_search: true, show_subscribe: true } },
    masthead:         { label: "Masthead",          pages: ["front"], defaults: { tagline: "" } },
    front_feature:    { label: "Front feature",     pages: ["front"], defaults: { mid_count: 2, sidebar_count: 6, hero_ratio: "3 / 2", mid_ratio: "16 / 9" } },
    front_sections:   { label: "Section blocks (all sections)", pages: ["front"], defaults: { per_section: 3, sections: "" } },
    headlines_list:   { label: "Latest headlines",  pages: ["front", "section"], defaults: { count: 5, show_readtime: true } },
    section_title:    { label: "Section title",     pages: ["section"], defaults: { uppercase: true } },
    section_lead:     { label: "Section lead + headlines", pages: ["section"], defaults: { sidebar_count: 6 } },
    article_grid:     { label: "Article grid",      pages: ["section"], defaults: { columns: 3, per_page: 12 } },
    article_header:   { label: "Article header",    pages: ["article"], defaults: {} },
    article_hero:     { label: "Article hero image",pages: ["article"], defaults: { ratio: "16x9" } },
    article_body:     { label: "Article body",      pages: ["article"], defaults: {} },
    article_byline:   { label: "Byline",            pages: ["article"], defaults: {} },
    article_comments: { label: "Comments",          pages: ["article"], defaults: { enabled: true } },
    section_carousel: { label: "Section carousel",  pages: ["front", "section", "article"], defaults: { section: "", count: 10 } },
    advert:           { label: "Advert slot",       pages: ["front", "section", "article"], defaults: { slot: "" } },
    ticker:           { label: "News ticker",       pages: ["front"], defaults: {} },
    breaking_news:    { label: "Breaking news",     pages: ["front"], defaults: { count: 6 } },
    rich_text:        { label: "Rich text",         pages: ["impressum", "contact", "about", "privacy", "terms", "disclaimer"], defaults: { content_key: "" } },
    footer:           { label: "Footer",            pages: PAGES, defaults: {} }
  };
  var THEME = [
    { k: "font_display", label: "Display font", t: "text" },
    { k: "font_body",    label: "Body font",    t: "text" },
    { k: "font_ui",      label: "UI font",      t: "text" },
    { k: "color_ink",    label: "Ink",     t: "color" },
    { k: "color_body",   label: "Body",    t: "color" },
    { k: "color_muted",  label: "Muted",   t: "color" },
    { k: "color_accent", label: "Accent",  t: "color" },
    { k: "color_bg",     label: "Background", t: "color" },
    { k: "color_button", label: "Button",  t: "color" }
  ];
  var SPACING = [
    { k: "container_max", label: "Max width" },
    { k: "gutter",        label: "Side gutter" },
    { k: "section_gap",   label: "Block gap (vertical)" },
    { k: "block_gap",     label: "Inner gap" }
  ];

  var st = null;   // { pub, host, page, device, desktop, mobile, sel }
  var el = {};     // cached DOM

  function esc(s) { return String(s == null ? "" : s).replace(/[&<>"]/g, function (c) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c]; }); }
  function clone(o) { return JSON.parse(JSON.stringify(o)); }

  function hostFrom(url) {
    var h = String(url || "").replace(/^https?:\/\//, "").replace(/\/.*$/, "");
    return h;
  }
  function previewUrl() {
    return "https://" + st.host + "/design/?preview=1&page=" + encodeURIComponent(st.page) +
           "&device=" + encodeURIComponent(st.device) + "&_=" + Date.now();
  }

  function api(action, data) {
    var body = new URLSearchParams();
    body.set("action", action);
    body.set("pub", st.pub);
    Object.keys(data || {}).forEach(function (k) { body.set(k, data[k]); });
    return fetch("ajax/design_layout.php", { method: "POST", body: body })
      .then(function (r) { return r.json(); })
      .then(function (j) { if (!j.success) throw new Error(j.message || "Request failed"); return j; });
  }

  function toast(msg) {
    var t = document.createElement("div");
    t.className = "dl-toast"; t.textContent = msg; document.body.appendChild(t);
    setTimeout(function () { t.remove(); }, 2400);
  }

  // ---- modal construction (once) ----
  function build() {
    if (el.modal) { return; }
    var root = document.getElementById("dlDesignRoot");
    var ov = document.createElement("div"); ov.className = "dl-ov"; ov.id = "dlOv";
    var m = document.createElement("div"); m.className = "dl-modal"; m.id = "dlModal";
    m.innerHTML =
      '<div class="dl-bar">' +
        '<h3 id="dlTitle">Design</h3>' +
        '<label class="dl-pagesel-wrap">Page: <select id="dlPageSel" class="dl-pagesel"></select></label>' +
        '<div class="dl-tabs" id="dlDevices">' +
          '<button class="dl-chip dl-active" data-dev="desktop">Desktop</button>' +
          '<button class="dl-chip" data-dev="mobile">Mobile</button>' +
        '</div>' +
        '<div class="dl-spacer"></div>' +
        '<button class="dl-b preview" id="dlPreviewBtn">Preview ↗</button>' +
        '<button class="dl-b save" id="dlSave">Save draft</button>' +
        '<button class="dl-b publish" id="dlPublish">Publish</button>' +
        '<button class="dl-b history" id="dlHistory">History</button>' +
        '<button class="dl-b max" id="dlMax" title="Maximise">⤢</button>' +
        '<button class="dl-b close" id="dlClose">Close</button>' +
      '</div>' +
      '<div class="dl-body">' +
        '<div class="dl-col left"><h4>Blocks</h4><ul class="dl-blocklist" id="dlBlocks"></ul>' +
          '<h4>Add block</h4><div class="dl-palette" id="dlPalette"></div></div>' +
        '<div class="dl-col center" style="padding:0"><div class="dl-preview-wrap" id="dlPvWrap"><iframe class="dl-preview" id="dlPreview"></iframe></div></div>' +
        '<div class="dl-col right" id="dlRight"></div>' +
      '</div>';
    root.appendChild(ov); root.appendChild(m);

    el.ov = ov; el.modal = m;
    el.pageSel = m.querySelector("#dlPageSel");
    el.blocks = m.querySelector("#dlBlocks");
    el.palette = m.querySelector("#dlPalette");
    el.right = m.querySelector("#dlRight");
    el.preview = m.querySelector("#dlPreview");
    el.pvWrap = m.querySelector("#dlPvWrap");
    el.title = m.querySelector("#dlTitle");

    PAGES.forEach(function (p) {
      var o = document.createElement("option");
      o.value = p; o.textContent = PAGE_LABELS[p] || p;
      el.pageSel.appendChild(o);
    });
    el.pageSel.addEventListener("change", function () { setPage(el.pageSel.value); });
    m.querySelectorAll("#dlDevices .dl-chip").forEach(function (b) {
      b.addEventListener("click", function () { setDevice(b.dataset.dev); });
    });
    m.querySelector("#dlPreviewBtn").addEventListener("click", function () { window.open(previewUrl(), "_blank"); });
    m.querySelector("#dlSave").addEventListener("click", saveDraft);
    m.querySelector("#dlPublish").addEventListener("click", doPublish);
    m.querySelector("#dlHistory").addEventListener("click", showHistory);
    m.querySelector("#dlMax").addEventListener("click", function () { el.modal.classList.toggle("dl-max"); });
    m.querySelector("#dlClose").addEventListener("click", close);
    ov.addEventListener("click", close);

    // Clicking a block in the preview iframe selects it and opens its settings.
    window.addEventListener("message", function (e) {
      var d = e.data;
      if (!d || d.source !== "ten-design" || d.action !== "select" || !st) { return; }
      if (st.device !== "desktop") { setDevice("desktop"); }
      var idx = parseInt(d.index, 10);
      if (!isNaN(idx) && st.desktop.blocks[idx]) { st.sel = idx; render(); }
    });
  }

  function open(p) {
    build();
    st = { pub: p.publication, host: hostFrom(p.url), page: "front", device: "desktop",
           desktop: { theme: {}, blocks: [] }, mobile: {}, sel: -1 };
    el.title.textContent = "Design — " + p.title;
    el.ov.classList.add("dl-show"); el.modal.classList.add("dl-show");
    document.body.style.overflow = "hidden"; // kill the page scrollbar behind the modal
    syncChips();
    loadPage();
  }
  function close() {
    if (el.ov) { el.ov.classList.remove("dl-show"); el.modal.classList.remove("dl-show"); }
    document.body.style.overflow = "";
  }

  function syncChips() {
    if (el.pageSel) { el.pageSel.value = st.page; }
    el.modal.querySelectorAll("#dlDevices .dl-chip").forEach(function (b) { b.classList.toggle("dl-active", b.dataset.dev === st.device); });
    el.pvWrap.classList.toggle("mobile", st.device === "mobile");
  }

  function setPage(p) { st.page = p; st.sel = -1; syncChips(); loadPage(); }
  function setDevice(d) { st.device = d; st.sel = -1; syncChips(); render(); refreshPreview(); }

  function loadPage() {
    Promise.all([
      api("load", { page: st.page, device: "desktop" }),
      api("load", { page: st.page, device: "mobile" })
    ]).then(function (res) {
      var d = res[0].layout;
      st.desktop = (d && d.blocks) ? d : { theme: {}, blocks: [] };
      st.mobile = res[1].layout || {};
      if (!st.mobile.hidden) { st.mobile.hidden = []; }
      if (!st.mobile.theme) { st.mobile.theme = {}; }
      render(); refreshPreview();
    }).catch(function (e) { toast(e.message); });
  }

  function refreshPreview() { el.preview.src = previewUrl(); }

  // ---- rendering ----
  function render() { renderBlocks(); renderPalette(); renderRight(); }

  function renderBlocks() {
    el.blocks.innerHTML = "";
    var blocks = st.desktop.blocks || [];
    blocks.forEach(function (b, i) {
      var li = document.createElement("li");
      var reg = REG[b.type] || { label: b.type };
      var hiddenOnMobile = st.device === "mobile" && st.mobile.hidden.indexOf(b.type) !== -1;
      li.className = (st.sel === i ? "dl-sel " : "") + (hiddenOnMobile ? "dl-hidden" : "");
      if (st.device === "desktop") {
        li.draggable = true;
        li.innerHTML = '<span class="dl-mini" title="drag">⋮⋮</span><span class="dl-name">' + esc(reg.label) + '</span>' +
                       '<button class="dl-mini" data-a="cfg" title="Settings">⚙</button>' +
                       '<button class="dl-mini" data-a="del" title="Remove">✕</button>';
        li.querySelector('[data-a="cfg"]').addEventListener("click", function (e) { e.stopPropagation(); st.sel = i; render(); });
        li.querySelector('[data-a="del"]').addEventListener("click", function (e) { e.stopPropagation(); blocks.splice(i, 1); if (st.sel === i) st.sel = -1; render(); });
        li.addEventListener("click", function () { st.sel = i; render(); });
        li.addEventListener("dragstart", function (e) { e.dataTransfer.setData("text/plain", i); });
        li.addEventListener("dragover", function (e) { e.preventDefault(); });
        li.addEventListener("drop", function (e) {
          e.preventDefault();
          var from = parseInt(e.dataTransfer.getData("text/plain"), 10);
          if (isNaN(from) || from === i) return;
          var moved = blocks.splice(from, 1)[0];
          blocks.splice(i, 0, moved);
          st.sel = -1; render();
        });
      } else {
        // Mobile: read-only list with a "hide on mobile" checkbox.
        var checked = hiddenOnMobile ? "" : "checked";
        li.innerHTML = '<label style="flex:1;display:flex;gap:8px;align-items:center;font-size:13px;">' +
                       '<input type="checkbox" ' + checked + '> ' + esc(reg.label) + '</label>';
        li.querySelector("input").addEventListener("change", function () {
          var idx = st.mobile.hidden.indexOf(b.type);
          if (this.checked) { if (idx !== -1) st.mobile.hidden.splice(idx, 1); }
          else { if (idx === -1) st.mobile.hidden.push(b.type); }
          renderBlocks();
        });
      }
      el.blocks.appendChild(li);
    });
  }

  function renderPalette() {
    el.palette.innerHTML = "";
    if (st.device !== "desktop") {
      el.palette.innerHTML = '<span style="font-size:12px;color:#9ca3af">Add/reorder on the Desktop tab. Mobile inherits it.</span>';
      return;
    }
    Object.keys(REG).forEach(function (type) {
      if (REG[type].pages.indexOf(st.page) === -1) return;
      var b = document.createElement("button");
      b.textContent = "+ " + REG[type].label;
      b.addEventListener("click", function () {
        st.desktop.blocks.push({ type: type, settings: clone(REG[type].defaults) });
        st.sel = st.desktop.blocks.length - 1; render();
      });
      el.palette.appendChild(b);
    });
  }

  function field(labelText, inputEl) {
    var w = document.createElement("div"); w.className = "dl-field";
    var l = document.createElement("label"); l.textContent = labelText; w.appendChild(l); w.appendChild(inputEl);
    return w;
  }

  function renderRight() {
    el.right.innerHTML = "";
    if (st.device === "desktop" && st.sel >= 0 && st.desktop.blocks[st.sel]) {
      renderBlockSettings(st.desktop.blocks[st.sel]);
    } else {
      renderTheme();
    }
  }

  function renderBlockSettings(block) {
    var reg = REG[block.type] || { label: block.type, defaults: {} };
    var h = document.createElement("h4"); h.textContent = reg.label + " — settings"; el.right.appendChild(h);
    if (!block.settings) block.settings = {};
    // Universal: match the tallest element on the same row (equal-height columns).
    var eqWrap = document.createElement("label"); eqWrap.className = "dl-inline";
    var eq = document.createElement("input"); eq.type = "checkbox"; eq.checked = !!block.settings.equal_height;
    eq.addEventListener("change", function () { block.settings.equal_height = eq.checked; });
    eqWrap.appendChild(eq); eqWrap.appendChild(document.createTextNode(" Match tallest element in the same row"));
    el.right.appendChild(eqWrap);
    var defs = reg.defaults || {};
    Object.keys(defs).forEach(function (k) {
      var val = (k in block.settings) ? block.settings[k] : defs[k];
      var input;
      if (typeof defs[k] === "boolean") {
        input = document.createElement("input"); input.type = "checkbox"; input.checked = !!val;
        input.addEventListener("change", function () { block.settings[k] = input.checked; refreshPreviewDebounced(); });
      } else if (typeof defs[k] === "number") {
        input = document.createElement("input"); input.type = "number"; input.value = val;
        input.addEventListener("input", function () { block.settings[k] = parseInt(input.value, 10) || 0; });
      } else {
        input = document.createElement("input"); input.type = "text"; input.value = val;
        input.addEventListener("input", function () { block.settings[k] = input.value; });
      }
      el.right.appendChild(field(k.replace(/_/g, " "), input));
    });
    // Spacing group
    var sub = document.createElement("div"); sub.className = "dl-sub"; sub.textContent = "Spacing"; el.right.appendChild(sub);
    var sp = block.settings.spacing || {};
    ["margin_top", "margin_bottom", "padding"].forEach(function (k) {
      var input = document.createElement("input"); input.type = "text"; input.value = sp[k] || "";
      input.placeholder = "e.g. 24px";
      input.addEventListener("input", function () {
        if (!block.settings.spacing) block.settings.spacing = {};
        block.settings.spacing[k] = input.value;
      });
      el.right.appendChild(field(k.replace(/_/g, " "), input));
    });
    // Custom CSS for THIS block (auto-scoped to this block instance).
    var cssSub = document.createElement("div"); cssSub.className = "dl-sub"; cssSub.textContent = "Custom CSS (this block)"; el.right.appendChild(cssSub);
    var ta = document.createElement("textarea"); ta.className = "dl-css"; ta.rows = 7;
    ta.placeholder = ".dl-headlines-list li { padding: 6px 0; }\n.dl-ff-hero-title { font-size: 46px; }";
    ta.value = block.settings.custom_css || "";
    ta.addEventListener("input", function () { block.settings.custom_css = ta.value; });
    el.right.appendChild(field("", ta));
    var hint = document.createElement("p"); hint.className = "dl-hint";
    hint.textContent = "Selectors are scoped to this block automatically. Save to apply.";
    el.right.appendChild(hint);
  }

  function renderTheme() {
    var h = document.createElement("h4");
    h.textContent = st.device === "mobile" ? "Mobile theme overrides" : "Page theme";
    el.right.appendChild(h);

    if (st.device === "mobile") {
      var note = document.createElement("p"); note.style.cssText = "font-size:12px;color:#9ca3af;margin:0 0 10px";
      note.textContent = "Leave blank to inherit desktop. Set to make mobile lighter.";
      el.right.appendChild(note);
      SPACING.forEach(function (f) {
        var input = document.createElement("input"); input.type = "text";
        input.value = (st.mobile.theme && st.mobile.theme[f.k]) || "";
        input.placeholder = "inherit";
        input.addEventListener("input", function () {
          if (!st.mobile.theme) st.mobile.theme = {};
          if (input.value) st.mobile.theme[f.k] = input.value; else delete st.mobile.theme[f.k];
        });
        el.right.appendChild(field(f.label, input));
      });
      return;
    }

    if (!st.desktop.theme) st.desktop.theme = {};
    THEME.forEach(function (f) {
      var input = document.createElement("input");
      input.type = f.t === "color" ? "color" : "text";
      input.value = st.desktop.theme[f.k] || "";
      input.addEventListener("input", function () { st.desktop.theme[f.k] = input.value; });
      el.right.appendChild(field(f.label, input));
    });
    var sub = document.createElement("div"); sub.className = "dl-sub"; sub.textContent = "Spacing & margins"; el.right.appendChild(sub);
    SPACING.forEach(function (f) {
      var input = document.createElement("input"); input.type = "text"; input.value = st.desktop.theme[f.k] || "";
      input.placeholder = "e.g. 24px";
      input.addEventListener("input", function () { st.desktop.theme[f.k] = input.value; });
      el.right.appendChild(field(f.label, input));
    });
    // Whole-page custom CSS — style any element on the page.
    var cssSub = document.createElement("div"); cssSub.className = "dl-sub"; cssSub.textContent = "Custom CSS (whole page)"; el.right.appendChild(cssSub);
    var ta = document.createElement("textarea"); ta.className = "dl-css"; ta.rows = 10;
    ta.placeholder = ".dl-headlines-list li { padding: 6px 0; font-size: 16px; }\n.dl-ff { gap: 32px; }";
    ta.value = st.desktop.theme.custom_css || "";
    ta.addEventListener("input", function () { st.desktop.theme.custom_css = ta.value; });
    el.right.appendChild(field("", ta));
    var hint = document.createElement("p"); hint.className = "dl-hint";
    hint.textContent = "Target any element by its class (e.g. .dl-ff-hero-title). Save to apply.";
    el.right.appendChild(hint);
  }

  var pvTimer = null;
  function refreshPreviewDebounced() { clearTimeout(pvTimer); pvTimer = setTimeout(refreshPreview, 400); }

  // ---- persistence ----
  function saveDraft() {
    var payload, device;
    if (st.device === "desktop") { device = "desktop"; payload = st.desktop; }
    else { device = "mobile"; payload = { hidden: st.mobile.hidden || [], theme: st.mobile.theme || {} }; }
    api("save_draft", { page: st.page, device: device, layout_json: JSON.stringify(payload) })
      .then(function () { toast("Draft saved"); refreshPreview(); })
      .catch(function (e) { toast(e.message); });
  }

  function doPublish() {
    if (!window.confirm("Publish this design to the live site now? The current draft becomes the live version for this publication.")) { return; }
    var label = window.prompt("Optional label for this published version:", "");
    if (label === null) { return; }
    api("publish", { label: label })
      .then(function (j) { toast("Published v" + j.version_no); })
      .catch(function (e) { toast(e.message); });
  }

  function showHistory() {
    api("list_versions", {}).then(function (j) {
      el.right.innerHTML = "";
      var h = document.createElement("h4"); h.textContent = "Version history"; el.right.appendChild(h);
      var ul = document.createElement("ul"); ul.className = "dl-versions";
      (j.versions || []).forEach(function (v) {
        var li = document.createElement("li");
        li.innerHTML = "<div><strong>v" + v.version_no + "</strong> " +
          (parseInt(v.is_live, 10) === 1 ? '<span class="dl-live">● live</span>' : "") + "</div>" +
          '<div style="color:#6b7280">' + esc(v.created_at) + " · " + esc(v.created_by || "") + "</div>" +
          (v.label ? '<div>' + esc(v.label) + "</div>" : "");
        var btn = document.createElement("button");
        btn.className = "dl-chip"; btn.style.marginTop = "6px"; btn.textContent = "Roll back to v" + v.version_no;
        btn.addEventListener("click", function () {
          if (!window.confirm("Roll back the live design to v" + v.version_no + "? This also restores it into the draft.")) return;
          api("rollback", { version_no: v.version_no })
            .then(function () { toast("Rolled back to v" + v.version_no); loadPage(); })
            .catch(function (e) { toast(e.message); });
        });
        li.appendChild(btn); ul.appendChild(li);
      });
      el.right.appendChild(ul);
    }).catch(function (e) { toast(e.message); });
  }

  window.openDesignModal = open;
})();
