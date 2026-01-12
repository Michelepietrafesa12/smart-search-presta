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
     * Traccia una ricerca (metodo aggregato - incrementa contatore se esiste)
     */
    public function trackSearch($query, $resultsCount)
    {
        $query = trim($query);
        if (strlen($query) < 2) {
            return false;
        }

        // Controlla se esiste già
        $existing = Db::getInstance()->getRow('
            SELECT id_smartsearch_stats, search_count
            FROM `' . _DB_PREFIX_ . 'smartsearch_stats`
            WHERE search_query = \'' . pSQL($query) . '\'
            AND id_lang = ' . $this->idLang . '
            AND id_shop = ' . $this->idShop . '
            LIMIT 1
        ');

        if ($existing) {
            // Aggiorna contatore
            return Db::getInstance()->execute('
                UPDATE `' . _DB_PREFIX_ . 'smartsearch_stats`
                SET search_count = search_count + 1,
                    results_count = ' . (int)$resultsCount . ',
                    last_search = NOW()
                WHERE id_smartsearch_stats = ' . (int)$existing['id_smartsearch_stats']
            );
        }

        // Inserisci nuovo
        return Db::getInstance()->execute('
            INSERT INTO `' . _DB_PREFIX_ . 'smartsearch_stats`
            (search_query, search_count, results_count, id_lang, id_shop, last_search, date_add)
            VALUES (
                \'' . pSQL($query) . '\',
                1,
                ' . (int)$resultsCount . ',
                ' . $this->idLang . ',
                ' . $this->idShop . ',
                NOW(),
                NOW()
            )
        ');
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
     * Nota: la tabella stats è aggregata per query, quindi usiamo search_count
     */
    public function getTopSearches($limit = 20, $dateFrom = null, $dateTo = null)
    {
        $dateCondition = '';
        if ($dateFrom) {
            $dateCondition .= ' AND last_search >= \'' . pSQL($dateFrom) . '\'';
        }
        if ($dateTo) {
            $dateCondition .= ' AND last_search <= \'' . pSQL($dateTo) . '\'';
        }

        $sql = '
            SELECT
                search_query,
                search_count,
                results_count AS avg_results
            FROM `' . _DB_PREFIX_ . 'smartsearch_stats`
            WHERE id_shop = ' . $this->idShop . '
            AND id_lang = ' . $this->idLang . '
            ' . $dateCondition . '
            ORDER BY search_count DESC
            LIMIT ' . (int)$limit;

        return Db::getInstance()->executeS($sql) ?: [];
    }

    /**
     * Ottieni ricerche senza risultati
     * Nota: la tabella stats è aggregata per query
     */
    public function getZeroResultSearches($limit = 20, $dateFrom = null, $dateTo = null)
    {
        $dateCondition = '';
        if ($dateFrom) {
            $dateCondition .= ' AND last_search >= \'' . pSQL($dateFrom) . '\'';
        }
        if ($dateTo) {
            $dateCondition .= ' AND last_search <= \'' . pSQL($dateTo) . '\'';
        }

        $sql = '
            SELECT
                search_query,
                search_count
            FROM `' . _DB_PREFIX_ . 'smartsearch_stats`
            WHERE id_shop = ' . $this->idShop . '
            AND id_lang = ' . $this->idLang . '
            AND results_count = 0
            ' . $dateCondition . '
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
     * Nota: la tabella stats è aggregata per query, usiamo search_count
     */
    public function getSearchCTR($dateFrom = null, $dateTo = null)
    {
        $dateCondition = '';
        $dateConditionClicks = '';
        if ($dateFrom) {
            $dateCondition .= ' AND s.last_search >= \'' . pSQL($dateFrom) . '\'';
            $dateConditionClicks .= ' AND c.date_add >= \'' . pSQL($dateFrom) . '\'';
        }
        if ($dateTo) {
            $dateCondition .= ' AND s.last_search <= \'' . pSQL($dateTo) . '\'';
            $dateConditionClicks .= ' AND c.date_add <= \'' . pSQL($dateTo) . '\'';
        }

        $sql = '
            SELECT
                s.search_query,
                s.search_count AS searches,
                COUNT(c.id_smartsearch_click) AS clicks,
                ROUND((COUNT(c.id_smartsearch_click) / s.search_count * 100), 2) AS ctr
            FROM `' . _DB_PREFIX_ . 'smartsearch_stats` s
            LEFT JOIN `' . _DB_PREFIX_ . 'smartsearch_clicks` c
                ON s.search_query = c.search_query
                AND c.id_shop = ' . $this->idShop . '
                ' . $dateConditionClicks . '
            WHERE s.id_shop = ' . $this->idShop . '
            AND s.results_count > 0
            ' . $dateCondition . '
            GROUP BY s.id_smartsearch_stats
            HAVING searches >= 10
            ORDER BY ctr DESC
            LIMIT 50';

        return Db::getInstance()->executeS($sql) ?: [];
    }

    /**
     * Calcola tasso di conversione per query
     * Nota: la tabella stats è aggregata per query, usiamo search_count
     */
    public function getSearchConversionRate($dateFrom = null, $dateTo = null)
    {
        $dateCondition = '';
        $dateConditionConv = '';
        if ($dateFrom) {
            $dateCondition .= ' AND s.last_search >= \'' . pSQL($dateFrom) . '\'';
            $dateConditionConv .= ' AND c.date_add >= \'' . pSQL($dateFrom) . '\'';
        }
        if ($dateTo) {
            $dateCondition .= ' AND s.last_search <= \'' . pSQL($dateTo) . '\'';
            $dateConditionConv .= ' AND c.date_add <= \'' . pSQL($dateTo) . '\'';
        }

        $sql = '
            SELECT
                s.search_query,
                s.search_count AS searches,
                COUNT(DISTINCT c.id_smartsearch_conversion) AS conversions,
                COALESCE(SUM(c.amount), 0) AS revenue,
                ROUND((COUNT(DISTINCT c.id_smartsearch_conversion) / s.search_count * 100), 2) AS conversion_rate
            FROM `' . _DB_PREFIX_ . 'smartsearch_stats` s
            LEFT JOIN `' . _DB_PREFIX_ . 'smartsearch_conversions` c
                ON s.search_query = c.search_query
                AND c.id_shop = ' . $this->idShop . '
                ' . $dateConditionConv . '
            WHERE s.id_shop = ' . $this->idShop . '
            ' . $dateCondition . '
            GROUP BY s.id_smartsearch_stats
            HAVING searches >= 10
            ORDER BY conversion_rate DESC
            LIMIT 50';

        return Db::getInstance()->executeS($sql) ?: [];
    }

    /**
     * Ottieni statistiche generali
     * Nota: la tabella stats è aggregata per query, usiamo SUM(search_count)
     */
    public function getDashboardStats($dateFrom = null, $dateTo = null)
    {
        $dateConditionStats = '';
        $dateConditionOther = '';
        if ($dateFrom) {
            $dateConditionStats .= ' AND last_search >= \'' . pSQL($dateFrom) . '\'';
            $dateConditionOther .= ' AND date_add >= \'' . pSQL($dateFrom) . '\'';
        }
        if ($dateTo) {
            $dateConditionStats .= ' AND last_search <= \'' . pSQL($dateTo) . '\'';
            $dateConditionOther .= ' AND date_add <= \'' . pSQL($dateTo) . '\'';
        }

        // Totale ricerche (somma di search_count perché aggregato)
        $totalSearches = Db::getInstance()->getValue('
            SELECT COALESCE(SUM(search_count), 0) FROM `' . _DB_PREFIX_ . 'smartsearch_stats`
            WHERE id_shop = ' . $this->idShop . $dateConditionStats
        );

        // Ricerche uniche (numero di query distinte)
        $uniqueSearches = Db::getInstance()->getValue('
            SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'smartsearch_stats`
            WHERE id_shop = ' . $this->idShop . $dateConditionStats
        );

        // Ricerche senza risultati
        $zeroResults = Db::getInstance()->getValue('
            SELECT COALESCE(SUM(search_count), 0) FROM `' . _DB_PREFIX_ . 'smartsearch_stats`
            WHERE id_shop = ' . $this->idShop . '
            AND results_count = 0' . $dateConditionStats
        );

        // Totale click
        $totalClicks = Db::getInstance()->getValue('
            SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'smartsearch_clicks`
            WHERE id_shop = ' . $this->idShop . $dateConditionOther
        );

        // Totale conversioni
        $totalConversions = Db::getInstance()->getValue('
            SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'smartsearch_conversions`
            WHERE id_shop = ' . $this->idShop . $dateConditionOther
        );

        // Revenue da ricerche
        $searchRevenue = Db::getInstance()->getValue('
            SELECT COALESCE(SUM(amount), 0) FROM `' . _DB_PREFIX_ . 'smartsearch_conversions`
            WHERE id_shop = ' . $this->idShop . $dateConditionOther
        );

        // Media risultati per ricerca (pesata per search_count)
        $avgResults = Db::getInstance()->getValue('
            SELECT COALESCE(SUM(results_count * search_count) / SUM(search_count), 0)
            FROM `' . _DB_PREFIX_ . 'smartsearch_stats`
            WHERE id_shop = ' . $this->idShop . ' AND search_count > 0' . $dateConditionStats
        );

        $totalSearches = (int)$totalSearches;

        return [
            'total_searches' => $totalSearches,
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
     * Nota: la tabella stats è aggregata, usiamo click table per daily trend
     */
    public function getSearchTrend($days = 30)
    {
        // Usiamo la tabella clicks per i trend giornalieri (ha timestamp preciso)
        $sql = '
            SELECT
                DATE(date_add) AS date,
                COUNT(*) AS searches,
                COUNT(DISTINCT search_query) AS unique_queries
            FROM `' . _DB_PREFIX_ . 'smartsearch_clicks`
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
        // Stats table usa last_search per la data di riferimento
        Db::getInstance()->execute('
            DELETE FROM `' . _DB_PREFIX_ . 'smartsearch_stats`
            WHERE last_search < DATE_SUB(NOW(), INTERVAL ' . (int)$daysToKeep . ' DAY)
            AND id_shop = ' . $this->idShop
        );

        // Altre tabelle usano date_add
        $tables = ['smartsearch_clicks', 'smartsearch_conversions'];

        foreach ($tables as $table) {
            Db::getInstance()->execute('
                DELETE FROM `' . _DB_PREFIX_ . $table . '`
                WHERE date_add < DATE_SUB(NOW(), INTERVAL ' . (int)$daysToKeep . ' DAY)
                AND id_shop = ' . $this->idShop
            );
        }

        return true;
    }
}
