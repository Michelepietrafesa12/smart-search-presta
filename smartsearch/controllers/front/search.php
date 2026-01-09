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

            // Ricerca prodotti semplice
            $products = $this->searchProducts($query, $idLang, $idShop);

            // Ricerca categorie
            $categories = $this->searchCategories($query, $idLang, $idShop);

            // Ottieni banner attivi per questa query
            $banners = $this->getBannersForQuery($query, $idShop);

            // Debug: conta regole boost attive
            $boostCount = (int)Db::getInstance()->getValue('
                SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'smartsearch_boost`
                WHERE id_shop = ' . (int)$idShop . ' AND active = 1
            ');

            die(json_encode([
                'products' => $products,
                'categories' => $categories,
                'total' => count($products),
                'query' => $query,
                'facets' => [],
                'banners' => $banners,
                'did_you_mean' => [],
                '_debug' => [
                    'boost_rules_active' => $boostCount,
                    'boosted_products' => array_values(array_filter(array_map(function($p) {
                        if (isset($p['boost_score']) && $p['boost_score'] > 1) {
                            return ['id' => $p['id'], 'name' => $p['name'], 'boost' => $p['boost_score']];
                        }
                        return null;
                    }, $products)))
                ]
            ], JSON_UNESCAPED_UNICODE));

        } catch (Exception $e) {
            die(json_encode([
                'products' => [],
                'categories' => [],
                'total' => 0,
                'query' => Tools::getValue('q', ''),
                'error' => $e->getMessage()
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

            // Ottieni marche con conteggio prodotti
            $brands = $this->getAvailableBrands($idLang, $idShop);

            // Ottieni categorie con conteggio prodotti
            $categories = $this->getAvailableCategories($idLang, $idShop);

            // Ottieni range prezzi
            $priceRange = $this->getPriceRange($idShop);

            die(json_encode([
                'brands' => $brands,
                'categories' => $categories,
                'price_range' => $priceRange
            ], JSON_UNESCAPED_UNICODE));

        } catch (Exception $e) {
            die(json_encode([
                'brands' => [],
                'categories' => [],
                'price_range' => ['min' => 0, 'max' => 1000],
                'error' => $e->getMessage()
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
            ORDER BY m.name ASC
            LIMIT 50';

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
            ORDER BY cl.name ASC
            LIMIT 50';

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

        return [
            'min' => (int)($result['min_price'] ?? 0),
            'max' => (int)($result['max_price'] ?? 1000)
        ];
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

        } catch (Exception $e) {
            die(json_encode([
                'products' => [],
                'total' => 0,
                'error' => $e->getMessage()
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
                'in_stock' => StockAvailable::getQuantityAvailableByProduct($row['id_product']) > 0,
                'total_sold' => isset($row['total_sold']) ? (int)$row['total_sold'] : 0,
                'boost_score' => isset($row['_boost_score']) ? (float)$row['_boost_score'] : 1.0
            ];
        }
        return $products;
    }

    /**
     * Ottieni i prodotti più venduti dal database ordini
     */
    protected function getBestsellers($idLang, $idShop, $limit = 12)
    {
        // Query per trovare i prodotti più venduti basandosi sugli ordini
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
                IFNULL(SUM(od.product_quantity), 0) as total_sold
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
            LEFT JOIN ' . _DB_PREFIX_ . 'order_detail od
                ON od.product_id = p.id_product
            LEFT JOIN ' . _DB_PREFIX_ . 'orders o
                ON o.id_order = od.id_order
                AND o.valid = 1
            WHERE p.active = 1
            AND ps.active = 1
            GROUP BY p.id_product
            HAVING total_sold > 0
            ORDER BY total_sold DESC, pl.name ASC
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

            switch ($action) {
                case 'analytics':
                    $this->displayAjaxAnalytics();
                    break;
                case 'filters':
                    $this->displayAjaxFilters();
                    break;
                case 'bestsellers':
                    $this->displayAjaxBestsellers();
                    break;
                case 'banners':
                    $this->displayAjaxBanners();
                    break;
                case 'search':
                default:
                    $this->displayAjaxSearch();
                    break;
            }
            // Non continuare dopo una risposta AJAX
            return;
        }
        parent::initContent();
    }

    /**
     * Ricerca prodotti con supporto fuzzy/tollerante e boosting
     */
    protected function searchProducts($query, $idLang, $idShop)
    {
        // Prima prova ricerca esatta
        $results = $this->searchProductsExact($query, $idLang, $idShop);

        // Se non trova nulla, prova ricerca fuzzy
        if (empty($results)) {
            $results = $this->searchProductsFuzzy($query, $idLang, $idShop);
        }

        if (!$results) {
            return [];
        }

        // Applica boosting ai risultati
        $results = $this->applyBoosting($results, $query, $idShop);

        return $this->formatProducts($results, $idLang);
    }

    /**
     * Applica boost ai prodotti in base alle regole configurate
     *
     * LOGICA:
     * - Se la regola ha KEYWORDS: il boost si applica solo se la query contiene quelle keyword
     * - Se la regola NON ha keywords: il boost si applica sempre al prodotto specificato
     */
    protected function applyBoosting($products, $query, $idShop)
    {
        if (empty($products)) {
            return $products;
        }

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

        // Calcola score per ogni prodotto
        foreach ($products as &$product) {
            $product['_boost_score'] = 1.0;
            $productId = isset($product['id_product']) ? (int)$product['id_product'] : 0;

            if (!$productId) {
                continue;
            }

            // Controlla ogni regola di boost
            foreach ($boostRules as $rule) {
                $ruleProductId = (int)$rule['id_product'];
                $boostValue = (float)$rule['boost_value'];
                $ruleKeywords = trim($rule['keywords'] ?? '');

                // Questa regola si applica a questo prodotto?
                if ($ruleProductId != $productId) {
                    continue; // Regola per un altro prodotto
                }

                // Se la regola ha keywords, verifica che la query le contenga
                if (!empty($ruleKeywords)) {
                    $keywords = array_map('trim', explode(',', mb_strtolower($ruleKeywords)));
                    $keywordMatches = false;

                    foreach ($keywords as $keyword) {
                        if (empty($keyword)) continue;

                        // Verifica se la query contiene questa keyword
                        foreach ($queryWords as $word) {
                            if (strlen($word) < 2) continue;
                            // Match parziale in entrambe le direzioni
                            if (stripos($keyword, $word) !== false || stripos($word, $keyword) !== false) {
                                $keywordMatches = true;
                                break 2;
                            }
                        }
                    }

                    // Se ha keywords ma non matchano, non applicare il boost
                    if (!$keywordMatches) {
                        continue;
                    }
                }

                // Applica il boost (solo una volta per regola)
                $product['_boost_score'] *= $boostValue;
            }
        }

        // Ordina per boost score (più alto prima), poi per nome
        usort($products, function($a, $b) {
            $scoreA = isset($a['_boost_score']) ? $a['_boost_score'] : 1.0;
            $scoreB = isset($b['_boost_score']) ? $b['_boost_score'] : 1.0;

            if ($scoreA != $scoreB) {
                return ($scoreB > $scoreA) ? 1 : -1; // Ordine decrescente per score
            }

            // A parità di score, ordina per nome
            $nameA = isset($a['name']) ? $a['name'] : '';
            $nameB = isset($b['name']) ? $b['name'] : '';
            return strcmp($nameA, $nameB);
        });

        return $products;
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
                $word = pSQL($word);
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
    protected function searchProductsFuzzy($query, $idLang, $idShop)
    {
        $words = explode(' ', $query);
        $conditions = [];

        foreach ($words as $word) {
            if (mb_strlen($word) >= 3) {
                $word = pSQL($word);
                $wordConditions = [];

                // 1. Ricerca SOUNDEX (fonetica)
                $wordConditions[] = "SOUNDEX(pl.name) = SOUNDEX('{$word}')";
                $wordConditions[] = "SOUNDEX(m.name) = SOUNDEX('{$word}')";

                // 2. Ricerca con wildcard tra le lettere (per typos)
                $fuzzyPattern = $this->createFuzzyPattern($word);
                $wordConditions[] = "pl.name LIKE '{$fuzzyPattern}'";
                $wordConditions[] = "pl.description LIKE '{$fuzzyPattern}'";
                $wordConditions[] = "m.name LIKE '{$fuzzyPattern}'";

                // 3. Ricerca senza la prima/ultima lettera (per errori comuni)
                if (mb_strlen($word) > 3) {
                    $withoutFirst = mb_substr($word, 1);
                    $withoutLast = mb_substr($word, 0, -1);
                    $wordConditions[] = "pl.name LIKE '%{$withoutFirst}%'";
                    $wordConditions[] = "pl.name LIKE '%{$withoutLast}%'";
                    $wordConditions[] = "pl.description LIKE '%{$withoutFirst}%'";
                    $wordConditions[] = "m.name LIKE '%{$withoutFirst}%'";
                    $wordConditions[] = "m.name LIKE '%{$withoutLast}%'";
                }

                // 4. Ricerca con consonanti (ignora vocali)
                $consonants = $this->extractConsonants($word);
                if (mb_strlen($consonants) >= 3) {
                    $consonantPattern = '%' . implode('%', str_split($consonants)) . '%';
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
            LIMIT 20';

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
            AND cl.name LIKE \'%' . pSQL($query) . '%\'
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
     * AJAX endpoint proxy per analytics - evita CORS
     * Riceve dati dal frontend e li inoltra a n8n server-side
     * Fail-safe: non deve mai bloccare il frontend
     */
    public function displayAjaxAnalytics()
    {
        // Pulisci qualsiasi output precedente
        if (ob_get_level()) {
            ob_end_clean();
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Cache-Control: no-cache, no-store, must-revalidate');

        try {
            // Verifica che cURL sia disponibile
            if (!function_exists('curl_init')) {
                die(json_encode(['success' => false, 'error' => 'cURL not available', 'debug' => 'curl_init not found']));
            }

            // Leggi il body JSON della richiesta
            $inputJSON = file_get_contents('php://input');
            if (empty($inputJSON)) {
                die(json_encode(['success' => false, 'error' => 'Empty request body', 'debug' => 'No POST data received']));
            }

            $data = json_decode($inputJSON, true);

            if (!$data || !is_array($data)) {
                die(json_encode(['success' => false, 'error' => 'Invalid JSON', 'debug' => 'JSON decode failed: ' . json_last_error_msg()]));
            }

            // Sanitizza i dati (rimuovi potenziali script injection)
            $data = $this->sanitizeAnalyticsData($data);

            // Ottieni URL webhook dalla configurazione
            $webhookUrl = Configuration::get('SMARTSEARCH_ANALYTICS_WEBHOOK_URL');

            if (empty($webhookUrl)) {
                die(json_encode(['success' => false, 'error' => 'Webhook URL not configured', 'debug' => 'SMARTSEARCH_ANALYTICS_WEBHOOK_URL is empty in PrestaShop configuration']));
            }

            // Valida URL webhook
            if (!filter_var($webhookUrl, FILTER_VALIDATE_URL)) {
                die(json_encode(['success' => false, 'error' => 'Invalid webhook URL', 'debug' => 'URL validation failed for: ' . substr($webhookUrl, 0, 50)]));
            }

            // Inoltra i dati a n8n con timeout basso
            $ch = curl_init($webhookUrl);

            if ($ch === false) {
                die(json_encode(['success' => false, 'error' => 'cURL init failed', 'debug' => 'curl_init returned false']));
            }

            $jsonPayload = json_encode($data, JSON_UNESCAPED_UNICODE);

            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $jsonPayload,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: application/json',
                    'Content-Length: ' . strlen($jsonPayload)
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,          // Max 10 secondi
                CURLOPT_CONNECTTIMEOUT => 5,    // Max 5 secondi per connessione
                CURLOPT_NOSIGNAL => 1,
                // Disabilita verifica SSL per server locali/self-signed (n8n spesso ha certificati self-signed)
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_USERAGENT => 'SmartSearch/2.0 PrestaShop Analytics'
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            $errno = curl_errno($ch);
            curl_close($ch);

            if ($error) {
                die(json_encode([
                    'success' => false,
                    'error' => 'Connection error',
                    'debug' => 'cURL error (' . $errno . '): ' . $error,
                    'webhook_url' => substr($webhookUrl, 0, 50) . '...'
                ]));
            }

            // Considera successo se HTTP 2xx
            $success = $httpCode >= 200 && $httpCode < 300;

            die(json_encode([
                'success' => $success,
                'http_code' => $httpCode,
                'debug' => $success ? 'Data forwarded successfully' : 'Webhook returned non-2xx status',
                'response_preview' => substr($response, 0, 200)
            ]));

        } catch (Exception $e) {
            die(json_encode([
                'success' => false,
                'error' => 'Server error',
                'debug' => 'Exception: ' . $e->getMessage()
            ]));
        }
    }

    /**
     * Sanitizza dati analytics per sicurezza
     */
    protected function sanitizeAnalyticsData($data, $depth = 0)
    {
        // Previeni ricorsione infinita
        if ($depth > 5) {
            return [];
        }

        // Non filtrare troppo - permetti tutti i campi ma sanitizza i valori
        $sanitized = [];

        foreach ($data as $key => $value) {
            // Sanitizza la chiave
            $cleanKey = preg_replace('/[^a-zA-Z0-9_]/', '', $key);
            if (empty($cleanKey) || strlen($cleanKey) > 50) {
                continue;
            }

            // Sanitizza il valore in base al tipo
            if (is_string($value)) {
                // Rimuovi tag HTML e limita lunghezza
                $sanitized[$cleanKey] = strip_tags(mb_substr($value, 0, 1000));
            } elseif (is_numeric($value)) {
                $sanitized[$cleanKey] = $value;
            } elseif (is_array($value)) {
                // Limita dimensione array e ricorsivamente sanitizza
                $limitedArray = array_slice($value, 0, 100);
                $sanitized[$cleanKey] = $this->sanitizeAnalyticsData($limitedArray, $depth + 1);
            } elseif (is_bool($value)) {
                $sanitized[$cleanKey] = $value;
            } elseif (is_null($value)) {
                $sanitized[$cleanKey] = null;
            }
        }

        return $sanitized;
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
}
