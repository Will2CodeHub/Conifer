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

  // Record a Save in this item's history (one snapshot per explicit Save).
  DS.addHistory = function (kind, id, snapshot) {
    return DS.api("add_history", { kind: kind, id: id, snapshot_json: JSON.stringify(snapshot) }).catch(function () {});
  };

  // Per-editor history: each Save is a revertable version (persists across sessions).
  DS.openItemHistory = function (kind, id, onRestore) {
    DS.api("list_history", { kind: kind, id: id }).then(function (r) {
      var items = r.history || [];
      var rows = items.length ? items.map(function (v, i) {
        return '<div class="ds-hist-row"><div><span class="ds-hist-v">' + DS.esc(v.created_at) +
          (i === 0 ? ' <span class="ds-live-pill">Latest</span>' : '') + '</span>' +
          '<span class="ds-tile-meta">saved by ' + DS.esc(v.created_by || '') + '</span></div>' +
          '<button class="ds-btn ds-btn-ghost ds-sm" data-h="' + DS.esc(v.id) + '">Restore</button></div>';
      }).join("") : '<p class="ds-dlg-say">No saved versions yet. Each time you press Save, a version is added here.</p>';
      var dlg = DS.dialog({ title: "Saved versions",
        body: '<p class="ds-dlg-say">Every Save adds a version. Restore one to roll this ' +
          (kind === "block" ? "block" : (kind === "template" ? "template" : "page")) + ' back to how it was.</p>' +
          '<div class="ds-hist">' + rows + '</div>' });
      dlg.el.querySelectorAll("[data-h]").forEach(function (b) {
        b.addEventListener("click", function () {
          b.disabled = true;
          DS.api("get_history", { hist_id: b.dataset.h })
            .then(function (res) { onRestore(res.snapshot); dlg.close(); DS.toast("Reverted"); })
            .catch(function (e) { b.disabled = false; DS.toast(e.message); });
        });
      });
    }).catch(function (e) { DS.toast(e.message); });
  };

  // Save guard (#3): editors set DS._dirty on change and clear it on Save. The
  // back button confirms before leaving, and a tab close/reload warns.
  DS._dirty = false;
  DS.setDirty = function (v) { DS._dirty = !!v; };
  DS.confirmLeave = function (onSaveLeave, onLeave) {
    var dlg = DS.dialog({ title: "Unsaved changes",
      body: '<p class="ds-dlg-say">You’ve made changes. Save them before leaving?</p>' +
        '<div class="ds-dlg-actions">' +
          '<button class="ds-btn ds-btn-ghost" data-g="leave">Leave without saving</button>' +
          '<button class="ds-btn ds-btn-primary" data-g="save">Save &amp; leave</button>' +
        '</div>' });
    dlg.el.querySelector('[data-g="save"]').addEventListener("click", function () { dlg.close(); onSaveLeave(); });
    dlg.el.querySelector('[data-g="leave"]').addEventListener("click", function () { dlg.close(); onLeave(); });
  };
  window.addEventListener("beforeunload", function (e) {
    if (DS._dirty) { e.preventDefault(); e.returnValue = ""; return ""; }
  });

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
