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
    nav: "menu link", sections: "section", items: "item", cards: "story", mid: "story", side: "story", stories: "story",
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
  // Custom (user-created, blk_*) blocks have no fixed field set: they receive a
  // generic context (see dl_custom_block_context on the engine) with a `stories`
  // list of the latest articles. Offer those so Summary etc. can be inserted.
  var CUSTOM_BLOCK_PLACEHOLDERS = "{{#each stories}}{{title}} {{image}} {{summary}} {{section}} {{href}} {{meta}}{{/each}}";
  function isCustomBlock(type) { return /^blk_/.test(String(type || "")); }
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
    var out = [], s = BLOCK_PLACEHOLDERS[type] || (isCustomBlock(type) ? CUSTOM_BLOCK_PLACEHOLDERS : ""), re = /\{\{\{?[^{}]*?\}?\}\}/g, m;
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
  // A styled placeholder saves as <span style="…">{{token}}</span>; on reload the
  // tokenizer makes a fresh chip inside that span. Merge the wrapper's style onto
  // the chip and unwrap it, so the style lives on the chip (one level) and editing
  // the token again updates the same element instead of nesting more spans.
  function hoistTokenStyles(root) {
    [].slice.call(root.querySelectorAll("span.dl-tok-field")).forEach(function (chip) {
      var p = chip.parentNode;
      if (p && p.tagName === "SPAN" && !p.classList.contains("dl-tok") &&
          p.getAttribute("style") && p.childNodes.length === 1) {
        var ps = p.getAttribute("style") || "", cs = chip.getAttribute("style") || "";
        chip.setAttribute("style", ps + (ps && cs ? ";" : "") + cs);
        p.parentNode.replaceChild(chip, p);
      }
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

  // ---- repeat-region cue -----------------------------------------------
  // The live site repeats a {{#each}} body for every item, but the editor shows
  // the body just once. Wrap each repeating region in a labelled, outlined box
  // and append faded ghost copies so the editor resembles the many-item page.
  // Everything added here is tagged data-ds-decor / data-ds-loopwrap and stripped
  // on serialize (see templateHtml), so it never reaches the saved template.
  // Falls back silently when a loop's open/end tokens are not simple siblings.
  function decorateLoops(root, doc) {
    var opens = [].slice.call(root.querySelectorAll(".dl-tok-loop"));
    opens.forEach(function (open) {
      if (!open.parentNode || open.getAttribute("data-ds-looped")) { return; }
      var parent = open.parentNode, depth = 1, node = open.nextSibling, body = [], end = null;
      while (node) {
        if (node.nodeType === 1 && node.classList) {
          if (node.classList.contains("dl-tok-loop")) { depth++; }
          else if (node.classList.contains("dl-tok-end")) { depth--; if (depth === 0) { end = node; break; } }
        }
        body.push(node); node = node.nextSibling;
      }
      if (!end) { return; }                       // open/end not siblings — leave as-is
      var hasEl = body.some(function (n) { return n.nodeType === 1; });
      if (!hasEl) { return; }                      // nothing visible to repeat

      var region = doc.createElement("div");
      region.className = "dl-loop-region"; region.setAttribute("data-ds-loopwrap", "1");
      var label = doc.createElement("div");
      label.className = "dl-loop-label"; label.setAttribute("data-ds-decor", "1");
      label.setAttribute("contenteditable", "false");
      label.textContent = "↻ " + tokInfo(open.getAttribute("data-tok")).text;
      parent.insertBefore(region, open);
      region.appendChild(label);
      region.appendChild(open);                    // keep the real tokens + body
      body.forEach(function (n) { region.appendChild(n); });
      region.appendChild(end);
      open.setAttribute("data-ds-looped", "1");
      for (var g = 0; g < 2; g++) {                // two faded, non-editable copies
        var ghost = doc.createElement("div");
        ghost.className = "dl-loop-ghost"; ghost.setAttribute("data-ds-decor", "1");
        ghost.setAttribute("contenteditable", "false"); ghost.setAttribute("aria-hidden", "true");
        body.forEach(function (n) { if (!(n.nodeType === 1 && n.classList && (n.classList.contains("dl-tok-loop") || n.classList.contains("dl-tok-end")))) { ghost.appendChild(n.cloneNode(true)); } });
        region.appendChild(ghost);
      }
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
    var el = null, nest = false;
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
      case "imgurl": openImageDialog(); return;   // custom image (not an article image)
      case "hr": el = w.doc.createElement("hr"); break;
      case "div": el = w.doc.createElement("div"); el.textContent = "New box"; el.style.padding = "16px"; el.style.border = "1px dashed #cbd5e1"; nest = true; break;
      case "row": el = makeFlexRow(2); nest = true; break;
      case "columns": el = makeGridCols(2); nest = true; break;
      default: return;
    }
    insertSmart(el, nest);
    selectElement(el);   // select it so it can be styled / typed into straight away
  }
  // Place a new node. When a box/column is selected it drops in at the TOP of
  // that box (so you can reach the top of a column you just made); otherwise it
  // goes in at the cursor. Container inserts always nest inside a selected box.
  function insertSmart(el, nest) {
    // Insert where the cursor is (the last place clicked or typed in the editor).
    // If there is no caret in the editor yet, insertNodeAtCaret appends to the end.
    insertNodeAtCaret(el);
  }
  // Insert a custom image: upload a file, crop it, and save it to the image
  // store (crop_save_image.php → WebP in /article_images/…, returns an absolute
  // URL that works on any publication's pages). Or paste a URL that is already
  // online. The image drops in at the cursor position.
  function openImageDialog() {
    if (w.srcMode || !w.doc) { return; }
    rememberRange();
    var cropper = null;
    var dlg = DS.dialog({ title: "Insert an image", body:
      '<div class="ds-img-dlg">' +
        '<div class="ds-img-drop" id="dsImgDrop">' +
          '<i class="fas fa-cloud-arrow-up"></i>' +
          '<p>Choose an image to upload &amp; crop</p>' +
          '<input type="file" id="dsImgFile" accept="image/*">' +
        '</div>' +
        '<div class="ds-crop-wrap" id="dsCropWrap" hidden><img id="dsCropImg" alt=""></div>' +
        '<div class="ds-crop-tools" id="dsCropTools" hidden>' +
          '<span>Shape:</span>' +
          '<button type="button" class="ds-btn ds-btn-ghost ds-sm" data-ar="NaN">Free</button>' +
          '<button type="button" class="ds-btn ds-btn-ghost ds-sm" data-ar="1.7777">16:9</button>' +
          '<button type="button" class="ds-btn ds-btn-ghost ds-sm" data-ar="1.3333">4:3</button>' +
          '<button type="button" class="ds-btn ds-btn-ghost ds-sm" data-ar="1">Square</button>' +
          '<button type="button" class="ds-btn ds-btn-ghost ds-sm" id="dsCropRot"><i class="fas fa-rotate-right"></i> Rotate</button>' +
        '</div>' +
        '<label class="ds-f"><span>Alt text (describes the image)</span><input type="text" id="dsImgAlt" placeholder="e.g. Eiffel Tower at dusk"></label>' +
        '<details class="ds-img-url"><summary>Already online? Paste its address instead</summary>' +
          '<label class="ds-f"><span>Image URL</span><input type="url" id="dsImgUrl" placeholder="https://…/photo.jpg"></label>' +
        '</details>' +
        '<div class="ds-dlg-actions">' +
          '<button class="ds-btn ds-btn-primary" id="dsImgIns"><i class="fas fa-check"></i> Insert image</button>' +
        '</div>' +
      '</div>' });

    var fileInput = dlg.el.querySelector("#dsImgFile");
    var cropWrap = dlg.el.querySelector("#dsCropWrap");
    var cropImg = dlg.el.querySelector("#dsCropImg");
    var cropTools = dlg.el.querySelector("#dsCropTools");
    var insBtn = dlg.el.querySelector("#dsImgIns");

    function destroyCropper() { if (cropper) { try { cropper.destroy(); } catch (e) {} cropper = null; } }

    fileInput.addEventListener("change", function () {
      var f = fileInput.files && fileInput.files[0];
      if (!f) { return; }
      var reader = new FileReader();
      reader.onload = function (ev) {
        cropImg.src = ev.target.result;
        cropWrap.hidden = false; cropTools.hidden = false;
        destroyCropper();
        if (window.Cropper) {
          cropper = new Cropper(cropImg, { viewMode: 1, autoCropArea: 1, background: false, movable: true, zoomable: true });
        }
      };
      reader.readAsDataURL(f);
    });

    dlg.el.querySelectorAll("[data-ar]").forEach(function (b) {
      b.addEventListener("click", function () { if (cropper) { cropper.setAspectRatio(parseFloat(b.getAttribute("data-ar"))); } });
    });
    var rot = dlg.el.querySelector("#dsCropRot");
    if (rot) { rot.addEventListener("click", function () { if (cropper) { cropper.rotate(90); } }); }

    function placeImg(url) {
      var img = w.doc.createElement("img");
      img.setAttribute("src", url);
      img.setAttribute("alt", (dlg.el.querySelector("#dsImgAlt").value || "").trim());
      img.style.maxWidth = "100%"; img.style.height = "auto";
      insertSmart(img, false); selectElement(img);
      destroyCropper(); dlg.close();
    }

    insBtn.addEventListener("click", function () {
      var url = (dlg.el.querySelector("#dsImgUrl").value || "").trim();
      if (cropper) {
        var canvas = cropper.getCroppedCanvas({ maxWidth: 2000, maxHeight: 2000 });
        if (!canvas) { DS.toast("Could not read the crop"); return; }
        var data = canvas.toDataURL("image/jpeg", 0.92);
        insBtn.disabled = true; insBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading…';
        fetch("crop_save_image.php", {
          method: "POST", headers: { "Content-Type": "application/json" },
          body: JSON.stringify({ image: data, attribution: (dlg.el.querySelector("#dsImgAlt").value || "").trim() })
        }).then(function (r) { return r.json(); }).then(function (j) {
          if (j && j.status === "success" && j.url) { placeImg(j.url); }
          else { insBtn.disabled = false; insBtn.innerHTML = '<i class="fas fa-check"></i> Insert image'; DS.toast((j && j.message) || "Upload failed"); }
        }).catch(function (e) {
          insBtn.disabled = false; insBtn.innerHTML = '<i class="fas fa-check"></i> Insert image'; DS.toast(e.message || "Upload failed");
        });
        return;
      }
      if (url) { placeImg(url); return; }
      DS.toast("Choose an image to upload, or paste a URL");
    });
  }
  function isContainerEl(el) {
    return el && el.tagName && ["DIV", "SECTION", "ARTICLE", "ASIDE", "HEADER", "FOOTER", "LI", "NAV", "MAIN"].indexOf(el.tagName) !== -1;
  }
  function makeFlexRow(n) {
    var d = w.doc.createElement("div"); d.style.display = "flex"; d.style.gap = "16px";
    for (var i = 0; i < n; i++) { var c = w.doc.createElement("div"); c.style.flex = "1"; c.style.minWidth = "0"; c.style.padding = "12px"; c.style.border = "1px dashed #e2e8f0"; c.textContent = "Column " + (i + 1); d.appendChild(c); }
    return d;
  }
  function makeGridCols(n) {
    var d = w.doc.createElement("div"); d.style.display = "grid"; d.style.gap = "16px";
    var tracks = []; for (var k = 0; k < n; k++) { tracks.push("1fr"); }
    d.style.gridTemplateColumns = tracks.join(" ");
    for (var i = 0; i < n; i++) { var c = w.doc.createElement("div"); c.style.padding = "12px"; c.style.border = "1px dashed #e2e8f0"; c.textContent = "Cell " + (i + 1); d.appendChild(c); }
    return d;
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

  // ---- on-canvas spacing handles (Figma-style padding + margin) -------
  // Overlay draggable bars at the selected element's PADDING edges (inner, red)
  // and MARGIN edges (outer, blue). Drag one to change that side live, with a
  // value tooltip. Works on any element (not placeholder chips). Decoration only
  // (data-ds-decor) so the bars never serialize into the saved template.
  function clearSpacingHandles() {
    w._spaceEl = null;
    if (w.doc) { w.doc.querySelectorAll(".ds-padbar,.ds-marbar,.ds-padband,.ds-marband,.ds-padlabel").forEach(function (n) { n.remove(); }); }
  }
  function mountSpacingHandles(el) {
    clearSpacingHandles();
    if (!el || !w.doc || el === w.root) { return; }
    if (el.classList && el.classList.contains("dl-tok")) { return; } // not on placeholder chips
    w._spaceEl = el;
    renderSpacingHandles();
  }
  function renderSpacingHandles() {
    var el = w._spaceEl; if (!el || !w.doc || !w.win) { return; }
    w.doc.querySelectorAll(".ds-padbar,.ds-marbar,.ds-padband,.ds-marband").forEach(function (n) { n.remove(); });
    var r = el.getBoundingClientRect();
    var cs = w.win.getComputedStyle(el);
    var num = function (v) { return parseFloat(v) || 0; };
    var pt = num(cs.paddingTop), pr = num(cs.paddingRight), pb = num(cs.paddingBottom), pl = num(cs.paddingLeft);
    var mt = num(cs.marginTop), mr = num(cs.marginRight), mb = num(cs.marginBottom), ml = num(cs.marginLeft);
    function band(cls, st) { var b = w.doc.createElement("div"); b.className = cls; b.setAttribute("data-ds-decor", "1"); Object.assign(b.style, st); w.doc.body.appendChild(b); }
    function bar(cls, side, type, st) {
      var b = w.doc.createElement("div"); b.className = cls; b.setAttribute("data-ds-decor", "1"); b.setAttribute("contenteditable", "false");
      Object.assign(b.style, st); bindSpaceDrag(b, side, type); w.doc.body.appendChild(b);
    }
    // Show ONE set at a time — the mode chosen by the Padding/Margin toggle in
    // the inspector — so it is unambiguous which you are dragging.
    var mode = w.spaceMode || "padding";
    if (mode === "padding") {
      // PADDING — bands + bars at the inner edges (red).
      if (pt > 0) band("ds-padband", { left: r.left + "px", top: r.top + "px", width: r.width + "px", height: pt + "px" });
      if (pb > 0) band("ds-padband", { left: r.left + "px", top: (r.bottom - pb) + "px", width: r.width + "px", height: pb + "px" });
      if (pl > 0) band("ds-padband", { left: r.left + "px", top: (r.top + pt) + "px", width: pl + "px", height: (r.height - pt - pb) + "px" });
      if (pr > 0) band("ds-padband", { left: (r.right - pr) + "px", top: (r.top + pt) + "px", width: pr + "px", height: (r.height - pt - pb) + "px" });
      bar("ds-padbar h", "Top", "padding", { left: r.left + "px", top: (r.top + pt - 3) + "px", width: r.width + "px" });
      bar("ds-padbar h", "Bottom", "padding", { left: r.left + "px", top: (r.bottom - pb - 4) + "px", width: r.width + "px" });
      bar("ds-padbar v", "Left", "padding", { top: r.top + "px", left: (r.left + pl - 3) + "px", height: r.height + "px" });
      bar("ds-padbar v", "Right", "padding", { top: r.top + "px", left: (r.right - pr - 4) + "px", height: r.height + "px" });
    } else {
      // MARGIN — bands + bars at the outer edges (blue).
      if (mt > 0) band("ds-marband", { left: r.left + "px", top: (r.top - mt) + "px", width: r.width + "px", height: mt + "px" });
      if (mb > 0) band("ds-marband", { left: r.left + "px", top: r.bottom + "px", width: r.width + "px", height: mb + "px" });
      if (ml > 0) band("ds-marband", { left: (r.left - ml) + "px", top: r.top + "px", width: ml + "px", height: r.height + "px" });
      if (mr > 0) band("ds-marband", { left: r.right + "px", top: r.top + "px", width: mr + "px", height: r.height + "px" });
      bar("ds-marbar h", "Top", "margin", { left: r.left + "px", top: (r.top - mt - 3) + "px", width: r.width + "px" });
      bar("ds-marbar h", "Bottom", "margin", { left: r.left + "px", top: (r.bottom + mb - 4) + "px", width: r.width + "px" });
      bar("ds-marbar v", "Left", "margin", { top: r.top + "px", left: (r.left - ml - 3) + "px", height: r.height + "px" });
      bar("ds-marbar v", "Right", "margin", { top: r.top + "px", left: (r.right + mr - 4) + "px", height: r.height + "px" });
    }
  }
  function bindSpaceDrag(bar, side, type) {
    bar.addEventListener("mousedown", function (e) {
      e.preventDefault(); e.stopPropagation();
      var el = w._spaceEl; if (!el) { return; }
      var prop = type + side;                         // e.g. paddingTop / marginTop
      var start = parseFloat(w.win.getComputedStyle(el)[prop]) || 0;
      var sx = e.clientX, sy = e.clientY, inner = (type === "padding");
      var label = w.doc.createElement("div"); label.className = "ds-padlabel"; label.setAttribute("data-ds-decor", "1");
      label.style.background = inner ? "#ef4444" : "#f59e0b"; w.doc.body.appendChild(label);
      function mv(ev) {
        var dx = ev.clientX - sx, dy = ev.clientY - sy, d;
        if (side === "Top") { d = inner ? dy : -dy; }
        else if (side === "Bottom") { d = inner ? -dy : dy; }
        else if (side === "Left") { d = inner ? dx : -dx; }
        else { d = inner ? -dx : dx; }               // Right
        var nv = Math.max(0, Math.round(start + d));
        el.style[prop] = nv + "px";
        label.textContent = (inner ? "padding " : "margin ") + side.toLowerCase() + ": " + nv;
        label.style.left = (ev.clientX + 12) + "px"; label.style.top = (ev.clientY + 12) + "px";
        renderSpacingHandles();
      }
      function up() {
        w.doc.removeEventListener("mousemove", mv); w.doc.removeEventListener("mouseup", up);
        label.remove(); changed();
        setTimeout(function () { if (w.selEl === el) { renderInspector(); } }, 0);
      }
      w.doc.addEventListener("mousemove", mv); w.doc.addEventListener("mouseup", up);
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
    // Strip the editor-only repeat-region cue (ghosts + labels first, then unwrap
    // the region), leaving the original {{#each}} … {{/each}} token sequence.
    clone.querySelectorAll("[data-ds-decor]").forEach(function (n) { n.remove(); });
    clone.querySelectorAll("[data-ds-loopwrap]").forEach(function (region) {
      var p = region.parentNode; if (!p) { return; }
      while (region.firstChild) { p.insertBefore(region.firstChild, region); }
      p.removeChild(region);
    });
    clone.querySelectorAll(".dl-tok").forEach(function (t) {
      var raw = t.getAttribute("data-tok") || "";
      // A styled placeholder (the user coloured/resized a {{token}}) must keep its
      // styling: emit a plain <span style="…">{{token}}</span> so the engine still
      // fills the token AND the style survives. Unstyled tokens → bare text, as before.
      var style = t.getAttribute("style");
      if (style && style.trim() && t.classList.contains("dl-tok-field")) {
        var sp = w.doc.createElement("span");
        sp.setAttribute("style", style);
        sp.appendChild(w.doc.createTextNode(raw));
        t.parentNode.replaceChild(sp, t);
      } else {
        t.parentNode.replaceChild(w.doc.createTextNode(raw), t);
      }
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
    changeTimer = setTimeout(function () { renderLayers(); if (w.onChange) { w.onChange(getTemplate()); } }, 350);
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
        '.dl-loop-region{position:relative;outline:1px dashed #94a3b8;outline-offset:7px;border-radius:4px;margin:18px 0}' +
        '.dl-loop-label{position:absolute;top:-11px;left:10px;z-index:2;background:#eef2ff;color:#4f46e5;font:600 11px/1 Inter,system-ui,sans-serif;padding:3px 8px;border-radius:10px;border:1px solid #c7d2fe;letter-spacing:.02em;white-space:nowrap}' +
        '.dl-loop-ghost{opacity:.4;pointer-events:none;filter:grayscale(.15)}' +
        '.dl-loop-ghost *{cursor:default !important}' +
        '.dl-tplimg{width:44px;height:30px;border:1px solid #cdd7e5;border-radius:4px;vertical-align:middle;object-fit:cover;opacity:.75}' +
        '.dl-edit-root a{color:#2563eb;text-decoration:underline}' +
        '.ds-cols{position:relative}' +
        '.ds-col-cell{min-height:24px;outline:1px dashed #e2e8f0;outline-offset:2px}' +
        '.ds-colsplit{background:rgba(37,99,235,.45);border-radius:3px;cursor:col-resize}' +
        '.ds-colsplit:hover{background:#2563eb}' +
        '.ds-padbar,.ds-marbar{position:fixed;z-index:40}' +
        '.ds-padbar.h,.ds-marbar.h{height:3px;cursor:ns-resize}.ds-padbar.v,.ds-marbar.v{width:3px;cursor:ew-resize}' +
        '.ds-padbar{background:rgba(239,68,68,.65)}.ds-padbar:hover{background:#ef4444}' +
        '.ds-marbar{background:rgba(245,158,11,.7)}.ds-marbar:hover{background:#f59e0b}' +
        // a clearly visible, grabbable grip knob in the middle of each side
        '.ds-padbar::after,.ds-marbar::after{content:"";position:absolute;left:50%;top:50%;transform:translate(-50%,-50%);border:1.5px solid #fff;border-radius:3px;box-shadow:0 1px 3px rgba(0,0,0,.35)}' +
        '.ds-padbar.h::after,.ds-marbar.h::after{width:28px;height:9px}.ds-padbar.v::after,.ds-marbar.v::after{width:9px;height:28px}' +
        '.ds-padbar::after{background:#ef4444}.ds-marbar::after{background:#f59e0b}' +
        '.ds-padband{position:fixed;z-index:39;background:rgba(239,68,68,.14);pointer-events:none}' +
        '.ds-marband{position:fixed;z-index:38;background:rgba(245,158,11,.16);pointer-events:none}' +
        '.ds-padlabel{position:fixed;z-index:41;background:#ef4444;color:#fff;font:600 11px/1 Inter,system-ui,sans-serif;padding:2px 6px;border-radius:4px;pointer-events:none;white-space:nowrap}' +
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
      hoistTokenStyles(w.root);
      softenTemplateImages(w.root);
      decorateLoops(w.root, w.doc);
      try { w.doc.execCommand("defaultParagraphSeparator", false, "p"); } catch (e) {}
      w.root.addEventListener("input", changed);
      w.root.addEventListener("keyup", rememberRange);
      w.root.addEventListener("mouseup", rememberRange);
      w.win.addEventListener("scroll", function () { if (w._spaceEl) { renderSpacingHandles(); } }, true);
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
      selectElement(null);
      renderLayers();
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
  // Populate the placeholder picker with EVERY placeholder the engine knows,
  // grouped: this block first, then the shared page contexts (article / author /
  // site), then every other block. The local sets render immediately so it is
  // never empty; the full catalogue (from the `placeholders` AJAX) replaces it.
  function fillInsertMenu(type) {
    if (!w.insSel) { return; }
    function opt(val, label) { var o = document.createElement("option"); o.value = val; o.textContent = label; return o; }
    function optgrp(label, pairs) {
      if (!pairs || !pairs.length) { return null; }
      var g = document.createElement("optgroup"); g.label = label;
      pairs.forEach(function (p) { g.appendChild(opt(p[0], p[1])); });
      return g;
    }
    function render(groups) {
      w.insSel.innerHTML = "";
      w.insSel.appendChild(opt("", "Insert placeholder…"));
      groups.forEach(function (g) { if (g) { w.insSel.appendChild(g); } });
    }
    function itemsOf(grp) {
      return (grp.items || []).map(function (it) {
        var val = (it.token === "body") ? "{{{body}}}" : ("{{" + it.token + "}}");
        return [val, it.label + (it.loop ? " (in list)" : "")];
      });
    }
    // Immediate local set so the picker is never empty.
    var localThis = fieldsFor(type).map(function (f) { return [f, tokInfo(f).text]; });
    render([optgrp(isCustomBlock(type) ? "This block (latest stories)" : "This block", localThis), optgrp("General", GLOBAL_FIELDS)]);
    // Full catalogue — every placeholder, grouped.
    if (DS.loadPlaceholders) {
      DS.loadPlaceholders().then(function (defs) {
        if (w.type !== type) { return; }
        var blocks = defs.blocks || {}, shared = defs.shared || {}, groups = [];
        if (blocks[type]) { groups.push(optgrp("This block — " + (blocks[type].label || type), itemsOf(blocks[type]))); }
        else if (localThis.length) { groups.push(optgrp(isCustomBlock(type) ? "This block (latest stories)" : "This block", localThis)); }
        Object.keys(shared).forEach(function (k) { groups.push(optgrp(shared[k].label || k, itemsOf(shared[k]))); });
        Object.keys(blocks).forEach(function (t) { if (t !== type) { groups.push(optgrp(blocks[t].label || t, itemsOf(blocks[t]))); } });
        render(groups);
      }).catch(function () { /* keep the local set */ });
    }
  }

  // ---- left rail: Insert (elements), Placeholder (scoped tokens), HTML ----
  function buildRail(container) {
    var insBtn = container.querySelector("#dsHeIns");
    var insMenu = w.insMenu;
    var items = [["p", "Text paragraph"], ["h2", "Heading"], ["a", "Link"], ["button", "Button"],
      ["subscribe", "Subscribe button"], ["imgurl", "Image (from a URL)"], ["img", "Image placeholder"], ["hr", "Divider line"],
      ["div", "Box / container"], ["row", "Row (side by side)"], ["columns", "Columns (grid)"], ["br", "Line break"]];
    insMenu.innerHTML = items.map(function (o) { return '<button type="button" data-ins="' + o[0] + '">' + esc(o[1]) + "</button>"; }).join("");
    insBtn.addEventListener("click", function (e) { e.preventDefault(); insMenu.hidden = !insMenu.hidden; });
    insMenu.addEventListener("click", function (e) {
      var b = e.target.closest ? e.target.closest("button[data-ins]") : null;
      if (!b) { return; }
      insMenu.hidden = true; insertElement(b.getAttribute("data-ins"));
    });
    document.addEventListener("click", function (e) {
      if (!insMenu.hidden && !insMenu.contains(e.target) && e.target !== insBtn && !insBtn.contains(e.target)) { insMenu.hidden = true; }
    });

    w.insSel = container.querySelector("#dsHeTokSel");
    w.insSel.addEventListener("mousedown", function () { rememberRange(); });
    w.insSel.addEventListener("change", function () { if (w.insSel.value !== "") { insertToken(w.insSel.value); } w.insSel.value = ""; });

    // The raw-HTML toggle now lives in the editor's top header (block_editor.js);
    // keep this wiring only if a rail button is present.
    w.srcBtn = container.querySelector("#dsHeSrcBtn");
    if (w.srcBtn) {
      w.srcBtn.addEventListener("click", function (e) {
        e.preventDefault();
        var src = toggleSource();
        w.srcBtn.classList.toggle("ds-on", src);
        w.srcBtn.innerHTML = src ? '<i class="fas fa-eye"></i> Visual editor' : '<i class="fas fa-code"></i> Edit HTML';
      });
    }
  }

  // ---- layers tree (click to select; mirrors canvas selection) ----------
  function layerLabel(el) {
    if (el.classList && el.classList.contains("dl-tok")) {
      var raw = el.getAttribute("data-tok") || ""; return tokInfo(raw).text || "token";
    }
    var tag = (el.tagName || "").toLowerCase();
    var map = { h1: "Heading", h2: "Heading", h3: "Subheading", h4: "Subheading", p: "Text",
      a: "Link", img: "Image", ul: "List", ol: "List", li: "List item", button: "Button",
      span: "Text", div: "Box", section: "Section", header: "Header", footer: "Footer",
      nav: "Navigation", article: "Card", aside: "Sidebar", figure: "Figure", time: "Date" };
    return map[tag] || (tag ? tag.charAt(0).toUpperCase() + tag.slice(1) : "Element");
  }
  function layerIcon(el) {
    var k = resolveKind(el);
    return { heading: "fa-heading", body: "fa-align-left", meta: "fa-tag", image: "fa-image",
      link: "fa-link", icon: "fa-icons", list: "fa-table-cells", container: "fa-square",
      text: "fa-font" }[k] || "fa-square";
  }
  function renderLayers() {
    var host = w.layers; if (!host || !w.root) { return; }
    host.innerHTML = "";
    var MAX = 120, n = 0;
    var dragEl = null;   // shared by every row built in this render (drag-to-reorder)
    (function walk(node, depth) {
      var kids = node.children; if (!kids) { return; }
      for (var i = 0; i < kids.length; i++) {
        var el = kids[i];
        if (el.hasAttribute && (el.hasAttribute("data-ds-decor") || el.hasAttribute("data-ds-loopwrap"))) { continue; }
        if (el.classList && (el.classList.contains("dl-tok-loop") || el.classList.contains("dl-tok-cond") || el.classList.contains("dl-tok-end"))) { continue; }
        if (n++ > MAX) { return; }
        var row = document.createElement("button");
        row.type = "button";
        row.className = "ds-he-layer" + (el === w.selEl ? " on" : "");
        row.style.paddingLeft = (6 + depth * 12) + "px";
        row.setAttribute("draggable", "true");
        row.innerHTML = '<i class="fas fa-grip-vertical ds-he-grip"></i><i class="fas ' + layerIcon(el) + '"></i><span class="ds-he-layer-t">' + esc(layerLabel(el)) + '</span>';
        (function (target) {
          row.addEventListener("click", function () { selectElement(target); });
          row.addEventListener("dragstart", function (e) {
            dragEl = target; row.classList.add("ds-he-dragging");
            try { e.dataTransfer.effectAllowed = "move"; e.dataTransfer.setData("text/plain", ""); } catch (_) {}
          });
          row.addEventListener("dragend", function () { row.classList.remove("ds-he-dragging"); clearDropCues(host); });
          row.addEventListener("dragover", function (e) {
            if (!dragEl || dragEl === target) { return; }
            e.preventDefault();
            var rect = row.getBoundingClientRect();
            var inside = isContainerEl(target) && !target.classList.contains("dl-tok");
            var zone = inside && (e.clientY - rect.top) > rect.height * 0.33 && (e.clientY - rect.top) < rect.height * 0.67
              ? "in" : ((e.clientY - rect.top) > rect.height / 2 ? "after" : "before");
            clearDropCues(host);
            row.classList.add(zone === "in" ? "ds-drop-in" : (zone === "after" ? "ds-drop-after" : "ds-drop-before"));
            row._zone = zone;
          });
          row.addEventListener("drop", function (e) {
            e.preventDefault();
            var moved = dragEl, zone = row._zone || "before"; dragEl = null; clearDropCues(host);
            if (!moved || moved === target || moved.contains(target)) { return; }
            try {
              if (zone === "in") { target.insertBefore(moved, target.firstChild); }
              else if (zone === "after") { target.parentNode.insertBefore(moved, target.nextSibling); }
              else { target.parentNode.insertBefore(moved, target); }
            } catch (_) { return; }
            changed(); renderLayers(); selectElement(moved);
          });
        })(el);
        host.appendChild(row);
        if (!(el.classList && el.classList.contains("dl-tok"))) { walk(el, depth + 1); }
      }
    })(w.root, 0);
    if (!host.children.length) { host.innerHTML = '<div class="ds-he-layers-empty">Nothing here yet.</div>'; }
  }
  function clearDropCues(host) {
    host.querySelectorAll(".ds-drop-before,.ds-drop-after,.ds-drop-in").forEach(function (r) {
      r.classList.remove("ds-drop-before", "ds-drop-after", "ds-drop-in");
    });
  }

  // ---- kind resolver: map a selected node to a taxonomy kind ------------
  function resolveKind(el) {
    if (!el || el.nodeType !== 1) { return "container"; }
    var tag = (el.tagName || "").toLowerCase();
    if (el.classList && el.classList.contains("dl-tok")) {
      if (el.classList.contains("dl-tok-field")) { return DS.tokenKind(el.getAttribute("data-tok") || ""); }
      return "list"; // a loop/cond chip selected
    }
    if (tag === "img" || (el.classList && el.classList.contains("dl-tplimg"))) { return "image"; }
    if (tag === "a" || tag === "button") { return "link"; }
    if (cv("display", el).indexOf("grid") !== -1) { return "list"; }
    if (el.querySelector && el.querySelector(".dl-tok-loop")) { return "list"; }
    if (tag === "h1" || tag === "h2" || tag === "h3" || tag === "h4") { return "heading"; }
    if (tag === "p" || tag === "blockquote") { return "body"; }
    if (tag === "small" || tag === "time" || tag === "em") { return "meta"; }
    if (tag === "ul" || tag === "ol" || tag === "li" || tag === "span") { return "text"; }
    return "container";
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
    // The repeat-region box, its label and the ghost copies are editor-only cues —
    // not real content, so don't let them be selected / styled / deleted.
    if (w.selEl && (w.selEl.hasAttribute("data-ds-loopwrap") || (w.selEl.closest && w.selEl.closest("[data-ds-decor]")))) { w.selEl = null; }
    if (w.selEl) { try { w.selEl.classList.add("ds-he-sel"); } catch (e) {} }
    w.gridEl = findGrid(w.selEl);
    renderInspector();
    renderLayers();
    if (!silent && w.onSelect) { try { w.onSelect(w.selEl ? elSelector(w.selEl) : ""); } catch (e) {} }
  }
  // A selector for an element, relative to the block, matching the one the
  // preview bridge posts (first dl-* class, else container+tag, else tag).
  function elSelector(el) {
    var tag = (el.tagName || "").toLowerCase(), node = el;
    while (node && node !== w.root) {
      if (node.classList) {
        var dl = [].slice.call(node.classList).find(function (c) {
          return c.indexOf("dl-") === 0 && c.indexOf("dl-block") !== 0 && c !== "dl-tok" && c.indexOf("dl-tok-") !== 0 && c.indexOf("dl-loop") !== 0 && c !== "ds-he-sel";
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
    var sides = [["Top", prefix + "Top"], ["Right", prefix + "Right"], ["Bottom", prefix + "Bottom"], ["Left", prefix + "Left"]];
    return '<div class="ds-insp-box4"><span class="ds-insp-b4lab">' + esc(label) + '</span>' +
      '<p class="ds-insp-note">Set each side on its own — e.g. 20 top, 10 bottom, 5 left &amp; right.</p>' +
      '<div class="ds-insp-b4grid">' +
      sides.map(function (s) { return '<label title="' + s[0] + '"><input type="number" class="ds-insp-b4" data-prop="' + s[1] + '" value="' + numOf(cv(s[1])) + '" placeholder="0"><em>' + s[0] + '</em></label>'; }).join("") +
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

  // Full rich-text button row: inline styles, scripts, lists, links.
  function fmtRow() {
    return '<div class="ds-insp-fmt">' +
      '<button type="button" data-fmt="b" title="Bold"><b>B</b></button>' +
      '<button type="button" data-fmt="i" title="Italic"><i>I</i></button>' +
      '<button type="button" data-fmt="u" title="Underline"><u>U</u></button>' +
      '<button type="button" data-fmt="s" title="Strikethrough"><s>S</s></button>' +
      '<button type="button" data-fmt="sup" title="Superscript">x<sup>2</sup></button>' +
      '<button type="button" data-fmt="sub" title="Subscript">x<sub>2</sub></button>' +
      '<button type="button" data-fmt="ul" title="Bulleted list"><i class="fas fa-list-ul"></i></button>' +
      '<button type="button" data-fmt="ol" title="Numbered list"><i class="fas fa-list-ol"></i></button>' +
      '<button type="button" data-fmt="link" title="Make a link"><i class="fas fa-link"></i></button>' +
      '<button type="button" data-fmt="unlink" title="Remove link"><i class="fas fa-unlink"></i></button>' +
      '<button type="button" data-fmt="clear" title="Clear formatting"><i class="fas fa-eraser"></i></button>' +
      '</div>';
  }
  // Font-family picker (applies to the text selection or the element).
  function fontRow() {
    var cur = cv("fontFamily");
    return '<label class="ds-insp-row"><span>Font</span><select class="ds-insp-sel ds-insp-font">' +
      FONTS.map(function (o) { return '<option value="' + esc(o[0]) + '"' + (o[0] && cur.indexOf(o[0].split(",")[0].replace(/"/g, "")) !== -1 ? " selected" : "") + '>' + esc(o[1]) + '</option>'; }).join("") +
      '</select></label>';
  }
  // Paragraph / heading block format.
  function formatBlockRow(el) {
    var tag = (el && el.tagName) ? el.tagName.toLowerCase() : "";
    var opts = [["", "—"], ["p", "Paragraph"], ["h1", "Heading 1"], ["h2", "Heading 2"], ["h3", "Heading 3"], ["h4", "Heading 4"], ["blockquote", "Quote"]];
    return '<label class="ds-insp-row"><span>Format</span><select class="ds-insp-sel ds-insp-fmtblock">' +
      opts.map(function (o) { return '<option value="' + o[0] + '"' + (o[0] === tag ? " selected" : "") + '>' + o[1] + '</option>'; }).join("") +
      '</select></label>';
  }
  // Full typography group — every text control, for any text-bearing element.
  function gTypography(full, el) {
    return group("Typography",
      fmtRow() +
      fontRow() +
      formatBlockRow(el) +
      colRow("Text colour", "color") +
      colRow("Highlight", "backgroundColor") +
      lenRow("Size", "fontSize") +
      selRow("Weight", "fontWeight", WEIGHTS) +
      selRow("Align", "textAlign", [["", "—"], ["left", "Left"], ["center", "Centre"], ["right", "Right"], ["justify", "Justify"]]) +
      lenRow("Line height", "lineHeight", ["", "px", "em"]) +
      lenRow("Letter spacing", "letterSpacing", ["px", "em"]) +
      selRow("Capitalisation", "textTransform", [["", "—"], ["none", "Normal"], ["uppercase", "UPPERCASE"], ["lowercase", "lowercase"], ["capitalize", "Capitalise"]]) +
      selRow("Decoration", "textDecorationLine", [["", "—"], ["none", "None"], ["underline", "Underline"], ["line-through", "Strikethrough"]]));
  }
  function gSpacing() {
    var mode = w.spaceMode || "padding";
    var toggle = '<div class="ds-spacemode-row"><span class="ds-insp-note">Drag on the canvas:</span>' +
      '<div class="ds-spacemode">' +
        '<button type="button" data-mode="padding"' + (mode === "padding" ? ' class="on"' : '') + '>Padding</button>' +
        '<button type="button" data-mode="margin"' + (mode === "margin" ? ' class="on"' : '') + '>Margin</button>' +
      '</div></div>';
    return group("Spacing", toggle + box4("Margin (outside)", "margin") + box4("Padding (inside)", "padding"));
  }
  function gBorder() {
    return group("Border",
      lenRow("Thickness", "borderWidth", ["px"]) +
      selRow("Style", "borderStyle", [["", "—"], ["none", "None"], ["solid", "Solid"], ["dashed", "Dashed"], ["dotted", "Dotted"], ["double", "Double"]]) +
      colRow("Colour", "borderColor") +
      lenRow("Rounded corners", "borderRadius", ["px", "%"]));
  }
  function gBackground() { return group("Background", colRow("Colour", "backgroundColor")); }
  function gLayout(withCols) {
    return group("Layout",
      selRow("Display", "display", [["", "—"], ["block", "Block"], ["inline-block", "Inline block"], ["flex", "Flex (row)"], ["grid", "Grid"], ["none", "Hidden"]]) +
      lenRow("Gap", "gap", ["px", "rem"]) +
      selRow("Justify", "justifyContent", [["", "—"], ["flex-start", "Start"], ["center", "Centre"], ["flex-end", "End"], ["space-between", "Space between"], ["space-around", "Space around"]]) +
      selRow("Align items", "alignItems", [["", "—"], ["stretch", "Stretch"], ["flex-start", "Start"], ["center", "Centre"], ["flex-end", "End"]]) +
      (withCols ? '<div class="ds-insp-fmt"><button type="button" data-col="add">+ column</button><button type="button" data-col="rm">&minus; column</button></div>' : ""));
  }

  // Groups shown for each taxonomy kind — only what fits that kind.
  function groupsForKind(kind, el) {
    switch (kind) {
      case "heading":
        return gTypography(true, el) + gSpacing() + group("Size", lenRow("Width", "width") + lenRow("Max width", "maxWidth"));
      case "body":
        return gTypography(true, el) + gSpacing() + group("Size", lenRow("Reading width (max)", "maxWidth"));
      case "meta":
        return gTypography(true, el) + gSpacing();
      case "image":
        return group("Image",
          lenRow("Width", "width") + lenRow("Height", "height") + lenRow("Max width", "maxWidth") +
          selRow("Aspect ratio", "aspectRatio", [["", "—"], ["16 / 9", "16:9"], ["4 / 3", "4:3"], ["3 / 2", "3:2"], ["1 / 1", "Square"], ["21 / 9", "Ultra-wide"]]) +
          selRow("Fit", "objectFit", [["", "—"], ["cover", "Cover"], ["contain", "Contain"], ["fill", "Fill"]]) +
          lenRow("Rounded corners", "borderRadius", ["px", "%"])) + gBorder() + gSpacing();
      case "link":
        return gTypography(true, el) +
          group("Button look", colRow("Background", "backgroundColor") + box4("Padding", "padding") + lenRow("Rounded corners", "borderRadius", ["px", "%"])) +
          gBorder() + gSpacing();
      case "icon":
        return group("Icon", lenRow("Size", "width", ["px", "rem", "em"]) + lenRow("Height", "height", ["px", "rem", "em"]) + colRow("Colour", "color")) + gSpacing();
      case "list":
        return gLayout(true) + (w.gridEl ? columnsControl(w.gridEl) : "") + gTypography(true, el) + gSpacing() + gBackground();
      case "text":
        return gTypography(true, el) + gSpacing();
      default: // container
        return gTypography(true, el) + gBackground() +
          group("Size", lenRow("Width", "width") + lenRow("Max width", "maxWidth") + lenRow("Min height", "minHeight")) +
          gBorder() + gSpacing() + gLayout(false) + (w.gridEl ? columnsControl(w.gridEl) : "") +
          group("Effects",
            rangeRow("Opacity", "opacity", 100) +
            selRow("Shadow", "boxShadow", [["", "—"], ["none", "None"], ["0 1px 3px rgba(0,0,0,.2)", "Small"], ["0 6px 18px rgba(0,0,0,.15)", "Medium"], ["0 14px 40px rgba(0,0,0,.2)", "Large"]]));
    }
  }

  function renderInspector() {
    var ins = w.inspector; if (!ins) { return; }
    var el = w.selEl;
    if (el !== w._lastInsEl) { try { ins.scrollTop = 0; } catch (e) {} w._lastInsEl = el; }
    if (!el) {
      ins.innerHTML = '<div class="ds-insp-empty"><i class="fas fa-arrow-pointer"></i><p>Click anything on the canvas to design it.</p><p class="ds-insp-note">The controls here change to match what you pick — a title, an image, a link, or a repeating list.</p></div>';
      clearGridHandles(); clearSpacingHandles();
      return;
    }
    w.gridEl = findGrid(el);
    var kind = resolveKind(el);
    var canParent = el.parentElement && el.parentElement !== w.root;
    ins.innerHTML =
      '<div class="ds-insp-head"><span class="ds-insp-kind">' + esc(DS.KIND_LABEL[kind] || "Element") + '</span>' +
        '<span class="ds-insp-sub">' + esc(friendlyEl(el)) + '</span>' +
        (canParent ? '<button type="button" class="ds-insp-parent" title="Select the element around this one"><i class="fas fa-arrow-up"></i> Parent</button>' : "") + '</div>' +
      groupsForKind(kind, el);
    wireInspector();
    if (w.gridEl && (kind === "list" || kind === "container")) { mountGridHandles(w.gridEl); } else { clearGridHandles(); }
    // Figma-style padding (inner) + margin (outer) drag handles on any element.
    mountSpacingHandles(el);
  }

  function wireInspector() {
    var ins = w.inspector;
    ins.querySelectorAll(".ds-insp-b4").forEach(function (i) {
      i.addEventListener("input", function () { applyEl(i.dataset.prop, i.value === "" ? "" : (i.value + "px")); });
      // Scrub: drag left/right on the field to change the value (a plain click
      // still lets you type). Mirrors the on-canvas padding drag.
      i.addEventListener("mousedown", function (e) {
        var startX = e.clientX, startVal = parseFloat(i.value) || 0, scrubbing = false;
        function mv(ev) {
          if (!scrubbing && Math.abs(ev.clientX - startX) < 3) { return; }
          scrubbing = true; ev.preventDefault();
          var nv = Math.max(0, Math.round(startVal + (ev.clientX - startX)));
          i.value = nv; applyEl(i.dataset.prop, nv + "px");
          if (w._spaceEl && w.selEl) { renderSpacingHandles(); }
        }
        function up() { document.removeEventListener("mousemove", mv); document.removeEventListener("mouseup", up); document.body.classList.remove("ds-scrubbing"); }
        document.addEventListener("mousemove", mv); document.addEventListener("mouseup", up);
        document.body.classList.add("ds-scrubbing");
      });
    });
    // Padding/Margin canvas toggle — chooses which drag handles the canvas shows.
    ins.querySelectorAll(".ds-spacemode button[data-mode]").forEach(function (b) {
      b.addEventListener("click", function () {
        w.spaceMode = b.getAttribute("data-mode");
        ins.querySelectorAll(".ds-spacemode button").forEach(function (x) { x.classList.toggle("on", x === b); });
        renderSpacingHandles();
      });
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
    ins.querySelectorAll(".ds-insp-fmt button[data-fmt]").forEach(function (b) {
      b.addEventListener("mousedown", function (e) { e.preventDefault(); });
      b.addEventListener("click", function (e) {
        e.preventDefault();
        switch (b.getAttribute("data-fmt")) {
          case "b": wrapInline("strong", ["STRONG", "B"]); break;
          case "i": wrapInline("em", ["EM", "I"]); break;
          case "u": wrapInline("u", ["U"]); break;
          case "s": wrapInline("s", ["S", "STRIKE", "DEL"]); break;
          case "sup": wrapInline("sup", ["SUP"]); break;
          case "sub": wrapInline("sub", ["SUB"]); break;
          case "ul": listSel(false); break;
          case "ol": listSel(true); break;
          case "link": openLinkDialog(); break;
          case "unlink": if (w.doc && !w.srcMode) { var a = currentAnchor(); if (a) { unwrap(a); changed(); } } break;
          case "clear": clearFormat(); break;
        }
      });
    });
    ins.querySelectorAll(".ds-insp-font").forEach(function (s) {
      s.addEventListener("mousedown", function () { rememberRange(); });
      s.addEventListener("change", function () { styleTextOrEl("fontFamily", s.value); });
    });
    ins.querySelectorAll(".ds-insp-fmtblock").forEach(function (s) {
      s.addEventListener("mousedown", function () { rememberRange(); });
      s.addEventListener("change", function () { if (s.value) { formatBlockSel(s.value); setTimeout(renderInspector, 0); } });
    });
    ins.querySelectorAll(".ds-insp-fmt button[data-col]").forEach(function (b) {
      b.addEventListener("click", function (e) {
        e.preventDefault();
        if (b.getAttribute("data-col") === "add") { addColumn(); } else { removeColumn(); }
        setTimeout(renderInspector, 0);
      });
    });
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
          '<aside class="ds-he-rail">' +
            '<div class="ds-he-rail-sec">' +
              '<button type="button" class="ds-he-ins" id="dsHeIns"><i class="fas fa-plus"></i> Insert element</button>' +
              '<div class="ds-he-insmenu" id="dsHeInsMenu" hidden></div>' +
            '</div>' +
            '<div class="ds-he-rail-sec">' +
              '<div class="ds-he-rail-h">Placeholder</div>' +
              '<select class="ds-he-tok" id="dsHeTokSel"><option value="">Insert placeholder…</option></select>' +
              '<div class="ds-he-rail-note">Every placeholder — this block first, then shared &amp; all blocks.</div>' +
            '</div>' +
            '<div class="ds-he-rail-sec ds-he-rail-grow">' +
              '<div class="ds-he-rail-h">Layers</div>' +
              '<div class="ds-he-layers" id="dsHeLayers"></div>' +
            '</div>' +
          '</aside>' +
          '<div class="ds-he-stage">' +
            '<iframe class="ds-he-frame" id="dsHeFrame" title="Block editor"></iframe>' +
            '<textarea class="ds-he-src" id="dsHeSrc" spellcheck="false" style="display:none"></textarea>' +
          '</div>' +
          '<aside class="ds-he-inspector" id="dsHeInsp"></aside>' +
        '</div>';
      w.container = container.querySelector(".ds-he");
      w.frame = container.querySelector("#dsHeFrame");
      w.src = container.querySelector("#dsHeSrc");
      w.inspector = container.querySelector("#dsHeInsp");
      w.layers = container.querySelector("#dsHeLayers");
      w.insMenu = container.querySelector("#dsHeInsMenu");
      w.src.addEventListener("input", changed);
      buildRail(container);
      fillInsertMenu(w.type);
      renderInspector();
      // live section list for the link picker
      DS.api("link_targets", {}).then(function (j) { w.sections = j.sections || []; }).catch(function () { w.sections = []; });
    },
    setHtml: setHtml,
    getHtml: getTemplate,
    toggleSource: toggleSource,
    cancelPending: function () { clearTimeout(changeTimer); },
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
