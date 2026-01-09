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

            // Costruisci facets per i filtri
            $facets = $this->buildFacets($idLang, $idShop);

            die(json_encode([
                'products' => $products,
                'categories' => $categories,
                'total' => count($products),
                'query' => $query,
                'facets' => $facets,
                'banners' => $banners,
                'did_you_mean' => []
            ], JSON_UNESCAPED_UNICODE));

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
    protected function searchProducts($query, $idLang, $idShop)
    {
        $query = trim($query);
        if (empty($query)) {
            return [];
        }

        // 1. Prima controlla match esatto EAN/SKU (priorità massima)
        $exactMatch = $this->searchByExactCode($query, $idLang, $idShop);
        if (!empty($exactMatch)) {
            return $this->formatProducts($exactMatch, $idLang);
        }

        // 2. Ricerca con scoring
        $results = $this->searchProductsWithScoring($query, $idLang, $idShop);

        // 3. Se pochi risultati, aggiungi fuzzy search
        if (count($results) < 5) {
            $fuzzyResults = $this->searchProductsFuzzy($query, $idLang, $idShop);
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

        // 6. Limita risultati
        $results = array_slice($results, 0, 20);

        return $this->formatProducts($results, $idLang);
    }

    /**
     * Cerca per codice esatto (EAN, SKU, Reference)
     */
    protected function searchByExactCode($query, $idLang, $idShop)
    {
        // Rimuovi spazi e normalizza
        $code = preg_replace('/\s+/', '', $query);

        // Solo se sembra un codice (alfanumerico senza spazi)
        if (strlen($code) < 4 || !preg_match('/^[a-zA-Z0-9\-_]+$/', $code)) {
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
                COALESCE(ps_sales.quantity, 0) as sales_count,
                150 as _relevance_score
            FROM ' . _DB_PREFIX_ . 'product p
            INNER JOIN ' . _DB_PREFIX_ . 'product_lang pl ON p.id_product = pl.id_product
                AND pl.id_lang = ' . (int)$idLang . ' AND pl.id_shop = ' . (int)$idShop . '
            INNER JOIN ' . _DB_PREFIX_ . 'product_shop ps ON p.id_product = ps.id_product
                AND ps.id_shop = ' . (int)$idShop . '
            LEFT JOIN ' . _DB_PREFIX_ . 'manufacturer m ON p.id_manufacturer = m.id_manufacturer
            LEFT JOIN ' . _DB_PREFIX_ . 'category_lang cl ON p.id_category_default = cl.id_category
                AND cl.id_lang = ' . (int)$idLang . '
            LEFT JOIN ' . _DB_PREFIX_ . 'product_sale ps_sales ON p.id_product = ps_sales.id_product
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
    protected function searchProductsWithScoring($query, $idLang, $idShop)
    {
        $queryLower = mb_strtolower(trim($query));
        $words = array_filter(explode(' ', $queryLower), function($w) {
            return mb_strlen($w) >= 2;
        });

        if (empty($words)) {
            return [];
        }

        // Costruisci condizioni OR (trova prodotti che matchano ALMENO una parola)
        $orConditions = [];
        foreach ($words as $word) {
            $wordSafe = pSQL($word);
            $orConditions[] = "pl.name LIKE '%{$wordSafe}%'";
            $orConditions[] = "pl.description_short LIKE '%{$wordSafe}%'";
            $orConditions[] = "p.reference LIKE '%{$wordSafe}%'";
            $orConditions[] = "m.name LIKE '%{$wordSafe}%'";
        }

        $sql = '
            SELECT DISTINCT
                p.id_product,
                pl.name,
                pl.link_rewrite,
                pl.description_short,
                p.reference,
                p.ean13,
                p.id_category_default,
                p.id_manufacturer,
                p.date_add,
                m.name as manufacturer_name,
                cl.name as category_name,
                (SELECT id_image FROM ' . _DB_PREFIX_ . 'image i WHERE i.id_product = p.id_product AND i.cover = 1 LIMIT 1) as id_image,
                COALESCE(ps_sales.quantity, 0) as sales_count,
                COALESCE(
                    (SELECT AVG(grade) FROM ' . _DB_PREFIX_ . 'product_comment pc
                     WHERE pc.id_product = p.id_product AND pc.validate = 1), 0
                ) as avg_rating
            FROM ' . _DB_PREFIX_ . 'product p
            INNER JOIN ' . _DB_PREFIX_ . 'product_lang pl ON p.id_product = pl.id_product
                AND pl.id_lang = ' . (int)$idLang . ' AND pl.id_shop = ' . (int)$idShop . '
            INNER JOIN ' . _DB_PREFIX_ . 'product_shop ps ON p.id_product = ps.id_product
                AND ps.id_shop = ' . (int)$idShop . '
            LEFT JOIN ' . _DB_PREFIX_ . 'manufacturer m ON p.id_manufacturer = m.id_manufacturer
            LEFT JOIN ' . _DB_PREFIX_ . 'category_lang cl ON p.id_category_default = cl.id_category
                AND cl.id_lang = ' . (int)$idLang . '
            LEFT JOIN ' . _DB_PREFIX_ . 'product_sale ps_sales ON p.id_product = ps_sales.id_product
            WHERE p.active = 1 AND ps.active = 1
            AND (' . implode(' OR ', $orConditions) . ')
            LIMIT 100';

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
     * Calcola lo score di rilevanza per un prodotto
     */
    protected function calculateRelevanceScore($product, $query, $words)
    {
        $score = 0;
        $nameLower = mb_strtolower($product['name'] ?? '');
        $descLower = mb_strtolower($product['description_short'] ?? '');
        $refLower = mb_strtolower($product['reference'] ?? '');
        $brandLower = mb_strtolower($product['manufacturer_name'] ?? '');

        // === MATCH NEL NOME (peso più alto) ===

        // Match esatto della query completa nel nome
        if (strpos($nameLower, $query) !== false) {
            $score += 100;

            // Bonus se il nome INIZIA con la query
            if (strpos($nameLower, $query) === 0) {
                $score += 50;
            }
        }

        // Match per singole parole nel nome
        $nameWordMatches = 0;
        foreach ($words as $word) {
            if (strpos($nameLower, $word) !== false) {
                $score += 30;
                $nameWordMatches++;

                // Bonus se la parola è all'inizio del nome
                if (strpos($nameLower, $word) === 0) {
                    $score += 15;
                }
            }
        }

        // Bonus per match di TUTTE le parole nel nome
        if ($nameWordMatches === count($words) && count($words) > 1) {
            $score += 40;
        }

        // === MATCH NEL REFERENCE/SKU ===
        if (!empty($refLower)) {
            if ($refLower === $query) {
                $score += 120; // Match esatto reference
            } elseif (strpos($refLower, $query) !== false) {
                $score += 80;
            } else {
                foreach ($words as $word) {
                    if (strpos($refLower, $word) !== false) {
                        $score += 40;
                    }
                }
            }
        }

        // === MATCH NELLA MARCA ===
        if (!empty($brandLower)) {
            if (strpos($brandLower, $query) !== false) {
                $score += 35;
            } else {
                foreach ($words as $word) {
                    if (strpos($brandLower, $word) !== false) {
                        $score += 25;
                    }
                }
            }
        }

        // === MATCH NELLA DESCRIZIONE (peso più basso) ===
        foreach ($words as $word) {
            if (strpos($descLower, $word) !== false) {
                $score += 10;
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

        // === BONUS RECENSIONI ===
        $avgRating = (float)($product['avg_rating'] ?? 0);
        if ($avgRating >= 4.5) {
            $score += 15;
        } elseif ($avgRating >= 4.0) {
            $score += 10;
        } elseif ($avgRating >= 3.5) {
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
