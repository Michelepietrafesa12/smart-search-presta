/**
 * SmartSearch 2.0 - JavaScript per ricerca dinamica intelligente
 * Con filtri, ricerca vocale, tracking, banner e UI avanzata
 */

(function() {
    'use strict';

    // Configurazione
    const config = typeof smartsearch_config !== 'undefined' ? smartsearch_config : {};
    const t = config.translations || {};

    // Stato
    let searchInput = null;
    let searchForm = null;
    let resultsContainer = null;
    let debounceTimer = null;
    let currentQuery = '';
    let selectedIndex = -1;
    let isOpen = false;
    let currentFilters = {};
    let lastResults = null;
    let voiceRecognition = null;
    let isListening = false;

    /**
     * Inizializzazione
     */
    function init() {
        // Prima cerca il widget SmartSearch dedicato
        searchInput = document.querySelector('#smartsearch-input');

        // Se non trova il widget dedicato, cerca la barra di ricerca esistente del tema
        if (!searchInput) {
            searchInput = document.querySelector('#search_widget input[type="text"]') ||
                          document.querySelector('.search-widget input[type="text"]') ||
                          document.querySelector('input[name="s"]') ||
                          document.querySelector('input[name="search_query"]');
        }

        if (!searchInput) {
            console.warn('SmartSearch: Campo di ricerca non trovato');
            return;
        }

        searchForm = searchInput.closest('form');

        // Crea/trova container risultati
        createResultsContainer();

        // Event listeners
        searchInput.addEventListener('input', handleInput);
        searchInput.addEventListener('keydown', handleKeydown);
        searchInput.addEventListener('focus', handleFocus);
        document.addEventListener('click', handleClickOutside);

        // Previeni submit form durante ricerca
        if (searchForm) {
            searchForm.addEventListener('submit', handleFormSubmit);
        }

        // Placeholder (se non già impostato dal template)
        if (!searchInput.getAttribute('placeholder')) {
            searchInput.setAttribute('placeholder', t.search_placeholder || 'Cerca prodotti...');
        }

        // Inizializza ricerca vocale
        if (config.voice_enabled) {
            initVoiceSearch();
        }

        console.log('SmartSearch 2.0: Inizializzato');
    }

    /**
     * Gestisce submit form
     */
    function handleFormSubmit(e) {
        // Permetti submit solo se c'è una query valida
        if (searchInput.value.trim().length < (config.min_chars || 2)) {
            e.preventDefault();
        }
    }

    /**
     * Crea container risultati con layout avanzato
     */
    function createResultsContainer() {
        // Prima cerca il container esistente dal template SmartSearch
        resultsContainer = document.querySelector('#smartsearch-results');

        // Se non esiste, crealo dinamicamente (per temi che usano barra ricerca esistente)
        if (!resultsContainer) {
            resultsContainer = document.createElement('div');
            resultsContainer.id = 'smartsearch-results';
            resultsContainer.className = 'smartsearch-results smartsearch-2';

            const parent = searchInput.parentElement;
            parent.style.position = 'relative';
            parent.appendChild(resultsContainer);

            // Aggiungi pulsante ricerca vocale solo se non esiste già
            if (config.voice_enabled && 'webkitSpeechRecognition' in window && !parent.querySelector('.smartsearch-voice-btn')) {
                const voiceBtn = document.createElement('button');
                voiceBtn.type = 'button';
                voiceBtn.className = 'smartsearch-voice-btn';
                voiceBtn.innerHTML = '<svg viewBox="0 0 24 24" width="20" height="20"><path fill="currentColor" d="M12 14c1.66 0 3-1.34 3-3V5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3zm-1 1.93c-3.94-.49-7-3.85-7-7.93h2c0 3.31 2.69 6 6 6s6-2.69 6-6h2c0 4.08-3.06 7.44-7 7.93V19h4v2H8v-2h4v-3.07z"/></svg>';
                voiceBtn.title = t.voice_search || 'Ricerca vocale';
                voiceBtn.addEventListener('click', toggleVoiceSearch);
                parent.appendChild(voiceBtn);
            }
        }

        resultsContainer.style.display = 'none';

        // Se il pulsante vocale esiste nel template, aggiungi l'evento
        const existingVoiceBtn = document.querySelector('.smartsearch-voice-btn');
        if (existingVoiceBtn && config.voice_enabled) {
            existingVoiceBtn.addEventListener('click', toggleVoiceSearch);
        }
    }

    /**
     * Gestisce input utente
     */
    function handleInput(e) {
        const query = e.target.value.trim();

        if (debounceTimer) clearTimeout(debounceTimer);

        if (query.length < (config.min_chars || 2)) {
            hideResults();
            currentQuery = '';
            return;
        }

        if (query === currentQuery) return;

        debounceTimer = setTimeout(() => {
            performSearch(query);
        }, config.debounce_time || 300);
    }

    /**
     * Esegue ricerca AJAX
     */
    function performSearch(query, filters = {}) {
        currentQuery = query;
        currentFilters = filters;

        showLoader();

        // Costruisci URL con filtri
        let url = config.ajax_url + '?q=' + encodeURIComponent(query);

        if (filters.category) url += '&category=' + filters.category.join(',');
        if (filters.manufacturer) url += '&manufacturer=' + filters.manufacturer.join(',');
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
        })
        .catch(error => {
            console.error('SmartSearch: Errore', error);
            hideResults();
        });
    }

    /**
     * Mostra loader
     */
    function showLoader() {
        resultsContainer.innerHTML = `
            <div class="smartsearch-loader">
                <div class="smartsearch-spinner"></div>
                <span>Ricerca in corso...</span>
            </div>
        `;
        resultsContainer.style.display = 'block';
        isOpen = true;
    }

    /**
     * Renderizza risultati completi
     */
    function renderResults(data) {
        if (!data) {
            hideResults();
            return;
        }

        const hasProducts = data.products && data.products.length > 0;
        const hasCategories = data.categories && data.categories.length > 0;
        const hasSuggestions = data.suggestions && data.suggestions.length > 0;
        const hasDidYouMean = data.did_you_mean && data.did_you_mean.length > 0;
        const hasBanners = data.banners && data.banners.length > 0;
        const hasFacets = config.facets_enabled && data.facets;

        if (!hasProducts && !hasCategories && !hasSuggestions) {
            showNoResults(data);
            return;
        }

        let html = '<div class="smartsearch-container">';

        // Sidebar filtri
        if (hasFacets && hasProducts) {
            html += renderFacets(data.facets);
        }

        // Area principale
        html += '<div class="smartsearch-main">';

        // Banner top
        if (hasBanners) {
            const topBanners = data.banners.filter(b => b.position === 'top');
            if (topBanners.length > 0) {
                html += renderBanners(topBanners);
            }
        }

        // "Forse cercavi"
        if (hasDidYouMean) {
            html += renderDidYouMean(data.did_you_mean);
        }

        // Suggerimenti
        if (hasSuggestions) {
            html += renderSuggestions(data.suggestions);
        }

        // Categorie
        if (hasCategories) {
            html += renderCategories(data.categories);
        }

        // Prodotti
        if (hasProducts) {
            html += renderProducts(data.products);

            // Link vedi tutti
            if (searchForm && data.total > 0) {
                const searchUrl = searchForm.action + '?s=' + encodeURIComponent(currentQuery);
                html += `
                    <a href="${searchUrl}" class="smartsearch-view-all">
                        <span>${t.view_all || 'Vedi tutti i risultati'} (${data.total})</span>
                        <svg viewBox="0 0 24 24" width="18" height="18"><path fill="currentColor" d="M12 4l-1.41 1.41L16.17 11H4v2h12.17l-5.58 5.59L12 20l8-8z"/></svg>
                    </a>
                `;
            }
        }

        html += '</div></div>';

        resultsContainer.innerHTML = html;
        resultsContainer.style.display = 'block';
        isOpen = true;
        selectedIndex = -1;

        // Bind eventi
        bindResultEvents();
    }

    /**
     * Renderizza filtri/facets
     */
    function renderFacets(facets) {
        let html = '<div class="smartsearch-sidebar">';
        html += `<div class="smartsearch-filters-header">
            <span>${t.filters || 'Filtri'}</span>
            <button type="button" class="smartsearch-clear-filters">${t.clear_filters || 'Rimuovi'}</button>
        </div>`;

        // Filtro categorie
        if (facets.categories && facets.categories.length > 0) {
            html += `<div class="smartsearch-facet">
                <div class="smartsearch-facet-title">${t.categories || 'Categorie'}</div>
                <div class="smartsearch-facet-values">`;
            facets.categories.forEach(cat => {
                const checked = currentFilters.category && currentFilters.category.includes(cat.id_category);
                html += `<label class="smartsearch-facet-item">
                    <input type="checkbox" data-facet="category" value="${cat.id_category}" ${checked ? 'checked' : ''}>
                    <span>${escapeHtml(cat.name)}</span>
                    <span class="count">(${cat.count})</span>
                </label>`;
            });
            html += '</div></div>';
        }

        // Filtro produttori
        if (facets.manufacturers && facets.manufacturers.length > 0) {
            html += `<div class="smartsearch-facet">
                <div class="smartsearch-facet-title">${t.brand || 'Marca'}</div>
                <div class="smartsearch-facet-values">`;
            facets.manufacturers.forEach(man => {
                const checked = currentFilters.manufacturer && currentFilters.manufacturer.includes(man.id_manufacturer);
                html += `<label class="smartsearch-facet-item">
                    <input type="checkbox" data-facet="manufacturer" value="${man.id_manufacturer}" ${checked ? 'checked' : ''}>
                    <span>${escapeHtml(man.name)}</span>
                    <span class="count">(${man.count})</span>
                </label>`;
            });
            html += '</div></div>';
        }

        // Filtro prezzo
        if (facets.price_range && facets.price_range.max > 0) {
            const min = Math.floor(facets.price_range.min);
            const max = Math.ceil(facets.price_range.max);
            html += `<div class="smartsearch-facet">
                <div class="smartsearch-facet-title">${t.price || 'Prezzo'}</div>
                <div class="smartsearch-price-range">
                    <input type="number" class="smartsearch-price-input" data-facet="price_min"
                           placeholder="${t.from || 'Da'}" min="${min}" max="${max}"
                           value="${currentFilters.price_min || ''}">
                    <span>-</span>
                    <input type="number" class="smartsearch-price-input" data-facet="price_max"
                           placeholder="${t.to || 'A'}" min="${min}" max="${max}"
                           value="${currentFilters.price_max || ''}">
                    <span>${config.currency_sign || '€'}</span>
                </div>
            </div>`;
        }

        // Filtro disponibilità
        html += `<div class="smartsearch-facet">
            <label class="smartsearch-facet-item smartsearch-stock-filter">
                <input type="checkbox" data-facet="in_stock" ${currentFilters.in_stock ? 'checked' : ''}>
                <span>${t.in_stock || 'Solo disponibili'}</span>
            </label>
        </div>`;

        html += '</div>';
        return html;
    }

    /**
     * Renderizza "Forse cercavi"
     */
    function renderDidYouMean(suggestions) {
        let html = '<div class="smartsearch-didyoumean">';
        html += `<span>${t.did_you_mean || 'Forse cercavi'}:</span>`;
        suggestions.forEach(s => {
            html += `<a href="#" class="smartsearch-suggestion" data-query="${escapeHtml(s.term)}">${escapeHtml(s.term)}</a>`;
        });
        html += '</div>';
        return html;
    }

    /**
     * Renderizza suggerimenti
     */
    function renderSuggestions(suggestions) {
        let html = '<div class="smartsearch-suggestions">';
        html += `<div class="smartsearch-section-title">${t.suggestions || 'Suggerimenti'}</div>`;
        html += '<div class="smartsearch-suggestions-list">';
        suggestions.forEach(s => {
            html += `<a href="#" class="smartsearch-suggestion" data-query="${escapeHtml(s)}">
                <svg viewBox="0 0 24 24" width="16" height="16"><path fill="currentColor" d="M15.5 14h-.79l-.28-.27C15.41 12.59 16 11.11 16 9.5 16 5.91 13.09 3 9.5 3S3 5.91 3 9.5 5.91 16 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>
                ${highlightText(s, currentQuery)}
            </a>`;
        });
        html += '</div></div>';
        return html;
    }

    /**
     * Renderizza categorie
     */
    function renderCategories(categories) {
        let html = '<div class="smartsearch-categories">';
        html += `<div class="smartsearch-section-title">${t.categories || 'Categorie'}</div>`;
        html += '<div class="smartsearch-categories-list">';
        categories.forEach(cat => {
            html += `<a href="${cat.url}" class="smartsearch-category-item">
                <svg viewBox="0 0 24 24" width="16" height="16"><path fill="currentColor" d="M10 4H4c-1.1 0-1.99.9-1.99 2L2 18c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2h-8l-2-2z"/></svg>
                <span>${highlightText(cat.name, currentQuery)}</span>
            </a>`;
        });
        html += '</div></div>';
        return html;
    }

    /**
     * Renderizza prodotti
     */
    function renderProducts(products) {
        let html = '<div class="smartsearch-products">';
        html += `<div class="smartsearch-section-title">${t.products || 'Prodotti'}</div>`;
        html += '<div class="smartsearch-products-grid">';

        products.forEach((product, index) => {
            html += `<a href="${product.url}" class="smartsearch-product" data-index="${index}" data-product-id="${product.id}">`;

            // Badge boost
            if (product.boosted) {
                html += '<span class="smartsearch-badge-boost">★</span>';
            }

            // Immagine
            if (config.show_image && product.image) {
                html += `<div class="smartsearch-product-image">
                    <img src="${product.image}" alt="${escapeHtml(product.name)}" loading="lazy">
                </div>`;
            }

            html += '<div class="smartsearch-product-info">';

            // Nome
            html += `<div class="smartsearch-product-name">${highlightText(product.name, currentQuery)}</div>`;

            // Categoria e produttore
            if ((config.show_category && product.category) || (config.show_manufacturer && product.manufacturer)) {
                html += '<div class="smartsearch-product-meta">';
                if (product.category) html += `<span>${escapeHtml(product.category)}</span>`;
                if (product.category && product.manufacturer) html += ' · ';
                if (product.manufacturer) html += `<span>${escapeHtml(product.manufacturer)}</span>`;
                html += '</div>';
            }

            // Descrizione
            if (config.show_description && product.description) {
                html += `<div class="smartsearch-product-desc">${highlightText(product.description, currentQuery)}</div>`;
            }

            // Prezzo e stock
            html += '<div class="smartsearch-product-footer">';
            if (config.show_price && product.price) {
                html += '<div class="smartsearch-product-price">';
                if (product.price_old) {
                    html += `<span class="old-price">${product.price_old}</span>`;
                }
                html += `<span class="current-price">${product.price}</span>`;
                html += '</div>';
            }

            if (config.show_stock) {
                if (product.in_stock) {
                    html += `<span class="smartsearch-stock in-stock">${t.in_stock || 'Disponibile'}</span>`;
                } else {
                    html += `<span class="smartsearch-stock out-of-stock">${t.out_of_stock || 'Non disponibile'}</span>`;
                }
            }
            html += '</div>';

            html += '</div></a>';
        });

        html += '</div></div>';
        return html;
    }

    /**
     * Renderizza banner
     */
    function renderBanners(banners) {
        let html = '<div class="smartsearch-banners">';
        banners.forEach(banner => {
            html += `<a href="${banner.link || '#'}" class="smartsearch-banner" target="_blank">
                <img src="${banner.image}" alt="${escapeHtml(banner.name)}" loading="lazy">
            </a>`;
        });
        html += '</div>';
        return html;
    }

    /**
     * Mostra nessun risultato
     */
    function showNoResults(data) {
        let html = '<div class="smartsearch-no-results">';
        html += `<svg viewBox="0 0 24 24" width="48" height="48"><path fill="currentColor" d="M15.5 14h-.79l-.28-.27C15.41 12.59 16 11.11 16 9.5 16 5.91 13.09 3 9.5 3S3 5.91 3 9.5 5.91 16 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/></svg>`;
        html += `<p>${t.no_results || 'Nessun risultato trovato'}</p>`;

        // Suggerimenti "forse cercavi"
        if (data && data.did_you_mean && data.did_you_mean.length > 0) {
            html += `<div class="smartsearch-didyoumean-inline">`;
            html += `<span>${t.did_you_mean || 'Forse cercavi'}:</span>`;
            data.did_you_mean.forEach(s => {
                html += `<a href="#" class="smartsearch-suggestion" data-query="${escapeHtml(s.term)}">${escapeHtml(s.term)}</a>`;
            });
            html += '</div>';
        }

        html += '</div>';

        resultsContainer.innerHTML = html;
        resultsContainer.style.display = 'block';
        isOpen = true;

        bindResultEvents();
    }

    /**
     * Bind eventi sui risultati
     */
    function bindResultEvents() {
        // Click su suggerimenti
        resultsContainer.querySelectorAll('.smartsearch-suggestion').forEach(el => {
            el.addEventListener('click', e => {
                e.preventDefault();
                const query = el.dataset.query;
                searchInput.value = query;
                performSearch(query);
            });
        });

        // Click su prodotti (tracking)
        resultsContainer.querySelectorAll('.smartsearch-product').forEach((el, index) => {
            el.addEventListener('click', () => {
                trackProductClick(el.dataset.productId, index);
            });
        });

        // Filtri checkbox
        resultsContainer.querySelectorAll('input[data-facet]').forEach(input => {
            input.addEventListener('change', handleFilterChange);
        });

        // Filtri prezzo
        resultsContainer.querySelectorAll('.smartsearch-price-input').forEach(input => {
            let timeout;
            input.addEventListener('input', () => {
                clearTimeout(timeout);
                timeout = setTimeout(handleFilterChange, 500);
            });
        });

        // Clear filters
        const clearBtn = resultsContainer.querySelector('.smartsearch-clear-filters');
        if (clearBtn) {
            clearBtn.addEventListener('click', () => {
                currentFilters = {};
                performSearch(currentQuery);
            });
        }
    }

    /**
     * Gestisce cambio filtri
     */
    function handleFilterChange() {
        const filters = {};

        // Categorie
        const categoryInputs = resultsContainer.querySelectorAll('input[data-facet="category"]:checked');
        if (categoryInputs.length > 0) {
            filters.category = Array.from(categoryInputs).map(i => parseInt(i.value));
        }

        // Produttori
        const manufacturerInputs = resultsContainer.querySelectorAll('input[data-facet="manufacturer"]:checked');
        if (manufacturerInputs.length > 0) {
            filters.manufacturer = Array.from(manufacturerInputs).map(i => parseInt(i.value));
        }

        // Prezzo
        const priceMin = resultsContainer.querySelector('input[data-facet="price_min"]');
        const priceMax = resultsContainer.querySelector('input[data-facet="price_max"]');
        if (priceMin && priceMin.value) filters.price_min = parseFloat(priceMin.value);
        if (priceMax && priceMax.value) filters.price_max = parseFloat(priceMax.value);

        // Disponibilità
        const inStock = resultsContainer.querySelector('input[data-facet="in_stock"]');
        if (inStock && inStock.checked) filters.in_stock = true;

        performSearch(currentQuery, filters);
    }

    /**
     * Traccia click su prodotto
     */
    function trackProductClick(productId, position) {
        if (!config.track_url) return;

        fetch(config.track_url + '?action=click&product_id=' + productId + '&position=' + position + '&query=' + encodeURIComponent(currentQuery), {
            method: 'GET',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).catch(() => {});
    }

    /**
     * Inizializza ricerca vocale
     */
    function initVoiceSearch() {
        if (!('webkitSpeechRecognition' in window)) return;

        voiceRecognition = new webkitSpeechRecognition();
        voiceRecognition.continuous = false;
        voiceRecognition.interimResults = false;
        voiceRecognition.lang = document.documentElement.lang || 'it-IT';

        voiceRecognition.onresult = (event) => {
            const transcript = event.results[0][0].transcript;
            searchInput.value = transcript;
            performSearch(transcript);
            stopVoiceSearch();
        };

        voiceRecognition.onerror = () => stopVoiceSearch();
        voiceRecognition.onend = () => stopVoiceSearch();
    }

    /**
     * Toggle ricerca vocale
     */
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

        const btn = document.querySelector('.smartsearch-voice-btn');
        if (btn) btn.classList.add('listening');

        // Mostra feedback
        resultsContainer.innerHTML = `
            <div class="smartsearch-voice-feedback">
                <div class="smartsearch-voice-icon listening"></div>
                <p>${t.listening || 'Sto ascoltando...'}</p>
            </div>
        `;
        resultsContainer.style.display = 'block';
        isOpen = true;
    }

    function stopVoiceSearch() {
        if (!voiceRecognition) return;

        isListening = false;
        try { voiceRecognition.stop(); } catch(e) {}

        const btn = document.querySelector('.smartsearch-voice-btn');
        if (btn) btn.classList.remove('listening');
    }

    /**
     * Gestisce navigazione tastiera
     */
    function handleKeydown(e) {
        if (!isOpen) return;

        const products = resultsContainer.querySelectorAll('.smartsearch-product');
        const totalItems = products.length;

        switch (e.key) {
            case 'ArrowDown':
                e.preventDefault();
                selectedIndex = Math.min(selectedIndex + 1, totalItems - 1);
                updateSelection(products);
                break;

            case 'ArrowUp':
                e.preventDefault();
                selectedIndex = Math.max(selectedIndex - 1, -1);
                updateSelection(products);
                break;

            case 'Enter':
                if (selectedIndex >= 0 && products[selectedIndex]) {
                    e.preventDefault();
                    products[selectedIndex].click();
                }
                break;

            case 'Escape':
                hideResults();
                searchInput.blur();
                break;
        }
    }

    function updateSelection(products) {
        products.forEach((product, index) => {
            product.classList.toggle('selected', index === selectedIndex);
            if (index === selectedIndex) {
                product.scrollIntoView({ block: 'nearest' });
            }
        });
    }

    function handleFocus() {
        if (currentQuery && lastResults) {
            resultsContainer.style.display = 'block';
            isOpen = true;
        }
    }

    function hideResults() {
        resultsContainer.style.display = 'none';
        isOpen = false;
        selectedIndex = -1;
    }

    function handleClickOutside(e) {
        if (!searchInput.contains(e.target) && !resultsContainer.contains(e.target)) {
            hideResults();
        }
    }

    /**
     * Evidenzia termini
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

    // Init
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
