/* TEN Scraper — Curate tab.
 * Per-publication sub-tabs, each showing the top N AI-ranked, region-relevant
 * stories (N = that section's daily limit). Select all + publish straight to the
 * live front page (state=published, flagged imageless). Publishing is chunked so
 * a big batch of AI writes doesn't exceed the request timeout.
 */
(function () {
    "use strict";
    var EP = "ajax/scraper_review.php";
    var inited = false, pubs = [], current = null;

    function el(id) { return document.getElementById(id); }
    function esc(s) {
        return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
            return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
        });
    }
    function post(params) {
        var fd = new FormData();
        Object.keys(params).forEach(function (k) { fd.append(k, params[k]); });
        return fetch(EP, { method: "POST", body: fd, credentials: "same-origin" }).then(function (r) { return r.json(); });
    }

    window.scCurateInit = function () {
        if (inited) return; inited = true;
        var tabs = el("scCuratePubTabs");
        tabs.innerHTML = "<span class='scraper-placeholder'>Loading publications…</span>";
        post({ action: "curate_pubs" }).then(function (j) {
            if (!j.success) { tabs.innerHTML = "<span class='scraper-placeholder'>Failed to load publications.</span>"; return; }
            pubs = j.publications || [];
            if (!pubs.length) { tabs.innerHTML = ""; el("scCurateBody").innerHTML = "<p class='scraper-placeholder'>No publications configured.</p>"; return; }
            tabs.innerHTML = pubs.map(function (p, i) {
                return '<button class="sc-subtab' + (i === 0 ? ' active' : '') + '" data-pub="' + i + '">' +
                    esc(p.publication_key.toUpperCase()) + ' <span style="opacity:.55">(' + p.new_count + ')</span></button>';
            }).join("");
            Array.prototype.forEach.call(tabs.querySelectorAll("button[data-pub]"), function (b) {
                b.addEventListener("click", function () {
                    Array.prototype.forEach.call(tabs.querySelectorAll("button"), function (x) { x.classList.remove("active"); });
                    b.classList.add("active");
                    loadPub(pubs[parseInt(b.getAttribute("data-pub"), 10)]);
                });
            });
            loadPub(pubs[0]);
        }).catch(function (e) { tabs.innerHTML = "<span class='scraper-placeholder'>Error: " + esc(e.message) + "</span>"; });
    };

    function loadPub(p) {
        current = p;
        var body = el("scCurateBody");
        body.innerHTML = "<p class='scraper-placeholder'><i class='fas fa-spinner fa-spin'></i> Ranking the top " + p.top_n +
            " stories for " + esc(p.publication_key.toUpperCase()) + "…</p>";
        post({ action: "curate_suggest", pub_section_id: p.section_id, top_n: p.top_n }).then(function (j) {
            if (!j.success) { body.innerHTML = "<p class='scraper-placeholder'>Failed: " + esc(j.message || "") + "</p>"; return; }
            renderList(j.items || []);
        }).catch(function (e) { body.innerHTML = "<p class='scraper-placeholder'>Error: " + esc(e.message) + "</p>"; });
    }

    function renderList(items) {
        var body = el("scCurateBody");
        if (!items.length) { body.innerHTML = "<p class='scraper-placeholder'>No new stories to curate for this publication yet — check back after the next ingest.</p>"; return; }
        var rows = items.map(function (it) {
            return '<label style="display:flex;gap:10px;align-items:flex-start;padding:10px 12px;border:1px solid #e5e7eb;border-radius:8px;margin-bottom:8px;background:#fff;cursor:pointer;">' +
                '<input type="checkbox" class="scCurCk" value="' + it.id + '" checked style="margin-top:3px;width:16px;height:16px;flex-shrink:0;">' +
                '<span><span style="font-weight:600;color:#111827;">' + esc(it.title) + '</span>' +
                (it.reason ? '<br><span style="font-size:12px;color:#6b7280;"><i class="fas fa-wand-magic-sparkles"></i> ' + esc(it.reason) + '</span>' : '') +
                '</span></label>';
        }).join("");
        body.innerHTML =
            '<div class="sc-toolbar" style="align-items:center;margin-bottom:8px;">' +
                '<label style="font-size:13px;font-weight:600;color:#374151;"><input type="checkbox" id="scCurAll" checked> Select all (' + items.length + ')</label>' +
                '<button class="sc-btn" id="scCurPublish"><i class="fas fa-bolt"></i> Publish selected</button>' +
                '<span id="scCurStatus" class="scraper-placeholder"></span>' +
            '</div><div id="scCurRows">' + rows + '</div>';
        el("scCurAll").addEventListener("change", function () {
            var v = this.checked;
            Array.prototype.forEach.call(document.querySelectorAll(".scCurCk"), function (c) { c.checked = v; });
        });
        el("scCurPublish").addEventListener("click", publish);
    }

    function publish() {
        var ids = Array.prototype.map.call(document.querySelectorAll(".scCurCk:checked"), function (c) { return c.value; });
        if (!ids.length) return;
        var CH = 4, chunks = [];
        for (var i = 0; i < ids.length; i += CH) chunks.push(ids.slice(i, i + CH));
        var btn = el("scCurPublish"), status = el("scCurStatus");
        var okN = 0, failN = 0, idx = 0;
        btn.disabled = true;
        function next() {
            if (idx >= chunks.length) {
                btn.disabled = false; btn.innerHTML = '<i class="fas fa-bolt"></i> Publish selected';
                status.textContent = "Published " + okN + (failN ? (" · " + failN + " failed") : "") + ". Front page refreshed.";
                if (typeof Swal !== "undefined") Swal.fire({ icon: "success", title: "Published " + okN, text: failN ? (failN + " failed.") : "All done.", timer: 2200, showConfirmButton: false });
                setTimeout(function () { loadPub(current); }, 1400);
                return;
            }
            var c = chunks[idx];
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Publishing ' + (okN + failN + c.length) + '/' + ids.length + '…';
            status.textContent = "Writing & publishing… " + (okN + failN) + "/" + ids.length;
            post({ action: "curate_publish", pub_section_id: current.section_id, ids: c.join(",") }).then(function (j) {
                if (j.success) { (j.results || []).forEach(function (r) { if (r.ok) okN++; else failN++; }); }
                else { failN += c.length; }
                idx++; next();
            }).catch(function () { failN += c.length; idx++; next(); });
        }
        next();
    }
})();
