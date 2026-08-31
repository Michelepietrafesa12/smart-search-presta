<?php
/**
 * SmartSearch - Cron Job per l'apprendimento automatico dei sinonimi
 *
 * Analizza le ricerche a zero risultati e il vocabolario del catalogo per
 * dedurre sinonimi/correzioni. I candidati ad alta confidenza vengono
 * applicati automaticamente, gli altri finiscono nella coda di revisione.
 *
 * Utilizzo da command line:
 *   php learn_synonyms.php
 *
 * Utilizzo via HTTP (richiede token di sicurezza):
 *   https://tuosito.com/modules/smartsearch/cron/learn_synonyms.php?token=TUO_TOKEN
 *
 * Configurazione crontab consigliata (ogni notte alle 4:00):
 *   0 4 * * * /usr/bin/php /var/www/html/modules/smartsearch/cron/learn_synonyms.php >> /var/log/smartsearch_learn.log 2>&1
 */

// Questi lavori sono lunghi e vengono avviati anche in background: senza
// queste impostazioni PHP li interrompeva a metà (timeout o chiusura della
// connessione) lasciando l'indice incompleto, in silenzio.
@ignore_user_abort(true);
@set_time_limit(0);
@ini_set('memory_limit', '512M');

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
    if (!hash_equals($expectedToken, (string) $secureToken)) {
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
        PrestaShopLogger::addLog('SmartSearch Learn Cron: ' . $msg, 3, null, 'SmartSearch');
    }
};

$log('=== SmartSearch - Apprendimento automatico sinonimi ===');

try {
    $module = Module::getInstanceByName('smartsearch');

    if (!$module || !$module->active) {
        $log('Modulo SmartSearch non attivo o non trovato', true);
        exit(1);
    }

    $startTime = microtime(true);

    $result = $module->learnSynonyms();

    $elapsed = round(microtime(true) - $startTime, 2);

    $analyzed = isset($result['analyzed']) ? (int) $result['analyzed'] : 0;
    $candidates = isset($result['candidates']) ? (int) $result['candidates'] : 0;
    $auto = isset($result['auto_applied']) ? (int) $result['auto_applied'] : 0;

    $log("Analizzate {$analyzed} query, {$candidates} candidati, {$auto} applicati in automatico in {$elapsed}s");
    exit(0);
} catch (Throwable $e) {
    $log('Errore critico: ' . $e->getMessage(), true);
    exit(1);
}
