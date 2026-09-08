/* TEN — shared crop resolution + preview control.
 *
 * The crop flows (editor "find images" tab and the scraper image-suggestion
 * strip) both crop with Cropper.js at a locked 490:310 aspect and POST the
 * canvas dataURL to crop_save_image.php, which stores a master WebP at whatever
 * pixel size it receives (then derives the placement sizes). Historically the
 * client forced a fixed output size, so a low-res source was silently reduced.
 *
 * TENCrop.mount() renders, next to a live cropper:
 *   - an output-resolution slider (width; height follows the locked aspect),
 *   - a live preview of the exact crop at that resolution,
 *   - a warn-but-allow notice when the chosen size is larger than the source
 *     region (upscaled/soft) or the source itself is below the base size.
 * The caller's Save button then reads api.currentDataUrl() instead of a fixed
 * getCroppedCanvas(), so nothing is saved until the user is happy with it.
 */
(function () {
    "use strict";

    function el(tag, css, html) {
        var e = document.createElement(tag);
        if (css) e.style.cssText = css;
        if (html != null) e.innerHTML = html;
        return e;
    }

    window.TENCrop = {
        /**
         * @param {HTMLElement} container  where the control renders
         * @param {Cropper}     cropper    a live Cropper.js instance
         * @param {Object}      opts       { baseW=490, aspectW=490, aspectH=310,
         *                                   fill='#ffffff', imageEl, quality=0.95 }
         * @returns {{currentDataUrl:Function, currentSize:Function, refresh:Function, destroy:Function}}
         */
        mount: function (container, cropper, opts) {
            opts = opts || {};
            var baseW = opts.baseW || 490,
                aW = opts.aspectW || 490,
                aH = opts.aspectH || 310,
                fill = opts.fill || "#ffffff",
                quality = opts.quality || 0.95,
                imageEl = opts.imageEl || null;

            function outH(w) { return Math.round(w * aH / aW); }
            function nativeW() {
                try { var c = cropper.getCroppedCanvas(); return c ? c.width : 0; } catch (e) { return 0; }
            }

            var nW = nativeW();
            var maxW = Math.max(nW || baseW, baseW);
            var minW = Math.max(200, Math.round(baseW / 2));
            var startW = Math.min(baseW, maxW);

            container.innerHTML = "";
            var wrap = el("div", "margin-top:14px;padding:12px;border:1px solid #e5e7eb;border-radius:8px;background:#fff;");
            wrap.appendChild(el("div", "font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.03em;color:#6b7280;margin-bottom:8px;", '<i class="fas fa-expand"></i> Output resolution &amp; preview'));

            var ctl = el("div", "display:flex;align-items:center;gap:10px;flex-wrap:wrap;font-size:13px;color:#374151;");
            var slider = el("input");
            slider.type = "range"; slider.min = minW; slider.max = maxW; slider.step = 10; slider.value = startW;
            slider.style.cssText = "flex:1;min-width:180px;";
            var maxBtn = el("button", "background:#eef2ff;border:1px solid #c7d2fe;color:#3730a3;border-radius:6px;padding:5px 10px;cursor:pointer;font-size:12px;font-weight:600;", "Max quality");
            maxBtn.type = "button";
            var dims = el("span", "font-weight:700;color:#111827;min-width:104px;text-align:right;");
            ctl.appendChild(el("span", "font-weight:600;", "Size")); ctl.appendChild(slider); ctl.appendChild(maxBtn); ctl.appendChild(dims);

            var warn = el("div", "margin-top:8px;font-size:12.5px;color:#b45309;line-height:1.5;");
            var pcap = el("div", "font-size:12px;color:#6b7280;margin:12px 0 6px;", "Preview (exactly what will be saved):");
            var pimg = el("img", "max-width:100%;max-height:300px;border:1px solid #e5e7eb;border-radius:6px;display:block;background:#f8fafc;");

            wrap.appendChild(ctl); wrap.appendChild(warn); wrap.appendChild(pcap); wrap.appendChild(pimg);
            container.appendChild(wrap);

            var lastDataUrl = "";
            var timer = null;

            function render() {
                nW = nativeW();
                var mx = Math.max(nW || baseW, baseW);
                if (parseInt(slider.max, 10) !== mx) {
                    slider.max = mx;
                    if (parseInt(slider.value, 10) > mx) slider.value = mx;
                }
                var w = parseInt(slider.value, 10) || baseW;
                var ht = outH(w);
                dims.textContent = w + " × " + ht + " px";
                var c;
                try {
                    c = cropper.getCroppedCanvas({ width: w, height: ht, fillColor: fill, imageSmoothingEnabled: true, imageSmoothingQuality: "high" });
                } catch (e) { c = null; }
                if (c) { lastDataUrl = c.toDataURL("image/jpeg", quality); pimg.src = lastDataUrl; }

                var msgs = [];
                if (nW && w > nW) msgs.push('&#9888; Enlarged beyond the source (' + nW + '&times;' + outH(nW) + ' px) — it may look soft. You can still save.');
                if (nW && nW < baseW) msgs.push('&#9888; This is a low-resolution image (source max ' + nW + '&times;' + outH(nW) + ' px).');
                warn.innerHTML = msgs.join("<br>");
            }
            function renderSoon() { if (timer) clearTimeout(timer); timer = setTimeout(render, 120); }

            slider.addEventListener("input", renderSoon);
            maxBtn.addEventListener("click", function () { slider.value = slider.max; render(); });
            // Re-render the preview whenever the crop box changes.
            if (imageEl) {
                imageEl.addEventListener("cropend", render);
                imageEl.addEventListener("zoom", renderSoon);
                imageEl.addEventListener("ready", render);
            }
            render();

            return {
                currentDataUrl: function () { render(); return lastDataUrl; },
                currentSize: function () { var w = parseInt(slider.value, 10) || baseW; return { w: w, h: outH(w) }; },
                refresh: render,
                destroy: function () {
                    if (imageEl) {
                        imageEl.removeEventListener("cropend", render);
                        imageEl.removeEventListener("zoom", renderSoon);
                        imageEl.removeEventListener("ready", render);
                    }
                    container.innerHTML = "";
                }
            };
        }
    };
})();
