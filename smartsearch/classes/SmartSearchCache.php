<?php
/**
 * SmartSearch 2.0 - Sistema di cache intelligente
 *
 * Gestisce la cache dei risultati di ricerca per migliorare le performance
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SmartSearchCache
{
    /** @var int Durata cache in secondi (default 1 ora) */
    protected $ttl = 3600;

    /** @var int */
    protected $idShop;

    /** @var int */
    protected $idLang;

    /** @var bool */
    protected $enabled = true;

    /**
     * Costruttore
     */
    public function __construct($idLang, $idShop)
    {
        $this->idLang = (int)$idLang;
        $this->idShop = (int)$idShop;
        $this->enabled = (bool)Configuration::get('SMARTSEARCH_CACHE_ENABLED');
        $this->ttl = (int)Configuration::get('SMARTSEARCH_CACHE_TTL') ?: 3600;
    }

    /**
     * Genera la chiave di cache
     */
    protected function generateKey($query, $filters = [])
    {
        $data = [
            'q' => mb_strtolower(trim($query)),
            'f' => $filters,
            'l' => $this->idLang,
            's' => $this->idShop
        ];

        return 'smartsearch_' . md5(json_encode($data));
    }

    /**
     * Ottieni risultati dalla cache
     */
    public function get($query, $filters = [])
    {
        if (!$this->enabled) {
            return null;
        }

        $key = $this->generateKey($query, $filters);

        // Prova prima la cache in memoria (se disponibile)
        if (function_exists('apcu_fetch')) {
            $result = apcu_fetch($key, $success);
            if ($success) {
                return $result;
            }
        }

        // Fallback su cache database
        $sql = 'SELECT result_data, created_at
                FROM `' . _DB_PREFIX_ . 'smartsearch_cache`
                WHERE cache_key = \'' . pSQL($key) . '\'
                AND created_at > DATE_SUB(NOW(), INTERVAL ' . (int)$this->ttl . ' SECOND)';

        $row = Db::getInstance()->getRow($sql);

        if ($row) {
            return json_decode($row['result_data'], true);
        }

        return null;
    }

    /**
     * Salva risultati in cache
     */
    public function set($query, $filters, $results)
    {
        if (!$this->enabled) {
            return false;
        }

        $key = $this->generateKey($query, $filters);
        $data = json_encode($results);

        // Salva in cache in memoria
        if (function_exists('apcu_store')) {
            apcu_store($key, $results, $this->ttl);
        }

        // Salva in database
        $sql = 'INSERT INTO `' . _DB_PREFIX_ . 'smartsearch_cache`
                (cache_key, result_data, query, id_lang, id_shop, created_at)
                VALUES (
                    \'' . pSQL($key) . '\',
                    \'' . pSQL($data) . '\',
                    \'' . pSQL($query) . '\',
                    ' . $this->idLang . ',
                    ' . $this->idShop . ',
                    NOW()
                )
                ON DUPLICATE KEY UPDATE
                    result_data = VALUES(result_data),
                    created_at = NOW()';

        return Db::getInstance()->execute($sql);
    }

    /**
     * Invalida la cache per una query specifica
     */
    public function invalidate($query = null)
    {
        if ($query) {
            $key = $this->generateKey($query, []);

            if (function_exists('apcu_delete')) {
                apcu_delete($key);
            }

            $sql = 'DELETE FROM `' . _DB_PREFIX_ . 'smartsearch_cache`
                    WHERE cache_key LIKE \'' . pSQL(substr($key, 0, -5)) . '%\'';
        } else {
            // Invalida tutta la cache
            if (function_exists('apcu_clear_cache')) {
                apcu_clear_cache();
            }

            $sql = 'DELETE FROM `' . _DB_PREFIX_ . 'smartsearch_cache`
                    WHERE id_shop = ' . $this->idShop;
        }

        return Db::getInstance()->execute($sql);
    }

    /**
     * Pulisci cache scaduta
     */
    public function cleanup()
    {
        $sql = 'DELETE FROM `' . _DB_PREFIX_ . 'smartsearch_cache`
                WHERE created_at < DATE_SUB(NOW(), INTERVAL ' . (int)$this->ttl . ' SECOND)';

        return Db::getInstance()->execute($sql);
    }

    /**
     * Ottieni statistiche cache
     */
    public function getStats()
    {
        $sql = 'SELECT
                    COUNT(*) AS total_entries,
                    SUM(LENGTH(result_data)) AS total_size,
                    MIN(created_at) AS oldest_entry,
                    MAX(created_at) AS newest_entry
                FROM `' . _DB_PREFIX_ . 'smartsearch_cache`
                WHERE id_shop = ' . $this->idShop;

        return Db::getInstance()->getRow($sql);
    }
}
