<?php
/**
 * SmartSearch 2.0 - Controller AJAX per la ricerca dinamica
 * Versione semplificata e robusta
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SmartsearchSearchModuleFrontController extends ModuleFrontController
{
    /**
     * Ottiene variazioni italiane di una parola (singolare/plurale)
     * Es: barretta -> [barretta, barrette], prodotto -> [prodotto, prodotti]
     */
    protected function getItalianWordVariations($word)
    {
        $word = mb_strtolower(trim($word));
        $variations = [$word];
        $len = mb_strlen($word);

        if ($len < 3) {
            return $variations;
        }

        // Ottieni le ultime lettere
        $last1 = mb_substr($word, -1);
        $last2 = mb_substr($word, -2);
        $last3 = mb_substr($word, -3);
        $base = mb_substr($word, 0, -1);
        $base2 = mb_substr($word, 0, -2);
        $base3 = mb_substr($word, 0, -3);

        // === REGOLE PLURALE -> SINGOLARE ===

        // -i -> -o (prodotti -> prodotto, integratori -> integratore)
        if ($last1 === 'i') {
            $variations[] = $base . 'o';
            $variations[] = $base . 'e'; // alcuni plurali in -i vengono da singolari in -e
        }

        // -e -> -a (barrette -> barretta, proteine -> proteina)
        if ($last1 === 'e') {
            $variations[] = $base . 'a';
            $variations[] = $base . 'o'; // energie -> energio? no, ma copre casi strani
        }

        // -he -> -a (bottiglie -> bottiglia, confezioni -> confezione)
        if ($last2 === 'he') {
            $variations[] = $base2 . 'a';
            $variations[] = $base2 . 'ia';
        }

        // -ie -> -ia (energie -> energia, calorie -> caloria)
        if ($last2 === 'ie') {
            $variations[] = $base2 . 'ia';
        }

        // -chi -> -co (pacchi -> pacco)
        if ($last3 === 'chi') {
            $variations[] = $base3 . 'co';
        }

        // -ghi -> -go (funghi -> fungo)
        if ($last3 === 'ghi') {
            $variations[] = $base3 . 'go';
        }

        // -ni -> -ne o -no (azioni -> azione)
        if ($last2 === 'ni') {
            $variations[] = $base2 . 'ne';
            $variations[] = $base2 . 'no';
        }

        // -zi -> -za o -zo (razzi -> razzo)
        if ($last2 === 'zi') {
            $variations[] = $base2 . 'za';
            $variations[] = $base2 . 'zo';
        }

        // === REGOLE SINGOLARE -> PLURALE ===

        // -o -> -i (prodotto -> prodotti)
        if ($last1 === 'o') {
            $variations[] = $base . 'i';
        }

        // -a -> -e (barretta -> barrette)
        if ($last1 === 'a') {
            $variations[] = $base . 'e';
            // -ia -> -ie (energia -> energie)
            if ($last2 === 'ia') {
                $variations[] = $base2 . 'ie';
            }
            // -ca -> -che (amica -> amiche)
            if ($last2 === 'ca') {
                $variations[] = $base2 . 'che';
            }
            // -ga -> -ghe (bottega -> botteghe)
            if ($last2 === 'ga') {
                $variations[] = $base2 . 'ghe';
            }
        }

        // -e -> -i (azione -> azioni, integratore -> integratori)
        if ($last1 === 'e') {
            $variations[] = $base . 'i';
        }

        // -co -> -chi (pacco -> pacchi)
        if ($last2 === 'co') {
            $variations[] = $base2 . 'chi';
        }

        // -go -> -ghi (fungo -> funghi)
        if ($last2 === 'go') {
            $variations[] = $base2 . 'ghi';
        }

        // Rimuovi duplicati e ritorna
        return array_unique($variations);
    }

    /**
     * Espande le parole della query con variazioni singolare/plurale
     */
    protected function expandQueryWords($words)
    {
        $expanded = [];
        $synonymMap = $this->getTableSynonyms();
        foreach ($words as $word) {
            // Aggiungi variazioni italiane (singolare/plurale)
            $variations = $this->getItalianWordVariations($word);
            foreach ($variations as $var) {
                if (!in_array($var, $expanded)) {
                    $expanded[] = $var;
                }
            }

            // Aggiungi variazioni per unità di misura (350g, 500ml, etc.)
            $unitVariations = $this->getUnitVariations($word);
            foreach ($unitVariations as $var) {
                if (!in_array($var, $expanded)) {
                    $expanded[] = $var;
                }
            }

            // Aggiungi sinonimi configurati o appresi automaticamente
            $wl = mb_strtolower($word);
            if (isset($synonymMap[$wl])) {
                foreach ($synonymMap[$wl] as $syn) {
                    if ($syn !== '' && !in_array($syn, $expanded)) {
                        $expanded[] = $syn;
                    }
                }
            }

            // Aggiungi correzioni brand (typo correction)
            if (mb_strlen($word) >= 4) {
                $brandCorrections = $this->findSimilarBrandNames($word);
                foreach ($brandCorrections as $correction) {
                    if (!in_array($correction, $expanded)) {
                        $expanded[] = $correction;
                    }
                }
            }
        }
        return $expanded;
    }

    /**
     * Cache statica per la mappa dei sinonimi (parola => [sinonimi]).
     */
    protected static $tableSynonymsCache = null;

    /**
     * Carica i sinonimi attivi dalla tabella smartsearch_synonyms.
     * Include sia quelli inseriti manualmente sia quelli appresi in automatico.
     * La mappa e' bidirezionale: cercando un sinonimo si espande anche verso
     * la parola principale e gli altri sinonimi del gruppo.
     *
     * @return array
     */
    protected function getTableSynonyms()
    {
        if (self::$tableSynonymsCache !== null) {
            return self::$tableSynonymsCache;
        }

        self::$tableSynonymsCache = [];

        // Rispetta il toggle globale dei sinonimi
        if (!Configuration::get('SMARTSEARCH_SYNONYMS_ENABLED')) {
            return self::$tableSynonymsCache;
        }

        $idShop = (int) $this->context->shop->id;
        $rows = Db::getInstance()->executeS(
            'SELECT word, synonyms FROM `' . _DB_PREFIX_ . 'smartsearch_synonyms`
             WHERE id_shop = ' . $idShop . ' AND active = 1'
        );

        if (!$rows) {
            return self::$tableSynonymsCache;
        }

        $map = [];
        foreach ($rows as $row) {
            $word = mb_strtolower(trim($row['word']));
            $syns = array_filter(array_map(function ($s) {
                return mb_strtolower(trim($s));
            }, explode(',', $row['synonyms'])));

            if ($word === '' || empty($syns)) {
                continue;
            }

            // Gruppo completo: parola principale + tutti i sinonimi
            $group = array_unique(array_merge([$word], $syns));

            // Ogni termine del gruppo espande verso tutti gli altri
            foreach ($group as $term) {
                foreach ($group as $other) {
                    if ($term !== $other) {
                        $map[$term][] = $other;
                    }
                }
            }
        }

        foreach ($map as $k => $v) {
            $map[$k] = array_values(array_unique($v));
        }

        self::$tableSynonymsCache = $map;
        return self::$tableSynonymsCache;
    }

    /**
     * Cache per i nomi dei brand (evita query ripetute)
     */
    protected static $brandNamesCache = null;

    /**
     * Cache per gli ID dei prodotti bestseller (top 20)
     */
    protected static $bestsellerIdsCache = null;

    /**
     * Ottiene gli ID dei prodotti più venduti (top 20)
     * Usa cache statica per evitare query ripetute
     */
    protected function getBestsellerIds($idShop)
    {
        if (self::$bestsellerIdsCache === null) {
            $sql = '
                SELECT p.id_product
                FROM ' . _DB_PREFIX_ . 'product p
                INNER JOIN ' . _DB_PREFIX_ . 'product_shop ps
                    ON p.id_product = ps.id_product
                    AND ps.id_shop = ' . (int)$idShop . '
                LEFT JOIN ' . _DB_PREFIX_ . 'order_detail od
                    ON od.product_id = p.id_product
                LEFT JOIN ' . _DB_PREFIX_ . 'orders o
                    ON o.id_order = od.id_order
                    AND o.valid = 1
                WHERE p.active = 1 AND ps.active = 1
                GROUP BY p.id_product
                HAVING SUM(IFNULL(od.product_quantity, 0)) > 0
                ORDER BY SUM(IFNULL(od.product_quantity, 0)) DESC
                LIMIT 20
            ';

            $results = Db::getInstance()->executeS($sql);
            self::$bestsellerIdsCache = [];

            if ($results) {
                foreach ($results as $row) {
                    self::$bestsellerIdsCache[] = (int)$row['id_product'];
                }
            }
        }

        return self::$bestsellerIdsCache;
    }

    /**
     * Trova brand names simili usando Levenshtein distance
     * Es: "etocsport" -> "ethicsport"
     */
    protected function findSimilarBrandNames($word)
    {
        $corrections = [];
        $wordLower = mb_strtolower($word);
        $wordLen = mb_strlen($wordLower);

        // Carica cache brand se non presente
        if (self::$brandNamesCache === null) {
            $idShop = (int)$this->context->shop->id;
            $sql = '
                SELECT DISTINCT m.name
                FROM ' . _DB_PREFIX_ . 'manufacturer m
                INNER JOIN ' . _DB_PREFIX_ . 'product p ON p.id_manufacturer = m.id_manufacturer
                INNER JOIN ' . _DB_PREFIX_ . 'product_shop ps ON p.id_product = ps.id_product AND ps.id_shop = ' . $idShop . '
                WHERE m.active = 1 AND ps.active = 1
            ';
            $brands = Db::getInstance()->executeS($sql);
            self::$brandNamesCache = [];
            if ($brands) {
                foreach ($brands as $brand) {
                    self::$brandNamesCache[] = $brand['name'];
                }
            }
        }

        // Cerca brand simili
        foreach (self::$brandNamesCache as $brandName) {
            $brandLower = mb_strtolower($brandName);
            $brandLen = mb_strlen($brandLower);

            // Skip se lunghezze troppo diverse (>50% differenza)
            if (abs($brandLen - $wordLen) > max($brandLen, $wordLen) * 0.5) {
                continue;
            }

            // Match esatto - già gestito altrove
            if ($brandLower === $wordLower) {
                continue;
            }

            // 1. Levenshtein distance - tolleranza basata sulla lunghezza
            // Per parole lunghe (>=8 caratteri) tollera fino a 3 errori
            // Per parole medie (>=5 caratteri) tollera fino a 2 errori
            // Per parole corte tollera 1 errore
            $maxDistance = $wordLen >= 8 ? 3 : ($wordLen >= 5 ? 2 : 1);
            $distance = levenshtein($wordLower, $brandLower);

            if ($distance > 0 && $distance <= $maxDistance) {
                $corrections[] = $brandLower;
                continue;
            }

            // 2. Controllo consonanti (ignora vocali) - per typo di vocali
            $wordConsonants = preg_replace('/[aeiouàèéìòù]/iu', '', $wordLower);
            $brandConsonants = preg_replace('/[aeiouàèéìòù]/iu', '', $brandLower);

            if (mb_strlen($wordConsonants) >= 4 && $wordConsonants === $brandConsonants) {
                $corrections[] = $brandLower;
                continue;
            }

            // 3. Soundex per match fonetici
            if (mb_strlen($wordLower) >= 4 && soundex($wordLower) === soundex($brandLower)) {
                $corrections[] = $brandLower;
                continue;
            }

            // 4. Contenimento parziale (una contiene l'altra con almeno 80% match)
            if ($wordLen >= 5 && $brandLen >= 5) {
                if (strpos($brandLower, $wordLower) !== false || strpos($wordLower, $brandLower) !== false) {
                    $corrections[] = $brandLower;
                    continue;
                }

                // Inizia o finisce allo stesso modo (primi/ultimi 4+ caratteri)
                $prefixLen = min(4, $wordLen - 1, $brandLen - 1);
                if ($prefixLen >= 3) {
                    $wordPrefix = mb_substr($wordLower, 0, $prefixLen);
                    $brandPrefix = mb_substr($brandLower, 0, $prefixLen);
                    $wordSuffix = mb_substr($wordLower, -$prefixLen);
                    $brandSuffix = mb_substr($brandLower, -$prefixLen);

                    if ($wordPrefix === $brandPrefix || $wordSuffix === $brandSuffix) {
                        // Verifica che non sia troppo diverso
                        if (levenshtein($wordLower, $brandLower) <= max($wordLen, $brandLen) * 0.4) {
                            $corrections[] = $brandLower;
                            continue;
                        }
                    }
                }
            }
        }

        return array_unique($corrections);
    }

    /**
     * Genera variazioni per unità di misura.
     * Oltre ai sinonimi formato (350g/350 g/350gr), genera conversioni
     * cross-unità: 1kg → 1000g, 1000mg → 1g, 500ml → 0.5l, etc.
     */
    protected function getUnitVariations($word)
    {
        $variations = [];

        // Pattern: numero (intero o decimale con . o ,) + unità
        if (preg_match('/^(\d+(?:[.,]\d+)?)(g|gr|kg|mg|ml|l|lt|cl|oz|lb|caps|cps|tab|tabs|compresse|bustine|porzioni)$/i', $word, $matches)) {
            $numberRaw = $matches[1];
            $number = str_replace(',', '.', $numberRaw);
            $value = (float) $number;
            $unit = mb_strtolower($matches[2]);

            // Variazioni formato base (con/senza spazio, sinonimi unità)
            foreach ($this->getUnitSynonyms($unit) as $syn) {
                $variations[] = $numberRaw . $syn;
                $variations[] = $numberRaw . ' ' . $syn;
            }

            // Conversioni cross-unità (1kg → 1000g, 1000mg → 1g, etc.)
            foreach ($this->convertUnit($value, $unit) as $conv) {
                $convNum = $this->formatUnitNumber($conv['value']);
                if ($convNum === null) {
                    continue; // Numero con troppe cifre decimali, skip
                }
                foreach ($this->getUnitSynonyms($conv['unit']) as $syn) {
                    $variations[] = $convNum . $syn;
                    $variations[] = $convNum . ' ' . $syn;
                }
            }
        }

        // Solo numero — potrebbe essere seguito da unità come parola separata
        if (preg_match('/^\d+$/', $word)) {
            $variations[] = $word;
        }

        return array_unique($variations);
    }

    /**
     * Restituisce i sinonimi di formato per un'unità di misura.
     * Es: "g" → ["g", "gr"], "l" → ["l", "lt"]
     */
    protected function getUnitSynonyms($unit)
    {
        $map = [
            'g'  => ['g', 'gr'],
            'gr' => ['g', 'gr'],
            'kg' => ['kg'],
            'mg' => ['mg'],
            'ml' => ['ml'],
            'l'  => ['l', 'lt'],
            'lt' => ['l', 'lt'],
            'cl' => ['cl'],
            'caps' => ['caps', 'cps', 'capsule'],
            'cps'  => ['caps', 'cps', 'capsule'],
            'tab'  => ['tab', 'tabs', 'compresse'],
            'tabs' => ['tab', 'tabs', 'compresse'],
            'compresse' => ['tab', 'tabs', 'compresse'],
            'bustine'   => ['bustine'],
            'porzioni'  => ['porzioni'],
            'oz' => ['oz'],
            'lb' => ['lb'],
        ];
        return $map[$unit] ?? [$unit];
    }

    /**
     * Conversioni cross-unità di misura.
     * Restituisce array di [value, unit] equivalenti in altre unità.
     */
    protected function convertUnit($value, $unit)
    {
        $conversions = [];

        switch ($unit) {
            case 'g':
            case 'gr':
                // g → kg (1000g = 1kg)
                if ($value >= 100) {
                    $conversions[] = ['value' => $value / 1000, 'unit' => 'kg'];
                }
                // g → mg (per piccoli valori, es. vitamine: 1g = 1000mg)
                if ($value <= 10) {
                    $conversions[] = ['value' => $value * 1000, 'unit' => 'mg'];
                }
                break;

            case 'kg':
                // kg → g (1kg = 1000g)
                $conversions[] = ['value' => $value * 1000, 'unit' => 'g'];
                break;

            case 'mg':
                // mg → g (1000mg = 1g)
                if ($value >= 100) {
                    $conversions[] = ['value' => $value / 1000, 'unit' => 'g'];
                }
                break;

            case 'ml':
                // ml → l (1000ml = 1l)
                if ($value >= 100) {
                    $conversions[] = ['value' => $value / 1000, 'unit' => 'l'];
                }
                break;

            case 'l':
            case 'lt':
                // l → ml (1l = 1000ml)
                $conversions[] = ['value' => $value * 1000, 'unit' => 'ml'];
                break;

            case 'cl':
                // cl → ml (1cl = 10ml)
                $conversions[] = ['value' => $value * 10, 'unit' => 'ml'];
                // cl → l (100cl = 1l)
                if ($value >= 10) {
                    $conversions[] = ['value' => $value / 100, 'unit' => 'l'];
                }
                break;
        }

        return $conversions;
    }

    /**
     * Formatta un numero per le variazioni unità.
     * Restituisce null se il numero ha troppe cifre decimali.
     */
    protected function formatUnitNumber($value)
    {
        // Intero
        if (abs($value - round($value)) < 0.001) {
            return (string) (int) round($value);
        }
        // Max 1 decimale
        $r1 = round($value, 1);
        if (abs($value - $r1) < 0.01) {
            return number_format($r1, 1, '.', '');
        }
        // Max 2 decimali
        $r2 = round($value, 2);
        if (abs($value - $r2) < 0.001) {
            return number_format($r2, 2, '.', '');
        }
        // Troppi decimali — non utile come termine di ricerca
        return null;
    }

    public function displayAjaxSearch()
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');

        try {
            $query = Tools::getValue('q', '');
            $query = trim(strip_tags($query));

            $idLang = (int)$this->context->language->id;
            $idShop = (int)$this->context->shop->id;

            // Wildcard "*" restituisce prodotti recenti
            if ($query === '*') {
                $products = $this->getRecentProducts($idLang, $idShop, 20);
                die(json_encode([
                    'products' => $products,
                    'categories' => [],
                    'total' => count($products),
                    'query' => $query
                ], JSON_UNESCAPED_UNICODE));
            }

            // Validazione query
            if (mb_strlen($query) < 2) {
                die(json_encode([
                    'products' => [],
                    'categories' => [],
                    'total' => 0,
                    'query' => $query
                ], JSON_UNESCAPED_UNICODE));
            }

            // Ottieni filtri dalla richiesta
            $filters = $this->getFiltersFromRequest();

            // Parametri paginazione
            $offset = max(0, (int)Tools::getValue('offset', 0));
            $limit = min(50, max(10, (int)Tools::getValue('limit', 24))); // min 10, max 50, default 24

            // Parametro ordinamento
            $sort = Tools::getValue('sort', 'relevance');
            $allowedSorts = ['relevance', 'price_asc', 'price_desc', 'name_asc', 'name_desc'];
            if (!in_array($sort, $allowedSorts)) {
                $sort = 'relevance';
            }

            // Controlla cache per query popolari (include offset/limit/sort nella chiave)
            $filterHash = md5(json_encode($filters));
            $cacheKey = 'smartsearch_' . md5($query . '_' . $idLang . '_' . $idShop . '_' . $offset . '_' . $limit . '_' . $sort . '_' . $filterHash);
            $cachedResult = $this->getFromCache($cacheKey);

            if ($cachedResult !== false) {
                // Aggiungi flag cache hit per debug
                $cachedResult['_cached'] = true;
                die(json_encode($cachedResult, JSON_UNESCAPED_UNICODE));
            }

            // Rate limit solo per ricerche non in cache
            if (!$this->checkRateLimit(120, 60, 'search')) {
                $this->dieRateLimit();
            }

            // Ricerca prodotti con filtri
            $allProducts = $this->searchProducts($query, $idLang, $idShop, $filters);

            // Applica ordinamento server-side
            if ($sort !== 'relevance') {
                usort($allProducts, function ($a, $b) use ($sort) {
                    switch ($sort) {
                        case 'price_asc':
                            return ((float)($a['price_raw'] ?? 0)) <=> ((float)($b['price_raw'] ?? 0));
                        case 'price_desc':
                            return ((float)($b['price_raw'] ?? 0)) <=> ((float)($a['price_raw'] ?? 0));
                        case 'name_asc':
                            return strcasecmp($a['name'] ?? '', $b['name'] ?? '');
                        case 'name_desc':
                            return strcasecmp($b['name'] ?? '', $a['name'] ?? '');
                        default:
                            return 0;
                    }
                });
            }

            $totalCount = count($allProducts);

            // Applica paginazione
            $products = array_slice($allProducts, $offset, $limit);
            $hasMore = ($offset + $limit) < $totalCount;

            // Ricerca categorie (solo alla prima richiesta)
            $categories = ($offset === 0) ? $this->searchCategories($query, $idLang, $idShop) : [];

            // Ottieni banner attivi per questa query (solo alla prima richiesta)
            $banners = ($offset === 0) ? $this->getBannersForQuery($query, $idShop) : [];

            // Costruisci facets filtrati per i prodotti trovati (solo alla prima richiesta)
            $facets = [];
            if ($offset === 0) {
                $matchedIds = array_map(function ($p) {
                    return (int) ($p['id_product'] ?? 0);
                }, $allProducts);
                $facets = $this->buildFacets($idLang, $idShop, $matchedIds);
            }

            // Genera suggerimenti "Forse cercavi..." se pochi risultati (solo alla prima richiesta)
            $didYouMean = [];
            if ($offset === 0 && $totalCount < 3) {
                $didYouMean = $this->getDidYouMeanSuggestions($query, $idLang, $idShop);
            }

            $response = [
                'products' => $products,
                'categories' => $categories,
                'total' => count($products),
                'total_count' => $totalCount,
                'offset' => $offset,
                'limit' => $limit,
                'has_more' => $hasMore,
                'query' => $query,
                'facets' => $facets,
                'banners' => $banners,
                'did_you_mean' => $didYouMean
            ];

            // Salva in cache se ha risultati (cache per 5 minuti)
            if (count($products) > 0) {
                $this->saveToCache($cacheKey, $response, 300);
            }

            // Traccia ricerca per statistiche (per "forse cercavi" futuro)
            $this->trackSearchQuery($query, count($products), $idLang, $idShop);

            die(json_encode($response, JSON_UNESCAPED_UNICODE));

        } catch (Throwable $e) {
            // Log error only in dev mode, never expose to frontend
            if (defined('_PS_MODE_DEV_') && _PS_MODE_DEV_) {
                PrestaShopLogger::addLog('SmartSearch error: ' . $e->getMessage(), 3, null, 'SmartSearch');
            }
            die(json_encode([
                'products' => [],
                'categories' => [],
                'total' => 0,
                'query' => Tools::getValue('q', '')
            ], JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * AJAX endpoint per ottenere i filtri disponibili
     */
    public function displayAjaxFilters()
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');

        try {
            $idLang = (int)$this->context->language->id;
            $idShop = (int)$this->context->shop->id;

            // Riusa buildFacets() che ha la propria cache dedicata
            $facets = $this->buildFacets($idLang, $idShop);

            // Mappa al formato atteso dall'endpoint /filters
            $brands = array_map(function ($m) {
                return [
                    'id' => $m['id_manufacturer'],
                    'name' => $m['name'],
                    'count' => $m['count']
                ];
            }, $facets['manufacturers'] ?? []);

            $categories = array_map(function ($c) {
                return [
                    'id' => $c['id_category'],
                    'name' => $c['name'],
                    'count' => $c['count']
                ];
            }, $facets['categories'] ?? []);

            die(json_encode([
                'brands' => $brands,
                'categories' => $categories,
                'price_range' => $facets['price_range'] ?? ['min' => 0, 'max' => 1000]
            ], JSON_UNESCAPED_UNICODE));

        } catch (Throwable $e) {
            if (defined('_PS_MODE_DEV_') && _PS_MODE_DEV_) {
                PrestaShopLogger::addLog('SmartSearch filters error: ' . $e->getMessage(), 3, null, 'SmartSearch');
            }
            die(json_encode([
                'brands' => [],
                'categories' => [],
                'price_range' => ['min' => 0, 'max' => 1000]
            ], JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * Ottieni marche disponibili con conteggio prodotti
     */
    protected function getAvailableBrands($idLang, $idShop)
    {
        $sql = '
            SELECT m.id_manufacturer, m.name, COUNT(DISTINCT p.id_product) as product_count
            FROM ' . _DB_PREFIX_ . 'manufacturer m
            INNER JOIN ' . _DB_PREFIX_ . 'product p ON p.id_manufacturer = m.id_manufacturer
            INNER JOIN ' . _DB_PREFIX_ . 'product_shop ps ON p.id_product = ps.id_product AND ps.id_shop = ' . (int)$idShop . '
            WHERE p.active = 1 AND ps.active = 1
            GROUP BY m.id_manufacturer
            HAVING product_count > 0
            ORDER BY m.name ASC';

        $results = Db::getInstance()->executeS($sql);

        $brands = [];
        if ($results) {
            foreach ($results as $row) {
                $brands[] = [
                    'id' => (int)$row['id_manufacturer'],
                    'name' => $row['name'],
                    'count' => (int)$row['product_count']
                ];
            }
        }
        return $brands;
    }

    /**
     * Ottieni categorie disponibili con conteggio prodotti
     */
    protected function getAvailableCategories($idLang, $idShop)
    {
        $sql = '
            SELECT c.id_category, cl.name, COUNT(DISTINCT cp.id_product) as product_count
            FROM ' . _DB_PREFIX_ . 'category c
            INNER JOIN ' . _DB_PREFIX_ . 'category_lang cl ON c.id_category = cl.id_category AND cl.id_lang = ' . (int)$idLang . ' AND cl.id_shop = ' . (int)$idShop . '
            INNER JOIN ' . _DB_PREFIX_ . 'category_shop cs ON c.id_category = cs.id_category AND cs.id_shop = ' . (int)$idShop . '
            INNER JOIN ' . _DB_PREFIX_ . 'category_product cp ON c.id_category = cp.id_category
            INNER JOIN ' . _DB_PREFIX_ . 'product p ON cp.id_product = p.id_product
            INNER JOIN ' . _DB_PREFIX_ . 'product_shop ps ON p.id_product = ps.id_product AND ps.id_shop = ' . (int)$idShop . '
            WHERE c.active = 1 AND p.active = 1 AND ps.active = 1 AND c.id_category > 2
            GROUP BY c.id_category
            HAVING product_count > 0
            ORDER BY cl.name ASC';

        $results = Db::getInstance()->executeS($sql);

        $categories = [];
        if ($results) {
            foreach ($results as $row) {
                $categories[] = [
                    'id' => (int)$row['id_category'],
                    'name' => $row['name'],
                    'count' => (int)$row['product_count']
                ];
            }
        }
        return $categories;
    }

    /**
     * Ottieni range prezzi disponibili
     */
    protected function getPriceRange($idShop)
    {
        $sql = '
            SELECT
                FLOOR(MIN(ps.price)) as min_price,
                CEIL(MAX(ps.price)) as max_price
            FROM ' . _DB_PREFIX_ . 'product_shop ps
            INNER JOIN ' . _DB_PREFIX_ . 'product p ON ps.id_product = p.id_product
            WHERE ps.id_shop = ' . (int)$idShop . ' AND p.active = 1 AND ps.active = 1';

        $result = Db::getInstance()->getRow($sql);

        // Recupera l'IVA di default dal paese del negozio
        $taxRate = $this->getDefaultTaxRate();
        $taxMultiplier = 1 + $taxRate / 100;
        $minPrice = (int)(($result['min_price'] ?? 0) * $taxMultiplier);
        $maxPrice = (int)(($result['max_price'] ?? 1000) * $taxMultiplier);

        return [
            'min' => $minPrice,
            'max' => $maxPrice
        ];
    }

    /**
     * Recupera l'aliquota IVA di default del negozio
     * Compatibile con PrestaShop 8.x e MySQL 8.0+
     */
    protected static $defaultTaxRateCache = null;

    protected function getDefaultTaxRate()
    {
        if (self::$defaultTaxRateCache !== null) {
            return self::$defaultTaxRateCache;
        }

        try {
            // Prova a ottenere l'IVA dal paese di default del negozio
            $idCountry = (int)Configuration::get('PS_COUNTRY_DEFAULT');

            if ($idCountry) {
                // Query compatibile con MySQL 8.0+ e PrestaShop 8.x
                // Usa subquery per evitare problemi con ORDER BY COUNT(*) e GROUP BY
                $sql = '
                    SELECT t.rate, COUNT(*) as usage_count
                    FROM ' . _DB_PREFIX_ . 'tax t
                    INNER JOIN ' . _DB_PREFIX_ . 'tax_rule tr ON t.id_tax = tr.id_tax
                    INNER JOIN ' . _DB_PREFIX_ . 'tax_rules_group trg ON tr.id_tax_rules_group = trg.id_tax_rules_group
                    WHERE tr.id_country = ' . $idCountry . '
                    AND trg.active = 1
                    AND t.active = 1
                    GROUP BY t.id_tax, t.rate
                    ORDER BY usage_count DESC
                    LIMIT 1';

                $result = Db::getInstance()->getRow($sql);

                if ($result && isset($result['rate']) && $result['rate'] > 0) {
                    self::$defaultTaxRateCache = (float)$result['rate'];
                    return self::$defaultTaxRateCache;
                }
            }
        } catch (Throwable $e) {
            // Se la query fallisce, continua con il fallback
        }

        // Fallback: cerca qualsiasi aliquota IVA attiva
        try {
            $sql = 'SELECT rate FROM ' . _DB_PREFIX_ . 'tax WHERE active = 1 ORDER BY rate DESC LIMIT 1';
            $rate = Db::getInstance()->getValue($sql);

            if ($rate !== false && $rate > 0) {
                self::$defaultTaxRateCache = (float)$rate;
                return self::$defaultTaxRateCache;
            }
        } catch (Throwable $e) {
            // Ignora errori
        }

        self::$defaultTaxRateCache = 22.0;
        return self::$defaultTaxRateCache; // Default 22% se non trovata
    }

    /**
     * Costruisce i facets per i filtri della sidebar.
     * Usa una singola query UNION ALL per brand + categorie + prezzo.
     *
     * Se $productIds è fornito, i facets riflettono solo quei prodotti
     * (facets contestuali alla ricerca). Se vuoto, restituisce i facets
     * globali dell'intero catalogo (cachati 10 min).
     *
     * @param int   $idLang
     * @param int   $idShop
     * @param int[] $productIds  ID dei prodotti matchati (opzionale)
     */
    protected function buildFacets($idLang, $idShop, array $productIds = [])
    {
        // Facets globali (no productIds): cachati 10 min
        // Facets contestuali: cachati con hash degli ID (più breve TTL)
        $isFiltered = !empty($productIds);
        if ($isFiltered) {
            sort($productIds);
            $cacheKey = 'smartsearch_facets_' . (int) $idLang . '_' . (int) $idShop . '_' . md5(implode(',', $productIds));
            $cached = $this->getFromCache($cacheKey, 300);
        } else {
            $cacheKey = 'smartsearch_facets_' . (int) $idLang . '_' . (int) $idShop;
            $cached = $this->getFromCache($cacheKey, 600);
        }
        if ($cached !== false) {
            return $cached;
        }

        // Condizione filtro prodotti per facets contestuali
        $productFilter = '';
        if ($isFiltered) {
            $safeIds = array_map('intval', array_slice($productIds, 0, 500));
            $productFilter = ' AND p.id_product IN (' . implode(',', $safeIds) . ')';
        }

        // Query aggregata unica: brand + categorie + price range
        $sql = '
            SELECT \'brand\' AS facet_type,
                   m.id_manufacturer AS facet_id,
                   m.name AS facet_name,
                   COUNT(DISTINCT p.id_product) AS product_count,
                   0 AS min_val, 0 AS max_val
            FROM ' . _DB_PREFIX_ . 'manufacturer m
            INNER JOIN ' . _DB_PREFIX_ . 'product p ON p.id_manufacturer = m.id_manufacturer
            INNER JOIN ' . _DB_PREFIX_ . 'product_shop ps ON p.id_product = ps.id_product
                AND ps.id_shop = ' . (int) $idShop . '
            WHERE p.active = 1 AND ps.active = 1' . $productFilter . '
            GROUP BY m.id_manufacturer, m.name
            HAVING product_count > 0

            UNION ALL

            SELECT \'category\' AS facet_type,
                   c.id_category AS facet_id,
                   cl.name AS facet_name,
                   COUNT(DISTINCT cp.id_product) AS product_count,
                   0, 0
            FROM ' . _DB_PREFIX_ . 'category c
            INNER JOIN ' . _DB_PREFIX_ . 'category_lang cl ON c.id_category = cl.id_category
                AND cl.id_lang = ' . (int) $idLang . ' AND cl.id_shop = ' . (int) $idShop . '
            INNER JOIN ' . _DB_PREFIX_ . 'category_shop cs ON c.id_category = cs.id_category
                AND cs.id_shop = ' . (int) $idShop . '
            INNER JOIN ' . _DB_PREFIX_ . 'category_product cp ON c.id_category = cp.id_category
            INNER JOIN ' . _DB_PREFIX_ . 'product p ON cp.id_product = p.id_product
            INNER JOIN ' . _DB_PREFIX_ . 'product_shop ps ON p.id_product = ps.id_product
                AND ps.id_shop = ' . (int) $idShop . '
            WHERE c.active = 1 AND p.active = 1 AND ps.active = 1 AND c.id_category > 2' . $productFilter . '
            GROUP BY c.id_category, cl.name
            HAVING product_count > 0

            UNION ALL

            SELECT \'price\' AS facet_type,
                   0, NULL, 0,
                   FLOOR(MIN(ps.price)),
                   CEIL(MAX(ps.price))
            FROM ' . _DB_PREFIX_ . 'product_shop ps
            INNER JOIN ' . _DB_PREFIX_ . 'product p ON ps.id_product = p.id_product
            WHERE ps.id_shop = ' . (int) $idShop . '
            AND p.active = 1 AND ps.active = 1' . $productFilter;

        $rows = Db::getInstance()->executeS($sql);

        $facets = [
            'manufacturers' => [],
            'categories' => [],
            'price_range' => ['min' => 0, 'max' => 1000],
        ];

        if ($rows) {
            foreach ($rows as $row) {
                switch ($row['facet_type']) {
                    case 'brand':
                        $facets['manufacturers'][] = [
                            'id_manufacturer' => (int) $row['facet_id'],
                            'name' => $row['facet_name'],
                            'count' => (int) $row['product_count'],
                        ];
                        break;
                    case 'category':
                        $facets['categories'][] = [
                            'id_category' => (int) $row['facet_id'],
                            'name' => $row['facet_name'],
                            'count' => (int) $row['product_count'],
                        ];
                        break;
                    case 'price':
                        $taxRate = $this->getDefaultTaxRate();
                        $taxMultiplier = 1 + $taxRate / 100;
                        $facets['price_range'] = [
                            'min' => (int) (($row['min_val'] ?? 0) * $taxMultiplier),
                            'max' => (int) (($row['max_val'] ?? 1000) * $taxMultiplier),
                        ];
                        break;
                }
            }
        }

        // Ordina brand e categorie per nome
        usort($facets['manufacturers'], function ($a, $b) {
            return strcmp($a['name'], $b['name']);
        });
        usort($facets['categories'], function ($a, $b) {
            return strcmp($a['name'], $b['name']);
        });

        $ttl = $isFiltered ? 300 : 600;
        $this->saveToCache($cacheKey, $facets, $ttl);

        return $facets;
    }

    /**
     * AJAX endpoint per i prodotti più venduti
     */
    public function displayAjaxBestsellers()
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');

        try {
            $idLang = (int)$this->context->language->id;
            $idShop = (int)$this->context->shop->id;

            // Prova a ottenere i bestseller
            $products = $this->getBestsellers($idLang, $idShop, 12);

            // Se non ci sono bestseller con vendite, ottieni i prodotti più recenti
            if (empty($products)) {
                $products = $this->getRecentProducts($idLang, $idShop, 12);
            }

            die(json_encode([
                'products' => $products,
                'total' => count($products)
            ], JSON_UNESCAPED_UNICODE));

        } catch (Throwable $e) {
            if (defined('_PS_MODE_DEV_') && _PS_MODE_DEV_) {
                PrestaShopLogger::addLog('SmartSearch bestsellers error: ' . $e->getMessage(), 3, null, 'SmartSearch');
            }
            die(json_encode([
                'products' => [],
                'total' => 0,
                'error' => true
            ], JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * Ottieni i prodotti più recenti (fallback)
     */
    protected function getRecentProducts($idLang, $idShop, $limit = 12)
    {
        $sql = '
            SELECT
                p.id_product,
                pl.name,
                pl.link_rewrite,
                pl.description_short,
                p.reference,
                p.id_category_default,
                p.id_manufacturer,
                m.name as manufacturer_name,
                cl.name as category_name,
                (SELECT id_image FROM ' . _DB_PREFIX_ . 'image i WHERE i.id_product = p.id_product AND i.cover = 1 LIMIT 1) as id_image
            FROM ' . _DB_PREFIX_ . 'product p
            INNER JOIN ' . _DB_PREFIX_ . 'product_lang pl
                ON p.id_product = pl.id_product
                AND pl.id_lang = ' . (int)$idLang . '
                AND pl.id_shop = ' . (int)$idShop . '
            INNER JOIN ' . _DB_PREFIX_ . 'product_shop ps
                ON p.id_product = ps.id_product
                AND ps.id_shop = ' . (int)$idShop . '
            LEFT JOIN ' . _DB_PREFIX_ . 'manufacturer m
                ON p.id_manufacturer = m.id_manufacturer
            LEFT JOIN ' . _DB_PREFIX_ . 'category_lang cl
                ON p.id_category_default = cl.id_category
                AND cl.id_lang = ' . (int)$idLang . '
            WHERE p.active = 1
            AND ps.active = 1
            ORDER BY p.date_add DESC
            LIMIT ' . (int)$limit;

        $results = Db::getInstance()->executeS($sql);

        if (!$results) {
            return [];
        }

        return $this->formatProducts($results, $idLang);
    }

    /**
     * Formatta i prodotti per la risposta JSON
     */
    protected function formatProducts($results, $idLang)
    {
        $products = [];
        foreach ($results as $row) {
            try {
                // URL prodotto
                $productUrl = $this->context->link->getProductLink(
                    $row['id_product'],
                    $row['link_rewrite'],
                    null,
                    null,
                    $idLang
                );

                // Immagine
                $imageUrl = '';
                if (!empty($row['id_image'])) {
                    $imageUrl = $this->context->link->getImageLink(
                        $row['link_rewrite'],
                        $row['id_image'],
                        ImageType::getFormattedName('home')
                    );
                }

                // Prezzo
                $priceDisplay = Product::getPriceStatic($row['id_product'], true);
                $priceOldDisplay = Product::getPriceStatic($row['id_product'], true, null, 6, null, false, false);

                // Verifica se il prodotto ha attributi/varianti (query diretta per evitare deprecation)
                $hasAttributes = false;
                try {
                    $hasAttributes = (bool)Db::getInstance()->getValue('
                        SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'product_attribute`
                        WHERE id_product = ' . (int)$row['id_product']
                    );
                } catch (Throwable $e) {
                    $hasAttributes = false;
                }
                $idProductAttribute = 0;

                // Calcola se è un bestseller (top 20 prodotti più venduti)
                $bestsellerIds = $this->getBestsellerIds((int)$this->context->shop->id);
                $isBestseller = in_array((int)$row['id_product'], $bestsellerIds);

                // Mantieni total_sold per eventuali usi futuri
                $totalSold = 0;
                if (isset($row['total_sold'])) {
                    $totalSold = (int)$row['total_sold'];
                } elseif (isset($row['sales_count'])) {
                    $totalSold = (int)$row['sales_count'];
                }

                // Quantità disponibile
                $quantity = StockAvailable::getQuantityAvailableByProduct($row['id_product']);

                $products[] = [
                    'id' => (int)$row['id_product'],
                    'name' => $row['name'],
                    'url' => $productUrl,
                    'image' => $imageUrl,
                    'price' => Tools::displayPrice($priceDisplay),
                    'price_raw' => $priceDisplay,
                    'price_old' => ($priceOldDisplay > $priceDisplay) ? Tools::displayPrice($priceOldDisplay) : '',
                    'price_old_raw' => ($priceOldDisplay > $priceDisplay) ? $priceOldDisplay : 0,
                    'description' => mb_substr(strip_tags($row['description_short'] ?? ''), 0, 100),
                    'category' => $row['category_name'] ?? '',
                    'manufacturer' => $row['manufacturer_name'] ?? '',
                    'reference' => $row['reference'] ?? '',
                    'in_stock' => $quantity > 0,
                    'quantity' => $quantity,
                    'total_sold' => $totalSold,
                    'is_bestseller' => $isBestseller,
                    'has_attributes' => $hasAttributes,
                    'id_product_attribute' => $idProductAttribute,
                    'boost_score' => isset($row['_boost_score']) ? (float)$row['_boost_score'] : 1.0,
                    'injected' => isset($row['_injected']) && $row['_injected'] ? true : false
                ];
            } catch (Throwable $e) {
                // Skip prodotto problematico, continua con gli altri
                continue;
            }
        }
        return $products;
    }

    /**
     * Ottieni i prodotti più venduti dal database ordini
     * Compatibile con MySQL 8.0+ (ONLY_FULL_GROUP_BY)
     */
    protected function getBestsellers($idLang, $idShop, $limit = 12)
    {
        // Query compatibile con MySQL 8.0+ usando subquery per aggregazione
        $sql = '
            SELECT
                p.id_product,
                pl.name,
                pl.link_rewrite,
                pl.description_short,
                p.reference,
                p.id_category_default,
                p.id_manufacturer,
                m.name as manufacturer_name,
                cl.name as category_name,
                (SELECT id_image FROM ' . _DB_PREFIX_ . 'image i WHERE i.id_product = p.id_product AND i.cover = 1 LIMIT 1) as id_image,
                IFNULL(sales.total_sold, 0) as total_sold
            FROM ' . _DB_PREFIX_ . 'product p
            INNER JOIN ' . _DB_PREFIX_ . 'product_lang pl
                ON p.id_product = pl.id_product
                AND pl.id_lang = ' . (int)$idLang . '
                AND pl.id_shop = ' . (int)$idShop . '
            INNER JOIN ' . _DB_PREFIX_ . 'product_shop ps
                ON p.id_product = ps.id_product
                AND ps.id_shop = ' . (int)$idShop . '
            LEFT JOIN ' . _DB_PREFIX_ . 'manufacturer m
                ON p.id_manufacturer = m.id_manufacturer
            LEFT JOIN ' . _DB_PREFIX_ . 'category_lang cl
                ON p.id_category_default = cl.id_category
                AND cl.id_lang = ' . (int)$idLang . '
            LEFT JOIN (
                SELECT od.product_id, SUM(od.product_quantity) as total_sold
                FROM ' . _DB_PREFIX_ . 'order_detail od
                INNER JOIN ' . _DB_PREFIX_ . 'orders o ON o.id_order = od.id_order AND o.valid = 1
                GROUP BY od.product_id
            ) sales ON sales.product_id = p.id_product
            WHERE p.active = 1
            AND ps.active = 1
            AND IFNULL(sales.total_sold, 0) > 0
            ORDER BY sales.total_sold DESC, pl.name ASC
            LIMIT ' . (int)$limit;

        $results = Db::getInstance()->executeS($sql);

        if (!$results) {
            return [];
        }

        return $this->formatProducts($results, $idLang);
    }

    /**
     * Gestisce le richieste AJAX in base al parametro action
     */
    public function initContent()
    {
        if (Tools::getValue('ajax') || Tools::isSubmit('ajax')) {
            $action = Tools::getValue('action', 'search');

            // Catch ALL errors including TypeError, etc.
            try {

                switch ($action) {
                    case 'filters':
                        if (!$this->checkRateLimit(10, 60, 'static')) { $this->dieRateLimit(); }
                        $this->displayAjaxFilters();
                        break;
                    case 'bestsellers':
                        if (!$this->checkRateLimit(10, 60, 'static')) { $this->dieRateLimit(); }
                        $this->displayAjaxBestsellers();
                        break;
                    case 'banners':
                        if (!$this->checkRateLimit(60, 60, 'banners')) { $this->dieRateLimit(); }
                        $this->displayAjaxBanners();
                        break;
                    case 'suggestions':
                        if (!$this->checkRateLimit(200, 60, 'suggestions')) { $this->dieRateLimit(); }
                        $this->displayAjaxSuggestions();
                        break;
                    case 'track':
                        if (!$this->checkRateLimit(200, 60, 'track')) { $this->dieRateLimit(); }
                        $this->displayAjaxTrack();
                        break;
                    case 'search':
                    default:
                        $this->displayAjaxSearch();
                        break;
                }
            } catch (Throwable $e) {
                // Log error for debugging
                if (defined('_PS_MODE_DEV_') && _PS_MODE_DEV_) {
                    PrestaShopLogger::addLog('SmartSearch FATAL: ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine(), 3, null, 'SmartSearch');
                }
                header('Content-Type: application/json; charset=utf-8');
                die(json_encode([
                    'error' => true,
                    'message' => 'Internal error',
                    'products' => [],
                    'total' => 0
                ], JSON_UNESCAPED_UNICODE));
            }
            // Non continuare dopo una risposta AJAX
            return;
        }
        parent::initContent();
    }

    /**
     * Ricerca prodotti con SISTEMA DI SCORING INTELLIGENTE
     *
     * Punteggi:
     * - Match esatto query nel nome: 100 punti
     * - Nome inizia con la query: 50 punti
     * - Match parola nel nome: 30 punti per parola
     * - Match EAN/SKU esatto: 150 punti (priorità massima)
     * - Match reference parziale: 80 punti
     * - Match marca: 25 punti
     * - Match descrizione: 10 punti per parola
     * - Bonus bestseller: +20 punti
     * - Bonus recensioni 4+: +15 punti
     * - Bonus prodotto recente (< 30 giorni): +10 punti
     */
    protected function searchProducts($query, $idLang, $idShop, $filters = [])
    {
        $query = trim($query);
        if (empty($query)) {
            return [];
        }

        // 1. Prima controlla match esatto EAN/SKU (priorità massima)
        $exactMatch = $this->searchByExactCode($query, $idLang, $idShop);
        if (!empty($exactMatch)) {
            // Applica filtri anche al match esatto
            $exactMatch = $this->applyFiltersToResults($exactMatch, $filters, $idShop);
            if (!empty($exactMatch)) {
                return $this->formatProducts($exactMatch, $idLang);
            }
        }

        // 2. Ricerca: usa indice pre-calcolato se disponibile, altrimenti query dirette
        if ($this->isSearchIndexAvailable()) {
            $results = $this->searchFromIndex($query, $idLang, $idShop, $filters);
        } else {
            $results = $this->searchProductsWithScoring($query, $idLang, $idShop, $filters);
        }

        // 2b. Applica filtri (prezzo con IVA, stock, etc.)
        if (!empty($filters)) {
            $results = $this->applyFiltersToResults($results, $filters, $idShop);
        }

        // 2c. Logica match_all: privilegia i prodotti che coprono TUTTE le
        // parole della query, rilassando solo se i risultati sono pochi.
        $results = $this->applyMatchAllGating($results, $query);

        // 3. Se pochi risultati, aggiungi fuzzy search
        if (count($results) < 5) {
            $fuzzyResults = $this->searchProductsFuzzy($query, $idLang, $idShop, $filters);
            // Applica filtri anche ai risultati fuzzy
            if (!empty($filters)) {
                $fuzzyResults = $this->applyFiltersToResults($fuzzyResults, $filters, $idShop);
            }
            $results = $this->mergeResultsWithScoring($results, $fuzzyResults, $query);
        }

        if (empty($results)) {
            return [];
        }

        // 4. Applica boosting configurato
        $results = $this->applyBoosting($results, $query, $idShop);

        // 4b. Applica learning-to-rank (i prodotti performanti per questa query salgono)
        $results = $this->applyLearningToRank($results, $query, $idShop, $idLang);

        // 5. Ordina per score totale (relevance_score * boost_score * ltr_score)
        usort($results, function($a, $b) {
            $scoreA = ($a['_relevance_score'] ?? 0) * ($a['_boost_score'] ?? 1) * ($a['_ltr_score'] ?? 1);
            $scoreB = ($b['_relevance_score'] ?? 0) * ($b['_boost_score'] ?? 1) * ($b['_ltr_score'] ?? 1);
            if ($scoreA !== $scoreB) {
                return $scoreB <=> $scoreA;
            }
            return strcmp($a['name'] ?? '', $b['name'] ?? '');
        });

        // 6. Limita risultati totali a 200
        $results = array_slice($results, 0, 200);

        return $this->formatProducts($results, $idLang);
    }

    /**
     * Applica i filtri ai risultati
     */
    protected function applyFiltersToResults($results, $filters, $idShop)
    {
        if (empty($filters)) {
            return $results;
        }

        $filtered = [];
        foreach ($results as $product) {
            $include = true;

            // Filtro categoria
            if (!empty($filters['category']) && is_array($filters['category'])) {
                $productCategories = $this->getProductCategories($product['id_product']);
                $hasCategory = false;
                foreach ($filters['category'] as $catId) {
                    if (in_array($catId, $productCategories)) {
                        $hasCategory = true;
                        break;
                    }
                }
                if (!$hasCategory) {
                    $include = false;
                }
            }

            // Filtro manufacturer
            if ($include && !empty($filters['manufacturer']) && is_array($filters['manufacturer'])) {
                if (!in_array((int)$product['id_manufacturer'], $filters['manufacturer'])) {
                    $include = false;
                }
            }

            // Filtro prezzo
            if ($include) {
                $productPrice = Product::getPriceStatic($product['id_product'], true);
                if (isset($filters['price_min']) && $productPrice < $filters['price_min']) {
                    $include = false;
                }
                if (isset($filters['price_max']) && $productPrice > $filters['price_max']) {
                    $include = false;
                }
            }

            // Filtro stock
            if ($include && !empty($filters['in_stock'])) {
                $stock = StockAvailable::getQuantityAvailableByProduct($product['id_product']);
                if ($stock <= 0) {
                    $include = false;
                }
            }

            if ($include) {
                $filtered[] = $product;
            }
        }

        return $filtered;
    }

    /**
     * Ottieni le categorie di un prodotto
     */
    protected function getProductCategories($idProduct)
    {
        $sql = 'SELECT id_category FROM ' . _DB_PREFIX_ . 'category_product WHERE id_product = ' . (int)$idProduct;
        $results = Db::getInstance()->executeS($sql);
        $categories = [];
        if ($results) {
            foreach ($results as $row) {
                $categories[] = (int)$row['id_category'];
            }
        }
        return $categories;
    }

    /**
     * Cerca per codice esatto (EAN, SKU, Reference)
     */
    protected function searchByExactCode($query, $idLang, $idShop)
    {
        // Rimuovi spazi e normalizza
        $code = preg_replace('/\s+/', '', $query);

        // Deve sembrare un CODICE, non un nome di brand/prodotto:
        // - Almeno 4 caratteri
        // - Deve contenere almeno un numero OPPURE contenere trattini/underscore
        // - Una parola tutta maiuscola senza numeri è un brand in caps lock, non un codice
        // - I codici reali (EAN, UPC, SKU) contengono sempre numeri o trattini/underscore
        $hasNumber = preg_match('/[0-9]/', $code);
        $hasSpecialChar = preg_match('/[\-_]/', $code);
        $isLikelyCode = $hasNumber || $hasSpecialChar;

        if (strlen($code) < 4 || !preg_match('/^[a-zA-Z0-9\-_]+$/', $code) || !$isLikelyCode) {
            return [];
        }

        $sql = '
            SELECT DISTINCT
                p.id_product,
                pl.name,
                pl.link_rewrite,
                pl.description_short,
                p.reference,
                p.ean13,
                p.upc,
                p.id_category_default,
                p.id_manufacturer,
                m.name as manufacturer_name,
                cl.name as category_name,
                (SELECT id_image FROM ' . _DB_PREFIX_ . 'image i WHERE i.id_product = p.id_product AND i.cover = 1 LIMIT 1) as id_image,
                0 as sales_count,
                150 as _relevance_score
            FROM ' . _DB_PREFIX_ . 'product p
            INNER JOIN ' . _DB_PREFIX_ . 'product_lang pl ON p.id_product = pl.id_product
                AND pl.id_lang = ' . (int)$idLang . ' AND pl.id_shop = ' . (int)$idShop . '
            INNER JOIN ' . _DB_PREFIX_ . 'product_shop ps ON p.id_product = ps.id_product
                AND ps.id_shop = ' . (int)$idShop . '
            LEFT JOIN ' . _DB_PREFIX_ . 'manufacturer m ON p.id_manufacturer = m.id_manufacturer
            LEFT JOIN ' . _DB_PREFIX_ . 'category_lang cl ON p.id_category_default = cl.id_category
                AND cl.id_lang = ' . (int)$idLang . '
            WHERE p.active = 1 AND ps.active = 1
            AND (
                p.reference = \'' . pSQL($code) . '\'
                OR p.ean13 = \'' . pSQL($code) . '\'
                OR p.upc = \'' . pSQL($code) . '\'
            )
            LIMIT 1';

        $result = Db::getInstance()->executeS($sql);
        return $result ?: [];
    }

    /**
     * Cache statica per la disponibilità FULLTEXT
     */
    protected static $fulltextAvailable = null;

    /**
     * Verifica se l'indice FULLTEXT ft_smartsearch esiste su product_lang
     */
    protected function isFulltextAvailable()
    {
        if (self::$fulltextAvailable === null) {
            try {
                $result = Db::getInstance()->executeS(
                    'SHOW INDEX FROM `' . _DB_PREFIX_ . 'product_lang` WHERE Key_name = \'ft_smartsearch\''
                );
                self::$fulltextAvailable = !empty($result);
            } catch (Throwable $e) {
                self::$fulltextAvailable = false;
            }
        }
        return self::$fulltextAvailable;
    }

    /**
     * Cache statica per la disponibilità dell'indice pre-calcolato
     */
    public static $searchIndexAvailable = null;

    /**
     * Resetta la cache statica di isSearchIndexAvailable()
     */
    public static function resetSearchIndexCache()
    {
        self::$searchIndexAvailable = null;
    }

    /**
     * Verifica se la tabella smartsearch_index è popolata
     */
    protected function isSearchIndexAvailable()
    {
        if (self::$searchIndexAvailable === null) {
            try {
                $count = (int) Db::getInstance()->getValue(
                    'SELECT 1 FROM `' . _DB_PREFIX_ . 'smartsearch_index` LIMIT 1'
                );
                self::$searchIndexAvailable = ($count > 0);
            } catch (Throwable $e) {
                self::$searchIndexAvailable = false;
            }
        }
        return self::$searchIndexAvailable;
    }

    /**
     * Ricerca dall'indice pre-calcolato (smartsearch_index).
     * Usa FULLTEXT su search_content — una sola tabella, nessun JOIN.
     */
    protected function searchFromIndex($query, $idLang, $idShop, $filters = [])
    {
        $queryLower = mb_strtolower(trim($query));
        $words = array_filter(explode(' ', $queryLower), function ($w) {
            return mb_strlen($w) >= 2;
        });

        if (empty($words)) {
            return [];
        }

        $expandedWords = $this->expandQueryWords($words);

        // Prepara termini FULLTEXT
        $ftTerms = [];
        $likeShort = [];
        foreach ($expandedWords as $word) {
            $clean = preg_replace('/[+\-><\(\)~*\"@]/', '', $word);
            if (mb_strlen($clean) >= 3) {
                $ftTerms[] = pSQL($clean) . '*';
            } else {
                $ws = pSQL($this->escapeLikeWildcards($word));
                $likeShort[] = "si.product_name LIKE '%{$ws}%'";
                $likeShort[] = "si.search_content LIKE '%{$ws}%'";
            }
        }

        $whereConditions = [];
        $ftScoreExpr = '0';

        if (!empty($ftTerms)) {
            $ftQueryStr = implode(' ', $ftTerms);
            $ftMatchAll = "MATCH(si.search_content) AGAINST('" . pSQL($ftQueryStr) . "' IN BOOLEAN MODE)";
            $ftMatchName = "MATCH(si.name_only_content) AGAINST('" . pSQL($ftQueryStr) . "' IN BOOLEAN MODE)";
            // Match su almeno uno dei due indici
            $whereConditions[] = '(' . $ftMatchAll . ' OR ' . $ftMatchName . ')';
            // Score pesato: nome/brand/ref conta il doppio della descrizione
            $ftScoreExpr = '(' . $ftMatchName . ' * 2 + ' . $ftMatchAll . ')';
        }
        if (!empty($likeShort)) {
            $whereConditions = array_merge($whereConditions, $likeShort);
        }

        // LIKE safety net su nome e brand per parole >= 3 chars
        // Copre i casi in cui FULLTEXT non trova (token non indicizzati, innodb_ft_min_token_size, ecc.)
        foreach ($words as $word) {
            if (mb_strlen($word) >= 3) {
                $ws = pSQL($this->escapeLikeWildcards($word));
                $whereConditions[] = "si.product_name LIKE '%{$ws}%'";
                $whereConditions[] = "si.manufacturer_name LIKE '%{$ws}%'";
            }
        }

        if (empty($whereConditions)) {
            return [];
        }

        // Name match count per scoring
        $nameMatchCases = [];
        foreach ($expandedWords as $word) {
            $ws = pSQL($this->escapeLikeWildcards($word));
            $nameMatchCases[] = "(CASE WHEN si.product_name LIKE '%{$ws}%' THEN 1 ELSE 0 END)";
        }
        $nameMatchScore = '(' . implode(' + ', $nameMatchCases) . ')';

        // Filtri
        $filterConds = [];
        $joinCategory = '';
        if (!empty($filters['category']) && is_array($filters['category'])) {
            $catIds = array_map('intval', $filters['category']);
            $joinCategory = 'INNER JOIN ' . _DB_PREFIX_ . 'category_product cp ON si.id_product = cp.id_product';
            $filterConds[] = 'cp.id_category IN (' . implode(',', $catIds) . ')';
        }
        if (!empty($filters['manufacturer']) && is_array($filters['manufacturer'])) {
            $mfrIds = array_map('intval', $filters['manufacturer']);
            $filterConds[] = 'si.id_manufacturer IN (' . implode(',', $mfrIds) . ')';
        }
        if (!empty($filters['in_stock'])) {
            $filterConds[] = '(SELECT SUM(sa.quantity) FROM ' . _DB_PREFIX_ . 'stock_available sa
                WHERE sa.id_product = si.id_product AND sa.id_shop = ' . (int) $idShop . ') > 0';
        }

        $sql = '
            SELECT
                si.id_product,
                si.product_name AS name,
                si.link_rewrite,
                si.description_short,
                \'\' AS description,
                si.reference,
                si.ean13,
                si.id_category_default,
                si.id_manufacturer,
                si.date_add,
                si.manufacturer_name,
                si.category_name,
                si.id_image,
                si.sales_count,
                ' . $ftScoreExpr . ' AS ft_score,
                ' . $nameMatchScore . ' AS name_match_count
            FROM `' . _DB_PREFIX_ . 'smartsearch_index` si
            ' . $joinCategory . '
            WHERE si.active = 1
            AND si.id_lang = ' . (int) $idLang . '
            AND si.id_shop = ' . (int) $idShop . '
            AND (' . implode(' OR ', $whereConditions) . ')
            ' . (!empty($filterConds) ? 'AND ' . implode(' AND ', $filterConds) : '') . '
            ORDER BY ft_score DESC, name_match_count DESC
            LIMIT 300';

        $results = Db::getInstance()->executeS($sql);

        if (!$results) {
            return [];
        }

        foreach ($results as &$product) {
            $product['_relevance_score'] = $this->calculateRelevanceScore($product, $queryLower, $words);
        }

        usort($results, function ($a, $b) {
            return ($b['_relevance_score'] ?? 0) <=> ($a['_relevance_score'] ?? 0);
        });

        return $results;
    }

    /**
     * Ricerca prodotti con calcolo score di rilevanza.
     * Usa FULLTEXT MATCH() AGAINST() quando l'indice è disponibile,
     * altrimenti fallback a LIKE.
     */
    protected function searchProductsWithScoring($query, $idLang, $idShop, $filters = [])
    {
        $queryLower = mb_strtolower(trim($query));
        $words = array_filter(explode(' ', $queryLower), function($w) {
            return mb_strlen($w) >= 2;
        });

        if (empty($words)) {
            return [];
        }

        // Espandi le parole con variazioni singolare/plurale italiano
        $expandedWords = $this->expandQueryWords($words);

        // Costruisci condizioni WHERE e score FULLTEXT
        $orConditions = [];
        $ftScoreExpr = '0';

        if ($this->isFulltextAvailable()) {
            // Prepara termini FULLTEXT (min 3 char per innodb_ft_min_token_size)
            $ftTerms = [];
            foreach ($expandedWords as $word) {
                $clean = preg_replace('/[+\-><\(\)~*\"@]/', '', $word);
                if (mb_strlen($clean) >= 3) {
                    $ftTerms[] = pSQL($clean) . '*';
                }
            }

            if (!empty($ftTerms)) {
                $ftQueryStr = implode(' ', $ftTerms);
                $ftMatchExpr = "MATCH(pl.name, pl.description_short) AGAINST('" . pSQL($ftQueryStr) . "' IN BOOLEAN MODE)";
                $orConditions[] = $ftMatchExpr;
                $ftScoreExpr = $ftMatchExpr;
            }

            // LIKE fallback per parole corte (< 3 char) su name/description_short
            foreach ($expandedWords as $word) {
                if (mb_strlen($word) < 3) {
                    $ws = pSQL($this->escapeLikeWildcards($word));
                    $orConditions[] = "pl.name LIKE '%{$ws}%'";
                    $orConditions[] = "pl.description_short LIKE '%{$ws}%'";
                }
            }
        } else {
            // Nessun FULLTEXT: LIKE su name e description_short
            foreach ($expandedWords as $word) {
                $ws = pSQL($this->escapeLikeWildcards($word));
                $orConditions[] = "pl.name LIKE '%{$ws}%'";
                $orConditions[] = "pl.description_short LIKE '%{$ws}%'";
            }
        }

        // LIKE per description (non coperto da FULLTEXT), reference e manufacturer
        foreach ($expandedWords as $word) {
            $ws = pSQL($this->escapeLikeWildcards($word));
            $orConditions[] = "pl.description LIKE '%{$ws}%'";
            $orConditions[] = "p.reference LIKE '%{$ws}%'";
            $orConditions[] = "m.name LIKE '%{$ws}%'";
        }

        // Per ordinamento SQL: conta quante parole matchano nel nome
        $nameMatchCases = [];
        foreach ($expandedWords as $word) {
            $ws = pSQL($this->escapeLikeWildcards($word));
            $nameMatchCases[] = "(CASE WHEN pl.name LIKE '%{$ws}%' THEN 1 ELSE 0 END)";
        }
        $nameMatchScore = '(' . implode(' + ', $nameMatchCases) . ')';

        // Costruisci condizioni filtro
        $filterConditions = $this->buildFilterConditions($filters, $idShop);

        $sql = '
            SELECT DISTINCT
                p.id_product,
                pl.name,
                pl.link_rewrite,
                pl.description_short,
                pl.description,
                p.reference,
                p.ean13,
                p.id_category_default,
                p.id_manufacturer,
                p.date_add,
                m.name as manufacturer_name,
                cl.name as category_name,
                (SELECT id_image FROM ' . _DB_PREFIX_ . 'image i WHERE i.id_product = p.id_product AND i.cover = 1 LIMIT 1) as id_image,
                COALESCE((SELECT SUM(od.product_quantity) FROM ' . _DB_PREFIX_ . 'order_detail od
                    INNER JOIN ' . _DB_PREFIX_ . 'orders o ON od.id_order = o.id_order AND o.valid = 1
                    WHERE od.product_id = p.id_product), 0) as sales_count,
                ' . $ftScoreExpr . ' as ft_score,
                ' . $nameMatchScore . ' as name_match_count
            FROM ' . _DB_PREFIX_ . 'product p
            INNER JOIN ' . _DB_PREFIX_ . 'product_lang pl ON p.id_product = pl.id_product
                AND pl.id_lang = ' . (int)$idLang . ' AND pl.id_shop = ' . (int)$idShop . '
            INNER JOIN ' . _DB_PREFIX_ . 'product_shop ps ON p.id_product = ps.id_product
                AND ps.id_shop = ' . (int)$idShop . '
            LEFT JOIN ' . _DB_PREFIX_ . 'manufacturer m ON p.id_manufacturer = m.id_manufacturer
            LEFT JOIN ' . _DB_PREFIX_ . 'category_lang cl ON p.id_category_default = cl.id_category
                AND cl.id_lang = ' . (int)$idLang . '
            ' . (!empty($filters['category']) ? 'INNER JOIN ' . _DB_PREFIX_ . 'category_product cp ON p.id_product = cp.id_product' : '') . '
            WHERE p.active = 1 AND ps.active = 1
            AND (' . implode(' OR ', $orConditions) . ')
            ' . $filterConditions . '
            ORDER BY ft_score DESC, name_match_count DESC, pl.name ASC
            LIMIT 300';

        $results = Db::getInstance()->executeS($sql);

        if (!$results) {
            return [];
        }

        // Calcola score per ogni prodotto
        foreach ($results as &$product) {
            $product['_relevance_score'] = $this->calculateRelevanceScore($product, $queryLower, $words);
        }

        // Ordina per score
        usort($results, function($a, $b) {
            return ($b['_relevance_score'] ?? 0) <=> ($a['_relevance_score'] ?? 0);
        });

        return $results;
    }

    /**
     * Costruisce le condizioni SQL per i filtri
     */
    protected function buildFilterConditions($filters, $idShop)
    {
        $conditions = [];

        // Filtro categoria
        if (!empty($filters['category']) && is_array($filters['category'])) {
            $categoryIds = array_map('intval', $filters['category']);
            $conditions[] = 'cp.id_category IN (' . implode(',', $categoryIds) . ')';
        }

        // Filtro manufacturer
        if (!empty($filters['manufacturer']) && is_array($filters['manufacturer'])) {
            $manufacturerIds = array_map('intval', $filters['manufacturer']);
            $conditions[] = 'p.id_manufacturer IN (' . implode(',', $manufacturerIds) . ')';
        }

        // Filtro prezzo - NON filtrare in SQL, lasciare al PHP che usa getPriceStatic con IVA
        // Il filtro prezzo viene applicato in applyFiltersToResults() con il prezzo corretto
        // Questo evita inconsistenze tra prezzo base (senza IVA) e prezzo finale (con IVA)

        // Filtro stock
        if (!empty($filters['in_stock'])) {
            $conditions[] = '(SELECT SUM(sa.quantity) FROM ' . _DB_PREFIX_ . 'stock_available sa
                WHERE sa.id_product = p.id_product AND sa.id_shop = ' . (int)$idShop . ') > 0';
        }

        if (empty($conditions)) {
            return '';
        }

        return 'AND ' . implode(' AND ', $conditions);
    }

    /**
     * Escape dei caratteri wildcard LIKE (%, _, \) nell'input utente.
     * Da chiamare PRIMA di pSQL() quando il valore viene usato in un pattern LIKE.
     */
    protected function escapeLikeWildcards($value)
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }

    /**
     * Controlla se una parola (o sue variazioni unità) è presente in un testo
     */
    protected function wordFoundIn($word, $text)
    {
        // Check diretto
        if (strpos($text, $word) !== false) {
            return true;
        }

        // Check variazioni unità (350g -> 350 g, 350gr, etc.)
        $unitVariations = $this->getUnitVariations($word);
        foreach ($unitVariations as $variation) {
            if (strpos($text, $variation) !== false) {
                return true;
            }
        }

        return false;
    }

    /**
     * Logica "match_all" con rilassamento progressivo (stile Doofinder).
     *
     * Calcola per ogni prodotto quante parole della query copre (una parola e'
     * coperta se il prodotto contiene la parola stessa o una sua variante/
     * sinonimo). Mantiene solo i prodotti con copertura massima; se sono meno
     * della soglia minima, rilassa il requisito a N-1, N-2... parole finche'
     * non raggiunge abbastanza risultati o arriva a 1 parola.
     *
     * @return array
     */
    protected function applyMatchAllGating($results, $query)
    {
        if (empty($results) || !(int) Configuration::get('SMARTSEARCH_MATCHALL_ENABLED')) {
            return $results;
        }

        $queryLower = mb_strtolower(trim($query));
        $words = array_values(array_unique(array_filter(explode(' ', $queryLower), function ($w) {
            return mb_strlen($w) >= 2;
        })));

        // Con una sola parola non c'e' nulla da "combinare"
        if (count($words) < 2) {
            return $results;
        }

        $groups = $this->buildCoverageGroups($words);
        $wordCount = count($groups);

        // Calcola la copertura di ogni prodotto
        $maxCoverage = 0;
        foreach ($results as &$product) {
            $haystack = $this->productHaystack($product);
            $covered = 0;
            foreach ($groups as $tokens) {
                foreach ($tokens as $token) {
                    if ($token !== '' && mb_strpos($haystack, $token) !== false) {
                        $covered++;
                        break;
                    }
                }
            }
            $product['_coverage'] = $covered;
            if ($covered > $maxCoverage) {
                $maxCoverage = $covered;
            }
        }
        unset($product);

        // Nessun prodotto copre neppure una parola: lascia invariato
        if ($maxCoverage <= 0) {
            return $results;
        }

        $minResults = (int) Configuration::get('SMARTSEARCH_MATCHALL_MIN_RESULTS') ?: 12;

        // Parti dalla copertura massima e rilassa finche' non raggiungi la soglia
        $required = $maxCoverage;
        $filtered = array();
        while ($required >= 1) {
            $filtered = array_filter($results, function ($p) use ($required) {
                return ($p['_coverage'] ?? 0) >= $required;
            });
            if (count($filtered) >= $minResults || $required === 1) {
                break;
            }
            $required--;
        }

        return array_values($filtered);
    }

    /**
     * Costruisce, per ogni parola della query, l'insieme dei token accettati
     * (la parola + variazioni italiane + variazioni unita' + sinonimi attivi),
     * coerente con l'espansione usata in fase di ricerca.
     *
     * @return array array di array di token (uno per parola)
     */
    protected function buildCoverageGroups($words)
    {
        $synonymMap = $this->getTableSynonyms();
        $groups = array();

        foreach ($words as $word) {
            $tokens = array($word);

            foreach ($this->getItalianWordVariations($word) as $v) {
                $tokens[] = $v;
            }
            foreach ($this->getUnitVariations($word) as $v) {
                $tokens[] = $v;
            }
            $wl = mb_strtolower($word);
            if (isset($synonymMap[$wl])) {
                foreach ($synonymMap[$wl] as $syn) {
                    $tokens[] = $syn;
                }
            }

            $groups[] = array_values(array_unique(array_filter(array_map('mb_strtolower', $tokens))));
        }

        return $groups;
    }

    /**
     * Costruisce il testo ricercabile di un prodotto (campi ad alto valore)
     * per il calcolo della copertura parole.
     */
    protected function productHaystack($product)
    {
        $parts = array(
            $product['name'] ?? '',
            $product['manufacturer_name'] ?? '',
            $product['category_name'] ?? '',
            $product['reference'] ?? '',
            $product['ean13'] ?? '',
            $product['description_short'] ?? '',
        );

        return mb_strtolower(strip_tags(implode(' ', $parts)));
    }

    /**
     * Calcola lo score di rilevanza per un prodotto
     */
    protected function calculateRelevanceScore($product, $query, $words)
    {
        $score = 0;
        $nameLower = mb_strtolower($product['name'] ?? '');
        $descShortLower = mb_strtolower($product['description_short'] ?? '');
        $descFullLower = mb_strtolower(strip_tags($product['description'] ?? ''));
        $refLower = mb_strtolower($product['reference'] ?? '');
        $brandLower = mb_strtolower($product['manufacturer_name'] ?? '');

        // Combina nome + marca per match completo
        $nameAndBrand = $nameLower . ' ' . $brandLower;

        // =============================================================
        // PRIORITÀ 0: TUTTE LE PAROLE NEL NOME PRODOTTO
        // Cerca SOLO nel nome - la marca è secondaria
        // =============================================================
        $wordsInName = 0;
        $wordsInBrand = 0;
        $wordsInRef = 0;

        foreach ($words as $word) {
            // Usa wordFoundIn per gestire variazioni unità (350g, 350 g, etc.)
            if ($this->wordFoundIn($word, $nameLower)) $wordsInName++;
            if ($this->wordFoundIn($word, $brandLower)) $wordsInBrand++;
            if ($this->wordFoundIn($word, $refLower)) $wordsInRef++;
        }

        $allWordsInName = ($wordsInName === count($words));
        $totalWordsFound = $wordsInName; // Conta solo parole nel nome per priorità

        // Se TUTTE le parole sono nel NOME -> MASSIMA priorità
        if ($allWordsInName && count($words) > 0) {
            $score += 1000; // Base altissima per match completo nel nome

            // Bonus se query esatta nel nome (come stringa continua)
            if (strpos($nameLower, $query) !== false) {
                $score += 300;
                if (strpos($nameLower, $query) === 0) {
                    $score += 100; // Inizia con la query
                }
            }

            // Bonus minore per match anche in brand/reference
            $score += $wordsInBrand * 10;
            $score += $wordsInRef * 10;
        }
        // =============================================================
        // PRIORITÀ 1: MATCH QUERY ESATTA (stringa completa)
        // =============================================================
        elseif (strpos($nameLower, $query) !== false) {
            $score += 800;
            if (strpos($nameLower, $query) === 0) {
                $score += 100;
            }
        }
        elseif (strpos($brandLower, $query) !== false) {
            $score += 700;
        }
        elseif (strpos($refLower, $query) !== false) {
            $score += 750;
        }
        // =============================================================
        // PRIORITÀ 2: MATCH PARZIALE - alcune parole trovate
        // =============================================================
        else {
            // Punteggio base per ogni parola trovata
            $score += $wordsInName * 40;
            $score += $wordsInBrand * 30;
            $score += $wordsInRef * 35;

            // Bonus se molte parole matchano
            $matchRatio = $totalWordsFound / count($words);
            if ($matchRatio >= 0.75) {
                $score += 100;
            } elseif ($matchRatio >= 0.5) {
                $score += 50;
            }

            // Match nella descrizione breve (peso basso)
            foreach ($words as $word) {
                if (strpos($descShortLower, $word) !== false) {
                    $score += 5;
                }
            }

            // PENALITÀ FORTE per match molto parziale
            if (count($words) > 1 && $matchRatio < 0.5) {
                $score *= 0.3; // Riduce molto lo score
            }
        }

        // =============================================================
        // BONUS AGGIUNTIVI (per tutti)
        // =============================================================

        // === MATCH NELLA DESCRIZIONE COMPLETA (peso basso) ===
        foreach ($words as $word) {
            // Solo se non già matchato altrove
            if (strpos($descFullLower, $word) !== false) {
                $score += 3;
            }
        }

        // Pesi dei criteri di rilevanza (configurabili dal pannello)
        $criteria = $this->getRelevanceCriteria();

        // === BONUS BESTSELLER (peso vendite configurabile) ===
        $salesCount = (int)($product['sales_count'] ?? 0);
        $salesBonus = 0;
        if ($salesCount > 100) {
            $salesBonus = 20;
        } elseif ($salesCount > 50) {
            $salesBonus = 15;
        } elseif ($salesCount > 10) {
            $salesBonus = 10;
        } elseif ($salesCount > 0) {
            $salesBonus = 5;
        }
        $score += $salesBonus * $criteria['sales'];

        // === BONUS PRODOTTO RECENTE (peso novità configurabile) ===
        if (!empty($product['date_add'])) {
            $daysOld = (time() - strtotime($product['date_add'])) / 86400;
            $noveltyBonus = 0;
            if ($daysOld <= 7) {
                $noveltyBonus = 15; // Novità ultima settimana
            } elseif ($daysOld <= 30) {
                $noveltyBonus = 10; // Novità ultimo mese
            }
            $score += $noveltyBonus * $criteria['novelty'];
        }

        // === PENALITÀ PRODOTTI ESAURITI (percentuale configurabile) ===
        if ($criteria['stock_penalty'] > 0) {
            $quantity = StockAvailable::getQuantityAvailableByProduct((int)$product['id_product']);
            if ($quantity <= 0) {
                $score = (int)round($score * (1 - $criteria['stock_penalty']));
            }
        }

        return $score;
    }

    /** @var array|null Cache statica dei pesi dei criteri di rilevanza */
    protected static $relevanceCriteriaCache = null;

    /**
     * Restituisce i pesi dei criteri di rilevanza (normalizzati 0..1 per i
     * moltiplicatori, e frazione per la penalità stock).
     *
     * @return array ['sales' => float, 'novelty' => float, 'stock_penalty' => float]
     */
    protected function getRelevanceCriteria()
    {
        if (self::$relevanceCriteriaCache !== null) {
            return self::$relevanceCriteriaCache;
        }

        $salesW = Configuration::get('SMARTSEARCH_REL_SALES_WEIGHT');
        $novelW = Configuration::get('SMARTSEARCH_REL_NOVELTY_WEIGHT');
        $stockP = Configuration::get('SMARTSEARCH_REL_STOCK_PENALTY');

        self::$relevanceCriteriaCache = array(
            'sales' => ($salesW === false ? 100 : (int) $salesW) / 100,
            'novelty' => ($novelW === false ? 100 : (int) $novelW) / 100,
            'stock_penalty' => min(100, max(0, ($stockP === false ? 30 : (int) $stockP))) / 100,
        );

        return self::$relevanceCriteriaCache;
    }

    /**
     * Unisce risultati rimuovendo duplicati e mantenendo score migliore
     */
    protected function mergeResultsWithScoring($primary, $secondary, $query)
    {
        $queryLower = mb_strtolower(trim($query));
        $words = array_filter(explode(' ', $queryLower), function($w) {
            return mb_strlen($w) >= 2;
        });

        $merged = [];
        $ids = [];

        // Aggiungi risultati primari
        foreach ($primary as $product) {
            $id = $product['id_product'];
            $merged[$id] = $product;
            $ids[$id] = true;
        }

        // Aggiungi risultati secondari se non già presenti
        foreach ($secondary as $product) {
            $id = $product['id_product'];
            if (!isset($ids[$id])) {
                // Calcola score se non presente
                if (!isset($product['_relevance_score'])) {
                    $product['_relevance_score'] = $this->calculateRelevanceScore($product, $queryLower, $words);
                    // Penalità per risultati fuzzy (sono meno precisi)
                    $product['_relevance_score'] *= 0.7;
                }
                $merged[$id] = $product;
            }
        }

        return array_values($merged);
    }

    /**
     * Applica boost ai prodotti in base alle regole configurate
     *
     * LOGICA:
     * - Se la regola ha KEYWORDS: il boost si applica solo se la query contiene quelle keyword
     * - Se la regola NON ha keywords: il boost si applica sempre al prodotto specificato
     * - I prodotti boostati vengono INIETTATI nei risultati anche se non matchano la ricerca
     */
    protected function applyBoosting($products, $query, $idShop)
    {
        $idLang = (int)$this->context->language->id;

        // Ottieni le regole di boosting attive
        $boostRules = Db::getInstance()->executeS('
            SELECT id_product, boost_value, keywords
            FROM `' . _DB_PREFIX_ . 'smartsearch_boost`
            WHERE id_shop = ' . (int)$idShop . ' AND active = 1
        ');

        if (!$boostRules || empty($boostRules)) {
            return $products;
        }

        // Normalizza la query
        $queryLower = mb_strtolower(trim($query));
        $queryWords = array_filter(explode(' ', $queryLower));

        // IDs dei prodotti già nei risultati
        $existingIds = [];
        foreach ($products as $p) {
            if (isset($p['id_product'])) {
                $existingIds[(int)$p['id_product']] = true;
            }
        }

        // Prima: inietta prodotti boostati che NON sono nei risultati
        foreach ($boostRules as $rule) {
            $ruleProductId = (int)$rule['id_product'];
            $boostValue = (float)$rule['boost_value'];
            $ruleKeywords = trim($rule['keywords'] ?? '');

            // Salta se il prodotto è già nei risultati
            if (isset($existingIds[$ruleProductId])) {
                continue;
            }

            // Verifica se questa regola deve attivarsi
            $shouldInject = false;

            if (empty($ruleKeywords)) {
                // Nessuna keyword = sempre attivo
                $shouldInject = true;
            } else {
                // Verifica match keywords
                $keywords = array_map('trim', explode(',', mb_strtolower($ruleKeywords)));
                foreach ($keywords as $keyword) {
                    if (empty($keyword)) continue;
                    foreach ($queryWords as $word) {
                        if (strlen($word) < 2) continue;
                        if (stripos($keyword, $word) !== false || stripos($word, $keyword) !== false) {
                            $shouldInject = true;
                            break 2;
                        }
                    }
                }
            }

            if ($shouldInject) {
                // Carica il prodotto dal database
                $injectedProduct = $this->loadProductById($ruleProductId, $idLang, $idShop);
                if ($injectedProduct) {
                    // Calcola relevance score per il prodotto iniettato
                    $words = array_filter($queryWords, function($w) { return strlen($w) >= 2; });
                    $injectedProduct['_relevance_score'] = $this->calculateRelevanceScore($injectedProduct, $queryLower, $words);
                    // Aggiungi bonus base per prodotti boostati (sono stati selezionati manualmente)
                    $injectedProduct['_relevance_score'] += 50;
                    $injectedProduct['_boost_score'] = $boostValue;
                    $injectedProduct['_injected'] = true;
                    $products[] = $injectedProduct;
                    $existingIds[$ruleProductId] = true;
                }
            }
        }

        // Poi: applica boost ai prodotti esistenti
        foreach ($products as &$product) {
            // Inizializza score se non presenti
            if (!isset($product['_boost_score'])) {
                $product['_boost_score'] = 1.0;
            }
            if (!isset($product['_relevance_score'])) {
                $product['_relevance_score'] = 50; // Score base
            }

            $productId = isset($product['id_product']) ? (int)$product['id_product'] : 0;

            if (!$productId || isset($product['_injected'])) {
                continue; // I prodotti iniettati hanno già il boost
            }

            // Controlla ogni regola di boost
            foreach ($boostRules as $rule) {
                $ruleProductId = (int)$rule['id_product'];
                $boostValue = (float)$rule['boost_value'];
                $ruleKeywords = trim($rule['keywords'] ?? '');

                if ($ruleProductId != $productId) {
                    continue;
                }

                // Se la regola ha keywords, verifica che la query le contenga
                if (!empty($ruleKeywords)) {
                    $keywords = array_map('trim', explode(',', mb_strtolower($ruleKeywords)));
                    $keywordMatches = false;

                    foreach ($keywords as $keyword) {
                        if (empty($keyword)) continue;
                        foreach ($queryWords as $word) {
                            if (strlen($word) < 2) continue;
                            if (stripos($keyword, $word) !== false || stripos($word, $keyword) !== false) {
                                $keywordMatches = true;
                                break 2;
                            }
                        }
                    }

                    if (!$keywordMatches) {
                        continue;
                    }
                }

                $product['_boost_score'] *= $boostValue;
            }
        }

        // Il sort finale viene fatto in searchProducts() usando relevance_score * boost_score
        return $products;
    }

    /**
     * Carica un singolo prodotto dal database per iniezione boost
     */
    protected function loadProductById($idProduct, $idLang, $idShop)
    {
        $sql = '
            SELECT
                p.id_product,
                pl.name,
                pl.link_rewrite,
                pl.description_short,
                p.reference,
                p.id_category_default,
                p.id_manufacturer,
                m.name as manufacturer_name,
                cl.name as category_name,
                (SELECT id_image FROM ' . _DB_PREFIX_ . 'image i WHERE i.id_product = p.id_product AND i.cover = 1 LIMIT 1) as id_image
            FROM ' . _DB_PREFIX_ . 'product p
            INNER JOIN ' . _DB_PREFIX_ . 'product_lang pl
                ON p.id_product = pl.id_product
                AND pl.id_lang = ' . (int)$idLang . '
                AND pl.id_shop = ' . (int)$idShop . '
            INNER JOIN ' . _DB_PREFIX_ . 'product_shop ps
                ON p.id_product = ps.id_product
                AND ps.id_shop = ' . (int)$idShop . '
            LEFT JOIN ' . _DB_PREFIX_ . 'manufacturer m
                ON p.id_manufacturer = m.id_manufacturer
            LEFT JOIN ' . _DB_PREFIX_ . 'category_lang cl
                ON p.id_category_default = cl.id_category
                AND cl.id_lang = ' . (int)$idLang . '
            WHERE p.id_product = ' . (int)$idProduct . '
            AND p.active = 1
            AND ps.active = 1
            LIMIT 1';

        $result = Db::getInstance()->getRow($sql);
        return $result ?: null;
    }

    /**
     * Ricerca prodotti esatta
     */
    protected function searchProductsExact($query, $idLang, $idShop)
    {
        $words = explode(' ', $query);
        $conditions = [];

        foreach ($words as $word) {
            if (mb_strlen($word) >= 2) {
                $word = pSQL($this->escapeLikeWildcards($word));
                $conditions[] = "(
                    pl.name LIKE '%{$word}%'
                    OR pl.description_short LIKE '%{$word}%'
                    OR pl.description LIKE '%{$word}%'
                    OR p.reference LIKE '%{$word}%'
                    OR m.name LIKE '%{$word}%'
                )";
            }
        }

        if (empty($conditions)) {
            return [];
        }

        return $this->executeProductSearch(implode(' AND ', $conditions), $idLang, $idShop);
    }

    /**
     * Ricerca prodotti fuzzy (tollerante agli errori di battitura)
     */
    protected function searchProductsFuzzy($query, $idLang, $idShop, $filters = [])
    {
        $words = explode(' ', $query);
        $conditions = [];

        foreach ($words as $word) {
            if (mb_strlen($word) >= 3) {
                $wordConditions = [];

                // Fuzzy pattern va generato PRIMA dell'escape (contiene % intenzionali)
                $fuzzyPattern = pSQL($this->createFuzzyPattern($word));

                // Consonant pattern va generato PRIMA dell'escape
                $consonants = $this->extractConsonants($word);
                $consonantPattern = '';
                if (mb_strlen($consonants) >= 3) {
                    $consonantPattern = pSQL('%' . implode('%', str_split($consonants)) . '%');
                }

                // Escape dei caratteri wildcard LIKE per i match esatti
                $wordEscaped = pSQL($this->escapeLikeWildcards($word));

                // Substr senza prima/ultima lettera (dopo escape per i LIKE contains)
                $withoutFirst = '';
                $withoutLast = '';
                if (mb_strlen($word) > 3) {
                    $withoutFirst = pSQL($this->escapeLikeWildcards(mb_substr($word, 1)));
                    $withoutLast = pSQL($this->escapeLikeWildcards(mb_substr($word, 0, -1)));
                }

                // 1. Ricerca SOUNDEX (fonetica) — usa parola non-escaped
                $wordSoundex = pSQL($word);
                $wordConditions[] = "SOUNDEX(pl.name) = SOUNDEX('{$wordSoundex}')";
                $wordConditions[] = "SOUNDEX(m.name) = SOUNDEX('{$wordSoundex}')";

                // 2. Ricerca con wildcard tra le lettere (per typos)
                $wordConditions[] = "pl.name LIKE '{$fuzzyPattern}'";
                $wordConditions[] = "pl.description LIKE '{$fuzzyPattern}'";
                $wordConditions[] = "m.name LIKE '{$fuzzyPattern}'";

                // 3. Ricerca senza la prima/ultima lettera (per errori comuni)
                if ($withoutFirst !== '') {
                    $wordConditions[] = "pl.name LIKE '%{$withoutFirst}%'";
                    $wordConditions[] = "pl.name LIKE '%{$withoutLast}%'";
                    $wordConditions[] = "pl.description LIKE '%{$withoutFirst}%'";
                    $wordConditions[] = "m.name LIKE '%{$withoutFirst}%'";
                    $wordConditions[] = "m.name LIKE '%{$withoutLast}%'";
                }

                // 4. Ricerca con consonanti (ignora vocali)
                if ($consonantPattern !== '') {
                    $wordConditions[] = "pl.name LIKE '{$consonantPattern}'";
                    $wordConditions[] = "pl.description LIKE '{$consonantPattern}'";
                    $wordConditions[] = "m.name LIKE '{$consonantPattern}'";
                }

                $conditions[] = '(' . implode(' OR ', $wordConditions) . ')';
            }
        }

        if (empty($conditions)) {
            return [];
        }

        return $this->executeProductSearch(implode(' OR ', $conditions), $idLang, $idShop);
    }

    /**
     * Crea pattern fuzzy con wildcard tra le lettere
     * "ethicsport" -> "%e%t%h%i%c%s%p%o%r%t%"
     */
    protected function createFuzzyPattern($word)
    {
        $chars = preg_split('//u', $word, -1, PREG_SPLIT_NO_EMPTY);
        return '%' . implode('%', $chars) . '%';
    }

    /**
     * Estrae solo le consonanti da una parola
     */
    protected function extractConsonants($word)
    {
        return preg_replace('/[aeiouàèéìòù]/iu', '', $word);
    }

    /**
     * Esegue la query di ricerca prodotti
     */
    protected function executeProductSearch($whereCondition, $idLang, $idShop)
    {
        $sql = '
            SELECT DISTINCT
                p.id_product,
                pl.name,
                pl.link_rewrite,
                pl.description_short,
                p.reference,
                p.id_category_default,
                p.id_manufacturer,
                m.name as manufacturer_name,
                cl.name as category_name,
                (SELECT id_image FROM ' . _DB_PREFIX_ . 'image i WHERE i.id_product = p.id_product AND i.cover = 1 LIMIT 1) as id_image
            FROM ' . _DB_PREFIX_ . 'product p
            INNER JOIN ' . _DB_PREFIX_ . 'product_lang pl
                ON p.id_product = pl.id_product
                AND pl.id_lang = ' . (int)$idLang . '
                AND pl.id_shop = ' . (int)$idShop . '
            INNER JOIN ' . _DB_PREFIX_ . 'product_shop ps
                ON p.id_product = ps.id_product
                AND ps.id_shop = ' . (int)$idShop . '
            LEFT JOIN ' . _DB_PREFIX_ . 'manufacturer m
                ON p.id_manufacturer = m.id_manufacturer
            LEFT JOIN ' . _DB_PREFIX_ . 'category_lang cl
                ON p.id_category_default = cl.id_category
                AND cl.id_lang = ' . (int)$idLang . '
            WHERE p.active = 1
            AND ps.active = 1
            AND (' . $whereCondition . ')
            ORDER BY pl.name ASC
            LIMIT 200';

        return Db::getInstance()->executeS($sql);
    }

    /**
     * Ricerca categorie
     */
    protected function searchCategories($query, $idLang, $idShop)
    {
        $sql = '
            SELECT c.id_category, cl.name, cl.link_rewrite
            FROM ' . _DB_PREFIX_ . 'category c
            INNER JOIN ' . _DB_PREFIX_ . 'category_lang cl
                ON c.id_category = cl.id_category
                AND cl.id_lang = ' . (int)$idLang . '
                AND cl.id_shop = ' . (int)$idShop . '
            INNER JOIN ' . _DB_PREFIX_ . 'category_shop cs
                ON c.id_category = cs.id_category
                AND cs.id_shop = ' . (int)$idShop . '
            WHERE c.active = 1
            AND c.id_category > 2
            AND cl.name LIKE \'%' . pSQL($this->escapeLikeWildcards($query)) . '%\'
            ORDER BY cl.name ASC
            LIMIT 5';

        $results = Db::getInstance()->executeS($sql);

        if (!$results) {
            return [];
        }

        $categories = [];
        foreach ($results as $row) {
            $categories[] = [
                'id' => (int)$row['id_category'],
                'name' => $row['name'],
                'url' => $this->context->link->getCategoryLink(
                    $row['id_category'],
                    $row['link_rewrite'],
                    $idLang
                )
            ];
        }

        return $categories;
    }

    /**
     * Ottieni filtri dalla richiesta
     */
    protected function getFiltersFromRequest()
    {
        $filters = [];

        $categoryIds = Tools::getValue('category', '');
        if (!empty($categoryIds)) {
            $filters['category'] = array_map('intval', explode(',', $categoryIds));
        }

        $manufacturerIds = Tools::getValue('manufacturer', '');
        if (!empty($manufacturerIds)) {
            $filters['manufacturer'] = array_map('intval', explode(',', $manufacturerIds));
        }

        $priceMin = Tools::getValue('price_min', '');
        if ($priceMin !== '' && is_numeric($priceMin)) {
            $filters['price_min'] = (float)$priceMin;
        }

        $priceMax = Tools::getValue('price_max', '');
        if ($priceMax !== '' && is_numeric($priceMax)) {
            $filters['price_max'] = (float)$priceMax;
        }

        $inStock = Tools::getValue('in_stock', '');
        if ($inStock !== '') {
            $filters['in_stock'] = (bool)$inStock;
        }

        return $filters;
    }

    /**
     * AJAX endpoint per ottenere banner attivi
     */
    public function displayAjaxBanners()
    {
        header('Content-Type: application/json; charset=utf-8');

        $query = Tools::getValue('q', '');
        $queryWords = array_filter(explode(' ', mb_strtolower(trim($query))));

        $idLang = $this->context->language->id;
        $idShop = $this->context->shop->id;

        // Get active banners
        $sql = '
            SELECT
                id_smartsearch_banner,
                name,
                image,
                link,
                keywords,
                position
            FROM `' . _DB_PREFIX_ . 'smartsearch_banners`
            WHERE id_shop = ' . (int)$idShop . '
            AND active = 1
            AND (date_start IS NULL OR date_start <= NOW())
            AND (date_end IS NULL OR date_end >= NOW())
            ORDER BY position ASC, date_add DESC
        ';

        $banners = Db::getInstance()->executeS($sql);
        $result = [];

        if ($banners) {
            $baseUrl = _MODULE_DIR_ . 'smartsearch/views/img/banners/';

            foreach ($banners as $banner) {
                // Check if banner matches query keywords (if keywords set)
                $showBanner = true;
                if (!empty($banner['keywords'])) {
                    $bannerKeywords = array_map('trim', explode(',', mb_strtolower($banner['keywords'])));
                    $showBanner = false;

                    // Check if any query word matches any banner keyword
                    foreach ($queryWords as $word) {
                        foreach ($bannerKeywords as $keyword) {
                            if (stripos($keyword, $word) !== false || stripos($word, $keyword) !== false) {
                                $showBanner = true;
                                break 2;
                            }
                        }
                    }
                }

                if ($showBanner) {
                    $result[] = [
                        'id' => (int)$banner['id_smartsearch_banner'],
                        'name' => $banner['name'],
                        'image' => $baseUrl . $banner['image'],
                        'link' => $banner['link'] ?: null,
                        'position' => $banner['position'],
                    ];
                }
            }
        }

        die(json_encode([
            'success' => true,
            'banners' => $result
        ]));
    }

    /**
     * AJAX endpoint per ottenere suggerimenti di ricerca
     * Basato sulle ricerche più popolari nel database
     */
    public function displayAjaxSuggestions()
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');

        $query = Tools::getValue('q', '');
        $query = trim(mb_strtolower($query));

        if (mb_strlen($query) < 2) {
            die(json_encode(['suggestions' => []]));
        }

        $idLang = (int)$this->context->language->id;
        $idShop = (int)$this->context->shop->id;

        $suggestions = [];

        // 1. Suggerimenti da ricerche popolari che iniziano con la query
        $sql = '
            SELECT search_query, search_count, results_count
            FROM `' . _DB_PREFIX_ . 'smartsearch_stats`
            WHERE id_lang = ' . $idLang . '
            AND id_shop = ' . $idShop . '
            AND search_query LIKE \'' . pSQL($this->escapeLikeWildcards($query)) . '%\'
            AND results_count > 0
            AND search_count >= 2
            ORDER BY search_count DESC
            LIMIT 5
        ';

        $popularStarting = Db::getInstance()->executeS($sql);
        if ($popularStarting) {
            foreach ($popularStarting as $row) {
                $suggestions[] = [
                    'query' => $row['search_query'],
                    'count' => (int)$row['search_count'],
                    'results' => (int)$row['results_count'],
                    'type' => 'popular'
                ];
            }
        }

        // 2. Suggerimenti da ricerche popolari che contengono la query
        if (count($suggestions) < 8) {
            $existingQueries = array_column($suggestions, 'query');
            $excludeList = !empty($existingQueries)
                ? "AND search_query NOT IN ('" . implode("','", array_map('pSQL', $existingQueries)) . "')"
                : '';

            $sql = '
                SELECT search_query, search_count, results_count
                FROM `' . _DB_PREFIX_ . 'smartsearch_stats`
                WHERE id_lang = ' . $idLang . '
                AND id_shop = ' . $idShop . '
                AND search_query LIKE \'%' . pSQL($this->escapeLikeWildcards($query)) . '%\'
                AND results_count > 0
                AND search_count >= 2
                ' . $excludeList . '
                ORDER BY search_count DESC
                LIMIT ' . (8 - count($suggestions)) . '
            ';

            $popularContaining = Db::getInstance()->executeS($sql);
            if ($popularContaining) {
                foreach ($popularContaining as $row) {
                    $suggestions[] = [
                        'query' => $row['search_query'],
                        'count' => (int)$row['search_count'],
                        'results' => (int)$row['results_count'],
                        'type' => 'related'
                    ];
                }
            }
        }

        // 3. Suggerimenti da nomi prodotti se pochi risultati dalle stats
        if (count($suggestions) < 5) {
            $existingQueries = array_column($suggestions, 'query');

            $sql = '
                SELECT DISTINCT
                    SUBSTRING_INDEX(pl.name, " ", 3) as suggestion
                FROM `' . _DB_PREFIX_ . 'product_lang` pl
                INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON pl.id_product = ps.id_product AND ps.id_shop = ' . $idShop . '
                WHERE pl.id_lang = ' . $idLang . '
                AND ps.active = 1
                AND pl.name LIKE \'' . pSQL($this->escapeLikeWildcards($query)) . '%\'
                ORDER BY pl.name ASC
                LIMIT ' . (5 - count($suggestions)) . '
            ';

            $productNames = Db::getInstance()->executeS($sql);
            if ($productNames) {
                foreach ($productNames as $row) {
                    $suggestion = trim($row['suggestion']);
                    if (!empty($suggestion) && !in_array(mb_strtolower($suggestion), array_map('mb_strtolower', $existingQueries))) {
                        $suggestions[] = [
                            'query' => $suggestion,
                            'count' => 0,
                            'results' => 0,
                            'type' => 'product'
                        ];
                    }
                }
            }
        }

        // 4. Suggerimenti da nomi brand
        if (count($suggestions) < 8) {
            $sql = '
                SELECT DISTINCT m.name
                FROM `' . _DB_PREFIX_ . 'manufacturer` m
                INNER JOIN `' . _DB_PREFIX_ . 'product` p ON p.id_manufacturer = m.id_manufacturer
                INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps ON p.id_product = ps.id_product AND ps.id_shop = ' . $idShop . '
                WHERE m.name LIKE \'' . pSQL($this->escapeLikeWildcards($query)) . '%\'
                AND p.active = 1
                LIMIT 3
            ';

            $brands = Db::getInstance()->executeS($sql);
            if ($brands) {
                $existingQueries = array_column($suggestions, 'query');
                foreach ($brands as $row) {
                    if (!in_array(mb_strtolower($row['name']), array_map('mb_strtolower', $existingQueries))) {
                        $suggestions[] = [
                            'query' => $row['name'],
                            'count' => 0,
                            'results' => 0,
                            'type' => 'brand'
                        ];
                    }
                }
            }
        }

        die(json_encode([
            'success' => true,
            'suggestions' => $suggestions
        ], JSON_UNESCAPED_UNICODE));
    }

    /**
     * Ottiene i banner attivi che matchano la query
     */
    protected function getBannersForQuery($query, $idShop)
    {
        $queryWords = array_filter(explode(' ', mb_strtolower(trim($query))));

        // Get active banners
        $sql = '
            SELECT
                id_smartsearch_banner,
                name,
                image,
                link,
                keywords,
                position
            FROM `' . _DB_PREFIX_ . 'smartsearch_banners`
            WHERE id_shop = ' . (int)$idShop . '
            AND active = 1
            AND (date_start IS NULL OR date_start <= NOW())
            AND (date_end IS NULL OR date_end >= NOW())
            ORDER BY position ASC, date_add DESC
        ';

        $banners = Db::getInstance()->executeS($sql);
        $result = [];

        if ($banners) {
            $baseUrl = _MODULE_DIR_ . 'smartsearch/views/img/banners/';

            foreach ($banners as $banner) {
                // Check if banner matches query keywords (if keywords set)
                $showBanner = true;
                if (!empty($banner['keywords'])) {
                    $bannerKeywords = array_map('trim', explode(',', mb_strtolower($banner['keywords'])));
                    $showBanner = false;

                    // Check if any query word matches any banner keyword
                    foreach ($queryWords as $word) {
                        if (strlen($word) < 2) continue;
                        foreach ($bannerKeywords as $keyword) {
                            if (stripos($keyword, $word) !== false || stripos($word, $keyword) !== false) {
                                $showBanner = true;
                                break 2;
                            }
                        }
                    }
                }

                if ($showBanner) {
                    $result[] = [
                        'id' => (int)$banner['id_smartsearch_banner'],
                        'name' => $banner['name'],
                        'image' => $baseUrl . $banner['image'],
                        'link' => $banner['link'] ?: null,
                        'position' => $banner['position'],
                    ];
                }
            }
        }

        return $result;
    }

    // =========================================================================
    // RATE LIMITING
    // =========================================================================

    /**
     * Controlla il rate limit per l'IP corrente.
     * Usa file temporanei per evitare query DB su ogni richiesta.
     *
     * @param int $maxRequests Numero massimo di richieste nella finestra
     * @param int $windowSeconds Dimensione della finestra in secondi
     * @return bool true se la richiesta è consentita, false se limitata
     */
    protected function dieRateLimit()
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Retry-After: 60');
        http_response_code(429);
        die(json_encode([
            'error' => true,
            'message' => 'Too many requests. Please try again later.',
            'products' => [],
            'total' => 0
        ], JSON_UNESCAPED_UNICODE));
    }

    protected function checkRateLimit($maxRequests = 30, $windowSeconds = 60, $key = 'search')
    {
        $ip = Tools::getRemoteAddr();
        if (empty($ip)) {
            return true;
        }

        $rateLimitDir = _PS_CACHE_DIR_ . 'smartsearch/ratelimit/';
        if (!is_dir($rateLimitDir)) {
            @mkdir($rateLimitDir, 0755, true);
        }

        $file = $rateLimitDir . md5($ip . '_' . $key) . '.json';
        $now = time();
        $allowed = true;

        // Operazione atomica con flock per evitare race condition TOCTOU
        $fp = @fopen($file, 'c+');
        if (!$fp) {
            return true; // Se non riusciamo ad aprire il file, non bloccare
        }

        if (flock($fp, LOCK_EX)) {
            $data = stream_get_contents($fp);
            $timestamps = [];
            if (!empty($data)) {
                $decoded = json_decode($data, true);
                if (is_array($decoded)) {
                    $timestamps = $decoded;
                }
            }

            // Rimuovi timestamp fuori dalla finestra
            $timestamps = array_values(array_filter($timestamps, function ($ts) use ($now, $windowSeconds) {
                return ($now - $ts) < $windowSeconds;
            }));

            if (count($timestamps) >= $maxRequests) {
                $allowed = false;
            } else {
                $timestamps[] = $now;
                ftruncate($fp, 0);
                rewind($fp);
                fwrite($fp, json_encode($timestamps));
            }

            flock($fp, LOCK_UN);
        }

        fclose($fp);

        // Pulizia periodica file scaduti (1% probabilità per richiesta)
        if ($allowed && mt_rand(1, 100) === 1) {
            $this->cleanRateLimitFiles($rateLimitDir, $windowSeconds);
        }

        return $allowed;
    }

    /**
     * Rimuove i file di rate limit scaduti
     */
    protected function cleanRateLimitFiles($dir, $windowSeconds)
    {
        $files = @glob($dir . '*.json');
        if (!is_array($files)) {
            return;
        }
        $now = time();
        foreach ($files as $file) {
            if (($now - filemtime($file)) > $windowSeconds * 2) {
                @unlink($file);
            }
        }
    }

    // =========================================================================
    // CACHE SYSTEM
    // =========================================================================

    /**
     * Recupera risultati dalla cache
     *
     * @param string $key   Chiave cache
     * @param int    $ttl   TTL in secondi (default 300 = 5 minuti)
     * @return array|false
     */
    protected function getFromCache($key, $ttl = 300)
    {
        // Cache PS nativa: salviamo un wrapper {ts, data} per validare il TTL
        if (class_exists('Cache') && method_exists('Cache', 'getInstance')) {
            $cache = Cache::getInstance();
            if ($cache->exists($key)) {
                $raw = $cache->get($key);
                if ($raw !== false) {
                    $wrapper = json_decode($raw, true);
                    if (is_array($wrapper) && isset($wrapper['_ts'], $wrapper['_data'])) {
                        if ((time() - $wrapper['_ts']) < $ttl) {
                            return $wrapper['_data'];
                        }
                        // Scaduto per il nostro TTL: ignora e prosegui
                    } else {
                        // Formato vecchio (pre-wrapper): decodifica direttamente
                        $decoded = is_string($raw) ? json_decode($raw, true) : null;
                        if ($decoded !== null) {
                            return $decoded;
                        }
                    }
                }
            }
        }

        // Fallback: cache su database
        $sql = 'SELECT result_data FROM `' . _DB_PREFIX_ . 'smartsearch_cache`
                WHERE cache_key = \'' . pSQL($key) . '\'
                AND created_at > DATE_SUB(NOW(), INTERVAL ' . (int) $ttl . ' SECOND)
                LIMIT 1';

        try {
            $result = Db::getInstance()->getRow($sql);
            if ($result && !empty($result['result_data'])) {
                return json_decode($result['result_data'], true);
            }
        } catch (Throwable $e) {
            // Table might not exist
        }

        return false;
    }

    /**
     * Salva risultati in cache
     */
    protected function saveToCache($key, $data, $ttl = 300)
    {
        // Wrappa i dati con timestamp per validare il TTL nella cache PS nativa
        $wrapper = json_encode(['_ts' => time(), '_data' => $data], JSON_UNESCAPED_UNICODE);

        // Cache PS nativa: salva wrapper con timestamp
        if (class_exists('Cache') && method_exists('Cache', 'getInstance')) {
            $cache = Cache::getInstance();
            $cache->set($key, $wrapper, $ttl);
        }

        // Salva anche su database (dati raw, il TTL è controllato da created_at)
        $idLang = (int)$this->context->language->id;
        $idShop = (int)$this->context->shop->id;
        $query = Tools::getValue('q', '');
        $jsonData = json_encode($data, JSON_UNESCAPED_UNICODE);

        // Usa REPLACE per aggiornare se esiste
        $sql = 'REPLACE INTO `' . _DB_PREFIX_ . 'smartsearch_cache`
                (cache_key, result_data, query, id_lang, id_shop, created_at)
                VALUES (
                    \'' . pSQL($key) . '\',
                    \'' . pSQL($jsonData) . '\',
                    \'' . pSQL($query) . '\',
                    ' . $idLang . ',
                    ' . $idShop . ',
                    NOW()
                )';

        try {
            Db::getInstance()->execute($sql);
        } catch (Throwable $e) {
            // Cache table might not exist, ignore
        }
    }

    /**
     * Pulisce la cache scaduta (più di 10 minuti)
     */
    public static function cleanExpiredCache()
    {
        // Usa 1 ora per coprire il TTL massimo (facets = 600s) con margine
        $sql = 'DELETE FROM `' . _DB_PREFIX_ . 'smartsearch_cache`
                WHERE created_at < DATE_SUB(NOW(), INTERVAL 1 HOUR)';
        try {
            Db::getInstance()->execute($sql);
        } catch (Throwable $e) {
            // Ignore
        }
    }

    // =========================================================================
    // "FORSE CERCAVI..." (Did You Mean)
    // =========================================================================

    /**
     * Genera suggerimenti "Forse cercavi..." per query con pochi risultati
     */
    protected function getDidYouMeanSuggestions($query, $idLang, $idShop)
    {
        $suggestions = [];
        $queryLower = mb_strtolower(trim($query));

        // 1. Cerca query popolari simili (basate su ricerche precedenti con risultati)
        $popularSuggestions = $this->getSimilarPopularQueries($queryLower, $idLang, $idShop);
        $suggestions = array_merge($suggestions, $popularSuggestions);

        // 2. Cerca nomi prodotti simili
        $productSuggestions = $this->getSimilarProductNames($queryLower, $idLang, $idShop);
        $suggestions = array_merge($suggestions, $productSuggestions);

        // 3. Cerca marche simili
        $brandSuggestions = $this->getSimilarBrands($queryLower);
        $suggestions = array_merge($suggestions, $brandSuggestions);

        // Rimuovi duplicati e limita
        $suggestions = array_unique($suggestions);
        $suggestions = array_filter($suggestions, function($s) use ($queryLower) {
            return mb_strtolower($s) !== $queryLower; // Escludi la query originale
        });

        return array_slice(array_values($suggestions), 0, 5);
    }

    /**
     * Trova query popolari simili usando Levenshtein
     */
    protected function getSimilarPopularQueries($query, $idLang, $idShop)
    {
        $suggestions = [];

        // Ottieni ricerche popolari con risultati
        $sql = 'SELECT search_query, search_count
                FROM `' . _DB_PREFIX_ . 'smartsearch_stats`
                WHERE id_lang = ' . (int)$idLang . '
                AND id_shop = ' . (int)$idShop . '
                AND results_count > 0
                AND search_count >= 3
                ORDER BY search_count DESC
                LIMIT 200';

        try {
            $popularQueries = Db::getInstance()->executeS($sql);

            if ($popularQueries) {
                foreach ($popularQueries as $row) {
                    $popular = mb_strtolower($row['search_query']);

                    // Calcola distanza Levenshtein
                    $distance = levenshtein($query, $popular);
                    $maxLen = max(strlen($query), strlen($popular));

                    // Suggerisci se simile (distanza <= 30% della lunghezza)
                    if ($distance > 0 && $distance <= ceil($maxLen * 0.3)) {
                        $suggestions[] = [
                            'term' => $row['search_query'],
                            'score' => $row['search_count'] - ($distance * 10)
                        ];
                    }

                    // Controlla anche se una contiene l'altra
                    if (strpos($popular, $query) !== false || strpos($query, $popular) !== false) {
                        if ($popular !== $query) {
                            $suggestions[] = [
                                'term' => $row['search_query'],
                                'score' => $row['search_count']
                            ];
                        }
                    }
                }

                // Ordina per score e prendi i termini
                usort($suggestions, function($a, $b) { return $b['score'] <=> $a['score']; });
                $suggestions = array_column(array_slice($suggestions, 0, 3), 'term');
            }
        } catch (Throwable $e) {
            // Table might not exist
        }

        return $suggestions;
    }

    /**
     * Trova nomi prodotti simili
     */
    protected function getSimilarProductNames($query, $idLang, $idShop)
    {
        $suggestions = [];

        // Estrai parole dalla query
        $words = array_filter(explode(' ', $query), function($w) { return strlen($w) >= 3; });

        if (empty($words)) {
            return [];
        }

        // Cerca prodotti con nomi simili
        $likeConditions = [];
        foreach ($words as $word) {
            $likeConditions[] = "pl.name LIKE '%" . pSQL($this->escapeLikeWildcards($word)) . "%'";
        }

        $sql = '
            SELECT DISTINCT pl.name
            FROM ' . _DB_PREFIX_ . 'product_lang pl
            INNER JOIN ' . _DB_PREFIX_ . 'product_shop ps ON pl.id_product = ps.id_product
                AND ps.id_shop = ' . (int)$idShop . '
            WHERE pl.id_lang = ' . (int)$idLang . '
            AND ps.active = 1
            AND (' . implode(' OR ', $likeConditions) . ')
            LIMIT 200';

        $results = Db::getInstance()->executeS($sql);

        if ($results) {
            foreach ($results as $row) {
                $name = $row['name'];
                $nameLower = mb_strtolower($name);

                // Estrai le prime 2-3 parole significative come suggerimento
                $nameWords = explode(' ', $name);
                $suggestion = implode(' ', array_slice($nameWords, 0, 3));

                if (strlen($suggestion) >= 4 && $nameLower !== $query) {
                    $suggestions[] = $suggestion;
                }
            }
        }

        return array_slice(array_unique($suggestions), 0, 2);
    }

    /**
     * Trova marche simili
     */
    protected function getSimilarBrands($query)
    {
        $suggestions = [];

        $sql = '
            SELECT DISTINCT m.name
            FROM ' . _DB_PREFIX_ . 'manufacturer m
            WHERE m.active = 1
            AND m.name LIKE \'%' . pSQL($this->escapeLikeWildcards($query)) . '%\'
            LIMIT 5';

        $results = Db::getInstance()->executeS($sql);

        if ($results) {
            foreach ($results as $row) {
                $brand = $row['name'];
                if (mb_strtolower($brand) !== $query) {
                    $suggestions[] = $brand;
                }
            }
        }

        // Prova anche fuzzy match su brand
        if (empty($suggestions) && strlen($query) >= 3) {
            $sql = 'SELECT name FROM ' . _DB_PREFIX_ . 'manufacturer WHERE active = 1 LIMIT 50';
            $brands = Db::getInstance()->executeS($sql);

            if ($brands) {
                foreach ($brands as $row) {
                    $brand = $row['name'];
                    $brandLower = mb_strtolower($brand);
                    $distance = levenshtein($query, $brandLower);

                    if ($distance > 0 && $distance <= 2) {
                        $suggestions[] = $brand;
                    }
                }
            }
        }

        return array_slice($suggestions, 0, 2);
    }

    // =========================================================================
    // SEARCH TRACKING (per statistiche e "forse cercavi")
    // =========================================================================

    /**
     * Learning-to-rank: applica un moltiplicatore al punteggio in base alle
     * performance storiche (click, carrelli, ordini) dei prodotti per QUESTA
     * query. Il prodotto con la performance migliore riceve il boost massimo
     * (configurabile), gli altri in proporzione. La rilevanza resta primaria.
     *
     * @return array
     */
    protected function applyLearningToRank($results, $query, $idShop, $idLang)
    {
        if (empty($results) || !(int) Configuration::get('SMARTSEARCH_LTR_ENABLED')) {
            return $results;
        }

        $queryNorm = $this->module->normalizeClickQuery($query);
        if ($queryNorm === '') {
            return $results;
        }

        $rows = Db::getInstance()->executeS(
            'SELECT id_product, clicks, carts, orders
             FROM `' . _DB_PREFIX_ . 'smartsearch_click_stats`
             WHERE query_norm = \'' . pSQL($queryNorm) . '\'
               AND id_shop = ' . (int) $idShop . '
               AND id_lang = ' . (int) $idLang
        );

        if (!$rows) {
            return $results;
        }

        // Pesi: click = 1, aggiunta al carrello = 3, ordine = 6
        $weights = [];
        $maxWeight = 0;
        foreach ($rows as $r) {
            $w = (int) $r['clicks'] + ((int) $r['carts'] * 3) + ((int) $r['orders'] * 6);
            if ($w > 0) {
                $weights[(int) $r['id_product']] = $w;
                if ($w > $maxWeight) {
                    $maxWeight = $w;
                }
            }
        }

        if ($maxWeight <= 0) {
            return $results;
        }

        // Forza del boost: 0..100 -> 0..1 (es. 50 => fino a +50% per il migliore)
        $strength = ((int) Configuration::get('SMARTSEARCH_LTR_STRENGTH') ?: 50) / 100;

        foreach ($results as &$product) {
            $pid = (int) ($product['id_product'] ?? 0);
            if ($pid > 0 && isset($weights[$pid])) {
                $product['_ltr_score'] = 1 + $strength * ($weights[$pid] / $maxWeight);
            } else {
                $product['_ltr_score'] = 1.0;
            }
        }
        unset($product);

        return $results;
    }

    /**
     * Endpoint AJAX per tracciare un evento su un risultato di ricerca
     * (learning-to-rank). Registra click e aggiunte al carrello per la
     * coppia query -> prodotto e memorizza l'ultima query in un cookie per
     * l'attribuzione delle conversioni all'ordine.
     */
    protected function displayAjaxTrack()
    {
        header('Content-Type: application/json; charset=utf-8');

        try {
            if (!(int) Configuration::get('SMARTSEARCH_LTR_ENABLED')) {
                die(json_encode(['ok' => false]));
            }

            $query = trim(strip_tags(Tools::getValue('q', '')));
            $idProduct = (int) Tools::getValue('id_product', 0);
            $event = Tools::getValue('event', 'click');
            $map = ['click' => 'clicks', 'cart' => 'carts'];

            if ($query === '' || mb_strlen($query) < 2 || $idProduct <= 0 || !isset($map[$event])) {
                die(json_encode(['ok' => false]));
            }

            $idLang = (int) $this->context->language->id;
            $idShop = (int) $this->context->shop->id;
            $queryNorm = $this->module->normalizeClickQuery($query);

            $this->module->recordClickStat($queryNorm, $idProduct, $idLang, $idShop, $map[$event]);

            // Memorizza l'ultima query per l'attribuzione delle conversioni
            if ($event === 'click') {
                $this->context->cookie->smartsearch_last_q = $queryNorm;
                $this->context->cookie->smartsearch_last_q_ts = time();
                $this->context->cookie->write();
            }

            // Nota: il nuovo ranking viene applicato al scadere della cache
            // dei risultati (TTL breve). L'LTR e' un segnale graduale, non
            // richiede effetto immediato sulla singola query.
            die(json_encode(['ok' => true]));
        } catch (Throwable $e) {
            die(json_encode(['ok' => false]));
        }
    }

    /**
     * Traccia la query di ricerca per statistiche
     */
    protected function trackSearchQuery($query, $resultsCount, $idLang, $idShop)
    {
        $query = trim($query);
        if (strlen($query) < 2) {
            return;
        }

        try {
            // Controlla se esiste già
            $sql = 'SELECT id_smartsearch_stats, search_count
                    FROM `' . _DB_PREFIX_ . 'smartsearch_stats`
                    WHERE search_query = \'' . pSQL($query) . '\'
                    AND id_lang = ' . (int)$idLang . '
                    AND id_shop = ' . (int)$idShop . '
                    LIMIT 1';

            $existing = Db::getInstance()->getRow($sql);

            if ($existing) {
                // Aggiorna contatore
                $sql = 'UPDATE `' . _DB_PREFIX_ . 'smartsearch_stats`
                        SET search_count = search_count + 1,
                            results_count = ' . (int)$resultsCount . ',
                            last_search = NOW()
                        WHERE id_smartsearch_stats = ' . (int)$existing['id_smartsearch_stats'];
            } else {
                // Inserisci nuovo
                $sql = 'INSERT INTO `' . _DB_PREFIX_ . 'smartsearch_stats`
                        (search_query, search_count, results_count, id_lang, id_shop, last_search, date_add)
                        VALUES (
                            \'' . pSQL($query) . '\',
                            1,
                            ' . (int)$resultsCount . ',
                            ' . (int)$idLang . ',
                            ' . (int)$idShop . ',
                            NOW(),
                            NOW()
                        )';
            }

            Db::getInstance()->execute($sql);
        } catch (Throwable $e) {
            // Ignore - table might not exist
        }
    }
}
