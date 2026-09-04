/* TEN Scraper — merged Curate & Promote screen.
 *
 *  Publication tabs (drag to reorder, saved per user)
 *    -> Section bar (choose section within the publication)
 *      -> Mode tabs: "Curated pick" (AI selection) | "All today" (full day's feed)
 *        -> Checkbox list + [Save as draft] / [Publish live]
 *
 *  All titles/summaries are pre-translated to English and rankings pre-computed by the
 *  cron (scraper/cron/precompute.php), so switching tabs just reads rows — no live AI.
 *  Promoted rows are badged and carry jump links: Edit (draft) / View live (published).
 */
(function () {
    "use strict";
    var EP = "ajax/scraper_review.php";
    var inited = false;
    var pubs = [];            // [{publication_key, section_id, ...}]
    var sections = [];        // sections for the active publication
    var cur = { pub: null, sectionId: 0, mode: "curated" };

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

    /* ---- init: load publications, build draggable tabs ---- */
    window.scCurateInit = function () {
        if (inited) return; inited = true;
        var tabs = el("scCuratePubTabs");
        tabs.innerHTML = "<span class='scraper-placeholder'>Loading publications…</span>";
        post({ action: "curate_pubs" }).then(function (j) {
            if (!j.success) { tabs.innerHTML = "<span class='scraper-placeholder'>Failed to load publications.</span>"; return; }
            pubs = j.publications || [];
            if (!pubs.length) { tabs.innerHTML = ""; el("scCurateBody").innerHTML = "<p class='scraper-placeholder'>No publications configured.</p>"; return; }
            renderPubTabs();
            selectPub(pubs[0]);
        }).catch(function (e) { tabs.innerHTML = "<span class='scraper-placeholder'>Error: " + esc(e.message) + "</span>"; });
        wireActions();
    };

    function renderPubTabs() {
        var tabs = el("scCuratePubTabs");
        tabs.innerHTML = pubs.map(function (p) {
            var on = cur.pub && p.publication_key === cur.pub.publication_key;
            return '<button class="sc-subtab' + (on ? ' active' : '') + '" draggable="true" data-pub="' + esc(p.publication_key) + '" title="Drag to reorder">' +
                esc(p.publication_key.toUpperCase()) + ' <span style="opacity:.55">(' + (p.new_count || 0) + ')</span></button>';
        }).join("");
        Array.prototype.forEach.call(tabs.querySelectorAll("button[data-pub]"), function (b) {
            b.addEventListener("click", function () {
                var key = b.getAttribute("data-pub");
                var p = pubs.filter(function (x) { return x.publication_key === key; })[0];
                if (p) selectPub(p);
            });
            b.addEventListener("dragstart", function (e) { e.dataTransfer.setData("text/plain", b.getAttribute("data-pub")); b.style.opacity = ".4"; });
            b.addEventListener("dragend", function () { b.style.opacity = ""; });
            b.addEventListener("dragover", function (e) { e.preventDefault(); });
            b.addEventListener("drop", function (e) {
                e.preventDefault();
                var from = e.dataTransfer.getData("text/plain"), to = b.getAttribute("data-pub");
                if (!from || from === to) return;
                var fi = idxOf(from), ti = idxOf(to);
                if (fi < 0 || ti < 0) return;
                var moved = pubs.splice(fi, 1)[0];
                pubs.splice(ti, 0, moved);
                renderPubTabs();
                savePubOrder();
            });
        });
    }
    function idxOf(key) { for (var i = 0; i < pubs.length; i++) if (pubs[i].publication_key === key) return i; return -1; }
    function savePubOrder() {
        post({ action: "save_pub_order", order: pubs.map(function (p) { return p.publication_key; }).join(",") });
    }

    /* ---- publication -> load its sections ---- */
    function selectPub(p) {
        cur.pub = p; cur.sectionId = 0;
        renderPubTabs();
        var bar = el("scCurateSectionBar");
        bar.innerHTML = "<span class='scraper-placeholder'>Loading sections…</span>";
        el("scCurateModeTabs").style.display = "none";
        el("scCurateActionBar").style.display = "none";
        el("scCurateBody").innerHTML = "";
        post({ action: "curate_sections", publication: p.publication_key }).then(function (j) {
            if (!j.success) { bar.innerHTML = "<span class='scraper-placeholder'>Failed to load sections.</span>"; return; }
            sections = j.sections || [];
            if (!sections.length) { bar.innerHTML = "<span class='scraper-placeholder'>No active sections for this publication.</span>"; return; }
            renderSectionBar();
            selectSection(sections[0].section_id);
        });
    }

    function renderSectionBar() {
        var bar = el("scCurateSectionBar");
        bar.innerHTML = '<span style="font-size:12px;font-weight:600;color:#6b7280;margin-right:2px;">Section:</span>' +
            sections.map(function (s) {
                var on = s.section_id === cur.sectionId;
                return '<button class="sc-chip" data-sec="' + s.section_id + '" style="padding:6px 12px;border-radius:16px;border:1px solid ' +
                    (on ? '#111827' : '#d1d5db') + ';background:' + (on ? '#111827' : '#fff') + ';color:' + (on ? '#fff' : '#374151') +
                    ';font-size:12px;font-weight:600;cursor:pointer;">' + esc(s.ten_section) +
                    ' <span style="opacity:.6">' + s.today_count + '</span></button>';
            }).join("");
        Array.prototype.forEach.call(bar.querySelectorAll("button[data-sec]"), function (b) {
            b.addEventListener("click", function () { selectSection(parseInt(b.getAttribute("data-sec"), 10)); });
        });
    }

    function selectSection(sid) {
        cur.sectionId = sid;
        renderSectionBar();
        el("scCurateModeTabs").style.display = "flex";
        // wire mode tabs (idempotent)
        Array.prototype.forEach.call(el("scCurateModeTabs").querySelectorAll("button[data-mode]"), function (b) {
            b.onclick = function () {
                cur.mode = b.getAttribute("data-mode");
                Array.prototype.forEach.call(el("scCurateModeTabs").querySelectorAll("button"), function (x) { x.classList.remove("active"); });
                b.classList.add("active");
                loadItems();
            };
            b.classList.toggle("active", b.getAttribute("data-mode") === cur.mode);
        });
        loadItems();
    }

    /* ---- load + render the item list ---- */
    function loadItems() {
        var body = el("scCurateBody");
        el("scCurateActionBar").style.display = "none";
        body.innerHTML = "<p class='scraper-placeholder'><i class='fas fa-spinner fa-spin'></i> Loading…</p>";
        post({ action: "curate_items", pub_section_id: cur.sectionId, mode: cur.mode }).then(function (j) {
            if (!j.success) { body.innerHTML = "<p class='scraper-placeholder'>Failed: " + esc(j.message || "") + "</p>"; return; }
            renderList(j.items || []);
        }).catch(function (e) { body.innerHTML = "<p class='scraper-placeholder'>Error: " + esc(e.message) + "</p>"; });
    }

    function stateBadge(r) {
        var m = { published: ["#dcfce7", "#166534", "Published"], draft: ["#e0e7ff", "#3730a3", "Draft"], ignored: ["#fee2e2", "#991b1b", "Ignored"] }[r.row_state];
        if (!m) return "";
        return '<span class="scStateBadge" style="background:' + m[0] + ';color:' + m[1] + ';padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;white-space:nowrap;">' + m[2] + (r.article_id ? ' · #' + r.article_id : '') + '</span>';
    }
    function linksFor(r) {
        var out = [];
        if (r.row_state === "published" && r.live_url) out.push('<a href="' + esc(r.live_url) + '" target="_blank" rel="noopener" style="color:#166534;font-weight:600;text-decoration:none;">View live ↗</a>');
        if ((r.row_state === "draft" || r.row_state === "ignored") && r.editor_url) out.push('<a href="' + esc(r.editor_url) + '" target="_blank" rel="noopener" style="color:#3730a3;font-weight:600;text-decoration:none;">Edit ↗</a>');
        if (r.article_id) out.push('<a href="#" class="scCurPv" data-aid="' + r.article_id + '" style="color:#2563eb;text-decoration:none;">Preview</a>');
        return out.length ? '<span style="font-size:12px;margin-left:2px;">' + out.join(' &nbsp;·&nbsp; ') + '</span>' : "";
    }

    function renderList(items) {
        var body = el("scCurateBody");
        if (!items.length) {
            body.innerHTML = "<p class='scraper-placeholder'>" +
                (cur.mode === "curated"
                    ? "Nothing curated yet for this section today — the cron ranks new stories as they arrive."
                    : "No stories collated for this section today yet.") + "</p>";
            el("scCurateActionBar").style.display = "none";
            return;
        }
        var rows = items.map(function (it) {
            var promoted = !!it.article_id;
            var badge = stateBadge(it);
            return '<label data-id="' + it.id + '" style="display:flex;gap:10px;align-items:flex-start;padding:10px 12px;border:1px solid #e5e7eb;border-radius:8px;margin-bottom:8px;background:' +
                (promoted ? "#f8fafc" : "#fff") + ';cursor:' + (promoted ? "default" : "pointer") + ';">' +
                '<input type="checkbox" class="scCurCk" value="' + it.id + '"' + (promoted ? " disabled" : "") + ' style="margin-top:3px;width:16px;height:16px;flex-shrink:0;">' +
                '<span style="flex:1;min-width:0;">' +
                    '<span style="font-weight:600;color:#111827;">' + esc(it.title) + '</span> ' + badge +
                    (it.reason ? '<br><span style="font-size:12px;color:#6b7280;"><i class="fas fa-wand-magic-sparkles"></i> ' + esc(it.reason) + '</span>' : '') +
                    (it.summary ? '<br><span style="font-size:12px;color:#6b7280;">' + esc(it.summary.length > 200 ? it.summary.slice(0, 200) + "…" : it.summary) + '</span>' : '') +
                    '<br><span style="font-size:11px;color:#9ca3af;">' + esc(it.source_name || "") +
                        (it.source_url ? ' · <a href="' + esc(it.source_url) + '" target="_blank" rel="noopener" style="color:#9ca3af;">source ↗</a>' : '') +
                        (badge ? ' &nbsp; ' + linksFor(it) : '') + '</span>' +
                '</span></label>';
        }).join("");
        body.innerHTML = '<div id="scCurRows">' + rows + '</div>';
        el("scCurateActionBar").style.display = "flex";
        el("scCurAll").checked = false;
        el("scCurStatus").textContent = items.length + " stor" + (items.length === 1 ? "y" : "ies") +
            " · " + items.filter(function (x) { return x.article_id; }).length + " already promoted";
        // preview links
        Array.prototype.forEach.call(body.querySelectorAll("a.scCurPv"), function (a) {
            a.addEventListener("click", function (e) { e.preventDefault(); e.stopPropagation(); openPreview(a.getAttribute("data-aid")); });
        });
    }

    /* ---- action bar (select all / draft / publish) ---- */
    function wireActions() {
        el("scCurAll").addEventListener("change", function () {
            var v = this.checked;
            Array.prototype.forEach.call(document.querySelectorAll(".scCurCk:not(:disabled)"), function (c) { c.checked = v; });
        });
        el("scCurDraft").addEventListener("click", function () { runAction("draft"); });
        el("scCurPublish").addEventListener("click", function () { runAction("publish"); });
    }

    function runAction(mode) {
        var ids = Array.prototype.map.call(document.querySelectorAll(".scCurCk:checked"), function (c) { return c.value; });
        if (!ids.length) { el("scCurStatus").textContent = "Select at least one story first."; return; }
        var verb = mode === "publish" ? "Publishing" : "Saving drafts";
        var CH = 3, chunks = [];
        for (var i = 0; i < ids.length; i += CH) chunks.push(ids.slice(i, i + CH));
        var dBtn = el("scCurDraft"), pBtn = el("scCurPublish"), status = el("scCurStatus");
        var okN = 0, failN = 0, idx = 0;
        dBtn.disabled = true; pBtn.disabled = true;
        function done() {
            dBtn.disabled = false; pBtn.disabled = false;
            dBtn.innerHTML = '<i class="fas fa-file-pen"></i> Save as draft';
            pBtn.innerHTML = '<i class="fas fa-bolt"></i> Publish live';
            status.textContent = (mode === "publish" ? "Published " : "Drafted ") + okN + (failN ? (" · " + failN + " failed") : "") + (mode === "publish" ? ". Front page refreshed." : ".");
            if (typeof Swal !== "undefined") Swal.fire({ icon: "success", title: (mode === "publish" ? "Published " : "Saved ") + okN, text: failN ? (failN + " failed.") : "All done.", timer: 2000, showConfirmButton: false });
        }
        function next() {
            if (idx >= chunks.length) { done(); return; }
            var c = chunks[idx];
            var btn = mode === "publish" ? pBtn : dBtn;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ' + verb + " " + (okN + failN + c.length) + "/" + ids.length + "…";
            status.textContent = verb + "… " + (okN + failN) + "/" + ids.length;
            post({ action: "curate_action", pub_section_id: cur.sectionId, ids: c.join(","), do: mode }).then(function (j) {
                if (j.success) { (j.results || []).forEach(function (r) { if (r.ok) { okN++; markDone(r); } else failN++; }); }
                else { failN += c.length; }
                idx++; next();
            }).catch(function () { failN += c.length; idx++; next(); });
        }
        next();
    }

    // Badge a row in place after it's promoted (no reload).
    function markDone(r) {
        var ck = document.querySelector('.scCurCk[value="' + r.id + '"]');
        if (!ck) return;
        ck.checked = false; ck.disabled = true;
        var label = ck.closest("label");
        if (label) { label.style.background = "#f8fafc"; label.style.cursor = "default"; }
        var span = label ? label.querySelector("span") : null;
        if (!span) return;
        var row = { row_state: r.row_state || (r.state === "published" ? "published" : "draft"), article_id: r.article_id, live_url: r.live_url, editor_url: r.editor_url };
        var badge = stateBadge(row);
        var titleEl = span.querySelector("span");
        if (titleEl && badge) titleEl.insertAdjacentHTML("afterend", " " + badge);
        // append links line
        var metaLines = span.querySelectorAll("span");
        var last = metaLines[metaLines.length - 1];
        if (last && row.article_id) last.insertAdjacentHTML("beforeend", " &nbsp; " + linksFor(row));
        // rewire any new preview link
        Array.prototype.forEach.call(span.querySelectorAll("a.scCurPv"), function (a) {
            a.onclick = function (e) { e.preventDefault(); e.stopPropagation(); openPreview(a.getAttribute("data-aid")); };
        });
    }

    /* ---- article preview modal ---- */
    function openPreview(aid) {
        var ov = el("scCurPvOverlay");
        if (!ov) {
            ov = document.createElement("div");
            ov.id = "scCurPvOverlay";
            ov.style.cssText = "position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:99999;display:flex;align-items:flex-start;justify-content:center;padding:40px 16px;overflow:auto;";
            ov.addEventListener("click", function (e) { if (e.target === ov) ov.remove(); });
            document.body.appendChild(ov);
        }
        ov.innerHTML = '<div style="background:#fff;max-width:760px;width:100%;border-radius:12px;padding:24px 28px;box-shadow:0 20px 60px rgba(0,0,0,.3);">' +
            '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;"><span style="font-size:12px;color:#6b7280;">Article #' + aid + '</span>' +
            '<button id="scCurPvClose" style="border:none;background:#f3f4f6;border-radius:8px;padding:6px 12px;cursor:pointer;font-size:13px;">Close</button></div>' +
            '<div id="scCurPvBody"><p class="scraper-placeholder"><i class="fas fa-spinner fa-spin"></i> Loading article…</p></div></div>';
        el("scCurPvClose").addEventListener("click", function () { ov.remove(); });
        var fd = new FormData(); fd.append("id", aid);
        fetch("ajax/get_article_data.php", { method: "POST", body: fd, credentials: "same-origin" })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (!j || j.status !== "success") { el("scCurPvBody").innerHTML = "<p class='scraper-placeholder'>Could not load article.</p>"; return; }
                el("scCurPvBody").innerHTML =
                    '<h2 style="margin:0 0 6px;font-size:20px;color:#111827;">' + esc(j.title || "") + '</h2>' +
                    '<div style="font-size:12px;color:#6b7280;margin-bottom:14px;">State: ' + esc(j.state || "") + '</div>' +
                    '<div style="font-size:14px;line-height:1.6;color:#1f2937;max-height:55vh;overflow:auto;">' + (j.article || "<em>No body text.</em>") + '</div>';
            })
            .catch(function () { el("scCurPvBody").innerHTML = "<p class='scraper-placeholder'>Error loading article.</p>"; });
    }
})();
