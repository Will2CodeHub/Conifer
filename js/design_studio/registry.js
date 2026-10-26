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
})();
