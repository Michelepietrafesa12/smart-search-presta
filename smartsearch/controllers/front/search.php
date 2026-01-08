<?php
/**
 * SmartSearch - Controller AJAX per la ricerca dinamica
 */

class SmartSearchSearchModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    public $ajax = true;

    /** @var bool */
    public $ssl = true;

    /**
     * Gestisce la richiesta di ricerca
     */
    public function initContent()
    {
        parent::initContent();

        // Verifica che sia una richiesta AJAX
        if (!$this->ajax) {
            die(json_encode(['error' => 'Invalid request']));
        }

        $query = Tools::getValue('q', '');
        $query = trim(strip_tags($query));

        // Validazione query
        $minChars = (int)Configuration::get('SMARTSEARCH_MIN_CHARS');
        if (strlen($query) < $minChars) {
            die(json_encode(['products' => [], 'categories' => [], 'suggestions' => []]));
        }

        $maxResults = (int)Configuration::get('SMARTSEARCH_MAX_RESULTS');
        $idLang = (int)$this->context->language->id;
        $idShop = (int)$this->context->shop->id;

        // Esegui ricerca
        $products = $this->searchProducts($query, $idLang, $idShop, $maxResults);
        $categories = $this->searchCategories($query, $idLang, $idShop, 3);
        $suggestions = $this->getSuggestions($query, $idLang, $idShop);

        // Salva statistiche
        $this->saveSearchStats($query, count($products), $idLang, $idShop);

        // Restituisci risultati
        die(json_encode([
            'products' => $products,
            'categories' => $categories,
            'suggestions' => $suggestions,
            'total' => count($products),
            'query' => $query
        ]));
    }

    /**
     * Ricerca prodotti
     */
    protected function searchProducts($query, $idLang, $idShop, $limit)
    {
        $query = pSQL($query);
        $words = explode(' ', $query);

        // Costruisci condizioni LIKE per ogni parola
        $conditions = [];
        foreach ($words as $word) {
            $word = trim($word);
            if (strlen($word) >= 2) {
                $conditions[] = "(
                    pl.name LIKE '%" . pSQL($word) . "%'
                    OR pl.description_short LIKE '%" . pSQL($word) . "%'
                    OR pl.description LIKE '%" . pSQL($word) . "%'
                    OR p.reference LIKE '%" . pSQL($word) . "%'
                    OR p.ean13 LIKE '%" . pSQL($word) . "%'
                    OR p.upc LIKE '%" . pSQL($word) . "%'
                    OR m.name LIKE '%" . pSQL($word) . "%'
                )";
            }
        }

        if (empty($conditions)) {
            return [];
        }

        $sql = '
            SELECT DISTINCT
                p.id_product,
                pl.name,
                pl.description_short,
                pl.link_rewrite,
                p.reference,
                p.price,
                p.id_category_default,
                cl.name AS category_name,
                m.name AS manufacturer_name,
                i.id_image,
                (
                    CASE
                        WHEN pl.name LIKE \'' . pSQL($query) . '%\' THEN 100
                        WHEN pl.name LIKE \'%' . pSQL($query) . '%\' THEN 80
                        WHEN p.reference LIKE \'' . pSQL($query) . '%\' THEN 90
                        WHEN pl.description_short LIKE \'%' . pSQL($query) . '%\' THEN 60
                        ELSE 40
                    END
                ) AS relevance
            FROM `' . _DB_PREFIX_ . 'product` p
            INNER JOIN `' . _DB_PREFIX_ . 'product_lang` pl
                ON p.id_product = pl.id_product
                AND pl.id_lang = ' . (int)$idLang . '
                AND pl.id_shop = ' . (int)$idShop . '
            INNER JOIN `' . _DB_PREFIX_ . 'product_shop` ps
                ON p.id_product = ps.id_product
                AND ps.id_shop = ' . (int)$idShop . '
            LEFT JOIN `' . _DB_PREFIX_ . 'category_lang` cl
                ON p.id_category_default = cl.id_category
                AND cl.id_lang = ' . (int)$idLang . '
                AND cl.id_shop = ' . (int)$idShop . '
            LEFT JOIN `' . _DB_PREFIX_ . 'manufacturer` m
                ON p.id_manufacturer = m.id_manufacturer
            LEFT JOIN `' . _DB_PREFIX_ . 'image` i
                ON p.id_product = i.id_product
                AND i.cover = 1
            WHERE ps.active = 1
            AND ps.visibility IN ("both", "search")
            AND (' . implode(' AND ', $conditions) . ')
            ORDER BY relevance DESC, pl.name ASC
            LIMIT ' . (int)$limit;

        $results = Db::getInstance()->executeS($sql);

        if (!$results) {
            return [];
        }

        $products = [];
        $showPrice = (bool)Configuration::get('SMARTSEARCH_SHOW_PRICE');
        $showImage = (bool)Configuration::get('SMARTSEARCH_SHOW_IMAGE');
        $showDescription = (bool)Configuration::get('SMARTSEARCH_SHOW_DESCRIPTION');
        $showCategory = (bool)Configuration::get('SMARTSEARCH_SHOW_CATEGORY');

        foreach ($results as $row) {
            $product = new Product($row['id_product'], false, $idLang);

            // URL prodotto
            $productUrl = $this->context->link->getProductLink(
                $row['id_product'],
                $row['link_rewrite'],
                null,
                null,
                $idLang
            );

            // Immagine
            $imageUrl = '';
            if ($showImage && $row['id_image']) {
                $imageUrl = $this->context->link->getImageLink(
                    $row['link_rewrite'],
                    $row['id_image'],
                    ImageType::getFormattedName('small')
                );
            }

            // Prezzo
            $price = '';
            $priceOld = '';
            if ($showPrice) {
                $priceDisplay = Product::getPriceStatic($row['id_product'], true);
                $priceOldDisplay = Product::getPriceStatic($row['id_product'], true, null, 6, null, false, false);

                $price = Tools::displayPrice($priceDisplay);
                if ($priceOldDisplay > $priceDisplay) {
                    $priceOld = Tools::displayPrice($priceOldDisplay);
                }
            }

            // Descrizione breve (troncata)
            $description = '';
            if ($showDescription && !empty($row['description_short'])) {
                $description = strip_tags($row['description_short']);
                if (strlen($description) > 100) {
                    $description = substr($description, 0, 97) . '...';
                }
            }

            $products[] = [
                'id' => (int)$row['id_product'],
                'name' => $row['name'],
                'url' => $productUrl,
                'image' => $imageUrl,
                'price' => $price,
                'price_old' => $priceOld,
                'description' => $description,
                'category' => $showCategory ? $row['category_name'] : '',
                'manufacturer' => $row['manufacturer_name'] ?: '',
                'reference' => $row['reference'],
                'in_stock' => StockAvailable::getQuantityAvailableByProduct($row['id_product']) > 0
            ];
        }

        return $products;
    }

    /**
     * Ricerca categorie
     */
    protected function searchCategories($query, $idLang, $idShop, $limit)
    {
        $sql = '
            SELECT c.id_category, cl.name, cl.link_rewrite
            FROM `' . _DB_PREFIX_ . 'category` c
            INNER JOIN `' . _DB_PREFIX_ . 'category_lang` cl
                ON c.id_category = cl.id_category
                AND cl.id_lang = ' . (int)$idLang . '
                AND cl.id_shop = ' . (int)$idShop . '
            INNER JOIN `' . _DB_PREFIX_ . 'category_shop` cs
                ON c.id_category = cs.id_category
                AND cs.id_shop = ' . (int)$idShop . '
            WHERE c.active = 1
            AND c.id_category > 2
            AND cl.name LIKE \'%' . pSQL($query) . '%\'
            ORDER BY cl.name ASC
            LIMIT ' . (int)$limit;

        $results = Db::getInstance()->executeS($sql);

        if (!$results) {
            return [];
        }

        $categories = [];
        foreach ($results as $row) {
            $categories[] = [
                'id' => (int)$row['id_category'],
                'name' => $row['name'],
                'url' => $this->context->link->getCategoryLink(
                    $row['id_category'],
                    $row['link_rewrite'],
                    $idLang
                )
            ];
        }

        return $categories;
    }

    /**
     * Ottieni suggerimenti di ricerca basati su ricerche precedenti
     */
    protected function getSuggestions($query, $idLang, $idShop)
    {
        $sql = '
            SELECT DISTINCT search_query, COUNT(*) as frequency
            FROM `' . _DB_PREFIX_ . 'smartsearch_stats`
            WHERE search_query LIKE \'%' . pSQL($query) . '%\'
            AND search_query != \'' . pSQL($query) . '\'
            AND results_count > 0
            AND id_lang = ' . (int)$idLang . '
            AND id_shop = ' . (int)$idShop . '
            GROUP BY search_query
            ORDER BY frequency DESC
            LIMIT 5';

        $results = Db::getInstance()->executeS($sql);

        if (!$results) {
            return [];
        }

        $suggestions = [];
        foreach ($results as $row) {
            $suggestions[] = $row['search_query'];
        }

        return $suggestions;
    }

    /**
     * Salva statistiche di ricerca
     */
    protected function saveSearchStats($query, $resultsCount, $idLang, $idShop)
    {
        $sql = '
            INSERT INTO `' . _DB_PREFIX_ . 'smartsearch_stats`
            (search_query, results_count, id_lang, id_shop, date_add)
            VALUES (
                \'' . pSQL($query) . '\',
                ' . (int)$resultsCount . ',
                ' . (int)$idLang . ',
                ' . (int)$idShop . ',
                NOW()
            )';

        return Db::getInstance()->execute($sql);
    }
}
