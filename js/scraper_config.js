/* TEN Scraper — News Collation config UI (Phase 2).
 * Vanilla JS + fetch. Talks to ajax/scraper_config.php (entity/action dispatcher).
 */
(function () {
    "use strict";

    var root = document.getElementById("scraperManage");
    if (!root) return;

    var PROJECT_ID = parseInt(root.getAttribute("data-project-id"), 10);
    var DEFAULT_PROVIDER = root.getAttribute("data-provider") || "anthropic";
    var DEFAULT_MODEL = root.getAttribute("data-model") || "claude-sonnet-5";
    var ENDPOINT = "ajax/scraper_config.php";

    var refs = { publications: [], journalists: [], sections: [], models: {}, vpn_profiles: [] };

    /* ---------------------------------------------------------------- helpers */

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
            .then(function (json) {
                if (!json.success) throw new Error(json.message || "Request failed");
                return json;
            });
    }

    function optionList(items, selected, valKey, labKey) {
        return items.map(function (it) {
            var v = valKey ? it[valKey] : it;
            var l = labKey ? it[labKey] : it;
            var sel = String(v) === String(selected) ? " selected" : "";
            return '<option value="' + esc(v) + '"' + sel + ">" + esc(l) + "</option>";
        }).join("");
    }

    function modelOptions(provider, selected) {
        var list = refs.models[provider] || [];
        return optionList(list, selected);
    }

    /* ---------------------------------------------------------------- modal */

    function openModal(html) {
        document.getElementById("scModalBody").innerHTML = html;
        document.getElementById("scOverlay").style.display = "block";
        document.getElementById("scModal").style.display = "block";
    }
    function closeModal() {
        document.getElementById("scOverlay").style.display = "none";
        document.getElementById("scModal").style.display = "none";
        document.getElementById("scModalBody").innerHTML = "";
    }
    document.getElementById("scOverlay").addEventListener("click", closeModal);

    /* ---------------------------------------------------------------- sections */

    function loadSections() {
        var box = document.getElementById("scSections");
        box.innerHTML = '<p class="scraper-placeholder">Loading…</p>';
        api("section", "list", { project_id: PROJECT_ID }).then(function (json) {
            renderSections(json.sections || []);
        }).catch(function (e) {
            box.innerHTML = '<p class="scraper-placeholder">Error: ' + esc(e.message) + "</p>";
        });
    }

    function pubLabel(key) {
        var p = refs.publications.filter(function (x) { return x.value === key; })[0];
        return p ? p.label : key;
    }
    function journalistLabel(id) {
        if (!id) return "—";
        var j = refs.journalists.filter(function (x) { return String(x.value) === String(id); })[0];
        return j ? j.label : ("#" + id);
    }
    function vpnLabel(id) {
        if (!id) return "none";
        var v = refs.vpn_profiles.filter(function (x) { return String(x.id) === String(id); })[0];
        return v ? (v.name + " (" + (v.country || v.provider) + ")") : ("#" + id);
    }

    // One section card, with a per-section enable/disable toggle. Disabling sets
    // is_active=0, which the worker honours — so its feeds aren't fetched and no
    // AI is spent translating them.
    function sectionCard(s) {
        var card = document.createElement("div");
        card.className = "sc-card";
        card.style.marginBottom = "8px";
        var auto = parseInt(s.auto_publish, 10) === 1;
        var active = parseInt(s.is_active, 10) !== 0;
        if (!active) card.style.opacity = "0.55";
        card.innerHTML =
            '<div class="sc-card-head">' +
                '<div>' +
                    '<div class="sc-card-title">' + esc(s.ten_section) +
                        ' <span class="sc-badge ' + (auto ? "on" : "off") + '">' + (auto ? "auto-publish" : "draft") + "</span>" +
                        (active ? "" : ' <span class="sc-badge off">scraping off</span>') + "</div>" +
                    '<div class="sc-card-meta">N=' + esc(s.daily_count) + " · cron " + esc(s.cron_schedule) +
                        " · " + esc(journalistLabel(s.journalist_id)) +
                        " · " + esc(s.ai_model || DEFAULT_MODEL) +
                        " · VPN: " + esc(vpnLabel(s.vpn_profile_id)) +
                        " · sources: " + esc(s.source_count) + "</div>" +
                "</div>" +
                "<div>" +
                    (active ? '<button class="sc-btn small" data-act="run" title="Scrape this section now"><i class="fas fa-bolt"></i> Run now</button> ' : "") +
                    '<button class="sc-btn small ' + (active ? "" : "secondary") + '" data-act="toggle">' +
                        (active ? '<i class="fas fa-pause"></i> Disable' : '<i class="fas fa-play"></i> Enable') + "</button> " +
                    '<button class="sc-btn small" data-act="sources">Sources</button> ' +
                    '<button class="sc-btn small secondary" data-act="edit">Edit</button> ' +
                    '<button class="sc-btn small danger" data-act="del">Delete</button>' +
                "</div>" +
            "</div>" +
            '<div class="sc-card-body"></div>';

        var body = card.querySelector(".sc-card-body");
        var runBtn = card.querySelector('[data-act="run"]');
        if (runBtn) runBtn.addEventListener("click", function () {
            var old = runBtn.innerHTML; runBtn.disabled = true;
            runBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Requesting…';
            api("section", "run_now", { id: s.id }).then(function (j) {
                runBtn.innerHTML = j.spawned
                    ? '<i class="fas fa-check"></i> Started'
                    : (j.already ? '<i class="fas fa-check"></i> Already queued' : '<i class="fas fa-check"></i> Queued');
                setTimeout(function () { runBtn.disabled = false; runBtn.innerHTML = old; }, 5000);
            }).catch(function (e) { runBtn.disabled = false; runBtn.innerHTML = old; alertErr(e); });
        });
        card.querySelector('[data-act="toggle"]').addEventListener("click", function () {
            api("section", "toggle", { id: s.id, active: active ? 0 : 1 }).then(loadSections).catch(alertErr);
        });
        card.querySelector('[data-act="sources"]').addEventListener("click", function () {
            if (body.classList.contains("open")) { body.classList.remove("open"); return; }
            body.classList.add("open");
            loadSources(s.id, body);
        });
        card.querySelector('[data-act="edit"]').addEventListener("click", function () { openSectionModal(s); });
        card.querySelector('[data-act="del"]').addEventListener("click", function () {
            if (!confirm("Delete this section and all its sources/feeds?")) return;
            api("section", "delete", { id: s.id }).then(loadSections).catch(alertErr);
        });
        return card;
    }

    // Group sections under their publication; each publication is collapsible and
    // has its own enable/disable-all toggle.
    function renderSections(sections) {
        var box = document.getElementById("scSections");
        if (!sections.length) {
            box.innerHTML = '<p class="scraper-placeholder">No publication/section configured yet. Click “Add publication / section”.</p>';
            return;
        }
        box.innerHTML = "";
        var groups = {}, order = [];
        sections.forEach(function (s) {
            if (!groups[s.publication_key]) { groups[s.publication_key] = []; order.push(s.publication_key); }
            groups[s.publication_key].push(s);
        });
        order.forEach(function (pk) {
            var list = groups[pk];
            var anyActive = list.some(function (s) { return parseInt(s.is_active, 10) !== 0; });
            var grp = document.createElement("div");
            grp.className = "sc-pubgroup";
            grp.style.cssText = "border:1px solid #e5e7eb;border-radius:10px;margin-bottom:12px;overflow:hidden;";
            var head = document.createElement("div");
            head.style.cssText = "display:flex;justify-content:space-between;align-items:center;gap:10px;padding:12px 14px;background:#f9fafb;cursor:pointer;";
            head.innerHTML =
                '<div style="display:flex;align-items:center;gap:10px;">' +
                    '<i class="fas fa-chevron-right sc-pubchev"></i>' +
                    "<strong>" + esc(pubLabel(pk)) + "</strong>" +
                    '<span class="scraper-placeholder">' + list.length + " section" + (list.length === 1 ? "" : "s") + "</span>" +
                    (anyActive ? "" : ' <span class="sc-badge off">all scraping off</span>') +
                "</div>" +
                '<div>' +
                    (anyActive ? '<button class="sc-btn small" data-pubrun title="Scrape all enabled sections now"><i class="fas fa-bolt"></i> Run now</button> ' : "") +
                    '<button class="sc-btn small ' + (anyActive ? "" : "secondary") + '" data-pubtoggle>' +
                    (anyActive ? '<i class="fas fa-pause"></i> Disable scraping' : '<i class="fas fa-play"></i> Enable scraping') + "</button></div>";
            var body = document.createElement("div");
            body.style.cssText = "padding:10px 12px;display:none;";
            grp.appendChild(head); grp.appendChild(body); box.appendChild(grp);

            head.addEventListener("click", function (ev) {
                if (ev.target.closest("[data-pubtoggle]")) return;
                var open = body.style.display !== "none";
                body.style.display = open ? "none" : "block";
                head.querySelector(".sc-pubchev").className = "fas fa-chevron-" + (open ? "right" : "down") + " sc-pubchev";
            });
            head.querySelector("[data-pubtoggle]").addEventListener("click", function (ev) {
                ev.stopPropagation();
                var enable = !anyActive;
                if (!confirm((enable ? "Enable" : "Disable") + " scraping for ALL sections of " + pubLabel(pk) + "?")) return;
                api("publication", "toggle", { project_id: PROJECT_ID, publication_key: pk, active: enable ? 1 : 0 }).then(loadSections).catch(alertErr);
            });
            var pubRun = head.querySelector("[data-pubrun]");
            if (pubRun) pubRun.addEventListener("click", function (ev) {
                ev.stopPropagation();
                if (!confirm("Run an immediate scrape for all enabled sections of " + pubLabel(pk) + "?")) return;
                var old = pubRun.innerHTML; pubRun.disabled = true;
                pubRun.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Requesting…';
                api("publication", "run_now", { project_id: PROJECT_ID, publication_key: pk }).then(function (j) {
                    pubRun.innerHTML = '<i class="fas fa-check"></i> ' + (j.queued || 0) + " queued";
                    setTimeout(function () { pubRun.disabled = false; pubRun.innerHTML = old; }, 5000);
                }).catch(function (e) { pubRun.disabled = false; pubRun.innerHTML = old; alertErr(e); });
            });
            list.forEach(function (s) { body.appendChild(sectionCard(s)); });
        });
    }

    function openSectionModal(section) {
        var isEdit = !!section;
        var s = section || {};
        var provider = s.ai_provider || DEFAULT_PROVIDER;
        var html =
            "<h3>" + (isEdit ? "Edit" : "Add") + " publication / section</h3>" +
            (isEdit ? "" :
                '<div class="sc-inline">' +
                    '<div class="sc-field"><label>Publication</label><select id="f_pub">' + optionList(refs.publications, "", "value", "label") + "</select></div>" +
                    '<div class="sc-field"><label>TEN section</label><select id="f_sec">' + optionList(refs.sections, "", "value", "label") + "</select></div>" +
                "</div>") +
            '<div class="sc-inline">' +
                '<div class="sc-field"><label>Daily count (N)</label><input type="number" id="f_n" min="1" value="' + esc(s.daily_count || 10) + '"></div>' +
                '<div class="sc-field"><label>Cron schedule</label><input type="text" id="f_cron" value="' + esc(s.cron_schedule || "0 6 * * *") + '">' +
                    '<select id="f_cron_preset" style="margin-top:6px;">' +
                        '<option value="">— preset —</option>' +
                        '<option value="0 * * * *">Every hour</option>' +
                        '<option value="0 */6 * * *">Every 6 hours</option>' +
                        '<option value="0 6 * * *">Daily 06:00</option>' +
                        '<option value="0 12 * * *">Daily 12:00</option>' +
                    "</select></div>" +
            "</div>" +
            '<div class="sc-inline">' +
                '<div class="sc-field"><label>Journalist (byline)</label><select id="f_journalist"><option value="">— none —</option>' + optionList(refs.journalists, s.journalist_id || "", "value", "label") + "</select></div>" +
                '<div class="sc-field"><label>AI provider</label><select id="f_provider">' +
                    '<option value="anthropic"' + (provider === "anthropic" ? " selected" : "") + ">Claude (Anthropic)</option>" +
                    '<option value="openai"' + (provider === "openai" ? " selected" : "") + ">ChatGPT (OpenAI)</option>" +
                "</select></div>" +
            "</div>" +
            '<div class="sc-inline">' +
                '<div class="sc-field"><label>AI model</label><select id="f_model">' + modelOptions(provider, s.ai_model || DEFAULT_MODEL) + "</select></div>" +
                '<div class="sc-field"><label>VPN egress</label><select id="f_vpn"><option value="">— none —</option>' + optionList(refs.vpn_profiles, s.vpn_profile_id || "", "id", "name") + "</select></div>" +
            "</div>" +
            '<div class="sc-field"><label><input type="checkbox" id="f_auto" ' + (parseInt(s.auto_publish, 10) === 1 ? "checked" : "") + '> Auto-publish (promote inserts as published instead of draft)</label></div>' +
            (isEdit ? '<div class="sc-field"><label>Section prompt</label><textarea id="f_prompt">' + esc(s.prompt || "") + "</textarea></div>"
                    : '<p class="scraper-placeholder">The section prompt starts as a copy of the project prompt; edit it after creating.</p>') +
            '<div class="sc-modal-actions">' +
                '<button class="sc-btn secondary" id="f_cancel">Cancel</button>' +
                '<button class="sc-btn" id="f_save">Save</button>' +
            "</div>";
        openModal(html);

        document.getElementById("f_cancel").addEventListener("click", closeModal);
        var provSel = document.getElementById("f_provider");
        provSel.addEventListener("change", function () {
            document.getElementById("f_model").innerHTML = modelOptions(provSel.value, "");
        });
        var preset = document.getElementById("f_cron_preset");
        preset.addEventListener("change", function () {
            if (preset.value) document.getElementById("f_cron").value = preset.value;
        });

        document.getElementById("f_save").addEventListener("click", function () {
            var data = {
                daily_count: document.getElementById("f_n").value,
                cron_schedule: document.getElementById("f_cron").value,
                journalist_id: document.getElementById("f_journalist").value,
                ai_provider: document.getElementById("f_provider").value,
                ai_model: document.getElementById("f_model").value,
                vpn_profile_id: document.getElementById("f_vpn").value,
                auto_publish: document.getElementById("f_auto").checked ? 1 : 0
            };
            var call;
            if (isEdit) {
                data.id = s.id;
                data.prompt = document.getElementById("f_prompt").value;
                call = api("section", "update", data);
            } else {
                data.project_id = PROJECT_ID;
                data.publication_key = document.getElementById("f_pub").value;
                data.ten_section = document.getElementById("f_sec").value;
                call = api("section", "create", data);
            }
            call.then(function () { closeModal(); loadSections(); }).catch(alertErr);
        });
    }

    /* ---------------------------------------------------------------- sources */

    function loadSources(sectionId, body) {
        body.innerHTML = '<p class="scraper-placeholder">Loading sources…</p>';
        api("source", "list", { pub_section_id: sectionId }).then(function (json) {
            renderSources(sectionId, body, json.sources || []);
        }).catch(function (e) { body.innerHTML = '<p class="scraper-placeholder">Error: ' + esc(e.message) + "</p>"; });
    }

    function renderSources(sectionId, body, sources) {
        var html = '<button class="sc-btn small" data-add="src">+ Add source</button>';
        if (!sources.length) {
            html += '<p class="scraper-placeholder" style="margin-top:10px;">No sources yet.</p>';
        }
        body.innerHTML = html;
        sources.forEach(function (src) {
            // Each source is a collapsed card; its feeds stay hidden until expanded.
            var card = document.createElement("div");
            card.className = "sc-card";
            card.style.marginTop = "8px";
            card.innerHTML =
                '<div class="sc-card-head">' +
                    '<div><div class="sc-card-title">' + esc(src.name) + "</div>" +
                        '<div class="sc-card-meta">' + esc(src.homepage_url) + " · feeds: " + esc(src.feed_count) + "</div></div>" +
                    "<div>" +
                        '<button class="sc-btn small" data-act="expand"><i class="fas fa-chevron-down"></i> Feeds</button> ' +
                        '<button class="sc-btn small secondary" data-act="edit">Edit</button> ' +
                        '<button class="sc-btn small danger" data-act="del">Delete</button>' +
                    "</div>" +
                "</div>" +
                '<div class="sc-card-body"></div>';
            var fbody = card.querySelector(".sc-card-body");
            var expandBtn = card.querySelector('[data-act="expand"]');
            expandBtn.addEventListener("click", function () {
                if (fbody.classList.contains("open")) {
                    fbody.classList.remove("open");
                    expandBtn.innerHTML = '<i class="fas fa-chevron-down"></i> Feeds';
                    return;
                }
                fbody.classList.add("open");
                expandBtn.innerHTML = '<i class="fas fa-chevron-up"></i> Feeds';
                loadFeeds(src.id, fbody);
            });
            card.querySelector('[data-act="edit"]').addEventListener("click", function () { openSourceModal(sectionId, body, src); });
            card.querySelector('[data-act="del"]').addEventListener("click", function () {
                if (!confirm("Delete this source and its feeds?")) return;
                api("source", "delete", { id: src.id }).then(function () { loadSources(sectionId, body); }).catch(alertErr);
            });
            body.appendChild(card);
        });
        body.querySelector('[data-add="src"]').addEventListener("click", function () { openSourceModal(sectionId, body, null); });
    }

    function openSourceModal(sectionId, body, source) {
        var isEdit = !!source;
        var s = source || {};
        openModal(
            "<h3>" + (isEdit ? "Edit" : "Add") + " source</h3>" +
            '<div class="sc-field"><label>Name</label><input type="text" id="s_name" value="' + esc(s.name || "") + '"></div>' +
            '<div class="sc-field"><label>Homepage URL</label><input type="text" id="s_home" value="' + esc(s.homepage_url || "") + '" placeholder="https://…"></div>' +
            '<div class="sc-modal-actions"><button class="sc-btn secondary" id="s_cancel">Cancel</button><button class="sc-btn" id="s_save">Save</button></div>'
        );
        document.getElementById("s_cancel").addEventListener("click", closeModal);
        document.getElementById("s_save").addEventListener("click", function () {
            var data = { name: document.getElementById("s_name").value, homepage_url: document.getElementById("s_home").value };
            var call;
            if (isEdit) { data.id = s.id; call = api("source", "update", data); }
            else { data.pub_section_id = sectionId; call = api("source", "create", data); }
            call.then(function () { closeModal(); loadSources(sectionId, body); }).catch(alertErr);
        });
    }

    /* ---------------------------------------------------------------- feeds */

    function loadFeeds(sourceId, body) {
        body.innerHTML = '<p class="scraper-placeholder">Loading feeds…</p>';
        api("feed", "list", { source_id: sourceId }).then(function (json) {
            renderFeeds(sourceId, body, json.feeds || []);
        }).catch(function (e) { body.innerHTML = '<p class="scraper-placeholder">Error: ' + esc(e.message) + "</p>"; });
    }

    function renderFeeds(sourceId, body, feeds) {
        body.innerHTML = '<button class="sc-btn small" data-add="feed">+ Add feed</button>';
        feeds.forEach(function (f) {
            var robots = parseInt(f.respect_robots, 10) === 1 ? "robots:on" : "robots:OFF";
            var row = document.createElement("div");
            row.className = "sc-row";
            row.style.marginTop = "8px";
            row.innerHTML =
                "<div><span class='sc-badge off'>" + esc(f.feed_type) + "</span> " + esc(f.feed_url) +
                    ' <span class="scraper-placeholder">' + (f.source_category_label ? "· " + esc(f.source_category_label) + " " : "") + "· max " + esc(f.max_items || 20) + " · " + robots + "</span></div>" +
                "<div>" +
                    '<button class="sc-btn small secondary" data-act="edit">Edit</button> ' +
                    '<button class="sc-btn small danger" data-act="del">Delete</button>' +
                "</div>";
            row.querySelector('[data-act="edit"]').addEventListener("click", function () { openFeedModal(sourceId, body, f); });
            row.querySelector('[data-act="del"]').addEventListener("click", function () {
                if (!confirm("Delete this feed?")) return;
                api("feed", "delete", { id: f.id }).then(function () { loadFeeds(sourceId, body); }).catch(alertErr);
            });
            body.appendChild(row);
        });
        body.querySelector('[data-add="feed"]').addEventListener("click", function () { openFeedModal(sourceId, body, null); });
    }

    function openFeedModal(sourceId, body, feed) {
        var isEdit = !!feed;
        var f = feed || {};
        var respect = f.respect_robots === undefined ? 1 : parseInt(f.respect_robots, 10);
        openModal(
            "<h3>" + (isEdit ? "Edit" : "Add") + " feed</h3>" +
            '<div class="sc-field"><label>Feed / listing URL</label><input type="text" id="fd_url" value="' + esc(f.feed_url || "") + '" placeholder="https://…/rss"></div>' +
            '<div class="sc-inline">' +
                '<div class="sc-field"><label>Type</label><select id="fd_type"><option value="rss"' + (f.feed_type !== "html" ? " selected" : "") + ">RSS/Atom feed</option><option value=\"html\"" + (f.feed_type === "html" ? " selected" : "") + ">HTML listing page</option></select></div>" +
                '<div class="sc-field"><label>Source category label</label><input type="text" id="fd_cat" value="' + esc(f.source_category_label || "") + '" placeholder="e.g. Politik"></div>' +
            "</div>" +
            '<div class="sc-inline">' +
                '<div class="sc-field"><label>Max articles per fetch</label><input type="number" id="fd_max" min="1" value="' + esc(f.max_items || 20) + '"></div>' +
                '<div class="sc-field"><label>Rate limit (seconds)</label><input type="number" id="fd_rate" min="0" value="' + esc(f.rate_limit_seconds || "") + '" placeholder="default"></div>' +
            "</div>" +
            '<div class="sc-field"><label><input type="checkbox" id="fd_robots" ' + (respect === 1 ? "checked" : "") + '> Respect robots.txt</label>' +
                '<div id="fd_override_wrap" style="margin-top:6px;' + (respect === 1 ? "display:none;" : "") + '"><input type="text" id="fd_override" value="' + esc(f.robots_override_reason || "") + '" placeholder="Override reason (own/permitted site)"></div></div>' +
            '<div class="sc-modal-actions"><button class="sc-btn secondary" id="fd_cancel">Cancel</button><button class="sc-btn" id="fd_save">Save</button></div>'
        );
        document.getElementById("fd_cancel").addEventListener("click", closeModal);
        var robotsBox = document.getElementById("fd_robots");
        robotsBox.addEventListener("change", function () {
            document.getElementById("fd_override_wrap").style.display = robotsBox.checked ? "none" : "block";
        });
        document.getElementById("fd_save").addEventListener("click", function () {
            var data = {
                feed_url: document.getElementById("fd_url").value,
                feed_type: document.getElementById("fd_type").value,
                source_category_label: document.getElementById("fd_cat").value,
                max_items: document.getElementById("fd_max").value,
                rate_limit_seconds: document.getElementById("fd_rate").value,
                respect_robots: robotsBox.checked ? 1 : 0,
                robots_override_reason: document.getElementById("fd_override").value
            };
            var call;
            if (isEdit) { data.id = f.id; call = api("feed", "update", data); }
            else { data.source_id = sourceId; call = api("feed", "create", data); }
            call.then(function () { closeModal(); loadFeeds(sourceId, body); }).catch(alertErr);
        });
    }

    /* ---------------------------------------------------------------- prompt */

    function providerOptions(sel) {
        return '<option value="anthropic"' + (sel === "anthropic" ? " selected" : "") + ">Claude (Anthropic)</option>" +
               '<option value="openai"' + (sel === "openai" ? " selected" : "") + ">ChatGPT (OpenAI)</option>";
    }

    function openPromptModal() {
        api("prompt", "get", { project_id: PROJECT_ID }).then(function (json) {
            var p = json.project || {};
            var provider = p.default_ai_provider || DEFAULT_PROVIDER;
            var tprovider = p.translation_provider || "anthropic";
            var tmodel = p.translation_model || "claude-haiku-4-5";
            openModal(
                "<h3>Project settings</h3>" +
                '<div style="font-weight:600;color:#111827;font-size:13px;margin-bottom:6px;">Article writing</div>' +
                '<div class="sc-inline">' +
                    '<div class="sc-field"><label>Writing provider</label><select id="p_provider">' + providerOptions(provider) + "</select></div>" +
                    '<div class="sc-field"><label>Writing model</label><select id="p_model">' + modelOptions(provider, p.default_ai_model || DEFAULT_MODEL) + "</select></div>" +
                "</div>" +
                '<div style="font-weight:600;color:#111827;font-size:13px;margin:10px 0 6px;">Translation <span style="font-weight:400;color:#6b7280;">— used for the review summaries; a cheap model is fine</span></div>' +
                '<div class="sc-inline">' +
                    '<div class="sc-field"><label>Translation provider</label><select id="p_tprovider">' + providerOptions(tprovider) + "</select></div>" +
                    '<div class="sc-field"><label>Translation model</label><select id="p_tmodel">' + modelOptions(tprovider, tmodel) + "</select></div>" +
                "</div>" +
                '<div class="sc-field"><label>Article prompt template</label><textarea id="p_prompt">' + esc(p.default_prompt || "") + "</textarea></div>" +
                '<div class="sc-modal-actions"><button class="sc-btn secondary" id="p_cancel">Cancel</button><button class="sc-btn" id="p_save">Save</button></div>'
            );
            document.getElementById("p_cancel").addEventListener("click", closeModal);
            var provSel = document.getElementById("p_provider");
            provSel.addEventListener("change", function () { document.getElementById("p_model").innerHTML = modelOptions(provSel.value, ""); });
            var tprovSel = document.getElementById("p_tprovider");
            tprovSel.addEventListener("change", function () { document.getElementById("p_tmodel").innerHTML = modelOptions(tprovSel.value, ""); });
            document.getElementById("p_save").addEventListener("click", function () {
                api("prompt", "update", {
                    project_id: PROJECT_ID,
                    provider: provSel.value,
                    model: document.getElementById("p_model").value,
                    translation_provider: tprovSel.value,
                    translation_model: document.getElementById("p_tmodel").value,
                    prompt: document.getElementById("p_prompt").value
                }).then(function () { closeModal(); }).catch(alertErr);
            });
        }).catch(alertErr);
    }

    /* ---------------------------------------------------------------- vpn */

    function openVpnModal() {
        api("vpn", "list", {}).then(function (json) {
            renderVpnModal(json.vpn_profiles || []);
        }).catch(alertErr);
    }

    function renderVpnModal(profiles) {
        var rows = profiles.map(function (v) {
            return '<div class="sc-row"><div><strong>' + esc(v.name) + "</strong> " +
                '<span class="scraper-placeholder">' + esc(v.provider) + " · " + esc(v.country || "—") + " · " + esc(v.config_ref || "") + "</span></div>" +
                '<div><button class="sc-btn small secondary" data-edit="' + esc(v.id) + '">Edit</button> ' +
                '<button class="sc-btn small danger" data-del="' + esc(v.id) + '">Delete</button></div></div>';
        }).join("");
        openModal(
            "<h3>VPN profiles</h3>" +
            (rows || '<p class="scraper-placeholder">No profiles yet.</p>') +
            '<div style="margin-top:14px;"><button class="sc-btn" id="v_add">+ Add profile</button> ' +
            '<button class="sc-btn secondary" id="v_close">Close</button></div>'
        );
        document.getElementById("v_close").addEventListener("click", closeModal);
        document.getElementById("v_add").addEventListener("click", function () { openVpnForm(null); });
        Array.prototype.forEach.call(document.querySelectorAll("[data-edit]"), function (b) {
            b.addEventListener("click", function () {
                var v = profiles.filter(function (x) { return String(x.id) === b.getAttribute("data-edit"); })[0];
                openVpnForm(v);
            });
        });
        Array.prototype.forEach.call(document.querySelectorAll("[data-del]"), function (b) {
            b.addEventListener("click", function () {
                if (!confirm("Delete this VPN profile? Sections using it will be set to no VPN.")) return;
                api("vpn", "delete", { id: b.getAttribute("data-del") }).then(function () {
                    reloadRefsThen(openVpnModal);
                }).catch(alertErr);
            });
        });
    }

    function openVpnForm(profile) {
        var v = profile || {};
        openModal(
            "<h3>" + (profile ? "Edit" : "Add") + " VPN profile</h3>" +
            '<div class="sc-inline">' +
                '<div class="sc-field"><label>Provider</label><select id="v_provider">' +
                    ["protonvpn", "wireguard", "openvpn"].map(function (p) {
                        return '<option value="' + p + '"' + (v.provider === p ? " selected" : "") + ">" + p + "</option>";
                    }).join("") + "</select></div>" +
                '<div class="sc-field"><label>Name</label><input type="text" id="v_name" value="' + esc(v.name || "") + '"></div>' +
            "</div>" +
            '<div class="sc-inline">' +
                '<div class="sc-field"><label>Country code</label><input type="text" id="v_country" value="' + esc(v.country || "") + '" placeholder="DE"></div>' +
                '<div class="sc-field"><label>Config ref (server/config)</label><input type="text" id="v_config" value="' + esc(v.config_ref || "") + '" placeholder="DE#12 or /etc/wg/de.conf"></div>' +
            "</div>" +
            '<div class="sc-modal-actions"><button class="sc-btn secondary" id="v_cancel">Cancel</button><button class="sc-btn" id="v_save">Save</button></div>'
        );
        document.getElementById("v_cancel").addEventListener("click", function () { openVpnModal(); });
        document.getElementById("v_save").addEventListener("click", function () {
            var data = {
                provider: document.getElementById("v_provider").value,
                name: document.getElementById("v_name").value,
                country: document.getElementById("v_country").value,
                config_ref: document.getElementById("v_config").value
            };
            if (profile) data.id = profile.id;
            api("vpn", "save", data).then(function () { reloadRefsThen(openVpnModal); }).catch(alertErr);
        });
    }

    /* ---------------------------------------------------------------- init */

    function alertErr(e) { alert("Error: " + (e && e.message ? e.message : e)); }

    function reloadRefsThen(cb) {
        api("refs", "all", {}).then(function (json) {
            refs = json;
            if (cb) cb();
        }).catch(alertErr);
    }

    document.getElementById("scAddSection").addEventListener("click", function () { openSectionModal(null); });
    document.getElementById("scEditPrompt").addEventListener("click", openPromptModal);
    document.getElementById("scManageVpn").addEventListener("click", openVpnModal);

    // Boot: load refs, then sections.
    api("refs", "all", {}).then(function (json) {
        refs = json;
        loadSections();
    }).catch(function (e) {
        document.getElementById("scSections").innerHTML = '<p class="scraper-placeholder">Error loading references: ' + esc(e.message) + "</p>";
    });
})();
