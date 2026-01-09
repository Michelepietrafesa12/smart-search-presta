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
    protected $activeTab = 'settings';
    protected $uploadDir;

    public function __construct()
    {
        $this->bootstrap = true;
        $this->display = 'view';
        parent::__construct();

        $this->uploadDir = _PS_MODULE_DIR_ . 'smartsearch/views/img/banners/';
        if (!is_dir($this->uploadDir)) {
            @mkdir($this->uploadDir, 0755, true);
        }

        $this->activeTab = Tools::getValue('tab', 'settings');
    }

    public function initContent()
    {
        parent::initContent();

        $this->content = $this->renderDashboard();

        $this->context->smarty->assign('content', $this->content);
    }

    protected function renderDashboard()
    {
        $html = '
        <style>
            .smartsearch-tabs { margin-bottom: 20px; }
            .smartsearch-tabs .nav-tabs > li > a { font-weight: 600; }
            .smartsearch-tabs .nav-tabs > li.active > a { background: #fff; border-bottom: 2px solid #25b9d7; }
        </style>

        <div class="smartsearch-dashboard">
            <div class="panel">
                <div class="panel-heading">
                    <i class="icon-search"></i> Smart Search 2.0
                    <span class="badge badge-success">v' . $this->module->version . '</span>
                </div>
                <div class="panel-body">
                    <div class="smartsearch-tabs">
                        <ul class="nav nav-tabs">
                            <li class="' . ($this->activeTab == 'settings' ? 'active' : '') . '">
                                <a href="' . $this->context->link->getAdminLink('AdminSmartSearchDashboard') . '&tab=settings">
                                    <i class="icon-cogs"></i> Impostazioni
                                </a>
                            </li>
                            <li class="' . ($this->activeTab == 'boosting' ? 'active' : '') . '">
                                <a href="' . $this->context->link->getAdminLink('AdminSmartSearchDashboard') . '&tab=boosting">
                                    <i class="icon-rocket"></i> Boosting
                                </a>
                            </li>
                            <li class="' . ($this->activeTab == 'banners' ? 'active' : '') . '">
                                <a href="' . $this->context->link->getAdminLink('AdminSmartSearchDashboard') . '&tab=banners">
                                    <i class="icon-picture-o"></i> Banner
                                </a>
                            </li>
                            <li class="' . ($this->activeTab == 'style' ? 'active' : '') . '">
                                <a href="' . $this->context->link->getAdminLink('AdminSmartSearchDashboard') . '&tab=style">
                                    <i class="icon-paint-brush"></i> Stile
                                </a>
                            </li>
                        </ul>
                    </div>
                    <div class="tab-content">';

        switch ($this->activeTab) {
            case 'boosting':
                $html .= $this->renderBoostingTab();
                break;
            case 'banners':
                $html .= $this->renderBannersTab();
                break;
            case 'style':
                $html .= $this->renderStyleTab();
                break;
            default:
                $html .= $this->renderSettingsTab();
        }

        $html .= '
                    </div>
                </div>
            </div>
        </div>';

        return $html;
    }

    public function postProcess()
    {
        if (Tools::isSubmit('submitSmartSearchSettings')) {
            $this->saveSettings();
            $this->confirmations[] = $this->l('Impostazioni salvate con successo!');
            $this->activeTab = 'settings';
        }

        if (Tools::isSubmit('clearCache')) {
            $this->clearModuleCache();
            $this->confirmations[] = $this->l('Cache svuotata con successo!');
            $this->activeTab = 'settings';
        }

        if (Tools::isSubmit('submitBoost')) {
            if ($this->saveBoost()) {
                $this->confirmations[] = $this->l('Boost salvato con successo!');
            }
            $this->activeTab = 'boosting';
        }

        if (Tools::getValue('deleteBoost')) {
            $this->deleteBoost((int)Tools::getValue('deleteBoost'));
            $this->confirmations[] = $this->l('Boost eliminato!');
            $this->activeTab = 'boosting';
        }

        if (Tools::getValue('toggleBoost')) {
            $this->toggleBoostStatus((int)Tools::getValue('toggleBoost'));
            $this->activeTab = 'boosting';
        }

        if (Tools::isSubmit('submitBanner')) {
            if ($this->saveBanner()) {
                $this->confirmations[] = $this->l('Banner salvato con successo!');
            }
            $this->activeTab = 'banners';
        }

        if (Tools::getValue('deleteBanner')) {
            $this->deleteBanner((int)Tools::getValue('deleteBanner'));
            $this->confirmations[] = $this->l('Banner eliminato!');
            $this->activeTab = 'banners';
        }

        if (Tools::getValue('toggleBanner')) {
            $this->toggleBannerStatus((int)Tools::getValue('toggleBanner'));
            $this->activeTab = 'banners';
        }

        if (Tools::isSubmit('submitStyleSettings')) {
            $this->saveStyleSettings();
            $this->confirmations[] = $this->l('Impostazioni di stile salvate con successo!');
            $this->activeTab = 'style';
        }

        if (Tools::isSubmit('resetStyleSettings')) {
            $this->resetStyleSettings();
            $this->confirmations[] = $this->l('Stile ripristinato ai valori predefiniti!');
            $this->activeTab = 'style';
        }

        parent::postProcess();
    }

    protected function renderSettingsTab()
    {
        $helper = new HelperForm();
        $helper->module = $this->module;
        $helper->name_controller = 'AdminSmartSearchDashboard';
        $helper->token = Tools::getAdminTokenLite('AdminSmartSearchDashboard');
        $helper->currentIndex = $this->context->link->getAdminLink('AdminSmartSearchDashboard', false) . '&tab=settings';
        $helper->default_form_language = (int)Configuration::get('PS_LANG_DEFAULT');
        $helper->submit_action = 'submitSmartSearchSettings';

        $fields_form = [
            [
                'form' => [
                    'legend' => ['title' => $this->l('Impostazioni Generali'), 'icon' => 'icon-cogs'],
                    'input' => [
                        ['type' => 'switch', 'label' => $this->l('Abilita Smart Search'), 'name' => 'SMARTSEARCH_ENABLED', 'is_bool' => true, 'values' => [['id' => 'on', 'value' => 1], ['id' => 'off', 'value' => 0]]],
                        ['type' => 'text', 'label' => $this->l('Caratteri minimi'), 'name' => 'SMARTSEARCH_MIN_CHARS', 'class' => 'fixed-width-sm'],
                        ['type' => 'text', 'label' => $this->l('Risultati massimi'), 'name' => 'SMARTSEARCH_MAX_RESULTS', 'class' => 'fixed-width-sm'],
                    ],
                ],
            ],
            [
                'form' => [
                    'legend' => ['title' => $this->l('Algoritmi'), 'icon' => 'icon-magic'],
                    'input' => [
                        ['type' => 'switch', 'label' => $this->l('Fuzzy Search'), 'name' => 'SMARTSEARCH_FUZZY_ENABLED', 'is_bool' => true, 'values' => [['id' => 'on', 'value' => 1], ['id' => 'off', 'value' => 0]]],
                        ['type' => 'switch', 'label' => $this->l('Filtri Dinamici'), 'name' => 'SMARTSEARCH_FACETS_ENABLED', 'is_bool' => true, 'values' => [['id' => 'on', 'value' => 1], ['id' => 'off', 'value' => 0]]],
                    ],
                ],
            ],
            [
                'form' => [
                    'legend' => ['title' => $this->l('Analytics'), 'icon' => 'icon-bar-chart'],
                    'input' => [
                        ['type' => 'switch', 'label' => $this->l('Abilita Analytics'), 'name' => 'SMARTSEARCH_ANALYTICS_ENABLED', 'is_bool' => true, 'values' => [['id' => 'on', 'value' => 1], ['id' => 'off', 'value' => 0]]],
                        ['type' => 'text', 'label' => $this->l('Webhook URL (n8n)'), 'name' => 'SMARTSEARCH_ANALYTICS_WEBHOOK_URL', 'class' => 'fixed-width-xxl', 'desc' => $this->l('URL per inviare analytics')],
                        ['type' => 'switch', 'label' => $this->l('Banner Promozionali'), 'name' => 'SMARTSEARCH_BANNERS_ENABLED', 'is_bool' => true, 'values' => [['id' => 'on', 'value' => 1], ['id' => 'off', 'value' => 0]]],
                    ],
                    'submit' => ['title' => $this->l('Salva Impostazioni')],
                ],
            ],
        ];

        $helper->fields_value = [
            'SMARTSEARCH_ENABLED' => Configuration::get('SMARTSEARCH_ENABLED'),
            'SMARTSEARCH_MIN_CHARS' => Configuration::get('SMARTSEARCH_MIN_CHARS') ?: 2,
            'SMARTSEARCH_MAX_RESULTS' => Configuration::get('SMARTSEARCH_MAX_RESULTS') ?: 8,
            'SMARTSEARCH_FUZZY_ENABLED' => Configuration::get('SMARTSEARCH_FUZZY_ENABLED'),
            'SMARTSEARCH_FACETS_ENABLED' => Configuration::get('SMARTSEARCH_FACETS_ENABLED'),
            'SMARTSEARCH_ANALYTICS_ENABLED' => Configuration::get('SMARTSEARCH_ANALYTICS_ENABLED'),
            'SMARTSEARCH_ANALYTICS_WEBHOOK_URL' => Configuration::get('SMARTSEARCH_ANALYTICS_WEBHOOK_URL'),
            'SMARTSEARCH_BANNERS_ENABLED' => Configuration::get('SMARTSEARCH_BANNERS_ENABLED'),
        ];

        return $helper->generateForm($fields_form);
    }

    protected function renderBoostingTab()
    {
        $formAction = $this->context->link->getAdminLink('AdminSmartSearchDashboard') . '&tab=boosting';

        $html = '<div class="panel"><div class="panel-heading"><i class="icon-rocket"></i> Product Boosting</div>';

        // Guida al boosting
        $html .= '<div class="alert alert-info">';
        $html .= '<h4><i class="icon-info-circle"></i> Come funziona il Product Boosting</h4>';
        $html .= '<p>Il boosting permette di aumentare la visibilità di specifici prodotti nei risultati di ricerca.</p>';
        $html .= '<hr style="margin: 10px 0; border-color: rgba(0,0,0,0.1);">';
        $html .= '<div class="row">';

        // Colonna 1: Valore Boost
        $html .= '<div class="col-md-4">';
        $html .= '<h5><i class="icon-star"></i> Valore Boost</h5>';
        $html .= '<ul style="padding-left: 20px; margin-bottom: 0;">';
        $html .= '<li><strong>1.0</strong> = Priorità normale (nessun boost)</li>';
        $html .= '<li><strong>1.5</strong> = Priorità aumentata del 50%</li>';
        $html .= '<li><strong>2.0</strong> = Doppia priorità</li>';
        $html .= '<li><strong>5.0+</strong> = Prodotto sempre in cima</li>';
        $html .= '</ul>';
        $html .= '</div>';

        // Colonna 2: Keywords
        $html .= '<div class="col-md-4">';
        $html .= '<h5><i class="icon-key"></i> Keywords (Opzionale)</h5>';
        $html .= '<ul style="padding-left: 20px; margin-bottom: 0;">';
        $html .= '<li><strong>Vuoto</strong> = Boost sempre attivo</li>';
        $html .= '<li><strong>Con keywords</strong> = Boost attivo solo quando la ricerca contiene quelle parole</li>';
        $html .= '<li>Separa più keywords con virgola</li>';
        $html .= '</ul>';
        $html .= '</div>';

        // Colonna 3: Esempi
        $html .= '<div class="col-md-4">';
        $html .= '<h5><i class="icon-lightbulb-o"></i> Esempi</h5>';
        $html .= '<ul style="padding-left: 20px; margin-bottom: 0;">';
        $html .= '<li><em>"Scarpe Nike"</em> + boost 3.0 + keywords "scarpe" → Appare in cima quando si cerca "scarpe"</li>';
        $html .= '<li><em>"Offerta Speciale"</em> + boost 5.0 + (vuoto) → Sempre visibile in cima a qualsiasi ricerca</li>';
        $html .= '</ul>';
        $html .= '</div>';

        $html .= '</div>'; // chiude row
        $html .= '</div>'; // chiude alert

        // Form con action esplicita
        $products = Product::getProducts($this->context->language->id, 0, 100, 'name', 'ASC', false, true);

        $html .= '<form method="post" action="' . htmlspecialchars($formAction) . '">';
        $html .= '<div class="row"><div class="col-md-4"><div class="form-group"><label>Prodotto</label><select name="boost_product" class="form-control" required><option value="">Seleziona...</option>';
        foreach ($products as $p) {
            $html .= '<option value="' . $p['id_product'] . '">' . htmlspecialchars($p['name']) . '</option>';
        }
        $html .= '</select></div></div>';
        $html .= '<div class="col-md-2"><div class="form-group"><label>Boost</label><input type="number" name="boost_value" class="form-control" value="1.5" min="0.1" max="10" step="0.1"></div></div>';
        $html .= '<div class="col-md-3"><div class="form-group"><label>Keywords</label><input type="text" name="boost_keywords" class="form-control" placeholder="es: scarpe"></div></div>';
        $html .= '<div class="col-md-3"><div class="form-group"><label>&nbsp;</label><button type="submit" name="submitBoost" class="btn btn-primary btn-block"><i class="icon-plus"></i> Aggiungi</button></div></div></div></form>';

        // List
        $boosts = Db::getInstance()->executeS('SELECT b.*, pl.name as product_name FROM `' . _DB_PREFIX_ . 'smartsearch_boost` b LEFT JOIN `' . _DB_PREFIX_ . 'product_lang` pl ON b.id_product = pl.id_product AND pl.id_lang = ' . (int)$this->context->language->id . ' WHERE b.id_shop = ' . (int)$this->context->shop->id);

        if ($boosts) {
            $html .= '<hr><table class="table"><thead><tr><th>Prodotto</th><th>Boost</th><th>Keywords</th><th>Azioni</th></tr></thead><tbody>';
            foreach ($boosts as $b) {
                $html .= '<tr><td>' . htmlspecialchars($b['product_name'] ?? 'N/A') . '</td><td>' . $b['boost_value'] . 'x</td><td>' . ($b['keywords'] ?: '-') . '</td>';
                $html .= '<td><a href="' . $this->context->link->getAdminLink('AdminSmartSearchDashboard') . '&tab=boosting&deleteBoost=' . $b['id_smartsearch_boost'] . '" class="btn btn-danger btn-xs" onclick="return confirm(\'Sicuro?\')"><i class="icon-trash"></i></a></td></tr>';
            }
            $html .= '</tbody></table>';
        } else {
            $html .= '<div class="alert alert-info">Nessun boost configurato.</div>';
        }

        $html .= '</div>';
        return $html;
    }

    protected function renderBannersTab()
    {
        $formAction = $this->context->link->getAdminLink('AdminSmartSearchDashboard') . '&tab=banners';

        $html = '<div class="panel"><div class="panel-heading"><i class="icon-picture-o"></i> Banner Promozionali</div>';

        // Form con action esplicita
        $html .= '<form method="post" action="' . htmlspecialchars($formAction) . '" enctype="multipart/form-data">';
        $html .= '<div class="row">';
        $html .= '<div class="col-md-2"><div class="form-group"><label>Nome</label><input type="text" name="banner_name" class="form-control" required></div></div>';
        $html .= '<div class="col-md-2"><div class="form-group"><label>Immagine</label><input type="file" name="banner_image" class="form-control" accept="image/*" required></div></div>';
        $html .= '<div class="col-md-2"><div class="form-group"><label>Posizione</label><select name="banner_position" class="form-control"><option value="top">Top</option><option value="middle">Middle</option><option value="bottom">Bottom</option></select></div></div>';
        $html .= '<div class="col-md-2"><div class="form-group"><label>Keywords</label><input type="text" name="banner_keywords" class="form-control" placeholder="es: scarpe, sport"></div></div>';
        $html .= '<div class="col-md-2"><div class="form-group"><label>Link</label><input type="url" name="banner_link" class="form-control"></div></div>';
        $html .= '<div class="col-md-2"><div class="form-group"><label>&nbsp;</label><button type="submit" name="submitBanner" class="btn btn-success btn-block"><i class="icon-plus"></i> Aggiungi</button></div></div>';
        $html .= '</div>';
        $html .= '<p class="help-block"><small>Keywords: Lascia vuoto per mostrare sempre, oppure inserisci parole chiave separate da virgola per mostrare solo quando la ricerca contiene quelle parole.</small></p>';
        $html .= '</form>';

        // List
        $banners = Db::getInstance()->executeS('SELECT * FROM `' . _DB_PREFIX_ . 'smartsearch_banners` WHERE id_shop = ' . (int)$this->context->shop->id);

        if ($banners) {
            $html .= '<hr><div class="row">';
            foreach ($banners as $b) {
                $imgUrl = _MODULE_DIR_ . 'smartsearch/views/img/banners/' . $b['image'];
                $html .= '<div class="col-md-4"><div class="panel text-center">';
                $html .= '<img src="' . $imgUrl . '" style="max-width:100%;max-height:60px;margin-bottom:10px">';
                $html .= '<h5>' . htmlspecialchars($b['name']) . '</h5>';
                $html .= '<span class="label label-default">' . $b['position'] . '</span> ';
                $html .= '<span class="label label-' . ($b['active'] ? 'success' : 'danger') . '">' . ($b['active'] ? 'Attivo' : 'Off') . '</span>';
                if (!empty($b['keywords'])) {
                    $html .= '<br><small class="text-muted"><i class="icon-tag"></i> ' . htmlspecialchars($b['keywords']) . '</small>';
                } else {
                    $html .= '<br><small class="text-muted"><i class="icon-globe"></i> Sempre visibile</small>';
                }
                $html .= '<br><br>';
                $html .= '<a href="' . $this->context->link->getAdminLink('AdminSmartSearchDashboard') . '&tab=banners&deleteBanner=' . $b['id_smartsearch_banner'] . '" class="btn btn-danger btn-xs" onclick="return confirm(\'Sicuro?\')"><i class="icon-trash"></i> Elimina</a>';
                $html .= '</div></div>';
            }
            $html .= '</div>';
        } else {
            $html .= '<div class="alert alert-info">Nessun banner configurato.</div>';
        }

        $html .= '</div>';
        return $html;
    }

    protected function renderStyleTab()
    {
        $formAction = $this->context->link->getAdminLink('AdminSmartSearchDashboard') . '&tab=style';

        // Valori predefiniti
        $defaults = $this->getDefaultStyleValues();

        // Valori attuali (o predefiniti se non impostati)
        $values = [
            'overlay_bg' => Configuration::get('SMARTSEARCH_STYLE_OVERLAY_BG') ?: $defaults['overlay_bg'],
            'overlay_opacity' => Configuration::get('SMARTSEARCH_STYLE_OVERLAY_OPACITY') ?: $defaults['overlay_opacity'],
            'search_bg' => Configuration::get('SMARTSEARCH_STYLE_SEARCH_BG') ?: $defaults['search_bg'],
            'search_text' => Configuration::get('SMARTSEARCH_STYLE_SEARCH_TEXT') ?: $defaults['search_text'],
            'search_placeholder' => Configuration::get('SMARTSEARCH_STYLE_SEARCH_PLACEHOLDER') ?: $defaults['search_placeholder'],
            'accent_color' => Configuration::get('SMARTSEARCH_STYLE_ACCENT') ?: $defaults['accent_color'],
            'card_bg' => Configuration::get('SMARTSEARCH_STYLE_CARD_BG') ?: $defaults['card_bg'],
            'card_title' => Configuration::get('SMARTSEARCH_STYLE_CARD_TITLE') ?: $defaults['card_title'],
            'card_price' => Configuration::get('SMARTSEARCH_STYLE_CARD_PRICE') ?: $defaults['card_price'],
            'card_price_old' => Configuration::get('SMARTSEARCH_STYLE_CARD_PRICE_OLD') ?: $defaults['card_price_old'],
            'discount_badge_bg' => Configuration::get('SMARTSEARCH_STYLE_DISCOUNT_BG') ?: $defaults['discount_badge_bg'],
            'discount_badge_text' => Configuration::get('SMARTSEARCH_STYLE_DISCOUNT_TEXT') ?: $defaults['discount_badge_text'],
            'sidebar_bg' => Configuration::get('SMARTSEARCH_STYLE_SIDEBAR_BG') ?: $defaults['sidebar_bg'],
            'sidebar_text' => Configuration::get('SMARTSEARCH_STYLE_SIDEBAR_TEXT') ?: $defaults['sidebar_text'],
            'button_bg' => Configuration::get('SMARTSEARCH_STYLE_BUTTON_BG') ?: $defaults['button_bg'],
            'button_text' => Configuration::get('SMARTSEARCH_STYLE_BUTTON_TEXT') ?: $defaults['button_text'],
        ];

        $html = '<div class="panel"><div class="panel-heading"><i class="icon-paint-brush"></i> Personalizzazione Stile</div>';

        // Anteprima live
        $html .= '<div class="alert alert-info">';
        $html .= '<i class="icon-eye"></i> <strong>Anteprima:</strong> Le modifiche saranno visibili dopo il salvataggio. Ricarica la pagina del negozio per vedere i cambiamenti.';
        $html .= '</div>';

        $html .= '<form method="post" action="' . htmlspecialchars($formAction) . '">';

        // Sezione 1: Overlay
        $html .= '<div class="panel">';
        $html .= '<div class="panel-heading"><i class="icon-square"></i> Overlay Sfondo</div>';
        $html .= '<div class="row">';
        $html .= '<div class="col-md-4">';
        $html .= '<div class="form-group">';
        $html .= '<label>Colore sfondo overlay</label>';
        $html .= '<input type="color" name="style_overlay_bg" class="form-control" value="' . htmlspecialchars($values['overlay_bg']) . '" style="height:40px;padding:2px">';
        $html .= '<p class="help-block">Sfondo dietro i risultati</p>';
        $html .= '</div></div>';
        $html .= '<div class="col-md-4">';
        $html .= '<div class="form-group">';
        $html .= '<label>Opacità overlay (0-100)</label>';
        $html .= '<input type="number" name="style_overlay_opacity" class="form-control" value="' . (int)$values['overlay_opacity'] . '" min="0" max="100">';
        $html .= '<p class="help-block">0 = trasparente, 100 = opaco</p>';
        $html .= '</div></div>';
        $html .= '</div></div>';

        // Sezione 2: Barra di ricerca
        $html .= '<div class="panel">';
        $html .= '<div class="panel-heading"><i class="icon-search"></i> Barra di Ricerca</div>';
        $html .= '<div class="row">';
        $html .= '<div class="col-md-3">';
        $html .= '<div class="form-group">';
        $html .= '<label>Sfondo input</label>';
        $html .= '<input type="color" name="style_search_bg" class="form-control" value="' . htmlspecialchars($values['search_bg']) . '" style="height:40px;padding:2px">';
        $html .= '</div></div>';
        $html .= '<div class="col-md-3">';
        $html .= '<div class="form-group">';
        $html .= '<label>Colore testo</label>';
        $html .= '<input type="color" name="style_search_text" class="form-control" value="' . htmlspecialchars($values['search_text']) . '" style="height:40px;padding:2px">';
        $html .= '</div></div>';
        $html .= '<div class="col-md-3">';
        $html .= '<div class="form-group">';
        $html .= '<label>Colore placeholder</label>';
        $html .= '<input type="color" name="style_search_placeholder" class="form-control" value="' . htmlspecialchars($values['search_placeholder']) . '" style="height:40px;padding:2px">';
        $html .= '</div></div>';
        $html .= '<div class="col-md-3">';
        $html .= '<div class="form-group">';
        $html .= '<label>Colore accento</label>';
        $html .= '<input type="color" name="style_accent_color" class="form-control" value="' . htmlspecialchars($values['accent_color']) . '" style="height:40px;padding:2px">';
        $html .= '<p class="help-block">Icone, bordi focus</p>';
        $html .= '</div></div>';
        $html .= '</div></div>';

        // Sezione 3: Card prodotto
        $html .= '<div class="panel">';
        $html .= '<div class="panel-heading"><i class="icon-th"></i> Card Prodotto</div>';
        $html .= '<div class="row">';
        $html .= '<div class="col-md-3">';
        $html .= '<div class="form-group">';
        $html .= '<label>Sfondo card</label>';
        $html .= '<input type="color" name="style_card_bg" class="form-control" value="' . htmlspecialchars($values['card_bg']) . '" style="height:40px;padding:2px">';
        $html .= '</div></div>';
        $html .= '<div class="col-md-3">';
        $html .= '<div class="form-group">';
        $html .= '<label>Colore titolo</label>';
        $html .= '<input type="color" name="style_card_title" class="form-control" value="' . htmlspecialchars($values['card_title']) . '" style="height:40px;padding:2px">';
        $html .= '</div></div>';
        $html .= '<div class="col-md-3">';
        $html .= '<div class="form-group">';
        $html .= '<label>Colore prezzo</label>';
        $html .= '<input type="color" name="style_card_price" class="form-control" value="' . htmlspecialchars($values['card_price']) . '" style="height:40px;padding:2px">';
        $html .= '</div></div>';
        $html .= '<div class="col-md-3">';
        $html .= '<div class="form-group">';
        $html .= '<label>Colore prezzo barrato</label>';
        $html .= '<input type="color" name="style_card_price_old" class="form-control" value="' . htmlspecialchars($values['card_price_old']) . '" style="height:40px;padding:2px">';
        $html .= '</div></div>';
        $html .= '</div></div>';

        // Sezione 4: Badge sconto
        $html .= '<div class="panel">';
        $html .= '<div class="panel-heading"><i class="icon-tag"></i> Badge Sconto</div>';
        $html .= '<div class="row">';
        $html .= '<div class="col-md-4">';
        $html .= '<div class="form-group">';
        $html .= '<label>Sfondo badge</label>';
        $html .= '<input type="color" name="style_discount_badge_bg" class="form-control" value="' . htmlspecialchars($values['discount_badge_bg']) . '" style="height:40px;padding:2px">';
        $html .= '</div></div>';
        $html .= '<div class="col-md-4">';
        $html .= '<div class="form-group">';
        $html .= '<label>Testo badge</label>';
        $html .= '<input type="color" name="style_discount_badge_text" class="form-control" value="' . htmlspecialchars($values['discount_badge_text']) . '" style="height:40px;padding:2px">';
        $html .= '</div></div>';
        $html .= '</div></div>';

        // Sezione 5: Sidebar filtri
        $html .= '<div class="panel">';
        $html .= '<div class="panel-heading"><i class="icon-sliders"></i> Sidebar Filtri</div>';
        $html .= '<div class="row">';
        $html .= '<div class="col-md-4">';
        $html .= '<div class="form-group">';
        $html .= '<label>Sfondo sidebar</label>';
        $html .= '<input type="color" name="style_sidebar_bg" class="form-control" value="' . htmlspecialchars($values['sidebar_bg']) . '" style="height:40px;padding:2px">';
        $html .= '</div></div>';
        $html .= '<div class="col-md-4">';
        $html .= '<div class="form-group">';
        $html .= '<label>Colore testo</label>';
        $html .= '<input type="color" name="style_sidebar_text" class="form-control" value="' . htmlspecialchars($values['sidebar_text']) . '" style="height:40px;padding:2px">';
        $html .= '</div></div>';
        $html .= '</div></div>';

        // Sezione 6: Pulsanti
        $html .= '<div class="panel">';
        $html .= '<div class="panel-heading"><i class="icon-hand-pointer-o"></i> Pulsanti</div>';
        $html .= '<div class="row">';
        $html .= '<div class="col-md-4">';
        $html .= '<div class="form-group">';
        $html .= '<label>Sfondo pulsanti</label>';
        $html .= '<input type="color" name="style_button_bg" class="form-control" value="' . htmlspecialchars($values['button_bg']) . '" style="height:40px;padding:2px">';
        $html .= '</div></div>';
        $html .= '<div class="col-md-4">';
        $html .= '<div class="form-group">';
        $html .= '<label>Testo pulsanti</label>';
        $html .= '<input type="color" name="style_button_text" class="form-control" value="' . htmlspecialchars($values['button_text']) . '" style="height:40px;padding:2px">';
        $html .= '</div></div>';
        $html .= '</div></div>';

        // Pulsanti azione
        $html .= '<div class="panel-footer">';
        $html .= '<button type="submit" name="submitStyleSettings" class="btn btn-primary"><i class="icon-save"></i> Salva Stile</button> ';
        $html .= '<button type="submit" name="resetStyleSettings" class="btn btn-default" onclick="return confirm(\'Ripristinare i colori predefiniti?\')"><i class="icon-refresh"></i> Ripristina Predefiniti</button>';
        $html .= '</div>';

        $html .= '</form>';
        $html .= '</div>';

        return $html;
    }

    protected function getDefaultStyleValues()
    {
        return [
            'overlay_bg' => '#1e293b',
            'overlay_opacity' => '98',
            'search_bg' => '#1e293b',
            'search_text' => '#ffffff',
            'search_placeholder' => '#94a3b8',
            'accent_color' => '#f97316',
            'card_bg' => '#ffffff',
            'card_title' => '#1e293b',
            'card_price' => '#059669',
            'card_price_old' => '#94a3b8',
            'discount_badge_bg' => '#dc2626',
            'discount_badge_text' => '#ffffff',
            'sidebar_bg' => '#f8fafc',
            'sidebar_text' => '#334155',
            'button_bg' => '#f97316',
            'button_text' => '#ffffff',
        ];
    }

    protected function saveStyleSettings()
    {
        Configuration::updateValue('SMARTSEARCH_STYLE_OVERLAY_BG', pSQL(Tools::getValue('style_overlay_bg')));
        Configuration::updateValue('SMARTSEARCH_STYLE_OVERLAY_OPACITY', (int)Tools::getValue('style_overlay_opacity'));
        Configuration::updateValue('SMARTSEARCH_STYLE_SEARCH_BG', pSQL(Tools::getValue('style_search_bg')));
        Configuration::updateValue('SMARTSEARCH_STYLE_SEARCH_TEXT', pSQL(Tools::getValue('style_search_text')));
        Configuration::updateValue('SMARTSEARCH_STYLE_SEARCH_PLACEHOLDER', pSQL(Tools::getValue('style_search_placeholder')));
        Configuration::updateValue('SMARTSEARCH_STYLE_ACCENT', pSQL(Tools::getValue('style_accent_color')));
        Configuration::updateValue('SMARTSEARCH_STYLE_CARD_BG', pSQL(Tools::getValue('style_card_bg')));
        Configuration::updateValue('SMARTSEARCH_STYLE_CARD_TITLE', pSQL(Tools::getValue('style_card_title')));
        Configuration::updateValue('SMARTSEARCH_STYLE_CARD_PRICE', pSQL(Tools::getValue('style_card_price')));
        Configuration::updateValue('SMARTSEARCH_STYLE_CARD_PRICE_OLD', pSQL(Tools::getValue('style_card_price_old')));
        Configuration::updateValue('SMARTSEARCH_STYLE_DISCOUNT_BG', pSQL(Tools::getValue('style_discount_badge_bg')));
        Configuration::updateValue('SMARTSEARCH_STYLE_DISCOUNT_TEXT', pSQL(Tools::getValue('style_discount_badge_text')));
        Configuration::updateValue('SMARTSEARCH_STYLE_SIDEBAR_BG', pSQL(Tools::getValue('style_sidebar_bg')));
        Configuration::updateValue('SMARTSEARCH_STYLE_SIDEBAR_TEXT', pSQL(Tools::getValue('style_sidebar_text')));
        Configuration::updateValue('SMARTSEARCH_STYLE_BUTTON_BG', pSQL(Tools::getValue('style_button_bg')));
        Configuration::updateValue('SMARTSEARCH_STYLE_BUTTON_TEXT', pSQL(Tools::getValue('style_button_text')));
    }

    protected function resetStyleSettings()
    {
        $defaults = $this->getDefaultStyleValues();
        Configuration::updateValue('SMARTSEARCH_STYLE_OVERLAY_BG', $defaults['overlay_bg']);
        Configuration::updateValue('SMARTSEARCH_STYLE_OVERLAY_OPACITY', $defaults['overlay_opacity']);
        Configuration::updateValue('SMARTSEARCH_STYLE_SEARCH_BG', $defaults['search_bg']);
        Configuration::updateValue('SMARTSEARCH_STYLE_SEARCH_TEXT', $defaults['search_text']);
        Configuration::updateValue('SMARTSEARCH_STYLE_SEARCH_PLACEHOLDER', $defaults['search_placeholder']);
        Configuration::updateValue('SMARTSEARCH_STYLE_ACCENT', $defaults['accent_color']);
        Configuration::updateValue('SMARTSEARCH_STYLE_CARD_BG', $defaults['card_bg']);
        Configuration::updateValue('SMARTSEARCH_STYLE_CARD_TITLE', $defaults['card_title']);
        Configuration::updateValue('SMARTSEARCH_STYLE_CARD_PRICE', $defaults['card_price']);
        Configuration::updateValue('SMARTSEARCH_STYLE_CARD_PRICE_OLD', $defaults['card_price_old']);
        Configuration::updateValue('SMARTSEARCH_STYLE_DISCOUNT_BG', $defaults['discount_badge_bg']);
        Configuration::updateValue('SMARTSEARCH_STYLE_DISCOUNT_TEXT', $defaults['discount_badge_text']);
        Configuration::updateValue('SMARTSEARCH_STYLE_SIDEBAR_BG', $defaults['sidebar_bg']);
        Configuration::updateValue('SMARTSEARCH_STYLE_SIDEBAR_TEXT', $defaults['sidebar_text']);
        Configuration::updateValue('SMARTSEARCH_STYLE_BUTTON_BG', $defaults['button_bg']);
        Configuration::updateValue('SMARTSEARCH_STYLE_BUTTON_TEXT', $defaults['button_text']);
    }

    protected function saveSettings()
    {
        Configuration::updateValue('SMARTSEARCH_ENABLED', (int)Tools::getValue('SMARTSEARCH_ENABLED'));
        Configuration::updateValue('SMARTSEARCH_MIN_CHARS', (int)Tools::getValue('SMARTSEARCH_MIN_CHARS'));
        Configuration::updateValue('SMARTSEARCH_MAX_RESULTS', (int)Tools::getValue('SMARTSEARCH_MAX_RESULTS'));
        Configuration::updateValue('SMARTSEARCH_FUZZY_ENABLED', (int)Tools::getValue('SMARTSEARCH_FUZZY_ENABLED'));
        Configuration::updateValue('SMARTSEARCH_FACETS_ENABLED', (int)Tools::getValue('SMARTSEARCH_FACETS_ENABLED'));
        Configuration::updateValue('SMARTSEARCH_ANALYTICS_ENABLED', (int)Tools::getValue('SMARTSEARCH_ANALYTICS_ENABLED'));
        Configuration::updateValue('SMARTSEARCH_ANALYTICS_WEBHOOK_URL', Tools::getValue('SMARTSEARCH_ANALYTICS_WEBHOOK_URL'));
        Configuration::updateValue('SMARTSEARCH_BANNERS_ENABLED', (int)Tools::getValue('SMARTSEARCH_BANNERS_ENABLED'));
    }

    protected function saveBoost()
    {
        $productId = (int)Tools::getValue('boost_product');
        if (!$productId) {
            $this->errors[] = $this->l('Seleziona un prodotto.');
            return false;
        }

        return Db::getInstance()->insert('smartsearch_boost', [
            'id_product' => $productId,
            'boost_value' => (float)Tools::getValue('boost_value'),
            'keywords' => pSQL(Tools::getValue('boost_keywords')),
            'id_shop' => (int)$this->context->shop->id,
            'active' => 1,
            'date_add' => date('Y-m-d H:i:s'),
            'date_upd' => date('Y-m-d H:i:s'),
        ]);
    }

    protected function deleteBoost($id)
    {
        return Db::getInstance()->delete('smartsearch_boost', 'id_smartsearch_boost = ' . $id);
    }

    protected function toggleBoostStatus($id)
    {
        $current = Db::getInstance()->getValue('SELECT active FROM `' . _DB_PREFIX_ . 'smartsearch_boost` WHERE id_smartsearch_boost = ' . $id);
        return Db::getInstance()->update('smartsearch_boost', ['active' => !$current], 'id_smartsearch_boost = ' . $id);
    }

    protected function saveBanner()
    {
        $name = pSQL(Tools::getValue('banner_name'));
        if (empty($name) || !isset($_FILES['banner_image']['name']) || empty($_FILES['banner_image']['name'])) {
            $this->errors[] = $this->l('Compila tutti i campi.');
            return false;
        }

        $file = $_FILES['banner_image'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $filename = 'banner_' . uniqid() . '.' . $ext;

        if (!move_uploaded_file($file['tmp_name'], $this->uploadDir . $filename)) {
            $this->errors[] = $this->l('Errore upload immagine.');
            return false;
        }

        return Db::getInstance()->insert('smartsearch_banners', [
            'name' => $name,
            'image' => $filename,
            'link' => pSQL(Tools::getValue('banner_link')),
            'position' => pSQL(Tools::getValue('banner_position')),
            'keywords' => pSQL(Tools::getValue('banner_keywords')),
            'id_shop' => (int)$this->context->shop->id,
            'id_lang' => (int)$this->context->language->id,
            'active' => 1,
            'date_add' => date('Y-m-d H:i:s'),
            'date_upd' => date('Y-m-d H:i:s'),
        ]);
    }

    protected function deleteBanner($id)
    {
        $img = Db::getInstance()->getValue('SELECT image FROM `' . _DB_PREFIX_ . 'smartsearch_banners` WHERE id_smartsearch_banner = ' . $id);
        if ($img && file_exists($this->uploadDir . $img)) @unlink($this->uploadDir . $img);
        return Db::getInstance()->delete('smartsearch_banners', 'id_smartsearch_banner = ' . $id);
    }

    protected function toggleBannerStatus($id)
    {
        $current = Db::getInstance()->getValue('SELECT active FROM `' . _DB_PREFIX_ . 'smartsearch_banners` WHERE id_smartsearch_banner = ' . $id);
        return Db::getInstance()->update('smartsearch_banners', ['active' => !$current], 'id_smartsearch_banner = ' . $id);
    }

    protected function clearModuleCache()
    {
        Db::getInstance()->execute('TRUNCATE TABLE `' . _DB_PREFIX_ . 'smartsearch_cache`');
    }
}
