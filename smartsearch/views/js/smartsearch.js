/**
 * SmartSearch - JavaScript per ricerca dinamica
 * Simile a Doofinder per PrestaShop
 */

(function() {
    'use strict';

    // Configurazione di default
    const config = typeof smartsearch_config !== 'undefined' ? smartsearch_config : {
        ajax_url: '',
        min_chars: 2,
        max_results: 8,
        debounce_time: 300,
        show_price: true,
        show_image: true,
        show_description: true,
        show_category: true,
        highlight: true,
        search_placeholder: 'Cerca prodotti...',
        no_results: 'Nessun risultato trovato',
        view_all: 'Vedi tutti i risultati'
    };

    let searchInput = null;
    let searchForm = null;
    let resultsContainer = null;
    let debounceTimer = null;
    let currentQuery = '';
    let selectedIndex = -1;
    let isOpen = false;

    /**
     * Inizializzazione
     */
    function init() {
        // Trova il campo di ricerca (supporta vari temi PrestaShop)
        searchInput = document.querySelector('#search_widget input[type="text"]') ||
                      document.querySelector('.search-widget input[type="text"]') ||
                      document.querySelector('input[name="s"]') ||
                      document.querySelector('input[name="search_query"]');

        if (!searchInput) {
            console.warn('SmartSearch: Campo di ricerca non trovato');
            return;
        }

        searchForm = searchInput.closest('form');

        // Crea il container per i risultati
        createResultsContainer();

        // Aggiungi event listeners
        searchInput.addEventListener('input', handleInput);
        searchInput.addEventListener('keydown', handleKeydown);
        searchInput.addEventListener('focus', handleFocus);
        searchInput.addEventListener('blur', handleBlur);

        // Chiudi i risultati quando si clicca fuori
        document.addEventListener('click', handleClickOutside);

        // Aggiorna placeholder
        if (config.search_placeholder) {
            searchInput.setAttribute('placeholder', config.search_placeholder);
        }

        console.log('SmartSearch: Inizializzato');
    }

    /**
     * Crea il container per i risultati
     */
    function createResultsContainer() {
        resultsContainer = document.createElement('div');
        resultsContainer.className = 'smartsearch-results';
        resultsContainer.style.display = 'none';

        // Posiziona il container rispetto al campo di ricerca
        const parent = searchInput.parentElement;
        parent.style.position = 'relative';
        parent.appendChild(resultsContainer);
    }

    /**
     * Gestisce l'input dell'utente
     */
    function handleInput(e) {
        const query = e.target.value.trim();

        // Annulla il timer precedente
        if (debounceTimer) {
            clearTimeout(debounceTimer);
        }

        // Verifica lunghezza minima
        if (query.length < config.min_chars) {
            hideResults();
            currentQuery = '';
            return;
        }

        // Se la query è la stessa, non fare nulla
        if (query === currentQuery) {
            return;
        }

        // Debounce
        debounceTimer = setTimeout(() => {
            performSearch(query);
        }, config.debounce_time);
    }

    /**
     * Esegue la ricerca AJAX
     */
    function performSearch(query) {
        currentQuery = query;

        // Mostra loader
        showLoader();

        // Richiesta AJAX
        fetch(config.ajax_url + '?q=' + encodeURIComponent(query), {
            method: 'GET',
            headers: {
                'X-Requested-With': 'XMLHttpRequest'
            }
        })
        .then(response => response.json())
        .then(data => {
            renderResults(data);
        })
        .catch(error => {
            console.error('SmartSearch: Errore nella ricerca', error);
            hideResults();
        });
    }

    /**
     * Mostra il loader
     */
    function showLoader() {
        resultsContainer.innerHTML = '<div class="smartsearch-loader"><div class="spinner"></div></div>';
        resultsContainer.style.display = 'block';
        isOpen = true;
    }

    /**
     * Renderizza i risultati
     */
    function renderResults(data) {
        if (!data || (data.products.length === 0 && data.categories.length === 0)) {
            showNoResults();
            return;
        }

        let html = '';

        // Suggerimenti
        if (data.suggestions && data.suggestions.length > 0) {
            html += '<div class="smartsearch-suggestions">';
            html += '<div class="smartsearch-section-title">Suggerimenti</div>';
            data.suggestions.forEach(suggestion => {
                html += `<a href="#" class="smartsearch-suggestion" data-query="${escapeHtml(suggestion)}">${highlightText(suggestion, currentQuery)}</a>`;
            });
            html += '</div>';
        }

        // Categorie
        if (data.categories && data.categories.length > 0) {
            html += '<div class="smartsearch-categories">';
            html += '<div class="smartsearch-section-title">Categorie</div>';
            data.categories.forEach(category => {
                html += `<a href="${category.url}" class="smartsearch-category">
                    <i class="material-icons">folder</i>
                    <span>${highlightText(category.name, currentQuery)}</span>
                </a>`;
            });
            html += '</div>';
        }

        // Prodotti
        if (data.products && data.products.length > 0) {
            html += '<div class="smartsearch-products">';
            html += '<div class="smartsearch-section-title">Prodotti</div>';

            data.products.forEach((product, index) => {
                html += renderProduct(product, index);
            });

            html += '</div>';

            // Link "Vedi tutti i risultati"
            if (searchForm) {
                const searchUrl = searchForm.action + '?s=' + encodeURIComponent(currentQuery);
                html += `<a href="${searchUrl}" class="smartsearch-view-all">
                    ${config.view_all} (${data.total})
                    <i class="material-icons">arrow_forward</i>
                </a>`;
            }
        }

        resultsContainer.innerHTML = html;
        resultsContainer.style.display = 'block';
        isOpen = true;
        selectedIndex = -1;

        // Event listeners per suggerimenti
        resultsContainer.querySelectorAll('.smartsearch-suggestion').forEach(el => {
            el.addEventListener('click', (e) => {
                e.preventDefault();
                const query = el.dataset.query;
                searchInput.value = query;
                performSearch(query);
            });
        });
    }

    /**
     * Renderizza un singolo prodotto
     */
    function renderProduct(product, index) {
        let html = `<a href="${product.url}" class="smartsearch-product" data-index="${index}">`;

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
        if (config.show_category && product.category) {
            html += `<div class="smartsearch-product-category">`;
            html += `<span>${escapeHtml(product.category)}</span>`;
            if (product.manufacturer) {
                html += ` &middot; <span>${escapeHtml(product.manufacturer)}</span>`;
            }
            html += `</div>`;
        }

        // Descrizione
        if (config.show_description && product.description) {
            html += `<div class="smartsearch-product-description">${highlightText(product.description, currentQuery)}</div>`;
        }

        // Prezzo
        if (config.show_price && product.price) {
            html += `<div class="smartsearch-product-price">`;
            if (product.price_old) {
                html += `<span class="old-price">${product.price_old}</span>`;
            }
            html += `<span class="current-price">${product.price}</span>`;
            html += `</div>`;
        }

        // Stock
        if (!product.in_stock) {
            html += `<div class="smartsearch-product-outofstock">Non disponibile</div>`;
        }

        html += '</div></a>';

        return html;
    }

    /**
     * Mostra messaggio "nessun risultato"
     */
    function showNoResults() {
        resultsContainer.innerHTML = `
            <div class="smartsearch-no-results">
                <i class="material-icons">search_off</i>
                <p>${config.no_results}</p>
            </div>
        `;
        resultsContainer.style.display = 'block';
        isOpen = true;
    }

    /**
     * Nasconde i risultati
     */
    function hideResults() {
        resultsContainer.style.display = 'none';
        isOpen = false;
        selectedIndex = -1;
    }

    /**
     * Gestisce la navigazione da tastiera
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

            case 'Tab':
                hideResults();
                break;
        }
    }

    /**
     * Aggiorna la selezione visiva
     */
    function updateSelection(products) {
        products.forEach((product, index) => {
            if (index === selectedIndex) {
                product.classList.add('selected');
                product.scrollIntoView({ block: 'nearest' });
            } else {
                product.classList.remove('selected');
            }
        });
    }

    /**
     * Gestisce il focus sul campo di ricerca
     */
    function handleFocus() {
        if (currentQuery && resultsContainer.innerHTML) {
            resultsContainer.style.display = 'block';
            isOpen = true;
        }
    }

    /**
     * Gestisce il blur del campo di ricerca
     */
    function handleBlur(e) {
        // Ritarda la chiusura per permettere il click sui risultati
        setTimeout(() => {
            if (!resultsContainer.contains(document.activeElement)) {
                hideResults();
            }
        }, 200);
    }

    /**
     * Gestisce i click fuori dal dropdown
     */
    function handleClickOutside(e) {
        if (!searchInput.contains(e.target) && !resultsContainer.contains(e.target)) {
            hideResults();
        }
    }

    /**
     * Evidenzia i termini di ricerca nel testo
     */
    function highlightText(text, query) {
        if (!config.highlight || !query) {
            return escapeHtml(text);
        }

        const escaped = escapeHtml(text);
        const words = query.split(' ').filter(w => w.length >= 2);

        let result = escaped;
        words.forEach(word => {
            const regex = new RegExp('(' + escapeRegex(word) + ')', 'gi');
            result = result.replace(regex, '<mark>$1</mark>');
        });

        return result;
    }

    /**
     * Escape HTML per prevenire XSS
     */
    function escapeHtml(text) {
        if (!text) return '';
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    /**
     * Escape caratteri speciali per regex
     */
    function escapeRegex(string) {
        return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    // Inizializza quando il DOM è pronto
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
