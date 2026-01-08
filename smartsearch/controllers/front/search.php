<?php
/**
 * SmartSearch 2.0 - Controller AJAX per la ricerca dinamica intelligente
 * Utilizza SmartSearchEngine con fuzzy search, sinonimi, filtri e cache
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'smartsearch/classes/SmartSearchEngine.php';
require_once _PS_MODULE_DIR_ . 'smartsearch/classes/SmartSearchCache.php';
require_once _PS_MODULE_DIR_ . 'smartsearch/classes/SmartSearchAnalytics.php';

class SmartsearchSearchModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $ajax = true;

    /** @var bool */
    public $ssl = true;

    /**
     * Gestisce la richiesta di ricerca
     */
    public function initContent()
    {
        parent::initContent();

        header('Content-Type: application/json; charset=utf-8');

        // Verifica che sia una richiesta AJAX
        if (!$this->ajax) {
            $this->sendResponse(['error' => 'Invalid request'], 400);
        }

        $query = Tools::getValue('q', '');
        $query = trim(strip_tags($query));

        // Validazione query
        $minChars = (int)Configuration::get('SMARTSEARCH_MIN_CHARS');
        if (mb_strlen($query) < $minChars) {
            $this->sendResponse([
                'products' => [],
                'categories' => [],
                'suggestions' => [],
                'facets' => [],
                'banners' => [],
                'did_you_mean' => [],
                'total' => 0,
                'query' => $query
            ]);
        }

        $idLang = (int)$this->context->language->id;
        $idShop = (int)$this->context->shop->id;
        $maxResults = (int)Configuration::get('SMARTSEARCH_MAX_RESULTS');

        // Ottieni filtri dalla richiesta
        $filters = $this->getFiltersFromRequest();

        // Inizializza componenti
        $cache = new SmartSearchCache($idLang, $idShop);
        $engine = new SmartSearchEngine($idLang, $idShop);
        $analytics = new SmartSearchAnalytics($idLang, $idShop);

        // Prova a ottenere dalla cache
        $cacheEnabled = (bool)Configuration::get('SMARTSEARCH_CACHE_ENABLED');
        $cachedResults = $cacheEnabled ? $cache->get($query, $filters) : null;

        if ($cachedResults) {
            // Traccia ricerca (anche se da cache)
            if (Configuration::get('SMARTSEARCH_ANALYTICS_ENABLED')) {
                $analytics->trackSearch($query, $cachedResults['total'], $filters, $this->getCustomerId());
            }

            // Salva query nell'ultima ricerca per tracciare conversioni
            $this->context->cookie->smartsearch_last_query = $query;

            $this->sendResponse($cachedResults);
        }

        // Esegui ricerca con motore intelligente
        $searchResults = $engine->search($query, $maxResults, $filters);

        // Formatta prodotti per il frontend
        $products = $this->formatProducts($searchResults['products'], $idLang);

        // Cerca categorie
        $categories = $this->searchCategories($query, $idLang, $idShop);

        // Ottieni suggerimenti
        $suggestions = $this->getSuggestions($query, $idLang, $idShop);

        // Ottieni "Forse cercavi"
        $didYouMean = [];
        if (count($products) < 3) {
            $didYouMean = $engine->getDidYouMean($query);
        }

        // Ottieni banner
        $banners = $this->getBanners($query, $idLang, $idShop);

        // Prepara risposta
        $response = [
            'products' => $products,
            'categories' => $categories,
            'suggestions' => $suggestions,
            'facets' => $searchResults['facets'] ?? [],
            'banners' => $banners,
            'did_you_mean' => $didYouMean,
            'total' => count($products),
            'query' => $query,
            'expanded_terms' => $searchResults['expanded_terms'] ?? []
        ];

        // Salva in cache
        if ($cacheEnabled) {
            $cache->set($query, $filters, $response);
        }

        // Traccia ricerca
        if (Configuration::get('SMARTSEARCH_ANALYTICS_ENABLED')) {
            $analytics->trackSearch($query, count($products), $filters, $this->getCustomerId());
        }

        // Salva query per tracciare conversioni
        $this->context->cookie->smartsearch_last_query = $query;

        $this->sendResponse($response);
    }

    /**
     * Ottieni filtri dalla richiesta
     */
    protected function getFiltersFromRequest()
    {
        $filters = [];

        // Filtro categoria
        $categoryIds = Tools::getValue('category', '');
        if (!empty($categoryIds)) {
            $filters['category'] = array_map('intval', explode(',', $categoryIds));
        }

        // Filtro prezzo
        $priceMin = Tools::getValue('price_min', '');
        $priceMax = Tools::getValue('price_max', '');
        if ($priceMin !== '') {
            $filters['price_min'] = (float)$priceMin;
        }
        if ($priceMax !== '') {
            $filters['price_max'] = (float)$priceMax;
        }

        // Filtro produttore/brand
        $manufacturerIds = Tools::getValue('manufacturer', '');
        if (!empty($manufacturerIds)) {
            $filters['manufacturer'] = array_map('intval', explode(',', $manufacturerIds));
        }

        // Filtro disponibilità
        $inStock = Tools::getValue('in_stock', '');
        if ($inStock !== '') {
            $filters['in_stock'] = (bool)$inStock;
        }

        // Filtri attributi
        $attributes = Tools::getValue('attributes', '');
        if (!empty($attributes)) {
            $attrArray = json_decode($attributes, true);
            if (is_array($attrArray)) {
                $filters['attributes'] = $attrArray;
            }
        }

        return $filters;
    }

    /**
     * Formatta prodotti per il frontend
     */
    protected function formatProducts($products, $idLang)
    {
        if (empty($products)) {
            return [];
        }

        $showPrice = (bool)Configuration::get('SMARTSEARCH_SHOW_PRICE');
        $showImage = (bool)Configuration::get('SMARTSEARCH_SHOW_IMAGE');
        $showDescription = (bool)Configuration::get('SMARTSEARCH_SHOW_DESCRIPTION');
        $showCategory = (bool)Configuration::get('SMARTSEARCH_SHOW_CATEGORY');
        $showManufacturer = (bool)Configuration::get('SMARTSEARCH_SHOW_MANUFACTURER');
        $showStock = (bool)Configuration::get('SMARTSEARCH_SHOW_STOCK');

        $formattedProducts = [];

        foreach ($products as $row) {
            // URL prodotto
            $productUrl = $this->context->link->getProductLink(
                $row['id_product'],
                $row['link_rewrite'] ?? null,
                null,
                null,
                $idLang
            );

            // Immagine
            $imageUrl = '';
            if ($showImage && !empty($row['id_image'])) {
                $imageUrl = $this->context->link->getImageLink(
                    $row['link_rewrite'] ?? '',
                    $row['id_image'],
                    ImageType::getFormattedName('small')
                );
            }

            // Prezzo
            $price = '';
            $priceOld = '';
            $priceRaw = 0;
            $priceOldRaw = 0;
            if ($showPrice) {
                $priceDisplay = Product::getPriceStatic($row['id_product'], true);
                $priceOldDisplay = Product::getPriceStatic($row['id_product'], true, null, 6, null, false, false);

                $priceRaw = $priceDisplay;
                $price = Tools::displayPrice($priceDisplay);
                if ($priceOldDisplay > $priceDisplay) {
                    $priceOld = Tools::displayPrice($priceOldDisplay);
                    $priceOldRaw = $priceOldDisplay;
                }
            }

            // Descrizione breve (troncata)
            $description = '';
            if ($showDescription && !empty($row['description_short'])) {
                $description = strip_tags($row['description_short']);
                if (mb_strlen($description) > 100) {
                    $description = mb_substr($description, 0, 97) . '...';
                }
            }

            // Stock
            $inStock = true;
            $stockQty = 0;
            if ($showStock) {
                $stockQty = StockAvailable::getQuantityAvailableByProduct($row['id_product']);
                $inStock = $stockQty > 0;
            }

            $formattedProducts[] = [
                'id' => (int)$row['id_product'],
                'name' => $row['name'],
                'url' => $productUrl,
                'image' => $imageUrl,
                'price' => $price,
                'price_raw' => $priceRaw,
                'price_old' => $priceOld,
                'price_old_raw' => $priceOldRaw,
                'description' => $description,
                'category' => $showCategory ? ($row['category_name'] ?? '') : '',
                'category_id' => (int)($row['id_category_default'] ?? 0),
                'manufacturer' => $showManufacturer ? ($row['manufacturer_name'] ?? '') : '',
                'manufacturer_id' => (int)($row['id_manufacturer'] ?? 0),
                'reference' => $row['reference'] ?? '',
                'in_stock' => $inStock,
                'stock_qty' => $stockQty,
                'score' => $row['_score'] ?? 0,
                'boosted' => !empty($row['_boosted'])
            ];
        }

        return $formattedProducts;
    }

    /**
     * Ricerca categorie
     */
    protected function searchCategories($query, $idLang, $idShop)
    {
        $sql = '
            SELECT c.id_category, cl.name, cl.link_rewrite
            FROM `' . _DB_PREFIX_ . 'category` c
            INNER JOIN `' . _DB_PREFIX_ . 'category_lang` cl
                ON c.id_category = cl.id_category
                AND cl.id_lang = ' . (int)$idLang . '
                AND cl.id_shop = ' . (int)$idShop . '
            INNER JOIN `' . _DB_PREFIX_ . 'category_shop` cs
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
     * Ottieni suggerimenti di ricerca
     */
    protected function getSuggestions($query, $idLang, $idShop)
    {
        $sql = '
            SELECT DISTINCT search_query, COUNT(*) as frequency
            FROM `' . _DB_PREFIX_ . 'smartsearch_stats`
            WHERE search_query LIKE \'%' . pSQL($query) . '%\'
            AND search_query != \'' . pSQL($query) . '\'
            AND results_count > 0
            AND id_lang = ' . (int)$idLang . '
            AND id_shop = ' . (int)$idShop . '
            GROUP BY search_query
            ORDER BY frequency DESC
            LIMIT 5';

        $results = Db::getInstance()->executeS($sql);

        if (!$results) {
            return [];
        }

        return array_column($results, 'search_query');
    }

    /**
     * Ottieni banner promozionali per la query
     */
    protected function getBanners($query, $idLang, $idShop)
    {
        if (!Configuration::get('SMARTSEARCH_BANNERS_ENABLED')) {
            return [];
        }

        $sql = '
            SELECT id_smartsearch_banner, name, image, link, position
            FROM `' . _DB_PREFIX_ . 'smartsearch_banners`
            WHERE active = 1
            AND id_shop = ' . (int)$idShop . '
            AND id_lang = ' . (int)$idLang . '
            AND (date_start IS NULL OR date_start <= NOW())
            AND (date_end IS NULL OR date_end >= NOW())
            AND (
                keywords IS NULL
                OR keywords = \'\'
                OR keywords LIKE \'%' . pSQL($query) . '%\'
            )
            ORDER BY position ASC
            LIMIT 3';

        $results = Db::getInstance()->executeS($sql);

        if (!$results) {
            return [];
        }

        $banners = [];
        foreach ($results as $row) {
            $banners[] = [
                'id' => (int)$row['id_smartsearch_banner'],
                'name' => $row['name'],
                'image' => _MODULE_DIR_ . 'smartsearch/views/img/banners/' . $row['image'],
                'link' => $row['link'],
                'position' => $row['position']
            ];
        }

        return $banners;
    }

    /**
     * Ottieni ID cliente corrente
     */
    protected function getCustomerId()
    {
        if ($this->context->customer && $this->context->customer->isLogged()) {
            return (int)$this->context->customer->id;
        }
        return null;
    }

    /**
     * Invia risposta JSON
     */
    protected function sendResponse($data, $statusCode = 200)
    {
        http_response_code($statusCode);
        die(json_encode($data, JSON_UNESCAPED_UNICODE));
    }
}
