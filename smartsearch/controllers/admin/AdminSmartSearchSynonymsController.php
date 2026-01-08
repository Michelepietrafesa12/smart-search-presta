<?php
/**
 * SmartSearch 2.0 - Admin Synonyms Controller
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class AdminSmartSearchSynonymsController extends ModuleAdminController
{
    public function __construct()
    {
        $this->table = 'smartsearch_synonyms';
        $this->className = 'ObjectModel';
        $this->lang = false;
        $this->bootstrap = true;
        $this->identifier = 'id_smartsearch_synonym';

        parent::__construct();

        $this->fields_list = [
            'id_smartsearch_synonym' => [
                'title' => $this->l('ID'),
                'align' => 'center',
                'class' => 'fixed-width-xs'
            ],
            'word' => [
                'title' => $this->l('Parola'),
                'filter_key' => 'a!word'
            ],
            'synonyms' => [
                'title' => $this->l('Sinonimi'),
                'filter_key' => 'a!synonyms'
            ],
            'active' => [
                'title' => $this->l('Attivo'),
                'active' => 'status',
                'type' => 'bool',
                'align' => 'center',
                'class' => 'fixed-width-sm'
            ],
        ];

        $this->addRowAction('edit');
        $this->addRowAction('delete');
    }

    public function renderForm()
    {
        $this->fields_form = [
            'legend' => [
                'title' => $this->l('Sinonimo'),
                'icon' => 'icon-edit'
            ],
            'input' => [
                [
                    'type' => 'text',
                    'label' => $this->l('Parola'),
                    'name' => 'word',
                    'required' => true,
                    'desc' => $this->l('La parola principale')
                ],
                [
                    'type' => 'textarea',
                    'label' => $this->l('Sinonimi'),
                    'name' => 'synonyms',
                    'required' => true,
                    'desc' => $this->l('Sinonimi separati da virgola')
                ],
                [
                    'type' => 'switch',
                    'label' => $this->l('Attivo'),
                    'name' => 'active',
                    'is_bool' => true,
                    'values' => [
                        ['id' => 'on', 'value' => 1, 'label' => $this->l('Sì')],
                        ['id' => 'off', 'value' => 0, 'label' => $this->l('No')]
                    ]
                ]
            ],
            'submit' => [
                'title' => $this->l('Salva')
            ]
        ];

        return parent::renderForm();
    }

    public function initContent()
    {
        // Redirect to Dashboard
        Tools::redirectAdmin($this->context->link->getAdminLink('AdminSmartSearchDashboard'));
    }
}
