<?php
/**
 * SmartSearch - Cron Job per ricostruzione indice di ricerca
 *
 * Utilizzo da command line:
 *   php rebuild_index.php
 *
 * Utilizzo via HTTP (richiede token di sicurezza):
 *   https://tuosito.com/modules/smartsearch/cron/rebuild_index.php?token=TUO_TOKEN
 *
 * Configurazione crontab consigliata (ogni notte alle 2:00):
 *   0 2 * * * /usr/bin/php /var/www/html/modules/smartsearch/cron/rebuild_index.php >> /var/log/smartsearch_index.log 2>&1
 */

$isCli = (php_sapi_name() === 'cli' || defined('STDIN'));

if (!$isCli) {
    $secureToken = isset($_GET['token']) ? $_GET['token'] : '';
}

// Trova la root di PrestaShop
$dir = __DIR__;
$maxLevels = 10;
$psRootPath = false;
for ($i = 0; $i < $maxLevels; $i++) {
    if (file_exists($dir . '/config/config.inc.php') &&
        file_exists($dir . '/classes/PrestaShopAutoload.php')) {
        $psRootPath = $dir;
        break;
    }
    $parent = dirname($dir);
    if ($parent === $dir) {
        break;
    }
    $dir = $parent;
}

if (!$psRootPath) {
    echo '[ERROR] Impossibile trovare la root di PrestaShop' . PHP_EOL;
    exit(1);
}

if (!defined('_PS_ADMIN_DIR_')) {
    define('_PS_ADMIN_DIR_', $psRootPath . '/admin');
}

require_once $psRootPath . '/config/config.inc.php';

// Verifica token per accesso HTTP
if (!$isCli) {
    $expectedToken = md5(_COOKIE_KEY_ . 'smartsearch_cron');
    if ($secureToken !== $expectedToken) {
        header('HTTP/1.1 403 Forbidden');
        echo 'Token non valido';
        exit(1);
    }
}

require_once _PS_MODULE_DIR_ . 'smartsearch/smartsearch.php';

$log = function ($msg, $error = false) use ($isCli) {
    $ts = date('Y-m-d H:i:s');
    $prefix = $error ? '[ERROR]' : '[INFO]';
    $line = "[$ts] $prefix $msg";
    echo $line . ($isCli ? PHP_EOL : "<br>\n");
    if ($error && class_exists('PrestaShopLogger')) {
        PrestaShopLogger::addLog('SmartSearch Index Cron: ' . $msg, 3, null, 'SmartSearch');
    }
};

$log('=== SmartSearch - Ricostruzione indice di ricerca ===');

try {
    $module = Module::getInstanceByName('smartsearch');

    if (!$module || !$module->active) {
        $log('Modulo SmartSearch non attivo o non trovato', true);
        exit(1);
    }

    $startTime = microtime(true);

    $result = $module->rebuildSearchIndex();

    $elapsed = round(microtime(true) - $startTime, 2);

    if ($result) {
        $count = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'smartsearch_index`'
        );
        $log("Indice ricostruito con successo: {$count} voci in {$elapsed}s");
        exit(0);
    } else {
        $log('Ricostruzione indice fallita. La causa esatta è registrata nei log di PrestaShop:', true);
        $log('  Parametri avanzati > Log  (cerca "SmartSearch ricostruzione indice")', true);

        // Mostra subito le ultime righe di log per non dover cercare a mano
        try {
            $rows = Db::getInstance()->executeS(
                'SELECT message, date_add FROM `' . _DB_PREFIX_ . 'log`
                 WHERE message LIKE \'SmartSearch ricostruzione indice%\'
                 ORDER BY id_log DESC LIMIT 3'
            );
            if ($rows) {
                foreach ($rows as $row) {
                    $log('  [' . $row['date_add'] . '] ' . $row['message'], true);
                }
            }
        } catch (Exception $e) {
            // il log è solo un aiuto: non deve far fallire lo script
        }
        exit(1);
    }
} catch (Exception $e) {
    $log('Errore critico: ' . $e->getMessage(), true);
    exit(1);
}
