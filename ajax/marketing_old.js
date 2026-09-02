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
    element.style.zIndex = '100';
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
    element.style.zIndex = '100';
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
    // Close HTML editor if clicking outside
    if (MarketingApp.htmlEditor.visible && 
        !e.target.closest('#htmlEditorToolbar') && 
        !e.target.closest('.article-element') && 
        !e.target.closest('.text-element')) {
        closeHtmlEditor();
    }
    
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
    
    // Layer management shortcuts
    if (e.key === 'ArrowUp' && e.shiftKey && !e.ctrlKey) {
        e.preventDefault();
        moveElementLayerUp();
        return;
    } else if (e.key === 'ArrowDown' && e.shiftKey && !e.ctrlKey) {
        e.preventDefault();
        moveElementLayerDown();
        return;
    }

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

    // Get highest z-index on this slide
    const existingElements = currentSlide.querySelectorAll('.canvas-element');
    const maxZ = existingElements.length > 0 
        ? Math.max(...Array.from(existingElements).map(el => parseInt(el.style.zIndex) || 0))
        : 0;

    const textElement = document.createElement('div');
    textElement.className = 'canvas-element text-element';
    textElement.contentEditable = true;
    textElement.textContent = 'Double-click to edit';
    textElement.style.position = 'absolute';
    textElement.style.left = '50px';
    textElement.style.top = '50px';
    textElement.style.fontSize = '24px';
    textElement.style.color = '#111827';
    textElement.style.fontWeight = '600';
    textElement.style.fontFamily = 'Inter';
    textElement.style.padding = '8px';
    textElement.style.minWidth = '100px';
    textElement.style.minHeight = '40px';
    textElement.style.cursor = 'move';
    textElement.style.zIndex = maxZ + 1; // Set to highest + 1
    textElement.dataset.elementType = 'text';
    
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
    controls.querySelectorAll('*').forEach(el => el.contentEditable = false);
    
    textElement.appendChild(controls);
    
    currentSlide.appendChild(textElement);
    makeElementInteractive(textElement);
    selectElement(textElement);
    saveElementToSlideState(textElement);
    
    // Focus for editing
    setTimeout(() => {
        textElement.focus();
        const range = document.createRange();
        const sel = window.getSelection();
        range.selectNodeContents(textElement.firstChild || textElement);
        sel.removeAllRanges();
        sel.addRange(range);
    }, 50);
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
            imageElement.style.backgroundSize = 'cover'; // CRITICAL FIX
            imageElement.style.backgroundPosition = 'center';
            imageElement.style.backgroundRepeat = 'no-repeat';
            imageElement.dataset.imageSrc = e.target.result;
            
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
            
            currentSlide.appendChild(imageElement);
            makeElementInteractive(imageElement);
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
    
    showNotification(`Canvas resized to ${width}×${height}px`, 'success');
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
    
    saveElementToSlideState(element);
}


// Make element interactive (draggable, selectable, resizable)
function makeElementInteractive(element) {
    let isDragging = false;
    let dragOffset = { x: 0, y: 0 };
    
    element.addEventListener('mousedown', function(e) {
        if (e.target.classList.contains('resize-handle')) {
            handleResizeStart(e, element);
            return;
        }
        
        if (element.contentEditable === 'true' && e.target === element) {
            // Allow editing
            return;
        }
        
        e.preventDefault();
        e.stopPropagation();
        
        selectElement(element);
        
        isDragging = true;
        const rect = element.getBoundingClientRect();
        const parentRect = element.parentElement.getBoundingClientRect();
        
        dragOffset.x = e.clientX - (rect.left - parentRect.left);
        dragOffset.y = e.clientY - (rect.top - parentRect.top);
        
        element.style.cursor = 'grabbing';
    });
    
    document.addEventListener('mousemove', function(e) {
        if (!isDragging) return;
        
        const parentRect = element.parentElement.getBoundingClientRect();
        const x = e.clientX - parentRect.left - dragOffset.x;
        const y = e.clientY - parentRect.top - dragOffset.y;
        
        element.style.left = Math.max(0, Math.min(x, parentRect.width - element.offsetWidth)) + 'px';
        element.style.top = Math.max(0, Math.min(y, parentRect.height - element.offsetHeight)) + 'px';
    });
    
    document.addEventListener('mouseup', function() {
        if (isDragging) {
            isDragging = false;
            element.style.cursor = 'move';
            saveElementToSlideState(element);
        }
    });
}

