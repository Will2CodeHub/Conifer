/* TEN Design Studio — core: shared state, API, screen router, bootstrap.
   Loaded LAST so home/page/block modules have registered their render fns. */
(function () {
  "use strict";
  var DS = window.DS = window.DS || {};
  var ds = DS.ds = { pub: "", host: "", device: "desktop", screen: "home", params: {} };

  DS.esc = function (s) {
    return String(s == null ? "" : s).replace(/[&<>"]/g, function (c) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;" }[c];
    });
  };
  DS.clone = function (o) { return JSON.parse(JSON.stringify(o)); };

  DS.toast = function (msg) {
    var t = document.createElement("div");
    t.className = "ds-toast"; t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(function () { t.classList.add("ds-toast-in"); }, 10);
    setTimeout(function () { t.remove(); }, 2600);
  };

  DS.api = function (action, data) {
    var body = new URLSearchParams();
    body.set("action", action); body.set("pub", ds.pub);
    Object.keys(data || {}).forEach(function (k) { body.set(k, data[k]); });
    return fetch("ajax/design_layout.php", { method: "POST", body: body })
      .then(function (r) { return r.json(); })
      .then(function (j) { if (!j.success) { throw new Error(j.message || "Request failed"); } return j; });
  };

  // Preview URL on the publication's own /design/ engine.
  DS.previewUrl = function (opts) {
    opts = opts || {};
    var q = ["preview=1"];
    if (opts.page) { q.push("page=" + encodeURIComponent(opts.page)); }
    if (opts.block) { q.push("block=" + encodeURIComponent(opts.block)); }
    q.push("device=" + encodeURIComponent(opts.device || ds.device));
    if (opts.thumb) { q.push("thumb=1"); }
    q.push("_=" + Date.now());
    return "https://" + ds.host + "/design/?" + q.join("&");
  };

  // Generic overlay dialog (the dialog sits inside the overlay so it is never
  // hidden behind it).
  DS.dialog = function (opts) {
    var ov = document.createElement("div"); ov.className = "ds-dlg-ov";
    var d = document.createElement("div"); d.className = "ds-dlg";
    d.innerHTML = '<h3>' + DS.esc(opts.title) + '</h3><div class="ds-dlg-body">' + (opts.body || "") + '</div>' +
      '<div class="ds-dlg-actions"><button class="ds-btn ds-btn-ghost" data-x>Close</button></div>';
    ov.appendChild(d); document.body.appendChild(ov);
    function close() { ov.remove(); }
    ov.addEventListener("click", function (e) { if (e.target === ov) { close(); } });
    d.querySelector("[data-x]").addEventListener("click", close);
    return { el: d, close: close };
  };

  // Per-editor history: revert one block/page to a published version's content.
  DS.openItemHistory = function (kind, id, onRestore) {
    DS.api("list_versions", {}).then(function (r) {
      var versions = r.versions || [];
      var rows = versions.length ? versions.map(function (v) {
        return '<div class="ds-hist-row"><div><span class="ds-hist-v">v' + DS.esc(v.version_no) +
          (v.label ? ' · ' + DS.esc(v.label) : '') + '</span>' +
          (parseInt(v.is_live, 10) ? '<span class="ds-live-pill">Live</span>' : '') +
          '<span class="ds-tile-meta">' + DS.esc(v.created_at) + ' · ' + DS.esc(v.created_by || '') + '</span></div>' +
          '<button class="ds-btn ds-btn-ghost ds-sm" data-v="' + DS.esc(v.version_no) + '">Restore this</button></div>';
      }).join("") : '<p class="ds-dlg-say">No saved versions yet. Use Publish on the Design home to save one.</p>';
      var dlg = DS.dialog({ title: "Revert to a saved version",
        body: '<p class="ds-dlg-say">Restore this ' + (kind === "block" ? "block" : "page") +
          '’s content from a published version. Nothing else is changed, and it stays a draft until you Publish.</p>' +
          '<div class="ds-hist">' + rows + '</div>' });
      dlg.el.querySelectorAll("[data-v]").forEach(function (b) {
        b.addEventListener("click", function () {
          b.disabled = true;
          DS.api("get_version_item", { version_no: b.dataset.v, kind: kind, id: id })
            .then(function (res) { onRestore(res); dlg.close(); DS.toast("Reverted to v" + b.dataset.v); })
            .catch(function (e) { b.disabled = false; DS.toast(e.message); });
        });
      });
    }).catch(function (e) { DS.toast(e.message); });
  };

  var SCREENS = { home: "dsHome", page: "dsPage", block: "dsBlock" };
  function show(screen) {
    Object.keys(SCREENS).forEach(function (k) {
      document.getElementById(SCREENS[k]).hidden = (k !== screen);
    });
  }

  DS.dsGo = function (screen, params, fromPop) {
    ds.screen = screen; ds.params = params || {};
    if (!fromPop) {
      history.pushState({ screen: screen, params: ds.params }, "",
        "?pub=" + encodeURIComponent(ds.pub) + "#" + screen);
    }
    show(screen);
    if (screen === "home" && DS.renderHome) { DS.renderHome(); }
    else if (screen === "page" && DS.renderPageEditor) { DS.renderPageEditor(ds.params); }
    else if (screen === "block" && DS.renderBlockEditor) { DS.renderBlockEditor(ds.params); }
  };

  window.addEventListener("popstate", function (e) {
    var s = (e.state && e.state.screen) || "home";
    DS.dsGo(s, (e.state && e.state.params) || {}, true);
  });

  function boot() {
    ds.pub = document.getElementById("dsApp").getAttribute("data-pub") || "";
    if (!ds.pub) { fail("No publication specified."); return; }
    DS.api("pub_info", {}).then(function (j) {
      ds.host = j.host;
      history.replaceState({ screen: "home", params: {} }, "",
        "?pub=" + encodeURIComponent(ds.pub) + "#home");
      DS.dsGo("home", {}, true);
    }).catch(function (e) { fail(e.message); });
  }
  function fail(msg) {
    var h = document.getElementById("dsHome");
    h.hidden = false;
    h.innerHTML = '<div class="ds-fatal"><i class="fas fa-triangle-exclamation"></i>' +
      '<p>' + DS.esc(msg) + '</p>' +
      '<p class="ds-muted">Design editing is available for publications with the design system switched on.</p></div>';
  }

  if (document.readyState !== "loading") { boot(); }
  else { document.addEventListener("DOMContentLoaded", boot); }
})();
