# Smart Search 2.0 - Plugin PrestaShop per Ricerca Dinamica Intelligente

Un modulo PrestaShop avanzato che aggiunge una ricerca dinamica intelligente professionale con overlay fullscreen, fuzzy search, filtri dinamici, boosting prodotti, banner promozionali, prodotti consigliati basati su correlazioni d'acquisto e integrazione analytics.

**Autore:** Michele Pietrafesa
**Versione:** 2.6.1
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

### Pannello "Analisi delle ricerche" (stile Doofinder)

Un pannello di controllo che mostra cosa cercano davvero i clienti e trasforma le ricerche fallite in miglioramenti, con un click.

#### 1. Parole chiave senza risultati
La lista delle ricerche che non trovano nulla, ordinate per frequenza. Ogni riga è **azionabile**:
- **Crea sinonimo** inline: mappa la parola verso un termine corretto → alla ricerca successiva torna a dare risultati e sparisce dalla lista.
- **Ignora** (e "Mostra ignorate" / "Ripristina") per tenere pulita la lista.

#### 2. Ricerche più frequenti + CTR
Le ricerche top con numero di risultati, click, **CTR** (percentuale di ricerche seguite da un click) e ordini attribuiti. Un CTR basso su ricerche frequenti segnala risultati poco pertinenti da migliorare.

#### 3. Ricerche con pochi risultati
I "quasi-fallimenti" (meno di 5 prodotti) su cui intervenire con sinonimi, boosting o nuovi prodotti.

#### 4. Gestione sinonimi completa
Lista dei sinonimi attivi con **aggiungi / modifica / elimina / attiva-disattiva**, oltre a quelli creati dalle ricerche senza risultati e a quelli appresi in automatico (tab "Apprendimento").

---

### Parole Attaccate / Separate (Decompounding)

Risolve il caso in cui il cliente scrive la ricerca **tutta attaccata** mentre nel catalogo il nome del prodotto contiene uno spazio o un trattino:

| Ricerca cliente | Prodotto a catalogo | Prima | Ora |
|---|---|---|---|
| `neopecia` | Neo Pecia | ❌ nessun risultato | ✅ trovato |
| `euphidra` | Eu-Phidra | ❌ nessun risultato | ✅ trovato |
| `magnesiosupremo` | Magnesio Supremo | ❌ nessun risultato | ✅ trovato |

#### Come funziona
Quando una ricerca di una sola parola restituisce pochi risultati, il motore genera tutte le divisioni possibili della parola e **verifica sul catalogo reale** quale di queste esiste davvero — poi ripete la ricerca con la versione divisa. Nessuna divisione viene inventata: se `neo pecia` non esiste tra i prodotti, non viene usata.

Il caso inverso (cliente scrive `neo pecia`, prodotto "Neopecia") era già gestito dalla ricerca per sottostringa.

---

### Logica Match All + Rilassamento Progressivo (stile Doofinder)

Come il `match_all` di Doofinder: la ricerca privilegia i prodotti che contengono **tutte** le parole della query, non solo alcune.

#### Come funziona
1. Per ogni prodotto candidato viene calcolata la **copertura**: quante parole della query copre (una parola è coperta anche da una sua variante singolare/plurale, unità di misura o sinonimo attivo).
2. Vengono mostrati prima i prodotti che coprono **tutte** le parole.
3. Se questi sono meno della soglia minima configurabile (default 12), il requisito si **rilassa progressivamente** a N-1, N-2… parole, finché non ci sono abbastanza risultati.
4. Solo se anche così i risultati sono pochi, entra in gioco il fuzzy come rete di sicurezza.

**Esempio:** cercando "magnesio supremo 150g", in cima appaiono solo i prodotti che contengono *tutte e tre* le informazioni, non quelli che hanno solo "magnesio". Attivabile/disattivabile dal pannello.

---

### Criteri di Rilevanza Configurabili

Come il "Criteri di rilevanza" di Doofinder: dal pannello puoi regolare quanto pesano nel ranking i fattori non testuali (in percentuale, 100% = standard):

| Criterio | Effetto |
|---|---|
| **Peso vendite / bestseller** | 0 = ignora le vendite, 200 = doppio peso ai più venduti |
| **Peso novità** | quanto contano i prodotti aggiunti di recente |
| **Penalità prodotti esauriti** | 0 = nessuna, 30 = -30% (default), 100 = spinti in fondo |

