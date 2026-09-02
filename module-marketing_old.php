<?php
require_once 'config.php';
requireLogin();

$currentPage = 'marketing';
$pageTitle = t('marketing.title', 'Marketing & Social Media');

// Check permissions
$canView = hasPermission('marketing.view') || isAdmin();
$canCreate = hasPermission('marketing.create') || isAdmin();
$canEdit = hasPermission('marketing.edit') || isAdmin();
$canDelete = hasPermission('marketing.delete') || isAdmin();

if (!$canView) {
    header('Location: dashboard.php');
    exit;
}

?>
<!DOCTYPE html>
<html lang="<?php echo $_SESSION['language'] ?? 'en'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - TEN Management</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link rel="stylesheet" href="css/backend-style.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            background: #f3f4f6;
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
            color: #667eea;
        }
        
        .page-header p {
            color: #6b7280;
            margin-top: 8px;
            font-size: 15px;
        }
        
        /* Tabs */
        .tabs {
            display: flex;
            gap: 8px;
            margin-bottom: 24px;
            border-bottom: 2px solid #e5e7eb;
            flex-wrap: wrap;
        }
        
        .tab {
            padding: 12px 24px;
            background: none;
            border: none;
            border-bottom: 3px solid transparent;
            font-size: 15px;
            font-weight: 600;
            color: #6b7280;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .tab:hover {
            color: #667eea;
            background: rgba(102, 126, 234, 0.05);
        }
        
        .tab.active {
            color: #667eea;
            border-bottom-color: #667eea;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        /* Social Media Canvas Layout */
        .canvas-layout {
            display: grid;
            grid-template-columns: 1fr 320px;
            gap: 20px;
            height: calc(100vh - 280px);
        }
        
        .canvas-main {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            display: flex;
            flex-direction: column;
            position: relative;
        }
        
        .canvas-toolbar {
            padding: 16px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: center;
        }
        
        .toolbar-group {
            display: flex;
            gap: 8px;
            align-items: center;
            padding: 0 12px;
            border-right: 1px solid #e5e7eb;
        }
        
        .toolbar-group:last-child {
            border-right: none;
            margin-left: auto;
        }
        
        .toolbar-btn {
            padding: 8px 16px;
            background: white;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            color: #374151;
            cursor: pointer;
            transition: all 0.2s;
            display: flex;
            align-items: center;
            gap: 6px;
        }
        
        .toolbar-btn:hover {
            background: #f9fafb;
            border-color: #667eea;
            color: #667eea;
        }
        
        .toolbar-btn.active {
            background: #667eea;
            color: white;
            border-color: #667eea;
        }
        
        .toolbar-btn i {
            font-size: 14px;
        }
        
        .color-picker {
            width: 40px;
            height: 40px;
            border: 2px solid #d1d5db;
            border-radius: 6px;
            cursor: pointer;
        }
        
        .canvas-workspace {
            flex: 1;
            padding: 20px;
            overflow: auto;
            position: relative;
            background: #f9fafb;
        }
        
        .canvas-area {
            background: white;
            border: 2px dashed #d1d5db;
            border-radius: 8px;
            min-height: 500px;
            position: relative;
            margin: 0 auto;
            max-width: 1080px;
        }
        
        .canvas-area.fullscreen {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            z-index: 9999;
            max-width: none;
            border-radius: 0;
            margin: 0;
        }
        
        .carousel-slide {
            width: 100%;
            height: 100%;
            position: absolute;
            top: 0;
            left: 0;
            background-size: cover;
            background-position: center;
            display: none;
        }
        
        .carousel-slide.active {
            display: block;
        }
        
        .canvas-element {
            position: absolute;
            cursor: move;
            border: 2px solid transparent;
        }
        
        .canvas-element.selected {
            border-color: #667eea;
        }
        
        .canvas-element.text-element {
            padding: 10px;
            background: transparent;
            min-width: 100px;
            min-height: 40px;
        }
        
        .canvas-element.image-element {
            background-size: contain;
            background-repeat: no-repeat;
            background-position: center;
            min-width: 100px;
            min-height: 100px;
        }
        
        .resize-handle {
            width: 12px;
            height: 12px;
            background: #667eea;
            border: 2px solid white;
            border-radius: 50%;
            position: absolute;
            display: none;
        }
        
        .canvas-element.selected .resize-handle {
            display: block;
        }
        
        .resize-handle.se { bottom: -6px; right: -6px; cursor: se-resize; }
        .resize-handle.sw { bottom: -6px; left: -6px; cursor: sw-resize; }
        .resize-handle.ne { top: -6px; right: -6px; cursor: ne-resize; }
        .resize-handle.nw { top: -6px; left: -6px; cursor: nw-resize; }
        
        /* Articles Sidebar */
        .articles-sidebar {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            display: flex;
            flex-direction: column;
            height: 100%;
        }
        
        .sidebar-header {
            padding: 16px;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .sidebar-header h3 {
            font-size: 16px;
            font-weight: 600;
            color: #111827;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .sidebar-search {
            padding: 12px 16px;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .sidebar-search input {
            width: 100%;
            padding: 8px 12px;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            font-size: 14px;
        }
        
        .articles-list {
            flex: 1;
            overflow-y: auto;
            padding: 12px;
        }
        
        .article-item {
            padding: 12px;
            margin-bottom: 8px;
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            cursor: grab;
            transition: all 0.2s;
        }
        
        .article-item:hover {
            background: white;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }
        
        .article-item.dragging {
            opacity: 0.5;
            cursor: grabbing;
        }
        
        .article-title {
            font-size: 14px;
            font-weight: 600;
            color: #111827;
            margin-bottom: 6px;
        }
        
        .article-snippet {
            font-size: 13px;
            color: #6b7280;
            line-height: 1.4;
            display: -webkit-box;
            -webkit-line-clamp: 3;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        
        .article-meta {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-top: 8px;
            font-size: 12px;
            color: #9ca3af;
        }
        
        /* Buttons */
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.4);
        }
        
        .btn-secondary {
            background: white;
            color: #374151;
            border: 1px solid #d1d5db;
        }
        
        .btn-secondary:hover {
            background: #f9fafb;
            border-color: #667eea;
            color: #667eea;
        }
        
        .btn-success {
            background: #10b981;
            color: white;
        }
        
        .btn-success:hover {
            background: #059669;
        }
        
        .btn-danger {
            background: #ef4444;
            color: white;
        }
        
        .btn-danger:hover {
            background: #dc2626;
        }
        
        /* Modal */
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 10000;
            align-items: center;
            justify-content: center;
        }
        
        .modal.active {
            display: flex;
        }
        
        .modal-content {
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            max-width: 800px;
            width: 90%;
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .modal-header {
            padding: 20px 24px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .modal-header h2 {
            font-size: 20px;
            font-weight: 700;
            color: #111827;
            margin: 0;
        }
        
        .modal-close {
            width: 32px;
            height: 32px;
            border: none;
            background: none;
            font-size: 20px;
            color: #6b7280;
            cursor: pointer;
            border-radius: 6px;
            transition: all 0.2s;
        }
        
        .modal-close:hover {
            background: #f3f4f6;
            color: #111827;
        }
        
        .modal-body {
            padding: 24px;
        }
        
        .modal-footer {
            padding: 20px 24px;
            border-top: 1px solid #e5e7eb;
            display: flex;
            justify-content: flex-end;
            gap: 12px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            display: block;
            font-size: 14px;
            font-weight: 600;
            color: #374151;
            margin-bottom: 8px;
        }
        
        .form-input,
        .form-select,
        .form-textarea {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 14px;
            font-family: inherit;
        }
        
        .form-textarea {
            resize: vertical;
            min-height: 100px;
        }
        
        .form-input:focus,
        .form-select:focus,
        .form-textarea:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
        }
        
        .checkbox-group {
            display: flex;
            flex-direction: column;
            gap: 12px;
        }
        
        .checkbox-item {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .checkbox-item input[type="checkbox"] {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        
        .checkbox-item label {
            font-size: 14px;
            color: #374151;
            cursor: pointer;
        }
        
        /* Template Gallery */
        .template-gallery {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 16px;
        }
        
        .template-card {
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            padding: 12px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .template-card:hover {
            border-color: #667eea;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.2);
        }
        
        .template-card.selected {
            border-color: #667eea;
            background: rgba(102, 126, 234, 0.05);
        }
        
        .template-preview {
            width: 100%;
            height: 150px;
            background: #f3f4f6;
            border-radius: 6px;
            margin-bottom: 8px;
            background-size: cover;
            background-position: center;
        }
        
        .template-name {
            font-size: 14px;
            font-weight: 600;
            color: #111827;
        }
        
        .template-slides {
            font-size: 12px;
            color: #6b7280;
            margin-top: 4px;
        }
        
        /* Slide Navigation */
        .slide-nav {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            background: #f9fafb;
            border-top: 1px solid #e5e7eb;
        }
        
        .slide-nav-btn {
            padding: 6px 12px;
            background: white;
            border: 1px solid #d1d5db;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .slide-nav-btn:hover {
            border-color: #667eea;
            color: #667eea;
        }
        
        .slide-indicator {
            flex: 1;
            text-align: center;
            font-size: 14px;
            font-weight: 600;
            color: #374151;
        }
        
        .slide-thumbnails {
            display: flex;
            gap: 8px;
        }
        
        .slide-thumb {
            width: 60px;
            height: 60px;
            background: white;
            border: 2px solid #e5e7eb;
            border-radius: 6px;
            cursor: pointer;
            transition: all 0.2s;
            background-size: cover;
            background-position: center;
        }
        
        .slide-thumb.active {
            border-color: #667eea;
            box-shadow: 0 2px 8px rgba(102, 126, 234, 0.3);
        }
        
        /* Export Preview */
        .export-preview {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            padding: 16px;
            margin-bottom: 16px;
        }
        
        .preview-platform {
            margin-bottom: 16px;
        }
        
        .preview-platform h4 {
            font-size: 14px;
            font-weight: 600;
            color: #111827;
            margin-bottom: 8px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .preview-text {
            background: white;
            padding: 12px;
            border-radius: 6px;
            font-size: 14px;
            color: #374151;
            line-height: 1.6;
            white-space: pre-wrap;
        }
        
        /* Stickers */
        .stickers-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
            gap: 16px;
            padding: 16px;
            max-height: 500px;
            overflow-y: auto;
        }
        
        .sticker-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            padding: 16px;
            background: #f9fafb;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            cursor: pointer;
            transition: all 0.2s;
            min-height: 120px;
        }
        
        .sticker-item:hover {
            border-color: #667eea;
            background: rgba(102, 126, 234, 0.05);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        
        .sticker-item img {
            max-width: 80px;
            max-height: 80px;
            margin-bottom: 8px;
        }
        
        .sticker-name {
            font-size: 12px;
            color: #6b7280;
            text-align: center;
            font-weight: 500;
        }
        
        /* Canvas Elements */
        .canvas-element {
            position: absolute;
            cursor: move;
            user-select: none;
        }
        
        .canvas-element.selected {
            outline: 2px solid #667eea;
            outline-offset: 2px;
        }
        
        .canvas-element .resize-handle {
            position: absolute;
            width: 12px;
            height: 12px;
            background: #667eea;
            border: 2px solid white;
            border-radius: 50%;
            z-index: 1000;
        }
        
        .canvas-element .resize-handle.nw {
            top: -6px;
            left: -6px;
            cursor: nw-resize;
        }
        
        .canvas-element .resize-handle.ne {
            top: -6px;
            right: -6px;
            cursor: ne-resize;
        }
        
        .canvas-element .resize-handle.sw {
            bottom: -6px;
            left: -6px;
            cursor: sw-resize;
        }
        
        .canvas-element .resize-handle.se {
            bottom: -6px;
            right: -6px;
            cursor: se-resize;
        }
        
        .element-delete-btn {
            position: absolute;
            top: -12px;
            right: -12px;
            width: 28px;
            height: 28px;
            background: #ef4444;
            color: white;
            border: 2px solid white;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            z-index: 1001;
            transition: all 0.2s;
        }
        
        .element-delete-btn:hover {
            background: #dc2626;
            transform: scale(1.1);
        }
        
        /* Text Style Toolbar */
        .text-style-toolbar {
            position: fixed;
            background: white;
            padding: 16px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            z-index: 1000;
            display: none;
        }
        
        .preview-meta {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 12px;
            margin-top: 12px;
        }
        
        .meta-item {
            background: white;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 13px;
        }
        
        .meta-label {
            font-weight: 600;
            color: #6b7280;
            margin-bottom: 4px;
        }
        
        .meta-value {
            color: #111827;
        }
        
        /* Stats Dashboard */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 24px;
        }
        
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .stat-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }
        
        .stat-title {
            font-size: 14px;
            font-weight: 600;
            color: #6b7280;
        }
        
        .stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 18px;
        }
        
        .stat-icon.instagram {
            background: linear-gradient(135deg, #f09433 0%, #e6683c 25%, #dc2743 50%, #cc2366 75%, #bc1888 100%);
            color: white;
        }
        
        .stat-icon.facebook {
            background: #1877f2;
            color: white;
        }
        
        .stat-icon.twitter {
            background: #1da1f2;
            color: white;
        }
        
        .stat-value {
            font-size: 32px;
            font-weight: 700;
            color: #111827;
            margin-bottom: 8px;
        }
        
        .stat-label {
            font-size: 14px;
            color: #6b7280;
        }
        
        .stat-change {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 13px;
            font-weight: 600;
            margin-top: 8px;
        }
        
        .stat-change.positive {
            color: #10b981;
        }
        
        .stat-change.negative {
            color: #ef4444;
        }
        
        .posts-table {
            background: white;
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        
        .posts-table table {
            width: 100%;
            border-collapse: collapse;
        }
        
        .posts-table th {
            padding: 16px;
            text-align: left;
            font-size: 13px;
            font-weight: 600;
            color: #6b7280;
            background: #f9fafb;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .posts-table td {
            padding: 16px;
            font-size: 14px;
            color: #374151;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .posts-table tr:hover {
            background: #f9fafb;
        }
        
        .platform-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .platform-badge.instagram {
            background: rgba(225, 48, 108, 0.1);
            color: #e1306c;
        }
        
        .platform-badge.facebook {
            background: rgba(24, 119, 242, 0.1);
            color: #1877f2;
        }
        
        .platform-badge.twitter {
            background: rgba(29, 161, 242, 0.1);
            color: #1da1f2;
        }
        
        .post-link {
            color: #667eea;
            text-decoration: none;
            font-weight: 500;
        }
        
        .post-link:hover {
            text-decoration: underline;
        }
        
        @media (max-width: 1200px) {
            .canvas-layout {
                grid-template-columns: 1fr;
            }
            
            .articles-sidebar {
                height: 400px;
            }
        }
        
        @media (max-width: 768px) {
            .main-content {
                margin-left: 0;
            }
            
            .content-area {
                padding: 16px;
            }
            
            .form-row {
                grid-template-columns: 1fr;
            }
            
            .template-gallery {
                grid-template-columns: 1fr;
            }
            
            .stats-grid {
                grid-template-columns: 1fr;
            }
        }

        /* Fullscreen Modal */
        .canvas-main.fullscreen-modal {
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            right: 0 !important;
            bottom: 0 !important;
            z-index: 9999 !important;
            width: 100vw !important;
            height: 100vh !important;
            border-radius: 0 !important;
        }

        /* Delete Button on Elements */
        .element-delete-btn {
            position: absolute;
            top: -10px;
            right: -10px;
            width: 24px;
            height: 24px;
            background: #ef4444;
            color: white;
            border: 2px solid white;
            border-radius: 50%;
            display: none;
            cursor: pointer;
        }

        .canvas-element.selected .element-delete-btn {
            display: flex;
            align-items: center;
            justify-content: center;
        }

        /* Text Toolbar */
        .text-style-toolbar {
            position: fixed;
            top: 120px;
            right: 360px;
            background: white;
            padding: 12px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            display: none;
            gap: 8px;
            z-index: 1000;
        }

        /* Stickers */
        .stickers-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
            gap: 16px;
        }

        .sticker-item {
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            padding: 12px;
            cursor: pointer;
            text-align: center;
        }

        .sticker-item:hover {
            border-color: #667eea;
        }
    </style>
</head>
<body>
    <?php include 'includes/sidebar.php'; ?>
    
    <div class="main-content">
        <?php include 'includes/header.php'; ?>
        
        <div class="content-area">
            <div class="page-header">
                <h1>
                    <i class="fas fa-bullhorn"></i>
                    <?php echo t('marketing.title', 'Marketing & Social Media'); ?>
                </h1>
                <p><?php echo t('marketing.subtitle', 'Create, manage, and publish social media content across multiple platforms'); ?></p>
            </div>
            
            <div class="tabs">
                <button class="tab active" onclick="switchTab('social-media')">
                    <i class="fab fa-instagram"></i> <?php echo t('marketing.tab.social_media', 'Social Media Creator'); ?>
                </button>
                <button class="tab" onclick="switchTab('trending-topics')">
                    <i class="fab fa-chart"></i> <?php echo t('marketing.tab.trending_topics', 'Trending Topics'); ?>
                </button>
                <button class="tab" onclick="switchTab('statistics')">
                    <i class="fas fa-chart-line"></i> <?php echo t('marketing.tab.statistics', 'Statistics & Analytics'); ?>
                </button>
                <button class="tab" onclick="switchTab('campaigns')">
                    <i class="fas fa-rocket"></i> <?php echo t('marketing.tab.campaigns', 'Campaigns'); ?>
                </button>
                <button class="tab" onclick="switchTab('content-calendar')">
                    <i class="fas fa-calendar-alt"></i> <?php echo t('marketing.tab.content_calendar', 'Content Calendar'); ?>
                </button>
                <button class="tab" onclick="switchTab('analytics')">
                    <i class="fas fa-chart-bar"></i> <?php echo t('marketing.tab.analytics', 'Advanced Analytics'); ?>
                </button>
            </div>
            
            <!-- Social Media Creator Tab -->
            <div id="social-media-tab" class="tab-content active">
                <div class="canvas-layout">
                    <div class="canvas-main">
                        <div class="canvas-toolbar">
                            <div class="toolbar-group">
                                <button class="toolbar-btn" onclick="toggleFullscreen()" title="Fullscreen">
                                    <i class="fas fa-expand"></i>
                                </button>
                            </div>
                            
                            <div class="toolbar-group">
                                <button onclick="moveElementLayerUp()" class="btn" title="Move Layer Up">
                                    <i class="fas fa-arrow-up"></i> Layer Up
                                </button>
                                <button onclick="moveElementLayerDown()" class="btn" title="Move Layer Down">
                                    <i class="fas fa-arrow-down"></i> Layer Down
                                </button>
                                <button onclick="showLayerManager()" class="btn btn-primary" title="Manage Layers">
                                    <i class="fas fa-layer-group"></i> Layers
                                </button>
                                <button class="toolbar-btn" onclick="addTextElement()" title="Add Text">
                                    <i class="fas fa-font"></i> Text
                                </button>
                                <button class="toolbar-btn" onclick="addImageElement()" title="Add Image">
                                    <i class="fas fa-image"></i> Image
                                </button>
                            </div>
                            
                            <div class="toolbar-group">
                                <label for="bgColor" style="font-size: 13px; color: #6b7280; margin-right: 8px;">Background:</label>
                                <input type="color" id="bgColor" class="color-picker" value="#ffffff" onchange="changeBackgroundColor(this.value)">
                            </div>
                            
                            <div class="toolbar-group">
                                <button class="toolbar-btn" onclick="openTemplateModal()">
                                    <i class="fas fa-layer-group"></i> Templates
                                </button>
                                <button class="toolbar-btn" onclick="openSlideSettingsModal()">
                                    <i class="fas fa-sliders-h"></i> Slides
                                </button>
                            </div>
                            
                            <div class="toolbar-group">
                                <button class="toolbar-btn" onclick="saveAsTemplate()">
                                    <i class="fas fa-save"></i> Save Template
                                </button>
                                <button class="toolbar-btn" onclick="saveCarousel()">
                                    <i class="fas fa-save"></i> Save Project
                                </button>
                                <button class="toolbar-btn" onclick="openLoadModal()">
                                    <i class="fas fa-folder-open"></i> Load
                                </button>
                            </div>
                            
                            <div class="toolbar-group">
                                <button class="toolbar-btn btn-primary" onclick="openExportModal()">
                                    <i class="fas fa-upload"></i> Export
                                </button>
                            </div>

                            <!-- Format Selector -->
                            <div class="toolbar-group">
                                <label style="font-size: 13px; color: #6b7280;">Format:</label>
                                <select id="formatSelector" onchange="changeCanvasFormat(this.value)" class="form-select">
                                    <option value="1080x1080">Instagram Square</option>
                                    <option value="1080x1920">Instagram Story</option>
                                    <option value="1200x630">Facebook Post</option>
                                    <option value="custom">Custom...</option>
                                </select>
                            </div>

                            <!-- Stickers Button -->
                            <button class="toolbar-btn" onclick="openStickersModal()">
                                <i class="fas fa-smile"></i> Stickers
                            </button>
                        </div>
                        
                        <div class="canvas-workspace" id="canvasWorkspace">
                            <div class="canvas-area" id="canvasArea">
                                <div class="carousel-slide active" id="slide-1" data-slide="1">
                                    <!-- Canvas elements will be added here -->
                                </div>
                            </div>
                        </div>
                        
                        <div class="slide-nav">
                            <button class="slide-nav-btn" onclick="previousSlide()">
                                <i class="fas fa-chevron-left"></i>
                            </button>
                            <div class="slide-indicator">
                                <span id="currentSlideNum">1</span> / <span id="totalSlidesNum">1</span>
                            </div>
                            <button class="slide-nav-btn" onclick="nextSlide()">
                                <i class="fas fa-chevron-right"></i>
                            </button>
                            <button class="slide-nav-btn" onclick="addSlide()">
                                <i class="fas fa-plus"></i> Add Slide
                            </button>
                            <div class="slide-thumbnails" id="slideThumbnails">
                                <!-- Thumbnails will be generated here -->
                            </div>
                        </div>
                    </div>
                    
                    <div class="articles-sidebar">
                        <div class="sidebar-header">
                            <h3>
                                <i class="fas fa-newspaper"></i>
                                <?php echo t('marketing.articles.title', 'Article Snippets'); ?>
                            </h3>
                        </div>
                        <div class="sidebar-search">
                            <input type="text" placeholder="<?php echo t('marketing.articles.search', 'Search articles...'); ?>" oninput="filterArticles(this.value)">
                        </div>
                        <div style="padding: 12px; border-bottom: 1px solid #e5e7eb;">
                            <button class="btn btn-secondary" style="width: 100%;" onclick="addAllArticlesToSlides()">
                                <i class="fas fa-layer-group"></i> Add All to Slides
                            </button>
                        </div>
                        <div class="articles-list" id="articlesList">
                            <div style="text-align: center; padding: 40px 20px; color: #9ca3af;">
                                <i class="fas fa-spinner fa-spin" style="font-size: 24px; margin-bottom: 12px;"></i>
                                <p><?php echo t('marketing.articles.loading', 'Loading articles...'); ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Trending Topics Tabs -->
            <div id="trending-topics-tab" class="tab-content">
                <div style="text-align: center; padding: 60px 20px;">
                    <i class="fas fa-chart" style="font-size: 64px; color: #d1d5db; margin-bottom: 20px;"></i>
                    <h2 style="color: #6b7280; margin-bottom: 12px;">Trending Topics</h2>
                    <p style="color: #9ca3af;"></p>
                    <button id="load-trends-btn" class="btn btn-primary">Load Trends</button>

                    <div id="trends-output" style="margin-top:20px;"></div>

                </div>
            </div>
            
            <!-- Statistics Tab -->
            <div id="statistics-tab" class="tab-content">
                <div class="stats-grid">
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-title">Instagram</div>
                            <div class="stat-icon instagram">
                                <i class="fab fa-instagram"></i>
                            </div>
                        </div>
                        <div class="stat-value" id="instagramPosts">0</div>
                        <div class="stat-label">Total Posts</div>
                        <div class="stat-change positive">
                            <i class="fas fa-arrow-up"></i>
                            <span id="instagramChange">+0%</span> this month
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-title">Facebook</div>
                            <div class="stat-icon facebook">
                                <i class="fab fa-facebook"></i>
                            </div>
                        </div>
                        <div class="stat-value" id="facebookPosts">0</div>
                        <div class="stat-label">Total Posts</div>
                        <div class="stat-change positive">
                            <i class="fas fa-arrow-up"></i>
                            <span id="facebookChange">+0%</span> this month
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-title">X (Twitter)</div>
                            <div class="stat-icon twitter">
                                <i class="fab fa-twitter"></i>
                            </div>
                        </div>
                        <div class="stat-value" id="twitterPosts">0</div>
                        <div class="stat-label">Total Posts</div>
                        <div class="stat-change positive">
                            <i class="fas fa-arrow-up"></i>
                            <span id="twitterChange">+0%</span> this month
                        </div>
                    </div>
                    
                    <div class="stat-card">
                        <div class="stat-header">
                            <div class="stat-title">Total Engagement</div>
                            <div class="stat-icon" style="background: #10b981; color: white;">
                                <i class="fas fa-heart"></i>
                            </div>
                        </div>
                        <div class="stat-value" id="totalEngagement">0</div>
                        <div class="stat-label">Likes, Comments, Shares</div>
                        <div class="stat-change positive">
                            <i class="fas fa-arrow-up"></i>
                            <span id="engagementChange">+0%</span> this month
                        </div>
                    </div>
                </div>
                
                <div class="posts-table">
                    <table>
                        <thead>
                            <tr>
                                <th><?php echo t('marketing.stats.platform', 'Platform'); ?></th>
                                <th><?php echo t('marketing.stats.post_title', 'Post'); ?></th>
                                <th><?php echo t('marketing.stats.date', 'Date'); ?></th>
                                <th><?php echo t('marketing.stats.engagement', 'Engagement'); ?></th>
                                <th><?php echo t('marketing.stats.reach', 'Reach'); ?></th>
                                <th><?php echo t('marketing.stats.actions', 'Actions'); ?></th>
                            </tr>
                        </thead>
                        <tbody id="postsTableBody">
                            <tr>
                                <td colspan="6" style="text-align: center; padding: 40px; color: #9ca3af;">
                                    <i class="fas fa-chart-line" style="font-size: 48px; margin-bottom: 16px; opacity: 0.5;"></i>
                                    <p><?php echo t('marketing.stats.no_posts', 'No published posts yet. Create and export your first social media post!'); ?></p>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            
            <!-- Other Tabs (Placeholder) -->
            <div id="campaigns-tab" class="tab-content">
                <div style="text-align: center; padding: 60px 20px;">
                    <i class="fas fa-rocket" style="font-size: 64px; color: #d1d5db; margin-bottom: 20px;"></i>
                    <h2 style="color: #6b7280; margin-bottom: 12px;">Campaigns Module</h2>
                    <p style="color: #9ca3af;">Campaign management features coming soon</p>
                </div>
            </div>
            
            <div id="content-calendar-tab" class="tab-content">
                <div style="text-align: center; padding: 60px 20px;">
                    <i class="fas fa-calendar-alt" style="font-size: 64px; color: #d1d5db; margin-bottom: 20px;"></i>
                    <h2 style="color: #6b7280; margin-bottom: 12px;">Content Calendar</h2>
                    <p style="color: #9ca3af;">Schedule and plan your content strategy</p>
                </div>
            </div>
            
            <div id="analytics-tab" class="tab-content">
                <div style="text-align: center; padding: 60px 20px;">
                    <i class="fas fa-chart-bar" style="font-size: 64px; color: #d1d5db; margin-bottom: 20px;"></i>
                    <h2 style="color: #6b7280; margin-bottom: 12px;">Advanced Analytics</h2>
                    <p style="color: #9ca3af;">Deep dive into your social media performance</p>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Template Selection Modal -->
    <div id="templateModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><?php echo t('marketing.templates.title', 'Select Template'); ?></h2>
                <button class="modal-close" onclick="closeModal('templateModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <div style="display: flex; gap: 12px; margin-bottom: 16px;">
                        <button class="btn btn-secondary" onclick="loadUserTemplates()">
                            <i class="fas fa-user"></i> My Templates
                        </button>
                        <button class="btn btn-secondary" onclick="loadSharedTemplates()">
                            <i class="fas fa-users"></i> Shared Templates
                        </button>
                        <button class="btn btn-secondary" onclick="loadDefaultTemplates()">
                            <i class="fas fa-star"></i> Default Templates
                        </button>
                    </div>
                </div>
                <div id="templateGallery" class="template-gallery">
                    <!-- Templates will be loaded here -->
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal('templateModal')"><?php echo t('common.cancel', 'Cancel'); ?></button>
                <button class="btn btn-primary" onclick="applySelectedTemplate()">
                    <i class="fas fa-check"></i> <?php echo t('common.apply', 'Apply Template'); ?>
                </button>
            </div>
        </div>
    </div>
    
    <!-- Slide Settings Modal -->
    <div id="slideSettingsModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><?php echo t('marketing.slides.title', 'Slide Settings'); ?></h2>
                <button class="modal-close" onclick="closeModal('slideSettingsModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label"><?php echo t('marketing.slides.count', 'Number of Slides'); ?></label>
                    <input type="number" class="form-input" id="slideCount" min="1" max="10" value="1" onchange="updateSlideCount(this.value)">
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo t('marketing.slides.duration', 'Slide Duration (seconds)'); ?></label>
                    <input type="number" class="form-input" id="slideDuration" min="1" max="30" value="5">
                </div>
                <div class="form-group">
                    <label class="form-label"><?php echo t('marketing.slides.transition', 'Transition Effect'); ?></label>
                    <select class="form-select" id="slideTransition">
                        <option value="fade">Fade</option>
                        <option value="slide">Slide</option>
                        <option value="zoom">Zoom</option>
                        <option value="none">None</option>
                    </select>
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal('slideSettingsModal')"><?php echo t('common.cancel', 'Cancel'); ?></button>
                <button class="btn btn-primary" onclick="applySlideSettings()">
                    <i class="fas fa-check"></i> <?php echo t('common.apply', 'Apply'); ?>
                </button>
            </div>
        </div>
    </div>
    
    <!-- Export Modal -->
    <div id="exportModal" class="modal">
        <div class="modal-content" style="max-width: 1000px;">
            <div class="modal-header" style="padding: 24px; border-bottom: 2px solid #e5e7eb;">
                <h2 style="font-size: 24px; font-weight: 700; color: #111827;">
                    <i class="fas fa-upload" style="color: #667eea; margin-right: 12px;"></i>
                    <?php echo t('marketing.export.title', 'Export to Social Media'); ?>
                </h2>
                <button class="modal-close" style="font-size: 28px; color: #6b7280; border: none; background: none; cursor: pointer;" onclick="closeModal('exportModal')">&times;</button>
            </div>
            <div class="modal-body" style="padding: 32px;">
                <div class="form-group" style="margin-bottom: 32px;">
                    <label class="form-label" style="display: block; font-size: 16px; font-weight: 600; color: #374151; margin-bottom: 16px;">
                        <?php echo t('marketing.export.platforms', 'Select Platforms'); ?>
                    </label>
                    <div class="checkbox-group" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px;">
                        <div class="checkbox-item" style="display: flex; align-items: center; padding: 16px; border: 2px solid #e5e7eb; border-radius: 8px; cursor: pointer; transition: all 0.2s;" 
                             onclick="event.target.tagName !== 'INPUT' && this.querySelector('input').click();">
                            <input type="checkbox" id="exportInstagram" value="instagram" style="width: 20px; height: 20px; margin-right: 12px; cursor: pointer;" onchange="updateExportPreview()">
                            <label for="exportInstagram" style="font-size: 15px; font-weight: 500; cursor: pointer; display: flex; align-items: center; gap: 8px;">
                                <i class="fab fa-instagram" style="font-size: 24px; color: #E4405F;"></i> Instagram
                            </label>
                        </div>
                        <div class="checkbox-item" style="display: flex; align-items: center; padding: 16px; border: 2px solid #e5e7eb; border-radius: 8px; cursor: pointer; transition: all 0.2s;"
                             onclick="event.target.tagName !== 'INPUT' && this.querySelector('input').click();"">
                            <input type="checkbox" id="exportFacebook" value="facebook" style="width: 20px; height: 20px; margin-right: 12px; cursor: pointer;" onchange="updateExportPreview()">
                            <label for="exportFacebook" style="font-size: 15px; font-weight: 500; cursor: pointer; display: flex; align-items: center; gap: 8px;">
                                <i class="fab fa-facebook" style="font-size: 24px; color: #1877F2;"></i> Facebook
                            </label>
                        </div>
                        <div class="checkbox-item" style="display: flex; align-items: center; padding: 16px; border: 2px solid #e5e7eb; border-radius: 8px; cursor: pointer; transition: all 0.2s;"
                             onclick="event.target.tagName !== 'INPUT' && this.querySelector('input').click();"">
                            <input type="checkbox" id="exportTwitter" value="twitter" style="width: 20px; height: 20px; margin-right: 12px; cursor: pointer;" onchange="updateExportPreview()">
                            <label for="exportTwitter" style="font-size: 15px; font-weight: 500; cursor: pointer; display: flex; align-items: center; gap: 8px;">
                                <i class="fab fa-twitter" style="font-size: 24px; color: #1DA1F2;"></i> X (Twitter)
                            </label>
                        </div>
                    </div>
                </div>
                
                <div class="form-group" style="margin-bottom: 32px;">
                    <label class="form-label" style="display: block; font-size: 16px; font-weight: 600; color: #374151; margin-bottom: 16px;">
                        <?php echo t('marketing.export.mode', 'Publishing Mode'); ?>
                    </label>
                    <div class="form-row" style="display: flex; gap: 16px; flex-wrap: wrap;">
                        <label class="checkbox-item" style="display: flex; align-items: center; padding: 16px; border: 2px solid #e5e7eb; border-radius: 8px; cursor: pointer; flex: 1; min-width: 250px;">
                            <input type="radio" name="publishMode" value="draft" checked style="width: 20px; height: 20px; margin-right: 12px; cursor: pointer;">
                            <span style="font-size: 15px; font-weight: 500;">
                                <i class="fas fa-file-alt" style="margin-right: 8px; color: #f59e0b;"></i>
                                <?php echo t('marketing.export.draft', 'Draft (Review before publishing)'); ?>
                            </span>
                        </label>
                        <label class="checkbox-item" style="display: flex; align-items: center; padding: 16px; border: 2px solid #e5e7eb; border-radius: 8px; cursor: pointer; flex: 1; min-width: 250px;">
                            <input type="radio" name="publishMode" value="publish" style="width: 20px; height: 20px; margin-right: 12px; cursor: pointer;">
                            <span style="font-size: 15px; font-weight: 500;">
                                <i class="fas fa-rocket" style="margin-right: 8px; color: #10b981;"></i>
                                <?php echo t('marketing.export.publish', 'Publish Immediately'); ?>
                            </span>
                        </label>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label" style="display: block; font-size: 16px; font-weight: 600; color: #374151; margin-bottom: 16px;">
                        <?php echo t('marketing.export.preview', 'Export Preview'); ?>
                    </label>
                    <div id="exportPreviewContainer" class="export-preview" style="background: #f9fafb; border: 2px dashed #d1d5db; border-radius: 8px; padding: 32px; min-height: 200px;">
                        <p style="text-align: center; color: #9ca3af; font-size: 15px;">
                            <?php echo t('marketing.export.select_platform', 'Select at least one platform to see preview'); ?>
                        </p>
                    </div>
                </div>
            </div>
            <div class="modal-footer" style="padding: 20px 32px; background: #f9fafb; border-top: 2px solid #e5e7eb; display: flex; gap: 12px; justify-content: flex-end;">
                <button class="btn btn-secondary" style="padding: 12px 24px; font-size: 15px; font-weight: 500;" onclick="closeModal('exportModal')">
                    <i class="fas fa-times" style="margin-right: 8px;"></i>
                    <?php echo t('common.cancel', 'Cancel'); ?>
                </button>
                <button class="btn btn-secondary" style="padding: 12px 24px; font-size: 15px; font-weight: 500;" onclick="downloadPackage()">
                    <i class="fas fa-download" style="margin-right: 8px;"></i>
                    <?php echo t('marketing.export.download', 'Download Package'); ?>
                </button>
                <button class="btn btn-success" style="padding: 12px 24px; font-size: 15px; font-weight: 500; background: #10b981; color: white; border: none; border-radius: 6px; cursor: pointer;" onclick="exportToSocialMedia()">
                    <i class="fas fa-upload" style="margin-right: 8px;"></i>
                    <?php echo t('marketing.export.upload', 'Upload to Platforms'); ?>
                </button>
            </div>
        </div>
    </div>
    
    <!-- Load Project Modal -->
    <div id="loadModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><?php echo t('marketing.load.title', 'Load Project'); ?></h2>
                <button class="modal-close" onclick="closeModal('loadModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div id="savedProjectsList">
                    <!-- Saved projects will be loaded here -->
                </div>
            </div>
            <div class="modal-footer">
                <button class="btn btn-secondary" onclick="closeModal('loadModal')"><?php echo t('common.cancel', 'Cancel'); ?></button>
            </div>
        </div>
    </div>

    <!-- Text Toolbar -->
    <div id="textStyleToolbar" class="text-style-toolbar" style="display: none; position: fixed; top: 80px; right: 340px; background: white; padding: 16px; border-radius: 8px; box-shadow: 0 4px 12px rgba(0,0,0,0.15); z-index: 1000; flex-direction: column; gap: 12px; min-width: 280px;">
        <button class="toolbar-close-btn" onclick="hideTextStyleToolbar()">
            <i class="fas fa-times"></i>
        </button>
        <div style="font-weight: 600; margin-bottom: 8px; color: #374151;">Text Formatting</div>
        
        <div style="display: flex; flex-direction: column; gap: 8px;">
            <label style="font-size: 12px; color: #6b7280; font-weight: 500;">Font Family</label>
            <select id="fontFamily" style="padding: 8px; border: 1px solid #d1d5db; border-radius: 6px; font-size: 14px;" onchange="applyTextStyle('fontFamily', this.value)">
                <option value="Inter">Inter</option>
                <option value="Arial">Arial</option>
                <option value="Georgia">Georgia</option>
                <option value="Times New Roman">Times New Roman</option>
                <option value="Courier New">Courier New</option>
                <option value="Verdana">Verdana</option>
                <option value="Helvetica">Helvetica</option>
            </select>
        </div>
        
        <div style="display: flex; flex-direction: column; gap: 8px;">
            <label style="font-size: 12px; color: #6b7280; font-weight: 500;">Font Size: <span id="fontSizeValue">24</span>px</label>
            <input type="range" id="fontSize" min="8" max="72" value="16" oninput="applyTextStyle('fontSize', this.value); document.getElementById('fontSizeValue').textContent = this.value;">
        </div>
        
        <div style="display: flex; flex-direction: column; gap: 8px;">
            <label style="font-size: 12px; color: #6b7280; font-weight: 500;">Text Color</label>
            <input type="color" id="textColor" value="#111827" onchange="applyTextStyle('color', this.value)">
        </div>
        
        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
            <button class="toolbar-btn" style="padding: 8px 12px; background: white; border: 1px solid #d1d5db; border-radius: 6px; cursor: pointer; flex: 1; min-width: 60px;" 
                    onclick="applyTextStyle('fontWeight', 'bold')">
                <i class="fas fa-bold"></i> Bold
            </button>
            <button class="toolbar-btn" style="padding: 8px 12px; background: white; border: 1px solid #d1d5db; border-radius: 6px; cursor: pointer; flex: 1; min-width: 60px;" 
                    onclick="applyTextStyle('fontStyle', 'italic')">
                <i class="fas fa-italic"></i> Italic
            </button>
            <button class="toolbar-btn" style="padding: 8px 12px; background: white; border: 1px solid #d1d5db; border-radius: 6px; cursor: pointer; flex: 1; min-width: 60px;" 
                    onclick="applyTextStyle('textDecoration', 'underline')">
                <i class="fas fa-underline"></i> Underline
            </button>
        </div>
    </div>

    <!-- Stickers Modal -->
    <div id="stickersModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <h2>Stickers</h2>
                <button class="modal-close" onclick="closeModal('stickersModal')">&times;</button>
            </div>
            <div class="modal-body">
                <div class="stickers-grid" id="stickersGrid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(120px, 1fr)); gap: 16px; padding: 16px;"></div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="/management/ajax/marketing.js"></script>

    <script>
(function () {

    const AJAX_URL = '/management/ajax/get_german_trends.php';
    const CONTAINER_ID = 'trends-output';

    // Safe HTML setter
    function setHTML(el, html) {
        try { el.innerHTML = html; }
        catch { el.textContent = html; }
    }

    function pretty(obj) {
        try { return JSON.stringify(obj, null, 2); }
        catch { return String(obj); }
    }

    function renderError(el, title, details) {
        setHTML(el, `
            <div style="border:1px solid #f5c6cb;background:#f8d7da;color:#721c24;padding:12px;border-radius:6px;">
                <strong>${title}</strong>
                <pre style="white-space:pre-wrap;margin-top:8px;">${details}</pre>
            </div>
        `);
    }

    function escapeHtml(str) {
        // Ensure that falsy values (like 0 or null) are converted to an empty string for safety before escaping
        return (str || "").toString()
            .replace(/&/g,'&amp;')
            .replace(/</g,'&lt;')
            .replace(/>/g,'&gt;')
            .replace(/"/g,'&quot;');
    }

    async function loadTrends() {

        let container = document.getElementById(CONTAINER_ID);

        if (!container) {
            console.warn(`[trends] No #${CONTAINER_ID} found, creating it…`);
            container = document.createElement('div');
            container.id = CONTAINER_ID;
            document.body.appendChild(container);
        }

        setHTML(container, "<div style='padding:10px;'>Loading trends…</div>");

        let respText = null;
        let status = null;

        try {
            const resp = await fetch(AJAX_URL, { credentials: 'same-origin' });
            status = resp.status;
            respText = await resp.text();
        }
        catch (err) {
            renderError(container, "Network Error", err.message || String(err));
            console.error('[trends] fetch error', err);
            return;
        }

        console.groupCollapsed("[trends] Raw response");
        console.log("HTTP Status:", status);
        console.log(respText ? respText.slice(0, 2000) : "(empty)");
        console.groupEnd();

        // -----------------------------
        // Parse server response (Client-side logic remains for compatibility)
        // -----------------------------
        let parsed = null;

        // 1) direct JSON attempt
        try {
            parsed = JSON.parse(respText);
            console.info("[trends] direct JSON parsed");
        } catch (e) {
            console.warn("[trends] direct JSON parse failed:", e.message);
        }

        // 2) parse OpenAI wrapper (if PHP was NOT fixed)
        if (!parsed) {
            try {
                const cleanedOuter = respText.replace(/^\)\]\}\'\,\s*/, '');
                const outer = JSON.parse(cleanedOuter);

                const content =
                    outer?.choices?.[0]?.message?.content;

                if (content) {

                    // remove markdown fences first
                    let cleaned = content
                        .replace(/```json/i, '')
                        .replace(/```/g, '')
                        .trim();

                    try {
                        parsed = JSON.parse(cleaned);
                        console.info("[trends] parsed JSON from cleaned OpenAI content");
                    }
                    catch (innerErr) {
                        console.warn("[trends] OpenAI JSON still invalid:", innerErr.message);
                        parsed = { raw: cleaned }; // fallback
                    }
                }
            }
            catch (outerErr) {
                console.warn("[trends] failed parsing OpenAI wrapper", outerErr);
            }
        }

        if (!parsed) {
            renderError(
                container,
                "Invalid JSON from server",
                "RAW RESPONSE:\n\n" + (respText || "(empty)")
            );
            return;
        }

        console.log("[trends] parsed object:", parsed);

        // Extract topics
        let topics = null;

        if (Array.isArray(parsed)) {
            topics = parsed;
        }
        else if (parsed.results && Array.isArray(parsed.results)) {
            topics = parsed.results;
        }
        else if (parsed.raw) {
            topics = [{ topic: "Non-JSON OpenAI output", raw: parsed.raw }];
        }
        else {
            // Updated fallback logic
            topics = parsed.data || parsed.items;
            if (!Array.isArray(topics)) {
                topics = [parsed]; // If all else fails, wrap the single object in an array
            }
        }

        if (!Array.isArray(topics)) {
            renderError(container, "Parsed response is not an array", pretty(parsed));
            return;
        }

        // Build HTML table
        let html = `
            <div style="overflow:auto;">
            <table style="width:100%;border-collapse:collapse;font-size:14px;">
            <thead>
                <tr style="background:#f1f1f1;">
                    <th style="padding:6px;border:1px solid #ddd; width: 40px;">#</th>
                    <th style="padding:6px;border:1px solid #ddd; text-align: left;">Topic</th>
                    <th style="padding:6px;border:1px solid #ddd;">Search Volume</th>
                    <th style="padding:6px;border:1px solid #ddd;">Region</th>
                    <th style="padding:6px;border:1px solid #ddd;">Date</th>
                    <th style="padding:6px;border:1px solid #ddd;">Notes</th>
                </tr>
            </thead>
            <tbody>
        `;

        topics.forEach((t, index) => { // Added index for numbering
            // Corrected to prioritize 'topic' (set in PHP) and include 'search_volume' from OpenAI output
            const topic = t.topic || t.term || t.title || t.query || "N/A";
            const vol = t.search_volume || t.traffic || t.volume || t.formattedTraffic || "";
            const geo = t.location || t.baseline_geo || t.geo || "";
            const date = t.date || t.trend_date || "";
            const note = t.why_selected || t.germany_relevance_reason || t.reason || "";

            html += `
                <tr>
                    <td style="padding:6px;border:1px solid #eee; text-align: center;">${index + 1}</td>
                    <td style="padding:6px;border:1px solid #eee; font-weight: bold; text-align: left;">${escapeHtml(topic)}</td>
                    <td style="padding:6px;border:1px solid #eee; text-align: center;">${escapeHtml(vol)}</td>
                    <td style="padding:6px;border:1px solid #eee; text-align: center;">${escapeHtml(geo)}</td>
                    <td style="padding:6px;border:1px solid #eee; text-align: center;">${escapeHtml(date)}</td>
                    <td style="padding:6px;border:1px solid #eee;">${escapeHtml(note)}</td>
                </tr>
            `;
        });

        html += "</tbody></table></div>";

        setHTML(container, html);

        console.info(`[trends] Rendered ${topics.length} topics.`);
    }

    // ----------------------------------------
    // Run ONLY when button clicked
    // ----------------------------------------
    document.getElementById("load-trends-btn").addEventListener("click", function () {
        console.info("[trends] Button clicked. Loading…");
        loadTrends();
    });

})();
</script>
</body>
</html>