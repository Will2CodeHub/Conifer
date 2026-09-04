/* TEN Scraper — History table.
 * Paginated, sortable, searchable, filterable by publication / section / date range.
 * Filters (publication, section, dates, search) run server-side on Apply; sorting
 * and paging are client-side over the returned set.
 */
(function () {
    "use strict";
    var EP = "ajax/scraper_review.php";
    var projectId = 0, rows = [], sortKey = "fetched_at", sortDir = -1, page = 1, PER = 25, ready = false;

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
        el("scHResult").innerHTML = "<p class='scraper-placeholder'><i class='fas fa-spinner fa-spin'></i> Loading history…</p>";
        post({ action: "history_query", project_id: projectId,
               publication: el("scHPub").value, section: el("scHSec").value,
               from: el("scHFrom").value, to: el("scHTo").value, search: el("scHSearch").value })
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

    function badge(bg, fg, txt) {
        return '<span style="background:' + bg + ';color:' + fg + ';padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;white-space:nowrap;">' + txt + '</span>';
    }
    function statusCell(r) {
        switch (r.row_state) {
            case "published": return badge("#dcfce7", "#166534", "Published");
            case "draft":     return badge("#e0e7ff", "#3730a3", "Draft");
            case "ignored":   return badge("#fee2e2", "#991b1b", "Ignored");
            default:          return badge("#f1f5f9", "#475569", "Collated");
        }
    }
    function actionsCell(r) {
        var out = [];
        if (r.row_state === "published" && r.live_url) {
            out.push('<a href="' + esc(r.live_url) + '" target="_blank" rel="noopener" style="color:#166534;font-weight:600;text-decoration:none;">View live ↗</a>');
        }
        if ((r.row_state === "draft" || r.row_state === "ignored") && r.editor_url) {
            out.push('<a href="' + esc(r.editor_url) + '" target="_blank" rel="noopener" style="color:#3730a3;font-weight:600;text-decoration:none;">Edit ↗</a>');
        }
        if (r.article_id) {
            out.push('<a href="#" class="scHPrev-view" data-aid="' + r.article_id + '" style="color:#2563eb;text-decoration:none;">Preview</a>');
        }
        return out.join(' &nbsp;·&nbsp; ') || '<span style="color:#cbd5e1;">—</span>';
    }

    function openPreview(aid) {
        var ov = el("scHPreview");
        if (!ov) {
            ov = document.createElement("div");
            ov.id = "scHPreview";
            ov.style.cssText = "position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:99999;display:flex;align-items:flex-start;justify-content:center;padding:40px 16px;overflow:auto;";
            ov.addEventListener("click", function (e) { if (e.target === ov) ov.remove(); });
            document.body.appendChild(ov);
        }
        ov.innerHTML = '<div style="background:#fff;max-width:760px;width:100%;border-radius:12px;padding:24px 28px;box-shadow:0 20px 60px rgba(0,0,0,.3);">' +
            '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;"><span style="font-size:12px;color:#6b7280;">Article #' + aid + '</span>' +
            '<button id="scHPvClose" style="border:none;background:#f3f4f6;border-radius:8px;padding:6px 12px;cursor:pointer;font-size:13px;">Close</button></div>' +
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
        var cols = [["fetched_at", "Collated"], ["publication_key", "Publication"], ["ten_section", "Section"], ["title", "Title"], ["source_name", "Source"], ["article_id", "Article #"], ["row_state", "Status"]];
        var head = cols.map(function (c) {
            var arrow = sortKey === c[0] ? (sortDir > 0 ? " ▲" : " ▼") : "";
            return '<th data-k="' + c[0] + '" style="cursor:pointer;text-align:left;padding:8px 10px;border-bottom:2px solid #e5e7eb;font-size:12px;color:#374151;white-space:nowrap;user-select:none;">' + c[1] + arrow + '</th>';
        }).join("") + '<th style="text-align:left;padding:8px 10px;border-bottom:2px solid #e5e7eb;font-size:12px;color:#374151;white-space:nowrap;">Actions</th>';
        var body = slice.map(function (r) {
            return '<tr style="border-bottom:1px solid #f1f5f9;">' +
                '<td style="padding:7px 10px;white-space:nowrap;font-size:12px;color:#6b7280;">' + esc((r.fetched_at || "").replace("T", " ").slice(0, 16)) + '</td>' +
                '<td style="padding:7px 10px;font-size:12px;font-weight:600;">' + esc((r.publication_key || "").toUpperCase()) + '</td>' +
                '<td style="padding:7px 10px;font-size:12px;">' + esc(r.ten_section || "") + '</td>' +
                '<td style="padding:7px 10px;font-size:13px;max-width:460px;"><a href="' + esc(r.source_url || "#") + '" target="_blank" rel="noopener" style="color:#111827;text-decoration:none;" title="Open original source">' + esc(r.title || "") + '</a></td>' +
                '<td style="padding:7px 10px;font-size:12px;color:#6b7280;white-space:nowrap;">' + esc(r.source_name || "") + '</td>' +
                '<td style="padding:7px 10px;font-size:12px;color:#374151;white-space:nowrap;">' + (r.article_id ? ('#' + r.article_id) : '<span style="color:#cbd5e1;">—</span>') + '</td>' +
                '<td style="padding:7px 10px;white-space:nowrap;">' + statusCell(r) + '</td>' +
                '<td style="padding:7px 10px;white-space:nowrap;font-size:12px;">' + actionsCell(r) + '</td></tr>';
        }).join("");
        el("scHResult").innerHTML =
            '<div style="overflow-x:auto;border:1px solid #e5e7eb;border-radius:8px;"><table style="width:100%;border-collapse:collapse;background:#fff;"><thead><tr>' + head +
            '</tr></thead><tbody>' + (body || '<tr><td colspan="8" style="padding:18px;color:#9ca3af;">No matching history.</td></tr>') + '</tbody></table></div>' +
            '<div style="display:flex;justify-content:space-between;align-items:center;margin-top:10px;font-size:13px;color:#6b7280;"><span>' + total + ' row' + (total === 1 ? '' : 's') + '</span>' +
            '<span><button class="sc-btn secondary" id="scHPrev"' + (page <= 1 ? ' disabled' : '') + '>Prev</button> &nbsp;Page ' + page + ' / ' + pages + '&nbsp; <button class="sc-btn secondary" id="scHNext"' + (page >= pages ? ' disabled' : '') + '>Next</button></span></div>';
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
