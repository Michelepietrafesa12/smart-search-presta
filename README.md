# Smart Search 2.0 - Plugin PrestaShop per Ricerca Dinamica Intelligente

Un modulo PrestaShop avanzato che aggiunge una ricerca dinamica intelligente professionale con overlay fullscreen, fuzzy search, filtri dinamici, boosting prodotti, banner promozionali, prodotti consigliati basati su correlazioni d'acquisto e integrazione analytics.

**Autore:** Michele Pietrafesa
**Versione:** 2.2.0
**Compatibilità:** PrestaShop 1.7.0.0+

---

## Caratteristiche Principali

### Ricerca Intelligente con Sistema di Scoring

Il motore di ricerca utilizza un sistema di scoring a 3 livelli di priorita:

#### Priorita 0 - Match Completo (1000+ punti)
Quando **TUTTE** le parole cercate sono presenti nel **nome del prodotto**:
- Base: **1000 punti**
- Bonus query esatta nel nome: **+300 punti**
- Bonus nome inizia con query: **+100 punti**
- Bonus parole nel brand: **+10 punti** per parola
- Bonus parole nel reference: **+10 punti** per parola

**Esempio:** Cerca "scitec nutrition cla" → Prodotto "Scitec Nutrition Cla Acido Linoleico..." = 1300+ punti

#### Priorita 1 - Match Query Esatta (700-900 punti)
Quando la query esatta (come stringa) appare in:
- Nome prodotto: **800 punti** (+100 se inizia con query)
- Reference/SKU: **750 punti**
- Brand: **700 punti**

#### Priorita 2 - Match Parziale (<200 punti)
Quando solo alcune parole matchano:
- Parola nel nome: **+40 punti**
- Parola nel reference: **+35 punti**
- Parola nel brand: **+30 punti**
- Bonus 75%+ match: **+100 punti**
- Bonus 50%+ match: **+50 punti**
- **PENALITA** <50% match: **x0.3** (riduce drasticamente)

#### Bonus Aggiuntivi (tutti i livelli)
- Match in descrizione: **+3 punti** per parola
- Bestseller (>100 vendite): **+20 punti**
- Bestseller (>50 vendite): **+15 punti**
- Bestseller (>10 vendite): **+10 punti**
- Prodotto nuovo (<7 giorni): **+15 punti**
- Prodotto recente (<30 giorni): **+10 punti**

### Ottimizzazione SQL
La query SQL pre-ordina i risultati per numero di parole matchate nel nome, garantendo che i prodotti con match completo siano sempre inclusi prima del LIMIT.

---

### Variazioni Linguistiche Italiane

Il motore espande automaticamente le parole cercate con variazioni singolare/plurale:

| Ricerca | Trova anche |
|---------|-------------|
| barretta | barrette |
| prodotto | prodotti |
| integratore | integratori |
| proteina | proteine |
| energia | energie |

**Regole implementate:**
- -o ↔ -i (prodotto/prodotti)
- -a ↔ -e (barretta/barrette)
- -e ↔ -i (azione/azioni)
- -ia ↔ -ie (energia/energie)
- -co ↔ -chi (pacco/pacchi)
- -go ↔ -ghi (fungo/funghi)

---

### Normalizzazione Unita di Misura

Il motore riconosce e normalizza le unita di misura:

| Ricerca | Trova anche |
|---------|-------------|
| 350g | 350 g, 350gr, 350 gr |
| 500ml | 500 ml |
| 1kg | 1 kg |
| 100caps | 100 caps, 100cps, 100 capsule |
| 60tab | 60 tab, 60tabs, 60 compresse |

---

### Ricerca Dinamica AJAX

- **Debounce**: Attende 300ms dopo l'ultima digitazione
- **Minimo caratteri**: 2 caratteri per attivare la ricerca
- **Infinite Scroll**: Carica 24 prodotti per volta
- **Massimo risultati**: 200 prodotti totali
- **Cache**: Risultati memorizzati per 5 minuti

---

### Suggerimenti di Ricerca (Autocomplete)

Mentre l'utente digita, vengono mostrati suggerimenti basati su:

1. **Ricerche popolari** che iniziano con la query
2. **Ricerche popolari** che contengono la query
3. **Nomi prodotti** che matchano
4. **Nomi brand** che matchano

Navigazione con tastiera: Frecce Su/Giu, Enter per selezionare, Escape per chiudere.

---

### "Forse Cercavi..." (Did You Mean)

