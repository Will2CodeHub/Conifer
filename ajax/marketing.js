// TEN Management - Marketing Module JavaScript
// Comprehensive social media management and content creation system

// Global state management
const MarketingApp = {
    currentSlide: 1,
    totalSlides: 1,
    slides: {},
    selectedElement: null,
    savedRange: null,
    draggedArticle: null,
    templates: [],
    savedProjects: [],
    articles: [],
    articlesPage: 1,
    articlesPerPage: 5,
    nextZIndex: 100, // Auto-incrementing z-index for proper layering
    canvasState: {
        isFullscreen: false,
        backgroundColor: '#ffffff',
        canvasWidth: 1080,
        canvasHeight: 1080,
        slideSettings: {
            duration: 5,
            transition: 'fade'
        }
    },
    exportData: {
        selectedPlatforms: [],
        articleLinks: [],
        mode: 'draft'
    },
    htmlEditor: {
        visible: false,
        targetElement: null
    }
};

// Helper function to get next z-index
function getNextZIndex() {
    return MarketingApp.nextZIndex++;
}

// Initialize the application
document.addEventListener('DOMContentLoaded', function() {
    console.log('Marketing module initialized');
    
    // Initialize first slide
    initializeCanvas();
    
    // Load articles from JSON
    loadArticles();
    
    // Load saved projects
    loadSavedProjects();
    
    // Load statistics
    loadStatistics();
    
    // Setup event listeners
    setupEventListeners();
    
    // Initialize drag and drop
    initializeDragAndDrop();
    
    // Initialize HTML editor toolbar
    initializeHtmlEditorToolbar();
});

// Tab switching
function switchTab(tabName) {
    // Hide all tabs
    document.querySelectorAll('.tab-content').forEach(tab => {
        tab.classList.remove('active');
    });
    
    // Remove active class from all tab buttons
    document.querySelectorAll('.tab').forEach(btn => {
        btn.classList.remove('active');
    });
    
    // Show selected tab
    document.getElementById(tabName + '-tab').classList.add('active');
    
    // Add active class to clicked button
    event.target.classList.add('active');
    
    // Special handling for different tabs
    if (tabName === 'statistics') {
        refreshStatistics();
    }
}

// Canvas initialization
function initializeCanvas() {
    const canvasArea = document.getElementById('canvasArea');
    
    // Initialize slide 1
    MarketingApp.slides[1] = {
        elements: [],
        background: '#ffffff',
        backgroundImage: null
    };
    
    updateSlideIndicator();
    generateSlideThumbnails();
}

// Load articles from JSON file
function loadArticles() {
    fetch('/management/ajax/marketing.php?action=get_articles')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                MarketingApp.articles = data.articles;
                renderArticles(data.articles);
            } else {
                // Use dummy data if JSON file not found
                useDummyArticles();
            }
        })
        .catch(error => {
            console.error('Error loading articles:', error);
            useDummyArticles();
        });
}

// Use dummy articles for testing
function useDummyArticles() {
    const dummyArticles = [
        {
            id: 1,
            title: 'Breaking News: Major Technology Breakthrough',
            snippet: 'Scientists announce revolutionary AI advancement that could change everything we know about computing.',
            description: 'In a groundbreaking announcement today, researchers have unveiled a new artificial intelligence system that demonstrates unprecedented capabilities.',
            url: 'https://example.com/article1',
            image: 'https://via.placeholder.com/1080x1080/667eea/ffffff?text=Tech+Breakthrough',
            category: 'Technology',
            date: '2025-11-11'
        },
        {
            id: 2,
            title: 'Global Markets React to Economic Policy Changes',
            snippet: 'Stock markets worldwide show mixed reactions as new economic policies are announced by major governments.',
            description: 'Financial markets around the globe are experiencing volatility following the announcement of significant economic policy changes by several major economies.',
            url: 'https://example.com/article2',
            image: 'https://via.placeholder.com/1080x1080/10b981/ffffff?text=Market+News',
            category: 'Business',
            date: '2025-11-11'
        },
        {
            id: 3,
            title: 'Climate Summit Reaches Historic Agreement',
            snippet: 'World leaders unite on ambitious climate goals, setting new standards for environmental protection.',
            description: 'At the International Climate Summit, representatives from over 180 nations have reached a landmark agreement on climate action.',
            url: 'https://example.com/article3',
            image: 'https://via.placeholder.com/1080x1080/059669/ffffff?text=Climate+Summit',
            category: 'Environment',
            date: '2025-11-10'
        },
        {
            id: 4,
            title: 'Sports: Championship Finals Preview',
            snippet: 'As the finals approach, we analyze the top contenders and predict the outcome of this season\'s championship.',
            description: 'The championship finals are just days away, and excitement is building as two powerhouse teams prepare to face off.',
            url: 'https://example.com/article4',
            image: 'https://via.placeholder.com/1080x1080/ef4444/ffffff?text=Championship',
            category: 'Sports',
            date: '2025-11-10'
        },
        {
            id: 5,
            title: 'Cultural Renaissance: Art Exhibition Opens',
            snippet: 'New exhibition showcases emerging artists and celebrates cultural diversity through innovative works.',
            description: 'A major new art exhibition has opened its doors, featuring works from dozens of emerging artists from around the world.',
            url: 'https://example.com/article5',
            image: 'https://via.placeholder.com/1080x1080/8b5cf6/ffffff?text=Art+Exhibition',
            category: 'Culture',
            date: '2025-11-09'
        },
        {
            id: 6,
            title: 'Health Study Reveals Surprising Findings',
            snippet: 'New research challenges conventional wisdom about diet and exercise, offering fresh insights into wellness.',
            description: 'A comprehensive health study published today presents findings that challenge some long-held beliefs about nutrition and fitness.',
            url: 'https://example.com/article6',
            image: 'https://via.placeholder.com/1080x1080/f59e0b/ffffff?text=Health+Study',
            category: 'Health',
            date: '2025-11-09'
        }
    ];
    
    MarketingApp.articles = dummyArticles;
    renderArticles(dummyArticles);
}

// Render articles in sidebar
function renderArticles(articles) {
    const articlesList = document.getElementById('articlesList');
    const start = (MarketingApp.articlesPage - 1) * MarketingApp.articlesPerPage;
    const end = start + MarketingApp.articlesPerPage;
    const pageArticles = articles.slice(start, end);
    const totalPages = Math.ceil(articles.length / MarketingApp.articlesPerPage);
    
    if (articles.length === 0) {
        articlesList.innerHTML = `
            <div style="text-align: center; padding: 40px 20px; color: #9ca3af;">
                <i class="fas fa-newspaper" style="font-size: 48px; margin-bottom: 12px; opacity: 0.5;"></i>
                <p>No articles available</p>
            </div>
        `;
        return;
    }
    
    const articlesHtml = pageArticles.map(article => `
        <div class="article-item" draggable="true" data-article-id="${article.id}">
            <div class="article-title">${article.title}</div>
            <div class="article-snippet">${article.snippet}</div>
            <div class="article-meta">
                <span><i class="fas fa-tag"></i> ${article.category}</span>
                <span><i class="fas fa-calendar"></i> ${article.date}</span>
            </div>
        </div>
    `).join('');
    
    const paginationHtml = `
        <div class="articles-pagination">
            <button class="pagination-btn" onclick="changeArticlePage(-1)" ${MarketingApp.articlesPage === 1 ? 'disabled' : ''}>
                <i class="fas fa-chevron-left"></i>
            </button>
            <span style="font-size: 13px; color: #6b7280;">
                Page ${MarketingApp.articlesPage} of ${totalPages}
            </span>
            <button class="pagination-btn" onclick="changeArticlePage(1)" ${MarketingApp.articlesPage === totalPages ? 'disabled' : ''}>
                <i class="fas fa-chevron-right"></i>
            </button>
        </div>
    `;
    
    articlesList.innerHTML = articlesHtml + paginationHtml;
    
    // Add drag event listeners
    document.querySelectorAll('.article-item').forEach(item => {
        item.addEventListener('dragstart', handleArticleDragStart);
        item.addEventListener('dragend', handleArticleDragEnd);
    });
}

// Filter articles
function filterArticles(searchTerm) {
    const filtered = MarketingApp.articles.filter(article => {
        const search = searchTerm.toLowerCase();
        return article.title.toLowerCase().includes(search) ||
               article.snippet.toLowerCase().includes(search) ||
               article.category.toLowerCase().includes(search);
    });
    
    renderArticles(filtered);
}

// Drag and drop functionality
function initializeDragAndDrop() {
    const canvasArea = document.getElementById('canvasArea');
    
    canvasArea.addEventListener('dragover', handleCanvasDragOver);
    canvasArea.addEventListener('drop', handleCanvasDrop);
}

function handleArticleDragStart(e) {
    const articleId = parseInt(e.target.dataset.articleId);
    const article = MarketingApp.articles.find(a => a.id === articleId);
    
    MarketingApp.draggedArticle = article;
    e.target.classList.add('dragging');
    
    e.dataTransfer.effectAllowed = 'copy';
    e.dataTransfer.setData('text/plain', JSON.stringify(article));
}

function handleArticleDragEnd(e) {
    e.target.classList.remove('dragging');
    MarketingApp.draggedArticle = null;
}

function handleCanvasDragOver(e) {
    e.preventDefault();
    e.dataTransfer.dropEffect = 'copy';
}

function handleCanvasDrop(e) {
    e.preventDefault();
    
    if (!MarketingApp.draggedArticle) return;
    
    const canvasArea = document.getElementById('canvasArea');
    const rect = canvasArea.getBoundingClientRect();
    
    const x = e.clientX - rect.left;
    const y = e.clientY - rect.top;
    
    // Add article snippet as text element to canvas
    addArticleToCanvas(MarketingApp.draggedArticle, x, y);
    
    // Track article for export
    if (!MarketingApp.exportData.articleLinks.find(a => a.id === MarketingApp.draggedArticle.id)) {
        MarketingApp.exportData.articleLinks.push(MarketingApp.draggedArticle);
    }
    
    showNotification('Article added to canvas', 'success');
}

function addArticleSnippetToCanvas(article, x, y, slideNum) {
    const slideElement = document.getElementById('slide-' + slideNum);
    if (!slideElement) return;
    
    const element = document.createElement('div');
    element.className = 'canvas-element article-element';
    element.style.position = 'absolute';
    element.style.left = Math.max(0, x - 200) + 'px';
    element.style.top = Math.max(0, y - 50) + 'px';
    element.style.width = '400px';
    element.style.minHeight = '100px';
    element.style.backgroundColor = '#ffffff';
    element.style.border = '2px solid #e5e7eb';
    element.style.borderRadius = '8px';
    element.style.padding = '16px';
    element.style.paddingTop = '32px';
    element.style.cursor = 'move';
    element.style.zIndex = getNextZIndex();
    element.style.boxShadow = '0 2px 8px rgba(0,0,0,0.1)';
    element.dataset.articleId = article.id;
    element.dataset.elementType = 'article';
    
    const contentDiv = document.createElement('div');
    contentDiv.className = 'article-content';
    contentDiv.contentEditable = 'true';
    contentDiv.style.outline = 'none';
    contentDiv.innerHTML = `
        <div>${article.title}</div>
        <div>${article.snippet}</div>
        <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #e5e7eb; display: flex; gap: 12px; font-size: 12px; color: #9ca3af;">
            <span><i class="fas fa-tag"></i> ${article.category}</span>
            <span><i class="fas fa-calendar"></i> ${article.date}</span>
        </div>
    `;
    
    const dragHandle = document.createElement('div');
    dragHandle.className = 'article-drag-handle';
    dragHandle.style.position = 'absolute';
    dragHandle.style.top = '0';
    dragHandle.style.left = '50%';
    dragHandle.style.transform = 'translateX(-50%)';
    dragHandle.style.width = '40px';
    dragHandle.style.height = '24px';
    dragHandle.style.backgroundColor = '#3b82f6';
    dragHandle.style.borderRadius = '0 0 8px 8px';
    dragHandle.style.cursor = 'grab';
    dragHandle.style.display = 'flex';
    dragHandle.style.alignItems = 'center';
    dragHandle.style.justifyContent = 'center';
    dragHandle.style.color = 'white';
    dragHandle.style.fontSize = '14px';
    dragHandle.innerHTML = '<i class="fas fa-grip-lines"></i>';
    
    const deleteBtn = document.createElement('button');
    deleteBtn.className = 'element-delete-btn';
    deleteBtn.style.position = 'absolute';
    deleteBtn.style.top = '4px';
    deleteBtn.style.right = '4px';
    deleteBtn.style.width = '24px';
    deleteBtn.style.height = '24px';
    deleteBtn.style.border = 'none';
    deleteBtn.style.borderRadius = '4px';
    deleteBtn.style.backgroundColor = '#ef4444';
    deleteBtn.style.color = 'white';
    deleteBtn.style.cursor = 'pointer';
    deleteBtn.style.display = 'flex';
    deleteBtn.style.alignItems = 'center';
    deleteBtn.style.justifyContent = 'center';
    deleteBtn.style.zIndex = '1000';
    deleteBtn.innerHTML = '<i class="fas fa-times"></i>';
    deleteBtn.onclick = function(e) {
        e.stopPropagation();
        deleteElement(deleteBtn);
    };
    
    const resizeHandles = document.createElement('div');
    resizeHandles.innerHTML = `
        <div class="resize-handle nw"></div>
        <div class="resize-handle ne"></div>
        <div class="resize-handle sw"></div>
        <div class="resize-handle se"></div>
    `;
    
    element.appendChild(dragHandle);
    element.appendChild(contentDiv);
    element.appendChild(deleteBtn);
    element.appendChild(resizeHandles);
    
    slideElement.appendChild(element);
    makeArticleElementInteractive(element, dragHandle, contentDiv);
    
    if (!MarketingApp.slides[slideNum]) {
        MarketingApp.slides[slideNum] = { elements: [], background: '#ffffff', backgroundImage: null };
    }
    
    MarketingApp.slides[slideNum].elements.push({
        type: 'article',
        articleId: article.id,
        position: { x: x, y: y },
        size: { width: 400, height: 'auto' },
        data: article
    });
}

function addArticleToCanvas(article, x, y) {
    const currentSlide = document.querySelector('.carousel-slide.active');
    
    const element = document.createElement('div');
    element.className = 'canvas-element article-element';
    element.style.position = 'absolute';
    element.style.left = Math.max(0, x - 200) + 'px';
    element.style.top = Math.max(0, y - 50) + 'px';
    element.style.width = '400px';
    element.style.minHeight = '100px';
    element.style.backgroundColor = '#ffffff';
    element.style.border = '2px solid #e5e7eb';
    element.style.borderRadius = '8px';
    element.style.padding = '16px';
    element.style.paddingTop = '32px';
    element.style.cursor = 'move';
    element.style.zIndex = getNextZIndex();
    element.style.boxShadow = '0 2px 8px rgba(0,0,0,0.1)';
    element.dataset.articleId = article.id;
    element.dataset.elementType = 'article';
    
    const contentDiv = document.createElement('div');
    contentDiv.className = 'article-content';
    contentDiv.contentEditable = 'false';
    contentDiv.style.outline = 'none';
    contentDiv.innerHTML = `
        <div>${article.title}</div>
        <div>${article.snippet}</div>
        <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #e5e7eb; display: flex; gap: 12px; font-size: 12px; color: #9ca3af;">
            <span><i class="fas fa-tag"></i> ${article.category}</span>
            <span><i class="fas fa-calendar"></i> ${article.date}</span>
        </div>
    `;
    
    const dragHandle = document.createElement('div');
    dragHandle.className = 'article-drag-handle';
    dragHandle.style.position = 'absolute';
    dragHandle.style.top = '0';
    dragHandle.style.left = '50%';
    dragHandle.style.transform = 'translateX(-50%)';
    dragHandle.style.width = '40px';
    dragHandle.style.height = '24px';
    dragHandle.style.backgroundColor = '#3b82f6';
    dragHandle.style.borderRadius = '0 0 8px 8px';
    dragHandle.style.cursor = 'grab';
    dragHandle.style.display = 'flex';
    dragHandle.style.alignItems = 'center';
    dragHandle.style.justifyContent = 'center';
    dragHandle.style.color = 'white';
    dragHandle.style.fontSize = '14px';
    dragHandle.innerHTML = '<i class="fas fa-grip-lines"></i>';
    
    const deleteBtn = document.createElement('button');
    deleteBtn.className = 'element-delete-btn';
    deleteBtn.style.position = 'absolute';
    deleteBtn.style.top = '4px';
    deleteBtn.style.right = '4px';
    deleteBtn.style.width = '24px';
    deleteBtn.style.height = '24px';
    deleteBtn.style.border = 'none';
    deleteBtn.style.borderRadius = '4px';
    deleteBtn.style.backgroundColor = '#ef4444';
    deleteBtn.style.color = 'white';
    deleteBtn.style.cursor = 'pointer';
    deleteBtn.style.display = 'flex';
    deleteBtn.style.alignItems = 'center';
    deleteBtn.style.justifyContent = 'center';
    deleteBtn.style.zIndex = '1000';
    deleteBtn.innerHTML = '<i class="fas fa-times"></i>';
    deleteBtn.onclick = function(e) {
        e.stopPropagation();
        deleteElement(deleteBtn);
    };
    
    const resizeHandles = document.createElement('div');
    resizeHandles.innerHTML = `
        <div class="resize-handle nw"></div>
        <div class="resize-handle ne"></div>
        <div class="resize-handle sw"></div>
        <div class="resize-handle se"></div>
    `;
    
    element.appendChild(dragHandle);
    element.appendChild(contentDiv);
    element.appendChild(deleteBtn);
    element.appendChild(resizeHandles);
    
    currentSlide.appendChild(element);
    makeArticleElementInteractive(element, dragHandle, contentDiv);
    selectElement(element);
    
    saveElementToSlideState(element);
    
    if (!MarketingApp.exportData.articleLinks.find(a => a.id === article.id)) {
        MarketingApp.exportData.articleLinks.push(article);
    }
}

function makeArticleElementInteractive(element, dragHandle, contentDiv) {
    let isDragging = false;
    let startX, startY, startLeft, startTop;
    
    dragHandle.addEventListener('mousedown', function(e) {
        const touch = e.touches ? e.touches[0] : null;
        const clientX = touch ? touch.clientX : e.clientX;
        const clientY = touch ? touch.clientY : e.clientY;
        
        isDragging = true;
        startX = clientX;
        startY = clientY;
        startLeft = element.offsetLeft;
        startTop = element.offsetTop;
        
        dragHandle.style.cursor = 'grabbing';
        selectElement(element);
        
        e.preventDefault();
        e.stopPropagation();
    });
    
    dragHandle.addEventListener('touchstart', function(e) {
        const touch = e.touches ? e.touches[0] : null;
        const clientX = touch ? touch.clientX : e.clientX;
        const clientY = touch ? touch.clientY : e.clientY;
        
        isDragging = true;
        startX = clientX;
        startY = clientY;
        startLeft = element.offsetLeft;
        startTop = element.offsetTop;
        
        selectElement(element);
        
        e.preventDefault();
        e.stopPropagation();
    });
    
    document.addEventListener('mousemove', function(e) {
        if (!isDragging) return;
        
        const touch = e.touches ? e.touches[0] : null;
        const clientX = touch ? touch.clientX : e.clientX;
        const clientY = touch ? touch.clientY : e.clientY;
        
        const deltaX = clientX - startX;
        const deltaY = clientY - startY;
        
        const parentRect = element.parentElement.getBoundingClientRect();
        const newLeft = startLeft + deltaX;
        const newTop = startTop + deltaY;
        
        element.style.left = Math.max(0, Math.min(newLeft, parentRect.width - element.offsetWidth)) + 'px';
        element.style.top = Math.max(0, Math.min(newTop, parentRect.height - element.offsetHeight)) + 'px';
    });
    
    document.addEventListener('touchmove', function(e) {
        if (!isDragging) return;
        
        const touch = e.touches ? e.touches[0] : null;
        const clientX = touch ? touch.clientX : e.clientX;
        const clientY = touch ? touch.clientY : e.clientY;
        
        const deltaX = clientX - startX;
        const deltaY = clientY - startY;
        
        const parentRect = element.parentElement.getBoundingClientRect();
        const newLeft = startLeft + deltaX;
        const newTop = startTop + deltaY;
        
        element.style.left = Math.max(0, Math.min(newLeft, parentRect.width - element.offsetWidth)) + 'px';
        element.style.top = Math.max(0, Math.min(newTop, parentRect.height - element.offsetHeight)) + 'px';
    });
    
    document.addEventListener('mouseup', function() {
        if (isDragging) {
            isDragging = false;
            dragHandle.style.cursor = 'grab';
            saveElementToSlideState(element);
        }
    });
    
    document.addEventListener('touchend', function() {
        if (isDragging) {
            isDragging = false;
            saveElementToSlideState(element);
        }
    });
    
    contentDiv.addEventListener('dblclick', function(e) {
        e.stopPropagation();
        contentDiv.contentEditable = 'true';
        contentDiv.focus();
        showHtmlEditor(contentDiv);
    });
    
    let lastTap = 0;
    contentDiv.addEventListener('touchend', function(e) {
        const currentTime = new Date().getTime();
        const tapLength = currentTime - lastTap;
        if (tapLength < 300 && tapLength > 0) {
            e.preventDefault();
            contentDiv.contentEditable = 'true';
            contentDiv.focus();
            showHtmlEditor(contentDiv);
        }
        lastTap = currentTime;
    });
    
    element.addEventListener('click', function(e) {
        if (e.target !== contentDiv && !contentDiv.contains(e.target)) {
            e.stopPropagation();
            selectElement(element);
        }
    });
    
    contentDiv.addEventListener('mousedown', function(e) {
        e.stopPropagation();
    });
    
    contentDiv.addEventListener('input', function() {
        saveElementToSlideState(element);
    });

    contentDiv.addEventListener('mouseup', function() {
        const selection = window.getSelection();
        if (selection.rangeCount > 0 && !selection.isCollapsed) {
            MarketingApp.savedRange = selection.getRangeAt(0).cloneRange();
        }
    });
    
    element.querySelectorAll('.resize-handle').forEach(handle => {
        handle.addEventListener('mousedown', function(e) {
            handleResizeStart(e, element);
        });
    });
}

