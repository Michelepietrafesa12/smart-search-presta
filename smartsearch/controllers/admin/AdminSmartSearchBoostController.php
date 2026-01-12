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

    /**
     * AJAX endpoint per cercare prodotti
     */
    public function ajaxProcessSearchProducts()
    {
        $query = Tools::getValue('q', '');
        $query = trim($query);

        if (strlen($query) < 2) {
            die(json_encode(['products' => []]));
        }

        $idLang = (int)$this->context->language->id;
        $idShop = (int)$this->context->shop->id;

        $sql = '
            SELECT p.id_product, pl.name, p.reference, m.name as manufacturer_name
            FROM ' . _DB_PREFIX_ . 'product p
            INNER JOIN ' . _DB_PREFIX_ . 'product_lang pl ON p.id_product = pl.id_product
                AND pl.id_lang = ' . $idLang . ' AND pl.id_shop = ' . $idShop . '
            INNER JOIN ' . _DB_PREFIX_ . 'product_shop ps ON p.id_product = ps.id_product
                AND ps.id_shop = ' . $idShop . '
            LEFT JOIN ' . _DB_PREFIX_ . 'manufacturer m ON p.id_manufacturer = m.id_manufacturer
            WHERE ps.active = 1
            AND (
                pl.name LIKE \'%' . pSQL($query) . '%\'
                OR p.reference LIKE \'%' . pSQL($query) . '%\'
                OR p.id_product = ' . (int)$query . '
            )
            ORDER BY pl.name ASC
            LIMIT 50
        ';

        $products = Db::getInstance()->executeS($sql);

        $results = [];
        if ($products) {
            foreach ($products as $product) {
                $label = $product['name'];
                if ($product['reference']) {
                    $label .= ' [' . $product['reference'] . ']';
                }
                if ($product['manufacturer_name']) {
                    $label .= ' - ' . $product['manufacturer_name'];
                }
                $label .= ' (ID: ' . $product['id_product'] . ')';

                $results[] = [
                    'id' => (int)$product['id_product'],
                    'name' => $label,
                    'text' => $label, // For Select2 compatibility
                ];
            }
        }

        die(json_encode(['products' => $results]));
    }

    public function renderForm()
    {
        // Get current product info if editing
        $currentProductName = '';
        $currentProductId = 0;

        if ($this->object && $this->object->id_product) {
            $currentProductId = (int)$this->object->id_product;
            $product = new Product($currentProductId, false, $this->context->language->id);
            if (Validate::isLoadedObject($product)) {
                $currentProductName = $product->name . ' (ID: ' . $currentProductId . ')';
            }
        }

        $this->fields_form = [
            'legend' => [
                'title' => $this->l('Product Boost'),
                'icon' => 'icon-rocket',
            ],
            'input' => [
                [
                    'type' => 'html',
                    'label' => $this->l('Product'),
                    'name' => 'product_search_html',
                    'required' => true,
                    'html_content' => $this->getProductSearchHtml($currentProductId, $currentProductName),
                    'desc' => $this->l('Search for a product by name, reference or ID'),
                ],
                [
                    'type' => 'hidden',
                    'name' => 'id_product',
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

    /**
     * Genera l'HTML per il campo di ricerca prodotti con autocomplete
     */
    protected function getProductSearchHtml($currentProductId = 0, $currentProductName = '')
    {
        $ajaxUrl = $this->context->link->getAdminLink('AdminSmartSearchBoost', true, [], ['ajax' => 1, 'action' => 'searchProducts']);

        $html = '
        <div class="product-search-container">
            <input type="text"
                   id="product_search_input"
                   class="form-control"
                   placeholder="' . $this->l('Type to search products...') . '"
                   value="' . htmlspecialchars($currentProductName) . '"
                   autocomplete="off">
            <div id="product_search_results" class="product-search-results"></div>
        </div>
        <style>
            .product-search-container {
                position: relative;
                max-width: 600px;
            }
            .product-search-results {
                position: absolute;
                top: 100%;
                left: 0;
                right: 0;
                background: #fff;
                border: 1px solid #ddd;
                border-top: none;
                max-height: 300px;
                overflow-y: auto;
                z-index: 1000;
                display: none;
                box-shadow: 0 4px 6px rgba(0,0,0,0.1);
            }
            .product-search-results.visible {
                display: block;
            }
            .product-search-item {
                padding: 10px 15px;
                cursor: pointer;
                border-bottom: 1px solid #eee;
            }
            .product-search-item:hover,
            .product-search-item.selected {
                background: #f5f5f5;
            }
            .product-search-item:last-child {
                border-bottom: none;
            }
            .product-search-no-results {
                padding: 10px 15px;
                color: #999;
                font-style: italic;
            }
        </style>
        <script>
        (function() {
            var searchInput = document.getElementById("product_search_input");
            var resultsContainer = document.getElementById("product_search_results");
            var hiddenInput = document.querySelector("input[name=id_product]");
            var searchTimer = null;
            var selectedIndex = -1;
            var currentResults = [];

            // Set initial value
            if (' . ($currentProductId ? 'true' : 'false') . ') {
                hiddenInput.value = ' . (int)$currentProductId . ';
            }

            searchInput.addEventListener("input", function() {
                var query = this.value.trim();
                clearTimeout(searchTimer);

                if (query.length < 2) {
                    hideResults();
                    return;
                }

                searchTimer = setTimeout(function() {
                    searchProducts(query);
                }, 300);
            });

            searchInput.addEventListener("keydown", function(e) {
                if (!resultsContainer.classList.contains("visible")) return;

                var items = resultsContainer.querySelectorAll(".product-search-item");

                if (e.key === "ArrowDown") {
                    e.preventDefault();
                    selectedIndex = Math.min(selectedIndex + 1, items.length - 1);
                    updateSelection(items);
                } else if (e.key === "ArrowUp") {
                    e.preventDefault();
                    selectedIndex = Math.max(selectedIndex - 1, 0);
                    updateSelection(items);
                } else if (e.key === "Enter") {
                    e.preventDefault();
                    if (selectedIndex >= 0 && currentResults[selectedIndex]) {
                        selectProduct(currentResults[selectedIndex]);
                    }
                } else if (e.key === "Escape") {
                    hideResults();
                }
            });

            document.addEventListener("click", function(e) {
                if (!e.target.closest(".product-search-container")) {
                    hideResults();
                }
            });

            function searchProducts(query) {
                var url = "' . $ajaxUrl . '&q=" + encodeURIComponent(query);

                fetch(url)
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        currentResults = data.products || [];
                        selectedIndex = -1;
                        renderResults(currentResults);
                    })
                    .catch(function(err) {
                        console.error("Search error:", err);
                    });
            }

            function renderResults(products) {
                if (products.length === 0) {
                    resultsContainer.innerHTML = "<div class=\"product-search-no-results\">' . $this->l('No products found') . '</div>";
                } else {
                    var html = "";
                    products.forEach(function(product, index) {
                        html += "<div class=\"product-search-item\" data-id=\"" + product.id + "\" data-index=\"" + index + "\">" + escapeHtml(product.name) + "</div>";
                    });
                    resultsContainer.innerHTML = html;

                    // Bind click events
                    resultsContainer.querySelectorAll(".product-search-item").forEach(function(item) {
                        item.addEventListener("click", function() {
                            var idx = parseInt(this.dataset.index);
                            selectProduct(currentResults[idx]);
                        });
                    });
                }
                resultsContainer.classList.add("visible");
            }

            function selectProduct(product) {
                searchInput.value = product.name;
                hiddenInput.value = product.id;
                hideResults();
            }

            function hideResults() {
                resultsContainer.classList.remove("visible");
                selectedIndex = -1;
            }

            function updateSelection(items) {
                items.forEach(function(item, idx) {
                    item.classList.toggle("selected", idx === selectedIndex);
                });
                if (selectedIndex >= 0) {
                    items[selectedIndex].scrollIntoView({ block: "nearest" });
                }
            }

            function escapeHtml(text) {
                var div = document.createElement("div");
                div.textContent = text;
                return div.innerHTML;
            }
        })();
        </script>
        ';

        return $html;
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
