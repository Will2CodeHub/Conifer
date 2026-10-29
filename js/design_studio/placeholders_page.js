/* TEN Design Studio — Placeholders design page.
   A central screen listing EVERY placeholder (block) with ALL of its editable
   settings — article counts, columns, which section(s), headings, toggles —
   generated from the registry so nothing is left out. Each can also jump to its
   full designer or roll back its history. Values save to the shared _blocks draft
   (the same store the per-block Content panel writes); the engine reads the draft
   in preview and the published snapshot live, so changes show in the preview
   immediately and go live on Publish. */
(function () {
  "use strict";
  var DS = window.DS = window.DS || {};
  var store = { templates: {}, settings: {}, names: {} };
  var secList = [];   // section names for this publication (for the section pickers)

  // Friendlier labels for common setting keys (fallback = humanised key).
  var LABELS = {
    count: "Number of articles", mid_count: "Middle stories", sidebar_count: "Sidebar headlines",
    per_section: "Stories per section", per_page: "Articles per page", columns: "Columns",
    heading: "Heading", headlines_label: "Heading", tagline: "Tagline", title: "Title",
    show_readtime: "Show read time", show_search: "Show search", show_subscribe: "Show subscribe",
    uppercase: "Uppercase", enabled: "Enabled", section: "From which section", sections: "Which sections",
    hero_ratio: "Hero image ratio", mid_ratio: "Mid image ratio", ratio: "Image ratio",
    slot: "Advert slot id", content_key: "Content key", action: "Form action URL", viewall_label: "View-all label"
  };
  // Keys that are styling/plumbing rather than content — hidden from this page.
  var HIDE_KEYS = { styles: 1, links: 1, custom_css: 1, custom_html: 1, template: 1, spacing: 1, equal_height: 1 };

  function toObj(v) { return (v && typeof v === "object" && !Array.isArray(v)) ? v : {}; }
  function humanize(k) { return k.replace(/_/g, " ").replace(/^\w/, function (c) { return c.toUpperCase(); }); }
  function labelFor(k) { return LABELS[k] || humanize(k); }

  DS.renderPlaceholders = function () {
    var root = document.getElementById("dsPlaceholders");
    if (!root) { return; }
    root.innerHTML =
      '<header class="ds-topbar">' +
        '<button class="ds-back" id="dsPhBack"><i class="fas fa-arrow-left"></i> Design home</button>' +
        '<h1 class="ds-home-title">Placeholders <span class="ds-home-sub">design</span></h1>' +
        '<div class="ds-grow"></div>' +
        '<input type="search" id="dsPhSearch" class="ds-ph-search" placeholder="Filter placeholders…">' +
      '</header>' +
      '<div class="ds-ph-page">' +
        '<p class="ds-ph-intro">Every placeholder is listed here with all its settings — counts, columns, which section(s), headings and toggles. Changes save to this publication’s <b>draft</b>, so they appear in the <b>Preview</b> straight away; press <b>Publish</b> on the home screen to put them on the live site. Use <b>Design</b> for full styling, or <b>History</b> to roll one back.</p>' +
        '<div class="ds-ph-cards" id="dsPhCards"><p class="ds-loading">Loading placeholders…</p></div>' +
      '</div>';
    root.querySelector("#dsPhBack").addEventListener("click", function () { DS.dsGo("home"); });
    var search = root.querySelector("#dsPhSearch");
    if (search) {
      search.addEventListener("input", function () {
        var q = search.value.trim().toLowerCase();
        root.querySelectorAll(".ds-ph-card").forEach(function (c) {
          c.style.display = (!q || (c.getAttribute("data-q") || "").indexOf(q) !== -1) ? "" : "none";
        });
      });
    }
    load();
  };

  function load() {
    Promise.all([
      DS.api("load", { page: "_blocks", device: "desktop" }),
      DS.api("link_targets", {}).catch(function () { return { sections: [] }; })
    ]).then(function (res) {
      var layout = res[0].layout || {};
      store = { templates: toObj(layout.templates), settings: toObj(layout.settings), names: toObj(layout.names) };
      secList = (res[1].sections || []).map(function (s) { return s.name; }).filter(Boolean);
      renderCards();
    }).catch(function (e) { DS.toast(e.message || "Could not load placeholders"); });
  }

  // One editable control for a setting key, chosen by the default value's type
  // (section/sections get pickers; booleans get a checkbox; numbers a number box;
  // everything else a text box).
  function fieldControl(type, k, cur, defs) {
    var val = (cur[k] !== undefined ? cur[k] : defs[k]);
    if (k === "section") {
      var opts = '<option value=""' + (!val ? " selected" : "") + '>All sections (latest)</option>' +
        secList.map(function (n) { return '<option value="' + DS.esc(n) + '"' + (n === val ? " selected" : "") + '>' + DS.esc(n) + '</option>'; }).join("");
      return '<label class="ds-ph-f ds-ph-f-wide"><span>' + labelFor(k) + '</span>' +
        '<select data-type="' + type + '" data-sk="section">' + opts + '</select></label>';
    }
    if (k === "sections") {
      var chosen = String(val || "").split(",").map(function (s) { return s.trim(); }).filter(Boolean);
      var boxes = secList.length ? secList.map(function (n) {
        return '<label class="ds-ph-chk"><input type="checkbox" data-type="' + type + '" data-smulti="1" value="' + DS.esc(n) + '"' + (chosen.indexOf(n) !== -1 ? " checked" : "") + '> ' + DS.esc(n) + '</label>';
      }).join("") : '<span class="ds-ph-secnote">No sections found.</span>';
      return '<div class="ds-ph-sec"><span class="ds-ph-seclab">' + labelFor(k) + ' <em>(none ticked = all)</em></span><div class="ds-ph-chks">' + boxes + '</div></div>';
    }
    if (typeof defs[k] === "boolean") {
      return '<label class="ds-ph-chk ds-ph-f-wide"><input type="checkbox" data-type="' + type + '" data-k="' + k + '" data-kind="bool"' + (val ? " checked" : "") + '> ' + labelFor(k) + '</label>';
    }
    if (typeof defs[k] === "number") {
      return '<label class="ds-ph-f"><span>' + labelFor(k) + '</span>' +
        '<input type="number" min="0" step="1" data-type="' + type + '" data-k="' + k + '" data-kind="num" value="' + DS.esc(val) + '"></label>';
    }
    return '<label class="ds-ph-f ds-ph-f-wide"><span>' + labelFor(k) + '</span>' +
      '<input type="text" data-type="' + type + '" data-k="' + k + '" data-kind="str" value="' + DS.esc(val == null ? "" : val) + '"></label>';
  }

  function renderCards() {
    var host = document.getElementById("dsPhCards");
    if (!host) { return; }
    var pageLabels = DS.PAGE_LABELS || {};
    var html = Object.keys(DS.REG).map(function (type) {
      var reg = DS.REG[type];
      if (!reg) { return ""; }
      var label = DS.blockLabel ? DS.blockLabel(type) : (reg.label || type);
      var cur = store.settings[type] || {};
      var defs = reg.defaults || {};
      var keys = Object.keys(defs).filter(function (k) { return !HIDE_KEYS[k]; });
      var rows = keys.map(function (k) { return fieldControl(type, k, cur, defs); }).join("");
      var pages = (reg.pages || []).map(function (p) { return pageLabels[p] || p; });
      var tag = pages.length ? '<span class="ds-ph-pages">' + DS.esc(pages.join(" · ")) + '</span>' : '';
      var q = (label + " " + type + " " + pages.join(" ")).toLowerCase();
      return '<div class="ds-ph-card" data-q="' + DS.esc(q) + '">' +
        '<div class="ds-ph-card-h"><div><h3>' + DS.esc(label) + '</h3>' + tag + '</div>' +
          '<div class="ds-ph-card-act">' +
            '<button type="button" class="ds-ph-mini ds-ph-hist" data-type="' + type + '" title="Revert this placeholder"><i class="fas fa-clock-rotate-left"></i> History</button>' +
            '<button type="button" class="ds-ph-mini ds-ph-design" data-type="' + type + '" title="Open the full designer"><i class="fas fa-pen"></i> Design</button>' +
          '</div></div>' +
        (rows ? '<div class="ds-ph-fields">' + rows + '</div>'
              : '<p class="ds-ph-secnote">No adjustable settings — use <b>Design</b> to edit its layout.</p>') +
        '</div>';
    }).join("");
    host.innerHTML = html || '<p class="ds-ph-secnote">No placeholders found.</p>';
    wire(host);
  }

  function wire(host) {
    host.querySelectorAll('input[data-kind="num"]').forEach(function (i) {
      i.addEventListener("change", function () { saveNum(i.dataset.type, i.dataset.k, i.value); });
    });
    host.querySelectorAll('input[data-kind="str"]').forEach(function (i) {
      i.addEventListener("change", function () { saveVal(i.dataset.type, i.dataset.k, i.value); });
    });
    host.querySelectorAll('input[data-kind="bool"]').forEach(function (i) {
      i.addEventListener("change", function () { saveVal(i.dataset.type, i.dataset.k, i.checked); });
    });
    host.querySelectorAll("select[data-sk]").forEach(function (s) {
      s.addEventListener("change", function () { saveVal(s.dataset.type, s.dataset.sk, s.value); });
    });
    host.querySelectorAll("input[data-smulti]").forEach(function (c) {
      c.addEventListener("change", function () {
        var type = c.dataset.type;
        var vals = Array.prototype.slice.call(host.querySelectorAll('input[data-smulti][data-type="' + type + '"]:checked')).map(function (x) { return x.value; });
        saveVal(type, "sections", vals.join(","));
      });
    });
    host.querySelectorAll(".ds-ph-design").forEach(function (b) {
      b.addEventListener("click", function () { DS.dsGo("block", { type: b.dataset.type }); });
    });
    host.querySelectorAll(".ds-ph-hist").forEach(function (b) {
      b.addEventListener("click", function () {
        if (!DS.openItemHistory) { DS.toast("History unavailable"); return; }
        DS.openItemHistory("block", b.dataset.type, function (snap) {
          if (!snap) { return; }
          if (snap.settings) { store.settings[b.dataset.type] = snap.settings; }
          if (snap.template != null) { store.templates[b.dataset.type] = snap.template; }
          persist().then(function () { renderCards(); DS.toast("Reverted"); }).catch(function (e) { DS.toast(e.message); });
        });
      });
    });
  }

  function saveNum(type, k, val) {
    var defs = (DS.REG[type] && DS.REG[type].defaults) || {};
    var num = (val === "" ? defs[k] : Number(val));
    if (num == null || isNaN(num) || num < 0) { num = defs[k]; }
    saveVal(type, k, num);
  }

  function saveVal(type, k, val) {
    store.settings[type] = Object.assign({}, store.settings[type] || {});
    store.settings[type][k] = val;
    persist().then(function () { DS.toast("Saved"); }).catch(function (e) { DS.toast(e.message); });
  }

  function persist() {
    return DS.api("save_draft", { page: "_blocks", device: "desktop",
      layout_json: JSON.stringify({ templates: store.templates, settings: store.settings, names: store.names }) });
  }
})();