// Canvas element interactions
function setupEventListeners() {
    document.addEventListener('click', handleCanvasClick);
    document.addEventListener('keydown', handleKeyPress);
    
    // Font formatting controls
    const fontFamilySelect = document.getElementById('fontFamily');
    const fontSizeInput = document.getElementById('fontSize');
    const textColorInput = document.getElementById('textColor');
    
    if (fontFamilySelect) {
        fontFamilySelect.addEventListener('change', function() {
            applyTextStyle('fontFamily', this.value);
        });
    }
    
    if (fontSizeInput) {
        fontSizeInput.addEventListener('input', function() {
            applyTextStyle('fontSize', this.value);
            const display = document.getElementById('fontSizeValue');
            if (display) display.textContent = this.value;
        });
    }
    
    if (textColorInput) {
        textColorInput.addEventListener('input', function() {
            applyTextStyle('color', this.value);
        });
    }
}

function handleCanvasClick(e) {
    // Deselect all elements
    // Don't deselect if clicking on text style toolbar
    if (!e.target.closest('.canvas-element') && !e.target.closest('#textStyleToolbar')) {
        document.querySelectorAll('.canvas-element').forEach(el => {
            el.classList.remove('selected');
        });
        MarketingApp.selectedElement = null;
    }
}

function handleKeyPress(e) {
    if (!MarketingApp.selectedElement) return;
    
    // Delete selected element
    if (e.key === 'Delete' || e.key === 'Backspace') {
        if (document.activeElement.contentEditable !== 'true') {
            MarketingApp.selectedElement.remove();
            removeElementFromSlideState(MarketingApp.selectedElement);
            MarketingApp.selectedElement = null;
        }
    }
}

// Add text element
function addTextElement() {
    const currentSlide = document.querySelector('.carousel-slide.active');
    
    // Main container - NOT contentEditable
    const textElement = document.createElement('div');
    textElement.className = 'canvas-element text-element';
    textElement.style.position = 'absolute';
    textElement.style.left = '100px';
    textElement.style.top = '100px';
    textElement.style.minWidth = '100px';
    textElement.style.minHeight = '40px';
    textElement.style.cursor = 'move';
    textElement.style.zIndex = getNextZIndex();
    textElement.style.padding = '8px';
    textElement.style.backgroundColor = 'transparent';
    textElement.dataset.elementType = 'text';
    
    // Content div - THIS is what becomes editable
    const contentDiv = document.createElement('div');
    contentDiv.className = 'text-content';
    contentDiv.contentEditable = 'false';
    contentDiv.style.outline = 'none';
    contentDiv.style.fontSize = '24px';
    contentDiv.style.color = '#111827';
    contentDiv.style.fontWeight = '600';
    contentDiv.style.fontFamily = 'Inter';
    contentDiv.style.minHeight = '30px';
    contentDiv.innerHTML = '<span>Double-click to edit</span>';
    
    // Add drag handle at top
    const dragHandle = document.createElement('div');
    dragHandle.className = 'drag-handle';
    dragHandle.style.cssText = `
        position: absolute;
        top: -10px;
        left: 50%;
        transform: translateX(-50%);
        width: 40px;
        height: 20px;
        background: #667eea;
        border-radius: 10px 10px 0 0;
        cursor: move;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 10px;
        z-index: 10;
        pointer-events: auto;
    `;
    dragHandle.innerHTML = '<i class="fas fa-grip-lines"></i>';
    
    // Add controls
    const controls = document.createElement('div');
    controls.innerHTML = `
        <div class="resize-handle nw"></div>
        <div class="resize-handle ne"></div>
        <div class="resize-handle sw"></div>
        <div class="resize-handle se"></div>
        <button class="element-delete-btn" onclick="deleteElement(this); event.stopPropagation();">
            <i class="fas fa-times"></i>
        </button>
    `;
    
    textElement.appendChild(dragHandle);
    textElement.appendChild(contentDiv);
    textElement.appendChild(controls);
    
    currentSlide.appendChild(textElement);
    
    // CRITICAL: Save position to state BEFORE making interactive
    saveElementToSlideState(textElement);
    
    // Use the article-style interactive setup
    makeTextElementInteractive(textElement, dragHandle, contentDiv);
    selectElement(textElement);
    
    // Show HTML toolbar and make editable immediately
    setTimeout(() => {
        contentDiv.contentEditable = 'true';
        contentDiv.focus();
        showHtmlEditor(contentDiv);
        
        // Select all text
        const range = document.createRange();
        const sel = window.getSelection();
        range.selectNodeContents(contentDiv);
        sel.removeAllRanges();
        sel.addRange(range);
    }, 50);
}

// Text element interactive handler (mirrors article element behavior)
function makeTextElementInteractive(element, dragHandle, contentDiv) {
    let isDragging = false;
    let startX, startY, startLeft, startTop;
    
    dragHandle.addEventListener('mousedown', function(e) {
        isDragging = true;
        startX = e.clientX;
        startY = e.clientY;
        startLeft = element.offsetLeft;
        startTop = element.offsetTop;
        
        dragHandle.style.cursor = 'grabbing';
        selectElement(element);
        
        e.preventDefault();
        e.stopPropagation();
    });
    
    dragHandle.addEventListener('touchstart', function(e) {
        const touch = e.touches[0];
        isDragging = true;
        startX = touch.clientX;
        startY = touch.clientY;
        startLeft = element.offsetLeft;
        startTop = element.offsetTop;
        
        selectElement(element);
        
        e.preventDefault();
        e.stopPropagation();
    });
    
    document.addEventListener('mousemove', function(e) {
        if (!isDragging) return;
        
        const deltaX = e.clientX - startX;
        const deltaY = e.clientY - startY;
        
        const parentRect = element.parentElement.getBoundingClientRect();
        const newLeft = startLeft + deltaX;
        const newTop = startTop + deltaY;
        
        element.style.left = Math.max(0, Math.min(newLeft, parentRect.width - element.offsetWidth)) + 'px';
        element.style.top = Math.max(0, Math.min(newTop, parentRect.height - element.offsetHeight)) + 'px';
    });
    
    document.addEventListener('touchmove', function(e) {
        if (!isDragging) return;
        
        const touch = e.touches[0];
        const deltaX = touch.clientX - startX;
        const deltaY = touch.clientY - startY;
        
        const parentRect = element.parentElement.getBoundingClientRect();
        const newLeft = startLeft + deltaX;
        const newTop = startTop + deltaY;
        
        element.style.left = Math.max(0, Math.min(newLeft, parentRect.width - element.offsetWidth)) + 'px';
        element.style.top = Math.max(0, Math.min(newTop, parentRect.height - element.offsetHeight)) + 'px';
    });
    
    document.addEventListener('mouseup', function() {
        if (isDragging) {
            isDragging = false;
            dragHandle.style.cursor = 'grab';
            saveElementToSlideState(element);
        }
    });
    
    document.addEventListener('touchend', function() {
        if (isDragging) {
            isDragging = false;
            saveElementToSlideState(element);
        }
    });
    
    // Double-click to edit
    contentDiv.addEventListener('dblclick', function(e) {
        e.stopPropagation();
        contentDiv.contentEditable = 'true';
        contentDiv.focus();
        showHtmlEditor(contentDiv);
    });
    
    // Touch double-tap to edit
    let lastTap = 0;
    contentDiv.addEventListener('touchend', function(e) {
        const currentTime = new Date().getTime();
        const tapLength = currentTime - lastTap;
        if (tapLength < 300 && tapLength > 0) {
            e.preventDefault();
            contentDiv.contentEditable = 'true';
            contentDiv.focus();
            showHtmlEditor(contentDiv);
        }
        lastTap = currentTime;
    });
    
    // Click element to select it
    element.addEventListener('click', function(e) {
        if (e.target !== contentDiv && !contentDiv.contains(e.target)) {
            e.stopPropagation();
            selectElement(element);
        }
    });
    
    // Prevent drag when clicking content
    contentDiv.addEventListener('mousedown', function(e) {
        e.stopPropagation();
    });
    
    // Save on input
    contentDiv.addEventListener('input', function() {
        saveElementToSlideState(element);
    });
    
    // Save selection for color application
    contentDiv.addEventListener('mouseup', function() {
        const selection = window.getSelection();
        if (selection.rangeCount > 0 && !selection.isCollapsed) {
            MarketingApp.savedRange = selection.getRangeAt(0).cloneRange();
        }
    });
    
    // Add resize handlers
    element.querySelectorAll('.resize-handle').forEach(handle => {
        handle.addEventListener('mousedown', function(e) {
            handleResizeStart(e, element);
        });
        handle.addEventListener('touchstart', function(e) {
            handleResizeStart(e, element);
        });
    });
}

// Add image element
function addImageElement() {
    const input = document.createElement('input');
    input.type = 'file';
    input.accept = 'image/*';
    
    input.onchange = function(e) {
        const file = e.target.files[0];
        if (!file) return;
        
        const reader = new FileReader();
        reader.onload = function(e) {
            const currentSlide = document.querySelector('.carousel-slide.active');
            
            const imageElement = document.createElement('div');
            imageElement.className = 'canvas-element image-element';
            imageElement.style.left = '50px';
            imageElement.style.top = '50px';
            imageElement.style.width = '300px';
            imageElement.style.height = '300px';
            imageElement.style.backgroundImage = `url(${e.target.result})`;
            imageElement.style.backgroundSize = 'cover';
            imageElement.style.backgroundPosition = 'center';
            imageElement.style.backgroundRepeat = 'no-repeat';
            imageElement.dataset.imageSrc = e.target.result;
            
            // Add drag handle at top
            const dragHandle = document.createElement('div');
            dragHandle.className = 'drag-handle';
            dragHandle.style.cssText = `
                position: absolute;
                top: -10px;
                left: 50%;
                transform: translateX(-50%);
                width: 40px;
                height: 20px;
                background: #667eea;
                border-radius: 10px 10px 0 0;
                cursor: move;
                display: flex;
                align-items: center;
                justify-content: center;
                color: white;
                font-size: 10px;
                z-index: 10;
            `;
            dragHandle.innerHTML = '<i class="fas fa-grip-lines"></i>';
            
            // Add controls
            imageElement.innerHTML = `
                <div class="resize-handle nw"></div>
                <div class="resize-handle ne"></div>
                <div class="resize-handle sw"></div>
                <div class="resize-handle se"></div>
                <button class="element-delete-btn" onclick="deleteElement(this); event.stopPropagation();">
                    <i class="fas fa-times"></i>
                </button>
            `;
            
            imageElement.insertBefore(dragHandle, imageElement.firstChild);
            
            currentSlide.appendChild(imageElement);
            makeElementInteractive(imageElement);
            makeElementDraggableTouch(imageElement);
            selectElement(imageElement);
            saveElementToSlideState(imageElement);
        };
        reader.readAsDataURL(file);
    };
    
    input.click();
}

// ====================================
// NEW FEATURE: DELETE ELEMENT BUTTON
// ====================================

function deleteElement(btn) {
    const element = btn.closest('.canvas-element');
    if (element && confirm('Delete this element?')) {
        element.remove();
        removeElementFromSlideState(element);
        hideTextStyleToolbar();
        MarketingApp.selectedElement = null;
        showNotification('Element deleted', 'success');
    }
}

// ====================================
// NEW FEATURE: CANVAS FORMAT SELECTOR
// ====================================

function changeCanvasFormat(format) {
    const canvasArea = document.getElementById('canvasArea');
    
    let width, height;
    
    // Define proper dimensions for each platform
    if (format === '1080x1080') { // Instagram Square
        width = 1080;
        height = 1080;
    } else if (format === '1080x1350') { // Instagram Portrait (4:5 ratio)
        width = 1080;
        height = 1350;
    } else if (format === '1080x1920') { // Instagram Story/Reels (9:16 ratio)
        width = 1080;
        height = 1920;
    } else if (format === '1200x630') { // Facebook Post
        width = 1200;
        height = 630;
    } else if (format === '1200x675') { // Twitter Post
        width = 1200;
        height = 675;
    } else if (format === 'custom') {
        const customWidth = prompt('Enter canvas width in pixels:', '1080');
        const customHeight = prompt('Enter canvas height in pixels:', '1080');
        
        if (customWidth && customHeight) {
            width = parseInt(customWidth);
            height = parseInt(customHeight);
        } else {
            return;
        }
    } else {
        // Parse format string like "1080x1080"
        [width, height] = format.split('x').map(Number);
    }
    
    // Apply dimensions to all slides
    document.querySelectorAll('.carousel-slide').forEach(slide => {
        slide.style.width = width + 'px';
        slide.style.height = height + 'px';
    });
    
    // Save format to state
    MarketingApp.canvasState.format = { width, height, name: format };
    
    showNotification(`Canvas resized to ${width}ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã¢â‚¬Â${height}px`, 'success');
}

// ====================================
// NEW FEATURE: TEXT STYLING TOOLBAR
// ====================================

function selectElement(element) {
    // Deselect all
    document.querySelectorAll('.canvas-element').forEach(el => {
        el.classList.remove('selected');
    });
    
    // Select this one
    element.classList.add('selected');
    MarketingApp.selectedElement = element;
    
    // Show text toolbar if text element
    if (element.classList.contains('text-element')) {
        showTextStyleToolbar(element);
    } else {
        hideTextStyleToolbar();
    }
}

function showTextStyleToolbar(element) {
    const toolbar = document.getElementById('textStyleToolbar');
    if (!toolbar) return;
    
    toolbar.style.display = 'flex';
    MarketingApp.selectedElement = element;
    
    // Save current selection
    const selection = window.getSelection();
    if (selection.rangeCount > 0) {
        MarketingApp.savedRange = selection.getRangeAt(0).cloneRange();
    }
    
    // Position close button on top right
    const closeBtn = toolbar.querySelector('.toolbar-close-btn');
    if (closeBtn) {
        closeBtn.style.position = 'absolute';
        closeBtn.style.top = '8px';
        closeBtn.style.right = '8px';
        closeBtn.style.background = '#ef4444';
        closeBtn.style.color = 'white';
        closeBtn.style.border = 'none';
        closeBtn.style.width = '24px';
        closeBtn.style.height = '24px';
        closeBtn.style.borderRadius = '4px';
        closeBtn.style.cursor = 'pointer';
        closeBtn.style.display = 'flex';
        closeBtn.style.alignItems = 'center';
        closeBtn.style.justifyContent = 'center';
    }
}

function hideTextStyleToolbar() {
    const toolbar = document.getElementById('textStyleToolbar');
    if (toolbar) toolbar.style.display = 'none';
}

function applyTextStyle(property, value) {
    if (!MarketingApp.selectedElement) return;
    
    const element = MarketingApp.selectedElement;
    const contentDiv = element.querySelector('.article-content');
    const target = contentDiv || element;
    
    let selection = window.getSelection();
    
    // Restore saved selection if current selection is collapsed (lost focus)
    if (MarketingApp.savedRange && (!selection.rangeCount || selection.isCollapsed)) {
        selection.removeAllRanges();
        selection.addRange(MarketingApp.savedRange);
    }
    
    // Check if there is selected text
    if (selection && selection.rangeCount > 0 && !selection.isCollapsed) {
        const range = selection.getRangeAt(0);
        
        // Verify selection is within our target element
        if (target.contains(range.commonAncestorContainer)) {
            // Check if selection is already in a styled span
            let existingSpan = null;
            let node = range.commonAncestorContainer;
            
            // Walk up to find if we're inside a span
            while (node && node !== target) {
                if (node.nodeType === 1 && node.tagName === 'SPAN') {
                    existingSpan = node;
                    break;
                }
                node = node.parentNode;
            }
            
            // If we found an existing span and the entire span is selected, just update it
            if (existingSpan && selection.toString() === existingSpan.textContent) {
                if (property === 'fontWeight') {
                    existingSpan.style.fontWeight = 'bold';
                } else if (property === 'fontStyle') {
                    existingSpan.style.fontStyle = 'italic';
                } else if (property === 'textDecoration') {
                    existingSpan.style.textDecoration = 'underline';
                } else if (property === 'fontFamily') {
                    existingSpan.style.setProperty('font-family', value, 'important');
                } else if (property === 'fontSize') {
                    existingSpan.style.setProperty('font-size', value + 'px', 'important');
                } else if (property === 'color') {
                    existingSpan.style.setProperty('color', value, 'important');
                }
                
                // Force repaint
                existingSpan.offsetHeight;
                
                saveElementToSlideState(element);
                return;
            }
            
            // Otherwise create new span
            const span = document.createElement('span');
            
            if (property === 'fontWeight') {
                span.style.fontWeight = 'bold';
            } else if (property === 'fontStyle') {
                span.style.fontStyle = 'italic';
            } else if (property === 'textDecoration') {
                span.style.textDecoration = 'underline';
            } else if (property === 'fontFamily') {
                span.style.setProperty('font-family', value, 'important');
            } else if (property === 'fontSize') {
                span.style.setProperty('font-size', value + 'px', 'important');
            } else if (property === 'color') {
                span.style.setProperty('color', value, 'important');
            }
            
            const contents = range.extractContents();
            span.appendChild(contents);
            range.insertNode(span);
            
            // Force repaint
            span.offsetHeight;
            
            // Only reselect for non-continuous properties (not fontSize during drag)
            if (property !== 'fontSize') {
                selection.removeAllRanges();
                const newRange = document.createRange();
                newRange.selectNodeContents(span);
                selection.addRange(newRange);
            }
            
            saveElementToSlideState(element);
            return;
        }
    }
    
    // No selection - apply to entire element
    if (property === 'fontWeight') {
        target.style.fontWeight = target.style.fontWeight === 'bold' ? 'normal' : 'bold';
    } else if (property === 'fontStyle') {
        target.style.fontStyle = target.style.fontStyle === 'italic' ? 'normal' : 'italic';
    } else if (property === 'textDecoration') {
        target.style.textDecoration = target.style.textDecoration === 'underline' ? 'none' : 'underline';
    } else if (property === 'fontFamily') {
        target.style.fontFamily = value;
    } else if (property === 'fontSize') {
        target.style.fontSize = value + 'px';
    } else if (property === 'color') {
        target.style.color = value;
    }
    
    // Force repaint
    target.offsetHeight;
    
    saveElementToSlideState(element);
}