// Resize handling
function handleResizeStart(e, element) {
    e.preventDefault();
    e.stopPropagation();
    
    const handle = e.target;
    const startX = e.clientX;
    const startY = e.clientY;
    const startWidth = element.offsetWidth;
    const startHeight = element.offsetHeight;
    const startLeft = element.offsetLeft;
    const startTop = element.offsetTop;
    
    function handleResizeMove(e) {
        const deltaX = e.clientX - startX;
        const deltaY = e.clientY - startY;
        
        if (handle.classList.contains('se')) {
            // Southeast: grow right and down
            element.style.width = Math.max(50, startWidth + deltaX) + 'px';
            element.style.height = Math.max(50, startHeight + deltaY) + 'px';
        } else if (handle.classList.contains('sw')) {
            // Southwest: grow left and down
            const newWidth = Math.max(50, startWidth - deltaX);
            element.style.width = newWidth + 'px';
            element.style.height = Math.max(50, startHeight + deltaY) + 'px';
            element.style.left = (startLeft + (startWidth - newWidth)) + 'px';
        } else if (handle.classList.contains('ne')) {
            // Northeast: grow right and up
            const newHeight = Math.max(50, startHeight - deltaY);
            element.style.width = Math.max(50, startWidth + deltaX) + 'px';
            element.style.height = newHeight + 'px';
            element.style.top = (startTop + (startHeight - newHeight)) + 'px';
        } else if (handle.classList.contains('nw')) {
            // Northwest: grow left and up
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
        saveElementToSlideState(element);
    }
    
    document.addEventListener('mousemove', handleResizeMove);
    document.addEventListener('mouseup', handleResizeEnd);
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

// Background color change
function changeBackgroundColor(color) {
    const currentSlide = document.querySelector('.carousel-slide.active');
    currentSlide.style.backgroundColor = color;
    
    // Save to slide state
    const slideNum = parseInt(currentSlide.dataset.slide);
    if (MarketingApp.slides[slideNum]) {
        MarketingApp.slides[slideNum].background = color;
    }
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
        // Add delete button
        const deleteBtn = document.createElement('button');
        deleteBtn.className = 'thumb-delete';
        deleteBtn.innerHTML = '×';
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
        type: element.classList.contains('text-element') ? 'text' : 'image',
        left: element.style.left,
        top: element.style.top,
        width: element.style.width,
        height: element.style.height,
        content: element.classList.contains('text-element') ? element.textContent : element.dataset.imageSrc,
        fontSize: element.style.fontSize,
        color: element.style.color,
        fontWeight: element.style.fontWeight,
        articleId: element.dataset.articleId
    };
    
    // Update or add element
    const existingIndex = MarketingApp.slides[slideNum].elements.findIndex(e => e === elementData);
    if (existingIndex >= 0) {
        MarketingApp.slides[slideNum].elements[existingIndex] = elementData;
    } else {
        MarketingApp.slides[slideNum].elements.push(elementData);
    }
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
    loadDefaultTemplates();
}

function loadUserTemplates() {
    fetch('/management/ajax/marketing.php?action=get_user_templates')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayTemplates(data.templates, 'user');
            }
        })
        .catch(error => {
            console.error('Error loading user templates:', error);
            showNotification('Failed to load templates', 'error');
        });
}

function loadSharedTemplates() {
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
    
    if (templates.length === 0) {
        gallery.innerHTML = `
            <div style="grid-column: 1/-1; text-align: center; padding: 40px; color: #9ca3af;">
                <i class="fas fa-layer-group" style="font-size: 48px; margin-bottom: 12px; opacity: 0.5;"></i>
                <p>No templates available</p>
            </div>
        `;
        return;
    }
    
    gallery.innerHTML = templates.map(template => `
        <div class="template-card" data-template-id="${template.id}" data-template-type="${type}" onclick="selectTemplate('${template.id}', '${type}')">
            <div class="template-preview" style="background-image: url('${template.thumbnail}');"></div>
            <div class="template-name">${template.name}</div>
            <div class="template-slides">${template.slides} slide${template.slides > 1 ? 's' : ''}</div>
        </div>
    `).join('');
    
    MarketingApp.templates = templates;
}

