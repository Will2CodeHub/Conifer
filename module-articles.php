<?php
session_start();
if ( ! defined('CURL_SSLVERSION_TLSv1_2')) {
    define('CURL_SSLVERSION_TLSv1_2', 6);
}
require_once('vendor/autoload.php');
require_once 'config.php';
requireLogin();

$apiKey = '35893630-f396d86d2cc73061b0e733d53';

// Journalists get a reduced Article Management page: their own articles only
// (enforced in ajax/get_articles.php) and no Sponsored Articles tab.
$isJournalist = (($_SESSION['ten_position'] ?? '') === 'Journalist');

$currentPage = 'articles';
$pageTitle = 'Article Management';

?>
<!DOCTYPE html>
<html lang="<?php echo getUserLanguage(); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - TEN Management</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="css/backend-style.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.12/cropper.min.css" />
    <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.12/cropper.min.js"></script>
    <style>
        :root {
            --primary: #3c4f6d;      /* slate-blue — matches the Data Scraper page */
            --primary-dark: #31415a;
            --accent: #b45309;        /* amber editorial accent */
            --success: #10b981;
            --warning: #f59e0b;
            --danger: #ef4444;
            --gray-50: #f9fafb;
            --gray-100: #f3f4f6;
            --gray-200: #e5e7eb;
            --gray-300: #d1d5db;
            --gray-400: #9ca3af;
            --gray-500: #6b7280;
            --gray-600: #4b5563;
            --gray-700: #374151;
            --gray-800: #1f2937;
            --gray-900: #111827;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Helvetica, Arial, sans-serif;
            background: var(--gray-50);
            color: var(--gray-900);
            font-size: 14px;
            line-height: 1.6;
            -webkit-font-smoothing: antialiased;
            -moz-osx-font-smoothing: grayscale;
        }
        
        .main-content {
            margin-left: 260px;
            min-height: 100vh;
        }
        
        .content-area {
            padding: 32px;
        }
        
        .page-header {
            margin-bottom: 32px;
        }
        
        .page-header h1 {
            font-size: 30px;
            font-weight: 700;
            color: var(--gray-900);
            display: flex;
            align-items: center;
            gap: 12px;
            letter-spacing: -0.025em;
        }
        
        .page-header h1 i {
            color: var(--primary);
            font-size: 26px;
        }
        
        .page-header p {
            color: var(--gray-500);
            margin-top: 6px;
            font-size: 15px;
        }
        
        /* Tabs - Modern Design */
        .tabs {
            display: flex;
            gap: 2px;
            margin-bottom: 24px;
            border-bottom: 2px solid var(--gray-200);
            flex-wrap: wrap;
            background: white;
            padding: 0 20px;
            border-radius: 8px 8px 0 0;
        }
        
        .tab {
            padding: 14px 24px;
            background: none;
            border: none;
            border-bottom: 3px solid transparent;
            font-size: 14px;
            font-weight: 600;
            color: var(--gray-500);
            cursor: pointer;
            transition: all 0.2s ease;
            display: flex;
            align-items: center;
            gap: 8px;
            position: relative;
        }
        
        .tab:hover {
            color: var(--gray-900);
            background: var(--gray-50);
        }
        
        .tab.active {
            color: var(--primary);
            border-bottom-color: var(--primary);
            background: white;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }

        /* Card styling - Modern with shadow */
        .card {
            background: white;
            border: 1px solid var(--gray-200);
            border-radius: 12px;
            padding: 28px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.1), 0 1px 2px 0 rgba(0, 0, 0, 0.06);
        }

        .card h2 {
            font-size: 20px;
            font-weight: 700;
            color: var(--gray-900);
            margin-bottom: 20px;
            letter-spacing: -0.02em;
        }

        /* Editor styles - Modern */
        .editor-container {
            background: #fff;
            border: 1px solid var(--gray-200);
            border-radius: 8px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 1px 2px 0 rgba(0, 0, 0, 0.05);
        }
        
        .editor-controls {
            display: flex;
            gap: 2px;
            padding: 10px;
            border-bottom: 1px solid var(--gray-200);
            background: var(--gray-50);
            flex-wrap: wrap;
        }
        
        .editor-controls button {
            padding: 8px 12px;
            border: 1px solid var(--gray-300);
            border-radius: 6px;
            background: white;
            color: var(--gray-700);
            cursor: pointer;
            position: relative;
            transition: all 0.15s ease;
            font-size: 14px;
            font-weight: 500;
        }
        
        .editor-controls button:hover {
            background: var(--gray-100);
            border-color: var(--gray-400);
            transform: translateY(-1px);
            box-shadow: 0 2px 4px rgba(0,0,0,0.08);
        }
        
        .editor-controls button:active {
            transform: translateY(0);
        }
        
        .editor-controls button i {
            font-size: 16px;
        }
        
        .editor-controls button:hover::after {
            content: attr(data-tooltip);
            position: absolute;
            bottom: -34px;
            left: 50%;
            transform: translateX(-50%);
            background: var(--gray-900);
            color: white;
            padding: 6px 10px;
            border-radius: 6px;
            font-size: 12px;
            white-space: nowrap;
            z-index: 1000;
            box-shadow: 0 4px 6px rgba(0,0,0,0.1);
        }
        
        .editor {
            display: block;
            width: 100%;
            padding: 20px;
            min-height: 300px;
            border: none;
            font-size: 15px;
            line-height: 1.75;
            outline: none;
            overflow-y: auto;
            color: var(--gray-900);
        }
        
        .editor[contenteditable="true"] {
            background-color: #fff;
        }
        
        .editor[contenteditable="true"]:focus {
            background-color: #fefefe;
        }

        /* Paragraph spacing in the editor (a blank line between paragraphs) —
           a global reset elsewhere can otherwise collapse <p> margins. */
        .editor p { margin: 0 0 1em; }
        .editor h2, .editor h3 { margin: 1.3em 0 0.5em; }
        
        .editor mark {
            background-color: #fef3c7;
            padding: 2px 4px;
            border-radius: 3px;
        }
        
        .editor img {
            cursor: pointer;
            border: 2px solid transparent;
            transition: all 0.2s;
            border-radius: 6px;
        }
        
        .editor img:hover {
            border-color: var(--primary);
            box-shadow: 0 4px 6px rgba(37, 99, 235, 0.1);
        }
        
        .source-view {
            display: none;
            width: 100%;
            padding: 20px;
            min-height: 300px;
            border: none;
            font-family: 'SF Mono', Monaco, 'Cascadia Code', 'Courier New', monospace;
            font-size: 13px;
            line-height: 1.7;
            background: var(--gray-50);
            color: var(--gray-700);
            outline: none;
            box-sizing: border-box;
        }
        
        /* Ensure source view is hidden in modal by default */
        #modal_editor_slot .source-view {
            display: none !important;
            height: 100%;
            min-height: 500px;
        }
        
        #modal_editor_slot .source-view.active {
            display: block !important;
        }
        
        #modal_editor_slot .editor {
            display: block;
            height: 100%;
            min-height: 500px;
        }
        
        #modal_editor_slot .editor.hidden {
            display: none !important;
        }
        
        /* Editor flows naturally (was position:absolute with a fixed height,
           which clipped tall content — e.g. after inserting an image — so the
           bottom of the article could not be reached). The modal body's scroll
           area now grows with the content instead. */
        #modal_editor_slot { overflow: visible; }
        #modal_editor_slot .editor-container {
            position: static;
            height: auto;
            min-height: 0;
        }
        #modal_editor_slot .editor-container > .editor,
        #modal_editor_slot .editor-container > .source-view {
            position: static;
            width: 100%;
            height: auto;
        }
        #modal_editor_slot .editor { min-height: 340px; height: auto; overflow: visible; }
        #modal_editor_slot .source-view { min-height: 340px; }
        @media (min-width: 768px) {
            #modal_editor_slot .editor-container,
            #modal_editor_slot .editor,
            #modal_editor_slot .source-view {
                min-height: 340px !important;
            }
        }
        @media (min-width: 1024px) {
            #modal_editor_slot .editor-container,
            #modal_editor_slot .editor,
            #modal_editor_slot .source-view {
                min-height: 340px !important;
            }
        }

        /* ── Collapsible settings panels (top of the edit modal) ── */
        .ed-panels { display:flex; flex-wrap:wrap; gap:8px; margin-bottom:14px; }
        .ed-panel { flex:0 1 auto; border:1px solid #e2e8f0; border-radius:8px; background:#f8fafc; overflow:hidden; }
        .ed-panel[open] { flex-basis:100%; background:#fff; }
        .ed-panel > summary { list-style:none; cursor:pointer; padding:9px 14px; font-size:12px; font-weight:700; color:#475569; text-transform:uppercase; letter-spacing:0.4px; user-select:none; white-space:nowrap; }
        .ed-panel > summary::-webkit-details-marker { display:none; }
        .ed-panel > summary i { color:#3b82f6; margin-right:6px; }
        .ed-panel > summary::after { content:'\25be'; float:right; margin-left:14px; color:#94a3b8; }
        .ed-panel[open] > summary { border-bottom:1px solid #e2e8f0; background:#f1f5f9; }
        .ed-panel[open] > summary::after { content:'\25b4'; }
        .ed-panel-body { padding:14px; }
        .ed-lbl { display:block; font-size:11px; font-weight:700; color:#64748b; margin-bottom:5px; text-transform:uppercase; letter-spacing:0.4px; }
        .ed-input { width:100%; padding:9px 10px; border:1px solid #e2e8f0; border-radius:6px; background:#fff; font-size:13px; color:#1e293b; box-sizing:border-box; outline:none; font-family:inherit; }
        .ed-input:focus { border-color:#3b82f6; box-shadow:0 0 0 3px rgba(59,130,246,0.1); }
        .ed-flag { display:inline-flex; align-items:center; gap:6px; padding:7px 11px; border:1px solid #e2e8f0; border-radius:8px; background:#f8fafc; font-size:13px; font-weight:600; color:#1e293b; cursor:pointer; }
        .ed-flag input { width:16px; height:16px; cursor:pointer; }

        /* ── Settings tabs (row stays fixed; active panel opens below it) ── */
        .ed-tabs { display:flex; flex-wrap:wrap; gap:6px; }
        .ed-tab { border:1px solid #e2e8f0; background:#f8fafc; color:#475569; font-size:12px; font-weight:700; text-transform:uppercase; letter-spacing:0.4px; padding:8px 13px; border-radius:8px; cursor:pointer; white-space:nowrap; transition:background .12s,color .12s; }
        .ed-tab:hover { background:#eef2f7; }
        .ed-tab i { color:#3b82f6; margin-right:6px; }
        .ed-tab.active { background:#3b82f6; color:#fff; border-color:#3b82f6; }
        .ed-tab.active i { color:#fff; }
        .ed-tabwrap { display:none; border:1px solid #e2e8f0; border-radius:8px; background:#fff; margin:8px 0 14px; }
        .ed-tabwrap.open { display:block; }
        .ed-tabpanel { display:none; padding:14px; }
        .ed-tabpanel.active { display:block; }
        
        /* Fullscreen styles */
        .fullscreen {
            position: fixed !important;
            top: 0;
            left: 0;
            width: 100vw !important;
            height: 100vh !important;
            margin: 0 !important;
            z-index: 1000;
            box-shadow: none;
            border-radius: 0 !important;
        }
        
        /* Modal styling - Modern with backdrop blur */
        .overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.6);
            z-index: 999;
            backdrop-filter: blur(8px);
        }
        
        .modal {
            display: none;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            padding: 24px;
            border: 1px solid var(--gray-200);
            z-index: 2000;
            height: 500px;
            width: auto;
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }
        
        .modal input, .modal select {
            width: 100%;
            padding: 10px 12px;
            margin-bottom: 10px;
            border: 1px solid var(--gray-300);
            border-radius: 6px;
            transition: all 0.15s;
        }
        
        .modal input:focus, .modal select:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }
        
        .modal button {
            padding: 10px 16px;
            border: 1px solid var(--gray-300);
            border-radius: 6px;
            background: white;
            color: var(--gray-700);
            cursor: pointer;
            margin-right: 8px;
            transition: all 0.15s;
            font-weight: 600;
        }
        
        .modal button:hover {
            background: var(--gray-50);
            border-color: var(--gray-400);
        }
        
        .image-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .image-grid img {
            max-width: 200px;
            height: auto;
            border: 1px solid var(--gray-200);
            border-radius: 6px;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: var(--gray-700);
            font-size: 14px;
        }

        .form-control {
            width: 100%;
            padding: 11px 14px;
            border: 1px solid var(--gray-300);
            border-radius: 6px;
            font-size: 14px;
            transition: all 0.15s;
        }

        .form-control:focus {
            outline: none;
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
        }

        /* Button System - Modern */
        .btn {
            padding: 11px 20px;
            border: 1px solid var(--gray-300);
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.15s;
            font-size: 14px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
            border-color: var(--primary);
        }

        .btn-primary:hover {
            background: var(--primary-dark);
            border-color: var(--primary-dark);
            transform: translateY(-1px);
            box-shadow: 0 4px 6px rgba(37, 99, 235, 0.25);
        }

        .btn-primary.active {
            background: var(--primary-dark);
            border-color: var(--primary-dark);
        }

        .btn-danger {
            background: var(--danger);
            color: white;
            border-color: var(--danger);
        }

        .btn-danger:hover {
            background: #dc2626;
            border-color: #dc2626;
            transform: translateY(-1px);
            box-shadow: 0 4px 6px rgba(239, 68, 68, 0.25);
        }

        .form-actions {
            margin-top: 24px;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }

        .switch {
            position: relative;
            display: inline-block;
            width: 50px;
            height: 24px;
        }

        .switch input {
            opacity: 0;
            width: 0;
            height: 0;
        }

        .switch span {
            position: absolute;
            cursor: pointer;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background-color: var(--gray-300);
            transition: .4s;
            border-radius: 24px;
        }

        .switch span:before {
            position: absolute;
            content: "";
            height: 18px;
            width: 18px;
            left: 3px;
            bottom: 3px;
            background-color: white;
            transition: .4s;
            border-radius: 50%;
        }

        .switch input:checked + span {
            background-color: #333;
        }

        .switch input:checked + span:before {
            transform: translateX(26px);
        }
        
        /* SweetAlert higher z-index than all modals */
        .swal-high-z {
            z-index: 10000 !important;
        }
        .swal2-container.swal-high-z {
            z-index: 10000 !important;
        }
        /* Option toggle checkboxes */
        .option-toggle:hover {
            border-color: #9ca3af !important;
            background: #f3f4f6 !important;
        }
        .option-toggle:has(input:checked) {
            border-color: #3c4f6d !important;
            background: #eff6ff !important;
        }
        /* Editor image float wrap */
        .editor .editor-img-wrapped {
            float: left;
            margin: 0 15px 10px 0;
            max-width: 50%;
            height: auto;
        }

        /* ===== Match the Data Scraper page: serif headings, amber-underline tabs,
                slate-blue primary, refined table. (Overrides earlier rules by order.) ===== */
        .page-header h1 { font-family:"Iowan Old Style","Palatino Linotype",Palatino,Georgia,"Times New Roman",serif; font-weight:600; letter-spacing:-0.01em; color:#15181e; font-size:26px; }
        .page-header h1 i { color:var(--primary); font-size:23px; }
        .card { border-color:#e6e8ec; border-radius:12px; box-shadow:0 1px 2px rgba(16,24,40,.05),0 1px 3px rgba(16,24,40,.05); }
        .card h2 { font-family:"Iowan Old Style","Palatino Linotype",Palatino,Georgia,"Times New Roman",serif; font-weight:600; color:#15181e; letter-spacing:-0.01em; font-size:19px; }

        /* Top tabs → underline tabs with amber active indicator (like the scraper view tabs) */
        .tabs { border-bottom:1px solid #e6e8ec; background:transparent; padding:0; gap:2px; border-radius:0; }
        .tab { padding:12px 16px; border-bottom:none; color:#6b7280; }
        .tab:hover { color:#15181e; background:#f8fafb; }
        .tab.active { color:#15181e; background:transparent; border-bottom:none; }
        .tab.active::after { content:""; position:absolute; left:12px; right:12px; bottom:-1px; height:2px; background:var(--accent); border-radius:2px 2px 0 0; }

        /* Buttons */
        .btn-primary:hover { box-shadow:0 4px 10px rgba(60,79,109,.25); }

        /* Articles table → uppercase muted header + row hover, like the scraper tables */
        #articlesTable thead th { text-transform:uppercase; letter-spacing:.04em; font-size:11px; color:#6b7280; background:#f8fafb; }
        #articlesTable tbody tr:hover { background:#f8fafb; }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    <?php include 'image_browser.php'; ?>
    
    <div class="main-content">
        <?php include 'includes/header.php'; ?>
        
        <div class="content-area">
            <div class="page-header">
                <h1><i class="fas fa-newspaper"></i> <?php echo $pageTitle; ?></h1>
                <p>Create, manage, and publish articles</p>
            </div>

            <!-- Tabs -->
            <div class="tabs">
                <button class="tab active" data-tab="view-articles">
                    <i class="fas fa-list"></i> View All Articles
                </button>
                <button class="tab" data-tab="add-article">
                    <i class="fas fa-plus-circle"></i> Add Article
                </button>
                <button class="tab" data-tab="add-image">
                    <i class="fas fa-image"></i> Add Image
                </button>
                <?php if (!$isJournalist): ?>
                <button class="tab" data-tab="sponsored-articles">
                    <i class="fas fa-list"></i> Sponsored Articles
                </button>
                <?php endif; ?>
            </div>

            <!-- Tab 1: View All Articles -->
            <div id="view-articles" class="tab-content active">
                <div class="card">
                    <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;">
                        <h2 style="margin:0;"><i class="fas fa-list"></i> All Articles</h2>
                        <button type="button" class="btn btn-primary" id="createArticleBtn" onclick="if(window.openCreateArticle)window.openCreateArticle();">
                            <i class="fas fa-plus-circle"></i> Create New Article
                        </button>
                    </div>
                    <div style="width:100%;max-width:1200px;margin:0 auto;padding:12px;font-family:inherit;">

                        <!-- Search -->
                        <div style="margin-bottom:12px;">
                            <input id="articleSearch"
                                   type="text"
                                   placeholder="Search articles..."
                                   style="width:100%;padding:8px 12px;border:1px solid #ced4da;border-radius:6px;box-sizing:border-box;" />
                        </div>

                        <!-- Advanced Filters - Collapsible -->
                        <div style="margin-bottom:16px;">
                            <button id="toggleFilters" onclick="toggleAdvancedFilters()" type="button" style="background:#fff;border:1px solid #d1d5db;color:#374151;padding:10px 16px;border-radius:6px;cursor:pointer;font-weight:600;font-size:14px;display:flex;align-items:center;gap:8px;width:100%;">
                                <i class="fas fa-filter"></i>
                                <span>Advanced Filters</span>
                                <i id="filterChevron" class="fas fa-chevron-down" style="margin-left:auto;transition:transform 0.2s;"></i>
                            </button>
                            
                            <div id="advancedFilters" style="display:none;margin-top:12px;padding:20px;background:#f9fafb;border:1px solid #d1d5db;border-radius:6px;">
                                <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:16px;">
                                    
                                    <div>
                                        <label style="display:block;font-weight:600;margin-bottom:6px;color:#374151;font-size:13px;">User/Author</label>
                                        <select id="filterUser" style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;background:#fff;font-size:14px;">
                                            <option value="">All Users</option>
                                        </select>
                                    </div>
                                    
                                    <div>
                                        <label style="display:block;font-weight:600;margin-bottom:6px;color:#374151;font-size:13px;">Section</label>
                                        <select id="filterSection" style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;background:#fff;font-size:14px;">
                                            <option value="">All Sections</option>
                                        </select>
                                    </div>
                                    
                                    <div>
                                        <label style="display:block;font-weight:600;margin-bottom:6px;color:#374151;font-size:13px;">Publication</label>
                                        <select id="filterPublication" style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;background:#fff;font-size:14px;">
                                            <option value="">All Publications</option>
                                        </select>
                                    </div>

                                    <div>
                                        <label style="display:block;font-weight:600;margin-bottom:6px;color:#374151;font-size:13px;">State</label>
                                        <select id="filterState" style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;background:#fff;font-size:14px;">
                                            <option value="">All States</option>
                                            <option value="published">Published</option>
                                            <option value="draft">Draft</option>
                                            <option value="under review">Under Review</option>
                                            <option value="expired">Expired</option>
                                            <option value="deleted">Deleted</option>
                                        </select>
                                    </div>

                                    <div>
                                        <label style="display:block;font-weight:600;margin-bottom:6px;color:#374151;font-size:13px;">Date From</label>
                                        <input type="date" id="filterDateFrom" style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;">
                                    </div>
                                    
                                    <div>
                                        <label style="display:block;font-weight:600;margin-bottom:6px;color:#374151;font-size:13px;">Date To</label>
                                        <input type="date" id="filterDateTo" style="width:100%;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;">
                                    </div>
                                    
                                    <div>
                                        <label style="display:block;font-weight:600;margin-bottom:6px;color:#374151;font-size:13px;">Options</label>
                                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;background:#fff;">
                                            <input type="checkbox" id="filterHideImageless" style="width:18px;height:18px;">
                                            <span style="font-weight:500;font-size:14px;color:#374151;">Hide Imageless</span>
                                        </label>
                                        <label style="display:flex;align-items:center;gap:8px;cursor:pointer;padding:8px 12px;border:1px solid #d1d5db;border-radius:6px;background:#fff;margin-top:8px;">
                                            <input type="checkbox" id="filterScraped" style="width:18px;height:18px;">
                                            <span style="font-weight:500;font-size:14px;color:#374151;">Scraped only</span>
                                        </label>
                                    </div>

                                </div>
                                
                                <div style="margin-top:16px;display:flex;gap:8px;justify-content:flex-end;">
                                    <button onclick="clearFilters()" type="button" style="background:#fff;border:1px solid #d1d5db;color:#374151;padding:8px 16px;border-radius:6px;cursor:pointer;font-weight:600;font-size:13px;">
                                        Clear Filters
                                    </button>
                                    <button onclick="applyFilters()" type="button" style="background:#3c4f6d;border:none;color:#fff;padding:8px 16px;border-radius:6px;cursor:pointer;font-weight:600;font-size:13px;">
                                        Apply Filters
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Table wrapper (responsive) -->
                        <div id="tableWrapper" style="width:100%;overflow-x:auto;background:#fff;border:1px solid #e9ecef;border-radius:6px;">
                            <table id="articlesTable"
                                   style="width:100%;border-collapse:collapse;min-width:700px;table-layout:auto;">
                                <thead>
                                    <tr style="background:#1e293b;color:#f1f5f9;">
                                        <th data-col="id" class="sortable"
                                            style="padding:12px 10px;border-bottom:2px solid #334155;vertical-align:middle;cursor:pointer;width:55px;text-align:left;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;">
                                            ID <span class="sort-arrow" style="margin-left:4px;font-size:11px;opacity:0.7;"></span>
                                        </th>
                                        <th data-col="title" class="sortable"
                                            style="padding:12px 10px;border-bottom:2px solid #334155;vertical-align:middle;cursor:pointer;text-align:left;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;">
                                            Title <span class="sort-arrow" style="margin-left:4px;font-size:11px;opacity:0.7;"></span>
                                        </th>
                                        <th data-col="journalist_name"
                                            style="padding:12px 10px;border-bottom:2px solid #334155;vertical-align:middle;text-align:left;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;width:150px;">
                                            Author
                                        </th>
                                        <th data-col="publications"
                                            style="padding:12px 10px;border-bottom:2px solid #334155;vertical-align:middle;text-align:left;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;width:130px;">
                                            Publication
                                        </th>
                                        <th data-col="state" class="sortable"
                                            style="padding:12px 10px;border-bottom:2px solid #334155;vertical-align:middle;cursor:pointer;width:110px;text-align:left;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;">
                                            Status <span class="sort-arrow" style="margin-left:4px;font-size:11px;opacity:0.7;"></span>
                                        </th>
                                        <th data-col="submission_date" class="sortable"
                                            style="padding:12px 10px;border-bottom:2px solid #334155;vertical-align:middle;cursor:pointer;width:140px;text-align:left;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;">
                                            Date <span class="sort-arrow" style="margin-left:4px;font-size:11px;opacity:0.7;"></span>
                                        </th>
                                        <th style="padding:12px 10px;border-bottom:2px solid #334155;vertical-align:middle;text-align:center;width:120px;font-size:12px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;">
                                            Actions
                                        </th>
                                    </tr>
                                </thead>
                                <tbody style="background:#fff;"></tbody>
                            </table>

                            <!-- Loading spinner -->
                            <div id="loadingSpinner" style="display:none;text-align:center;padding:16px;">
                                <div style="display:inline-block;width:2rem;height:2rem;border:0.25rem solid rgba(0,0,0,0.1);border-top-color:#007bff;border-radius:50%;animation:spin 1s linear infinite;"></div>
                            </div>

                            <!-- No results -->
                            <div id="noResults" style="display:none;padding:14px;text-align:center;background:#fff;border-top:1px solid #e9ecef;color:#6c757d;">
                                No results found.
                            </div>
                        </div>

                        <!-- Pagination -->
                        <div style="margin-top:12px;text-align:center;">
                            <ul id="pagination" style="display:inline-flex;flex-wrap:nowrap;list-style:none;padding:0;margin:0;"></ul>
                        </div>
                    </div>

                </div>
            </div>

            <!-- Tab 2: Add Article -->
            <div id="add-article" class="tab-content">
                <div class="card">
                    <h2><i class="fas fa-pen"></i> Create New Article</h2>
                    
                    <img src="loading.gif" id="loading_image" style="display: none; position: absolute; width: 100px; margin: 0px auto; z-index: 100; text-align: center; justify-content: center; right: 0px; left: 0px;" />

                    <form id="ten_add_article" method="post" onsubmit="return false;">
                        <div style="margin-bottom:14px;">
                            <label class="ed-lbl" for="article_title">Article Title <span style="color:#dc2626;">*</span></label>
                            <input type="text" id="article_title" name="article_title" class="ed-input" placeholder="Enter article title..." style="font-size:16px;font-weight:600;padding:12px 14px;">
                        </div>

                        <div class="form-group">
                            <button id="ai_prompt_toggle" style="float: right;" type="button" class="btn btn-primary">
                                <i class="fa fa-angle-right"></i> Show AI Prompt
                            </button>
                            <div style="clear: both;"></div>
                        </div>

                        <div id="hide_ai_prompt" style="display:none;">
                            <div class="form-group">
                                <label for="ai_query">AI Prompt</label>
                                <div style="display: flex; gap: 12px;">
                                    <input type="text" id="ai_query" name="ai_query" class="form-control" placeholder="Enter instructions to create AI article" style="flex: 1;">
                                    <button id="ai_prompt_request" type="button" class="btn btn-primary">
                                        <i class="fa fa-angle-right"></i> Get Response
                                    </button>
                                </div>
                            </div>

                            <div class="form-group">
                                <label>Templates</label>
                                <div style="display: flex; gap: 12px;">
                                    <select class="form-control" id="canned_prompt_create_article" style="flex: 1;">
                                        <option value="">Select template...</option>
                                        <option value="Create 800 word article">Create 800 word article</option>
                                        <option value="Create 650 word article">Create 650 word article</option>
                                        <option value="Create 500 word article">Create 500 word article</option>
                                        <option value="Create 300 word article">Create 300 word article</option>
                                        <option value="Create article">Create article</option>
                                        <option value="Check this article for journalistic standards, remove any plagiarism, improve professionalism and clarity">Professional Edit & Plagiarism Check</option>
                                        <option value="Rewrite this article to improve grammar, spelling and readability while maintaining the core message">Grammar & Readability Check</option>
                                        <option value="Fact-check this article and verify all claims are accurate and properly sourced">Fact Check Article</option>
                                        <option value="Optimize this article for SEO while maintaining journalistic quality">SEO Optimization</option>
                                    </select>
                                    <select class="form-control" id="canned_prompt_about_place" style="flex: 1;">
                                        <option value="">Select location...</option>
                                        <option value="in Munich ">In Munich</option>
                                        <option value="in Germany ">In Germany</option>
                                        <option value="in Barcelona ">In Barcelona</option>
                                        <option value="in Madrid ">In Madrid</option>
                                        <option value="in Europe ">In Europe</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <!-- Settings tabs — mirror the edit modal on the View All Articles tab -->
                        <div class="ed-tabs">
                            <button type="button" class="ed-tab" data-edtab="apub"><i class="fas fa-newspaper"></i> Publications</button>
                            <button type="button" class="ed-tab" data-edtab="aauthor"><i class="fas fa-user"></i> Author &amp; Section</button>
                            <button type="button" class="ed-tab" data-edtab="aflags"><i class="fas fa-sliders-h"></i> Flags</button>
                            <button type="button" class="ed-tab" data-edtab="aseo"><i class="fas fa-hashtag"></i> SEO &amp; Metadata</button>
                        </div>
                        <div class="ed-tabwrap">
                            <div class="ed-tabpanel" data-edpanel="apub">
                                <label class="ed-lbl">Publication <span style="color:#dc2626;">*</span> <span style="font-weight:400;text-transform:none;color:#94a3b8;">— tick each publication; choose one as the canonical (primary) site.</span></label>
                                <div id="add_article_publications"><span style="color:#9ca3af;font-size:13px;">Loading publications…</span></div>
                            </div>
                            <div class="ed-tabpanel" data-edpanel="aauthor">
                                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                                    <div id="add_article_author_wrapper">
                                        <label class="ed-lbl">Author</label>
                                        <select id="add_article_author" name="add_article_author" class="ed-input"><option value="">Loading authors…</option></select>
                                    </div>
                                    <div>
                                        <label class="ed-lbl">Section <span style="color:#dc2626;">*</span></label>
                                        <select id="add_article_section" name="add_article_section" class="ed-input"><option value="">Loading sections…</option></select>
                                    </div>
                                </div>
                            </div>
                            <div class="ed-tabpanel" data-edpanel="aflags">
                                <div style="display:flex;flex-wrap:wrap;gap:8px;">
                                    <label class="ed-flag" title="Show this article as the main front-page headline story."><input type="checkbox" id="add_article_headline"><i class="fas fa-fire" style="color:#ef4444;"></i> Frontpage Headline</label>
                                    <label class="ed-flag" title="Show this article as the section headline."><input type="checkbox" id="add_article_featured"><i class="fas fa-star" style="color:#f59e0b;"></i> Section Headline</label>
                                    <label class="ed-flag" title="Never expires."><input type="checkbox" id="add_article_evergreen"><i class="fas fa-leaf" style="color:#10b981;"></i> Evergreen</label>
                                    <label class="ed-flag" title="Paid/partner content."><input type="checkbox" id="add_article_sponsored" name="add_article_sponsored" value="1"><i class="fas fa-ad" style="color:#8b5cf6;"></i> Sponsored</label>
                                </div>
                            </div>
                            <div class="ed-tabpanel" data-edpanel="aseo">
                                <label class="ed-lbl">Meta title <span style="font-weight:400;text-transform:none;color:#94a3b8;">(≤60 chars)</span></label>
                                <input type="text" id="add_article_meta_title" class="ed-input" maxlength="70">
                                <label class="ed-lbl" style="margin-top:10px;">Meta description <span style="font-weight:400;text-transform:none;color:#94a3b8;">(≤155 chars)</span></label>
                                <textarea id="add_article_meta_description" class="ed-input" rows="2" maxlength="180" style="resize:vertical;"></textarea>
                                <label class="ed-lbl" style="margin-top:10px;">Meta keywords <span style="font-weight:400;text-transform:none;color:#94a3b8;">(comma separated)</span></label>
                                <input type="text" id="add_article_meta_keywords" class="ed-input">
                                <label class="ed-lbl" style="margin-top:10px;">Tags <span style="font-weight:400;text-transform:none;color:#94a3b8;">(comma separated)</span></label>
                                <input type="text" id="add_article_tags" class="ed-input">
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="ed-lbl">Article Content <span style="color:#dc2626;">*</span></label>
                            <div class="editor-container">
                                <div class="editor-controls">
                                    <button type="button" id="bold" data-tooltip="Bold"><i class="fas fa-bold"></i></button>
                                    <button type="button" id="italic" data-tooltip="Italic"><i class="fas fa-italic"></i></button>
                                    <button type="button" id="underline" data-tooltip="Underline"><i class="fas fa-underline"></i></button>
                                    <button type="button" id="unordered-list" data-tooltip="Unordered List"><i class="fas fa-list-ul"></i></button>
                                    <button type="button" id="ordered-list" data-tooltip="Ordered List"><i class="fas fa-list-ol"></i></button>
                                    <button type="button" id="add-link" data-tooltip="Add Link"><i class="fas fa-link"></i></button>
                                    <button type="button" id="add-image-editor" data-tooltip="Add Image"><i class="fas fa-image"></i></button>
                                    <button type="button" id="search-free-images" data-tooltip="Search free images"><i class="fas fa-images"></i></button>
                                    <button type="button" id="maximize" data-tooltip="Maximize"><i class="fas fa-expand"></i></button>
                                    <button type="button" id="search" data-tooltip="Search"><i class="fas fa-search"></i></button>
                                    <button type="button" id="toggle-source" data-tooltip="Source"><i class="fas fa-code"></i></button>
                                </div>
                                <div id="article_text" name="article_text" class="editor" contenteditable="true" placeholder="Enter article text here..."></div>
                                <textarea id="source-view" class="source-view"></textarea>
                            </div>

                            <!-- Search Modal -->
                            <div class="overlay"></div>
                            <div class="modal html_editor" id="search-modal" style="background: #fff; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.2);">
                                <h3>Search in Editor</h3>
                                <input type="text" id="search-input" placeholder="Enter text to search...">
                                <button type="button" id="search-button">Search</button>
                                <button type="button" id="close-modal">Close</button>
                            </div>

                            <!-- link-modal moved to root level below -->
                        </div>

                        <div class="form-group" style="margin-top:14px;">
                            <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:#475569;cursor:pointer;">
                                <input type="checkbox" id="article_submission_terms_checkbox" name="article_submission_terms_checkbox" value="1" checked style="width:16px;height:16px;cursor:pointer;">
                                I agree to the <a href="#" onclick="$('#modal-article-terms').fadeIn(); return false;" style="color:#3c4f6d;font-weight:600;">Terms of Article Submission</a>
                            </label>
                        </div>

                        <div class="form-actions" style="display:flex;gap:10px;">
                            <button type="submit" class="btn btn-primary" onclick="window._addArticleAction='save';">
                                <i class="fa fa-save"></i> Save Draft Article
                            </button>
                            <button type="submit" class="btn" style="background:#f59e0b;color:#fff;border:none;" onclick="window._addArticleAction='submit';">
                                <i class="fa fa-paper-plane"></i> Submit for Review
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Tab 3: Add Image -->
            <div id="add-image" class="tab-content">
                <div class="card">
                    <h2><i class="fas fa-image"></i> Add Article Image</h2>
                    <p style="color:#6b7280;margin:-4px 0 18px;font-size:14px;">Search royalty-free images across multiple sources (Openverse, Wikimedia Commons, Pexels, Unsplash, Pixabay), or upload your own — then crop and save to your image library.</p>

                    <!-- Add-image actions: inline free-image search (rendered in the tab) -->
                    <div id="image_upload_section" style="display: block;">
                        <div class="form-group" id="file-upload-area" style="display: block;">
                            <div style="display:flex;gap:10px;margin-bottom:12px;flex-wrap:wrap;">
                                <input type="text" id="tab2ISQuery" class="form-control" placeholder="Search for an image — e.g. Munich town hall, wind turbines, football stadium" style="flex:1;min-width:240px;">
                                <button type="button" class="btn btn-primary" id="tab2ISSearchBtn"><i class="fas fa-search"></i> Search</button>
                            </div>
                            <div id="tab2ISSources" style="display:flex;flex-wrap:wrap;gap:8px 16px;margin-bottom:10px;align-items:center;"></div>
                            <p style="font-size:12.5px;color:#6b7280;margin:0 0 14px;">All sources are royalty-free. Pick a thumbnail, then crop and save to your library. <a href="#" id="tab2UploadLink" style="color:#475569;font-weight:600;text-decoration:none;">— or upload from your computer</a></p>
                            <div id="tab2ISResults"><div style="color:#98a0ac;font-size:14px;padding:24px 0;">Enter a search above to find royalty-free images.</div></div>
                            <input style="display: none;" type="file" id="file-input-tab2" accept="image/*">
                            <span id="filename-display" style="display:none;color:#666;"></span>
                        </div>

                        <div id="image-container-tab2" style="margin-top: 20px; display: none;">
                            <h3 style="color: #111827; margin-bottom: 15px;"><i class="fas fa-crop"></i> Crop Your Image</h3>
                            <div style="max-width: 800px; overflow: hidden; border: 2px solid #e5e7eb; border-radius: 8px; padding: 10px; background: #f9fafb;">
                                <img id="image-tab2" style="max-width: 100%; display: block;">
                            </div>

                            <div id="loading-tab2" style="display:none; text-align: center; margin: 20px 0;">
                                <img src="loading.gif" style="width: 50px;" />
                                <p style="color: #667eea; margin-top: 10px;">Processing image...</p>
                            </div>

                            <div class="form-group" style="margin-top: 20px;">
                                <label for="attribution-tab2">Image Attribution (optional)</label>
                                <input class="form-control" id="attribution-tab2" type="text" placeholder="Enter image copyright or attribution (e.g., Photo by John Doe)">
                                <small style="color: #6b7280; display: block; margin-top: 5px;">
                                    <i class="fas fa-info-circle"></i> Add attribution if the image requires credit
                                </small>
                            </div>

                            <div class="form-actions">
                                <button type="button" class="btn btn-danger" id="clear-image-tab2">
                                    <i class="fas fa-times"></i> Clear
                                </button>
                                <button type="button" class="btn btn-primary" id="crop-save-btn-tab2">
                                    <i class="fas fa-save"></i> Crop & Save Image
                                </button>
                            </div>
                        </div>

                        <div id="preview-tab2" style="margin-top: 30px; display: none;">
                            <h3 style="color: #111827; margin-bottom: 15px;"><i class="fas fa-check-circle" style="color: #10b981;"></i> Image Saved Successfully</h3>
                            <div id="preview-content-tab2" style="border: 2px solid #10b981; border-radius: 8px; padding: 15px; background: #f0fdf4;"></div>
                            <button type="button" class="btn btn-primary" style="margin-top: 15px;" onclick="location.reload();">
                                <i class="fas fa-plus"></i> Upload Another Image
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Tab 4: View Sponsored Articles -->
            <?php if (!$isJournalist): ?>
            <div id="sponsored-articles" class="tab-content">
                <div class="card">
                    <h2><i class="fas fa-list"></i> Sponsored Articles</h2>
                    <p>Sponsored articles will be added here</p>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Article Terms Modal -->
    <div id="modal-article-terms" class="modal" style="background: #fff; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.2); width: 600px; height: auto; max-height: 80vh; overflow-y: auto;">
        <button type="button" style="float: right; font-size: 24px; border: none; background: none; cursor: pointer;" onclick="$('#modal-article-terms').fadeOut();">&times;</button>
        <h2><i class="fa fa-pencil"></i> The Eye Newspapers' Terms of Usage</h2>
        <div style="padding: 20px;">
            <p><strong>Terms and Conditions</strong></p>
            <p>By submitting your article:</p>
            <ul style="margin-left: 20px; margin-top: 10px;">
                <li>You declare that you are the sole owner and author of the article and own 100% of all copyrights pertaining to the article or have permission from the owner or author to submit the article to The Eye Newspapers or its assigns. The same applies to any images included for publication.</li>
                <li>The Eye Newspapers retains all rights and copyrights to your article.</li>
                <li>You may submit articles that have been published elsewhere.</li>
                <li>You agree that The Eye Newspapers or its assigns are not liable for any potential lawsuit that could arise from the article(s) submitted and hold The Eye Newspapers or its assigns harmless for any situation that may arise.</li>
                <li>You agree that all articles submitted are submitted without any financial consideration now or in the future.</li>
                <li>You are giving permission to The Eye Newspapers or its assigns to publish, distribute or use in any way we deem appropriate. Including but not limited to: on any of our websites/print publications, E-mail newsletters etc.</li>
                <li>You are giving permission for The Eye Newspapers or its assigns to include your articles in our RSS Feeds.</li>
                <li>These permissions are provided to The Eye Newspapers or its assigns or to any successor or future owner of its assets.</li>
            </ul>
            <p style="margin-top: 10px;">No other use is implied or granted.</p>
            <p>Questions or concerns? Please contact us at admin@themunicheye.com</p>
            <div style="text-align: right; margin-top: 20px;">
                <button type="button" class="btn btn-primary" onclick="$('#modal-article-terms').fadeOut();">Close</button>
            </div>
        </div>
    </div>

    <!-- Image Upload Modal -->
    <div class="modal" id="modal_article_image_upload" style="width: auto; height: 500px; overflow-y: scroll; background: #fff; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.2);">
        <button style="float: right; font-size: 24px; border: none; background: none; cursor: pointer;" id="close-modal_article_image_upload">&times;</button>
        <h3 class="modal-title"><i class="gi gi-image"></i> Crop and Save Image</h3>
        
        <div class="image-upload">
            <label for="file-input" class="custom-file-upload" style="display: inline-block; padding: 10px 20px; background: #667eea; color: white; border-radius: 6px; cursor: pointer;">
                Upload Image
            </label>
            <input style="display: none;" type="file" id="file-input" accept="image/*">
            <div id="image-container">
                <img id="image" style="max-width: 100%; height: 310px">

                <div id="loading" style="display:none;">
                    <img src="loading.gif" />
                </div>
            </div>
            <div class="form-group" style="margin-top: 20px;">
                Image Copyright: <input class="form-control" id="attribution" type="text" placeholder="Enter name of image owner, if any" value="">
                <button type="button" class="btn btn-primary" style="clear: both; display: block; margin-top: 20px; float: right;" id="crop-btn">Crop & Save</button>
            </div>
            <div id="preview"></div>

            <button id="close-modal_article_image_upload2" style="clear: both; display: block; margin-top: 20px; float: right;" class="btn btn-danger">Close</button>
            <button type="button" id="clear_image" style="display: block; margin-top: 20px; float: right; margin-right: 10px;" class="btn btn-danger">Clear</button>
        </div>
    </div>

    <!-- Image Insert Modal -->
    <div id="modal_image_insert" style="z-index: 4444; height: 600px; width: 1000px; overflow: scroll; background: #fff; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.2);" class="modal">
        <button style="float: right; font-size: 24px; border: none; background: none; cursor: pointer;" id="close-modal_image_insert">&times;</button>
        <h2 class="modal-title"><i class="fa fa-pencil"></i> Insert Image</h2>
        <form class="form-horizontal form-bordered" onsubmit="return false;">
            <fieldset>
                <legend>Use this tool to insert an existing image into the article</legend>
                <div class="form-group">
                    <div class="col-xs-12" id="image_browser_container">
                        <div id="image_block_container" style="display: flex; flex-wrap: wrap; gap: 15px;">
                            <!-- Images will be dynamically added here -->
                        </div>
                    </div>
                </div>
            </fieldset>
            <div class="form-group form-actions">
                <div class="col-xs-12 text-right">
                    <button id="close-modal_image_insert2" style="clear: both; display: block; margin-top: 20px; float: right;" class="btn btn-danger">Close</button>
                </div>
            </div>
        </form>
    </div>

    <!-- Pixabay Modal -->
    <div id="modal_pixabay" style="z-index: 5444; height: 400px; width: 1000px; overflow: scroll; background: #fff; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.2);" class="modal">
        <span class="close" style="float: right; font-size: 28px; cursor: pointer;" onclick="$('.overlay, #modal_pixabay').fadeOut();">&times;</span>
        <form id="pixabayForm">
            <input type="text" id="searchQuery" placeholder="Search for images..." required style="padding: 10px; width: 70%; margin: 10px;">
            <button type="submit" class="btn btn-primary">Search</button>
        </form>
        <div id="modalBody" class="image-grid"></div>
    </div>

    <!-- Success Modal -->
    <div id="modal_saved_draft_article" class="modal" style="display: none; width: 500px; height: auto; background: #fff; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.2);">
        <h3 class="modal-title"><i class="gi gi-pen"></i> Draft Article Submission</h3>
        <div style="padding: 20px;">
            <div id="draft_article_saved_response"></div>
        </div>
        <div style="text-align: right; padding: 20px; border-top: 1px solid #e5e7eb;" id="footer_buttons">
        </div>
    </div>

    <!-- Article Edit Modal - Professional 2-column redesign -->
<div id="articleEditModal" aria-hidden="true" style="display:none;position:fixed;z-index:2000;left:0;top:0;width:100%;height:100%;overflow:hidden;background:rgba(15,23,42,0.7);backdrop-filter:blur(2px);">
    <div id="articleEditModalDialog" role="dialog" aria-modal="true"
         style="position:relative;display:flex;flex-direction:column;background:#fff;width:96%;max-width:1440px;max-height:95vh;margin:2.5vh auto;border-radius:14px;box-shadow:0 25px 60px rgba(0,0,0,0.3);overflow:hidden;">

        <!-- ── Modal Header ── -->
        <div style="display:flex;align-items:center;justify-content:space-between;padding:14px 24px;background:linear-gradient(135deg,#1e293b 0%,#334155 100%);flex-shrink:0;">
            <div style="display:flex;align-items:center;gap:12px;">
                <div style="background:rgba(255,255,255,0.15);border-radius:8px;width:36px;height:36px;display:flex;align-items:center;justify-content:center;">
                    <i class="fas fa-file-alt" style="color:#fff;font-size:16px;"></i>
                </div>
                <div>
                    <div style="font-size:16px;font-weight:700;color:#fff;line-height:1.2;">Edit Article</div>
                    <span id="modalArticleId" style="font-size:12px;color:#94a3b8;"></span>
                </div>
            </div>
            <div style="display:flex;gap:8px;align-items:center;">
                <div id="modal_save_status" style="padding:5px 12px;border-radius:6px;color:#94a3b8;font-size:12px;font-weight:500;"></div>
                <button id="modal_fullscreen_toggle" title="Toggle fullscreen" style="border:1px solid rgba(255,255,255,0.2);background:rgba(255,255,255,0.1);padding:7px 10px;border-radius:6px;cursor:pointer;font-size:13px;color:#cbd5e1;">
                    <i class="fas fa-expand"></i>
                </button>
                <button id="closeArticleModal" style="background:rgba(255,255,255,0.1);border:1px solid rgba(255,255,255,0.2);color:#fff;padding:7px 10px;cursor:pointer;font-size:16px;border-radius:6px;line-height:1;" title="Close">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>

        <!-- ── Modal Body: single column; collapsible settings on top ── -->
        <div style="flex:1;overflow:hidden;display:flex;flex-direction:column;">
            <form id="ten_edit_article" method="post" onsubmit="return false;" novalidate="novalidate" style="display:flex;flex-direction:column;flex:1;overflow:hidden;">
                <input type="hidden" id="article_id_field" name="article_id_field" value="">

                <!-- One scroll area so tall content (with images) is fully reachable -->
                <div style="flex:1;overflow-y:auto;padding:18px 24px;background:#fff;">

                    <!-- Title -->
                    <div style="margin-bottom:12px;">
                        <label style="display:block;font-size:11px;font-weight:700;color:#64748b;margin-bottom:6px;text-transform:uppercase;letter-spacing:0.8px;">Article Title <span style="color:#dc2626;">*</span></label>
                        <input type="text" id="modal_title" name="modal_title" placeholder="Enter article title..."
                               style="width:100%;padding:12px 14px;border:1px solid #e2e8f0;border-radius:8px;font-size:16px;font-weight:600;box-sizing:border-box;color:#0f172a;background:#fff;outline:none;">
                    </div>

                    <!-- Publish-now row (outside the tabs). From/To appear to the right only when unchecked. -->
                    <div style="display:flex;align-items:flex-end;gap:14px;flex-wrap:wrap;margin-bottom:12px;">
                        <label class="ed-flag" title="Publish immediately. Uncheck to schedule a publish window." style="background:#eff6ff;border-color:#bfdbfe;align-self:center;">
                            <input type="checkbox" id="modal_publish_now" checked><i class="fas fa-bolt" style="color:#3b82f6;"></i> Publish now
                        </label>
                        <div id="publish_date_fields" style="display:none;align-items:flex-end;gap:10px;">
                            <div><label class="ed-lbl">Publish from</label><input id="modal_publish_from" type="text" placeholder="dd-mm-yyyy HH:MM" class="ed-input" style="width:160px;"></div>
                            <div><label class="ed-lbl">Publish to</label><input id="modal_publish_to" type="text" placeholder="dd-mm-yyyy HH:MM" class="ed-input" style="width:160px;"></div>
                        </div>
                    </div>

                    <!-- Settings tabs: the tab row stays put; the chosen panel opens BELOW it. -->
                    <div class="ed-tabs">
                        <button type="button" class="ed-tab" data-edtab="pub"><i class="fas fa-newspaper"></i> Publications</button>
                        <button type="button" class="ed-tab" data-edtab="author"><i class="fas fa-user"></i> Author &amp; Section</button>
                        <button type="button" class="ed-tab" data-edtab="flags"><i class="fas fa-sliders-h"></i> Flags &amp; Scheduling</button>
                        <button type="button" class="ed-tab" data-edtab="seo"><i class="fas fa-hashtag"></i> SEO &amp; Metadata</button>
                    </div>
                    <div class="ed-tabwrap">
                        <div class="ed-tabpanel" data-edpanel="pub"><div id="modal_publications_container"></div></div>

                        <div class="ed-tabpanel" data-edpanel="author">
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                                <div>
                                    <label class="ed-lbl">Author</label>
                                    <select id="modal_author" name="modal_author" size="1" class="ed-input"><option value="">Select author...</option></select>
                                </div>
                                <div>
                                    <label class="ed-lbl">Section</label>
                                    <select id="modal_section" name="modal_section" size="1" class="ed-input"><option value="">Choose section...</option></select>
                                </div>
                            </div>
                        </div>

                        <div class="ed-tabpanel" data-edpanel="flags">
                            <div style="display:flex;flex-wrap:wrap;gap:8px;">
                                <label class="ed-flag" title="Show this article as the main front-page headline story."><input type="checkbox" id="modal_headline_checkbox"><i class="fas fa-fire" style="color:#ef4444;"></i> Frontpage Headline</label>
                                <label class="ed-flag" title="Show this article as the section headline."><input type="checkbox" id="modal_featured" name="modal_featured" value="1"><i class="fas fa-star" style="color:#f59e0b;"></i> Section Headline</label>
                                <label class="ed-flag" title="Never expires."><input type="checkbox" id="modal_evergreen"><i class="fas fa-leaf" style="color:#10b981;"></i> Evergreen</label>
                                <label class="ed-flag" title="Paid/partner content."><input type="checkbox" id="modal_sponsored"><i class="fas fa-ad" style="color:#8b5cf6;"></i> Sponsored</label>
                            </div>
                        </div>

                        <div class="ed-tabpanel" data-edpanel="seo">
                            <label class="ed-lbl">Meta title <span style="font-weight:400;text-transform:none;color:#94a3b8;">(≤60 chars)</span></label>
                            <input type="text" id="modal_meta_title" class="ed-input" maxlength="70">
                            <label class="ed-lbl" style="margin-top:10px;">Meta description <span style="font-weight:400;text-transform:none;color:#94a3b8;">(≤155 chars)</span></label>
                            <textarea id="modal_meta_description" class="ed-input" rows="2" maxlength="180" style="resize:vertical;"></textarea>
                            <label class="ed-lbl" style="margin-top:10px;">Meta keywords <span style="font-weight:400;text-transform:none;color:#94a3b8;">(comma separated)</span></label>
                            <input type="text" id="modal_meta_keywords" class="ed-input">
                            <label class="ed-lbl" style="margin-top:10px;">Tags <span style="font-weight:400;text-transform:none;color:#94a3b8;">(comma separated)</span></label>
                            <input type="text" id="modal_tags" class="ed-input">
                        </div>
                    </div>

                    <!-- Content Editor (the scraper photo-suggestion strip auto-inserts just above this) -->
                    <div style="margin-bottom:8px;">
                        <label style="display:block;font-size:11px;font-weight:700;color:#64748b;margin-bottom:6px;text-transform:uppercase;letter-spacing:0.8px;">Article Content <span style="color:#dc2626;">*</span></label>
                        <div id="modal_editor_slot" style="border:1px solid #e2e8f0;border-radius:8px;overflow:visible;background:#fff;">
                            <!-- Editor container moved here dynamically -->
                        </div>
                    </div>

                    <!-- Hidden legacy fields -->
                    <div style="display:none;">
                        <input type="text" id="modal_alias" name="modal_alias">
                        <select id="modal_section_subcat" name="modal_section_subcat" size="1">
                            <option value="">Is it a sub-category?</option>
                        </select>
                    </div>

                </div>

            </form>
        </div><!-- end body -->

        <!-- ── Modal Footer ── -->
        <div style="padding:14px 24px;border-top:1px solid #e2e8f0;background:#f8fafc;flex-shrink:0;display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap;">
            <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:#475569;cursor:pointer;">
                <input type="checkbox" id="modal_terms_checkbox" checked style="width:16px;height:16px;cursor:pointer;">
                I agree to the <a href="#" onclick="$('#modal-article-terms').fadeIn();return false;" style="color:#3c4f6d;font-weight:600;">Terms of Article Submission</a>
            </label>
            <div style="display:flex;gap:8px;">
                <button id="deleteArticleBtn" title="Move this article to the Deleted state (kept in the database)" style="background:#fff;border:1px solid #fecaca;color:#dc2626;padding:9px 18px;border-radius:8px;cursor:pointer;font-weight:600;font-size:13px;display:flex;align-items:center;gap:6px;">
                    <i class="fas fa-trash"></i> Delete
                </button>
                <button id="cancelBtn" style="background:#fff;border:1px solid #e2e8f0;color:#64748b;padding:9px 18px;border-radius:8px;cursor:pointer;font-weight:600;font-size:13px;display:flex;align-items:center;gap:6px;transition:all 0.15s;">
                    <i class="fas fa-times"></i> Cancel
                </button>
                <button id="saveDraftBtn" style="background:#fff;border:1px solid #e2e8f0;color:#374151;padding:9px 18px;border-radius:8px;cursor:pointer;font-weight:600;font-size:13px;display:flex;align-items:center;gap:6px;transition:all 0.15s;">
                    <i class="fas fa-save"></i> Save Draft
                </button>
                <button id="submitReviewBtn" style="background:#f59e0b;border:none;color:#fff;padding:9px 18px;border-radius:8px;cursor:pointer;font-weight:600;font-size:13px;display:flex;align-items:center;gap:6px;">
                    <i class="fas fa-paper-plane"></i> Submit for Review
                </button>
                <button id="publishBtn" style="background:#10b981;border:none;color:#fff;padding:9px 18px;border-radius:8px;cursor:pointer;font-weight:600;font-size:13px;display:none;align-items:center;gap:6px;">
                    <i class="fas fa-check-circle"></i> Publish
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ===== SHARED LINK MODAL (root-level, high z-index, works in both Add Article and Edit Modal) ===== -->
<div id="link-modal" style="display:none;position:fixed;z-index:9999;left:50%;top:50%;transform:translate(-50%,-50%);background:#fff;border-radius:10px;box-shadow:0 20px 60px rgba(0,0,0,0.3);min-width:420px;max-width:520px;padding:0;font-family:inherit;">
    <div style="padding:16px 20px;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;justify-content:space-between;">
        <div style="display:flex;align-items:center;gap:8px;">
            <i class="fas fa-link" style="color:#3c4f6d;"></i>
            <strong style="font-size:15px;color:#111827;">Insert / Edit Link</strong>
        </div>
        <button type="button" id="close-link-modal2" style="background:none;border:none;font-size:20px;color:#9ca3af;cursor:pointer;line-height:1;">&times;</button>
    </div>
    <div style="padding:20px;">
        <div style="margin-bottom:14px;">
            <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:5px;text-transform:uppercase;letter-spacing:0.5px;">URL <span style="color:#dc2626;">*</span></label>
            <input type="url" id="link-url" placeholder="https://example.com" style="width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;box-sizing:border-box;">
        </div>
        <div style="margin-bottom:14px;">
            <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:5px;text-transform:uppercase;letter-spacing:0.5px;">Link Text <span style="color:#dc2626;">*</span></label>
            <input type="text" id="link-text" placeholder="Text to display" style="width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;box-sizing:border-box;">
        </div>
        <div style="margin-bottom:14px;">
            <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:5px;text-transform:uppercase;letter-spacing:0.5px;">Title / Tooltip</label>
            <input type="text" id="link-title" placeholder="Hover tooltip (optional)" style="width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:14px;box-sizing:border-box;">
        </div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:14px;">
            <div>
                <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:5px;text-transform:uppercase;letter-spacing:0.5px;">Open In</label>
                <select id="link-target" style="width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;background:#fff;">
                    <option value="_blank" selected>New Tab (recommended)</option>
                    <option value="_self">Same Page</option>
                </select>
            </div>
            <div>
                <label style="display:block;font-size:12px;font-weight:600;color:#374151;margin-bottom:5px;text-transform:uppercase;letter-spacing:0.5px;">Rel Attribute</label>
                <select id="link-rel" style="width:100%;padding:9px 12px;border:1px solid #d1d5db;border-radius:6px;font-size:13px;background:#fff;">
                    <option value="noopener noreferrer">noopener noreferrer</option>
                    <option value="">None</option>
                    <option value="nofollow">nofollow</option>
                    <option value="nofollow noopener noreferrer">nofollow noopener noreferrer</option>
                </select>
            </div>
        </div>
    </div>
    <div style="padding:14px 20px;border-top:1px solid #e5e7eb;display:flex;gap:8px;justify-content:flex-end;background:#f9fafb;border-radius:0 0 10px 10px;">
        <button type="button" id="preview-link" style="background:#fff;color:#374151;border:1px solid #d1d5db;padding:8px 16px;border-radius:6px;cursor:pointer;font-size:13px;font-weight:500;display:flex;align-items:center;gap:6px;"><i class="fas fa-external-link-alt"></i> Preview</button>
        <button type="button" id="close-link-modal" style="background:#fff;color:#374151;border:1px solid #d1d5db;padding:8px 16px;border-radius:6px;cursor:pointer;font-size:13px;font-weight:500;">Cancel</button>
        <button type="button" id="insert-link" style="background:#3c4f6d;color:#fff;border:none;padding:8px 18px;border-radius:6px;cursor:pointer;font-size:13px;font-weight:600;display:flex;align-items:center;gap:6px;"><i class="fas fa-link"></i> Insert Link</button>
    </div>
</div>
<!-- Also a root-level overlay for link modal -->
<div id="link-modal-overlay" style="display:none;position:fixed;z-index:9998;left:0;top:0;width:100%;height:100%;background:rgba(0,0,0,0.4);"></div>

<script>
// Toggle publish date fields when Publish Now checkbox changes
document.getElementById('modal_publish_now').addEventListener('change', function() {
    const dateFields = document.getElementById('publish_date_fields');
    dateFields.style.display = this.checked ? 'none' : 'flex';
});

/* Settings tabs: keep the tab row fixed; open the chosen panel below it.
   Scoped per .ed-tabs group (its own .ed-tabwrap sibling + panels) so multiple
   groups on the page — the edit modal AND the Add Article tab — work independently. */
(function () {
    document.querySelectorAll('.ed-tabs').forEach(function (group) {
        var tabs = group.querySelectorAll('.ed-tab');
        var wrap = group.nextElementSibling;
        while (wrap && !wrap.classList.contains('ed-tabwrap')) wrap = wrap.nextElementSibling;
        if (!tabs.length || !wrap) return;
        var panels = wrap.querySelectorAll('.ed-tabpanel');
        tabs.forEach(function (t) {
            t.addEventListener('click', function () {
                var key = t.getAttribute('data-edtab');
                var wasActive = t.classList.contains('active');
                tabs.forEach(function (x) { x.classList.remove('active'); });
                panels.forEach(function (p) { p.classList.remove('active'); });
                if (wasActive) { wrap.classList.remove('open'); return; }
                t.classList.add('active');
                var panel = wrap.querySelector('.ed-tabpanel[data-edpanel="' + key + '"]');
                if (panel) panel.classList.add('active');
                wrap.classList.add('open');
            });
        });
    });
})();
</script>

<style>
/* Edit modal responsive sidebar */
@media (max-width: 900px) {
    #articleEditModalDialog > div[style*="flex-direction:row"] { flex-direction: column !important; }
    #modal_sidebar { width: 100% !important; border-right: none !important; border-top: 1px solid #e2e8f0; }
}
@media (min-width: 901px) {
    #modal_editor_slot { min-height: 520px; }
}
#saveDraftBtn:hover { background: #f1f5f9 !important; border-color: #94a3b8 !important; }
#cancelBtn:hover { background: #f1f5f9 !important; }
#submitReviewBtn:hover { background: #d97706 !important; }
#publishBtn:hover { background: #059669 !important; }
/* Sidebar label rows hover */
#modal_sidebar label[style*="justify-content:space-between"]:hover { background: #f1f5f9 !important; }
</style>



    <script>
        // Tab switching - wrap in DOMContentLoaded
        document.addEventListener('DOMContentLoaded', function() {
            document.querySelectorAll('.tab').forEach(tab => {
                tab.addEventListener('click', function() {
                    const targetTab = this.dataset.tab;

                    // Remove active class from all tabs and contents
                    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
                    document.querySelectorAll('.tab-content').forEach(c => c.classList.remove('active'));

                    // Add active class to clicked tab and corresponding content
                    this.classList.add('active');
                    document.getElementById(targetTab).classList.add('active');

                    // If returning to Add Article tab, ensure editor-container is in its original home
                    if (targetTab === 'add-article') {
                        const editorSlot = document.getElementById('modal_editor_slot');
                        const addArticleForm = document.getElementById('ten_add_article');
                        if (addArticleForm) {
                            const editorContainer = document.querySelector('.editor-container');
                            // Only restore if it's inside the modal slot, modal is closed
                            if (editorContainer && editorSlot && editorSlot.contains(editorContainer)) {
                                const articleEditModal = document.getElementById('articleEditModal');
                                if (!articleEditModal || articleEditModal.style.display === 'none' || articleEditModal.style.display === '') {
                                    // Find the article_text form-group to restore before
                                    const contentLabel = addArticleForm.querySelector('label[for="article_text"], .form-group .editor-container');
                                    const formGroupDiv = editorContainer.closest ? null : null;
                                    // Find the right insertion point: after the form-group containing the AI prompt
                                    const aiGroup = addArticleForm.querySelector('#hide_ai_prompt');
                                    if (aiGroup && aiGroup.parentNode) {
                                        const insertAfter = aiGroup.parentNode;
                                        const nextSib = insertAfter.nextSibling;
                                        if (nextSib) {
                                            insertAfter.parentNode.insertBefore(editorContainer, nextSib);
                                        } else {
                                            insertAfter.parentNode.appendChild(editorContainer);
                                        }
                                        editorContainer.removeAttribute('data-__moved');
                                        // Reset the movedEditor flag via a custom event
                                        document.dispatchEvent(new CustomEvent('editorRestored'));
                                    }
                                }
                            }
                        }
                    }
                });
            });
        });

        $(document).ready(function () {
            let isSourceView = false;
            let isFullscreen = false;

            var selectedRange = null;

            // Function to save the current selection range
            function saveSelection() {
                var selection = window.getSelection();
                if (selection.rangeCount > 0) {
                    selectedRange = selection.getRangeAt(0);
                }
            }

            // Function to restore the saved selection
            function restoreSelection() {
                const selection = window.getSelection();
                if (selectedRange) {
                    selection.removeAllRanges();
                    selection.addRange(selectedRange);
                }
            }

            // Check if the selection is inside an <a> tag
            function getParentAnchorNode() {
                const selection = window.getSelection();
                if (selection.rangeCount > 0) {
                    let node = selection.anchorNode;
                    while (node && node.nodeType === 3) { // Walk up if it's a text node
                        node = node.parentNode;
                    }
                    while (node && node.nodeName !== "A") {
                        node = node.parentNode;
                    }
                    return node && node.nodeName === "A" ? node : null;
                }
                return null;
            }

            // Editor toolbar functionality - ensure focus is in editor
            $('#bold').click(function() {
                $('#article_text').focus();
                document.execCommand('bold', false, null);
            });
            
            $('#italic').click(function() {
                $('#article_text').focus();
                document.execCommand('italic', false, null);
            });
            
            $('#underline').click(function() {
                $('#article_text').focus();
                document.execCommand('underline', false, null);
            });
            
            $('#unordered-list').click(function() {
                $('#article_text').focus();
                document.execCommand('insertUnorderedList', false, null);
            });
            
            $('#ordered-list').click(function() {
                $('#article_text').focus();
                document.execCommand('insertOrderedList', false, null);
            });

            // Add link functionality
            $('#add-link').click(function () {
                saveSelection();
                const anchorNode = getParentAnchorNode();

                if (anchorNode) {
                    $('#link-url').val(anchorNode.href || '');
                    $('#link-title').val(anchorNode.title || '');
                    $('#link-text').val(anchorNode.textContent || '');
                    $('#link-target').val(anchorNode.target || '_blank');
                    $('#link-rel').val(anchorNode.rel || '');
                } else {
                    const selectedText = window.getSelection().toString();
                    $('#link-url').val('');
                    $('#link-title').val('');
                    $('#link-text').val(selectedText);
                    $('#link-target').val('_blank');
                    $('#link-rel').val('noopener noreferrer');
                }
                $('#link-modal-overlay, #link-modal').fadeIn();
                setTimeout(function() { $('#link-url').focus(); }, 100);
            });

            // Insert link
            $('#insert-link').click(function () {
                const url = $('#link-url').val().trim();
                const title = $('#link-title').val().trim();
                const linkText = $('#link-text').val().trim();
                const target = $('#link-target').val();
                const rel = $('#link-rel').val();

                if (!url) {
                    alert('Please enter a URL.');
                    $('#link-url').focus();
                    return;
                }
                if (!linkText) {
                    alert('Please enter link text to display.');
                    $('#link-text').focus();
                    return;
                }

                if (url && linkText) {
                    restoreSelection();

                    const anchorNode = getParentAnchorNode();
                    if (anchorNode) {
                        anchorNode.href = url;
                        anchorNode.title = title;
                        anchorNode.target = target;
                        anchorNode.rel = rel;
                        anchorNode.textContent = linkText;
                    } else {
                        const titleAttr = title ? ` title="${title}"` : '';
                        const relAttr = rel ? ` rel="${rel}"` : '';
                        const linkHTML = `<a href="${url}"${titleAttr} target="${target}"${relAttr}>${linkText}</a>`;
                        const editor = $('.editor');
                        const editorEl = editor[0];

                        if (selectedRange && editorEl && editorEl.contains(selectedRange.commonAncestorContainer)) {
                            const tempDiv = document.createElement('div');
                            tempDiv.innerHTML = linkHTML;
                            const linkNode = tempDiv.firstChild;
                            selectedRange.deleteContents();
                            selectedRange.insertNode(linkNode);
                            selectedRange.setStartAfter(linkNode);
                            selectedRange.collapse(true);
                            window.getSelection().removeAllRanges();
                            window.getSelection().addRange(selectedRange);
                        } else if (editorEl) {
                            // Fallback: insert at start of editor
                            const tempDiv = document.createElement('div');
                            tempDiv.innerHTML = linkHTML;
                            const linkNode = tempDiv.firstChild;
                            if (editorEl.firstChild) {
                                editorEl.insertBefore(linkNode, editorEl.firstChild);
                            } else {
                                editorEl.appendChild(linkNode);
                            }
                        }
                    }
                    $('#link-modal-overlay, #link-modal').fadeOut();
                } else {
                    alert('Please provide both URL and link text.');
                }
            });

            // Preview link
            $('#preview-link').click(function () {
                const url = $('#link-url').val().trim();
                if (url) {
                    window.open(url, '_blank');
                } else {
                    alert('Please enter a valid URL to preview.');
                }
            });

            // Add image from browser
            $('#add-image-editor').click(function () {
                // Load existing images
                loadExistingImages();
                $('.overlay, #modal_image_insert').fadeIn();
            });

            function loadExistingImages() {
                $.ajax({
                    url: 'load_images.php',
                    method: 'GET',
                    dataType: 'json',
                    success: function(data) {
                        if (data.status === 'success' && data.images && data.images.length > 0) {
                            const container = document.getElementById('image_block_container');
                            container.innerHTML = '';
                            
                            data.images.forEach(function(img) {
                                const imageBlock = document.createElement('div');
                                imageBlock.className = 'image_block_item';
                                imageBlock.style.cssText = 'display: inline-block; margin: 10px; text-align: center; border: 1px solid #ddd; padding: 10px; border-radius: 8px; background: #fff;';
                                
                                imageBlock.innerHTML = `
                                    <a href="${img.url}" target="_blank" title="${img.attribution || ''}">
                                        <img src="${img.url}" style="max-width: 150px; max-height: 150px; display: block; margin-bottom: 8px; border-radius: 4px;">
                                    </a>
                                    <button type="button" class="image_chosen_id btn btn-sm btn-primary" style="margin-top: 5px;">
                                        <i class="fas fa-check"></i> Insert Image
                                    </button>
                                    ${img.attribution ? `<p style="font-size: 11px; color: #6b7280; margin-top: 5px; word-break: break-word;">${img.attribution}</p>` : ''}
                                `;
                                
                                container.appendChild(imageBlock);
                            });
                        } else {
                            document.getElementById('image_block_container').innerHTML = '<p style="text-align: center; color: #6b7280; padding: 40px;">No images uploaded yet. Use the "Add Image" tab to upload images.</p>';
                        }
                    },
                    error: function() {
                        document.getElementById('image_block_container').innerHTML = '<p style="text-align: center; color: #ef4444; padding: 40px;">Failed to load images. Please try again.</p>';
                    }
                });
            }

            // Add Pixabay image
            $('#add-pixabay-image').click(function () {
                $('.overlay, #modal_pixabay').fadeIn();
            });

            // Maximize editor
            $('#maximize').click(function () {
                const container = $('.editor-container');
                if (isFullscreen) {
                    container.removeClass('fullscreen');
                    $(this).html('<i class="fas fa-expand"></i>');
                } else {
                    container.addClass('fullscreen');
                    $(this).html('<i class="fas fa-compress"></i>');
                }
                isFullscreen = !isFullscreen;
            });

            // Search functionality
            let searchMatches = [];
            let currentMatchIndex = -1;

            $('#search').click(function () {
                $('.overlay, #search-modal').fadeIn();
                $('#search-input').val('');
                clearHighlights();
            });

            $('#search-button').click(function () {
                searchEditor();
            });

            $('#search-input').on('keypress', function(e) {
                if (e.which === 13) { // Enter key
                    searchEditor();
                }
            });

            function searchEditor() {
                const searchText = $('#search-input').val().trim();
                clearHighlights();

                if (searchText) {
                    const content = $('#article_text').html();
                    const regex = new RegExp(searchText, 'gi');
                    const highlighted = content.replace(regex, match => `<mark style="background-color: yellow;">${match}</mark>`);
                    $('#article_text').html(highlighted);
                    
                    const matches = $('#article_text').find('mark');
                    if (matches.length > 0) {
                        searchMatches = matches;
                        currentMatchIndex = 0;
                        scrollToMatch(0);
                    } else {
                        alert('No matches found.');
                    }
                }
            }

            function clearHighlights() {
                $('#article_text').find('mark').each(function() {
                    $(this).replaceWith($(this).text());
                });
                searchMatches = [];
                currentMatchIndex = -1;
            }

            function scrollToMatch(index) {
                if (searchMatches[index]) {
                    searchMatches[index].scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }

            // Toggle source view
            // Toggle source view - works in both tab and modal
            $('#toggle-source').click(function () {
                const editorContainer = $(this).closest('.editor-container');
                const editor = editorContainer.find('.editor, #article_text[contenteditable="true"]');
                const sourceView = editorContainer.find('.source-view, #source-view');
                
                console.log('Toggle source clicked', {
                    isSourceView: isSourceView,
                    editorFound: editor.length,
                    sourceViewFound: sourceView.length
                });
                
                if (isSourceView) {
                    // Switch to visual editor
                    editor.html(sourceView.val()).removeClass('hidden').show();
                    sourceView.removeClass('active').hide();
                    $(this).html('<i class="fas fa-code"></i>');
                    $(this).attr('data-tooltip', 'Source');
                } else {
                    // Switch to source view
                    sourceView.val(editor.html()).addClass('active').show();
                    editor.addClass('hidden').hide();
                    $(this).html('<i class="fas fa-edit"></i>');
                    $(this).attr('data-tooltip', 'Visual');
                }
                isSourceView = !isSourceView;
            });

            // Close modals
            $('#close-link-modal, #close-link-modal2').click(function () {
                $('#link-modal-overlay, #link-modal').fadeOut();
            });
            $('#link-modal-overlay').click(function() {
                $(this).fadeOut();
                $('#link-modal').fadeOut();
            });

            $('#close-modal').click(function () {
                $('.overlay, #search-modal').fadeOut();
            });

            $('#close-modal_article_image_upload, #close-modal_article_image_upload2').click(function () {
                $('.overlay, #modal_article_image_upload').fadeOut();
            });

            $('#close-modal_image_insert, #close-modal_image_insert2').click(function () {
                $('.overlay, #modal_image_insert').fadeOut();
            });

            $('.overlay').click(function () {
                $(this).fadeOut();
                $('.modal').fadeOut();
            });

            // AI Prompt toggle
            $("#ai_prompt_toggle").click(function() {
                $('#hide_ai_prompt').toggle();  
                if ($("#hide_ai_prompt").is(":hidden"))
                    $("#ai_prompt_toggle").html('<i class="fa fa-angle-right"></i> Show AI Prompt');
                else
                    $("#ai_prompt_toggle").html('<i class="fa fa-angle-down"></i> Hide AI Prompt');
            });

            // AI Prompt request
            $("#ai_prompt_request").click(function() {
                $('#loading_image').show(); 
                var ai_query = $("#ai_query").val();
                
                // If the query contains "Check this article" or similar, get content from editor
                if (ai_query.toLowerCase().includes('check this article') || 
                    ai_query.toLowerCase().includes('rewrite this article') || 
                    ai_query.toLowerCase().includes('fact-check this article') || 
                    ai_query.toLowerCase().includes('optimize this article')) {
                    var article_content = $("#article_text").text().trim();
                    if (article_content) {
                        ai_query = ai_query + ': ' + article_content;
                    }
                }
                
                var datastring = 'ai_query=' + encodeURI(ai_query);
                $.ajax({
                    type: "POST",
                    url: "get_ai_response.php",
                    data: datastring,
                    dataType: "json",
                    success: function(data) {
                        var article_prepped = '<p>' + data.ai_response + '</p>';
                        $('#article_text').append(article_prepped);
                        $('#loading_image').hide(); 
                    },
                    error: function(data) {
                        alert('Error getting AI response');
                        $('#loading_image').hide();
                    }
                }); 
            });

            // Canned prompts
            $(document).on('change', "#canned_prompt_create_article", function() {
                if ($('#canned_prompt_create_article').val() != '') {
                    if ($('#ai_query').val() == '')
                        $('#ai_query').val($('#canned_prompt_create_article').val());
                    else
                        $('#ai_query').val($('#ai_query').val() + ' ' + $('#canned_prompt_create_article').val());
                }
            });

            $(document).on('change', "#canned_prompt_about_place", function() {
                if ($('#canned_prompt_about_place').val() != '') {
                    if ($('#ai_query').val() == '')
                        $('#ai_query').val($('#canned_prompt_about_place').val());
                    else
                        $('#ai_query').val($('#ai_query').val() + ' ' + $('#canned_prompt_about_place').val());
                }
            });

            // Image cropper functionality
            const fileInput = document.getElementById('file-input');
            const image = document.getElementById('image');
            const cropperOptions = {
                aspectRatio: 490 / 310,
                viewMode: 1
            };
            let cropper;

            fileInput.addEventListener('change', (e) => {
                const file = e.target.files[0];
                if (file) {
                    const reader = new FileReader();
                    reader.onload = (event) => {
                        image.src = event.target.result;
                        if (cropper) cropper.destroy();
                        cropper = new Cropper(image, cropperOptions);
                    };
                    reader.readAsDataURL(file);
                }
            });

            document.getElementById('crop-btn').addEventListener('click', () => {
                if (!cropper) {
                    alert('Please upload an image first');
                    return;
                }
                var attribution = document.getElementById('attribution').value;
                const croppedCanvas = cropper.getCroppedCanvas({ width: 490, height: 310, fillColor: '#ffffff', imageSmoothingEnabled: true, imageSmoothingQuality: 'high' });
                const croppedImage = croppedCanvas.toDataURL('image/jpeg', 0.95);
                $("#loading").show();
                fetch('crop_webp.php', {
                    method: 'POST',
                    body: JSON.stringify({ image: croppedImage, attribution: attribution }),
                    headers: { 'Content-Type': 'application/json' },
                })
                .then((response) => response.json())
                .then((data) => {
                    $("#loading").hide();
                    document.getElementById('preview').innerHTML = `<img src="${data.url}" alt="Cropped Image">`;
                    if (data.single_image_insert_block) {
                        $("#image_block").prepend(data.single_image_insert_block);
                    }
                    Swal.fire('Success', 'Image uploaded successfully', 'success');
                })
                .catch((error) => {
                    $("#loading").hide();
                    console.error('Error:', error);
                    alert('Error uploading image');
                });
            });

            $("#clear_image").click(function() {
                document.getElementById('preview').innerHTML = ``;
                if (cropper) {
                    cropper.destroy();
                    cropper = null;
                }
                $('#image').attr('src', '');
                $('#attribution').val("");
            });

            // Insert image into editor
            $(document).on('click', '.image_chosen_id', function() {
                const editor = $('.editor');
                var image_link = $(this).prev().attr('href');
                var image_attribution = $(this).prev().attr('title') || '';
                // Clean image markup — the site's per-placement CSS handles sizing;
                // an inline max-width:50% would shrink the front-page headline.
                var image_str_prepped = '<img src="' + image_link + '" alt="" />';

                insertHTMLAtCursor(image_str_prepped, editor);
                $('.overlay, #modal_image_insert').fadeOut();
            });

            // ── Image right-click context menu ──────────────────────────────────
            // Build a single reusable context menu (root-level, z-index above modal)
            (function() {
                var menu = document.getElementById('editor-img-context-menu');
                if (!menu) {
                    menu = document.createElement('div');
                    menu.id = 'editor-img-context-menu';
                    menu.style.cssText = [
                        'display:none',
                        'position:fixed',
                        'z-index:10000',
                        'background:#fff',
                        'border:1px solid #e2e8f0',
                        'border-radius:8px',
                        'box-shadow:0 8px 24px rgba(0,0,0,0.18)',
                        'padding:6px 0',
                        'min-width:160px',
                        'font-family:inherit',
                        'font-size:13px'
                    ].join(';');
                    menu.innerHTML =
                        '<div id="editor-img-ctx-delete" style="display:flex;align-items:center;gap:8px;padding:9px 16px;cursor:pointer;color:#dc2626;border-radius:4px;transition:background 0.12s;">' +
                            '<i class="fas fa-trash-alt" style="font-size:12px;"></i> Remove Image' +
                        '</div>';
                    document.body.appendChild(menu);

                    // Hover state
                    var delBtn = menu.querySelector('#editor-img-ctx-delete');
                    delBtn.addEventListener('mouseenter', function() { this.style.background = '#fef2f2'; });
                    delBtn.addEventListener('mouseleave', function() { this.style.background = ''; });
                }

                var targetImg = null;

                // Show menu on right-click inside any .editor img
                $(document).on('contextmenu', '.editor img', function(e) {
                    e.preventDefault();
                    e.stopPropagation();
                    targetImg = this;

                    // Position near cursor, keep within viewport
                    var x = e.clientX, y = e.clientY;
                    menu.style.display = 'block';
                    var mw = menu.offsetWidth, mh = menu.offsetHeight;
                    if (x + mw > window.innerWidth)  x = window.innerWidth  - mw - 8;
                    if (y + mh > window.innerHeight) y = window.innerHeight - mh - 8;
                    menu.style.left = x + 'px';
                    menu.style.top  = y + 'px';
                });

                // Delete action
                document.getElementById('editor-img-ctx-delete').addEventListener('click', function(e) {
                    e.stopPropagation();
                    if (targetImg) {
                        // Remove the img; if it's the only child of a wrapper block, remove the wrapper too
                        var parent = targetImg.parentElement;
                        targetImg.parentNode.removeChild(targetImg);
                        if (parent && parent !== document.body &&
                            ['P','DIV','FIGURE'].indexOf(parent.tagName) !== -1 &&
                            parent.innerHTML.trim() === '') {
                            parent.parentNode && parent.parentNode.removeChild(parent);
                        }
                        targetImg = null;
                    }
                    menu.style.display = 'none';
                });

                // Dismiss menu on any outside click or scroll
                document.addEventListener('click',     function() { menu.style.display = 'none'; });
                document.addEventListener('scroll',    function() { menu.style.display = 'none'; }, true);
                document.addEventListener('keydown',   function(e) { if (e.key === 'Escape') menu.style.display = 'none'; });
            })();

            function insertHTMLAtCursor(html, editor) {
                const editorElement = editor[0];
                const selection = window.getSelection();

                if (selection.rangeCount > 0) {
                    const range = selection.getRangeAt(0);
                    if (editorElement.contains(range.commonAncestorContainer)) {
                        const tempDiv = document.createElement("div");
                        tempDiv.innerHTML = html;
                        const nodes = Array.from(tempDiv.childNodes);
                        nodes.forEach((node) => {
                            range.insertNode(node);
                        });
                        if (nodes.length > 0) {
                            range.setStartAfter(nodes[nodes.length - 1]);
                            range.collapse(true);
                        }
                        selection.removeAllRanges();
                        selection.addRange(range);
                        return;
                    }
                }

                // Fallback: insert at the beginning of the editor
                const tempDiv = document.createElement("div");
                tempDiv.innerHTML = html;
                const fragment = document.createDocumentFragment();
                Array.from(tempDiv.childNodes).forEach((node) => fragment.appendChild(node));
                if (editorElement.firstChild) {
                    editorElement.insertBefore(fragment, editorElement.firstChild);
                } else {
                    editorElement.appendChild(fragment);
                }
            }

            // Pixabay integration
            const gallery = document.getElementById('modalBody');
            const apiKey = '<?php echo $apiKey; ?>';

            $("#pixabayForm").on("submit", function(event) {
                event.preventDefault();
                const query = $("#searchQuery").val().trim();
                if (query) {
                    fetchPixabayImages(query);
                }
            });

            function fetchPixabayImages(query) {
                const apiUrl = `https://pixabay.com/api/?key=${apiKey}&q=${encodeURIComponent(query)}`;
                $.get(apiUrl, function(data) {
                    if (data.hits && data.hits.length > 0) {
                        let content = '';
                        data.hits.forEach(image => {
                            if (image.imageWidth >= 640) {
                                const imgElement = document.createElement("img");
                                imgElement.src = image.largeImageURL;
                                imgElement.alt = image.tags;
                                imgElement.style.cursor = "pointer";
                                imgElement.style.margin = "10px";
                                imgElement.style.maxWidth = "200px";
                                
                                imgElement.addEventListener("click", () => {
                                    const editor = $('.editor');
                                    const imgHtml = `<img src="${image.largeImageURL}" alt="">`;
                                    insertHTMLAtCursor(imgHtml, editor);
                                    $('.overlay, #modal_pixabay').fadeOut();
                                });
                                
                                gallery.appendChild(imgElement);
                            }
                        });
                    } else {
                        $("#modalBody").html("<p>No images found.</p>");
                    }
                }).fail(function() {
                    $("#modalBody").html("<p>Unable to fetch data. Please try again later.</p>");
                });
            }

            // Load section and publication dropdowns for Add Article form
            (function loadAddArticleDropdowns() {
                var fd = new FormData();
                fd.append('id', 0);
                fetch('/management/ajax/get_article_data.php', {
                    method: 'POST',
                    body: fd,
                    credentials: 'same-origin'
                })
                .then(function(r) { return r.json(); })
                .then(function(json) {
                    if (!json || json.status !== 'success') return;

                    // Sections follow the chosen publication(s): keep the per-pub
                    // map + union, and rebuild the dropdown from whichever pubs
                    // are ticked (union, deduped). Defined here so the pub
                    // checkboxes below can call it on change.
                    var addSectionsByPub = json.sections_by_pub || {};
                    var addAllSections = json.all_sections || [];
                    window._rebuildAddSections = function() {
                        var sel = document.getElementById('add_article_section');
                        var prev = sel.value;
                        var checked = [];
                        document.querySelectorAll('#add_article_publications input.add_pub_cb:checked').forEach(function(cb){ checked.push(cb.value); });
                        var list = [], seen = {};
                        if (checked.length && Object.keys(addSectionsByPub).length) {
                            checked.forEach(function(ed){
                                (addSectionsByPub[ed] || []).forEach(function(s){
                                    if (!seen[s.value]) { seen[s.value] = 1; list.push(s); }
                                });
                            });
                        }
                        if (!list.length) list = addAllSections;
                        list.sort(function(a,b){ return a.label.localeCompare(b.label); });
                        sel.innerHTML = '<option value="">Choose section...</option>';
                        list.forEach(function(s){
                            var opt = document.createElement('option');
                            opt.value = s.value; opt.textContent = s.label; sel.appendChild(opt);
                        });
                        if (prev && list.some(function(s){ return s.value === prev; })) sel.value = prev;
                        else if (list.length === 1) sel.value = list[0].value;
                    };
                    window._rebuildAddSections();

                    // Populate publications — checkbox + canonical radio, like the edit modal.
                    var pubContainer = document.getElementById('add_article_publications');
                    pubContainer.innerHTML = '';
                    if (json.all_publications && json.all_publications.length > 0) {
                        var only = (json.all_publications.length === 1);
                        json.all_publications.forEach(function(pub) {
                            var row = document.createElement('div');
                            row.style.cssText = 'display:grid;grid-template-columns:1fr auto;align-items:center;gap:16px;padding:8px 10px;border-bottom:1px solid #f0f0f0;';
                            var left = document.createElement('label');
                            left.style.cssText = 'display:flex;align-items:center;gap:8px;cursor:pointer;font-size:14px;color:#1e293b;';
                            var cb = document.createElement('input');
                            cb.type = 'checkbox'; cb.className = 'add_pub_cb'; cb.value = pub.name;
                            cb.id = 'add_pub_' + pub.name;
                            cb.checked = pub.selected || only;
                            cb.style.cssText = 'width:16px;height:16px;cursor:pointer;';
                            var span = document.createElement('span'); span.textContent = pub.title || pub.name;
                            left.appendChild(cb); left.appendChild(span);
                            var right = document.createElement('label');
                            right.style.cssText = 'display:flex;align-items:center;gap:6px;font-size:12px;color:#64748b;cursor:pointer;white-space:nowrap;';
                            var radio = document.createElement('input');
                            radio.type = 'radio'; radio.name = 'add_canonical_radio'; radio.className = 'add_pub_canonical'; radio.value = pub.name;
                            radio.style.cssText = 'width:15px;height:15px;cursor:pointer;';
                            radio.disabled = !cb.checked;
                            radio.checked = cb.checked && only;
                            var rlab = document.createElement('span'); rlab.textContent = 'Canonical';
                            right.appendChild(radio); right.appendChild(rlab);
                            cb.addEventListener('change', function(){
                                radio.disabled = !cb.checked;
                                if (!cb.checked && radio.checked) radio.checked = false;
                                if (window._rebuildAddSections) window._rebuildAddSections();
                            });
                            row.appendChild(left); row.appendChild(right);
                            pubContainer.appendChild(row);
                        });
                    }
                    // now that publication checkboxes exist, scope sections to the
                    // ticked publication(s).
                    if (window._rebuildAddSections) window._rebuildAddSections();

                    // Populate author dropdown
                    var authorSel = document.getElementById('add_article_author');
                    if (authorSel) {
                        authorSel.innerHTML = '<option value="">Select author (optional)...</option>';
                        if (json.all_journalists && json.all_journalists.length > 0) {
                            json.all_journalists.forEach(function(j) {
                                var opt = document.createElement('option');
                                opt.value = j.id;
                                opt.textContent = j.name;
                                authorSel.appendChild(opt);
                            });
                            // Hide wrapper if no choice (only one journalist = current user)
                            if (json.all_journalists.length === 1) {
                                authorSel.value = json.all_journalists[0].id;
                                document.getElementById('add_article_author_wrapper').style.display = 'none';
                            } else {
                                document.getElementById('add_article_author_wrapper').style.display = 'block';
                            }
                        } else {
                            document.getElementById('add_article_author_wrapper').style.display = 'none';
                        }
                    }
                })
                .catch(function(e) { console.error('Error loading article dropdowns:', e); });
            })();

            // Form submission — posts to the SAME create endpoint the edit modal uses
            // (ajax/save_article.php with a blank id => INSERT), so the fields here
            // (publications+canonical, flags, SEO) are saved identically.
            $("#ten_add_article").submit(function(event) {
                event.preventDefault();

                var title = ($('#article_title').val() || '').trim();
                var terms = $('#article_submission_terms_checkbox').is(":checked");
                var section = $('#add_article_section').val();

                if (!title) { Swal.fire('Error', 'Please enter a title for this article', 'error'); return; }
                if (!section) { Swal.fire('Error', 'Please select a section for this article', 'error'); return; }
                if (!terms) { Swal.fire('Error', 'You must agree to the Terms of Article Submission', 'error'); return; }

                var article_text = $("#article_text").html();
                if (!article_text || !article_text.trim()) { Swal.fire('Error', 'Please add some article content', 'error'); return; }

                var selectedPubs = [];
                $('#add_article_publications input.add_pub_cb:checked').each(function() { selectedPubs.push($(this).val()); });
                if (selectedPubs.length === 0) { Swal.fire('Error', 'Please select at least one publication', 'error'); return; }
                var canonical = $('#add_article_publications input.add_pub_canonical:checked').val() || selectedPubs[0];

                $('#loading_image').show();

                var fd = new FormData();
                fd.append('id', '');                    // blank => create
                fd.append('title', title);
                fd.append('article_text', article_text);
                fd.append('alias', '');
                fd.append('tags', $('#add_article_tags').val() || '');
                fd.append('section', section);
                fd.append('section_subcat', '');
                fd.append('author', $('#add_article_author').val() || '');
                fd.append('publications', selectedPubs.join(','));
                fd.append('canonical', canonical);
                fd.append('meta_title', $('#add_article_meta_title').val() || '');
                fd.append('meta_description', $('#add_article_meta_description').val() || '');
                fd.append('meta_keywords', $('#add_article_meta_keywords').val() || '');
                fd.append('evergreen', $('#add_article_evergreen').is(':checked') ? '1' : '0');
                fd.append('featured', $('#add_article_featured').is(':checked') ? '1' : '0');
                fd.append('sponsored', $('#add_article_sponsored').is(':checked') ? '1' : '0');
                fd.append('frontpage_temp', $('#add_article_headline').is(':checked') ? '1' : '0');
                fd.append('publish_now', '1');
                fd.append('publish_from', '');
                fd.append('publish_to', '');
                fd.append('action', (window._addArticleAction === 'submit') ? 'submit' : 'save'); // Save Draft or Submit for Review

                fetch('/management/ajax/save_article.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    $('#loading_image').hide();
                    if (data.status === 'success') {
                        if (typeof loadArticles === 'function') loadArticles();
                        Swal.fire({
                            icon: 'success',
                            title: 'Article Saved!',
                            html: '<p>Your article has been saved as a <strong>draft</strong>' + (data.article_id ? ' (#' + data.article_id + ')' : '') + '.</p>',
                            confirmButtonText: 'View All Articles',
                            showCancelButton: true,
                            cancelButtonText: 'Add Another'
                        }).then(function(result) {
                            if (result.isConfirmed) {
                                document.querySelector('.tab[data-tab="view-articles"]').click();
                            } else {
                                $('#article_title').val('');
                                $('#article_text').html('');
                                $('#add_article_meta_title,#add_article_meta_description,#add_article_meta_keywords,#add_article_tags').val('');
                                $('#add_article_headline,#add_article_featured,#add_article_evergreen,#add_article_sponsored').prop('checked', false);
                                $('#article_submission_terms_checkbox').prop('checked', true);
                            }
                        });
                    } else {
                        Swal.fire('Error', data.message || 'There was an error saving your article. Please try again.', 'error');
                    }
                })
                .catch(function(err) {
                    $('#loading_image').hide();
                    console.error('Save error:', err);
                    Swal.fire('Error', 'There was a network error saving your article. Please try again.', 'error');
                });
            });
        });

        // TAB 2: Image Upload and Cropper functionality
        (function() {
            const fileInputTab2 = document.getElementById('file-input-tab2');
            const imageTab2 = document.getElementById('image-tab2');
            const imageContainerTab2 = document.getElementById('image-container-tab2');
            const filenameDisplay = document.getElementById('filename-display');
            const pixabayApiKey = '<?php echo $apiKey; ?>';
            
            const cropperOptions = {
                aspectRatio: 490 / 310,
                viewMode: 1,
                autoCropArea: 1,
                responsive: true,
                background: false,
                zoomable: true,
                scalable: true,
                movable: true
            };
            let cropperTab2;

            /* ---- Inline free-image search (rendered in the tab, not a modal) ----
             * search text + source checkboxes -> thumbnail grid -> preview -> Select &
             * crop downloads the image (by index, SSRF-safe) and loads it straight into
             * this tab's existing cropper, then the normal Crop & Save saves to library. */
            const IMG_EP = 'ajax/image_search.php';
            function isEsc(s){ return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c];}); }
            function isPost(params){ const fd=new FormData(); Object.keys(params).forEach(k=>fd.append(k,params[k])); return fetch(IMG_EP,{method:'POST',body:fd,credentials:'same-origin'}).then(r=>r.json()); }
            let tab2Items = [];

            // Provider checkboxes (loaded once; only sources with a key show).
            isPost({action:'providers'}).then(function(j){
                const box=document.getElementById('tab2ISSources'); if(!box) return;
                const list=(j&&j.providers)||[];
                if(!list.length){ box.innerHTML='<span style="font-size:12px;color:#98a0ac;">No image sources configured.</span>'; return; }
                box.innerHTML='<span style="font-size:12px;font-weight:700;letter-spacing:.05em;text-transform:uppercase;color:#98a0ac;">Sources</span>'+
                    list.map(p=>'<label style="display:inline-flex;align-items:center;gap:6px;font-size:13px;color:#2b313b;font-weight:600;cursor:pointer;"><input type="checkbox" class="tab2ISProv" value="'+isEsc(p.key)+'" checked style="width:15px;height:15px;"> '+isEsc(p.label)+'</label>').join('');
            }).catch(function(){});

            function tab2RunSearch(){
                const q=(document.getElementById('tab2ISQuery').value||'').trim();
                if(!q){ document.getElementById('tab2ISQuery').focus(); return; }
                const provs=Array.prototype.map.call(document.querySelectorAll('.tab2ISProv:checked'),c=>c.value);
                if(!provs.length){ Swal.fire('Pick a source','Select at least one image source.','info'); return; }
                const res=document.getElementById('tab2ISResults');
                res.innerHTML='<div style="color:#6b7280;font-size:14px;padding:24px 0;"><i class="fas fa-spinner fa-spin"></i> Searching '+provs.length+' source'+(provs.length===1?'':'s')+'…</div>';
                isPost({action:'search',q:q,providers:provs.join(',')}).then(function(j){
                    if(j.status!=='success'){ res.innerHTML='<div style="color:#98a0ac;padding:24px 0;">'+isEsc(j.message||'Search failed.')+'</div>'; return; }
                    tab2Items=j.items||[]; tab2RenderGrid();
                }).catch(function(e){ res.innerHTML='<div style="color:#98a0ac;padding:24px 0;">Error: '+isEsc(e.message)+'</div>'; });
            }

            function tab2RenderGrid(){
                const res=document.getElementById('tab2ISResults');
                if(!tab2Items.length){ res.innerHTML='<div style="color:#98a0ac;padding:24px 0;">No images found — try different words or more sources.</div>'; return; }
                res.innerHTML='<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:12px;">'+
                    tab2Items.map(function(it){
                        return '<div class="tab2ISCell" data-i="'+it.index+'" style="border:1px solid #e6e8ec;border-radius:10px;overflow:hidden;cursor:pointer;background:#fff;">'+
                            '<img loading="lazy" src="'+isEsc(it.thumb)+'" alt="'+isEsc(it.title||'')+'" style="display:block;width:100%;height:120px;object-fit:cover;background:#f1f5f9;">'+
                            '<div style="font-size:11px;font-weight:700;color:#6b7280;padding:6px 8px;text-align:center;text-transform:uppercase;letter-spacing:.02em;">'+isEsc(it.provider_label||it.provider)+'</div>'+
                        '</div>';
                    }).join('')+'</div>';
                Array.prototype.forEach.call(res.querySelectorAll('.tab2ISCell'),function(c){ c.addEventListener('click',function(){ tab2ShowPreview(parseInt(c.getAttribute('data-i'),10)); }); });
            }

            function tab2ItemByIndex(i){ for(let k=0;k<tab2Items.length;k++) if(tab2Items[k].index===i) return tab2Items[k]; return null; }

            function tab2ShowPreview(i){
                const it=tab2ItemByIndex(i); if(!it) return;
                const res=document.getElementById('tab2ISResults');
                const srcLink=it.source_page?'<div style="font-size:13px;margin-bottom:8px;"><a href="'+isEsc(it.source_page)+'" target="_blank" rel="noopener" style="color:#15181e;font-weight:600;">View on '+isEsc(it.provider_label||it.provider)+' ↗</a></div>':'';
                res.innerHTML='<div style="display:flex;gap:20px;flex-wrap:wrap;">'+
                    '<div style="flex:1;min-width:280px;"><img src="'+isEsc(it.thumb)+'" alt="'+isEsc(it.title||'')+'" style="width:100%;border-radius:10px;border:1px solid #e6e8ec;"></div>'+
                    '<div style="flex:1;min-width:220px;">'+
                        '<span style="display:inline-block;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:#b45309;background:#fff7ed;border:1px solid #fbe3c6;padding:3px 10px;border-radius:999px;margin-bottom:12px;">'+isEsc(it.provider_label||it.provider)+'</span>'+
                        (it.title?'<h4 style="font-size:16px;font-weight:600;color:#15181e;margin:0 0 10px;line-height:1.4;">'+isEsc(it.title)+'</h4>':'')+
                        (it.attribution?'<div style="font-size:13px;color:#475467;margin-bottom:8px;"><b style="color:#15181e;">Attribution:</b> '+isEsc(it.attribution)+'</div>':'')+
                        (it.license?'<div style="font-size:13px;color:#475467;margin-bottom:8px;"><b style="color:#15181e;">Licence:</b> '+isEsc(it.license)+'</div>':'')+
                        srcLink+
                        '<div style="display:flex;gap:10px;margin-top:18px;">'+
                            '<button type="button" class="btn btn-primary" id="tab2ISCrop"><i class="fas fa-crop-alt"></i> Select &amp; crop</button>'+
                            '<button type="button" class="btn" id="tab2ISBack" style="background:#fff;color:#2b313b;border:1px solid #d3d8e0;"><i class="fas fa-arrow-left"></i> Back to results</button>'+
                        '</div>'+
                    '</div>'+
                '</div>';
                document.getElementById('tab2ISCrop').addEventListener('click',function(){ tab2SelectCrop(it); });
                document.getElementById('tab2ISBack').addEventListener('click',tab2RenderGrid);
            }

            function tab2SelectCrop(it){
                Swal.fire({title:'Fetching image…',allowOutsideClick:false,didOpen:function(){Swal.showLoading();}});
                isPost({action:'download',index:it.index}).then(function(d){
                    Swal.close();
                    if(d.status!=='success'){ Swal.fire('Error',d.message||'Download failed','error'); return; }
                    document.getElementById('attribution-tab2').value = d.attribution || it.attribution || '';
                    imageTab2.src = d.dataUrl;                       // data URI → same-origin, no canvas taint
                    imageContainerTab2.style.display = 'block';
                    document.getElementById('preview-tab2').style.display = 'none';
                    if (cropperTab2) { cropperTab2.destroy(); }
                    cropperTab2 = new Cropper(imageTab2, cropperOptions);
                    imageContainerTab2.scrollIntoView({behavior:'smooth',block:'nearest'});
                }).catch(function(e){ Swal.close(); Swal.fire('Error',e.message,'error'); });
            }

            document.getElementById('tab2ISSearchBtn').addEventListener('click',tab2RunSearch);
            document.getElementById('tab2ISQuery').addEventListener('keydown',function(e){ if(e.key==='Enter'){ e.preventDefault(); tab2RunSearch(); } });
            const tab2UploadLink=document.getElementById('tab2UploadLink');
            if(tab2UploadLink) tab2UploadLink.addEventListener('click',function(e){ e.preventDefault(); document.getElementById('file-input-tab2').click(); });

            // Pixabay search functionality
            $('#pixabay-search-form-tab2').on('submit', function(e) {
                e.preventDefault();
                const query = $('#pixabay-query-tab2').val().trim();
                if (query) {
                    searchPixabayTab2(query);
                }
            });

            function searchPixabayTab2(query) {
                $('#pixabay-loading-tab2').show();
                $('#pixabay-results-tab2').html('');
                $('#pixabay-no-results-tab2').hide();

                const apiUrl = `https://pixabay.com/api/?key=${pixabayApiKey}&q=${encodeURIComponent(query)}&per_page=24&image_type=photo`;
                
                $.get(apiUrl, function(data) {
                    $('#pixabay-loading-tab2').hide();
                    
                    if (data.hits && data.hits.length > 0) {
                        let html = '';
                        data.hits.forEach(image => {
                            if (image.webformatWidth >= 490) {
                                html += `
                                    <div class="pixabay-image-item" style="cursor: pointer; border: 2px solid #e5e7eb; border-radius: 8px; overflow: hidden; transition: all 0.2s;" 
                                         data-url="${image.largeImageURL}" 
                                         data-user="${image.user}"
                                         data-page="${image.pageURL}">
                                        <img src="${image.webformatURL}" style="width: 100%; height: 150px; object-fit: cover;">
                                        <div style="padding: 8px; background: #fff;">
                                            <p style="font-size: 11px; color: #6b7280; margin: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;">
                                                <i class="fas fa-user"></i> ${image.user}
                                            </p>
                                        </div>
                                    </div>
                                `;
                            }
                        });
                        $('#pixabay-results-tab2').html(html);
                        
                        // Add click handlers to images
                        $('.pixabay-image-item').hover(
                            function() { $(this).css('border-color', '#667eea'); },
                            function() { $(this).css('border-color', '#e5e7eb'); }
                        );
                        
                        $('.pixabay-image-item').click(function() {
                            const imageUrl = $(this).data('url');
                            const userName = $(this).data('user');
                            const pageUrl = $(this).data('page');
                            loadPixabayImageToCanvas(imageUrl, userName, pageUrl);
                        });
                    } else {
                        $('#pixabay-no-results-tab2').show();
                    }
                }).fail(function() {
                    $('#pixabay-loading-tab2').hide();
                    Swal.fire('Error', 'Failed to search Pixabay. Please try again.', 'error');
                });
            }

            function loadPixabayImageToCanvas(imageUrl, userName, pageUrl) {
                $('#pixabay-loading-tab2').show();
                
                // Download image via proxy to avoid CORS issues
                fetch('download_pixabay_image.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ url: imageUrl })
                })
                .then(response => response.json())
                .then(data => {
                    $('#pixabay-loading-tab2').hide();
                    
                    if (data.status === 'success') {
                        // Set attribution automatically
                        const attribution = `Photo by ${userName} from Pixabay - ${pageUrl}`;
                        $('#attribution-tab2').val(attribution);
                        
                        // Load image into canvas
                        imageTab2.src = data.dataUrl;
                        imageContainerTab2.style.display = 'block';
                        document.getElementById('preview-tab2').style.display = 'none';
                        filenameDisplay.textContent = 'Pixabay Image';
                        
                        // Hide Pixabay section and show canvas
                        $('#pixabay-search-section').hide();
                        
                        if (cropperTab2) {
                            cropperTab2.destroy();
                        }
                        cropperTab2 = new Cropper(imageTab2, cropperOptions);
                        
                        Swal.fire({
                            icon: 'success',
                            title: 'Image Loaded',
                            text: 'You can now crop and save the image',
                            timer: 2000,
                            showConfirmButton: false
                        });
                    } else {
                        Swal.fire('Error', data.message || 'Failed to load image', 'error');
                    }
                })
                .catch(error => {
                    $('#pixabay-loading-tab2').hide();
                    console.error('Error:', error);
                    Swal.fire('Error', 'Failed to load image from Pixabay', 'error');
                });
            }

            fileInputTab2.addEventListener('change', (e) => {
                const file = e.target.files[0];
                if (file) {
                    filenameDisplay.textContent = file.name;
                    const reader = new FileReader();
                    reader.onload = (event) => {
                        imageTab2.src = event.target.result;
                        imageContainerTab2.style.display = 'block';
                        document.getElementById('preview-tab2').style.display = 'none';
                        
                        if (cropperTab2) {
                            cropperTab2.destroy();
                        }
                        cropperTab2 = new Cropper(imageTab2, cropperOptions);
                    };
                    reader.readAsDataURL(file);
                }
            });

            document.getElementById('crop-save-btn-tab2').addEventListener('click', () => {
                if (!cropperTab2) {
                    Swal.fire('Error', 'Please upload an image first', 'error');
                    return;
                }

                const attribution = document.getElementById('attribution-tab2').value;
                const croppedCanvas = cropperTab2.getCroppedCanvas({
                    width: 490,
                    height: 310,
                    fillColor: '#ffffff',
                    imageSmoothingEnabled: true,
                    imageSmoothingQuality: 'high'
                });

                const croppedImage = croppedCanvas.toDataURL('image/jpeg', 0.95);
                
                $("#loading-tab2").show();
                
                fetch('crop_save_image.php', {
                    method: 'POST',
                    body: JSON.stringify({ 
                        image: croppedImage, 
                        attribution: attribution 
                    }),
                    headers: { 'Content-Type': 'application/json' },
                })
                .then((response) => response.json())
                .then((data) => {
                    $("#loading-tab2").hide();
                    
                    if (data.status === 'success') {
                        document.getElementById('preview-content-tab2').innerHTML = `
                            <div style="text-align: center;">
                                <img src="${data.url}" alt="Saved Image" style="max-width: 100%; border-radius: 8px; margin-bottom: 15px;">
                                <p style="color: #374151; font-weight: 600;">Image URL: <a href="${data.url}" target="_blank">${data.url}</a></p>
                                ${attribution ? `<p style="color: #6b7280;"><em>Attribution: ${attribution}</em></p>` : ''}
                            </div>
                        `;
                        document.getElementById('preview-tab2').style.display = 'block';
                        document.getElementById('image-container-tab2').style.display = 'none';
                        
                        // Update the image browser in Tab 1 modal
                        if (data.image_html) {
                            const imageBrowserContainer = document.getElementById('image_block_container');
                            if (imageBrowserContainer) {
                                const tempDiv = document.createElement('div');
                                tempDiv.innerHTML = data.image_html;
                                imageBrowserContainer.insertBefore(tempDiv.firstChild, imageBrowserContainer.firstChild);
                            }
                        }
                        
                        Swal.fire('Success', 'Image saved successfully!', 'success');
                    } else {
                        Swal.fire('Error', data.message || 'Failed to save image', 'error');
                    }
                })
                .catch((error) => {
                    $("#loading-tab2").hide();
                    console.error('Error:', error);
                    Swal.fire('Error', 'Failed to upload image. Please try again.', 'error');
                });
            });

            document.getElementById('clear-image-tab2').addEventListener('click', () => {
                if (cropperTab2) {
                    cropperTab2.destroy();
                    cropperTab2 = null;
                }
                imageTab2.src = '';
                document.getElementById('attribution-tab2').value = '';
                document.getElementById('preview-tab2').style.display = 'none';
                imageContainerTab2.style.display = 'none';
                fileInputTab2.value = '';
                filenameDisplay.textContent = '';
                $('#pixabay-search-section').show();
                $('#pixabay-results-tab2').html('');
                $('#pixabay-query-tab2').val('');
            });
        })();
    </script>

    <!-- Small keyframes for spinner (required inline via <style>) -->
    <style>
        @keyframes spin { from { transform: rotate(0deg); } to { transform: rotate(360deg); } }
    </style>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        // State
        let currentPage = 1;
        let currentSortCol = 'submission_date';
        let currentSortDir = 'DESC';
        let currentSearch = '';
        const limit = 10;
        const maxPageLinks = 10;
        
        // Filter state
        let filterUser = '';
        let filterSection = '';
        let filterPublication = '';
        let filterDateFrom = '';
        let filterDateTo = '';
        let filterHideImageless = false;
        let filterState = '';
        let filterScraped = false;

        // Elements
        const tbody = document.querySelector('#articlesTable tbody');
        const paginationUL = document.getElementById('pagination');
        const searchInput = document.getElementById('articleSearch');
        const spinner = document.getElementById('loadingSpinner');
        const noResults = document.getElementById('noResults');

        // Helpers
        function showSpinner(show) {
            spinner.style.display = show ? 'block' : 'none';
        }

        function safeText(s) {
            if (s === null || s === undefined) return '';
            return String(s)
                .replace(/&/g,"&amp;")
                .replace(/</g,"&lt;")
                .replace(/>/g,"&gt;");
        }

        function buildBadge(name) {
            // small inline badge style
            return '<span style="display:inline-block;background:#0d6efd;color:#fff;padding:3px 8px;border-radius:999px;font-size:12px;margin-right:6px;text-decoration:none;">'
                + safeText(name) + '</span>';
        }

        function buildBadgeLink(name, url) {
            return '<a href="' + safeText(url) + '" target="_blank" style="display:inline-block;background:#0d6efd;color:#fff;padding:3px 8px;border-radius:999px;font-size:12px;margin-right:6px;text-decoration:none;">'
                + safeText(name) + '</a>';
        }

        // Fetch & render
        function loadArticles() {
            showSpinner(true);
            noResults.style.display = 'none';
            tbody.innerHTML = '';

            const q = '/management/ajax/get_articles.php?page=' + currentPage +
                      '&limit=' + limit +
                      '&search=' + encodeURIComponent(currentSearch) +
                      '&sort_col=' + encodeURIComponent(currentSortCol) +
                      '&sort_dir=' + encodeURIComponent(currentSortDir) +
                      '&user=' + encodeURIComponent(filterUser || '') +
                      '&section=' + encodeURIComponent(filterSection || '') +
                      '&publication=' + encodeURIComponent(filterPublication || '') +
                      '&date_from=' + encodeURIComponent(filterDateFrom || '') +
                      '&date_to=' + encodeURIComponent(filterDateTo || '') +
                      '&state=' + encodeURIComponent(filterState || '') +
                      '&scraped=' + (filterScraped ? '1' : '') +
                      '&hide_imageless=' + (filterHideImageless ? '1' : '');

            fetch(q)
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    showSpinner(false);

                    if (!data || !Array.isArray(data.results)) {
                        tbody.innerHTML = '<tr><td colspan="7" style="padding:12px;text-align:center;color:#dc3545;">Error loading data</td></tr>';
                        paginationUL.innerHTML = '';
                        return;
                    }

                    renderTable(data.results);
                    renderPagination((data.total || 0), (data.page || 1), (data.limit || limit));
                })
                .catch(function (err) {
                    showSpinner(false);
                    tbody.innerHTML = '<tr><td colspan="7" style="padding:12px;text-align:center;color:#dc3545;">Error loading data</td></tr>';
                    paginationUL.innerHTML = '';
                    console.error(err);
                });
        }

        function renderTable(rows) {
            tbody.innerHTML = '';

            if (!rows || rows.length === 0) {
                noResults.style.display = 'block';
                return;
            }
            noResults.style.display = 'none';

            rows.forEach(function (row, idx) {
                // Publication badges - show just the names cleanly
                let pubsHtml = '';
                if (Array.isArray(row.publication_links) && row.publication_links.length) {
                    row.publication_links.forEach(function (p) {
                        pubsHtml += '<span style="display:inline-block;background:#dbeafe;color:#1d4ed8;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;margin-right:4px;margin-bottom:2px;letter-spacing:0.3px;text-transform:uppercase;">' + safeText(p.name) + '</span>';
                    });
                } else if (row.publications) {
                    row.publications.split(',').forEach(function(p) {
                        p = p.trim();
                        if (p) pubsHtml += '<span style="display:inline-block;background:#dbeafe;color:#1d4ed8;padding:2px 8px;border-radius:4px;font-size:11px;font-weight:600;margin-right:4px;margin-bottom:2px;letter-spacing:0.3px;text-transform:uppercase;">' + safeText(p) + '</span>';
                    });
                }

                // Status badge
                let stateBg = '#64748b', stateColor = '#fff', stateLabel = row.state;
                if (row.state === 'draft') { stateBg = '#fee2e2'; stateColor = '#991b1b'; stateLabel = 'Draft'; }
                else if (row.state === 'under review') { stateBg = '#fef3c7'; stateColor = '#92400e'; stateLabel = 'In Review'; }
                else if (row.state === 'published') { stateBg = '#dcfce7'; stateColor = '#166534'; stateLabel = 'Published'; }
                else if (row.state === 'expired') { stateBg = '#f3f4f6'; stateColor = '#6b7280'; stateLabel = 'Expired'; }

                const statusBadge = '<span style="display:inline-flex;align-items:center;gap:4px;background:' + stateBg + ';color:' + stateColor + ';padding:3px 10px;border-radius:999px;font-size:11px;font-weight:700;white-space:nowrap;">' +
                    '<span style="width:6px;height:6px;border-radius:50%;background:' + stateColor + ';display:inline-block;"></span>' +
                    stateLabel + '</span>';

                // Edit button
                const editBtn = row.editable == 1
                    ? '<button type="button" onclick="openEdit(' + row.id + ')" style="border:none;background:#3b82f6;color:#fff;padding:6px 10px;border-radius:6px;cursor:pointer;font-size:12px;display:inline-flex;align-items:center;gap:4px;font-weight:500;" title="Edit article"><i class="fas fa-pencil-alt"></i> Edit</button>'
                    : '';

                // Smart view button: preview for unpublished, live for published
                let viewBtn = '';
                if (row.state === 'published' && row.live_url) {
                    viewBtn = '<a href="' + safeText(row.live_url) + '" target="_blank" style="display:inline-flex;align-items:center;gap:4px;background:#10b981;color:#fff;padding:6px 10px;border-radius:6px;cursor:pointer;font-size:12px;font-weight:500;text-decoration:none;" title="View live article"><i class="fas fa-external-link-alt"></i> Live</a>';
                } else if (row.preview_url) {
                    viewBtn = '<a href="' + safeText(row.preview_url) + '" target="_blank" style="display:inline-flex;align-items:center;gap:4px;background:#6b7280;color:#fff;padding:6px 10px;border-radius:6px;cursor:pointer;font-size:12px;font-weight:500;text-decoration:none;" title="Preview article"><i class="fas fa-eye"></i> Preview</a>';
                }

                // Format date nicely
                let dateStr = row.submission_date || '';
                if (dateStr) {
                    try {
                        const d = new Date(dateStr.replace(' ', 'T'));
                        dateStr = d.toLocaleDateString('en-GB', {day:'2-digit', month:'short', year:'numeric'});
                    } catch(e) {}
                }

                // Author: show just name without username in parens if too long
                let authorStr = safeText(row.journalist_name || '');
                const parenIdx = authorStr.indexOf(' (');
                if (parenIdx > 0) authorStr = authorStr.substring(0, parenIdx);

                const tr = document.createElement('tr');
                tr.style.cssText = 'border-bottom:1px solid #f1f5f9;transition:background 0.1s;';
                tr.style.background = idx % 2 === 0 ? '#ffffff' : '#f8fafc';
                tr.addEventListener('mouseenter', function() { this.style.background = '#eff6ff'; });
                tr.addEventListener('mouseleave', function() { this.style.background = idx % 2 === 0 ? '#ffffff' : '#f8fafc'; });

                tr.innerHTML =
                    '<td style="padding:12px 10px;vertical-align:middle;width:55px;font-size:12px;color:#94a3b8;font-weight:600;">' + safeText(row.id) + '</td>' +
                    '<td style="padding:12px 10px;vertical-align:middle;max-width:320px;"><span style="font-size:13px;font-weight:600;color:#1e293b;line-height:1.4;display:block;">' + safeText(row.title) + '</span></td>' +
                    '<td style="padding:12px 10px;vertical-align:middle;width:150px;"><span style="font-size:12px;color:#475569;">' + authorStr + '</span></td>' +
                    '<td style="padding:12px 10px;vertical-align:middle;width:130px;">' + pubsHtml + '</td>' +
                    '<td style="padding:12px 10px;vertical-align:middle;width:110px;">' + statusBadge + '</td>' +
                    '<td style="padding:12px 10px;vertical-align:middle;width:140px;font-size:12px;color:#64748b;white-space:nowrap;">' + dateStr + '</td>' +
                    '<td style="padding:12px 10px;vertical-align:middle;text-align:center;width:120px;">' +
                        '<div style="display:flex;gap:6px;justify-content:center;flex-wrap:nowrap;">' + editBtn + viewBtn + '</div>' +
                    '</td>';

                tbody.appendChild(tr);
            });
        }

        // Pagination builder: max maxPageLinks displayed, sliding window
        function renderPagination(total, page, limitPerPage) {
            paginationUL.innerHTML = '';

            const totalPages = Math.ceil(total / limitPerPage);
            if (totalPages <= 1) return;

            // Normalize page
            if (page < 1) page = 1;
            if (page > totalPages) page = totalPages;

            // Window
            let half = Math.floor(maxPageLinks / 2);
            let start = Math.max(1, page - half);
            let end = Math.min(totalPages, start + maxPageLinks - 1);

            if ((end - start + 1) < maxPageLinks) {
                start = Math.max(1, end - maxPageLinks + 1);
            }

            // Helper to create li
            function createLi(label, target, disabled, active) {
                const li = document.createElement('li');
                li.setAttribute('role', 'button');
                li.style.listStyle = 'none';
                li.style.margin = '0 4px';

                const a = document.createElement('a');
                a.textContent = label;
                a.href = 'javascript:void(0)';
                a.style.display = 'inline-block';
                a.style.minWidth = '40px';
                a.style.padding = '6px 10px';
                a.style.borderRadius = '6px';
                a.style.textDecoration = 'none';
                a.style.border = '1px solid #dee2e6';
                a.style.color = '#0d6efd';
                a.style.background = '#fff';
                a.style.fontSize = '14px';
                a.style.textAlign = 'center';
                a.style.cursor = disabled ? 'default' : 'pointer';
                if (active) {
                    a.style.background = '#0d6efd';
                    a.style.color = '#fff';
                    a.style.border = '1px solid #0d6efd';
                    a.style.fontWeight = '600';
                }
                if (disabled) {
                    a.style.opacity = '0.6';
                    a.style.pointerEvents = 'none';
                } else {
                    a.addEventListener('click', function () {
                        currentPage = target;
                        loadArticles();
                        window.scrollTo({ top: 0, behavior: 'smooth' });
                    });
                }

                li.appendChild(a);
                return li;
            }

            // First, Prev
            paginationUL.appendChild(createLi('«', 1, page === 1, false));
            paginationUL.appendChild(createLi('‹', Math.max(1, page - 1), page === 1, false));

            // Page numbers
            for (let p = start; p <= end; p++) {
                paginationUL.appendChild(createLi(p, p, false, p === page));
            }

            // Next, Last
            paginationUL.appendChild(createLi('›', Math.min(totalPages, page + 1), page === totalPages, false));
            paginationUL.appendChild(createLi('»', totalPages, page === totalPages, false));
        }

        // Toggle advanced filters
        window.toggleAdvancedFilters = function() {
            const filters = document.getElementById('advancedFilters');
            const chevron = document.getElementById('filterChevron');
            
            if (filters.style.display === 'none' || filters.style.display === '') {
                filters.style.display = 'block';
                chevron.style.transform = 'rotate(180deg)';
            } else {
                filters.style.display = 'none';
                chevron.style.transform = 'rotate(0deg)';
            }
        };

        // Apply filters
        window.applyFilters = function() {
            filterUser = document.getElementById('filterUser').value;
            filterSection = document.getElementById('filterSection').value;
            filterPublication = document.getElementById('filterPublication').value;
            filterState = document.getElementById('filterState').value;
            filterDateFrom = document.getElementById('filterDateFrom').value;
            filterDateTo = document.getElementById('filterDateTo').value;
            filterHideImageless = document.getElementById('filterHideImageless').checked;
            filterScraped = document.getElementById('filterScraped').checked;
            
            currentPage = 1;
            loadArticles();
        };

        // Clear filters
        window.clearFilters = function() {
            document.getElementById('filterUser').value = '';
            document.getElementById('filterSection').value = '';
            document.getElementById('filterPublication').value = '';
            document.getElementById('filterState').value = '';
            document.getElementById('filterDateFrom').value = '';
            document.getElementById('filterDateTo').value = '';
            document.getElementById('filterHideImageless').checked = false;
            document.getElementById('filterScraped').checked = false;

            filterUser = '';
            filterSection = '';
            filterPublication = '';
            filterState = '';
            filterDateFrom = '';
            filterDateTo = '';
            filterHideImageless = false;
            filterScraped = false;
            
            currentPage = 1;
            loadArticles();
        };

        // Load filter dropdowns - use same approach as edit modal
        function loadFilterDropdowns() {
            const fd = new FormData();
            fd.append('id', 0); // Use id=0 to get ONLY dropdown data without article
            
            fetch('/management/ajax/get_article_data.php', {
                method: 'POST',
                body: fd,
                credentials: 'same-origin'
            })
                .then(r => r.json())
                .then(json => {
                    console.log('Filter dropdowns data:', json);
                    
                    // Populate users dropdown - using all_journalists
                    const userSelect = document.getElementById('filterUser');
                    if (userSelect && json.all_journalists && json.all_journalists.length > 0) {
                        json.all_journalists.forEach(function(journalist) {
                            const option = document.createElement('option');
                            option.value = journalist.id;
                            option.textContent = journalist.name;
                            userSelect.appendChild(option);
                        });
                        console.log('Populated filterUser with', json.all_journalists.length, 'journalists');
                    } else {
                        console.error('No journalists data received');
                    }
                    
                    // Populate sections dropdown - using all_sections
                    const sectionSelect = document.getElementById('filterSection');
                    if (sectionSelect && json.all_sections && json.all_sections.length > 0) {
                        json.all_sections.forEach(function(section) {
                            const option = document.createElement('option');
                            option.value = section.value;
                            option.textContent = section.label;
                            sectionSelect.appendChild(option);
                        });
                        console.log('Populated filterSection with', json.all_sections.length, 'sections');
                    } else {
                        console.error('No sections data received');
                    }
                    
                    // Populate publications dropdown - using all_publications
                    const pubSelect = document.getElementById('filterPublication');
                    if (pubSelect && json.all_publications && json.all_publications.length > 0) {
                        json.all_publications.forEach(function(pub) {
                            const option = document.createElement('option');
                            option.value = pub.name;
                            option.textContent = pub.title || pub.name;
                            pubSelect.appendChild(option);
                        });
                        console.log('Populated filterPublication with', json.all_publications.length, 'publications');
                    } else {
                        console.error('No publications data received');
                    }
                })
                .catch(err => console.error('Error loading filter dropdowns:', err));
        }
        
        loadFilterDropdowns();

        // Auto-trigger filters on change
        ['filterUser', 'filterSection', 'filterPublication', 'filterState'].forEach(function(id) {
            const el = document.getElementById(id);
            if (el) el.addEventListener('change', applyFilters);
        });
        const filterDateFromEl = document.getElementById('filterDateFrom');
        const filterDateToEl = document.getElementById('filterDateTo');
        const filterHideImagelessEl = document.getElementById('filterHideImageless');
        const filterScrapedEl = document.getElementById('filterScraped');
        if (filterDateFromEl) filterDateFromEl.addEventListener('change', applyFilters);
        if (filterDateToEl) filterDateToEl.addEventListener('change', applyFilters);
        if (filterHideImagelessEl) filterHideImagelessEl.addEventListener('change', applyFilters);
        if (filterScrapedEl) filterScrapedEl.addEventListener('change', applyFilters);

        // Sorting attach to headers
        function initSorting() {
            const ths = document.querySelectorAll('#articlesTable thead th.sortable');
            ths.forEach(function (th) {
                th.style.cursor = 'pointer';
                th.setAttribute('aria-role', 'button');

                th.addEventListener('click', function () {
                    const col = th.dataset.col;
                    if (!col) return;

                    if (currentSortCol === col) {
                        currentSortDir = (currentSortDir === 'ASC') ? 'DESC' : 'ASC';
                    } else {
                        currentSortCol = col;
                        currentSortDir = 'ASC';
                    }

                    // update arrows
                    document.querySelectorAll('#articlesTable .sort-arrow').forEach(function (sp) { sp.textContent = ''; });
                    const span = th.querySelector('.sort-arrow');
                    if (span) span.textContent = (currentSortDir === 'ASC') ? '▲' : '▼';

                    // reload
                    currentPage = 1;
                    loadArticles();
                });
            });
        }

        // Debounce for search
        function debounce(fn, delay) {
            let t;
            return function () {
                const args = arguments;
                clearTimeout(t);
                t = setTimeout(function () { fn.apply(null, args); }, delay);
            };
        }

        searchInput.addEventListener('input', debounce(function (e) {
            currentSearch = e.target.value.trim();
            currentPage = 1;
            loadArticles();
        }, 350));

        // Expose loadArticles globally so the edit-modal IIFE can call it after save
        window.loadArticles = loadArticles;

        // Init
        initSorting();
        loadArticles();
    });

    (function () {
    // Ensure openEdit is available immediately for onclick handlers
    if (typeof window.openEdit === 'undefined') {
        window.openEdit = function(id) {
            console.log('openEdit called before initialization, waiting...');
            setTimeout(function() { window.openEdit(id); }, 100);
        };
    }
    
    // Modal element refs
    const modal = document.getElementById('articleEditModal');
    const modalDialog = document.getElementById('articleEditModalDialog');
    const modalArticleIdSpan = document.getElementById('modalArticleId');
    const closeBtn = document.getElementById('closeArticleModal');
    const cancelBtn = document.getElementById('cancelBtn');
    const saveDraftBtn = document.getElementById('saveDraftBtn');
    const submitReviewBtn = document.getElementById('submitReviewBtn');
    const publishBtn = document.getElementById('publishBtn');
    const deleteArticleBtn = document.getElementById('deleteArticleBtn');
    const fullscreenBtn = document.getElementById('modal_fullscreen_toggle');

    // Inputs & containers
    const fld_title = document.getElementById('modal_title');
    const fld_alias = document.getElementById('modal_alias');
    const fld_tags = document.getElementById('modal_tags');
    const fld_section = document.getElementById('modal_section');
    const fld_section_subcat = document.getElementById('modal_section_subcat');
    const fld_author = document.getElementById('modal_author');
    const pub_container = document.getElementById('modal_publications_container');
    const fld_evergreen = document.getElementById('modal_evergreen');
    const fld_featured = document.getElementById('modal_featured');
    const fld_sponsored = document.getElementById('modal_sponsored');
    const fld_headline = document.getElementById('modal_headline_checkbox');
    const fld_publish_from = document.getElementById('modal_publish_from');
    const fld_publish_to = document.getElementById('modal_publish_to');
    const fld_publish_now = document.getElementById('modal_publish_now');
    const fld_meta_title = document.getElementById('modal_meta_title');
    const fld_meta_description = document.getElementById('modal_meta_description');
    const fld_meta_keywords = document.getElementById('modal_meta_keywords');
    const save_status = document.getElementById('modal_save_status');

    const editorSlot = document.getElementById('modal_editor_slot');

    let activeArticleId = null;
    let isCreateMode = false;
    let movedEditor = false;
    let originalEditor = null;
    let originalEditorParent = null;
    let originalEditorNextSibling = null;
    let placeholderNode = null;
    let cloneUsed = false;
    let isFullscreen = false;

    // Reset state if editor was restored externally (e.g., tab switch)
    document.addEventListener('editorRestored', function() {
        movedEditor = false;
        originalEditor = null;
        placeholderNode = null;
    });

    // Utility: find the original bespoke editor container on page
    function findOriginalEditor() {
        return document.querySelector('.editor-container');
    }

    // Safe move: move original editor DOM into modal slot, storing a placeholder to restore back
    function moveEditorIntoModal() {
        const orig = findOriginalEditor();
        if (!orig) return false;

        // If editor is already moved
        if (orig.dataset.__moved === '1') return true;

        // create placeholder
        placeholderNode = document.createElement('div');
        placeholderNode.style.display = 'none';
        orig.parentNode.insertBefore(placeholderNode, orig);

        // remember original parent and next sibling
        originalEditorParent = placeholderNode.parentNode;
        originalEditorNextSibling = placeholderNode.nextSibling;

        // append editor into modal slot
        editorSlot.appendChild(orig);
        orig.dataset.__moved = '1';
        movedEditor = true;
        originalEditor = orig;
        cloneUsed = false;
        return true;
    }

    // Restore moved editor back to its original place
    function restoreMovedEditor() {
        if (!movedEditor || !originalEditor) return;
        originalEditor.removeAttribute('data-__moved');
        // put it back before placeholder (or at end)
        if (placeholderNode && placeholderNode.parentNode) {
            placeholderNode.parentNode.insertBefore(originalEditor, placeholderNode);
            placeholderNode.parentNode.removeChild(placeholderNode);
        } else if (originalEditorParent) {
            originalEditorParent.appendChild(originalEditor);
        }
        movedEditor = false;
        originalEditor = null;
        placeholderNode = null;
    }

    // Clone fallback: clone editor node into modal slot (best-effort)
    function cloneEditorToModal() {
        const orig = findOriginalEditor();
        if (!orig) return false;
        const clone = orig.cloneNode(true);
        // attempt to call an init function if your bespoke editor provides it
        // common names: initCustomEditor, editorInit, initEditor
        try {
            editorSlot.appendChild(clone);
            if (typeof window.initCustomEditor === 'function') {
                window.initCustomEditor(clone);
            } else if (typeof window.editorInit === 'function') {
                window.editorInit(clone);
            } else if (typeof window.initEditor === 'function') {
                window.initEditor(clone);
            }
            cloneUsed = true;
            return true;
        } catch (e) {
            // append anyway
            editorSlot.appendChild(clone);
            cloneUsed = true;
            return true;
        }
    }

    // Cleanup cloned editor
    function cleanupClonedEditor() {
        if (!cloneUsed) return;
        // remove everything inside slot (assuming clone is located there)
        while (editorSlot.firstChild) {
            editorSlot.removeChild(editorSlot.firstChild);
        }
        cloneUsed = false;
    }

    // Show/hide modal
    function showModal() {
        modal.style.display = 'block';
        document.body.style.overflow = 'hidden';
        // focus trap init
        trapFocus(modal);
    }

    function hideModal() {
        modal.style.display = 'none';
        document.body.style.overflow = '';
        
        // Reset source view if active
        if (isSourceView) {
            const editorContainer = modal.querySelector('.editor-container');
            if (editorContainer) {
                const editor = editorContainer.querySelector('.editor, #article_text[contenteditable="true"]');
                const sourceView = editorContainer.querySelector('.source-view, #source-view');
                const toggleBtn = editorContainer.querySelector('#toggle-source');
                
                if (editor && sourceView && toggleBtn) {
                    editor.classList.remove('hidden');
                    editor.style.display = 'block';
                    sourceView.classList.remove('active');
                    sourceView.style.display = 'none';
                    toggleBtn.innerHTML = '<i class="fas fa-code"></i>';
                }
            }
            isSourceView = false;
        }
        
        // restore editor if moved
        if (movedEditor) {
            restoreMovedEditor();
        }
        // cleanup clone
        if (cloneUsed) {
            cleanupClonedEditor();
        }
        // Strict reset: empty the shared editor so no article content lingers in the
        // Add Article tab or the next modal open (prevents cross-context contamination).
        const etReset = document.getElementById('article_text');
        if (etReset) etReset.innerHTML = '';
        activeArticleId = null;
        isCreateMode = false;
        releaseFocusTrap();
    }

    // Focus trap minimal implementation
    let lastFocused = null;
    function trapFocus(container) {
        lastFocused = document.activeElement;
        const focusable = container.querySelectorAll('a[href],button,textarea,input,select,[tabindex]:not([tabindex="-1"])');
        if (focusable.length) focusable[0].focus();

        function handleKey(e) {
            if (e.key === 'Tab') {
                const focusableEls = Array.prototype.slice.call(container.querySelectorAll('a[href],button,textarea,input,select,[tabindex]:not([tabindex="-1"])'));
                if (!focusableEls.length) return;
                const firstEl = focusableEls[0];
                const lastEl = focusableEls[focusableEls.length - 1];
                if (e.shiftKey) {
                    if (document.activeElement === firstEl) {
                        e.preventDefault();
                        lastEl.focus();
                    }
                } else {
                    if (document.activeElement === lastEl) {
                        e.preventDefault();
                        firstEl.focus();
                    }
                }
            } else if (e.key === 'Escape') {
                hideModal();
            }
        }

        container.__trapHandler = handleKey;
        document.addEventListener('keydown', handleKey);
    }

    function releaseFocusTrap() {
        if (modal && modal.__trapHandler) {
            document.removeEventListener('keydown', modal.__trapHandler);
            modal.__trapHandler = null;
        } else {
            // remove any leftover
            document.removeEventListener('keydown', function () {});
        }
        if (lastFocused) {
            try { lastFocused.focus(); } catch (e) {}
            lastFocused = null;
        }
    }

    // Fullscreen toggle - Fixed version
    fullscreenBtn.addEventListener('click', function() {
        const elem = modalDialog;
        
        if (!document.fullscreenElement && !document.webkitFullscreenElement && !document.msFullscreenElement) {
            if (elem.requestFullscreen) {
                elem.requestFullscreen();
            } else if (elem.webkitRequestFullscreen) {
                elem.webkitRequestFullscreen();
            } else if (elem.msRequestFullscreen) {
                elem.msRequestFullscreen();
            }
        } else {
            if (document.exitFullscreen) {
                document.exitFullscreen();
            } else if (document.webkitExitFullscreen) {
                document.webkitExitFullscreen();
            } else if (document.msExitFullscreen) {
                document.msExitFullscreen();
            }
        }
    });

    // Update button icon on fullscreen change
    document.addEventListener('fullscreenchange', updateFullscreenButton);
    document.addEventListener('webkitfullscreenchange', updateFullscreenButton);
    document.addEventListener('msfullscreenchange', updateFullscreenButton);
    
    function updateFullscreenButton() {
        if (document.fullscreenElement || document.webkitFullscreenElement || document.msFullscreenElement) {
            fullscreenBtn.innerHTML = '<i class="fas fa-compress"></i>';
            isFullscreen = true;
        } else {
            fullscreenBtn.innerHTML = '<i class="fas fa-expand"></i>';
            isFullscreen = false;
        }
    }

    // Resizer logic (resize modal dialog)

    // openEdit exposed for table buttons to call
    // Open the modal to CREATE a new article (reuses the same edit modal + save path).
    window.openCreateArticle = function () { window.openEdit(0); };

    window.openEdit = function (id) {
        const creating = !id || parseInt(id, 10) === 0;
        activeArticleId = creating ? null : id;
        isCreateMode = creating;
        modalArticleIdSpan.textContent = creating ? 'New article' : ('#' + id);
        save_status.style.color = '#666';
        save_status.textContent = creating ? '' : 'Loading article...';
        if (deleteArticleBtn) deleteArticleBtn.style.display = creating ? 'none' : '';
        showModal();

        // Clear fields
        fld_title.value = '';
        fld_alias.value = '';
        fld_tags.value = '';
        fld_section.innerHTML = '<option value="">Choose section</option>';
        fld_section_subcat.innerHTML = '<option value="">Is it a sub-category?</option>';
        fld_author.innerHTML = '<option value="">Please select article author</option>';
        if (fld_evergreen) fld_evergreen.checked = false;
        if (fld_featured) fld_featured.checked = false;
        if (fld_sponsored) fld_sponsored.checked = false;
        fld_headline.value = '';
        fld_publish_from.value = '';
        fld_publish_to.value = '';
        fld_publish_now.checked = true; // Default to publish now
        pub_container.innerHTML = '';

        // Try to move the editor in first
        const moved = moveEditorIntoModal();
        if (!moved) {
            cloneEditorToModal();
        }
        // Create mode: start from a genuinely empty editor (no lingering content).
        if (creating) {
            const etNew = document.getElementById('article_text');
            if (etNew) etNew.innerHTML = '';
        }

        // POST load article data (id=0 returns blank defaults + the dropdown lists).
        const fd = new FormData();
        fd.append('id', creating ? 0 : id);

        fetch('/management/ajax/get_article_data.php', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
        })
        .then(r => r.json())
        .then(json => {
            console.log('Article data received:', json);
            
            if (!json || json.status !== 'success') {
                save_status.style.color = '#dc3545';
                save_status.textContent = (json && json.message) ? json.message : 'Failed to load article.';
                return;
            }

            // Sections follow the article's chosen publication(s). Keep the
            // per-pub map + union and rebuild from whichever pubs are ticked,
            // preserving the article's current section selection.
            const modalSectionsByPub = json.sections_by_pub || {};
            const modalAllSections = json.all_sections || [];
            const articleSection = (json.section || '');
            window._rebuildModalSections = function() {
                const prev = fld_section.value || articleSection;
                const checked = [];
                document.querySelectorAll('#modal_publications_container input[type="checkbox"]:checked').forEach(function(cb){ checked.push(cb.value); });
                let list = [], seen = {};
                if (checked.length && Object.keys(modalSectionsByPub).length) {
                    checked.forEach(function(ed){
                        (modalSectionsByPub[ed] || []).forEach(function(s){
                            if (!seen[s.value]) { seen[s.value] = 1; list.push(s); }
                        });
                    });
                }
                if (!list.length) list = modalAllSections;
                list.sort(function(a,b){ return a.label.localeCompare(b.label); });
                fld_section.innerHTML = '<option value="">Choose section</option>';
                list.forEach(function(section) {
                    const option = document.createElement('option');
                    option.value = section.value;
                    option.textContent = section.label;
                    if (prev && section.value.toLowerCase() === prev.toLowerCase()) option.selected = true;
                    fld_section.appendChild(option);
                });
            };
            window._rebuildModalSections();
            
            // Populate subcategories dropdown
            if (json.all_subcategories && json.all_subcategories.length > 0) {
                json.all_subcategories.forEach(function(subcat) {
                    const option = document.createElement('option');
                    option.value = subcat.value;
                    option.textContent = subcat.label;
                    if (subcat.value === json.section_subcat) {
                        option.selected = true;
                    }
                    fld_section_subcat.appendChild(option);
                });
            }
            
            // Populate journalists dropdown
            if (json.all_journalists && json.all_journalists.length > 0) {
                json.all_journalists.forEach(function(journalist) {
                    const option = document.createElement('option');
                    option.value = journalist.id;
                    option.textContent = journalist.name;
                    if (journalist.id == json.author_id) {
                        option.selected = true;
                    }
                    fld_author.appendChild(option);
                });
            }

            // Populate basic fields
            fld_title.value = json.title || '';
            fld_alias.value = json.article_alias || '';
            fld_tags.value = json.article_tags || '';
            if (fld_meta_title) fld_meta_title.value = json.meta_title || '';
            if (fld_meta_description) fld_meta_description.value = json.meta_description || '';
            if (fld_meta_keywords) fld_meta_keywords.value = json.meta_keywords || '';
            
            // Set checkboxes with detailed logging
            const evergreenValue = (json.evergreen == 1 || json.evergreen == '1' || json.evergreen === true);
            const featuredValue = (json.featured == 1 || json.featured == '1' || json.featured === true);
            const sponsoredValue = (json.sponsored == 1 || json.sponsored == '1' || json.sponsored === true);
            const headlineValue = (json.headline == 1 || json.headline == '1' || json.headline === true || json.frontpage_temp == 1 || json.frontpage_temp == '1');
            const publishNowValue = (json.publish_now == 1 || json.publish_now == '1' || json.publish_now === true);
            
            console.log('Setting checkboxes:', {
                evergreen: evergreenValue,
                featured: featuredValue,
                sponsored: sponsoredValue,
                headline: headlineValue,
                publishNow: publishNowValue
            });
            
            if (fld_evergreen) fld_evergreen.checked = evergreenValue;
            if (fld_featured) fld_featured.checked = featuredValue;
            if (fld_sponsored) fld_sponsored.checked = sponsoredValue;
            if (fld_headline) fld_headline.checked = headlineValue;
            
            fld_publish_from.value = json.publish_from || '';
            fld_publish_to.value = json.publish_to || '';
            fld_publish_now.checked = publishNowValue;
            
            // Build publication rows with checkbox + radio in same row
            pub_container.innerHTML = '';
            console.log('Publications data:', json.all_publications);
            console.log('Article publications:', json.publications);
            console.log('Article canonical:', json.canonical);
            
            if (json.all_publications && json.all_publications.length > 0) {
                json.all_publications.forEach(function(pub) {
                    const row = document.createElement('div');
                    row.style.cssText = 'display:grid;grid-template-columns:1fr auto;align-items:center;gap:16px;padding:8px 10px;border-bottom:1px solid #f0f0f0;';
                    
                    // Left side: checkbox + publication name
                    const leftSide = document.createElement('div');
                    leftSide.style.cssText = 'display:flex;align-items:center;gap:8px;';
                    
                    const checkbox = document.createElement('input');
                    checkbox.type = 'checkbox';
                    checkbox.id = 'pub_' + pub.name.replace(/\s+/g, '_');
                    checkbox.value = pub.name;
                    checkbox.checked = pub.selected;
                    checkbox.style.cssText = 'cursor:pointer;width:16px;height:16px;';
                    
                    const label = document.createElement('label');
                    label.htmlFor = checkbox.id;
                    label.textContent = pub.title || pub.name;
                    label.style.cssText = 'cursor:pointer;font-size:14px;color:#333;font-weight:500;';
                    
                    leftSide.appendChild(checkbox);
                    leftSide.appendChild(label);
                    
                    // Right side: canonical radio
                    const rightSide = document.createElement('div');
                    rightSide.style.cssText = 'display:flex;align-items:center;gap:6px;';
                    
                    const canonicalLabel = document.createElement('span');
                    canonicalLabel.textContent = 'Canonical:';
                    canonicalLabel.style.cssText = 'font-size:13px;color:#666;';
                    
                    const radio = document.createElement('input');
                    radio.type = 'radio';
                    radio.name = 'canonical_radio';
                    radio.value = pub.name;
                    radio.checked = pub.canonical;
                    radio.style.cssText = 'cursor:pointer;width:16px;height:16px;';
                    
                    // Disable radio if checkbox is not checked
                    if (!pub.selected) {
                        radio.disabled = true;
                        radio.style.opacity = '0.3';
                        radio.style.cursor = 'not-allowed';
                    }
                    
                    // Add change event to checkbox to enable/disable radio
                    checkbox.addEventListener('change', function() {
                        if (checkbox.checked) {
                            radio.disabled = false;
                            radio.style.opacity = '1';
                            radio.style.cursor = 'pointer';
                        } else {
                            radio.disabled = true;
                            radio.checked = false;
                            radio.style.opacity = '0.3';
                            radio.style.cursor = 'not-allowed';
                        }
                        if (window._rebuildModalSections) window._rebuildModalSections();
                    });

                    rightSide.appendChild(canonicalLabel);
                    rightSide.appendChild(radio);

                    row.appendChild(leftSide);
                    row.appendChild(rightSide);
                    pub_container.appendChild(row);
                });
                // publications now exist — scope sections to the ticked pub(s).
                if (window._rebuildModalSections) window._rebuildModalSections();
            }

            // Show/hide publish button based on permissions
            if (json.can_publish) {
                publishBtn.style.display = 'inline-block';
            } else {
                publishBtn.style.display = 'none';
            }

            // Handle editor content insertion
            let inserted = false;
            const articleContent = json.article || json.article_text || '';

            console.log('DEBUG - Article content length:', articleContent.length);
            console.log('DEBUG - movedEditor:', movedEditor, 'originalEditor:', !!originalEditor);
            console.log('DEBUG - cloneUsed:', cloneUsed);
            
            if (movedEditor && originalEditor) {
                try {
                    const inst = originalEditor.__editorInstance || originalEditor.dataset.editorInstance;
                    if (inst && typeof inst.setContent === 'function') {
                        inst.setContent(articleContent);
                        inserted = true;
                    }
                } catch (e) {}

                if (!inserted) {
                    // Try to find the actual contenteditable editor inside the moved container
                    const editorDiv = originalEditor.querySelector('.editor[contenteditable="true"]') || 
                                    originalEditor.querySelector('#article_text[contenteditable="true"]') ||
                                    originalEditor.querySelector('[contenteditable="true"]');
                    
                    if (editorDiv) {
                        editorDiv.innerHTML = articleContent;
                        inserted = true;
                        console.log('Editor content set via querySelector, length:', articleContent.length);
                        
                        // Make sure we're in editor view, not source view
                        const sourceView = originalEditor.querySelector('.source-view, #source-view');
                        if (sourceView) {
                            sourceView.classList.remove('active');
                            sourceView.style.display = 'none';
                        }
                        editorDiv.classList.remove('hidden');
                        editorDiv.style.display = 'block';
                    } else {
                        console.error('Could not find editor div inside originalEditor');
                    }
                }
            } else if (cloneUsed) {
                const clone = editorSlot.querySelector('.editor-container') || editorSlot.firstElementChild;
                if (clone) {
                    try {
                        if (typeof window.initCustomEditor === 'function') {
                            if (window.initCustomEditor.length === 1) {
                                window.initCustomEditor(clone);
                            } else {
                                window.initCustomEditor();
                            }
                        }
                    } catch (e) {}
                    
                    const editorDiv = clone.querySelector('.editor[contenteditable="true"]') ||
                                    clone.querySelector('#article_text[contenteditable="true"]') ||
                                    clone.querySelector('[contenteditable="true"]');
                    if (editorDiv) {
                        editorDiv.innerHTML = articleContent;
                        inserted = true;
                    }
                }
            }

            console.log('DEBUG - Editor content inserted:', inserted);
            const originalArticleText = document.getElementById('article_text');
            if (originalArticleText && originalArticleText.id === 'article_text') {
                originalArticleText.innerHTML = articleContent;
            }

            // NOTE: previously this re-applied float + max-width:50% inline to every
            // image on load, which was then saved and shrank the front-page headline.
            // Images are now kept clean; the site's per-placement CSS sizes them.

            save_status.style.color = '#666';
            save_status.textContent = 'Ready to edit';
        })
        .catch(err => {
            save_status.style.color = '#dc3545';
            save_status.textContent = 'Error loading article.';
            console.error(err);
        });
    }; // end openEdit
    
    // Validation helper: ensure title, at least one publication, and non-empty content
    function validateBeforeSave() {
        if (!fld_title.value.trim()) {
            save_status.style.color = '#dc3545';
            save_status.textContent = 'Title cannot be empty.';
            fld_title.focus();
            return false;
        }

        // Must agree to the submission terms.
        const termsCb = document.getElementById('modal_terms_checkbox');
        if (termsCb && !termsCb.checked) {
            save_status.style.color = '#dc3545';
            save_status.textContent = 'You must agree to the Terms of Article Submission.';
            return false;
        }

        // check chosen pubs
        const checkboxes = pub_container.querySelectorAll('input[type="checkbox"]');
        let anyPub = false;
        checkboxes.forEach(cb => { if (cb.checked) anyPub = true; });
        if (!anyPub) {
            save_status.style.color = '#dc3545';
            save_status.textContent = 'Select at least one publication.';
            return false;
        }

        // check editor content
        let content = '';
        if (movedEditor && originalEditor) {
            const inst = originalEditor.__editorInstance || originalEditor.dataset.editorInstance;
            if (inst && typeof inst.getContent === 'function') {
                content = inst.getContent().trim();
            }
            if (!content) {
                const editorDiv = originalEditor.querySelector('.editor[contenteditable="true"]') ||
                                originalEditor.querySelector('#article_text');
                if (editorDiv) {
                    content = editorDiv.textContent.trim();
                }
            }
        } else if (cloneUsed) {
            const clone = editorSlot.querySelector('.editor-container') || editorSlot.firstElementChild;
            if (clone) {
                const inst = clone.__editorInstance || clone.dataset.editorInstance;
                if (inst && typeof inst.getContent === 'function') {
                    content = inst.getContent().trim();
                }
                if (!content) {
                    const editorDiv = clone.querySelector('.editor[contenteditable="true"]') ||
                                    clone.querySelector('#article_text');
                    if (editorDiv) {
                        content = editorDiv.textContent.trim();
                    }
                }
            }
        }

        if (!content) {
            save_status.style.color = '#dc3545';
            save_status.textContent = 'Article content cannot be empty.';
            return false;
        }

        return true;
    }

    // Soft-delete: set the article's state to 'deleted' (row is kept in the DB).
    function deleteArticle() {
        if (!activeArticleId) return;
        const doDelete = function () {
            if (deleteArticleBtn) { deleteArticleBtn.disabled = true; deleteArticleBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Deleting...'; }
            save_status.style.color = '#666';
            save_status.textContent = 'Deleting article...';
            const fd = new FormData();
            fd.append('id', activeArticleId);
            fetch('/management/ajax/delete_article.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(r => r.json())
                .then(resp => {
                    if (deleteArticleBtn) { deleteArticleBtn.disabled = false; deleteArticleBtn.innerHTML = '<i class="fas fa-trash"></i> Delete'; }
                    if (resp && resp.status === 'success') {
                        save_status.style.color = '#28a745';
                        save_status.textContent = 'Article deleted.';
                        Swal.fire({ icon: 'success', title: 'Deleted', text: resp.message || 'Article moved to Deleted.', showConfirmButton: false, timer: 1500, customClass: { container: 'swal-high-z' } });
                        if (typeof loadArticles === 'function') loadArticles();
                        setTimeout(function () { hideModal(); }, 1600);
                    } else {
                        save_status.style.color = '#dc3545';
                        save_status.textContent = (resp && resp.message) ? resp.message : 'Failed to delete.';
                        Swal.fire({ icon: 'error', title: 'Error', text: (resp && resp.message) ? resp.message : 'Failed to delete article', customClass: { container: 'swal-high-z' } });
                    }
                })
                .catch(err => {
                    if (deleteArticleBtn) { deleteArticleBtn.disabled = false; deleteArticleBtn.innerHTML = '<i class="fas fa-trash"></i> Delete'; }
                    save_status.style.color = '#dc3545';
                    save_status.textContent = 'Failed to delete.';
                    Swal.fire({ icon: 'error', title: 'Error', text: err.message, customClass: { container: 'swal-high-z' } });
                });
        };
        Swal.fire({
            icon: 'warning',
            title: 'Delete this article?',
            text: 'It will be moved to the Deleted state and hidden from the site, but kept in the database.',
            showCancelButton: true,
            confirmButtonText: 'Yes, delete',
            confirmButtonColor: '#dc2626',
            cancelButtonText: 'Cancel',
            customClass: { container: 'swal-high-z' }
        }).then(function (res) { if (res.isConfirmed) doDelete(); });
    }

    // Generic save handler
    function saveArticle(action) {
        // No activeArticleId => creating a new article (id sent blank => server INSERTs).
        if (!validateBeforeSave()) return;

        const btnMap = {
            'save': saveDraftBtn,
            'submit': submitReviewBtn,
            'publish': publishBtn
        };
        const btn = btnMap[action];
        if (btn) {
            btn.disabled = true;
            btn.textContent = 'Saving...';
        }
        
        save_status.style.color = '#666';
        save_status.textContent = 'Saving article...';

        // Extract content from editor
        let articleHtml = '';
        if (movedEditor && originalEditor) {
            const inst = originalEditor.__editorInstance || originalEditor.dataset.editorInstance;
            try {
                if (inst && typeof inst.getContent === 'function') {
                    articleHtml = inst.getContent();
                }
            } catch (e) {}
            if (!articleHtml) {
                // Get content from the actual editor div, not the container
                const editorDiv = originalEditor.querySelector('.editor[contenteditable="true"]') ||
                                originalEditor.querySelector('#article_text');
                if (editorDiv) {
                    articleHtml = editorDiv.innerHTML;
                }
            }
        } else if (cloneUsed) {
            const clone = editorSlot.querySelector('.editor-container') || editorSlot.firstElementChild;
            if (clone) {
                const inst = clone.__editorInstance || clone.dataset.editorInstance;
                if (inst && typeof inst.getContent === 'function') {
                    articleHtml = inst.getContent();
                } else {
                    const editorDiv = clone.querySelector('.editor[contenteditable="true"]') ||
                                    clone.querySelector('#article_text');
                    if (editorDiv) {
                        articleHtml = editorDiv.innerHTML;
                    } else {
                        articleHtml = clone.innerHTML;
                    }
                }
            }
        }

        // Strip float/wrap styles from images before saving (display-only, not for DB)
        (function() {
            const tempDiv = document.createElement('div');
            tempDiv.innerHTML = articleHtml;
            tempDiv.querySelectorAll('img.editor-img-wrapped, img[style*="float"]').forEach(function(img) {
                img.classList.remove('editor-img-wrapped');
                img.style.removeProperty('float');
                img.style.removeProperty('margin-left');
                img.style.removeProperty('margin-right');
                // Normalize margin if it was shorthand "0 15px 10px 0"
                if (img.style.margin === '0 15px 10px 0' || img.style.margin === '0px 15px 10px 0px') {
                    img.style.removeProperty('margin');
                }
                if (!img.getAttribute('style') || img.getAttribute('style').trim() === '') {
                    img.removeAttribute('style');
                }
            });
            articleHtml = tempDiv.innerHTML;
        })();

        // chosen publications
        const pubInputs = pub_container.querySelectorAll('input[type="checkbox"]');
        const chosenPubs = [];
        pubInputs.forEach(function (cb) {
            if (cb.checked) {
                chosenPubs.push(cb.value);
            }
        });

        const canonicalRadio = pub_container.querySelector('input[type="radio"][name="canonical_radio"]:checked');
        const canonicalVal = canonicalRadio ? canonicalRadio.value : '';

        // Prepare POST
        const fd = new FormData();
        fd.append('id', activeArticleId || '');   // blank => create
        fd.append('title', fld_title.value || '');
        fd.append('article_text', articleHtml || '');
        fd.append('alias', fld_alias.value || '');
        fd.append('tags', fld_tags.value || '');
        fd.append('meta_title', fld_meta_title ? (fld_meta_title.value || '') : '');
        fd.append('meta_description', fld_meta_description ? (fld_meta_description.value || '') : '');
        fd.append('meta_keywords', fld_meta_keywords ? (fld_meta_keywords.value || '') : '');
        fd.append('section', fld_section.value || '');
        fd.append('section_subcat', fld_section_subcat.value || '');
        fd.append('author', fld_author.value || '');
        fd.append('publications', chosenPubs.join(','));
        fd.append('canonical', canonicalVal || '');
        fd.append('evergreen', fld_evergreen.checked ? '1' : '0');
        fd.append('featured', fld_featured.checked ? '1' : '0');
        fd.append('sponsored', fld_sponsored.checked ? '1' : '0');
        fd.append('frontpage_temp', fld_headline.checked ? '1' : '0');
        fd.append('publish_now', fld_publish_now.checked ? '1' : '0');
        fd.append('publish_from', fld_publish_from.value || '');
        fd.append('publish_to', fld_publish_to.value || '');
        fd.append('action', action);

        fetch('/management/ajax/save_article.php', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
        })
        .then(r => r.json())
        .then(resp => {
            if (btn) {
                btn.disabled = false;
                const btnText = {
                    'save': 'Save as Draft',
                    'submit': 'Submit for Review',
                    'publish': 'Publish'
                };
                btn.textContent = btnText[action];
            }
            
            console.log('Save response:', resp);
            
            if (resp && resp.status === 'success') {
                // A create returns the new id — adopt it so any further save updates
                // (not re-creates) this same article, and the modal is now in edit mode.
                if (!activeArticleId && resp.article_id) {
                    activeArticleId = resp.article_id;
                    isCreateMode = false;
                    if (deleteArticleBtn) deleteArticleBtn.style.display = '';
                    modalArticleIdSpan.textContent = '#' + resp.article_id;
                }
                save_status.style.color = '#28a745';
                save_status.textContent = 'Saved successfully.';

                // Show SweetAlert with higher z-index
                Swal.fire({
                    icon: 'success',
                    title: 'Success!',
                    text: resp.message || 'Article saved successfully',
                    showConfirmButton: false,
                    timer: 1500,
                    customClass: {
                        container: 'swal-high-z'
                    }
                });
                
                // refresh list
                if (typeof loadArticles === 'function') loadArticles();
                // close modal shortly
                setTimeout(function () { hideModal(); }, 1600);
            } else {
                save_status.style.color = '#dc3545';
                save_status.textContent = (resp && resp.message) ? resp.message : 'Failed to save.';
                console.error('Save failed:', resp);
                
                Swal.fire({
                    icon: 'error',
                    title: 'Error',
                    text: (resp && resp.message) ? resp.message : 'Failed to save article',
                    customClass: {
                        container: 'swal-high-z'
                    }
                });
            }
        })
        .catch(err => {
            if (btn) {
                btn.disabled = false;
                const btnText = {
                    'save': 'Save as Draft',
                    'submit': 'Submit for Review',
                    'publish': 'Publish'
                };
                btn.textContent = btnText[action];
            }
            save_status.style.color = '#dc3545';
            save_status.textContent = 'Error saving article.';
            console.error('Save error:', err);
            
            Swal.fire({
                icon: 'error',
                title: 'Error',
                text: 'Network error: Could not save article',
                customClass: {
                    container: 'swal-high-z'
                }
            });
        });
    }

    // Save handler
    saveDraftBtn.addEventListener('click', function () { saveArticle('save'); });
    submitReviewBtn.addEventListener('click', function () { saveArticle('submit'); });
    publishBtn.addEventListener('click', function () { saveArticle('publish'); });
    if (deleteArticleBtn) deleteArticleBtn.addEventListener('click', deleteArticle);

    // Close handlers
    closeBtn.addEventListener('click', hideModal);
    cancelBtn.addEventListener('click', hideModal);
    modal.addEventListener('click', function (e) { if (e.target === modal) hideModal(); });

    // Esc handled by focus trap

    // Ensure modal can't be left with editor missing - on unload restore
    window.addEventListener('beforeunload', function () {
        if (movedEditor) restoreMovedEditor();
    });

})();


    // Deep-link: module-articles.php?open=<id> opens that article straight in the editor
    // (used by the Scraper History / Curate screens to jump to a promoted draft).
    (function () {
        try {
            var m = new URLSearchParams(window.location.search).get('open');
            var oid = m ? parseInt(m, 10) : 0;
            if (oid > 0) {
                var tryOpen = function (tries) {
                    if (typeof window.openEdit === 'function') { window.openEdit(oid); }
                    else if (tries > 0) { setTimeout(function () { tryOpen(tries - 1); }, 200); }
                };
                if (document.readyState === 'complete' || document.readyState === 'interactive') tryOpen(25);
                else document.addEventListener('DOMContentLoaded', function () { tryOpen(25); });
            }
        } catch (e) { /* no-op */ }
    })();
    </script>
    <script src="js/scraper_image_suggest.js?v=<?php echo @filemtime(__DIR__ . '/js/scraper_image_suggest.js'); ?>"></script>
    <script src="js/image_search.js?v=<?php echo @filemtime(__DIR__ . '/js/image_search.js'); ?>"></script>
</body>
</html>