// Make element interactive (draggable, selectable, resizable)
function makeElementInteractive(element) {
    let isDragging = false;
    let dragOffset = { x: 0, y: 0 };
    
    element.addEventListener('mousedown', function(e) {
        // Check if clicked on drag handle or element itself (not controls)
        const isDragHandle = e.target.classList.contains('drag-handle') || e.target.closest('.drag-handle');
        const isElement = e.target === element;
        
        if (e.target.classList.contains('resize-handle')) {
            handleResizeStart(e, element);
            return;
        }
        
        if (element.contentEditable === 'true' && e.target === element && !isDragHandle) {
            // Allow editing
            return;
        }
        
        if (!isDragHandle && !isElement) {
            return;
        }
        
        e.preventDefault();
        e.stopPropagation();
        
        selectElement(element);
        
        isDragging = true;
        
        // Calculate offset from mouse to element's current position
        const rect = element.getBoundingClientRect();
        const parentRect = element.parentElement.getBoundingClientRect();
        
        dragOffset.x = e.clientX - rect.left;
        dragOffset.y = e.clientY - rect.top;
        
        element.style.cursor = 'grabbing';
        if (element.querySelector('.drag-handle')) {
            element.querySelector('.drag-handle').style.cursor = 'grabbing';
        }
    });
    
    const handleMouseMove = function(e) {
        if (!isDragging) return;
        
        e.preventDefault();
        
        const parentRect = element.parentElement.getBoundingClientRect();
        
        // Calculate new position relative to parent
        const x = e.clientX - parentRect.left - dragOffset.x;
        const y = e.clientY - parentRect.top - dragOffset.y;
        
        // Constrain within parent bounds
        const maxX = parentRect.width - element.offsetWidth;
        const maxY = parentRect.height - element.offsetHeight;
        
        element.style.left = Math.max(0, Math.min(x, maxX)) + 'px';
        element.style.top = Math.max(0, Math.min(y, maxY)) + 'px';
    };
    
    const handleMouseUp = function() {
        if (isDragging) {
            isDragging = false;
            element.style.cursor = 'move';
            if (element.querySelector('.drag-handle')) {
                element.querySelector('.drag-handle').style.cursor = 'move';
            }
            saveElementToSlideState(element);
        }
    };
    
    document.addEventListener('mousemove', handleMouseMove);
    document.addEventListener('mouseup', handleMouseUp);
}

// Touch dragging support for mobile devices
function makeElementDraggableTouch(element) {
    let isDragging = false;
    let dragOffset = { x: 0, y: 0 };
    
    element.addEventListener('touchstart', function(e) {
        const touch = e.touches[0];
        const isDragHandle = e.target.classList.contains('drag-handle') || e.target.closest('.drag-handle');
        const isElement = e.target === element;
        
        if (!isDragHandle && !isElement) {
            return;
        }
        
        e.preventDefault();
        e.stopPropagation();
        
        selectElement(element);
        
        isDragging = true;
        
        // Calculate offset from touch to element's current position
        const rect = element.getBoundingClientRect();
        const parentRect = element.parentElement.getBoundingClientRect();
        
        dragOffset.x = touch.clientX - rect.left;
        dragOffset.y = touch.clientY - rect.top;
    });
    
    const handleTouchMove = function(e) {
        if (!isDragging) return;
        
        e.preventDefault();
        const touch = e.touches[0];
        const parentRect = element.parentElement.getBoundingClientRect();
        
        // Calculate new position relative to parent
        const x = touch.clientX - parentRect.left - dragOffset.x;
        const y = touch.clientY - parentRect.top - dragOffset.y;
        
        // Constrain within parent bounds
        const maxX = parentRect.width - element.offsetWidth;
        const maxY = parentRect.height - element.offsetHeight;
        
        element.style.left = Math.max(0, Math.min(x, maxX)) + 'px';
        element.style.top = Math.max(0, Math.min(y, maxY)) + 'px';
    };
    
    const handleTouchEnd = function() {
        if (isDragging) {
            isDragging = false;
            saveElementToSlideState(element);
        }
    };
    
    element.addEventListener('touchmove', handleTouchMove);
    element.addEventListener('touchend', handleTouchEnd);
}

// Resize handling
function handleResizeStart(e, element) {
    e.preventDefault();
    e.stopPropagation();
    
    const handle = e.target;
    const startX = e.clientX || (e.touches && e.touches[0].clientX);
    const startY = e.clientY || (e.touches && e.touches[0].clientY);
    const startWidth = element.offsetWidth;
    const startHeight = element.offsetHeight;
    const startLeft = element.offsetLeft;
    const startTop = element.offsetTop;
    
    function handleResizeMove(e) {
        const currentX = e.clientX || (e.touches && e.touches[0].clientX);
        const currentY = e.clientY || (e.touches && e.touches[0].clientY);
        const deltaX = currentX - startX;
        const deltaY = currentY - startY;
        
        if (handle.classList.contains('se')) {
            element.style.width = Math.max(50, startWidth + deltaX) + 'px';
            element.style.height = Math.max(50, startHeight + deltaY) + 'px';
        } else if (handle.classList.contains('sw')) {
            const newWidth = Math.max(50, startWidth - deltaX);
            element.style.width = newWidth + 'px';
            element.style.height = Math.max(50, startHeight + deltaY) + 'px';
            element.style.left = (startLeft + (startWidth - newWidth)) + 'px';
        } else if (handle.classList.contains('ne')) {
            const newHeight = Math.max(50, startHeight - deltaY);
            element.style.width = Math.max(50, startWidth + deltaX) + 'px';
            element.style.height = newHeight + 'px';
            element.style.top = (startTop + (startHeight - newHeight)) + 'px';
        } else if (handle.classList.contains('nw')) {
            const newWidth = Math.max(50, startWidth - deltaX);
            const newHeight = Math.max(50, startHeight - deltaY);
            element.style.width = newWidth + 'px';
            element.style.height = newHeight + 'px';
            element.style.left = (startLeft + (startWidth - newWidth)) + 'px';
            element.style.top = (startTop + (startHeight - newHeight)) + 'px';
        }
    }
    
    function handleResizeEnd() {
        document.removeEventListener('mousemove', handleResizeMove);
        document.removeEventListener('mouseup', handleResizeEnd);
        document.removeEventListener('touchmove', handleResizeMove);
        document.removeEventListener('touchend', handleResizeEnd);
        saveElementToSlideState(element);
    }
    
    document.addEventListener('mousemove', handleResizeMove);
    document.addEventListener('mouseup', handleResizeEnd);
    document.addEventListener('touchmove', handleResizeMove);
    document.addEventListener('touchend', handleResizeEnd);
}

function selectElement(element) {
    // Deselect all
    document.querySelectorAll('.canvas-element').forEach(el => {
        el.classList.remove('selected');
    });
    
    // Select this one
    element.classList.add('selected');
    MarketingApp.selectedElement = element;
}

// Layer management functions
function bringForward() {
    if (!MarketingApp.selectedElement) return;
    
    const element = MarketingApp.selectedElement;
    const currentSlide = document.querySelector('.carousel-slide.active');
    const allElements = Array.from(currentSlide.querySelectorAll('.canvas-element'));
    
    const currentZ = parseInt(element.style.zIndex) || 100;
    
    // Find all elements with z-index higher than current
    const elementsAbove = allElements
        .filter(el => el !== element)
        .map(el => ({
            element: el,
            zIndex: parseInt(el.style.zIndex) || 100
        }))
        .filter(item => item.zIndex > currentZ)
        .sort((a, b) => a.zIndex - b.zIndex); // Sort ascending
    
    if (elementsAbove.length > 0) {
        // Swap z-index with the next element above
        const nextElement = elementsAbove[0];
        element.style.zIndex = nextElement.zIndex;
        nextElement.element.style.zIndex = currentZ;
        
        saveElementToSlideState(element);
        saveElementToSlideState(nextElement.element);
        showNotification('Element moved forward', 'success');
    } else {
        // Already at the top
        showNotification('Element is already at the front', 'info');
    }
}

function sendBackward() {
    if (!MarketingApp.selectedElement) return;
    
    const element = MarketingApp.selectedElement;
    const currentSlide = document.querySelector('.carousel-slide.active');
    const allElements = Array.from(currentSlide.querySelectorAll('.canvas-element'));
    
    const currentZ = parseInt(element.style.zIndex) || 100;
    
    // Find all elements with z-index lower than current
    const elementsBelow = allElements
        .filter(el => el !== element)
        .map(el => ({
            element: el,
            zIndex: parseInt(el.style.zIndex) || 100
        }))
        .filter(item => item.zIndex < currentZ)
        .sort((a, b) => b.zIndex - a.zIndex); // Sort descending
    
    if (elementsBelow.length > 0) {
        // Swap z-index with the next element below
        const nextElement = elementsBelow[0];
        element.style.zIndex = nextElement.zIndex;
        nextElement.element.style.zIndex = currentZ;
        
        saveElementToSlideState(element);
        saveElementToSlideState(nextElement.element);
        showNotification('Element moved backward', 'success');
    } else {
        // Already at the bottom
        showNotification('Element is already at the back', 'info');
    }
}

function bringToFront() {
    if (!MarketingApp.selectedElement) return;
    
    const element = MarketingApp.selectedElement;
    const currentSlide = document.querySelector('.carousel-slide.active');
    const allElements = Array.from(currentSlide.querySelectorAll('.canvas-element'));
    
    // Find highest z-index
    let maxZ = 100;
    allElements.forEach(el => {
        const z = parseInt(el.style.zIndex) || 100;
        if (z > maxZ) maxZ = z;
    });
    
    element.style.zIndex = maxZ + 1;
    
    saveElementToSlideState(element);
    showNotification('Element brought to front', 'success');
}

function sendToBack() {
    if (!MarketingApp.selectedElement) return;
    
    const element = MarketingApp.selectedElement;
    const currentSlide = document.querySelector('.carousel-slide.active');
    const allElements = Array.from(currentSlide.querySelectorAll('.canvas-element'));
    
    // Find lowest z-index
    let minZ = 100;
    allElements.forEach(el => {
        const z = parseInt(el.style.zIndex) || 100;
        if (z < minZ) minZ = z;
    });
    
    element.style.zIndex = minZ - 1;
    
    saveElementToSlideState(element);
    showNotification('Element sent to back', 'success');
}

// Background color change
function changeBackgroundColor(color) {
    const currentSlide = document.querySelector('.carousel-slide.active');
    currentSlide.style.backgroundColor = color;
    currentSlide.style.background = color;
    
    // Force repaint
    currentSlide.offsetHeight;
    
    // Save to slide state
    const slideNum = parseInt(currentSlide.dataset.slide);
    if (MarketingApp.slides[slideNum]) {
        MarketingApp.slides[slideNum].background = color;
    }
    
    // Update thumbnail
    generateSlideThumbnails();
}

function toggleFullscreen() {
    const canvasLayout = document.querySelector('.canvas-layout');
    const sidebar = document.querySelector('.articles-sidebar');
    const canvasMain = document.querySelector('.canvas-main');
    const btn = event.target.closest('.toolbar-btn');
    
    if (!MarketingApp.canvasState.isFullscreen) {
        // Enter fullscreen
        canvasLayout.style.cssText = `
            position: fixed !important;
            top: 0 !important;
            left: 0 !important;
            right: 0 !important;
            bottom: 0 !important;
            width: 100vw !important;
            height: 100vh !important;
            z-index: 9999;
            background: #f3f4f6;
            display: grid;
            grid-template-columns: 1fr 300px;
            padding: 20px;
            gap: 20px;
            overflow: hidden;
            margin: 0 !important;
        `;
        
        // Make canvas-main scrollable and constrained
        if (canvasMain) {
            canvasMain.style.height = '100%';
            canvasMain.style.overflow = 'auto';
            canvasMain.style.display = 'flex';
            canvasMain.style.flexDirection = 'column';
        }
        
        // Make sidebar full height and scrollable
        if (sidebar) {
            sidebar.style.display = 'flex';
            sidebar.style.flexDirection = 'column';
            sidebar.style.height = '100%';
            sidebar.style.overflow = 'hidden';
        }
        
        // Find the articles-list container and make it scrollable
        const articlesListContainer = sidebar.querySelector('.articles-list');
        if (articlesListContainer) {
            articlesListContainer.style.flex = '1';
            articlesListContainer.style.overflow = 'auto';
            articlesListContainer.style.minHeight = '0';
        }
        
        if (btn) btn.innerHTML = '<i class="fas fa-compress"></i>';
        MarketingApp.canvasState.isFullscreen = true;
        document.body.style.overflow = 'hidden';
        document.addEventListener('keydown', escapeFullscreen);
    } else {
        // Exit fullscreen
        canvasLayout.style.cssText = '';
        
        if (canvasMain) {
            canvasMain.style.height = '';
            canvasMain.style.overflow = '';
            canvasMain.style.display = '';
            canvasMain.style.flexDirection = '';
        }
        
        if (sidebar) {
            sidebar.style.display = '';
            sidebar.style.flexDirection = '';
            sidebar.style.height = '';
            sidebar.style.overflow = '';
        }
        
        const articlesListContainer = sidebar.querySelector('.articles-list');
        if (articlesListContainer) {
            articlesListContainer.style.flex = '';
            articlesListContainer.style.overflow = '';
            articlesListContainer.style.minHeight = '';
        }
        
        if (btn) btn.innerHTML = '<i class="fas fa-expand"></i>';
        MarketingApp.canvasState.isFullscreen = false;
        document.body.style.overflow = '';
        document.removeEventListener('keydown', escapeFullscreen);
    }
}
function escapeFullscreen(e) {
    if (e.key === 'Escape' && MarketingApp.canvasState.isFullscreen) {
        toggleFullscreen();
    }
}

// Slide management
function addSlide() {
    MarketingApp.totalSlides++;
    const newSlideNum = MarketingApp.totalSlides;
    
    const canvasArea = document.getElementById('canvasArea');
    const newSlide = document.createElement('div');
    newSlide.className = 'carousel-slide';
    newSlide.id = 'slide-' + newSlideNum;
    newSlide.dataset.slide = newSlideNum;
    newSlide.style.backgroundColor = '#ffffff';
    
    canvasArea.appendChild(newSlide);
    
    // Initialize slide state
    MarketingApp.slides[newSlideNum] = {
        elements: [],
        background: '#ffffff',
        backgroundImage: null
    };
    
    // Switch to new slide
    switchToSlide(newSlideNum);
    
    updateSlideIndicator();
    generateSlideThumbnails();
    
    showNotification('New slide added', 'success');
}

function nextSlide() {
    if (MarketingApp.currentSlide < MarketingApp.totalSlides) {
        switchToSlide(MarketingApp.currentSlide + 1);
    }
}

function previousSlide() {
    if (MarketingApp.currentSlide > 1) {
        switchToSlide(MarketingApp.currentSlide - 1);
    }
}

function switchToSlide(slideNum) {
    // Hide all slides
    document.querySelectorAll('.carousel-slide').forEach(slide => {
        slide.classList.remove('active');
    });
    
    // Show target slide
    const targetSlide = document.getElementById('slide-' + slideNum);
    if (targetSlide) {
        targetSlide.classList.add('active');
        MarketingApp.currentSlide = slideNum;
        updateSlideIndicator();
        generateSlideThumbnails();
    }
}

function updateSlideIndicator() {
    document.getElementById('currentSlideNum').textContent = MarketingApp.currentSlide;
    document.getElementById('totalSlidesNum').textContent = MarketingApp.totalSlides;
}

function generateSlideThumbnails() {
    const container = document.getElementById('slideThumbnails');
    if (!container) return;
    
    container.innerHTML = '';
    
    for (let i = 1; i <= MarketingApp.totalSlides; i++) {
        const thumb = document.createElement('div');
        thumb.className = 'slide-thumb' + (i === MarketingApp.currentSlide ? ' active' : '');
        thumb.style.position = 'relative';
        thumb.style.display = 'inline-block';
        thumb.onclick = () => switchToSlide(i);
        
        const slide = document.getElementById('slide-' + i);
        if (slide) {
            thumb.style.backgroundColor = slide.style.backgroundColor || '#ffffff';
        }
        
        // Add delete button
        const deleteBtn = document.createElement('button');
        deleteBtn.className = 'thumb-delete';
        deleteBtn.innerHTML = '<i class="fas fa-times"></i>';
        deleteBtn.onclick = function(e) {
            e.stopPropagation();
            deleteSlideByNumber(i);
        };
        
        thumb.appendChild(deleteBtn);
        container.appendChild(thumb);
    }
}

// State management
function saveElementToSlideState(element) {
    const slideNum = MarketingApp.currentSlide;
    if (!MarketingApp.slides[slideNum]) return;
    
    const elementData = {
        // Common properties for all elements
        left: element.style.left,
        top: element.style.top,
        width: element.style.width,
        height: element.style.height,
        zIndex: element.style.zIndex || '100',
        className: element.className
    };
    
    // Type-specific properties
    if (element.classList.contains('text-element')) {
        elementData.type = 'text';
        const textContent = element.querySelector('.text-content');
        if (textContent) {
            elementData.content = textContent.innerHTML;
            elementData.fontSize = textContent.style.fontSize;
            elementData.color = textContent.style.color;
            elementData.fontWeight = textContent.style.fontWeight;
            elementData.fontFamily = textContent.style.fontFamily;
            elementData.textAlign = textContent.style.textAlign;
        }
    } else if (element.classList.contains('article-element')) {
        elementData.type = 'article';
        elementData.articleId = element.dataset.articleId;
        const articleContent = element.querySelector('.article-content');
        if (articleContent) {
            elementData.content = articleContent.innerHTML;
        }
    } else if (element.classList.contains('image-element')) {
        elementData.type = 'image';
        elementData.imageSrc = element.dataset.imageSrc;
        elementData.backgroundSize = element.style.backgroundSize;
        elementData.backgroundPosition = element.style.backgroundPosition;
    } else if (element.classList.contains('sticker-element')) {
        elementData.type = 'sticker';
        elementData.stickerUrl = element.dataset.stickerUrl;
        elementData.backgroundImage = element.style.backgroundImage;
    } else if (element.classList.contains('shape-element')) {
        elementData.type = 'shape';
        elementData.shapeType = element.dataset.shapeType;
        elementData.fillColor = element.dataset.fillColor;
        elementData.strokeColor = element.dataset.strokeColor;
        elementData.strokeWidth = element.dataset.strokeWidth;
    }
    
    // Create unique ID for this element based on its properties
    const elementId = `${elementData.type}_${elementData.left}_${elementData.top}`;
    
    // Update or add element
    const existingIndex = MarketingApp.slides[slideNum].elements.findIndex(e => {
        return e.left === elementData.left && e.top === elementData.top && e.type === elementData.type;
    });
    
    if (existingIndex >= 0) {
        MarketingApp.slides[slideNum].elements[existingIndex] = elementData;
    } else {
        MarketingApp.slides[slideNum].elements.push(elementData);
    }
    
    console.log('Saved element to slide state:', elementData);
}

function removeElementFromSlideState(element) {
    const slideNum = MarketingApp.currentSlide;
    if (!MarketingApp.slides[slideNum]) return;
    
    // This is simplified - in production, you'd want better element tracking
    MarketingApp.slides[slideNum].elements = MarketingApp.slides[slideNum].elements.filter(e => {
        return !(e.left === element.style.left && e.top === element.style.top);
    });
}

// Template Management
function openTemplateModal() {
    document.getElementById('templateModal').classList.add('active');
    loadUserTemplates(); // Load user templates first
}

function setActiveTemplateTab(tabName) {
    // Reset all buttons
    const buttons = ['myTemplatesBtn', 'sharedTemplatesBtn', 'defaultTemplatesBtn'];
    buttons.forEach(btnId => {
        const btn = document.getElementById(btnId);
        if (btn) {
            btn.style.background = 'white';
            btn.style.borderColor = '#d1d5db';
            btn.style.color = '#6b7280';
        }
    });
    
    // Activate selected button
    const activeBtn = document.getElementById(tabName + 'Btn');
    if (activeBtn) {
        activeBtn.style.background = '#667eea';
        activeBtn.style.borderColor = '#667eea';
        activeBtn.style.color = 'white';
    }
}

