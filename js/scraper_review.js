/* TEN Scraper — Review & Promote (Phase 5).
 * Lists collated items (translated), select up to N, promote → AI article in Article Tool.
 */
(function () {
    "use strict";

    var root = document.getElementById("scViewReview");
    if (!root) return;
    var PROJECT_ID = parseInt(root.getAttribute("data-project-id"), 10);
    var EP = "ajax/scraper_review.php";

    var state = { sectionId: 0, dailyCount: 0, autoPublish: 0, language: "English", selected: {} };

    function esc(s) {
        return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
            return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
        });
    }

    function api(action, data) {
        var fd = new FormData();
        fd.append("action", action);
        Object.keys(data || {}).forEach(function (k) { fd.append(k, data[k]); });
        return fetch(EP, { method: "POST", body: fd, credentials: "same-origin" })
            .then(function (r) { return r.json(); })
            .then(function (j) { if (!j.success) throw new Error(j.message || "Request failed"); return j; });
    }

    // Styled confirm via SweetAlert2 (bundled locally); falls back to native confirm.
    function scConfirm(title, message, confirmLabel, danger, onYes) {
        if (typeof Swal === "undefined") {
            if (confirm(title)) onYes();
            return;
        }
        Swal.fire({
            title: title,
            html: message,
            icon: danger ? "warning" : "question",
            showCancelButton: true,
            confirmButtonText: confirmLabel,
            cancelButtonText: "Cancel",
            confirmButtonColor: danger ? "#dc2626" : "#2563eb",
            reverseButtons: true
        }).then(function (res) { if (res.isConfirmed) onYes(); });
    }

    function scToast(text, icon) {
        if (typeof Swal === "undefined") { alert(text); return; }
        Swal.fire({ toast: true, position: "top-end", timer: 2600, showConfirmButton: false, icon: icon || "info", title: text });
    }

    function loadSections() {
        api("sections", { project_id: PROJECT_ID }).then(function (j) {
            var sel = document.getElementById("scReviewSection");
            var secs = j.sections || [];
            if (!secs.length) {
                sel.innerHTML = '<option value="">No sections configured yet</option>';
                return;
            }
            sel.innerHTML = '<option value="">— choose a section —</option>' + secs.map(function (s) {
                return '<option value="' + s.id + '">' + esc(s.publication_key) + " › " + esc(s.ten_section) +
                    " (N=" + s.daily_count + (s.auto_publish ? ", auto-publish" : "") + ")</option>";
            }).join("");
            sel.addEventListener("change", onSectionChange);
        }).catch(function (e) {
            document.getElementById("scReviewSection").innerHTML = '<option value="">Error loading sections</option>';
        });
    }

    function onSectionChange() {
        var sel = document.getElementById("scReviewSection");
        state.sectionId = parseInt(sel.value, 10) || 0;
        state.selected = {};
        document.getElementById("scPromoteLog").innerHTML = "";
        if (!state.sectionId) {
            document.getElementById("scReviewList").innerHTML = '<p class="scraper-placeholder">Choose a section to review its collated articles.</p>';
            updateCounter();
            return;
        }
        loadItems();
    }

    function loadItems() {
        var list = document.getElementById("scReviewList");
        list.innerHTML = '<div class="sc-spinner-wrap"><span class="sc-spinner"></span> Loading &amp; translating… (first load of new items may take a few seconds)</div>';
        api("list", { pub_section_id: state.sectionId }).then(function (j) {
            state.dailyCount = j.daily_count || 0;
            state.autoPublish = j.auto_publish || 0;
            state.language = j.language || "English";
            renderItems(j.items || [], j.translate_error || null, j.cap_note || null);
        }).catch(function (e) {
            list.innerHTML = '<p class="scraper-placeholder">Error: ' + esc(e.message) + "</p>";
        });
    }

    function renderItems(items, translateError, capNote) {
        var list = document.getElementById("scReviewList");
        var banner = "";
        if (translateError) banner += '<div class="rv-banner">⚠ Translation did not run — showing original text. Reason: ' + esc(translateError) + "</div>";
        if (capNote) banner += '<div class="rv-banner" style="background:#dbeafe;border-color:#bfdbfe;color:#1e40af;">ℹ ' + esc(capNote) + "</div>";
        if (!items.length) {
            list.innerHTML = banner + '<p class="scraper-placeholder">No new collated articles for this section. The scraper adds more on its schedule.</p>';
            updateCounter();
            return;
        }
        list.innerHTML = banner + '<p class="scraper-placeholder" style="margin-bottom:10px;">' + items.length +
            " collated articles, translated to <strong>" + esc(state.language) + "</strong>. Select up to " + state.dailyCount +
            (state.autoPublish ? ' — <strong style="color:#b91c1c;">this section AUTO-PUBLISHES on promote</strong>.' : " (they land as drafts in the Article Tool).") + "</p>";

        items.forEach(function (it) {
            var row = document.createElement("div");
            row.className = "rv-item";
            row.setAttribute("data-id", it.id);
            row.innerHTML =
                "<input type='checkbox'>" +
                '<div style="flex:1;">' +
                    '<div class="rv-title">' + esc(it.title) + "</div>" +
                    '<div class="rv-summary">' + esc(it.summary) + "</div>" +
                    '<div class="rv-orig"><strong>Original:</strong> ' + esc(it.title_original) + " — " + esc(it.summary_original) + "</div>" +
                    '<div class="rv-meta">' + esc(it.published_at || "") +
                        ' · <a href="' + esc(it.source_url) + '" target="_blank" rel="noopener">source</a>' +
                        ' · <span class="rv-toggle">show original</span></div>' +
                "</div>";
            var cb = row.querySelector("input");
            cb.addEventListener("change", function () { toggleSelect(it.id, cb, row); });
            row.querySelector(".rv-toggle").addEventListener("click", function () {
                var o = row.querySelector(".rv-orig");
                var show = o.style.display !== "block";
                o.style.display = show ? "block" : "none";
                this.textContent = show ? "hide original" : "show original";
            });
            list.appendChild(row);
        });
        updateCounter();
    }

    function toggleSelect(id, cb, row) {
        if (cb.checked) {
            var count = Object.keys(state.selected).length;
            if (state.dailyCount > 0 && count >= state.dailyCount) {
                cb.checked = false;
                scToast("You can select at most " + state.dailyCount + " (this section's daily count).", "warning");
                return;
            }
            state.selected[id] = true;
            row.classList.add("sel");
        } else {
            delete state.selected[id];
            row.classList.remove("sel");
        }
        updateCounter();
    }

    function updateCounter() {
        var n = Object.keys(state.selected).length;
        document.getElementById("scReviewCounter").textContent = state.sectionId ? (n + " / " + state.dailyCount + " selected") : "";
        document.getElementById("scPromote").disabled = n === 0;
    }

    function addLog(text, cls) {
        var d = document.createElement("div");
        d.className = "rv-log-line " + (cls || "");
        d.textContent = text;
        document.getElementById("scPromoteLog").appendChild(d);
        return d;
    }

    function removeItem(id) {
        delete state.selected[id];
        var row = document.querySelector('.rv-item[data-id="' + id + '"]');
        if (row) row.remove();
        updateCounter();
    }

    function promote() {
        var ids = Object.keys(state.selected);
        if (!ids.length) return;
        var msg = "Each of the <strong>" + ids.length + "</strong> selected article(s) will be written by AI as " +
            (state.autoPublish ? '<strong style="color:#b91c1c;">a PUBLISHED article</strong>' : "a <strong>draft</strong>") +
            " in <strong>" + esc(state.language) + "</strong> and sent to the Article Tool. This can take a few seconds each.";
        scConfirm(
            "Promote " + ids.length + " article(s)?",
            msg,
            state.autoPublish ? "Write & publish" : "Write drafts",
            state.autoPublish,
            function () { runPromote(ids); }
        );
    }

    function runPromote(ids) {
        document.getElementById("scPromoteLog").innerHTML = "";
        var btn = document.getElementById("scPromote");
        var origBtn = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<span class="sc-spinner" style="border-top-color:#fff;border-color:rgba(255,255,255,.5);border-top-color:#fff;"></span> Promoting…';
        var i = 0;

        function next() {
            if (i >= ids.length) {
                btn.disabled = false;
                btn.innerHTML = origBtn;
                addLog("Finished.", "rv-ok");
                return;
            }
            var id = ids[i++];
            var line = addLog("", "");
            line.innerHTML = '<span class="sc-spinner"></span> Writing article ' + i + " of " + ids.length + "…";
            api("promote", { pub_section_id: state.sectionId, ids: id }).then(function (j) {
                var r = (j.results && j.results[0]) || {};
                if (r.ok) {
                    line.className = "rv-log-line rv-ok";
                    line.textContent = "✓ " + (r.title || ("item " + id)) + " → " + (r.state || "draft");
                    removeItem(id);
                } else {
                    line.className = "rv-log-line rv-err";
                    line.textContent = "✗ item " + id + ": " + (r.error || "failed");
                }
                next();
            }).catch(function (e) {
                line.className = "rv-log-line rv-err";
                line.textContent = "✗ item " + id + ": " + e.message;
                next();
            });
        }
        next();
    }

    /* ---------------------------------------------------------------- history */

    function loadHistorySections() {
        var sel = document.getElementById("scHistorySection");
        if (!sel) return;
        api("sections", { project_id: PROJECT_ID }).then(function (j) {
            var secs = j.sections || [];
            if (!secs.length) { sel.innerHTML = '<option value="">No sections configured yet</option>'; return; }
            sel.innerHTML = '<option value="">— choose a section —</option>' + secs.map(function (s) {
                return '<option value="' + s.id + '">' + esc(s.publication_key) + " › " + esc(s.ten_section) + "</option>";
            }).join("");
            sel.addEventListener("change", function () {
                var id = parseInt(sel.value, 10) || 0;
                if (!id) { document.getElementById("scHistoryList").innerHTML = '<p class="scraper-placeholder">Choose a section to see its recent history.</p>'; return; }
                loadHistory(id);
            });
        });
    }

    function loadHistory(sectionId) {
        var box = document.getElementById("scHistoryList");
        box.innerHTML = '<div class="sc-spinner-wrap"><span class="sc-spinner"></span> Loading history…</div>';
        api("history", { pub_section_id: sectionId, days: 30 }).then(function (j) {
            renderHistory(j.history || []);
        }).catch(function (e) { box.innerHTML = '<p class="scraper-placeholder">Error: ' + esc(e.message) + "</p>"; });
    }

    function statusBadge(s) {
        var map = { promoted: ["#dcfce7", "#166534"], discarded: ["#fee2e2", "#991b1b"], selected: ["#e0e7ff", "#3730a3"], new: ["#f1f5f9", "#475569"] };
        var c = map[s] || map.new;
        return '<span class="sc-badge" style="background:' + c[0] + ";color:" + c[1] + ';">' + esc(s) + "</span>";
    }

    var historyRows = [];
    var historyPage = 0;
    var HISTORY_PER = 25;

    function historyRowHtml(r) {
        var when = String(r.fetched_at || "").replace("T", " ").slice(0, 16);
        var art = r.article_id ? ' · <a href="module-articles.php" title="article #' + esc(r.article_id) + '">article #' + esc(r.article_id) + "</a>" : "";
        return "<tr>" +
            "<td style='white-space:nowrap;'>" + esc(when) + "</td>" +
            "<td>" + esc(r.source_name || "") + "</td>" +
            "<td>" + esc(r.title || "") +
                '<div class="scraper-placeholder"><a href="' + esc(r.source_url) + '" target="_blank" rel="noopener">source</a>' + art + "</div></td>" +
            "<td>" + statusBadge(r.status) + "</td>" +
            "</tr>";
    }

    function renderHistory(rows) {
        historyRows = rows || [];
        historyPage = 0;
        renderHistoryPage();
    }

    function renderHistoryPage() {
        var box = document.getElementById("scHistoryList");
        if (!historyRows.length) {
            box.innerHTML = '<p class="scraper-placeholder">Nothing collated for this section in the last 30 days.</p>';
            return;
        }
        var total = historyRows.length;
        var pages = Math.ceil(total / HISTORY_PER);
        if (historyPage >= pages) historyPage = pages - 1;
        if (historyPage < 0) historyPage = 0;
        var start = historyPage * HISTORY_PER;
        var body = historyRows.slice(start, start + HISTORY_PER).map(historyRowHtml).join("");
        var nav =
            '<div style="display:flex;justify-content:space-between;align-items:center;margin-top:10px;">' +
                '<button class="sc-btn small secondary" id="scHistPrev"' + (historyPage === 0 ? " disabled" : "") + ">Prev</button>" +
                '<span class="scraper-placeholder">Page ' + (historyPage + 1) + " of " + pages + " · " + total + " items</span>" +
                '<button class="sc-btn small secondary" id="scHistNext"' + (historyPage >= pages - 1 ? " disabled" : "") + ">Next</button>" +
            "</div>";
        box.innerHTML =
            '<div style="overflow-x:auto;"><table class="sc-htable">' +
            "<thead><tr><th>Collated</th><th>Source</th><th>Article</th><th>Status</th></tr></thead>" +
            "<tbody>" + body + "</tbody></table></div>" + nav;
        var prev = document.getElementById("scHistPrev");
        var next = document.getElementById("scHistNext");
        if (prev) prev.addEventListener("click", function () { if (historyPage > 0) { historyPage--; renderHistoryPage(); } });
        if (next) next.addEventListener("click", function () { if (historyPage < pages - 1) { historyPage++; renderHistoryPage(); } });
    }

    document.getElementById("scPromote").addEventListener("click", promote);
    loadSections();
    loadHistorySections();
})();
