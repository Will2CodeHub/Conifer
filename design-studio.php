<?php
/**
 * TEN Design Studio — standalone full-page editor for a publication's design.
 * Opened in a new tab from TEN News Sites. Admin + design_enabled gated.
 * All persistence goes through ajax/design_layout.php (admin_ten / mysqli).
 */
require_once 'config.php';
requireLogin();
if (!isAdmin()) { header('Location: dashboard.php?error=unauthorized'); exit(); }
$pub = preg_replace('/[^a-z0-9_-]/i', '', (string)($_GET['pub'] ?? ''));
?>
<!DOCTYPE html>
<html lang="<?php echo getUserLanguage(); ?>">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Design Studio — <?php echo htmlspecialchars($pub); ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Public+Sans:ital,wght@0,400;0,500;0,600;0,700;0,800&display=swap">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link rel="stylesheet" href="css/design_studio.css?v=<?php echo @filemtime(__DIR__.'/css/design_studio.css'); ?>">
</head>
<body>
  <div id="dsApp" data-pub="<?php echo htmlspecialchars($pub); ?>">
    <div id="dsHome" class="ds-screen"></div>
    <div id="dsPage" class="ds-screen" hidden></div>
    <div id="dsBlock" class="ds-screen" hidden></div>
  </div>
  <script src="js/design_studio/registry.js?v=<?php echo @filemtime(__DIR__.'/js/design_studio/registry.js'); ?>"></script>
  <script src="js/design_studio/undo.js?v=<?php echo @filemtime(__DIR__.'/js/design_studio/undo.js'); ?>"></script>
  <script src="js/design_studio/html_editor.js?v=<?php echo @filemtime(__DIR__.'/js/design_studio/html_editor.js'); ?>"></script>
  <script src="js/design_studio/page_editor.js?v=<?php echo @filemtime(__DIR__.'/js/design_studio/page_editor.js'); ?>"></script>
  <script src="js/design_studio/block_editor.js?v=<?php echo @filemtime(__DIR__.'/js/design_studio/block_editor.js'); ?>"></script>
  <script src="js/design_studio/home.js?v=<?php echo @filemtime(__DIR__.'/js/design_studio/home.js'); ?>"></script>
  <script src="js/design_studio/placeholders.js?v=<?php echo @filemtime(__DIR__.'/js/design_studio/placeholders.js'); ?>"></script>
  <script src="js/design_studio/core.js?v=<?php echo @filemtime(__DIR__.'/js/design_studio/core.js'); ?>"></script>
</body>
</html>