function loadUserTemplates() {
    setActiveTemplateTab('myTemplates');
    console.log('=== LOADING USER TEMPLATES ===');
    console.log('URL: /management/ajax/marketing.php?action=get_user_templates');
    
    fetch('/management/ajax/marketing.php?action=get_user_templates')
        .then(response => {
            console.log('Response status:', response.status);
            console.log('Response headers:', response.headers);
            console.log('Response ok:', response.ok);
            
            // Get the raw text first to see what we're actually receiving
            return response.text().then(text => {
                console.log('Raw response text:', text);
                try {
                    const data = JSON.parse(text);
                    console.log('Parsed JSON:', data);
                    return data;
                } catch (e) {
                    console.error('JSON parse error:', e);
                    throw new Error('Invalid JSON response: ' + text);
                }
            });
        })
        .then(data => {
            console.log('Success value:', data.success);
            console.log('Templates array:', data.templates);
            console.log('Templates count:', data.templates ? data.templates.length : 'undefined');
            
            if (data.success) {
                if (data.templates && data.templates.length > 0) {
                    console.log('Calling displayTemplates with', data.templates.length, 'templates');
                    displayTemplates(data.templates, 'user');
                } else {
                    console.log('No templates found - showing empty state');
                    displayTemplates([], 'user');
                }
            } else {
                console.error('API returned success=false:', data.message);
                showNotification('Failed to load templates: ' + (data.message || 'Unknown error'), 'error');
            }
        })
        .catch(error => {
            console.error('=== ERROR LOADING USER TEMPLATES ===');
            console.error('Error object:', error);
            console.error('Error message:', error.message);
            console.error('Error stack:', error.stack);
            showNotification('Failed to load templates: ' + error.message, 'error');
        });
}

function loadSharedTemplates() {
    setActiveTemplateTab('sharedTemplates');
    fetch('/management/ajax/marketing.php?action=get_shared_templates')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayTemplates(data.templates, 'shared');
            }
        })
        .catch(error => console.error('Error loading shared templates:', error));
}

function loadDefaultTemplates() {
    setActiveTemplateTab('defaultTemplates');
    // Default templates for demonstration
    const defaultTemplates = [
        {
            id: 'default-1',
            name: 'Instagram Story - Bold',
            slides: 1,
            thumbnail: 'https://via.placeholder.com/400x700/667eea/ffffff?text=Bold+Story',
            data: {
                slides: {
                    1: {
                        background: '#667eea',
                        elements: []
                    }
                }
            }
        },
        {
            id: 'default-2',
            name: 'Instagram Carousel - News',
            slides: 3,
            thumbnail: 'https://via.placeholder.com/1080x1080/10b981/ffffff?text=News+Carousel',
            data: {
                slides: {
                    1: { background: '#10b981', elements: [] },
                    2: { background: '#ffffff', elements: [] },
                    3: { background: '#10b981', elements: [] }
                }
            }
        },
        {
            id: 'default-3',
            name: 'Facebook Post - Minimal',
            slides: 1,
            thumbnail: 'https://via.placeholder.com/1200x630/ffffff/333333?text=Minimal+Post',
            data: {
                slides: {
                    1: {
                        background: '#ffffff',
                        elements: []
                    }
                }
            }
        },
        {
            id: 'default-4',
            name: 'Twitter Header - Gradient',
            slides: 1,
            thumbnail: 'https://via.placeholder.com/1500x500/764ba2/ffffff?text=Gradient+Header',
            data: {
                slides: {
                    1: {
                        background: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
                        elements: []
                    }
                }
            }
        },
        {
            id: 'default-5',
            name: 'Multi-Platform - 5 Slides',
            slides: 5,
            thumbnail: 'https://via.placeholder.com/1080x1080/f59e0b/ffffff?text=5+Slides',
            data: {
                slides: {
                    1: { background: '#f59e0b', elements: [] },
                    2: { background: '#10b981', elements: [] },
                    3: { background: '#3b82f6', elements: [] },
                    4: { background: '#8b5cf6', elements: [] },
                    5: { background: '#ef4444', elements: [] }
                }
            }
        }
    ];
    
    displayTemplates(defaultTemplates, 'default');
}

function displayTemplates(templates, type) {
    const gallery = document.getElementById('templateGallery');
    
    console.log('displayTemplates called with', templates.length, 'templates of type', type);
    console.log('Templates:', templates);
    
    if (templates.length === 0) {
        gallery.innerHTML = `
            <div style="grid-column: 1/-1; text-align: center; padding: 60px 20px; color: #9ca3af;">
                <i class="fas fa-layer-group" style="font-size: 64px; margin-bottom: 16px; opacity: 0.3;"></i>
                <p style="font-size: 16px; font-weight: 500; margin-bottom: 8px;">No templates available</p>
                <p style="font-size: 14px; opacity: 0.7;">Create your first template by designing a canvas and clicking "Save as Template"</p>
            </div>
        `;
        return;
    }
    
    gallery.innerHTML = templates.map(template => {
        const thumbnail = template.thumbnail_url || template.thumbnail || 'data:image/svg+xml,%3Csvg xmlns="http://www.w3.org/2000/svg" width="280" height="200"%3E%3Crect width="280" height="200" fill="%23f3f4f6"/%3E%3Ctext x="50%25" y="50%25" text-anchor="middle" dy=".3em" fill="%239ca3af" font-family="Arial" font-size="24"%3ETemplate%3C/text%3E%3C/svg%3E';
        
        console.log('Rendering template:', template.name, 'ID:', template.id, 'Thumbnail:', thumbnail);
        
        return `
            <div class="template-card" data-template-id="${template.id}" onclick="selectTemplate(${template.id})">
                <div class="template-preview" style="background-image: url('${thumbnail}'); background-size: cover; background-position: center;">
                </div>
                <div class="template-name">${escapeHtml(template.name)}</div>
                <div class="template-slides">
                    <i class="fas fa-images"></i> ${template.slides} slide${template.slides > 1 ? 's' : ''}
                </div>
            </div>
        `;
    }).join('');
    
    MarketingApp.templates = templates;
    console.log('Templates displayed, stored in MarketingApp.templates');
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function selectTemplate(templateId) {
    // Remove selection from all
    document.querySelectorAll('.template-card').forEach(card => {
        card.classList.remove('selected');
    });
    
    // Select this one
    const card = document.querySelector(`[data-template-id="${templateId}"]`);
    if (card) {
        card.classList.add('selected');
        MarketingApp.selectedTemplate = MarketingApp.templates.find(t => t.id == templateId);
    }
}

function applySelectedTemplate() {
    if (!MarketingApp.selectedTemplate) {
        showNotification('Please select a template', 'warning');
        return;
    }
    
    const template = MarketingApp.selectedTemplate;
    
    // Clear current canvas
    if (confirm('Applying this template will replace your current work. Continue?')) {
        applyTemplateToCanvas(template);
        closeModal('templateModal');
    }
}

function applyTemplateToCanvas(template) {
    if (!template || !template.template_data) {
        showNotification('Invalid template data', 'error');
        return;
    }
    
    // Clear canvas
    const canvasArea = document.getElementById('canvasArea');
    canvasArea.innerHTML = '';
    
    // Reset state
    MarketingApp.slides = {};
    MarketingApp.totalSlides = template.slides || 1;
    MarketingApp.currentSlide = 1;
    MarketingApp.nextZIndex = 100;
    
    const templateData = template.template_data;
    
    // Apply canvas state if available
    if (templateData.canvasState) {
        MarketingApp.canvasState.canvasWidth = templateData.canvasState.width || 1080;
        MarketingApp.canvasState.canvasHeight = templateData.canvasState.height || 1080;
        MarketingApp.canvasState.backgroundColor = templateData.canvasState.backgroundColor || '#ffffff';
    }
    
    // Create slides from template with complete element recreation
    Object.keys(templateData.slides).forEach((slideNum, index) => {
        const slideData = templateData.slides[slideNum];
        const slide = document.createElement('div');
        slide.className = 'carousel-slide' + (index === 0 ? ' active' : '');
        slide.id = 'slide-' + slideNum;
        slide.dataset.slide = slideNum;
        
        // Apply slide background
        if (slideData.background) {
            slide.style.background = slideData.background;
        }
        if (slideData.backgroundImage) {
            slide.style.backgroundImage = slideData.backgroundImage;
            slide.style.backgroundSize = slideData.backgroundSize || 'cover';
            slide.style.backgroundPosition = slideData.backgroundPosition || 'center';
        }
        
        canvasArea.appendChild(slide);
        
        // Recreate all elements with complete properties
        if (slideData.elements && Array.isArray(slideData.elements)) {
            slideData.elements.forEach((elementData, elemIndex) => {
                const element = document.createElement('div');
                element.className = 'canvas-element';
                element.dataset.type = elementData.type || 'text';
                element.id = elementData.id || `element-${slideNum}-${Date.now()}-${elemIndex}`;
                
                // Position and size
                element.style.position = 'absolute';
                element.style.left = elementData.left || '50px';
                element.style.top = elementData.top || '50px';
                element.style.width = elementData.width || '200px';
                element.style.height = elementData.height || 'auto';
                element.style.zIndex = elementData.zIndex || getNextZIndex();
                
                // Common properties
                if (elementData.transform) element.style.transform = elementData.transform;
                if (elementData.opacity) element.style.opacity = elementData.opacity;
                if (elementData.backgroundColor) element.style.backgroundColor = elementData.backgroundColor;
                if (elementData.border) element.style.border = elementData.border;
                if (elementData.borderRadius) element.style.borderRadius = elementData.borderRadius;
                if (elementData.boxShadow) element.style.boxShadow = elementData.boxShadow;
                if (elementData.padding) element.style.padding = elementData.padding;
                
                // Type-specific recreation
                if (elementData.type === 'text') {
                    element.innerHTML = elementData.content || 'Text';
                    element.contentEditable = 'true';
                    element.style.fontSize = elementData.fontSize || '16px';
                    element.style.fontFamily = elementData.fontFamily || 'Inter';
                    element.style.color = elementData.color || '#111827';
                    element.style.minWidth = '100px';
                    element.style.minHeight = '40px';
                    
                    if (elementData.fontWeight) element.style.fontWeight = elementData.fontWeight;
                    if (elementData.fontStyle) element.style.fontStyle = elementData.fontStyle;
                    if (elementData.textAlign) element.style.textAlign = elementData.textAlign;
                    if (elementData.textDecoration) element.style.textDecoration = elementData.textDecoration;
                    if (elementData.lineHeight) element.style.lineHeight = elementData.lineHeight;
                    
                    // Text element event listeners
                    element.addEventListener('click', function(e) {
                        e.stopPropagation();
                        selectElement(this);
                    });
                    
                    element.addEventListener('input', function() {
                        // Auto-resize if needed
                    });
                    
                } else if (elementData.type === 'image') {
                    element.style.backgroundSize = elementData.backgroundSize || 'cover';
                    element.style.backgroundPosition = elementData.backgroundPosition || 'center';
                    element.style.backgroundRepeat = 'no-repeat';
                    element.style.minWidth = '100px';
                    element.style.minHeight = '100px';
                    
                    if (elementData.src) {
                        element.style.backgroundImage = `url('${elementData.src}')`;
                    }
                    
                    element.addEventListener('click', function(e) {
                        e.stopPropagation();
                        selectElement(this);
                    });
                }
                
                // Add resize handles
                const handles = ['nw', 'ne', 'sw', 'se'];
                handles.forEach(pos => {
                    const handle = document.createElement('div');
                    handle.className = `resize-handle ${pos}`;
                    handle.style.display = 'none';
                    element.appendChild(handle);
                    
                    handle.addEventListener('mousedown', function(e) {
                        e.stopPropagation();
                        startResize(e, element, pos);
                    });
                });
                
                // Make element draggable
                element.addEventListener('mousedown', function(e) {
                    if (e.target === element || element.contains(e.target)) {
                        if (!e.target.classList.contains('resize-handle')) {
                            startDrag(e, element);
                        }
                    }
                });
                
                slide.appendChild(element);
            });
        }
        
        // Store slide data
        MarketingApp.slides[slideNum] = {
            elements: slideData.elements || [],
            background: slideData.background || '#ffffff',
            backgroundImage: slideData.backgroundImage || null
        };
    });
    
    // Apply slide settings if available
    if (templateData.slideSettings) {
        MarketingApp.canvasState.slideSettings = templateData.slideSettings;
    }
    
    updateSlideIndicator();
    generateSlideThumbnails();
    
    showNotification('Template applied successfully!', 'success');
}

// Save as template
function saveAsTemplate() {
    const templateName = prompt('Enter a name for this template:');
    if (!templateName) return;
    
    // Collect complete data from all slides including all element properties
    const slidesData = {};
    
    for (let slideNum = 1; slideNum <= MarketingApp.totalSlides; slideNum++) {
        const slideElement = document.getElementById('slide-' + slideNum);
        if (!slideElement) continue;
        
        // Get all elements in this slide
        const elements = slideElement.querySelectorAll('.canvas-element');
        const elementsData = [];
        
        elements.forEach(element => {
            const elementData = {
                type: element.dataset.type,
                id: element.id,
                left: element.style.left,
                top: element.style.top,
                width: element.style.width,
                height: element.style.height,
                zIndex: element.style.zIndex || 100
            };
            
            // Type-specific data
            if (element.dataset.type === 'text') {
                const textElement = element.querySelector('[contenteditable]') || element;
                elementData.content = textElement.innerHTML || textElement.textContent;
                elementData.fontSize = element.style.fontSize;
                elementData.fontFamily = element.style.fontFamily;
                elementData.color = element.style.color;
                elementData.fontWeight = element.style.fontWeight;
                elementData.fontStyle = element.style.fontStyle;
                elementData.textAlign = element.style.textAlign;
                elementData.textDecoration = element.style.textDecoration;
                elementData.lineHeight = element.style.lineHeight;
            } else if (element.dataset.type === 'image') {
                elementData.src = element.style.backgroundImage ? 
                    element.style.backgroundImage.replace(/url\(['"]?([^'"]+)['"]?\)/, '$1') : 
                    (element.querySelector('img') ? element.querySelector('img').src : '');
                elementData.objectFit = element.style.objectFit || 'cover';
                elementData.backgroundSize = element.style.backgroundSize;
                elementData.backgroundPosition = element.style.backgroundPosition;
            }
            
            // Common properties for all element types
            elementData.transform = element.style.transform;
            elementData.opacity = element.style.opacity || '1';
            elementData.backgroundColor = element.style.backgroundColor;
            elementData.border = element.style.border;
            elementData.borderRadius = element.style.borderRadius;
            elementData.boxShadow = element.style.boxShadow;
            elementData.padding = element.style.padding;
            
            elementsData.push(elementData);
        });
        
        slidesData[slideNum] = {
            background: slideElement.style.background || slideElement.style.backgroundColor || '#ffffff',
            backgroundImage: slideElement.style.backgroundImage || null,
            backgroundSize: slideElement.style.backgroundSize || 'cover',
            backgroundPosition: slideElement.style.backgroundPosition || 'center',
            elements: elementsData
        };
    }
    
    // Create thumbnail from current slide
    const currentSlide = document.getElementById('slide-' + MarketingApp.currentSlide);
    let thumbnailData = null;
    
    if (currentSlide) {
        // Use html2canvas if available, otherwise use a placeholder
        if (typeof html2canvas !== 'undefined') {
            html2canvas(currentSlide, {
                scale: 0.5,
                backgroundColor: null
            }).then(canvas => {
                thumbnailData = canvas.toDataURL('image/png');
                sendTemplateData(templateName, slidesData, thumbnailData);
            }).catch(() => {
                sendTemplateData(templateName, slidesData, null);
            });
        } else {
            sendTemplateData(templateName, slidesData, null);
        }
    } else {
        sendTemplateData(templateName, slidesData, null);
    }
}

function sendTemplateData(templateName, slidesData, thumbnail) {
    const isShared = confirm('Make this template available to all users?') ? 1 : 0;
    
    const templateData = {
        canvasState: {
            width: MarketingApp.canvasState.canvasWidth,
            height: MarketingApp.canvasState.canvasHeight,
            backgroundColor: MarketingApp.canvasState.backgroundColor
        },
        slides: slidesData,
        slideSettings: MarketingApp.canvasState.slideSettings
    };
    
    const payload = {
        action: 'save_template',
        name: templateName,
        slides: MarketingApp.totalSlides,
        template_data: templateData,
        thumbnail: thumbnail,
        is_shared: isShared
    };
    
    console.log('Saving template with payload:', payload);
    
    fetch('/management/ajax/marketing.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify(payload)
    })
    .then(response => {
        console.log('Response status:', response.status);
        if (!response.ok) {
            throw new Error('HTTP error! status: ' + response.status);
        }
        return response.json();
    })
    .then(data => {
        console.log('Save response:', data);
        if (data.success) {
            showNotification('Template saved successfully!', 'success');
            // Reload user templates to show the new one
            if (document.getElementById('templateModal').classList.contains('active')) {
                loadUserTemplates();
            }
        } else {
            console.error('Save failed:', data.message);
            showNotification(data.message || 'Failed to save template', 'error');
        }
    })
    .catch(error => {
        console.error('Error saving template:', error);
        showNotification('Error saving template: ' + error.message, 'error');
    });
}

// Slide settings
function openSlideSettingsModal() {
    document.getElementById('slideSettingsModal').classList.add('active');
    document.getElementById('slideCount').value = MarketingApp.totalSlides;
}

function updateSlideCount(count) {
    count = parseInt(count);
    if (count < 1) count = 1;
    if (count > 10) count = 10;
    
    document.getElementById('slideCount').value = count;
}

function applySlideSettings() {
    const targetCount = parseInt(document.getElementById('slideCount').value);
    const duration = parseInt(document.getElementById('slideDuration').value);
    const transition = document.getElementById('slideTransition').value;
    
    MarketingApp.canvasState.slideSettings = { duration, transition };
    
    // Adjust slide count
    if (targetCount > MarketingApp.totalSlides) {
        for (let i = MarketingApp.totalSlides + 1; i <= targetCount; i++) {
            addSlide();
        }
    } else if (targetCount < MarketingApp.totalSlides) {
        if (confirm(`Remove ${MarketingApp.totalSlides - targetCount} slide(s)?`)) {
            for (let i = MarketingApp.totalSlides; i > targetCount; i--) {
                const slide = document.getElementById('slide-' + i);
                if (slide) slide.remove();
                delete MarketingApp.slides[i];
            }
            MarketingApp.totalSlides = targetCount;
            if (MarketingApp.currentSlide > targetCount) {
                switchToSlide(targetCount);
            }
            updateSlideIndicator();
            generateSlideThumbnails();
        }
    }
    
    closeModal('slideSettingsModal');
    showNotification('Slide settings applied', 'success');
}

// Save carousel/project
function saveCarousel() {
    const projectName = prompt('Enter a name for this project:');
    if (!projectName) return;
    
    const projectData = {
        name: projectName,
        totalSlides: MarketingApp.totalSlides,
        currentSlide: MarketingApp.currentSlide,
        slides: MarketingApp.slides,
        canvasState: MarketingApp.canvasState,
        articleLinks: MarketingApp.exportData.articleLinks,
        created_at: new Date().toISOString()
    };
    
    fetch('/management/ajax/marketing.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            action: 'save_project',
            project: projectData
        })
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('Network response was not ok');
        }
        return response.json();
    })
    .then(data => {
        if (data.success) {
            showNotification('Project saved successfully', 'success');
            MarketingApp.currentProjectId = data.project_id;
        } else {
            showNotification(data.message || 'Failed to save project', 'error');
        }
    })
    .catch(error => {
        console.error('Error saving project:', error);
        showNotification('Error saving project: ' + error.message, 'error');
    });
}

// Load project
function openLoadModal() {
    document.getElementById('loadModal').classList.add('active');
    loadSavedProjects();
}

function loadSavedProjects() {
    fetch('/management/ajax/marketing.php?action=get_projects')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displaySavedProjects(data.projects);
            }
        })
        .catch(error => {
            console.error('Error loading projects:', error);
            showNotification('Failed to load projects', 'error');
        });
}

function displaySavedProjects(projects) {
    const container = document.getElementById('savedProjectsList');
    
    if (projects.length === 0) {
        container.innerHTML = `
            <div style="text-align: center; padding: 40px; color: #9ca3af;">
                <i class="fas fa-folder-open" style="font-size: 48px; margin-bottom: 12px; opacity: 0.5;"></i>
                <p>No saved projects</p>
            </div>
        `;
        return;
    }
    
    container.innerHTML = projects.map(project => `
        <div class="project-item" style="padding: 16px; border: 1px solid #e5e7eb; border-radius: 8px; margin-bottom: 12px; cursor: pointer;" onclick="loadProject(${project.id})">
            <div style="display: flex; justify-content: space-between; align-items: start;">
                <div>
                    <h4 style="font-size: 16px; font-weight: 600; margin-bottom: 4px;">${project.name}</h4>
                    <p style="font-size: 14px; color: #6b7280; margin-bottom: 8px;">${project.totalSlides} slides</p>
                    <p style="font-size: 12px; color: #9ca3af;">
                        <i class="fas fa-clock"></i> ${new Date(project.created_at).toLocaleDateString()}
                    </p>
                </div>
                <div>
                    <button class="btn btn-secondary" style="padding: 6px 12px;" onclick="loadProject(${project.id}); event.stopPropagation();">
                        <i class="fas fa-folder-open"></i> Load
                    </button>
                    <button class="btn btn-danger" style="padding: 6px 12px; margin-left: 8px;" onclick="deleteProject(${project.id}); event.stopPropagation();">
                        <i class="fas fa-trash"></i>
                    </button>
                </div>
            </div>
        </div>
    `).join('');
}

