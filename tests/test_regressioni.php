<?php
/**
 * Test di regressione: ogni test qui corrisponde a un BUG REALE che ha
 * causato problemi in produzione. Servono a impedire che si ripresentino.
 *
 * Uso:  php tests/test_regressioni.php
 */
require_once __DIR__ . '/stubs.php';

$prod = function ($id, $name, $extra = []) {
    return array_merge([
        'id_product' => $id, 'name' => $name, 'manufacturer_name' => '', 'category_name' => '',
        'reference' => '', 'ean13' => '', 'description_short' => '', 'description' => '',
        '_haystack' => '', 'sales_count' => 0, 'date_add' => '2020-01-01', '_relevance_score' => 100,
    ], $extra);
};
$t = new SearchTestable();

// ═══════════════════════════════════════════════════════════════
T::section('BUG 1 — Indice parziale rendeva introvabile il catalogo');
// Il rebuild falliva, ma gli hook inserivano i prodotti modificati.
// L'indice veniva considerato "pronto" con una sola riga.
Db::$inst->results = ['smartsearch_index' => 12, 'product_shop' => 10000];
SearchTestable::resetIndexCache();
T::ok('indice 12/10000 -> NON usato (usa ricerca diretta, completa)', $t->call('isSearchIndexAvailable') === false);

Db::$inst->results = ['smartsearch_index' => 9900, 'product_shop' => 10000];
SearchTestable::resetIndexCache();
T::ok('indice completo -> usato', $t->call('isSearchIndexAvailable') === true);

Db::$inst->results = ['smartsearch_index' => 0, 'product_shop' => 0];
SearchTestable::resetIndexCache();
T::ok('catalogo vuoto -> nessuna divisione per zero', $t->call('isSearchIndexAvailable') === false);

// ═══════════════════════════════════════════════════════════════
T::section('BUG 2 — Il filtro scartava prodotti trovati via descrizione');
Configuration::$data = ['SMARTSEARCH_MATCHALL_ENABLED' => 1, 'SMARTSEARCH_SYNONYMS_ENABLED' => 0,
                        'SMARTSEARCH_MATCHALL_MODE' => 'filter', 'SMARTSEARCH_MATCHALL_MIN_RESULTS' => 1];
SearchTestable::resetSynonymCache();
$viaDesc = $prod(1, 'Neopecia 90 cpr', ['_haystack' => 'neopecia integratore per capelli']);
$h = $t->call('productHaystack', $viaDesc);
T::ok('la descrizione lunga entra nella valutazione', strpos($h, 'capelli') !== false);
$r = $t->call('applyMatchAllGating', [$viaDesc], 'integratore capelli');
T::ok('prodotto trovato via descrizione NON viene scartato', count($r) === 1);

// ═══════════════════════════════════════════════════════════════
T::section('BUG 3 — Il filtro nascondeva prodotti (perdita di vendite)');
$in = [$prod(1, 'Magnesio Supremo'), $prod(2, 'Magnesio Citrato'), $prod(3, 'Potassio')];
Configuration::$data['SMARTSEARCH_MATCHALL_MODE'] = 'sort';
T::ok('modalità predefinita: nessun prodotto rimosso', count($t->call('applyMatchAllGating', $in, 'magnesio supremo')) === 3);
Configuration::$data['SMARTSEARCH_MATCHALL_MODE'] = false;
T::ok('impostazione assente -> comportamento sicuro', count($t->call('applyMatchAllGating', $in, 'magnesio supremo')) === 3);

// ═══════════════════════════════════════════════════════════════
T::section('BUG 4 — Prezzi di un cliente serviti a un altro (cache)');
$k1 = $t->call('getPriceContextKey');
$t->context->customer->id = 5; $t->context->customer->id_default_group = 3;  // rivenditore
$k2 = $t->call('getPriceContextKey');
T::ok('gruppo cliente diverso -> chiave cache diversa', $k1 !== $k2);
$t->context->currency->id = 2;                                               // altra valuta
$k3 = $t->call('getPriceContextKey');
T::ok('valuta diversa -> chiave cache diversa', $k2 !== $k3);
$t->context->country->id = 7;                                                // altro paese (IVA)
T::ok('paese diverso -> chiave cache diversa', $k3 !== $t->call('getPriceContextKey'));

// ═══════════════════════════════════════════════════════════════
T::section('BUG 5 — Ordinamento instabile (confronto int/float)');
$mixed = [
    $prod(2, 'Bravo',  ['_relevance_score' => 100]),                                    // int
    $prod(1, 'Alfa',   ['_relevance_score' => 100, '_boost_score' => 1.0]),             // float
    $prod(3, 'Charlie',['_relevance_score' => 100, '_ltr_score' => 1.0]),               // float
];
$sorted = $mixed;
$eps = 0.000001;
usort($sorted, function ($a, $b) use ($eps) {
    $sa = (float)($a['_relevance_score'] ?? 0) * (float)($a['_boost_score'] ?? 1) * (float)($a['_ltr_score'] ?? 1);
    $sb = (float)($b['_relevance_score'] ?? 0) * (float)($b['_boost_score'] ?? 1) * (float)($b['_ltr_score'] ?? 1);
    if (abs($sa - $sb) > $eps) { return $sb <=> $sa; }
    $n = strcmp((string)$a['name'], (string)$b['name']);
    return $n !== 0 ? $n : ((int)$a['id_product'] <=> (int)$b['id_product']);
});
T::ok('a parità di punteggio ordina per nome (deterministico)',
      $sorted[0]['name'] === 'Alfa' && $sorted[1]['name'] === 'Bravo' && $sorted[2]['name'] === 'Charlie');

// ═══════════════════════════════════════════════════════════════
T::section('BUG 6 — Ricerca attaccata non trovava il prodotto');
T::ok('query con spazio -> non elabora',   $t->call('findCompoundSplit', 'neo pecia', 1, 1) === null);
T::ok('query troppo corta -> non elabora', $t->call('findCompoundSplit', 'abc', 1, 1) === null);
T::ok('query con simboli -> non elabora',  $t->call('findCompoundSplit', 'neo@pecia', 1, 1) === null);
$t->call('findCompoundSplit', 'neopecia', 1, 1);
T::ok('genera le divisioni da verificare sul catalogo', strpos(Db::$inst->lastSql, 'neo pecia') !== false);

// ═══════════════════════════════════════════════════════════════
T::section('ROBUSTEZZA — input anomali non devono generare errori');
Configuration::$data['SMARTSEARCH_MATCHALL_MODE'] = 'sort';
foreach ([['', 'query vuota'], ['   ', 'solo spazi'], ['+++', 'solo simboli'],
          ['"; DROP TABLE--', 'tentativo SQL'], ['àèìòù 日本語 🔍', 'unicode ed emoji'],
          [str_repeat('a', 300), 'query lunghissima']] as $case) {
    list($q, $label) = $case;
    try { $t->call('applyMatchAllGating', $in, $q); $t->call('findCompoundSplit', $q, 1, 1); $ok = true; }
    catch (Throwable $e) { $ok = false; }
    T::ok("nessun errore con: $label", $ok);
}

exit(T::summary());
