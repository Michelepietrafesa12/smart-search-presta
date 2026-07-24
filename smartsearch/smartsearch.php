<?php
/**
 * SmartSearch 2.0 - Modulo di ricerca dinamica intelligente per PrestaShop
 * Simile a Doofinder con AI, fuzzy search, sinonimi e filtri
 *
 * @author Michele Pietrafesa
 * @copyright 2024
 * @license MIT
 * @version 2.0.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once dirname(__FILE__) . '/classes/SmartSearchEngine.php';
require_once dirname(__FILE__) . '/classes/SmartSearchCache.php';
require_once dirname(__FILE__) . '/classes/SmartSearchLearner.php';

class SmartSearch extends Module
{
    /** @var array */
    protected $tabs = [];

    /** @var array Cache delle configurazioni per evitare query multiple */
    protected static $configCache = null;

    /**
     * Ottieni configurazione con cache statica
     * Evita ~20 query al database per ogni page load
     */
    protected static function getConfig($key, $default = null)
    {
        if (self::$configCache === null) {
            // Carica tutte le config in una volta sola
            // Configuration::get() restituisce false se la chiave non esiste
            $showBestseller = Configuration::get('SMARTSEARCH_SHOW_BESTSELLER');

            self::$configCache = [
                'enabled' => (bool)Configuration::get('SMARTSEARCH_ENABLED'),
                'min_chars' => (int)Configuration::get('SMARTSEARCH_MIN_CHARS') ?: 2,
                'max_results' => (int)Configuration::get('SMARTSEARCH_MAX_RESULTS') ?: 8,
                'debounce_time' => (int)Configuration::get('SMARTSEARCH_DEBOUNCE_TIME') ?: 300,
                'show_price' => (bool)Configuration::get('SMARTSEARCH_SHOW_PRICE'),
                'show_image' => (bool)Configuration::get('SMARTSEARCH_SHOW_IMAGE'),
                'show_description' => (bool)Configuration::get('SMARTSEARCH_SHOW_DESCRIPTION'),
                'show_category' => (bool)Configuration::get('SMARTSEARCH_SHOW_CATEGORY'),
                'show_manufacturer' => (bool)Configuration::get('SMARTSEARCH_SHOW_MANUFACTURER'),
                'show_stock' => (bool)Configuration::get('SMARTSEARCH_SHOW_STOCK'),
                // Default: true per bestseller se non configurato
                'show_bestseller_badge' => ($showBestseller === false) ? true : (bool)$showBestseller,
                'highlight' => (bool)Configuration::get('SMARTSEARCH_HIGHLIGHT'),
                'facets_enabled' => (bool)Configuration::get('SMARTSEARCH_FACETS_ENABLED'),
                'voice_enabled' => (bool)Configuration::get('SMARTSEARCH_VOICE_ENABLED'),
                'banners_enabled' => (bool)Configuration::get('SMARTSEARCH_BANNERS_ENABLED'),
                'cache_enabled' => (bool)Configuration::get('SMARTSEARCH_CACHE_ENABLED'),
            ];
        }
        return isset(self::$configCache[$key]) ? self::$configCache[$key] : $default;
    }

    /**
     * Ottieni configurazione stile personalizzato
     * Ritorna i valori configurati o i default
     */
    protected function getStyleConfig()
    {
        // Valori predefiniti
        $defaults = [
            'overlay_bg' => '#1e293b',
            'overlay_opacity' => 98,
            'search_bg' => '#1e293b',
            'search_text' => '#ffffff',
            'search_placeholder' => '#94a3b8',
            'accent_color' => '#f97316',
            'card_bg' => '#ffffff',
            'card_title' => '#1e293b',
            'card_price' => '#059669',
            'card_price_old' => '#94a3b8',
            'discount_badge_bg' => '#dc2626',
            'discount_badge_text' => '#ffffff',
            'sidebar_bg' => '#f8fafc',
            'sidebar_text' => '#334155',
            'button_bg' => '#f97316',
            'button_text' => '#ffffff',
        ];

        return [
            'overlay_bg' => Configuration::get('SMARTSEARCH_STYLE_OVERLAY_BG') ?: $defaults['overlay_bg'],
            'overlay_opacity' => (int)(Configuration::get('SMARTSEARCH_STYLE_OVERLAY_OPACITY') ?: $defaults['overlay_opacity']),
            'search_bg' => Configuration::get('SMARTSEARCH_STYLE_SEARCH_BG') ?: $defaults['search_bg'],
            'search_text' => Configuration::get('SMARTSEARCH_STYLE_SEARCH_TEXT') ?: $defaults['search_text'],
            'search_placeholder' => Configuration::get('SMARTSEARCH_STYLE_SEARCH_PLACEHOLDER') ?: $defaults['search_placeholder'],
            'accent_color' => Configuration::get('SMARTSEARCH_STYLE_ACCENT') ?: $defaults['accent_color'],
            'card_bg' => Configuration::get('SMARTSEARCH_STYLE_CARD_BG') ?: $defaults['card_bg'],
            'card_title' => Configuration::get('SMARTSEARCH_STYLE_CARD_TITLE') ?: $defaults['card_title'],
            'card_price' => Configuration::get('SMARTSEARCH_STYLE_CARD_PRICE') ?: $defaults['card_price'],
            'card_price_old' => Configuration::get('SMARTSEARCH_STYLE_CARD_PRICE_OLD') ?: $defaults['card_price_old'],
            'discount_badge_bg' => Configuration::get('SMARTSEARCH_STYLE_DISCOUNT_BG') ?: $defaults['discount_badge_bg'],
            'discount_badge_text' => Configuration::get('SMARTSEARCH_STYLE_DISCOUNT_TEXT') ?: $defaults['discount_badge_text'],
            'sidebar_bg' => Configuration::get('SMARTSEARCH_STYLE_SIDEBAR_BG') ?: $defaults['sidebar_bg'],
            'sidebar_text' => Configuration::get('SMARTSEARCH_STYLE_SIDEBAR_TEXT') ?: $defaults['sidebar_text'],
            'button_bg' => Configuration::get('SMARTSEARCH_STYLE_BUTTON_BG') ?: $defaults['button_bg'],
            'button_text' => Configuration::get('SMARTSEARCH_STYLE_BUTTON_TEXT') ?: $defaults['button_text'],
        ];
    }

    public function __construct()
    {
        $this->name = 'smartsearch';
        $this->tab = 'search_filter';
        $this->version = '2.3.0';
        $this->author = 'Michele Pietrafesa';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = [
            'min' => '1.7.0.0',
            'max' => _PS_VERSION_
        ];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Smart Search 2.0');
        $this->description = $this->l('Ricerca dinamica intelligente con AI, fuzzy search, sinonimi e filtri avanzati - simile a Doofinder');
        $this->confirmUninstall = $this->l('Sei sicuro di voler disinstallare questo modulo? Tutti i dati delle ricerche verranno persi.');

        // Definizione tabs admin - Solo Dashboard principale (contiene tutto)
        $this->tabs = [
            [
                'class_name' => 'AdminSmartSearchDashboard',
                'visible' => true,
                'name' => 'Smart Search',
                'parent_class_name' => 'AdminCatalog',
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
            && $this->registerHook('displayFooterProduct')
            && $this->registerHook('displayShoppingCartFooter')
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
        Configuration::updateValue('SMARTSEARCH_SHOW_BESTSELLER', 1);
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

        // Voice Search
        Configuration::updateValue('SMARTSEARCH_VOICE_ENABLED', 1);

        // Banner
        Configuration::updateValue('SMARTSEARCH_BANNERS_ENABLED', 1);

        // Correlazioni/Raccomandazioni
        Configuration::updateValue('SMARTSEARCH_CORRELATIONS_ENABLED', 1);
        Configuration::updateValue('SMARTSEARCH_CORRELATIONS_PRODUCT_ENABLED', 1);
        Configuration::updateValue('SMARTSEARCH_CORRELATIONS_CART_ENABLED', 1);
        Configuration::updateValue('SMARTSEARCH_CORRELATIONS_DAYS', 180);
        Configuration::updateValue('SMARTSEARCH_CORRELATIONS_MIN_PURCHASES', 2);
        Configuration::updateValue('SMARTSEARCH_CORRELATIONS_LAST_UPDATE', '');

        // Apprendimento automatico sinonimi + reindicizzazione programmata
        Configuration::updateValue('SMARTSEARCH_AUTOLEARN_ENABLED', 1);
        Configuration::updateValue('SMARTSEARCH_AUTOLEARN_INTERVAL_DAYS', 7);
        Configuration::updateValue('SMARTSEARCH_AUTOLEARN_MIN_FREQ', 3);
        Configuration::updateValue('SMARTSEARCH_AUTOLEARN_AUTO_THRESHOLD', 85);
        Configuration::updateValue('SMARTSEARCH_AUTOLEARN_MIN_THRESHOLD', 55);
        Configuration::updateValue('SMARTSEARCH_AUTOLEARN_LAST', '');
        Configuration::updateValue('SMARTSEARCH_AUTOREINDEX_ENABLED', 1);
        Configuration::updateValue('SMARTSEARCH_AUTOREINDEX_INTERVAL_DAYS', 3);
        Configuration::updateValue('SMARTSEARCH_AUTOREINDEX_LAST', '');

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
            'SMARTSEARCH_SHOW_STOCK', 'SMARTSEARCH_SHOW_BESTSELLER',
            'SMARTSEARCH_HIGHLIGHT', 'SMARTSEARCH_FUZZY_ENABLED',
            'SMARTSEARCH_FUZZY_THRESHOLD', 'SMARTSEARCH_PHONETIC_ENABLED', 'SMARTSEARCH_STEMMING_ENABLED',
            'SMARTSEARCH_SYNONYMS_ENABLED', 'SMARTSEARCH_FACETS_ENABLED', 'SMARTSEARCH_FACETS_CATEGORIES',
            'SMARTSEARCH_FACETS_PRICE', 'SMARTSEARCH_FACETS_MANUFACTURER', 'SMARTSEARCH_FACETS_ATTRIBUTES',
            'SMARTSEARCH_FACETS_STOCK', 'SMARTSEARCH_CACHE_ENABLED', 'SMARTSEARCH_CACHE_TTL',
            'SMARTSEARCH_VOICE_ENABLED', 'SMARTSEARCH_BANNERS_ENABLED',
            'SMARTSEARCH_CORRELATIONS_ENABLED', 'SMARTSEARCH_CORRELATIONS_PRODUCT_ENABLED',
            'SMARTSEARCH_CORRELATIONS_CART_ENABLED', 'SMARTSEARCH_CORRELATIONS_DAYS',
            'SMARTSEARCH_CORRELATIONS_MIN_PURCHASES', 'SMARTSEARCH_CORRELATIONS_LAST_UPDATE',
            'SMARTSEARCH_AUTOLEARN_ENABLED', 'SMARTSEARCH_AUTOLEARN_INTERVAL_DAYS',
            'SMARTSEARCH_AUTOLEARN_MIN_FREQ', 'SMARTSEARCH_AUTOLEARN_AUTO_THRESHOLD',
            'SMARTSEARCH_AUTOLEARN_MIN_THRESHOLD', 'SMARTSEARCH_AUTOLEARN_LAST',
            'SMARTSEARCH_AUTOREINDEX_ENABLED', 'SMARTSEARCH_AUTOREINDEX_INTERVAL_DAYS',
            'SMARTSEARCH_AUTOREINDEX_LAST'
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

        // Tabella statistiche ricerche (aggregata per query)
        $sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'smartsearch_stats` (
            `id_smartsearch_stats` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `search_query` VARCHAR(255) NOT NULL,
            `search_count` INT(11) NOT NULL DEFAULT 1,
            `results_count` INT(11) NOT NULL DEFAULT 0,
            `id_lang` INT(11) UNSIGNED NOT NULL,
            `id_shop` INT(11) UNSIGNED NOT NULL,
            `last_search` DATETIME NOT NULL,
            `date_add` DATETIME NOT NULL,
            PRIMARY KEY (`id_smartsearch_stats`),
            UNIQUE KEY `query_lang_shop` (`search_query`(191), `id_lang`, `id_shop`),
            INDEX `search_count` (`search_count`),
            INDEX `id_shop` (`id_shop`),
            INDEX `idx_zero_results` (`id_shop`, `id_lang`, `results_count`),
            INDEX `idx_last_search` (`last_search`)
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

        // Indice di ricerca pre-calcolato (denormalizzato, con FULLTEXT)
        $sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'smartsearch_index` (
            `id_product` INT(11) UNSIGNED NOT NULL,
            `id_lang` INT(11) UNSIGNED NOT NULL,
            `id_shop` INT(11) UNSIGNED NOT NULL,
            `product_name` VARCHAR(255) NOT NULL,
            `name_only_content` TEXT NOT NULL,
            `search_content` TEXT NOT NULL,
            `link_rewrite` VARCHAR(255) NOT NULL DEFAULT \'\',
            `description_short` TEXT,
            `reference` VARCHAR(64) DEFAULT NULL,
            `ean13` VARCHAR(13) DEFAULT NULL,
            `id_category_default` INT(11) UNSIGNED DEFAULT NULL,
            `category_name` VARCHAR(128) DEFAULT NULL,
            `id_manufacturer` INT(11) UNSIGNED DEFAULT NULL,
            `manufacturer_name` VARCHAR(128) DEFAULT NULL,
            `id_image` INT(11) UNSIGNED DEFAULT NULL,
            `sales_count` INT(11) NOT NULL DEFAULT 0,
            `date_add` DATETIME DEFAULT NULL,
            `active` TINYINT(1) NOT NULL DEFAULT 1,
            `date_indexed` DATETIME NOT NULL,
            PRIMARY KEY (`id_product`, `id_lang`, `id_shop`),
            FULLTEXT INDEX `ft_search_content` (`search_content`),
            FULLTEXT INDEX `ft_name_only` (`name_only_content`),
            FULLTEXT INDEX `ft_product_name` (`product_name`),
            INDEX `idx_active_shop_lang` (`active`, `id_shop`, `id_lang`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';

        // Tabella correlazioni prodotti (per raccomandazioni)
        $sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'smartsearch_correlations` (
            `id_smartsearch_correlation` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_product_source` INT(11) UNSIGNED NOT NULL,
            `id_product_target` INT(11) UNSIGNED NOT NULL,
            `correlation_score` DECIMAL(5,4) NOT NULL DEFAULT 0,
            `purchase_count` INT(11) NOT NULL DEFAULT 0,
            `id_shop` INT(11) UNSIGNED NOT NULL,
            `date_upd` DATETIME NOT NULL,
            PRIMARY KEY (`id_smartsearch_correlation`),
            UNIQUE KEY `product_pair_shop` (`id_product_source`, `id_product_target`, `id_shop`),
            INDEX `idx_source_shop` (`id_product_source`, `id_shop`, `correlation_score`),
            INDEX `idx_target` (`id_product_target`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';

        // Candidati sinonimo appresi automaticamente (coda di revisione)
        $sql[] = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'smartsearch_synonym_candidates` (
            `id_candidate` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `source_term` VARCHAR(191) NOT NULL,
            `target_term` VARCHAR(191) NOT NULL,
            `confidence` DECIMAL(5,4) NOT NULL DEFAULT 0,
            `source_type` VARCHAR(32) NOT NULL DEFAULT \'catalog\',
            `occurrences` INT(11) NOT NULL DEFAULT 1,
            `status` VARCHAR(16) NOT NULL DEFAULT \'pending\',
            `id_lang` INT(11) UNSIGNED NOT NULL,
            `id_shop` INT(11) UNSIGNED NOT NULL,
            `date_add` DATETIME NOT NULL,
            `date_upd` DATETIME NOT NULL,
            PRIMARY KEY (`id_candidate`),
            UNIQUE KEY `pair_shop` (`source_term`, `target_term`, `id_shop`),
            INDEX `idx_status_shop` (`status`, `id_shop`),
            INDEX `idx_confidence` (`confidence`)
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

        // Aggiungi FULLTEXT index su product_lang per ricerca veloce
        try {
            Db::getInstance()->execute(
                'ALTER TABLE `' . _DB_PREFIX_ . 'product_lang` '
                . 'ADD FULLTEXT INDEX `ft_smartsearch` (`name`, `description_short`)'
            );
        } catch (Throwable $e) {
            // L'indice potrebbe già esistere
        }

        return true;
    }

    /**
     * Esegue le migrazioni di schema per aggiornamenti in-place.
     * Chiamato da getContent() così gira anche su installazioni esistenti.
     */
    protected function runMigrations()
    {
        // Migrazione v7: aggiunge name_only_content per scoring pesato FULLTEXT
        try {
            $cols = Db::getInstance()->executeS(
                'SHOW COLUMNS FROM `' . _DB_PREFIX_ . 'smartsearch_index` LIKE \'name_only_content\''
            );
            if (empty($cols)) {
                Db::getInstance()->execute(
                    'ALTER TABLE `' . _DB_PREFIX_ . 'smartsearch_index` '
                    . 'ADD COLUMN `name_only_content` TEXT NOT NULL AFTER `product_name`, '
                    . 'ADD FULLTEXT INDEX `ft_name_only` (`name_only_content`)'
                );
                // Popola la colonna per i record esistenti
                Db::getInstance()->execute(
                    'UPDATE `' . _DB_PREFIX_ . 'smartsearch_index` '
                    . 'SET `name_only_content` = CONCAT_WS(\' \', `product_name`, IFNULL(`manufacturer_name`, \'\'), IFNULL(`reference`, \'\')) '
                    . 'WHERE `name_only_content` = \'\''
                );
            }
        } catch (Throwable $e) {
            // Tabella potrebbe non esistere ancora
        }

        // Migrazione v8: apprendimento automatico sinonimi + reindicizzazione programmata
        try {
            Db::getInstance()->execute(
                'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'smartsearch_synonym_candidates` (
                    `id_candidate` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
                    `source_term` VARCHAR(191) NOT NULL,
                    `target_term` VARCHAR(191) NOT NULL,
                    `confidence` DECIMAL(5,4) NOT NULL DEFAULT 0,
                    `source_type` VARCHAR(32) NOT NULL DEFAULT \'catalog\',
                    `occurrences` INT(11) NOT NULL DEFAULT 1,
                    `status` VARCHAR(16) NOT NULL DEFAULT \'pending\',
                    `id_lang` INT(11) UNSIGNED NOT NULL,
                    `id_shop` INT(11) UNSIGNED NOT NULL,
                    `date_add` DATETIME NOT NULL,
                    `date_upd` DATETIME NOT NULL,
                    PRIMARY KEY (`id_candidate`),
                    UNIQUE KEY `pair_shop` (`source_term`, `target_term`, `id_shop`),
                    INDEX `idx_status_shop` (`status`, `id_shop`),
                    INDEX `idx_confidence` (`confidence`)
                ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;'
            );

            // Imposta i valori di default solo se non gia' presenti
            $learnDefaults = array(
                'SMARTSEARCH_AUTOLEARN_ENABLED' => 1,
                'SMARTSEARCH_AUTOLEARN_INTERVAL_DAYS' => 7,
                'SMARTSEARCH_AUTOLEARN_MIN_FREQ' => 3,
                'SMARTSEARCH_AUTOLEARN_AUTO_THRESHOLD' => 85,
                'SMARTSEARCH_AUTOLEARN_MIN_THRESHOLD' => 55,
                'SMARTSEARCH_AUTOREINDEX_ENABLED' => 1,
                'SMARTSEARCH_AUTOREINDEX_INTERVAL_DAYS' => 3,
            );
            foreach ($learnDefaults as $key => $value) {
                if (Configuration::get($key) === false) {
                    Configuration::updateValue($key, $value);
                }
            }
        } catch (Throwable $e) {
            // Ignora se non applicabile
        }
    }

    /**
     * Disinstallazione tabelle database
     */
    protected function uninstallDb()
    {
        $tables = [
            'smartsearch_stats',
            'smartsearch_synonyms',
            'smartsearch_boost',
            'smartsearch_banners',
            'smartsearch_cache',
            'smartsearch_index',
            'smartsearch_correlations',
            'smartsearch_synonym_candidates'
        ];

        foreach ($tables as $table) {
            Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . $table . '`');
        }

        // Rimuovi FULLTEXT index da product_lang
        try {
            Db::getInstance()->execute(
                'ALTER TABLE `' . _DB_PREFIX_ . 'product_lang` DROP INDEX `ft_smartsearch`'
            );
        } catch (Throwable $e) {
            // Ignora se non esiste
        }

        return true;
    }

    /**
     * Installa tabs nel back-office
     */
    protected function installTabs()
    {
        foreach ($this->tabs as $tabData) {
            // Check if tab already exists - skip if so
            $existingTabId = Tab::getIdFromClassName($tabData['class_name']);
            if ($existingTabId) {
                continue;
            }

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
     * Disinstalla tabs - rimuove anche i tabs legacy
     */
    protected function uninstallTabs()
    {
        // Lista completa di tutti i tabs da rimuovere (inclusi legacy)
        $tabsToRemove = [
            'AdminSmartSearchDashboard',
            'AdminSmartSearchSynonyms',
            'AdminSmartSearchBoost',
            'AdminSmartSearchBanners',
        ];

        foreach ($tabsToRemove as $className) {
            $idTab = Tab::getIdFromClassName($className);
            if ($idTab) {
                $tab = new Tab($idTab);
                $tab->delete();
            }
        }

        return true;
    }

    /**
     * Hook per aggiungere CSS e JS
     * OTTIMIZZATO: Carica JS in defer, CSS con preload
     */
    public function hookActionFrontControllerSetMedia($params)
    {
        if (!self::getConfig('enabled')) {
            return;
        }

        // Non caricare JS/CSS su checkout e conferma ordine
        $excludedControllers = ['order', 'orderopc', 'orderosc', 'orderconfirmation'];
        if (in_array(strtolower(Tools::getValue('controller', '')), $excludedControllers)) {
            return;
        }

        // CSS - caricato normalmente ma con priority bassa (non blocca render)
        $this->context->controller->registerStylesheet(
            'smartsearch-css',
            'modules/' . $this->name . '/views/css/smartsearch.css',
            ['media' => 'all', 'priority' => 200] // Priority alta = caricato dopo gli altri CSS
        );

        // JavaScript - caricato in defer per non bloccare il rendering
        $this->context->controller->registerJavascript(
            'smartsearch-js',
            'modules/' . $this->name . '/views/js/smartsearch.js',
            [
                'position' => 'bottom',
                'priority' => 200,
                'attributes' => 'defer' // Non blocca il parsing HTML
            ]
        );

        // Scheduler interno (pseudo-cron): se un job programmato e' scaduto,
        // lo avvia in background senza rallentare la pagina. Fail-safe.
        try {
            $this->maybeRunScheduledJobs();
        } catch (Throwable $e) {
            // Non deve mai impattare il front office
        }
    }

    /**
     * Scheduler interno. Verifica se i job programmati (reindicizzazione,
     * apprendimento sinonimi) sono scaduti e li avvia via richiesta HTTP
     * "fire and forget" verso gli script cron, senza attendere la risposta.
     *
     * Utile per hosting che non permettono di configurare un vero cron:
     * l'aggiornamento avviene in occasione delle visite al negozio.
     * Ovviamente, se e' gia' configurato un cron reale, non fa nulla di dannoso.
     */
    protected function maybeRunScheduledJobs()
    {
        // Esegui il controllo al massimo una volta per richiesta
        static $checked = false;
        if ($checked) {
            return;
        }
        $checked = true;

        $now = time();

        // 1) Reindicizzazione programmata
        if ((int) Configuration::get('SMARTSEARCH_AUTOREINDEX_ENABLED') === 1) {
            $intervalDays = (int) Configuration::get('SMARTSEARCH_AUTOREINDEX_INTERVAL_DAYS');
            if ($intervalDays > 0 && $this->isJobDue('SMARTSEARCH_AUTOREINDEX_LAST', $intervalDays, $now)) {
                // Segna subito l'orario per evitare avvii multipli concorrenti
                Configuration::updateValue('SMARTSEARCH_AUTOREINDEX_LAST', date('Y-m-d H:i:s', $now));
                $this->triggerCronAsync('rebuild_index.php');
            }
        }

        // 2) Apprendimento automatico sinonimi
        if ((int) Configuration::get('SMARTSEARCH_AUTOLEARN_ENABLED') === 1) {
            $intervalDays = (int) Configuration::get('SMARTSEARCH_AUTOLEARN_INTERVAL_DAYS');
            if ($intervalDays > 0 && $this->isJobDue('SMARTSEARCH_AUTOLEARN_LAST', $intervalDays, $now)) {
                Configuration::updateValue('SMARTSEARCH_AUTOLEARN_LAST', date('Y-m-d H:i:s', $now));
                $this->triggerCronAsync('learn_synonyms.php');
            }
        }
    }

    /**
     * Verifica se un job e' scaduto rispetto al suo ultimo avvio.
     */
    protected function isJobDue($lastConfigKey, $intervalDays, $now)
    {
        $last = Configuration::get($lastConfigKey);
        if (empty($last)) {
            return true;
        }
        $lastTs = strtotime($last);
        if ($lastTs === false) {
            return true;
        }
        return ($now - $lastTs) >= ($intervalDays * 86400);
    }

    /**
     * Avvia uno script cron via HTTP in modalita' "fire and forget":
     * apre la connessione, invia la richiesta e chiude senza leggere la
     * risposta, cosi' la pagina dell'utente non attende il completamento.
     */
    protected function triggerCronAsync($scriptName)
    {
        $token = md5(_COOKIE_KEY_ . 'smartsearch_cron');

        $ssl = Configuration::get('PS_SSL_ENABLED');
        $domain = $ssl ? Tools::getShopDomainSsl(false) : Tools::getShopDomain(false);
        $baseUri = __PS_BASE_URI__; // es. "/" oppure "/shop/"
        $path = $baseUri . 'modules/smartsearch/cron/' . $scriptName . '?token=' . $token;

        $port = $ssl ? 443 : 80;
        $transport = $ssl ? 'ssl://' : '';

        $errno = 0;
        $errstr = '';
        $fp = @fsockopen($transport . $domain, $port, $errno, $errstr, 2);
        if (!$fp) {
            return false;
        }

        $out = 'GET ' . $path . " HTTP/1.1\r\n";
        $out .= 'Host: ' . $domain . "\r\n";
        $out .= "User-Agent: SmartSearch-Scheduler\r\n";
        $out .= "Connection: Close\r\n\r\n";

        @fwrite($fp, $out);
        // Non leggiamo la risposta: chiudiamo subito (fire and forget)
        @stream_set_timeout($fp, 1);
        @fclose($fp);

        return true;
    }

    /**
     * Esegue un ciclo di apprendimento automatico dei sinonimi.
     * Chiamato dal cron learn_synonyms.php (o manualmente dal pannello).
     *
     * @param int|null $idShop
     * @param int|null $idLang
     * @return array ['analyzed' => int, 'candidates' => int, 'auto_applied' => int]
     */
    public function learnSynonyms($idShop = null, $idLang = null)
    {
        $totals = array('analyzed' => 0, 'candidates' => 0, 'auto_applied' => 0);

        $opts = array(
            'min_freq' => (int) Configuration::get('SMARTSEARCH_AUTOLEARN_MIN_FREQ') ?: 3,
            'auto_threshold' => ((int) Configuration::get('SMARTSEARCH_AUTOLEARN_AUTO_THRESHOLD') ?: 85) / 100,
            'min_threshold' => ((int) Configuration::get('SMARTSEARCH_AUTOLEARN_MIN_THRESHOLD') ?: 55) / 100,
        );

        // Determina le coppie shop/lingua da elaborare
        $pairs = array();
        if ($idShop !== null && $idLang !== null) {
            $pairs[] = array((int) $idShop, (int) $idLang);
        } else {
            $shops = Shop::getShops(true);
            $langs = Language::getLanguages(true);
            foreach ($shops as $shop) {
                foreach ($langs as $lang) {
                    $pairs[] = array((int) $shop['id_shop'], (int) $lang['id_lang']);
                }
            }
        }

        foreach ($pairs as $pair) {
            $learner = new SmartSearchLearner($pair[0], $pair[1], $opts);
            $res = $learner->run();
            $totals['analyzed'] += $res['analyzed'];
            $totals['candidates'] += $res['candidates'];
            $totals['auto_applied'] += $res['auto_applied'];
        }

        Configuration::updateValue('SMARTSEARCH_AUTOLEARN_LAST', date('Y-m-d H:i:s'));

        // I sinonimi sono cambiati: invalida la cache dei risultati
        try {
            $this->invalidateCache();
        } catch (Throwable $e) {
            // ignora
        }

        return $totals;
    }

    /**
     * Hook displayHeader per variabili JS
     * OTTIMIZZATO: Config minima inline, il resto via cache statica
     */
    public function hookDisplayHeader($params)
    {
        if (!self::getConfig('enabled')) {
            return '';
        }

        // Non caricare su checkout e conferma ordine
        $excludedControllers = ['order', 'orderopc', 'orderosc', 'orderconfirmation'];
        if (in_array(strtolower(Tools::getValue('controller', '')), $excludedControllers)) {
            return '';
        }

        // Passa SOLO le configurazioni essenziali al JavaScript
        // Usa la cache statica invece di query multiple
        Media::addJsDef([
            'smartsearch_config' => [
                // URLs - essenziali
                'ajax_url' => $this->context->link->getModuleLink($this->name, 'search'),

                // Config essenziali (dalla cache)
                'min_chars' => self::getConfig('min_chars'),
                'debounce_time' => self::getConfig('debounce_time'),
                'highlight' => self::getConfig('highlight'),
                'facets_enabled' => self::getConfig('facets_enabled'),
                'show_bestseller_badge' => self::getConfig('show_bestseller_badge'),

                'shop_id' => (int)$this->context->shop->id,

                // Valuta - minimale
                'currency_sign' => $this->context->currency->sign,

                // Traduzioni essenziali (ridotte al minimo)
                'translations' => [
                    'search_placeholder' => $this->l('Cerca prodotti...'),
                    'no_results' => $this->l('Nessun risultato'),
                    'filters' => $this->l('Filtri'),
                    'price' => $this->l('Prezzo'),
                    'brand' => $this->l('Marca'),
                    'categories' => $this->l('Categorie'),
                    'clear_filters' => $this->l('Rimuovi filtri'),
                    'apply_filters' => $this->l('Applica'),
                    'in_stock' => $this->l('Disponibile'),
                    'featured_products' => $this->l('Prodotti in evidenza'),
                    'products_found' => $this->l('risultati'),
                    'bestseller' => $this->l('Più acquistato'),
                ],

                // Stile personalizzato
                'style' => $this->getStyleConfig(),
            ]
        ]);

        return '';
    }

    /**
     * Hook displaySearch - Sovrascrive la barra di ricerca del tema
     * OTTIMIZZATO: Usa cache config
     */
    public function hookDisplaySearch($params)
    {
        if (!self::getConfig('enabled')) {
            return '';
        }

        $this->context->smarty->assign([
            'smartsearch_placeholder' => $this->l('Cerca prodotti...'),
            'smartsearch_search_url' => $this->context->link->getPageLink('search', true),
            'smartsearch_voice_enabled' => (bool)Configuration::get('SMARTSEARCH_VOICE_ENABLED'),
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
        $idProduct = isset($params['id_product']) ? (int) $params['id_product'] : 0;
        if (!$idProduct && isset($params['product']) && is_object($params['product'])) {
            $idProduct = (int) $params['product']->id;
        }
        if ($idProduct) {
            $this->updateProductIndex($idProduct);
        }
    }

    public function hookActionProductUpdate($params)
    {
        $this->invalidateCache();
        $idProduct = isset($params['id_product']) ? (int) $params['id_product'] : 0;
        if (!$idProduct && isset($params['product']) && is_object($params['product'])) {
            $idProduct = (int) $params['product']->id;
        }
        if ($idProduct) {
            $this->updateProductIndex($idProduct);
        }
    }

    public function hookActionProductDelete($params)
    {
        $this->invalidateCache();
        $idProduct = isset($params['id_product']) ? (int) $params['id_product'] : 0;
        if (!$idProduct && isset($params['product']) && is_object($params['product'])) {
            $idProduct = (int) $params['product']->id;
        }
        if ($idProduct) {
            $this->deleteProductIndex($idProduct);
        }
    }

    /**
     * Invalida la cache
     */
    protected function invalidateCache()
    {
        // Invalida anche la cache statica delle config
        self::$configCache = null;

        if (self::getConfig('cache_enabled')) {
            $cache = new SmartSearchCache(
                $this->context->language->id,
                $this->context->shop->id
            );
            $cache->invalidate();
        }
    }

    // =========================================================================
    // SEARCH INDEX
    // =========================================================================

    /**
     * Aggiorna l'indice di ricerca per un singolo prodotto (tutte le lingue/shop)
     */
    public function updateProductIndex($idProduct)
    {
        try {
            $idProduct = (int) $idProduct;
            $languages = Language::getLanguages(true);
            $shops = Shop::getShops(true);

            foreach ($shops as $shop) {
                foreach ($languages as $lang) {
                    $this->indexProduct($idProduct, (int) $lang['id_lang'], (int) $shop['id_shop']);
                }
            }
        } catch (Throwable $e) {
            // Non bloccare il flusso principale
        }
    }

    /**
     * Rimuove un prodotto dall'indice
     */
    public function deleteProductIndex($idProduct)
    {
        try {
            Db::getInstance()->execute(
                'DELETE FROM `' . _DB_PREFIX_ . 'smartsearch_index` WHERE id_product = ' . (int) $idProduct
            );
        } catch (Throwable $e) {
            // Ignora
        }
    }

    /**
     * Indicizza un singolo prodotto per lingua e shop
     */
    protected function indexProduct($idProduct, $idLang, $idShop)
    {
        $sql = '
            SELECT
                p.id_product,
                pl.name,
                pl.link_rewrite,
                pl.description_short,
                pl.description,
                p.reference,
                p.ean13,
                p.id_category_default,
                p.id_manufacturer,
                p.active,
                p.date_add,
                m.name AS manufacturer_name,
                cl.name AS category_name,
                (SELECT i.id_image FROM ' . _DB_PREFIX_ . 'image i
                 WHERE i.id_product = p.id_product AND i.cover = 1 LIMIT 1) AS id_image,
                COALESCE((SELECT SUM(od.product_quantity)
                 FROM ' . _DB_PREFIX_ . 'order_detail od
                 INNER JOIN ' . _DB_PREFIX_ . 'orders o ON od.id_order = o.id_order AND o.valid = 1
                 WHERE od.product_id = p.id_product), 0) AS sales_count
            FROM ' . _DB_PREFIX_ . 'product p
            INNER JOIN ' . _DB_PREFIX_ . 'product_lang pl ON p.id_product = pl.id_product
                AND pl.id_lang = ' . (int) $idLang . ' AND pl.id_shop = ' . (int) $idShop . '
            INNER JOIN ' . _DB_PREFIX_ . 'product_shop ps ON p.id_product = ps.id_product
                AND ps.id_shop = ' . (int) $idShop . '
            LEFT JOIN ' . _DB_PREFIX_ . 'manufacturer m ON p.id_manufacturer = m.id_manufacturer
            LEFT JOIN ' . _DB_PREFIX_ . 'category_lang cl ON p.id_category_default = cl.id_category
                AND cl.id_lang = ' . (int) $idLang . '
            WHERE p.id_product = ' . (int) $idProduct;

        $product = Db::getInstance()->getRow($sql);

        if (!$product) {
            Db::getInstance()->execute(
                'DELETE FROM `' . _DB_PREFIX_ . 'smartsearch_index`
                 WHERE id_product = ' . (int) $idProduct
                . ' AND id_lang = ' . (int) $idLang
                . ' AND id_shop = ' . (int) $idShop
            );
            return;
        }

        // Campi ad alto peso (nome, brand, reference) — peso doppio nel FULLTEXT
        $nameOnlyContent = implode(' ', array_filter([
            $product['name'],
            $product['manufacturer_name'],
            $product['reference'],
        ]));

        // Tutti i campi ricercabili (inclusa descrizione lunga)
        $searchContent = implode(' ', array_filter([
            $product['name'],
            strip_tags($product['description_short'] ?? ''),
            strip_tags($product['description'] ?? ''),
            $product['reference'],
            $product['manufacturer_name'],
            $product['category_name'],
            $product['ean13'],
        ]));

        $sql = 'REPLACE INTO `' . _DB_PREFIX_ . 'smartsearch_index`
            (id_product, id_lang, id_shop, product_name, name_only_content, search_content, link_rewrite,
             description_short, reference, ean13, id_category_default, category_name,
             id_manufacturer, manufacturer_name, id_image, sales_count, date_add, active, date_indexed)
            VALUES (
                ' . (int) $product['id_product'] . ',
                ' . (int) $idLang . ',
                ' . (int) $idShop . ',
                \'' . pSQL($product['name']) . '\',
                \'' . pSQL($nameOnlyContent) . '\',
                \'' . pSQL($searchContent) . '\',
                \'' . pSQL($product['link_rewrite'] ?? '') . '\',
                \'' . pSQL($product['description_short'] ?? '') . '\',
                ' . ($product['reference'] ? '\'' . pSQL($product['reference']) . '\'' : 'NULL') . ',
                ' . ($product['ean13'] ? '\'' . pSQL($product['ean13']) . '\'' : 'NULL') . ',
                ' . (int) ($product['id_category_default'] ?? 0) . ',
                ' . ($product['category_name'] ? '\'' . pSQL($product['category_name']) . '\'' : 'NULL') . ',
                ' . (int) ($product['id_manufacturer'] ?? 0) . ',
                ' . ($product['manufacturer_name'] ? '\'' . pSQL($product['manufacturer_name']) . '\'' : 'NULL') . ',
                ' . (int) ($product['id_image'] ?? 0) . ',
                ' . (int) ($product['sales_count'] ?? 0) . ',
                ' . ($product['date_add'] ? '\'' . pSQL($product['date_add']) . '\'' : 'NOW()') . ',
                ' . (int) ($product['active'] ?? 0) . ',
                NOW()
            )';

        Db::getInstance()->execute($sql);
    }

    /**
     * Ricostruisce completamente l'indice di ricerca.
     * Usa INSERT ... SELECT per bulk-inserire senza loop PHP.
     *
     * @return bool
     */
    public function rebuildSearchIndex()
    {
        $db = Db::getInstance();
        $liveTable = '`' . _DB_PREFIX_ . 'smartsearch_index`';
        $tmpTable  = '`' . _DB_PREFIX_ . 'smartsearch_index_tmp`';
        $oldTable  = '`' . _DB_PREFIX_ . 'smartsearch_index_old`';

        // 1. Crea tabella temporanea con la stessa struttura
        try {
            $db->execute('DROP TABLE IF EXISTS ' . $tmpTable);
            $db->execute('CREATE TABLE ' . $tmpTable . ' LIKE ' . $liveTable);
        } catch (Throwable $e) {
            return false;
        }

        // 2. Popola la tabella temporanea (la live resta intatta)
        $languages = Language::getLanguages(true);
        $shops = Shop::getShops(true);

        foreach ($shops as $shop) {
            $idShop = (int) $shop['id_shop'];
            foreach ($languages as $lang) {
                $idLang = (int) $lang['id_lang'];

                $sql = '
                    INSERT INTO ' . $tmpTable . '
                    (id_product, id_lang, id_shop, product_name, name_only_content, search_content, link_rewrite,
                     description_short, reference, ean13, id_category_default, category_name,
                     id_manufacturer, manufacturer_name, id_image, sales_count, date_add, active, date_indexed)
                    SELECT
                        p.id_product,
                        ' . $idLang . ',
                        ' . $idShop . ',
                        pl.name,
                        CONCAT_WS(\' \',
                            pl.name,
                            IFNULL(m.name, \'\'),
                            IFNULL(p.reference, \'\')
                        ),
                        CONCAT_WS(\' \',
                            pl.name,
                            REGEXP_REPLACE(IFNULL(pl.description_short, \'\'), \'<[^>]+>\', \' \'),
                            REGEXP_REPLACE(IFNULL(pl.description, \'\'), \'<[^>]+>\', \' \'),
                            IFNULL(p.reference, \'\'),
                            IFNULL(m.name, \'\'),
                            IFNULL(cl.name, \'\'),
                            IFNULL(p.ean13, \'\')
                        ),
                        IFNULL(pl.link_rewrite, \'\'),
                        pl.description_short,
                        p.reference,
                        p.ean13,
                        p.id_category_default,
                        cl.name,
                        p.id_manufacturer,
                        m.name,
                        (SELECT i.id_image FROM ' . _DB_PREFIX_ . 'image i
                         WHERE i.id_product = p.id_product AND i.cover = 1 LIMIT 1),
                        COALESCE((SELECT SUM(od.product_quantity)
                         FROM ' . _DB_PREFIX_ . 'order_detail od
                         INNER JOIN ' . _DB_PREFIX_ . 'orders o ON od.id_order = o.id_order AND o.valid = 1
                         WHERE od.product_id = p.id_product), 0),
                        p.date_add,
                        p.active,
                        NOW()
                    FROM ' . _DB_PREFIX_ . 'product p
                    INNER JOIN ' . _DB_PREFIX_ . 'product_lang pl ON p.id_product = pl.id_product
                        AND pl.id_lang = ' . $idLang . ' AND pl.id_shop = ' . $idShop . '
                    INNER JOIN ' . _DB_PREFIX_ . 'product_shop ps ON p.id_product = ps.id_product
                        AND ps.id_shop = ' . $idShop . '
                    LEFT JOIN ' . _DB_PREFIX_ . 'manufacturer m ON p.id_manufacturer = m.id_manufacturer
                    LEFT JOIN ' . _DB_PREFIX_ . 'category_lang cl ON p.id_category_default = cl.id_category
                        AND cl.id_lang = ' . $idLang . '
                    WHERE ps.active = 1';

                try {
                    $db->execute($sql);
                } catch (Throwable $e) {
                    // Cleanup e abort: la live table non è stata toccata
                    $db->execute('DROP TABLE IF EXISTS ' . $tmpTable);
                    if (defined('_PS_MODE_DEV_') && _PS_MODE_DEV_) {
                        PrestaShopLogger::addLog(
                            'SmartSearch index rebuild error: ' . $e->getMessage(),
                            3, null, 'SmartSearch'
                        );
                    }
                    return false;
                }
            }
        }

        // 3. Swap atomico: tmp → live, vecchia live → old, drop old
        try {
            $db->execute('DROP TABLE IF EXISTS ' . $oldTable);
            $db->execute(
                'RENAME TABLE '
                . $liveTable . ' TO ' . $oldTable . ', '
                . $tmpTable . ' TO ' . $liveTable
            );
            $db->execute('DROP TABLE IF EXISTS ' . $oldTable);
        } catch (Throwable $e) {
            // Fallback: se il RENAME fallisce, pulisci la tmp
            $db->execute('DROP TABLE IF EXISTS ' . $tmpTable);
            $db->execute('DROP TABLE IF EXISTS ' . $oldTable);
            return false;
        }

        // Resetta la cache statica per evitare che isSearchIndexAvailable()
        // resti false nella stessa request (es. rebuild da admin)
        if (class_exists('SmartsearchSearchModuleFrontController')) {
            SmartsearchSearchModuleFrontController::resetSearchIndexCache();
        }

        return true;
    }

    /**
     * Configurazione del modulo nel back-office
     */
    public function getContent()
    {
        $this->runMigrations();

        $output = '';

        if (Tools::isSubmit('submitSmartSearchConfig')) {
            $this->saveConfiguration();
            $output .= $this->displayConfirmation($this->l('Impostazioni salvate con successo'));
        }

        if (Tools::isSubmit('clearCache')) {
            $this->invalidateCache();
            $output .= $this->displayConfirmation($this->l('Cache svuotata con successo'));
        }

        if (Tools::isSubmit('rebuildIndex')) {
            $result = $this->rebuildSearchIndex();
            if ($result) {
                $count = (int) Db::getInstance()->getValue(
                    'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'smartsearch_index`'
                );
                $output .= $this->displayConfirmation(
                    sprintf($this->l('Indice di ricerca ricostruito! %d voci indicizzate.'), $count)
                );
            } else {
                $output .= $this->displayError($this->l('Errore nella ricostruzione dell\'indice.'));
            }
        }

        if (Tools::isSubmit('calculateCorrelations')) {
            $daysBack = (int)Configuration::get('SMARTSEARCH_CORRELATIONS_DAYS') ?: 180;
            $count = $this->calculateProductCorrelations(null, $daysBack);
            Configuration::updateValue('SMARTSEARCH_CORRELATIONS_LAST_UPDATE', date('Y-m-d H:i:s'));
            $output .= $this->displayConfirmation(
                sprintf($this->l('Correlazioni calcolate con successo! %d correlazioni create/aggiornate.'), $count)
            );
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
            'SMARTSEARCH_SHOW_STOCK', 'SMARTSEARCH_SHOW_BESTSELLER',
            'SMARTSEARCH_HIGHLIGHT', 'SMARTSEARCH_FUZZY_ENABLED',
            'SMARTSEARCH_FUZZY_THRESHOLD', 'SMARTSEARCH_PHONETIC_ENABLED', 'SMARTSEARCH_STEMMING_ENABLED',
            'SMARTSEARCH_SYNONYMS_ENABLED', 'SMARTSEARCH_FACETS_ENABLED', 'SMARTSEARCH_FACETS_CATEGORIES',
            'SMARTSEARCH_FACETS_PRICE', 'SMARTSEARCH_FACETS_MANUFACTURER', 'SMARTSEARCH_FACETS_ATTRIBUTES',
            'SMARTSEARCH_CACHE_ENABLED', 'SMARTSEARCH_CACHE_TTL',
            'SMARTSEARCH_VOICE_ENABLED', 'SMARTSEARCH_BANNERS_ENABLED',
            'SMARTSEARCH_CORRELATIONS_ENABLED', 'SMARTSEARCH_CORRELATIONS_PRODUCT_ENABLED',
            'SMARTSEARCH_CORRELATIONS_CART_ENABLED', 'SMARTSEARCH_CORRELATIONS_DAYS', 'SMARTSEARCH_CORRELATIONS_MIN_PURCHASES'
        ];

        foreach ($configs as $config) {
            Configuration::updateValue($config, (int)Tools::getValue($config));
        }

        $this->invalidateCache();
    }

    /**
     * Ottiene statistiche sulle correlazioni
     */
    protected function getCorrelationStats()
    {
        $idShop = (int)$this->context->shop->id;

        $stats = [
            'total_correlations' => 0,
            'total_products' => 0,
            'last_update' => Configuration::get('SMARTSEARCH_CORRELATIONS_LAST_UPDATE') ?: null,
            'avg_score' => 0
        ];

        // Conta correlazioni totali
        $sql = 'SELECT COUNT(*) FROM ' . _DB_PREFIX_ . 'smartsearch_correlations WHERE id_shop = ' . $idShop;
        $stats['total_correlations'] = (int)Db::getInstance()->getValue($sql);

        // Conta prodotti con correlazioni
        $sql = 'SELECT COUNT(DISTINCT id_product_source) FROM ' . _DB_PREFIX_ . 'smartsearch_correlations WHERE id_shop = ' . $idShop;
        $stats['total_products'] = (int)Db::getInstance()->getValue($sql);

        // Score medio
        if ($stats['total_correlations'] > 0) {
            $sql = 'SELECT AVG(correlation_score) FROM ' . _DB_PREFIX_ . 'smartsearch_correlations WHERE id_shop = ' . $idShop;
            $stats['avg_score'] = round((float)Db::getInstance()->getValue($sql), 4);
        }

        return $stats;
    }

    /**
     * Genera HTML per mostrare le statistiche correlazioni
     */
    protected function getCorrelationStatsHtml($stats)
    {
        $lastUpdate = $stats['last_update']
            ? date('d/m/Y H:i', strtotime($stats['last_update']))
            : $this->l('Mai');

        $statusColor = $stats['total_correlations'] > 0 ? '#059669' : '#dc2626';
        $statusText = $stats['total_correlations'] > 0
            ? $this->l('Attivo')
            : $this->l('Nessuna correlazione - Clicca "Calcola Correlazioni Ora"');

        return '
        <div style="background: #f8fafc; border-radius: 8px; padding: 15px; margin-bottom: 20px;">
            <div style="display: flex; gap: 30px; flex-wrap: wrap;">
                <div>
                    <strong style="color: #64748b; font-size: 12px; text-transform: uppercase;">' . $this->l('Stato') . '</strong>
                    <div style="font-size: 16px; color: ' . $statusColor . '; font-weight: 600;">' . $statusText . '</div>
                </div>
                <div>
                    <strong style="color: #64748b; font-size: 12px; text-transform: uppercase;">' . $this->l('Correlazioni') . '</strong>
                    <div style="font-size: 24px; font-weight: 700; color: #1e293b;">' . number_format($stats['total_correlations'], 0, ',', '.') . '</div>
                </div>
                <div>
                    <strong style="color: #64748b; font-size: 12px; text-transform: uppercase;">' . $this->l('Prodotti collegati') . '</strong>
                    <div style="font-size: 24px; font-weight: 700; color: #1e293b;">' . number_format($stats['total_products'], 0, ',', '.') . '</div>
                </div>
                <div>
                    <strong style="color: #64748b; font-size: 12px; text-transform: uppercase;">' . $this->l('Ultimo aggiornamento') . '</strong>
                    <div style="font-size: 16px; color: #1e293b;">' . $lastUpdate . '</div>
                </div>
            </div>
            <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #e2e8f0; color: #64748b; font-size: 13px;">
                <strong>' . $this->l('Come funziona:') . '</strong> ' .
                $this->l('Le correlazioni analizzano gli ordini per trovare prodotti acquistati insieme. Esegui il calcolo periodicamente (consigliato: settimanale) per mantenere i suggerimenti aggiornati.') . '
            </div>
        </div>';
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
                    ['type' => 'switch', 'label' => $this->l('Mostra badge "Più acquistato"'), 'name' => 'SMARTSEARCH_SHOW_BESTSELLER', 'is_bool' => true,
                     'desc' => $this->l('Mostra un badge rosso sui prodotti più venduti'),
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
                'buttons' => [
                    ['title' => $this->l('Svuota Cache'), 'name' => 'clearCache', 'type' => 'submit', 'class' => 'btn btn-default', 'icon' => 'process-icon-eraser'],
                    ['title' => $this->l('Ricostruisci Indice'), 'name' => 'rebuildIndex', 'type' => 'submit', 'class' => 'btn btn-default', 'icon' => 'process-icon-refresh'],
                ]
            ]
        ];

        // Form Extra
        $fields_form[5] = [
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

        // Form Correlazioni/Raccomandazioni
        $correlationStats = $this->getCorrelationStats();
        $fields_form[6] = [
            'form' => [
                'legend' => ['title' => $this->l('Prodotti Consigliati (Correlazioni)'), 'icon' => 'icon-link'],
                'description' => $this->getCorrelationStatsHtml($correlationStats),
                'input' => [
                    ['type' => 'switch', 'label' => $this->l('Abilita Raccomandazioni'), 'name' => 'SMARTSEARCH_CORRELATIONS_ENABLED', 'is_bool' => true,
                     'desc' => $this->l('Abilita il sistema di raccomandazioni prodotti basato sulle correlazioni d\'acquisto'),
                     'values' => [['id' => 'on', 'value' => 1, 'label' => $this->l('Sì')], ['id' => 'off', 'value' => 0, 'label' => $this->l('No')]]],
                    ['type' => 'switch', 'label' => $this->l('Mostra in Pagina Prodotto'), 'name' => 'SMARTSEARCH_CORRELATIONS_PRODUCT_ENABLED', 'is_bool' => true,
                     'desc' => $this->l('Mostra lo slider "Chi ha acquistato questo ha comprato anche" nella pagina prodotto'),
                     'values' => [['id' => 'on', 'value' => 1, 'label' => $this->l('Sì')], ['id' => 'off', 'value' => 0, 'label' => $this->l('No')]]],
                    ['type' => 'switch', 'label' => $this->l('Mostra nel Carrello'), 'name' => 'SMARTSEARCH_CORRELATIONS_CART_ENABLED', 'is_bool' => true,
                     'desc' => $this->l('Mostra lo slider "Completa il tuo ordine" nella pagina carrello'),
                     'values' => [['id' => 'on', 'value' => 1, 'label' => $this->l('Sì')], ['id' => 'off', 'value' => 0, 'label' => $this->l('No')]]],
                    ['type' => 'text', 'label' => $this->l('Periodo analisi (giorni)'), 'name' => 'SMARTSEARCH_CORRELATIONS_DAYS', 'class' => 'fixed-width-sm',
                     'desc' => $this->l('Numero di giorni di storico ordini da analizzare (consigliato: 180)')],
                    ['type' => 'text', 'label' => $this->l('Acquisti minimi'), 'name' => 'SMARTSEARCH_CORRELATIONS_MIN_PURCHASES', 'class' => 'fixed-width-sm',
                     'desc' => $this->l('Numero minimo di acquisti congiunti per creare una correlazione (consigliato: 2)')],
                ],
                'buttons' => [
                    [
                        'title' => $this->l('Calcola Correlazioni Ora'),
                        'name' => 'calculateCorrelations',
                        'type' => 'submit',
                        'class' => 'btn btn-primary',
                        'icon' => 'process-icon-refresh'
                    ]
                ]
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
            'SMARTSEARCH_SHOW_BESTSELLER' => Configuration::get('SMARTSEARCH_SHOW_BESTSELLER'),
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
            'SMARTSEARCH_VOICE_ENABLED' => Configuration::get('SMARTSEARCH_VOICE_ENABLED'),
            'SMARTSEARCH_BANNERS_ENABLED' => Configuration::get('SMARTSEARCH_BANNERS_ENABLED'),
            'SMARTSEARCH_CORRELATIONS_ENABLED' => Configuration::get('SMARTSEARCH_CORRELATIONS_ENABLED'),
            'SMARTSEARCH_CORRELATIONS_PRODUCT_ENABLED' => Configuration::get('SMARTSEARCH_CORRELATIONS_PRODUCT_ENABLED'),
            'SMARTSEARCH_CORRELATIONS_CART_ENABLED' => Configuration::get('SMARTSEARCH_CORRELATIONS_CART_ENABLED'),
            'SMARTSEARCH_CORRELATIONS_DAYS' => Configuration::get('SMARTSEARCH_CORRELATIONS_DAYS') ?: 180,
            'SMARTSEARCH_CORRELATIONS_MIN_PURCHASES' => Configuration::get('SMARTSEARCH_CORRELATIONS_MIN_PURCHASES') ?: 2,
        ];

        return $helper->generateForm($fields_form);
    }

    /**
     * Calcola e aggiorna le correlazioni tra prodotti basandosi sugli ordini
     * Chiamare periodicamente via cron o manualmente dal backoffice
     *
     * @param int $idShop ID del negozio
     * @param int $daysBack Numero di giorni da analizzare (default 180)
     * @return int Numero di correlazioni create/aggiornate
     */
    public function calculateProductCorrelations($idShop = null, $daysBack = 180)
    {
        if ($idShop === null) {
            $idShop = (int)$this->context->shop->id;
        }

        $db = Db::getInstance();
        $dateLimit = date('Y-m-d H:i:s', strtotime("-{$daysBack} days"));

        // Query per trovare prodotti comprati insieme nello stesso ordine
        // Conta quante volte ogni coppia di prodotti appare negli stessi ordini
        $sql = '
            SELECT
                od1.product_id as product_source,
                od2.product_id as product_target,
                COUNT(DISTINCT od1.id_order) as purchase_count
            FROM ' . _DB_PREFIX_ . 'order_detail od1
            INNER JOIN ' . _DB_PREFIX_ . 'order_detail od2
                ON od1.id_order = od2.id_order
                AND od1.product_id < od2.product_id
            INNER JOIN ' . _DB_PREFIX_ . 'orders o
                ON od1.id_order = o.id_order
            WHERE o.valid = 1
                AND o.id_shop = ' . (int)$idShop . '
                AND o.date_add >= "' . pSQL($dateLimit) . '"
            GROUP BY od1.product_id, od2.product_id
            HAVING purchase_count >= 2
            ORDER BY purchase_count DESC
            LIMIT 10000';

        $correlations = $db->executeS($sql);

        if (!$correlations) {
            return 0;
        }

        // Trova il massimo per normalizzare gli score
        $maxCount = 1;
        foreach ($correlations as $corr) {
            if ($corr['purchase_count'] > $maxCount) {
                $maxCount = $corr['purchase_count'];
            }
        }

        $count = 0;
        $now = date('Y-m-d H:i:s');

        foreach ($correlations as $corr) {
            // Normalizza score tra 0 e 1
            $score = round($corr['purchase_count'] / $maxCount, 4);
            $purchaseCount = (int)$corr['purchase_count'];
            $productSource = (int)$corr['product_source'];
            $productTarget = (int)$corr['product_target'];

            // Inserisci/aggiorna in entrambe le direzioni (A->B e B->A)
            $sqlInsert = '
                INSERT INTO ' . _DB_PREFIX_ . 'smartsearch_correlations
                    (id_product_source, id_product_target, correlation_score, purchase_count, id_shop, date_upd)
                VALUES
                    (' . $productSource . ', ' . $productTarget . ', ' . $score . ', ' . $purchaseCount . ', ' . (int)$idShop . ', "' . $now . '"),
                    (' . $productTarget . ', ' . $productSource . ', ' . $score . ', ' . $purchaseCount . ', ' . (int)$idShop . ', "' . $now . '")
                ON DUPLICATE KEY UPDATE
                    correlation_score = VALUES(correlation_score),
                    purchase_count = VALUES(purchase_count),
                    date_upd = VALUES(date_upd)';

            $db->execute($sqlInsert);
            $count += 2;
        }

        return $count;
    }

    /**
     * Ottiene i prodotti correlati/consigliati per un prodotto
     *
     * @param int $idProduct ID del prodotto
     * @param int $limit Numero massimo di risultati
     * @return array Lista di prodotti consigliati
     */
    public function getCorrelatedProducts($idProduct, $limit = 8)
    {
        $idLang = (int)$this->context->language->id;
        $idShop = (int)$this->context->shop->id;

        $sql = '
            SELECT
                p.id_product,
                pl.name,
                pl.link_rewrite,
                pl.description_short,
                p.id_manufacturer,
                m.name as manufacturer_name,
                c.correlation_score,
                c.purchase_count,
                (SELECT id_image FROM ' . _DB_PREFIX_ . 'image i WHERE i.id_product = p.id_product AND i.cover = 1 LIMIT 1) as id_image
            FROM ' . _DB_PREFIX_ . 'smartsearch_correlations c
            INNER JOIN ' . _DB_PREFIX_ . 'product p ON c.id_product_target = p.id_product
            INNER JOIN ' . _DB_PREFIX_ . 'product_lang pl ON p.id_product = pl.id_product
                AND pl.id_lang = ' . (int)$idLang . ' AND pl.id_shop = ' . (int)$idShop . '
            INNER JOIN ' . _DB_PREFIX_ . 'product_shop ps ON p.id_product = ps.id_product
                AND ps.id_shop = ' . (int)$idShop . '
            LEFT JOIN ' . _DB_PREFIX_ . 'manufacturer m ON p.id_manufacturer = m.id_manufacturer
            WHERE c.id_product_source = ' . (int)$idProduct . '
                AND c.id_shop = ' . (int)$idShop . '
                AND p.active = 1
                AND ps.active = 1
            ORDER BY c.correlation_score DESC, c.purchase_count DESC
            LIMIT ' . (int)$limit;

        $results = Db::getInstance()->executeS($sql);

        if (!$results) {
            // Fallback: prodotti della stessa categoria
            return $this->getFallbackRecommendations($idProduct, $limit);
        }

        return $this->formatRecommendedProducts($results);
    }

    /**
     * Ottiene prodotti correlati per multipli prodotti (per il carrello)
     *
     * @param array $productIds Array di ID prodotti
     * @param int $limit Numero massimo di risultati
     * @return array Lista di prodotti consigliati
     */
    public function getCorrelatedProductsForCart($productIds, $limit = 8)
    {
        if (empty($productIds)) {
            return [];
        }

        $idLang = (int)$this->context->language->id;
        $idShop = (int)$this->context->shop->id;
        $productIdsStr = implode(',', array_map('intval', $productIds));

        // Query compatibile con MySQL 8.0+ (ONLY_FULL_GROUP_BY)
        $sql = '
            SELECT
                p.id_product,
                pl.name,
                pl.link_rewrite,
                pl.description_short,
                p.id_manufacturer,
                m.name as manufacturer_name,
                scores.total_score,
                scores.total_purchases,
                (SELECT id_image FROM ' . _DB_PREFIX_ . 'image i WHERE i.id_product = p.id_product AND i.cover = 1 LIMIT 1) as id_image
            FROM ' . _DB_PREFIX_ . 'product p
            INNER JOIN (
                SELECT c.id_product_target, SUM(c.correlation_score) as total_score, SUM(c.purchase_count) as total_purchases
                FROM ' . _DB_PREFIX_ . 'smartsearch_correlations c
                WHERE c.id_product_source IN (' . $productIdsStr . ')
                    AND c.id_product_target NOT IN (' . $productIdsStr . ')
                    AND c.id_shop = ' . (int)$idShop . '
                GROUP BY c.id_product_target
            ) scores ON scores.id_product_target = p.id_product
            INNER JOIN ' . _DB_PREFIX_ . 'product_lang pl ON p.id_product = pl.id_product
                AND pl.id_lang = ' . (int)$idLang . ' AND pl.id_shop = ' . (int)$idShop . '
            INNER JOIN ' . _DB_PREFIX_ . 'product_shop ps ON p.id_product = ps.id_product
                AND ps.id_shop = ' . (int)$idShop . '
            LEFT JOIN ' . _DB_PREFIX_ . 'manufacturer m ON p.id_manufacturer = m.id_manufacturer
            WHERE p.active = 1
                AND ps.active = 1
            ORDER BY scores.total_score DESC, scores.total_purchases DESC
            LIMIT ' . (int)$limit;

        $results = Db::getInstance()->executeS($sql);

        if (!$results || count($results) < 4) {
            // Fallback: bestseller se pochi risultati
            return $this->getBestsellerRecommendations($productIds, $limit);
        }

        return $this->formatRecommendedProducts($results);
    }

    /**
     * Fallback: prodotti della stessa categoria
     */
    protected function getFallbackRecommendations($idProduct, $limit = 8)
    {
        $idLang = (int)$this->context->language->id;
        $idShop = (int)$this->context->shop->id;

        // Ottieni categoria del prodotto
        $idCategory = (int)Db::getInstance()->getValue('
            SELECT id_category_default FROM ' . _DB_PREFIX_ . 'product WHERE id_product = ' . (int)$idProduct
        );

        if (!$idCategory) {
            return [];
        }

        $sql = '
            SELECT
                p.id_product,
                pl.name,
                pl.link_rewrite,
                pl.description_short,
                p.id_manufacturer,
                m.name as manufacturer_name,
                (SELECT id_image FROM ' . _DB_PREFIX_ . 'image i WHERE i.id_product = p.id_product AND i.cover = 1 LIMIT 1) as id_image
            FROM ' . _DB_PREFIX_ . 'product p
            INNER JOIN ' . _DB_PREFIX_ . 'product_lang pl ON p.id_product = pl.id_product
                AND pl.id_lang = ' . (int)$idLang . ' AND pl.id_shop = ' . (int)$idShop . '
            INNER JOIN ' . _DB_PREFIX_ . 'product_shop ps ON p.id_product = ps.id_product
                AND ps.id_shop = ' . (int)$idShop . '
            LEFT JOIN ' . _DB_PREFIX_ . 'manufacturer m ON p.id_manufacturer = m.id_manufacturer
            WHERE p.id_category_default = ' . (int)$idCategory . '
                AND p.id_product != ' . (int)$idProduct . '
                AND p.active = 1
                AND ps.active = 1
            ORDER BY RAND()
            LIMIT ' . (int)$limit;

        $results = Db::getInstance()->executeS($sql);
        return $results ? $this->formatRecommendedProducts($results) : [];
    }

    /**
     * Fallback: prodotti bestseller (escludendo quelli nel carrello)
     */
    protected function getBestsellerRecommendations($excludeIds, $limit = 8)
    {
        $idLang = (int)$this->context->language->id;
        $idShop = (int)$this->context->shop->id;
        $excludeStr = implode(',', array_map('intval', $excludeIds));

        // Query compatibile con MySQL 8.0+ (ONLY_FULL_GROUP_BY)
        $sql = '
            SELECT
                p.id_product,
                pl.name,
                pl.link_rewrite,
                pl.description_short,
                p.id_manufacturer,
                m.name as manufacturer_name,
                (SELECT id_image FROM ' . _DB_PREFIX_ . 'image i WHERE i.id_product = p.id_product AND i.cover = 1 LIMIT 1) as id_image,
                IFNULL(sales.total_sold, 0) as total_sold
            FROM ' . _DB_PREFIX_ . 'product p
            INNER JOIN ' . _DB_PREFIX_ . 'product_lang pl ON p.id_product = pl.id_product
                AND pl.id_lang = ' . (int)$idLang . ' AND pl.id_shop = ' . (int)$idShop . '
            INNER JOIN ' . _DB_PREFIX_ . 'product_shop ps ON p.id_product = ps.id_product
                AND ps.id_shop = ' . (int)$idShop . '
            LEFT JOIN ' . _DB_PREFIX_ . 'manufacturer m ON p.id_manufacturer = m.id_manufacturer
            LEFT JOIN (
                SELECT od.product_id, SUM(od.product_quantity) as total_sold
                FROM ' . _DB_PREFIX_ . 'order_detail od
                INNER JOIN ' . _DB_PREFIX_ . 'orders o ON o.id_order = od.id_order AND o.valid = 1
                GROUP BY od.product_id
            ) sales ON sales.product_id = p.id_product
            WHERE p.id_product NOT IN (' . $excludeStr . ')
                AND p.active = 1
                AND ps.active = 1
            ORDER BY total_sold DESC
            LIMIT ' . (int)$limit;

        $results = Db::getInstance()->executeS($sql);
        return $results ? $this->formatRecommendedProducts($results) : [];
    }

    /**
     * Formatta i prodotti consigliati per il frontend
     */
    protected function formatRecommendedProducts($products)
    {
        $idLang = (int)$this->context->language->id;
        $formatted = [];

        foreach ($products as $row) {
            $priceDisplay = Product::getPriceStatic($row['id_product'], true);
            $priceOldDisplay = Product::getPriceStatic($row['id_product'], true, null, 6, null, false, false);
            $quantity = StockAvailable::getQuantityAvailableByProduct($row['id_product']);

            $imageUrl = '';
            if (!empty($row['id_image'])) {
                $imageUrl = $this->context->link->getImageLink(
                    $row['link_rewrite'],
                    $row['id_image'],
                    ImageType::getFormattedName('home')
                );
            }

            $hasDiscount = ($priceOldDisplay > $priceDisplay);
            $formatted[] = [
                'id' => (int)$row['id_product'],
                'name' => $row['name'],
                'url' => $this->context->link->getProductLink($row['id_product'], $row['link_rewrite'], null, null, $idLang),
                'image' => $imageUrl,
                'price' => Tools::displayPrice($priceDisplay),
                'price_raw' => $priceDisplay,
                'price_old' => $hasDiscount ? Tools::displayPrice($priceOldDisplay) : '',
                'price_old_raw' => $hasDiscount ? $priceOldDisplay : 0,
                'manufacturer' => $row['manufacturer_name'] ?? '',
                'in_stock' => $quantity > 0,
                'quantity' => $quantity
            ];
        }

        return $formatted;
    }

    /**
     * Hook: Mostra slider prodotti consigliati nella pagina prodotto
     * Fail-safe: non blocca mai la pagina prodotto in caso di errori
     */
    public function hookDisplayFooterProduct($params)
    {
        try {
            if (!self::getConfig('enabled')) {
                return '';
            }

            // Verifica se le correlazioni sono abilitate (generale e pagina prodotto)
            if (!Configuration::get('SMARTSEARCH_CORRELATIONS_ENABLED')) {
                return '';
            }
            if (!Configuration::get('SMARTSEARCH_CORRELATIONS_PRODUCT_ENABLED')) {
                return '';
            }

            $idProduct = (int)Tools::getValue('id_product');
            if (!$idProduct && isset($params['product'])) {
                $idProduct = (int)$params['product']['id_product'];
            }

            if (!$idProduct) {
                return '';
            }

            $recommendations = $this->getCorrelatedProducts($idProduct, 8);

            if (empty($recommendations)) {
                return '';
            }

            $this->context->smarty->assign([
                'smartsearch_recommendations' => $recommendations,
                'smartsearch_rec_title' => $this->l('Chi ha acquistato questo prodotto ha comprato anche'),
                'smartsearch_rec_type' => 'product'
            ]);

            return $this->display(__FILE__, 'views/templates/hook/recommendations.tpl');
        } catch (Throwable $e) {
            // Log silenzioso - non bloccare mai la pagina prodotto
            if (defined('_PS_MODE_DEV_') && _PS_MODE_DEV_) {
                PrestaShopLogger::addLog(
                    'SmartSearch recommendations error (product): ' . $e->getMessage(),
                    2, null, 'SmartSearch'
                );
            }
            return '';
        }
    }

    /**
     * Hook: Mostra slider prodotti consigliati nel carrello
     * Fail-safe: non blocca mai il carrello in caso di errori
     */
    public function hookDisplayShoppingCartFooter($params)
    {
        try {
            if (!self::getConfig('enabled')) {
                return '';
            }

            // Verifica se le correlazioni sono abilitate (generale e carrello)
            if (!Configuration::get('SMARTSEARCH_CORRELATIONS_ENABLED')) {
                return '';
            }
            if (!Configuration::get('SMARTSEARCH_CORRELATIONS_CART_ENABLED')) {
                return '';
            }

            // Ottieni prodotti nel carrello
            $cart = $this->context->cart;
            if (!$cart || !$cart->id) {
                return '';
            }

            $cartProducts = $cart->getProducts();
            if (empty($cartProducts)) {
                return '';
            }

            $productIds = array_column($cartProducts, 'id_product');
            $recommendations = $this->getCorrelatedProductsForCart($productIds, 8);

            if (empty($recommendations)) {
                return '';
            }

            $this->context->smarty->assign([
                'smartsearch_recommendations' => $recommendations,
                'smartsearch_rec_title' => $this->l('Completa il tuo ordine'),
                'smartsearch_rec_type' => 'cart'
            ]);

            return $this->display(__FILE__, 'views/templates/hook/recommendations.tpl');
        } catch (Throwable $e) {
            // Log silenzioso - non bloccare mai il carrello
            if (defined('_PS_MODE_DEV_') && _PS_MODE_DEV_) {
                PrestaShopLogger::addLog(
                    'SmartSearch recommendations error (cart): ' . $e->getMessage(),
                    2, null, 'SmartSearch'
                );
            }
            return '';
        }
    }
}
