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
        }
        return $expanded;
    }

    /**
     * Genera variazioni per unità di misura
     * Es: "350g" -> ["350g", "350 g", "350gr", "350 gr"]
     */
    protected function getUnitVariations($word)
    {
        $variations = [];

        // Pattern: numero + unità (es: 350g, 500ml, 1kg)
        if (preg_match('/^(\d+)(g|gr|kg|mg|ml|l|cl|oz|lb|caps|cps|tab|tabs|compresse|bustine|porzioni)$/i', $word, $matches)) {
            $number = $matches[1];
            $unit = mb_strtolower($matches[2]);

            // Variazioni base
            $variations[] = $number . $unit;           // 350g
            $variations[] = $number . ' ' . $unit;     // 350 g

            // Variazioni specifiche per unità
            if ($unit === 'g' || $unit === 'gr') {
                $variations[] = $number . 'g';
                $variations[] = $number . ' g';
                $variations[] = $number . 'gr';
                $variations[] = $number . ' gr';
            } elseif ($unit === 'kg') {
                $variations[] = $number . 'kg';
                $variations[] = $number . ' kg';
            } elseif ($unit === 'mg') {
                $variations[] = $number . 'mg';
                $variations[] = $number . ' mg';
            } elseif ($unit === 'ml') {
                $variations[] = $number . 'ml';
                $variations[] = $number . ' ml';
            } elseif ($unit === 'l') {
                $variations[] = $number . 'l';
                $variations[] = $number . ' l';
                $variations[] = $number . 'lt';
                $variations[] = $number . ' lt';
            } elseif ($unit === 'caps' || $unit === 'cps') {
                $variations[] = $number . 'caps';
                $variations[] = $number . ' caps';
                $variations[] = $number . 'cps';
                $variations[] = $number . ' cps';
                $variations[] = $number . ' capsule';
            } elseif ($unit === 'tab' || $unit === 'tabs') {
                $variations[] = $number . 'tab';
                $variations[] = $number . ' tab';
                $variations[] = $number . 'tabs';
                $variations[] = $number . ' tabs';
                $variations[] = $number . ' compresse';
            }
        }

        // Pattern: numero con spazio + unità (es: "350 g")
        // Questo viene gestito come due parole separate, quindi aggiungiamo la versione unita
        if (preg_match('/^(\d+)$/', $word)) {
            // È solo un numero, potrebbe essere seguito da un'unità
            $variations[] = $word;
        }

        return array_unique($variations);
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

            // Controlla cache per query popolari (include offset/limit nella chiave)
            $filterHash = md5(json_encode($filters));
            $cacheKey = 'smartsearch_' . md5($query . '_' . $idLang . '_' . $idShop . '_' . $offset . '_' . $limit . '_' . $filterHash);
            $cachedResult = $this->getFromCache($cacheKey);

            if ($cachedResult !== false) {
                // Aggiungi flag cache hit per debug
                $cachedResult['_cached'] = true;
                die(json_encode($cachedResult, JSON_UNESCAPED_UNICODE));
            }

            // Ricerca prodotti con filtri
            $allProducts = $this->searchProducts($query, $idLang, $idShop, $filters);
            $totalCount = count($allProducts);

            // Applica paginazione
            $products = array_slice($allProducts, $offset, $limit);
            $hasMore = ($offset + $limit) < $totalCount;

            // Ricerca categorie (solo alla prima richiesta)
            $categories = ($offset === 0) ? $this->searchCategories($query, $idLang, $idShop) : [];

            // Ottieni banner attivi per questa query (solo alla prima richiesta)
            $banners = ($offset === 0) ? $this->getBannersForQuery($query, $idShop) : [];

            // Costruisci facets per i filtri (solo alla prima richiesta)
            $facets = ($offset === 0) ? $this->buildFacets($idLang, $idShop) : [];

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

        } catch (Exception $e) {
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
        $minPrice = (int)($result['min_price'] ?? 0);
        $maxPrice = (int)(($result['max_price'] ?? 1000) * (1 + $taxRate / 100));

        return [
            'min' => $minPrice,
            'max' => $maxPrice
        ];
    }

    /**
     * Recupera l'aliquota IVA di default del negozio
     */
    protected function getDefaultTaxRate()
    {
        // Prova a ottenere l'IVA dal paese di default del negozio
        $idCountry = (int)Configuration::get('PS_COUNTRY_DEFAULT');

        if ($idCountry) {
            // Cerca l'aliquota IVA più comune per questo paese
            $sql = '
                SELECT t.rate
                FROM ' . _DB_PREFIX_ . 'tax t
                INNER JOIN ' . _DB_PREFIX_ . 'tax_rule tr ON t.id_tax = tr.id_tax
                INNER JOIN ' . _DB_PREFIX_ . 'tax_rules_group trg ON tr.id_tax_rules_group = trg.id_tax_rules_group
                WHERE tr.id_country = ' . $idCountry . '
                AND trg.active = 1
                AND t.active = 1
                GROUP BY t.rate
                ORDER BY COUNT(*) DESC
                LIMIT 1';

            $rate = Db::getInstance()->getValue($sql);

            if ($rate !== false && $rate > 0) {
                return (float)$rate;
            }
        }

        // Fallback: cerca qualsiasi aliquota IVA attiva
        $sql = 'SELECT rate FROM ' . _DB_PREFIX_ . 'tax WHERE active = 1 ORDER BY rate DESC LIMIT 1';
        $rate = Db::getInstance()->getValue($sql);

        return $rate !== false ? (float)$rate : 22.0; // Default 22% se non trovata
    }

    /**
     * Costruisce i facets per i filtri della sidebar
     */
    protected function buildFacets($idLang, $idShop)
    {
        $facets = [];

        // Price range
        $facets['price_range'] = $this->getPriceRange($idShop);

        // Manufacturers (brands) - formato compatibile con JS
        $brands = $this->getAvailableBrands($idLang, $idShop);
        $facets['manufacturers'] = array_map(function($brand) {
            return [
                'id_manufacturer' => $brand['id'],
                'name' => $brand['name'],
                'count' => $brand['count']
            ];
        }, $brands);

        // Categories - formato compatibile con JS (usa id_category)
        $categories = $this->getAvailableCategories($idLang, $idShop);
        $facets['categories'] = array_map(function($cat) {
            return [
                'id_category' => $cat['id'],
                'name' => $cat['name'],
                'count' => $cat['count']
            ];
        }, $categories);

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

        } catch (Exception $e) {
            if (defined('_PS_MODE_DEV_') && _PS_MODE_DEV_) {
                PrestaShopLogger::addLog('SmartSearch filtered search error: ' . $e->getMessage(), 3, null, 'SmartSearch');
            }
            die(json_encode([
                'products' => [],
                'total' => 0
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
                'boost_score' => isset($row['_boost_score']) ? (float)$row['_boost_score'] : 1.0,
                'injected' => isset($row['_injected']) && $row['_injected'] ? true : false
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
            // Catch ALL errors including TypeError, etc.
            try {
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
                    case 'suggestions':
                        $this->displayAjaxSuggestions();
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

        // 2. Ricerca con scoring
        $results = $this->searchProductsWithScoring($query, $idLang, $idShop, $filters);

        // 2b. Applica filtri (prezzo con IVA, stock, etc.)
        if (!empty($filters)) {
            $results = $this->applyFiltersToResults($results, $filters, $idShop);
        }

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

        // 5. Ordina per score totale (relevance_score * boost_score)
        usort($results, function($a, $b) {
            $scoreA = ($a['_relevance_score'] ?? 0) * ($a['_boost_score'] ?? 1);
            $scoreB = ($b['_relevance_score'] ?? 0) * ($b['_boost_score'] ?? 1);
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
        // - Deve contenere almeno un numero OPPURE essere tutto maiuscolo OPPURE contenere trattini/underscore
        // - Non deve essere una parola comune (tutto lettere minuscole)
        $hasNumber = preg_match('/[0-9]/', $code);
        $hasSpecialChar = preg_match('/[\-_]/', $code);
        $isAllUppercase = $code === strtoupper($code) && preg_match('/[A-Z]/', $code);
        $isLikelyCode = $hasNumber || $hasSpecialChar || $isAllUppercase;

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
     * Ricerca prodotti con calcolo score di rilevanza
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

        // Costruisci condizioni OR (trova prodotti che matchano ALMENO una parola o variazione)
        $orConditions = [];
        $nameMatchCases = [];
        foreach ($expandedWords as $word) {
            $wordSafe = pSQL($word);
            $orConditions[] = "pl.name LIKE '%{$wordSafe}%'";
            $orConditions[] = "pl.description_short LIKE '%{$wordSafe}%'";
            $orConditions[] = "pl.description LIKE '%{$wordSafe}%'";
            $orConditions[] = "p.reference LIKE '%{$wordSafe}%'";
            $orConditions[] = "m.name LIKE '%{$wordSafe}%'";
            // Per ordinamento SQL: conta quante parole matchano nel nome
            $nameMatchCases[] = "(CASE WHEN pl.name LIKE '%{$wordSafe}%' THEN 1 ELSE 0 END)";
        }
        // Campo calcolato per ordinare per numero di parole matchate nel nome
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
            ORDER BY name_match_count DESC, pl.name ASC
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

        // === BONUS BESTSELLER ===
        $salesCount = (int)($product['sales_count'] ?? 0);
        if ($salesCount > 100) {
            $score += 20;
        } elseif ($salesCount > 50) {
            $score += 15;
        } elseif ($salesCount > 10) {
            $score += 10;
        } elseif ($salesCount > 0) {
            $score += 5;
        }

        // === BONUS PRODOTTO RECENTE ===
        if (!empty($product['date_add'])) {
            $daysOld = (time() - strtotime($product['date_add'])) / 86400;
            if ($daysOld <= 7) {
                $score += 15; // Novità ultima settimana
            } elseif ($daysOld <= 30) {
                $score += 10; // Novità ultimo mese
            }
        }

        return $score;
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
    protected function searchProductsFuzzy($query, $idLang, $idShop, $filters = [])
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
                die(json_encode(['success' => false, 'error' => 'Server configuration error']));
            }

            // Leggi il body JSON della richiesta
            $inputJSON = file_get_contents('php://input');
            if (empty($inputJSON)) {
                die(json_encode(['success' => false, 'error' => 'Invalid request']));
            }

            $data = json_decode($inputJSON, true);

            if (!$data || !is_array($data)) {
                die(json_encode(['success' => false, 'error' => 'Invalid request']));
            }

            // Sanitizza i dati (rimuovi potenziali script injection)
            $data = $this->sanitizeAnalyticsData($data);

            // Ottieni URL webhook dalla configurazione
            $webhookUrl = Configuration::get('SMARTSEARCH_ANALYTICS_WEBHOOK_URL');

            if (empty($webhookUrl) || !filter_var($webhookUrl, FILTER_VALIDATE_URL)) {
                // Silently fail - analytics shouldn't block user experience
                die(json_encode(['success' => true]));
            }

            // Inoltra i dati a n8n con timeout basso
            $ch = curl_init($webhookUrl);

            if ($ch === false) {
                die(json_encode(['success' => false, 'error' => 'Server error']));
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
                if (defined('_PS_MODE_DEV_') && _PS_MODE_DEV_) {
                    PrestaShopLogger::addLog('SmartSearch webhook error: ' . $error, 2, null, 'SmartSearch');
                }
                die(json_encode([
                    'success' => false,
                    'error' => 'Connection error'
                ]));
            }

            // Considera successo se HTTP 2xx
            $success = $httpCode >= 200 && $httpCode < 300;

            die(json_encode([
                'success' => $success
            ]));

        } catch (Exception $e) {
            if (defined('_PS_MODE_DEV_') && _PS_MODE_DEV_) {
                PrestaShopLogger::addLog('SmartSearch analytics error: ' . $e->getMessage(), 3, null, 'SmartSearch');
            }
            die(json_encode([
                'success' => false,
                'error' => 'Server error'
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
            AND search_query LIKE \'' . pSQL($query) . '%\'
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
                AND search_query LIKE \'%' . pSQL($query) . '%\'
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
                AND pl.name LIKE \'' . pSQL($query) . '%\'
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
                WHERE m.name LIKE \'' . pSQL($query) . '%\'
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
    // CACHE SYSTEM
    // =========================================================================

    /**
     * Recupera risultati dalla cache
     * Cache valida per 5 minuti (300 secondi)
     */
    protected function getFromCache($key)
    {
        // Usa la cache di PrestaShop se disponibile
        if (class_exists('Cache') && method_exists('Cache', 'getInstance')) {
            $cache = Cache::getInstance();
            if ($cache->exists($key)) {
                $data = $cache->get($key);
                if ($data !== false) {
                    return json_decode($data, true);
                }
            }
        }

        // Fallback: cache su database (cache valida per 5 minuti)
        $sql = 'SELECT result_data FROM `' . _DB_PREFIX_ . 'smartsearch_cache`
                WHERE cache_key = \'' . pSQL($key) . '\'
                AND created_at > DATE_SUB(NOW(), INTERVAL 5 MINUTE)
                LIMIT 1';

        try {
            $result = Db::getInstance()->getRow($sql);
            if ($result && !empty($result['result_data'])) {
                return json_decode($result['result_data'], true);
            }
        } catch (Exception $e) {
            // Table might not exist
        }

        return false;
    }

    /**
     * Salva risultati in cache
     */
    protected function saveToCache($key, $data, $ttl = 300)
    {
        $jsonData = json_encode($data, JSON_UNESCAPED_UNICODE);

        // Usa la cache di PrestaShop se disponibile
        if (class_exists('Cache') && method_exists('Cache', 'getInstance')) {
            $cache = Cache::getInstance();
            $cache->set($key, $jsonData, $ttl);
        }

        // Salva anche su database
        $idLang = (int)$this->context->language->id;
        $idShop = (int)$this->context->shop->id;
        $query = Tools::getValue('q', '');

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
        } catch (Exception $e) {
            // Cache table might not exist, ignore
        }
    }

    /**
     * Pulisce la cache scaduta (più di 10 minuti)
     */
    public static function cleanExpiredCache()
    {
        $sql = 'DELETE FROM `' . _DB_PREFIX_ . 'smartsearch_cache`
                WHERE created_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)';
        try {
            Db::getInstance()->execute($sql);
        } catch (Exception $e) {
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
        } catch (Exception $e) {
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
            $likeConditions[] = "pl.name LIKE '%" . pSQL($word) . "%'";
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
            AND m.name LIKE \'%' . pSQL($query) . '%\'
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
        } catch (Exception $e) {
            // Ignore - table might not exist
        }
    }
}
