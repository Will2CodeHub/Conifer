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
            renderItems(j.items || [], j.translate_error || null);
        }).catch(function (e) {
            list.innerHTML = '<p class="scraper-placeholder">Error: ' + esc(e.message) + "</p>";
        });
    }

    function renderItems(items, translateError) {
        var list = document.getElementById("scReviewList");
        var banner = translateError
            ? '<div class="rv-banner">⚠ Translation did not run — showing original text. Reason: ' + esc(translateError) + "</div>"
            : "";
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
                alert("You can select at most " + state.dailyCount + " (this section's daily count).");
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
        var verb = state.autoPublish ? "write & PUBLISH" : "write as a draft";
        if (!confirm("Promote " + ids.length + " article(s)? Each will be AI-" + verb + " in " + state.language + ". This can take a few seconds each.")) return;

        document.getElementById("scPromoteLog").innerHTML = "";
        var btn = document.getElementById("scPromote");
        btn.disabled = true;
        var i = 0;

        function next() {
            if (i >= ids.length) {
                btn.disabled = false;
                addLog("Finished.", "");
                return;
            }
            var id = ids[i++];
            var line = addLog("Writing article " + i + " of " + ids.length + "…", "");
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

    document.getElementById("scPromote").addEventListener("click", promote);
    loadSections();
})();
