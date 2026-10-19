/* TEN Design Studio — full HTML editor module.
   Built in Phase 4 (ports the verified WYSIWYG core from news_sites_design.js).
   Placeholder registration so block_editor can reference it before then. */
(function () {
  "use strict";
  var DS = window.DS = window.DS || {};
  DS.HtmlEditor = DS.HtmlEditor || {
    mount: function (container) {
      container.innerHTML = '<div class="ds-stub-note">The full HTML editor lands in the next build.</div>';
    },
    getHtml: function () { return ""; },
    setHtml: function () {},
    onChange: function () {},
    focusElement: function () {}
  };
})();
