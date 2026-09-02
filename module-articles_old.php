<?php
session_start();
if ( ! defined('CURL_SSLVERSION_TLSv1_2')) {
    define('CURL_SSLVERSION_TLSv1_2', 6);
}
require_once('vendor/autoload.php');
require_once 'config.php';
requireLogin();

$apiKey = '35893630-f396d86d2cc73061b0e733d53';

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
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f5f5f5;
            color: #111827;
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
            font-size: 32px;
            font-weight: 700;
            color: #111827;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .page-header h1 i {
            color: #333;
        }
        
        .page-header p {
            color: #666;
            margin-top: 8px;
            font-size: 15px;
        }
        
        /* Tabs */
        .tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 24px;
            border-bottom: 2px solid #ddd;
            flex-wrap: wrap;
        }
        
        .tab {
            padding: 12px 24px;
            background: none;
            border: none;
            border-bottom: 3px solid transparent;
            font-size: 15px;
            font-weight: 600;
            color: #666;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .tab:hover {
            color: #333;
            background: #f0f0f0;
        }
        
        .tab.active {
            color: #111;
            border-bottom-color: #333;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }

        /* Card styling */
        .card {
            background: white;
            border: 1px solid #ddd;
            padding: 28px;
            margin-bottom: 24px;
        }

        .card h2 {
            font-size: 20px;
            font-weight: 700;
            color: #111827;
            margin-bottom: 20px;
        }

        /* Editor styles from add_article.php */
        .editor-container {
            background: #fff;
            border: 1px solid #ddd;
            position: relative;
        }
        .editor-controls {
            display: flex;
            gap: 10px;
            padding: 10px;
            border-bottom: 1px solid #ddd;
            background: #fafafa;
        }
        .editor-controls button {
            padding: 8px;
            border: 1px solid #ccc;
            border-radius: 4px;
            background: #fff;
            color: #444;
            cursor: pointer;
            position: relative;
            transition: all 0.2s;
        }
        .editor-controls button:hover {
            background: #f5f5f5;
            border-color: #999;
        }
        .editor-controls button i {
            font-size: 18px;
        }
        .editor-controls button:hover::after {
            content: attr(data-tooltip);
            position: absolute;
            bottom: -30px;
            left: 50%;
            transform: translateX(-50%);
            background: #333;
            color: #fff;
            padding: 5px 8px;
            border-radius: 4px;
            font-size: 12px;
            white-space: nowrap;
        }
        .editor {
            display: block;
            width: 100%;
            padding: 12px;
            min-height: 200px;
            border: none;
            font-size: 16px;
            line-height: 1.6;
            outline: none;
            overflow-y: auto;
        }
        .editor[contenteditable="true"] {
            background-color: #fff;
        }
        .editor mark {
            background-color: yellow;
            padding: 2px;
        }
        .editor img {
            cursor: pointer;
            border: 2px solid transparent;
            transition: border-color 0.2s;
        }
        .editor img:hover {
            border-color: #999;
        }
        .source-view {
            display: none;
            width: 100%;
            padding: 12px;
            min-height: 200px;
            border: none;
            font-family: monospace;
            font-size: 14px;
            line-height: 1.6;
            background: #f9f9f9;
            color: #444;
            outline: none;
        }
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
        }
        /* Modal styling */
        .overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
        }
        .modal {
            display: none;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            padding: 20px;
            border: 1px solid #ccc;
            z-index: 2000;
            height: 500px;
            width: auto;
        }
        .modal input, .modal select {
            width: 100%;
            padding: 10px;
            margin-bottom: 10px;
            border: 1px solid #ddd;
        }
        .modal button {
            padding: 8px 12px;
            border: 1px solid #ccc;
            border-radius: 4px;
            background: #f5f5f5;
            color: #333;
            cursor: pointer;
            margin-right: 5px;
            transition: all 0.2s;
        }
        .modal button:hover {
            background: #e5e5e5;
        }
        .image-grid {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
        }
        .image-grid img {
            max-width: 200px;
            height: auto;
            border: 1px solid #ccc;
        }

        .form-group {
            margin-bottom: 20px;
        }

        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 600;
            color: #fff;
        }

        .form-control {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid #ccc;
            font-size: 14px;
            transition: border-color 0.2s;
        }

        .form-control:focus {
            outline: none;
            border-color: #666;
        }

        .btn {
            padding: 10px 20px;
            border: 1px solid #ccc;
            border-radius: 4px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-primary {
            background: #333;
            color: white;
            border-color: #333;
        }

        .btn-primary:hover {
            background: #555;
            border-color: #555;
        }

        .btn-primary.active {
            background: #111;
            border-color: #111;
        }

        .btn-danger {
            background: #dc3545;
            color: white;
            border-color: #dc3545;
        }

        .btn-danger:hover {
            background: #c82333;
            border-color: #c82333;
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
            background-color: #ccc;
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
                <button class="tab active" data-tab="add-article">
                    <i class="fas fa-plus-circle"></i> Add Article
                </button>
                <button class="tab" data-tab="add-image">
                    <i class="fas fa-image"></i> Add Image
                </button>
                <button class="tab" data-tab="view-articles">
                    <i class="fas fa-list"></i> View All Articles
                </button>
                <button class="tab" data-tab="sponsored-articles">
                    <i class="fas fa-list"></i> Sponsoredn Articles
                </button>
            </div>

            <!-- Tab 1: Add Article -->
            <div id="add-article" class="tab-content active">
                <div class="card">
                    <h2><i class="fas fa-pen"></i> Create New Article</h2>
                    
                    <img src="loading.gif" id="loading_image" style="display: none; position: absolute; width: 100px; margin: 0px auto; z-index: 100; text-align: center; justify-content: center; right: 0px; left: 0px;" />

                    <form id="ten_add_article" method="post" onsubmit="return false;">
                        <div class="form-group">
                            <label for="article_title">Article Title</label>
                            <input type="text" id="article_title" name="article_title" class="form-control" placeholder="Enter article title" style="width: 100%;">
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

                        <div class="form-group">
                            <label>Article Content</label>
                            <div class="editor-container">
                                <div class="editor-controls">
                                    <button type="button" id="bold" data-tooltip="Bold"><i class="fas fa-bold"></i></button>
                                    <button type="button" id="italic" data-tooltip="Italic"><i class="fas fa-italic"></i></button>
                                    <button type="button" id="underline" data-tooltip="Underline"><i class="fas fa-underline"></i></button>
                                    <button type="button" id="unordered-list" data-tooltip="Unordered List"><i class="fas fa-list-ul"></i></button>
                                    <button type="button" id="ordered-list" data-tooltip="Ordered List"><i class="fas fa-list-ol"></i></button>
                                    <button type="button" id="add-link" data-tooltip="Add Link"><i class="fas fa-link"></i></button>
                                    <button type="button" id="add-image-editor" data-tooltip="Add Image"><i class="fas fa-image"></i></button>
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

                            <div class="modal html_editor" id="link-modal" style="background: #fff; box-shadow: 0 4px 6px rgba(0, 0, 0, 0.2);">
                                <button type="button" style="float: right;" id="close-link-modal2">&times;</button>
                                <h3>Add a Link</h3>
                                <label for="link-url">URL:</label>
                                <input type="url" id="link-url" placeholder="Enter the link URL..." >
                                <label for="link-title">Title/Tooltip:</label>
                                <input type="text" id="link-title" placeholder="Enter the link title/tooltip...">
                                <label for="link-text">Link Text:</label>
                                <input type="text" id="link-text" placeholder="Enter the link text to display..." >
                                <label for="link-target">Open Link In:</label>
                                <select id="link-target">
                                    <option value="_self">Same Page</option>
                                    <option value="_blank">New Tab</option>
                                    <option value="anchor">Named Anchor</option>
                                </select>
                                <div>
                                    <button type="button" id="insert-link">Insert Link</button>
                                    <button type="button" id="preview-link">Preview Link</button>
                                    <button type="button" id="close-link-modal">Close</button>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label class="switch" for="article_submission_terms_checkbox">
                                <input type="checkbox" id="article_submission_terms_checkbox" name="article_submission_terms_checkbox" value="1" CHECKED>
                                <span></span>
                            </label>
                            <label for="article_submission_terms_checkbox" style="display: inline; margin-left: 12px; cursor: pointer;">
                                I agree to the <a href="#" onclick="$('#modal-article-terms').fadeIn(); return false;">Terms of Article Submission</a>
                            </label>
                        </div>

                        <div class="form-actions">
                            <button type="submit" class="btn btn-primary">
                                <i class="fa fa-save"></i> Save Draft Article
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Tab 2: Add Image -->
            <div id="add-image" class="tab-content">
                <div class="card">
                    <h2><i class="fas fa-image"></i> Upload Article Image</h2>
                    
                    <!-- Pixabay Search Section -->
                    <div id="pixabay-search-section" style="display: none; margin-top: 20px; padding: 20px; background: #f9fafb; border: 1px solid #ddd;">
                        <h3 style="color: #111827; margin-bottom: 15px;"><i class="fas fa-search"></i> Search Free Images on Pixabay</h3>
                        <form id="pixabay-search-form-tab2" style="margin-bottom: 20px;" onsubmit="return false;">
                            <div style="display: flex; gap: 12px;">
                                <input type="text" id="pixabay-query-tab2" class="form-control" placeholder="Search for images (e.g., 'nature', 'business', 'technology')" style="flex: 1;">
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-search"></i> Search
                                </button>
                            </div>
                        </form>
                        
                        <div id="pixabay-loading-tab2" style="display: none; text-align: center; padding: 40px;">
                            <img src="loading.gif" style="width: 50px;">
                            <p style="color: #333; margin-top: 10px;">Searching Pixabay...</p>
                        </div>
                        
                        <div id="pixabay-results-tab2" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 15px; max-height: 500px; overflow-y: auto;"></div>
                        
                        <div id="pixabay-no-results-tab2" style="display: none; text-align: center; padding: 40px; color: #666;">
                            <i class="fas fa-image" style="font-size: 48px; margin-bottom: 15px; opacity: 0.3;"></i>
                            <p>No images found. Try a different search term.</p>
                        </div>
                    </div>

                    <!-- Upload Section -->
                    <div id="image_upload_section" style="display: block;">
                        <div class="form-group" id="file-upload-area" style="display: block;">
                            <label for="file-input-tab2" class="btn btn-primary" style="cursor: pointer; display: inline-block;">
                                <i class="fas fa-upload"></i> Choose Image from Your Computer
                            </label>
                            <button type="button" class="btn btn-primary" id="search-pixabay-toggle" style="margin-left: 10px;">
                                <i class="fas fa-search"></i> Search Pixabay Images
                            </button>
                            <input style="display: none;" type="file" id="file-input-tab2" accept="image/*">
                            <span id="filename-display" style="margin-left: 15px; color: #666;"></span>
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

            <!-- Tab 3: View All Articles -->
            <div id="view-articles" class="tab-content">
                <div class="card">
                    <h2><i class="fas fa-list"></i> All Articles</h2>
                    <div style="width:100%;max-width:1200px;margin:0 auto;padding:12px;font-family:inherit;">

                        <!-- Search -->
                        <div style="margin-bottom:12px;">
                            <input id="articleSearch"
                                   type="text"
                                   placeholder="Search articles..."
                                   style="width:100%;padding:8px 12px;border:1px solid #ced4da;border-radius:6px;box-sizing:border-box;" />
                        </div>

                        <!-- Table wrapper (responsive) -->
                        <div id="tableWrapper" style="width:100%;overflow-x:auto;background:#fff;border:1px solid #e9ecef;border-radius:6px;">
                            <table id="articlesTable"
                                   style="width:100%;border-collapse:collapse;min-width:700px;table-layout:auto;">
                                <thead>
                                    <tr style="background:#343a40;color:#fff;">
                                        <th data-col="id" class="sortable"
                                            style="padding:10px 8px;border:1px solid #dee2e6;vertical-align:middle;cursor:pointer;width:60px;text-align:left;">
                                            ID <span class="sort-arrow" style="margin-left:6px;font-size:12px;"></span>
                                        </th>
                                        <th data-col="title" class="sortable"
                                            style="padding:10px 8px;border:1px solid #dee2e6;vertical-align:middle;cursor:pointer;text-align:left;">
                                            Title <span class="sort-arrow" style="margin-left:6px;font-size:12px;"></span>
                                        </th>
                                        <th data-col="journalist_name"
                                            style="padding:10px 8px;border:1px solid #dee2e6;vertical-align:middle;text-align:left;">
                                            Journalist
                                        </th>
                                        <th data-col="publications"
                                            style="padding:10px 8px;border:1px solid #dee2e6;vertical-align:middle;text-align:left;min-width:140px;">
                                            Publications
                                        </th>
                                        <th data-col="state" class="sortable"
                                            style="padding:10px 8px;border:1px solid #dee2e6;vertical-align:middle;cursor:pointer;width:120px;text-align:left;">
                                            Status <span class="sort-arrow" style="margin-left:6px;font-size:12px;"></span>
                                        </th>
                                        <th data-col="submission_date" class="sortable"
                                            style="padding:10px 8px;border:1px solid #dee2e6;vertical-align:middle;cursor:pointer;width:160px;text-align:left;">
                                            Submission Date <span class="sort-arrow" style="margin-left:6px;font-size:12px;"></span>
                                        </th>
                                        <th style="padding:10px 8px;border:1px solid #dee2e6;vertical-align:middle;text-align:center;width:90px;">
                                            Edit
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

            <!-- Tab 3: View All Articles -->
            <div id="sponsored-articles" class="tab-content">
                <div class="card">
                    <h2><i class="fas fa-list"></i> Sponsored Articles</h2>
                    <p>Sponsored articles will be added here</p>
                </div>
            </div>
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

    <!-- Article Edit Modal (inline styles only) -->
<div id="articleEditModal" aria-hidden="true" style="display:none;position:fixed;z-index:2000;left:0;top:0;width:100%;height:100%;overflow:auto;background:rgba(0,0,0,0.55);">
    <div id="articleEditModalDialog" role="dialog" aria-modal="true"
         style="width:98%;max-width:1280px;margin:10px auto;background:#fff;border-radius:10px;box-shadow:0 15px 40px rgba(0,0,0,0.4);overflow:hidden;display:flex;flex-direction:column;max-height:98vh;">
        
        <div style="display:flex;align-items:center;justify-content:space-between;padding:16px 24px;border-bottom:1px solid #e9ecef;background:#f8f9fa;">
            <div style="display:flex;align-items:center;gap:16px;">
                <div style="font-size:20px;font-weight:600;color:#343a40;">Edit Article <span id="modalArticleId" style="font-weight:400;color:#6c757d;margin-left:8px;"></span></div>
                <button id="modal_fullscreen_toggle" title="Toggle fullscreen"
                        style="border:1px solid #dee2e6;background:#fff;padding:8px 10px;border-radius:6px;cursor:pointer;font-size:14px;transition:all 0.2s;">⤢</button>
            </div>

            <div style="display:flex;gap:12px;">
                <button id="closeArticleModal" style="background:#fff;border:1px solid #ced4da;color:#495057;padding:8px 16px;border-radius:6px;cursor:pointer;transition:background-color 0.2s;">Close</button>
                <button id="saveArticleBtn" style="background:#007bff;color:#fff;border:none;padding:8px 16px;border-radius:6px;cursor:pointer;transition:background-color 0.2s;">Save</button>
            </div>
        </div>

        <div style="display:flex;flex:1;overflow:hidden; flex-direction: column; @media (min-width: 768px) { flex-direction: row; }">
            <form id="ten_edit_article" method="post" onsubmit="return false;" novalidate="novalidate" style="display:contents;">
                <input type="hidden" id="article_id_field" name="article_id_field" value="29215">
                
                <div style="flex:1 1 70%;padding:24px;box-sizing:border-box;border-right:1px solid #e9ecef;display:flex;flex-direction:column;gap:20px;overflow-y:auto;">
                    
                    <div style="display:flex;align-items:flex-end;gap:16px;">
                        <div style="flex-grow:1;">
                            <label style="display:block;font-weight:600;margin-bottom:4px;color:#495057;" for="article_title">Title</label>
                            <input type="text" id="article_title" name="article_title" placeholder="Article Title"
                                   style="width:100%;padding:12px;border:1px solid #ced4da;border-radius:6px;font-weight:700;font-size:1.3em;box-sizing:border-box;transition:border-color 0.2s;">
                        </div>
                    </div>
                    
                    <div id="modal_editor_slot" style="flex-grow:1; min-height:400px; border:1px solid #ced4da; border-radius:6px; box-sizing:border-box; background:#fff; overflow:hidden;">
                        <textarea id="article_text" name="article_text" class="ckeditor" style="visibility: hidden; display: none;"></textarea>
                    </div>

                    <div style="display:flex;gap:20px;flex-wrap:wrap;border-top:1px solid #e9ecef;padding-top:20px;">
                        
                        <div style="flex:1 1 45%; min-width: 300px; display:flex; flex-direction:column; gap:20px;">
                            
                            <div>
                                <label style="display:block;font-weight:600;margin-bottom:4px;color:#495057;" for="author_select">Author</label>
                                <select id="author_select" name="author_select" size="1"
                                        style="width:100%;padding:10px 12px;border:1px solid #ced4da;border-radius:6px;height:44px;box-sizing:border-box;background-color:#fff;">
                                    <option value="">Please select article author</option>
                                    <option value="150">Adam Dudyk</option><option value="149">Adelina Vladescu</option></select>
                            </div>
                            <div>
                                <label style="display:block;font-weight:600;margin-bottom:4px;color:#495057;" for="article_alias">Alias (URL Slug)</label>
                                <input type="text" id="article_alias" name="article_alias" placeholder="e.g., my-article-title" 
                                       style="width:100%;padding:10px 12px;border:1px solid #ced4da;border-radius:6px;box-sizing:border-box;">
                            </div>
                            
                            <div>
                                <label style="display:block;font-weight:600;margin-bottom:4px;color:#495057;">Tags (comma separated)</label>
                                <input id="modal_tags" type="text" style="width:100%;padding:10px 12px;border:1px solid #ced4da;border-radius:6px;box-sizing:border-box;" />
                            </div>

                        </div>

                        <div style="flex:1 1 45%; min-width: 300px; display:flex; flex-direction:column; gap:20px;">
                            
                            <div style="display:flex;gap:16px;">
                                <div style="flex:1;">
                                    <label style="display:block;font-weight:600;margin-bottom:4px;color:#495057;" for="section_select">Section</label>
                                    <select id="section_select" name="section_select" size="1"
                                            style="width:100%;padding:10px 12px;border:1px solid #ced4da;border-radius:6px;height:44px;box-sizing:border-box;background-color:#fff;">
                                        <option value="">Choose section</option></select>
                                </div>
                                <div style="flex:1;">
                                    <label style="display:block;font-weight:600;margin-bottom:4px;color:#495057;" for="section_subcat_select">Sub-category</label>
                                    <select id="section_subcat_select" name="section_subcat_select" size="1"
                                            style="width:100%;padding:10px 12px;border:1px solid #ced4da;border-radius:6px;height:44px;box-sizing:border-box;background-color:#fff;">
                                        <option value="">Is it a sub-category?</option></select>
                                </div>
                            </div>
                            
                            <div style="display:flex;gap:16px;">
                                
                                <div style="flex:1;">
                                    <label style="font-weight:600;display:block;margin-bottom:4px;color:#495057;">Publication assignments</label>
                                    <div id="modal_publications_container" style="max-height:160px;overflow-y:auto;border:1px solid #ced4da;padding:10px;border-radius:6px;background:#fff;display:flex;flex-direction:column;gap:8px;">
                                        <div style="font-size:14px;">[Pub 1 Checkbox]</div>
                                        <div style="font-size:14px;">[Pub 2 Checkbox]</div>
                                        <div style="font-size:14px;">[Pub 3 Checkbox]</div>
                                    </div>
                                    <div id="modal_publication_tags_display" style="margin-top:8px;"></div>
                                </div>
                                
                                <div style="flex:1;">
                                    <label style="font-weight:600;display:block;margin-bottom:4px;color:#495057;">Canonical publication</label>
                                    <div id="modal_canonical_container" style="max-height:160px;overflow-y:auto;border:1px solid #ced4da;padding:10px;border-radius:6px;background:#fff;display:flex;flex-direction:column;gap:8px;">
                                        <div style="font-size:14px;">[Canonical 1 Radio]</div>
                                        <div style="font-size:14px;">[Canonical 2 Radio]</div>
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>

                <div style="width:100%;flex:1 1 30%;min-width:320px;padding:24px;box-sizing:border-box;display:flex;flex-direction:column;gap:20px;background:#f8f9fa; border-left: 1px solid #e9ecef; overflow-y:auto;">
                    
                    <div style="border:1px solid #dee2e6;padding:16px;border-radius:6px;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,0.05);display:flex;flex-direction:column;gap:12px;">
                        <h4 style="font-size:1rem;font-weight:700;margin:0;padding-bottom:10px;border-bottom:1px solid #dee2e6;color:#343a40;">Status & Visibility</h4>
                        
                        <div style="display:flex;gap:20px; flex-wrap:wrap;">
                            <label style="display:flex;align-items:center;gap:8px;font-weight:600;color:#495057;" for="status_published">
                                <input type="radio" id="status_published" name="article_status" value="published" style="transform:scale(1.1);" />
                                Published
                            </label>
                            <label style="display:flex;align-items:center;gap:8px;font-weight:600;color:#495057;" for="status_draft">
                                <input type="radio" id="status_draft" name="article_status" value="draft" checked style="transform:scale(1.1);" />
                                Draft
                            </label>
                        </div>

                        <div style="display:flex;gap:16px;flex-wrap:wrap;border-top:1px dashed #e9ecef;padding-top:12px;">
                            <label style="display:flex;align-items:center;gap:8px;font-weight:600;color:#007bff;" for="is_featured">
                                <input type="checkbox" id="is_featured" name="is_featured" value="1" style="transform:scale(1.1);" />
                                Featured
                            </label>
                            <label style="display:flex;align-items:center;gap:8px;font-weight:600;color:#28a745;" for="modal_evergreen">
                                <input id="modal_evergreen" type="checkbox" style="transform:scale(1.1);" />
                                Evergreen
                            </label>
                            <label style="display:flex;align-items:center;gap:8px;font-weight:600;color:#6c757d;" for="modal_sponsored">
                                <input id="modal_sponsored" type="checkbox" style="transform:scale(1.1);" />
                                Sponsored
                            </label>
                        </div>

                        <div style="display:flex;gap:8px;padding-top:8px;">
                            <label style="display:flex;align-items:center;gap:8px;font-weight:600;color:#dc3545;" for="modal_headline_checkbox">
                                <input id="modal_headline_checkbox" type="checkbox" style="transform:scale(1.1);" />
                                Frontpage Headline
                            </label>
                        </div>
                    </div>

                    <div style="border:1px solid #dee2e6;padding:16px;border-radius:6px;background:#fff;box-shadow:0 1px 3px rgba(0,0,0,0.05);display:flex;flex-direction:column;gap:12px;">
                         <h4 style="font-size:1rem;font-weight:700;margin:0;padding-bottom:10px;border-bottom:1px solid #dee2e6;color:#343a40;">Publishing Schedule</h4>

                        <div style="display:flex;gap:16px;flex-wrap:wrap;">
                            <div style="flex:1; min-width: 120px;">
                                <label style="font-weight:600;display:block;margin-bottom:4px;color:#495057;font-size:0.95rem;">Publish From</label>
                                <input id="modal_publish_from" type="text" placeholder="dd-mm-yyyy" style="width:100%;padding:10px;border:1px solid #ced4da;border-radius:6px;box-sizing:border-box;" />
                            </div>
                            <div style="flex:1; min-width: 120px;">
                                <label style="font-weight:600;display:block;margin-bottom:4px;color:#495057;font-size:0.95rem;">Publish To</label>
                                <input id="modal_publish_to" type="text" placeholder="dd-mm-yyyy" style="width:100%;padding:10px;border:1px solid #ced4da;border-radius:6px;box-sizing:border-box;" />
                            </div>
                        </div>

                        <div>
                            <label style="display:flex;align-items:center;gap:8px;font-weight:600;color:#495057;">
                                <input id="modal_publish_now" type="checkbox" style="transform:scale(1.1);" />
                                Publish Now
                            </label>
                        </div>
                    </div>

                    <div id="modal_save_status" style="margin-top:6px;padding:8px;border-radius:4px;text-align:center;color:#6c757d;background:#e9ecef;">Save Status: Ready</div>
                </div>
            </form>
        </div>
        
        <div style="padding:10px 24px;border-top:1px solid #e9ecef;text-align:right;background:#fafafa;">
            <span style="font-size:12px;color:#6c757d;margin-right:8px;">Drag corner to resize</span>
            <div id="modal_resizer" style="width:14px;height:14px;display:inline-block;border-radius:3px;background:#ced4da;cursor:se-resize;transition:background-color 0.2s;"></div>
        </div>
    </div>
</div>



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
                    $('.overlay, #link-modal').fadeIn();
                } else {
                    const selectedText = window.getSelection().toString();
                    if (selectedText) {
                        $('#link-url').val('');
                        $('#link-title').val('');
                        $('#link-text').val(selectedText);
                        $('#link-target').val('_blank');
                        $('.overlay, #link-modal').fadeIn();
                    } else {
                        alert('Please select some text to create a link.');
                    }
                }
            });

            // Insert link
            $('#insert-link').click(function () {
                const url = $('#link-url').val().trim();
                const title = $('#link-title').val().trim();
                const linkText = $('#link-text').val();
                const target = $('#link-target').val();

                if (url && linkText) {
                    restoreSelection();

                    const anchorNode = getParentAnchorNode();
                    if (anchorNode) {
                        anchorNode.href = url;
                        anchorNode.title = title;
                        anchorNode.target = target;
                        anchorNode.textContent = linkText;
                    } else {
                        if (selectedRange) {
                            const linkHTML = `<a href="${url}" title="${title}" target="${target}">${linkText}</a>`;
                            const tempDiv = document.createElement('div');
                            tempDiv.innerHTML = linkHTML;
                            const linkNode = tempDiv.firstChild;
                            selectedRange.deleteContents();
                            selectedRange.insertNode(linkNode);
                            selectedRange.setStartAfter(linkNode);
                            selectedRange.setEndAfter(linkNode);
                            window.getSelection().removeAllRanges();
                            window.getSelection().addRange(selectedRange);
                        }
                    }
                    $('.overlay, #link-modal').fadeOut();
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
            $('#toggle-source').click(function () {
                const editor = $('#article_text');
                const sourceView = $('#source-view');
                
                if (isSourceView) {
                    editor.html(sourceView.val()).show();
                    sourceView.hide();
                    $(this).html('<i class="fas fa-code"></i>');
                } else {
                    sourceView.val(editor.html()).show();
                    editor.hide();
                    $(this).html('<i class="fas fa-edit"></i>');
                }
                isSourceView = !isSourceView;
            });

            // Close modals
            $('#close-link-modal, #close-link-modal2').click(function () {
                $('.overlay, #link-modal').fadeOut();
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
                const croppedCanvas = cropper.getCroppedCanvas({ width: 490, height: 310, fillColor: '#ffffff' });
                const croppedImage = croppedCanvas.toDataURL('image/jpeg');
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
                var image_attribution = $(this).prev().attr('title'); 
                var image_str_prepped = '<img src="' + image_link + '" alt="' + image_attribution + '" style="max-width: 100%; height: auto; margin: 10px 0; cursor: pointer;" class="editor-image" />';
                
                if (image_attribution != '') {
                    var image_attribution_str = '<p class="attribution_class"><br /><br /><em>'+ image_attribution +'</em></p>';
                }
                
                insertHTMLAtCursor(image_str_prepped, editor);
                $('.overlay, #modal_image_insert').fadeOut();
            });

            // Delete image on click in editor
            $(document).on('click', '.editor img', function(e) {
                e.preventDefault();
                const imgElement = this;
                Swal.fire({
                    title: 'Delete Image?',
                    text: 'Do you want to remove this image from the article?',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonText: 'Yes, delete it',
                    cancelButtonText: 'Cancel',
                    confirmButtonColor: '#dc3545',
                    cancelButtonColor: '#6c757d'
                }).then((result) => {
                    if (result.isConfirmed) {
                        $(imgElement).remove();
                        Swal.fire('Deleted!', 'The image has been removed.', 'success');
                    }
                });
            });

            function insertHTMLAtCursor(html, editor) {
                const selection = window.getSelection();
                if (selection.rangeCount > 0) {
                    const range = selection.getRangeAt(0);
                    const editorElement = editor[0];

                    if (editorElement.contains(range.commonAncestorContainer)) {
                        const tempDiv = document.createElement("div");
                        tempDiv.innerHTML = html;

                        Array.from(tempDiv.childNodes).forEach((node) => {
                            range.insertNode(node);
                        });

                        range.collapse(false);
                        selection.removeAllRanges();
                        selection.addRange(range);
                    } else {
                        alert("Please place the cursor inside the editor.");
                    }
                } else {
                    alert("Please place the cursor inside the editor.");
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
                                    const imgHtml = `<img src="${image.largeImageURL}" alt="${image.tags}" style="max-width: 100%;">`;
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

            // Form submission
            $("#ten_add_article").submit(function(event) {
                event.preventDefault();
                
                var tmp_var_title = $('#article_title').val();
                var tmp_var_terms = $('#article_submission_terms_checkbox').is(":checked");
                
                if (tmp_var_title == '' || tmp_var_title == false) {
                    Swal.fire('Error', 'Please enter a title for this article', 'error');
                    return;
                }
                else if (tmp_var_terms == false) {
                    Swal.fire('Error', 'You must agree to the Terms of Article Submission', 'error');
                    return;
                }
                
                // Get article text from editor
                var article_text = $("#article_text").html();
                
                // Show loading
                $('#loading_image').show();
                
                var datastring = $("#ten_add_article").serialize();
                datastring += '&article_text=' + encodeURIComponent(article_text);
                
                $.ajax({
                    type: "POST",
                    url: "save_article.php",
                    data: datastring,
                    dataType: "json",
                    success: function(data) {
                        $('#loading_image').hide();
                        
                        if (data.status === 'success') {
                            var button_str = '<button type="button" class="btn btn-primary" onclick="location.reload();">Submit Another Draft Article</button>' +
                                    '<button type="button" class="btn btn-primary" onclick="$(\'#modal_saved_draft_article\').fadeOut(); $(\'.tab[data-tab=view-articles]\').click();">View All Articles</button>';
                            
                            $('#footer_buttons').html(button_str);
                            $('#draft_article_saved_response').html(data.message);
                            $('.overlay, #modal_saved_draft_article').fadeIn();
                            
                            // Clear the form
                            $('#article_title').val('');
                            $('#article_text').html('');
                            $('#article_submission_terms_checkbox').prop('checked', true);
                        } else {
                            Swal.fire('Error', data.message || 'There was an error (1) saving your article. Please try again.', 'error');
                        }
                    },
                    error: function(xhr, status, error) {
                        $('#loading_image').hide();
                        console.error('AJAX Error:', status, error);
                        Swal.fire('Error', 'There was an error (2) saving your article. Please try again.', 'error');
                    }
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

            // Pixabay toggle button
            $('#search-pixabay-toggle').click(function() {
                const pixabaySection = $('#pixabay-search-section');
                if (pixabaySection.is(':visible')) {
                    pixabaySection.hide();
                    $(this).html('<i class="fas fa-search"></i> Search Pixabay Images');
                } else {
                    pixabaySection.show();
                    $(this).html('<i class="fas fa-times"></i> Hide Pixabay Search');
                }
            });

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
                
                const croppedImage = croppedCanvas.toDataURL('image/jpeg', 0.9);
                
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
        const limit = 20;
        const maxPageLinks = 10;

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
                      '&sort_dir=' + encodeURIComponent(currentSortDir);

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

            rows.forEach(function (row) {
                // publications
                let pubsHtml = '';
                if (Array.isArray(row.publication_links) && row.publication_links.length) {
                    row.publication_links.forEach(function (p) {
                        pubsHtml += buildBadgeLink(p.name, p.url);
                    });
                }

                // status badge inline styles
                let statusStyle = 'background:#6c757d;color:#fff;padding:4px 8px;border-radius:6px;font-size:12px;display:inline-block;';
                if (row.state === 'draft') statusStyle = 'background:#dc3545;color:#fff;padding:4px 8px;border-radius:6px;font-size:12px;display:inline-block;';
                if (row.state === 'under review') statusStyle = 'background:#ffc107;color:#212529;padding:4px 8px;border-radius:6px;font-size:12px;display:inline-block;';
                if (row.state === 'published') statusStyle = 'background:#28a745;color:#fff;padding:4px 8px;border-radius:6px;font-size:12px;display:inline-block;';

                const editBtn = row.editable == 1
                    ? '<button type="button" onclick="openEdit(' + row.id + ')" style="border:1px solid #0d6efd;background:#fff;color:#0d6efd;padding:6px 8px;border-radius:6px;cursor:pointer;">'
                        + '<i style="font-style:normal;">✎</i></button>'
                    : '';

                const tr = document.createElement('tr');
                tr.style.background = '#fff';
                tr.style.borderBottom = '1px solid #e9ecef';

                tr.innerHTML =
                    '<td style="padding:10px 8px;border:1px solid #dee2e6;vertical-align:middle;width:60px;">' + safeText(row.id) + '</td>' +
                    '<td style="padding:10px 8px;border:1px solid #dee2e6;vertical-align:middle;">' + safeText(row.title) + '</td>' +
                    '<td style="padding:10px 8px;border:1px solid #dee2e6;vertical-align:middle;">' + safeText(row.journalist_name) + '</td>' +
                    '<td style="padding:10px 8px;border:1px solid #dee2e6;vertical-align:middle;">' + pubsHtml + '</td>' +
                    '<td style="padding:10px 8px;border:1px solid #dee2e6;vertical-align:middle;">' + '<span style="' + statusStyle + '">' + safeText(row.state) + '</span>' + '</td>' +
                    '<td style="padding:10px 8px;border:1px solid #dee2e6;vertical-align:middle;width:160px;">' + safeText(row.submission_date) + '</td>' +
                    '<td style="padding:10px 8px;border:1px solid #dee2e6;vertical-align:middle;text-align:center;width:90px;">' + editBtn + '</td>';

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
                    if (span) span.textContent = (currentSortDir === 'ASC') ? '↑' : '↓';

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

        // Init
        initSorting();
        loadArticles();
    });

    (function () {
    // Modal element refs
    const modal = document.getElementById('articleEditModal');
    const modalDialog = document.getElementById('articleEditModalDialog');
    const modalArticleIdSpan = document.getElementById('modalArticleId');
    const closeBtn = document.getElementById('closeArticleModal');
    const saveBtn = document.getElementById('saveArticleBtn');
    const fullscreenBtn = document.getElementById('modal_fullscreen_toggle');
    const resizer = document.getElementById('modal_resizer');

    // Inputs & containers
    const fld_title = document.getElementById('modal_title');
    const fld_alias = document.getElementById('modal_alias');
    const fld_tags = document.getElementById('modal_tags');
    const fld_section = document.getElementById('modal_section');
    const fld_section_subcat = document.getElementById('modal_section_subcat');
    const fld_author = document.getElementById('modal_author');
    const pub_container = document.getElementById('modal_publications_container');
    const pub_tags_display = document.getElementById('modal_publication_tags_display');
    const fld_evergreen = document.getElementById('modal_evergreen');
    const fld_featured = document.getElementById('modal_featured');
    const fld_sponsored = document.getElementById('modal_sponsored');
    const fld_headline = document.getElementById('modal_headline');
    const fld_note = document.getElementById('modal_note');
    const fld_publish_from = document.getElementById('modal_publish_from');
    const fld_publish_to = document.getElementById('modal_publish_to');
    const fld_publish_now = document.getElementById('modal_publish_now');
    const canonical_container = document.getElementById('modal_canonical_container');
    const save_status = document.getElementById('modal_save_status');

    const editorSlot = document.getElementById('modal_editor_slot');

    let activeArticleId = null;
    let movedEditor = false;
    let originalEditor = null;
    let originalEditorParent = null;
    let originalEditorNextSibling = null;
    let placeholderNode = null;
    let cloneUsed = false;
    let isFullscreen = false;

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
        // restore editor if moved
        if (movedEditor) {
            restoreMovedEditor();
        }
        // cleanup clone
        if (cloneUsed) {
            cleanupClonedEditor();
        }
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

    // Fullscreen toggle
    function toggleFullscreen() {
        if (!isFullscreen) {
            modalDialog.style.position = 'fixed';
            modalDialog.style.left = '0';
            modalDialog.style.top = '0';
            modalDialog.style.width = '100%';
            modalDialog.style.height = '100%';
            modalDialog.style.maxWidth = '100%';
            modalDialog.style.maxHeight = '100%';
            modalDialog.style.borderRadius = '0';
            modalDialog.style.margin = '0';
            isFullscreen = true;
            fullscreenBtn.textContent = '⤡';
        } else {
            modalDialog.style.position = '';
            modalDialog.style.left = '';
            modalDialog.style.top = '';
            modalDialog.style.width = '';
            modalDialog.style.height = '';
            modalDialog.style.maxWidth = '';
            modalDialog.style.maxHeight = '';
            modalDialog.style.borderRadius = '';
            modalDialog.style.margin = '28px auto';
            isFullscreen = false;
            fullscreenBtn.textContent = '⤢';
        }
    }

    fullscreenBtn.addEventListener('click', toggleFullscreen);

    // Resizer logic (resize modal dialog)
    (function initResizer() {
        let dragging = false;
        let startX, startY, startWidth, startHeight;

        resizer.addEventListener('mousedown', function (e) {
            e.preventDefault();
            dragging = true;
            startX = e.clientX;
            startY = e.clientY;
            const rect = modalDialog.getBoundingClientRect();
            startWidth = rect.width;
            startHeight = rect.height;
            document.body.style.userSelect = 'none';
        });

        document.addEventListener('mousemove', function (e) {
            if (!dragging) return;
            const dx = e.clientX - startX;
            const dy = e.clientY - startY;
            modalDialog.style.width = Math.max(600, startWidth + dx) + 'px';
            modalDialog.style.height = Math.max(400, startHeight + dy) + 'px';
        });

        document.addEventListener('mouseup', function () {
            if (dragging) {
                dragging = false;
                document.body.style.userSelect = '';
            }
        });
    })();

    // openEdit exposed for table buttons to call
    window.openEdit = function (id) {
        activeArticleId = id;
        modalArticleIdSpan.textContent = '#' + id;
        save_status.style.color = '#6c757d';
        save_status.textContent = 'Loading article…';
        showModal();

        // Clear fields
        fld_title.value = '';
        fld_alias.value = '';
        fld_tags.value = '';
        fld_section.value = '';
        fld_section_subcat.value = '';
        fld_author.value = '';
        fld_evergreen.checked = false;
        fld_featured.checked = false;
        fld_sponsored.checked = false;
        fld_headline.value = '';
        fld_note.value = '';
        fld_publish_from.value = '';
        fld_publish_to.value = '';
        fld_publish_now.checked = false;
        pub_container.innerHTML = '';
        pub_tags_display.innerHTML = '';
        canonical_container.innerHTML = '';

        // Try to move the editor in first
        const moved = moveEditorIntoModal();
        if (!moved) {
            // fallback to clone
            cloneEditorToModal();
        }

        // POST load article
        const fd = new FormData();
        fd.append('id', id);

        fetch('/management/ajax/get_article_data.php', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
        })
        .then(r => r.json())
        .then(json => {
            if (!json || json.status !== 'success') {
                save_status.style.color = '#dc3545';
                save_status.textContent = (json && json.message) ? json.message : 'Failed to load article.';
                return;
            }

            // Populate fields
            fld_title.value = json.title || '';
            fld_alias.value = json.article_alias || '';
            fld_tags.value = json.article_tags || '';
            fld_section.value = json.section || '';
            fld_section_subcat.value = json.section_subcat || '';
            fld_author.value = json.author || '';
            fld_evergreen.checked = (json.evergreen == 1 || json.evergreen == '1' || json.evergreen === true);
            fld_featured.checked = (json.featured == 1 || json.featured == '1' || json.featured === true);
            fld_sponsored.checked = (json.sponsored == 1 || json.sponsored == '1' || json.sponsored === true);
            fld_headline.value = json.headline || '';
            fld_note.value = json.note || '';
            fld_publish_from.value = json.publish_from || '';
            fld_publish_to.value = json.publish_to || '';
            fld_publish_now.checked = (json.publish_now == 1 || json.publish_now == '1' || json.publish_now === true);

            // Insert server publications HTML and tags
            pub_container.innerHTML = json.publications_verified || '';
            pub_tags_display.innerHTML = json.publication_tags || '';
            canonical_container.innerHTML = '<div style="font-size:13px;color:#6c757d;margin-top:6px;">Canonical: <strong>' + (json.canonical_pub || '') + '</strong></div>';

            // Populate editor content:
            // If we moved the editor, we need to insert the article HTML into the editor's API.
            if (movedEditor && originalEditor) {
                // Try common ways your bespoke editor might expose a setContent API:
                // 1) editor instance stored as data-editor on container
                // 2) a global setEditorContent(editorElement, html) helper
                // 3) a child textarea we can set .value on
                let inserted = false;
                try {
                    // 1) data-editor instance
                    const inst = originalEditor.__editorInstance || originalEditor.dataset.editorInstance;
                    if (inst && typeof inst.setContent === 'function') {
                        inst.setContent(json.article || '');
                        inserted = true;
                    }
                } catch (e) {}

                try {
                    if (!inserted && typeof window.setEditorContent === 'function') {
                        window.setEditorContent(originalEditor, json.article || '');
                        inserted = true;
                    }
                } catch (e) {}

                if (!inserted) {
                    // fallback: find a textarea inside originalEditor and set its value
                    const ta = originalEditor.querySelector('textarea');
                    if (ta) {
                        ta.value = json.article || '';
                        // also dispatch input event if editor listens
                        ta.dispatchEvent(new Event('input', { bubbles: true }));
                        inserted = true;
                    } else {
                        // lastly, inject raw HTML into editor DOM if safe
                        originalEditor.innerHTML = json.article || originalEditor.innerHTML;
                        inserted = true;
                    }
                }
            } else if (cloneUsed) {
                // If we cloned, attempt to set content on the clone similarly
                const clone = editorSlot.querySelector('.editor-container') || editorSlot.firstElementChild;
                if (clone) {
                    try {
                        if (typeof window.initCustomEditor === 'function') {
                            // try re-init with provided init function (some inits accept element arg)
                            if (window.initCustomEditor.length === 1) {
                                window.initCustomEditor(clone);
                            } else {
                                window.initCustomEditor();
                            }
                        }
                    } catch (e) {}
                    // attempt to set textarea content if exists
                    const ta = clone.querySelector('textarea');
                    if (ta) {
                        ta.value = json.article || '';
                        ta.dispatchEvent(new Event('input', { bubbles: true }));
                    } else {
                        clone.innerHTML = json.article || clone.innerHTML;
                    }
                }
            } // end editor handling

            save_status.style.color = '#6c757d';
            save_status.textContent = 'Loaded. Edit fields and click Save.';
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
            // try common access points
            const ta = originalEditor.querySelector('textarea');
            if (ta) content = ta.value.trim();
            // try instance helper
            const inst = originalEditor.__editorInstance || originalEditor.dataset.editorInstance;
            if (!content && inst && typeof inst.getContent === 'function') {
                content = inst.getContent().trim();
            }
            if (!content) {
                content = originalEditor.textContent.trim();
            }
        } else if (cloneUsed) {
            const clone = editorSlot.querySelector('.editor-container') || editorSlot.firstElementChild;
            if (clone) {
                const ta = clone.querySelector('textarea');
                if (ta) content = ta.value.trim();
                const inst = clone.__editorInstance || clone.dataset.editorInstance;
                if (!content && inst && typeof inst.getContent === 'function') {
                    content = inst.getContent().trim();
                }
                if (!content) content = clone.textContent.trim();
            }
        }

        if (!content) {
            save_status.style.color = '#dc3545';
            save_status.textContent = 'Article content cannot be empty.';
            return false;
        }

        return true;
    }

    // Save handler
    saveBtn.addEventListener('click', function () {
        if (!activeArticleId) return;
        if (!validateBeforeSave()) return;

        saveBtn.disabled = true;
        saveBtn.textContent = 'Saving…';
        save_status.style.color = '#6c757d';
        save_status.textContent = 'Saving article…';

        // Extract content from editor
        let articleHtml = '';
        if (movedEditor && originalEditor) {
            // try instance API
            const inst = originalEditor.__editorInstance || originalEditor.dataset.editorInstance;
            try {
                if (inst && typeof inst.getContent === 'function') {
                    articleHtml = inst.getContent();
                }
            } catch (e) {}
            if (!articleHtml) {
                const ta = originalEditor.querySelector('textarea');
                if (ta) articleHtml = ta.value;
            }
            if (!articleHtml) {
                articleHtml = originalEditor.innerHTML;
            }
        } else if (cloneUsed) {
            const clone = editorSlot.querySelector('.editor-container') || editorSlot.firstElementChild;
            if (clone) {
                const inst = clone.__editorInstance || clone.dataset.editorInstance;
                if (inst && typeof inst.getContent === 'function') {
                    articleHtml = inst.getContent();
                } else {
                    const ta = clone.querySelector('textarea');
                    if (ta) articleHtml = ta.value;
                    else articleHtml = clone.innerHTML;
                }
            }
        }

        // chosen publications
        const pubInputs = pub_container.querySelectorAll('input[type="checkbox"]');
        const chosenPubs = [];
        pubInputs.forEach(function (cb) {
            if (cb.checked) {
                let pubName = cb.id || '';
                if (pubName && pubName.indexOf('_checkbox') !== -1) pubName = pubName.replace('_checkbox', '');
                chosenPubs.push(pubName);
            }
        });

        const canonicalRadio = pub_container.querySelector('input[type="radio"][name="canonical_radio"]:checked');
        const canonicalVal = canonicalRadio ? canonicalRadio.value : '';

        // Prepare POST
        const fd = new FormData();
        fd.append('id', activeArticleId);
        fd.append('title', fld_title.value || '');
        fd.append('article_text', articleHtml || '');
        fd.append('alias', fld_alias.value || '');
        fd.append('tags', fld_tags.value || '');
        fd.append('section', fld_section.value || '');
        fd.append('section_subcat', fld_section_subcat.value || '');
        fd.append('author', fld_author.value || '');
        fd.append('publications', chosenPubs.join(','));
        fd.append('canonical', canonicalVal || '');
        fd.append('evergreen', fld_evergreen.checked ? '1' : '0');
        fd.append('featured', fld_featured.checked ? '1' : '0');
        fd.append('sponsored', fld_sponsored.checked ? '1' : '0');
        fd.append('frontpage_temp', fld_headline.value || '');
        fd.append('note', fld_note.value || '');
        fd.append('publish_now', fld_publish_now.checked ? '1' : '0');
        fd.append('publish_from', fld_publish_from.value || '');
        fd.append('publish_to', fld_publish_to.value || '');

        fetch('/management/ajax/save_article.php', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin'
        })
        .then(r => r.json())
        .then(resp => {
            saveBtn.disabled = false;
            saveBtn.textContent = 'Save';
            if (resp && resp.status === 'success') {
                save_status.style.color = '#198754';
                save_status.textContent = 'Saved successfully.';
                // refresh list
                if (typeof loadArticles === 'function') loadArticles();
                // close modal shortly
                setTimeout(function () { hideModal(); }, 700);
            } else {
                save_status.style.color = '#dc3545';
                save_status.textContent = (resp && resp.message) ? resp.message : 'Failed to save.';
                console.error(resp);
            }
        })
        .catch(err => {
            saveBtn.disabled = false;
            saveBtn.textContent = 'Save';
            save_status.style.color = '#dc3545';
            save_status.textContent = 'Error saving article.';
            console.error(err);
        });
    });

    // Close handlers
    closeBtn.addEventListener('click', hideModal);
    modal.addEventListener('click', function (e) { if (e.target === modal) hideModal(); });

    // Esc handled by focus trap

    // Ensure modal can't be left with editor missing — on unload restore
    window.addEventListener('beforeunload', function () {
        if (movedEditor) restoreMovedEditor();
    });

})();


    </script>
</body>
</html>
