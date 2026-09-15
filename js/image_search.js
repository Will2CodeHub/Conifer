/* TEN Article editor — free-image search across royalty-free sources.
 *
 * Toolbar button -> popup: search text + source checkboxes (all on) -> Search ->
 * thumbnail grid (source labelled beneath each) -> click a thumb for an in-popup
 * preview -> "Select & crop" -> download the image server-side (by index, so the
 * client never sends a URL) -> Cropper.js (490:310) -> crop_save_image.php ->
 * insert the saved WebP at the top of the article. Same pipeline as the scraper
 * suggestion strip (js/scraper_image_suggest.js), just driven by a manual search.
 */
(function () {
    "use strict";

    var EP = "ajax/image_search.php";
    var SAVE = "crop_save_image.php";
    var TOP_Z = 2147483600;
    var built = false;
    var providersLoaded = false;
    var items = [];
    var selected = null;   // currently-previewed item
    var cropper = null;
    // Post-save behaviour, set per-open. Default: editor toolbar (insert at article top).
    var opts = { insertIntoArticle: true, onSaved: null };

    function esc(s) {
        return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
            return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
        });
    }
    function el(id) { return document.getElementById(id); }
    function alertErr(m) {
        if (typeof Swal !== "undefined") Swal.fire({ icon: "error", title: "Error", text: m, didOpen: liftSwal });
        else alert("Error: " + m);
    }
    function toast(m) {
        if (typeof Swal !== "undefined") Swal.fire({ toast: true, position: "top-end", timer: 2400, showConfirmButton: false, icon: "success", title: m, didOpen: liftSwal });
    }
    function liftSwal() { var c = document.querySelector(".swal2-container"); if (c) c.style.zIndex = TOP_Z + 100; }

    function post(params) {
        var fd = new FormData();
        Object.keys(params).forEach(function (k) { fd.append(k, params[k]); });
        return fetch(EP, { method: "POST", body: fd, credentials: "same-origin" }).then(function (r) { return r.json(); });
    }

    /* ---------- styles (injected once) ---------- */
    function ensureStyles() {
        if (el("imgSearchStyles")) return;
        var s = document.createElement("style");
        s.id = "imgSearchStyles";
        s.textContent =
            "#imgSearchOv{position:fixed;inset:0;background:rgba(15,20,28,.55);backdrop-filter:blur(2px);z-index:" + TOP_Z + ";display:flex;align-items:flex-start;justify-content:center;padding:34px 16px;overflow:auto;}" +
            "#imgSearchModal{background:#fff;border-radius:14px;width:min(940px,96vw);box-shadow:0 24px 60px rgba(0,0,0,.32);overflow:hidden;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;}" +
            "#imgSearchModal .is-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:16px 20px;border-bottom:1px solid #eceef2;}" +
            "#imgSearchModal .is-head h3{margin:0;font-size:17px;color:#15181e;font-weight:700;}" +
            "#imgSearchModal .is-x{border:none;background:#f1f3f6;border-radius:8px;width:32px;height:32px;cursor:pointer;font-size:16px;color:#475467;}" +
            "#imgSearchModal .is-x:hover{background:#e7eaef;color:#15181e;}" +
            "#imgSearchModal .is-body{padding:18px 20px;max-height:74vh;overflow:auto;}" +
            "#imgSearchModal .is-searchrow{display:flex;gap:10px;margin-bottom:12px;}" +
            "#imgSearchModal .is-searchrow input{flex:1;padding:11px 13px;border:1px solid #d3d8e0;border-radius:9px;font-size:14px;}" +
            "#imgSearchModal .is-searchrow input:focus{outline:none;border-color:#b45309;box-shadow:0 0 0 3px rgba(180,83,9,.25);}" +
            "#imgSearchModal .is-srch{background:#15181e;color:#fff;border:none;padding:0 20px;border-radius:9px;font-weight:600;font-size:14px;cursor:pointer;display:inline-flex;align-items:center;gap:8px;}" +
            "#imgSearchModal .is-srch:hover{filter:brightness(1.15);}" +
            "#imgSearchModal .is-srch:disabled{opacity:.55;cursor:default;}" +
            "#imgSearchModal .is-sources{display:flex;flex-wrap:wrap;gap:8px 16px;margin-bottom:8px;padding-bottom:14px;border-bottom:1px solid #eceef2;}" +
            "#imgSearchModal .is-src{display:inline-flex;align-items:center;gap:6px;font-size:13px;color:#2b313b;font-weight:600;cursor:pointer;}" +
            "#imgSearchModal .is-src input{width:15px;height:15px;accent-color:#15181e;}" +
            "#imgSearchModal .is-hint{font-size:12px;color:#98a0ac;margin:0 0 12px;}" +
            "#imgSearchModal .is-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px;}" +
            "#imgSearchModal .is-cell{border:1px solid #e6e8ec;border-radius:10px;overflow:hidden;cursor:pointer;background:#fff;transition:border-color .15s,box-shadow .15s,transform .1s;}" +
            "#imgSearchModal .is-cell:hover{border-color:#b45309;box-shadow:0 4px 14px rgba(16,24,40,.1);transform:translateY(-1px);}" +
            "#imgSearchModal .is-cell img{display:block;width:100%;height:110px;object-fit:cover;background:#f1f5f9;}" +
            "#imgSearchModal .is-cell .is-cap{font-size:11px;font-weight:700;color:#6b7280;padding:6px 8px;text-align:center;letter-spacing:.02em;text-transform:uppercase;}" +
            "#imgSearchModal .is-empty{color:#98a0ac;font-size:14px;padding:32px 0;text-align:center;}" +
            "#imgSearchModal .is-preview{display:flex;gap:20px;flex-wrap:wrap;}" +
            "#imgSearchModal .is-preview .is-pv-img{flex:1;min-width:280px;}" +
            "#imgSearchModal .is-preview .is-pv-img img{width:100%;border-radius:10px;border:1px solid #e6e8ec;}" +
            "#imgSearchModal .is-preview .is-pv-meta{flex:1;min-width:220px;}" +
            "#imgSearchModal .is-pv-source{display:inline-block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#b45309;background:#fff7ed;border:1px solid #fbe3c6;padding:3px 10px;border-radius:999px;margin-bottom:12px;}" +
            "#imgSearchModal .is-pv-title{font-size:16px;font-weight:600;color:#15181e;margin:0 0 10px;line-height:1.4;}" +
            "#imgSearchModal .is-pv-row{font-size:13px;color:#475467;margin-bottom:8px;}" +
            "#imgSearchModal .is-pv-row b{color:#15181e;}" +
            "#imgSearchModal .is-pv-row a{color:#15181e;font-weight:600;}" +
            "#imgSearchModal .is-actions{display:flex;gap:10px;margin-top:18px;}" +
            "#imgSearchModal .is-btn{border:none;border-radius:8px;padding:10px 16px;font-weight:600;font-size:13px;cursor:pointer;display:inline-flex;align-items:center;gap:7px;}" +
            "#imgSearchModal .is-btn.primary{background:#15181e;color:#fff;}" +
            "#imgSearchModal .is-btn.primary:hover{filter:brightness(1.15);}" +
            "#imgSearchModal .is-btn.ghost{background:#fff;color:#2b313b;border:1px solid #d3d8e0;}" +
            "#imgSearchModal .is-btn.ghost:hover{background:#f8fafb;border-color:#15181e;}" +
            "#imgSearchModal .is-spin{color:#6b7280;font-size:14px;padding:32px 0;text-align:center;}";
        document.head.appendChild(s);
    }

    /* ---------- build modal ---------- */
    function build() {
        if (built) return;
        ensureStyles();
        var ov = document.createElement("div");
        ov.id = "imgSearchOv";
        ov.style.display = "none";
        ov.innerHTML =
            '<div id="imgSearchModal" role="dialog" aria-label="Search free images">' +
                '<div class="is-head"><h3><i class="fas fa-images"></i> Search free images</h3>' +
                    '<button type="button" class="is-x" id="isClose" title="Close">&times;</button></div>' +
                '<div class="is-body">' +
                    '<div class="is-searchrow">' +
                        '<input type="text" id="isQuery" placeholder="Search for an image — e.g. Munich town hall, wind turbines, football stadium">' +
                        '<button type="button" class="is-srch" id="isSearchBtn"><i class="fas fa-search"></i> Search</button>' +
                    '</div>' +
                    '<div class="is-sources" id="isSources"></div>' +
                    '<p class="is-hint">All sources are royalty-free. Pick a thumbnail to preview, then crop and insert at the top of the article.</p>' +
                    '<div id="isResults"><div class="is-empty">Enter a search above to find images.</div></div>' +
                '</div>' +
            '</div>';
        document.body.appendChild(ov);
        ov.addEventListener("click", function (e) { if (e.target === ov) close(); });
        el("isClose").addEventListener("click", close);
        el("isSearchBtn").addEventListener("click", runSearch);
        el("isQuery").addEventListener("keydown", function (e) { if (e.key === "Enter") { e.preventDefault(); runSearch(); } });
        built = true;
    }

    function loadProviders() {
        if (providersLoaded) return Promise.resolve();
        return post({ action: "providers" }).then(function (j) {
            var box = el("isSources");
            var list = (j && j.providers) || [];
            if (!list.length) { box.innerHTML = '<span class="is-hint">No image sources configured.</span>'; return; }
            box.innerHTML = '<span style="font-size:12px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:#98a0ac;align-self:center;">Sources</span>' +
                list.map(function (p) {
                    return '<label class="is-src"><input type="checkbox" class="isProv" value="' + esc(p.key) + '" checked> ' + esc(p.label) + '</label>';
                }).join("");
            providersLoaded = true;
        });
    }

    /* ---------- search + grid ---------- */
    function runSearch() {
        var q = el("isQuery").value.trim();
        if (!q) { el("isQuery").focus(); return; }
        var provs = Array.prototype.map.call(document.querySelectorAll(".isProv:checked"), function (c) { return c.value; });
        if (!provs.length) { alertErr("Pick at least one source."); return; }
        var btn = el("isSearchBtn");
        btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Searching…';
        el("isResults").innerHTML = '<div class="is-spin"><i class="fas fa-spinner fa-spin"></i> Searching ' + provs.length + ' source' + (provs.length === 1 ? '' : 's') + '…</div>';
        post({ action: "search", q: q, providers: provs.join(",") }).then(function (j) {
            btn.disabled = false; btn.innerHTML = '<i class="fas fa-search"></i> Search';
            if (j.status !== "success") { el("isResults").innerHTML = '<div class="is-empty">' + esc(j.message || "Search failed.") + '</div>'; return; }
            items = j.items || [];
            renderGrid();
        }).catch(function (e) {
            btn.disabled = false; btn.innerHTML = '<i class="fas fa-search"></i> Search';
            el("isResults").innerHTML = '<div class="is-empty">Error: ' + esc(e.message) + '</div>';
        });
    }

    function renderGrid() {
        var box = el("isResults");
        if (!items.length) { box.innerHTML = '<div class="is-empty">No images found — try different words or more sources.</div>'; return; }
        box.innerHTML = '<div class="is-grid">' + items.map(function (it) {
            return '<div class="is-cell" data-i="' + it.index + '">' +
                '<img loading="lazy" decoding="async" src="' + esc(it.thumb) + '" alt="' + esc(it.title || "") + '">' +
                '<div class="is-cap">' + esc(it.provider_label || it.provider) + '</div>' +
            '</div>';
        }).join("") + '</div>';
        Array.prototype.forEach.call(box.querySelectorAll(".is-cell"), function (c) {
            c.addEventListener("click", function () { showPreview(parseInt(c.getAttribute("data-i"), 10)); });
        });
    }

    /* ---------- preview ---------- */
    function itemByIndex(i) { for (var k = 0; k < items.length; k++) if (items[k].index === i) return items[k]; return null; }

    function showPreview(i) {
        var it = itemByIndex(i);
        if (!it) return;
        selected = it;
        var srcLink = it.source_page ? '<div class="is-pv-row"><a href="' + esc(it.source_page) + '" target="_blank" rel="noopener">View on ' + esc(it.provider_label || it.provider) + ' ↗</a></div>' : '';
        el("isResults").innerHTML =
            '<div class="is-preview">' +
                '<div class="is-pv-img"><img src="' + esc(it.thumb) + '" alt="' + esc(it.title || "") + '"></div>' +
                '<div class="is-pv-meta">' +
                    '<span class="is-pv-source">' + esc(it.provider_label || it.provider) + '</span>' +
                    (it.title ? '<h4 class="is-pv-title">' + esc(it.title) + '</h4>' : '') +
                    (it.attribution ? '<div class="is-pv-row"><b>Attribution:</b> ' + esc(it.attribution) + '</div>' : '') +
                    (it.license ? '<div class="is-pv-row"><b>Licence:</b> ' + esc(it.license) + '</div>' : '') +
                    srcLink +
                    '<div class="is-actions">' +
                        '<button type="button" class="is-btn primary" id="isCropBtn"><i class="fas fa-crop-simple"></i> Select &amp; crop</button>' +
                        '<button type="button" class="is-btn ghost" id="isBackBtn"><i class="fas fa-arrow-left"></i> Back to results</button>' +
                    '</div>' +
                '</div>' +
            '</div>';
        el("isCropBtn").addEventListener("click", function () { fetchAndCrop(it.index); });
        el("isBackBtn").addEventListener("click", renderGrid);
    }

    /* ---------- download -> crop -> save -> insert ---------- */
    function fetchAndCrop(index) {
        if (typeof Swal !== "undefined") Swal.fire({ title: "Fetching image…", allowOutsideClick: false, didOpen: function () { liftSwal(); Swal.showLoading(); } });
        post({ action: "download", index: index }).then(function (d) {
            if (typeof Swal !== "undefined") Swal.close();
            if (d.status !== "success") { alertErr(d.message || "Download failed"); return; }
            openCropModal(d.dataUrl, d.attribution || (selected && selected.attribution) || "");
        }).catch(function (e) { if (typeof Swal !== "undefined") Swal.close(); alertErr(e.message); });
    }

    function closeCropModal() {
        if (cropper) { cropper.destroy(); cropper = null; }
        var ov = el("isCropOverlay");
        if (ov) ov.remove();
    }

    function openCropModal(dataUrl, attribution) {
        closeCropModal();
        var ov = document.createElement("div");
        ov.id = "isCropOverlay";
        ov.style.cssText = "position:fixed;inset:0;background:rgba(0,0,0,.72);z-index:" + (TOP_Z + 50) + ";display:flex;align-items:center;justify-content:center;padding:20px;";
        ov.innerHTML =
            '<div style="background:#fff;border-radius:12px;padding:16px;width:min(780px,94vw);max-height:92vh;overflow:auto;">' +
                '<h3 style="margin:0 0 10px;font-size:16px;color:#15181e;">Crop image (saved to your library as a WebP)</h3>' +
                '<div style="max-height:58vh;"><img id="isCropImg" style="max-width:100%;display:block;"></div>' +
                '<div style="margin-top:12px;"><label style="font-size:12px;font-weight:600;color:#374151;">Attribution</label>' +
                    '<input id="isCropAttr" style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;box-sizing:border-box;" value="' + esc(attribution) + '"></div>' +
                '<div style="display:flex;justify-content:flex-end;gap:10px;margin-top:14px;">' +
                    '<button id="isCropCancel" style="background:#475569;color:#fff;border:none;padding:9px 15px;border-radius:6px;cursor:pointer;font-weight:600;">Cancel</button>' +
                    '<button id="isCropSave" style="background:#15181e;color:#fff;border:none;padding:9px 15px;border-radius:6px;cursor:pointer;font-weight:600;">' + (opts.insertIntoArticle !== false ? "Crop &amp; insert at top" : "Crop &amp; save to library") + '</button>' +
                '</div>' +
            '</div>';
        document.body.appendChild(ov);
        var img = el("isCropImg");
        img.onload = function () {
            if (typeof Cropper !== "undefined") cropper = new Cropper(img, { aspectRatio: 490 / 310, viewMode: 1, autoCropArea: 1, background: false });
        };
        img.src = dataUrl;
        el("isCropCancel").addEventListener("click", closeCropModal);
        el("isCropSave").addEventListener("click", saveCrop);
    }

    function saveCrop() {
        if (!cropper) { alertErr("Cropper not ready"); return; }
        var attribution = el("isCropAttr").value;
        var canvas = cropper.getCroppedCanvas({ width: 790, height: 500, fillColor: "#ffffff", imageSmoothingEnabled: true, imageSmoothingQuality: "high" });
        var jpeg = canvas.toDataURL("image/jpeg", 0.95);
        var insert = opts.insertIntoArticle !== false;
        var btn = el("isCropSave");
        var label = insert ? "Crop & insert at top" : "Crop & save to library";
        btn.disabled = true; btn.textContent = "Saving…";
        fetch(SAVE, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            credentials: "same-origin",
            body: JSON.stringify({ image: jpeg, attribution: attribution })
        })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.status === "success") {
                    if (insert) { insertImage(d.url, attribution); }
                    closeCropModal();
                    close();
                    if (typeof opts.onSaved === "function") opts.onSaved(d, attribution);
                    toast(insert ? "Image saved and inserted at top" : "Image saved to your library");
                } else {
                    btn.disabled = false; btn.textContent = label;
                    alertErr(d.message || "Save failed");
                }
            })
            .catch(function (e) { btn.disabled = false; btn.textContent = label; alertErr(e.message); });
    }

    function insertImage(url, attribution) {
        // Insert the image and append its credit at the bottom of the article, linked
        // so removing the image removes its credit too. Helper lives in module-articles.php.
        if (window.tenInsertArticleImage) { window.tenInsertArticleImage(url, attribution); return; }
        var editor = el("article_text");
        if (!editor) { alertErr("Editor not found"); return; }
        var img = document.createElement("img");
        img.src = url;
        img.setAttribute("alt", "");
        editor.insertBefore(img, editor.firstChild);
    }

    /* ---------- open / close ---------- */
    // o.insertIntoArticle (default true) inserts the saved image at the top of the
    // article; o.onSaved(data, attribution) fires after a successful save (data is
    // the crop_save_image.php response: url, attribution, image_html).
    function open(o) {
        opts = { insertIntoArticle: true, onSaved: null };
        if (o && typeof o === "object") {
            if (o.insertIntoArticle === false) opts.insertIntoArticle = false;
            if (typeof o.onSaved === "function") opts.onSaved = o.onSaved;
        }
        build();
        loadProviders();
        el("imgSearchOv").style.display = "flex";
        setTimeout(function () { var q = el("isQuery"); if (q) q.focus(); }, 40);
    }
    function close() {
        var ov = el("imgSearchOv");
        if (ov) ov.style.display = "none";
    }

    window.openImageSearch = open;

    // Delegated so it works wherever the editor toolbar lives (it is moved into the modal slot).
    document.addEventListener("click", function (e) {
        var btn = e.target.closest && e.target.closest("#search-free-images");
        if (btn) { e.preventDefault(); open(); }   // editor toolbar → insert into article
    });
})();
