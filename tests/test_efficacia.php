<?php
/**
 * Test di EFFICACIA della ricerca.
 * Non cerca bug: verifica che, dato un catalogo realistico, la ricerca
 * metta in cima il prodotto che il cliente si aspetta.
 *
 * Uso:  php tests/test_efficacia.php
 */
require_once __DIR__ . '/stubs.php';

Configuration::$data = [
    'SMARTSEARCH_MATCHALL_ENABLED' => 1, 'SMARTSEARCH_MATCHALL_MODE' => 'sort',
    'SMARTSEARCH_SYNONYMS_ENABLED' => 0, 'SMARTSEARCH_LTR_ENABLED' => 0,
    'SMARTSEARCH_REL_SALES_WEIGHT' => 100, 'SMARTSEARCH_REL_NOVELTY_WEIGHT' => 100,
    'SMARTSEARCH_REL_STOCK_PENALTY' => 30,
];
$t = new SearchTestable();

/** Catalogo realistico da parafarmacia */
function prodotto($id, $nome, $brand = '', $cat = '', $desc = '', $ref = '', $vendite = 0) {
    return ['id_product' => $id, 'name' => $nome, 'manufacturer_name' => $brand,
        'category_name' => $cat, 'reference' => $ref, 'ean13' => '', 'description_short' => $desc,
        'description' => $desc, '_haystack' => "$nome $brand $cat $desc $ref",
        'sales_count' => $vendite, 'date_add' => '2023-01-01'];
}
$CATALOGO = [
    prodotto(1,  'Neo Pecia 90 Compresse', 'Neopharmed', 'Capelli', 'integratore anticaduta per capelli', 'NEO090', 50),
    prodotto(2,  'Magnesio Supremo 150g', 'Natural Point', 'Integratori', 'magnesio citrato in polvere', 'MAG150', 120),
    prodotto(3,  'Magnesio Supremo 300g', 'Natural Point', 'Integratori', 'magnesio citrato in polvere formato scorta', 'MAG300', 80),
    prodotto(4,  'Magnesio Citrato 100 Capsule', 'Solgar', 'Integratori', 'integratore di magnesio', 'SOL100', 30),
    prodotto(5,  'Omega 3 1000mg 60 Perle', 'Enerzona', 'Integratori', 'acidi grassi omega tre', 'omega3', 200),
    prodotto(6,  'Omega 3 Plus 120 Capsule', 'Solgar', 'Integratori', 'olio di pesce omega 3', 'SOL-O3', 90),
    prodotto(7,  'Vitamina D3 1000 UI', 'Solgar', 'Vitamine', 'colecalciferolo gocce', 'D3-1000', 150),
    prodotto(8,  'Vitamina C 1000mg Effervescente', 'Bayer', 'Vitamine', 'acido ascorbico', 'C1000', 300),
    prodotto(9,  'Aspirina 400mg 20 Compresse', 'Bayer', 'Farmaci', 'acido acetilsalicilico antidolorifico', 'ASP400', 500),
    prodotto(10, 'Tachipirina 1000mg', 'Angelini', 'Farmaci', 'paracetamolo febbre e dolore', 'TAC1000', 800),
    prodotto(11, 'Crema Idratante Viso 50ml', 'Vichy', 'Cosmetici', 'crema viso pelle secca', 'VIC050', 60),
    prodotto(12, 'Shampoo Anticaduta 200ml', 'Vichy', 'Capelli', 'shampoo rinforzante per capelli', 'VIC200', 40),
];

