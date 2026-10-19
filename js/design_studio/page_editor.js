/* TEN Design Studio — Page Editor (structure-only: add/remove/move blocks).
   Built in Phase 3. Placeholder render keeps navigation coherent for now. */
(function () {
  "use strict";
  var DS = window.DS = window.DS || {};
  DS.renderPageEditor = function (params) {
    var root = document.getElementById("dsPage");
    var name = (params && (DS.PAGE_LABELS[params.slug] || params.slug)) || "page";
    root.innerHTML =
      '<header class="ds-topbar">' +
        '<button class="ds-back" id="dsPageBack"><i class="fas fa-arrow-left"></i> Back</button>' +
        '<h1>Edit ' + DS.esc(name) + '</h1>' +
      '</header>' +
      '<div class="ds-stub"><i class="fas fa-pen-ruler"></i>' +
        '<p>The page editor — drag blocks to reorder, add and remove them — lands in the next build.</p></div>';
    root.querySelector("#dsPageBack").addEventListener("click", function () { history.back(); });
  };
})();