function selectTemplate(templateId, type) {
    // Remove selection from all
    document.querySelectorAll('.template-card').forEach(card => {
        card.classList.remove('selected');
    });
    
    // Select this one
    const card = document.querySelector(`[data-template-id="${templateId}"]`);
    if (card) {
        card.classList.add('selected');
        MarketingApp.selectedTemplate = MarketingApp.templates.find(t => t.id === templateId);
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
        showNotification('Template applied successfully', 'success');
    }
}

function applyTemplateToCanvas(template) {
    // Clear canvas
    const canvasArea = document.getElementById('canvasArea');
    canvasArea.innerHTML = '';
    
    // Reset state
    MarketingApp.slides = {};
    MarketingApp.totalSlides = template.slides;
    MarketingApp.currentSlide = 1;
    
    // Create slides from template
    Object.keys(template.data.slides).forEach(slideNum => {
        const slideData = template.data.slides[slideNum];
        const slide = document.createElement('div');
        slide.className = 'carousel-slide' + (slideNum === '1' ? ' active' : '');
        slide.id = 'slide-' + slideNum;
        slide.dataset.slide = slideNum;
        slide.style.background = slideData.background;
        
        canvasArea.appendChild(slide);
        
        MarketingApp.slides[slideNum] = {
            elements: slideData.elements || [],
            background: slideData.background,
            backgroundImage: slideData.backgroundImage || null
        };
    });
    
    updateSlideIndicator();
    generateSlideThumbnails();
}

// Save as template
function saveAsTemplate() {
    const templateName = prompt('Enter a name for this template:');
    if (!templateName) return;
    
    const templateData = {
        name: templateName,
        slides: MarketingApp.totalSlides,
        data: {
            slides: MarketingApp.slides
        },
        is_shared: confirm('Make this template available to all users?')
    };
    
    fetch('/management/ajax/marketing.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            action: 'save_template',
            template: templateData
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            showNotification('Template saved successfully', 'success');
        } else {
            showNotification(data.message || 'Failed to save template', 'error');
        }
    })
    .catch(error => {
        console.error('Error saving template:', error);
        showNotification('Error saving template', 'error');
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
    .then(response => response.json())
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
        showNotification('Error saving project', 'error');
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
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                applyProjectToCanvas(data.project);
                closeModal('loadModal');
                showNotification('Project loaded successfully', 'success');
            }
        })
        .catch(error => {
            console.error('Error loading project:', error);
            showNotification('Error loading project', 'error');
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
            return `Ã°Å¸"â€” Article ${index + 1}: ${article.title}`;
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
            return `Ã°Å¸"â€” ${article.title}: ${article.url}`;
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
        const canvasWidth = slideElement.clientWidth || 1080;
        const canvasHeight = slideElement.clientHeight || 1080;
        const articleWidth = 400;
        const articleHeight = 200;
        const centerX = (canvasWidth - articleWidth) / 2;
        const centerY = (canvasHeight - articleHeight) / 2;
        
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

    // Get highest z-index on this slide
    const existingElements = slideElement.querySelectorAll('.canvas-element');
    const maxZ = existingElements.length > 0 
        ? Math.max(...Array.from(existingElements).map(el => parseInt(el.style.zIndex) || 0))
        : 0;

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
    element.style.zIndex = maxZ + 1; // Set to highest + 1
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
// NEW FEATURE: STICKERS
// ====================================

function openStickersModal() {
    document.getElementById('stickersModal').classList.add('active');
    loadStickers();
}

function loadStickers() {
    fetch('/management/ajax/marketing.php?action=get_stickers')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                displayStickers(data.stickers);
            } else {
                showNotification('Failed to load stickers', 'error');
            }
        })
        .catch(error => {
            console.error('Error loading stickers:', error);
            showNotification('Error loading stickers: ' + error.message, 'error');
        });
}

function displayStickers(stickers) {
    const grid = document.getElementById('stickersGrid');
    
    if (stickers.length === 0) {
        grid.innerHTML = '<p style="text-align: center; color: #9ca3af; padding: 40px;">No stickers available</p>';
        return;
    }
    
    grid.innerHTML = stickers.map(sticker => `
        <div class="sticker-item" onclick="addStickerToCanvas('${sticker.file_url}', ${sticker.width || 100}, ${sticker.height || 100})">
            <img src="${sticker.file_url}" alt="${sticker.name}" style="max-width: 100%; max-height: 100px;">
            <div class="sticker-name">${sticker.name}</div>
        </div>
    `).join('');
}

