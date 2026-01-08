# SmartSearch - Plugin PrestaShop per Ricerca Dinamica

Un modulo PrestaShop che aggiunge una ricerca dinamica avanzata simile a Doofinder, con suggerimenti istantanei mentre l'utente digita.

## Caratteristiche

- **Ricerca istantanea**: Risultati mostrati mentre l'utente digita
- **Suggerimenti intelligenti**: Basati sulle ricerche precedenti
- **Ricerca nei prodotti**: Nome, descrizione, riferimento, EAN, UPC, produttore
- **Ricerca nelle categorie**: Mostra categorie corrispondenti
- **Evidenziazione termini**: I termini cercati vengono evidenziati nei risultati
- **Prezzo e sconti**: Mostra prezzi con eventuali sconti barrati
- **Immagini prodotti**: Miniature dei prodotti nei risultati
- **Navigazione da tastiera**: Frecce su/giù, Enter per selezionare, Esc per chiudere
- **Design responsive**: Adattato per desktop e mobile
- **Dark mode**: Supporto automatico per dark mode
- **Statistiche ricerche**: Tracciamento delle ricerche effettuate
- **Completamente configurabile**: Tutte le opzioni gestibili dal back-office

## Requisiti

- PrestaShop 1.7.0.0 o superiore
- PHP 7.2 o superiore
- MySQL 5.6 o superiore

## Installazione

### Metodo 1: Upload manuale

1. Scarica la cartella `smartsearch`
2. Comprimi la cartella in un file ZIP
3. Vai nel Back-Office di PrestaShop
4. Naviga in **Moduli > Gestione moduli**
5. Clicca su **Carica un modulo**
6. Seleziona il file ZIP
7. Clicca su **Configura** per personalizzare le impostazioni

### Metodo 2: FTP

1. Scarica la cartella `smartsearch`
2. Carica la cartella via FTP in `/modules/`
3. Vai nel Back-Office di PrestaShop
4. Naviga in **Moduli > Gestione moduli**
5. Cerca "Smart Search" e clicca **Installa**

## Configurazione

Nel back-office, vai su **Moduli > Smart Search > Configura**:

| Opzione | Descrizione | Default |
|---------|-------------|---------|
| Abilita Smart Search | Attiva/disattiva il modulo | Sì |
| Caratteri minimi | Numero minimo di caratteri per avviare la ricerca | 2 |
| Risultati massimi | Numero massimo di prodotti nel dropdown | 8 |
| Tempo di debounce | Ritardo (ms) prima di effettuare la ricerca | 300 |
| Mostra prezzo | Visualizza i prezzi nei risultati | Sì |
| Mostra immagine | Visualizza le miniature dei prodotti | Sì |
| Mostra descrizione | Visualizza la descrizione breve | Sì |
| Mostra categoria | Visualizza la categoria del prodotto | Sì |
| Evidenzia termini | Evidenzia i termini cercati nei risultati | Sì |

## Struttura del modulo

```
smartsearch/
├── smartsearch.php          # File principale del modulo
├── config.xml               # Configurazione XML
├── logo.svg                 # Logo del modulo
├── controllers/
│   └── front/
│       └── search.php       # Controller AJAX per la ricerca
├── views/
│   ├── css/
│   │   └── smartsearch.css  # Stili CSS
│   └── js/
│       └── smartsearch.js   # JavaScript per ricerca dinamica
├── sql/                     # Query SQL
├── classes/                 # Classi PHP
└── translations/            # File di traduzione
```

## API AJAX

Il modulo espone un endpoint AJAX per la ricerca:

```
GET /module/smartsearch/search?q={query}
```

Risposta JSON:
```json
{
    "products": [
        {
            "id": 1,
            "name": "Nome Prodotto",
            "url": "https://...",
            "image": "https://...",
            "price": "€ 29,99",
            "price_old": "€ 39,99",
            "description": "Descrizione breve...",
            "category": "Categoria",
            "manufacturer": "Brand",
            "reference": "REF123",
            "in_stock": true
        }
    ],
    "categories": [
        {
            "id": 1,
            "name": "Categoria",
            "url": "https://..."
        }
    ],
    "suggestions": ["termine suggerito"],
    "total": 5,
    "query": "termine cercato"
}
```

## Personalizzazione CSS

Puoi personalizzare lo stile sovrascrivendo le classi CSS nel tuo tema:

```css
/* Container risultati */
.smartsearch-results { }

/* Singolo prodotto */
.smartsearch-product { }

/* Nome prodotto */
.smartsearch-product-name { }

/* Prezzo */
.smartsearch-product-price .current-price { }
.smartsearch-product-price .old-price { }

/* Evidenziazione */
.smartsearch-results mark { }
```

## Risoluzione problemi

### La ricerca non funziona
1. Verifica che il modulo sia attivo
2. Svuota la cache di PrestaShop
3. Controlla la console del browser per errori JavaScript
4. Verifica che l'URL AJAX sia corretto

### Nessun risultato
1. Verifica che i prodotti siano attivi e visibili
2. Controlla che il prodotto abbia almeno il nome compilato
3. Prova ad abbassare il numero minimo di caratteri

### Problemi di stile
1. Il CSS potrebbe essere sovrascritto dal tema
2. Usa `!important` se necessario
3. Verifica la specificità dei selettori

## Changelog

### 1.0.0
- Rilascio iniziale
- Ricerca dinamica con AJAX
- Supporto prodotti e categorie
- Configurazione back-office
- Design responsive
- Dark mode support

## Licenza

MIT License

## Supporto

Per segnalare bug o richiedere nuove funzionalità, apri una issue su GitHub.