La rilevanza testuale resta sempre il fattore primario; questi pesi regolano i "pareggi".

---

### Learning-to-Rank (il ranking impara dai click)

I risultati **migliorano da soli con l'uso**: i prodotti che gli utenti cliccano e acquistano per una determinata ricerca salgono automaticamente in cima **per quella ricerca**.

#### Come funziona
1. Ogni click su un risultato di ricerca viene registrato per la coppia **query → prodotto** (tabella `smartsearch_click_stats`), tramite `navigator.sendBeacon` (non rallenta la navigazione).
2. Le **conversioni** sono attribuite via hook `actionValidateOrder`: se l'ordine deriva da un click su un risultato (entro 2 ore), i prodotti acquistati ricevono il segnale più forte.
3. In fase di ranking, il punteggio finale diventa `rilevanza × boost × ltr`, dove il fattore LTR premia i prodotti più performanti per quella query.

#### Pesi dei segnali
| Evento | Peso |
|---|---|
| Click | 1 |
| Aggiunta al carrello | 3 |
| Ordine (conversione) | 6 |

Il prodotto con la performance migliore per una query riceve il **boost massimo** (configurabile, default +50%), gli altri in proporzione. La **rilevanza testuale resta prioritaria**: l'LTR riordina tra risultati già pertinenti, non introduce risultati fuori tema.

#### Controllo
Dal tab "Apprendimento": attiva/disattiva, regola la **forza del boost** (0-100%) e monitora il numero di segnali raccolti. Tutto interno, nessun costo ricorrente, nessun dato inviato a terzi.

---

### Apprendimento Automatico dei Sinonimi (Auto-Learning)

Il modulo impara **da solo** nuovi sinonimi e correzioni, senza dipendenze esterne né AI a pagamento — solo i dati che hai già in casa.

#### Come funziona
1. Ogni ricerca a **zero risultati** viene registrata (tabella `smartsearch_stats`).
2. A intervalli regolari, il motore confronta questi termini falliti con il **vocabolario del catalogo** (nomi prodotto, brand, codici) e con le ricerche di successo.
3. Usando **Levenshtein + fonetica (Metaphone/Soundex)** con bucketing per performance, deduce la correzione più probabile e le assegna un punteggio di **confidenza (0-100%)**.
4. I candidati sopra la **soglia di auto-approvazione** (default 85%) vengono attivati subito; gli altri finiscono in una **coda di revisione** nel pannello, dove li approvi o rifiuti con un click.

**Esempio:** molti utenti cercano `magnesio suprem` → 0 risultati → il sistema propone `suprem → supremo` con confidenza 92% → attivato in automatico. Alla ricerca successiva, la query viene espansa e trova i prodotti giusti.

> Il motore riconosce errori di battitura, varianti di scrittura e termini vicini. Non deduce significati concettuali astratti: per quelli restano disponibili i **sinonimi manuali**.

#### Sinonimi collegati alla ricerca
I sinonimi (sia manuali sia appresi) vengono ora applicati **direttamente nel percorso di ricerca principale** (indice FULLTEXT): ogni parola della query viene espansa con i suoi sinonimi attivi in modo bidirezionale.

#### Reindicizzazione programmata
Nuova opzione per ricostruire l'indice del catalogo **ogni N giorni** in automatico. Se sull'hosting non puoi configurare un cron reale, è disponibile uno **scheduler interno (pseudo-cron)** che avvia i job in background durante le visite al negozio, senza rallentare le pagine.

#### Pannello "Apprendimento"
- Attiva/disattiva auto-apprendimento e reindicizzazione
- Configura frequenza (giorni), frequenza minima delle ricerche e soglie di confidenza
- Pulsanti **"Impara ora"** e **"Reindicizza ora"**
- Statistiche in tempo reale e coda di revisione dei candidati

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

Per la reindicizzazione del catalogo e l'apprendimento dei sinonimi:
```bash
# Reindicizza il catalogo ogni notte alle 2:00
0 2 * * * /usr/bin/php /var/www/html/modules/smartsearch/cron/rebuild_index.php >> /var/log/smartsearch_index.log 2>&1

# Impara nuovi sinonimi ogni notte alle 4:00
0 4 * * * /usr/bin/php /var/www/html/modules/smartsearch/cron/learn_synonyms.php >> /var/log/smartsearch_learn.log 2>&1
```

