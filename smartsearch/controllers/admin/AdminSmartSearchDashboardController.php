<?php
/**
 * SmartSearch 2.0 - Admin Dashboard Controller
 * Pannello unificato con tre sezioni: Boosting, Banner, Impostazioni
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class AdminSmartSearchDashboardController extends ModuleAdminController
{
    /** @var string Current active tab */
    protected $activeTab = 'settings';

    /** @var string Upload directory for banners */
    protected $uploadDir;

    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();

        $this->uploadDir = _PS_MODULE_DIR_ . 'smartsearch/views/img/banners/';

        // Create upload directory if not exists
        if (!is_dir($this->uploadDir)) {
            @mkdir($this->uploadDir, 0755, true);
        }

        // Get active tab from URL
        $this->activeTab = Tools::getValue('tab', 'settings');
    }

    public function initContent()
    {
        parent::initContent();

        $this->context->smarty->assign([
            'module_dir' => _MODULE_DIR_ . 'smartsearch/',
            'module_version' => $this->module->version,
            'active_tab' => $this->activeTab,
            'tabs_content' => $this->renderTabsContent(),
            'admin_token' => Tools::getAdminTokenLite('AdminSmartSearchDashboard'),
            'current_url' => $this->context->link->getAdminLink('AdminSmartSearchDashboard'),
        ]);

        $this->setTemplate('dashboard.tpl');
    }

    /**
     * Render the main tabs content
     */
    protected function renderTabsContent()
    {
        return [
            'settings' => $this->renderSettingsTab(),
            'boosting' => $this->renderBoostingTab(),
            'banners' => $this->renderBannersTab(),
        ];
    }

    /**
     * Process form submissions
     */
    public function postProcess()
    {
        // Save settings
        if (Tools::isSubmit('submitSmartSearchSettings')) {
            $this->saveSettings();
            $this->confirmations[] = $this->l('Impostazioni salvate con successo!');
            $this->activeTab = 'settings';
        }

        // Clear cache
        if (Tools::isSubmit('clearCache')) {
            $this->clearModuleCache();
            $this->confirmations[] = $this->l('Cache svuotata con successo!');
            $this->activeTab = 'settings';
        }

        // Save boost
        if (Tools::isSubmit('submitBoost')) {
            if ($this->saveBoost()) {
                $this->confirmations[] = $this->l('Boost salvato con successo!');
            }
            $this->activeTab = 'boosting';
        }

        // Delete boost
        if (Tools::getValue('deleteBoost')) {
            $this->deleteBoost((int)Tools::getValue('deleteBoost'));
            $this->confirmations[] = $this->l('Boost eliminato!');
            $this->activeTab = 'boosting';
        }

        // Toggle boost status
        if (Tools::getValue('toggleBoost')) {
            $this->toggleBoostStatus((int)Tools::getValue('toggleBoost'));
            $this->activeTab = 'boosting';
        }

        // Save banner
        if (Tools::isSubmit('submitBanner')) {
            if ($this->saveBanner()) {
                $this->confirmations[] = $this->l('Banner salvato con successo!');
            }
            $this->activeTab = 'banners';
        }

        // Delete banner
        if (Tools::getValue('deleteBanner')) {
            $this->deleteBanner((int)Tools::getValue('deleteBanner'));
            $this->confirmations[] = $this->l('Banner eliminato!');
            $this->activeTab = 'banners';
        }

        // Toggle banner status
        if (Tools::getValue('toggleBanner')) {
            $this->toggleBannerStatus((int)Tools::getValue('toggleBanner'));
            $this->activeTab = 'banners';
        }

        parent::postProcess();
    }

    /**
     * Render Settings Tab
     */
    protected function renderSettingsTab()
    {
        $helper = new HelperForm();
        $helper->module = $this->module;
        $helper->name_controller = 'AdminSmartSearchDashboard';
        $helper->token = Tools::getAdminTokenLite('AdminSmartSearchDashboard');
        $helper->currentIndex = $this->context->link->getAdminLink('AdminSmartSearchDashboard', false) . '&tab=settings';
        $helper->default_form_language = (int)Configuration::get('PS_LANG_DEFAULT');
        $helper->submit_action = 'submitSmartSearchSettings';

        $fields_form = [];

        // General Settings
        $fields_form[0] = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Impostazioni Generali'),
                    'icon' => 'icon-cogs',
                ],
                'input' => [
                    [
                        'type' => 'switch',
                        'label' => $this->l('Abilita Smart Search'),
                        'name' => 'SMARTSEARCH_ENABLED',
                        'is_bool' => true,
                        'values' => [
                            ['id' => 'on', 'value' => 1, 'label' => $this->l('Sì')],
                            ['id' => 'off', 'value' => 0, 'label' => $this->l('No')],
                        ],
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Caratteri minimi'),
                        'name' => 'SMARTSEARCH_MIN_CHARS',
                        'class' => 'fixed-width-sm',
                        'desc' => $this->l('Numero minimo di caratteri per avviare la ricerca'),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Risultati massimi'),
                        'name' => 'SMARTSEARCH_MAX_RESULTS',
                        'class' => 'fixed-width-sm',
                        'desc' => $this->l('Numero massimo di risultati da mostrare'),
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Debounce (ms)'),
                        'name' => 'SMARTSEARCH_DEBOUNCE_TIME',
                        'class' => 'fixed-width-sm',
                        'desc' => $this->l('Tempo di attesa prima di eseguire la ricerca'),
                    ],
                ],
            ],
        ];

        // Algorithm Settings
        $fields_form[1] = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Algoritmi Intelligenti'),
                    'icon' => 'icon-magic',
                ],
                'input' => [
                    [
                        'type' => 'switch',
                        'label' => $this->l('Fuzzy Search'),
                        'name' => 'SMARTSEARCH_FUZZY_ENABLED',
                        'is_bool' => true,
                        'desc' => $this->l('Trova risultati anche con errori di battitura'),
                        'values' => [
                            ['id' => 'on', 'value' => 1, 'label' => $this->l('Sì')],
                            ['id' => 'off', 'value' => 0, 'label' => $this->l('No')],
                        ],
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Sinonimi'),
                        'name' => 'SMARTSEARCH_SYNONYMS_ENABLED',
                        'is_bool' => true,
                        'desc' => $this->l('Usa sinonimi per espandere la ricerca'),
                        'values' => [
                            ['id' => 'on', 'value' => 1, 'label' => $this->l('Sì')],
                            ['id' => 'off', 'value' => 0, 'label' => $this->l('No')],
                        ],
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Filtri Dinamici'),
                        'name' => 'SMARTSEARCH_FACETS_ENABLED',
                        'is_bool' => true,
                        'desc' => $this->l('Mostra filtri per prezzo, marca, categoria'),
                        'values' => [
                            ['id' => 'on', 'value' => 1, 'label' => $this->l('Sì')],
                            ['id' => 'off', 'value' => 0, 'label' => $this->l('No')],
                        ],
                    ],
                ],
            ],
        ];

        // Analytics & Cache
        $fields_form[2] = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Analytics e Cache'),
                    'icon' => 'icon-bar-chart',
                ],
                'input' => [
                    [
                        'type' => 'switch',
                        'label' => $this->l('Abilita Analytics'),
                        'name' => 'SMARTSEARCH_ANALYTICS_ENABLED',
                        'is_bool' => true,
                        'values' => [
                            ['id' => 'on', 'value' => 1, 'label' => $this->l('Sì')],
                            ['id' => 'off', 'value' => 0, 'label' => $this->l('No')],
                        ],
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Webhook URL (n8n)'),
                        'name' => 'SMARTSEARCH_ANALYTICS_WEBHOOK_URL',
                        'class' => 'fixed-width-xxl',
                        'desc' => $this->l('URL webhook per inviare eventi analytics'),
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Abilita Cache'),
                        'name' => 'SMARTSEARCH_CACHE_ENABLED',
                        'is_bool' => true,
                        'values' => [
                            ['id' => 'on', 'value' => 1, 'label' => $this->l('Sì')],
                            ['id' => 'off', 'value' => 0, 'label' => $this->l('No')],
                        ],
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Banner Promozionali'),
                        'name' => 'SMARTSEARCH_BANNERS_ENABLED',
                        'is_bool' => true,
                        'values' => [
                            ['id' => 'on', 'value' => 1, 'label' => $this->l('Sì')],
                            ['id' => 'off', 'value' => 0, 'label' => $this->l('No')],
                        ],
                    ],
                ],
                'buttons' => [
                    [
                        'title' => $this->l('Svuota Cache'),
                        'name' => 'clearCache',
                        'type' => 'submit',
                        'class' => 'btn btn-default',
                        'icon' => 'process-icon-eraser',
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Salva Impostazioni'),
                    'class' => 'btn btn-primary pull-right',
                ],
            ],
        ];

        // Valori correnti
        $helper->fields_value = [
            'SMARTSEARCH_ENABLED' => Configuration::get('SMARTSEARCH_ENABLED'),
            'SMARTSEARCH_MIN_CHARS' => Configuration::get('SMARTSEARCH_MIN_CHARS') ?: 2,
            'SMARTSEARCH_MAX_RESULTS' => Configuration::get('SMARTSEARCH_MAX_RESULTS') ?: 8,
            'SMARTSEARCH_DEBOUNCE_TIME' => Configuration::get('SMARTSEARCH_DEBOUNCE_TIME') ?: 300,
            'SMARTSEARCH_FUZZY_ENABLED' => Configuration::get('SMARTSEARCH_FUZZY_ENABLED'),
            'SMARTSEARCH_SYNONYMS_ENABLED' => Configuration::get('SMARTSEARCH_SYNONYMS_ENABLED'),
            'SMARTSEARCH_FACETS_ENABLED' => Configuration::get('SMARTSEARCH_FACETS_ENABLED'),
            'SMARTSEARCH_ANALYTICS_ENABLED' => Configuration::get('SMARTSEARCH_ANALYTICS_ENABLED'),
            'SMARTSEARCH_ANALYTICS_WEBHOOK_URL' => Configuration::get('SMARTSEARCH_ANALYTICS_WEBHOOK_URL'),
            'SMARTSEARCH_CACHE_ENABLED' => Configuration::get('SMARTSEARCH_CACHE_ENABLED'),
            'SMARTSEARCH_BANNERS_ENABLED' => Configuration::get('SMARTSEARCH_BANNERS_ENABLED'),
        ];

        return $helper->generateForm($fields_form);
    }

    /**
     * Render Boosting Tab
     */
    protected function renderBoostingTab()
    {
        $html = '<div class="panel">';
        $html .= '<div class="panel-heading">';
        $html .= '<i class="icon-rocket"></i> ' . $this->l('Product Boosting');
        $html .= ' <span class="badge">' . $this->l('Aumenta la visibilità dei prodotti nei risultati') . '</span>';
        $html .= '</div>';

        // Add new boost form
        $html .= $this->renderBoostForm();

        // Existing boosts list
        $html .= '<hr><h4>' . $this->l('Boost Attivi') . '</h4>';
        $html .= $this->renderBoostList();

        $html .= '</div>';

        return $html;
    }

    /**
     * Render boost form
     */
    protected function renderBoostForm()
    {
        $products = Product::getProducts(
            $this->context->language->id,
            0,
            100,
            'name',
            'ASC',
            false,
            true
        );

        $html = '<form method="post" class="form-horizontal">';
        $html .= '<input type="hidden" name="tab" value="boosting">';

        $html .= '<div class="row">';
        $html .= '<div class="col-md-4">';
        $html .= '<div class="form-group">';
        $html .= '<label>' . $this->l('Prodotto') . '</label>';
        $html .= '<select name="boost_product" class="form-control" required>';
        $html .= '<option value="">' . $this->l('Seleziona un prodotto...') . '</option>';
        foreach ($products as $product) {
            $html .= '<option value="' . (int)$product['id_product'] . '">';
            $html .= htmlspecialchars($product['name']) . ' (ID: ' . $product['id_product'] . ')';
            $html .= '</option>';
        }
        $html .= '</select>';
        $html .= '</div>';
        $html .= '</div>';

        $html .= '<div class="col-md-2">';
        $html .= '<div class="form-group">';
        $html .= '<label>' . $this->l('Boost Value') . '</label>';
        $html .= '<div class="input-group">';
        $html .= '<input type="number" name="boost_value" class="form-control" value="1.5" min="0.1" max="10" step="0.1" required>';
        $html .= '<span class="input-group-addon">x</span>';
        $html .= '</div>';
        $html .= '<small class="text-muted">1.5 = +50%, 2 = +100%</small>';
        $html .= '</div>';
        $html .= '</div>';

        $html .= '<div class="col-md-3">';
        $html .= '<div class="form-group">';
        $html .= '<label>' . $this->l('Keywords (opzionale)') . '</label>';
        $html .= '<input type="text" name="boost_keywords" class="form-control" placeholder="' . $this->l('es: scarpe, sneakers') . '">';
        $html .= '<small class="text-muted">' . $this->l('Boost solo per queste ricerche') . '</small>';
        $html .= '</div>';
        $html .= '</div>';

        $html .= '<div class="col-md-3">';
        $html .= '<div class="form-group">';
        $html .= '<label>&nbsp;</label>';
        $html .= '<button type="submit" name="submitBoost" class="btn btn-primary btn-block">';
        $html .= '<i class="icon-plus"></i> ' . $this->l('Aggiungi Boost');
        $html .= '</button>';
        $html .= '</div>';
        $html .= '</div>';

        $html .= '</div>';
        $html .= '</form>';

        return $html;
    }

    /**
     * Render boost list
     */
    protected function renderBoostList()
    {
        $boosts = Db::getInstance()->executeS('
            SELECT b.*, pl.name as product_name
            FROM `' . _DB_PREFIX_ . 'smartsearch_boost` b
            LEFT JOIN `' . _DB_PREFIX_ . 'product_lang` pl
                ON (b.id_product = pl.id_product
                AND pl.id_lang = ' . (int)$this->context->language->id . '
                AND pl.id_shop = ' . (int)$this->context->shop->id . ')
            WHERE b.id_shop = ' . (int)$this->context->shop->id . '
            ORDER BY b.boost_value DESC
        ');

        if (empty($boosts)) {
            return '<div class="alert alert-info">' . $this->l('Nessun boost configurato.') . '</div>';
        }

        $html = '<table class="table table-striped">';
        $html .= '<thead><tr>';
        $html .= '<th>' . $this->l('Prodotto') . '</th>';
        $html .= '<th class="text-center">' . $this->l('Boost') . '</th>';
        $html .= '<th>' . $this->l('Keywords') . '</th>';
        $html .= '<th class="text-center">' . $this->l('Stato') . '</th>';
        $html .= '<th class="text-center">' . $this->l('Azioni') . '</th>';
        $html .= '</tr></thead>';
        $html .= '<tbody>';

        foreach ($boosts as $boost) {
            $statusClass = $boost['active'] ? 'success' : 'danger';
            $statusIcon = $boost['active'] ? 'check' : 'times';
            $statusText = $boost['active'] ? $this->l('Attivo') : $this->l('Disattivo');

            $html .= '<tr>';
            $html .= '<td><strong>' . htmlspecialchars($boost['product_name'] ?? 'N/A') . '</strong></td>';
            $html .= '<td class="text-center"><span class="badge badge-primary">' . number_format($boost['boost_value'], 1) . 'x</span></td>';
            $html .= '<td>' . ($boost['keywords'] ? htmlspecialchars($boost['keywords']) : '<em class="text-muted">' . $this->l('Tutte le ricerche') . '</em>') . '</td>';
            $html .= '<td class="text-center"><span class="label label-' . $statusClass . '"><i class="icon-' . $statusIcon . '"></i> ' . $statusText . '</span></td>';
            $html .= '<td class="text-center">';
            $html .= '<a href="' . $this->context->link->getAdminLink('AdminSmartSearchDashboard') . '&tab=boosting&toggleBoost=' . $boost['id_smartsearch_boost'] . '" class="btn btn-default btn-xs" title="' . $this->l('Toggle') . '"><i class="icon-power-off"></i></a> ';
            $html .= '<a href="' . $this->context->link->getAdminLink('AdminSmartSearchDashboard') . '&tab=boosting&deleteBoost=' . $boost['id_smartsearch_boost'] . '" class="btn btn-danger btn-xs" onclick="return confirm(\'' . $this->l('Sei sicuro?') . '\');" title="' . $this->l('Elimina') . '"><i class="icon-trash"></i></a>';
            $html .= '</td>';
            $html .= '</tr>';
        }

        $html .= '</tbody></table>';

        return $html;
    }

    /**
     * Render Banners Tab
     */
    protected function renderBannersTab()
    {
        $html = '<div class="panel">';
        $html .= '<div class="panel-heading">';
        $html .= '<i class="icon-picture-o"></i> ' . $this->l('Banner Promozionali');
        $html .= ' <span class="badge">' . $this->l('Mostra promozioni nei risultati di ricerca') . '</span>';
        $html .= '</div>';

        // Add new banner form
        $html .= $this->renderBannerForm();

        // Existing banners list
        $html .= '<hr><h4>' . $this->l('Banner Attivi') . '</h4>';
        $html .= $this->renderBannerList();

        $html .= '</div>';

        return $html;
    }

    /**
     * Render banner form
     */
    protected function renderBannerForm()
    {
        $html = '<form method="post" enctype="multipart/form-data" class="form-horizontal">';
        $html .= '<input type="hidden" name="tab" value="banners">';

        $html .= '<div class="row">';

        $html .= '<div class="col-md-3">';
        $html .= '<div class="form-group">';
        $html .= '<label>' . $this->l('Nome Banner') . '</label>';
        $html .= '<input type="text" name="banner_name" class="form-control" placeholder="' . $this->l('es: Spedizione 1€') . '" required>';
        $html .= '</div>';
        $html .= '</div>';

        $html .= '<div class="col-md-3">';
        $html .= '<div class="form-group">';
        $html .= '<label>' . $this->l('Immagine Banner') . '</label>';
        $html .= '<input type="file" name="banner_image" class="form-control" accept="image/*" required>';
        $html .= '<small class="text-muted">JPG, PNG, GIF, WEBP - Max 2MB</small>';
        $html .= '</div>';
        $html .= '</div>';

        $html .= '<div class="col-md-2">';
        $html .= '<div class="form-group">';
        $html .= '<label>' . $this->l('Posizione') . '</label>';
        $html .= '<select name="banner_position" class="form-control">';
        $html .= '<option value="top">' . $this->l('Top') . '</option>';
        $html .= '<option value="middle">' . $this->l('Middle (dopo 4 prodotti)') . '</option>';
        $html .= '<option value="bottom">' . $this->l('Bottom') . '</option>';
        $html .= '</select>';
        $html .= '</div>';
        $html .= '</div>';

        $html .= '<div class="col-md-2">';
        $html .= '<div class="form-group">';
        $html .= '<label>' . $this->l('Link (opzionale)') . '</label>';
        $html .= '<input type="url" name="banner_link" class="form-control" placeholder="https://">';
        $html .= '</div>';
        $html .= '</div>';

        $html .= '<div class="col-md-2">';
        $html .= '<div class="form-group">';
        $html .= '<label>&nbsp;</label>';
        $html .= '<button type="submit" name="submitBanner" class="btn btn-success btn-block">';
        $html .= '<i class="icon-plus"></i> ' . $this->l('Aggiungi');
        $html .= '</button>';
        $html .= '</div>';
        $html .= '</div>';

        $html .= '</div>';

        // Keywords row
        $html .= '<div class="row">';
        $html .= '<div class="col-md-12">';
        $html .= '<div class="form-group">';
        $html .= '<label>' . $this->l('Keywords (opzionale)') . '</label>';
        $html .= '<input type="text" name="banner_keywords" class="form-control" placeholder="' . $this->l('es: spedizione, consegna - Lascia vuoto per mostrare sempre') . '">';
        $html .= '</div>';
        $html .= '</div>';
        $html .= '</div>';

        $html .= '</form>';

        return $html;
    }

    /**
     * Render banner list
     */
    protected function renderBannerList()
    {
        $banners = Db::getInstance()->executeS('
            SELECT * FROM `' . _DB_PREFIX_ . 'smartsearch_banners`
            WHERE id_shop = ' . (int)$this->context->shop->id . '
            ORDER BY position ASC, date_add DESC
        ');

        if (empty($banners)) {
            return '<div class="alert alert-info">' . $this->l('Nessun banner configurato.') . '</div>';
        }

        $html = '<div class="row">';

        foreach ($banners as $banner) {
            $statusClass = $banner['active'] ? 'success' : 'danger';
            $statusText = $banner['active'] ? $this->l('Attivo') : $this->l('Disattivo');
            $imgUrl = _MODULE_DIR_ . 'smartsearch/views/img/banners/' . $banner['image'];

            $html .= '<div class="col-md-4">';
            $html .= '<div class="panel panel-default">';
            $html .= '<div class="panel-body text-center">';

            // Image preview
            $html .= '<img src="' . $imgUrl . '" alt="" style="max-width: 100%; max-height: 80px; border-radius: 4px; margin-bottom: 10px;">';

            // Info
            $html .= '<h5><strong>' . htmlspecialchars($banner['name']) . '</strong></h5>';
            $html .= '<p class="text-muted">';
            $html .= '<span class="label label-default">' . ucfirst($banner['position']) . '</span> ';
            $html .= '<span class="label label-' . $statusClass . '">' . $statusText . '</span>';
            $html .= '</p>';

            if ($banner['keywords']) {
                $html .= '<p class="small text-muted">' . $this->l('Keywords:') . ' ' . htmlspecialchars($banner['keywords']) . '</p>';
            }

            // Actions
            $html .= '<div class="btn-group">';
            $html .= '<a href="' . $this->context->link->getAdminLink('AdminSmartSearchDashboard') . '&tab=banners&toggleBanner=' . $banner['id_smartsearch_banner'] . '" class="btn btn-default btn-sm" title="' . $this->l('Toggle') . '"><i class="icon-power-off"></i></a>';
            $html .= '<a href="' . $this->context->link->getAdminLink('AdminSmartSearchDashboard') . '&tab=banners&deleteBanner=' . $banner['id_smartsearch_banner'] . '" class="btn btn-danger btn-sm" onclick="return confirm(\'' . $this->l('Sei sicuro?') . '\');" title="' . $this->l('Elimina') . '"><i class="icon-trash"></i></a>';
            $html .= '</div>';

            $html .= '</div>';
            $html .= '</div>';
            $html .= '</div>';
        }

        $html .= '</div>';

        return $html;
    }

    /**
     * Save settings
     */
    protected function saveSettings()
    {
        $boolConfigs = [
            'SMARTSEARCH_ENABLED',
            'SMARTSEARCH_FUZZY_ENABLED',
            'SMARTSEARCH_SYNONYMS_ENABLED',
            'SMARTSEARCH_FACETS_ENABLED',
            'SMARTSEARCH_ANALYTICS_ENABLED',
            'SMARTSEARCH_CACHE_ENABLED',
            'SMARTSEARCH_BANNERS_ENABLED',
        ];

        $intConfigs = [
            'SMARTSEARCH_MIN_CHARS',
            'SMARTSEARCH_MAX_RESULTS',
            'SMARTSEARCH_DEBOUNCE_TIME',
        ];

        foreach ($boolConfigs as $config) {
            Configuration::updateValue($config, (int)Tools::getValue($config));
        }

        foreach ($intConfigs as $config) {
            Configuration::updateValue($config, (int)Tools::getValue($config));
        }

        // String configs
        Configuration::updateValue('SMARTSEARCH_ANALYTICS_WEBHOOK_URL', Tools::getValue('SMARTSEARCH_ANALYTICS_WEBHOOK_URL'));

        // Clear config cache
        $this->clearModuleCache();
    }

    /**
     * Save boost
     */
    protected function saveBoost()
    {
        $productId = (int)Tools::getValue('boost_product');
        $boostValue = (float)Tools::getValue('boost_value');
        $keywords = pSQL(Tools::getValue('boost_keywords'));

        if (!$productId) {
            $this->errors[] = $this->l('Seleziona un prodotto.');
            return false;
        }

        if ($boostValue < 0.1 || $boostValue > 10) {
            $this->errors[] = $this->l('Il valore del boost deve essere tra 0.1 e 10.');
            return false;
        }

        // Check for existing
        $existing = Db::getInstance()->getValue('
            SELECT id_smartsearch_boost FROM `' . _DB_PREFIX_ . 'smartsearch_boost`
            WHERE id_product = ' . $productId . '
            AND id_shop = ' . (int)$this->context->shop->id
        );

        if ($existing) {
            $this->errors[] = $this->l('Questo prodotto ha già un boost configurato.');
            return false;
        }

        $now = date('Y-m-d H:i:s');

        return Db::getInstance()->insert('smartsearch_boost', [
            'id_product' => $productId,
            'boost_value' => $boostValue,
            'keywords' => $keywords,
            'id_shop' => (int)$this->context->shop->id,
            'active' => 1,
            'date_add' => $now,
            'date_upd' => $now,
        ]);
    }

    /**
     * Delete boost
     */
    protected function deleteBoost($id)
    {
        return Db::getInstance()->delete('smartsearch_boost', 'id_smartsearch_boost = ' . $id . ' AND id_shop = ' . (int)$this->context->shop->id);
    }

    /**
     * Toggle boost status
     */
    protected function toggleBoostStatus($id)
    {
        $current = Db::getInstance()->getValue('
            SELECT active FROM `' . _DB_PREFIX_ . 'smartsearch_boost`
            WHERE id_smartsearch_boost = ' . $id
        );

        return Db::getInstance()->update('smartsearch_boost', [
            'active' => !$current,
            'date_upd' => date('Y-m-d H:i:s'),
        ], 'id_smartsearch_boost = ' . $id);
    }

    /**
     * Save banner
     */
    protected function saveBanner()
    {
        $name = pSQL(Tools::getValue('banner_name'));
        $position = pSQL(Tools::getValue('banner_position'));
        $link = pSQL(Tools::getValue('banner_link'));
        $keywords = pSQL(Tools::getValue('banner_keywords'));

        if (empty($name)) {
            $this->errors[] = $this->l('Inserisci un nome per il banner.');
            return false;
        }

        // Handle image upload
        if (!isset($_FILES['banner_image']) || empty($_FILES['banner_image']['name'])) {
            $this->errors[] = $this->l('Carica un\'immagine per il banner.');
            return false;
        }

        $uploadResult = $this->uploadBannerImage();
        if (!$uploadResult['success']) {
            $this->errors[] = $uploadResult['error'];
            return false;
        }

        $now = date('Y-m-d H:i:s');

        return Db::getInstance()->insert('smartsearch_banners', [
            'name' => $name,
            'image' => $uploadResult['filename'],
            'link' => $link,
            'position' => $position,
            'keywords' => $keywords,
            'id_shop' => (int)$this->context->shop->id,
            'id_lang' => (int)$this->context->language->id,
            'active' => 1,
            'date_add' => $now,
            'date_upd' => $now,
        ]);
    }

    /**
     * Upload banner image
     */
    protected function uploadBannerImage()
    {
        $file = $_FILES['banner_image'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'error' => $this->l('Errore upload: ') . $file['error']];
        }

        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mimeType, $allowedTypes)) {
            return ['success' => false, 'error' => $this->l('Tipo file non valido. Usa: JPG, PNG, GIF, WEBP')];
        }

        if ($file['size'] > 2 * 1024 * 1024) {
            return ['success' => false, 'error' => $this->l('File troppo grande. Max 2MB.')];
        }

        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = 'banner_' . uniqid() . '_' . time() . '.' . strtolower($extension);

        if (move_uploaded_file($file['tmp_name'], $this->uploadDir . $filename)) {
            return ['success' => true, 'filename' => $filename];
        }

        return ['success' => false, 'error' => $this->l('Impossibile salvare il file.')];
    }

    /**
     * Delete banner
     */
    protected function deleteBanner($id)
    {
        // Delete image file
        $image = Db::getInstance()->getValue('
            SELECT image FROM `' . _DB_PREFIX_ . 'smartsearch_banners`
            WHERE id_smartsearch_banner = ' . $id
        );

        if ($image && file_exists($this->uploadDir . $image)) {
            @unlink($this->uploadDir . $image);
        }

        return Db::getInstance()->delete('smartsearch_banners', 'id_smartsearch_banner = ' . $id . ' AND id_shop = ' . (int)$this->context->shop->id);
    }

    /**
     * Toggle banner status
     */
    protected function toggleBannerStatus($id)
    {
        $current = Db::getInstance()->getValue('
            SELECT active FROM `' . _DB_PREFIX_ . 'smartsearch_banners`
            WHERE id_smartsearch_banner = ' . $id
        );

        return Db::getInstance()->update('smartsearch_banners', [
            'active' => !$current,
            'date_upd' => date('Y-m-d H:i:s'),
        ], 'id_smartsearch_banner = ' . $id);
    }

    /**
     * Clear module cache
     */
    protected function clearModuleCache()
    {
        // Clear search cache
        Db::getInstance()->execute('TRUNCATE TABLE `' . _DB_PREFIX_ . 'smartsearch_cache`');
    }

    /**
     * Set admin media (CSS/JS)
     */
    public function setMedia($isNewTheme = false)
    {
        parent::setMedia($isNewTheme);

        // Add custom CSS for dashboard
        $this->addCSS(_MODULE_DIR_ . 'smartsearch/views/css/admin-dashboard.css');
    }
}
