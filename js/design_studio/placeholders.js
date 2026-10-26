/* TEN Design Studio — Placeholders manual (#4/#5).
   A reference panel listing every {{token}} the engine can fill, grouped by
   shared page area and by block, each copyable. Opened from Design Home and
   from the editors. Data comes from the `placeholders` AJAX action (single
   source of truth: dl_placeholder_defs in lib/design_registry.php). */
(function () {
  "use strict";
  var DS = window.DS = window.DS || {};
  var cache = null;      // resolved defs, fetched once
  var stylesInjected = false;

  function injectStyles() {
    if (stylesInjected) { return; }
    stylesInjected = true;
    var css =
      ".ds-ph-search{width:100%;padding:9px 12px;margin:0 0 14px;border:1px solid #d7d7e0;border-radius:8px;font:inherit}" +
      ".ds-ph-group{margin:0 0 18px}" +
      ".ds-ph-group>h4{margin:0 0 8px;font-size:.95rem;font-weight:700;color:#14142b}" +
      ".ds-ph-group>p.ds-ph-note{margin:-4px 0 10px;font-size:.8rem;color:#6b6b6b}" +
      ".ds-ph-row{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:7px 0;border-bottom:1px solid #f0f0f4}" +
      ".ds-ph-meta{display:flex;flex-direction:column;gap:1px;min-width:0}" +
      ".ds-ph-label{font-size:.85rem;color:#2a2a33;font-weight:600}" +
      ".ds-ph-eg{font-size:.72rem;color:#9a9aa6;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:260px}" +
      ".ds-ph-loop{display:inline-block;margin-left:6px;font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#8a6d00;background:#fff4cc;border-radius:4px;padding:1px 5px;vertical-align:middle}" +
      ".ds-ph-copy{flex:0 0 auto;display:inline-flex;align-items:center;gap:6px;border:1px solid #d7d7e0;background:#fafafd;border-radius:6px;padding:5px 9px;cursor:pointer;font:inherit;color:#1a2b4a}" +
      ".ds-ph-copy:hover{background:#eef1f8;border-color:#b9c2da}" +
      ".ds-ph-copy code{font-size:.78rem}" +
      ".ds-ph-empty{color:#6b6b6b;font-size:.85rem;padding:8px 0}";
    var s = document.createElement("style");
    s.setAttribute("data-ds-ph", "1");
    s.textContent = css;
    document.head.appendChild(s);
  }

  function copyText(t) {
    function done() { DS.toast("Copied " + t); }
    try {
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(t).then(done, fallback);
      } else { fallback(); }
    } catch (e) { fallback(); }
    function fallback() {
      var ta = document.createElement("textarea");
      ta.value = t; ta.style.position = "fixed"; ta.style.opacity = "0";
      document.body.appendChild(ta); ta.focus(); ta.select();
      try { document.execCommand("copy"); } catch (_) {}
      ta.remove(); done();
    }
  }

  // Load the defs once (promise cached).
  DS.loadPlaceholders = function () {
    if (cache) { return Promise.resolve(cache); }
    return DS.api("placeholders", {}).then(function (r) { cache = r.placeholders || { shared: {}, blocks: {} }; return cache; });
  };

  function tokenRow(it) {
    var tok = "{{" + it.token + "}}";
    return '<div class="ds-ph-row" data-q="' + DS.esc((it.label + " " + it.token).toLowerCase()) + '">' +
      '<div class="ds-ph-meta"><span class="ds-ph-label">' + DS.esc(it.label) +
        (it.loop ? '<span class="ds-ph-loop">in list</span>' : '') + '</span>' +
        (it.example ? '<span class="ds-ph-eg">e.g. ' + DS.esc(it.example) + '</span>' : '') +
      '</div>' +
      '<button type="button" class="ds-ph-copy" data-tok="' + DS.esc(tok) + '"><code>' + DS.esc(tok) + '</code> <i class="fas fa-copy"></i></button>' +
    '</div>';
  }

  function group(title, note, items) {
    if (!items || !items.length) { return ""; }
    return '<div class="ds-ph-group"><h4>' + DS.esc(title) + '</h4>' +
      (note ? '<p class="ds-ph-note">' + DS.esc(note) + '</p>' : '') +
      items.map(tokenRow).join("") + '</div>';
  }

  // Build the body HTML. If `blockType` is given, that block's own group is shown
  // first, then the shared groups; otherwise shared groups then every block.
  function buildBody(defs, blockType) {
    var shared = defs.shared || {}, blocks = defs.blocks || {};
    var html = "";
    if (blockType && blocks[blockType]) {
      html += group("This block — " + blocks[blockType].label, "Fields this block fills in directly.", blocks[blockType].items);
    }
    Object.keys(shared).forEach(function (k) {
      html += group(shared[k].label || k, "", shared[k].items);
    });
    if (!blockType) {
      Object.keys(blocks).forEach(function (t) {
        html += group(blocks[t].label || t, "", blocks[t].items);
      });
    }
    return html || '<p class="ds-ph-empty">No placeholders found.</p>';
  }

  function wire(root) {
    root.querySelectorAll(".ds-ph-copy").forEach(function (b) {
      b.addEventListener("click", function () { copyText(b.dataset.tok); });
    });
    var search = root.querySelector(".ds-ph-search");
    if (search) {
      search.addEventListener("input", function () {
        var q = search.value.trim().toLowerCase();
        root.querySelectorAll(".ds-ph-row").forEach(function (r) {
          r.style.display = (!q || (r.dataset.q || "").indexOf(q) !== -1) ? "" : "none";
        });
        root.querySelectorAll(".ds-ph-group").forEach(function (g) {
          var any = Array.prototype.some.call(g.querySelectorAll(".ds-ph-row"), function (r) { return r.style.display !== "none"; });
          g.style.display = any ? "" : "none";
        });
      });
    }
  }

  // Open the manual. opts.block = a block type to feature at the top.
  DS.openPlaceholders = function (opts) {
    opts = opts || {};
    injectStyles();
    DS.loadPlaceholders().then(function (defs) {
      var body =
        '<p class="ds-dlg-say">Put these in a block’s HTML to show live content. Click one to copy it — or copy the token (e.g. <code>{{title}}</code>) into an AI prompt.</p>' +
        '<input type="text" class="ds-ph-search" placeholder="Search placeholders…" aria-label="Search placeholders">' +
        '<div class="ds-ph-list">' + buildBody(defs, opts.block) + '</div>';
      var dlg = DS.dialog({ title: "Placeholders", body: body });
      wire(dlg.el);
    }).catch(function (e) { DS.toast(e.message || "Could not load placeholders"); });
  };
})();
