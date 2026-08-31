<?php
/**
 * Stub minimi delle classi PrestaShop, per poter caricare ed ESEGUIRE
 * il codice reale del modulo senza un'installazione completa.
 */
error_reporting(E_ALL);
if (!defined('_PS_VERSION_')) { define('_PS_VERSION_', '1.7.8.0'); }
if (!defined('_DB_PREFIX_')) { define('_DB_PREFIX_', 'ps_'); }

function pSQL($s, $html = false) { return str_replace("'", "\\'", (string) $s); }

class ModuleFrontController { public $context; public $module; }

class Configuration {
    public static $data = [];
    public static function get($k) { return array_key_exists($k, self::$data) ? self::$data[$k] : false; }
    public static function updateValue($k, $v) { self::$data[$k] = $v; return true; }
}
class Tools {
    public static $values = [];
    public static function getValue($k, $d = false) { return array_key_exists($k, self::$values) ? self::$values[$k] : $d; }
    public static function isSubmit($k) { return isset(self::$values[$k]); }
    public static function getRemoteAddr() { return '127.0.0.1'; }
    public static function displayPrice($p) { return number_format((float) $p, 2) . ' EUR'; }
}
class Validate { public static function isLoadedObject($o) { return is_object($o) && !empty($o->id); } }
class StockAvailable { public static $qty = 10; public static function getQuantityAvailableByProduct($id) { return self::$qty; } }
class PrestaShopLogger { public static $logs = []; public static function addLog($m, $s = 1, $c = null, $o = null) { self::$logs[] = $m; } }
class Product { public static function getPriceStatic($id, $tax = true, $a = null, $d = 6, $e = null, $f = false, $g = true) { return 9.99; } }

class StubDb {
    public $queries = [];
    public $results = [];      // sottostringa query => risultato
    public $lastSql = '';
    protected function match($sql) {
        foreach ($this->results as $needle => $val) {
            if ($needle !== '' && strpos($sql, $needle) !== false) { return $val; }
        }
        return null;
    }
    public function executeS($sql) { $this->lastSql = $sql; $this->queries[] = $sql; $m = $this->match($sql); return $m === null ? [] : $m; }
    public function getRow($sql)   { $this->lastSql = $sql; $this->queries[] = $sql; $m = $this->match($sql); return $m === null ? false : $m; }
    public function getValue($sql) { $this->lastSql = $sql; $this->queries[] = $sql; $m = $this->match($sql); return $m === null ? false : $m; }
    public function execute($sql)  { $this->lastSql = $sql; $this->queries[] = $sql; $m = $this->match($sql); return $m === null ? true : $m; }
    public function insert($t, $d) { $this->queries[] = "INSERT $t"; return true; }
    public function update($t, $d, $w) { $this->queries[] = "UPDATE $t"; return true; }
    public function delete($t, $w) { $this->queries[] = "DELETE $t"; return true; }
    public function getMsgError() { return 'stub error'; }
}
class Db { public static $inst; public static function getInstance() { return self::$inst ?: (self::$inst = new StubDb()); } }
Db::$inst = new StubDb();

class StubShop { public $id = 1; }
class StubLang { public $id = 1; }
class StubCurrency { public $id = 1; public $sign = 'EUR'; }
class StubCountry { public $id = 1; }
class StubCustomer { public $id = 0; public $id_default_group = 1; }
class StubContext {
    public $shop, $language, $currency, $country, $customer;
    public function __construct() {
        $this->shop = new StubShop(); $this->language = new StubLang();
        $this->currency = new StubCurrency(); $this->country = new StubCountry();
        $this->customer = new StubCustomer();
    }
}
class StubModule {
    public function normalizeClickQuery($q) {
        $q = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', (string) $q);
        $q = mb_strtolower(trim($q)); $q = preg_replace('/\s+/', ' ', $q);
        return mb_substr($q, 0, 64);
    }
    public function recordClickStat() { return true; }
}

require_once __DIR__ . '/../smartsearch/controllers/front/search.php';

/** Espone i metodi protetti per i test */
class SearchTestable extends SmartsearchSearchModuleFrontController {
    public function __construct() { $this->context = new StubContext(); $this->module = new StubModule(); }
    public function call($m, ...$a) { return $this->$m(...$a); }
    public static function resetIndexCache() { self::$searchIndexAvailable = null; }
    public static function resetSynonymCache() { self::$tableSynonymsCache = null; }
}

// ---- mini framework di asserzioni ----
class T {
    public static $pass = 0, $fail = 0, $section = '';
    public static function section($s) { self::$section = $s; echo "\n── $s\n"; }
    public static function ok($label, $cond) {
        if ($cond) { self::$pass++; printf("  \033[32mOK\033[0m   %s\n", $label); }
        else { self::$fail++; printf("  \033[31mFAIL\033[0m %s\n", $label); }
    }
    public static function summary() {
        $tot = self::$pass + self::$fail;
        echo "\n" . str_repeat('─', 60) . "\n";
        printf("Totale: %d test — %d superati, %d falliti\n", $tot, self::$pass, self::$fail);
        return self::$fail === 0 ? 0 : 1;
    }
}