Se la ricerca restituisce pochi risultati (<3), il sistema suggerisce alternative usando:
- Distanza Levenshtein per query simili
- Nomi prodotti correlati
- Brand simili

---

### Fuzzy Search

Se i risultati sono insufficienti (<5), attiva ricerca tollerante:
- **SOUNDEX**: Match fonetico
- **Pattern fuzzy**: Wildcard tra lettere
- **Consonanti**: Ignora vocali per typo comuni
- **Troncamento**: Ricerca senza prima/ultima lettera

---

### Filtri Dinamici

- **Filtro Prezzo**: Range slider per prezzo min/max
- **Filtro Marca**: Checkbox per brand (senza limite, mostra tutti)
- **Filtro Categorie**: Checkbox per categoria (senza limite, mostra tutte)
- **Mobile Ottimizzato**: Pannello filtri dedicato per dispositivi mobili

---

### Product Boosting

- **Boost Prodotti**: Aumenta la visibilita di prodotti specifici nei risultati
- **Keywords Target**: Applica boost solo per ricerche specifiche
- **Scheduling**: Programma boost con date di inizio/fine
- **Iniezione**: Prodotti boostati appaiono anche se non matchano direttamente
- **Pannello Admin**: Gestione semplice dal back-office

---

### Banner Promozionali

- **Banner nei Risultati**: Mostra promozioni durante la ricerca
- **3 Posizioni**: Top, Middle (dopo 4 prodotti), Bottom
- **Keywords Target**: Banner contestuali basati sulla ricerca
- **Upload Immagini**: Carica banner personalizzati
- **Responsive**: Ottimizzati per mobile e desktop

---

### Analytics e Tracking

- **Statistiche Ricerche**: Traccia query, risultati, frequenza
- **Webhook n8n**: Integrazione con workflow esterni
- **Conversion Tracking**: Traccia ordini originati da ricerche
- **Session Tracking**: Segui il percorso utente

---

### Prodotti Consigliati (Correlazioni)

Sistema intelligente di raccomandazioni basato sugli acquisti:

#### Pagina Prodotto
- **"Chi ha acquistato questo ha comprato anche"**: Slider con prodotti correlati
- Basato su ordini reali degli ultimi 180 giorni (configurabile)
- Fallback automatico a prodotti della stessa categoria se non ci sono correlazioni

#### Pagina Carrello
- **"Completa il tuo ordine"**: Slider con prodotti complementari
- Analizza tutti i prodotti nel carrello
- **Bottone "Aggiungi"**: Aggiunge direttamente al carrello senza uscire dal checkout
- Fallback a bestseller se correlazioni insufficienti

#### Pannello di Controllo
- Abilita/disabilita raccomandazioni
- Configura periodo di analisi (giorni)
- Configura acquisti minimi per correlazione
- Pulsante "Calcola Correlazioni Ora" per aggiornamento manuale
- Statistiche in tempo reale (correlazioni totali, prodotti, score medio)

#### Cron Job Automatico
- Script per calcolo automatico delle correlazioni
- Eseguibile da CLI o via HTTP con token di sicurezza
- Consigliato: esecuzione notturna giornaliera

---

### Penalità Prodotti Esauriti

I prodotti non disponibili vengono penalizzati del 30% nello scoring, facendoli apparire più in basso nei risultati di ricerca rispetto ai prodotti disponibili.

---

### Performance Ottimizzate

- **Cache Statica Config**: Zero query DB per configurazioni
- **Cache Risultati**: Risultati ricerche memorizzati 5 minuti
- **SQL Ottimizzato**: Pre-ordinamento per relevanza prima del LIMIT
- **JS Defer**: Caricamento non bloccante
- **Impatto Minimo**: ~310KB totali, ~53KB JS, ~26KB CSS

---

### Compatibilita

- **Payment Methods Safe**: Hook fail-safe che non bloccano mai checkout/pagamenti
- **Multi-shop**: Supporto completo multi-negozio
- **Multi-lingua**: Supporto completo multilingua

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

#### 4. Correlazioni (Prodotti Consigliati)
- Abilita/disabilita raccomandazioni
- Periodo analisi ordini (default: 180 giorni)
- Acquisti minimi per correlazione (default: 2)
- Pulsante calcolo manuale
- Statistiche in tempo reale

---

## Configurazione Cron Job

Per mantenere aggiornate le correlazioni prodotti, configura un cron job:

