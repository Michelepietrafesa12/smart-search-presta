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
    let activeBanners = [];

    // Pagination state
    let currentOffset = 0;
    let isLoadingMore = false;
    let hasMoreResults = false;
    let totalResultsCount = 0;
    const RESULTS_PER_PAGE = 24;

    // Analytics
    const sessionId = getOrCreateSessionId();

    /**
     * Get or create session ID for analytics
     */
    function getOrCreateSessionId() {
        let sid = sessionStorage.getItem('smartsearch_session_id');
        if (!sid) {
            sid = 'ss_' + Date.now() + '_' + Math.random().toString(36).substr(2, 9);
            sessionStorage.setItem('smartsearch_session_id', sid);
        }
        // Salva anche in cookie per PHP (conversioni)
        setCookie('smartsearch_session_id', sid, 30);
        return sid;
    }

    /**
     * Set cookie
     */
    function setCookie(name, value, days) {
        const expires = new Date(Date.now() + days * 864e5).toUTCString();
        document.cookie = name + '=' + encodeURIComponent(value) + '; expires=' + expires + '; path=/; SameSite=Lax';
    }

    /**
     * Get device type
     */
    function getDeviceType() {
        const width = window.innerWidth;
        if (width < 768) return 'mobile';
        if (width < 1024) return 'tablet';
        return 'desktop';
    }

    /**
     * Send analytics event via PrestaShop proxy (avoids CORS issues)
     */
    function sendAnalyticsEvent(eventType, data) {
        // Skip if webhook not configured
        if (!config.analytics_webhook_url) {
            return;
        }

        const payload = {
            event_type: eventType,
            shop_id: config.shop_id || window.location.hostname,
            session_id: sessionId,
            user_agent: navigator.userAgent,
            device_type: getDeviceType(),
            timestamp: new Date().toISOString(),
            page_url: window.location.href,
            ...data
        };

        // Send to PrestaShop proxy (non-blocking)
        fetch(config.ajax_url + '?ajax=1&action=analytics', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
            keepalive: true
        }).catch(() => {
            // Silently ignore analytics errors - never block user experience
        });
    }

    /**
     * Track search event
     * Salva storico completo delle ricerche per attribuzione conversioni
     */
    function trackSearch(query, resultsCount) {
        if (query && resultsCount > 0) {
            // Salva ultima ricerca (retrocompatibilità)
            setCookie('smartsearch_last_query', query, 7);

            // Salva storico ricerche della sessione (max 10)
            const searchHistory = getSearchHistory();
            searchHistory.push({
                query: query,
                results: resultsCount,
                timestamp: Date.now()
            });
            // Mantieni solo le ultime 10 ricerche
            if (searchHistory.length > 10) searchHistory.shift();
            setCookie('smartsearch_search_history', JSON.stringify(searchHistory), 7);
        }

        sendAnalyticsEvent(resultsCount > 0 ? 'search' : 'no_results', {
            query: query,
            results_count: resultsCount
        });
    }

    /**
     * Track product click event
     * Salva storico completo dei click per attribuzione conversioni
     */
    function trackClick(productId, productName, position, price) {
        const clickData = {
            product_id: productId,
            product_name: productName,
            price: price,
            query: currentQuery,
            position: position,
            timestamp: Date.now()
        };

        // Salva ultimo click (retrocompatibilità)
        setCookie('smartsearch_last_click', JSON.stringify(clickData), 7);

        // Salva storico click della sessione (max 20)
        const clickHistory = getClickHistory();
        clickHistory.push(clickData);
        // Mantieni solo gli ultimi 20 click
        if (clickHistory.length > 20) clickHistory.shift();
        setCookie('smartsearch_click_history', JSON.stringify(clickHistory), 7);

        sendAnalyticsEvent('click', {
            query: currentQuery,
            product_id: productId,
            product_name: productName,
            position: position,
            price: price
        });
    }

    /**
     * Get search history from cookie
     */
    function getSearchHistory() {
        try {
            const cookie = getCookie('smartsearch_search_history');
            return cookie ? JSON.parse(cookie) : [];
        } catch (e) {
            return [];
        }
    }

    /**
     * Get click history from cookie
     */
    function getClickHistory() {
        try {
            const cookie = getCookie('smartsearch_click_history');
            return cookie ? JSON.parse(cookie) : [];
        } catch (e) {
            return [];
        }
    }

    /**
     * Get cookie value
     */
    function getCookie(name) {
        const value = `; ${document.cookie}`;
        const parts = value.split(`; ${name}=`);
        if (parts.length === 2) return decodeURIComponent(parts.pop().split(';').shift());
        return null;
    }

    // Icons SVG
    const icons = {
        search: '<svg viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M15.5 14h-.79l-.28-.27C15.41 12.59 16 11.11 16 9.5 16 5.91 13.09 3 9.5 3S3 5.91 3 9.5 5.91 16 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>',
        close: '<svg viewBox="0 0 24 24" width="24" height="24"><path fill="currentColor" d="M19 6.41L17.59 5 12 10.59 6.41 5 5 6.41 10.59 12 5 17.59 6.41 19 12 13.41 17.59 19 19 17.59 13.41 12z"/></svg>',
        chevron: '<svg viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M7.41 8.59L12 13.17l4.59-4.58L18 10l-6 6-6-6 1.41-1.41z"/></svg>',
        check: '<svg viewBox="0 0 24 24" width="12" height="12"><path fill="currentColor" d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41z"/></svg>',
        cart: '<svg viewBox="0 0 24 24" width="18" height="18"><path fill="currentColor" d="M11 9h2V6h3V4h-3V1h-2v3H8v2h3v3zm-4 9c-1.1 0-1.99.9-1.99 2S5.9 22 7 22s2-.9 2-2-.9-2-2-2zm10 0c-1.1 0-1.99.9-1.99 2s.89 2 1.99 2 2-.9 2-2-.9-2-2-2zm-9.83-3.25l.03-.12.9-1.63h7.45c.75 0 1.41-.41 1.75-1.03l3.86-7.01L19.42 4h-.01l-1.1 2-2.76 5H8.53l-.13-.27L6.16 6l-.95-2-.94-2H1v2h2l3.6 7.59-1.35 2.45c-.16.28-.25.61-.25.96 0 1.1.9 2 2 2h12v-2H7.42c-.13 0-.25-.11-.25-.25z"/></svg>',
        filter: '<svg viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M10 18h4v-2h-4v2zM3 6v2h18V6H3zm3 7h12v-2H6v2z"/></svg>',
        noResults: '<svg viewBox="0 0 24 24" width="80" height="80"><path fill="currentColor" d="M15.5 14h-.79l-.28-.27C15.41 12.59 16 11.11 16 9.5 16 5.91 13.09 3 9.5 3S3 5.91 3 9.5 5.91 16 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>'
    };

    /**
     * Load banners from API
     */
    function loadBanners(query) {
        const url = config.ajax_url + '?ajax=1&action=banners&q=' + encodeURIComponent(query || '');

        fetch(url, {
            method: 'GET',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(response => response.json())
        .then(data => {
            if (data.success && data.banners) {
                activeBanners = data.banners;
            } else {
                activeBanners = [];
            }
        })
        .catch(() => {
            activeBanners = [];
        });
    }

    /**
     * Render banners by position
     */
    function renderBanners(position) {
        const banners = activeBanners.filter(b => b.position === position);
        if (banners.length === 0) return '';

        let html = '';
        banners.forEach(banner => {
            const linkOpen = banner.link ? `<a href="${banner.link}" target="_blank" class="smartsearch-banner-link">` : '<div class="smartsearch-banner-link">';
            const linkClose = banner.link ? '</a>' : '</div>';

            html += `
                ${linkOpen}
                    <div class="smartsearch-banner smartsearch-banner-${position}">
                        <img src="${banner.image}" alt="${escapeHtml(banner.name)}" loading="lazy">
                    </div>
                ${linkClose}
            `;
        });

        return html;
    }

    /**
     * Init
     */
    function init() {
        applyCustomStyles(); // Apply custom colors from config
        loadRecentSearches();
        createOverlay();
        bindTriggers();
        loadBanners(''); // Preload banners
    }

    /**
     * Apply custom styles from admin configuration
     * Injects CSS variables into the page
     */
    function applyCustomStyles() {
        const style = config.style || {};

        // Default values (fallback)
        const defaults = {
            overlay_bg: '#1e293b',
            overlay_opacity: 98,
            search_bg: '#1e293b',
            search_text: '#ffffff',
            search_placeholder: '#94a3b8',
            accent_color: '#f97316',
            card_bg: '#ffffff',
            card_title: '#1e293b',
            card_price: '#059669',
            card_price_old: '#94a3b8',
            discount_badge_bg: '#dc2626',
            discount_badge_text: '#ffffff',
            sidebar_bg: '#f8fafc',
            sidebar_text: '#334155',
            button_bg: '#f97316',
            button_text: '#ffffff'
        };

        // Merge with defaults
        const s = { ...defaults, ...style };

        // Convert hex to RGB for opacity support
        const hexToRgb = (hex) => {
            const result = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec(hex);
            return result ? `${parseInt(result[1], 16)}, ${parseInt(result[2], 16)}, ${parseInt(result[3], 16)}` : '30, 41, 59';
        };

        // Create CSS custom properties
        const cssVars = `
            :root {
                --ss-overlay-bg: ${s.overlay_bg};
                --ss-overlay-bg-rgb: ${hexToRgb(s.overlay_bg)};
                --ss-overlay-opacity: ${s.overlay_opacity / 100};
                --ss-search-bg: ${s.search_bg};
                --ss-search-text: ${s.search_text};
                --ss-search-placeholder: ${s.search_placeholder};
                --ss-accent: ${s.accent_color};
                --ss-card-bg: ${s.card_bg};
                --ss-card-title: ${s.card_title};
                --ss-card-price: ${s.card_price};
                --ss-card-price-old: ${s.card_price_old};
                --ss-discount-bg: ${s.discount_badge_bg};
                --ss-discount-text: ${s.discount_badge_text};
                --ss-sidebar-bg: ${s.sidebar_bg};
                --ss-sidebar-text: ${s.sidebar_text};
                --ss-button-bg: ${s.button_bg};
                --ss-button-text: ${s.button_text};
            }
        `;

        // Inject styles
        const styleEl = document.createElement('style');
        styleEl.id = 'smartsearch-custom-styles';
        styleEl.textContent = cssVars;

        // Remove existing if present
        const existing = document.getElementById('smartsearch-custom-styles');
        if (existing) existing.remove();

        document.head.appendChild(styleEl);
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
                    <div class="smartsearch-suggestions"></div>
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
        searchInput.addEventListener('focus', () => showSuggestions());

        // Hide suggestions when clicking outside
        document.addEventListener('click', (e) => {
            if (!e.target.closest('.smartsearch-search-box')) {
                hideSuggestions();
            }
        });

        overlay.querySelector('.smartsearch-close-btn').addEventListener('click', closeOverlay);
        overlay.querySelector('.smartsearch-clear-input').addEventListener('click', clearInput);

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

                    // Rimuovi eventi esistenti e aggiungi i nostri
                    el.addEventListener('focus', handleTriggerFocus, true);
                    el.addEventListener('click', handleTriggerClick, true);

                    // Marca come gestito
                    el.setAttribute('data-smartsearch-bound', 'true');
                });
            } catch (e) {}
        });

        if (!found) {
            // Fallback: cerca qualsiasi input che sembri una ricerca
            document.querySelectorAll('input').forEach(el => {
                const placeholder = (el.placeholder || '').toLowerCase();
                const name = (el.name || '').toLowerCase();
                const id = (el.id || '').toLowerCase();

                if (placeholder.includes('cerca') || placeholder.includes('search') ||
                    name.includes('search') || name.includes('query') ||
                    id.includes('search') || id.includes('query')) {

                    if (!el.classList.contains('smartsearch-search-input')) {
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

    // Stato filtri
    let availableFilters = { brands: [], categories: [], price_range: { min: 0, max: 1000 } };
    let selectedFilters = { brands: [], categories: [], price_min: null, price_max: null };

    /**
     * Open overlay
     */
    function openOverlay() {
        overlay.classList.add('active');
        document.body.style.overflow = 'hidden';
        setTimeout(() => searchInput.focus(), 100);

        // Carica filtri e prodotti in parallelo
        loadFilters();
        loadBestsellers();
    }

    /**
     * Carica i filtri disponibili
     */
    function loadFilters() {
        const url = config.ajax_url + '?ajax=1&action=filters';

        fetch(url, {
            method: 'GET',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(response => response.json())
        .then(data => {
            availableFilters = data;
            renderFilters();
        })
        .catch(error => {
        });
    }

    /**
     * Renderizza i filtri nella sidebar
     */
    function renderFilters() {
        const sidebar = overlay.querySelector('.smartsearch-sidebar');
        if (!sidebar) return;

        let html = '';

        // Titolo filtri con pulsante mobile
        html += `
            <div class="smartsearch-filters-header">
                <h3>${t.filters || 'Filtri'}</h3>
                <button class="smartsearch-filters-close-mobile">${icons.close}</button>
            </div>
        `;

        // Filtro Prezzo
        if (availableFilters.price_range) {
            const pr = availableFilters.price_range;
            html += `
                <div class="smartsearch-filter-section smartsearch-filter-price">
                    <div class="smartsearch-filter-title" data-toggle="price">
                        <span>${t.price || 'Prezzo'}</span>
                        ${icons.chevron}
                    </div>
                    <div class="smartsearch-filter-content" id="filter-price-content">
                        <div class="smartsearch-price-inputs">
                            <div class="smartsearch-price-input">
                                <input type="number" id="price-min" min="${pr.min}" max="${pr.max}" value="${selectedFilters.price_min || pr.min}" placeholder="${pr.min}€">
                                <span>€</span>
                            </div>
                            <span class="smartsearch-price-separator">-</span>
                            <div class="smartsearch-price-input">
                                <input type="number" id="price-max" min="${pr.min}" max="${pr.max}" value="${selectedFilters.price_max || pr.max}" placeholder="${pr.max}€">
                                <span>€</span>
                            </div>
                        </div>
                        <div class="smartsearch-price-slider">
                            <div class="smartsearch-price-track"></div>
                            <input type="range" id="price-slider-min" min="${pr.min}" max="${pr.max}" value="${selectedFilters.price_min || pr.min}" step="1">
                            <input type="range" id="price-slider-max" min="${pr.min}" max="${pr.max}" value="${selectedFilters.price_max || pr.max}" step="1">
                        </div>
                    </div>
                </div>
            `;
        }

        // Filtro Marca
        if (availableFilters.brands && availableFilters.brands.length > 0) {
            html += `
                <div class="smartsearch-filter-section smartsearch-filter-brands">
                    <div class="smartsearch-filter-title" data-toggle="brands">
                        <span>${t.brand || 'Marca'}</span>
                        ${icons.chevron}
                    </div>
                    <div class="smartsearch-filter-content" id="filter-brands-content">
                        <div class="smartsearch-filter-search">
                            <input type="text" placeholder="Cerca marche..." class="smartsearch-filter-search-input" data-filter="brands">
                        </div>
                        <div class="smartsearch-filter-list smartsearch-brands-list">
                            ${availableFilters.brands.map(brand => `
                                <label class="smartsearch-filter-checkbox">
                                    <input type="checkbox" value="${brand.id}" data-type="brand" ${selectedFilters.brands.includes(brand.id) ? 'checked' : ''}>
                                    <span class="smartsearch-checkbox-mark"></span>
                                    <span class="smartsearch-filter-name">${escapeHtml(brand.name)}</span>
                                    <span class="smartsearch-filter-count">${brand.count}</span>
                                </label>
                            `).join('')}
                        </div>
                    </div>
                </div>
            `;
        }

        // Filtro Categorie
        if (availableFilters.categories && availableFilters.categories.length > 0) {
            html += `
                <div class="smartsearch-filter-section smartsearch-filter-categories">
                    <div class="smartsearch-filter-title" data-toggle="categories">
                        <span>${t.categories || 'Categorie'}</span>
                        ${icons.chevron}
                    </div>
                    <div class="smartsearch-filter-content" id="filter-categories-content">
                        <div class="smartsearch-filter-tags">
                            ${availableFilters.categories.map(cat => `
                                <button class="smartsearch-filter-tag ${selectedFilters.categories.includes(cat.id) ? 'active' : ''}" data-type="category" data-id="${cat.id}">
                                    ${escapeHtml(cat.name)}
                                </button>
                            `).join('')}
                        </div>
                    </div>
                </div>
            `;
        }

        // Pulsante applica filtri (mobile)
        html += `
            <div class="smartsearch-filters-actions">
                <button class="smartsearch-clear-filters">${t.clear_filters || 'Rimuovi filtri'}</button>
                <button class="smartsearch-apply-filters">${t.apply_filters || 'Applica'}</button>
            </div>
        `;

        sidebar.innerHTML = html;

        // Bind eventi filtri
        bindFilterEvents();
    }

    /**
     * Chiudi pannello filtri mobile
     */
    function closeMobileFilters() {
        const sidebar = overlay.querySelector('.smartsearch-sidebar');
        if (sidebar) {
            sidebar.classList.remove('mobile-visible');
        }
    }

    /**
     * Bind eventi filtri
     */
    function bindFilterEvents() {
        const sidebar = overlay.querySelector('.smartsearch-sidebar');
        if (!sidebar) return;

        // Toggle sezioni filtri
        sidebar.querySelectorAll('.smartsearch-filter-title').forEach(title => {
            title.addEventListener('click', () => {
                const section = title.closest('.smartsearch-filter-section');
                section.classList.toggle('collapsed');
            });
        });

        // Chiudi filtri mobile - pulsante X
        const closeBtn = sidebar.querySelector('.smartsearch-filters-close-mobile');
        if (closeBtn) {
            closeBtn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                closeMobileFilters();
            });
            // Supporto touch per mobile
            closeBtn.addEventListener('touchend', (e) => {
                e.preventDefault();
                e.stopPropagation();
                closeMobileFilters();
            });
        }

        // Checkbox brand
        sidebar.querySelectorAll('input[data-type="brand"]').forEach(checkbox => {
            checkbox.addEventListener('change', () => {
                const brandId = parseInt(checkbox.value);
                if (checkbox.checked) {
                    if (!selectedFilters.brands.includes(brandId)) {
                        selectedFilters.brands.push(brandId);
                    }
                } else {
                    selectedFilters.brands = selectedFilters.brands.filter(id => id !== brandId);
                }
                applyFiltersIfSearching();
            });
        });

        // Tag categoria
        sidebar.querySelectorAll('.smartsearch-filter-tag[data-type="category"]').forEach(tag => {
            tag.addEventListener('click', () => {
                const catId = parseInt(tag.dataset.id);
                tag.classList.toggle('active');
                if (tag.classList.contains('active')) {
                    if (!selectedFilters.categories.includes(catId)) {
                        selectedFilters.categories.push(catId);
                    }
                } else {
                    selectedFilters.categories = selectedFilters.categories.filter(id => id !== catId);
                }
                applyFiltersIfSearching();
            });
        });

        // Slider prezzo
        const sliderMin = sidebar.querySelector('#price-slider-min');
        const sliderMax = sidebar.querySelector('#price-slider-max');
        const inputMin = sidebar.querySelector('#price-min');
        const inputMax = sidebar.querySelector('#price-max');

        if (sliderMin && sliderMax) {
            sliderMin.addEventListener('input', () => {
                const minVal = parseInt(sliderMin.value);
                const maxVal = parseInt(sliderMax.value);
                if (minVal <= maxVal) {
                    selectedFilters.price_min = minVal;
                    if (inputMin) inputMin.value = minVal;
                    updatePriceSliderTrack();
                }
            });

            sliderMax.addEventListener('input', () => {
                const minVal = parseInt(sliderMin.value);
                const maxVal = parseInt(sliderMax.value);
                if (maxVal >= minVal) {
                    selectedFilters.price_max = maxVal;
                    if (inputMax) inputMax.value = maxVal;
                    updatePriceSliderTrack();
                }
            });

            sliderMin.addEventListener('change', applyFiltersIfSearching);
            sliderMax.addEventListener('change', applyFiltersIfSearching);
        }

        if (inputMin && inputMax) {
            inputMin.addEventListener('change', () => {
                selectedFilters.price_min = parseInt(inputMin.value) || null;
                if (sliderMin) sliderMin.value = inputMin.value;
                updatePriceSliderTrack();
                applyFiltersIfSearching();
            });

            inputMax.addEventListener('change', () => {
                selectedFilters.price_max = parseInt(inputMax.value) || null;
                if (sliderMax) sliderMax.value = inputMax.value;
                updatePriceSliderTrack();
                applyFiltersIfSearching();
            });
        }

        // Ricerca marche
        sidebar.querySelectorAll('.smartsearch-filter-search-input').forEach(input => {
            input.addEventListener('input', (e) => {
                const query = e.target.value.toLowerCase();
                const list = input.closest('.smartsearch-filter-content').querySelector('.smartsearch-filter-list');
                if (list) {
                    list.querySelectorAll('.smartsearch-filter-checkbox').forEach(item => {
                        const name = item.querySelector('.smartsearch-filter-name').textContent.toLowerCase();
                        item.style.display = name.includes(query) ? '' : 'none';
                    });
                }
            });
        });

        // Rimuovi filtri
        const clearBtn = sidebar.querySelector('.smartsearch-clear-filters');
        if (clearBtn) {
            clearBtn.addEventListener('click', () => {
                selectedFilters = { brands: [], categories: [], price_min: null, price_max: null };
                renderFilters();
                applyFiltersIfSearching();
            });
        }

        // Applica filtri (mobile)
        const applyBtn = sidebar.querySelector('.smartsearch-apply-filters');
        if (applyBtn) {
            applyBtn.addEventListener('click', () => {
                closeMobileFilters();
                applyFiltersIfSearching();
            });
        }

        updatePriceSliderTrack();
    }

    /**
     * Aggiorna lo stile della track del price slider
     */
    function updatePriceSliderTrack() {
        const sidebar = overlay.querySelector('.smartsearch-sidebar');
        if (!sidebar) return;

        const sliderMin = sidebar.querySelector('#price-slider-min');
        const sliderMax = sidebar.querySelector('#price-slider-max');
        const track = sidebar.querySelector('.smartsearch-price-track');
        const inputMin = sidebar.querySelector('#price-min');
        const inputMax = sidebar.querySelector('#price-max');

        if (sliderMin && sliderMax && track) {
            const min = parseInt(sliderMin.min);
            const max = parseInt(sliderMin.max);
            const minVal = parseInt(sliderMin.value);
            const maxVal = parseInt(sliderMax.value);

            const leftPercent = ((minVal - min) / (max - min)) * 100;
            const rightPercent = ((max - maxVal) / (max - min)) * 100;

            // Usa CSS variables per la track highlight
            track.style.setProperty('--track-left', leftPercent + '%');
            track.style.setProperty('--track-right', rightPercent + '%');

            // Aggiorna anche gli input numerici
            if (inputMin) inputMin.value = minVal;
            if (inputMax) inputMax.value = maxVal;
        }
    }

    /**
     * Conta i filtri attivi
     */
    function getActiveFilterCount() {
        let count = 0;
        if (selectedFilters.categories && selectedFilters.categories.length > 0) {
            count += selectedFilters.categories.length;
        }
        if (selectedFilters.brands && selectedFilters.brands.length > 0) {
            count += selectedFilters.brands.length;
        }
        if (selectedFilters.price_min !== null && selectedFilters.price_min !== undefined) {
            count++;
        }
        if (selectedFilters.price_max !== null && selectedFilters.price_max !== undefined) {
            count++;
        }
        return count;
    }

    /**
     * Applica filtri se c'è una ricerca attiva
     * Converte i nomi delle proprietà da selectedFilters a quelli attesi da performSearch
     */
    function applyFiltersIfSearching() {
        if (currentQuery && currentQuery.length >= (config.min_chars || 2)) {
            // Converti i nomi delle proprietà per performSearch
            const filters = {
                category: selectedFilters.categories || [],
                manufacturer: selectedFilters.brands || [],
                price_min: selectedFilters.price_min,
                price_max: selectedFilters.price_max
            };
            performSearch(currentQuery, filters);
        }
    }

    /**
     * Carica i prodotti più venduti
     */
    function loadBestsellers() {
        const main = overlay.querySelector('.smartsearch-main');
        // NON toccare la sidebar - i filtri sono renderizzati separatamente

        // Mostra loader
        main.innerHTML = `
            <div class="smartsearch-loader">
                <div class="smartsearch-spinner"></div>
                <span>Caricamento prodotti...</span>
            </div>
        `;

        // Chiama l'API per i bestseller
        const url = config.ajax_url + '?ajax=1&action=bestsellers';

        fetch(url, {
            method: 'GET',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('HTTP error ' + response.status);
            }
            return response.json();
        })
        .then(data => {
            if (data.products && data.products.length > 0) {
                renderBestsellers(data.products);
            } else if (data.error) {
                loadFallbackProducts();
            } else {
                loadFallbackProducts();
            }
        })
        .catch(error => {
            loadFallbackProducts();
        });
    }

    /**
     * Fallback: cerca prodotti generici se bestsellers non funziona
     */
    function loadFallbackProducts() {
        const url = config.ajax_url + '?ajax=1&action=search&q=*';

        fetch(url, {
            method: 'GET',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(response => response.json())
        .then(data => {
            if (data.products && data.products.length > 0) {
                renderBestsellers(data.products);
            } else {
                renderEmptyState();
            }
        })
        .catch(error => {
            renderEmptyState();
        });
    }

    /**
     * Mostra stato vuoto quando non ci sono prodotti
     */
    function renderEmptyState() {
        const main = overlay.querySelector('.smartsearch-main');
        // NON toccare la sidebar - i filtri sono già renderizzati

        // Pulsante filtri mobile
        const activeFilterCount = getActiveFilterCount();
        main.innerHTML = `
            <button type="button" class="smartsearch-filter-toggle-mobile">
                ${icons.filter}
                <span>${t.filters || 'Filtri'}</span>
                ${activeFilterCount > 0 ? `<span class="smartsearch-filter-badge">${activeFilterCount}</span>` : ''}
            </button>
            <div class="smartsearch-initial">
                ${icons.search.replace('width="20"', 'width="100"').replace('height="20"', 'height="100"')}
                <h3>${t.search_placeholder || 'Cerca prodotti...'}</h3>
                <p>Inizia a digitare per cercare nel catalogo</p>
            </div>
        `;

        // Bind evento pulsante filtri mobile
        const filterToggle = main.querySelector('.smartsearch-filter-toggle-mobile');
        if (filterToggle) {
            filterToggle.addEventListener('click', () => {
                const sidebar = overlay.querySelector('.smartsearch-sidebar');
                sidebar.classList.toggle('mobile-visible');
            });
        }
    }

    /**
     * Render bestsellers
     */
    function renderBestsellers(products) {
        const main = overlay.querySelector('.smartsearch-main');
        // NON toccare la sidebar - i filtri sono già renderizzati

        // Pulsante filtri mobile
        const activeFilterCount = getActiveFilterCount();
        let html = `
            <button type="button" class="smartsearch-filter-toggle-mobile">
                ${icons.filter}
                <span>${t.filters || 'Filtri'}</span>
                ${activeFilterCount > 0 ? `<span class="smartsearch-filter-badge">${activeFilterCount}</span>` : ''}
            </button>
        `;

        html += `
            <div class="smartsearch-section-title">
                <svg viewBox="0 0 24 24" width="24" height="24"><path fill="currentColor" d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg>
                <span>${t.featured_products || 'Prodotti in evidenza'}</span>
            </div>
        `;

        html += '<div class="smartsearch-products-grid">';

        products.forEach((product, index) => {
            const discount = product.price_old ? calculateDiscount(product.price_old_raw, product.price_raw) : 0;
            const savings = product.price_old ? calculateSavings(product.price_old_raw, product.price_raw) : 0;

            html += `
                <a href="${product.url}" class="smartsearch-product-card" data-product-id="${product.id}" data-index="${index}" data-price="${product.price_raw || 0}">
                    ${discount > 0 ? `<span class="smartsearch-discount-badge">-${discount}%</span>` : ''}
                    <div class="smartsearch-product-image">
                        <img src="${product.image}" alt="${escapeHtml(product.name)}" loading="lazy">
                    </div>
                    <div class="smartsearch-product-info">
                        <div class="smartsearch-product-name">${escapeHtml(product.name)}</div>
                        <div class="smartsearch-product-prices">
                            ${product.price_old ? `<span class="smartsearch-product-old-price">${product.price_old}</span>` : ''}
                            <span class="smartsearch-product-price">${product.price}</span>
                        </div>
                        ${savings > 0 ? `<div class="smartsearch-product-savings">Risparmi ${formatSavings(savings)}</div>` : ''}
                    </div>
                </a>
            `;
        });

        html += '</div>';

        main.innerHTML = html;

        // Bind evento pulsante filtri mobile
        const filterToggle = main.querySelector('.smartsearch-filter-toggle-mobile');
        if (filterToggle) {
            filterToggle.addEventListener('click', () => {
                const sidebar = overlay.querySelector('.smartsearch-sidebar');
                sidebar.classList.toggle('mobile-visible');
            });
        }
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
                hideSuggestions();
            }
            return;
        }

        // Fetch suggestions immediately (faster feedback)
        fetchSuggestions(query);

        debounceTimer = setTimeout(() => {
            hideSuggestions();
            performSearch(query);
        }, config.debounce_time || 300);
    }

    /**
     * Handle keydown
     */
    function handleKeydown(e) {
        const suggestionsEl = overlay.querySelector('.smartsearch-suggestions');
        const items = suggestionsEl.querySelectorAll('.smartsearch-suggestion-item');
        const activeItem = suggestionsEl.querySelector('.smartsearch-suggestion-item.active');

        if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (!activeItem && items.length > 0) {
                items[0].classList.add('active');
            } else if (activeItem && activeItem.nextElementSibling) {
                activeItem.classList.remove('active');
                activeItem.nextElementSibling.classList.add('active');
            }
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            if (activeItem && activeItem.previousElementSibling) {
                activeItem.classList.remove('active');
                activeItem.previousElementSibling.classList.add('active');
            }
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (activeItem) {
                const query = activeItem.dataset.query;
                searchInput.value = query;
                hideSuggestions();
                performSearch(query);
            } else if (currentQuery) {
                hideSuggestions();
                saveRecentSearch(currentQuery);
            }
        } else if (e.key === 'Escape') {
            hideSuggestions();
        }
    }

    /**
     * Fetch suggestions from server
     */
    let suggestionsTimer = null;
    function fetchSuggestions(query) {
        if (suggestionsTimer) clearTimeout(suggestionsTimer);

        suggestionsTimer = setTimeout(() => {
            const url = config.ajax_url + '?ajax=1&action=suggestions&q=' + encodeURIComponent(query);

            fetch(url, {
                method: 'GET',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(response => response.json())
            .then(data => {
                if (data.suggestions && data.suggestions.length > 0) {
                    renderSuggestions(data.suggestions, query);
                } else {
                    hideSuggestions();
                }
            })
            .catch(() => hideSuggestions());
        }, 100); // Faster than search debounce
    }

    /**
     * Render suggestions dropdown
     */
    function renderSuggestions(suggestions, query) {
        const container = overlay.querySelector('.smartsearch-suggestions');
        if (!container) return;

        const queryLower = query.toLowerCase();

        let html = suggestions.map(s => {
            // Highlight matching text
            const name = s.query;
            const nameLower = name.toLowerCase();
            const index = nameLower.indexOf(queryLower);

            let highlighted = escapeHtml(name);
            if (index >= 0) {
                highlighted = escapeHtml(name.substring(0, index)) +
                    '<strong>' + escapeHtml(name.substring(index, index + query.length)) + '</strong>' +
                    escapeHtml(name.substring(index + query.length));
            }

            // Icon based on type
            let icon = icons.search;
            let typeLabel = '';
            if (s.type === 'popular') {
                icon = '<svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 7h8m0 0v8m0-8l-8 8-4-4-6 6"></path></svg>';
                typeLabel = s.count > 10 ? '<span class="smartsearch-suggestion-badge">Popolare</span>' : '';
            } else if (s.type === 'brand') {
                icon = '<svg width="16" height="16" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4"></path></svg>';
                typeLabel = '<span class="smartsearch-suggestion-badge">Marca</span>';
            }

            return `
                <div class="smartsearch-suggestion-item" data-query="${escapeHtml(s.query)}">
                    <span class="smartsearch-suggestion-icon">${icon}</span>
                    <span class="smartsearch-suggestion-text">${highlighted}</span>
                    ${typeLabel}
                </div>
            `;
        }).join('');

        container.innerHTML = html;
        container.classList.add('visible');

        // Bind click events
        container.querySelectorAll('.smartsearch-suggestion-item').forEach(item => {
            item.addEventListener('click', () => {
                const query = item.dataset.query;
                searchInput.value = query;
                hideSuggestions();
                performSearch(query);
            });
        });
    }

    /**
     * Show suggestions (if any cached)
     */
    function showSuggestions() {
        const container = overlay.querySelector('.smartsearch-suggestions');
        if (container && container.innerHTML.trim()) {
            container.classList.add('visible');
        }
    }

    /**
     * Hide suggestions
     */
    function hideSuggestions() {
        const container = overlay.querySelector('.smartsearch-suggestions');
        if (container) {
            container.classList.remove('visible');
        }
    }

    /**
     * Perform search
     */
    function performSearch(query, filters = {}) {
        currentQuery = query;
        currentFilters = filters;

        // Reset pagination state for new search
        currentOffset = 0;
        hasMoreResults = false;
        totalResultsCount = 0;

        showLoader();

        // Load banners for this query
        loadBanners(query);

        // Aggiungi ajax=1 e action=search per PrestaShop
        let url = config.ajax_url + '?ajax=1&action=search&q=' + encodeURIComponent(query);
        url += '&offset=0&limit=' + RESULTS_PER_PAGE;

        if (filters.category && filters.category.length) url += '&category=' + filters.category.join(',');
        if (filters.manufacturer && filters.manufacturer.length) url += '&manufacturer=' + filters.manufacturer.join(',');
        if (filters.price_min) url += '&price_min=' + filters.price_min;
        if (filters.price_max) url += '&price_max=' + filters.price_max;
        if (filters.in_stock) url += '&in_stock=1';


        fetch(url, {
            method: 'GET',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(response => {
            if (!response.ok) {
                throw new Error('HTTP error ' + response.status);
            }
            return response.json();
        })
        .then(data => {
            lastResults = data;
            // Update pagination state
            currentOffset = data.offset + data.products.length;
            hasMoreResults = data.has_more || false;
            totalResultsCount = data.total_count || data.total || 0;

            renderResults(data);
            saveRecentSearch(query);
            // Track search analytics
            trackSearch(query, totalResultsCount);
        })
        .catch(error => {
            renderNoResults();
        });
    }

    /**
     * Load more results (for infinite scroll)
     */
    function loadMoreResults() {
        if (isLoadingMore || !hasMoreResults || !currentQuery) return;

        isLoadingMore = true;

        // Show loading indicator at bottom
        const main = overlay.querySelector('.smartsearch-main');
        const loadMoreIndicator = document.createElement('div');
        loadMoreIndicator.className = 'smartsearch-load-more';
        loadMoreIndicator.innerHTML = `
            <div class="smartsearch-spinner" style="width:30px;height:30px;border-width:3px;margin:0 auto 10px;"></div>
            <span>${t.loading_more || 'Caricamento...'}</span>
        `;
        main.appendChild(loadMoreIndicator);

        let url = config.ajax_url + '?ajax=1&action=search&q=' + encodeURIComponent(currentQuery);
        url += '&offset=' + currentOffset + '&limit=' + RESULTS_PER_PAGE;

        if (currentFilters.category && currentFilters.category.length) url += '&category=' + currentFilters.category.join(',');
        if (currentFilters.manufacturer && currentFilters.manufacturer.length) url += '&manufacturer=' + currentFilters.manufacturer.join(',');
        if (currentFilters.price_min) url += '&price_min=' + currentFilters.price_min;
        if (currentFilters.price_max) url += '&price_max=' + currentFilters.price_max;
        if (currentFilters.in_stock) url += '&in_stock=1';

        fetch(url, {
            method: 'GET',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(response => response.json())
        .then(data => {
            // Remove loading indicator
            loadMoreIndicator.remove();

            if (data.products && data.products.length > 0) {
                // Update pagination state
                currentOffset += data.products.length;
                hasMoreResults = data.has_more || false;

                // Append new products to grid
                appendProductsToGrid(data.products);

                // Update results count display
                updateResultsCount();
            }

            isLoadingMore = false;
        })
        .catch(error => {
            loadMoreIndicator.remove();
            isLoadingMore = false;
        });
    }

    /**
     * Append products to existing grid
     */
    function appendProductsToGrid(products) {
        const grid = overlay.querySelector('.smartsearch-products-grid');
        if (!grid) return;

        const startIndex = grid.querySelectorAll('.smartsearch-product-card').length;

        products.forEach((product, index) => {
            const discount = product.price_old ? calculateDiscount(product.price_old_raw, product.price_raw) : 0;
            const savings = product.price_old ? calculateSavings(product.price_old_raw, product.price_raw) : 0;

            const card = document.createElement('a');
            card.href = product.url;
            card.className = 'smartsearch-product-card';
            card.dataset.productId = product.id;
            card.dataset.index = startIndex + index;
            card.dataset.price = product.price_raw || 0;

            card.innerHTML = `
                ${discount > 0 ? `<span class="smartsearch-discount-badge">-${discount}%</span>` : ''}
                <div class="smartsearch-product-image">
                    <img src="${product.image}" alt="${escapeHtml(product.name)}" loading="lazy">
                </div>
                <div class="smartsearch-product-info">
                    <div class="smartsearch-product-name">${highlightText(product.name, currentQuery)}</div>
                    <div class="smartsearch-product-prices">
                        ${product.price_old ? `<span class="smartsearch-product-old-price">${product.price_old}</span>` : ''}
                        <span class="smartsearch-product-price">${product.price}</span>
                    </div>
                    ${savings > 0 ? `<div class="smartsearch-product-savings">Risparmi ${formatSavings(savings)}</div>` : ''}
                </div>
            `;

            // Bind click event for analytics
            card.addEventListener('click', () => {
                trackProductClick(product.id, currentQuery, startIndex + index);
            });

            grid.appendChild(card);
        });
    }

    /**
     * Update results count display
     */
    function updateResultsCount() {
        const countEl = overlay.querySelector('.smartsearch-results-count');
        if (countEl) {
            const loadedCount = overlay.querySelectorAll('.smartsearch-product-card').length;
            countEl.innerHTML = `<strong>${loadedCount}</strong> di ${totalResultsCount} ${t.products_found || 'risultati'}`;
        }
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
     * Render initial state - mostra prodotti in evidenza o stato vuoto
     */
    function renderInitialState() {
        // Ricarica i prodotti invece di mostrare stato vuoto
        if (overlay.classList.contains('active')) {
            loadBestsellers();
        } else {
            renderEmptyState();
        }
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

        // Update banners from response if available
        if (data.banners && data.banners.length > 0) {
            activeBanners = data.banners;
        }

        // Mantieni i filtri esistenti - NON sovrascrivere con renderFilters(sidebar, data.facets)
        // I filtri sono già renderizzati da renderFilters() all'apertura dell'overlay
        // e lo stato è preservato in selectedFilters

        // Render main content
        let html = '';

        // Results header with pagination info
        const displayedCount = data.products.length;
        const totalCount = data.total_count || data.total || displayedCount;
        const countText = hasMoreResults
            ? `<strong>${displayedCount}</strong> di ${totalCount} ${t.products_found || 'risultati'}`
            : `<strong>${totalCount}</strong> ${t.products_found || 'risultati trovati'}`;

        html += `
            <div class="smartsearch-results-header">
                <div class="smartsearch-results-count">
                    ${countText}
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

        // "Forse cercavi..." per pochi risultati
        if (data.did_you_mean && data.did_you_mean.length > 0 && data.products.length < 5) {
            html += '<div class="smartsearch-did-you-mean" style="padding: 12px 24px; background: #fef3c7; border-radius: 8px; margin: 0 24px 16px;">';
            html += `<span style="color: #92400e; font-size: 14px;">${t.did_you_mean || 'Forse cercavi'}: </span>`;
            data.did_you_mean.forEach((s, i) => {
                const term = typeof s === 'string' ? s : (s.term || s);
                if (i > 0) html += ', ';
                html += `<a href="#" class="smartsearch-suggestion-link" data-query="${escapeHtml(term)}" style="color: #d97706; text-decoration: underline;">${escapeHtml(term)}</a>`;
            });
            html += '</div>';
        }

        // Mobile filter toggle con badge filtri attivi
        const activeFilterCount = getActiveFilterCount();
        html += `
            <button type="button" class="smartsearch-filter-toggle-mobile">
                ${icons.filter}
                <span>${t.filters || 'Filtri'}</span>
                ${activeFilterCount > 0 ? `<span class="smartsearch-filter-badge">${activeFilterCount}</span>` : ''}
            </button>
        `;

        // Top banners (sopra i prodotti)
        const topBannersHtml = renderBannersHtml('top');
        if (topBannersHtml) {
            html += '<div class="smartsearch-banners-container smartsearch-banners-top" style="padding: 0 24px;">' + topBannersHtml + '</div>';
        }

        // Middle banners (anche sopra i prodotti, dopo top)
        const middleBannersHtml = renderBannersHtml('middle');
        if (middleBannersHtml) {
            html += '<div class="smartsearch-banners-container smartsearch-banners-middle" style="padding: 0 24px;">' + middleBannersHtml + '</div>';
        }

        // Products grid - MAI interrotto dai banner
        html += '<div class="smartsearch-products-grid">';

        data.products.forEach((product, index) => {
            const discount = product.price_old ? calculateDiscount(product.price_old_raw, product.price_raw) : 0;
            const savings = product.price_old ? calculateSavings(product.price_old_raw, product.price_raw) : 0;

            html += `
                <a href="${product.url}" class="smartsearch-product-card" data-product-id="${product.id}" data-index="${index}" data-price="${product.price_raw || 0}">
                    ${discount > 0 ? `<span class="smartsearch-discount-badge">-${discount}%</span>` : ''}
                    <div class="smartsearch-product-image">
                        <img src="${product.image}" alt="${escapeHtml(product.name)}" loading="lazy">
                    </div>
                    <div class="smartsearch-product-info">
                        <div class="smartsearch-product-name">${highlightText(product.name, currentQuery)}</div>
                        <div class="smartsearch-product-prices">
                            ${product.price_old ? `<span class="smartsearch-product-old-price">${product.price_old}</span>` : ''}
                            <span class="smartsearch-product-price">${product.price}</span>
                        </div>
                        ${savings > 0 ? `<div class="smartsearch-product-savings">Risparmi ${formatSavings(savings)}</div>` : ''}
                    </div>
                </a>
            `;
        });

        html += '</div>';

        // Bottom banners
        const bottomBannersHtml = renderBannersHtml('bottom');
        if (bottomBannersHtml) {
            html += '<div class="smartsearch-banners-container smartsearch-banners-bottom" style="padding: 0 24px;">' + bottomBannersHtml + '</div>';
        }

        main.innerHTML = html;

        // Bind events
        bindResultEvents();

        // Setup infinite scroll
        setupInfiniteScroll();
    }

    /**
     * Setup infinite scroll listener
     */
    function setupInfiniteScroll() {
        const main = overlay.querySelector('.smartsearch-main');
        if (!main) return;

        // Remove existing listener to avoid duplicates
        main.removeEventListener('scroll', handleInfiniteScroll);
        main.addEventListener('scroll', handleInfiniteScroll);
    }

    /**
     * Handle infinite scroll
     */
    function handleInfiniteScroll(e) {
        const main = e.target;
        const scrollTop = main.scrollTop;
        const scrollHeight = main.scrollHeight;
        const clientHeight = main.clientHeight;

        // Trigger load more when user scrolls to 80% of the page
        if (scrollTop + clientHeight >= scrollHeight * 0.8) {
            loadMoreResults();
        }
    }

    /**
     * Render banners HTML by position (returns empty string if no banners)
     */
    function renderBannersHtml(position) {
        const banners = activeBanners.filter(b => b.position === position);
        if (banners.length === 0) return '';

        let html = '';
        banners.forEach(banner => {
            const linkOpen = banner.link ? `<a href="${banner.link}" target="_blank" class="smartsearch-banner-link">` : '<div class="smartsearch-banner-link">';
            const linkClose = banner.link ? '</a>' : '</div>';

            html += `
                ${linkOpen}
                    <div class="smartsearch-banner smartsearch-banner-${position}">
                        <img src="${banner.image}" alt="${escapeHtml(banner.name)}" loading="lazy">
                    </div>
                ${linkClose}
            `;
        });

        return html;
    }

    /**
     * Bind result events
     */
    function bindResultEvents() {
        const main = overlay.querySelector('.smartsearch-main');

        // Product clicks
        main.querySelectorAll('.smartsearch-product-card').forEach(card => {
            card.addEventListener('click', (e) => {
                const productId = parseInt(card.dataset.productId);
                const position = parseInt(card.dataset.index);
                const productName = card.querySelector('.smartsearch-product-name')?.textContent || '';
                const price = parseFloat(card.dataset.price) || 0;
                // Track click to n8n analytics
                trackClick(productId, productName, position, price);
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
            });
        }

        // "Forse cercavi" suggestion links
        main.querySelectorAll('.smartsearch-suggestion-link').forEach(link => {
            link.addEventListener('click', (e) => {
                e.preventDefault();
                const query = link.dataset.query;
                searchInput.value = query;
                overlay.querySelector('.smartsearch-clear-input')?.classList.add('visible');
                performSearch(query);
            });
        });
    }

    /**
     * Render no results - mostra suggerimenti e prodotti in evidenza
     */
    function renderNoResults(data) {
        const main = overlay.querySelector('.smartsearch-main');

        // NON svuotare sidebar - mantieni i filtri

        // Pulsante filtri mobile
        const activeFilterCount = getActiveFilterCount();
        let html = `
            <button type="button" class="smartsearch-filter-toggle-mobile">
                ${icons.filter}
                <span>${t.filters || 'Filtri'}</span>
                ${activeFilterCount > 0 ? `<span class="smartsearch-filter-badge">${activeFilterCount}</span>` : ''}
            </button>
        `;

        html += `
            <div class="smartsearch-no-results-header">
                ${icons.noResults}
                <h3>${t.no_results || 'Nessun risultato trovato'}</h3>
                <p>${t.try_different || 'Prova con termini di ricerca diversi'}</p>
        `;

        if (data && data.did_you_mean && data.did_you_mean.length > 0) {
            html += `<p style="margin-top: 16px;">${t.did_you_mean || 'Forse cercavi'}:</p>`;
            html += '<div style="display: flex; gap: 8px; flex-wrap: wrap; justify-content: center; margin-top: 8px;">';
            data.did_you_mean.forEach(s => {
                const term = typeof s === 'string' ? s : (s.term || s);
                html += `<span class="smartsearch-tag smartsearch-suggestion" data-query="${escapeHtml(term)}">${escapeHtml(term)}</span>`;
            });
            html += '</div>';
        }

        html += '</div>';

        // Aggiungi sezione prodotti in evidenza
        html += `
            <div class="smartsearch-featured-section">
                <div class="smartsearch-section-title">
                    <svg viewBox="0 0 24 24" width="24" height="24"><path fill="currentColor" d="M12 17.27L18.18 21l-1.64-7.03L22 9.24l-7.19-.61L12 2 9.19 8.63 2 9.24l5.46 4.73L5.82 21z"/></svg>
                    <span>${t.featured_products || 'Prodotti in evidenza'}</span>
                </div>
                <div class="smartsearch-products-grid smartsearch-featured-products">
                    <div class="smartsearch-loader">
                        <div class="smartsearch-spinner"></div>
                    </div>
                </div>
            </div>
        `;

        main.innerHTML = html;

        // Bind evento pulsante filtri mobile
        const filterToggle = main.querySelector('.smartsearch-filter-toggle-mobile');
        if (filterToggle) {
            filterToggle.addEventListener('click', () => {
                const sidebar = overlay.querySelector('.smartsearch-sidebar');
                sidebar.classList.toggle('mobile-visible');
            });
        }

        // Bind suggestion clicks
        main.querySelectorAll('.smartsearch-suggestion').forEach(el => {
            el.addEventListener('click', () => {
                const query = el.dataset.query;
                searchInput.value = query;
                overlay.querySelector('.smartsearch-clear-input').classList.add('visible');
                performSearch(query);
            });
        });

        // Carica prodotti in evidenza
        loadFeaturedProductsForNoResults();
    }

    /**
     * Carica prodotti in evidenza per pagina nessun risultato
     */
    function loadFeaturedProductsForNoResults() {
        const container = overlay.querySelector('.smartsearch-featured-products');
        if (!container) return;

        const url = config.ajax_url + '?ajax=1&action=bestsellers';

        fetch(url, {
            method: 'GET',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(response => response.json())
        .then(data => {
            if (data.products && data.products.length > 0) {
                let html = '';
                data.products.slice(0, 8).forEach((product, index) => {
                    const discount = product.price_old ? calculateDiscount(product.price_old_raw, product.price_raw) : 0;
                    html += `
                        <a href="${product.url}" class="smartsearch-product-card" data-product-id="${product.id}" data-index="${index}">
                            ${discount > 0 ? `<span class="smartsearch-discount-badge">-${discount}%</span>` : ''}
                            <div class="smartsearch-product-image">
                                <img src="${product.image}" alt="${escapeHtml(product.name)}" loading="lazy">
                            </div>
                            <div class="smartsearch-product-info">
                                <div class="smartsearch-product-name">${escapeHtml(product.name)}</div>
                                <div class="smartsearch-product-prices">
                                    ${product.price_old ? `<span class="smartsearch-product-old-price">${product.price_old}</span>` : ''}
                                    <span class="smartsearch-product-price">${product.price}</span>
                                </div>
                            </div>
                        </a>
                    `;
                });
                container.innerHTML = html;
            } else {
                container.innerHTML = '<p style="text-align: center; color: #666; padding: 20px;">Nessun prodotto disponibile</p>';
            }
        })
        .catch(() => {
            container.innerHTML = '';
        });
    }


    /**
     * Calculate discount percentage
     */
    function calculateDiscount(oldPrice, newPrice) {
        if (!oldPrice || !newPrice || oldPrice <= newPrice) return 0;
        return Math.round((1 - newPrice / oldPrice) * 100);
    }

    /**
     * Calculate savings amount
     */
    function calculateSavings(oldPrice, newPrice) {
        if (!oldPrice || !newPrice || oldPrice <= newPrice) return 0;
        return (oldPrice - newPrice).toFixed(2);
    }

    /**
     * Format price with currency
     */
    function formatSavings(amount) {
        const sign = config.currency_sign || '€';
        return amount + ' ' + sign;
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