> Se non puoi configurare un cron reale, lascia attivo lo **scheduler interno** dal pannello "Apprendimento": i job partiranno automaticamente durante le visite al negozio.

### Via HTTP (con token di sicurezza)
```
https://tuosito.com/modules/smartsearch/cron/calculate_correlations.php?token=TOKEN
https://tuosito.com/modules/smartsearch/cron/rebuild_index.php?token=TOKEN
https://tuosito.com/modules/smartsearch/cron/learn_synonyms.php?token=TOKEN
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
│   ├── SmartSearchLearner.php         # Apprendimento automatico sinonimi
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
│   ├── calculate_correlations.php     # Cron job correlazioni
│   ├── rebuild_index.php              # Cron ricostruzione indice
│   └── learn_synonyms.php             # Cron apprendimento sinonimi
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

### v2.6.1 (Luglio 2026)
- **FIX**: Parole scritte tutte attaccate ora trovano prodotti il cui nome contiene uno spazio o un trattino (es. `neopecia` → "Neo Pecia", `euphidra` → "Eu-Phidra")
- **NEW**: Decompounding/word splitting verificato sul catalogo (nessuno split inventato)
- **IMPROVEMENT**: L'ordinamento usa la percentuale di copertura invece del conteggio assoluto, così query di lunghezza diversa restano confrontabili

### v2.6.0 (Luglio 2026)
- **NEW**: Nuovo tab "Analisi" con i dati delle ricerche
- **NEW**: Lista parole chiave senza risultati con azione inline "Crea sinonimo" e "Ignora"
- **NEW**: Ricerche più frequenti con CTR e ordini attribuiti (dai dati di tracking)
- **NEW**: Ricerche con pochi risultati (< 5)
- **NEW**: Gestione sinonimi completa (aggiungi/modifica/elimina/attiva-disattiva)
- **NEW**: Colonna `handled` su `smartsearch_stats` per marcare le parole chiave gestite

### v2.5.0 (Luglio 2026)
- **NEW**: Logica `match_all` con rilassamento progressivo — privilegia i prodotti che coprono tutte le parole della query (stile Doofinder), rilassando solo se i risultati sono pochi
- **NEW**: Calcolo della copertura parole per prodotto (parola coperta anche da variante/unità/sinonimo)
- **NEW**: Criteri di rilevanza configurabili dal pannello (peso vendite, peso novità, penalità esauriti)
- **NEW**: Sezioni "Match All" e "Criteri di rilevanza" nel tab Impostazioni
- **IMPROVEMENT**: `calculateRelevanceScore` ora usa pesi configurabili invece di valori fissi

### v2.4.0 (Luglio 2026)
- **NEW**: Learning-to-rank — i prodotti più cliccati/acquistati per una query salgono automaticamente nei risultati di quella query
- **NEW**: Tracking click sui risultati via `navigator.sendBeacon` (tabella `smartsearch_click_stats`)
- **NEW**: Attribuzione conversioni via hook `actionValidateOrder` (finestra 2 ore)
- **NEW**: Controllo LTR nel tab "Apprendimento" (attiva/disattiva + forza del boost 0-100%)
- **NEW**: Endpoint AJAX `action=track` e potatura automatica dei segnali obsoleti
- **IMPROVEMENT**: Ranking finale = rilevanza × boost × learning-to-rank

### v2.3.0 (Luglio 2026)
- **NEW**: Apprendimento automatico dei sinonimi dai log di ricerca a zero risultati + vocabolario del catalogo (motore interno, nessuna dipendenza esterna)
- **NEW**: Coda di revisione dei sinonimi con auto-approvazione sopra soglia di confidenza configurabile
- **NEW**: Reindicizzazione del catalogo programmata ogni N giorni
- **NEW**: Scheduler interno (pseudo-cron) per hosting senza cron reale
- **NEW**: Nuovo tab "Apprendimento" nel pannello con statistiche, soglie e pulsanti "Impara ora" / "Reindicizza ora"
- **NEW**: Cron `learn_synonyms.php` per l'apprendimento automatico via CLI o HTTP
- **NEW**: Classe `SmartSearchLearner` (Levenshtein + fonetica con bucketing)
- **FIX**: I sinonimi (manuali e appresi) ora vengono applicati nel percorso di ricerca principale basato su indice FULLTEXT (prima venivano ignorati da `searchFromIndex`)
- **IMPROVEMENT**: Allineata la versione del modulo (codice/config.xml) a quella documentata

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
