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
            insSel: null, srcBtn: null, onChange: null, container: null,
            inspector: null, selEl: null };
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

  var FONTS = [["", "Font"], ['"Playfair Display", Georgia, serif', "Playfair Display"], ['"Lora", Georgia, serif', "Lora (headlines)"], ['"Merriweather", Georgia, serif', "Merriweather (body)"],
    ['"Inter", system-ui, sans-serif', "Inter (UI)"], ['"Public Sans", sans-serif', "Public Sans"], ["Georgia, serif", "Georgia"],
    ['"Times New Roman", serif', "Times"], ["Arial, sans-serif", "Arial"], ['"Helvetica Neue", Helvetica, sans-serif', "Helvetica"],
    ["Verdana, sans-serif", "Verdana"], ['"Courier New", monospace', "Courier"]];
  var SIZES = [["", "Size"], ["12px", "12"], ["14px", "14"], ["16px", "16"], ["18px", "18"], ["20px", "20"],
    ["24px", "24"], ["28px", "28"], ["32px", "32"], ["40px", "40"], ["48px", "48"], ["64px", "64"]];
  var WEIGHTS = [["", "Weight"], ["300", "Light"], ["400", "Normal"], ["500", "Medium"], ["600", "Semibold"], ["700", "Bold"], ["800", "Extra bold"]];
  var LINEHEIGHTS = [["", "Line height"], ["1", "1.0"], ["1.2", "1.2"], ["1.4", "1.4"], ["1.6", "1.6"], ["1.8", "1.8"], ["2", "2.0"]];

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
  // Apply a style to the nearest block (e.g. line-height).
  function blockStyle(prop, val) {
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
    blk.style[prop] = val;
    var nr = w.doc.createRange(); nr.selectNodeContents(blk); sel.removeAllRanges(); sel.addRange(nr);
    rememberRange(); changed();
  }
  function insertNodeAtCaret(node) {
    w.root.focus();
    var sel = restoreSelection();
    if (sel && sel.rangeCount) {
      var r = sel.getRangeAt(0); r.collapse(false); r.insertNode(node);
      r.setStartAfter(node); r.collapse(true); sel.removeAllRanges(); sel.addRange(r);
    } else { w.root.appendChild(node); }
    rememberRange(); changed();
  }
  function insertHR() { if (w.srcMode || !w.doc) { return; } insertNodeAtCaret(w.doc.createElement("hr")); }
  // Add a brand-new element (text, heading, line break, link, button, image,
  // divider or box) at the cursor.
  function insertElement(type) {
    if (w.srcMode || !w.doc) { return; }
    var el = null;
    switch (type) {
      case "p": el = w.doc.createElement("p"); el.textContent = "New text"; break;
      case "h2": el = w.doc.createElement("h2"); el.textContent = "New heading"; break;
      case "br": insertNodeAtCaret(w.doc.createElement("br")); return;
      case "a": el = w.doc.createElement("a"); el.setAttribute("href", "#"); el.textContent = "New link"; break;
      case "button": el = w.doc.createElement("button"); el.textContent = "Button"; break;
      case "subscribe":
        el = w.doc.createElement("a"); el.className = "dl-subscribe"; el.setAttribute("href", "/subscribe");
        el.textContent = "{{subscribe_label}}";
        insertNodeAtCaret(el); tokenizeDom(el, w.doc); selectElement(el); return;
      case "img": el = w.doc.createElement("img"); el.setAttribute("src", IMG_PH); el.setAttribute("alt", "Image"); el.style.maxWidth = "100%"; el.style.height = "auto"; break;
      case "hr": el = w.doc.createElement("hr"); break;
      case "div": el = w.doc.createElement("div"); el.textContent = "New box"; break;
      default: return;
    }
    insertNodeAtCaret(el);
    selectElement(el);   // select it so it can be styled / typed into straight away
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
    changed(); mountGridHandles(grid);
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
    changed(); mountGridHandles(grid);
  }
  // Remove all drag handles from the document (they are view-only, stripped on save).
  function clearGridHandles() {
    if (!w.doc) { return; }
    w.doc.querySelectorAll(".ds-colsplit").forEach(function (s) { s.remove(); });
  }
  // Overlay a draggable divider over each column boundary of ANY grid, so the
  // user can drag to resize the columns of a real multi-column block.
  function mountGridHandles(grid) {
    clearGridHandles();
    if (!grid || !w.win) { return; }
    var cs = w.win.getComputedStyle(grid);
    if (cs.display.indexOf("grid") === -1) { return; }
    var tracks = cs.gridTemplateColumns.split(/\s+/).map(parseFloat).filter(function (x) { return !isNaN(x); });
    if (tracks.length < 2) { return; }
    var gap = parseFloat(cs.columnGap || cs.gap || "0") || 0;
    var padL = parseFloat(cs.paddingLeft) || 0, padT = parseFloat(cs.paddingTop) || 0, padB = parseFloat(cs.paddingBottom) || 0;
    if (cs.position === "static") { grid.style.position = "relative"; }
    var x = padL;
    for (var i = 0; i < tracks.length - 1; i++) {
      x += tracks[i];
      var h = w.doc.createElement("div");
      h.className = "ds-colsplit"; h.setAttribute("contenteditable", "false"); h.title = "Drag to resize columns";
      h.style.cssText = "position:absolute;top:" + padT + "px;left:" + (x + gap / 2 - 3) + "px;width:6px;height:calc(100% - " + (padT + padB) + "px);z-index:50;";
      h.dataset.left = i; h.dataset.right = i + 1;
      grid.appendChild(h);
      bindGridSplitter(grid, h);
      x += gap;
    }
  }
  function bindGridSplitter(grid, handle) {
    handle.addEventListener("mousedown", function (e) {
      e.preventDefault(); e.stopPropagation();
      var li = parseInt(handle.dataset.left, 10), ri = parseInt(handle.dataset.right, 10);
      var startX = e.clientX;
      var widths = w.win.getComputedStyle(grid).gridTemplateColumns.split(/\s+/).map(parseFloat);
      var total = widths.reduce(function (a, b) { return a + b; }, 0) || 1;
      function move(ev) {
        var dx = ev.clientX - startX;
        var nw = widths.slice();
        nw[li] = Math.max(20, widths[li] + dx);
        nw[ri] = Math.max(20, widths[ri] - dx);
        grid.style.gridTemplateColumns = nw.map(function (px) { return (px / total).toFixed(4) + "fr"; }).join(" ");
      }
      function up() { w.doc.removeEventListener("mousemove", move); w.doc.removeEventListener("mouseup", up); mountGridHandles(grid); changed(); }
      w.doc.addEventListener("mousemove", move); w.doc.addEventListener("mouseup", up);
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
      if (w.container) { w.container.classList.add("ds-he-src-mode"); }
      w.src.focus();
    } else {
      var raw = w.src.value;
      w.srcMode = false;
      if (w.container) { w.container.classList.remove("ds-he-src-mode"); }
      setHtml(raw + (w.styleTag ? "\n" + w.styleTag : "")); changed();
    }
    return w.srcMode;
  }

  // ---- serialise -------------------------------------------------------
  function templateHtml() {
    if (!w.root) { return ""; }
    var clone = w.root.cloneNode(true);
    clone.querySelectorAll(".dl-tok").forEach(function (t) {
      t.parentNode.replaceChild(w.doc.createTextNode(t.getAttribute("data-tok") || ""), t);
    });
    clone.querySelectorAll(".ds-colsplit").forEach(function (s) { s.remove(); });
    clone.querySelectorAll(".ds-he-sel, .ds-he-hl").forEach(function (e) {
      e.classList.remove("ds-he-sel"); e.classList.remove("ds-he-hl");
      if (!e.getAttribute("class")) { e.removeAttribute("class"); }
    });
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
    changeTimer = setTimeout(function () { if (w.onChange) { w.onChange(getTemplate()); } }, 350);
  }

  // ---- load ------------------------------------------------------------
  function setHtml(tpl) {
    w.srcMode = false;
    if (w.container) { w.container.classList.remove("ds-he-src-mode"); }
    if (w.srcBtn) { w.srcBtn.classList.remove("ds-on"); w.srcBtn.innerHTML = "&lt;/&gt; HTML"; }
    var parts = splitStyle(tpl); w.styleTag = parts.style;
    var skeleton =
      '<!doctype html><html><head><meta charset="utf-8">' +
      '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,500;0,600;0,700;0,800;0,900;1,400;1,600&family=Lora:wght@400;700&family=Merriweather&family=Inter:wght@400;600;700&display=swap">' +
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
        '.ds-colsplit{background:rgba(37,99,235,.45);border-radius:3px;cursor:col-resize}' +
        '.ds-colsplit:hover{background:#2563eb}' +
        '.ds-he-hl{outline:2px solid #2563eb !important;outline-offset:2px}' +
        '.ds-he-sel{outline:2px solid #2563eb !important;outline-offset:1px}' +
        '::selection{background:#bfdbfe}' +
      '</style></head><body><div class="dl-edit-root" contenteditable="true"></div></body></html>';
    w.frame.onload = function () {
      w.doc = w.frame.contentDocument; w.win = w.frame.contentWindow;
      w.root = w.doc.querySelector(".dl-edit-root");
      w.root.innerHTML = parts.html || "";
      w.selEl = null; w.gridEl = null;
      tokenizeDom(w.root, w.doc);
      softenTemplateImages(w.root);
      try { w.doc.execCommand("defaultParagraphSeparator", false, "p"); } catch (e) {}
      w.root.addEventListener("input", changed);
      w.root.addEventListener("keyup", rememberRange);
      w.root.addEventListener("mouseup", rememberRange);
      // Click an element to SELECT it for the style inspector; clicking a
      // placeholder also range-selects it so the toolbar can style it.
      w.root.addEventListener("click", function (e) {
        var elx = e.target;
        if (elx && elx.classList && elx.classList.contains("ds-colsplit")) { return; }
        selectElement(elx);
        var tk = e.target && e.target.closest ? e.target.closest(".dl-tok") : null;
        if (tk) { selectNode(tk); }
      });
      // Reliable new lines: Enter inserts a paragraph, Shift+Enter a line break,
      // even when the caret sits next to protected placeholders.
      w.root.addEventListener("keydown", function (e) {
        if (e.key === "Enter") {
          e.preventDefault();
          var sel = w.win.getSelection();
          if (!sel.rangeCount) { return; }
          var node;
          if (e.shiftKey) { node = w.doc.createElement("br"); }
          else { node = w.doc.createElement("p"); node.appendChild(w.doc.createElement("br")); }
          var r = sel.getRangeAt(0); r.deleteContents(); r.insertNode(node);
          var nr = w.doc.createRange();
          if (node.tagName === "P") { nr.setStart(node, 0); } else { nr.setStartAfter(node); }
          nr.collapse(true); sel.removeAllRanges(); sel.addRange(nr);
          rememberRange(); changed();
        }
      });
      syncToolbar();
      // Native drag of content reflows the editor into a mess; allow only the
      // column resize handles to be dragged.
      w.root.addEventListener("dragstart", function (e) {
        if (!(e.target && e.target.classList && e.target.classList.contains("ds-colsplit"))) { e.preventDefault(); }
      });
      w.src.style.display = "none"; w.frame.style.display = "";
    };
    w.frame.srcdoc = skeleton;
  }

  // ---- toolbar (single style area; reflects the selected element) -------
  function buildToolbar(tb) {
    var c = w.ctl = {};
    function btn(html, title, fn, cls) {
      var b = document.createElement("button");
      b.type = "button"; b.className = "ds-he-btn" + (cls ? " " + cls : ""); b.title = title; b.innerHTML = html;
      b.addEventListener("mousedown", function (e) { e.preventDefault(); });
      b.addEventListener("click", function (e) { e.preventDefault(); fn(); });
      tb.appendChild(b); return b;
    }
    function sep() { tb.appendChild(mk("span", "ds-he-sep")); }
    // action menu: runs onChange then resets to the first option
    function selAct(title, opts, onChange) {
      var s = document.createElement("select"); s.className = "ds-he-sel"; s.title = title;
      opts.forEach(function (o) { var op = document.createElement("option"); op.value = o[0]; op.textContent = o[1]; s.appendChild(op); });
      s.addEventListener("mousedown", function () { rememberRange(); });
      s.addEventListener("change", function () { if (s.value !== "") { onChange(s.value); } s.value = ""; });
      tb.appendChild(s); return s;
    }
    // value menu: keeps its value (reflects the element)
    function selRef(title, opts, onChange) {
      var s = document.createElement("select"); s.className = "ds-he-sel"; s.title = title;
      opts.forEach(function (o) { var op = document.createElement("option"); op.value = o[0]; op.textContent = o[1]; s.appendChild(op); });
      s.addEventListener("mousedown", function () { rememberRange(); });
      s.addEventListener("change", function () { onChange(s.value); });
      tb.appendChild(s); return s;
    }
    function numBox(title, ph, onInput, width) {
      var i = document.createElement("input"); i.type = "number"; i.className = "ds-he-num"; i.title = title; i.placeholder = ph || ""; if (width) { i.style.width = width; }
      i.addEventListener("mousedown", function () { rememberRange(); });
      i.addEventListener("input", function () { onInput(i.value); });
      tb.appendChild(i); return i;
    }
    function colBox(title, glyph, onInput) {
      var wrap = mk("label", "ds-he-colour"); wrap.title = title; wrap.innerHTML = "<span>" + glyph + "</span>";
      var i = document.createElement("input"); i.type = "color";
      i.addEventListener("mousedown", function () { rememberRange(); });
      i.addEventListener("input", function () { onInput(i.value); });
      wrap.appendChild(i); tb.appendChild(wrap); return i;
    }
    function lab(t) { tb.appendChild(mk("span", "ds-he-lab", t)); }

    w.srcBtn = btn("&lt;/&gt; HTML", "Switch between visual editing and the HTML code", function () {
      var src = toggleSource();
      w.srcBtn.classList.toggle("ds-on", src);
      w.srcBtn.innerHTML = src ? "&#9998; Visual" : "&lt;/&gt; HTML";
    });
    sep();
    selAct("Add a new element", [["", "+ Insert"], ["p", "Text paragraph"], ["h2", "Heading"], ["br", "Line break"], ["a", "Link"], ["button", "Button"], ["subscribe", "Subscribe button"], ["img", "Image"], ["hr", "Divider line"], ["div", "Box / container"]], insertElement);
    sep();
    c.format = selRef("Paragraph / heading", [["", "Format"], ["p", "Paragraph"], ["h1", "Heading 1"], ["h2", "Heading 2"], ["h3", "Heading 3"], ["h4", "Heading 4"], ["blockquote", "Quote"]], function (v) { if (v) { formatBlockSel(v); } });
    sep();
    btn("<b>B</b>", "Bold", function () { wrapInline("strong", ["STRONG", "B"]); });
    btn("<i>I</i>", "Italic", function () { wrapInline("em", ["EM", "I"]); });
    btn("<u>U</u>", "Underline", function () { wrapInline("u", ["U"]); });
    btn("<s>S</s>", "Strikethrough", function () { wrapInline("s", ["S", "STRIKE", "DEL"]); });
    btn("x<sup>2</sup>", "Superscript", function () { wrapInline("sup", ["SUP"]); });
    btn("x<sub>2</sub>", "Subscript", function () { wrapInline("sub", ["SUB"]); });
    sep();
    selAct("Font", FONTS, function (v) { styleTextOrEl("fontFamily", v); });
    lab("Size"); c.size = numBox("Text size (px)", "–", function (v) { styleTextOrEl("fontSize", v === "" ? "" : (v + "px")); }, "3.2em");
    c.weight = selRef("Font weight", WEIGHTS, function (v) { styleTextOrEl("fontWeight", v); });
    lab("Line"); c.lineh = numBox("Line height", "–", function (v) { styleEl("lineHeight", v === "" ? "" : v); }, "3em");
    c.textcolor = colBox("Text colour", "A", function (v) { styleTextOrEl("color", v); });
    c.bgcolor = colBox("Highlight colour", "&#9635;", function (v) { styleTextOrEl("backgroundColor", v); });
    sep();
    btn("&bull; List", "Bulleted list", function () { listSel(false); });
    btn("1. List", "Numbered list", function () { listSel(true); });
    btn("&#9776;&larr;", "Align left", function () { alignSel("left"); });
    btn("&#9776;", "Align centre", function () { alignSel("center"); });
    btn("&rarr;&#9776;", "Align right", function () { alignSel("right"); });
    btn("&#9776;&#9776;", "Justify", function () { alignSel("justify"); });
    sep();
    btn("&#128279; Link", "Turn the selected text into a link", openLinkDialog, "ds-he-link");
    btn("Unlink", "Remove the link", function () { if (!w.doc || w.srcMode) { return; } var a = currentAnchor(); if (a) { unwrap(a); changed(); } });
    btn("&#8213; Rule", "Insert a horizontal line", insertHR);
    sep();
    w.insSel = selAct("Insert a placeholder (auto-filled on the live site)", [["", "Placeholder…"]], insertToken);
    sep();
    lab("Margin"); c.margin = numBox("Margin — all sides (px)", "0", function (v) { styleElBox("margin", v === "" ? "" : (v + "px")); }, "3em");
    lab("Pad"); c.padding = numBox("Padding — all sides (px)", "0", function (v) { styleElBox("padding", v === "" ? "" : (v + "px")); }, "3em");
    lab("Width"); c.width = numBox("Width", "auto", function (v) { applyWidth(); }, "3.4em");
    c.widthUnit = (function () {
      var s = document.createElement("select"); s.className = "ds-he-unit";
      ["px", "%", "rem", "em", "auto"].forEach(function (u) { var o = document.createElement("option"); o.value = u; o.textContent = u; s.appendChild(o); });
      s.addEventListener("change", applyWidth); tb.appendChild(s); return s;
    })();
    sep();
    lab("Border"); c.bstyle = selRef("Border style", [["", "border"], ["none", "None"], ["solid", "Solid"], ["dashed", "Dashed"], ["dotted", "Dotted"], ["double", "Double"]], function (v) {
      styleEl("borderStyle", v); if (w.selEl && v && v !== "none" && !w.selEl.style.borderWidth) { w.selEl.style.borderWidth = "1px"; changed(); }
    });
    c.bcolor = colBox("Border colour", "&#9638;", function (v) { styleEl("borderColor", v); });
    sep();
    btn("&#9707; Add column", "Add a column", addColumn);
    btn("&minus; Column", "Remove the last column", removeColumn);
    c.cols = mk("span", "ds-he-cols"); c.cols.style.display = "none"; tb.appendChild(c.cols);
    sep();
    btn("&#128465; Delete element", "Delete the selected element", deleteSelected, "ds-he-del");
    btn("&#10005; Clear formatting", "Remove bold / italic / colour from the selected text", clearFormat);

    function applyWidth() {
      var u = c.widthUnit.value, v = c.width.value;
      if (u === "auto") { styleEl("width", "auto"); return; }
      styleEl("width", v === "" ? "" : (v + u));
    }
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

  // =====================================================================
  //  Element style inspector — click an element, edit its full box model,
  //  typography, border, background, layout and column widths. Current
  //  values are read from the rendered (computed) style so a title shows
  //  its real size/colour; edits are written as inline styles and reflected
  //  live in the editor (and in the Preview pane on the next save).
  // =====================================================================
  function esc(s) { return DS.esc(s); }
  function numOf(s) { var m = String(s == null ? "" : s).match(/-?\d*\.?\d+/); return m ? m[0] : ""; }
  function unitOf(s) { var m = String(s == null ? "" : s).match(/(px|%|rem|em|vh|vw)/); return m ? m[1] : ""; }
  function rgbToHex(c) {
    if (!c) { return "#000000"; }
    if (/^#([0-9a-f]{6})$/i.test(c)) { return c; }
    var m = String(c).match(/rgba?\((\d+),\s*(\d+),\s*(\d+)/i);
    if (!m) { return "#000000"; }
    function h(x) { x = parseInt(x, 10).toString(16); return x.length < 2 ? "0" + x : x; }
    return "#" + h(m[1]) + h(m[2]) + h(m[3]);
  }
  // Current value of a property on the selected element: inline style wins,
  // else the rendered (computed) value.
  function cv(prop, el) {
    el = el || w.selEl; if (!el) { return ""; }
    if (el.style && el.style[prop]) { return el.style[prop]; }
    try { return (w.win.getComputedStyle(el)[prop] || ""); } catch (e) { return ""; }
  }
  function friendlyEl(el) {
    var tag = (el.tagName || "").toLowerCase();
    var map = { h1: "heading", h2: "heading", h3: "subheading", h4: "subheading", p: "paragraph",
      a: "link", img: "image", ul: "list", ol: "list", li: "list item", button: "button",
      span: "text", div: "box", section: "section", header: "header", footer: "footer", nav: "navigation" };
    var name = map[tag] || tag || "element";
    var cls = el.className && typeof el.className === "string" ? el.className.split(/\s+/).filter(function (c) { return c && c !== "ds-he-sel" && c !== "ds-he-hl"; })[0] : "";
    return name + (cls ? " ." + cls : "");
  }
  function findGrid(el) {
    if (!el) { return null; }
    if (cv("display", el).indexOf("grid") !== -1) { return el; }
    var p = el.parentElement;
    if (p && p !== w.root && cv("display", p).indexOf("grid") !== -1) { return p; }
    return null;
  }

  function applyElTo(el, prop, val) { if (!el) { return; } try { el.style[prop] = val; } catch (e) {} changed(); }
  function applyEl(prop, val) { applyElTo(w.selEl, prop, val); }

  function selectElement(el, silent) {
    if (w.selEl) { try { w.selEl.classList.remove("ds-he-sel"); } catch (e) {} }
    w.selEl = (el && el !== w.root && el.nodeType === 1) ? el : null;
    if (w.selEl) { try { w.selEl.classList.add("ds-he-sel"); } catch (e) {} }
    w.gridEl = findGrid(w.selEl);
    syncToolbar();
    if (w.gridEl) { mountGridHandles(w.gridEl); renderColumnSliders(w.gridEl); } else { clearGridHandles(); renderColumnSliders(null); }
    if (!silent && w.onSelect) { try { w.onSelect(w.selEl ? elSelector(w.selEl) : ""); } catch (e) {} }
  }
  // A selector for an element, relative to the block, matching the one the
  // preview bridge posts (first dl-* class, else container+tag, else tag).
  function elSelector(el) {
    var tag = (el.tagName || "").toLowerCase(), node = el;
    while (node && node !== w.root) {
      if (node.classList) {
        var dl = [].slice.call(node.classList).find(function (c) {
          return c.indexOf("dl-") === 0 && c.indexOf("dl-block") !== 0 && c !== "dl-tok" && c.indexOf("dl-tok-") !== 0 && c !== "ds-he-sel";
        });
        if (dl) { return node === el ? ("." + dl) : ("." + dl + (tag ? " " + tag : "")); }
      }
      node = node.parentElement;
    }
    return tag;
  }
  // Select an element by a selector coming from the preview (clicking there).
  function selectBySelector(selector) {
    if (!w.root || !selector) { return; }
    try { var el = w.root.querySelector(selector); if (el) { el.scrollIntoView({ block: "center" }); selectElement(el, true); } } catch (e) {}
  }
  // Delete the selected element.
  function deleteSelected() {
    if (w.srcMode) { return; }
    if (!w.selEl) { DS.toast("Click an element to delete it first"); return; }
    var el = w.selEl, parent = el.parentNode;
    selectElement(null);
    if (parent) { parent.removeChild(el); }
    clearGridHandles(); changed();
  }

  // Apply a style to the current text selection (if any) or the selected element.
  function styleTextOrEl(prop, val) {
    var sel = w.win && w.win.getSelection();
    if (sel && sel.rangeCount && !sel.isCollapsed && w.root.contains(sel.anchorNode)) { wrapInlineStyle(prop, val); }
    else if (w.selEl) { w.selEl.style[prop] = val; changed(); }
    else { DS.toast("Select some text, or click an element first"); }
  }
  function styleEl(prop, val) { if (w.selEl) { w.selEl.style[prop] = val; changed(); } else { DS.toast("Click an element first"); } }
  function styleElBox(prefix, val) {
    if (!w.selEl) { DS.toast("Click an element first"); return; }
    ["Top", "Right", "Bottom", "Left"].forEach(function (s) { w.selEl.style[prefix + s] = val; });
    changed();
  }

  // Reflect the selected element's current (rendered) values into the toolbar.
  function syncToolbar() {
    var c = w.ctl; if (!c) { return; }
    var el = w.selEl;
    if (c.label) { c.label.textContent = el ? friendlyEl(el) : "nothing selected"; }
    if (!el || !w.win) {
      [c.size, c.weight, c.lineh, c.margin, c.padding, c.width, c.format, c.bstyle].forEach(function (x) { if (x) { x.value = ""; } });
      return;
    }
    var cs = w.win.getComputedStyle(el);
    if (c.size) { c.size.value = Math.round(parseFloat(cs.fontSize)) || ""; }
    if (c.weight) { var wt = String(cs.fontWeight); c.weight.value = (["100", "200", "300", "400", "500", "600", "700", "800", "900"].indexOf(wt) >= 0 ? wt : ""); }
    if (c.lineh) { var lh = cs.lineHeight; c.lineh.value = (lh === "normal" ? "" : (Math.round(parseFloat(lh) * 10) / 10) || ""); }
    if (c.textcolor) { c.textcolor.value = rgbToHex(cs.color); }
    if (c.bgcolor) { c.bgcolor.value = rgbToHex(cs.backgroundColor); }
    if (c.margin) { c.margin.value = pxNum(el.style.marginTop || cs.marginTop); }
    if (c.padding) { c.padding.value = pxNum(el.style.paddingTop || cs.paddingTop); }
    if (c.width) { var wd = el.style.width || ""; c.width.value = wd ? numOf(wd) : ""; if (c.widthUnit && wd) { c.widthUnit.value = unitOf(wd) || "px"; } }
    if (c.bstyle) { c.bstyle.value = (cs.borderStyle && cs.borderStyle !== "none" ? cs.borderStyle.split(" ")[0] : ""); }
    if (c.bcolor) { c.bcolor.value = rgbToHex(cs.borderTopColor || cs.borderColor); }
    if (c.format) { var tag = el.tagName.toLowerCase(); c.format.value = (["p", "h1", "h2", "h3", "h4", "blockquote"].indexOf(tag) >= 0 ? tag : ""); }
  }
  function pxNum(v) { var n = parseFloat(v); return (!n || isNaN(n)) ? "" : Math.round(n); }

  // Column width sliders (shown in the toolbar when a grid is selected).
  function renderColumnSliders(grid) {
    var host = w.ctl && w.ctl.cols; if (!host) { return; }
    host.innerHTML = "";
    if (!grid) { host.style.display = "none"; return; }
    var cs = w.win.getComputedStyle(grid);
    var widths = cs.gridTemplateColumns.split(/\s+/).map(parseFloat).filter(function (x) { return !isNaN(x); });
    if (widths.length < 2) { host.style.display = "none"; return; }
    host.style.display = "inline-flex";
    var total = widths.reduce(function (a, b) { return a + b; }, 0) || 1;
    host.appendChild(mk("span", "ds-he-colslab", "Columns:"));
    widths.forEach(function (px, i) {
      var wrap = mk("span", "ds-he-colsld");
      var sl = document.createElement("input");
      sl.type = "range"; sl.min = "1"; sl.max = "12"; sl.title = "Column " + (i + 1) + " width";
      sl.value = Math.max(1, Math.round(px / total * 12));
      sl.addEventListener("input", function () {
        var all = Array.prototype.map.call(host.querySelectorAll("input[type=range]"), function (s) { return (+s.value) + "fr"; });
        applyElTo(grid, "gridTemplateColumns", all.join(" "));
        mountGridHandles(grid);
      });
      wrap.appendChild(document.createTextNode((i + 1) + "")); wrap.appendChild(sl);
      host.appendChild(wrap);
    });
  }
  function mk(tag, cls, txt) { var n = document.createElement(tag); if (cls) { n.className = cls; } if (txt != null) { n.textContent = txt; } return n; }

  // ---- control builders ----
  function group(title, inner) { return '<details class="ds-insp-group" open><summary>' + esc(title) + '</summary><div class="ds-insp-body">' + inner + '</div></details>'; }
  function selRow(label, prop, opts) {
    var c = cv(prop);
    return '<label class="ds-insp-row"><span>' + esc(label) + '</span><select class="ds-insp-sel" data-prop="' + prop + '">' +
      opts.map(function (o) { return '<option value="' + esc(o[0]) + '"' + (o[0] === c ? " selected" : "") + '>' + esc(o[1]) + '</option>'; }).join("") +
      '</select></label>';
  }
  function colRow(label, prop) {
    var c = rgbToHex(cv(prop));
    return '<label class="ds-insp-row"><span>' + esc(label) + '</span><span class="ds-insp-col">' +
      '<input type="color" class="ds-insp-color" data-prop="' + prop + '" value="' + c + '">' +
      '<button type="button" class="ds-insp-clear" data-prop="' + prop + '" title="Remove">&#10005;</button></span></label>';
  }
  function lenRow(label, prop, units) {
    units = units || ["px", "%", "rem", "em", "auto"];
    var c = cv(prop), isAuto = (c === "auto" || c === ""), n = numOf(c), u = unitOf(c) || "px";
    return '<label class="ds-insp-row"><span>' + esc(label) + '</span><span class="ds-insp-len">' +
      '<input type="number" class="ds-insp-num" data-prop="' + prop + '" value="' + (isAuto ? "" : n) + '" placeholder="auto">' +
      '<select class="ds-insp-unit" data-prop="' + prop + '">' +
        units.map(function (x) { return '<option value="' + x + '"' + ((isAuto && x === "auto") || (!isAuto && x === u) ? " selected" : "") + '>' + x + '</option>'; }).join("") +
      '</select></span></label>';
  }
  function box4(label, prefix) {
    var sides = [["T", prefix + "Top"], ["R", prefix + "Right"], ["B", prefix + "Bottom"], ["L", prefix + "Left"]];
    return '<div class="ds-insp-box4"><span class="ds-insp-b4lab">' + esc(label) + '</span><div class="ds-insp-b4grid">' +
      sides.map(function (s) { return '<label><input type="number" class="ds-insp-b4" data-prop="' + s[1] + '" value="' + numOf(cv(s[1])) + '" placeholder="0"><em>' + s[0] + '</em></label>'; }).join("") +
      '</div></div>';
  }
  function rangeRow(label, prop, factor) {
    var raw = cv(prop); var v = Math.round((raw === "" ? 1 : parseFloat(raw)) * factor);
    return '<label class="ds-insp-row"><span>' + esc(label) + '</span><input type="range" class="ds-insp-range" data-prop="' + prop + '" data-factor="' + factor + '" min="0" max="' + factor + '" value="' + v + '"></label>';
  }
  function columnsControl(grid) {
    if (!grid) { return ""; }
    var gtc = (grid.style.gridTemplateColumns || cv("gridTemplateColumns", grid) || "").trim();
    if (!gtc || gtc === "none") { return ""; }
    var tracks = gtc.split(/\s+/);
    if (tracks.length < 2) { return ""; }
    var inputs = tracks.map(function (t, i) { return '<label class="ds-insp-colw"><em>Col ' + (i + 1) + '</em><input type="text" class="ds-insp-coltrack" data-i="' + i + '" value="' + esc(t) + '"></label>'; }).join("");
    return group("Columns (" + tracks.length + ")",
      '<p class="ds-insp-note">Width of each column. Type a share (1, 2, 3) or a size (200px, 30%).</p>' +
      '<div class="ds-insp-cols">' + inputs + '</div>' +
      '<button type="button" class="ds-insp-coleq">Make all equal</button>');
  }

  function renderInspector() {
    var ins = w.inspector; if (!ins) { return; }
    var el = w.selEl;
    if (!el) {
      ins.innerHTML = '<div class="ds-insp-empty"><i class="fas fa-arrow-pointer"></i><p>Click any element in the editor to style it.</p><p class="ds-insp-note">Margins, padding, size, border, background, text and columns all appear here.</p></div>';
      clearGridHandles();
      return;
    }
    w.gridEl = findGrid(el);
    var canParent = el.parentElement && el.parentElement !== w.root;
    ins.innerHTML =
      '<div class="ds-insp-head"><span>Editing <b>' + esc(friendlyEl(el)) + '</b></span>' +
        (canParent ? '<button type="button" class="ds-insp-parent" title="Select the element around this one"><i class="fas fa-arrow-up"></i> Parent</button>' : "") + '</div>' +
      group("Text",
        colRow("Colour", "color") +
        lenRow("Size", "fontSize") +
        selRow("Weight", "fontWeight", WEIGHTS) +
        selRow("Align", "textAlign", [["", "—"], ["left", "Left"], ["center", "Centre"], ["right", "Right"], ["justify", "Justify"]]) +
        lenRow("Line height", "lineHeight", ["", "px", "em"]) +
        lenRow("Letter spacing", "letterSpacing", ["px", "em"]) +
        selRow("Capitalisation", "textTransform", [["", "—"], ["none", "Normal"], ["uppercase", "UPPERCASE"], ["lowercase", "lowercase"], ["capitalize", "Capitalise"]]) +
        selRow("Decoration", "textDecorationLine", [["", "—"], ["none", "None"], ["underline", "Underline"], ["line-through", "Strikethrough"]])) +
      group("Spacing", box4("Margin (outside)", "margin") + box4("Padding (inside)", "padding")) +
      group("Size", lenRow("Width", "width") + lenRow("Height", "height") + lenRow("Max width", "maxWidth") + lenRow("Min height", "minHeight")) +
      group("Border",
        lenRow("Thickness", "borderWidth", ["px"]) +
        selRow("Style", "borderStyle", [["", "—"], ["none", "None"], ["solid", "Solid"], ["dashed", "Dashed"], ["dotted", "Dotted"], ["double", "Double"]]) +
        colRow("Colour", "borderColor") +
        lenRow("Rounded corners", "borderRadius", ["px", "%"])) +
      group("Background", colRow("Colour", "backgroundColor")) +
      (w.gridEl ? columnsControl(w.gridEl) : "") +
      group("Layout",
        selRow("Display", "display", [["", "—"], ["block", "Block"], ["inline-block", "Inline block"], ["flex", "Flex (row)"], ["grid", "Grid"], ["none", "Hidden"]]) +
        lenRow("Gap", "gap", ["px", "rem"]) +
        selRow("Justify", "justifyContent", [["", "—"], ["flex-start", "Start"], ["center", "Centre"], ["flex-end", "End"], ["space-between", "Space between"], ["space-around", "Space around"]]) +
        selRow("Align items", "alignItems", [["", "—"], ["stretch", "Stretch"], ["flex-start", "Start"], ["center", "Centre"], ["flex-end", "End"]])) +
      group("Effects",
        rangeRow("Opacity", "opacity", 100) +
        selRow("Shadow", "boxShadow", [["", "—"], ["none", "None"], ["0 1px 3px rgba(0,0,0,.2)", "Small"], ["0 6px 18px rgba(0,0,0,.15)", "Medium"], ["0 14px 40px rgba(0,0,0,.2)", "Large"]]));
    wireInspector();
    if (w.gridEl) { mountGridHandles(w.gridEl); } else { clearGridHandles(); }
  }

  function wireInspector() {
    var ins = w.inspector;
    ins.querySelectorAll(".ds-insp-b4").forEach(function (i) {
      i.addEventListener("input", function () { applyEl(i.dataset.prop, i.value === "" ? "" : (i.value + "px")); });
    });
    ins.querySelectorAll(".ds-insp-num, .ds-insp-unit").forEach(function (i) {
      i.addEventListener("input", function () { applyLen(i.dataset.prop); });
      i.addEventListener("change", function () { applyLen(i.dataset.prop); });
    });
    ins.querySelectorAll(".ds-insp-sel").forEach(function (i) {
      i.addEventListener("change", function () { applyEl(i.dataset.prop, i.value); setTimeout(renderInspector, 0); });
    });
    ins.querySelectorAll(".ds-insp-color").forEach(function (i) {
      i.addEventListener("input", function () { applyEl(i.dataset.prop, i.value); });
    });
    ins.querySelectorAll(".ds-insp-clear").forEach(function (i) {
      i.addEventListener("click", function () { applyEl(i.dataset.prop, ""); renderInspector(); });
    });
    ins.querySelectorAll(".ds-insp-range").forEach(function (i) {
      i.addEventListener("input", function () { applyEl(i.dataset.prop, String((+i.value) / (+i.dataset.factor))); });
    });
    ins.querySelectorAll(".ds-insp-coltrack").forEach(function (i) {
      i.addEventListener("input", applyColumns); i.addEventListener("change", applyColumns);
    });
    var eq = ins.querySelector(".ds-insp-coleq");
    if (eq) { eq.addEventListener("click", function () {
      var n = ins.querySelectorAll(".ds-insp-coltrack").length, a = [];
      for (var k = 0; k < n; k++) { a.push("1fr"); }
      applyElTo(w.gridEl, "gridTemplateColumns", a.join(" ")); renderInspector();
    }); }
    var par = ins.querySelector(".ds-insp-parent");
    if (par) { par.addEventListener("click", function () { if (w.selEl && w.selEl.parentElement && w.selEl.parentElement !== w.root) { selectElement(w.selEl.parentElement); } }); }
  }

  function applyLen(prop) {
    var num = w.inspector.querySelector('.ds-insp-num[data-prop="' + prop + '"]');
    var unit = w.inspector.querySelector('.ds-insp-unit[data-prop="' + prop + '"]');
    if (!num || !unit) { return; }
    if (unit.value === "auto") { applyEl(prop, "auto"); return; }
    if (unit.value === "") { applyEl(prop, num.value === "" ? "" : num.value); return; }
    applyEl(prop, num.value === "" ? "" : (num.value + unit.value));
  }
  function applyColumns() {
    var ins = w.inspector.querySelectorAll(".ds-insp-coltrack");
    var vals = Array.prototype.map.call(ins, function (i) { var v = i.value.trim(); if (/^\d*\.?\d+$/.test(v)) { v = v + "fr"; } return v || "1fr"; });
    applyElTo(w.gridEl, "gridTemplateColumns", vals.join(" "));
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
          '<div class="ds-he-hint">Click an element to select it — the toolbar then shows and changes its styles (size, colour, margins, padding, width, columns…). Grey dotted text fills in automatically; style it but don’t retype it. Use <b>+ Insert</b> to add new text, headings or lines.</div>' +
          '<div class="ds-he-stage">' +
            '<iframe class="ds-he-frame" id="dsHeFrame" title="Block editor"></iframe>' +
            '<textarea class="ds-he-src" id="dsHeSrc" spellcheck="false" style="display:none"></textarea>' +
          '</div>' +
        '</div>';
      w.container = container.querySelector(".ds-he");
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
    toggleSource: toggleSource,
    isSourceMode: function () { return !!w.srcMode; },
    onChange: function (fn) { w.onChange = fn; },
    onSelect: function (fn) { w.onSelect = fn; },
    selectBySelector: selectBySelector,
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
