<?php
require_once 'config.php';
requireLogin();

$currentUser = getCurrentUser();
$position = $_SESSION['ten_position'] ?? '';
$currentPage = 'tutorial';

// Role guides. Each is [title, intro, sections[] => [heading, items[]]].
$guides = [
    'Journalist' => [
        'title' => 'Journalist — your guide',
        'intro' => 'As a Journalist you write and submit your own articles. You only see the Article Management page, and it only shows your own articles.',
        'sections' => [
            ['Getting around', [
                'When you log in you land straight on <strong>Article Management</strong> — that is the only tool you need.',
                'Your articles list shows only the articles you have created.',
            ]],
            ['Writing an article', [
                'Click <strong>Add Article</strong> (or “Create New Article”).',
                'Enter a title and write your article in the editor. Use the <strong>Add Image</strong> tab to find and insert images.',
                'If you have been assigned a <strong>section</strong>, it is already set for you. If not, choose the most suitable section.',
                'Your <strong>publication</strong> is set from your account, and the <strong>author</strong> is automatically you — these cannot be changed.',
            ]],
            ['Saving vs submitting', [
                '<strong>Save Draft</strong> keeps the article private so you can keep working on it.',
                '<strong>Submit for Review</strong> sends it to your section editor, who reviews and publishes it. You cannot publish directly.',
                'You can re-open and edit your own articles at any time from the list.',
            ]],
            ['Your byline &amp; bio', [
                'Go to <strong>Settings</strong> (top-right menu) to set your <strong>byline</strong> (how your name appears) and your <strong>biography</strong>. These show on your published articles.',
            ]],
        ],
    ],
    'Section Editor' => [
        'title' => 'Section Editor — your guide',
        'intro' => 'You manage the articles in your section(s) for your publication(s), review what journalists submit, and can publish. You also have the Data Scraper for your publication(s).',
        'sections' => [
            ['What you can see', [
                'You land on <strong>Article Management</strong>. You see all <strong>non-draft</strong> articles in your assigned section(s) and publication(s) — including those submitted for review.',
                'Use the filters to narrow by state (e.g. “Under review”), author, or date.',
            ]],
            ['Reviewing &amp; publishing', [
                'Open a submitted article to edit it. You can fix the copy, images, section and author (within your team).',
                'Click <strong>Publish</strong> to make it live, or <strong>Save Draft</strong> to send it back for more work.',
            ]],
            ['Writing your own', [
                'Use <strong>Add Article</strong> to write directly. You can Save Draft, Submit, or Publish.',
            ]],
            ['Data Scraper', [
                'Open <strong>Data Scraper</strong> to work the AI-collated story feed for your publication and section.',
                'Pick a story, review the generated draft, then <strong>Publish</strong> live or <strong>Save as draft</strong>. You only see the publications/sections you are assigned to.',
            ]],
        ],
    ],
    'Editor' => [
        'title' => 'Editor — your guide',
        'intro' => 'You manage all sections within your publication(s): create, edit and publish articles, and use the Data Scraper.',
        'sections' => [
            ['What you can see', [
                'You land on <strong>Article Management</strong> and see all articles across every section within your assigned publication(s).',
                'Filter by section, author, state or date to find what you need.',
            ]],
            ['Creating &amp; publishing', [
                'Use <strong>Add Article</strong> to write. Choose any section within your publication and assign any author.',
                '<strong>Save Draft</strong>, <strong>Submit for Review</strong>, or <strong>Publish</strong> directly.',
                'Open any article in your publication to edit or publish it.',
            ]],
            ['Data Scraper', [
                'Open <strong>Data Scraper</strong> to curate AI-collated stories for your publication(s), then publish or save as drafts.',
            ]],
        ],
    ],
];
// Managing / General Editor share the Editor guide.
$guides['Managing Editor'] = $guides['Editor'];
$guides['General Editor'] = $guides['Editor'];

$editorialPositions = ['Journalist', 'Section Editor', 'Editor', 'Managing Editor', 'General Editor'];
$guide = $guides[$position] ?? null;
?>
<!DOCTYPE html>
<html lang="<?php echo getUserLanguage(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Role Tutorial - TEN Management</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/backend-style.css">
    <style>
        .tut-wrap { max-width: 860px; }
        .tut-card { background:#fff; border:1px solid #e5e7eb; border-radius:12px; padding:28px 30px; box-shadow:0 2px 8px rgba(0,0,0,0.06); }
        .tut-intro { color:#4b5563; font-size:15px; line-height:1.6; margin-bottom:24px; }
        .tut-section { margin-bottom:22px; }
        .tut-section h3 { font-size:16px; color:#111827; margin-bottom:10px; display:flex; align-items:center; gap:8px; }
        .tut-section h3 i { color:#3c4f6d; }
        .tut-section ul { margin:0; padding-left:22px; }
        .tut-section li { color:#374151; font-size:14px; line-height:1.7; margin-bottom:6px; }
        .tut-badge { display:inline-block; background:#eef2f7; color:#3c4f6d; font-weight:600; font-size:12px; padding:4px 10px; border-radius:999px; margin-bottom:14px; }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    <div class="main-content">
        <?php include 'includes/header.php'; ?>
        <div class="content-area">
            <div class="page-header" style="margin-bottom:24px;">
                <h1><i class="fas fa-graduation-cap"></i> Role Tutorial</h1>
                <p>How to use TEN Management in your role.</p>
            </div>
            <div class="tut-wrap">
                <div class="tut-card">
                    <?php if ($guide): ?>
                        <span class="tut-badge"><?php echo htmlspecialchars($position); ?></span>
                        <h2 style="margin-bottom:10px;color:#111827;"><?php echo htmlspecialchars($guide['title']); ?></h2>
                        <p class="tut-intro"><?php echo $guide['intro']; ?></p>
                        <?php foreach ($guide['sections'] as $i => $sec): ?>
                            <div class="tut-section">
                                <h3><i class="fas fa-<?php echo ['compass','pen-nib','paper-plane','id-badge','robot','list'][$i] ?? 'circle-check'; ?>"></i> <?php echo htmlspecialchars($sec[0]); ?></h3>
                                <ul>
                                    <?php foreach ($sec[1] as $item): ?>
                                        <li><?php echo $item; ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <h2 style="margin-bottom:10px;color:#111827;">Welcome</h2>
                        <p class="tut-intro">Your available tools are in the sidebar on the left. A step-by-step guide has not been prepared for your role yet — please contact an administrator if you need help getting started.</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</body>
</html>