function addStickerToCanvas(url, width, height) {
    const currentSlide = document.querySelector('.carousel-slide.active');
    
    const stickerElement = document.createElement('div');
    stickerElement.className = 'canvas-element sticker-element';
    stickerElement.style.left = '100px';
    stickerElement.style.top = '100px';
    stickerElement.style.width = width + 'px';
    stickerElement.style.height = height + 'px';
    stickerElement.style.backgroundImage = `url(${url})`;
    stickerElement.style.backgroundSize = 'contain';
    stickerElement.style.backgroundRepeat = 'no-repeat';
    stickerElement.style.backgroundPosition = 'center';
    stickerElement.dataset.stickerUrl = url;
    
    // Add controls
    stickerElement.innerHTML = `
        <div class="resize-handle nw"></div>
        <div class="resize-handle ne"></div>
        <div class="resize-handle sw"></div>
        <div class="resize-handle se"></div>
        <button class="element-delete-btn" onclick="deleteElement(this); event.stopPropagation();">
            <i class="fas fa-times"></i>
        </button>
    `;
    
    currentSlide.appendChild(stickerElement);
    makeElementInteractive(stickerElement);
    selectElement(stickerElement);
    
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
            top: 4px;
            right: 4px;
            width: 20px;
            height: 20px;
            background: rgba(239, 68, 68, 0.95);
            color: white;
            border: none;
            border-radius: 50%;
            cursor: pointer;
            display: none;
            align-items: center;
            justify-content: center;
            font-size: 14px;
            line-height: 1;
            transition: all 0.2s;
            z-index: 10;
        }
        .slide-thumb:hover .thumb-delete {
            display: flex;
        }
        .thumb-delete:hover {
            background: rgba(220, 38, 38, 1);
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
}

