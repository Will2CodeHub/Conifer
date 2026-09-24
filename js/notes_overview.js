/* Notes overview page (module-notes.php).
 * Lists every note the user can see across all pages/projects — searchable,
 * filterable and paginated. Reuses window.ProjectNotes (api + openThread) which
 * header.php loads on every page. */
(function () {
    "use strict";

    var state = { q: "", view: "all", project_id: 0, page: 1, per_page: 25, pages: 1, total: 0 };
    var searchTimer = null;

    function ready(fn) {
        if (window.ProjectNotes && document.getElementById("novRows")) { fn(); return; }
        setTimeout(function () { ready(fn); }, 60);
    }

    function esc(s) {
        return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
            return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
        });
    }

    var COLORS = { yellow: "#fde047", pink: "#f9a8d4", green: "#bef264", blue: "#7dd3fc", orange: "#fdba74", purple: "#c4b5fd" };
    var STATUS_LABEL = { active: "Open", in_progress: "In progress", on_hold: "On hold", completed: "Completed", archived: "Archived", deleted: "Deleted" };

    function fmtDate(s, withTime) {
        if (!s) return "";
        var d = new Date(String(s).replace(" ", "T"));
        if (isNaN(d.getTime())) return esc(s);
        var opts = withTime
            ? { day: "numeric", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" }
            : { day: "numeric", month: "short", year: "numeric" };
        return d.toLocaleString([], opts);
    }

    function viaLabel(v) {
        if (v === "email") return "email";
        if (v === "app" || v === "bell") return "bell";
        if (v === "both") return "email + bell";
        return v || "";
    }

    function rowHtml(n) {
        var swatch = '<span class="nov-swatch" style="background:' + (COLORS[n.color] || COLORS.yellow) + ';"></span>';
        var title = n.title ? esc(n.title) : "";
        var snippet = esc((n.body || "").replace(/\s+/g, " ").trim().slice(0, 90));
        var noteCell = swatch + (title ? '<span class="nov-title">' + title + "</span>" : "") +
            (title && snippet ? '<div class="nov-snippet">' + snippet + "</div>"
                              : '<span class="nov-title">' + (snippet || "Note") + "</span>") +
            (n.reply_count ? ' <span class="nov-muted">· ' + n.reply_count + ' repl' + (n.reply_count === 1 ? "y" : "ies") + "</span>" : "");

        var pageCell = n.page_key
            ? '<a class="nov-link" href="/management/' + esc(n.page_key) + '" onclick="event.stopPropagation()">' + esc(n.page_label || n.page_key) + "</a>"
            : '<span class="nov-muted">—</span>';

        var projectCell = n.project_name ? esc(n.project_name) : '<span class="nov-muted">—</span>';
        var authorCell = esc(n.author_name || "—");

        var people = (n.shares || []);
        var peopleCell = people.length
            ? '<div class="nov-people">' + people.map(function (p) { return '<span class="nov-chip" title="' + esc(p.permission) + '">' + esc(p.name) + "</span>"; }).join("") + "</div>"
            : '<span class="nov-muted">—</span>';

        var deadlineCell = n.deadline
            ? '<span class="' + (n.is_overdue ? "nov-overdue" : "") + '">' + fmtDate(n.deadline, true) + "</span>"
            : '<span class="nov-muted">—</span>';

        var remindCell = n.next_remind_at
            ? fmtDate(n.next_remind_at, true) + ' <span class="nov-muted">· ' + esc(viaLabel(n.remind_via)) + "</span>"
            : '<span class="nov-muted">—</span>';

        var statusCell = '<span class="nov-pill nov-st-' + esc(n.status) + '">' + esc(STATUS_LABEL[n.status] || n.status) + "</span>";
        var createdCell = '<span class="nov-muted">' + fmtDate(n.created_at, false) + "</span>";

        return '<tr data-note="' + n.id + '">' +
            "<td>" + noteCell + "</td>" +
            "<td>" + pageCell + "</td>" +
            '<td class="nov-col-project">' + projectCell + "</td>" +
            "<td>" + authorCell + "</td>" +
            "<td>" + peopleCell + "</td>" +
            "<td>" + deadlineCell + "</td>" +
            "<td>" + remindCell + "</td>" +
            "<td>" + statusCell + "</td>" +
            '<td class="nov-col-created">' + createdCell + "</td>" +
            "</tr>";
    }

    function render(d) {
        var body = document.getElementById("novRows");
        var notes = d.notes || [];
        if (!notes.length) {
            body.innerHTML = '<tr><td colspan="9" class="nov-empty">' +
                (state.q ? "No notes match your search." : "No notes to show.") + "</td></tr>";
        } else {
            body.innerHTML = notes.map(rowHtml).join("");
            body.querySelectorAll("tr[data-note]").forEach(function (tr) {
                tr.addEventListener("click", function () {
                    ProjectNotes.openThread(parseInt(tr.getAttribute("data-note"), 10), load);
                });
            });
        }
        state.pages = d.pages || 1;
        state.total = d.total || 0;
        document.getElementById("novCount").textContent =
            state.total + " note" + (state.total === 1 ? "" : "s");
        document.getElementById("novPage").textContent = "Page " + state.page + " / " + Math.max(1, state.pages);
        document.getElementById("novPrev").disabled = state.page <= 1;
        document.getElementById("novNext").disabled = state.page >= state.pages;
    }

    function load() {
        var body = document.getElementById("novRows");
        ProjectNotes.api("all", {
            q: state.q, view: state.view, project_id: state.project_id,
            page: state.page, per_page: state.per_page
        }).then(function (d) {
            if (!d || !d.success) {
                body.innerHTML = '<tr><td colspan="9" class="nov-empty">' + esc((d && d.message) || "Could not load notes.") + "</td></tr>";
                return;
            }
            render(d);
        }).catch(function () {
            body.innerHTML = '<tr><td colspan="9" class="nov-empty">Could not load notes.</td></tr>';
        });
    }

    function loadProjects() {
        ProjectNotes.api("projects", {}).then(function (d) {
            if (!d || !d.success || !d.projects) return;
            var sel = document.getElementById("novProject");
            d.projects.forEach(function (p) {
                var opt = document.createElement("option");
                opt.value = p.id;
                opt.textContent = p.project_name || p.name || ("Project " + p.id);
                sel.appendChild(opt);
            });
        }).catch(function () {});
    }

    ready(function () {
        ProjectNotes.injectCss();
        loadProjects();
        load();

        document.getElementById("novSearch").addEventListener("input", function (e) {
            clearTimeout(searchTimer);
            var v = e.target.value;
            searchTimer = setTimeout(function () { state.q = v.trim(); state.page = 1; load(); }, 300);
        });
        document.getElementById("novStatus").addEventListener("change", function (e) {
            state.view = e.target.value; state.page = 1; load();
        });
        document.getElementById("novProject").addEventListener("change", function (e) {
            state.project_id = parseInt(e.target.value, 10) || 0; state.page = 1; load();
        });
        document.getElementById("novPrev").addEventListener("click", function () {
            if (state.page > 1) { state.page--; load(); }
        });
        document.getElementById("novNext").addEventListener("click", function () {
            if (state.page < state.pages) { state.page++; load(); }
        });

        // Keep in sync when a note is changed from its opened thread.
        document.addEventListener("ten-notes-changed", load);
    });
})();
