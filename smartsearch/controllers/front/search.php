<?php
/**
 * SmartSearch 2.0 - Controller AJAX per la ricerca dinamica
 * Versione semplificata e robusta
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SmartsearchSearchModuleFrontController extends ModuleFrontController
{
    public function displayAjaxSearch()
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');

        try {
            $query = Tools::getValue('q', '');
            $query = trim(strip_tags($query));

            // Validazione query
            if (mb_strlen($query) < 2) {
                die(json_encode([
                    'products' => [],
                    'categories' => [],
                    'total' => 0,
                    'query' => $query
                ], JSON_UNESCAPED_UNICODE));
            }

            $idLang = (int)$this->context->language->id;
            $idShop = (int)$this->context->shop->id;

            // Ricerca prodotti semplice
            $products = $this->searchProducts($query, $idLang, $idShop);

            // Ricerca categorie
            $categories = $this->searchCategories($query, $idLang, $idShop);

            die(json_encode([
                'products' => $products,
                'categories' => $categories,
                'total' => count($products),
                'query' => $query,
                'facets' => [],
                'banners' => [],
                'did_you_mean' => []
            ], JSON_UNESCAPED_UNICODE));

        } catch (Exception $e) {
            die(json_encode([
                'products' => [],
                'categories' => [],
                'total' => 0,
                'query' => Tools::getValue('q', ''),
                'error' => $e->getMessage()
            ], JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * AJAX endpoint per i prodotti più venduti
     */
    public function displayAjaxBestsellers()
    {
        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');

        try {
            $idLang = (int)$this->context->language->id;
            $idShop = (int)$this->context->shop->id;

            $products = $this->getBestsellers($idLang, $idShop, 12);

            die(json_encode([
                'products' => $products,
                'total' => count($products)
            ], JSON_UNESCAPED_UNICODE));

        } catch (Exception $e) {
            die(json_encode([
                'products' => [],
                'total' => 0,
                'error' => $e->getMessage()
            ], JSON_UNESCAPED_UNICODE));
        }
    }

    /**
     * Ottieni i prodotti più venduti dal database ordini
     */
    protected function getBestsellers($idLang, $idShop, $limit = 12)
    {
        // Query per trovare i prodotti più venduti basandosi sugli ordini
        $sql = '
            SELECT
                p.id_product,
                pl.name,
                pl.link_rewrite,
                pl.description_short,
                p.reference,
                p.id_category_default,
                p.id_manufacturer,
                m.name as manufacturer_name,
                cl.name as category_name,
                (SELECT id_image FROM ' . _DB_PREFIX_ . 'image i WHERE i.id_product = p.id_product AND i.cover = 1 LIMIT 1) as id_image,
                IFNULL(SUM(od.product_quantity), 0) as total_sold
            FROM ' . _DB_PREFIX_ . 'product p
            INNER JOIN ' . _DB_PREFIX_ . 'product_lang pl
                ON p.id_product = pl.id_product
                AND pl.id_lang = ' . (int)$idLang . '
                AND pl.id_shop = ' . (int)$idShop . '
            INNER JOIN ' . _DB_PREFIX_ . 'product_shop ps
                ON p.id_product = ps.id_product
                AND ps.id_shop = ' . (int)$idShop . '
            LEFT JOIN ' . _DB_PREFIX_ . 'manufacturer m
                ON p.id_manufacturer = m.id_manufacturer
            LEFT JOIN ' . _DB_PREFIX_ . 'category_lang cl
                ON p.id_category_default = cl.id_category
                AND cl.id_lang = ' . (int)$idLang . '
            LEFT JOIN ' . _DB_PREFIX_ . 'order_detail od
                ON od.product_id = p.id_product
            LEFT JOIN ' . _DB_PREFIX_ . 'orders o
                ON o.id_order = od.id_order
                AND o.valid = 1
            WHERE p.active = 1
            AND ps.active = 1
            GROUP BY p.id_product
            ORDER BY total_sold DESC, pl.name ASC
            LIMIT ' . (int)$limit;

        $results = Db::getInstance()->executeS($sql);

        if (!$results) {
            return [];
        }

        $products = [];
        foreach ($results as $row) {
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
            if (!empty($row['id_image'])) {
                $imageUrl = $this->context->link->getImageLink(
                    $row['link_rewrite'],
                    $row['id_image'],
                    ImageType::getFormattedName('home')
                );
            }

            // Prezzo
            $priceDisplay = Product::getPriceStatic($row['id_product'], true);
            $priceOldDisplay = Product::getPriceStatic($row['id_product'], true, null, 6, null, false, false);

            $products[] = [
                'id' => (int)$row['id_product'],
                'name' => $row['name'],
                'url' => $productUrl,
                'image' => $imageUrl,
                'price' => Tools::displayPrice($priceDisplay),
                'price_raw' => $priceDisplay,
                'price_old' => ($priceOldDisplay > $priceDisplay) ? Tools::displayPrice($priceOldDisplay) : '',
                'price_old_raw' => ($priceOldDisplay > $priceDisplay) ? $priceOldDisplay : 0,
                'description' => mb_substr(strip_tags($row['description_short'] ?? ''), 0, 100),
                'category' => $row['category_name'] ?? '',
                'manufacturer' => $row['manufacturer_name'] ?? '',
                'reference' => $row['reference'] ?? '',
                'in_stock' => StockAvailable::getQuantityAvailableByProduct($row['id_product']) > 0,
                'total_sold' => (int)$row['total_sold']
            ];
        }

        return $products;
    }

    /**
     * Compatibilità: se chiamato senza action=search
     */
    public function initContent()
    {
        if (Tools::getValue('ajax') || Tools::isSubmit('ajax')) {
            $this->displayAjaxSearch();
        }
        parent::initContent();
    }

    /**
     * Ricerca prodotti semplice nel database
     */
    protected function searchProducts($query, $idLang, $idShop)
    {
        $words = explode(' ', $query);
        $conditions = [];

        foreach ($words as $word) {
            if (mb_strlen($word) >= 2) {
                $word = pSQL($word);
                $conditions[] = "(
                    pl.name LIKE '%{$word}%'
                    OR pl.description_short LIKE '%{$word}%'
                    OR p.reference LIKE '%{$word}%'
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
                pl.link_rewrite,
                pl.description_short,
                p.reference,
                p.id_category_default,
                p.id_manufacturer,
                m.name as manufacturer_name,
                cl.name as category_name,
                (SELECT id_image FROM ' . _DB_PREFIX_ . 'image i WHERE i.id_product = p.id_product AND i.cover = 1 LIMIT 1) as id_image
            FROM ' . _DB_PREFIX_ . 'product p
            INNER JOIN ' . _DB_PREFIX_ . 'product_lang pl
                ON p.id_product = pl.id_product
                AND pl.id_lang = ' . (int)$idLang . '
                AND pl.id_shop = ' . (int)$idShop . '
            INNER JOIN ' . _DB_PREFIX_ . 'product_shop ps
                ON p.id_product = ps.id_product
                AND ps.id_shop = ' . (int)$idShop . '
            LEFT JOIN ' . _DB_PREFIX_ . 'manufacturer m
                ON p.id_manufacturer = m.id_manufacturer
            LEFT JOIN ' . _DB_PREFIX_ . 'category_lang cl
                ON p.id_category_default = cl.id_category
                AND cl.id_lang = ' . (int)$idLang . '
            WHERE p.active = 1
            AND ps.active = 1
            AND (' . implode(' AND ', $conditions) . ')
            ORDER BY pl.name ASC
            LIMIT 20';

        $results = Db::getInstance()->executeS($sql);

        if (!$results) {
            return [];
        }

        $products = [];
        foreach ($results as $row) {
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
            if (!empty($row['id_image'])) {
                $imageUrl = $this->context->link->getImageLink(
                    $row['link_rewrite'],
                    $row['id_image'],
                    ImageType::getFormattedName('home')
                );
            }

            // Prezzo
            $priceDisplay = Product::getPriceStatic($row['id_product'], true);
            $priceOldDisplay = Product::getPriceStatic($row['id_product'], true, null, 6, null, false, false);

            $products[] = [
                'id' => (int)$row['id_product'],
                'name' => $row['name'],
                'url' => $productUrl,
                'image' => $imageUrl,
                'price' => Tools::displayPrice($priceDisplay),
                'price_raw' => $priceDisplay,
                'price_old' => ($priceOldDisplay > $priceDisplay) ? Tools::displayPrice($priceOldDisplay) : '',
                'price_old_raw' => ($priceOldDisplay > $priceDisplay) ? $priceOldDisplay : 0,
                'description' => mb_substr(strip_tags($row['description_short'] ?? ''), 0, 100),
                'category' => $row['category_name'] ?? '',
                'manufacturer' => $row['manufacturer_name'] ?? '',
                'reference' => $row['reference'] ?? '',
                'in_stock' => StockAvailable::getQuantityAvailableByProduct($row['id_product']) > 0
            ];
        }

        return $products;
    }

    /**
     * Ricerca categorie
     */
    protected function searchCategories($query, $idLang, $idShop)
    {
        $sql = '
            SELECT c.id_category, cl.name, cl.link_rewrite
            FROM ' . _DB_PREFIX_ . 'category c
            INNER JOIN ' . _DB_PREFIX_ . 'category_lang cl
                ON c.id_category = cl.id_category
                AND cl.id_lang = ' . (int)$idLang . '
                AND cl.id_shop = ' . (int)$idShop . '
            INNER JOIN ' . _DB_PREFIX_ . 'category_shop cs
                ON c.id_category = cs.id_category
                AND cs.id_shop = ' . (int)$idShop . '
            WHERE c.active = 1
            AND c.id_category > 2
            AND cl.name LIKE \'%' . pSQL($query) . '%\'
            ORDER BY cl.name ASC
            LIMIT 5';

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
     * Ottieni filtri dalla richiesta
     */
    protected function getFiltersFromRequest()
    {
        $filters = [];

        $categoryIds = Tools::getValue('category', '');
        if (!empty($categoryIds)) {
            $filters['category'] = array_map('intval', explode(',', $categoryIds));
        }

        $manufacturerIds = Tools::getValue('manufacturer', '');
        if (!empty($manufacturerIds)) {
            $filters['manufacturer'] = array_map('intval', explode(',', $manufacturerIds));
        }

        $inStock = Tools::getValue('in_stock', '');
        if ($inStock !== '') {
            $filters['in_stock'] = (bool)$inStock;
        }

        return $filters;
    }
}
