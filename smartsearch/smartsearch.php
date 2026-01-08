<?php
/**
 * SmartSearch 2.0 - Modulo di ricerca dinamica intelligente per PrestaShop
 * Simile a Doofinder con AI, fuzzy search, sinonimi, filtri e analytics
 *
 * @author Smart Search Team
 * @copyright 2024
 * @license MIT
 * @version 2.0.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/classes/SmartSearchEngine.php';
require_once dirname(__FILE__) . '/classes/SmartSearchCache.php';
require_once dirname(__FILE__) . '/classes/SmartSearchAnalytics.php';

class SmartSearch extends Module
{
    /** @var array */
    protected $tabs = [];

    public function __construct()
    {
        $this->name = 'smartsearch';
        $this->tab = 'search_filter';
        $this->version = '2.0.0';
        $this->author = 'Smart Search Team';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = [
            'min' => '1.7.0.0',
            'max' => _PS_VERSION_
        ];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Smart Search 2.0');
        $this->description = $this->l('Ricerca dinamica intelligente con AI, fuzzy search, sinonimi, filtri avanzati e analytics - simile a Doofinder');
        $this->confirmUninstall = $this->l('Sei sicuro di voler disinstallare questo modulo? Tutti i dati delle ricerche verranno persi.');

        // Definizione tabs admin
        $this->tabs = [
            [
                'class_name' => 'AdminSmartSearchDashboard',
                'visible' => true,
                'name' => 'Smart Search',
                'parent_class_name' => 'AdminCatalog',
            ],
            [
                'class_name' => 'AdminSmartSearchSynonyms',
                'visible' => true,
                'name' => 'Sinonimi',
                'parent_class_name' => 'AdminSmartSearchDashboard',
            ],
            [
                'class_name' => 'AdminSmartSearchBoost',
                'visible' => true,
                'name' => 'Boost Prodotti',
                'parent_class_name' => 'AdminSmartSearchDashboard',
            ],
            [
                'class_name' => 'AdminSmartSearchBanners',
                'visible' => true,
                'name' => 'Banner Promozionali',
                'parent_class_name' => 'AdminSmartSearchDashboard',
            ],
            [
                'class_name' => 'AdminSmartSearchAnalytics',
                'visible' => true,
                'name' => 'Analytics',
                'parent_class_name' => 'AdminSmartSearchDashboard',
            ],
        ];
    }

    /**
     * Installazione del modulo
     */
    public function install()
    {
        // Configurazioni di default
        $this->installConfiguration();

        return parent::install()
            && $this->registerHook('displayHeader')
            && $this->registerHook('displayTop')
            && $this->registerHook('displaySearch')
            && $this->registerHook('actionFrontControllerSetMedia')
            && $this->registerHook('actionProductAdd')
            && $this->registerHook('actionProductUpdate')
            && $this->registerHook('actionProductDelete')
            && $this->registerHook('actionOrderStatusPostUpdate')
            && $this->installDb()
            && $this->installTabs();
    }

    /**
     * Disinstallazione del modulo
     */
    public function uninstall()
    {
        return $this->uninstallConfiguration()
            && $this->uninstallDb()
            && $this->uninstallTabs()
            && parent::uninstall();
    }

    /**
     * Installa configurazioni di default
     */
    protected function installConfiguration()
    {
        // Configurazioni generali
        Configuration::updateValue('SMARTSEARCH_ENABLED', 1);
        Configuration::updateValue('SMARTSEARCH_MIN_CHARS', 2);
        Configuration::updateValue('SMARTSEARCH_MAX_RESULTS', 8);
        Configuration::updateValue('SMARTSEARCH_DEBOUNCE_TIME', 300);

        // Visualizzazione
        Configuration::updateValue('SMARTSEARCH_SHOW_PRICE', 1);
        Configuration::updateValue('SMARTSEARCH_SHOW_IMAGE', 1);
        Configuration::updateValue('SMARTSEARCH_SHOW_DESCRIPTION', 1);
        Configuration::updateValue('SMARTSEARCH_SHOW_CATEGORY', 1);
        Configuration::updateValue('SMARTSEARCH_SHOW_MANUFACTURER', 1);
        Configuration::updateValue('SMARTSEARCH_SHOW_STOCK', 1);
        Configuration::updateValue('SMARTSEARCH_HIGHLIGHT', 1);

        // Algoritmi intelligenti
        Configuration::updateValue('SMARTSEARCH_FUZZY_ENABLED', 1);
        Configuration::updateValue('SMARTSEARCH_FUZZY_THRESHOLD', 2);
        Configuration::updateValue('SMARTSEARCH_PHONETIC_ENABLED', 1);
        Configuration::updateValue('SMARTSEARCH_STEMMING_ENABLED', 1);
        Configuration::updateValue('SMARTSEARCH_SYNONYMS_ENABLED', 1);

        // Filtri/Facets
        Configuration::updateValue('SMARTSEARCH_FACETS_ENABLED', 1);
        Configuration::updateValue('SMARTSEARCH_FACETS_CATEGORIES', 1);
        Configuration::updateValue('SMARTSEARCH_FACETS_PRICE', 1);
        Configuration::updateValue('SMARTSEARCH_FACETS_MANUFACTURER', 1);
        Configuration::updateValue('SMARTSEARCH_FACETS_ATTRIBUTES', 1);
        Configuration::updateValue('SMARTSEARCH_FACETS_STOCK', 1);

        // Cache
        Configuration::updateValue('SMARTSEARCH_CACHE_ENABLED', 1);
        Configuration::updateValue('SMARTSEARCH_CACHE_TTL', 3600);

        // Analytics
        Configuration::updateValue('SMARTSEARCH_ANALYTICS_ENABLED', 1);
        Configuration::updateValue('SMARTSEARCH_ANALYTICS_RETENTION', 90);
        Configuration::updateValue('SMARTSEARCH_ANALYTICS_WEBHOOK_URL', '');

        // Voice Search
        Configuration::updateValue('SMARTSEARCH_VOICE_ENABLED', 1);

        // Banner
        Configuration::updateValue('SMARTSEARCH_BANNERS_ENABLED', 1);

        return true;
    }

    /**
     * Rimuovi configurazioni
     */
    protected function uninstallConfiguration()
    {
        $configs = [
            'SMARTSEARCH_ENABLED', 'SMARTSEARCH_MIN_CHARS', 'SMARTSEARCH_MAX_RESULTS',
            'SMARTSEARCH_DEBOUNCE_TIME', 'SMARTSEARCH_SHOW_PRICE', 'SMARTSEARCH_SHOW_IMAGE',
            'SMARTSEARCH_SHOW_DESCRIPTION', 'SMARTSEARCH_SHOW_CATEGORY', 'SMARTSEARCH_SHOW_MANUFACTURER',
            'SMARTSEARCH_SHOW_STOCK', 'SMARTSEARCH_HIGHLIGHT', 'SMARTSEARCH_FUZZY_ENABLED',
            'SMARTSEARCH_FUZZY_THRESHOLD', 'SMARTSEARCH_PHONETIC_ENABLED', 'SMARTSEARCH_STEMMING_ENABLED',
            'SMARTSEARCH_SYNONYMS_ENABLED', 'SMARTSEARCH_FACETS_ENABLED', 'SMARTSEARCH_FACETS_CATEGORIES',
            'SMARTSEARCH_FACETS_PRICE', 'SMARTSEARCH_FACETS_MANUFACTURER', 'SMARTSEARCH_FACETS_ATTRIBUTES',
            'SMARTSEARCH_FACETS_STOCK', 'SMARTSEARCH_CACHE_ENABLED', 'SMARTSEARCH_CACHE_TTL',
            'SMARTSEARCH_ANALYTICS_ENABLED', 'SMARTSEARCH_ANALYTICS_RETENTION',
            'SMARTSEARCH_ANALYTICS_WEBHOOK_URL', 'SMARTSEARCH_VOICE_ENABLED', 'SMARTSEARCH_BANNERS_ENABLED'
        ];

        foreach ($configs as $config) {
            Configuration::deleteByName($config);
        }

        return true;
    }

    /**
     * Installazione tabelle database
     */
    protected function installDb()
    {
        $sql = [];

        // Tabella statistiche ricerche
        $sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'smartsearch_stats` (
            `id_smartsearch_stat` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `search_query` VARCHAR(255) NOT NULL,
            `results_count` INT(11) NOT NULL DEFAULT 0,
            `filters_used` TEXT,
            `id_customer` INT(11) UNSIGNED DEFAULT NULL,
            `id_lang` INT(11) UNSIGNED NOT NULL,
            `id_shop` INT(11) UNSIGNED NOT NULL,
            `session_id` VARCHAR(64),
            `date_add` DATETIME NOT NULL,
            PRIMARY KEY (`id_smartsearch_stat`),
            INDEX `search_query` (`search_query`),
            INDEX `date_add` (`date_add`),
            INDEX `id_shop` (`id_shop`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';

        // Tabella click sui prodotti
        $sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'smartsearch_clicks` (
            `id_smartsearch_click` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `search_query` VARCHAR(255) NOT NULL,
            `id_product` INT(11) UNSIGNED NOT NULL,
            `position` INT(11) NOT NULL DEFAULT 0,
            `id_customer` INT(11) UNSIGNED DEFAULT NULL,
            `id_lang` INT(11) UNSIGNED NOT NULL,
            `id_shop` INT(11) UNSIGNED NOT NULL,
            `session_id` VARCHAR(64),
            `date_add` DATETIME NOT NULL,
            PRIMARY KEY (`id_smartsearch_click`),
            INDEX `search_query` (`search_query`),
            INDEX `id_product` (`id_product`),
            INDEX `date_add` (`date_add`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';

        // Tabella conversioni
        $sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'smartsearch_conversions` (
            `id_smartsearch_conversion` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `search_query` VARCHAR(255) NOT NULL,
            `id_product` INT(11) UNSIGNED NOT NULL,
            `id_order` INT(11) UNSIGNED NOT NULL,
            `amount` DECIMAL(20,6) NOT NULL DEFAULT 0,
            `id_lang` INT(11) UNSIGNED NOT NULL,
            `id_shop` INT(11) UNSIGNED NOT NULL,
            `date_add` DATETIME NOT NULL,
            PRIMARY KEY (`id_smartsearch_conversion`),
            INDEX `search_query` (`search_query`),
            INDEX `id_order` (`id_order`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';

        // Tabella sinonimi
        $sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'smartsearch_synonyms` (
            `id_smartsearch_synonym` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `word` VARCHAR(100) NOT NULL,
            `synonyms` TEXT NOT NULL,
            `id_shop` INT(11) UNSIGNED NOT NULL,
            `active` TINYINT(1) NOT NULL DEFAULT 1,
            `date_add` DATETIME NOT NULL,
            `date_upd` DATETIME NOT NULL,
            PRIMARY KEY (`id_smartsearch_synonym`),
            UNIQUE KEY `word_shop` (`word`, `id_shop`),
            INDEX `id_shop` (`id_shop`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';

        // Tabella boost prodotti
        $sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'smartsearch_boost` (
            `id_smartsearch_boost` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_product` INT(11) UNSIGNED NOT NULL,
            `boost_value` DECIMAL(5,2) NOT NULL DEFAULT 1.00,
            `keywords` TEXT,
            `id_shop` INT(11) UNSIGNED NOT NULL,
            `active` TINYINT(1) NOT NULL DEFAULT 1,
            `date_start` DATETIME DEFAULT NULL,
            `date_end` DATETIME DEFAULT NULL,
            `date_add` DATETIME NOT NULL,
            `date_upd` DATETIME NOT NULL,
            PRIMARY KEY (`id_smartsearch_boost`),
            UNIQUE KEY `product_shop` (`id_product`, `id_shop`),
            INDEX `id_shop` (`id_shop`),
            INDEX `active` (`active`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';

        // Tabella banner promozionali
        $sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'smartsearch_banners` (
            `id_smartsearch_banner` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(128) NOT NULL,
            `image` VARCHAR(255) NOT NULL,
            `link` VARCHAR(255),
            `keywords` TEXT,
            `position` ENUM("top", "bottom", "sidebar") NOT NULL DEFAULT "top",
            `id_shop` INT(11) UNSIGNED NOT NULL,
            `id_lang` INT(11) UNSIGNED NOT NULL,
            `active` TINYINT(1) NOT NULL DEFAULT 1,
            `date_start` DATETIME DEFAULT NULL,
            `date_end` DATETIME DEFAULT NULL,
            `date_add` DATETIME NOT NULL,
            `date_upd` DATETIME NOT NULL,
            PRIMARY KEY (`id_smartsearch_banner`),
            INDEX `id_shop` (`id_shop`),
            INDEX `active` (`active`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';

        // Tabella cache
        $sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'smartsearch_cache` (
            `id_smartsearch_cache` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `cache_key` VARCHAR(64) NOT NULL,
            `result_data` LONGTEXT NOT NULL,
            `query` VARCHAR(255) NOT NULL,
            `id_lang` INT(11) UNSIGNED NOT NULL,
            `id_shop` INT(11) UNSIGNED NOT NULL,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id_smartsearch_cache`),
            UNIQUE KEY `cache_key` (`cache_key`),
            INDEX `created_at` (`created_at`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';

        // Inserisci sinonimi di default
        $sql[] = 'INSERT IGNORE INTO `' . _DB_PREFIX_ . 'smartsearch_synonyms` (word, synonyms, id_shop, active, date_add, date_upd) VALUES
            ("smartphone", "cellulare, telefono, mobile, phone", 1, 1, NOW(), NOW()),
            ("pc", "computer, portatile, laptop, notebook", 1, 1, NOW(), NOW()),
            ("tv", "televisore, televisione, schermo", 1, 1, NOW(), NOW()),
            ("scarpe", "calzature, sneakers, stivali", 1, 1, NOW(), NOW()),
            ("maglietta", "t-shirt, maglia, top", 1, 1, NOW(), NOW()),
            ("pantaloni", "jeans, pants, trousers", 1, 1, NOW(), NOW()),
            ("borsa", "borsetta, bag, zaino", 1, 1, NOW(), NOW()),
            ("orologio", "watch, smartwatch", 1, 1, NOW(), NOW()),
            ("cuffie", "auricolari, headphones, earbuds", 1, 1, NOW(), NOW()),
            ("economico", "cheap, low cost, offerta, scontato", 1, 1, NOW(), NOW())
        ';

        foreach ($sql as $query) {
            if (!Db::getInstance()->execute($query)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Disinstallazione tabelle database
     */
    protected function uninstallDb()
    {
        $tables = [
            'smartsearch_stats',
            'smartsearch_clicks',
            'smartsearch_conversions',
            'smartsearch_synonyms',
            'smartsearch_boost',
            'smartsearch_banners',
            'smartsearch_cache'
        ];

        foreach ($tables as $table) {
            Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . $table . '`');
        }

        return true;
    }

    /**
     * Installa tabs nel back-office
     */
    protected function installTabs()
    {
        foreach ($this->tabs as $tabData) {
            $tab = new Tab();
            $tab->class_name = $tabData['class_name'];
            $tab->active = $tabData['visible'];
            $tab->module = $this->name;

            foreach (Language::getLanguages(true) as $lang) {
                $tab->name[$lang['id_lang']] = $tabData['name'];
            }

            $parentTab = Tab::getIdFromClassName($tabData['parent_class_name']);
            $tab->id_parent = $parentTab ?: 0;

            if (!$tab->add()) {
                return false;
            }
        }

        return true;
    }

    /**
     * Disinstalla tabs
     */
    protected function uninstallTabs()
    {
        foreach ($this->tabs as $tabData) {
            $idTab = Tab::getIdFromClassName($tabData['class_name']);
            if ($idTab) {
                $tab = new Tab($idTab);
                if (!$tab->delete()) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Hook per aggiungere CSS e JS
     */
    public function hookActionFrontControllerSetMedia($params)
    {
        if (!Configuration::get('SMARTSEARCH_ENABLED')) {
            return;
        }

        // CSS
        $this->context->controller->registerStylesheet(
            'smartsearch-css',
            'modules/' . $this->name . '/views/css/smartsearch.css',
            ['media' => 'all', 'priority' => 150]
        );

        // JavaScript
        $this->context->controller->registerJavascript(
            'smartsearch-js',
            'modules/' . $this->name . '/views/js/smartsearch.js',
            ['position' => 'bottom', 'priority' => 150]
        );
    }

    /**
     * Hook displayHeader per variabili JS
     */
    public function hookDisplayHeader($params)
    {
        if (!Configuration::get('SMARTSEARCH_ENABLED')) {
            return;
        }

        // Passa le configurazioni al JavaScript
        Media::addJsDef([
            'smartsearch_config' => [
                'ajax_url' => $this->context->link->getModuleLink($this->name, 'search'),
                'track_url' => $this->context->link->getModuleLink($this->name, 'track'),
                'min_chars' => (int)Configuration::get('SMARTSEARCH_MIN_CHARS'),
                'max_results' => (int)Configuration::get('SMARTSEARCH_MAX_RESULTS'),
                'debounce_time' => (int)Configuration::get('SMARTSEARCH_DEBOUNCE_TIME'),

                // Visualizzazione
                'show_price' => (bool)Configuration::get('SMARTSEARCH_SHOW_PRICE'),
                'show_image' => (bool)Configuration::get('SMARTSEARCH_SHOW_IMAGE'),
                'show_description' => (bool)Configuration::get('SMARTSEARCH_SHOW_DESCRIPTION'),
                'show_category' => (bool)Configuration::get('SMARTSEARCH_SHOW_CATEGORY'),
                'show_manufacturer' => (bool)Configuration::get('SMARTSEARCH_SHOW_MANUFACTURER'),
                'show_stock' => (bool)Configuration::get('SMARTSEARCH_SHOW_STOCK'),
                'highlight' => (bool)Configuration::get('SMARTSEARCH_HIGHLIGHT'),

                // Funzionalità
                'facets_enabled' => (bool)Configuration::get('SMARTSEARCH_FACETS_ENABLED'),
                'voice_enabled' => (bool)Configuration::get('SMARTSEARCH_VOICE_ENABLED'),
                'banners_enabled' => (bool)Configuration::get('SMARTSEARCH_BANNERS_ENABLED'),

                // Analytics webhook per n8n
                'analytics_webhook_url' => Configuration::get('SMARTSEARCH_ANALYTICS_WEBHOOK_URL'),
                'shop_id' => (int)$this->context->shop->id,

                // Traduzioni
                'translations' => [
                    'search_placeholder' => $this->l('Cerca prodotti...'),
                    'no_results' => $this->l('Nessun risultato trovato'),
                    'view_all' => $this->l('Vedi tutti i risultati'),
                    'did_you_mean' => $this->l('Forse cercavi'),
                    'categories' => $this->l('Categorie'),
                    'products' => $this->l('Prodotti'),
                    'suggestions' => $this->l('Suggerimenti'),
                    'filters' => $this->l('Filtri'),
                    'price' => $this->l('Prezzo'),
                    'brand' => $this->l('Marca'),
                    'in_stock' => $this->l('Disponibile'),
                    'out_of_stock' => $this->l('Non disponibile'),
                    'voice_search' => $this->l('Ricerca vocale'),
                    'listening' => $this->l('Sto ascoltando...'),
                    'clear_filters' => $this->l('Rimuovi filtri'),
                    'apply_filters' => $this->l('Applica'),
                    'from' => $this->l('Da'),
                    'to' => $this->l('A'),
                ],

                // Valuta
                'currency_sign' => $this->context->currency->sign,
                'currency_format' => $this->context->currency->format,
            ]
        ]);

        return '';
    }

    /**
     * Hook displaySearch - Sovrascrive la barra di ricerca del tema
     */
    public function hookDisplaySearch($params)
    {
        if (!Configuration::get('SMARTSEARCH_ENABLED')) {
            return '';
        }

        $this->context->smarty->assign([
            'smartsearch_placeholder' => $this->l('Cerca prodotti...'),
            'smartsearch_voice_enabled' => (bool)Configuration::get('SMARTSEARCH_VOICE_ENABLED'),
            'smartsearch_search_url' => $this->context->link->getPageLink('search', true),
        ]);

        return $this->display(__FILE__, 'views/templates/hook/searchbar.tpl');
    }

    /**
     * Hook displayTop - Aggiunge widget ricerca anche nella posizione top (alternativa)
     */
    public function hookDisplayTop($params)
    {
        // Se displaySearch è già gestito dal tema, non duplicare
        // Questa è un'alternativa per temi che non supportano displaySearch
        return '';
    }

    /**
     * Hook quando un prodotto viene aggiunto/modificato/eliminato - Invalida la cache
     */
    public function hookActionProductAdd($params)
    {
        $this->invalidateCache();
    }

    public function hookActionProductUpdate($params)
    {
        $this->invalidateCache();
    }

    public function hookActionProductDelete($params)
    {
        $this->invalidateCache();
    }

    /**
     * Hook per tracciare conversioni
     * IMPORTANTE: Questo hook è fail-safe - non deve mai bloccare il checkout
     */
    public function hookActionOrderStatusPostUpdate($params)
    {
        // Tutto in try/catch per non bloccare MAI il checkout
        try {
            // Verifica parametri obbligatori
            if (!isset($params['newOrderStatus']) || !isset($params['id_order'])) {
                return;
            }

            $newStatus = $params['newOrderStatus'];

            // Verifica che sia un oggetto valido con proprietà paid
            if (!is_object($newStatus) || !isset($newStatus->paid) || !$newStatus->paid) {
                return;
            }

            $orderId = (int)$params['id_order'];
            if ($orderId <= 0) {
                return;
            }

            // Carica ordine e verifica che esista
            $order = new Order($orderId);
            if (!Validate::isLoadedObject($order)) {
                return;
            }

            // Recupera dati dalla sessione/cookie in modo sicuro
            $cookie = Context::getContext()->cookie;
            $lastSearch = '';
            $sessionId = '';

            if (isset($cookie->smartsearch_last_query)) {
                $lastSearch = (string)$cookie->smartsearch_last_query;
            }
            if (isset($cookie->smartsearch_session_id)) {
                $sessionId = (string)$cookie->smartsearch_session_id;
            }

            // Prepara i dati dei prodotti
            $products = $order->getProducts();
            if (!is_array($products)) {
                $products = [];
            }

            $productsData = [];
            $totalRevenue = 0;

            foreach ($products as $product) {
                if (!is_array($product)) {
                    continue;
                }
                $productsData[] = [
                    'product_id' => isset($product['product_id']) ? (int)$product['product_id'] : 0,
                    'product_name' => isset($product['product_name']) ? (string)$product['product_name'] : '',
                    'quantity' => isset($product['product_quantity']) ? (int)$product['product_quantity'] : 0,
                    'price' => isset($product['unit_price_tax_incl']) ? (float)$product['unit_price_tax_incl'] : 0,
                    'total' => isset($product['total_price_tax_incl']) ? (float)$product['total_price_tax_incl'] : 0
                ];
                $totalRevenue += isset($product['total_price_tax_incl']) ? (float)$product['total_price_tax_incl'] : 0;
            }

            // Ottieni valuta in modo sicuro
            $currency = 'EUR';
            if ($order->id_currency) {
                $currencyIso = Currency::getIsoCodeById((int)$order->id_currency);
                if ($currencyIso) {
                    $currency = $currencyIso;
                }
            }

            // Invia al webhook n8n (non bloccante, con timeout basso)
            $this->sendConversionToWebhook([
                'event_type' => 'conversion',
                'order_id' => $orderId,
                'order_reference' => $order->reference ?? '',
                'session_id' => $sessionId,
                'last_search_query' => $lastSearch,
                'customer_id' => (int)$order->id_customer,
                'products' => $productsData,
                'products_count' => count($productsData),
                'revenue' => round($totalRevenue, 2),
                'currency' => $currency,
                'shop_id' => (int)$this->context->shop->id,
                'timestamp' => date('c')
            ]);

            // Traccia anche internamente se abilitato
            if (Configuration::get('SMARTSEARCH_ANALYTICS_ENABLED') && !empty($lastSearch)) {
                $this->trackConversionInternal($lastSearch, $products, $orderId);
            }

        } catch (Exception $e) {
            // Log silenzioso - NON bloccare mai il checkout
            if (_PS_MODE_DEV_) {
                PrestaShopLogger::addLog(
                    'SmartSearch conversion tracking error: ' . $e->getMessage(),
                    2,
                    null,
                    'SmartSearch'
                );
            }
        } catch (Error $e) {
            // Cattura anche errori PHP 7+ fatali
            if (_PS_MODE_DEV_) {
                PrestaShopLogger::addLog(
                    'SmartSearch conversion tracking fatal error: ' . $e->getMessage(),
                    3,
                    null,
                    'SmartSearch'
                );
            }
        }
    }

    /**
     * Traccia conversione internamente (separato per gestione errori)
     */
    protected function trackConversionInternal($lastSearch, $products, $orderId)
    {
        try {
            $analytics = new SmartSearchAnalytics(
                $this->context->language->id,
                $this->context->shop->id
            );

            foreach ($products as $product) {
                if (!is_array($product)) {
                    continue;
                }
                $analytics->trackConversion(
                    $lastSearch,
                    isset($product['product_id']) ? $product['product_id'] : 0,
                    $orderId,
                    isset($product['total_price_tax_incl']) ? $product['total_price_tax_incl'] : 0
                );
            }
        } catch (Exception $e) {
            // Ignora errori di tracking interno
        }
    }

    /**
     * Invia dati conversione al webhook n8n
     * Timeout molto basso per non bloccare il checkout
     */
    protected function sendConversionToWebhook($data)
    {
        $webhookUrl = Configuration::get('SMARTSEARCH_ANALYTICS_WEBHOOK_URL');

        if (empty($webhookUrl)) {
            return false;
        }

        // Verifica che cURL sia disponibile
        if (!function_exists('curl_init')) {
            return false;
        }

        try {
            $ch = curl_init($webhookUrl);

            if ($ch === false) {
                return false;
            }

            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => json_encode($data),
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Accept: application/json'
                ],
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 2,           // Max 2 secondi totali
                CURLOPT_CONNECTTIMEOUT => 1,    // Max 1 secondo per connessione
                CURLOPT_NOSIGNAL => 1,          // Necessario per timeout < 1s su alcuni sistemi
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_FOLLOWLOCATION => false // Non seguire redirect
            ]);

            curl_exec($ch);
            $error = curl_error($ch);
            curl_close($ch);

            // Log errori solo in dev mode
            if ($error && _PS_MODE_DEV_) {
                PrestaShopLogger::addLog(
                    'SmartSearch webhook error: ' . $error,
                    2,
                    null,
                    'SmartSearch'
                );
            }

            return empty($error);

        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * Invalida la cache
     */
    protected function invalidateCache()
    {
        if (Configuration::get('SMARTSEARCH_CACHE_ENABLED')) {
            $cache = new SmartSearchCache(
                $this->context->language->id,
                $this->context->shop->id
            );
            $cache->invalidate();
        }
    }

    /**
     * Configurazione del modulo nel back-office
     */
    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitSmartSearchConfig')) {
            $this->saveConfiguration();
            $output .= $this->displayConfirmation($this->l('Impostazioni salvate con successo'));
        }

        if (Tools::isSubmit('clearCache')) {
            $this->invalidateCache();
            $output .= $this->displayConfirmation($this->l('Cache svuotata con successo'));
        }

        return $output . $this->renderConfigForm();
    }

    /**
     * Salva la configurazione
     */
    protected function saveConfiguration()
    {
        $configs = [
            'SMARTSEARCH_ENABLED', 'SMARTSEARCH_MIN_CHARS', 'SMARTSEARCH_MAX_RESULTS',
            'SMARTSEARCH_DEBOUNCE_TIME', 'SMARTSEARCH_SHOW_PRICE', 'SMARTSEARCH_SHOW_IMAGE',
            'SMARTSEARCH_SHOW_DESCRIPTION', 'SMARTSEARCH_SHOW_CATEGORY', 'SMARTSEARCH_SHOW_MANUFACTURER',
            'SMARTSEARCH_SHOW_STOCK', 'SMARTSEARCH_HIGHLIGHT', 'SMARTSEARCH_FUZZY_ENABLED',
            'SMARTSEARCH_FUZZY_THRESHOLD', 'SMARTSEARCH_PHONETIC_ENABLED', 'SMARTSEARCH_STEMMING_ENABLED',
            'SMARTSEARCH_SYNONYMS_ENABLED', 'SMARTSEARCH_FACETS_ENABLED', 'SMARTSEARCH_FACETS_CATEGORIES',
            'SMARTSEARCH_FACETS_PRICE', 'SMARTSEARCH_FACETS_MANUFACTURER', 'SMARTSEARCH_FACETS_ATTRIBUTES',
            'SMARTSEARCH_CACHE_ENABLED', 'SMARTSEARCH_CACHE_TTL', 'SMARTSEARCH_ANALYTICS_ENABLED',
            'SMARTSEARCH_ANALYTICS_RETENTION', 'SMARTSEARCH_VOICE_ENABLED', 'SMARTSEARCH_BANNERS_ENABLED'
        ];

        foreach ($configs as $config) {
            Configuration::updateValue($config, (int)Tools::getValue($config));
        }

        // Salva separatamente i campi stringa
        Configuration::updateValue('SMARTSEARCH_ANALYTICS_WEBHOOK_URL', Tools::getValue('SMARTSEARCH_ANALYTICS_WEBHOOK_URL'));

        $this->invalidateCache();
    }

    /**
     * Render del form di configurazione
     */
    protected function renderConfigForm()
    {
        $fields_form = [];

        // Form Generale
        $fields_form[0] = [
            'form' => [
                'legend' => ['title' => $this->l('Impostazioni Generali'), 'icon' => 'icon-cogs'],
                'input' => [
                    ['type' => 'switch', 'label' => $this->l('Abilita Smart Search'), 'name' => 'SMARTSEARCH_ENABLED', 'is_bool' => true,
                     'values' => [['id' => 'on', 'value' => 1, 'label' => $this->l('Sì')], ['id' => 'off', 'value' => 0, 'label' => $this->l('No')]]],
                    ['type' => 'text', 'label' => $this->l('Caratteri minimi'), 'name' => 'SMARTSEARCH_MIN_CHARS', 'class' => 'fixed-width-sm'],
                    ['type' => 'text', 'label' => $this->l('Risultati massimi'), 'name' => 'SMARTSEARCH_MAX_RESULTS', 'class' => 'fixed-width-sm'],
                    ['type' => 'text', 'label' => $this->l('Debounce (ms)'), 'name' => 'SMARTSEARCH_DEBOUNCE_TIME', 'class' => 'fixed-width-sm'],
                ],
                'submit' => ['title' => $this->l('Salva'), 'class' => 'btn btn-default pull-right']
            ]
        ];

        // Form Visualizzazione
        $fields_form[1] = [
            'form' => [
                'legend' => ['title' => $this->l('Visualizzazione'), 'icon' => 'icon-eye'],
                'input' => [
                    ['type' => 'switch', 'label' => $this->l('Mostra prezzo'), 'name' => 'SMARTSEARCH_SHOW_PRICE', 'is_bool' => true,
                     'values' => [['id' => 'on', 'value' => 1, 'label' => $this->l('Sì')], ['id' => 'off', 'value' => 0, 'label' => $this->l('No')]]],
                    ['type' => 'switch', 'label' => $this->l('Mostra immagine'), 'name' => 'SMARTSEARCH_SHOW_IMAGE', 'is_bool' => true,
                     'values' => [['id' => 'on', 'value' => 1, 'label' => $this->l('Sì')], ['id' => 'off', 'value' => 0, 'label' => $this->l('No')]]],
                    ['type' => 'switch', 'label' => $this->l('Mostra descrizione'), 'name' => 'SMARTSEARCH_SHOW_DESCRIPTION', 'is_bool' => true,
                     'values' => [['id' => 'on', 'value' => 1, 'label' => $this->l('Sì')], ['id' => 'off', 'value' => 0, 'label' => $this->l('No')]]],
                    ['type' => 'switch', 'label' => $this->l('Mostra categoria'), 'name' => 'SMARTSEARCH_SHOW_CATEGORY', 'is_bool' => true,
                     'values' => [['id' => 'on', 'value' => 1, 'label' => $this->l('Sì')], ['id' => 'off', 'value' => 0, 'label' => $this->l('No')]]],
                    ['type' => 'switch', 'label' => $this->l('Mostra marca'), 'name' => 'SMARTSEARCH_SHOW_MANUFACTURER', 'is_bool' => true,
                     'values' => [['id' => 'on', 'value' => 1, 'label' => $this->l('Sì')], ['id' => 'off', 'value' => 0, 'label' => $this->l('No')]]],
                    ['type' => 'switch', 'label' => $this->l('Mostra disponibilità'), 'name' => 'SMARTSEARCH_SHOW_STOCK', 'is_bool' => true,
                     'values' => [['id' => 'on', 'value' => 1, 'label' => $this->l('Sì')], ['id' => 'off', 'value' => 0, 'label' => $this->l('No')]]],
                    ['type' => 'switch', 'label' => $this->l('Evidenzia termini'), 'name' => 'SMARTSEARCH_HIGHLIGHT', 'is_bool' => true,
                     'values' => [['id' => 'on', 'value' => 1, 'label' => $this->l('Sì')], ['id' => 'off', 'value' => 0, 'label' => $this->l('No')]]],
                ]
            ]
        ];

        // Form Algoritmi Intelligenti
        $fields_form[2] = [
            'form' => [
                'legend' => ['title' => $this->l('Algoritmi Intelligenti'), 'icon' => 'icon-magic'],
                'input' => [
                    ['type' => 'switch', 'label' => $this->l('Fuzzy Search'), 'name' => 'SMARTSEARCH_FUZZY_ENABLED', 'is_bool' => true,
                     'desc' => $this->l('Trova risultati anche con errori di battitura'),
                     'values' => [['id' => 'on', 'value' => 1, 'label' => $this->l('Sì')], ['id' => 'off', 'value' => 0, 'label' => $this->l('No')]]],
                    ['type' => 'text', 'label' => $this->l('Tolleranza Fuzzy'), 'name' => 'SMARTSEARCH_FUZZY_THRESHOLD', 'class' => 'fixed-width-sm',
                     'desc' => $this->l('Numero massimo di errori tollerati (1-3)')],
                    ['type' => 'switch', 'label' => $this->l('Ricerca Fonetica'), 'name' => 'SMARTSEARCH_PHONETIC_ENABLED', 'is_bool' => true,
                     'desc' => $this->l('Trova risultati basandosi sul suono delle parole'),
                     'values' => [['id' => 'on', 'value' => 1, 'label' => $this->l('Sì')], ['id' => 'off', 'value' => 0, 'label' => $this->l('No')]]],
                    ['type' => 'switch', 'label' => $this->l('Stemming'), 'name' => 'SMARTSEARCH_STEMMING_ENABLED', 'is_bool' => true,
                     'desc' => $this->l('Riduce le parole alla radice'),
                     'values' => [['id' => 'on', 'value' => 1, 'label' => $this->l('Sì')], ['id' => 'off', 'value' => 0, 'label' => $this->l('No')]]],
                    ['type' => 'switch', 'label' => $this->l('Sinonimi'), 'name' => 'SMARTSEARCH_SYNONYMS_ENABLED', 'is_bool' => true,
                     'desc' => $this->l('Usa sinonimi per espandere la ricerca'),
                     'values' => [['id' => 'on', 'value' => 1, 'label' => $this->l('Sì')], ['id' => 'off', 'value' => 0, 'label' => $this->l('No')]]],
                ]
            ]
        ];

        // Form Filtri
        $fields_form[3] = [
            'form' => [
                'legend' => ['title' => $this->l('Filtri Dinamici (Facets)'), 'icon' => 'icon-filter'],
                'input' => [
                    ['type' => 'switch', 'label' => $this->l('Abilita Filtri'), 'name' => 'SMARTSEARCH_FACETS_ENABLED', 'is_bool' => true,
                     'values' => [['id' => 'on', 'value' => 1, 'label' => $this->l('Sì')], ['id' => 'off', 'value' => 0, 'label' => $this->l('No')]]],
                    ['type' => 'switch', 'label' => $this->l('Filtro Categorie'), 'name' => 'SMARTSEARCH_FACETS_CATEGORIES', 'is_bool' => true,
                     'values' => [['id' => 'on', 'value' => 1, 'label' => $this->l('Sì')], ['id' => 'off', 'value' => 0, 'label' => $this->l('No')]]],
                    ['type' => 'switch', 'label' => $this->l('Filtro Prezzo'), 'name' => 'SMARTSEARCH_FACETS_PRICE', 'is_bool' => true,
                     'values' => [['id' => 'on', 'value' => 1, 'label' => $this->l('Sì')], ['id' => 'off', 'value' => 0, 'label' => $this->l('No')]]],
                    ['type' => 'switch', 'label' => $this->l('Filtro Marca'), 'name' => 'SMARTSEARCH_FACETS_MANUFACTURER', 'is_bool' => true,
                     'values' => [['id' => 'on', 'value' => 1, 'label' => $this->l('Sì')], ['id' => 'off', 'value' => 0, 'label' => $this->l('No')]]],
                    ['type' => 'switch', 'label' => $this->l('Filtro Attributi'), 'name' => 'SMARTSEARCH_FACETS_ATTRIBUTES', 'is_bool' => true,
                     'desc' => $this->l('Colore, taglia, materiale, ecc.'),
                     'values' => [['id' => 'on', 'value' => 1, 'label' => $this->l('Sì')], ['id' => 'off', 'value' => 0, 'label' => $this->l('No')]]],
                ]
            ]
        ];

        // Form Cache
        $fields_form[4] = [
            'form' => [
                'legend' => ['title' => $this->l('Cache e Performance'), 'icon' => 'icon-rocket'],
                'input' => [
                    ['type' => 'switch', 'label' => $this->l('Abilita Cache'), 'name' => 'SMARTSEARCH_CACHE_ENABLED', 'is_bool' => true,
                     'values' => [['id' => 'on', 'value' => 1, 'label' => $this->l('Sì')], ['id' => 'off', 'value' => 0, 'label' => $this->l('No')]]],
                    ['type' => 'text', 'label' => $this->l('Durata Cache (sec)'), 'name' => 'SMARTSEARCH_CACHE_TTL', 'class' => 'fixed-width-md'],
                ],
                'buttons' => [['title' => $this->l('Svuota Cache'), 'name' => 'clearCache', 'type' => 'submit', 'class' => 'btn btn-default', 'icon' => 'process-icon-eraser']]
            ]
        ];

        // Form Analytics
        $fields_form[5] = [
            'form' => [
                'legend' => ['title' => $this->l('Analytics'), 'icon' => 'icon-bar-chart'],
                'input' => [
                    ['type' => 'switch', 'label' => $this->l('Abilita Analytics'), 'name' => 'SMARTSEARCH_ANALYTICS_ENABLED', 'is_bool' => true,
                     'values' => [['id' => 'on', 'value' => 1, 'label' => $this->l('Sì')], ['id' => 'off', 'value' => 0, 'label' => $this->l('No')]]],
                    ['type' => 'text', 'label' => $this->l('Retention (giorni)'), 'name' => 'SMARTSEARCH_ANALYTICS_RETENTION', 'class' => 'fixed-width-sm'],
                    ['type' => 'text', 'label' => $this->l('Webhook URL (n8n)'), 'name' => 'SMARTSEARCH_ANALYTICS_WEBHOOK_URL', 'class' => 'fixed-width-xxl',
                     'desc' => $this->l('URL del webhook n8n per inviare gli eventi analytics (es. https://tuo-n8n.com/webhook/smartsearch-analytics)')],
                ]
            ]
        ];

        // Form Extra
        $fields_form[6] = [
            'form' => [
                'legend' => ['title' => $this->l('Funzionalità Extra'), 'icon' => 'icon-star'],
                'input' => [
                    ['type' => 'switch', 'label' => $this->l('Ricerca Vocale'), 'name' => 'SMARTSEARCH_VOICE_ENABLED', 'is_bool' => true,
                     'desc' => $this->l('Permette di cercare usando la voce'),
                     'values' => [['id' => 'on', 'value' => 1, 'label' => $this->l('Sì')], ['id' => 'off', 'value' => 0, 'label' => $this->l('No')]]],
                    ['type' => 'switch', 'label' => $this->l('Banner Promozionali'), 'name' => 'SMARTSEARCH_BANNERS_ENABLED', 'is_bool' => true,
                     'desc' => $this->l('Mostra banner nei risultati'),
                     'values' => [['id' => 'on', 'value' => 1, 'label' => $this->l('Sì')], ['id' => 'off', 'value' => 0, 'label' => $this->l('No')]]],
                ],
                'submit' => ['title' => $this->l('Salva tutte le impostazioni'), 'class' => 'btn btn-default pull-right']
            ]
        ];

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->default_form_language = (int)Configuration::get('PS_LANG_DEFAULT');
        $helper->title = $this->displayName;
        $helper->submit_action = 'submitSmartSearchConfig';

        // Valori correnti
        $helper->fields_value = [
            'SMARTSEARCH_ENABLED' => Configuration::get('SMARTSEARCH_ENABLED'),
            'SMARTSEARCH_MIN_CHARS' => Configuration::get('SMARTSEARCH_MIN_CHARS'),
            'SMARTSEARCH_MAX_RESULTS' => Configuration::get('SMARTSEARCH_MAX_RESULTS'),
            'SMARTSEARCH_DEBOUNCE_TIME' => Configuration::get('SMARTSEARCH_DEBOUNCE_TIME'),
            'SMARTSEARCH_SHOW_PRICE' => Configuration::get('SMARTSEARCH_SHOW_PRICE'),
            'SMARTSEARCH_SHOW_IMAGE' => Configuration::get('SMARTSEARCH_SHOW_IMAGE'),
            'SMARTSEARCH_SHOW_DESCRIPTION' => Configuration::get('SMARTSEARCH_SHOW_DESCRIPTION'),
            'SMARTSEARCH_SHOW_CATEGORY' => Configuration::get('SMARTSEARCH_SHOW_CATEGORY'),
            'SMARTSEARCH_SHOW_MANUFACTURER' => Configuration::get('SMARTSEARCH_SHOW_MANUFACTURER'),
            'SMARTSEARCH_SHOW_STOCK' => Configuration::get('SMARTSEARCH_SHOW_STOCK'),
            'SMARTSEARCH_HIGHLIGHT' => Configuration::get('SMARTSEARCH_HIGHLIGHT'),
            'SMARTSEARCH_FUZZY_ENABLED' => Configuration::get('SMARTSEARCH_FUZZY_ENABLED'),
            'SMARTSEARCH_FUZZY_THRESHOLD' => Configuration::get('SMARTSEARCH_FUZZY_THRESHOLD'),
            'SMARTSEARCH_PHONETIC_ENABLED' => Configuration::get('SMARTSEARCH_PHONETIC_ENABLED'),
            'SMARTSEARCH_STEMMING_ENABLED' => Configuration::get('SMARTSEARCH_STEMMING_ENABLED'),
            'SMARTSEARCH_SYNONYMS_ENABLED' => Configuration::get('SMARTSEARCH_SYNONYMS_ENABLED'),
            'SMARTSEARCH_FACETS_ENABLED' => Configuration::get('SMARTSEARCH_FACETS_ENABLED'),
            'SMARTSEARCH_FACETS_CATEGORIES' => Configuration::get('SMARTSEARCH_FACETS_CATEGORIES'),
            'SMARTSEARCH_FACETS_PRICE' => Configuration::get('SMARTSEARCH_FACETS_PRICE'),
            'SMARTSEARCH_FACETS_MANUFACTURER' => Configuration::get('SMARTSEARCH_FACETS_MANUFACTURER'),
            'SMARTSEARCH_FACETS_ATTRIBUTES' => Configuration::get('SMARTSEARCH_FACETS_ATTRIBUTES'),
            'SMARTSEARCH_CACHE_ENABLED' => Configuration::get('SMARTSEARCH_CACHE_ENABLED'),
            'SMARTSEARCH_CACHE_TTL' => Configuration::get('SMARTSEARCH_CACHE_TTL'),
            'SMARTSEARCH_ANALYTICS_ENABLED' => Configuration::get('SMARTSEARCH_ANALYTICS_ENABLED'),
            'SMARTSEARCH_ANALYTICS_RETENTION' => Configuration::get('SMARTSEARCH_ANALYTICS_RETENTION'),
            'SMARTSEARCH_ANALYTICS_WEBHOOK_URL' => Configuration::get('SMARTSEARCH_ANALYTICS_WEBHOOK_URL'),
            'SMARTSEARCH_VOICE_ENABLED' => Configuration::get('SMARTSEARCH_VOICE_ENABLED'),
            'SMARTSEARCH_BANNERS_ENABLED' => Configuration::get('SMARTSEARCH_BANNERS_ENABLED'),
        ];

        return $helper->generateForm($fields_form);
    }
}
