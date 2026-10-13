/* TEN Design editor — per-publication layout/theme/spacing modal.
   Exposes window.openDesignModal(publicationRow). Talks to ajax/design_layout.php.
   Desktop tab edits the full layout; Mobile tab edits a diff (hide blocks + theme
   overrides) so mobile "follows desktop but lighter". */
(function () {
  "use strict";

  // Registry mirror — keep in sync with lib/design_registry.php.
  var PAGES = ["front", "section", "article", "author", "impressum", "contact", "about", "privacy", "terms", "disclaimer"];
  var PAGE_LABELS = {
    front: "Front page", section: "Section page", article: "Article page", author: "Author page",
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
    article_related:  { label: "Related articles (3-up)", pages: ["article"], defaults: { count: 3, heading: "" } },
    article_quickreads: { label: "Quick reads (3-up)", pages: ["article"], defaults: { count: 3, heading: "Quick Reads News" } },
    article_comments: { label: "Comments",          pages: ["article"], defaults: { enabled: true } },
    author_profile:   { label: "Author profile",     pages: ["author"], defaults: {} },
    author_articles:  { label: "Author article list",pages: ["author"], defaults: { count: 12, heading: "" } },
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

  // Plain-English name for a selected element (from its tag, else its class).
  function elementFriendly(tag, selector) {
    var map = { h1: "heading", h2: "heading", h3: "subheading", h4: "subheading",
      p: "text", span: "text", a: "link", img: "image", button: "button",
      ul: "list", ol: "list", li: "list item", nav: "navigation",
      header: "header", footer: "footer", section: "section", div: "section" };
    var t = String(tag || "").toLowerCase();
    if (map[t]) { return map[t]; }
    if (selector) { return selector.replace(/^\./, "").replace(/\bdl-/g, "").replace(/-/g, " ").trim(); }
    return t || "element";
  }

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
        '<label class="dl-pagesel-wrap" id="dlBlockWrap" style="display:none">Block: <select id="dlBlockSel" class="dl-pagesel"><option value="">— editing pages —</option></select></label>' +
        '<div class="dl-tabs" id="dlDevices">' +
          '<button class="dl-chip dl-active" data-dev="desktop">Desktop</button>' +
          '<button class="dl-chip" data-dev="mobile">Mobile</button>' +
        '</div>' +
        '<div class="dl-spacer"></div>' +
        '<button class="dl-b dl-ai" id="dlAi">✨ AI edit</button>' +
        '<button class="dl-b preview" id="dlPreviewBtn">Preview ↗</button>' +
        '<button class="dl-b save" id="dlSave">Save draft</button>' +
        '<button class="dl-b publish" id="dlPublish">Publish</button>' +
        '<button class="dl-b history" id="dlHistory">History</button>' +
        '<button class="dl-b" id="dlPvMax" title="Maximise the preview, then click any element to edit its HTML">⤢ Max preview</button>' +
        '<button class="dl-b close" id="dlClose">Close</button>' +
      '</div>' +
      '<div class="dl-main">' +
        '<div class="dl-body">' +
          '<div class="dl-col left"><h4>Blocks</h4><ul class="dl-blocklist" id="dlBlocks"></ul>' +
            '<h4>Add block</h4><div class="dl-palette" id="dlPalette"></div></div>' +
          '<div class="dl-col center" style="padding:0"><div class="dl-preview-wrap" id="dlPvWrap"><iframe class="dl-preview" id="dlPreview"></iframe></div></div>' +
          '<div class="dl-col right" id="dlRight"></div>' +
        '</div>' +
        '<div id="dlGjsWrap">' +
          '<div class="dl-wys">' +
            '<div class="dl-wys-tb" id="dlWysTb"></div>' +
            '<div class="dl-wys-hint">Grey underlined text fills in automatically — hover it to see what it is and why it can’t be changed here. Any other text you can simply click and type over.</div>' +
            '<div class="dl-wys-stage">' +
              '<iframe class="dl-wys-frame" id="dlWysFrame"></iframe>' +
              '<textarea class="dl-wys-src" id="dlWysSrc" spellcheck="false" style="display:none"></textarea>' +
            '</div>' +
          '</div>' +
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
    m.querySelector("#dlAi").addEventListener("click", openAiDialog);
    m.querySelector("#dlPreviewBtn").addEventListener("click", function () { window.open(previewUrl(), "_blank"); });
    m.querySelector("#dlSave").addEventListener("click", saveDraft);
    m.querySelector("#dlPublish").addEventListener("click", doPublish);
    m.querySelector("#dlHistory").addEventListener("click", showHistory);
    m.querySelector("#dlPvMax").addEventListener("click", togglePvMax);
    m.querySelector("#dlClose").addEventListener("click", close);
    ov.addEventListener("click", close);

    // Preview → editor messages: select a block/element, or apply an inline edit.
    window.addEventListener("message", function (e) {
      var d = e.data;
      if (!d || d.source !== "ten-design" || !st || st.mode !== "page") { return; }
      if (d.action === "select") {
        if (st.device !== "desktop") { setDevice("desktop"); }
        var idx = parseInt(d.index, 10);
        if (isNaN(idx) || !st.desktop.blocks[idx]) { return; }
        st.sel = idx; st.selEl = d.selector || "";
        st.selElLabel = d.elLabel || ""; st.selEditKey = d.editKey || "";
        // In max-preview mode, a click jumps straight into the block's HTML editor.
        if (st.pvMax && d.blockType && TEMPLATE_BLOCKS.indexOf(d.blockType) !== -1) { enterBlockMode(d.blockType); return; }
        render();
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
           mode: "page", blockType: null, blockTemplates: {}, blockSel: null, pvMax: false };
    el.modal.classList.remove("dl-pvmax", "dl-max");
    var pvb = el.modal.querySelector("#dlPvMax"); if (pvb) { pvb.innerHTML = "⤢ Max preview"; pvb.classList.remove("dl-active-max"); }
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

  // Maximise the preview pane (hide side panels + go fullscreen). While on, a
  // click on any block in the preview opens that block's HTML editor — a focused
  // canvas for detailed desktop design work.
  function togglePvMax() {
    if (!st) { return; }
    st.pvMax = !st.pvMax;
    el.modal.classList.toggle("dl-pvmax", st.pvMax);
    el.modal.classList.toggle("dl-max", st.pvMax);
    var b = el.modal.querySelector("#dlPvMax");
    if (b) { b.innerHTML = st.pvMax ? "⤡ Exit max" : "⤢ Max preview"; b.classList.toggle("dl-active-max", st.pvMax); }
    if (st.pvMax && st.mode === "page") { toast("Max preview on — click any part of the page to edit its HTML"); }
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
                         "article_related", "article_quickreads", "author_profile", "author_articles",
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
    article_byline: "{{#if has_author}}{{name}} {{role}} {{photo}} {{read_all_label}} {{#if linkedin_url}}{{linkedin_url}}{{/if}} {{#if email}}{{email}}{{/if}}{{/if}}",
    article_related: "{{heading}}, {{#each cards}}{{title}} {{image}} {{section}} {{meta}}{{/each}}",
    article_quickreads: "{{heading}}, {{#each items}}{{title}} {{section}} {{meta}}{{/each}}",
    author_profile: "{{site_name}}, {{crumb_label}}, {{name}}, {{role}}, {{photo}}, {{#each bio_paras}}{{value}}{{/each}}, {{x_url}}, {{linkedin_url}}, {{email}}",
    author_articles: "{{heading}}, {{#each items}}{{title}} {{image}} {{section}} {{standfirst}} {{meta}}{{/each}}",
    rich_text: "{{title}}, {{body}}",
    article_comments: "{{heading}}, {{note}}, {{placeholder}}, {{guest_label}}, {{button_label}}"
  };

  // ====================================================================
  //  WYSIWYG block-template editor
  //  Edits a reusable block's HTML template as rich text: click a block,
  //  select text, format it or turn it into a link (fixed page, section,
  //  dynamic field, or any URL). Placeholders like {{title}} show as
  //  protected tokens and round-trip untouched. A raw-HTML view covers
  //  anything the toolbar does not.
  // ====================================================================

  // Static "fixed page" destinations, always offered in the link picker.
  var FIXED_PAGES = [
    ["/design/", "Home page"],
    ["/design/about", "About us"],
    ["/design/contact", "Contact"],
    ["/design/impressum", "Impressum"],
    ["/design/privacy", "Privacy policy"],
    ["/design/terms", "Terms"],
    ["/design/disclaimer", "Disclaimer"]
  ];
  // Data-driven href placeholders worth offering per block (so the section
  // name can link to *its own* section, etc.). {{href}} suits any list item.
  var BLOCK_HREFS = {
    article_header: [["{{section_href}}", "This article’s section page"]],
    front_feature:  [["{{hero.href}}", "Lead-story link"]]
  };
  var GENERIC_HREF = ["{{href}}", "Current item’s link (inside a list)"];

  var wys = { frame: null, src: null, doc: null, win: null, root: null, type: null,
              srcMode: false, styleTag: "", savedRange: null, sections: [],
              chrome: false, insSel: null, srcBtn: null };

  // Distinct placeholders a block template can use (for the Insert-field menu).
  function fieldsFor(type) {
    var out = [], s = BLOCK_PLACEHOLDERS[type] || "", re = /\{\{\{?[^{}]*?\}?\}\}/g, m;
    while ((m = re.exec(s))) { if (out.indexOf(m[0]) === -1) { out.push(m[0]); } }
    return out;
  }

  // Separate a stored template's HTML from any trailing <style> blocks (kept so
  // styles authored in the previous builder are preserved on round-trip).
  function splitStyle(tpl) {
    var styles = [];
    var html = String(tpl || "").replace(/<style[\s\S]*?<\/style>/gi, function (m) { styles.push(m); return ""; });
    return { html: html.trim(), style: styles.join("\n") };
  }

  function loadBlockTemplates(cb) {
    api("load", { page: "_blocks", device: "desktop" }).then(function (j) {
      st.blockTemplates = (j.layout && j.layout.templates) ? j.layout.templates : {};
      if (cb) { cb(); }
    }).catch(function (e) { st.blockTemplates = {}; toast(e.message); if (cb) { cb(); } });
  }

  function loadSections() {
    api("link_targets", {}).then(function (j) { wys.sections = j.sections || []; })
      .catch(function () { wys.sections = []; });
  }

  function enterBlockMode(type) {
    st.mode = "blocks"; st.blockType = type; wys.type = type;
    el.pageSel.disabled = true;
    if (el.blockSel.value !== type) { el.blockSel.value = type; }
    el.gjsWrap.style.display = "block"; el.body.style.display = "none";
    buildWysChrome();
    fillInsertMenu(type);
    loadSections();
    loadBlockTemplates(function () {
      var saved = st.blockTemplates[type];
      if (saved != null && saved !== "") { loadWys(saved); }
      else {
        fetch("https://" + st.host + "/design/?block=" + encodeURIComponent(type) + "&tpl_default=1")
          .then(function (r) { return r.text(); })
          .then(function (txt) { if (st.blockType === type) { loadWys(txt); } })
          .catch(function () { loadWys("<div>Empty block</div>"); });
      }
    });
  }
  function exitBlockMode() {
    if (wys.srcMode) { commitSource(); }   // fold any raw-HTML edits back first
    st.mode = "page"; st.blockType = null;
    el.blockSel.value = "";
    el.pageSel.disabled = false;
    el.gjsWrap.style.display = "none"; el.body.style.display = "";
    render(); refreshPreview();
  }

  // ---- toolbar + editing surface (built once) ----
  function buildWysChrome() {
    if (wys.chrome) { return; }
    wys.chrome = true;
    wys.frame = document.getElementById("dlWysFrame");
    wys.src   = document.getElementById("dlWysSrc");
    var tb = document.getElementById("dlWysTb");

    function btn(html, title, fn, cls) {
      var b = document.createElement("button");
      b.type = "button"; b.className = "dl-tbtn" + (cls ? " " + cls : ""); b.title = title; b.innerHTML = html;
      b.addEventListener("mousedown", function (e) { e.preventDefault(); }); // keep the editor selection
      b.addEventListener("click", function (e) { e.preventDefault(); fn(); });
      tb.appendChild(b); return b;
    }
    function cmd(name, val) {
      return function () { if (wys.srcMode || !wys.doc) { return; } wys.doc.execCommand(name, false, val || null); wys.root.focus(); wysChanged(); };
    }
    function sep() { var s = document.createElement("span"); s.className = "dl-tsep"; tb.appendChild(s); }

    btn("‹ Done", "Finish editing this block", function () { exitBlockMode(); }, "dl-tback");
    var lab = document.createElement("span"); lab.className = "dl-wys-label"; lab.id = "dlWysLabel"; tb.appendChild(lab);
    sep();

    var fmt = document.createElement("select"); fmt.className = "dl-tsel"; fmt.title = "Paragraph / heading";
    [["", "Format"], ["p", "Paragraph"], ["h1", "Heading 1"], ["h2", "Heading 2"], ["h3", "Heading 3"], ["h4", "Heading 4"], ["blockquote", "Quote"]]
      .forEach(function (o) { var op = document.createElement("option"); op.value = o[0]; op.textContent = o[1]; fmt.appendChild(op); });
    fmt.addEventListener("change", function () {
      if (fmt.value) { formatBlockSel(fmt.value); }
      fmt.value = "";
    });
    tb.appendChild(fmt); sep();

    btn("<b>B</b>", "Bold", function () { wrapInline("strong", ["STRONG", "B"]); });
    btn("<i>I</i>", "Italic", function () { wrapInline("em", ["EM", "I"]); });
    btn("<u>U</u>", "Underline", function () { wrapInline("u", ["U"]); });
    btn("<s>S</s>", "Strikethrough", function () { wrapInline("s", ["S", "STRIKE", "DEL"]); });
    sep();
    btn("• List", "Bulleted list", function () { listSel(false); });
    btn("1. List", "Numbered list", function () { listSel(true); });
    sep();
    btn("☰←", "Align left", function () { alignSel("left"); });
    btn("☰", "Align centre", function () { alignSel("center"); });
    btn("→☰", "Align right", function () { alignSel("right"); });
    sep();
    btn("🔗 Link", "Turn the selected text into a link", openLinkDialog, "dl-tlink");
    btn("Unlink", "Remove the link", function () { if (wys.srcMode || !wys.doc) { return; } wys.doc.execCommand("unlink"); wys.root.focus(); wysChanged(); });
    sep();

    wys.insSel = document.createElement("select"); wys.insSel.className = "dl-tsel"; wys.insSel.title = "Insert a dynamic field";
    wys.insSel.addEventListener("change", function () { if (wys.insSel.value) { insertToken(wys.insSel.value); } wys.insSel.value = ""; });
    tb.appendChild(wys.insSel); sep();

    btn("✕ Clear", "Clear formatting", cmd("removeFormat"));
    wys.srcBtn = btn("&lt;/&gt; HTML", "Edit the raw HTML", toggleSource, "dl-tsrc");
  }

  function fillInsertMenu(type) {
    if (!wys.insSel) { return; }
    wys.insSel.innerHTML = "";
    var first = document.createElement("option"); first.value = ""; first.textContent = "Insert field…"; wys.insSel.appendChild(first);
    fieldsFor(type).forEach(function (f) { var o = document.createElement("option"); o.value = f; o.textContent = tokInfo(f).text; wys.insSel.appendChild(o); });
  }

  // ---- tokens: {{...}} <-> protected chips ----
  // Friendly, plain-English labels so non-technical editors never see raw
  // template syntax ({{title}}, ↻ nav, ? show_search). The raw token is kept in
  // data-tok and round-trips untouched; only the on-screen label changes.
  var FIELD_LABELS = {
    site_name: "Publication name", logo: "Logo", search_icon: "Search icon",
    subscribe_label: "Subscribe button", name: "Name", href: "Link",
    title: "Title", standfirst: "Standfirst", meta: "Date & read time", body: "Article text",
    tagline: "Tagline", heading: "Heading", button_label: "Button", note: "Note",
    placeholder: "Placeholder text", guest_label: "Guest label", section: "Section name",
    section_href: "Section link", read_time: "Read time", snippet: "Summary", summary: "Summary",
    image: "Image", copyright: "Copyright", blurb: "Blurb", label: "Label",
    col1_label: "Column 1 title", col2_label: "Column 2 title", col3_label: "Column 3 title", col4_label: "Column 4 title",
    viewall_label: "View-all label", viewall_href: "View-all link", headlines_label: "Headlines title",
    url: "Link", cls: "Style", pager: "Pager", event: "Event",
    clinics_title: "Clinics title", clinics_text: "Clinics text",
    pkv_title: "PKV title", pkv_text: "PKV text", events_title: "Events title",
    read_all_label: "Read-all link", role: "Role", photo: "Photo", email: "Email",
    x_url: "X link", linkedin_url: "LinkedIn link",
    // loop nouns (singular, so "repeats for each …" reads correctly)
    nav: "menu link", sections: "section", connect: "link", services: "link", items: "item",
    cards: "story", mid: "story", side: "story",
    // condition senses
    show_search: "search is on", show_subscribe: "subscribe is on",
    hero: "there is a lead story", lead: "there is a lead story"
  };
  function tokHumanize(key) {
    if (FIELD_LABELS[key]) { return FIELD_LABELS[key]; }
    var last = String(key).split(".").pop();
    if (FIELD_LABELS[last]) { return FIELD_LABELS[last]; }
    last = last.replace(/_/g, " ");
    return last.charAt(0).toUpperCase() + last.slice(1);
  }
  function tokInfo(raw) {
    var inner = raw.replace(/^\{\{\{?\s*|\s*\}?\}\}$/g, ""), m;
    if ((m = inner.match(/^#each\s+(.+)$/))) { return { cls: "loop", text: "⟳ repeats for each " + tokHumanize(m[1]) }; }
    if ((m = inner.match(/^#if\s+(.+)$/)))   { return { cls: "cond", text: "only if " + tokHumanize(m[1]) }; }
    if (/^\/each$/.test(inner))              { return { cls: "end",  text: "⟳ end" }; }
    if (/^\/if$/.test(inner))                { return { cls: "end",  text: "end" }; }
    return { cls: "field", text: tokHumanize(inner) };
  }
  function makeTok(raw, doc) {
    var span = doc.createElement("span");
    var info = tokInfo(raw);
    span.className = "dl-tok dl-tok-" + info.cls;
    span.setAttribute("contenteditable", "false");
    span.setAttribute("data-tok", raw);
    span.textContent = info.text;
    if (info.cls === "field") {
      span.setAttribute("title", "“" + info.text + "” is filled in automatically on the live site and changes for every page, so it can’t be typed here.");
    } else {
      // Loop / condition markers: kept in the DOM for a clean round-trip, but
      // hidden from view (CSS) so there are no strange symbols on screen.
      span.setAttribute("title", "Automatic layout — managed for you.");
    }
    return span;
  }
  // A small neutral placeholder shown in the editor in place of an <img> whose
  // src is a {{token}} (which would otherwise render as a broken image + show
  // the raw token in its alt text).
  var DL_IMG_PLACEHOLDER = "data:image/svg+xml;utf8," + encodeURIComponent(
    "<svg xmlns='http://www.w3.org/2000/svg' width='44' height='30'><rect width='44' height='30' fill='#e8edf5'/><circle cx='15' cy='11' r='3.2' fill='#b9c4d6'/><path d='M7 24l9-9 5 5 5-4 11 8z' fill='#b9c4d6'/></svg>");

  // Replace templated images with a neutral placeholder for display, stashing the
  // real src/alt so they round-trip untouched (restored in wysTemplateHtml).
  function softenTemplateImages(root) {
    root.querySelectorAll("img").forEach(function (img) {
      var src = img.getAttribute("src") || "", alt = img.getAttribute("alt") || "";
      if (!/\{\{/.test(src) && !/\{\{/.test(alt)) { return; }
      img.setAttribute("data-dl-src", src);
      if (/\{\{/.test(alt)) { img.setAttribute("data-dl-alt", alt); }
      var m = src.match(/\{\{\{?\s*([\w.]+)\s*\}?\}\}/);
      img.setAttribute("src", DL_IMG_PLACEHOLDER);
      img.setAttribute("alt", m ? tokHumanize(m[1]) : "Image");
      img.setAttribute("title", "Image — set automatically on the live site");
      img.classList.add("dl-tplimg");
    });
  }

  function tokenizeDom(root, doc) {
    var walker = doc.createTreeWalker(root, NodeFilter.SHOW_TEXT, null), nodes = [], n;
    while ((n = walker.nextNode())) { if (/\{\{[\s\S]*?\}\}/.test(n.nodeValue)) { nodes.push(n); } }
    nodes.forEach(function (tn) {
      var s = tn.nodeValue, re = /\{\{\{[\s\S]*?\}\}\}|\{\{[\s\S]*?\}\}/g, frag = doc.createDocumentFragment(), last = 0, m;
      while ((m = re.exec(s))) {
        if (m.index > last) { frag.appendChild(doc.createTextNode(s.slice(last, m.index))); }
        frag.appendChild(makeTok(m[0], doc));
        last = m.index + m[0].length;
      }
      if (last < s.length) { frag.appendChild(doc.createTextNode(s.slice(last))); }
      tn.parentNode.replaceChild(frag, tn);
    });
  }

  function loadWys(tpl) {
    wys.srcMode = false;
    if (wys.srcBtn) { wys.srcBtn.classList.remove("dl-on"); }
    var parts = splitStyle(tpl); wys.styleTag = parts.style;
    var labelEl = document.getElementById("dlWysLabel");
    if (labelEl) { labelEl.textContent = (REG[wys.type] ? REG[wys.type].label : wys.type); }
    var skeleton =
      '<!doctype html><html><head><meta charset="utf-8">' +
      '<link rel="stylesheet" href="https://' + st.host + '/design/assets/design.css">' +
      '<style>' +
        'html,body{margin:0}body{padding:18px;background:#fff;color:#111}' +
        '.dl-edit-root{min-height:140px;outline:none}' +
        '.dl-tok{font:inherit;white-space:nowrap}' +
        '.dl-tok-field{color:#6b7a90;text-decoration:underline dotted #b6c0cf;text-underline-offset:3px;cursor:help}' +
        '.dl-tok-loop,.dl-tok-cond,.dl-tok-end{display:none}' +
        '.dl-tplimg{width:44px;height:30px;border:1px solid #cdd7e5;border-radius:4px;vertical-align:middle;object-fit:cover;opacity:.75}' +
        '.dl-edit-root a{color:#2563eb;text-decoration:underline}' +
        '::selection{background:#bfdbfe}' +
      '</style></head><body><div class="dl-edit-root" contenteditable="true"></div></body></html>';
    wys.frame.onload = function () {
      wys.doc = wys.frame.contentDocument; wys.win = wys.frame.contentWindow;
      wys.root = wys.doc.querySelector(".dl-edit-root");
      wys.root.innerHTML = parts.html || "";
      tokenizeDom(wys.root, wys.doc);
      softenTemplateImages(wys.root);
      try { wys.doc.execCommand("defaultParagraphSeparator", false, "p"); } catch (e) {}
      wys.root.addEventListener("input", wysChanged);
      wys.root.addEventListener("keyup", rememberRange);
      wys.root.addEventListener("mouseup", rememberRange);
      wys.src.style.display = "none"; wys.frame.style.display = "";
    };
    wys.frame.srcdoc = skeleton;
  }

  function rememberRange() {
    try { var s = wys.win.getSelection(); if (s && s.rangeCount) { wys.savedRange = s.getRangeAt(0).cloneRange(); } } catch (e) {}
  }

  // Serialise the editor back to a template string ({{...}} restored).
  function wysTemplateHtml() {
    if (!wys.root) { return ""; }
    var clone = wys.root.cloneNode(true);
    clone.querySelectorAll(".dl-tok").forEach(function (t) {
      t.parentNode.replaceChild(wys.doc.createTextNode(t.getAttribute("data-tok") || ""), t);
    });
    // Restore templated images to their original {{token}} src/alt.
    clone.querySelectorAll("img[data-dl-src]").forEach(function (img) {
      img.setAttribute("src", img.getAttribute("data-dl-src"));
      if (img.hasAttribute("data-dl-alt")) { img.setAttribute("alt", img.getAttribute("data-dl-alt")); }
      img.removeAttribute("data-dl-src"); img.removeAttribute("data-dl-alt");
      img.classList.remove("dl-tplimg"); if (!img.getAttribute("class")) { img.removeAttribute("class"); }
      img.removeAttribute("title");
    });
    clone.querySelectorAll("[contenteditable]").forEach(function (e) { e.removeAttribute("contenteditable"); });
    return clone.innerHTML.trim();
  }
  function wysGetTemplate() {
    var html = wys.srcMode ? wys.src.value.trim() : wysTemplateHtml();
    return html + (wys.styleTag ? "\n" + wys.styleTag : "");
  }

  function wysChanged() {
    if (!st.blockType) { return; }
    rememberRange();
    clearTimeout(applyTimer);
    applyTimer = setTimeout(function () {
      var tpl = wysGetTemplate();
      st.blockTemplates[st.blockType] = tpl;
      api("save_draft", { page: "_blocks", device: "desktop", layout_json: JSON.stringify({ templates: st.blockTemplates }) })
        .catch(function (e) { toast(e.message); });
    }, 700);
  }

  function insertToken(raw) {
    if (wys.srcMode) {
      var ta = wys.src, p = ta.selectionStart || 0;
      ta.value = ta.value.slice(0, p) + raw + ta.value.slice(ta.selectionEnd || p);
      ta.focus(); ta.selectionStart = ta.selectionEnd = p + raw.length;
      wysChanged(); return;
    }
    if (!wys.doc) { return; }
    wys.root.focus();
    var sel = wys.win.getSelection();
    if (wys.savedRange) { sel.removeAllRanges(); sel.addRange(wys.savedRange); }
    if (!sel.rangeCount) { wys.root.appendChild(makeTok(raw, wys.doc)); wysChanged(); return; }
    var r = sel.getRangeAt(0); r.deleteContents();
    var tok = makeTok(raw, wys.doc); r.insertNode(tok);
    r.setStartAfter(tok); r.collapse(true); sel.removeAllRanges(); sel.addRange(r);
    rememberRange(); wysChanged();
  }

  // ---- raw HTML view ----
  function toggleSource() {
    if (!wys.srcMode) {
      wys.src.value = wysTemplateHtml();
      wys.src.style.display = ""; wys.frame.style.display = "none";
      wys.srcMode = true; wys.srcBtn.classList.add("dl-on");
      wys.src.focus();
    } else {
      commitSource();
    }
  }
  function commitSource() {
    var raw = wys.src.value;
    wys.srcMode = false; wys.srcBtn.classList.remove("dl-on");
    loadWys(raw + (wys.styleTag ? "\n" + wys.styleTag : ""));
    wysChanged();
  }

  // ---- inline formatting ----
  // execCommand refuses to format a selection made of contenteditable=false
  // tokens, so we wrap the range ourselves (works for tokens AND plain text).
  function closestTag(node, tags) {
    var el = node && (node.nodeType === 1 ? node : node.parentElement);
    while (el && el !== wys.root) {
      if (tags.indexOf(el.tagName) !== -1) { return el; }
      el = el.parentElement;
    }
    return null;
  }
  function restoreSelection() {
    var sel = wys.win.getSelection();
    if (wys.savedRange) { try { sel.removeAllRanges(); sel.addRange(wys.savedRange); } catch (e) {} }
    return sel;
  }
  function wrapInline(tag, tags) {
    if (wys.srcMode || !wys.doc) { return; }
    wys.root.focus();
    var sel = restoreSelection();
    if (!sel.rangeCount || sel.isCollapsed) { toast("Select some text first"); return; }
    var range = sel.getRangeAt(0);
    var existing = closestTag(range.commonAncestorContainer, tags);
    if (existing) {                         // already formatted -> toggle off
      var inner = wys.doc.createRange(); inner.selectNodeContents(existing);
      unwrap(existing);
      sel.removeAllRanges(); sel.addRange(inner);
    } else {                                // wrap the selection
      var frag = range.extractContents();
      var w = wys.doc.createElement(tag);
      w.appendChild(frag);
      range.insertNode(w);
      var nr = wys.doc.createRange(); nr.selectNodeContents(w);
      sel.removeAllRanges(); sel.addRange(nr);
    }
    rememberRange(); wysChanged();
  }
  function clearFormat() {
    if (wys.srcMode || !wys.doc) { return; }
    wys.root.focus();
    var sel = restoreSelection();
    if (!sel.rangeCount) { return; }
    var tags = ["STRONG", "B", "EM", "I", "U", "S", "STRIKE", "DEL", "MARK", "A", "SMALL", "SUP", "SUB"];
    for (var i = 0; i < 12 && sel.rangeCount; i++) {
      var anc = closestTag(sel.getRangeAt(0).commonAncestorContainer, tags);
      if (!anc) { break; }
      unwrap(anc);
    }
    rememberRange(); wysChanged();
  }

  // ---- block-level formatting (headings, lists, align) ----
  // These also bypass execCommand so they work across protected tokens.
  var BLOCK_TAGS = ["P", "H1", "H2", "H3", "H4", "H5", "H6", "DIV", "BLOCKQUOTE", "LI", "PRE", "FIGCAPTION"];
  function blockAncestor(node) {
    var el = node && (node.nodeType === 1 ? node : node.parentElement);
    while (el && el !== wys.root) {
      if (BLOCK_TAGS.indexOf(el.tagName) !== -1) { return el; }
      el = el.parentElement;
    }
    return null;
  }
  function formatBlockSel(tag) {
    if (wys.srcMode || !wys.doc) { return; }
    wys.root.focus();
    var sel = restoreSelection();
    if (!sel.rangeCount) { toast("Select the text to restyle first"); return; }
    var range = sel.getRangeAt(0);
    var blk = blockAncestor(range.commonAncestorContainer);
    var el = wys.doc.createElement(tag);
    if (blk) {
      while (blk.firstChild) { el.appendChild(blk.firstChild); }
      if (blk.getAttribute("style")) { el.setAttribute("style", blk.getAttribute("style")); }
      blk.parentNode.replaceChild(el, blk);
    } else {
      if (range.collapsed) { toast("Select the text to restyle first"); return; }
      el.appendChild(range.extractContents());
      range.insertNode(el);
    }
    var nr = wys.doc.createRange(); nr.selectNodeContents(el);
    sel.removeAllRanges(); sel.addRange(nr);
    rememberRange(); wysChanged();
  }
  function alignSel(dir) {
    if (wys.srcMode || !wys.doc) { return; }
    wys.root.focus();
    var sel = restoreSelection();
    if (!sel.rangeCount) { return; }
    var range = sel.getRangeAt(0);
    var blk = blockAncestor(range.commonAncestorContainer);
    if (!blk) {
      if (range.collapsed) { toast("Select text in a paragraph first"); return; }
      blk = wys.doc.createElement("div");
      blk.appendChild(range.extractContents());
      range.insertNode(blk);
    }
    blk.style.textAlign = dir;
    var nr = wys.doc.createRange(); nr.selectNodeContents(blk);
    sel.removeAllRanges(); sel.addRange(nr);
    rememberRange(); wysChanged();
  }
  function listSel(ordered) {
    if (wys.srcMode || !wys.doc) { return; }
    wys.root.focus();
    var sel = restoreSelection();
    if (!sel.rangeCount) { return; }
    var range = sel.getRangeAt(0);
    var li = closestTag(range.commonAncestorContainer, ["LI"]);
    if (li) {                               // already a list item -> back to paragraph
      var list = li.parentNode;
      var p = wys.doc.createElement("p");
      while (li.firstChild) { p.appendChild(li.firstChild); }
      list.parentNode.insertBefore(p, list.nextSibling);
      li.parentNode.removeChild(li);
      if (!list.querySelector("li")) { list.parentNode.removeChild(list); }
      var pr = wys.doc.createRange(); pr.selectNodeContents(p); sel.removeAllRanges(); sel.addRange(pr);
      rememberRange(); wysChanged(); return;
    }
    var listEl = wys.doc.createElement(ordered ? "ol" : "ul");
    var item = wys.doc.createElement("li");
    var blk = blockAncestor(range.commonAncestorContainer);
    if (blk) {
      while (blk.firstChild) { item.appendChild(blk.firstChild); }
      listEl.appendChild(item);
      blk.parentNode.replaceChild(listEl, blk);
    } else {
      if (range.collapsed) { toast("Select the text to list first"); return; }
      item.appendChild(range.extractContents());
      listEl.appendChild(item);
      range.insertNode(listEl);
    }
    var nr = wys.doc.createRange(); nr.selectNodeContents(item);
    sel.removeAllRanges(); sel.addRange(nr);
    rememberRange(); wysChanged();
  }

  // ---- link dialog ----
  function buildLinkDialog() {
    if (el.linkDlg) { return; }
    var ov = document.createElement("div"); ov.className = "dl-ov dl-linkov";
    var m = document.createElement("div"); m.className = "dl-linkdlg";
    m.innerHTML =
      '<h3>Add a link</h3>' +
      '<p class="dl-linksel" id="dlLinkSel"></p>' +
      '<label class="dl-lk-lbl">Link to</label>' +
      '<select id="dlLinkDest" class="dl-lk-sel"></select>' +
      '<label class="dl-lk-lbl">URL</label>' +
      '<input type="text" id="dlLinkUrl" class="dl-lk-url" placeholder="/design/…   https://…   or   {{section_href}}">' +
      '<label class="dl-lk-chk"><input type="checkbox" id="dlLinkNew"> Open in a new tab</label>' +
      '<div class="dl-lk-actions">' +
        '<button class="dl-b" id="dlLinkCancel">Cancel</button>' +
        '<button class="dl-b" id="dlLinkRemove">Remove link</button>' +
        '<button class="dl-b publish" id="dlLinkApply">Apply link</button>' +
      '</div>';
    var root = document.getElementById("dlDesignRoot");
    root.appendChild(ov); root.appendChild(m);
    el.linkOv = ov; el.linkDlg = m;
    el.linkDest = m.querySelector("#dlLinkDest");
    el.linkUrl = m.querySelector("#dlLinkUrl");
    el.linkNew = m.querySelector("#dlLinkNew");
    el.linkSel = m.querySelector("#dlLinkSel");
    el.linkDest.addEventListener("change", function () { if (el.linkDest.value) { el.linkUrl.value = el.linkDest.value; } });
    m.querySelector("#dlLinkCancel").addEventListener("click", closeLinkDialog);
    ov.addEventListener("click", closeLinkDialog);
    m.querySelector("#dlLinkApply").addEventListener("click", function () { applyLink(false); });
    m.querySelector("#dlLinkRemove").addEventListener("click", function () { applyLink(true); });
  }
  function fillLinkDest() {
    var d = el.linkDest; d.innerHTML = "";
    var first = document.createElement("option"); first.value = ""; first.textContent = "Choose a destination…"; d.appendChild(first);
    function grp(label, pairs) {
      if (!pairs || !pairs.length) { return; }
      var og = document.createElement("optgroup"); og.label = label;
      pairs.forEach(function (p) { var o = document.createElement("option"); o.value = p[0]; o.textContent = p[1]; og.appendChild(o); });
      d.appendChild(og);
    }
    grp("Fixed pages", FIXED_PAGES);
    grp("Sections", (wys.sections || []).map(function (s) { return [s.href, s.name]; }));
    grp("Dynamic fields", (BLOCK_HREFS[wys.type] || []).concat([GENERIC_HREF]));
  }
  function currentAnchor() {
    try {
      var n = wys.savedRange ? wys.savedRange.startContainer : null;
      n = n && (n.nodeType === 1 ? n : n.parentElement);
      return n && n.closest ? n.closest("a") : null;
    } catch (e) { return null; }
  }
  function openLinkDialog() {
    if (wys.srcMode) { toast("Switch off the HTML view to use the link tool"); return; }
    if (!wys.doc) { return; }
    rememberRange();
    var sel = wys.win.getSelection();
    var txt = sel ? sel.toString() : "";
    var a = currentAnchor();
    buildLinkDialog(); fillLinkDest();
    el.linkSel.textContent = a
      ? ("Editing an existing link: “" + (a.textContent || "") + "”")
      : (txt ? ("Linking: “" + txt + "”") : "Tip: select some text first, then choose a destination.");
    el.linkUrl.value = a ? (a.getAttribute("href") || "") : "";
    el.linkNew.checked = a ? (a.getAttribute("target") === "_blank") : false;
    el.linkDest.value = "";
    el.linkOv.classList.add("dl-show"); el.linkDlg.classList.add("dl-show");
    setTimeout(function () { el.linkUrl.focus(); }, 30);
  }
  function closeLinkDialog() {
    if (el.linkOv) { el.linkOv.classList.remove("dl-show"); el.linkDlg.classList.remove("dl-show"); }
    if (wys.root) { wys.root.focus(); }
  }
  function unwrap(node) {
    var p = node.parentNode; while (node.firstChild) { p.insertBefore(node.firstChild, node); } p.removeChild(node);
  }
  function applyLink(remove) {
    if (!wys.doc) { return; }
    var url = el.linkUrl.value.trim(), newTab = el.linkNew.checked;
    wys.root.focus();
    var sel = wys.win.getSelection();
    if (wys.savedRange) { sel.removeAllRanges(); sel.addRange(wys.savedRange); }
    var a = currentAnchor();
    if (remove) {
      if (a) { unwrap(a); }
      closeLinkDialog(); wysChanged(); return;
    }
    if (!url) { toast("Enter a URL or choose a destination"); return; }
    if (a) {                                  // edit an existing link in place
      a.setAttribute("href", url);
      if (newTab) { a.setAttribute("target", "_blank"); a.setAttribute("rel", "noopener"); }
      else { a.removeAttribute("target"); a.removeAttribute("rel"); }
    } else {
      if (!sel.rangeCount || sel.isCollapsed) { toast("Select the text you want to link first"); return; }
      var r = sel.getRangeAt(0), frag = r.extractContents();
      var link = wys.doc.createElement("a");
      link.setAttribute("href", url);                        // setAttribute keeps {{...}} literal
      if (newTab) { link.setAttribute("target", "_blank"); link.setAttribute("rel", "noopener"); }
      link.appendChild(frag); r.insertNode(link);
      var nr = wys.doc.createRange(); nr.selectNodeContents(link); sel.removeAllRanges(); sel.addRange(nr);
    }
    rememberRange(); closeLinkDialog(); wysChanged();
  }

  // ---- AI edit (Claude) ----
  function aiApi(params) {
    var body = new URLSearchParams();
    body.set("pub", st.pub);
    Object.keys(params).forEach(function (k) { body.set(k, params[k]); });
    return fetch("ajax/design_ai.php", { method: "POST", body: body })
      .then(function (r) { return r.json(); })
      .then(function (j) { if (!j.success) { throw new Error(j.message || "AI request failed"); } return j; });
  }

  function buildAiDialog() {
    if (el.aiDlg) { return; }
    var ov = document.createElement("div"); ov.className = "dl-ov dl-aiov";
    var m = document.createElement("div"); m.className = "dl-aidlg";
    m.innerHTML =
      '<h3>✨ AI design edit</h3>' +
      '<div class="dl-ai-modes">' +
        '<label><input type="radio" name="dlAiMode" value="restyle" checked> Restyle the whole design</label>' +
        '<label><input type="radio" name="dlAiMode" value="edit_element"> Edit the selected block</label>' +
      '</div>' +
      '<p class="dl-ai-ctx" id="dlAiCtx"></p>' +
      '<textarea id="dlAiText" class="dl-ai-text" placeholder="Describe the change in plain English — e.g. “make the headlines grander and the captions tighter”."></textarea>' +
      '<p class="dl-ai-note">Works to your house system (Lora / Merriweather / Inter, zero-radius). Changes go to the draft — preview them, then Publish when you’re happy.</p>' +
      '<div class="dl-ai-actions">' +
        '<span class="dl-ai-busy" id="dlAiBusy">Thinking…</span>' +
        '<button class="dl-b" id="dlAiCancel">Cancel</button>' +
        '<button class="dl-b publish" id="dlAiApply">Apply</button>' +
      '</div>';
    var root = document.getElementById("dlDesignRoot");
    root.appendChild(ov); root.appendChild(m);
    el.aiOv = ov; el.aiDlg = m;
    el.aiText = m.querySelector("#dlAiText");
    el.aiCtx = m.querySelector("#dlAiCtx");
    el.aiBusy = m.querySelector("#dlAiBusy");
    el.aiApply = m.querySelector("#dlAiApply");
    ov.addEventListener("click", closeAiDialog);
    m.querySelector("#dlAiCancel").addEventListener("click", closeAiDialog);
    el.aiApply.addEventListener("click", applyAi);
    m.querySelectorAll('input[name="dlAiMode"]').forEach(function (r) {
      r.addEventListener("change", updateAiCtx);
    });
  }
  function selectedAiMode() {
    var r = el.aiDlg.querySelector('input[name="dlAiMode"]:checked');
    return r ? r.value : "restyle";
  }
  function selectedBlock() {
    return (st.mode === "page" && st.sel >= 0 && st.desktop.blocks[st.sel]) ? st.desktop.blocks[st.sel] : null;
  }
  function updateAiCtx() {
    var mode = selectedAiMode();
    if (mode === "restyle") {
      el.aiCtx.textContent = "Applies to the whole " + (PAGE_LABELS[st.page] || st.page) + " design (fonts, sizes, spacing, colours).";
    } else {
      var blk = selectedBlock();
      el.aiCtx.textContent = blk ? ("Editing the “" + (REG[blk.type] ? REG[blk.type].label : blk.type) + "” block.")
                                 : "Select a block in the list first, then choose this mode.";
    }
  }
  function openAiDialog() {
    if (st.mode !== "page") { exitBlockMode(); }
    buildAiDialog();
    el.aiText.value = "";
    el.aiBusy.style.visibility = "hidden";
    // Default to edit-block mode when a block is selected.
    var blk = selectedBlock();
    el.aiDlg.querySelector('input[value="edit_element"]').checked = !!blk;
    el.aiDlg.querySelector('input[value="restyle"]').checked = !blk;
    updateAiCtx();
    el.aiOv.classList.add("dl-show"); el.aiDlg.classList.add("dl-show");
    setTimeout(function () { el.aiText.focus(); }, 30);
  }
  function closeAiDialog() {
    if (el.aiOv) { el.aiOv.classList.remove("dl-show"); el.aiDlg.classList.remove("dl-show"); }
  }
  function aiBusy(on) {
    if (el.aiBusy) { el.aiBusy.style.visibility = on ? "visible" : "hidden"; }
    if (el.aiApply) { el.aiApply.disabled = on; }
  }
  // Fetch a block's current template (saved per-pub, else the built-in default).
  function getBlockTemplateFor(type, cb) {
    loadBlockTemplates(function () {
      var saved = st.blockTemplates[type];
      if (saved != null && saved !== "") { cb(saved); return; }
      fetch("https://" + st.host + "/design/?block=" + encodeURIComponent(type) + "&tpl_default=1")
        .then(function (r) { return r.text(); })
        .then(cb).catch(function () { cb(""); });
    });
  }
  function applyAi() {
    var mode = selectedAiMode();
    var instruction = el.aiText.value.trim();
    if (!instruction) { toast("Describe the change first"); return; }

    if (mode === "restyle") {
      aiBusy(true);
      aiApi({ action: "restyle", instruction: instruction, current_theme: JSON.stringify(st.desktop.theme || {}) })
        .then(function (j) {
          if (!st.desktop.theme) { st.desktop.theme = {}; }
          Object.keys(j.theme || {}).forEach(function (k) { st.desktop.theme[k] = j.theme[k]; });
          if (j.custom_css) {
            st.desktop.theme.custom_css = (st.desktop.theme.custom_css ? st.desktop.theme.custom_css + "\n" : "") + j.custom_css;
          }
          aiBusy(false); closeAiDialog();
          saveDraftSilent().then(refreshPreview); renderRight();
          toast("Design restyled — preview updated");
        })
        .catch(function (e) { aiBusy(false); toast(e.message); });
      return;
    }

    // edit_element
    var blk = selectedBlock();
    if (!blk) { toast("Select a block in the list first"); return; }
    var type = blk.type;
    aiBusy(true);
    getBlockTemplateFor(type, function (tpl) {
      if (!tpl) { aiBusy(false); toast("Couldn't load that block's content"); return; }
      aiApi({ action: "edit_element", block_type: type, template: tpl, instruction: instruction })
        .then(function (j) {
          var tasks = [];
          if (j.template) {
            st.blockTemplates[type] = j.template;
            tasks.push(api("save_draft", { page: "_blocks", device: "desktop", layout_json: JSON.stringify({ templates: st.blockTemplates }) }));
          }
          if (j.css) {
            if (!blk.settings) { blk.settings = {}; }
            blk.settings.custom_css = (blk.settings.custom_css ? blk.settings.custom_css + "\n" : "") + j.css;
            tasks.push(saveDraftSilent());
          }
          if (!tasks.length) { aiBusy(false); toast("No change suggested — try rephrasing"); return; }
          Promise.all(tasks).then(function () {
            aiBusy(false); closeAiDialog(); refreshPreview();
            toast("Block updated — preview refreshed");
          });
        })
        .catch(function (e) { aiBusy(false); toast(e.message); });
    });
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

    // Non-technical editing happens on the live preview; the raw HTML editor is
    // an advanced, clearly-labelled escape hatch (links / custom layout).
    if (TEMPLATE_BLOCKS.indexOf(block.type) !== -1) {
      var tip = document.createElement("p");
      tip.className = "dl-hint";
      tip.style.cssText = "margin:0 0 12px;color:#334155;font-size:12px;line-height:1.5;background:#f1f5f9;border:1px solid #e2e8f0;border-radius:6px;padding:9px 11px;";
      tip.innerHTML = "✏️ <strong>Edit on the page.</strong> Click any text in the preview to change it, or click any part to style it with the controls below.";
      el.right.appendChild(tip);

      var editBtn = document.createElement("button");
      editBtn.type = "button"; editBtn.className = "dl-adv-link";
      editBtn.innerHTML = "⟨⟩ Advanced: edit raw HTML &amp; links";
      editBtn.title = "For developers — shows the block's template code.";
      editBtn.addEventListener("click", function () { enterBlockMode(block.type); });
      el.right.appendChild(editBtn);
    }

    // Which element the style controls target.
    var target = document.createElement("div"); target.className = "dl-target";
    target.innerHTML = "Editing: <strong>" + (selEl ? esc(elementFriendly(st.selElLabel, selEl)) : "the whole block") + "</strong>";
    if (selEl) {
      var whole = document.createElement("button"); whole.type = "button"; whole.className = "dl-chip"; whole.style.marginLeft = "8px"; whole.textContent = "whole block";
      whole.addEventListener("click", function () { st.selEl = ""; st.selEditKey = ""; st.selElLabel = ""; renderRight(); });
      target.appendChild(whole);
    }
    el.right.appendChild(target);
    var pHint = document.createElement("p"); pHint.className = "dl-hint";
    pHint.textContent = selEl ? "Changes below apply to this element. Click another part of the preview to pick a different one."
                              : "Click any element in the preview to edit and style just that part.";
    el.right.appendChild(pHint);

    // If this element's text is editable, offer a reliable text field here too.
    if (selEl && st.selEditKey) {
      var txtSub = document.createElement("div"); txtSub.className = "dl-sub"; txtSub.textContent = "Text"; el.right.appendChild(txtSub);
      var txt = document.createElement("input"); txt.type = "text"; txt.placeholder = "(automatic default)";
      txt.value = (st.selEditKey in block.settings) ? block.settings[st.selEditKey] : "";
      var ekey = st.selEditKey;
      txt.addEventListener("input", function () { block.settings[ekey] = txt.value; applyChangeDebounced(); });
      el.right.appendChild(field("Edit this text", txt));
    }

    // Visual style controls for the selected element.
    var styleSub = document.createElement("div"); styleSub.className = "dl-sub"; styleSub.textContent = selEl ? "Style this element" : "Style the whole block"; el.right.appendChild(styleSub);
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
