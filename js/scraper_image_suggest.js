/* TEN Scraper — royalty-free image suggestions inside the Article Tool editor.
 * A chosen suggestion is downloaded server-side, opened in Cropper.js, then saved
 * via crop_save_image.php (renamed WebP under /article_images + attribution) —
 * the same pipeline as the Pixabay flow — and the saved image is inserted at the
 * top of the article body.
 */
(function () {
    "use strict";

    var EP = "ajax/scraper_image_suggestions.php";
    var DL = "ajax/scraper_download_image.php";
    var SAVE = "crop_save_image.php";
    var STRIP_ID = "scImgSuggestStrip";
    var TOP_Z = "2147483000";
    var lastId = null;
    var lastSuggestions = [];
    var cropper = null;

    function esc(s) {
        return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
            return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
        });
    }

    function alertErr(m) {
        if (typeof Swal !== "undefined") Swal.fire({ icon: "error", title: "Error", text: m, didOpen: liftSwal });
        else alert("Error: " + m);
    }
    function toast(m) {
        if (typeof Swal !== "undefined") Swal.fire({ toast: true, position: "top-end", timer: 2400, showConfirmButton: false, icon: "success", title: m, didOpen: liftSwal });
    }
    function liftSwal() {
        var c = document.querySelector(".swal2-container");
        if (c) c.style.zIndex = TOP_Z;
    }

    function currentArticleId() {
        var f = document.getElementById("article_id_field");
        if (f && /^\d+$/.test(f.value || "")) return f.value;
        var span = document.getElementById("modalArticleId");
        if (span) {
            var m = (span.textContent || "").match(/\d+/);
            if (m) return m[0];
        }
        return null;
    }

    // The editor (#article_text) is position:absolute filling .editor-container and
    // paints over its siblings, so anchor the strip BEFORE the whole container.
    function anchorEl() {
        var editor = document.getElementById("article_text");
        if (!editor) return null;
        return editor.closest(".editor-container") || editor;
    }

    function ensureStrip() {
        var anchor = anchorEl();
        if (!anchor || !anchor.parentNode) return null;
        var strip = document.getElementById(STRIP_ID);
        if (!strip) {
            strip = document.createElement("div");
            strip.id = STRIP_ID;
            strip.style.cssText = "margin:0 0 10px;padding:10px;border:1px solid #e5e7eb;border-radius:8px;background:#f9fafb;";
        }
        if (strip.nextElementSibling !== anchor) anchor.parentNode.insertBefore(strip, anchor);
        return strip;
    }

    function insertImage(url, attribution) {
        var editor = document.getElementById("article_text");
        if (!editor) return;
        var img = document.createElement("img");
        img.src = url;
        img.alt = attribution || "";
        img.className = "editor-image editor-img-wrapped";
        img.style.cssText = "float:left;margin:0 15px 10px 0;max-width:50%;height:auto;cursor:pointer;";
        editor.insertBefore(img, editor.firstChild);
        var attr = document.getElementById("attribution");
        if (attr && !attr.value) attr.value = attribution || "";
    }

    /* ---- download -> crop -> save pipeline ---- */

    function useSuggestion(index) {
        if (!lastSuggestions[index]) return;
        if (typeof Swal !== "undefined") {
            Swal.fire({ title: "Fetching image…", allowOutsideClick: false, didOpen: function () { liftSwal(); Swal.showLoading(); } });
        }
        var fd = new FormData();
        fd.append("article_id", lastId);
        fd.append("index", index);
        fetch(DL, { method: "POST", body: fd, credentials: "same-origin" })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (typeof Swal !== "undefined") Swal.close();
                if (d.status !== "success") { alertErr(d.message || "Download failed"); return; }
                openCropModal(d.dataUrl, d.attribution || lastSuggestions[index].attribution || "");
            })
            .catch(function (e) { if (typeof Swal !== "undefined") Swal.close(); alertErr(e.message); });
    }

    function closeCropModal() {
        if (cropper) { cropper.destroy(); cropper = null; }
        var ov = document.getElementById("scCropOverlay");
        if (ov) ov.remove();
    }

    function openCropModal(dataUrl, attribution) {
        closeCropModal();
        var ov = document.createElement("div");
        ov.id = "scCropOverlay";
        ov.style.cssText = "position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:" + TOP_Z + ";display:flex;align-items:center;justify-content:center;";
        ov.innerHTML =
            '<div style="background:#fff;border-radius:12px;padding:16px;width:min(780px,94vw);max-height:92vh;overflow:auto;">' +
                '<h3 style="margin:0 0 10px;font-size:16px;">Crop image (saved to your library as a WebP)</h3>' +
                '<div style="max-height:58vh;"><img id="scCropImg" style="max-width:100%;display:block;"></div>' +
                '<div style="margin-top:12px;"><label style="font-size:12px;font-weight:600;color:#374151;">Attribution</label>' +
                    '<input id="scCropAttr" style="width:100%;padding:8px 10px;border:1px solid #d1d5db;border-radius:6px;box-sizing:border-box;" value="' + esc(attribution) + '"></div>' +
                '<div style="display:flex;justify-content:flex-end;gap:10px;margin-top:14px;">' +
                    '<button id="scCropCancel" style="background:#475569;color:#fff;border:none;padding:9px 15px;border-radius:6px;cursor:pointer;font-weight:600;">Cancel</button>' +
                    '<button id="scCropSave" style="background:#2563eb;color:#fff;border:none;padding:9px 15px;border-radius:6px;cursor:pointer;font-weight:600;">Crop &amp; insert at top</button>' +
                "</div>" +
            "</div>";
        document.body.appendChild(ov);
        var img = document.getElementById("scCropImg");
        img.onload = function () {
            if (typeof Cropper !== "undefined") {
                cropper = new Cropper(img, { aspectRatio: 490 / 310, viewMode: 1, autoCropArea: 1, background: false });
            }
        };
        img.src = dataUrl;
        document.getElementById("scCropCancel").addEventListener("click", closeCropModal);
        document.getElementById("scCropSave").addEventListener("click", saveCrop);
    }

    function saveCrop() {
        if (!cropper) { alertErr("Cropper not ready"); return; }
        var attribution = document.getElementById("scCropAttr").value;
        var canvas = cropper.getCroppedCanvas({ width: 490, height: 310, fillColor: "#ffffff", imageSmoothingEnabled: true, imageSmoothingQuality: "high" });
        var jpeg = canvas.toDataURL("image/jpeg", 0.9);
        var btn = document.getElementById("scCropSave");
        btn.disabled = true;
        btn.textContent = "Saving…";
        fetch(SAVE, {
            method: "POST",
            headers: { "Content-Type": "application/json" },
            credentials: "same-origin",
            body: JSON.stringify({ image: jpeg, attribution: attribution })
        })
            .then(function (r) { return r.json(); })
            .then(function (d) {
                if (d.status === "success") {
                    insertImage(d.url, attribution);
                    closeCropModal();
                    toast("Image saved and inserted at top");
                } else {
                    btn.disabled = false; btn.textContent = "Crop & insert at top";
                    alertErr(d.message || "Save failed");
                }
            })
            .catch(function (e) { btn.disabled = false; btn.textContent = "Crop & insert at top"; alertErr(e.message); });
    }

    /* ---- suggestion strip ---- */

    function render(suggestions) {
        var strip = ensureStrip();
        if (!strip) return;
        if (!suggestions || !suggestions.length) {
            strip.style.display = "none";
            strip.innerHTML = "";
            return;
        }
        var thumbs = suggestions.map(function (s, i) {
            return '<img data-i="' + i + '" src="' + esc(s.thumb) + '" title="' + esc((s.attribution || "") + " · " + (s.license || "")) +
                '" style="height:64px;width:auto;border-radius:4px;cursor:pointer;border:1px solid #e5e7eb;">';
        }).join(" ");
        strip.innerHTML =
            '<div style="font-size:12px;font-weight:600;color:#374151;margin-bottom:6px;">' +
                '<i class="fas fa-images"></i> Scraper image suggestions — royalty-free, click to crop &amp; insert</div>' +
            '<div style="display:flex;gap:8px;flex-wrap:wrap;">' + thumbs + "</div>";
        strip.style.display = "block";
        Array.prototype.forEach.call(strip.querySelectorAll("img[data-i]"), function (t) {
            t.addEventListener("click", function () { useSuggestion(parseInt(t.getAttribute("data-i"), 10)); });
        });
    }

    function fetchFor(id) {
        fetch(EP + "?article_id=" + encodeURIComponent(id), { credentials: "same-origin" })
            .then(function (r) { return r.json(); })
            .then(function (j) { lastSuggestions = j.suggestions || []; render(lastSuggestions); })
            .catch(function () {});
    }

    setInterval(function () {
        var id = currentArticleId();
        if (id !== lastId) {
            lastId = id;
            if (id) {
                fetchFor(id);
            } else {
                lastSuggestions = [];
                var s = document.getElementById(STRIP_ID);
                if (s) { s.style.display = "none"; s.innerHTML = ""; }
            }
        }
        if (id && lastSuggestions.length) {
            var strip = document.getElementById(STRIP_ID);
            var anchor = anchorEl();
            if (strip && anchor && anchor.parentNode && strip.nextElementSibling !== anchor) {
                anchor.parentNode.insertBefore(strip, anchor);
            }
        }
    }, 800);
})();
