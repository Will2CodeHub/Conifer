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
            var card = document.createElement("div");
            card.className = "ns-card";
            card.innerHTML =
                '<div class="ns-head">' +
                    "<div>" +
                        '<div class="ns-title">' + esc(p.title) + ' <span class="ns-badge ' + (live ? "on" : "off") + '">' + (live ? "live" : "offline") + "</span></div>" +
                        '<div class="ns-meta">' + esc(p.publication) + " · " + esc(p.url) + " · lang: " + esc(p.target_language || "English") +
                            " · translations/day: " + (parseInt(p.max_daily_translations, 10) > 0 ? esc(p.max_daily_translations) : "no cap") + "</div>" +
                    "</div>" +
                    "<div>" +
                        '<button class="ns-btn small" data-act="feeds">Feeds</button> ' +
                        (CAN_EDIT ? '<button class="ns-btn small secondary" data-act="edit">Edit</button>' : "") +
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
        openModal(
            "<h3>" + (pub ? "Edit" : "Add") + " publication</h3>" +
            '<div class="ns-field"><label>Acronym / key</label><input type="text" id="ns_acr" value="' + esc(p.publication || "") + '" placeholder="e.g. tme"></div>' +
            '<div class="ns-field"><label>Site name</label><input type="text" id="ns_title" value="' + esc(p.title || "") + '" placeholder="The Munich Eye"></div>' +
            '<div class="ns-field"><label>URL</label><input type="text" id="ns_url" value="' + esc(p.url || "") + '" placeholder="themunicheye.com"></div>' +
            '<div class="ns-field"><label>Target language (for scraper translation + articles)</label><input type="text" id="ns_lang" value="' + esc(p.target_language || "English") + '" placeholder="English"></div>' +
            '<div class="ns-field"><label>Max translations per day (0 = unlimited)</label><input type="number" id="ns_cap" min="0" value="' + esc(p.max_daily_translations || 0) + '"></div>' +
            '<div class="ns-field"><label><input type="checkbox" id="ns_live" ' + (live ? "checked" : "") + '> Live</label></div>' +
            '<div class="ns-actions"><button class="ns-btn secondary" id="ns_cancel">Cancel</button><button class="ns-btn" id="ns_save">Save</button></div>'
        );
        document.getElementById("ns_cancel").addEventListener("click", closeModal);
        document.getElementById("ns_save").addEventListener("click", function () {
            var data = {
                publication: document.getElementById("ns_acr").value,
                title: document.getElementById("ns_title").value,
                url: document.getElementById("ns_url").value,
                target_language: document.getElementById("ns_lang").value,
                max_daily_translations: document.getElementById("ns_cap").value,
                pub_live: document.getElementById("ns_live").checked ? 1 : 0
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