function loadProject(projectId) {
    fetch(`/management/ajax/marketing.php?action=get_project&id=${projectId}`)
        .then(response => {
            if (!response.ok) {
                throw new Error('Network response was not ok');
            }
            return response.json();
        })
        .then(data => {
            if (data.success) {
                applyProjectToCanvas(data.project);
                closeModal('loadModal');
                showNotification('Project loaded successfully', 'success');
            } else {
                showNotification(data.message || 'Failed to load project', 'error');
            }
        })
        .catch(error => {
            console.error('Error loading project:', error);
            showNotification('Error loading project: ' + error.message, 'error');
        });
}

function applyProjectToCanvas(project) {
    // Clear canvas
    const canvasArea = document.getElementById('canvasArea');
    canvasArea.innerHTML = '';
    
    // Apply project data
    MarketingApp.totalSlides = project.totalSlides;
    MarketingApp.currentSlide = project.currentSlide || 1;
    MarketingApp.slides = project.slides;
    MarketingApp.canvasState = project.canvasState;
    MarketingApp.exportData.articleLinks = project.articleLinks || [];
    MarketingApp.currentProjectId = project.id;
    
    // Recreate slides
    Object.keys(project.slides).forEach(slideNum => {
        const slideData = project.slides[slideNum];
        const slide = document.createElement('div');
        slide.className = 'carousel-slide' + (parseInt(slideNum) === MarketingApp.currentSlide ? ' active' : '');
        slide.id = 'slide-' + slideNum;
        slide.dataset.slide = slideNum;
        slide.style.background = slideData.background || '#ffffff';
        
        // Recreate elements
        slideData.elements.forEach(elementData => {
            const element = document.createElement('div');
            element.className = 'canvas-element ' + (elementData.type === 'text' ? 'text-element' : 'image-element');
            
            if (elementData.type === 'text') {
                element.contentEditable = true;
                element.textContent = elementData.content;
                element.style.fontSize = elementData.fontSize;
                element.style.color = elementData.color;
                element.style.fontWeight = elementData.fontWeight;
            } else {
                element.style.backgroundImage = `url(${elementData.content})`;
                element.dataset.imageSrc = elementData.content;
            }
            
            element.style.left = elementData.left;
            element.style.top = elementData.top;
            element.style.width = elementData.width;
            element.style.height = elementData.height;
            
            if (elementData.articleId) {
                element.dataset.articleId = elementData.articleId;
            }
            
            element.innerHTML += `
                <div class="resize-handle nw"></div>
                <div class="resize-handle ne"></div>
                <div class="resize-handle sw"></div>
                <div class="resize-handle se"></div>
            `;
            
            slide.appendChild(element);
            makeElementInteractive(element);
        });
        
        canvasArea.appendChild(slide);
    });
    
    updateSlideIndicator();
    generateSlideThumbnails();
}

function deleteProject(projectId) {
    if (!confirm('Are you sure you want to delete this project?')) return;
    
    fetch('/management/ajax/marketing.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            action: 'delete_project',
            project_id: projectId
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('Project deleted', 'success');
            loadSavedProjects();
        }
    })
    .catch(error => console.error('Error deleting project:', error));
}

// Export functionality
function openExportModal() {
    document.getElementById('exportModal').classList.add('active');
    updateExportPreview();
}

function updateExportPreview() {
    const instagram = document.getElementById('exportInstagram').checked;
    const facebook = document.getElementById('exportFacebook').checked;
    const twitter = document.getElementById('exportTwitter').checked;
    
    MarketingApp.exportData.selectedPlatforms = [];
    if (instagram) MarketingApp.exportData.selectedPlatforms.push('instagram');
    if (facebook) MarketingApp.exportData.selectedPlatforms.push('facebook');
    if (twitter) MarketingApp.exportData.selectedPlatforms.push('twitter');
    
    const container = document.getElementById('exportPreviewContainer');
    
    if (MarketingApp.exportData.selectedPlatforms.length === 0) {
        container.innerHTML = `
            <p style="text-align: center; color: #9ca3af; padding: 20px;">
                Select at least one platform to see preview
            </p>
        `;
        return;
    }
    
    // Generate preview content
    let previewHTML = '';
    
    MarketingApp.exportData.selectedPlatforms.forEach(platform => {
        const postText = generatePostText(platform);
        const metadata = generateMetadata(platform);
        
        previewHTML += `
            <div class="preview-platform">
                <h4>
                    <i class="fab fa-${platform}"></i>
                    ${platform.charAt(0).toUpperCase() + platform.slice(1)}
                </h4>
                <div class="preview-text">${postText}</div>
                <div class="preview-meta">
                    ${Object.entries(metadata).map(([key, value]) => `
                        <div class="meta-item">
                            <div class="meta-label">${key}</div>
                            <div class="meta-value">${value}</div>
                        </div>
                    `).join('')}
                </div>
            </div>
        `;
    });
    
    container.innerHTML = previewHTML;
}

function generatePostText(platform) {
    const articles = MarketingApp.exportData.articleLinks;
    
    if (articles.length === 0) {
        return 'Check out our latest updates!';
    }
    
    let text = '';
    
    if (platform === 'instagram') {
        // Instagram format
        text = articles[0].description + '\n\n';
        text += articles.map((article, index) => {
            return `ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â°ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¦ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¸"ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â Article ${index + 1}: ${article.title}`;
        }).join('\n');
        text += '\n\n#News #Updates';
    } else if (platform === 'facebook') {
        // Facebook format
        text = articles[0].description + '\n\n';
        text += articles.map((article, index) => {
            return `${index + 1}. ${article.title}\n   ${article.url}`;
        }).join('\n\n');
    } else if (platform === 'twitter') {
        // Twitter format (character limit consideration)
        text = articles[0].snippet + '\n\n';
        text += articles.slice(0, 2).map((article, index) => {
            return `ÃƒÆ’Ã†â€™Ãƒâ€ Ã¢â‚¬â„¢ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â°ÃƒÆ’Ã†â€™ÃƒÂ¢Ã¢â€šÂ¬Ã‚Â¦ÃƒÆ’Ã¢â‚¬Å¡Ãƒâ€šÃ‚Â¸"ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â ${article.title}: ${article.url}`;
        }).join('\n');
    }
    
    return text;
}

function generateMetadata(platform) {
    const metadata = {};
    
    if (platform === 'instagram') {
        metadata['Post Type'] = MarketingApp.totalSlides > 1 ? 'Carousel' : 'Single Image';
        metadata['Slides'] = MarketingApp.totalSlides;
        metadata['Aspect Ratio'] = '1:1 (Square)';
        metadata['Caption Length'] = generatePostText(platform).length + ' characters';
    } else if (platform === 'facebook') {
        metadata['Post Type'] = MarketingApp.totalSlides > 1 ? 'Carousel' : 'Single Image';
        metadata['Images'] = MarketingApp.totalSlides;
        metadata['Text Length'] = generatePostText(platform).length + ' characters';
        metadata['Link Preview'] = 'Enabled';
    } else if (platform === 'twitter') {
        metadata['Post Type'] = MarketingApp.totalSlides > 1 ? 'Thread' : 'Single Tweet';
        metadata['Images'] = Math.min(MarketingApp.totalSlides, 4);
        metadata['Character Count'] = generatePostText(platform).length + '/280';
        metadata['Media'] = 'Attached';
    }
    
    return metadata;
}

function downloadPackage() {
    showNotification('Preparing download package...', 'info');
    
    // Capture canvas as images
    captureCanvasSlides().then(images => {
        // Create a data package
        const packageData = {
            images: images,
            posts: {},
            metadata: {}
        };
        
        MarketingApp.exportData.selectedPlatforms.forEach(platform => {
            packageData.posts[platform] = generatePostText(platform);
            packageData.metadata[platform] = generateMetadata(platform);
        });
        
        // Convert to JSON
        const dataStr = JSON.stringify(packageData, null, 2);
        const dataBlob = new Blob([dataStr], { type: 'application/json' });
        
        // Download
        const url = URL.createObjectURL(dataBlob);
        const a = document.createElement('a');
        a.href = url;
        a.download = 'social-media-export-' + Date.now() + '.json';
        a.click();
        
        showNotification('Package downloaded successfully', 'success');
    });
}

function captureCanvasSlides() {
    return new Promise((resolve) => {
        const images = [];
        const slides = document.querySelectorAll('.carousel-slide');
        
        // For demonstration, we'll just note the slide count
        // In production, you'd use html2canvas or similar library
        slides.forEach((slide, index) => {
            images.push({
                slideNumber: index + 1,
                placeholder: 'base64-image-data-would-go-here'
            });
        });
        
        resolve(images);
    });
}

function exportToSocialMedia() {
    const mode = document.querySelector('input[name="publishMode"]:checked').value;
    
    showNotification('Preparing export...', 'info');
    
    captureCanvasSlides().then(images => {
        const exportData = {
            platforms: MarketingApp.exportData.selectedPlatforms,
            images: images,
            posts: {},
            metadata: {},
            mode: mode
        };
        
        MarketingApp.exportData.selectedPlatforms.forEach(platform => {
            exportData.posts[platform] = generatePostText(platform);
            exportData.metadata[platform] = generateMetadata(platform);
        });
        
        // Send to backend for API upload
        fetch('/management/ajax/marketing.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({
                action: 'export_to_platforms',
                data: exportData
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                showNotification('Successfully exported to social media!', 'success');
                closeModal('exportModal');
                
                // Refresh statistics
                setTimeout(() => {
                    loadStatistics();
                }, 1000);
            } else {
                showNotification(data.message || 'Export failed', 'error');
            }
        })
        .catch(error => {
            console.error('Export error:', error);
            showNotification('Error during export', 'error');
        });
    });
}

// Statistics
function loadStatistics() {
    fetch('/management/ajax/marketing.php?action=get_statistics')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                updateStatisticsDisplay(data.stats);
            }
        })
        .catch(error => console.error('Error loading statistics:', error));
}

function refreshStatistics() {
    loadStatistics();
}

function updateStatisticsDisplay(stats) {
    // Update overview cards
    document.getElementById('instagramPosts').textContent = stats.instagram?.total || 0;
    document.getElementById('instagramChange').textContent = `+${stats.instagram?.change || 0}%`;
    
    document.getElementById('facebookPosts').textContent = stats.facebook?.total || 0;
    document.getElementById('facebookChange').textContent = `+${stats.facebook?.change || 0}%`;
    
    document.getElementById('twitterPosts').textContent = stats.twitter?.total || 0;
    document.getElementById('twitterChange').textContent = `+${stats.twitter?.change || 0}%`;
    
    document.getElementById('totalEngagement').textContent = stats.totalEngagement || 0;
    document.getElementById('engagementChange').textContent = `+${stats.engagementChange || 0}%`;
    
    // Update posts table
    updatePostsTable(stats.recentPosts || []);
}

function updatePostsTable(posts) {
    const tbody = document.getElementById('postsTableBody');
    
    if (posts.length === 0) {
        tbody.innerHTML = `
            <tr>
                <td colspan="6" style="text-align: center; padding: 40px; color: #9ca3af;">
                    <i class="fas fa-chart-line" style="font-size: 48px; margin-bottom: 16px; opacity: 0.5;"></i>
                    <p>No published posts yet</p>
                </td>
            </tr>
        `;
        return;
    }
    
    tbody.innerHTML = posts.map(post => `
        <tr>
            <td>
                <span class="platform-badge ${post.platform}">
                    <i class="fab fa-${post.platform}"></i> ${post.platform}
                </span>
            </td>
            <td>${post.title}</td>
            <td>${new Date(post.date).toLocaleDateString()}</td>
            <td>${post.engagement || 0}</td>
            <td>${post.reach || 0}</td>
            <td>
                <a href="${post.url}" target="_blank" class="post-link">
                    <i class="fas fa-external-link-alt"></i> View
                </a>
            </td>
        </tr>
    `).join('');
}

// Modal handling
function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('active');
}

// Notifications
function showNotification(message, type = 'info') {
    const icons = {
        success: 'fa-check-circle',
        error: 'fa-exclamation-circle',
        warning: 'fa-exclamation-triangle',
        info: 'fa-info-circle'
    };
    
    const colors = {
        success: '#10b981',
        error: '#ef4444',
        warning: '#f59e0b',
        info: '#3b82f6'
    };
    
    // Use SweetAlert2 if available
    if (typeof Swal !== 'undefined') {
        Swal.fire({
            icon: type,
            title: message,
            toast: true,
            position: 'top-end',
            showConfirmButton: false,
            timer: 3000,
            timerProgressBar: true
        });
    } else {
        // Fallback to console
        console.log(`[${type.toUpperCase()}] ${message}`);
        alert(message);
    }
}

function rgbToHex(rgb) {
    if (!rgb || rgb.indexOf('rgb') !== 0) return rgb;
    const values = rgb.match(/\d+/g);
    if (!values || values.length < 3) return rgb;
    return '#' + values.slice(0, 3).map(x => {
        const hex = parseInt(x).toString(16);
        return hex.length === 1 ? '0' + hex : hex;
    }).join('');
}

// ====================================
// NEW FEATURE: BORDER CONTROLS
// ====================================

function toggleBorderControls() {
    const controls = document.getElementById('borderControls');
    if (controls) {
        controls.style.display = controls.style.display === 'none' ? 'block' : 'none';
    }
}

function applyBorder() {
    if (!MarketingApp.selectedElement) return;
    
    const width = document.getElementById('borderWidth')?.value || 0;
    const style = document.getElementById('borderStyle')?.value || 'solid';
    const color = document.getElementById('borderColor')?.value || '#000000';
    const radius = document.getElementById('borderRadius')?.value || 0;
    
    const element = MarketingApp.selectedElement;
    element.style.border = width > 0 ? `${width}px ${style} ${color}` : 'none';
    element.style.borderRadius = `${radius}px`;
    
    document.getElementById('borderWidthValue').textContent = width;
    document.getElementById('borderRadiusValue').textContent = radius;
    
    saveElementToSlideState(element);
}

// ====================================
// NEW FEATURE: ADD ALL ARTICLES TO SLIDES
// ====================================

function addAllArticlesToSlides() {
    const articles = MarketingApp.articles;
    
    if (articles.length === 0) {
        showNotification('No articles available', 'warning');
        return;
    }
    
    const totalSlides = MarketingApp.totalSlides;
    
    if (totalSlides < 2) {
        showNotification('Need at least 2 slides to add articles', 'warning');
        return;
    }
    
    let articlesAdded = 0;
    let articleIndex = 0;
    
    // Start from slide 2, go through all slides
    for (let slideNum = 2; slideNum <= totalSlides && articleIndex < articles.length; slideNum++) {
        const article = articles[articleIndex];
        
        // Check if this article already exists on this slide
        const slideElement = document.querySelector(`#slide-${slideNum}`);
        const existingArticles = slideElement.querySelectorAll('.article-element');
        let articleExists = false;
        
        for (let existing of existingArticles) {
            if (existing.dataset.articleId == article.id) {
                articleExists = true;
                break;
            }
        }
        
        // If article already exists, skip to next article but stay on same slide
        if (articleExists) {
            articleIndex++;
            slideNum--; // Decrement so we retry this slide with next article
            continue;
        }
        
        // Add article to center of slide
        const centerX = (slideElement.offsetWidth / 2) - 200;
        const centerY = (slideElement.offsetHeight / 2) - 50;
        
        addArticleToSlide(article, slideNum, centerX, centerY);
        articlesAdded++;
        articleIndex++;
    }
    
    showNotification(`Added ${articlesAdded} article${articlesAdded !== 1 ? 's' : ''} starting from slide 2`, 'success');
}

// Helper function to add article to a specific slide
function addArticleToSlide(article, slideNum, x, y) {
    const slideElement = document.querySelector(`.carousel-slide[data-slide="${slideNum}"]`);
    if (!slideElement) return;
    
    const element = document.createElement('div');
    element.className = 'canvas-element article-element';
    element.style.position = 'absolute';
    element.style.left = x + 'px';
    element.style.top = y + 'px';
    element.style.width = '400px';
    element.style.backgroundColor = '#ffffff';
    element.style.border = '2px solid #e5e7eb';
    element.style.borderRadius = '8px';
    element.style.padding = '16px';
    element.style.cursor = 'move';
    element.style.zIndex = getNextZIndex();
    element.dataset.articleId = article.id;
    
    element.innerHTML = `
        <div style="font-weight: 600; font-size: 16px; margin-bottom: 8px; color: #111827;">${article.title}</div>
        <div style="font-size: 14px; color: #6b7280; line-height: 1.5;">${article.snippet}</div>
        <div style="margin-top: 12px; padding-top: 12px; border-top: 1px solid #e5e7eb; display: flex; gap: 12px; font-size: 12px; color: #9ca3af;">
            <span><i class="fas fa-tag"></i> ${article.category}</span>
            <span><i class="fas fa-calendar"></i> ${article.date}</span>
        </div>
        <div class="resize-handle nw"></div>
        <div class="resize-handle ne"></div>
        <div class="resize-handle sw"></div>
        <div class="resize-handle se"></div>
        <button class="element-delete-btn" onclick="deleteElement(this); event.stopPropagation();">
            <i class="fas fa-times"></i>
        </button>
    `;
    
    slideElement.appendChild(element);
    makeElementInteractive(element);
    
    // Save to slide state
    if (!MarketingApp.slides[slideNum]) {
        MarketingApp.slides[slideNum] = { elements: [], background: '#ffffff', backgroundImage: null };
    }
    
    MarketingApp.slides[slideNum].elements.push({
        type: 'article',
        articleId: article.id,
        position: { x: x, y: y },
        size: { width: 400, height: 'auto' },
        data: article
    });
}

// ====================================
// NEW FEATURE: DELETE SLIDE
// ====================================

function deleteCurrentSlide() {
    if (MarketingApp.totalSlides <= 1) {
        showNotification('Cannot delete the last slide', 'warning');
        return;
    }
    
    if (!confirm('Delete this slide and all its content?')) return;
    
    const currentNum = MarketingApp.currentSlide;
    const slideSelector = `.carousel-slide[data-slide="${currentNum}"]`;
    const slide = document.querySelector(slideSelector);
    
    if (slide) {
        slide.remove();
        delete MarketingApp.slides[currentNum];
        
        // Renumber all remaining slides
        const allSlides = document.querySelectorAll('.carousel-slide');
        const newSlides = {};
        
        allSlides.forEach((s, index) => {
            const newNum = index + 1;
            s.dataset.slide = newNum;
            
            // Get old slide number
            const oldNum = parseInt(s.dataset.slide);
            if (MarketingApp.slides[oldNum]) {
                newSlides[newNum] = MarketingApp.slides[oldNum];
            } else {
                // Find the slide data by looking through all keys
                for (let key in MarketingApp.slides) {
                    if (s === document.querySelector(`[data-slide="${key}"]`)) {
                        newSlides[newNum] = MarketingApp.slides[key];
                        break;
                    }
                }
            }
        });
        
        MarketingApp.slides = newSlides;
        MarketingApp.totalSlides = allSlides.length;
        
        // Switch to previous or first slide
        const newSlide = currentNum > 1 ? currentNum - 1 : 1;
        switchToSlide(Math.min(newSlide, MarketingApp.totalSlides));
        
        updateSlideIndicator();
        generateSlideThumbnails();
        
        showNotification('Slide deleted successfully', 'success');
    }
}

// ====================================
// NEW FEATURE: STICKERS & SHAPES
// ====================================

function openStickersModal() {
    document.getElementById('stickersModal').classList.add('active');
    loadStickers();
}