// Simula il ranking come fa searchProducts (punteggio + copertura + ordinamento)
function classifica($t, $catalogo, $query) {
    $qLower = mb_strtolower(trim($query));
    $words = array_values(array_filter(explode(' ', $qLower), function ($w) { return mb_strlen($w) >= 2; }));
    // 1) filtra i candidati come farebbe l'SQL: almeno una parola presente
    $candidati = [];
    foreach ($catalogo as $p) {
        $hay = mb_strtolower($p['_haystack']);
        foreach ($words as $w) {
            if (mb_strpos($hay, $w) !== false) { $candidati[] = $p; break; }
        }
    }
    // Come la ricerca reale: se i risultati sono pochi, riprova con la query
    // divisa (es. "omega3" -> "omega 3", "neopecia" -> "neo pecia").
    if (count($candidati) < 2 && mb_strpos($qLower, ' ') === false) {
        $divisa = preg_replace('/([a-z])([0-9])/u', '$1 $2', $qLower);
        if ($divisa === $qLower) {
            for ($i = 3; $i <= mb_strlen($qLower) - 3; $i++) {
                $tent = mb_substr($qLower, 0, $i) . ' ' . mb_substr($qLower, $i);
                foreach ($catalogo as $p) {
                    if (mb_strpos(mb_strtolower($p['name']), $tent) !== false) { $divisa = $tent; break 2; }
                }
            }
        }
        if ($divisa !== $qLower) { return classifica($t, $catalogo, $divisa); }
    }
    if (!$candidati) { return []; }
    // 2) punteggio di rilevanza (codice reale)
    foreach ($candidati as &$p) { $p['_relevance_score'] = $t->call('calculateRelevanceScore', $p, $qLower, $words); }
    unset($p);
    // 3) copertura parole (codice reale)
    $candidati = $t->call('applyMatchAllGating', $candidati, $query);
    // 4) ordinamento come in searchProducts
    $maxCov = 0;
    foreach ($candidati as $p) { if (isset($p['_coverage_ratio']) && $p['_coverage_ratio'] > $maxCov) { $maxCov = $p['_coverage_ratio']; } }
    usort($candidati, function ($a, $b) use ($maxCov) {
        $ca = (float)($a['_coverage_ratio'] ?? $maxCov); $cb = (float)($b['_coverage_ratio'] ?? $maxCov);
        if (abs($ca - $cb) > 0.000001) { return $cb <=> $ca; }
        $sa = (float)($a['_relevance_score'] ?? 0); $sb = (float)($b['_relevance_score'] ?? 0);
        if (abs($sa - $sb) > 0.000001) { return $sb <=> $sa; }
        return strcmp($a['name'], $b['name']);
    });
    return $candidati;
}

function verifica($t, $catalogo, $query, $attesoId, $attesoNome) {
    $r = classifica($t, $catalogo, $query);
    $primo = $r ? $r[0] : null;
    $ok = $primo && $primo['id_product'] === $attesoId;
    $trovato = $primo ? $primo['name'] : 'NESSUN RISULTATO';
    T::ok(sprintf('"%s" → %s%s', $query, $attesoNome, $ok ? '' : "   [ottenuto: $trovato]"), $ok);
    return $r;
}

T::section('Ricerca del nome esatto');
verifica($t, $CATALOGO, 'tachipirina', 10, 'Tachipirina');
verifica($t, $CATALOGO, 'aspirina', 9, 'Aspirina');
verifica($t, $CATALOGO, 'magnesio supremo 150g', 2, 'Magnesio Supremo 150g');

T::section('Ricerca parziale / per marca');
verifica($t, $CATALOGO, 'vichy crema', 11, 'Crema Vichy');
verifica($t, $CATALOGO, 'solgar omega', 6, 'Omega 3 Solgar');

T::section('Distinzione tra formati simili');
verifica($t, $CATALOGO, 'magnesio supremo 300g', 3, 'Magnesio Supremo 300g');
verifica($t, $CATALOGO, 'vitamina c 1000', 8, 'Vitamina C 1000mg');
verifica($t, $CATALOGO, 'vitamina d3', 7, 'Vitamina D3');

T::section('Il caso segnalato dal cliente (parola attaccata)');
$r = classifica($t, $CATALOGO, 'neo pecia');
T::ok('"neo pecia" → Neo Pecia', $r && $r[0]['id_product'] === 1);

T::section('Ricerca per codice prodotto');
$r = classifica($t, $CATALOGO, 'omega3');
T::ok('"omega3" mostra prodotti Omega 3', $r && in_array($r[0]['id_product'], [5, 6], true));

T::section('Nomi con lettera+numero (tipici degli integratori)');
$r = classifica($t, $CATALOGO, 'omega3');
T::ok('"omega3" trova entrambi i prodotti Omega 3', count($r) >= 2);
$r = classifica($t, $CATALOGO, 'neopecia');
T::ok('"neopecia" (attaccato) trova Neo Pecia', $r && $r[0]['id_product'] === 1);

T::section('Maiuscole e accenti non devono contare');
verifica($t, $CATALOGO, 'TACHIPIRINA', 10, 'Tachipirina');
verifica($t, $CATALOGO, 'Magnesio SUPREMO 150g', 2, 'Magnesio Supremo 150g');

T::section('Le parole in più non devono far sparire il prodotto giusto');
$r = classifica($t, $CATALOGO, 'crema idratante viso vichy');
T::ok('"crema idratante viso vichy" → Crema Vichy', $r && $r[0]['id_product'] === 11);

T::section('Ricerca per categoria/uso');
$r = classifica($t, $CATALOGO, 'capelli');
T::ok('"capelli" trova i prodotti per capelli', count($r) >= 2);
$r = classifica($t, $CATALOGO, 'anticaduta');
T::ok('"anticaduta" trova prodotti anticaduta', count($r) >= 2);

exit(T::summary());
