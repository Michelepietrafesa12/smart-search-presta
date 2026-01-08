# Smart Search 2.0 - Plugin PrestaShop per Ricerca Dinamica Intelligente

Un modulo PrestaShop avanzato che aggiunge una ricerca dinamica intelligente simile a Doofinder, con overlay fullscreen, fuzzy search, filtri dinamici, boosting prodotti, banner promozionali e integrazione analytics.

**Autore:** Michele Pietrafesa
**Versione:** 2.0.0
**Compatibilita:** PrestaShop 1.7.0.0+

---

## Caratteristiche Principali

### Ricerca Intelligente
- **Overlay Fullscreen**: Interfaccia di ricerca moderna a schermo intero
- **Fuzzy Search**: Trova risultati anche con errori di battitura
- **Sinonimi**: Espansione automatica della ricerca (es. "smartphone" trova anche "cellulare")
- **Stemming e Fonetica**: Algoritmi avanzati per matching intelligente
- **Ricerca nelle descrizioni**: Cerca anche nel contenuto delle descrizioni prodotto

### Filtri Dinamici
- **Filtro Prezzo**: Range slider per prezzo min/max
- **Filtro Marca**: Selezione brand/manufacturer
- **Filtro Categorie**: Navigazione per categoria
- **Mobile Ottimizzato**: Pannello filtri dedicato per dispositivi mobili

### Product Boosting
- **Boost Prodotti**: Aumenta la visibilita di prodotti specifici nei risultati
- **Keywords Target**: Applica boost solo per ricerche specifiche
- **Scheduling**: Programma boost con date di inizio/fine
- **Pannello Admin**: Gestione semplice dal back-office

### Banner Promozionali
- **Banner nei Risultati**: Mostra promozioni durante la ricerca
- **3 Posizioni**: Top, Middle (dopo 4 prodotti), Bottom
- **Keywords Target**: Banner contestuali basati sulla ricerca
- **Upload Immagini**: Carica banner personalizzati
- **Responsive**: Ottimizzati per mobile e desktop

### Analytics e Tracking
- **Statistiche Ricerche**: Traccia query, click e conversioni
- **Webhook n8n**: Integrazione con workflow esterni
- **Conversion Tracking**: Traccia ordini originati da ricerche
- **Session Tracking**: Segui il percorso utente

### Performance Ottimizzate
- **Cache Statica Config**: Zero query DB per configurazioni
- **Cache Risultati**: Risultati ricerche memorizzati
- **JS Defer**: Caricamento non bloccante
- **Impatto Minimo**: ~310KB totali, ~53KB JS, ~26KB CSS

### Compatibilita
- **Payment Methods Safe**: Hook fail-safe che non bloccano mai checkout/pagamenti
- **Multi-shop**: Supporto completo multi-negozio
- **Multi-lingua**: Supporto complete multilingua

---

## Requisiti

- PrestaShop 1.7.0.0 o superiore
- PHP 7.2 o superiore
- MySQL 5.6 o superiore

---

## Installazione

### Metodo 1: Upload dal Back-Office

1. Scarica la cartella `smartsearch`
2. Comprimi in un file ZIP
3. Vai nel Back-Office > **Moduli > Gestione moduli**
4. Clicca **Carica un modulo**
5. Seleziona il file ZIP
6. Clicca **Configura**

### Metodo 2: FTP

1. Carica la cartella `smartsearch` in `/modules/`
2. Vai nel Back-Office > **Moduli > Gestione moduli**
3. Cerca "Smart Search" e clicca **Installa**

---

## Configurazione

### Pannello Admin Unificato

Il modulo include un pannello admin con 3 sezioni:

#### 1. Impostazioni
- Abilita/Disabilita modulo
- Caratteri minimi per ricerca
- Risultati massimi
- Fuzzy search, sinonimi, filtri
- Analytics webhook URL
- Cache

#### 2. Boosting
- Aggiungi boost a prodotti specifici
- Imposta moltiplicatore (1.5x, 2x, 3x...)
- Target keywords opzionale
- Toggle attivo/disattivo

