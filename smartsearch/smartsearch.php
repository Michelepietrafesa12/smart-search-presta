<?php
/**
 * SmartSearch - Modulo di ricerca dinamica per PrestaShop
 * Simile a Doofinder per ricerca istantanea con suggerimenti
 *
 * @author Smart Search Team
 * @copyright 2024
 * @license MIT
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class SmartSearch extends Module
{
    public function __construct()
    {
        $this->name = 'smartsearch';
        $this->tab = 'search_filter';
        $this->version = '1.0.0';
        $this->author = 'Smart Search Team';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = [
            'min' => '1.7.0.0',
            'max' => _PS_VERSION_
        ];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('Smart Search');
        $this->description = $this->l('Ricerca dinamica avanzata con suggerimenti istantanei, simile a Doofinder');
        $this->confirmUninstall = $this->l('Sei sicuro di voler disinstallare questo modulo?');
    }

    /**
     * Installazione del modulo
     */
    public function install()
    {
        // Configurazioni di default
        Configuration::updateValue('SMARTSEARCH_ENABLED', 1);
        Configuration::updateValue('SMARTSEARCH_MIN_CHARS', 2);
        Configuration::updateValue('SMARTSEARCH_MAX_RESULTS', 8);
        Configuration::updateValue('SMARTSEARCH_SHOW_PRICE', 1);
        Configuration::updateValue('SMARTSEARCH_SHOW_IMAGE', 1);
        Configuration::updateValue('SMARTSEARCH_SHOW_DESCRIPTION', 1);
        Configuration::updateValue('SMARTSEARCH_SHOW_CATEGORY', 1);
        Configuration::updateValue('SMARTSEARCH_DEBOUNCE_TIME', 300);
        Configuration::updateValue('SMARTSEARCH_HIGHLIGHT', 1);

        return parent::install()
            && $this->registerHook('displayHeader')
            && $this->registerHook('displayTop')
            && $this->registerHook('actionFrontControllerSetMedia')
            && $this->installDb();
    }

    /**
     * Disinstallazione del modulo
     */
    public function uninstall()
    {
        // Rimozione configurazioni
        Configuration::deleteByName('SMARTSEARCH_ENABLED');
        Configuration::deleteByName('SMARTSEARCH_MIN_CHARS');
        Configuration::deleteByName('SMARTSEARCH_MAX_RESULTS');
        Configuration::deleteByName('SMARTSEARCH_SHOW_PRICE');
        Configuration::deleteByName('SMARTSEARCH_SHOW_IMAGE');
        Configuration::deleteByName('SMARTSEARCH_SHOW_DESCRIPTION');
        Configuration::deleteByName('SMARTSEARCH_SHOW_CATEGORY');
        Configuration::deleteByName('SMARTSEARCH_DEBOUNCE_TIME');
        Configuration::deleteByName('SMARTSEARCH_HIGHLIGHT');

        return parent::uninstall() && $this->uninstallDb();
    }

    /**
     * Installazione tabelle database (per statistiche ricerche)
     */
    protected function installDb()
    {
        $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'smartsearch_stats` (
            `id_smartsearch_stat` INT(11) UNSIGNED NOT NULL AUTO_INCREMENT,
            `search_query` VARCHAR(255) NOT NULL,
            `results_count` INT(11) NOT NULL DEFAULT 0,
            `clicked_product_id` INT(11) UNSIGNED DEFAULT NULL,
            `id_lang` INT(11) UNSIGNED NOT NULL,
            `id_shop` INT(11) UNSIGNED NOT NULL,
            `date_add` DATETIME NOT NULL,
            PRIMARY KEY (`id_smartsearch_stat`),
            INDEX `search_query` (`search_query`),
            INDEX `date_add` (`date_add`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';

        return Db::getInstance()->execute($sql);
    }

    /**
     * Disinstallazione tabelle database
     */
    protected function uninstallDb()
    {
        return Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'smartsearch_stats`');
    }

    /**
     * Hook per aggiungere CSS e JS
     */
    public function hookActionFrontControllerSetMedia($params)
    {
        if (!Configuration::get('SMARTSEARCH_ENABLED')) {
            return;
        }

        // CSS
        $this->context->controller->registerStylesheet(
            'smartsearch-css',
            'modules/' . $this->name . '/views/css/smartsearch.css',
            ['media' => 'all', 'priority' => 150]
        );

        // JavaScript
        $this->context->controller->registerJavascript(
            'smartsearch-js',
            'modules/' . $this->name . '/views/js/smartsearch.js',
            ['position' => 'bottom', 'priority' => 150]
        );
    }

    /**
     * Hook displayHeader per variabili JS
     */
    public function hookDisplayHeader($params)
    {
        if (!Configuration::get('SMARTSEARCH_ENABLED')) {
            return;
        }

        // Passa le configurazioni al JavaScript
        Media::addJsDef([
            'smartsearch_config' => [
                'ajax_url' => $this->context->link->getModuleLink($this->name, 'search'),
                'min_chars' => (int)Configuration::get('SMARTSEARCH_MIN_CHARS'),
                'max_results' => (int)Configuration::get('SMARTSEARCH_MAX_RESULTS'),
                'debounce_time' => (int)Configuration::get('SMARTSEARCH_DEBOUNCE_TIME'),
                'show_price' => (bool)Configuration::get('SMARTSEARCH_SHOW_PRICE'),
                'show_image' => (bool)Configuration::get('SMARTSEARCH_SHOW_IMAGE'),
                'show_description' => (bool)Configuration::get('SMARTSEARCH_SHOW_DESCRIPTION'),
                'show_category' => (bool)Configuration::get('SMARTSEARCH_SHOW_CATEGORY'),
                'highlight' => (bool)Configuration::get('SMARTSEARCH_HIGHLIGHT'),
                'search_placeholder' => $this->l('Cerca prodotti...'),
                'no_results' => $this->l('Nessun risultato trovato'),
                'view_all' => $this->l('Vedi tutti i risultati'),
                'currency_sign' => $this->context->currency->sign,
                'currency_format' => $this->context->currency->format,
            ]
        ]);

        return '';
    }

    /**
     * Configurazione del modulo nel back-office
     */
    public function getContent()
    {
        $output = '';

        // Salvataggio configurazione
        if (Tools::isSubmit('submitSmartSearchConfig')) {
            Configuration::updateValue('SMARTSEARCH_ENABLED', (int)Tools::getValue('SMARTSEARCH_ENABLED'));
            Configuration::updateValue('SMARTSEARCH_MIN_CHARS', (int)Tools::getValue('SMARTSEARCH_MIN_CHARS'));
            Configuration::updateValue('SMARTSEARCH_MAX_RESULTS', (int)Tools::getValue('SMARTSEARCH_MAX_RESULTS'));
            Configuration::updateValue('SMARTSEARCH_SHOW_PRICE', (int)Tools::getValue('SMARTSEARCH_SHOW_PRICE'));
            Configuration::updateValue('SMARTSEARCH_SHOW_IMAGE', (int)Tools::getValue('SMARTSEARCH_SHOW_IMAGE'));
            Configuration::updateValue('SMARTSEARCH_SHOW_DESCRIPTION', (int)Tools::getValue('SMARTSEARCH_SHOW_DESCRIPTION'));
            Configuration::updateValue('SMARTSEARCH_SHOW_CATEGORY', (int)Tools::getValue('SMARTSEARCH_SHOW_CATEGORY'));
            Configuration::updateValue('SMARTSEARCH_DEBOUNCE_TIME', (int)Tools::getValue('SMARTSEARCH_DEBOUNCE_TIME'));
            Configuration::updateValue('SMARTSEARCH_HIGHLIGHT', (int)Tools::getValue('SMARTSEARCH_HIGHLIGHT'));

            $output .= $this->displayConfirmation($this->l('Impostazioni salvate con successo'));
        }

        return $output . $this->renderConfigForm();
    }

    /**
     * Render del form di configurazione
     */
    protected function renderConfigForm()
    {
        $fields_form = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Configurazione Smart Search'),
                    'icon' => 'icon-search'
                ],
                'input' => [
                    [
                        'type' => 'switch',
                        'label' => $this->l('Abilita Smart Search'),
                        'name' => 'SMARTSEARCH_ENABLED',
                        'is_bool' => true,
                        'values' => [
                            ['id' => 'active_on', 'value' => 1, 'label' => $this->l('Sì')],
                            ['id' => 'active_off', 'value' => 0, 'label' => $this->l('No')]
                        ],
                        'desc' => $this->l('Attiva o disattiva la ricerca dinamica')
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Caratteri minimi'),
                        'name' => 'SMARTSEARCH_MIN_CHARS',
                        'class' => 'fixed-width-sm',
                        'desc' => $this->l('Numero minimo di caratteri per avviare la ricerca')
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Risultati massimi'),
                        'name' => 'SMARTSEARCH_MAX_RESULTS',
                        'class' => 'fixed-width-sm',
                        'desc' => $this->l('Numero massimo di risultati da mostrare nel dropdown')
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->l('Tempo di debounce (ms)'),
                        'name' => 'SMARTSEARCH_DEBOUNCE_TIME',
                        'class' => 'fixed-width-sm',
                        'desc' => $this->l('Ritardo in millisecondi prima di effettuare la ricerca')
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Mostra prezzo'),
                        'name' => 'SMARTSEARCH_SHOW_PRICE',
                        'is_bool' => true,
                        'values' => [
                            ['id' => 'price_on', 'value' => 1, 'label' => $this->l('Sì')],
                            ['id' => 'price_off', 'value' => 0, 'label' => $this->l('No')]
                        ]
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Mostra immagine'),
                        'name' => 'SMARTSEARCH_SHOW_IMAGE',
                        'is_bool' => true,
                        'values' => [
                            ['id' => 'image_on', 'value' => 1, 'label' => $this->l('Sì')],
                            ['id' => 'image_off', 'value' => 0, 'label' => $this->l('No')]
                        ]
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Mostra descrizione'),
                        'name' => 'SMARTSEARCH_SHOW_DESCRIPTION',
                        'is_bool' => true,
                        'values' => [
                            ['id' => 'desc_on', 'value' => 1, 'label' => $this->l('Sì')],
                            ['id' => 'desc_off', 'value' => 0, 'label' => $this->l('No')]
                        ]
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Mostra categoria'),
                        'name' => 'SMARTSEARCH_SHOW_CATEGORY',
                        'is_bool' => true,
                        'values' => [
                            ['id' => 'cat_on', 'value' => 1, 'label' => $this->l('Sì')],
                            ['id' => 'cat_off', 'value' => 0, 'label' => $this->l('No')]
                        ]
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->l('Evidenzia termini di ricerca'),
                        'name' => 'SMARTSEARCH_HIGHLIGHT',
                        'is_bool' => true,
                        'values' => [
                            ['id' => 'highlight_on', 'value' => 1, 'label' => $this->l('Sì')],
                            ['id' => 'highlight_off', 'value' => 0, 'label' => $this->l('No')]
                        ],
                        'desc' => $this->l('Evidenzia i termini cercati nei risultati')
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Salva'),
                    'class' => 'btn btn-default pull-right'
                ]
            ]
        ];

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->default_form_language = (int)Configuration::get('PS_LANG_DEFAULT');
        $helper->allow_employee_form_lang = Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') ? Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG') : 0;
        $helper->title = $this->displayName;
        $helper->submit_action = 'submitSmartSearchConfig';

        // Valori correnti
        $helper->fields_value['SMARTSEARCH_ENABLED'] = Configuration::get('SMARTSEARCH_ENABLED');
        $helper->fields_value['SMARTSEARCH_MIN_CHARS'] = Configuration::get('SMARTSEARCH_MIN_CHARS');
        $helper->fields_value['SMARTSEARCH_MAX_RESULTS'] = Configuration::get('SMARTSEARCH_MAX_RESULTS');
        $helper->fields_value['SMARTSEARCH_SHOW_PRICE'] = Configuration::get('SMARTSEARCH_SHOW_PRICE');
        $helper->fields_value['SMARTSEARCH_SHOW_IMAGE'] = Configuration::get('SMARTSEARCH_SHOW_IMAGE');
        $helper->fields_value['SMARTSEARCH_SHOW_DESCRIPTION'] = Configuration::get('SMARTSEARCH_SHOW_DESCRIPTION');
        $helper->fields_value['SMARTSEARCH_SHOW_CATEGORY'] = Configuration::get('SMARTSEARCH_SHOW_CATEGORY');
        $helper->fields_value['SMARTSEARCH_DEBOUNCE_TIME'] = Configuration::get('SMARTSEARCH_DEBOUNCE_TIME');
        $helper->fields_value['SMARTSEARCH_HIGHLIGHT'] = Configuration::get('SMARTSEARCH_HIGHLIGHT');

        return $helper->generateForm([$fields_form]);
    }
}