### Da Command Line (consigliato)
```bash
# Ogni notte alle 3:00
0 3 * * * /usr/bin/php /var/www/html/modules/smartsearch/cron/calculate_correlations.php >> /var/log/smartsearch_cron.log 2>&1
```

### Via HTTP (con token di sicurezza)
```
https://tuosito.com/modules/smartsearch/cron/calculate_correlations.php?token=TOKEN
```

Per generare il token:
```bash
php -r "require_once('/path/to/config/config.inc.php'); echo md5(_COOKIE_KEY_ . 'smartsearch_cron');"
```

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
│       ├── AdminSmartSearchBannersController.php
│       ├── AdminSmartSearchSynonymsController.php
│       └── AdminSmartSearchAnalyticsController.php
├── cron/
│   └── calculate_correlations.php     # Cron job correlazioni
├── views/
│   ├── css/
│   │   ├── smartsearch.css            # Stili frontend
│   │   └── admin-dashboard.css        # Stili admin
│   ├── js/
│   │   └── smartsearch.js             # JavaScript frontend
│   ├── templates/
│   │   ├── hook/
│   │   │   ├── searchbar.tpl          # Template searchbar
│   │   │   └── recommendations.tpl    # Template slider consigliati
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
GET /module/smartsearch/search?ajax=1&action=search&q={query}&offset={0}&limit={24}
```

**Parametri:**
- `q`: Query di ricerca
- `offset`: Offset paginazione (default: 0)
- `limit`: Risultati per pagina (default: 24, max: 50)
- `category`: ID categorie (comma-separated)
- `manufacturer`: ID brand (comma-separated)
- `price_min`: Prezzo minimo
- `price_max`: Prezzo massimo

**Risposta:**
```json
{
  "products": [...],
  "total": 24,
  "total_count": 150,
  "offset": 0,
  "limit": 24,
  "has_more": true,
  "facets": {...},
  "banners": [...],
  "did_you_mean": [...]
}
```

### Endpoint Suggerimenti
```
GET /module/smartsearch/search?ajax=1&action=suggestions&q={query}
```

**Risposta:**
```json
{
  "success": true,
  "suggestions": [
    {"query": "...", "count": 10, "results": 25, "type": "popular"},
    {"query": "...", "count": 0, "results": 0, "type": "product"},
    {"query": "...", "count": 0, "results": 0, "type": "brand"}
  ]
}
```

### Endpoint Filtri
```
GET /module/smartsearch/search?ajax=1&action=filters
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

### v2.2.0 (Gennaio 2025)
- **NEW**: Slider prodotti consigliati in pagina prodotto ("Chi ha acquistato...")
- **NEW**: Slider prodotti consigliati nel carrello ("Completa il tuo ordine")
- **NEW**: Sistema correlazioni basato su ordini reali
- **NEW**: Bottone "Aggiungi al carrello" diretto nello slider carrello
- **NEW**: Pannello controllo correlazioni con statistiche
- **NEW**: Cron job per calcolo automatico correlazioni
- **NEW**: Penalità -30% per prodotti esauriti nello scoring
- **NEW**: Hook fail-safe con try/catch Throwable
- **FIX**: Compatibilità completa checkout e pagamenti
- **IMPROVEMENT**: Lazy loading per overlay ricerca
- **IMPROVEMENT**: AbortController per richieste di ricerca
- **IMPROVEMENT**: Indici database ottimizzati per analytics

### v2.1.0 (Gennaio 2025)
- **NEW**: Sistema di scoring a 3 livelli di priorità
- **NEW**: Priorita assoluta per match completo nel nome prodotto
- **NEW**: Pre-ordinamento SQL per numero parole matchate
- **NEW**: Variazioni singolare/plurale italiano
- **NEW**: Normalizzazione unita di misura (350g = 350 g)
- **NEW**: Infinite scroll AJAX (24 prodotti per volta)
- **NEW**: Suggerimenti autocomplete da ricerche popolari
- **NEW**: Endpoint AJAX per suggerimenti
- **NEW**: Navigazione tastiera per suggerimenti
- **FIX**: Filtri brand/categorie senza limite (rimosso LIMIT 50)
- **FIX**: Prodotti con match completo sempre inclusi nei risultati
- **FIX**: CSS checkbox filtri su mobile
- **IMPROVEMENT**: Limite risultati aumentato a 200
- **IMPROVEMENT**: Query SQL ottimizzata con ORDER BY relevanza

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
