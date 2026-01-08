<?php
/**
 * SmartSearch 2.0 - Admin Controller per Product Boosting
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class AdminSmartSearchBoostController extends ModuleAdminController
{
    public function __construct()
    {
        $this->table = 'smartsearch_boost';
        $this->className = 'SmartSearchBoost';
        $this->identifier = 'id_smartsearch_boost';
        $this->lang = false;
        $this->bootstrap = true;
        $this->addRowAction('edit');
        $this->addRowAction('delete');

        parent::__construct();

        $this->bulk_actions = [
            'delete' => [
                'text' => $this->l('Delete selected'),
                'confirm' => $this->l('Are you sure?'),
            ],
            'enableSelection' => [
                'text' => $this->l('Enable selection'),
            ],
            'disableSelection' => [
                'text' => $this->l('Disable selection'),
            ],
        ];

        $this->fields_list = [
            'id_smartsearch_boost' => [
                'title' => $this->l('ID'),
                'align' => 'center',
                'class' => 'fixed-width-xs',
            ],
            'product_name' => [
                'title' => $this->l('Product'),
                'filter_key' => 'pl!name',
            ],
            'boost_value' => [
                'title' => $this->l('Boost Value'),
                'align' => 'center',
                'class' => 'fixed-width-sm',
                'type' => 'float',
                'suffix' => 'x',
            ],
            'keywords' => [
                'title' => $this->l('Keywords'),
                'maxlength' => 50,
            ],
            'date_start' => [
                'title' => $this->l('Start Date'),
                'type' => 'datetime',
                'align' => 'center',
            ],
            'date_end' => [
                'title' => $this->l('End Date'),
                'type' => 'datetime',
                'align' => 'center',
            ],
            'active' => [
                'title' => $this->l('Active'),
                'active' => 'status',
                'type' => 'bool',
                'align' => 'center',
                'class' => 'fixed-width-sm',
            ],
        ];

        $this->_select = 'pl.name AS product_name';
        $this->_join = '
            LEFT JOIN `' . _DB_PREFIX_ . 'product_lang` pl
                ON (a.id_product = pl.id_product
                AND pl.id_lang = ' . (int)$this->context->language->id . '
                AND pl.id_shop = ' . (int)$this->context->shop->id . ')
        ';
        $this->_where = 'AND a.id_shop = ' . (int)$this->context->shop->id;
        $this->_orderBy = 'boost_value';
        $this->_orderWay = 'DESC';
    }

    public function renderForm()
    {
        // Get products for dropdown
        $products = Product::getProducts(
            $this->context->language->id,
            0,
            0,
            'name',
            'ASC',
            false,
            true
        );

        $productOptions = [];
        foreach ($products as $product) {
            $productOptions[] = [
                'id' => $product['id_product'],
                'name' => $product['name'] . ' (ID: ' . $product['id_product'] . ')',
            ];
        }

        $this->fields_form = [
            'legend' => [
                'title' => $this->l('Product Boost'),
                'icon' => 'icon-rocket',
            ],
            'input' => [
                [
                    'type' => 'select',
                    'label' => $this->l('Product'),
                    'name' => 'id_product',
                    'required' => true,
                    'options' => [
                        'query' => $productOptions,
                        'id' => 'id',
                        'name' => 'name',
                    ],
                    'desc' => $this->l('Select the product to boost'),
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('Boost Value'),
                    'name' => 'boost_value',
                    'required' => true,
                    'class' => 'fixed-width-sm',
                    'suffix' => 'x',
                    'desc' => $this->l('Multiplier for search score. Examples: 1.5 = 50% boost, 2.0 = double score, 3.0 = triple score'),
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('Keywords'),
                    'name' => 'keywords',
                    'desc' => $this->l('Optional: Comma-separated keywords. Boost only applies when user searches for these terms. Leave empty to boost for all searches.'),
                ],
                [
                    'type' => 'datetime',
                    'label' => $this->l('Start Date'),
                    'name' => 'date_start',
                    'desc' => $this->l('Optional: When should this boost start? Leave empty for immediate effect.'),
                ],
                [
                    'type' => 'datetime',
                    'label' => $this->l('End Date'),
                    'name' => 'date_end',
                    'desc' => $this->l('Optional: When should this boost end? Leave empty for no expiration.'),
                ],
                [
                    'type' => 'switch',
                    'label' => $this->l('Active'),
                    'name' => 'active',
                    'is_bool' => true,
                    'values' => [
                        ['id' => 'active_on', 'value' => 1, 'label' => $this->l('Yes')],
                        ['id' => 'active_off', 'value' => 0, 'label' => $this->l('No')],
                    ],
                ],
            ],
            'submit' => [
                'title' => $this->l('Save'),
            ],
        ];

        return parent::renderForm();
    }

    public function processSave()
    {
        $id = (int)Tools::getValue('id_smartsearch_boost');
        $idProduct = (int)Tools::getValue('id_product');
        $boostValue = (float)Tools::getValue('boost_value');
        $keywords = pSQL(Tools::getValue('keywords'));
        $dateStart = Tools::getValue('date_start') ?: null;
        $dateEnd = Tools::getValue('date_end') ?: null;
        $active = (int)Tools::getValue('active');

        // Validation
        if (!$idProduct) {
            $this->errors[] = $this->l('Please select a product.');
            return false;
        }

        if ($boostValue < 0.1 || $boostValue > 100) {
            $this->errors[] = $this->l('Boost value must be between 0.1 and 100.');
            return false;
        }

        // Check for duplicates
        $existing = Db::getInstance()->getValue('
            SELECT id_smartsearch_boost
            FROM `' . _DB_PREFIX_ . 'smartsearch_boost`
            WHERE id_product = ' . $idProduct . '
            AND id_shop = ' . (int)$this->context->shop->id . '
            AND id_smartsearch_boost != ' . $id
        );

        if ($existing) {
            $this->errors[] = $this->l('This product already has a boost configured.');
            return false;
        }

        $now = date('Y-m-d H:i:s');

        if ($id) {
            // Update
            $result = Db::getInstance()->update('smartsearch_boost', [
                'id_product' => $idProduct,
                'boost_value' => $boostValue,
                'keywords' => $keywords,
                'date_start' => $dateStart,
                'date_end' => $dateEnd,
                'active' => $active,
                'date_upd' => $now,
            ], 'id_smartsearch_boost = ' . $id);
        } else {
            // Insert
            $result = Db::getInstance()->insert('smartsearch_boost', [
                'id_product' => $idProduct,
                'boost_value' => $boostValue,
                'keywords' => $keywords,
                'id_shop' => (int)$this->context->shop->id,
                'active' => $active,
                'date_start' => $dateStart,
                'date_end' => $dateEnd,
                'date_add' => $now,
                'date_upd' => $now,
            ]);
        }

        if ($result) {
            $this->confirmations[] = $this->l('Boost saved successfully.');
            // Redirect to list
            Tools::redirectAdmin($this->context->link->getAdminLink('AdminSmartSearchBoost'));
        } else {
            $this->errors[] = $this->l('Error saving boost.');
        }

        return $result;
    }

    public function processDelete()
    {
        $id = (int)Tools::getValue('id_smartsearch_boost');

        if ($id) {
            Db::getInstance()->delete('smartsearch_boost', 'id_smartsearch_boost = ' . $id);
            $this->confirmations[] = $this->l('Boost deleted.');
        }

        return true;
    }

    public function processStatus()
    {
        $id = (int)Tools::getValue('id_smartsearch_boost');

        if ($id) {
            $current = Db::getInstance()->getValue('
                SELECT active FROM `' . _DB_PREFIX_ . 'smartsearch_boost`
                WHERE id_smartsearch_boost = ' . $id
            );

            Db::getInstance()->update('smartsearch_boost', [
                'active' => !$current,
                'date_upd' => date('Y-m-d H:i:s'),
            ], 'id_smartsearch_boost = ' . $id);
        }

        Tools::redirectAdmin($this->context->link->getAdminLink('AdminSmartSearchBoost'));
    }

    public function renderList()
    {
        // Add "Add new" button
        $this->page_header_toolbar_btn['new'] = [
            'href' => self::$currentIndex . '&add' . $this->table . '&token=' . $this->token,
            'desc' => $this->l('Add new boost'),
            'icon' => 'process-icon-new',
        ];

        return parent::renderList();
    }

    public function initPageHeaderToolbar()
    {
        if (empty($this->display)) {
            $this->page_header_toolbar_btn['new_boost'] = [
                'href' => self::$currentIndex . '&add' . $this->table . '&token=' . $this->token,
                'desc' => $this->l('Add new boost'),
                'icon' => 'process-icon-new',
            ];
        }

        parent::initPageHeaderToolbar();
    }
}
