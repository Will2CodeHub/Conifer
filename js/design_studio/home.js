/* TEN Design Studio — Design Home: two-column hub (Pages | Blocks),
   live thumbnails, create page/template/block, Publish + History. */
(function () {
  "use strict";
  var DS = window.DS = window.DS || {};

  function el(tag, cls, html) {
    var n = document.createElement(tag);
    if (cls) { n.className = cls; }
    if (html != null) { n.innerHTML = html; }
    return n;
  }

  // ---- tiles ------------------------------------------------------------
  function tile(opts) {
    // opts: {thumb, icon, title, meta, onClick, cls}
    var t = el("button", "ds-tile " + (opts.cls || ""));
    t.type = "button";
    var thumbHtml;
    if (opts.thumb) {
      thumbHtml = '<div class="ds-tile-thumb"><iframe loading="lazy" tabindex="-1" aria-hidden="true" src="' + DS.esc(opts.thumb) + '"></iframe></div>';
    } else {
      thumbHtml = '<div class="ds-tile-thumb ds-tile-thumb-icon"><i class="fas ' + DS.esc(opts.icon || "fa-plus") + '"></i></div>';
    }
    t.innerHTML = thumbHtml +
      '<div class="ds-tile-foot"><span class="ds-tile-name">' + DS.esc(opts.title) + '</span>' +
      (opts.meta ? '<span class="ds-tile-meta">' + DS.esc(opts.meta) + '</span>' : '') + '</div>';
    t.addEventListener("click", opts.onClick);
    var ifr = t.querySelector("iframe");
    if (ifr) { ifr.addEventListener("load", function () { ifr.setAttribute("data-loaded", "1"); }); }
    return t;
  }

  // ---- screen -----------------------------------------------------------
  DS.renderHome = function () {
    var ds = DS.ds;
    var root = document.getElementById("dsHome");
    root.innerHTML =
      '<header class="ds-topbar ds-home-bar">' +
        '<a class="ds-back" href="module-news.php"><i class="fas fa-arrow-left"></i> All sites</a>' +
        '<h1 class="ds-home-title">' + DS.esc(ds.pub.toUpperCase()) + '<span class="ds-home-sub">Design</span></h1>' +
        '<div class="ds-grow"></div>' +
        '<div class="ds-seg" role="group" aria-label="Device">' +
          '<button data-dev="desktop" class="' + (ds.device === "desktop" ? "on" : "") + '">Desktop</button>' +
          '<button data-dev="mobile" class="' + (ds.device === "mobile" ? "on" : "") + '">Mobile</button>' +
        '</div>' +
        '<button class="ds-btn ds-btn-ghost" id="dsHistory"><i class="fas fa-clock-rotate-left"></i> History</button>' +
        '<button class="ds-btn ds-btn-primary" id="dsPublish"><i class="fas fa-rocket"></i> Publish</button>' +
      '</header>' +
      '<div class="ds-hub">' +
        '<section class="ds-col">' +
          '<div class="ds-col-head"><h2>Pages</h2><p class="ds-col-say">Whole pages built from blocks. Start with a template.</p></div>' +
          '<div class="ds-tiles" id="dsPageTiles"><p class="ds-loading">Loading pages…</p></div>' +
        '</section>' +
        '<section class="ds-col">' +
          '<div class="ds-col-head"><h2>Blocks</h2><p class="ds-col-say">Reusable pieces — menus, feeds, footers — shared across pages.</p></div>' +
          '<div class="ds-tiles" id="dsBlockTiles"><p class="ds-loading">Loading blocks…</p></div>' +
        '</section>' +
      '</div>';

    root.querySelectorAll(".ds-seg button").forEach(function (b) {
      b.addEventListener("click", function () { ds.device = b.dataset.dev; DS.renderHome(); });
    });
    root.querySelector("#dsPublish").addEventListener("click", openPublish);
    root.querySelector("#dsHistory").addEventListener("click", openHistory);

    loadPageTiles();
    loadBlockTiles();
  };

  function loadPageTiles() {
    var wrap = document.getElementById("dsPageTiles");
    Promise.all([DS.api("list_pages", {}), DS.api("list_templates", {})]).then(function (r) {
      var pages = r[0], tpls = r[1].templates || [];
      DS.ds._tpls = tpls;
      wrap.innerHTML = "";
      wrap.appendChild(tile({
        icon: "fa-layer-group", title: "Page templates",
        meta: tpls.length + (tpls.length === 1 ? " template" : " templates"),
        cls: "ds-tile-feature", onClick: function () { openTemplatePicker(); }
      }));
      (pages.builtin || []).forEach(function (p) { wrap.appendChild(pageTile(p)); });
      (pages.pages || []).forEach(function (p) { wrap.appendChild(pageTile(p)); });
      wrap.appendChild(tile({ icon: "fa-plus", title: "New page", cls: "ds-tile-new", onClick: openNewPage }));
      wrap.appendChild(tile({ icon: "fa-plus", title: "New template", cls: "ds-tile-new", onClick: openNewTemplate }));
    }).catch(function (e) { wrap.innerHTML = '<p class="ds-err">' + DS.esc(e.message) + '</p>'; });
  }

  function pageTile(p) {
    return tile({
      thumb: DS.previewUrl({ page: p.slug, thumb: true }),
      title: p.title || p.slug,
      meta: p.created_by ? "Custom page" : null,
      onClick: function () { DS.dsGo("page", { slug: p.slug, kind: "page" }); }
    });
  }

  function loadBlockTiles() {
    var wrap = document.getElementById("dsBlockTiles");
    DS.api("list_blocks", {}).then(function (r) {
      var names = r.names || {};
      wrap.innerHTML = "";
      Object.keys(DS.REG).forEach(function (type) {
        wrap.appendChild(tile({
          thumb: DS.previewUrl({ block: type, thumb: true }),
          title: DS.REG[type].label,
          onClick: function () { DS.dsGo("block", { type: type }); }
        }));
      });
      Object.keys(names).forEach(function (type) {
        wrap.appendChild(tile({
          thumb: DS.previewUrl({ block: type, thumb: true }),
          title: names[type].name, meta: "Custom block",
          onClick: function () { DS.dsGo("block", { type: type, name: names[type].name }); }
        }));
      });
      wrap.appendChild(tile({ icon: "fa-plus", title: "New block", cls: "ds-tile-new", onClick: openNewBlock }));
    }).catch(function (e) { wrap.innerHTML = '<p class="ds-err">' + DS.esc(e.message) + '</p>'; });
  }

  // ---- dialog helper ----------------------------------------------------
  function dialog(opts) {
    // opts: {title, body (html), okLabel, onOk(dlgEl) -> Promise|falsy, okClass}
    var ov = el("div", "ds-dlg-ov");
    var d = el("div", "ds-dlg");
    d.innerHTML =
      '<h3>' + DS.esc(opts.title) + '</h3>' +
      '<div class="ds-dlg-body">' + (opts.body || "") + '</div>' +
      '<div class="ds-dlg-actions">' +
        '<button class="ds-btn ds-btn-ghost" data-act="cancel">Cancel</button>' +
        (opts.okLabel ? '<button class="ds-btn ' + (opts.okClass || "ds-btn-primary") + '" data-act="ok">' + DS.esc(opts.okLabel) + '</button>' : '') +
      '</div>';
    ov.appendChild(d); document.body.appendChild(ov);
    function close() { ov.remove(); }
    ov.addEventListener("click", function (e) { if (e.target === ov) { close(); } });
    d.querySelector('[data-act="cancel"]').addEventListener("click", close);
    var okBtn = d.querySelector('[data-act="ok"]');
    if (okBtn) {
      okBtn.addEventListener("click", function () {
        var r = opts.onOk ? opts.onOk(d) : null;
        if (r && r.then) { okBtn.disabled = true; r.then(close).catch(function (e) { okBtn.disabled = false; DS.toast(e.message); }); }
        else if (r !== false) { close(); }
      });
    }
    var first = d.querySelector("input, select, textarea"); if (first) { first.focus(); }
    return { el: d, close: close };
  }

  // ---- create flows -----------------------------------------------------
  function openNewPage() {
    var tpls = DS.ds._tpls || [];
    var opts = tpls.map(function (t) { return '<option value="' + DS.esc(t.id) + '">' + DS.esc(t.name) + '</option>'; }).join("");
    dialog({
      title: "New page",
      okLabel: "Create page",
      body:
        '<label class="ds-f"><span>Name</span><input id="npTitle" type="text" placeholder="e.g. Guides"></label>' +
        '<label class="ds-f"><span>Web address</span><input id="npSlug" type="text" placeholder="guides"><small>Lowercase letters, numbers and hyphens. The page will live at /design/&lt;address&gt;.</small></label>' +
        '<label class="ds-f"><span>Based on template</span><select id="npTpl">' + opts + '</select></label>',
      onOk: function (d) {
        var title = d.querySelector("#npTitle").value.trim();
        var slug = d.querySelector("#npSlug").value.trim() || title.toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, "");
        var tpl = d.querySelector("#npTpl").value;
        if (!title) { throw new Error("Give the page a name"); }
        return DS.api("create_page", { slug: slug, title: title, template: tpl }).then(function (res) {
          DS.toast("Page created");
          DS.dsGo("page", { slug: res.page.slug, kind: "page" });
        });
      }
    });
    // live slug suggestion
    var dlg = document.querySelector(".ds-dlg");
    if (dlg) {
      var tt = dlg.querySelector("#npTitle"), ss = dlg.querySelector("#npSlug");
      tt.addEventListener("input", function () {
        if (!ss.dataset.touched) { ss.value = tt.value.toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-|-$/g, ""); }
      });
      ss.addEventListener("input", function () { ss.dataset.touched = "1"; });
    }
  }

  function openNewTemplate() {
    var tpls = DS.ds._tpls || [];
    var baseOpts = tpls.map(function (t) { return '<option value="' + DS.esc(t.id) + '">' + DS.esc(t.name) + '</option>'; }).join("");
    dialog({
      title: "New template",
      okLabel: "Create template",
      body: '<label class="ds-f"><span>Name</span><input id="ntName" type="text" placeholder="e.g. Long read"></label>' +
            '<label class="ds-f"><span>Base it on</span><select id="ntBase">' + baseOpts + '</select></label>' +
            '<p class="ds-dlg-say">The new template starts as a copy of the base template’s blocks and page settings, which you can then change. New pages can be based on it.</p>',
      onOk: function (d) {
        var name = d.querySelector("#ntName").value.trim();
        var base = d.querySelector("#ntBase").value;
        if (!name) { throw new Error("Give the template a name"); }
        return DS.api("create_template", { name: name, base: base }).then(function (res) {
          DS.toast("Template created");
          DS.dsGo("page", { slug: res.template.id, kind: "template", id: res.template.id, name: res.template.name });
        });
      }
    });
  }

  function openNewBlock() {
    dialog({
      title: "New block",
      okLabel: "Create block",
      body: '<label class="ds-f"><span>Name</span><input id="nbName" type="text" placeholder="e.g. Sponsor strip"></label>' +
            '<p class="ds-dlg-say">A block is a reusable piece you can place on any page and edit in one place.</p>',
      onOk: function (d) {
        var name = d.querySelector("#nbName").value.trim();
        if (!name) { throw new Error("Give the block a name"); }
        return DS.api("create_block", { name: name }).then(function (res) {
          DS.toast("Block created");
          DS.dsGo("block", { type: res.type, name: name });
        });
      }
    });
  }

  function openTemplatePicker() {
    var tpls = DS.ds._tpls || [];
    var rows = tpls.map(function (t) {
      return '<button class="ds-pick-row" data-id="' + DS.esc(t.id) + '">' +
        '<span class="ds-pick-name">' + DS.esc(t.name) + '</span>' +
        (t.created_by ? '<span class="ds-tile-meta">yours</span>' : '<span class="ds-tile-meta">built-in</span>') +
        '</button>';
    }).join("");
    var dlg = dialog({
      title: "Page templates",
      body: '<p class="ds-dlg-say">Edit a template to change the page frame (top menu, footer, margins) for every page based on it.</p>' +
            '<div class="ds-pick">' + rows + '</div>'
    });
    dlg.el.querySelectorAll(".ds-pick-row").forEach(function (b) {
      b.addEventListener("click", function () {
        var id = b.dataset.id;
        var t = tpls.filter(function (x) { return x.id === id; })[0] || {};
        dlg.close();
        DS.dsGo("page", { slug: id, kind: "template", id: id, name: t.name });
      });
    });
  }

  // ---- publish + history ------------------------------------------------
  function openPublish() {
    dialog({
      title: "Publish design",
      okLabel: "Publish now",
      body: '<p class="ds-dlg-say">This makes every saved change live on the public site.</p>' +
            '<label class="ds-f"><span>Label (optional)</span><input id="pubLabel" type="text" placeholder="e.g. Autumn refresh"></label>',
      onOk: function (d) {
        return DS.api("publish", { label: d.querySelector("#pubLabel").value.trim() })
          .then(function (res) { DS.toast("Published version " + res.version_no); });
      }
    });
  }

  function openHistory() {
    DS.api("list_versions", {}).then(function (r) {
      var versions = r.versions || [];
      var rows = versions.length ? versions.map(function (v) {
        return '<div class="ds-hist-row">' +
          '<div><span class="ds-hist-v">v' + DS.esc(v.version_no) + (v.label ? ' · ' + DS.esc(v.label) : '') + '</span>' +
          (parseInt(v.is_live, 10) ? '<span class="ds-live-pill">Live</span>' : '') +
          '<span class="ds-tile-meta">' + DS.esc(v.created_at) + ' · ' + DS.esc(v.created_by || '') + '</span></div>' +
          (parseInt(v.is_live, 10) ? '' : '<button class="ds-btn ds-btn-ghost ds-sm" data-v="' + DS.esc(v.version_no) + '">Restore</button>') +
          '</div>';
      }).join("") : '<p class="ds-dlg-say">No published versions yet.</p>';
      var dlg = dialog({ title: "Version history", body: '<div class="ds-hist">' + rows + '</div>' });
      dlg.el.querySelectorAll("[data-v]").forEach(function (b) {
        b.addEventListener("click", function () {
          var vno = b.dataset.v;
          b.disabled = true;
          DS.api("rollback", { version_no: vno }).then(function () {
            DS.toast("Restored version " + vno); dlg.close(); DS.renderHome();
          }).catch(function (e) { b.disabled = false; DS.toast(e.message); });
        });
      });
    }).catch(function (e) { DS.toast(e.message); });
  }
})();
