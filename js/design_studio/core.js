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