function loadStickers() {
    // Add built-in shapes
    const shapes = [
        { name: 'Circle', type: 'circle', width: 100, height: 100 },
        { name: 'Square', type: 'square', width: 100, height: 100 },
        { name: 'Rectangle', type: 'rectangle', width: 150, height: 100 },
        { name: 'Triangle', type: 'triangle', width: 100, height: 100 },
        { name: 'Star', type: 'star', width: 100, height: 100 },
        { name: 'Heart', type: 'heart', width: 100, height: 100 },
        { name: 'Arrow Right', type: 'arrow-right', width: 120, height: 60 },
        { name: 'Arrow Left', type: 'arrow-left', width: 120, height: 60 },
        { name: 'Arrow Up', type: 'arrow-up', width: 60, height: 120 },
        { name: 'Arrow Down', type: 'arrow-down', width: 60, height: 120 },
        { name: 'Pentagon', type: 'pentagon', width: 100, height: 100 },
        { name: 'Hexagon', type: 'hexagon', width: 100, height: 100 }
    ];
    
    // Fetch custom stickers
    fetch('/management/ajax/marketing.php?action=get_stickers')
        .then(response => response.json())
        .then(data => {
            displayStickers(shapes, data.success ? data.stickers : []);
        })
        .catch(error => {
            console.error('Error loading stickers:', error);
            displayStickers(shapes, []);
        });
}

function displayStickers(shapes, customStickers) {
    const grid = document.getElementById('stickersGrid');
    
    let html = '<h3 style="grid-column: 1/-1; margin: 0 0 12px 0; font-size: 14px; color: #6b7280;">Shapes</h3>';
    
    html += shapes.map(shape => `
        <div class="sticker-item" onclick="addShapeToCanvas('${shape.type}', ${shape.width}, ${shape.height})">
            <div style="width: 80px; height: 80px; display: flex; align-items: center; justify-content: center;">
                ${getShapePreview(shape.type)}
            </div>
            <div class="sticker-name">${shape.name}</div>
        </div>
    `).join('');
    
    if (customStickers.length > 0) {
        html += '<h3 style="grid-column: 1/-1; margin: 20px 0 12px 0; font-size: 14px; color: #6b7280;">Custom Stickers</h3>';
        html += customStickers.map(sticker => `
            <div class="sticker-item" onclick="addStickerToCanvas('${sticker.file_url}', ${sticker.width || 100}, ${sticker.height || 100})">
                <img src="${sticker.file_url}" alt="${sticker.name}" style="max-width: 100%; max-height: 100px;">
                <div class="sticker-name">${sticker.name}</div>
            </div>
        `).join('');
    }
    
    grid.innerHTML = html;
}

function getShapePreview(type) {
    const color = '#667eea';
    switch(type) {
        case 'circle':
            return `<div style="width: 60px; height: 60px; border-radius: 50%; background: ${color};"></div>`;
        case 'square':
            return `<div style="width: 60px; height: 60px; background: ${color};"></div>`;
        case 'rectangle':
            return `<div style="width: 70px; height: 45px; background: ${color};"></div>`;
        case 'triangle':
            return `<div style="width: 0; height: 0; border-left: 35px solid transparent; border-right: 35px solid transparent; border-bottom: 60px solid ${color};"></div>`;
        case 'star':
            return `<i class="fas fa-star" style="font-size: 50px; color: ${color};"></i>`;
        case 'heart':
            return `<i class="fas fa-heart" style="font-size: 50px; color: ${color};"></i>`;
        case 'arrow-right':
            return `<i class="fas fa-arrow-right" style="font-size: 40px; color: ${color};"></i>`;
        case 'arrow-left':
            return `<i class="fas fa-arrow-left" style="font-size: 40px; color: ${color};"></i>`;
        case 'arrow-up':
            return `<i class="fas fa-arrow-up" style="font-size: 40px; color: ${color};"></i>`;
        case 'arrow-down':
            return `<i class="fas fa-arrow-down" style="font-size: 40px; color: ${color};"></i>`;
        case 'pentagon':
            return `<i class="fas fa-stop" style="font-size: 50px; color: ${color}; transform: rotate(45deg);"></i>`;
        case 'hexagon':
            return `<i class="fas fa-stop" style="font-size: 50px; color: ${color};"></i>`;
        default:
            return `<div style="width: 60px; height: 60px; background: ${color};"></div>`;
    }
}

function addShapeToCanvas(shapeType, width, height) {
    const currentSlide = document.querySelector('.carousel-slide.active');
    
    const shapeElement = document.createElement('div');
    shapeElement.className = 'canvas-element shape-element';
    shapeElement.style.position = 'absolute';
    shapeElement.style.left = '100px';
    shapeElement.style.top = '100px';
    shapeElement.style.width = width + 'px';
    shapeElement.style.height = height + 'px';
    shapeElement.style.cursor = 'move';
    shapeElement.style.zIndex = getNextZIndex();
    shapeElement.dataset.shapeType = shapeType;
    shapeElement.dataset.fillColor = '#667eea';
    shapeElement.dataset.strokeColor = '#667eea';
    shapeElement.dataset.strokeWidth = '0';
    
    // Create SVG for shape
    const svg = createShapeSVG(shapeType, width, height, '#667eea', '#667eea', 0);
    shapeElement.innerHTML = svg;
    
    // Add drag handle at top
    const dragHandle = document.createElement('div');
    dragHandle.className = 'drag-handle';
    dragHandle.style.cssText = `
        position: absolute;
        top: -10px;
        left: 50%;
        transform: translateX(-50%);
        width: 40px;
        height: 20px;
        background: #667eea;
        border-radius: 10px 10px 0 0;
        cursor: move;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 10px;
        z-index: 10;
    `;
    dragHandle.innerHTML = '<i class="fas fa-grip-lines"></i>';
    
    // Add controls
    const controls = document.createElement('div');
    controls.innerHTML = `
        <div class="resize-handle nw"></div>
        <div class="resize-handle ne"></div>
        <div class="resize-handle sw"></div>
        <div class="resize-handle se"></div>
        <button class="element-delete-btn" onclick="deleteElement(this); event.stopPropagation();">
            <i class="fas fa-times"></i>
        </button>
    `;
    
    shapeElement.appendChild(dragHandle);
    shapeElement.appendChild(controls);
    
    currentSlide.appendChild(shapeElement);
    makeElementInteractive(shapeElement);
    makeElementDraggableTouch(shapeElement);
    selectElement(shapeElement);
    saveElementToSlideState(shapeElement);
    
    // Show HTML toolbar for color selection
    setTimeout(() => {
        showHtmlEditor(shapeElement);
        showShapeControls();
    }, 100);
    
    closeModal('stickersModal');
    showNotification('Shape added to canvas', 'success');
}

function showShapeControls() {
    // Add shape-specific controls to toolbar if not already there
    const toolbar = document.getElementById('htmlEditorToolbar');
    if (!toolbar) return;
    
    let shapeControls = document.getElementById('shapeControls');
    if (!shapeControls) {
        shapeControls = document.createElement('div');
        shapeControls.id = 'shapeControls';
        shapeControls.style.cssText = 'display:flex;gap:6px;padding:0 8px;border-right:1px solid #e5e7eb;';
        shapeControls.innerHTML = `
            <button class="editor-btn" onclick="toggleShapeFill()" title="Toggle Fill/Outline">
                <i class="fas fa-fill-drip"></i>
            </button>
            <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:#6b7280;">
                Border:
                <select onchange="changeShapeStrokeWidth(this.value)" style="height:36px;border:1px solid #e5e7eb;border-radius:6px;padding:0 8px;">
                    <option value="0">None</option>
                    <option value="1">1px</option>
                    <option value="2">2px</option>
                    <option value="3" selected>3px</option>
                    <option value="4">4px</option>
                    <option value="5">5px</option>
                    <option value="8">8px</option>
                    <option value="10">10px</option>
                </select>
            </label>
        `;
        
        // Insert after color pickers
        const colorGroup = toolbar.querySelector('div:has(#htmlTextColorPicker)');
        if (colorGroup && colorGroup.nextSibling) {
            toolbar.insertBefore(shapeControls, colorGroup.nextSibling);
        }
    }
    shapeControls.style.display = 'flex';
}

function hideShapeControls() {
    const shapeControls = document.getElementById('shapeControls');
    if (shapeControls) {
        shapeControls.style.display = 'none';
    }
}

function createShapeSVG(type, width, height, fillColor, strokeColor, strokeWidth) {
    const fill = fillColor === 'transparent' ? 'none' : fillColor;
    const stroke = strokeWidth > 0 ? strokeColor : 'none';
    
    switch(type) {
        case 'circle':
            return `<svg width="${width}" height="${height}" viewBox="0 0 ${width} ${height}">
                <circle cx="${width/2}" cy="${height/2}" r="${Math.min(width, height)/2 - strokeWidth/2}" 
                    fill="${fill}" stroke="${stroke}" stroke-width="${strokeWidth}"/>
            </svg>`;
        case 'square':
        case 'rectangle':
            return `<svg width="${width}" height="${height}" viewBox="0 0 ${width} ${height}">
                <rect x="${strokeWidth/2}" y="${strokeWidth/2}" width="${width-strokeWidth}" height="${height-strokeWidth}" 
                    fill="${fill}" stroke="${stroke}" stroke-width="${strokeWidth}"/>
            </svg>`;
        case 'triangle':
            return `<svg width="${width}" height="${height}" viewBox="0 0 ${width} ${height}">
                <polygon points="${width/2},${strokeWidth/2} ${width-strokeWidth/2},${height-strokeWidth/2} ${strokeWidth/2},${height-strokeWidth/2}" 
                    fill="${fill}" stroke="${stroke}" stroke-width="${strokeWidth}"/>
            </svg>`;
        case 'star':
            return `<svg width="${width}" height="${height}" viewBox="0 0 24 24">
                <path d="M12 2l3.09 6.26L22 9.27l-5 4.87 1.18 6.88L12 17.77l-6.18 3.25L7 14.14 2 9.27l6.91-1.01L12 2z" 
                    fill="${fill}" stroke="${stroke}" stroke-width="${strokeWidth/10}"/>
            </svg>`;
        case 'heart':
            return `<svg width="${width}" height="${height}" viewBox="0 0 24 24">
                <path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z" 
                    fill="${fill}" stroke="${stroke}" stroke-width="${strokeWidth/10}"/>
            </svg>`;
        case 'arrow-right':
            return `<svg width="${width}" height="${height}" viewBox="0 0 24 24">
                <path d="M5 12h14M12 5l7 7-7 7" fill="none" stroke="${strokeColor}" stroke-width="2" stroke-linecap="round"/>
            </svg>`;
        case 'arrow-left':
            return `<svg width="${width}" height="${height}" viewBox="0 0 24 24">
                <path d="M19 12H5M12 19l-7-7 7-7" fill="none" stroke="${strokeColor}" stroke-width="2" stroke-linecap="round"/>
            </svg>`;
        case 'arrow-up':
            return `<svg width="${width}" height="${height}" viewBox="0 0 24 24">
                <path d="M12 19V5M5 12l7-7 7 7" fill="none" stroke="${strokeColor}" stroke-width="2" stroke-linecap="round"/>
            </svg>`;
        case 'arrow-down':
            return `<svg width="${width}" height="${height}" viewBox="0 0 24 24">
                <path d="M12 5v14M19 12l-7 7-7-7" fill="none" stroke="${strokeColor}" stroke-width="2" stroke-linecap="round"/>
            </svg>`;
        case 'pentagon':
            const points = [];
            for (let i = 0; i < 5; i++) {
                const angle = (Math.PI * 2 * i) / 5 - Math.PI / 2;
                const x = width/2 + (width/2 - strokeWidth) * Math.cos(angle);
                const y = height/2 + (height/2 - strokeWidth) * Math.sin(angle);
                points.push(`${x},${y}`);
            }
            return `<svg width="${width}" height="${height}" viewBox="0 0 ${width} ${height}">
                <polygon points="${points.join(' ')}" fill="${fill}" stroke="${stroke}" stroke-width="${strokeWidth}"/>
            </svg>`;
        case 'hexagon':
            const hexPoints = [];
            for (let i = 0; i < 6; i++) {
                const angle = (Math.PI * 2 * i) / 6;
                const x = width/2 + (width/2 - strokeWidth) * Math.cos(angle);
                const y = height/2 + (height/2 - strokeWidth) * Math.sin(angle);
                hexPoints.push(`${x},${y}`);
            }
            return `<svg width="${width}" height="${height}" viewBox="0 0 ${width} ${height}">
                <polygon points="${hexPoints.join(' ')}" fill="${fill}" stroke="${stroke}" stroke-width="${strokeWidth}"/>
            </svg>`;
        default:
            return `<svg width="${width}" height="${height}"><rect width="${width}" height="${height}" fill="${fill}" stroke="${stroke}" stroke-width="${strokeWidth}"/></svg>`;
    }
}

function addStickerToCanvas(url, width, height) {
    const currentSlide = document.querySelector('.carousel-slide.active');
    
    const stickerElement = document.createElement('div');
    stickerElement.className = 'canvas-element sticker-element';
    stickerElement.style.position = 'absolute';
    stickerElement.style.left = '100px';
    stickerElement.style.top = '100px';
    stickerElement.style.width = width + 'px';
    stickerElement.style.height = height + 'px';
    stickerElement.style.backgroundImage = `url(${url})`;
    stickerElement.style.backgroundSize = 'contain';
    stickerElement.style.backgroundRepeat = 'no-repeat';
    stickerElement.style.backgroundPosition = 'center';
    stickerElement.style.cursor = 'move';
    stickerElement.style.zIndex = getNextZIndex();
    stickerElement.dataset.stickerUrl = url;
    
    // Add drag handle at top
    const dragHandle = document.createElement('div');
    dragHandle.className = 'drag-handle';
    dragHandle.style.cssText = `
        position: absolute;
        top: -10px;
        left: 50%;
        transform: translateX(-50%);
        width: 40px;
        height: 20px;
        background: #667eea;
        border-radius: 10px 10px 0 0;
        cursor: move;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 10px;
        z-index: 10;
    `;
    dragHandle.innerHTML = '<i class="fas fa-grip-lines"></i>';
    
    // Add controls
    const controls = document.createElement('div');
    controls.innerHTML = `
        <div class="resize-handle nw"></div>
        <div class="resize-handle ne"></div>
        <div class="resize-handle sw"></div>
        <div class="resize-handle se"></div>
        <button class="element-delete-btn" onclick="deleteElement(this); event.stopPropagation();">
            <i class="fas fa-times"></i>
        </button>
    `;
    
    stickerElement.appendChild(dragHandle);
    stickerElement.appendChild(controls);
    
    currentSlide.appendChild(stickerElement);
    makeElementInteractive(stickerElement);
    makeElementDraggableTouch(stickerElement);
    selectElement(stickerElement);
    saveElementToSlideState(stickerElement);
    
    closeModal('stickersModal');
    showNotification('Sticker added to canvas', 'success');
}

// ====================================
// NEW FEATURE: PORTAL SELECTION FOR EXPORT
// ====================================

function loadPortalsForExport() {
    fetch('/management/ajax/marketing.php?action=get_portals')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayPortalSelection(data.portals);
            }
        })
        .catch(error => console.error('Error loading portals:', error));
}

function displayPortalSelection(portals) {
    const container = document.getElementById('portalSelection');
    if (!container) return;
    
    // Group by portal
    const grouped = {};
    portals.forEach(portal => {
        if (!grouped[portal.portal_url]) {
            grouped[portal.portal_url] = {
                name: portal.portal_name,
                url: portal.portal_url,
                platforms: []
            };
        }
        if (portal.is_active) {
            grouped[portal.portal_url].platforms.push(portal.platform);
        }
    });
    
    container.innerHTML = Object.values(grouped).map(portal => `
        <div class="portal-item">
            <label class="checkbox-item">
                <input type="checkbox" class="portal-checkbox" value="${portal.url}" 
                       onchange="updatePortalSelection()">
                <span>${portal.name}</span>
            </label>
            <small style="color: #6b7280; margin-left: 26px; display: block;">
                ${portal.platforms.length > 0 ? portal.platforms.join(', ') : 'No accounts configured'}
            </small>
        </div>
    `).join('');
}

function updatePortalSelection() {
    const checkboxes = document.querySelectorAll('.portal-checkbox:checked');
    MarketingApp.exportData.selectedPortals = Array.from(checkboxes).map(cb => cb.value);
    updateExportPreview();
}

// Console log when loaded
console.log('Marketing Module v1.1 patches loaded successfully');
// ============================================
// HTML EDITOR TOOLBAR IMPLEMENTATION
// ============================================

function initializeHtmlEditorToolbar() {
    const toolbar = document.createElement('div');
    toolbar.id = 'htmlEditorToolbar';
    toolbar.style.cssText = 'display:none;position:sticky;top:0;z-index:1000;background:#fff;border-bottom:2px solid #e5e7eb;padding:12px 16px;flex-wrap:wrap;gap:12px;align-items:center;box-shadow:0 2px 8px rgba(0,0,0,0.08);';
    
    toolbar.innerHTML = `
        <div style="display:flex;gap:6px;padding:0 8px;border-right:1px solid #e5e7eb;">
            <button class="editor-btn" onclick="applyHtmlFormat('bold')" title="Bold"><i class="fas fa-bold"></i></button>
            <button class="editor-btn" onclick="applyHtmlFormat('italic')" title="Italic"><i class="fas fa-italic"></i></button>
            <button class="editor-btn" onclick="applyHtmlFormat('underline')" title="Underline"><i class="fas fa-underline"></i></button>
            <button class="editor-btn" onclick="applyHtmlFormat('strikethrough')" title="Strikethrough"><i class="fas fa-strikethrough"></i></button>
        </div>
        <div style="display:flex;gap:6px;padding:0 8px;border-right:1px solid #e5e7eb;">
            <select class="editor-select" onchange="applyFontFamily(this.value)" style="height:36px;border:1px solid #e5e7eb;border-radius:6px;padding:0 12px;min-width:120px;">
                <option value="Inter">Inter</option>
                <option value="Arial">Arial</option>
                <option value="Helvetica">Helvetica</option>
                <option value="Georgia">Georgia</option>
                <option value="Times New Roman">Times New Roman</option>
                <option value="Courier New">Courier New</option>
                <option value="Verdana">Verdana</option>
            </select>
            <select class="editor-select" onchange="applyFontSize(this.value)" style="height:36px;border:1px solid #e5e7eb;border-radius:6px;padding:0 12px;">
                <option value="">Size</option>
                <option value="12px">12px</option>
                <option value="14px">14px</option>
                <option value="16px">16px</option>
                <option value="18px">18px</option>
                <option value="20px">20px</option>
                <option value="24px">24px</option>
                <option value="28px">28px</option>
                <option value="32px">32px</option>
                <option value="36px">36px</option>
                <option value="48px">48px</option>
                <option value="64px">64px</option>
            </select>
        </div>
        <div style="display:flex;gap:6px;padding:0 8px;border-right:1px solid #e5e7eb;">
            <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:#6b7280;">
                Text: <input type="color" id="htmlTextColorPicker" onchange="applyTextColor(this.value)" value="#000000" style="width:36px;height:36px;border:1px solid #e5e7eb;border-radius:6px;cursor:pointer;">
            </label>
            <label style="display:flex;align-items:center;gap:8px;font-size:13px;color:#6b7280;">
                BG: <input type="color" id="htmlBgColorPicker" onchange="applyBackgroundColor(this.value)" value="#ffffff" style="width:36px;height:36px;border:1px solid #e5e7eb;border-radius:6px;cursor:pointer;">
            </label>
        </div>
        <div style="display:flex;gap:6px;padding:0 8px;border-right:1px solid #e5e7eb;">
            <button class="editor-btn" onclick="showBorderEditor()" title="Border" style="width:auto;padding:0 12px;"><i class="fas fa-border-style"></i> Border</button>
        </div>
        <div style="display:flex;gap:6px;padding:0 8px;">
            <button class="editor-btn" onclick="closeHtmlEditor()" title="Close"><i class="fas fa-times"></i></button>
        </div>
    `;
    
    const workspace = document.getElementById('canvasWorkspace');
    if (workspace) {
        workspace.insertBefore(toolbar, workspace.firstChild);
    }
    
    // Add border editor modal
    const borderModal = document.createElement('div');
    borderModal.id = 'borderEditorModal';
    borderModal.className = 'modal';
    borderModal.style.display = 'none';
    borderModal.innerHTML = `
        <div class="modal-content" style="max-width:500px;">
            <div class="modal-header">
                <h3>Border Settings</h3>
                <button class="modal-close" onclick="closeBorderEditor()">&times;</button>
            </div>
            <div class="modal-body">
                <div class="form-group">
                    <label class="form-label">Border Width</label>
                    <select id="borderWidth" class="form-select">
                        <option value="0px">None</option>
                        <option value="1px">1px</option>
                        <option value="2px">2px</option>
                        <option value="3px">3px</option>
                        <option value="4px">4px</option>
                        <option value="5px">5px</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Border Style</label>
                    <select id="borderStyle" class="form-select">
                        <option value="solid">Solid</option>
                        <option value="dashed">Dashed</option>
                        <option value="dotted">Dotted</option>
                        <option value="double">Double</option>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Border Color</label>
                    <input type="color" id="borderColor" class="form-control" value="#000000">
                </div>
                <div class="form-group">
                    <label class="form-label">Apply To</label>
                    <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
                        <button class="btn btn-secondary" onclick="applyBorder('all')">All Sides</button>
                        <button class="btn btn-secondary" onclick="applyBorder('top')">Top</button>
                        <button class="btn btn-secondary" onclick="applyBorder('right')">Right</button>
                        <button class="btn btn-secondary" onclick="applyBorder('bottom')">Bottom</button>
                        <button class="btn btn-secondary" onclick="applyBorder('left')">Left</button>
                    </div>
                </div>
            </div>
        </div>
    `;
    document.body.appendChild(borderModal);
    
    // Add styles
    const style = document.createElement('style');
    style.textContent = `
        .editor-btn {
            width: 36px;
            height: 36px;
            border: 1px solid #e5e7eb;
            background: #ffffff;
            border-radius: 6px;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: all 0.2s;
            color: #374151;
        }
        .editor-btn:hover {
            background: #f3f4f6;
            border-color: #d1d5db;
        }
        .drag-handle {
            position: absolute;
            top: 4px;
            left: 50%;
            transform: translateX(-50%);
            background: rgba(0, 0, 0, 0.6);
            color: white;
            padding: 4px 8px;
            border-radius: 4px;
            cursor: grab;
            font-size: 12px;
            z-index: 10;
            display: none;
        }
        .canvas-element:hover .drag-handle,
        .canvas-element.selected .drag-handle {
            display: block;
        }
        .drag-handle:active {
            cursor: grabbing;
        }
        .thumb-delete {
            position: absolute;
            top: -8px;
            right: -8px;
            width: 20px;
            height: 20px;
            background: #ef4444;
            color: white;
            border: 2px solid white;
            border-radius: 50%;
            cursor: pointer;
            display: none;
            align-items: center;
            justify-content: center;
            font-size: 10px;
            transition: all 0.2s;
        }
        .slide-thumbnail:hover .thumb-delete {
            display: flex;
        }
        .thumb-delete:hover {
            background: #dc2626;
            transform: scale(1.1);
        }
        .articles-pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 8px;
            padding: 12px;
            border-top: 1px solid #e5e7eb;
        }
        .pagination-btn {
            padding: 6px 12px;
            border: 1px solid #e5e7eb;
            background: white;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
        }
        .pagination-btn:hover:not(:disabled) {
            background: #f3f4f6;
        }
        .pagination-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
    `;
    document.head.appendChild(style);
}