#### 3. Banner
- Carica banner promozionali
- Scegli posizione (top/middle/bottom)
- Link opzionale
- Keywords target

---

## Struttura del Modulo

```
smartsearch/
├── smartsearch.php                    # File principale
├── config.xml                         # Configurazione XML
├── logo.svg                           # Logo modulo
├── classes/
│   ├── SmartSearchEngine.php          # Motore di ricerca
│   ├── SmartSearchCache.php           # Gestione cache
│   └── SmartSearchAnalytics.php       # Analytics
├── controllers/
│   ├── front/
│   │   └── search.php                 # Controller AJAX
│   └── admin/
│       ├── AdminSmartSearchDashboardController.php
│       ├── AdminSmartSearchBoostController.php
│       └── AdminSmartSearchBannersController.php
├── views/
│   ├── css/
│   │   ├── smartsearch.css            # Stili frontend
│   │   └── admin-dashboard.css        # Stili admin
│   ├── js/
│   │   └── smartsearch.js             # JavaScript frontend
│   ├── templates/
│   │   ├── hook/searchbar.tpl         # Template searchbar
│   │   └── admin/dashboard.tpl        # Template dashboard
│   └── img/banners/                   # Upload banner
└── docs/
    ├── n8n-workflow.json              # Workflow n8n esempio
    └── supabase-schema.sql            # Schema DB analytics
```

---

## API AJAX

### Endpoint Ricerca
```
GET /module/smartsearch/search?q={query}&filters={json}
```

### Endpoint Banners
```
GET /module/smartsearch/search?ajax=1&action=banners&q={query}
```

### Endpoint Analytics
```
POST /module/smartsearch/search?ajax=1&action=analytics
Content-Type: application/json

{
    "event_type": "search|click|add_to_cart|conversion",
    "query": "...",
    "product_id": 123,
    ...
}
```

---

## Changelog

### v2.0.0 (Gennaio 2025)
- **NEW**: Overlay fullscreen per ricerca
- **NEW**: Fuzzy search con tolleranza errori
- **NEW**: Sistema sinonimi configurabile
- **NEW**: Filtri dinamici (prezzo, marca, categorie)
- **NEW**: Pannello filtri mobile ottimizzato
- **NEW**: Product boosting con admin panel
- **NEW**: Banner promozionali nei risultati
- **NEW**: Analytics con webhook n8n
- **NEW**: Conversion tracking
- **NEW**: Dashboard admin unificata con tabs
- **NEW**: Cache statica per configurazioni
- **NEW**: Hooks fail-safe per pagamenti
- **FIX**: Compatibilita con tutti i metodi di pagamento
- **FIX**: Performance ottimizzate (no impatto su pagine)
- **IMPROVEMENT**: Ricerca nelle descrizioni prodotto
- **IMPROVEMENT**: Bestsellers/prodotti in evidenza in homepage search

### v1.0.0 (Rilascio Iniziale)
- Ricerca dinamica con AJAX
- Supporto prodotti e categorie
- Configurazione back-office
- Design responsive
- Dark mode support

---

## Peso del Modulo

| Componente | Dimensione |
|------------|------------|
| **Totale Modulo** | ~310 KB |
| JavaScript | ~53 KB |
| CSS | ~26 KB |
| PHP Classes | ~50 KB |
| Admin Controllers | ~67 KB |
| Templates | ~5 KB |

**Impatto Performance**: Minimo. JS caricato con `defer`, CSS non bloccante, configurazioni in cache statica.

---

## Compatibilita Metodi di Pagamento

Il modulo e stato testato e ottimizzato per non interferire con:
- PayPal
- Stripe
- Satispay
- Bonifico bancario
- Contrassegno
- Tutti i gateway di pagamento PrestaShop

Gli hook di tracking sono **fail-safe**: catturano Exception e Error per non bloccare MAI il checkout.

---

## Licenza

MIT License

---

## Autore

**Michele Pietrafesa**

Per segnalare bug o richiedere nuove funzionalita, apri una issue su GitHub.
