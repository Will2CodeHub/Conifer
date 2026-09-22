/* TEN Scraper — merged Curate & Promote screen.
 *
 *  Publication tabs (drag to reorder, saved per user)
 *    -> Section bar (choose section within the publication)
 *      -> Mode tabs: "Curated pick" (AI selection) | "All today" (today's feed)
 *                    | "Published today" (what the scraper published today, current section)
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
    function spinner(label) {
        return "<p class='scraper-placeholder'><span class='sc-spinner'></span>" + esc(label || "Loading…") + "</p>";
    }

    /* ---- init: load publications, build draggable tabs ---- */
    window.scCurateInit = function () {
        if (inited) return; inited = true;
        var tabs = el("scCuratePubTabs");
        tabs.innerHTML = "<span class='scraper-placeholder'>Loading publications…</span>";
        post({ action: "curate_pubs" }).then(function (j) {
            if (!j.success) { tabs.innerHTML = "<span class='scraper-placeholder'>Failed to load publications.</span>"; return; }
            pubs = j.publications || [];
            if (!pubs.length) { tabs.innerHTML = ""; el("scCurateBody").innerHTML = "<p class='scraper-placeholder'>No publications configured yet — add one from the Configure tab.</p>"; return; }
            renderPubTabs();
            selectPub(pubs[0]);
        }).catch(function (e) { tabs.innerHTML = "<span class='scraper-placeholder'>Error: " + esc(e.message) + "</span>"; });
        wireActions();
    };

    // Stable per-publication colour + monogram so each tab is instantly distinguishable.
    function pubHue(key) { var h = 0; for (var i = 0; i < key.length; i++) h = (h * 31 + key.charCodeAt(i)) % 360; return h; }
    function pubMono(key) { return esc(key.slice(0, 2).toUpperCase()); }

    function renderPubTabs() {
        var tabs = el("scCuratePubTabs");
        tabs.innerHTML = pubs.map(function (p) {
            var on = cur.pub && p.publication_key === cur.pub.publication_key;
            var hue = pubHue(p.publication_key);
            return '<button class="sc-pub' + (on ? ' active' : '') + '" draggable="true" data-pub="' + esc(p.publication_key) + '">' +
                '<span class="sc-pub-grip" aria-hidden="true"><i class="fas fa-grip-vertical"></i></span>' +
                '<span class="sc-pub-dot" style="background:hsl(' + hue + ',55%,42%);">' + pubMono(p.publication_key) + '</span>' +
                '<span class="sc-pub-key">' + esc(p.publication_key.toUpperCase()) + '</span>' +
                '<span class="sc-count">' + (p.new_count || 0) + '</span></button>';
        }).join("");
        Array.prototype.forEach.call(tabs.querySelectorAll("button[data-pub]"), function (b) {
            var key = b.getAttribute("data-pub");
            var pub = pubs.filter(function (x) { return x.publication_key === key; })[0];
            b.addEventListener("click", function () { if (pub) selectPub(pub); });
            b.addEventListener("mouseenter", function () { showPubPop(b, pub); });
            b.addEventListener("mouseleave", function () { scheduleHidePop(); });
            b.addEventListener("dragstart", function (e) { hidePubPop(); dragKey = key; e.dataTransfer.effectAllowed = "move"; e.dataTransfer.setData("text/plain", key); b.style.opacity = ".4"; });
            b.addEventListener("dragend", function () { b.style.opacity = ""; dragKey = null; clearDropMarks(); });
            // Hovering a pill marks it as the drop target — the dragged pill lands to its LEFT.
            b.addEventListener("dragover", function (e) {
                e.preventDefault(); e.dataTransfer.dropEffect = "move";
                if (dragKey && dragKey !== key) { clearDropMarks(); b.classList.add("sc-drop-before"); }
            });
            b.addEventListener("dragleave", function () { b.classList.remove("sc-drop-before"); });
            b.addEventListener("drop", function (e) {
                e.preventDefault();
                clearDropMarks();
                var from = e.dataTransfer.getData("text/plain") || dragKey, to = key;
                if (!from || from === to) return;
                var fi = idxOf(from);
                if (fi < 0) return;
                var moved = pubs.splice(fi, 1)[0];      // remove the dragged pill
                var ti = idxOf(to);                     // target's index after removal
                if (ti < 0) ti = pubs.length;
                pubs.splice(ti, 0, moved);              // insert immediately BEFORE the target
                renderPubTabs();
                savePubOrder();
            });
        });
    }
    var dragKey = null;
    function clearDropMarks() {
        var t = el("scCuratePubTabs"); if (!t) return;
        Array.prototype.forEach.call(t.querySelectorAll(".sc-drop-before"), function (x) { x.classList.remove("sc-drop-before"); });
    }

    /* ---- interactive hover tooltip: name + front-page link (opens new tab) ---- */
    var popEl = null, popTimer = null;
    function ensurePop() {
        if (popEl) return popEl;
        popEl = document.createElement("div");
        // sc-app so the design tokens (var(--sc-*)) resolve on a body-level element.
        popEl.className = "sc-pub-pop sc-app";
        popEl.addEventListener("mouseenter", function () { if (popTimer) { clearTimeout(popTimer); popTimer = null; } });
        popEl.addEventListener("mouseleave", function () { scheduleHidePop(); });
        document.body.appendChild(popEl);
        return popEl;
    }
    function showPubPop(btn, pub) {
        if (popTimer) { clearTimeout(popTimer); popTimer = null; }
        if (!pub) return;
        var pop = ensurePop();
        var name = pub.name || pub.publication_key.toUpperCase();
        var link = pub.front_page_url
            ? '<a href="' + esc(pub.front_page_url) + '" target="_blank" rel="noopener"><i class="fas fa-arrow-up-right-from-square"></i> Open front page</a>'
            : '<span style="color:rgba(255,255,255,.5);font-size:12px;">No front page linked</span>';
        pop.innerHTML = '<div class="sc-pop-name">' + esc(name) + ' <span class="sc-pop-key">· ' + esc(pub.publication_key.toUpperCase()) + '</span></div>' + link;
        // position under the tab
        var r = btn.getBoundingClientRect();
        pop.style.left = (window.scrollX + r.left) + "px";
        pop.style.top = (window.scrollY + r.bottom + 8) + "px";
        pop.classList.add("show");
    }
    function scheduleHidePop() {
        if (popTimer) clearTimeout(popTimer);
        popTimer = setTimeout(hidePubPop, 180);
    }
    function hidePubPop() { if (popEl) popEl.classList.remove("show"); if (popTimer) { clearTimeout(popTimer); popTimer = null; } }
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
        bar.innerHTML = '<span class="sc-secbar-label">Section</span>' +
            sections.map(function (s) {
                var on = s.section_id === cur.sectionId;
                return '<button class="sc-chip' + (on ? ' active' : '') + '" data-sec="' + s.section_id + '">' +
                    esc(s.ten_section) + ' <span class="sc-count">' + s.today_count + '</span></button>';
            }).join("");
        Array.prototype.forEach.call(bar.querySelectorAll("button[data-sec]"), function (b) {
            b.addEventListener("click", function () { selectSection(parseInt(b.getAttribute("data-sec"), 10)); });
        });
    }

    function selectSection(sid) {
        cur.sectionId = sid;
        renderSectionBar();
        el("scCurateModeTabs").style.display = "inline-flex";
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
        if (cur.mode === "published") { loadPublished(); return; }
        var body = el("scCurateBody");
        el("scCurateActionBar").style.display = "none";
        body.innerHTML = spinner("Loading stories…");
        post({ action: "curate_items", pub_section_id: cur.sectionId, mode: cur.mode }).then(function (j) {
            if (!j.success) { body.innerHTML = "<p class='scraper-placeholder'>Failed: " + esc(j.message || "") + "</p>"; return; }
            renderList(j.items || []);
            if (j.untranslated > 0) translatePending(cur.sectionId, j.untranslated);
        }).catch(function (e) { body.innerHTML = "<p class='scraper-placeholder'>Error: " + esc(e.message) + "</p>"; });
    }

    /* ---- catch-up translation: new items (e.g. after "Run now") arrive untranslated;
       drive precompute passes from here instead of waiting for the 15-min cron ---- */
    var translating = {};
    function translatePending(sid, count) {
        if (translating[sid]) return;
        translating[sid] = true;
        var stalls = 0;
        function banner(msg) {
            if (cur.sectionId !== sid) return;
            var b = el("scCurTrBanner");
            if (!b) {
                b = document.createElement("div");
                b.id = "scCurTrBanner";
                b.className = "scraper-placeholder";
                b.style.cssText = "margin:0 0 10px;padding:8px 12px;border-radius:8px;background:#fff7e6;border:1px solid #f5d28a;color:#8a5a00;";
                el("scCurateBody").insertBefore(b, el("scCurateBody").firstChild);
            }
            b.innerHTML = msg;
        }
        function done() { translating[sid] = false; if (cur.sectionId === sid && cur.mode !== "published") loadItems(); }
        banner("<span class='sc-spinner'></span>Translating " + count + " new stor" + (count === 1 ? "y" : "ies") + " to English…");
        (function pass() {
            post({ action: "curate_precompute", pub_section_id: sid }).then(function (r) {
                if (!r.success) { translating[sid] = false; banner("⚠ Translation failed: " + esc(r.message || "unknown error")); return; }
                if (!r.more) { done(); return; }
                if (!r.busy && !r.translated) stalls++; else stalls = 0;
                if (stalls >= 2) { translating[sid] = false; banner("⚠ Translation is not making progress (AI call failing?) — " + r.untranslated + " stories still in the original language."); return; }
                banner("<span class='sc-spinner'></span>Translating… " + r.untranslated + " left" + (r.busy ? " (the scheduled job is working on this section)" : ""));
                setTimeout(pass, r.busy ? 5000 : 200);
            }).catch(function (e) { translating[sid] = false; banner("⚠ Translation error: " + esc(e.message)); });
        })();
    }

    function stateBadge(r) {
        var cls = { published: "published", draft: "draft", ignored: "ignored" }[r.row_state];
        var txt = { published: "Published", draft: "Draft", ignored: "Ignored" }[r.row_state];
        if (!cls) return "";
        return '<span class="sc-badge ' + cls + '">' + txt + (r.article_id ? ' · #' + r.article_id : '') + '</span>';
    }
    function linksFor(r) {
        var out = [];
        if (r.row_state === "published" && r.live_url) out.push('<a class="sc-link live" href="' + esc(r.live_url) + '" target="_blank" rel="noopener">View live ↗</a>');
        if ((r.row_state === "draft" || r.row_state === "ignored") && r.editor_url) out.push('<a class="sc-link edit" href="' + esc(r.editor_url) + '" target="_blank" rel="noopener">Edit ↗</a>');
        if (r.article_id) out.push('<a href="#" class="sc-link view scCurPv" data-aid="' + r.article_id + '">Preview</a>');
        return out.join('<span style="color:var(--sc-faint);">·</span>');
    }

    function renderList(items) {
        var body = el("scCurateBody");
        if (!items.length) {
            var empty = {
                curated: "Nothing curated yet for this section today — the cron ranks new stories as they arrive.",
                all: "No stories collated for this section today — the scraper hasn't ingested new items today yet."
            }[cur.mode] || "No stories to show.";
            body.innerHTML = "<p class='scraper-placeholder'>" + empty + "</p>";
            el("scCurateActionBar").style.display = "none";
            return;
        }
        var rows = items.map(function (it) {
            var promoted = !!it.article_id;
            var badge = stateBadge(it);
            var meta = esc(it.source_name || "") +
                (it.source_url ? '<span style="color:var(--sc-faint);">·</span><a href="' + esc(it.source_url) + '" target="_blank" rel="noopener">source ↗</a>' : '') +
                (badge ? '<span style="color:var(--sc-faint);">·</span>' + linksFor(it) : '');
            return '<label class="sc-item' + (promoted ? ' is-promoted' : '') + '" data-id="' + it.id + '">' +
                '<input type="checkbox" class="sc-ck scCurCk" value="' + it.id + '"' + (promoted ? " disabled" : "") + '>' +
                '<span class="sc-item-main">' +
                    '<span class="sc-item-title">' + esc(it.title) + '</span> ' + badge +
                    (it.reason ? '<div class="sc-item-reason"><i class="fas fa-wand-magic-sparkles"></i> ' + esc(it.reason) + '</div>' : '') +
                    (it.summary ? '<div class="sc-item-sub">' + esc(it.summary.length > 220 ? it.summary.slice(0, 220) + "…" : it.summary) + '</div>' : '') +
                    '<div class="sc-item-meta">' + meta + '</div>' +
                '</span></label>';
        }).join("");
        body.innerHTML = '<div id="scCurRows">' + rows + '</div>';
        el("scCurateActionBar").style.display = "flex";
        el("scCurAll").checked = false;
        var promotedN = items.filter(function (x) { return x.article_id; }).length;
        var status = el("scCurStatus");
        status.className = "sc-status";
        status.textContent = items.length + " stor" + (items.length === 1 ? "y" : "ies") +
            (promotedN ? (" · " + promotedN + " already promoted") : "");
        wirePreview(body);
    }

    /* ---- Published today (current section) ---- */
    function loadPublished() {
        var body = el("scCurateBody");
        el("scCurateActionBar").style.display = "none";
        body.innerHTML = spinner("Loading today's published articles…");
        post({ action: "curate_published_today", pub_section_id: cur.sectionId }).then(function (j) {
            if (!j.success) { body.innerHTML = "<p class='scraper-placeholder'>Failed: " + esc(j.message || "") + "</p>"; return; }
            renderPublished(j.items || [], j.front_page_url);
        }).catch(function (e) { body.innerHTML = "<p class='scraper-placeholder'>Error: " + esc(e.message) + "</p>"; });
    }

    function renderPublished(items, frontPageUrl) {
        var body = el("scCurateBody");
        if (!items.length) {
            body.innerHTML = "<p class='scraper-placeholder'>Nothing published from this section today yet. Publish stories from " +
                "<strong>Curated pick</strong> or <strong>All today</strong> and they'll appear here.</p>";
            return;
        }
        var headlineN = items.filter(function (x) { return x.is_headline; }).length;
        var head = '<div class="sc-pub-head"><span class="sc-pub-count"><strong>' + items.length + '</strong> article' +
            (items.length === 1 ? '' : 's') + ' published today' +
            (headlineN ? (' · <strong>' + headlineN + '</strong> on the front page') : '') + '</span>' +
            (frontPageUrl ? '<a class="sc-link accent" href="' + esc(frontPageUrl) + '" target="_blank" rel="noopener">Open front page ↗</a>' : '') +
            '</div>';
        var rows = items.map(function (it, i) {
            var links = [];
            if (it.live_url) links.push('<a class="sc-link live" href="' + esc(it.live_url) + '" target="_blank" rel="noopener">View live ↗</a>');
            links.push('<a href="#" class="sc-link view scCurPv" data-aid="' + it.article_id + '">Preview</a>');
            if (it.is_headline && it.front_page_url) links.push('<a class="sc-link accent" href="' + esc(it.front_page_url) + '" target="_blank" rel="noopener">Front page ↗</a>');
            return '<div class="sc-pt-row' + (it.is_headline ? ' is-headline' : '') + '">' +
                '<span class="sc-pt-idx">' + (i + 1) + '</span>' +
                '<div class="sc-pt-main">' +
                    '<span class="sc-pt-title">' + esc(it.title) + '</span> ' +
                    (it.is_headline ? '<span class="sc-badge headline"><i class="fas fa-fire"></i> Headline</span>' : '') +
                    '<div class="sc-pt-links">' + links.join('') + '</div>' +
                '</div></div>';
        }).join("");
        body.innerHTML = head + '<div id="scCurRows" style="margin-top:12px;">' + rows + '</div>';
        wirePreview(body);
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
        var status = el("scCurStatus");
        if (!ids.length) { status.className = "sc-status warn"; status.textContent = "Select at least one story first."; return; }
        var verb = mode === "publish" ? "Publishing" : "Saving drafts";
        var CH = 3, chunks = [];
        for (var i = 0; i < ids.length; i += CH) chunks.push(ids.slice(i, i + CH));
        var dBtn = el("scCurDraft"), pBtn = el("scCurPublish");
        var okN = 0, failN = 0, idx = 0;
        dBtn.disabled = true; pBtn.disabled = true;
        // The button is the sole live progress indicator; the status text stays quiet
        // until the run finishes (no duplicate running count).
        status.className = "sc-status"; status.textContent = "";
        function done() {
            dBtn.disabled = false; pBtn.disabled = false;
            dBtn.innerHTML = '<i class="fas fa-file-pen"></i> Save as draft';
            pBtn.innerHTML = '<i class="fas fa-bolt"></i> Publish live';
            status.className = "sc-status " + (failN ? "warn" : "ok");
            status.textContent = (mode === "publish" ? "Published " : "Drafted ") + okN +
                (failN ? (" · " + failN + " failed") : "") +
                (mode === "publish" ? " · front page refreshed." : ".");
            if (typeof Swal !== "undefined") Swal.fire({ icon: "success", title: (mode === "publish" ? "Published " : "Saved ") + okN, text: failN ? (failN + " failed.") : "All done.", timer: 2000, showConfirmButton: false });
        }
        function next() {
            if (idx >= chunks.length) { done(); return; }
            var c = chunks[idx];
            var btn = mode === "publish" ? pBtn : dBtn;
            btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> ' + verb + " " + (okN + failN + c.length) + "/" + ids.length + "…";
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
        if (label) label.classList.add("is-promoted");
        var main = label ? label.querySelector(".sc-item-main") : null;
        if (!main) return;
        var row = { row_state: r.row_state || (r.state === "published" ? "published" : "draft"), article_id: r.article_id, live_url: r.live_url, editor_url: r.editor_url };
        var badge = stateBadge(row);
        var titleEl = main.querySelector(".sc-item-title");
        if (titleEl && badge) titleEl.insertAdjacentHTML("afterend", " " + badge);
        var metaEl = main.querySelector(".sc-item-meta");
        if (metaEl && row.article_id) metaEl.insertAdjacentHTML("beforeend", '<span style="color:var(--sc-faint);">·</span>' + linksFor(row));
        wirePreview(main);
    }

    /* ---- article preview modal ---- */
    function wirePreview(scope) {
        Array.prototype.forEach.call(scope.querySelectorAll("a.scCurPv"), function (a) {
            a.onclick = function (e) { e.preventDefault(); e.stopPropagation(); openPreview(a.getAttribute("data-aid")); };
        });
    }

    function openPreview(aid) {
        var ov = el("scCurPvOverlay");
        if (!ov) {
            ov = document.createElement("div");
            ov.id = "scCurPvOverlay";
            ov.className = "sc-app";
            ov.style.cssText = "position:fixed;inset:0;background:rgba(16,20,28,.5);backdrop-filter:blur(2px);z-index:99999;display:flex;align-items:flex-start;justify-content:center;padding:40px 16px;overflow:auto;";
            ov.addEventListener("click", function (e) { if (e.target === ov) ov.remove(); });
            document.body.appendChild(ov);
        }
        ov.innerHTML = '<div style="background:#fff;max-width:760px;width:100%;border-radius:12px;padding:24px 28px;box-shadow:0 20px 60px rgba(0,0,0,.3);">' +
            '<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;"><span style="font-size:12px;color:#6b7280;">Article #' + aid + '</span>' +
            '<button id="scCurPvClose" class="sc-btn secondary small">Close</button></div>' +
            '<div id="scCurPvBody">' + spinner("Loading article…") + '</div></div>';
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