function showHtmlEditor(element) {
    const toolbar = document.getElementById('htmlEditorToolbar');
    if (!toolbar) return;
    toolbar.style.display = 'flex';
    MarketingApp.htmlEditor.visible = true;
    MarketingApp.htmlEditor.targetElement = element;
    
    const computedStyle = window.getComputedStyle(element);
    const textPicker = document.getElementById('htmlTextColorPicker');
    const bgPicker = document.getElementById('htmlBgColorPicker');
    if (textPicker) textPicker.value = rgbToHex(computedStyle.color);
    if (bgPicker) bgPicker.value = rgbToHex(computedStyle.backgroundColor);
    
    // Check if element is a shape - show shape controls
    const shapeElement = element.closest ? element.closest('.shape-element') : (element.classList && element.classList.contains('shape-element') ? element : null);
    if (shapeElement) {
        showShapeControls();
    } else {
        hideShapeControls();
    }
}

function closeHtmlEditor() {
    const toolbar = document.getElementById('htmlEditorToolbar');
    if (!toolbar) return;
    toolbar.style.display = 'none';
    MarketingApp.htmlEditor.visible = false;
    MarketingApp.htmlEditor.targetElement = null;
}

function showHtmlEditorToolbar(element) {
    const toolbar = document.getElementById('htmlEditorToolbar');
    if (!toolbar) return;
    
    toolbar.style.display = 'flex';
    MarketingApp.htmlEditor.visible = true;
    MarketingApp.htmlEditor.targetElement = element;
    
    // Store current selection for color application
    const selection = window.getSelection();
    if (selection.rangeCount > 0) {
        MarketingApp.savedRange = selection.getRangeAt(0);
    }
}

function hideHtmlEditorToolbar() {
    const toolbar = document.getElementById('htmlEditorToolbar');
    if (!toolbar) return;
    toolbar.style.display = 'none';
    MarketingApp.htmlEditor.visible = false;
    MarketingApp.htmlEditor.targetElement = null;
}

function applyHtmlFormat(command) {
    if (!MarketingApp.htmlEditor.targetElement) return;
    document.execCommand(command, false, null);
    saveElementToSlideState(MarketingApp.htmlEditor.targetElement);
}

function applyFontFamily(font) {
    if (!MarketingApp.htmlEditor.targetElement || !font) return;
    const element = MarketingApp.htmlEditor.targetElement;
    const selection = window.getSelection();
    if (selection.rangeCount > 0 && !selection.isCollapsed) {
        document.execCommand('fontName', false, font);
    } else {
        element.style.fontFamily = font;
    }
    saveElementToSlideState(element);
}

function applyFontSize(size) {
    if (!MarketingApp.htmlEditor.targetElement || !size) return;
    const element = MarketingApp.htmlEditor.targetElement;
    const selection = window.getSelection();
    if (selection.rangeCount > 0 && !selection.isCollapsed) {
        const span = document.createElement('span');
        span.style.fontSize = size;
        try {
            const range = selection.getRangeAt(0);
            range.surroundContents(span);
        } catch (e) {
            document.execCommand('fontSize', false, '7');
            const fontElements = element.querySelectorAll('font');
            fontElements.forEach(el => {
                el.removeAttribute('size');
                el.style.fontSize = size;
            });
        }
    } else {
        element.style.fontSize = size;
    }
    saveElementToSlideState(element);
}

function applyTextColor(color) {
    if (!MarketingApp.htmlEditor.targetElement) return;
    const element = MarketingApp.htmlEditor.targetElement;
    
    // Check if it's a shape element (by checking parent)
    const shapeElement = element.closest('.shape-element');
    if (shapeElement) {
        shapeElement.dataset.fillColor = color;
        const shapeType = shapeElement.dataset.shapeType;
        const width = shapeElement.offsetWidth;
        const height = shapeElement.offsetHeight;
        const strokeColor = shapeElement.dataset.strokeColor || '#667eea';
        const strokeWidth = parseInt(shapeElement.dataset.strokeWidth) || 0;
        
        // Find and replace the SVG
        const svg = shapeElement.querySelector('svg');
        if (svg) {
            const newSVG = createShapeSVG(shapeType, width, height, color, strokeColor, strokeWidth);
            svg.outerHTML = newSVG;
        }
        saveElementToSlideState(shapeElement);
        return;
    }
    
    // Regular text color handling
    if (MarketingApp.savedRange) {
        const span = document.createElement('span');
        span.style.color = color;
        try {
            MarketingApp.savedRange.surroundContents(span);
            const selection = window.getSelection();
            selection.removeAllRanges();
            selection.addRange(MarketingApp.savedRange);
        } catch (e) {
            console.error('Error applying color:', e);
        }
        saveElementToSlideState(element);
    }
}

function applyBackgroundColor(color) {
    if (!MarketingApp.htmlEditor.targetElement) return;
    const element = MarketingApp.htmlEditor.targetElement;
    
    // Check if it's a shape element (by checking parent)
    const shapeElement = element.closest('.shape-element');
    if (shapeElement) {
        shapeElement.dataset.strokeColor = color;
        shapeElement.dataset.strokeWidth = '3'; // Set stroke width when applying stroke color
        const shapeType = shapeElement.dataset.shapeType;
        const width = shapeElement.offsetWidth;
        const height = shapeElement.offsetHeight;
        const fillColor = shapeElement.dataset.fillColor || '#667eea';
        
        // Find and replace the SVG
        const svg = shapeElement.querySelector('svg');
        if (svg) {
            const newSVG = createShapeSVG(shapeType, width, height, fillColor, color, 3);
            svg.outerHTML = newSVG;
        }
        saveElementToSlideState(shapeElement);
        return;
    }
    
    // Regular background color handling
    const selection = window.getSelection();
    if (selection.rangeCount > 0 && !selection.isCollapsed) {
        document.execCommand('backColor', false, color);
    } else {
        element.style.backgroundColor = color;
    }
    saveElementToSlideState(element);
}

// Shape-specific controls
function toggleShapeFill() {
    const element = MarketingApp.htmlEditor.targetElement;
    const shapeElement = element ? element.closest('.shape-element') : MarketingApp.selectedElement;
    if (!shapeElement || !shapeElement.classList.contains('shape-element')) return;
    
    const currentFill = shapeElement.dataset.fillColor || '#667eea';
    const newFill = currentFill === 'transparent' ? '#667eea' : 'transparent';
    
    shapeElement.dataset.fillColor = newFill;
    const shapeType = shapeElement.dataset.shapeType;
    const width = shapeElement.offsetWidth;
    const height = shapeElement.offsetHeight;
    const strokeColor = shapeElement.dataset.strokeColor || '#667eea';
    const strokeWidth = parseInt(shapeElement.dataset.strokeWidth) || (newFill === 'transparent' ? 3 : 0);
    
    // If making transparent, ensure there's a stroke
    if (newFill === 'transparent' && strokeWidth === 0) {
        shapeElement.dataset.strokeWidth = '3';
    }
    
    const svg = shapeElement.querySelector('svg');
    if (svg) {
        const newSVG = createShapeSVG(shapeType, width, height, newFill, strokeColor, strokeWidth || 3);
        svg.outerHTML = newSVG;
    }
    saveElementToSlideState(shapeElement);
    showNotification(newFill === 'transparent' ? 'Shape fill removed (outline only)' : 'Shape filled', 'success');
}

function changeShapeStrokeWidth(width) {
    const element = MarketingApp.htmlEditor.targetElement;
    const shapeElement = element ? element.closest('.shape-element') : MarketingApp.selectedElement;
    if (!shapeElement || !shapeElement.classList.contains('shape-element')) return;
    
    shapeElement.dataset.strokeWidth = width;
    const shapeType = shapeElement.dataset.shapeType;
    const elementWidth = shapeElement.offsetWidth;
    const elementHeight = shapeElement.offsetHeight;
    const fillColor = shapeElement.dataset.fillColor || '#667eea';
    const strokeColor = shapeElement.dataset.strokeColor || '#667eea';
    
    const svg = shapeElement.querySelector('svg');
    if (svg) {
        const newSVG = createShapeSVG(shapeType, elementWidth, elementHeight, fillColor, strokeColor, parseInt(width));
        svg.outerHTML = newSVG;
    }
    saveElementToSlideState(shapeElement);
}

function showBorderEditor() {
    const modal = document.getElementById('borderEditorModal');
    if (modal) {
        modal.style.display = 'flex';
        if (MarketingApp.htmlEditor.targetElement) {
            const style = window.getComputedStyle(MarketingApp.htmlEditor.targetElement);
            const widthEl = document.getElementById('borderWidth');
            const styleEl = document.getElementById('borderStyle');
            const colorEl = document.getElementById('borderColor');
            if (widthEl) widthEl.value = style.borderWidth || '0px';
            if (styleEl) styleEl.value = style.borderStyle || 'solid';
            if (colorEl) colorEl.value = rgbToHex(style.borderColor);
        }
    }
}

function closeBorderEditor() {
    const modal = document.getElementById('borderEditorModal');
    if (modal) modal.style.display = 'none';
}

function applyBorder(side) {
    if (!MarketingApp.htmlEditor.targetElement) return;
    const element = MarketingApp.htmlEditor.targetElement;
    const width = document.getElementById('borderWidth').value;
    const style = document.getElementById('borderStyle').value;
    const color = document.getElementById('borderColor').value;
    const borderValue = `${width} ${style} ${color}`;
    
    if (side === 'all') {
        element.style.border = borderValue;
    } else {
        element.style[`border${side.charAt(0).toUpperCase() + side.slice(1)}`] = borderValue;
    }
    
    saveElementToSlideState(element);
    closeBorderEditor();
}

function rgbToHex(rgb) {
    if (!rgb || rgb === 'transparent') return '#ffffff';
    if (rgb.startsWith('#')) return rgb;
    const match = rgb.match(/^rgb\((\d+),\s*(\d+),\s*(\d+)\)$/);
    if (!match) return '#000000';
    const r = parseInt(match[1]);
    const g = parseInt(match[2]);
    const b = parseInt(match[3]);
    return '#' + ((1 << 24) + (r << 16) + (g << 8) + b).toString(16).slice(1);
}

// Change article page
function changeArticlePage(direction) {
    MarketingApp.articlesPage += direction;
    renderArticles(MarketingApp.articles);
}

// Delete slide by number
function deleteSlideByNumber(slideNum) {
    if (MarketingApp.totalSlides <= 1) {
        showNotification('Cannot delete the last slide', 'warning');
        return;
    }
    if (!confirm(`Delete slide ${slideNum}?`)) return;
    
    const slideElement = document.querySelector(`#slide-${slideNum}`);
    if (slideElement) slideElement.remove();
    delete MarketingApp.slides[slideNum];
    
    const newSlides = {};
    let newSlideNum = 1;
    for (let i = 1; i <= MarketingApp.totalSlides; i++) {
        if (i !== slideNum && MarketingApp.slides[i]) {
            newSlides[newSlideNum] = MarketingApp.slides[i];
            const slide = document.querySelector(`#slide-${i}`);
            if (slide) {
                slide.id = 'slide-' + newSlideNum;
                slide.dataset.slide = newSlideNum;
            }
            newSlideNum++;
        }
    }
    
    MarketingApp.slides = newSlides;
    MarketingApp.totalSlides--;
    
    if (MarketingApp.currentSlide === slideNum) {
        switchToSlide(Math.min(slideNum, MarketingApp.totalSlides));
    } else if (MarketingApp.currentSlide > slideNum) {
        MarketingApp.currentSlide--;
        updateSlideIndicator();
    }
    
    generateSlideThumbnails();
    showNotification('Slide deleted', 'success');
}

// Preview modal functionality
function openPreviewModal() {
    const modal = document.getElementById('previewModal');
    const previewContainer = document.getElementById('previewContainer');
    
    // Show loading
    previewContainer.innerHTML = '<div style="text-align: center; padding: 40px;"><i class="fas fa-spinner fa-spin" style="font-size: 48px; color: #667eea;"></i><p style="margin-top: 16px; color: #6b7280;">Generating preview...</p></div>';
    modal.classList.add('active');
    
    // Get current format dimensions
    const formatSelector = document.getElementById('formatSelector');
    const format = formatSelector.value;
    let width, height;
    
    switch(format) {
        case '1080x1080':
            width = 1080;
            height = 1080;
            break;
        case '1080x1920':
            width = 1080;
            height = 1920;
            break;
        case '1200x630':
            width = 1200;
            height = 630;
            break;
        case '1500x500':
            width = 1500;
            height = 500;
            break;
        default:
            width = 1080;
            height = 1080;
    }
    
    // Generate images for all slides
    generateAllSlideImages(width, height).then(slideImages => {
        console.log('Generated slide images:', slideImages);
        displayCarouselPreview(slideImages, width, height);
    }).catch(error => {
        console.error('Error generating preview:', error);
        showNotification('Error generating preview: ' + error.message, 'error');
        closeModal('previewModal');
    });
}

function generateAllSlideImages(width, height) {
    return new Promise((resolve, reject) => {
        const canvasArea = document.getElementById('canvasArea');
        const slides = canvasArea.querySelectorAll('.carousel-slide');
        
        console.log('Found slides:', slides.length);
        
        if (slides.length === 0) {
            reject(new Error('No slides found'));
            return;
        }
        
        const slideImages = [];
        let currentIndex = 0;
        
        function processNextSlide() {
            if (currentIndex >= slides.length) {
                console.log('All slides processed:', slideImages.length);
                resolve(slideImages);
                return;
            }
            
            const slide = slides[currentIndex];
            const slideNum = currentIndex + 1;
            
            console.log('Processing slide', slideNum);
            
            generateSlideImage(slide, width, height, slideNum).then(imageData => {
                console.log('Generated image for slide', slideNum, 'length:', imageData.length);
                slideImages.push({
                    slideNumber: slideNum,
                    imageUrl: imageData
                });
                currentIndex++;
                processNextSlide();
            }).catch(error => {
                console.error('Error processing slide', slideNum, ':', error);
                reject(error);
            });
        }
        
        processNextSlide();
    });
}

function generateSlideImage(slide, width, height, slideNum) {
    return new Promise((resolve, reject) => {
        // Temporarily make this slide active to get proper dimensions
        const wasActive = slide.classList.contains('active');
        if (!wasActive) {
            // Hide current active slide
            const currentActive = document.querySelector('.carousel-slide.active');
            if (currentActive) {
                currentActive.classList.remove('active');
            }
            // Show this slide
            slide.classList.add('active');
        }
        
        const canvas = document.createElement('canvas');
        canvas.width = width;
        canvas.height = height;
        const ctx = canvas.getContext('2d');
        
        // Get actual dimensions of the slide element itself
        const actualWidth = slide.offsetWidth;
        const actualHeight = slide.offsetHeight;
        
        console.log('Slide dimensions - Actual:', actualWidth, 'x', actualHeight, 'Target:', width, 'x', height);
        
        // Fill background
        const computedStyle = window.getComputedStyle(slide);
        let bgColor = computedStyle.backgroundColor;
        
        // Handle gradient backgrounds
        if (computedStyle.background && computedStyle.background !== 'none' && computedStyle.background.includes('gradient')) {
            // For gradients, we'll use a solid color approximation or draw the gradient
            bgColor = computedStyle.backgroundColor || '#ffffff';
        }
        
        if (!bgColor || bgColor === 'rgba(0, 0, 0, 0)' || bgColor === 'transparent') {
            bgColor = '#ffffff';
        }
        
        ctx.fillStyle = bgColor;
        ctx.fillRect(0, 0, width, height);
        
        console.log('Background color:', bgColor);
        
        // Get all elements and sort by z-index
        const elements = Array.from(slide.querySelectorAll('.canvas-element'));
        
        console.log('Found elements in slide ' + slideNum + ':', elements.length);
        
        elements.sort((a, b) => {
            const zA = parseInt(a.style.zIndex) || 0;
            const zB = parseInt(b.style.zIndex) || 0;
            return zA - zB;
        });
        
        if (elements.length === 0) {
            // No elements, just return the background
            console.log('No elements in slide, returning background only');
            
            // Restore active state
            if (!wasActive) {
                slide.classList.remove('active');
                const firstSlide = document.querySelector('.carousel-slide[data-slide="1"]');
                if (firstSlide) firstSlide.classList.add('active');
            }
            
            resolve(canvas.toDataURL('image/png'));
            return;
        }
        
        let processedCount = 0;
        
        function processNextElement() {
            if (processedCount >= elements.length) {
                console.log('All elements processed for slide ' + slideNum);
                const dataUrl = canvas.toDataURL('image/png');
                console.log('Final image data URL length:', dataUrl.length);
                
                // Restore active state
                if (!wasActive) {
                    slide.classList.remove('active');
                    const firstSlide = document.querySelector('.carousel-slide[data-slide="1"]');
                    if (firstSlide) firstSlide.classList.add('active');
                }
                
                resolve(dataUrl);
                return;
            }
            
            const element = elements[processedCount];
            
            // Get element dimensions with fallbacks
            let elementLeft = element.offsetLeft || 0;
            let elementTop = element.offsetTop || 0;
            let elementWidth = element.offsetWidth || 0;
            let elementHeight = element.offsetHeight || 0;
            
            // If dimensions are still 0, try getBoundingClientRect
            if (elementWidth === 0 || elementHeight === 0) {
                const rect = element.getBoundingClientRect();
                const slideRect = slide.getBoundingClientRect();
                elementLeft = rect.left - slideRect.left;
                elementTop = rect.top - slideRect.top;
                elementWidth = rect.width;
                elementHeight = rect.height;
            }
            
            // Skip elements with no dimensions
            if (elementWidth === 0 || elementHeight === 0 || actualWidth === 0 || actualHeight === 0) {
                console.log('Skipping element with zero dimensions:', element.className);
                processedCount++;
                processNextElement();
                return; // This returns from the CURRENT call, which is correct
            }
            
            // Calculate position relative to canvas area
            const x = (elementLeft / actualWidth) * width;
            const y = (elementTop / actualHeight) * height;
            const w = (elementWidth / actualWidth) * width;
            const h = (elementHeight / actualHeight) * height;
            
            console.log('Element', processedCount + 1, '- Type:', element.className, 'Position:', {x, y, w, h}, 'Original:', {elementLeft, elementTop, elementWidth, elementHeight});
            
            if (element.classList.contains('text-element')) {
                try {
                    renderTextElement(ctx, element, x, y, w, h, actualWidth, width);
                    processedCount++;
                    processNextElement();
                } catch (err) {
                    console.error('Error rendering text element:', err);
                    processedCount++;
                    processNextElement();
                }
            } else if (element.classList.contains('article-element')) {
                try {
                    renderArticleElement(ctx, element, x, y, w, h, actualWidth, width);
                    processedCount++;
                    processNextElement();
                } catch (err) {
                    console.error('Error rendering article element:', err);
                    processedCount++;
                    processNextElement();
                }
            } else if (element.classList.contains('image-element') || element.classList.contains('sticker-element')) {
                renderImageElement(ctx, element, x, y, w, h).then(() => {
                    processedCount++;
                    processNextElement();
                }).catch((err) => {
                    console.error('Error rendering image/sticker element:', err);
                    processedCount++;
                    processNextElement();
                });
            } else if (element.classList.contains('shape-element')) {
                try {
                    renderShapeElement(ctx, element, x, y, w, h, actualWidth, width);
                    processedCount++;
                    processNextElement();
                } catch (err) {
                    console.error('Error rendering shape element:', err);
                    processedCount++;
                    processNextElement();
                }
            } else {
                console.log('Unknown element type:', element.className);
                processedCount++;
                processNextElement();
            }
        }
        
        processNextElement();
    });
}

