/* TEN News Sites — manage owned publications + view their scraper feeds. */
(function () {
    "use strict";

    var root = document.getElementById("nsRoot");
    if (!root) return;
    var CAN_EDIT = root.getAttribute("data-can-edit") === "1";
    var ENDPOINT = "ajax/news_sites.php";

    function esc(s) {
        return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
            return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
        });
    }

    function api(entity, action, data) {
        var fd = new FormData();
        fd.append("entity", entity);
        fd.append("action", action);
        Object.keys(data || {}).forEach(function (k) {
            if (data[k] !== undefined && data[k] !== null) fd.append(k, data[k]);
        });
        return fetch(ENDPOINT, { method: "POST", body: fd, credentials: "same-origin" })
            .then(function (r) { return r.json(); })
            .then(function (j) { if (!j.success) throw new Error(j.message || "Request failed"); return j; });
    }

    function openModal(html) {
        document.getElementById("nsModalBody").innerHTML = html;
        document.getElementById("nsOverlay").style.display = "block";
        document.getElementById("nsModal").style.display = "block";
    }
    function closeModal() {
        document.getElementById("nsOverlay").style.display = "none";
        document.getElementById("nsModal").style.display = "none";
        document.getElementById("nsModalBody").innerHTML = "";
    }
    document.getElementById("nsOverlay").addEventListener("click", closeModal);

    function load() {
        var box = document.getElementById("nsList");
        box.innerHTML = '<p class="ns-placeholder">Loading…</p>';
        api("publication", "list", {}).then(function (j) { render(j.publications || []); })
            .catch(function (e) { box.innerHTML = '<p class="ns-placeholder">Error: ' + esc(e.message) + "</p>"; });
    }

    function render(pubs) {
        var box = document.getElementById("nsList");
        if (!pubs.length) {
            box.innerHTML = '<p class="ns-placeholder">No publications found.</p>';
            return;
        }
        box.innerHTML = "";
        pubs.forEach(function (p) {
            var live = parseInt(p.pub_live, 10) === 1;
            var breaking = parseInt(p.breaking_news_enabled, 10) === 1;
            var card = document.createElement("div");
            card.className = "ns-card";
            card.innerHTML =
                '<div class="ns-head">' +
                    "<div>" +
                        '<div class="ns-title">' + esc(p.title) +
                            ' <span class="ns-badge ' + (live ? "on" : "off") + '">' + (live ? "live" : "offline") + "</span>" +
                            ' <span class="ns-badge ' + (breaking ? "on" : "off") + '" title="Breaking-news desk (auto-publish + front-page section)">' +
                                (breaking ? "breaking on" : "breaking off") + "</span></div>" +
                        '<div class="ns-meta">' + esc(p.publication) + " · " + esc(p.url) + " · lang: " + esc(p.target_language || "English") +
                            " · translations/day: " + (parseInt(p.max_daily_translations, 10) > 0 ? esc(p.max_daily_translations) : "no cap") + "</div>" +
                    "</div>" +
                    "<div>" +
                        '<button class="ns-btn small" data-act="feeds">Feeds</button> ' +
                        (CAN_EDIT ? '<button class="ns-btn small secondary" data-act="edit">Edit</button> ' : "") +
                        (parseInt(p.design_enabled, 10) === 1
                            ? '<button class="ns-btn small" data-act="design">Design</button>'
                            : '<button class="ns-btn small" data-act="design" disabled title="Design not enabled for this site">Design</button>') +
                    "</div>" +
                "</div>" +
                '<div class="ns-body"></div>';
            var body = card.querySelector(".ns-body");
            card.querySelector('[data-act="feeds"]').addEventListener("click", function () {
                if (body.classList.contains("open")) { body.classList.remove("open"); return; }
                body.classList.add("open");
                loadFeeds(p.publication, body);
            });
            if (CAN_EDIT) {
                card.querySelector('[data-act="edit"]').addEventListener("click", function () { openForm(p); });
            }
            var designBtn = card.querySelector('[data-act="design"]');
            if (designBtn) {
                designBtn.addEventListener("click", function () {
                    if (this.disabled) { return; }
                    if (typeof openDesignModal === "function") { openDesignModal(p); }
                });
            }
            box.appendChild(card);
        });
    }

    function loadFeeds(pubKey, body) {
        body.innerHTML = '<p class="ns-placeholder">Loading feeds…</p>';
        api("feeds", "list", { publication: pubKey }).then(function (j) {
            var feeds = j.feeds || [];
            if (!feeds.length) {
                body.innerHTML = '<p class="ns-placeholder">No scraper feeds configured for this publication yet. ' +
                    'Add them in <a href="module-scraper.php">Data Scraper</a>.</p>';
                return;
            }
            var rows = feeds.map(function (f) {
                return "<tr><td>" + esc(f.ten_section) + "</td><td>" + esc(f.source_name) + "</td>" +
                    "<td>" + esc(f.feed_type) + "</td><td>" + esc(f.source_category_label || "") + "</td>" +
                    '<td><a href="' + esc(f.feed_url) + '" target="_blank" rel="noopener">' + esc(f.feed_url) + "</a></td></tr>";
            }).join("");
            body.innerHTML =
                '<table class="ns-feeds"><thead><tr><th>Section</th><th>Source</th><th>Type</th><th>Category</th><th>Feed URL</th></tr></thead>' +
                "<tbody>" + rows + "</tbody></table>" +
                '<p class="ns-placeholder" style="margin-top:8px;">Edit these in <a href="module-scraper.php">Data Scraper</a>.</p>';
        }).catch(function (e) { body.innerHTML = '<p class="ns-placeholder">Error: ' + esc(e.message) + "</p>"; });
    }

    function openForm(pub) {
        var p = pub || {};
        var live = pub ? (parseInt(p.pub_live, 10) === 1) : true;
        var breaking = pub ? (parseInt(p.breaking_news_enabled, 10) === 1) : false;
        var subtitle = pub
            ? (esc(p.title || p.publication || "") + (p.publication ? ' &middot; <span style="font-family:monospace;">' + esc(p.publication) + "</span>" : ""))
            : "Add a new publication site";
        openModal(
            '<div class="ns-modal-head">' +
                '<div class="ns-modal-icon"><i class="fas fa-newspaper"></i></div>' +
                "<div><h3>" + (pub ? "Edit publication" : "New publication") + "</h3>" +
                    '<p class="ns-modal-sub">' + subtitle + "</p></div>" +
            "</div>" +

            '<div class="ns-section">' +
                '<div class="ns-section-title">Identity</div>' +
                '<div class="ns-grid">' +
                    '<div class="ns-field"><label>Acronym / key</label><input type="text" id="ns_acr" value="' + esc(p.publication || "") + '" placeholder="tme"></div>' +
                    '<div class="ns-field"><label>Site name</label><input type="text" id="ns_title" value="' + esc(p.title || "") + '" placeholder="The Munich Eye"></div>' +
                "</div>" +
                '<div class="ns-field" style="margin-bottom:0;"><label>URL</label><input type="text" id="ns_url" value="' + esc(p.url || "") + '" placeholder="themunicheye.com"></div>' +
            "</div>" +

            '<div class="ns-section">' +
                '<div class="ns-section-title">Scraper</div>' +
                '<div class="ns-grid">' +
                    '<div class="ns-field"><label>Target language</label><input type="text" id="ns_lang" value="' + esc(p.target_language || "English") + '" placeholder="English"></div>' +
                    '<div class="ns-field"><label>Max translations / day</label><input type="number" id="ns_cap" min="0" value="' + esc(p.max_daily_translations || 0) + '"><div class="ns-hint">0 = unlimited</div></div>' +
                "</div>" +
            "</div>" +

            '<div class="ns-section">' +
                '<div class="ns-section-title">Publication settings</div>' +
                '<div class="ns-toggle-row' + (live ? " is-on" : "") + '" id="ns_live_row">' +
                    '<div class="ns-toggle-text"><span class="ns-toggle-label"><i class="fas fa-globe"></i>Live</span>' +
                        '<span class="ns-toggle-desc">Publicly visible. Turn off to take the site offline.</span></div>' +
                    '<label class="ns-switch"><input type="checkbox" id="ns_live" ' + (live ? "checked" : "") + '><span class="ns-slider"></span></label>' +
                "</div>" +
                '<div class="ns-toggle-row' + (breaking ? " is-on" : "") + '" id="ns_breaking_row">' +
                    '<div class="ns-toggle-text"><span class="ns-toggle-label"><i class="fas fa-bolt"></i>Breaking-news desk</span>' +
                        '<span class="ns-toggle-desc">Auto-publishes up to 2 breaking stories a day and shows the Breaking News section on this site\'s front page.</span></div>' +
                    '<label class="ns-switch brk"><input type="checkbox" id="ns_breaking" ' + (breaking ? "checked" : "") + '><span class="ns-slider"></span></label>' +
                "</div>" +
            "</div>" +

            '<div class="ns-actions"><button class="ns-btn secondary" id="ns_cancel">Cancel</button><button class="ns-btn" id="ns_save">' + (pub ? "Save changes" : "Create publication") + "</button></div>"
        );
        // Toggle rows brighten when on, for an at-a-glance read of the settings.
        ["ns_live", "ns_breaking"].forEach(function (id) {
            var input = document.getElementById(id);
            var row = document.getElementById(id + "_row");
            if (input && row) input.addEventListener("change", function () { row.classList.toggle("is-on", input.checked); });
        });
        document.getElementById("ns_cancel").addEventListener("click", closeModal);
        document.getElementById("ns_save").addEventListener("click", function () {
            var data = {
                publication: document.getElementById("ns_acr").value,
                title: document.getElementById("ns_title").value,
                url: document.getElementById("ns_url").value,
                target_language: document.getElementById("ns_lang").value,
                max_daily_translations: document.getElementById("ns_cap").value,
                pub_live: document.getElementById("ns_live").checked ? 1 : 0,
                breaking_news_enabled: document.getElementById("ns_breaking").checked ? 1 : 0
            };
            if (pub) data.id = p.id;
            api("publication", "save", data).then(function () { closeModal(); load(); })
                .catch(function (e) { alert("Error: " + e.message); });
        });
    }

    if (CAN_EDIT) {
        var addBtn = document.getElementById("nsAdd");
        if (addBtn) addBtn.addEventListener("click", function () { openForm(null); });
    }

    load();
})();
