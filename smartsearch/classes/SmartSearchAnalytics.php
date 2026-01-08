<?php
/**
 * SmartSearch 2.0 - Analytics avanzate
 *
 * Traccia e analizza il comportamento di ricerca degli utenti
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SmartSearchAnalytics
{
    /** @var int */
    protected $idShop;

    /** @var int */
    protected $idLang;

    /**
     * Costruttore
     */
    public function __construct($idLang, $idShop)
    {
        $this->idLang = (int)$idLang;
        $this->idShop = (int)$idShop;
    }

    /**
     * Traccia una ricerca
     */
    public function trackSearch($query, $resultsCount, $filters = [], $customerId = null)
    {
        $sql = 'INSERT INTO `' . _DB_PREFIX_ . 'smartsearch_stats`
                (search_query, results_count, filters_used, id_customer, id_lang, id_shop, session_id, date_add)
                VALUES (
                    \'' . pSQL($query) . '\',
                    ' . (int)$resultsCount . ',
                    \'' . pSQL(json_encode($filters)) . '\',
                    ' . ($customerId ? (int)$customerId : 'NULL') . ',
                    ' . $this->idLang . ',
                    ' . $this->idShop . ',
                    \'' . pSQL(session_id()) . '\',
                    NOW()
                )';

        return Db::getInstance()->execute($sql);
    }

    /**
     * Traccia un click su un prodotto
     */
    public function trackProductClick($query, $productId, $position, $customerId = null)
    {
        $sql = 'INSERT INTO `' . _DB_PREFIX_ . 'smartsearch_clicks`
                (search_query, id_product, position, id_customer, id_lang, id_shop, session_id, date_add)
                VALUES (
                    \'' . pSQL($query) . '\',
                    ' . (int)$productId . ',
                    ' . (int)$position . ',
                    ' . ($customerId ? (int)$customerId : 'NULL') . ',
                    ' . $this->idLang . ',
                    ' . $this->idShop . ',
                    \'' . pSQL(session_id()) . '\',
                    NOW()
                )';

        return Db::getInstance()->execute($sql);
    }

    /**
     * Traccia una conversione (acquisto dopo ricerca)
     */
    public function trackConversion($query, $productId, $orderId, $amount)
    {
        $sql = 'INSERT INTO `' . _DB_PREFIX_ . 'smartsearch_conversions`
                (search_query, id_product, id_order, amount, id_lang, id_shop, date_add)
                VALUES (
                    \'' . pSQL($query) . '\',
                    ' . (int)$productId . ',
                    ' . (int)$orderId . ',
                    ' . (float)$amount . ',
                    ' . $this->idLang . ',
                    ' . $this->idShop . ',
                    NOW()
                )';

        return Db::getInstance()->execute($sql);
    }

    /**
     * Ottieni ricerche più popolari
     */
    public function getTopSearches($limit = 20, $dateFrom = null, $dateTo = null)
    {
        $dateCondition = '';
        if ($dateFrom) {
            $dateCondition .= ' AND date_add >= \'' . pSQL($dateFrom) . '\'';
        }
        if ($dateTo) {
            $dateCondition .= ' AND date_add <= \'' . pSQL($dateTo) . '\'';
        }

        $sql = '
            SELECT
                search_query,
                COUNT(*) AS search_count,
                AVG(results_count) AS avg_results,
                COUNT(DISTINCT session_id) AS unique_users
            FROM `' . _DB_PREFIX_ . 'smartsearch_stats`
            WHERE id_shop = ' . $this->idShop . '
            AND id_lang = ' . $this->idLang . '
            ' . $dateCondition . '
            GROUP BY search_query
            ORDER BY search_count DESC
            LIMIT ' . (int)$limit;

        return Db::getInstance()->executeS($sql) ?: [];
    }

    /**
     * Ottieni ricerche senza risultati
     */
    public function getZeroResultSearches($limit = 20, $dateFrom = null, $dateTo = null)
    {
        $dateCondition = '';
        if ($dateFrom) {
            $dateCondition .= ' AND date_add >= \'' . pSQL($dateFrom) . '\'';
        }
        if ($dateTo) {
            $dateCondition .= ' AND date_add <= \'' . pSQL($dateTo) . '\'';
        }

        $sql = '
            SELECT
                search_query,
                COUNT(*) AS search_count,
                COUNT(DISTINCT session_id) AS unique_users
            FROM `' . _DB_PREFIX_ . 'smartsearch_stats`
            WHERE id_shop = ' . $this->idShop . '
            AND id_lang = ' . $this->idLang . '
            AND results_count = 0
            ' . $dateCondition . '
            GROUP BY search_query
            ORDER BY search_count DESC
            LIMIT ' . (int)$limit;

        return Db::getInstance()->executeS($sql) ?: [];
    }

    /**
     * Ottieni prodotti più cliccati dalla ricerca
     */
    public function getTopClickedProducts($limit = 20, $dateFrom = null, $dateTo = null)
    {
        $dateCondition = '';
        if ($dateFrom) {
            $dateCondition .= ' AND c.date_add >= \'' . pSQL($dateFrom) . '\'';
        }
        if ($dateTo) {
            $dateCondition .= ' AND c.date_add <= \'' . pSQL($dateTo) . '\'';
        }

        $sql = '
            SELECT
                c.id_product,
                pl.name AS product_name,
                COUNT(*) AS click_count,
                AVG(c.position) AS avg_position
            FROM `' . _DB_PREFIX_ . 'smartsearch_clicks` c
            INNER JOIN `' . _DB_PREFIX_ . 'product_lang` pl
                ON c.id_product = pl.id_product AND pl.id_lang = ' . $this->idLang . '
            WHERE c.id_shop = ' . $this->idShop . '
            ' . $dateCondition . '
            GROUP BY c.id_product
            ORDER BY click_count DESC
            LIMIT ' . (int)$limit;

        return Db::getInstance()->executeS($sql) ?: [];
    }

    /**
     * Calcola CTR (Click-Through Rate) per query
     */
    public function getSearchCTR($dateFrom = null, $dateTo = null)
    {
        $dateCondition = '';
        if ($dateFrom) {
            $dateCondition .= ' AND s.date_add >= \'' . pSQL($dateFrom) . '\'';
        }
        if ($dateTo) {
            $dateCondition .= ' AND s.date_add <= \'' . pSQL($dateTo) . '\'';
        }

        $sql = '
            SELECT
                s.search_query,
                COUNT(DISTINCT s.id_smartsearch_stat) AS searches,
                COUNT(DISTINCT c.id_smartsearch_click) AS clicks,
                (COUNT(DISTINCT c.id_smartsearch_click) / COUNT(DISTINCT s.id_smartsearch_stat) * 100) AS ctr
            FROM `' . _DB_PREFIX_ . 'smartsearch_stats` s
            LEFT JOIN `' . _DB_PREFIX_ . 'smartsearch_clicks` c
                ON s.search_query = c.search_query
                AND DATE(s.date_add) = DATE(c.date_add)
            WHERE s.id_shop = ' . $this->idShop . '
            AND s.results_count > 0
            ' . $dateCondition . '
            GROUP BY s.search_query
            HAVING searches >= 10
            ORDER BY ctr DESC
            LIMIT 50';

        return Db::getInstance()->executeS($sql) ?: [];
    }

    /**
     * Calcola tasso di conversione per query
     */
    public function getSearchConversionRate($dateFrom = null, $dateTo = null)
    {
        $dateCondition = '';
        if ($dateFrom) {
            $dateCondition .= ' AND s.date_add >= \'' . pSQL($dateFrom) . '\'';
        }
        if ($dateTo) {
            $dateCondition .= ' AND s.date_add <= \'' . pSQL($dateTo) . '\'';
        }

        $sql = '
            SELECT
                s.search_query,
                COUNT(DISTINCT s.id_smartsearch_stat) AS searches,
                COUNT(DISTINCT c.id_smartsearch_conversion) AS conversions,
                SUM(c.amount) AS revenue,
                (COUNT(DISTINCT c.id_smartsearch_conversion) / COUNT(DISTINCT s.id_smartsearch_stat) * 100) AS conversion_rate
            FROM `' . _DB_PREFIX_ . 'smartsearch_stats` s
            LEFT JOIN `' . _DB_PREFIX_ . 'smartsearch_conversions` c
                ON s.search_query = c.search_query
            WHERE s.id_shop = ' . $this->idShop . '
            ' . $dateCondition . '
            GROUP BY s.search_query
            HAVING searches >= 10
            ORDER BY conversion_rate DESC
            LIMIT 50';

        return Db::getInstance()->executeS($sql) ?: [];
    }

    /**
     * Ottieni statistiche generali
     */
    public function getDashboardStats($dateFrom = null, $dateTo = null)
    {
        $dateCondition = '';
        if ($dateFrom) {
            $dateCondition .= ' AND date_add >= \'' . pSQL($dateFrom) . '\'';
        }
        if ($dateTo) {
            $dateCondition .= ' AND date_add <= \'' . pSQL($dateTo) . '\'';
        }

        // Totale ricerche
        $totalSearches = Db::getInstance()->getValue('
            SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'smartsearch_stats`
            WHERE id_shop = ' . $this->idShop . $dateCondition
        );

        // Ricerche uniche
        $uniqueSearches = Db::getInstance()->getValue('
            SELECT COUNT(DISTINCT search_query) FROM `' . _DB_PREFIX_ . 'smartsearch_stats`
            WHERE id_shop = ' . $this->idShop . $dateCondition
        );

        // Ricerche senza risultati
        $zeroResults = Db::getInstance()->getValue('
            SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'smartsearch_stats`
            WHERE id_shop = ' . $this->idShop . '
            AND results_count = 0' . $dateCondition
        );

        // Totale click
        $totalClicks = Db::getInstance()->getValue('
            SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'smartsearch_clicks`
            WHERE id_shop = ' . $this->idShop . $dateCondition
        );

        // Totale conversioni
        $totalConversions = Db::getInstance()->getValue('
            SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'smartsearch_conversions`
            WHERE id_shop = ' . $this->idShop . $dateCondition
        );

        // Revenue da ricerche
        $searchRevenue = Db::getInstance()->getValue('
            SELECT SUM(amount) FROM `' . _DB_PREFIX_ . 'smartsearch_conversions`
            WHERE id_shop = ' . $this->idShop . $dateCondition
        );

        // Media risultati per ricerca
        $avgResults = Db::getInstance()->getValue('
            SELECT AVG(results_count) FROM `' . _DB_PREFIX_ . 'smartsearch_stats`
            WHERE id_shop = ' . $this->idShop . $dateCondition
        );

        return [
            'total_searches' => (int)$totalSearches,
            'unique_searches' => (int)$uniqueSearches,
            'zero_results' => (int)$zeroResults,
            'zero_results_rate' => $totalSearches > 0 ? round(($zeroResults / $totalSearches) * 100, 2) : 0,
            'total_clicks' => (int)$totalClicks,
            'ctr' => $totalSearches > 0 ? round(($totalClicks / $totalSearches) * 100, 2) : 0,
            'total_conversions' => (int)$totalConversions,
            'conversion_rate' => $totalClicks > 0 ? round(($totalConversions / $totalClicks) * 100, 2) : 0,
            'search_revenue' => (float)$searchRevenue,
            'avg_results' => round((float)$avgResults, 1)
        ];
    }

    /**
     * Ottieni trend ricerche per grafico
     */
    public function getSearchTrend($days = 30)
    {
        $sql = '
            SELECT
                DATE(date_add) AS date,
                COUNT(*) AS searches,
                COUNT(DISTINCT session_id) AS unique_users,
                SUM(CASE WHEN results_count = 0 THEN 1 ELSE 0 END) AS zero_results
            FROM `' . _DB_PREFIX_ . 'smartsearch_stats`
            WHERE id_shop = ' . $this->idShop . '
            AND date_add >= DATE_SUB(NOW(), INTERVAL ' . (int)$days . ' DAY)
            GROUP BY DATE(date_add)
            ORDER BY date ASC';

        return Db::getInstance()->executeS($sql) ?: [];
    }

    /**
     * Pulisci dati vecchi
     */
    public function cleanup($daysToKeep = 90)
    {
        $tables = ['smartsearch_stats', 'smartsearch_clicks', 'smartsearch_conversions'];

        foreach ($tables as $table) {
            $sql = 'DELETE FROM `' . _DB_PREFIX_ . $table . '`
                    WHERE date_add < DATE_SUB(NOW(), INTERVAL ' . (int)$daysToKeep . ' DAY)
                    AND id_shop = ' . $this->idShop;

            Db::getInstance()->execute($sql);
        }

        return true;
    }
}
