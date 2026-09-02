/* TEN Scraper — royalty-free image suggestions inside the Article Tool editor.
 * When a scraper-originated article is open, shows a thumbnail strip above the
 * editor; click a thumb to preview and insert it at the top of the article.
 */
(function () {
    "use strict";

    var EP = "ajax/scraper_image_suggestions.php";
    var STRIP_ID = "scImgSuggestStrip";
    var lastId = null;

    function esc(s) {
        return String(s == null ? "" : s).replace(/[&<>"']/g, function (c) {
            return { "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c];
        });
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

    function ensureStrip() {
        var strip = document.getElementById(STRIP_ID);
        if (strip) return strip;
        var editor = document.getElementById("article_text");
        if (!editor || !editor.parentNode) return null;
        strip = document.createElement("div");
        strip.id = STRIP_ID;
        strip.style.cssText = "display:none;margin:0 0 10px;padding:10px;border:1px solid #e5e7eb;border-radius:8px;background:#f9fafb;";
        editor.parentNode.insertBefore(strip, editor);
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

    function openImage(s) {
        if (typeof Swal !== "undefined") {
            Swal.fire({
                title: s.title || "Suggested image",
                imageUrl: s.url,
                imageAlt: s.attribution || "",
                imageWidth: 480,
                html: '<div style="font-size:12px;color:#555;">' + esc(s.attribution || "") + " · " + esc(s.license || "") + " · " + esc(s.provider || "") + "</div>",
                showCancelButton: true,
                confirmButtonText: "Insert at top of article",
                cancelButtonText: "Close",
                confirmButtonColor: "#2563eb"
            }).then(function (r) { if (r.isConfirmed) insertImage(s.url, s.attribution); });
        } else if (confirm("Insert this image at the top of the article?")) {
            insertImage(s.url, s.attribution);
        }
    }

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
                '<i class="fas fa-images"></i> Scraper image suggestions — royalty-free, click to preview &amp; insert</div>' +
            '<div style="display:flex;gap:8px;flex-wrap:wrap;">' + thumbs + "</div>";
        strip.style.display = "block";
        Array.prototype.forEach.call(strip.querySelectorAll("img[data-i]"), function (t) {
            t.addEventListener("click", function () { openImage(suggestions[parseInt(t.getAttribute("data-i"), 10)]); });
        });
    }

    function fetchFor(id) {
        fetch(EP + "?article_id=" + encodeURIComponent(id), { credentials: "same-origin" })
            .then(function (r) { return r.json(); })
            .then(function (j) { render(j.suggestions || []); })
            .catch(function () {});
    }

    // Poll for the currently-open article and refresh the strip when it changes.
    setInterval(function () {
        var id = currentArticleId();
        if (id === lastId) return;
        lastId = id;
        if (id) {
            fetchFor(id);
        } else {
            var s = document.getElementById(STRIP_ID);
            if (s) { s.style.display = "none"; s.innerHTML = ""; }
        }
    }, 800);
})();
