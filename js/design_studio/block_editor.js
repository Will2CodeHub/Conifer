/* TEN Design Studio — Block Editor (main-pane render + full-width HTML editor).
   Built in Phase 4. Placeholder render keeps navigation coherent for now. */
(function () {
  "use strict";
  var DS = window.DS = window.DS || {};
  DS.renderBlockEditor = function (params) {
    var root = document.getElementById("dsBlock");
    var type = params && params.type;
    var name = (type && DS.REG[type] && DS.REG[type].label) || (params && params.name) || type || "block";
    root.innerHTML =
      '<header class="ds-topbar">' +
        '<button class="ds-back" id="dsBlockBack"><i class="fas fa-arrow-left"></i> Back</button>' +
        '<h1>Edit ' + DS.esc(name) + '</h1>' +
      '</header>' +
      '<div class="ds-stub"><i class="fas fa-code"></i>' +
        '<p>The block editor with the full HTML editor lands in the next build.</p></div>';
    root.querySelector("#dsBlockBack").addEventListener("click", function () { history.back(); });
  };
})();