function closeHtmlEditor() {
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
    
    if (MarketingApp.savedRange) {
        const span = document.createElement('span');
        span.style.color = color;
        try {
            MarketingApp.savedRange.surroundContents(span);
            // Re-insert the range into the document
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
    const selection = window.getSelection();
    if (selection.rangeCount > 0 && !selection.isCollapsed) {
        document.execCommand('backColor', false, color);
    } else {
        element.style.backgroundColor = color;
    }
    saveElementToSlideState(element);
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

function moveElementLayerUp() {
    if (!MarketingApp.selectedElement) {
        showNotification('No element selected', 'warning');
        return;
    }
    
    const currentSlide = document.querySelector('.carousel-slide.active');
    const allElements = Array.from(currentSlide.querySelectorAll('.canvas-element'));
    const currentZ = parseInt(MarketingApp.selectedElement.style.zIndex) || 0;
    
    // Get all z-indexes except current element's
    const otherZIndexes = allElements
        .filter(el => el !== MarketingApp.selectedElement)
        .map(el => parseInt(el.style.zIndex) || 0);
    
    // Find next higher z-index
    const higherZIndexes = otherZIndexes.filter(z => z > currentZ).sort((a, b) => a - b);
    
    if (higherZIndexes.length > 0) {
        // Swap with the element directly above
        const targetZ = higherZIndexes[0];
        const elementAbove = allElements.find(el => el !== MarketingApp.selectedElement && parseInt(el.style.zIndex) === targetZ);
        
        if (elementAbove) {
            elementAbove.style.zIndex = currentZ;
            saveElementToSlideState(elementAbove);
        }
        MarketingApp.selectedElement.style.zIndex = targetZ;
    } else {
        // Already at top
        showNotification('Already at top layer', 'info');
        return;
    }
    
    saveElementToSlideState(MarketingApp.selectedElement);
    showNotification('Layer swapped (z-index: ' + MarketingApp.selectedElement.style.zIndex + ')', 'success');
}

function moveElementLayerDown() {
    if (!MarketingApp.selectedElement) {
        showNotification('No element selected', 'warning');
        return;
    }
    
    const currentSlide = document.querySelector('.carousel-slide.active');
    const allElements = Array.from(currentSlide.querySelectorAll('.canvas-element'));
    const currentZ = parseInt(MarketingApp.selectedElement.style.zIndex) || 0;
    
    // Get all z-indexes except current element's
    const otherZIndexes = allElements
        .filter(el => el !== MarketingApp.selectedElement)
        .map(el => parseInt(el.style.zIndex) || 0);
    
    // Find next lower z-index
    const lowerZIndexes = otherZIndexes.filter(z => z < currentZ).sort((a, b) => b - a);
    
    if (lowerZIndexes.length > 0) {
        // Swap with the element directly below
        const targetZ = lowerZIndexes[0];
        const elementBelow = allElements.find(el => el !== MarketingApp.selectedElement && parseInt(el.style.zIndex) === targetZ);
        
        if (elementBelow) {
            elementBelow.style.zIndex = currentZ;
            saveElementToSlideState(elementBelow);
        }
        MarketingApp.selectedElement.style.zIndex = targetZ;
    } else {
        // Already at bottom
        showNotification('Already at bottom layer', 'info');
        return;
    }
    
    saveElementToSlideState(MarketingApp.selectedElement);
    showNotification('Layer swapped (z-index: ' + MarketingApp.selectedElement.style.zIndex + ')', 'success');
}

function showLayerManager() {
    let modal = document.getElementById('layerManagerModal');
    if (!modal) {
        const modalHtml = `
            <div id="layerManagerModal" class="modal">
                <div class="modal-content">
                    <div class="modal-header">
                        <h2><i class="fas fa-layer-group"></i> Layer Manager</h2>
                        <span class="close" style="font-size: 2rem;" onclick="document.getElementById('layerManagerModal').style.display='none'">&times;</span>
                    </div>
                    <div class="modal-body" style="max-height: 500px; overflow-y: auto; padding: 20px;">
                        <div id="layerManagerList"></div>
                    </div>
                </div>
            </div>
        `;
        document.body.insertAdjacentHTML('beforeend', modalHtml);
        modal = document.getElementById('layerManagerModal');
    }
    updateLayerManagerList();
    modal.style.display = 'flex';
}

function updateLayerManagerList() {
    const listEl = document.getElementById('layerManagerList');
    if (!listEl) return;
    
    const currentSlide = document.querySelector('.carousel-slide.active');
    if (!currentSlide) {
        listEl.innerHTML = '<p style="text-align: center; color: #9ca3af; padding: 20px;">No active slide</p>';
        return;
    }
    
    const elements = Array.from(currentSlide.querySelectorAll('.canvas-element'));
    if (elements.length === 0) {
        listEl.innerHTML = '<p style="text-align: center; color: #9ca3af; padding: 20px;">No elements on this slide</p>';
        return;
    }
    
    const sortedElements = elements.sort((a, b) => {
        const zA = parseInt(a.style.zIndex) || 0;
        const zB = parseInt(b.style.zIndex) || 0;
        return zB - zA;
    });
    
    listEl.innerHTML = sortedElements.map((el, idx) => {
        const type = el.classList.contains('text-element') ? 'Text' : 
                     el.classList.contains('article-element') ? 'Article' : 'Image';
        const zIndex = parseInt(el.style.zIndex) || 0;
        
        return `
            <div class="layer-item" style="padding: 16px; border: 1px solid #e5e7eb; border-radius: 8px; margin-bottom: 12px; background: white; display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <strong style="font-size: 14px; color: #111827;">${type}</strong> 
                    <span style="color: #6b7280; font-size: 13px; margin-left: 8px;">Layer: ${zIndex}</span>
                </div>
                <div style="display: flex; gap: 8px;">
                    <button onclick="setElementLayerByElement(this, 'top')" class="btn" style="padding: 6px 12px; font-size: 12px;" title="Move to top">
                        <i class="fas fa-angle-double-up"></i>
                    </button>
                    <button onclick="setElementLayerByElement(this, 'up')" class="btn" style="padding: 6px 12px; font-size: 12px;" title="Move up">
                        <i class="fas fa-angle-up"></i>
                    </button>
                    <button onclick="setElementLayerByElement(this, 'down')" class="btn" style="padding: 6px 12px; font-size: 12px;" title="Move down">
                        <i class="fas fa-angle-down"></i>
                    </button>
                    <button onclick="setElementLayerByElement(this, 'bottom')" class="btn" style="padding: 6px 12px; font-size: 12px;" title="Move to bottom">
                        <i class="fas fa-angle-double-down"></i>
                    </button>
                    <button onclick="selectElementFromLayer(this)" class="btn btn-primary" style="padding: 6px 12px; font-size: 12px;" title="Select">
                        <i class="fas fa-mouse-pointer"></i>
                    </button>
                </div>
            </div>
        `;
    }).join('');
}

function setElementLayerByElement(btn, action) {
    const layerItem = btn.closest('.layer-item');
    const layerList = document.getElementById('layerManagerList');
    const items = Array.from(layerList.querySelectorAll('.layer-item'));
    const index = items.indexOf(layerItem);
    
    const currentSlide = document.querySelector('.carousel-slide.active');
    const elements = Array.from(currentSlide.querySelectorAll('.canvas-element')).sort((a, b) => {
        const zA = parseInt(a.style.zIndex) || 0;
        const zB = parseInt(b.style.zIndex) || 0;
        return zB - zA;
    });
    
    const element = elements[index];
    if (!element) return;
    
    const allElements = Array.from(currentSlide.querySelectorAll('.canvas-element'));
    const currentZ = parseInt(element.style.zIndex) || 0;
    
    // Get all unique z-index values sorted
    const allZIndexes = [...new Set(allElements.map(el => parseInt(el.style.zIndex) || 0))].sort((a, b) => a - b);
    const currentPosition = allZIndexes.indexOf(currentZ);
    
    switch(action) {
        case 'top':
            const maxZ = Math.max(0, ...allElements.map(el => parseInt(el.style.zIndex) || 0));
            element.style.zIndex = maxZ + 1;
            break;
        case 'bottom':
            element.style.zIndex = 0;
            break;
        case 'up':
            // Move to next higher z-index position, or create new one above current
            if (currentPosition < allZIndexes.length - 1) {
                element.style.zIndex = allZIndexes[currentPosition + 1] + 1;
            } else {
                element.style.zIndex = currentZ + 1;
            }
            break;
        case 'down':
            // Move to next lower z-index position, or create new one below current
            if (currentPosition > 0) {
                element.style.zIndex = allZIndexes[currentPosition - 1] - 1;
                if (element.style.zIndex < 0) element.style.zIndex = 0;
            } else if (currentZ > 0) {
                element.style.zIndex = currentZ - 1;
            }
            break;
    }
    
    saveElementToSlideState(element);
    showNotification('Layer updated (z-index: ' + element.style.zIndex + ')', 'success');
    
    // Force refresh the list after a short delay to show new order
    setTimeout(() => updateLayerManagerList(), 100);
}

function selectElementFromLayer(btn) {
    const layerItem = btn.closest('.layer-item');
    const layerList = document.getElementById('layerManagerList');
    const items = Array.from(layerList.querySelectorAll('.layer-item'));
    const index = items.indexOf(layerItem);
    
    const currentSlide = document.querySelector('.carousel-slide.active');
    const elements = Array.from(currentSlide.querySelectorAll('.canvas-element')).sort((a, b) => {
        const zA = parseInt(a.style.zIndex) || 0;
        const zB = parseInt(b.style.zIndex) || 0;
        return zB - zA;
    });
    
    const element = elements[index];
    if (element) {
        // Close modal
        document.getElementById('layerManagerModal').style.display = 'none';
        
        // Select the element
        document.querySelectorAll('.canvas-element').forEach(el => el.classList.remove('selected'));
        element.classList.add('selected');
        MarketingApp.selectedElement = element;
        
        // Scroll element into view
        element.scrollIntoView({ behavior: 'smooth', block: 'center' });
        
        showNotification('Element selected', 'success');
    }
}

// Close HTML editor when clicking outside
document.addEventListener('click', function(e) {
    const toolbar = document.getElementById('htmlEditorToolbar');
    if (!toolbar || toolbar.style.display === 'none') return;
    
    // Check if click is outside toolbar and outside any article element
    if (!e.target.closest('#htmlEditorToolbar') && 
        !e.target.closest('.article-element') && 
        !e.target.closest('.text-element')) {
        closeHtmlEditor();
    }
});

console.log('Marketing Module v2.0 with HTML Editor Toolbar loaded successfully');
