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
    triple_box:       { label: "Triple box (insurance / clinics / events)", pages: ["front", "section"], defaults: {} },
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
        '<label class="dl-pagesel-wrap">Block: <select id="dlBlockSel" class="dl-pagesel"><option value="">— editing pages —</option></select></label>' +
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
      '<div class="dl-main">' +
        '<div class="dl-body">' +
          '<div class="dl-col left"><h4>Blocks</h4><ul class="dl-blocklist" id="dlBlocks"></ul>' +
            '<h4>Add block</h4><div class="dl-palette" id="dlPalette"></div></div>' +
          '<div class="dl-col center" style="padding:0"><div class="dl-preview-wrap" id="dlPvWrap"><iframe class="dl-preview" id="dlPreview"></iframe></div></div>' +
          '<div class="dl-col right" id="dlRight"></div>' +
        '</div>' +
        '<div id="dlGjsWrap"><div id="dlGjs"></div></div>' +
      '</div>';
    root.appendChild(ov); root.appendChild(m);

    el.ov = ov; el.modal = m;
    el.body = m.querySelector(".dl-body");
    el.gjsWrap = m.querySelector("#dlGjsWrap");
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
    el.pageSel.addEventListener("change", function () { el.blockSel.value = ""; setPage(el.pageSel.value); });

    el.blockSel = m.querySelector("#dlBlockSel");
    Object.keys(REG).forEach(function (type) {
      var o = document.createElement("option"); o.value = type; o.textContent = REG[type].label; el.blockSel.appendChild(o);
    });
    el.blockSel.addEventListener("change", function () {
      if (el.blockSel.value) { enterBlockMode(el.blockSel.value); } else { exitBlockMode(); }
    });
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

    // Preview → editor messages: select a block/element, or apply an inline edit.
    window.addEventListener("message", function (e) {
      var d = e.data;
      if (!d || d.source !== "ten-design" || !st || st.mode !== "page") { return; }
      if (d.action === "select") {
        if (st.device !== "desktop") { setDevice("desktop"); }
        var idx = parseInt(d.index, 10);
        if (!isNaN(idx) && st.desktop.blocks[idx]) { st.sel = idx; st.selEl = d.selector || ""; render(); }
      } else if (d.action === "edit") {
        var i = parseInt(d.index, 10);
        var blk = st.desktop.blocks[i];
        if (blk && d.key) {
          if (!blk.settings) { blk.settings = {}; }
          blk.settings[d.key] = d.value;
          applyChangeDebounced();
          if (st.sel === i) { renderRight(); }
        }
      }
    });
  }

  function open(p) {
    build();
    st = { pub: p.publication, host: hostFrom(p.url), page: "front", device: "desktop",
           desktop: { theme: {}, blocks: [] }, mobile: {}, sel: -1, selEl: "",
           mode: "page", blockType: null, blockTemplates: {}, blockSel: null };
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

  function setPage(p) { st.mode = "page"; st.page = p; st.sel = -1; st.selEl = ""; el.pageSel.disabled = false; syncChips(); loadPage(); }
  function setDevice(d) { st.device = d; st.sel = -1; st.selEl = ""; syncChips(); render(); refreshPreview(); }

  // ---- Blocks mode: edit a reusable block's HTML template (per publication) ----
  var TEMPLATE_BLOCKS = ["site_header", "masthead", "front_feature", "front_sections", "footer",
                         "breaking_news", "triple_box", "headlines_list", "ticker", "advert",
                         "section_title", "section_lead", "section_carousel", "article_grid",
                         "article_header", "article_hero", "article_body", "article_byline",
                         "rich_text", "article_comments"];
  var BLOCK_PLACEHOLDERS = {
    site_header: "{{site_name}}, {{logo}}, {{search_icon}}, {{subscribe_label}}, {{#if show_search}}, {{#if show_subscribe}}, {{#each nav}}{{name}} {{href}}{{/each}}",
    masthead: "{{site_name}}, {{tagline}}",
    front_feature: "{{#if hero}}{{hero.title}} {{hero.image}} {{hero.meta}} {{hero.summary}}{{/if}}, {{#each mid}}{{title}} {{image}} {{summary}}{{/each}}, {{#each side}}{{title}} {{section}}{{/each}}",
    front_sections: "{{#each sections}}{{name}} {{href}} {{#each cards}}{{title}} {{image}} {{snippet}}{{/each}}{{/each}}",
    footer: "{{site_name}}, {{copyright}}, {{col1_label}}…{{col4_label}}, {{#each sections}}{{name}} {{url}}{{/each}}",
    breaking_news: "{{heading}}, {{viewall_label}}, {{#each items}}{{title}} {{href}} {{read_time}}{{/each}}",
    triple_box: "{{pkv_title}}, {{pkv_text}}, {{clinics_title}}, {{clinics_text}}, {{events_title}}, {{#if event}}{{event.title}} {{event.text}}{{/if}}",
    headlines_list: "{{headlines_label}}, {{#each items}}{{title}} {{href}} {{meta}}{{/each}}",
    ticker: "{{label}}, {{#each items}}{{title}} {{href}}{{/each}}",
    advert: "{{label}}, {{slot}}",
    section_title: "{{name}}, {{cls}}",
    section_lead: "{{#if lead}}{{lead.title}} {{lead.image}} {{lead.summary}}{{/if}}, {{#each side}}{{title}} {{meta}}{{/each}}",
    section_carousel: "{{title}}, {{#each items}}{{title}} {{image}} {{section}}{{/each}}",
    article_grid: "{{columns}}, {{#each cards}}{{title}} {{image}} {{read_time}}{{/each}}, {{{pager}}}",
    article_header: "{{section}}, {{title}}, {{standfirst}}, {{meta}}",
    article_hero: "{{#if image}}{{image}} {{title}}{{/if}}",
    article_body: "{{{body}}}",
    article_byline: "{{#if byline}}{{byline}}{{/if}}",
    rich_text: "{{title}}, {{body}}",
    article_comments: "{{heading}}, {{button_label}}"
  };

  var gjs = null;
  var DL_GJS_BLOCKS = [
    { id: "dl-text", label: "Text", content: '<div data-gjs-type="text">Insert text</div>' },
    { id: "dl-heading", label: "Heading", content: '<h2 data-gjs-type="text">Heading</h2>' },
    { id: "dl-para", label: "Paragraph", content: '<p data-gjs-type="text">Paragraph text</p>' },
    { id: "dl-image", label: "Image", content: { type: "image" } },
    { id: "dl-link", label: "Link", content: '<a data-gjs-type="link" href="#">Link</a>' },
    { id: "dl-button", label: "Button", content: '<a class="dl-btn" href="#">Button</a>' },
    { id: "dl-box", label: "Box", content: '<div style="padding:20px;border:1px solid #e5e7eb;min-height:40px"></div>' },
    { id: "dl-row2", label: "2 columns", content: '<div style="display:flex;gap:20px"><div style="flex:1;min-height:40px">Column</div><div style="flex:1;min-height:40px">Column</div></div>' },
    { id: "dl-row3", label: "3 columns", content: '<div style="display:flex;gap:20px"><div style="flex:1;min-height:40px">Col</div><div style="flex:1;min-height:40px">Col</div><div style="flex:1;min-height:40px">Col</div></div>' }
  ];

  function enterBlockMode(type) {
    st.mode = "blocks"; st.blockType = type; el.pageSel.disabled = true;
    el.gjsWrap.style.display = "block"; el.body.style.display = "none";
    loadBlockTemplates(function () {
      var saved = st.blockTemplates[type];
      if (saved != null && saved !== "") { initGjs(type, saved); }
      else {
        fetch("https://" + st.host + "/design/?block=" + encodeURIComponent(type) + "&tpl_default=1")
          .then(function (r) { return r.text(); })
          .then(function (txt) { if (st.blockType === type) { initGjs(type, txt); } })
          .catch(function () { initGjs(type, "<div>Empty block</div>"); });
      }
    });
  }
  function exitBlockMode() {
    st.mode = "page"; st.blockType = null; el.pageSel.disabled = false;
    el.gjsWrap.style.display = "none"; el.body.style.display = "";
    render(); refreshPreview();
  }
  function loadBlockTemplates(cb) {
    api("load", { page: "_blocks", device: "desktop" }).then(function (j) {
      st.blockTemplates = (j.layout && j.layout.templates) ? j.layout.templates : {};
      if (cb) { cb(); }
    }).catch(function (e) { st.blockTemplates = {}; toast(e.message); if (cb) { cb(); } });
  }
  function initGjs(type, html) {
    if (!window.grapesjs) { toast("Visual editor library failed to load"); return; }
    if (!gjs) {
      var cfg = {
        container: "#dlGjs",
        height: "100%",
        fromElement: false,
        storageManager: false,
        canvas: { styles: ["https://" + st.host + "/design/assets/design.css"] }
      };
      // Full builder UI (blocks panel, style manager, layers, RTE) when available.
      var preset = window["grapesjs-preset-webpage"] || window.grapesjsPresetWebpage;
      if (typeof preset === "function") {
        cfg.plugins = [preset];
      } else {
        cfg.blockManager = { blocks: DL_GJS_BLOCKS };
      }
      gjs = grapesjs.init(cfg);
      // Add our own basic blocks alongside whatever the preset provides.
      try { DL_GJS_BLOCKS.forEach(function (b) { gjs.BlockManager.add(b.id, b); }); } catch (e) {}
      gjs.on("update", saveGjsDebounced);
    }
    gjs.setStyle("");
    gjs.setComponents(html || "<div>Empty block</div>");
    window.__dlGjs = gjs;
    try { gjs.runCommand("open-blocks"); } catch (e) {}
  }
  function saveGjsDebounced() {
    clearTimeout(applyTimer);
    applyTimer = setTimeout(function () {
      if (!gjs || !st.blockType) { return; }
      var html = gjs.getHtml();
      var css = gjs.getCss();
      st.blockTemplates[st.blockType] = html + (css ? "\n<style>" + css + "</style>" : "");
      api("save_draft", { page: "_blocks", device: "desktop", layout_json: JSON.stringify({ templates: st.blockTemplates }) })
        .catch(function (e) { toast(e.message); });
    }, 700);
  }

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
        li.innerHTML = '<span class="dl-name">' + esc(reg.label) + '</span>' +
                       '<button class="dl-mini" data-a="up" title="Move up">↑</button>' +
                       '<button class="dl-mini" data-a="down" title="Move down">↓</button>' +
                       '<button class="dl-mini" data-a="cfg" title="Settings">⚙</button>' +
                       '<button class="dl-mini" data-a="del" title="Remove">✕</button>';
        li.querySelector('[data-a="up"]').addEventListener("click", function (e) { e.stopPropagation(); if (i > 0) { var t = blocks[i - 1]; blocks[i - 1] = blocks[i]; blocks[i] = t; st.sel = i - 1; render(); applyChangeDebounced(); } });
        li.querySelector('[data-a="down"]').addEventListener("click", function (e) { e.stopPropagation(); if (i < blocks.length - 1) { var t = blocks[i + 1]; blocks[i + 1] = blocks[i]; blocks[i] = t; st.sel = i + 1; render(); applyChangeDebounced(); } });
        li.querySelector('[data-a="cfg"]').addEventListener("click", function (e) { e.stopPropagation(); st.sel = i; st.selEl = ""; render(); });
        li.querySelector('[data-a="del"]').addEventListener("click", function (e) { e.stopPropagation(); blocks.splice(i, 1); if (st.sel === i) st.sel = -1; render(); applyChangeDebounced(); });
        li.addEventListener("click", function () { st.sel = i; st.selEl = ""; render(); });
        li.addEventListener("dragstart", function (e) { e.dataTransfer.setData("text/plain", i); });
        li.addEventListener("dragover", function (e) { e.preventDefault(); });
        li.addEventListener("drop", function (e) {
          e.preventDefault();
          var from = parseInt(e.dataTransfer.getData("text/plain"), 10);
          if (isNaN(from) || from === i) return;
          var moved = blocks.splice(from, 1)[0];
          blocks.splice(i, 0, moved);
          st.sel = -1; st.selEl = ""; render(); applyChangeDebounced();
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
          renderBlocks(); applyChangeDebounced();
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
        st.sel = st.desktop.blocks.length - 1; st.selEl = ""; render(); applyChangeDebounced();
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

  var FONT_OPTS = [
    ["", "Inherit"],
    ['"Playfair Display", Georgia, serif', "Playfair Display"],
    ['"Lora", Georgia, serif', "Lora"],
    ['"Inter", system-ui, sans-serif', "Inter"],
    ["Georgia, serif", "Georgia"],
    ["Arial, sans-serif", "Arial"],
    ["'Times New Roman', serif", "Times"]
  ];
  var STYLE_SPECS = [
    { sub: "Typography" },
    { p: "font-family", l: "Font", t: "select", o: FONT_OPTS },
    { p: "font-size", l: "Font size", t: "text", ph: "e.g. 20px" },
    { p: "font-weight", l: "Weight", t: "select", o: [["",""],["400","Normal"],["500","Medium"],["600","Semibold"],["700","Bold"],["800","Extra bold"]] },
    { p: "font-style", l: "Style", t: "select", o: [["",""],["normal","Normal"],["italic","Italic"]] },
    { p: "text-align", l: "Align", t: "select", o: [["",""],["left","Left"],["center","Center"],["right","Right"],["justify","Justify"]] },
    { p: "line-height", l: "Line height", t: "text", ph: "e.g. 1.3" },
    { p: "letter-spacing", l: "Letter spacing", t: "text", ph: "e.g. 0.02em" },
    { p: "text-transform", l: "Transform", t: "select", o: [["",""],["none","None"],["uppercase","UPPER"],["lowercase","lower"],["capitalize","Capitalize"]] },
    { p: "color", l: "Text colour", t: "color" },
    { sub: "Background & border" },
    { p: "background-color", l: "Background", t: "color" },
    { p: "border-width", l: "Border width", t: "text", ph: "e.g. 1px" },
    { p: "border-style", l: "Border style", t: "select", o: [["",""],["solid","Solid"],["dashed","Dashed"],["dotted","Dotted"],["none","None"]] },
    { p: "border-color", l: "Border colour", t: "color" },
    { p: "border-radius", l: "Corner radius", t: "text", ph: "e.g. 8px" },
    { sub: "Box" },
    { p: "padding", l: "Padding", t: "text", ph: "e.g. 16px 24px" },
    { p: "margin", l: "Margin", t: "text", ph: "e.g. 0 0 24px" }
  ];

  function buildStyleControls(styles) {
    STYLE_SPECS.forEach(function (s) {
      if (s.sub) { var d = document.createElement("div"); d.className = "dl-sub"; d.textContent = s.sub; el.right.appendChild(d); return; }
      var input;
      if (s.t === "select") {
        input = document.createElement("select");
        s.o.forEach(function (o) { var op = document.createElement("option"); op.value = o[0]; op.textContent = o[1]; input.appendChild(op); });
        input.value = styles[s.p] || "";
        input.addEventListener("change", function () { setStyle(styles, s.p, input.value); });
      } else if (s.t === "color") {
        var wrap = document.createElement("div"); wrap.className = "dl-color-row";
        input = document.createElement("input"); input.type = "color"; input.value = styles[s.p] || "#000000";
        var clr = document.createElement("button"); clr.type = "button"; clr.className = "dl-clear"; clr.textContent = styles[s.p] ? "clear" : "";
        input.addEventListener("input", function () { setStyle(styles, s.p, input.value); clr.textContent = "clear"; });
        clr.addEventListener("click", function () { setStyle(styles, s.p, ""); clr.textContent = ""; });
        wrap.appendChild(input); wrap.appendChild(clr);
        el.right.appendChild(field(s.l, wrap));
        return;
      } else {
        input = document.createElement("input"); input.type = "text"; input.value = styles[s.p] || ""; input.placeholder = s.ph || "";
        input.addEventListener("input", function () { setStyle(styles, s.p, input.value); });
      }
      el.right.appendChild(field(s.l, input));
    });
  }
  function setStyle(styles, prop, val) { if (val === "" || val == null) { delete styles[prop]; } else { styles[prop] = val; } applyChangeDebounced(); }

  function miniBtn(txt) { var b = document.createElement("button"); b.type = "button"; b.className = "dl-mini"; b.textContent = txt; return b; }

  // Add / remove / reorder the sections shown by a front_sections block.
  function renderSectionList(block) {
    var arr = block.settings.sections;
    if (!Array.isArray(arr)) {
      arr = (typeof arr === "string" && arr) ? arr.split(",").map(function (s) { return s.trim(); }).filter(Boolean) : [];
      block.settings.sections = arr;
    }
    var sub = document.createElement("div"); sub.className = "dl-sub"; sub.textContent = "Sections shown (in order)"; el.right.appendChild(sub);
    if (!arr.length) { var n = document.createElement("p"); n.className = "dl-hint"; n.textContent = "Empty = all sections from the menu, in order."; el.right.appendChild(n); }
    var ul = document.createElement("ul"); ul.className = "dl-seclist";
    arr.forEach(function (name, i) {
      var li = document.createElement("li");
      var nm = document.createElement("span"); nm.className = "dl-name"; nm.textContent = name; li.appendChild(nm);
      var up = miniBtn("↑"), dn = miniBtn("↓"), rm = miniBtn("✕");
      up.addEventListener("click", function () { if (i > 0) { var t = arr[i - 1]; arr[i - 1] = arr[i]; arr[i] = t; renderRight(); applyChangeDebounced(); } });
      dn.addEventListener("click", function () { if (i < arr.length - 1) { var t = arr[i + 1]; arr[i + 1] = arr[i]; arr[i] = t; renderRight(); applyChangeDebounced(); } });
      rm.addEventListener("click", function () { arr.splice(i, 1); renderRight(); applyChangeDebounced(); });
      li.appendChild(up); li.appendChild(dn); li.appendChild(rm);
      ul.appendChild(li);
    });
    el.right.appendChild(ul);
    var addWrap = document.createElement("div"); addWrap.className = "dl-row2";
    var inp = document.createElement("input"); inp.type = "text"; inp.placeholder = "Add section, e.g. News";
    var btn = document.createElement("button"); btn.type = "button"; btn.className = "dl-chip"; btn.textContent = "Add";
    function add() { var v = inp.value.trim(); if (v) { arr.push(v); inp.value = ""; renderRight(); applyChangeDebounced(); } }
    btn.addEventListener("click", add);
    inp.addEventListener("keydown", function (e) { if (e.key === "Enter") { e.preventDefault(); add(); } });
    addWrap.appendChild(inp); addWrap.appendChild(btn);
    el.right.appendChild(addWrap);
  }

  function renderBlockSettings(block) {
    var reg = REG[block.type] || { label: block.type, defaults: {} };
    if (!block.settings) block.settings = {};
    if (!block.settings.styles) block.settings.styles = {};
    var selEl = st.selEl || "";

    var h = document.createElement("h4"); h.textContent = reg.label + " — settings"; el.right.appendChild(h);

    // Which element the style controls target.
    var target = document.createElement("div"); target.className = "dl-target";
    target.innerHTML = "Styling: <strong>" + (selEl ? esc(selEl) : "whole block") + "</strong>";
    if (selEl) {
      var whole = document.createElement("button"); whole.type = "button"; whole.className = "dl-chip"; whole.style.marginLeft = "8px"; whole.textContent = "whole block";
      whole.addEventListener("click", function () { st.selEl = ""; renderRight(); });
      target.appendChild(whole);
    }
    el.right.appendChild(target);
    var pHint = document.createElement("p"); pHint.className = "dl-hint"; pHint.textContent = "Click any element in the preview to style just that element.";
    el.right.appendChild(pHint);

    // Visual style controls for the selected element.
    var styles = block.settings.styles[selEl] || (block.settings.styles[selEl] = {});
    buildStyleControls(styles);

    // Content / behaviour settings for the whole block only.
    if (!selEl) {
      var cfgSub = document.createElement("div"); cfgSub.className = "dl-sub"; cfgSub.textContent = "Content & options"; el.right.appendChild(cfgSub);
      var eqWrap = document.createElement("label"); eqWrap.className = "dl-inline";
      var eq = document.createElement("input"); eq.type = "checkbox"; eq.checked = !!block.settings.equal_height;
      eq.addEventListener("change", function () { block.settings.equal_height = eq.checked; applyChangeDebounced(); });
      eqWrap.appendChild(eq); eqWrap.appendChild(document.createTextNode(" Match tallest element in the same row"));
      el.right.appendChild(eqWrap);

      var defs = reg.defaults || {};
      Object.keys(defs).forEach(function (k) {
        if (block.type === "front_sections" && k === "sections") { return; } // custom UI below
        var val = (k in block.settings) ? block.settings[k] : defs[k];
        var input;
        if (typeof defs[k] === "boolean") {
          input = document.createElement("input"); input.type = "checkbox"; input.checked = !!val;
          input.addEventListener("change", function () { block.settings[k] = input.checked; applyChangeDebounced(); });
        } else if (typeof defs[k] === "number") {
          input = document.createElement("input"); input.type = "number"; input.value = val;
          input.addEventListener("input", function () { block.settings[k] = parseInt(input.value, 10) || 0; applyChangeDebounced(); });
        } else {
          input = document.createElement("input"); input.type = "text"; input.value = val;
          input.addEventListener("input", function () { block.settings[k] = input.value; applyChangeDebounced(); });
        }
        el.right.appendChild(field(k.replace(/_/g, " "), input));
      });

      if (block.type === "front_sections") { renderSectionList(block); }

      // Custom HTML appended to the block.
      var htmlSub = document.createElement("div"); htmlSub.className = "dl-sub"; htmlSub.textContent = "Custom HTML (appended)"; el.right.appendChild(htmlSub);
      var hta = document.createElement("textarea"); hta.className = "dl-css"; hta.rows = 5;
      hta.placeholder = "<div class=\"promo\">Custom HTML…</div>";
      hta.value = block.settings.custom_html || "";
      hta.addEventListener("input", function () { block.settings.custom_html = hta.value; applyChangeDebounced(); });
      el.right.appendChild(field("", hta));

      // Advanced freeform CSS.
      var cssSub = document.createElement("div"); cssSub.className = "dl-sub"; cssSub.textContent = "Advanced CSS (this block)"; el.right.appendChild(cssSub);
      var ta = document.createElement("textarea"); ta.className = "dl-css"; ta.rows = 5;
      ta.placeholder = ".dl-headlines-list li { padding: 6px 0; }";
      ta.value = block.settings.custom_css || "";
      ta.addEventListener("input", function () { block.settings.custom_css = ta.value; applyChangeDebounced(); });
      el.right.appendChild(field("", ta));
    }
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
          applyChangeDebounced();
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
      input.addEventListener("input", function () { st.desktop.theme[f.k] = input.value; applyChangeDebounced(); });
      el.right.appendChild(field(f.label, input));
    });
    var sub = document.createElement("div"); sub.className = "dl-sub"; sub.textContent = "Spacing & margins"; el.right.appendChild(sub);
    SPACING.forEach(function (f) {
      var input = document.createElement("input"); input.type = "text"; input.value = st.desktop.theme[f.k] || "";
      input.placeholder = "e.g. 24px";
      input.addEventListener("input", function () { st.desktop.theme[f.k] = input.value; applyChangeDebounced(); });
      el.right.appendChild(field(f.label, input));
    });
    // Whole-page custom CSS — style any element on the page.
    var cssSub = document.createElement("div"); cssSub.className = "dl-sub"; cssSub.textContent = "Custom CSS (whole page)"; el.right.appendChild(cssSub);
    var ta = document.createElement("textarea"); ta.className = "dl-css"; ta.rows = 10;
    ta.placeholder = ".dl-headlines-list li { padding: 6px 0; font-size: 16px; }\n.dl-ff { gap: 32px; }";
    ta.value = st.desktop.theme.custom_css || "";
    ta.addEventListener("input", function () { st.desktop.theme.custom_css = ta.value; applyChangeDebounced(); });
    el.right.appendChild(field("", ta));
    var hint = document.createElement("p"); hint.className = "dl-hint";
    hint.textContent = "Target any element by its class (e.g. .dl-ff-hero-title). Save to apply.";
    el.right.appendChild(hint);
  }

  var pvTimer = null;
  function refreshPreviewDebounced() { clearTimeout(pvTimer); pvTimer = setTimeout(refreshPreview, 400); }

  // ---- persistence ----
  function currentDraftPayload() {
    if (st.device === "desktop") { return { device: "desktop", payload: st.desktop }; }
    return { device: "mobile", payload: { hidden: st.mobile.hidden || [], theme: st.mobile.theme || {} } };
  }
  function saveDraft() {
    var d = currentDraftPayload();
    api("save_draft", { page: st.page, device: d.device, layout_json: JSON.stringify(d.payload) })
      .then(function () { toast("Draft saved"); refreshPreview(); })
      .catch(function (e) { toast(e.message); });
  }
  function saveDraftSilent() {
    var d = currentDraftPayload();
    return api("save_draft", { page: st.page, device: d.device, layout_json: JSON.stringify(d.payload) })
      .catch(function (e) { toast(e.message); });
  }
  var applyTimer = null;
  // Every editor change persists the draft then refreshes the preview, so the
  // preview always reflects the current settings (e.g. hiding search/subscribe).
  function applyChangeDebounced() {
    clearTimeout(applyTimer);
    applyTimer = setTimeout(function () { saveDraftSilent().then(refreshPreview); }, 500);
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
