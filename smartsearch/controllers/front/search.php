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

            die(json_encode([
                'products' => $products,
                'categories' => $categories,
                'total' => count($products),
                'query' => $query,
                'facets' => [],
                'banners' => [],
                'did_you_mean' => []
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
                'total_sold' => isset($row['total_sold']) ? (int)$row['total_sold'] : 0
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
     * Compatibilità: se chiamato senza action=search
     */
    public function initContent()
    {
        if (Tools::getValue('ajax') || Tools::isSubmit('ajax')) {
            $this->displayAjaxSearch();
        }
        parent::initContent();
    }

    /**
     * Ricerca prodotti con supporto fuzzy/tollerante
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

        return $this->formatProducts($results, $idLang);
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
                $wordConditions[] = "m.name LIKE '{$fuzzyPattern}'";

                // 3. Ricerca senza la prima/ultima lettera (per errori comuni)
                if (mb_strlen($word) > 3) {
                    $withoutFirst = mb_substr($word, 1);
                    $withoutLast = mb_substr($word, 0, -1);
                    $wordConditions[] = "pl.name LIKE '%{$withoutFirst}%'";
                    $wordConditions[] = "pl.name LIKE '%{$withoutLast}%'";
                    $wordConditions[] = "m.name LIKE '%{$withoutFirst}%'";
                    $wordConditions[] = "m.name LIKE '%{$withoutLast}%'";
                }

                // 4. Ricerca con consonanti (ignora vocali)
                $consonants = $this->extractConsonants($word);
                if (mb_strlen($consonants) >= 3) {
                    $consonantPattern = '%' . implode('%', str_split($consonants)) . '%';
                    $wordConditions[] = "pl.name LIKE '{$consonantPattern}'";
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
     */
    public function displayAjaxAnalytics()
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');

        try {
            // Leggi il body JSON della richiesta
            $inputJSON = file_get_contents('php://input');
            $data = json_decode($inputJSON, true);

            if (!$data) {
                die(json_encode(['success' => false, 'error' => 'Invalid JSON']));
            }

            // Ottieni URL webhook dalla configurazione
            $webhookUrl = Configuration::get('SMARTSEARCH_ANALYTICS_WEBHOOK_URL');

            if (empty($webhookUrl)) {
                die(json_encode(['success' => false, 'error' => 'Webhook URL not configured']));
            }

            // Inoltra i dati a n8n
            $ch = curl_init($webhookUrl);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($data),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: application/json'
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 5, // timeout breve per non rallentare il frontend
                CURLOPT_CONNECTTIMEOUT => 3
            ]);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($error) {
                die(json_encode(['success' => false, 'error' => $error]));
            }

            die(json_encode([
                'success' => true,
                'http_code' => $httpCode
            ]));

        } catch (Exception $e) {
            die(json_encode([
                'success' => false,
                'error' => $e->getMessage()
            ]));
        }
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
}
