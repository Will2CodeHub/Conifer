/* TEN Design Studio — full HTML editor module (DS.HtmlEditor).
   A rich editor over a same-origin contenteditable iframe styled with the site's
   design.css. {{…}} placeholders show as protected tokens: their text can't be
   edited, but they can be styled. Toolbar: format/headings, B/I/U/S, lists, align,
   font family, font size, text + highlight colour, link, placeholder dropdown,
   columns, clear, and a raw-HTML source view. Ports the verified core from
   news_sites_design.js. */
(function () {
  "use strict";
  var DS = window.DS = window.DS || {};

  var w = { frame: null, src: null, doc: null, win: null, root: null, type: null,
            host: "", srcMode: false, styleTag: "", savedRange: null, sections: [],
            insSel: null, srcBtn: null, onChange: null };
  var changeTimer = null;

  // ---- field labels / destinations (ported) ----------------------------
  var FIELD_LABELS = {
    site_name: "Publication name", logo: "Logo", search_icon: "Search icon",
    subscribe_label: "Subscribe button", name: "Name", href: "Link",
    title: "Title", standfirst: "Standfirst", meta: "Date & read time", body: "Article text",
    tagline: "Tagline", heading: "Heading", button_label: "Button", note: "Note",
    placeholder: "Placeholder text", guest_label: "Guest label", section: "Section name",
    section_href: "Section link", read_time: "Read time", snippet: "Summary", summary: "Summary",
    image: "Image", copyright: "Copyright", blurb: "Blurb", label: "Label", date: "Today's date",
    col1_label: "Column 1 title", col2_label: "Column 2 title", col3_label: "Column 3 title", col4_label: "Column 4 title",
    viewall_label: "View-all label", viewall_href: "View-all link", headlines_label: "Headlines title",
    url: "Link", role: "Role", photo: "Photo", email: "Email", x_url: "X link", linkedin_url: "LinkedIn link",
    nav: "menu link", sections: "section", items: "item", cards: "story", mid: "story", side: "story",
    show_search: "search is on", show_subscribe: "subscribe is on", hero: "there is a lead story", lead: "there is a lead story"
  };
  var GLOBAL_FIELDS = [
    ["{{date}}", "Today's date"], ["{{site_name}}", "Publication name"],
    ["{{name}}", "Journalist / author name"], ["{{role}}", "Position / role"],
    ["{{section}}", "Section name"], ["{{title}}", "Article title"],
    ["{{standfirst}}", "Standfirst"], ["{{{body}}}", "Article text"], ["{{meta}}", "Date & read time"]
  ];
  var BLOCK_PLACEHOLDERS = {
    site_header: "{{site_name}}, {{logo}}, {{search_icon}}, {{subscribe_label}}, {{#if show_search}}, {{#if show_subscribe}}, {{#each nav}}{{name}} {{href}}{{/each}}",
    masthead: "{{site_name}}, {{tagline}}",
    front_feature: "{{#if hero}}{{hero.title}} {{hero.image}} {{hero.meta}} {{hero.summary}}{{/if}}, {{#each mid}}{{title}} {{image}} {{summary}}{{/each}}, {{#each side}}{{title}} {{section}}{{/each}}",
    front_sections: "{{#each sections}}{{name}} {{href}} {{#each cards}}{{title}} {{image}} {{snippet}}{{/each}}{{/each}}",
    footer: "{{site_name}}, {{copyright}}, {{col1_label}}, {{col2_label}}, {{col3_label}}, {{col4_label}}, {{#each sections}}{{name}} {{url}}{{/each}}",
    breaking_news: "{{heading}}, {{viewall_label}}, {{#each items}}{{title}} {{href}} {{read_time}}{{/each}}",
    triple_box: "{{pkv_title}}, {{pkv_text}}, {{clinics_title}}, {{clinics_text}}, {{events_title}}, {{#if event}}{{event.title}} {{event.text}}{{/if}}",
    headlines_list: "{{headlines_label}}, {{#each items}}{{title}} {{href}} {{meta}}{{/each}}",
    ticker: "{{label}}, {{#each items}}{{title}} {{href}}{{/each}}",
    advert: "{{label}}, {{slot}}",
    section_title: "{{name}}",
    section_lead: "{{#if lead}}{{lead.title}} {{lead.image}} {{lead.summary}}{{/if}}, {{#each side}}{{title}} {{meta}}{{/each}}",
    section_carousel: "{{title}}, {{#each items}}{{title}} {{image}} {{section}}{{/each}}",
    article_grid: "{{columns}}, {{#each cards}}{{title}} {{image}} {{read_time}}{{/each}}",
    article_header: "{{section}}, {{title}}, {{standfirst}}, {{meta}}",
    article_hero: "{{#if image}}{{image}} {{title}}{{/if}}",
    article_body: "{{{body}}}",
    article_byline: "{{#if has_author}}{{name}} {{role}} {{photo}} {{read_all_label}}{{/if}}",
    article_related: "{{heading}}, {{#each cards}}{{title}} {{image}} {{section}} {{meta}}{{/each}}",
    article_quickreads: "{{heading}}, {{#each items}}{{title}} {{section}} {{meta}}{{/each}}",
    author_profile: "{{site_name}}, {{name}}, {{role}}, {{photo}}, {{#each bio_paras}}{{value}}{{/each}}, {{x_url}}, {{linkedin_url}}, {{email}}",
    author_articles: "{{heading}}, {{#each items}}{{title}} {{image}} {{section}} {{standfirst}} {{meta}}{{/each}}",
    rich_text: "{{title}}, {{body}}",
    article_comments: "{{heading}}, {{note}}, {{placeholder}}, {{guest_label}}, {{button_label}}"
  };
  var FIXED_PAGES = [
    ["/design/", "Home page"], ["/design/about", "About us"], ["/design/contact", "Contact"],
    ["/design/impressum", "Impressum"], ["/design/privacy", "Privacy policy"],
    ["/design/terms", "Terms"], ["/design/disclaimer", "Disclaimer"]
  ];
  var BLOCK_HREFS = {
    article_header: [["{{section_href}}", "This article's section page"]],
    front_feature: [["{{hero.href}}", "Lead-story link"], ["{{section_href}}", "This headline's section page"]],
    headlines_list: [["{{section_href}}", "This headline's section page"]],
    section_carousel: [["{{section_href}}", "This item's section page"]],
    article_related: [["{{section_href}}", "This item's section page"]],
    article_quickreads: [["{{section_href}}", "This item's section page"]],
    author_articles: [["{{section_href}}", "This article's section page"]]
  };
  var GENERIC_HREF = ["{{href}}", "Current item's link (inside a list)"];

  var FONTS = [["", "Font"], ['"Lora", Georgia, serif', "Lora (headlines)"], ['"Merriweather", Georgia, serif', "Merriweather (body)"],
    ['"Inter", system-ui, sans-serif', "Inter (UI)"], ['"Public Sans", sans-serif', "Public Sans"], ["Georgia, serif", "Georgia"],
    ['"Times New Roman", serif', "Times"], ["Arial, sans-serif", "Arial"], ['"Helvetica Neue", Helvetica, sans-serif', "Helvetica"],
    ["Verdana, sans-serif", "Verdana"], ['"Courier New", monospace', "Courier"]];
  var SIZES = [["", "Size"], ["12px", "12"], ["14px", "14"], ["16px", "16"], ["18px", "18"], ["20px", "20"],
    ["24px", "24"], ["28px", "28"], ["32px", "32"], ["40px", "40"], ["48px", "48"], ["64px", "64"]];

  // ---- tokens ----------------------------------------------------------
  function fieldsFor(type) {
    var out = [], s = BLOCK_PLACEHOLDERS[type] || "", re = /\{\{\{?[^{}]*?\}?\}\}/g, m;
    while ((m = re.exec(s))) { if (out.indexOf(m[0]) === -1) { out.push(m[0]); } }
    return out;
  }
  function splitStyle(tpl) {
    var styles = [];
    var html = String(tpl || "").replace(/<style[\s\S]*?<\/style>/gi, function (m) { styles.push(m); return ""; });
    return { html: html.trim(), style: styles.join("\n") };
  }
  // Per-block overrides so ambiguous field names read clearly in context
  // (e.g. {{name}} inside the top menu means the menu item's label).
  var BLOCK_FIELD_LABELS = {
    site_header: { name: "Menu label", href: "Menu link" },
    footer: { name: "Footer link label", url: "Footer link" },
    front_sections: { name: "Section name", href: "Section link" },
    breaking_news: { title: "Headline", href: "Headline link" },
    headlines_list: { title: "Headline", href: "Headline link" },
    ticker: { title: "Ticker item", href: "Ticker link" },
    front_feature: { title: "Story headline", summary: "Story summary" },
    section_carousel: { title: "Story headline" },
    article_related: { title: "Story headline" },
    article_quickreads: { title: "Story headline" },
    author_articles: { title: "Article headline" }
  };
  function tokHumanize(key) {
    if (w.type && BLOCK_FIELD_LABELS[w.type] && BLOCK_FIELD_LABELS[w.type][key]) { return BLOCK_FIELD_LABELS[w.type][key]; }
    if (FIELD_LABELS[key]) { return FIELD_LABELS[key]; }
    var last = String(key).split(".").pop();
    if (w.type && BLOCK_FIELD_LABELS[w.type] && BLOCK_FIELD_LABELS[w.type][last]) { return BLOCK_FIELD_LABELS[w.type][last]; }
    if (FIELD_LABELS[last]) { return FIELD_LABELS[last]; }
    last = last.replace(/_/g, " ");
    return last.charAt(0).toUpperCase() + last.slice(1);
  }
  function tokInfo(raw) {
    var inner = raw.replace(/^\{\{\{?\s*|\s*\}?\}\}$/g, ""), m;
    if ((m = inner.match(/^#each\s+(.+)$/))) { return { cls: "loop", text: "repeats for each " + tokHumanize(m[1]) }; }
    if ((m = inner.match(/^#if\s+(.+)$/))) { return { cls: "cond", text: "only if " + tokHumanize(m[1]) }; }
    if (/^\/each$/.test(inner)) { return { cls: "end", text: "end" }; }
    if (/^\/if$/.test(inner)) { return { cls: "end", text: "end" }; }
    return { cls: "field", text: tokHumanize(inner) };
  }
  function makeTok(raw, doc) {
    var span = doc.createElement("span");
    var info = tokInfo(raw);
    span.className = "dl-tok dl-tok-" + info.cls;
    span.setAttribute("contenteditable", "false");
    span.setAttribute("data-tok", raw);
    span.textContent = info.text;
    span.setAttribute("title", info.cls === "field"
      ? "“" + info.text + "” is filled in automatically on the live site, so it can't be typed here. You can still colour and resize it."
      : "Automatic layout — managed for you.");
    return span;
  }
  var IMG_PH = "data:image/svg+xml;utf8," + encodeURIComponent(
    "<svg xmlns='http://www.w3.org/2000/svg' width='44' height='30'><rect width='44' height='30' fill='#e8edf5'/><circle cx='15' cy='11' r='3.2' fill='#b9c4d6'/><path d='M7 24l9-9 5 5 5-4 11 8z' fill='#b9c4d6'/></svg>");
  function softenTemplateImages(root) {
    root.querySelectorAll("img").forEach(function (img) {
      var src = img.getAttribute("src") || "", alt = img.getAttribute("alt") || "";
      if (!/\{\{/.test(src) && !/\{\{/.test(alt)) { return; }
      img.setAttribute("data-dl-src", src);
      if (/\{\{/.test(alt)) { img.setAttribute("data-dl-alt", alt); }
      var m = src.match(/\{\{\{?\s*([\w.]+)\s*\}?\}\}/);
      img.setAttribute("src", IMG_PH);
      img.setAttribute("alt", m ? tokHumanize(m[1]) : "Image");
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

  // ---- selection helpers -----------------------------------------------
  function rememberRange() {
    try { var s = w.win.getSelection(); if (s && s.rangeCount) { w.savedRange = s.getRangeAt(0).cloneRange(); } } catch (e) {}
  }
  function restoreSelection() {
    var sel = w.win.getSelection();
    if (w.savedRange) { try { sel.removeAllRanges(); sel.addRange(w.savedRange); } catch (e) {} }
    return sel;
  }
  // Select a whole element (used when a placeholder token is clicked) so the next
  // style action applies to it.
  function selectNode(node) {
    try {
      var r = w.doc.createRange(); r.selectNode(node);
      var sel = w.win.getSelection(); sel.removeAllRanges(); sel.addRange(r);
      w.savedRange = r.cloneRange();
      node.classList.add("ds-he-hl"); setTimeout(function () { node.classList.remove("ds-he-hl"); }, 900);
    } catch (e) {}
  }
  function unwrap(node) { var p = node.parentNode; while (node.firstChild) { p.insertBefore(node.firstChild, node); } p.removeChild(node); }
  var INLINE_TAGS = ["STRONG", "B", "EM", "I", "U", "S", "STRIKE", "DEL", "MARK", "A", "SMALL", "SUP", "SUB", "SPAN"];
  var BLOCK_TAGS = ["P", "H1", "H2", "H3", "H4", "H5", "H6", "DIV", "BLOCKQUOTE", "LI", "PRE", "FIGCAPTION"];
  function closestTag(node, tags) {
    var el = node && (node.nodeType === 1 ? node : node.parentElement);
    while (el && el !== w.root) { if (tags.indexOf(el.tagName) !== -1) { return el; } el = el.parentElement; }
    return null;
  }
  function blockAncestor(node) {
    var el = node && (node.nodeType === 1 ? node : node.parentElement);
    while (el && el !== w.root) { if (BLOCK_TAGS.indexOf(el.tagName) !== -1) { return el; } el = el.parentElement; }
    return null;
  }

  // ---- inline formatting -----------------------------------------------
  function wrapInline(tag, tags) {
    if (w.srcMode || !w.doc) { return; }
    w.root.focus();
    var sel = restoreSelection();
    if (!sel.rangeCount || sel.isCollapsed) { DS.toast("Select some text first"); return; }
    var range = sel.getRangeAt(0);
    var existing = closestTag(range.commonAncestorContainer, tags);
    if (existing) {
      var inner = w.doc.createRange(); inner.selectNodeContents(existing);
      unwrap(existing); sel.removeAllRanges(); sel.addRange(inner);
    } else {
      var wrapEl = w.doc.createElement(tag); wrapEl.appendChild(range.extractContents());
      range.insertNode(wrapEl);
      var nr = w.doc.createRange(); nr.selectNodeContents(wrapEl); sel.removeAllRanges(); sel.addRange(nr);
    }
    rememberRange(); changed();
  }
  // Style the selection (font/size/colour). Works on text AND on protected
  // tokens (the span wraps the token, styling it without touching its text).
  function wrapInlineStyle(prop, val) {
    if (w.srcMode || !w.doc) { return; }
    w.root.focus();
    var sel = restoreSelection();
    if (!sel.rangeCount || sel.isCollapsed) { DS.toast("Select something to style first"); return; }
    var range = sel.getRangeAt(0);
    var span = w.doc.createElement("span");
    span.style[prop] = val;
    span.appendChild(range.extractContents());
    range.insertNode(span);
    var nr = w.doc.createRange(); nr.selectNodeContents(span); sel.removeAllRanges(); sel.addRange(nr);
    rememberRange(); changed();
  }
  function clearFormat() {
    if (w.srcMode || !w.doc) { return; }
    w.root.focus();
    var sel = restoreSelection();
    if (!sel.rangeCount) { return; }
    var tags = ["STRONG", "B", "EM", "I", "U", "S", "STRIKE", "DEL", "MARK", "A", "SMALL", "SUP", "SUB", "SPAN"];
    for (var i = 0; i < 12 && sel.rangeCount; i++) {
      var anc = closestTag(sel.getRangeAt(0).commonAncestorContainer, tags);
      if (!anc) { break; }
      unwrap(anc);
    }
    rememberRange(); changed();
  }
  function formatBlockSel(tag) {
    if (w.srcMode || !w.doc) { return; }
    w.root.focus();
    var sel = restoreSelection();
    if (!sel.rangeCount) { DS.toast("Select the text to restyle first"); return; }
    var range = sel.getRangeAt(0);
    var blk = blockAncestor(range.commonAncestorContainer);
    var el = w.doc.createElement(tag);
    if (blk) {
      while (blk.firstChild) { el.appendChild(blk.firstChild); }
      if (blk.getAttribute("style")) { el.setAttribute("style", blk.getAttribute("style")); }
      blk.parentNode.replaceChild(el, blk);
    } else {
      if (range.collapsed) { DS.toast("Select the text to restyle first"); return; }
      el.appendChild(range.extractContents()); range.insertNode(el);
    }
    var nr = w.doc.createRange(); nr.selectNodeContents(el); sel.removeAllRanges(); sel.addRange(nr);
    rememberRange(); changed();
  }
  function alignSel(dir) {
    if (w.srcMode || !w.doc) { return; }
    w.root.focus();
    var sel = restoreSelection();
    if (!sel.rangeCount) { return; }
    var range = sel.getRangeAt(0);
    var blk = blockAncestor(range.commonAncestorContainer);
    if (!blk) {
      if (range.collapsed) { DS.toast("Select text in a paragraph first"); return; }
      blk = w.doc.createElement("div"); blk.appendChild(range.extractContents()); range.insertNode(blk);
    }
    blk.style.textAlign = dir;
    var nr = w.doc.createRange(); nr.selectNodeContents(blk); sel.removeAllRanges(); sel.addRange(nr);
    rememberRange(); changed();
  }
  function listSel(ordered) {
    if (w.srcMode || !w.doc) { return; }
    w.root.focus();
    var sel = restoreSelection();
    if (!sel.rangeCount) { return; }
    var range = sel.getRangeAt(0);
    var li = closestTag(range.commonAncestorContainer, ["LI"]);
    if (li) {
      var list = li.parentNode, p = w.doc.createElement("p");
      while (li.firstChild) { p.appendChild(li.firstChild); }
      list.parentNode.insertBefore(p, list.nextSibling); li.parentNode.removeChild(li);
      if (!list.querySelector("li")) { list.parentNode.removeChild(list); }
      var pr = w.doc.createRange(); pr.selectNodeContents(p); sel.removeAllRanges(); sel.addRange(pr);
      rememberRange(); changed(); return;
    }
    var listEl = w.doc.createElement(ordered ? "ol" : "ul"), item = w.doc.createElement("li");
    var blk = blockAncestor(range.commonAncestorContainer);
    if (blk) { while (blk.firstChild) { item.appendChild(blk.firstChild); } listEl.appendChild(item); blk.parentNode.replaceChild(listEl, blk); }
    else {
      if (range.collapsed) { DS.toast("Select the text to list first"); return; }
      item.appendChild(range.extractContents()); listEl.appendChild(item); range.insertNode(listEl);
    }
    var nr = w.doc.createRange(); nr.selectNodeContents(item); sel.removeAllRanges(); sel.addRange(nr);
    rememberRange(); changed();
  }

  // ---- columns ---------------------------------------------------------
  function colsAncestor(node) {
    var el = node && (node.nodeType === 1 ? node : node.parentElement);
    while (el && el !== w.root) { if (el.classList && el.classList.contains("ds-cols")) { return el; } el = el.parentElement; }
    return null;
  }
  function addColumn() {
    if (w.srcMode || !w.doc) { return; }
    w.root.focus();
    var sel = restoreSelection();
    var node = sel.rangeCount ? sel.getRangeAt(0).commonAncestorContainer : w.root;
    var grid = colsAncestor(node);
    if (grid) {
      var col = w.doc.createElement("div"); col.className = "ds-col-cell"; col.appendChild(w.doc.createElement("br"));
      grid.appendChild(col);
      grid.style.gridTemplateColumns = repeatFr(countCols(grid));
    } else {
      grid = w.doc.createElement("div"); grid.className = "ds-cols";
      grid.style.display = "grid"; grid.style.gap = "20px"; grid.style.gridTemplateColumns = "1fr 1fr";
      var c1 = w.doc.createElement("div"); c1.className = "ds-col-cell";
      var blk = blockAncestor(node);
      if (blk && blk !== w.root) { blk.parentNode.insertBefore(grid, blk); c1.appendChild(blk); }
      else { c1.appendChild(w.doc.createElement("p")); w.root.appendChild(grid); }
      var c2 = w.doc.createElement("div"); c2.className = "ds-col-cell"; c2.appendChild(w.doc.createElement("br"));
      grid.appendChild(c1); grid.appendChild(c2);
    }
    injectSplitters(grid);
    changed();
  }
  function countCols(grid) { return grid.querySelectorAll(":scope > .ds-col-cell").length; }
  function repeatFr(n) { var a = []; for (var i = 0; i < n; i++) { a.push("1fr"); } return a.join(" "); }
  function removeColumn() {
    if (w.srcMode || !w.doc) { return; }
    var sel = restoreSelection();
    var node = sel.rangeCount ? sel.getRangeAt(0).commonAncestorContainer : w.root;
    var grid = colsAncestor(node);
    if (!grid) { DS.toast("Put the cursor inside a column first"); return; }
    var cells = grid.querySelectorAll(":scope > .ds-col-cell");
    if (cells.length <= 1) { unwrap(grid); changed(); return; }
    cells[cells.length - 1].remove();
    grid.style.gridTemplateColumns = repeatFr(countCols(grid));
    injectSplitters(grid); changed();
  }
  // Draggable splitters between columns; they are view-only (stripped on save).
  function injectSplitters(grid) {
    grid.querySelectorAll(":scope > .ds-colsplit").forEach(function (s) { s.remove(); });
    var cells = Array.prototype.slice.call(grid.querySelectorAll(":scope > .ds-col-cell"));
    for (var i = 0; i < cells.length - 1; i++) {
      var h = w.doc.createElement("div");
      h.className = "ds-colsplit"; h.setAttribute("contenteditable", "false"); h.title = "Drag to resize";
      cells[i].parentNode.insertBefore(h, cells[i].nextSibling);
      bindSplitter(grid, h);
    }
  }
  function bindSplitter(grid, handle) {
    handle.addEventListener("mousedown", function (e) {
      e.preventDefault();
      var rect = grid.getBoundingClientRect();
      var cells = Array.prototype.slice.call(grid.querySelectorAll(":scope > .ds-col-cell"));
      var idx = 0, prev = handle.previousElementSibling;
      cells.forEach(function (c, i) { if (c === prev) { idx = i; } });
      function onMove(ev) {
        var total = rect.width || 1;
        var fr = cells.map(function (c) { return c.getBoundingClientRect().width / total; });
        var deltaPx = ev.clientX - (prev.getBoundingClientRect().right);
        var d = deltaPx / total;
        fr[idx] = Math.max(0.1, fr[idx] + d);
        fr[idx + 1] = Math.max(0.1, fr[idx + 1] - d);
        grid.style.gridTemplateColumns = fr.map(function (x) { return x.toFixed(3) + "fr"; }).join(" ");
      }
      function onUp() { w.doc.removeEventListener("mousemove", onMove); w.doc.removeEventListener("mouseup", onUp); changed(); }
      w.doc.addEventListener("mousemove", onMove); w.doc.addEventListener("mouseup", onUp);
    });
  }

  // ---- link dialog -----------------------------------------------------
  function currentAnchor() {
    try {
      var n = w.savedRange ? w.savedRange.startContainer : null;
      n = n && (n.nodeType === 1 ? n : n.parentElement);
      return n && n.closest ? n.closest("a") : null;
    } catch (e) { return null; }
  }
  function openLinkDialog() {
    if (w.srcMode) { DS.toast("Switch off the HTML view to use the link tool"); return; }
    if (!w.doc) { return; }
    rememberRange();
    var sel = w.win.getSelection(), txt = sel ? sel.toString() : "", a = currentAnchor();
    var destGroups = [
      ["Fixed pages", FIXED_PAGES],
      ["Sections", (w.sections || []).map(function (s) { return [s.href, s.name]; })],
      ["Dynamic fields", (BLOCK_HREFS[w.type] || []).concat([GENERIC_HREF])]
    ];
    var optsHtml = '<option value="">Choose a destination…</option>' + destGroups.map(function (g) {
      if (!g[1] || !g[1].length) { return ""; }
      return '<optgroup label="' + DS.esc(g[0]) + '">' + g[1].map(function (p) {
        return '<option value="' + DS.esc(p[0]) + '">' + DS.esc(p[1]) + '</option>';
      }).join("") + '</optgroup>';
    }).join("");
    var ov = document.createElement("div"); ov.className = "ds-dlg-ov";
    var d = document.createElement("div"); d.className = "ds-dlg";
    d.innerHTML =
      '<h3>Add a link</h3>' +
      '<p class="ds-dlg-say">' + (a ? "Editing the selected link." : (txt ? "Linking: “" + DS.esc(txt) + "”" : "Select some text first, then choose a destination.")) + '</p>' +
      '<label class="ds-f"><span>Link to</span><select id="lkDest">' + optsHtml + '</select></label>' +
      '<label class="ds-f"><span>Web address</span><input type="text" id="lkUrl" placeholder="/design/…   https://…   or   {{section_href}}"></label>' +
      '<label class="ds-lk-chk"><input type="checkbox" id="lkNew"> Open in a new tab</label>' +
      '<div class="ds-dlg-actions">' +
        '<button class="ds-btn ds-btn-ghost" data-a="cancel">Cancel</button>' +
        (a ? '<button class="ds-btn ds-btn-danger" data-a="remove">Remove link</button>' : '') +
        '<button class="ds-btn ds-btn-primary" data-a="apply">Apply link</button>' +
      '</div>';
    ov.appendChild(d); document.body.appendChild(ov);   // dialog INSIDE the overlay so it sits in front
    var urlI = d.querySelector("#lkUrl"), destI = d.querySelector("#lkDest"), newI = d.querySelector("#lkNew");
    urlI.value = a ? (a.getAttribute("href") || "") : "";
    newI.checked = a ? (a.getAttribute("target") === "_blank") : false;
    destI.addEventListener("change", function () { if (destI.value) { urlI.value = destI.value; } });
    function close() { ov.remove(); if (w.root) { w.root.focus(); } }
    ov.addEventListener("click", function (e) { if (e.target === ov) { close(); } });
    d.querySelector('[data-a="cancel"]').addEventListener("click", close);
    if (a) { d.querySelector('[data-a="remove"]').addEventListener("click", function () { applyLink(true, "", false); close(); }); }
    d.querySelector('[data-a="apply"]').addEventListener("click", function () {
      if (applyLink(false, urlI.value.trim(), newI.checked)) { close(); }
    });
    setTimeout(function () { urlI.focus(); }, 30);
  }
  function applyLink(remove, url, newTab) {
    if (!w.doc) { return true; }
    w.root.focus();
    var sel = w.win.getSelection();
    if (w.savedRange) { sel.removeAllRanges(); sel.addRange(w.savedRange); }
    var a = currentAnchor();
    if (remove) { if (a) { unwrap(a); } changed(); return true; }
    if (!url) { DS.toast("Enter a web address or choose a destination"); return false; }
    if (a) {
      a.setAttribute("href", url);
      if (newTab) { a.setAttribute("target", "_blank"); a.setAttribute("rel", "noopener"); }
      else { a.removeAttribute("target"); a.removeAttribute("rel"); }
    } else {
      if (!sel.rangeCount || sel.isCollapsed) { DS.toast("Select the text you want to link first"); return false; }
      var r = sel.getRangeAt(0), frag = r.extractContents(), link = w.doc.createElement("a");
      link.setAttribute("href", url);
      if (newTab) { link.setAttribute("target", "_blank"); link.setAttribute("rel", "noopener"); }
      link.appendChild(frag); r.insertNode(link);
      var nr = w.doc.createRange(); nr.selectNodeContents(link); sel.removeAllRanges(); sel.addRange(nr);
    }
    rememberRange(); changed(); return true;
  }

  // ---- source view + insert token --------------------------------------
  function insertToken(raw) {
    if (w.srcMode) {
      var ta = w.src, p = ta.selectionStart || 0;
      ta.value = ta.value.slice(0, p) + raw + ta.value.slice(ta.selectionEnd || p);
      ta.focus(); ta.selectionStart = ta.selectionEnd = p + raw.length; changed(); return;
    }
    if (!w.doc) { return; }
    w.root.focus();
    var sel = w.win.getSelection();
    if (w.savedRange) { sel.removeAllRanges(); sel.addRange(w.savedRange); }
    if (!sel.rangeCount) { w.root.appendChild(makeTok(raw, w.doc)); changed(); return; }
    var r = sel.getRangeAt(0); r.deleteContents();
    var tok = makeTok(raw, w.doc); r.insertNode(tok);
    r.setStartAfter(tok); r.collapse(true); sel.removeAllRanges(); sel.addRange(r);
    rememberRange(); changed();
  }
  function toggleSource() {
    if (!w.srcMode) {
      w.src.value = templateHtml();
      w.src.style.display = ""; w.frame.style.display = "none"; w.srcMode = true;
      w.srcBtn.classList.add("ds-on"); w.src.focus();
    } else {
      var raw = w.src.value;
      w.srcMode = false; w.srcBtn.classList.remove("ds-on");
      setHtml(raw + (w.styleTag ? "\n" + w.styleTag : "")); changed();
    }
  }

  // ---- serialise -------------------------------------------------------
  function templateHtml() {
    if (!w.root) { return ""; }
    var clone = w.root.cloneNode(true);
    clone.querySelectorAll(".dl-tok").forEach(function (t) {
      t.parentNode.replaceChild(w.doc.createTextNode(t.getAttribute("data-tok") || ""), t);
    });
    clone.querySelectorAll(".ds-colsplit").forEach(function (s) { s.remove(); });
    clone.querySelectorAll("img[data-dl-src]").forEach(function (img) {
      img.setAttribute("src", img.getAttribute("data-dl-src"));
      if (img.hasAttribute("data-dl-alt")) { img.setAttribute("alt", img.getAttribute("data-dl-alt")); }
      img.removeAttribute("data-dl-src"); img.removeAttribute("data-dl-alt");
      img.classList.remove("dl-tplimg"); if (!img.getAttribute("class")) { img.removeAttribute("class"); }
    });
    clone.querySelectorAll("[contenteditable]").forEach(function (e) { e.removeAttribute("contenteditable"); });
    return clone.innerHTML.trim();
  }
  function getTemplate() { var html = w.srcMode ? w.src.value.trim() : templateHtml(); return html + (w.styleTag ? "\n" + w.styleTag : ""); }
  function changed() {
    rememberRange();
    clearTimeout(changeTimer);
    changeTimer = setTimeout(function () { if (w.onChange) { w.onChange(getTemplate()); } }, 500);
  }

  // ---- load ------------------------------------------------------------
  function setHtml(tpl) {
    w.srcMode = false;
    if (w.srcBtn) { w.srcBtn.classList.remove("ds-on"); }
    var parts = splitStyle(tpl); w.styleTag = parts.style;
    var skeleton =
      '<!doctype html><html><head><meta charset="utf-8">' +
      '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Lora:wght@400;700&family=Merriweather&family=Inter:wght@400;600;700&display=swap">' +
      '<link rel="stylesheet" href="https://' + w.host + '/design/assets/design.css">' +
      '<style>' +
        'html,body{margin:0}body{padding:18px;background:#fff;color:#111}' +
        '.dl-edit-root{min-height:200px;outline:none}' +
        '.dl-tok{font:inherit;white-space:nowrap}' +
        '.dl-tok-field{color:#6b7a90;text-decoration:underline dotted #b6c0cf;text-underline-offset:3px;cursor:help}' +
        '.dl-tok-loop,.dl-tok-cond,.dl-tok-end{display:none}' +
        '.dl-tplimg{width:44px;height:30px;border:1px solid #cdd7e5;border-radius:4px;vertical-align:middle;object-fit:cover;opacity:.75}' +
        '.dl-edit-root a{color:#2563eb;text-decoration:underline}' +
        '.ds-cols{position:relative}' +
        '.ds-col-cell{min-height:24px;outline:1px dashed #e2e8f0;outline-offset:2px}' +
        '.ds-colsplit{cursor:col-resize;background:#dbe6ff;width:6px;border-radius:3px;margin:0 -3px;position:relative;z-index:2}' +
        '.ds-colsplit:hover{background:#2563eb}' +
        '.ds-he-hl{outline:2px solid #2563eb !important;outline-offset:2px}' +
        '::selection{background:#bfdbfe}' +
      '</style></head><body><div class="dl-edit-root" contenteditable="true"></div></body></html>';
    w.frame.onload = function () {
      w.doc = w.frame.contentDocument; w.win = w.frame.contentWindow;
      w.root = w.doc.querySelector(".dl-edit-root");
      w.root.innerHTML = parts.html || "";
      tokenizeDom(w.root, w.doc);
      softenTemplateImages(w.root);
      w.root.querySelectorAll(".ds-cols").forEach(injectSplitters);
      try { w.doc.execCommand("defaultParagraphSeparator", false, "p"); } catch (e) {}
      w.root.addEventListener("input", changed);
      w.root.addEventListener("keyup", rememberRange);
      w.root.addEventListener("mouseup", rememberRange);
      // Click a placeholder to SELECT it, so font / size / colour apply to it
      // (its text still can't be edited).
      w.root.addEventListener("click", function (e) {
        var tk = e.target && e.target.closest ? e.target.closest(".dl-tok") : null;
        if (tk) { selectNode(tk); }
      });
      // Native drag of content reflows the editor into a mess; allow only the
      // column resize handles to be dragged.
      w.root.addEventListener("dragstart", function (e) {
        if (!(e.target && e.target.classList && e.target.classList.contains("ds-colsplit"))) { e.preventDefault(); }
      });
      w.src.style.display = "none"; w.frame.style.display = "";
    };
    w.frame.srcdoc = skeleton;
  }

  // ---- toolbar ---------------------------------------------------------
  function buildToolbar(tb) {
    function btn(html, title, fn, cls) {
      var b = document.createElement("button");
      b.type = "button"; b.className = "ds-he-btn" + (cls ? " " + cls : ""); b.title = title; b.innerHTML = html;
      b.addEventListener("mousedown", function (e) { e.preventDefault(); });
      b.addEventListener("click", function (e) { e.preventDefault(); fn(); });
      tb.appendChild(b); return b;
    }
    function sep() { var s = document.createElement("span"); s.className = "ds-he-sep"; tb.appendChild(s); }
    function sel(title, opts, onChange) {
      var s = document.createElement("select"); s.className = "ds-he-sel"; s.title = title;
      opts.forEach(function (o) { var op = document.createElement("option"); op.value = o[0]; op.textContent = o[1]; s.appendChild(op); });
      s.addEventListener("mousedown", function () { rememberRange(); });
      s.addEventListener("change", function () { if (s.value !== "") { onChange(s.value); } s.value = ""; });
      tb.appendChild(s); return s;
    }
    sel("Paragraph / heading", [["", "Format"], ["p", "Paragraph"], ["h1", "Heading 1"], ["h2", "Heading 2"], ["h3", "Heading 3"], ["h4", "Heading 4"], ["blockquote", "Quote"]], formatBlockSel);
    sep();
    btn("<b>B</b>", "Bold", function () { wrapInline("strong", ["STRONG", "B"]); });
    btn("<i>I</i>", "Italic", function () { wrapInline("em", ["EM", "I"]); });
    btn("<u>U</u>", "Underline", function () { wrapInline("u", ["U"]); });
    btn("<s>S</s>", "Strikethrough", function () { wrapInline("s", ["S", "STRIKE", "DEL"]); });
    sep();
    sel("Font", FONTS, function (v) { wrapInlineStyle("fontFamily", v); });
    sel("Text size", SIZES, function (v) { wrapInlineStyle("fontSize", v); });
    // colour pickers
    var cText = colourControl("Text colour", "A", function (v) { wrapInlineStyle("color", v); });
    var cBg = colourControl("Highlight colour", "▣", function (v) { wrapInlineStyle("backgroundColor", v); });
    tb.appendChild(cText); tb.appendChild(cBg); sep();
    btn("&bull; List", "Bulleted list", function () { listSel(false); });
    btn("1. List", "Numbered list", function () { listSel(true); });
    btn("&#9776;&larr;", "Align left", function () { alignSel("left"); });
    btn("&#9776;", "Align centre", function () { alignSel("center"); });
    btn("&rarr;&#9776;", "Align right", function () { alignSel("right"); });
    sep();
    btn("&#128279; Link", "Turn the selected text into a link", openLinkDialog, "ds-he-link");
    btn("Unlink", "Remove the link", function () { if (!w.doc || w.srcMode) { return; } var a = currentAnchor(); if (a) { unwrap(a); changed(); } });
    sep();
    w.insSel = sel("Insert a placeholder (auto-filled on the live site)", [["", "Placeholder…"]], insertToken);
    sep();
    btn("&#9707; Columns", "Add a column", addColumn);
    btn("&minus; Column", "Remove the last column", removeColumn);
    sep();
    btn("&#10005; Clear", "Clear formatting", clearFormat);
    w.srcBtn = btn("&lt;/&gt; HTML", "Edit the raw HTML", toggleSource, "ds-he-src");
  }
  function colourControl(title, glyph, onPick) {
    var wrap = document.createElement("label"); wrap.className = "ds-he-colour"; wrap.title = title;
    wrap.innerHTML = '<span>' + glyph + '</span>';
    var inp = document.createElement("input"); inp.type = "color";
    inp.addEventListener("mousedown", function () { rememberRange(); });
    inp.addEventListener("input", function () { onPick(inp.value); });
    wrap.appendChild(inp); return wrap;
  }
  function fillInsertMenu(type) {
    if (!w.insSel) { return; }
    w.insSel.innerHTML = "";
    var first = document.createElement("option"); first.value = ""; first.textContent = "Placeholder…"; w.insSel.appendChild(first);
    var og1 = document.createElement("optgroup"); og1.label = "This block";
    fieldsFor(type).forEach(function (f) { var o = document.createElement("option"); o.value = f; o.textContent = tokInfo(f).text; og1.appendChild(o); });
    if (og1.children.length) { w.insSel.appendChild(og1); }
    var og2 = document.createElement("optgroup"); og2.label = "General";
    GLOBAL_FIELDS.forEach(function (p) { var o = document.createElement("option"); o.value = p[0]; o.textContent = p[1]; og2.appendChild(o); });
    w.insSel.appendChild(og2);
  }

  // ---- public API ------------------------------------------------------
  DS.HtmlEditor = {
    mount: function (container, opts) {
      opts = opts || {};
      w.host = opts.host || (DS.ds && DS.ds.host) || "";
      w.type = opts.type || "";
      w.onChange = null; w.srcMode = false; w.savedRange = null;
      container.innerHTML =
        '<div class="ds-he">' +
          '<div class="ds-he-tb" id="dsHeTb"></div>' +
          '<div class="ds-he-hint">Grey dotted text is filled in automatically on the live site — you can colour and resize it, but not retype it. Everything else you can click and edit.</div>' +
          '<div class="ds-he-stage">' +
            '<iframe class="ds-he-frame" id="dsHeFrame" title="Block editor"></iframe>' +
            '<textarea class="ds-he-src" id="dsHeSrc" spellcheck="false" style="display:none"></textarea>' +
          '</div>' +
        '</div>';
      w.frame = container.querySelector("#dsHeFrame");
      w.src = container.querySelector("#dsHeSrc");
      w.src.addEventListener("input", changed);
      buildToolbar(container.querySelector("#dsHeTb"));
      fillInsertMenu(w.type);
      // live section list for the link picker
      DS.api("link_targets", {}).then(function (j) { w.sections = j.sections || []; }).catch(function () { w.sections = []; });
    },
    setHtml: setHtml,
    getHtml: getTemplate,
    onChange: function (fn) { w.onChange = fn; },
    focusElement: function (selector) {
      if (!w.root || !selector) { return; }
      try {
        var elx = w.root.querySelector(selector);
        if (!elx) { return; }
        elx.scrollIntoView({ block: "center" });
        elx.classList.add("ds-he-hl");
        setTimeout(function () { elx.classList.remove("ds-he-hl"); }, 1400);
        var r = w.doc.createRange(); r.selectNodeContents(elx);
        var sel = w.win.getSelection(); sel.removeAllRanges(); sel.addRange(r); rememberRange();
      } catch (e) {}
    }
  };
})();
