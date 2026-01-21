<?php
/**
 * SmartSearch - Cron Job per calcolo correlazioni prodotti
 *
 * Utilizzo da command line:
 *   php calculate_correlations.php
 *
 * Utilizzo via HTTP (richiede token di sicurezza):
 *   https://tuosito.com/modules/smartsearch/cron/calculate_correlations.php?token=TUO_TOKEN
 *
 * Configurazione crontab consigliata (ogni notte alle 3:00):
 *   0 3 * * * /usr/bin/php /var/www/html/modules/smartsearch/cron/calculate_correlations.php >> /var/log/smartsearch_cron.log 2>&1
 */

// Impedisci accesso diretto senza autenticazione via HTTP
$isCli = (php_sapi_name() === 'cli' || defined('STDIN'));

if (!$isCli) {
    // Verifica token di sicurezza per accesso HTTP
    $secureToken = isset($_GET['token']) ? $_GET['token'] : '';

    // Il token viene generato dalla combinazione di _COOKIE_KEY_ e nome modulo
    // Questo garantisce che sia unico per ogni installazione PrestaShop
}

// Trova la root di PrestaShop
$psRootPath = findPrestaShopRoot(__DIR__);
if (!$psRootPath) {
    logMessage('ERRORE: Impossibile trovare la root di PrestaShop', true);
    exit(1);
}

// Carica configurazione PrestaShop
$configPath = $psRootPath . '/config/config.inc.php';
if (!file_exists($configPath)) {
    logMessage('ERRORE: File config.inc.php non trovato in: ' . $configPath, true);
    exit(1);
}

// Imposta variabili necessarie per PrestaShop
if (!defined('_PS_ADMIN_DIR_')) {
    define('_PS_ADMIN_DIR_', $psRootPath . '/admin');
}

require_once($configPath);

// Verifica token dopo aver caricato la configurazione (per accesso HTTP)
if (!$isCli) {
    $expectedToken = md5(_COOKIE_KEY_ . 'smartsearch_cron');
    if ($secureToken !== $expectedToken) {
        header('HTTP/1.1 403 Forbidden');
        logMessage('ERRORE: Token di sicurezza non valido', true);
        exit(1);
    }
}

// Carica il modulo SmartSearch
require_once(_PS_MODULE_DIR_ . 'smartsearch/smartsearch.php');

// Esegui il calcolo delle correlazioni
logMessage('=== SmartSearch Cron Job - Inizio calcolo correlazioni ===');
logMessage('Data/Ora: ' . date('Y-m-d H:i:s'));

try {
    $module = Module::getInstanceByName('smartsearch');

    if (!$module || !$module->active) {
        logMessage('ERRORE: Modulo SmartSearch non attivo o non trovato', true);
        exit(1);
    }

    // Verifica se le correlazioni sono abilitate
    if (!Configuration::get('SMARTSEARCH_CORRELATIONS_ENABLED')) {
        logMessage('INFO: Le correlazioni sono disabilitate nella configurazione. Nessuna azione eseguita.');
        exit(0);
    }

    // Esegui il calcolo
    logMessage('Avvio calcolo correlazioni...');
    $startTime = microtime(true);

    $result = $module->calculateProductCorrelations();

    $endTime = microtime(true);
    $executionTime = round($endTime - $startTime, 2);

    if ($result) {
        logMessage('SUCCESS: Correlazioni calcolate con successo');
        logMessage('Tempo di esecuzione: ' . $executionTime . ' secondi');

        // Log statistiche
        $stats = getCorrelationStats();
        if ($stats) {
            logMessage('Statistiche:');
            logMessage('  - Correlazioni totali: ' . $stats['total_correlations']);
            logMessage('  - Prodotti con correlazioni: ' . $stats['products_with_correlations']);
            logMessage('  - Score medio: ' . round($stats['avg_score'] * 100, 2) . '%');
            logMessage('  - Ultimo aggiornamento: ' . $stats['last_update']);
        }

        exit(0);
    } else {
        logMessage('ERRORE: Il calcolo delle correlazioni ha restituito false', true);
        exit(1);
    }

} catch (Exception $e) {
    logMessage('ERRORE CRITICO: ' . $e->getMessage(), true);
    logMessage('Stack trace: ' . $e->getTraceAsString(), true);
    exit(1);
}

/**
 * Trova la directory root di PrestaShop partendo dalla directory corrente
 */
function findPrestaShopRoot($startDir)
{
    $dir = $startDir;
    $maxLevels = 10;
    $level = 0;

    while ($level < $maxLevels) {
        // Verifica se siamo nella root di PrestaShop
        if (file_exists($dir . '/config/config.inc.php') &&
            file_exists($dir . '/classes/PrestaShopAutoload.php')) {
            return $dir;
        }

        $parentDir = dirname($dir);
        if ($parentDir === $dir) {
            // Abbiamo raggiunto la root del filesystem
            break;
        }

        $dir = $parentDir;
        $level++;
    }

    return false;
}

/**
 * Log message con timestamp
 */
function logMessage($message, $isError = false)
{
    global $isCli;

    $timestamp = date('Y-m-d H:i:s');
    $prefix = $isError ? '[ERROR]' : '[INFO]';
    $formattedMessage = "[$timestamp] $prefix $message";

    if ($isCli) {
        echo $formattedMessage . PHP_EOL;
    } else {
        // Per accesso HTTP, accumula i messaggi
        echo $formattedMessage . "<br>\n";
    }

    // Log anche nel file di log PrestaShop per errori
    if ($isError && class_exists('PrestaShopLogger')) {
        PrestaShopLogger::addLog('SmartSearch Cron: ' . $message, 3, null, 'SmartSearch');
    }
}

/**
 * Recupera statistiche correlazioni dal database
 */
function getCorrelationStats()
{
    try {
        $idShop = (int)Context::getContext()->shop->id;
        if (!$idShop) {
            $idShop = 1;
        }

        $sql = 'SELECT
                    COUNT(*) as total_correlations,
                    COUNT(DISTINCT id_product_source) as products_with_correlations,
                    AVG(correlation_score) as avg_score,
                    MAX(date_upd) as last_update
                FROM `' . _DB_PREFIX_ . 'smartsearch_correlations`
                WHERE id_shop = ' . $idShop;

        return Db::getInstance()->getRow($sql);
    } catch (Exception $e) {
        return false;
    }
}
