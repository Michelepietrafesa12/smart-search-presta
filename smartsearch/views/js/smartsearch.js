/**
 * SmartSearch 2.0 - Fullscreen Overlay Search
 * UI/UX ottimizzata simile a Doofinder
 */

(function() {
    'use strict';

    // Config
    const config = typeof smartsearch_config !== 'undefined' ? smartsearch_config : {};
    const t = config.translations || {};

    // State
    let overlay = null;
    let searchInput = null;
    let currentQuery = '';
    let currentFilters = {};
    let debounceTimer = null;
    let recentSearches = [];
    let lastResults = null;
    let voiceRecognition = null;
    let isListening = false;

    // Icons SVG
    const icons = {
        search: '<svg viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M15.5 14h-.79l-.28-.27C15.41 12.59 16 11.11 16 9.5 16 5.91 13.09 3 9.5 3S3 5.91 3 9.5 5.91 16 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>',
        close: '<svg viewBox="0 0 24 24" width="24" height="24"><path fill="currentColor" d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>',
        chevron: '<svg viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M7.41 8.59L12 13.17l4.59-4.58L18 10l-6 6-6-6 1.41-1.41z"/></svg>',
        check: '<svg viewBox="0 0 24 24" width="12" height="12"><path fill="currentColor" d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>',
        mic: '<svg viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M12 14c1.66 0 3-1.34 3-3V5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3zm-1 1.93c-3.94-.49-7-3.85-7-7.93h2c0 3.31 2.69 6 6 6s6-2.69 6-6h2c0 4.08-3.06 7.44-7 7.93V19h4v2H8v-2h4v-3.07z"/></svg>',
        cart: '<svg viewBox="0 0 24 24" width="18" height="18"><path fill="currentColor" d="M11 9h2V6h3V4h-3V1h-2v3H8v2h3v3zm-4 9c-1.1 0-1.99.9-1.99 2S5.9 22 7 22s2-.9 2-2-.9-2-2-2zm10 0c-1.1 0-1.99.9-1.99 2s.89 2 1.99 2 2-.9 2-2-.9-2-2-2zm-9.83-3.25l.03-.12.9-1.63h7.45c.75 0 1.41-.41 1.75-1.03l3.86-7.01L19.42 4h-.01l-1.1 2-2.76 5H8.53l-.13-.27L6.16 6l-.95-2-.94-2H1v2h2l3.6 7.59-1.35 2.45c-.16.28-.25.61-.25.96 0 1.1.9 2 2 2h12v-2H7.42c-.13 0-.25-.11-.25-.25z"/></svg>',
        filter: '<svg viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M10 18h4v-2h-4v2zM3 6v2h18V6H3zm3 7h12v-2H6v2z"/></svg>',
        noResults: '<svg viewBox="0 0 24 24" width="80" height="80"><path fill="currentColor" d="M15.5 14h-.79l-.28-.27C15.41 12.59 16 11.11 16 9.5 16 5.91 13.09 3 9.5 3S3 5.91 3 9.5 5.91 16 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>'
    };

    /**
     * Init
     */
    function init() {
        loadRecentSearches();
        createOverlay();
        bindTriggers();

        if (config.voice_enabled && 'webkitSpeechRecognition' in window) {
            initVoiceSearch();
        }

        console.log('SmartSearch 2.0 Fullscreen: Inizializzato');
    }

    /**
     * Load recent searches from localStorage
     */
    function loadRecentSearches() {
        try {
            const saved = localStorage.getItem('smartsearch_recent');
            if (saved) {
                recentSearches = JSON.parse(saved).slice(0, 5);
            }
        } catch (e) {}
    }

    /**
     * Save recent search
     */
    function saveRecentSearch(query) {
        if (!query || query.length < 2) return;

        recentSearches = recentSearches.filter(s => s !== query);
        recentSearches.unshift(query);
        recentSearches = recentSearches.slice(0, 5);

        try {
            localStorage.setItem('smartsearch_recent', JSON.stringify(recentSearches));
        } catch (e) {}

        renderTags();
    }

    /**
     * Remove recent search
     */
    function removeRecentSearch(query) {
        recentSearches = recentSearches.filter(s => s !== query);
        try {
            localStorage.setItem('smartsearch_recent', JSON.stringify(recentSearches));
        } catch (e) {}
        renderTags();
    }

    /**
     * Create overlay HTML
     */
    function createOverlay() {
        overlay = document.createElement('div');
        overlay.className = 'smartsearch-overlay';
        overlay.innerHTML = `
            <div class="smartsearch-header">
                <div class="smartsearch-search-box">
                    ${icons.search}
                    <input type="text" class="smartsearch-search-input" placeholder="${t.search_placeholder || 'Cerca prodotti...'}" autocomplete="off">
                    <button type="button" class="smartsearch-clear-input">${icons.close}</button>
                    ${config.voice_enabled ? `<button type="button" class="smartsearch-voice-btn" title="${t.voice_search || 'Ricerca vocale'}">${icons.mic}</button>` : ''}
                </div>
                <button type="button" class="smartsearch-close-btn" title="Chiudi">${icons.close}</button>
            </div>
            <div class="smartsearch-tags"></div>
            <div class="smartsearch-content">
                <aside class="smartsearch-sidebar"></aside>
                <main class="smartsearch-main">
                    <div class="smartsearch-initial">
                        ${icons.search.replace('width="20"', 'width="100"').replace('height="20"', 'height="100"')}
                        <h3>${t.search_placeholder || 'Cerca prodotti...'}</h3>
                        <p>Inizia a digitare per cercare nel catalogo</p>
                    </div>
                </main>
            </div>
        `;

        document.body.appendChild(overlay);

        // Get elements
        searchInput = overlay.querySelector('.smartsearch-search-input');

        // Bind events
        searchInput.addEventListener('input', handleInput);
        searchInput.addEventListener('keydown', handleKeydown);

        overlay.querySelector('.smartsearch-close-btn').addEventListener('click', closeOverlay);
        overlay.querySelector('.smartsearch-clear-input').addEventListener('click', clearInput);

        if (config.voice_enabled) {
            const voiceBtn = overlay.querySelector('.smartsearch-voice-btn');
            if (voiceBtn) voiceBtn.addEventListener('click', toggleVoiceSearch);
        }

        // Close on ESC
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && overlay.classList.contains('active')) {
                closeOverlay();
            }
        });

        // Render initial tags
        renderTags();
    }

    /**
     * Bind triggers (existing search inputs)
     */
    function bindTriggers() {
        // Selettori comuni per vari temi PrestaShop
        const selectors = [
            // PrestaShop Classic
            '#search_widget input[type="text"]',
            '#search_widget input[type="search"]',
            '.search-widget input[type="text"]',
            '.search-widget input[type="search"]',
            // Nomi input comuni
            'input[name="s"]',
            'input[name="search_query"]',
            'input[name="search"]',
            // ID comuni
            '#search_query',
            '#search_query_top',
            '#search-query',
            '#searchbox input',
            // Classi comuni
            '.search-input',
            '.search_query',
            '.search-field',
            '.input-search',
            // Form di ricerca generici
            'form[action*="search"] input[type="text"]',
            'form[action*="search"] input[type="search"]',
            'form.search input',
            '.search-form input',
            // Header search
            'header input[type="search"]',
            'header input[type="text"][placeholder*="erca"]',
            'header input[type="text"][placeholder*="earch"]',
            // SmartSearch
            '#smartsearch-input',
            '.smartsearch-trigger'
        ];

        let found = false;

        selectors.forEach(selector => {
            try {
                document.querySelectorAll(selector).forEach(el => {
                    // Evita di bindare l'input dell'overlay stesso
                    if (el.classList.contains('smartsearch-search-input')) return;

                    found = true;
                    console.log('SmartSearch: Trovato input', selector, el);

                    // Rimuovi eventi esistenti e aggiungi i nostri
                    el.addEventListener('focus', handleTriggerFocus, true);
                    el.addEventListener('click', handleTriggerClick, true);

                    // Marca come gestito
                    el.setAttribute('data-smartsearch-bound', 'true');
                });
            } catch (e) {}
        });

        if (!found) {
            console.warn('SmartSearch: Nessun campo di ricerca trovato! Selettori provati:', selectors);
            // Fallback: cerca qualsiasi input che sembri una ricerca
            document.querySelectorAll('input').forEach(el => {
                const placeholder = (el.placeholder || '').toLowerCase();
                const name = (el.name || '').toLowerCase();
                const id = (el.id || '').toLowerCase();

                if (placeholder.includes('cerca') || placeholder.includes('search') ||
                    name.includes('search') || name.includes('query') ||
                    id.includes('search') || id.includes('query')) {

                    if (!el.classList.contains('smartsearch-search-input')) {
                        console.log('SmartSearch: Trovato input via fallback', el);
                        el.addEventListener('focus', handleTriggerFocus, true);
                        el.addEventListener('click', handleTriggerClick, true);
                    }
                }
            });
        }
    }

    function handleTriggerFocus(e) {
        e.preventDefault();
        e.stopPropagation();
        e.target.blur();
        openOverlay();
    }

    function handleTriggerClick(e) {
        e.preventDefault();
        e.stopPropagation();
        openOverlay();
    }

    /**
     * Open overlay
     */
    function openOverlay() {
        overlay.classList.add('active');
        document.body.style.overflow = 'hidden';
        setTimeout(() => searchInput.focus(), 100);
    }

    /**
     * Close overlay
     */
    function closeOverlay() {
        overlay.classList.remove('active');
        document.body.style.overflow = '';
        currentQuery = '';
        currentFilters = {};
        searchInput.value = '';
        renderInitialState();
    }

    /**
     * Clear input
     */
    function clearInput() {
        searchInput.value = '';
        currentQuery = '';
        overlay.querySelector('.smartsearch-clear-input').classList.remove('visible');
        renderInitialState();
        searchInput.focus();
    }

    /**
     * Handle input
     */
    function handleInput(e) {
        const query = e.target.value.trim();

        // Toggle clear button
        overlay.querySelector('.smartsearch-clear-input').classList.toggle('visible', query.length > 0);

        if (debounceTimer) clearTimeout(debounceTimer);

        if (query.length < (config.min_chars || 2)) {
            if (query.length === 0) {
                renderInitialState();
            }
            return;
        }

        debounceTimer = setTimeout(() => {
            performSearch(query);
        }, config.debounce_time || 300);
    }

    /**
     * Handle keydown
     */
    function handleKeydown(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            if (currentQuery) {
                saveRecentSearch(currentQuery);
            }
        }
    }

    /**
     * Perform search
     */
    function performSearch(query, filters = {}) {
        currentQuery = query;
        currentFilters = filters;

        showLoader();

        let url = config.ajax_url + '?q=' + encodeURIComponent(query);

        if (filters.category && filters.category.length) url += '&category=' + filters.category.join(',');
        if (filters.manufacturer && filters.manufacturer.length) url += '&manufacturer=' + filters.manufacturer.join(',');
        if (filters.price_min) url += '&price_min=' + filters.price_min;
        if (filters.price_max) url += '&price_max=' + filters.price_max;
        if (filters.in_stock) url += '&in_stock=1';

        fetch(url, {
            method: 'GET',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(response => response.json())
        .then(data => {
            lastResults = data;
            renderResults(data);
            saveRecentSearch(query);
        })
        .catch(error => {
            console.error('SmartSearch error:', error);
            renderNoResults();
        });
    }

    /**
     * Render tags
     */
    function renderTags() {
        const container = overlay.querySelector('.smartsearch-tags');

        if (recentSearches.length === 0) {
            container.style.display = 'none';
            return;
        }

        container.style.display = 'flex';
        container.innerHTML = recentSearches.map(search => `
            <div class="smartsearch-tag" data-query="${escapeHtml(search)}">
                <span>${escapeHtml(search)}</span>
                <span class="smartsearch-tag-remove" data-remove="${escapeHtml(search)}">&times;</span>
            </div>
        `).join('');

        // Bind events
        container.querySelectorAll('.smartsearch-tag').forEach(tag => {
            tag.addEventListener('click', (e) => {
                if (e.target.classList.contains('smartsearch-tag-remove')) {
                    e.stopPropagation();
                    removeRecentSearch(e.target.dataset.remove);
                } else {
                    const query = tag.dataset.query;
                    searchInput.value = query;
                    overlay.querySelector('.smartsearch-clear-input').classList.add('visible');
                    performSearch(query);
                }
            });
        });
    }

    /**
     * Render initial state
     */
    function renderInitialState() {
        const main = overlay.querySelector('.smartsearch-main');
        const sidebar = overlay.querySelector('.smartsearch-sidebar');

        sidebar.innerHTML = '';
        main.innerHTML = `
            <div class="smartsearch-initial">
                ${icons.search.replace('width="20"', 'width="100"').replace('height="20"', 'height="100"')}
                <h3>${t.search_placeholder || 'Cerca prodotti...'}</h3>
                <p>Inizia a digitare per cercare nel catalogo</p>
            </div>
        `;
    }

    /**
     * Show loader
     */
    function showLoader() {
        const main = overlay.querySelector('.smartsearch-main');
        main.innerHTML = `
            <div class="smartsearch-loader">
                <div class="smartsearch-spinner"></div>
                <span>Ricerca in corso...</span>
            </div>
        `;
    }

    /**
     * Render results
     */
    function renderResults(data) {
        const main = overlay.querySelector('.smartsearch-main');
        const sidebar = overlay.querySelector('.smartsearch-sidebar');

        const hasProducts = data.products && data.products.length > 0;

        if (!hasProducts) {
            renderNoResults(data);
            return;
        }

        // Render sidebar filters
        if (config.facets_enabled && data.facets) {
            renderFilters(sidebar, data.facets);
        } else {
            sidebar.innerHTML = '';
        }

        // Render main content
        let html = '';

        // Results header
        html += `
            <div class="smartsearch-results-header">
                <div class="smartsearch-results-count">
                    <strong>${data.total || data.products.length}</strong> ${t.products_found || 'risultati trovati'}
                </div>
                <div class="smartsearch-results-sort">
                    <span>${t.sort_by || 'Ordinato per'}:</span>
                    <select>
                        <option value="relevance">${t.relevance || 'Rilevanza'}</option>
                        <option value="price_asc">${t.price_low || 'Prezzo crescente'}</option>
                        <option value="price_desc">${t.price_high || 'Prezzo decrescente'}</option>
                    </select>
                </div>
            </div>
        `;

        // Mobile filter toggle
        html += `
            <button type="button" class="smartsearch-filter-toggle-mobile">
                ${icons.filter}
                <span>${t.filters || 'Filtri'}</span>
            </button>
        `;

        // Banner
        if (config.banners_enabled && data.banners && data.banners.length > 0) {
            const banner = data.banners[0];
            html += `<a href="${banner.link || '#'}" class="smartsearch-banner">${escapeHtml(banner.name || 'Offerta speciale')}</a>`;
        }

        // Products grid
        html += '<div class="smartsearch-products-grid">';

        data.products.forEach((product, index) => {
            const discount = product.price_old ? calculateDiscount(product.price_old_raw, product.price_raw) : 0;

            html += `
                <a href="${product.url}" class="smartsearch-product-card" data-product-id="${product.id}" data-index="${index}">
                    ${discount > 0 ? `<span class="smartsearch-discount-badge">${discount}%</span>` : ''}
                    <div class="smartsearch-product-image">
                        <img src="${product.image}" alt="${escapeHtml(product.name)}" loading="lazy">
                    </div>
                    <div class="smartsearch-product-info">
                        <div class="smartsearch-product-name">${highlightText(product.name, currentQuery)}</div>
                        <div class="smartsearch-product-prices">
                            ${product.price_old ? `<span class="smartsearch-product-old-price">${product.price_old}</span>` : ''}
                            <span class="smartsearch-product-price">${product.price}</span>
                        </div>
                    </div>
                    <button type="button" class="smartsearch-add-cart" title="${t.add_to_cart || 'Aggiungi al carrello'}">
                        ${icons.cart}
                    </button>
                </a>
            `;
        });

        html += '</div>';

        main.innerHTML = html;

        // Bind events
        bindResultEvents();
    }

    /**
     * Render filters
     */
    function renderFilters(container, facets) {
        let html = '';

        // Price filter
        if (facets.price_range && facets.price_range.max > 0) {
            const min = Math.floor(facets.price_range.min);
            const max = Math.ceil(facets.price_range.max);
            html += `
                <div class="smartsearch-filter-section">
                    <div class="smartsearch-filter-header">
                        <span class="smartsearch-filter-title">${t.price || 'Prezzo'}</span>
                        <span class="smartsearch-filter-toggle">${icons.chevron}</span>
                    </div>
                    <div class="smartsearch-filter-body">
                        <div class="smartsearch-price-slider">
                            <div class="smartsearch-price-values">
                                <span class="smartsearch-price-badge">${min} ${config.currency_sign || '€'}</span>
                                <span class="smartsearch-price-badge">${max} ${config.currency_sign || '€'}</span>
                            </div>
                            <div class="smartsearch-price-range">
                                <div class="smartsearch-price-range-fill" style="left: 0%; width: 100%;"></div>
                                <div class="smartsearch-price-handle" style="left: 0%;" data-handle="min"></div>
                                <div class="smartsearch-price-handle" style="left: 100%;" data-handle="max"></div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
        }

        // Manufacturer filter
        if (facets.manufacturers && facets.manufacturers.length > 0) {
            html += `
                <div class="smartsearch-filter-section">
                    <div class="smartsearch-filter-header">
                        <span class="smartsearch-filter-title">${t.brand || 'Marca'}</span>
                        <span class="smartsearch-filter-toggle">${icons.chevron}</span>
                    </div>
                    <div class="smartsearch-filter-body">
                        <div class="smartsearch-filter-search">
                            ${icons.search}
                            <input type="text" placeholder="${t.search_options || 'Cerca opzioni'}">
                        </div>
                        <div class="smartsearch-filter-options">
                            ${facets.manufacturers.map(m => `
                                <div class="smartsearch-filter-option ${currentFilters.manufacturer && currentFilters.manufacturer.includes(m.id_manufacturer) ? 'selected' : ''}"
                                     data-filter="manufacturer" data-value="${m.id_manufacturer}">
                                    <span class="smartsearch-filter-checkbox">${icons.check}</span>
                                    <span class="smartsearch-filter-label">${escapeHtml(m.name)}</span>
                                    <span class="smartsearch-filter-count">${m.count}</span>
                                </div>
                            `).join('')}
                        </div>
                    </div>
                </div>
            `;
        }

        // Category filter
        if (facets.categories && facets.categories.length > 0) {
            html += `
                <div class="smartsearch-filter-section">
                    <div class="smartsearch-filter-header">
                        <span class="smartsearch-filter-title">${t.categories || 'Categorie'}</span>
                        <span class="smartsearch-filter-toggle">${icons.chevron}</span>
                    </div>
                    <div class="smartsearch-filter-body">
                        <div class="smartsearch-filter-tags">
                            ${facets.categories.map(c => `
                                <span class="smartsearch-filter-tag ${currentFilters.category && currentFilters.category.includes(c.id_category) ? 'selected' : ''}"
                                      data-filter="category" data-value="${c.id_category}">
                                    ${escapeHtml(c.name)}
                                </span>
                            `).join('')}
                        </div>
                    </div>
                </div>
            `;
        }

        // Stock filter
        html += `
            <div class="smartsearch-filter-section">
                <div class="smartsearch-filter-header">
                    <span class="smartsearch-filter-title">${t.availability || 'Disponibilità'}</span>
                    <span class="smartsearch-filter-toggle">${icons.chevron}</span>
                </div>
                <div class="smartsearch-filter-body">
                    <div class="smartsearch-filter-option ${currentFilters.in_stock ? 'selected' : ''}" data-filter="in_stock" data-value="1">
                        <span class="smartsearch-filter-checkbox">${icons.check}</span>
                        <span class="smartsearch-filter-label">${t.in_stock || 'in stock'}</span>
                    </div>
                    <div class="smartsearch-filter-option" data-filter="out_of_stock" data-value="1">
                        <span class="smartsearch-filter-checkbox">${icons.check}</span>
                        <span class="smartsearch-filter-label">${t.out_of_stock || 'out of stock'}</span>
                    </div>
                </div>
            </div>
        `;

        container.innerHTML = html;

        // Bind filter events
        bindFilterEvents(container);
    }

    /**
     * Bind filter events
     */
    function bindFilterEvents(container) {
        // Collapsible sections
        container.querySelectorAll('.smartsearch-filter-header').forEach(header => {
            header.addEventListener('click', () => {
                header.parentElement.classList.toggle('collapsed');
            });
        });

        // Filter options (checkboxes)
        container.querySelectorAll('.smartsearch-filter-option').forEach(option => {
            option.addEventListener('click', () => {
                option.classList.toggle('selected');
                applyFilters();
            });
        });

        // Filter tags
        container.querySelectorAll('.smartsearch-filter-tag').forEach(tag => {
            tag.addEventListener('click', () => {
                tag.classList.toggle('selected');
                applyFilters();
            });
        });

        // Filter search
        container.querySelectorAll('.smartsearch-filter-search input').forEach(input => {
            input.addEventListener('input', (e) => {
                const query = e.target.value.toLowerCase();
                const options = input.closest('.smartsearch-filter-body').querySelectorAll('.smartsearch-filter-option');
                options.forEach(opt => {
                    const label = opt.querySelector('.smartsearch-filter-label').textContent.toLowerCase();
                    opt.style.display = label.includes(query) ? '' : 'none';
                });
            });
        });
    }

    /**
     * Apply filters
     */
    function applyFilters() {
        const sidebar = overlay.querySelector('.smartsearch-sidebar');
        const filters = {};

        // Categories
        const selectedCategories = sidebar.querySelectorAll('.smartsearch-filter-tag.selected[data-filter="category"]');
        if (selectedCategories.length > 0) {
            filters.category = Array.from(selectedCategories).map(el => parseInt(el.dataset.value));
        }

        // Manufacturers
        const selectedManufacturers = sidebar.querySelectorAll('.smartsearch-filter-option.selected[data-filter="manufacturer"]');
        if (selectedManufacturers.length > 0) {
            filters.manufacturer = Array.from(selectedManufacturers).map(el => parseInt(el.dataset.value));
        }

        // In stock
        const inStock = sidebar.querySelector('.smartsearch-filter-option.selected[data-filter="in_stock"]');
        if (inStock) {
            filters.in_stock = true;
        }

        performSearch(currentQuery, filters);
    }

    /**
     * Bind result events
     */
    function bindResultEvents() {
        const main = overlay.querySelector('.smartsearch-main');

        // Product clicks
        main.querySelectorAll('.smartsearch-product-card').forEach(card => {
            card.addEventListener('click', (e) => {
                if (e.target.closest('.smartsearch-add-cart')) {
                    e.preventDefault();
                    // Add to cart logic here
                    console.log('Add to cart:', card.dataset.productId);
                }
                trackProductClick(card.dataset.productId, card.dataset.index);
            });
        });

        // Mobile filter toggle
        const filterToggle = main.querySelector('.smartsearch-filter-toggle-mobile');
        if (filterToggle) {
            filterToggle.addEventListener('click', () => {
                const sidebar = overlay.querySelector('.smartsearch-sidebar');
                sidebar.classList.toggle('mobile-visible');
            });
        }

        // Sort select
        const sortSelect = main.querySelector('.smartsearch-results-sort select');
        if (sortSelect) {
            sortSelect.addEventListener('change', (e) => {
                // Sort logic here
                console.log('Sort by:', e.target.value);
            });
        }
    }

    /**
     * Render no results
     */
    function renderNoResults(data) {
        const main = overlay.querySelector('.smartsearch-main');
        const sidebar = overlay.querySelector('.smartsearch-sidebar');

        sidebar.innerHTML = '';

        let html = `
            <div class="smartsearch-no-results">
                ${icons.noResults}
                <h3>${t.no_results || 'Nessun risultato trovato'}</h3>
                <p>${t.try_different || 'Prova con termini di ricerca diversi'}</p>
        `;

        if (data && data.did_you_mean && data.did_you_mean.length > 0) {
            html += `<p style="margin-top: 16px;">${t.did_you_mean || 'Forse cercavi'}:</p>`;
            html += '<div style="display: flex; gap: 8px; flex-wrap: wrap; justify-content: center; margin-top: 8px;">';
            data.did_you_mean.forEach(s => {
                html += `<span class="smartsearch-tag smartsearch-suggestion" data-query="${escapeHtml(s.term)}">${escapeHtml(s.term)}</span>`;
            });
            html += '</div>';
        }

        html += '</div>';
        main.innerHTML = html;

        // Bind suggestion clicks
        main.querySelectorAll('.smartsearch-suggestion').forEach(el => {
            el.addEventListener('click', () => {
                const query = el.dataset.query;
                searchInput.value = query;
                overlay.querySelector('.smartsearch-clear-input').classList.add('visible');
                performSearch(query);
            });
        });
    }

    /**
     * Track product click
     */
    function trackProductClick(productId, position) {
        if (!config.track_url) return;

        fetch(`${config.track_url}?action=click&product_id=${productId}&position=${position}&query=${encodeURIComponent(currentQuery)}`, {
            method: 'GET',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).catch(() => {});
    }

    /**
     * Calculate discount percentage
     */
    function calculateDiscount(oldPrice, newPrice) {
        if (!oldPrice || !newPrice || oldPrice <= newPrice) return 0;
        return Math.round((1 - newPrice / oldPrice) * 100);
    }

    /**
     * Voice search
     */
    function initVoiceSearch() {
        voiceRecognition = new webkitSpeechRecognition();
        voiceRecognition.continuous = false;
        voiceRecognition.interimResults = false;
        voiceRecognition.lang = document.documentElement.lang || 'it-IT';

        voiceRecognition.onresult = (event) => {
            const transcript = event.results[0][0].transcript;
            searchInput.value = transcript;
            overlay.querySelector('.smartsearch-clear-input').classList.add('visible');
            performSearch(transcript);
            stopVoiceSearch();
        };

        voiceRecognition.onerror = () => stopVoiceSearch();
        voiceRecognition.onend = () => stopVoiceSearch();
    }

    function toggleVoiceSearch() {
        if (isListening) {
            stopVoiceSearch();
        } else {
            startVoiceSearch();
        }
    }

    function startVoiceSearch() {
        if (!voiceRecognition) return;
        isListening = true;
        voiceRecognition.start();
        const btn = overlay.querySelector('.smartsearch-voice-btn');
        if (btn) btn.classList.add('listening');
    }

    function stopVoiceSearch() {
        if (!voiceRecognition) return;
        isListening = false;
        try { voiceRecognition.stop(); } catch(e) {}
        const btn = overlay.querySelector('.smartsearch-voice-btn');
        if (btn) btn.classList.remove('listening');
    }

    /**
     * Highlight text
     */
    function highlightText(text, query) {
        if (!config.highlight || !query) return escapeHtml(text);

        const escaped = escapeHtml(text);
        const words = query.split(' ').filter(w => w.length >= 2);

        let result = escaped;
        words.forEach(word => {
            const regex = new RegExp('(' + escapeRegex(word) + ')', 'gi');
            result = result.replace(regex, '<mark>$1</mark>');
        });

        return result;
    }

    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function escapeRegex(string) {
        return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    // Init on DOM ready
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
