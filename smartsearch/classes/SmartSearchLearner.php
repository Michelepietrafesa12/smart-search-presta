<?php
/**
 * SmartSearch 2.0 - Motore di apprendimento automatico dei sinonimi
 *
 * Analizza i log di ricerca (smartsearch_stats) e il vocabolario del catalogo
 * (smartsearch_index) per dedurre automaticamente sinonimi/correzioni di
 * termini, senza dipendenze esterne.
 *
 * Segnali usati (tutti locali, nessuna AI esterna):
 *  - Query a ZERO risultati abbastanza frequenti  -> candidate da correggere
 *  - Vocabolario del catalogo (nomi prodotto, brand, reference)
 *  - Query "di successo" gia' registrate           -> target alternativi
 *
 * Tecniche: Levenshtein + fonetica (metaphone/soundex) + bucketing per
 * limitare i confronti. Produce coppie termine->sinonimo con un punteggio
 * di confidenza 0..1. Sopra una soglia vengono applicate in automatico,
 * altrimenti finiscono in una coda di revisione.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SmartSearchLearner
{
    /** @var int */
    protected $idShop;

    /** @var int */
    protected $idLang;

    /** @var int Frequenza minima (search_count) di una query a zero risultati per essere considerata */
    protected $minFreq = 3;

    /** @var float Confidenza >= questa soglia => applicato in automatico (0..1) */
    protected $autoThreshold = 0.85;

    /** @var float Confidenza < questa soglia => scartato (0..1) */
    protected $minThreshold = 0.55;

    /** @var int Numero massimo di query a zero risultati analizzate per esecuzione */
    protected $maxQueries = 500;

    /** @var array token(minuscolo) => frequenza nel catalogo */
    protected $vocab = [];

    /** @var array primo_carattere => [token, ...] per limitare i confronti */
    protected $vocabByFirst = [];

    /** @var array metaphone => [token, ...] */
    protected $vocabByPhonetic = [];

    /** @var array set di query a risultato > 0 (per target comportamentali) */
    protected $successful = [];

    /**
     * @param int $idShop
     * @param int $idLang
     * @param array $opts min_freq, auto_threshold, min_threshold, max_queries
     */
    public function __construct($idShop, $idLang, array $opts = [])
    {
        $this->idShop = (int) $idShop;
        $this->idLang = (int) $idLang;

        if (isset($opts['min_freq'])) {
            $this->minFreq = max(1, (int) $opts['min_freq']);
        }
        if (isset($opts['auto_threshold'])) {
            $this->autoThreshold = $this->clamp01((float) $opts['auto_threshold']);
        }
        if (isset($opts['min_threshold'])) {
            $this->minThreshold = $this->clamp01((float) $opts['min_threshold']);
        }
        if (isset($opts['max_queries'])) {
            $this->maxQueries = max(1, (int) $opts['max_queries']);
        }
    }

    /**
     * Esegue un ciclo di apprendimento.
     *
     * @return array ['analyzed' => int, 'candidates' => int, 'auto_applied' => int]
     */
    public function run()
    {
        $stats = ['analyzed' => 0, 'candidates' => 0, 'auto_applied' => 0];

        $zeroQueries = $this->getZeroResultQueries();
        if (empty($zeroQueries)) {
            return $stats;
        }

        $this->buildVocabulary();
        if (empty($this->vocab)) {
            // Senza catalogo indicizzato non possiamo dedurre nulla di affidabile
            return $stats;
        }
        $this->loadSuccessfulQueries();

        foreach ($zeroQueries as $row) {
            $stats['analyzed']++;

            $query = $this->normalize($row['search_query']);
            $freq = (int) $row['search_count'];
            if ($query === '') {
                continue;
            }

            $match = $this->findBestCorrection($query);
            if ($match === null) {
                continue;
            }

            list($source, $target, $confidence, $type) = $match;

            if ($source === $target || $confidence < $this->minThreshold) {
                continue;
            }

            // Non "correggere" un termine che e' gia' un token reale del catalogo
            if (isset($this->vocab[$source])) {
                continue;
            }

            // Salta se esiste gia' un sinonimo attivo per questo termine
            if ($this->synonymExists($source)) {
                continue;
            }

            $autoApply = ($confidence >= $this->autoThreshold);
            $status = $autoApply ? 'auto' : 'pending';

            if ($this->upsertCandidate($source, $target, $confidence, $type, $freq, $status)) {
                $stats['candidates']++;
            }

            if ($autoApply && $this->applyToSynonyms($source, $target)) {
                $stats['auto_applied']++;
            }
        }

        return $stats;
    }

    /**
     * Query registrate con zero risultati, sopra la frequenza minima.
     */
    protected function getZeroResultQueries()
    {
        $sql = 'SELECT search_query, search_count
                FROM `' . _DB_PREFIX_ . 'smartsearch_stats`
                WHERE id_shop = ' . $this->idShop . '
                  AND id_lang = ' . $this->idLang . '
                  AND results_count = 0
                  AND search_count >= ' . $this->minFreq . '
                ORDER BY search_count DESC
                LIMIT ' . $this->maxQueries;

        return Db::getInstance()->executeS($sql) ?: array();
    }

    /**
     * Costruisce il vocabolario dei token dal catalogo indicizzato.
     * Usa name_only_content (nome + brand + reference) per privilegiare i
     * termini ad alto valore ed evitare rumore dalle descrizioni lunghe.
     */
    protected function buildVocabulary()
    {
        $sql = 'SELECT name_only_content
                FROM `' . _DB_PREFIX_ . 'smartsearch_index`
                WHERE id_shop = ' . $this->idShop . '
                  AND id_lang = ' . $this->idLang . '
                  AND active = 1';

        $rows = Db::getInstance()->executeS($sql);
        if (!$rows) {
            return;
        }

        foreach ($rows as $row) {
            foreach ($this->tokenize($row['name_only_content']) as $token) {
                if (!isset($this->vocab[$token])) {
                    $this->vocab[$token] = 0;
                }
                $this->vocab[$token]++;
            }
        }

        // Indici di supporto per limitare i confronti Levenshtein
        foreach (array_keys($this->vocab) as $token) {
            $first = mb_substr($token, 0, 1);
            $this->vocabByFirst[$first][] = $token;

            $mp = metaphone($token);
            if ($mp !== '') {
                $this->vocabByPhonetic[$mp][] = $token;
            }
        }
    }

    /**
     * Carica le query di successo (results_count > 0) come possibili target.
     */
    protected function loadSuccessfulQueries()
    {
        $sql = 'SELECT search_query
                FROM `' . _DB_PREFIX_ . 'smartsearch_stats`
                WHERE id_shop = ' . $this->idShop . '
                  AND id_lang = ' . $this->idLang . '
                  AND results_count > 0
                  AND search_count >= 1
                ORDER BY search_count DESC
                LIMIT 5000';

        $rows = Db::getInstance()->executeS($sql);
        if (!$rows) {
            return;
        }

        foreach ($rows as $row) {
            $q = $this->normalize($row['search_query']);
            // Interessano soprattutto le query di una sola parola come target pulito
            if ($q !== '' && mb_strpos($q, ' ') === false && mb_strlen($q) >= 3) {
                $this->successful[$q] = true;
            }
        }
    }

    /**
     * Trova la migliore correzione per una query fallita.
     * Restituisce [source_term, target_term, confidence, type] oppure null.
     *
     * Strategia (alta precisione):
     *  - Query di una parola: cerca il token di catalogo piu' vicino.
     *  - Query multi-parola: corregge la singola parola "colpevole"
     *    (l'unica non presente nel vocabolario).
     */
    protected function findBestCorrection($query)
    {
        $words = array_values(array_filter(explode(' ', $query), function ($w) {
            return mb_strlen($w) >= 2;
        }));

        if (empty($words)) {
            return null;
        }

        if (count($words) === 1) {
            return $this->matchWord($words[0]);
        }

        // Multi-parola: individua le parole non riconosciute
        $unknown = array();
        foreach ($words as $w) {
            if (!isset($this->vocab[$w]) && !isset($this->successful[$w])) {
                $unknown[] = $w;
            }
        }

        // Correggiamo solo se c'e' esattamente UNA parola sconosciuta (ambiguita' minima)
        if (count($unknown) === 1) {
            return $this->matchWord($unknown[0]);
        }

        return null;
    }

    /**
     * Cerca il miglior token/target per una singola parola sconosciuta.
     *
     * @return array|null [source, target, confidence, type]
     */
    protected function matchWord($word)
    {
        $word = mb_strtolower($word);
        $len = mb_strlen($word);
        if ($len < 3) {
            return null;
        }

        // Insieme ristretto di candidati: stessa iniziale + stesso codice fonetico
        $candidates = array();
        $first = mb_substr($word, 0, 1);
        if (isset($this->vocabByFirst[$first])) {
            $candidates = $this->vocabByFirst[$first];
        }
        $mp = metaphone($word);
        if ($mp !== '' && isset($this->vocabByPhonetic[$mp])) {
            $candidates = array_merge($candidates, $this->vocabByPhonetic[$mp]);
        }
        if (empty($candidates)) {
            return null;
        }
        $candidates = array_unique($candidates);

        $bestToken = null;
        $bestConfidence = 0.0;

        foreach ($candidates as $token) {
            if ($token === $word) {
                continue;
            }
            $tokenLen = mb_strlen($token);

            // Salta se le lunghezze sono troppo diverse (>40%)
            if (abs($tokenLen - $len) > max($len, $tokenLen) * 0.4) {
                continue;
            }

            $distance = levenshtein($word, $token);
            $maxLen = max($len, $tokenLen);
            if ($maxLen === 0) {
                continue;
            }

            $similarity = 1 - ($distance / $maxLen);

            // Bonus fonetico
            $confidence = $similarity;
            if ($mp !== '' && $mp === metaphone($token)) {
                $confidence += 0.10;
            }
            if ($len >= 4 && soundex($word) === soundex($token)) {
                $confidence += 0.05;
            }

            // Bonus se il token e' molto comune nel catalogo (target affidabile)
            if (isset($this->vocab[$token]) && $this->vocab[$token] >= 5) {
                $confidence += 0.03;
            }

            $confidence = $this->clamp01($confidence);

            if ($confidence > $bestConfidence) {
                $bestConfidence = $confidence;
                $bestToken = $token;
            }
        }

        if ($bestToken === null) {
            return null;
        }

        return array($word, $bestToken, $bestConfidence, 'catalog');
    }

    /**
     * Inserisce/aggiorna un candidato sinonimo.
     */
    protected function upsertCandidate($source, $target, $confidence, $type, $occurrences, $status)
    {
        $now = date('Y-m-d H:i:s');
        $confDec = number_format($this->clamp01($confidence), 4, '.', '');

        // Non sovrascrivere un candidato gia' revisionato dall'utente
        $existingStatus = Db::getInstance()->getValue(
            'SELECT status FROM `' . _DB_PREFIX_ . 'smartsearch_synonym_candidates`
             WHERE source_term = \'' . pSQL($source) . '\'
               AND target_term = \'' . pSQL($target) . '\'
               AND id_shop = ' . $this->idShop
        );

        if ($existingStatus === 'approved' || $existingStatus === 'rejected') {
            return false;
        }

        $sql = 'INSERT INTO `' . _DB_PREFIX_ . 'smartsearch_synonym_candidates`
                (source_term, target_term, confidence, source_type, occurrences, status, id_lang, id_shop, date_add, date_upd)
                VALUES (
                    \'' . pSQL($source) . '\',
                    \'' . pSQL($target) . '\',
                    ' . $confDec . ',
                    \'' . pSQL($type) . '\',
                    ' . (int) $occurrences . ',
                    \'' . pSQL($status) . '\',
                    ' . $this->idLang . ',
                    ' . $this->idShop . ',
                    \'' . pSQL($now) . '\',
                    \'' . pSQL($now) . '\'
                )
                ON DUPLICATE KEY UPDATE
                    confidence = VALUES(confidence),
                    source_type = VALUES(source_type),
                    occurrences = VALUES(occurrences),
                    status = VALUES(status),
                    date_upd = VALUES(date_upd)';

        return (bool) Db::getInstance()->execute($sql);
    }

    /**
     * Applica un sinonimo appreso alla tabella smartsearch_synonyms.
     * word = termine digitato (spesso errato), synonyms = termine corretto.
     * Se la parola esiste gia', aggiunge il target alla lista senza duplicare.
     */
    protected function applyToSynonyms($source, $target)
    {
        $now = date('Y-m-d H:i:s');

        $existing = Db::getInstance()->getRow(
            'SELECT id_smartsearch_synonym, synonyms FROM `' . _DB_PREFIX_ . 'smartsearch_synonyms`
             WHERE word = \'' . pSQL($source) . '\' AND id_shop = ' . $this->idShop
        );

        if ($existing) {
            $current = array_filter(array_map('trim', explode(',', $existing['synonyms'])));
            if (in_array($target, $current, true)) {
                return true; // gia' presente
            }
            $current[] = $target;
            return (bool) Db::getInstance()->update(
                'smartsearch_synonyms',
                array('synonyms' => pSQL(implode(', ', $current)), 'active' => 1, 'date_upd' => pSQL($now)),
                'id_smartsearch_synonym = ' . (int) $existing['id_smartsearch_synonym']
            );
        }

        return (bool) Db::getInstance()->insert('smartsearch_synonyms', array(
            'word' => pSQL($source),
            'synonyms' => pSQL($target),
            'id_shop' => $this->idShop,
            'active' => 1,
            'date_add' => pSQL($now),
            'date_upd' => pSQL($now),
        ));
    }

    /**
     * Verifica se esiste gia' un sinonimo (attivo) per la parola.
     */
    protected function synonymExists($word)
    {
        return (bool) Db::getInstance()->getValue(
            'SELECT 1 FROM `' . _DB_PREFIX_ . 'smartsearch_synonyms`
             WHERE word = \'' . pSQL($word) . '\' AND id_shop = ' . $this->idShop . ' AND active = 1'
        );
    }

    /**
     * Tokenizza una stringa in parole significative.
     */
    protected function tokenize($text)
    {
        $text = $this->normalize($text);
        if ($text === '') {
            return array();
        }
        $tokens = array();
        foreach (explode(' ', $text) as $t) {
            if (mb_strlen($t) >= 3 && !is_numeric($t)) {
                $tokens[$t] = true;
            }
        }
        return array_keys($tokens);
    }

    /**
     * Normalizza una stringa: minuscolo, no simboli, spazi singoli.
     */
    protected function normalize($text)
    {
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', (string) $text);
        $text = mb_strtolower(trim($text));
        $text = preg_replace('/\s+/', ' ', $text);
        return $text;
    }

    /**
     * @param float $v
     * @return float 0..1
     */
    protected function clamp01($v)
    {
        if ($v < 0) {
            return 0.0;
        }
        if ($v > 1) {
            return 1.0;
        }
        return (float) $v;
    }
}
