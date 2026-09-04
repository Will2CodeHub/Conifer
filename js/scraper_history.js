/* TEN Scraper — History table.
 * Articles the scraper has PUBLISHED in the last 30 days, each linking to the
 * article on its canonical publication. Filters (publication, section, search)
 * run server-side on Apply; sorting and paging are client-side.
 */
(function () {
    "use strict";
    var EP = "ajax/scraper_review.php";
    var projectId = 0, rows = [], sortKey = "published_at", sortDir = -1, page = 1, PER = 25, ready = false;

    function el(id) { return document.getElementById(id); }
    function esc(s) { return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) { return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]; }); }
    function post(p) { var fd = new FormData(); Object.keys(p).forEach(function (k) { fd.append(k, p[k]); }); return fetch(EP, { method: "POST", body: fd, credentials: "same-origin" }).then(function (r) { return r.json(); }); }

    window.scHistoryInit = function () {
        var view = el("scViewHistory"); if (!view) return;
        projectId = view.getAttribute("data-project-id") || 0;
        if (ready) return; ready = true;
        el("scHApply").addEventListener("click", function () { page = 1; load(); });
        el("scHSearch").addEventListener("keydown", function (e) { if (e.key === "Enter") { page = 1; load(); } });
        load();
    };

    function load() {
        el("scHResult").innerHTML = "<p class='scraper-placeholder'><i class='fas fa-spinner fa-spin'></i> Loading articles…</p>";
        post({ action: "history_query", project_id: projectId,
               publication: el("scHPub").value, section: el("scHSec").value,
               state: el("scHState").value, from: el("scHFrom").value, to: el("scHTo").value,
               search: el("scHSearch").value })
        .then(function (j) {
            if (!j.success) { el("scHResult").innerHTML = "<p class='scraper-placeholder'>Failed: " + esc(j.message || "") + "</p>"; return; }
            rows = j.rows || [];
            if (j.filters) populateFilters(j.filters);
            render();
        }).catch(function (e) { el("scHResult").innerHTML = "<p class='scraper-placeholder'>Error: " + esc(e.message) + "</p>"; });
    }

    function populateFilters(f) {
        var pub = el("scHPub");
        if (pub.options.length <= 1) (f.publications || []).forEach(function (p) { var o = document.createElement("option"); o.value = p; o.textContent = p.toUpperCase(); pub.appendChild(o); });
        var sec = el("scHSec");
        if (sec.options.length <= 1) (f.sections || []).forEach(function (s) { var o = document.createElement("option"); o.value = s; o.textContent = s; sec.appendChild(o); });
    }

    function fmtDate(s) { return String(s || "").replace("T", " ").slice(0, 16); }
    function stateCell(r) {
        switch (r.state) {
            case "published":    return '<span class="sc-badge published">Published</span>';
            case "draft":        return '<span class="sc-badge draft">Draft</span>';
            case "under review": return '<span class="sc-badge collated">In Review</span>';
            case "expired":      return '<span class="sc-badge collated">Expired</span>';
            case "deleted":      return '<span class="sc-badge ignored">Deleted</span>';
            default:             return '<span class="sc-badge collated">' + esc(r.state || "—") + '</span>';
        }
    }
    function actionsCell(r) {
        var out = [];
        if (r.state === "published" && r.canonical_url) {
            var label = r.canonical_pub ? ("View on " + r.canonical_pub.toUpperCase() + " ↗") : "View live ↗";
            out.push('<a class="sc-link live" href="' + esc(r.canonical_url) + '" target="_blank" rel="noopener">' + esc(label) + '</a>');
        } else if (r.editor_url) {
            out.push('<a class="sc-link edit" href="' + esc(r.editor_url) + '" target="_blank" rel="noopener">Edit ↗</a>');
        }
        if (r.article_id) {
            out.push('<a href="#" class="sc-link view scHPrev-view" data-aid="' + r.article_id + '">Preview</a>');
        }
        return out.join('<span style="color:var(--sc-faint);"> · </span>') || '<span style="color:var(--sc-faint);">—</span>';
    }

    function openPreview(aid) {
        var ov = el("scHPreview");
        if (!ov) {
            ov = document.createElement("div");
            ov.id = "scHPreview";
            ov.className = "sc-app";
            ov.style.cssText = "position:fixed;inset:0;background:rgba(16,20,28,.5);backdrop-filter:blur(2px);z-index:99999;display:flex;align-items:flex-start;justify-content:center;padding:40px 16px;overflow:auto;";
            ov.addEventListener("click", function (e) { if (e.target === ov) ov.remove(); });
            document.body.appendChild(ov);
        }
        ov.innerHTML = '<div style="background:#fff;max-width:760px;width:100%;border-radius:12px;padding:24px 28px;box-shadow:0 20px 60px rgba(0,0,0,.3);">' +
            '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;"><span style="font-size:12px;color:#6b7280;">Article #' + aid + '</span>' +
            '<button id="scHPvClose" class="sc-btn secondary small">Close</button></div>' +
            '<div id="scHPvBody"><p class="scraper-placeholder"><i class="fas fa-spinner fa-spin"></i> Loading article…</p></div></div>';
        el("scHPvClose").addEventListener("click", function () { ov.remove(); });
        var fd = new FormData(); fd.append("id", aid);
        fetch("ajax/get_article_data.php", { method: "POST", body: fd, credentials: "same-origin" })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j || j.status !== "success") { el("scHPvBody").innerHTML = "<p class='scraper-placeholder'>Could not load article.</p>"; return; }
                el("scHPvBody").innerHTML =
                    '<h2 style="margin:0 0 6px;font-size:20px;color:#111827;">' + esc(j.title || "") + '</h2>' +
                    '<div style="font-size:12px;color:#6b7280;margin-bottom:14px;">State: ' + esc(j.state || "") + '</div>' +
                    '<div style="font-size:14px;line-height:1.6;color:#1f2937;max-height:55vh;overflow:auto;">' + (j.article || "<em>No body text.</em>") + '</div>';
            })
            .catch(function () { el("scHPvBody").innerHTML = "<p class='scraper-placeholder'>Error loading article.</p>"; });
    }

    function render() {
        var sorted = rows.slice().sort(function (a, b) {
            var av = (a[sortKey] == null ? "" : a[sortKey]).toString().toLowerCase();
            var bv = (b[sortKey] == null ? "" : b[sortKey]).toString().toLowerCase();
            return (av > bv ? 1 : av < bv ? -1 : 0) * sortDir;
        });
        var total = sorted.length, pages = Math.max(1, Math.ceil(total / PER));
        if (page > pages) page = pages;
        var slice = sorted.slice((page - 1) * PER, page * PER);
        var cols = [["published_at", "Date"], ["publication_key", "Publication"], ["ten_section", "Section"], ["title", "Title"], ["source_name", "Source"], ["article_id", "Article #"], ["state", "State"]];
        var head = cols.map(function (c) {
            var arrow = sortKey === c[0] ? (sortDir > 0 ? " ▲" : " ▼") : "";
            return '<th data-k="' + c[0] + '">' + c[1] + arrow + '</th>';
        }).join("") + '<th>Article link</th>';
        var body = slice.map(function (r) {
            var titleCell = r.canonical_url
                ? '<a href="' + esc(r.canonical_url) + '" target="_blank" rel="noopener" title="Open the published article">' + esc(r.title || "") + '</a>'
                : esc(r.title || "");
            return '<tr>' +
                '<td style="white-space:nowrap;color:var(--sc-muted);font-variant-numeric:tabular-nums;">' + esc(fmtDate(r.published_at)) + '</td>' +
                '<td style="font-weight:600;">' + esc((r.publication_key || "").toUpperCase()) + '</td>' +
                '<td>' + esc(r.ten_section || "") + '</td>' +
                '<td style="max-width:460px;">' + titleCell + '</td>' +
                '<td style="color:var(--sc-muted);white-space:nowrap;">' + esc(r.source_name || "") + '</td>' +
                '<td style="color:var(--sc-muted);white-space:nowrap;font-variant-numeric:tabular-nums;">' + (r.article_id ? ('#' + r.article_id) : '<span style="color:var(--sc-faint);">—</span>') + '</td>' +
                '<td style="white-space:nowrap;">' + stateCell(r) + '</td>' +
                '<td style="white-space:nowrap;">' + actionsCell(r) + '</td></tr>';
        }).join("");
        el("scHResult").innerHTML =
            '<div class="sc-tablewrap"><table class="sc-htable"><thead><tr>' + head +
            '</tr></thead><tbody>' + (body || '<tr><td colspan="8" style="padding:18px;color:var(--sc-faint);">No matching articles in this range.</td></tr>') + '</tbody></table></div>' +
            '<div style="display:flex;justify-content:space-between;align-items:center;margin-top:12px;font-size:13px;color:var(--sc-muted);"><span>' + total + ' row' + (total === 1 ? '' : 's') + '</span>' +
            '<span style="display:inline-flex;align-items:center;gap:10px;"><button class="sc-btn secondary small" id="scHPrev"' + (page <= 1 ? ' disabled' : '') + '>Prev</button> Page ' + page + ' / ' + pages + ' <button class="sc-btn secondary small" id="scHNext"' + (page >= pages ? ' disabled' : '') + '>Next</button></span></div>';
        Array.prototype.forEach.call(el("scHResult").querySelectorAll("th[data-k]"), function (th) {
            th.addEventListener("click", function () { var k = th.getAttribute("data-k"); if (sortKey === k) sortDir = -sortDir; else { sortKey = k; sortDir = 1; } render(); });
        });
        if (el("scHPrev")) el("scHPrev").addEventListener("click", function () { if (page > 1) { page--; render(); } });
        if (el("scHNext")) el("scHNext").addEventListener("click", function () { if (page < pages) { page++; render(); } });
        Array.prototype.forEach.call(el("scHResult").querySelectorAll("a.scHPrev-view"), function (a) {
            a.addEventListener("click", function (e) { e.preventDefault(); openPreview(a.getAttribute("data-aid")); });
        });
    }
})();