function renderTextElement(ctx, element, x, y, w, h, slideWidth, canvasWidth) {
    // Find the actual text content div
    const textContentDiv = element.querySelector('.text-content');
    if (!textContentDiv) {
        console.log('No .text-content div found in text element');
        return;
    }
    
    const computedStyle = window.getComputedStyle(textContentDiv);
    
    // Get text content
    let text = '';
    if (textContentDiv.textContent) {
        text = textContentDiv.textContent.trim();
    } else if (textContentDiv.innerText) {
        text = textContentDiv.innerText.trim();
    }
    
    if (!text) {
        console.log('No text content found');
        return;
    }
    
    console.log('Rendering text:', text.substring(0, 50));
    
    // Parse font properties with fallbacks
    const fontSize = parseFloat(computedStyle.fontSize) || 16;
    const scaledFontSize = (fontSize / slideWidth) * canvasWidth;
    const color = computedStyle.color || '#000000';
    const fontWeight = computedStyle.fontWeight || 'normal';
    const fontStyle = computedStyle.fontStyle || 'normal';
    
    // Clean up font family - remove quotes and use first font
    let fontFamily = computedStyle.fontFamily || 'Arial';
    fontFamily = fontFamily.replace(/["']/g, '').split(',')[0].trim();
    
    const textAlign = computedStyle.textAlign || 'left';
    
    ctx.save();
    
    // Build font string more carefully
    let fontString = '';
    if (fontStyle !== 'normal') fontString += fontStyle + ' ';
    if (fontWeight !== 'normal' && fontWeight !== '400') fontString += fontWeight + ' ';
    fontString += Math.round(scaledFontSize) + 'px ';
    fontString += fontFamily;
    
    ctx.font = fontString;
    ctx.fillStyle = color;
    ctx.textBaseline = 'top';
    
    console.log('Text style - Font:', ctx.font, 'Color:', color, 'Align:', textAlign);
    
    // Handle text alignment
    let textX = x;
    if (textAlign === 'center') {
        ctx.textAlign = 'center';
        textX = x + w / 2;
    } else if (textAlign === 'right') {
        ctx.textAlign = 'right';
        textX = x + w;
    } else {
        ctx.textAlign = 'left';
    }
    
    // Wrap text if needed
    const words = text.split(/\s+/);
    let line = '';
    let lineY = y;
    const lineHeight = scaledFontSize * 1.2;
    
    for (let i = 0; i < words.length; i++) {
        const testLine = line + (line ? ' ' : '') + words[i];
        const metrics = ctx.measureText(testLine);
        if (metrics.width > w && i > 0) {
            ctx.fillText(line, textX, lineY);
            line = words[i];
            lineY += lineHeight;
        } else {
            line = testLine;
        }
    }
    ctx.fillText(line, textX, lineY);
    
    ctx.restore();
}

function renderArticleElement(ctx, element, x, y, w, h, slideWidth, canvasWidth) {
    console.log('renderArticleElement called');
    
    // Draw background (article elements already have borders in the DOM, don't duplicate)
    ctx.save();
    ctx.fillStyle = '#ffffff';
    ctx.fillRect(x, y, w, h);
    
    // Get text - try article-content div first, then element itself
    let text = '';
    const articleContentDiv = element.querySelector('.article-content');
    if (articleContentDiv) {
        text = articleContentDiv.textContent || articleContentDiv.innerText || '';
    } else {
        text = element.textContent || element.innerText || '';
    }
    
    // Clean up text
    text = text.replace(/[\n\r\t]+/g, ' ').replace(/\s+/g, ' ').trim();
    
    if (!text) {
        console.log('No article text found');
        ctx.restore();
        return;
    }
    
    console.log('Rendering article text:', text.substring(0, 50));
    
    // Use default article styling
    const baseFontSize = 14;
    const scaledFontSize = Math.max(8, (baseFontSize / slideWidth) * canvasWidth);
    
    ctx.font = `${Math.round(scaledFontSize)}px Arial, sans-serif`;
    ctx.fillStyle = '#111827';
    ctx.textBaseline = 'top';
    ctx.textAlign = 'left';
    
    // Add padding
    const padding = (16 / slideWidth) * canvasWidth;
    const textX = x + padding;
    let textY = y + padding * 2; // Extra top padding for drag handle
    const textWidth = w - (padding * 2);
    const lineHeight = scaledFontSize * 1.4;
    
    // Wrap text
    const words = text.split(/\s+/);
    let line = '';
    
    for (let i = 0; i < words.length; i++) {
        const testLine = line + (line ? ' ' : '') + words[i];
        const metrics = ctx.measureText(testLine);
        if (metrics.width > textWidth && i > 0) {
            ctx.fillText(line, textX, textY);
            line = words[i];
            textY += lineHeight;
            if (textY > y + h - padding) break;
        } else {
            line = testLine;
        }
    }
    if (textY <= y + h - padding && line) {
        ctx.fillText(line, textX, textY);
    }
    
    ctx.restore();
}

function renderImageElement(ctx, element, x, y, w, h) {
    return new Promise((resolve, reject) => {
        const computedStyle = window.getComputedStyle(element);
        const bgImage = computedStyle.backgroundImage;
        
        console.log('Image/Sticker element background:', bgImage);
        console.log('Element has dataset.stickerUrl:', element.dataset.stickerUrl);
        console.log('Element has dataset.imageSrc:', element.dataset.imageSrc);
        
        // Check dataset first for data URLs
        if (element.dataset.imageSrc) {
            const img = new Image();
            img.crossOrigin = 'anonymous';
            img.onload = function() {
                console.log('Image loaded successfully from dataset.imageSrc:', img.width, 'x', img.height);
                ctx.drawImage(img, x, y, w, h);
                resolve();
            };
            img.onerror = function(err) {
                console.error('Image load error:', err);
                resolve();
            };
            img.src = element.dataset.imageSrc;
            return;
        }
        
        // Check for sticker URL
        if (element.dataset.stickerUrl) {
            const img = new Image();
            img.crossOrigin = 'anonymous';
            img.onload = function() {
                console.log('Sticker loaded successfully from dataset.stickerUrl:', img.width, 'x', img.height);
                ctx.drawImage(img, x, y, w, h);
                resolve();
            };
            img.onerror = function(err) {
                console.error('Sticker load error:', err);
                resolve();
            };
            img.src = element.dataset.stickerUrl;
            return;
        }
        
        if (!bgImage || bgImage === 'none') {
            console.log('No background image found');
            resolve();
            return;
        }
        
        const urlMatch = bgImage.match(/url\(['"]?(.*?)['"]?\)/);
        
        if (urlMatch && urlMatch[1]) {
            const img = new Image();
            img.crossOrigin = 'anonymous';
            img.onload = function() {
                console.log('Image loaded successfully from backgroundImage:', img.width, 'x', img.height);
                ctx.drawImage(img, x, y, w, h);
                resolve();
            };
            img.onerror = function(err) {
                console.error('Image load error:', err);
                resolve();
            };
            img.src = urlMatch[1];
        } else {
            console.log('Could not extract URL from background image');
            resolve();
        }
    });
}

function renderShapeElement(ctx, element, x, y, w, h, slideWidth, canvasWidth) {
    const shapeType = element.dataset.shapeType;
    const fillColor = element.dataset.fillColor || '#667eea';
    const strokeColor = element.dataset.strokeColor || '#667eea';
    const strokeWidth = parseInt(element.dataset.strokeWidth) || 0;
    
    console.log('Rendering shape:', shapeType, 'Fill:', fillColor, 'Stroke:', strokeColor, strokeWidth);
    
    ctx.save();
    
    const scaledStrokeWidth = strokeWidth > 0 ? (strokeWidth / slideWidth) * canvasWidth : 0;
    
    if (scaledStrokeWidth > 0) {
        ctx.strokeStyle = strokeColor;
        ctx.lineWidth = scaledStrokeWidth;
    }
    
    ctx.beginPath();
    
    switch(shapeType) {
        case 'rectangle':
            ctx.rect(x, y, w, h);
            break;
        case 'circle':
            const centerX = x + w / 2;
            const centerY = y + h / 2;
            const radius = Math.min(w, h) / 2;
            ctx.arc(centerX, centerY, radius, 0, 2 * Math.PI);
            break;
        case 'triangle':
            ctx.moveTo(x + w / 2, y);
            ctx.lineTo(x + w, y + h);
            ctx.lineTo(x, y + h);
            ctx.closePath();
            break;
        case 'star':
            const spikes = 5;
            const outerRadius = Math.min(w, h) / 2;
            const innerRadius = outerRadius / 2;
            const centerStarX = x + w / 2;
            const centerStarY = y + h / 2;
            let rot = Math.PI / 2 * 3;
            let stepAngle = Math.PI / spikes;
            
            ctx.moveTo(centerStarX, centerStarY - outerRadius);
            for (let i = 0; i < spikes; i++) {
                ctx.lineTo(centerStarX + Math.cos(rot) * outerRadius, centerStarY + Math.sin(rot) * outerRadius);
                rot += stepAngle;
                ctx.lineTo(centerStarX + Math.cos(rot) * innerRadius, centerStarY + Math.sin(rot) * innerRadius);
                rot += stepAngle;
            }
            ctx.lineTo(centerStarX, centerStarY - outerRadius);
            ctx.closePath();
            break;
    }
    
    if (fillColor !== 'transparent') {
        ctx.fillStyle = fillColor;
        ctx.fill();
    }
    if (scaledStrokeWidth > 0) {
        ctx.stroke();
    }
    
    ctx.restore();
}

function displayCarouselPreview(slideImages, width, height) {
    const previewContainer = document.getElementById('previewContainer');
    
    console.log('Displaying carousel with', slideImages.length, 'slides');
    
    // Calculate preview dimensions
    const maxWidth = window.innerWidth * 0.7;
    const maxHeight = window.innerHeight * 0.6;
    const aspectRatio = width / height;
    
    let previewWidth, previewHeight;
    
    if (width > maxWidth || height > maxHeight) {
        if (aspectRatio > 1) {
            previewWidth = Math.min(width, maxWidth);
            previewHeight = previewWidth / aspectRatio;
            if (previewHeight > maxHeight) {
                previewHeight = maxHeight;
                previewWidth = previewHeight * aspectRatio;
            }
        } else {
            previewHeight = Math.min(height, maxHeight);
            previewWidth = previewHeight * aspectRatio;
            if (previewWidth > maxWidth) {
                previewWidth = maxWidth;
                previewHeight = previewWidth / aspectRatio;
            }
        }
    } else {
        previewWidth = width;
        previewHeight = height;
    }
    
    console.log('Preview dimensions:', previewWidth, 'x', previewHeight);
    
    // Create carousel HTML
    let carouselHTML = `
        <div style="position: relative; width: ${previewWidth}px; height: ${previewHeight}px; background: white; box-shadow: 0 10px 40px rgba(0,0,0,0.2);">
            <div id="previewCarouselInner" style="width: 100%; height: 100%; position: relative; overflow: hidden;">
                ${slideImages.map((slide, index) => `
                    <div class="preview-slide" data-slide="${index}" style="position: absolute; top: 0; left: 0; width: 100%; height: 100%; opacity: ${index === 0 ? '1' : '0'}; transition: opacity 0.5s ease;">
                        <img src="${slide.imageUrl}" style="width: 100%; height: 100%; object-fit: contain; display: block;" alt="Slide ${slide.slideNumber}">
                    </div>
                `).join('')}
            </div>
            
            ${slideImages.length > 1 ? `
                <button onclick="previewPrevSlide()" style="position: absolute; left: 10px; top: 50%; transform: translateY(-50%); background: rgba(0,0,0,0.5); color: white; border: none; width: 40px; height: 40px; border-radius: 50%; cursor: pointer; display: flex; align-items: center; justify-content: center; z-index: 10;">
                    <i class="fas fa-chevron-left"></i>
                </button>
                <button onclick="previewNextSlide()" style="position: absolute; right: 10px; top: 50%; transform: translateY(-50%); background: rgba(0,0,0,0.5); color: white; border: none; width: 40px; height: 40px; border-radius: 50%; cursor: pointer; display: flex; align-items: center; justify-content: center; z-index: 10;">
                    <i class="fas fa-chevron-right"></i>
                </button>
                
                <div style="position: absolute; bottom: 10px; left: 50%; transform: translateX(-50%); display: flex; gap: 8px; z-index: 10;">
                    ${slideImages.map((_, index) => `
                        <div class="preview-indicator" data-index="${index}" onclick="previewGoToSlide(${index})" style="width: 8px; height: 8px; border-radius: 50%; background: ${index === 0 ? 'white' : 'rgba(255,255,255,0.5)'}; cursor: pointer; transition: background 0.3s;"></div>
                    `).join('')}
                </div>
                
                <div style="position: absolute; top: 10px; right: 10px; display: flex; gap: 8px; z-index: 10;">
                    <button onclick="toggleAutoPlay()" id="autoPlayBtn" style="background: rgba(0,0,0,0.5); color: white; border: none; padding: 8px 12px; border-radius: 6px; cursor: pointer; display: flex; align-items: center; gap: 6px;">
                        <i class="fas fa-play"></i> Auto
                    </button>
                </div>
            ` : ''}
            
            <div style="position: absolute; top: 10px; left: 10px; background: rgba(0,0,0,0.7); color: white; padding: 6px 12px; border-radius: 6px; font-size: 14px; z-index: 10;">
                <span id="previewSlideCounter">1</span> / ${slideImages.length}
            </div>
        </div>
        
        <div style="margin-top: 20px; display: flex; gap: 12px; justify-content: center;">
            <button onclick="saveCarouselImages()" class="btn btn-success" style="padding: 12px 24px; background: #10b981; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 15px; font-weight: 600;">
                <i class="fas fa-save" style="margin-right: 8px;"></i> Save Carousel
            </button>
            <button onclick="closeModal('previewModal')" class="btn btn-secondary" style="padding: 12px 24px; background: #6b7280; color: white; border: none; border-radius: 6px; cursor: pointer; font-size: 15px; font-weight: 600;">
                <i class="fas fa-times" style="margin-right: 8px;"></i> Close
            </button>
        </div>
    `;
    
    previewContainer.innerHTML = carouselHTML;
    
    // Store slide images globally for saving
    window.currentPreviewImages = slideImages;
    window.currentPreviewSlide = 0;
    window.previewAutoPlayInterval = null;
    
    console.log('Carousel preview displayed');
}

function previewNextSlide() {
    const slides = document.querySelectorAll('.preview-slide');
    const indicators = document.querySelectorAll('.preview-indicator');
    
    if (slides.length === 0) return;
    
    // Hide current
    slides[window.currentPreviewSlide].style.opacity = '0';
    indicators[window.currentPreviewSlide].style.background = 'rgba(255,255,255,0.5)';
    
    // Next slide
    window.currentPreviewSlide = (window.currentPreviewSlide + 1) % slides.length;
    
    // Show next
    slides[window.currentPreviewSlide].style.opacity = '1';
    indicators[window.currentPreviewSlide].style.background = 'white';
    
    // Update counter
    document.getElementById('previewSlideCounter').textContent = window.currentPreviewSlide + 1;
}

function previewPrevSlide() {
    const slides = document.querySelectorAll('.preview-slide');
    const indicators = document.querySelectorAll('.preview-indicator');
    
    if (slides.length === 0) return;
    
    // Hide current
    slides[window.currentPreviewSlide].style.opacity = '0';
    indicators[window.currentPreviewSlide].style.background = 'rgba(255,255,255,0.5)';
    
    // Previous slide
    window.currentPreviewSlide = (window.currentPreviewSlide - 1 + slides.length) % slides.length;
    
    // Show previous
    slides[window.currentPreviewSlide].style.opacity = '1';
    indicators[window.currentPreviewSlide].style.background = 'white';
    
    // Update counter
    document.getElementById('previewSlideCounter').textContent = window.currentPreviewSlide + 1;
}

function previewGoToSlide(index) {
    const slides = document.querySelectorAll('.preview-slide');
    const indicators = document.querySelectorAll('.preview-indicator');
    
    if (slides.length === 0) return;
    
    // Hide current
    slides[window.currentPreviewSlide].style.opacity = '0';
    indicators[window.currentPreviewSlide].style.background = 'rgba(255,255,255,0.5)';
    
    // Go to target
    window.currentPreviewSlide = index;
    
    // Show target
    slides[window.currentPreviewSlide].style.opacity = '1';
    indicators[window.currentPreviewSlide].style.background = 'white';
    
    // Update counter
    document.getElementById('previewSlideCounter').textContent = window.currentPreviewSlide + 1;
}

function toggleAutoPlay() {
    const btn = document.getElementById('autoPlayBtn');
    
    if (window.previewAutoPlayInterval) {
        // Stop autoplay
        clearInterval(window.previewAutoPlayInterval);
        window.previewAutoPlayInterval = null;
        btn.innerHTML = '<i class="fas fa-play"></i> Auto';
    } else {
        // Start autoplay
        window.previewAutoPlayInterval = setInterval(() => {
            previewNextSlide();
        }, 3000);
        btn.innerHTML = '<i class="fas fa-pause"></i> Auto';
    }
}

function saveCarouselImages() {
    if (!window.currentPreviewImages || window.currentPreviewImages.length === 0) {
        showNotification('No images to save', 'warning');
        return;
    }
    
    const carouselName = prompt('Enter a name for this carousel:');
    if (!carouselName) return;
    
    showNotification('Saving carousel images...', 'info');
    
    console.log('Saving carousel with', window.currentPreviewImages.length, 'images');
    console.log('First image preview:', window.currentPreviewImages[0].imageUrl.substring(0, 100));
    
    fetch('/management/ajax/marketing.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            action: 'save_carousel_images',
            carousel_name: carouselName,
            images: window.currentPreviewImages
        })
    })
    .then(response => {
        console.log('Response status:', response.status);
        if (!response.ok) {
            throw new Error('Network response was not ok');
        }
        return response.json();
    })
    .then(data => {
        console.log('Save response:', data);
        if (data.success) {
            showNotification('Carousel saved successfully! ' + data.count + ' images saved to folder: ' + data.folder, 'success');
            closeModal('previewModal');
        } else {
            showNotification(data.message || 'Failed to save carousel', 'error');
        }
    })
    .catch(error => {
        console.error('Error saving carousel:', error);
        showNotification('Error saving carousel: ' + error.message, 'error');
    });
}

console.log('Marketing Module v2.0 with HTML Editor Toolbar loaded successfully');