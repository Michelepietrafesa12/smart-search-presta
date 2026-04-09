<?php
/**
 * SmartSearch 2.0 - Motore di ricerca intelligente
 *
 * Implementa algoritmi avanzati:
 * - Fuzzy Search (Levenshtein Distance)
 * - Phonetic Search (Soundex, Metaphone)
 * - Stemming italiano
 * - Sinonimi
 * - Ranking intelligente
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SmartSearchEngine
{
    /** @var int */
    protected $idLang;

    /** @var int */
    protected $idShop;

    /** @var array */
    protected $synonyms = [];

    /** @var array */
    protected $stopWords = [];

    /** @var array */
    protected $boostedProducts = [];

    /** @var int */
    protected $fuzzyThreshold = 2;

    /** @var bool */
    protected $enableFuzzy = true;

    /** @var bool */
    protected $enablePhonetic = true;

    /** @var bool */
    protected $enableStemming = true;

    /** @var bool */
    protected $enableSynonyms = true;

    /**
     * Suffissi italiani per lo stemming
     */
    protected static $italianSuffixes = [
        'issimo', 'issima', 'issimi', 'issime',
        'amente', 'mente', 'ibile', 'abile',
        'azione', 'izione', 'uzione',
        'atore', 'atrice', 'atore', 'enza', 'anza',
        'aggio', 'aggio', 'eria', 'ismo', 'ista',
        'etto', 'etta', 'etti', 'ette',
        'ino', 'ina', 'ini', 'ine',
        'one', 'ona', 'oni', 'one',
        'are', 'ere', 'ire', 'ato', 'ito', 'uto',
        'ando', 'endo', 'ato', 'ito', 'uto',
        'ante', 'ente', 'zione',
        'ità', 'tà', 'ezza',
        'oso', 'osa', 'osi', 'ose',
        'ivo', 'iva', 'ivi', 'ive',
        'ale', 'ali', 'ile', 'ili',
        'mente', 'zione', 'sione',
        'i', 'e', 'a', 'o'
    ];

    /**
     * Stop words italiane
     */
    protected static $defaultStopWords = [
        'il', 'lo', 'la', 'i', 'gli', 'le', 'un', 'uno', 'una',
        'di', 'a', 'da', 'in', 'con', 'su', 'per', 'tra', 'fra',
        'e', 'o', 'ma', 'se', 'che', 'chi', 'cui', 'non',
        'più', 'quale', 'quanto', 'quanti', 'quanta', 'quante',
        'quello', 'questa', 'questi', 'queste', 'questo',
        'come', 'dove', 'quando', 'perché', 'anche',
        'the', 'a', 'an', 'and', 'or', 'but', 'in', 'on', 'at', 'to', 'for'
    ];

    /**
     * Costruttore
     */
    public function __construct($idLang, $idShop)
    {
        $this->idLang = (int)$idLang;
        $this->idShop = (int)$idShop;

        $this->loadConfiguration();
        $this->loadSynonyms();
        $this->loadBoostedProducts();
        $this->stopWords = self::$defaultStopWords;
    }

    /**
     * Carica configurazione
     */
    protected function loadConfiguration()
    {
        $this->enableFuzzy = (bool)Configuration::get('SMARTSEARCH_FUZZY_ENABLED');
        $this->enablePhonetic = (bool)Configuration::get('SMARTSEARCH_PHONETIC_ENABLED');
        $this->enableStemming = (bool)Configuration::get('SMARTSEARCH_STEMMING_ENABLED');
        $this->enableSynonyms = (bool)Configuration::get('SMARTSEARCH_SYNONYMS_ENABLED');
        $this->fuzzyThreshold = (int)Configuration::get('SMARTSEARCH_FUZZY_THRESHOLD') ?: 2;
    }

    /**
     * Carica sinonimi dal database
     */
    protected function loadSynonyms()
    {
        $sql = 'SELECT word, synonyms FROM `' . _DB_PREFIX_ . 'smartsearch_synonyms`
                WHERE id_shop = ' . $this->idShop . ' AND active = 1';

        $results = Db::getInstance()->executeS($sql);

        if ($results) {
            foreach ($results as $row) {
                $synonymList = array_map('trim', explode(',', $row['synonyms']));
                $this->synonyms[mb_strtolower($row['word'])] = $synonymList;
            }
        }
    }

    /**
     * Carica prodotti con boost
     */
    protected function loadBoostedProducts()
    {
        $sql = 'SELECT id_product, boost_value, keywords FROM `' . _DB_PREFIX_ . 'smartsearch_boost`
                WHERE id_shop = ' . $this->idShop . ' AND active = 1
                AND (date_start IS NULL OR date_start <= NOW())
                AND (date_end IS NULL OR date_end >= NOW())';

        $results = Db::getInstance()->executeS($sql);

        if ($results) {
            foreach ($results as $row) {
                $this->boostedProducts[$row['id_product']] = [
                    'boost' => (float)$row['boost_value'],
                    'keywords' => array_map('trim', explode(',', mb_strtolower($row['keywords'])))
                ];
            }
        }
    }

    /**
     * Esegue la ricerca intelligente
     */
    public function search($query, $limit = 10, $filters = [])
    {
        $originalQuery = $query;
        $query = $this->normalizeQuery($query);

        if (empty($query)) {
            return ['products' => [], 'facets' => [], 'total' => 0];
        }

        // Espandi la query con sinonimi
        $expandedTerms = $this->expandQueryWithSynonyms($query);

        // Genera varianti fonetiche
        $phoneticVariants = $this->enablePhonetic ? $this->getPhoneticVariants($query) : [];

        // Esegui ricerca SQL
        $products = $this->executeSearch($query, $expandedTerms, $phoneticVariants, $limit * 3, $filters);

        // Applica fuzzy matching sui risultati
        if ($this->enableFuzzy && count($products) < $limit) {
            $fuzzyProducts = $this->fuzzySearch($query, $limit, $filters);
            $products = $this->mergeResults($products, $fuzzyProducts);
        }

        // Calcola score e ordina
        $products = $this->calculateScores($products, $originalQuery);

        // Applica boosting
        $products = $this->applyBoosting($products, $originalQuery);

        // Ordina per score
        usort($products, function($a, $b) {
            return $b['_score'] <=> $a['_score'];
        });

        // Limita risultati
        $products = array_slice($products, 0, $limit);

        // Genera facets
        $facets = $this->generateFacets($query, $filters);

        return [
            'products' => $products,
            'facets' => $facets,
            'total' => count($products),
            'query' => $originalQuery,
            'expanded_terms' => $expandedTerms
        ];
    }

    /**
     * Normalizza la query
     */
    protected function normalizeQuery($query)
    {
        // Rimuovi caratteri speciali
        $query = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $query);

        // Converti in minuscolo
        $query = mb_strtolower(trim($query));

        // Rimuovi spazi multipli
        $query = preg_replace('/\s+/', ' ', $query);

        return $query;
    }

    /**
     * Rimuovi stop words
     */
    protected function removeStopWords($words)
    {
        return array_filter($words, function($word) {
            return !in_array($word, $this->stopWords) && strlen($word) > 1;
        });
    }

    /**
     * Espande la query con sinonimi
     */
    protected function expandQueryWithSynonyms($query)
    {
        if (!$this->enableSynonyms) {
            return [];
        }

        $words = explode(' ', $query);
        $expanded = [];

        foreach ($words as $word) {
            $word = mb_strtolower($word);
            if (isset($this->synonyms[$word])) {
                $expanded = array_merge($expanded, $this->synonyms[$word]);
            }

            // Cerca anche sinonimi inversi
            foreach ($this->synonyms as $key => $synonymList) {
                if (in_array($word, $synonymList)) {
                    $expanded[] = $key;
                    $expanded = array_merge($expanded, $synonymList);
                }
            }
        }

        return array_unique($expanded);
    }

    /**
     * Genera varianti fonetiche
     */
    protected function getPhoneticVariants($query)
    {
        $words = explode(' ', $query);
        $variants = [];

        foreach ($words as $word) {
            if (strlen($word) >= 3) {
                // Soundex
                $variants[] = soundex($word);

                // Metaphone
                $variants[] = metaphone($word);

                // Double Metaphone (se disponibile)
                if (function_exists('metaphone')) {
                    $variants[] = metaphone($word, 4);
                }
            }
        }

        return array_unique(array_filter($variants));
    }

    /**
     * Stemming italiano
     */
    public function stem($word)
    {
        if (!$this->enableStemming) {
            return $word;
        }

        $word = mb_strtolower($word);

        if (strlen($word) <= 3) {
            return $word;
        }

        // Applica i suffissi in ordine di lunghezza decrescente
        foreach (self::$italianSuffixes as $suffix) {
            $suffixLen = strlen($suffix);
            if (strlen($word) > $suffixLen + 2 && substr($word, -$suffixLen) === $suffix) {
                return substr($word, 0, -$suffixLen);
            }
        }

        return $word;
    }

    /**
     * Esegue la ricerca SQL principale
     */
    protected function executeSearch($query, $expandedTerms, $phoneticVariants, $limit, $filters)
    {
        $words = $this->removeStopWords(explode(' ', $query));

        if (empty($words)) {
            $words = explode(' ', $query);
        }

        // Costruisci condizioni di ricerca
        $conditions = [];

        // Ricerca esatta e parziale
        foreach ($words as $word) {
            $stemmed = $this->stem($word);

            $wordConditions = [
                "pl.name LIKE '%" . pSQL($word) . "%'",
                "pl.description_short LIKE '%" . pSQL($word) . "%'",
                "p.reference LIKE '%" . pSQL($word) . "%'",
                "p.ean13 LIKE '%" . pSQL($word) . "%'",
                "m.name LIKE '%" . pSQL($word) . "%'",
                "t.name LIKE '%" . pSQL($word) . "%'"
            ];

            // Aggiungi ricerca con stemming
            if ($stemmed !== $word) {
                $wordConditions[] = "pl.name LIKE '%" . pSQL($stemmed) . "%'";
                $wordConditions[] = "pl.description_short LIKE '%" . pSQL($stemmed) . "%'";
            }

            $conditions[] = '(' . implode(' OR ', $wordConditions) . ')';
        }

        // Aggiungi sinonimi
        foreach ($expandedTerms as $term) {
            $conditions[] = "(pl.name LIKE '%" . pSQL($term) . "%' OR pl.description_short LIKE '%" . pSQL($term) . "%')";
        }

        // Costruisci filtri
        $filterConditions = $this->buildFilterConditions($filters);

        $sql = '
            SELECT DISTINCT
                p.id_product,
                pl.name,
                pl.description_short,
                pl.link_rewrite,
                p.reference,
                p.price,
                p.id_category_default,
                cl.name AS category_name,
                cl.link_rewrite AS category_link_rewrite,
                m.name AS manufacturer_name,
                m.id_manufacturer,
                i.id_image,
                p.date_add,
                p.date_upd,
                COALESCE(ps.sales, 0) AS sales,
                COALESCE(AVG(pc.grade), 0) AS avg_rating,
                COUNT(DISTINCT pc.id_product_comment) AS review_count
            FROM `' . _DB_PREFIX_ . 'product` p
            INNER JOIN `' . _DB_PREFIX_ . 'product_lang` pl
                ON p.id_product = pl.id_product
                AND pl.id_lang = ' . $this->idLang . '
                AND pl.id_shop = ' . $this->idShop . '
            INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps2
                ON p.id_product = ps2.id_product
                AND ps2.id_shop = ' . $this->idShop . '
            LEFT JOIN `' . _DB_PREFIX_ . 'category_lang` cl
                ON p.id_category_default = cl.id_category
                AND cl.id_lang = ' . $this->idLang . '
            LEFT JOIN `' . _DB_PREFIX_ . 'manufacturer` m
                ON p.id_manufacturer = m.id_manufacturer
            LEFT JOIN `' . _DB_PREFIX_ . 'image` i
                ON p.id_product = i.id_product AND i.cover = 1
            LEFT JOIN `' . _DB_PREFIX_ . 'product_sale` ps
                ON p.id_product = ps.id_product
            LEFT JOIN `' . _DB_PREFIX_ . 'product_comment` pc
                ON p.id_product = pc.id_product AND pc.validate = 1
            LEFT JOIN `' . _DB_PREFIX_ . 'tag` t
                ON p.id_product = t.id_product AND t.id_lang = ' . $this->idLang . '
            WHERE ps2.active = 1
            AND ps2.visibility IN ("both", "search")
            AND (' . implode(' OR ', $conditions) . ')
            ' . $filterConditions . '
            GROUP BY p.id_product
            ORDER BY sales DESC, pl.name ASC
            LIMIT ' . (int)$limit;

        $results = Db::getInstance()->executeS($sql);

        return $results ?: [];
    }

    /**
     * Costruisce le condizioni dei filtri
     */
    protected function buildFilterConditions($filters)
    {
        $conditions = [];

        // Filtro categoria
        if (!empty($filters['category'])) {
            $categoryIds = array_map('intval', (array)$filters['category']);
            $conditions[] = 'p.id_category_default IN (' . implode(',', $categoryIds) . ')';
        }

        // Filtro prezzo
        if (!empty($filters['price_min'])) {
            $conditions[] = 'p.price >= ' . (float)$filters['price_min'];
        }
        if (!empty($filters['price_max'])) {
            $conditions[] = 'p.price <= ' . (float)$filters['price_max'];
        }

        // Filtro brand/manufacturer
        if (!empty($filters['manufacturer'])) {
            $manufacturerIds = array_map('intval', (array)$filters['manufacturer']);
            $conditions[] = 'p.id_manufacturer IN (' . implode(',', $manufacturerIds) . ')';
        }

        // Filtro disponibilità
        if (!empty($filters['in_stock'])) {
            $conditions[] = 'EXISTS (SELECT 1 FROM `' . _DB_PREFIX_ . 'stock_available` sa
                             WHERE sa.id_product = p.id_product AND sa.quantity > 0)';
        }

        // Filtro attributi
        if (!empty($filters['attributes'])) {
            foreach ($filters['attributes'] as $attrGroupId => $attrValues) {
                $attrIds = array_map('intval', (array)$attrValues);
                $conditions[] = 'EXISTS (SELECT 1 FROM `' . _DB_PREFIX_ . 'product_attribute_combination` pac
                                 INNER JOIN `' . _DB_PREFIX_ . 'product_attribute` pa ON pac.id_product_attribute = pa.id_product_attribute
                                 WHERE pa.id_product = p.id_product AND pac.id_attribute IN (' . implode(',', $attrIds) . '))';
            }
        }

        return !empty($conditions) ? ' AND ' . implode(' AND ', $conditions) : '';
    }

    /**
     * Ricerca fuzzy con Levenshtein
     */
    protected function fuzzySearch($query, $limit, $filters)
    {
        // Ottieni tutti i nomi prodotti per fuzzy matching
        $sql = 'SELECT p.id_product, pl.name
                FROM `' . _DB_PREFIX_ . 'product` p
                INNER JOIN `' . _DB_PREFIX_ . 'product_lang` pl ON p.id_product = pl.id_product
                    AND pl.id_lang = ' . $this->idLang . '
                INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON p.id_product = ps.id_product
                    AND ps.id_shop = ' . $this->idShop . '
                WHERE ps.active = 1
                LIMIT 1000';

        $allProducts = Db::getInstance()->executeS($sql);
        $matches = [];

        $queryWords = explode(' ', $query);

        foreach ($allProducts as $product) {
            $productName = mb_strtolower($product['name']);
            $productWords = explode(' ', $productName);

            $matchScore = 0;

            foreach ($queryWords as $queryWord) {
                if (strlen($queryWord) < 3) continue;

                foreach ($productWords as $productWord) {
                    if (strlen($productWord) < 3) continue;

                    $distance = levenshtein($queryWord, $productWord);
                    $maxLen = max(strlen($queryWord), strlen($productWord));

                    // Calcola threshold dinamico basato sulla lunghezza
                    $threshold = min($this->fuzzyThreshold, floor($maxLen / 3));

                    if ($distance <= $threshold) {
                        $matchScore += (1 - ($distance / $maxLen)) * 10;
                    }
                }
            }

            if ($matchScore > 0) {
                $matches[$product['id_product']] = $matchScore;
            }
        }

        // Ordina per score e prendi i migliori
        arsort($matches);
        $topIds = array_slice(array_keys($matches), 0, $limit);

        if (empty($topIds)) {
            return [];
        }

        // Costruisci CASE WHEN per assegnare lo score corretto a ciascun prodotto
        $caseWhen = 'CASE p.id_product';
        foreach ($topIds as $id) {
            $caseWhen .= ' WHEN ' . (int) $id . ' THEN ' . (float) $matches[$id];
        }
        $caseWhen .= ' ELSE 0 END';

        // Recupera i dettagli completi
        $sql = '
            SELECT DISTINCT
                p.id_product,
                pl.name,
                pl.description_short,
                pl.link_rewrite,
                p.reference,
                p.price,
                p.id_category_default,
                cl.name AS category_name,
                m.name AS manufacturer_name,
                i.id_image,
                ' . $caseWhen . ' AS fuzzy_score
            FROM `' . _DB_PREFIX_ . 'product` p
            INNER JOIN `' . _DB_PREFIX_ . 'product_lang` pl ON p.id_product = pl.id_product
                AND pl.id_lang = ' . $this->idLang . '
            LEFT JOIN `' . _DB_PREFIX_ . 'category_lang` cl ON p.id_category_default = cl.id_category
                AND cl.id_lang = ' . $this->idLang . '
            LEFT JOIN `' . _DB_PREFIX_ . 'manufacturer` m ON p.id_manufacturer = m.id_manufacturer
            LEFT JOIN `' . _DB_PREFIX_ . 'image` i ON p.id_product = i.id_product AND i.cover = 1
            WHERE p.id_product IN (' . implode(',', $topIds) . ')';

        $results = Db::getInstance()->executeS($sql);

        // Riassegna fuzzy score dai valori calcolati in PHP e riordina
        foreach ($results as &$result) {
            $result['fuzzy_score'] = $matches[$result['id_product']] ?? 0;
        }
        unset($result);

        usort($results, function ($a, $b) {
            return $b['fuzzy_score'] <=> $a['fuzzy_score'];
        });

        return $results ?: [];
    }

    /**
     * Unisce i risultati rimuovendo duplicati
     */
    protected function mergeResults($primary, $secondary)
    {
        $ids = array_column($primary, 'id_product');

        foreach ($secondary as $item) {
            if (!in_array($item['id_product'], $ids)) {
                $primary[] = $item;
            }
        }

        return $primary;
    }

    /**
     * Calcola gli score di rilevanza
     */
    protected function calculateScores($products, $query)
    {
        $queryWords = explode(' ', mb_strtolower($query));

        foreach ($products as &$product) {
            $score = 0;
            $name = mb_strtolower($product['name']);

            // Match esatto nel nome (peso alto)
            if (stripos($name, $query) !== false) {
                $score += 100;
            }

            // Match all'inizio del nome
            if (stripos($name, $query) === 0) {
                $score += 50;
            }

            // Match per ogni parola
            foreach ($queryWords as $word) {
                if (strlen($word) < 2) continue;

                if (stripos($name, $word) !== false) {
                    $score += 20;

                    // Bonus se la parola è all'inizio
                    if (stripos($name, $word) === 0) {
                        $score += 10;
                    }
                }

                // Match nel riferimento
                if (stripos($product['reference'] ?? '', $word) !== false) {
                    $score += 30;
                }

                // Match nel produttore
                if (stripos($product['manufacturer_name'] ?? '', $word) !== false) {
                    $score += 15;
                }
            }

            // Bonus per prodotti con vendite
            if (!empty($product['sales'])) {
                $score += min(20, $product['sales'] / 10);
            }

            // Bonus per prodotti con recensioni positive
            if (!empty($product['avg_rating']) && $product['avg_rating'] >= 4) {
                $score += 10;
            }

            // Fuzzy score se presente
            if (!empty($product['fuzzy_score'])) {
                $score += $product['fuzzy_score'];
            }

            $product['_score'] = $score;
        }

        return $products;
    }

    /**
     * Applica boosting ai prodotti
     */
    protected function applyBoosting($products, $query)
    {
        $queryWords = explode(' ', mb_strtolower($query));

        foreach ($products as &$product) {
            $productId = $product['id_product'];

            if (isset($this->boostedProducts[$productId])) {
                $boost = $this->boostedProducts[$productId];

                // Verifica se le keyword corrispondono
                $keywordMatch = empty($boost['keywords']);

                if (!$keywordMatch) {
                    foreach ($queryWords as $word) {
                        if (in_array($word, $boost['keywords'])) {
                            $keywordMatch = true;
                            break;
                        }
                    }
                }

                if ($keywordMatch) {
                    $product['_score'] *= $boost['boost'];
                    $product['_boosted'] = true;
                }
            }
        }

        return $products;
    }

    /**
     * Genera facets per i filtri
     */
    protected function generateFacets($query, $currentFilters)
    {
        $facets = [];

        // Facet categorie
        $facets['categories'] = $this->getCategoryFacet($query);

        // Facet produttori
        $facets['manufacturers'] = $this->getManufacturerFacet($query);

        // Facet prezzo
        $facets['price_range'] = $this->getPriceRangeFacet($query);

        // Facet attributi (colori, taglie, ecc.)
        $facets['attributes'] = $this->getAttributeFacets($query);

        return $facets;
    }

    /**
     * Facet per categorie
     */
    protected function getCategoryFacet($query)
    {
        $words = explode(' ', $query);
        $conditions = [];

        foreach ($words as $word) {
            if (strlen($word) >= 2) {
                $conditions[] = "pl.name LIKE '%" . pSQL($word) . "%'";
            }
        }

        if (empty($conditions)) {
            return [];
        }

        $sql = '
            SELECT cl.id_category, cl.name, COUNT(DISTINCT p.id_product) AS count
            FROM `' . _DB_PREFIX_ . 'product` p
            INNER JOIN `' . _DB_PREFIX_ . 'product_lang` pl ON p.id_product = pl.id_product
                AND pl.id_lang = ' . $this->idLang . '
            INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON p.id_product = ps.id_product
                AND ps.id_shop = ' . $this->idShop . '
            INNER JOIN `' . _DB_PREFIX_ . 'category_lang` cl ON p.id_category_default = cl.id_category
                AND cl.id_lang = ' . $this->idLang . '
            WHERE ps.active = 1
            AND (' . implode(' OR ', $conditions) . ')
            GROUP BY cl.id_category
            ORDER BY count DESC
            LIMIT 10';

        return Db::getInstance()->executeS($sql) ?: [];
    }

    /**
     * Facet per produttori
     */
    protected function getManufacturerFacet($query)
    {
        $words = explode(' ', $query);
        $conditions = [];

        foreach ($words as $word) {
            if (strlen($word) >= 2) {
                $conditions[] = "pl.name LIKE '%" . pSQL($word) . "%'";
            }
        }

        if (empty($conditions)) {
            return [];
        }

        $sql = '
            SELECT m.id_manufacturer, m.name, COUNT(DISTINCT p.id_product) AS count
            FROM `' . _DB_PREFIX_ . 'product` p
            INNER JOIN `' . _DB_PREFIX_ . 'product_lang` pl ON p.id_product = pl.id_product
                AND pl.id_lang = ' . $this->idLang . '
            INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON p.id_product = ps.id_product
                AND ps.id_shop = ' . $this->idShop . '
            INNER JOIN `' . _DB_PREFIX_ . 'manufacturer` m ON p.id_manufacturer = m.id_manufacturer
            WHERE ps.active = 1
            AND p.id_manufacturer > 0
            AND (' . implode(' OR ', $conditions) . ')
            GROUP BY m.id_manufacturer
            ORDER BY count DESC
            LIMIT 10';

        return Db::getInstance()->executeS($sql) ?: [];
    }

    /**
     * Facet per range di prezzo
     */
    protected function getPriceRangeFacet($query)
    {
        $words = explode(' ', $query);
        $conditions = [];

        foreach ($words as $word) {
            if (strlen($word) >= 2) {
                $conditions[] = "pl.name LIKE '%" . pSQL($word) . "%'";
            }
        }

        if (empty($conditions)) {
            return ['min' => 0, 'max' => 0];
        }

        $sql = '
            SELECT MIN(p.price) AS min_price, MAX(p.price) AS max_price
            FROM `' . _DB_PREFIX_ . 'product` p
            INNER JOIN `' . _DB_PREFIX_ . 'product_lang` pl ON p.id_product = pl.id_product
                AND pl.id_lang = ' . $this->idLang . '
            INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON p.id_product = ps.id_product
                AND ps.id_shop = ' . $this->idShop . '
            WHERE ps.active = 1
            AND (' . implode(' OR ', $conditions) . ')';

        $result = Db::getInstance()->getRow($sql);

        return [
            'min' => (float)($result['min_price'] ?? 0),
            'max' => (float)($result['max_price'] ?? 0)
        ];
    }

    /**
     * Facet per attributi
     */
    protected function getAttributeFacets($query)
    {
        $words = explode(' ', $query);
        $conditions = [];

        foreach ($words as $word) {
            if (strlen($word) >= 2) {
                $conditions[] = "pl.name LIKE '%" . pSQL($word) . "%'";
            }
        }

        if (empty($conditions)) {
            return [];
        }

        $sql = '
            SELECT agl.id_attribute_group, agl.name AS group_name,
                   al.id_attribute, al.name AS attribute_name,
                   COUNT(DISTINCT p.id_product) AS count
            FROM `' . _DB_PREFIX_ . 'product` p
            INNER JOIN `' . _DB_PREFIX_ . 'product_lang` pl ON p.id_product = pl.id_product
                AND pl.id_lang = ' . $this->idLang . '
            INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON p.id_product = ps.id_product
                AND ps.id_shop = ' . $this->idShop . '
            INNER JOIN `' . _DB_PREFIX_ . 'product_attribute` pa ON p.id_product = pa.id_product
            INNER JOIN `' . _DB_PREFIX_ . 'product_attribute_combination` pac ON pa.id_product_attribute = pac.id_product_attribute
            INNER JOIN `' . _DB_PREFIX_ . 'attribute` a ON pac.id_attribute = a.id_attribute
            INNER JOIN `' . _DB_PREFIX_ . 'attribute_lang` al ON a.id_attribute = al.id_attribute
                AND al.id_lang = ' . $this->idLang . '
            INNER JOIN `' . _DB_PREFIX_ . 'attribute_group_lang` agl ON a.id_attribute_group = agl.id_attribute_group
                AND agl.id_lang = ' . $this->idLang . '
            WHERE ps.active = 1
            AND (' . implode(' OR ', $conditions) . ')
            GROUP BY a.id_attribute
            ORDER BY agl.name, count DESC';

        $results = Db::getInstance()->executeS($sql) ?: [];

        // Raggruppa per attribute group
        $grouped = [];
        foreach ($results as $row) {
            $groupId = $row['id_attribute_group'];
            if (!isset($grouped[$groupId])) {
                $grouped[$groupId] = [
                    'id' => $groupId,
                    'name' => $row['group_name'],
                    'values' => []
                ];
            }
            $grouped[$groupId]['values'][] = [
                'id' => $row['id_attribute'],
                'name' => $row['attribute_name'],
                'count' => $row['count']
            ];
        }

        return array_values($grouped);
    }

    /**
     * Ottieni suggerimenti "Forse cercavi..."
     */
    public function getDidYouMean($query)
    {
        $suggestions = [];

        // Ottieni termini di ricerca popolari simili
        $sql = '
            SELECT DISTINCT search_query, COUNT(*) AS frequency
            FROM `' . _DB_PREFIX_ . 'smartsearch_stats`
            WHERE results_count > 0
            AND id_lang = ' . $this->idLang . '
            AND id_shop = ' . $this->idShop . '
            GROUP BY search_query
            HAVING frequency > 5
            ORDER BY frequency DESC
            LIMIT 100';

        $popularTerms = Db::getInstance()->executeS($sql) ?: [];

        foreach ($popularTerms as $term) {
            $distance = levenshtein(mb_strtolower($query), mb_strtolower($term['search_query']));
            $maxLen = max(strlen($query), strlen($term['search_query']));

            if ($distance > 0 && $distance <= ceil($maxLen / 4)) {
                $suggestions[] = [
                    'term' => $term['search_query'],
                    'distance' => $distance,
                    'frequency' => $term['frequency']
                ];
            }
        }

        // Ordina per distanza e frequenza
        usort($suggestions, function($a, $b) {
            if ($a['distance'] === $b['distance']) {
                return $b['frequency'] <=> $a['frequency'];
            }
            return $a['distance'] <=> $b['distance'];
        });

        return array_slice($suggestions, 0, 3);
    }
}
