/* TEN Design Studio — block/page registry (JS mirror of lib/design_registry.php).
   Keep labels in sync with the PHP registry. */
(function () {
  "use strict";
  var DS = window.DS = window.DS || {};

  DS.PAGES = ["front", "section", "article", "author", "search", "impressum", "contact", "about", "privacy", "terms", "disclaimer"];
  DS.PAGE_LABELS = {
    front: "Front page", section: "Section page", article: "Article page", author: "Author page",
    search: "Search",
    impressum: "Impressum", contact: "Contact", about: "About us",
    privacy: "Privacy policy", terms: "Terms", disclaimer: "Disclaimer"
  };

  var P = DS.PAGES;
  DS.REG = {
    site_header:        { label: "Top menu / header",  pages: P,                                 defaults: { show_search: true, show_subscribe: true } },
    masthead:           { label: "Masthead",           pages: ["front"],                         defaults: { tagline: "" } },
    front_feature:      { label: "Front feature",      pages: ["front"],                         defaults: { mid_count: 2, sidebar_count: 6, hero_ratio: "3 / 2", mid_ratio: "16 / 9" } },
    front_sections:     { label: "Section blocks",     pages: ["front"],                         defaults: { per_section: 3, sections: "" } },
    headlines_list:     { label: "Latest headlines",   pages: ["front", "section"],              defaults: { count: 5, show_readtime: true } },
    section_title:      { label: "Section title",      pages: ["section"],                       defaults: { uppercase: true } },
    section_lead:       { label: "Section lead",       pages: ["section"],                       defaults: { sidebar_count: 6 } },
    article_grid:       { label: "Article grid",       pages: ["section"],                       defaults: { columns: 3, per_page: 12 } },
    article_header:     { label: "Article header",     pages: ["article"],                       defaults: {} },
    article_hero:       { label: "Article hero image", pages: ["article"],                       defaults: { ratio: "16x9" } },
    article_body:       { label: "Article body",       pages: ["article"],                       defaults: {} },
    article_sources:    { label: "Article sources",    pages: ["article"],                       defaults: { heading: "Sources" } },
    article_byline:     { label: "Byline",             pages: ["article"],                       defaults: {} },
    article_related:    { label: "Related articles",   pages: ["article"],                       defaults: { count: 3, heading: "" } },
    article_quickreads: { label: "Quick reads",        pages: ["article"],                       defaults: { count: 3, heading: "Quick Reads News" } },
    article_comments:   { label: "Comments",           pages: ["article"],                       defaults: { enabled: true } },
    author_profile:     { label: "Author profile",     pages: ["author"],                        defaults: {} },
    author_articles:    { label: "Author article list",pages: ["author"],                        defaults: { count: 12, heading: "" } },
    triple_box:         { label: "Triple box",         pages: ["front", "section"],              defaults: {} },
    section_carousel:   { label: "More stories carousel", pages: ["front", "section", "article"], defaults: { section: "", count: 10 } },
    advert:             { label: "Advert slot",        pages: ["front", "section", "article"],   defaults: { slot: "" } },
    ticker:             { label: "News ticker",        pages: ["front"],                         defaults: {} },
    breaking_news:      { label: "Breaking news",      pages: ["front"],                         defaults: { count: 6 } },
    rich_text:          { label: "Rich text",          pages: ["impressum", "contact", "about", "privacy", "terms", "disclaimer"], defaults: { content_key: "" } },
    about_page:         { label: "About page",         pages: ["about"],                         defaults: {} },
    contact_page:       { label: "Contact page",       pages: ["contact"],                       defaults: { action: "/backend/contact_form_handler.php" } },
    search_results:     { label: "Search results",     pages: ["search"],                        defaults: { per_page: 40 } },
    footer:             { label: "Footer",             pages: P,                                 defaults: {} }
  };

  // Blocks that belong to a page template's locked frame (header + footer).
  DS.FRAME_BLOCKS = { site_header: 1, footer: 1 };

  // Custom-block names {type:name} and built-in label overrides {type:label},
  // populated from the `list_blocks` AJAX response. The display label for any
  // block type resolves: label override → custom name → built-in label → type.
  DS.customNames = {};
  DS.labels = {};
  DS.ingestBlocks = function (r) {
    r = r || {};
    DS.customNames = {};
    var names = r.names || {};
    Object.keys(names).forEach(function (t) { DS.customNames[t] = names[t].name; });
    DS.labels = r.labels || {};
  };
  DS.blockLabel = function (type) {
    return DS.labels[type] || DS.customNames[type] || (DS.REG[type] && DS.REG[type].label) || type;
  };
  // True for a user-created block (no built-in PHP renderer).
  DS.isCustomBlock = function (type) { return /^blk_/.test(String(type)); };

  // --------------------------------------------------------------------
  //  Placeholder-token classification. Every {{token}} falls into one
  //  "kind"; the editor inspector shows only the controls that make sense
  //  for that kind (an image doesn't get letter-spacing, a date doesn't get
  //  object-fit). Element kinds (container/text/etc.) are resolved in the
  //  editor from the tag; this classifies the DATA tokens.
  //  Kinds: heading | body | meta | image | link | icon.  (List/repeating
  //  regions are detected structurally in the editor, not from a token name.)
  // --------------------------------------------------------------------
  DS.KIND_LABEL = {
    heading: "Heading", body: "Body text", meta: "Label / meta",
    image: "Image", link: "Link / button", icon: "Icon",
    list: "List / repeating", container: "Container", text: "Text"
  };

  var BODY_TOKENS = { standfirst: 1, summary: 1, snippet: 1, body: 1, bio: 1, teaser: 1, blurb: 1, value: 1, note: 1, text: 1 };
  var META_TOKENS = { date: 1, date_published: 1, date_updated: 1, read_time: 1, meta: 1, section: 1, caption: 1, copyright: 1, q: 1, count_label: 1, placeholder: 1 };
  var HEADING_TOKENS = { title: 1, name: 1, heading: 1, headlines_label: 1, tagline: 1 };

  // tok = the inner token name (no braces, no #each/#if markers).
  DS.tokenKind = function (tok) {
    var t = String(tok == null ? "" : tok).replace(/^\{*\s*|\s*\}*$/g, "").trim();
    t = t.replace(/^(#each|#if)\s+/, "");       // a loop/cond marker → classify its field
    var last = t.split(".").pop();              // hero.image → image
    if (/icon/i.test(last)) { return "icon"; }
    if (last === "image" || last === "logo" || last === "photo" || /_image$|_photo$|^photo_|avatar|thumb|logo/i.test(last)) { return "image"; }
    if (last === "href" || last === "url" || last === "email" || last === "action" ||
        /_href$|_url$|^href$|^url$/i.test(last) || /^(x_url|linkedin_url|facebook|twitter|instagram)$/i.test(last)) { return "link"; }
    if (HEADING_TOKENS[last]) { return "heading"; }
    if (BODY_TOKENS[last]) { return "body"; }
    if (META_TOKENS[last] || /_label$|_date$/i.test(last)) { return "meta"; }
    return "heading"; // default: short display text reads as a heading/title
  };
})();
