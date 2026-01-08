<?php
/**
 * SmartSearch 2.0 - Admin Controller per Banner Promozionali
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class AdminSmartSearchBannersController extends ModuleAdminController
{
    /** @var string */
    protected $uploadDir;

    public function __construct()
    {
        $this->table = 'smartsearch_banners';
        $this->className = 'SmartSearchBanner';
        $this->identifier = 'id_smartsearch_banner';
        $this->lang = false;
        $this->bootstrap = true;
        $this->addRowAction('edit');
        $this->addRowAction('delete');

        parent::__construct();

        $this->uploadDir = _PS_MODULE_DIR_ . 'smartsearch/views/img/banners/';

        // Create upload directory if not exists
        if (!is_dir($this->uploadDir)) {
            mkdir($this->uploadDir, 0755, true);
        }

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
            'id_smartsearch_banner' => [
                'title' => $this->l('ID'),
                'align' => 'center',
                'class' => 'fixed-width-xs',
            ],
            'image_preview' => [
                'title' => $this->l('Preview'),
                'callback' => 'renderImagePreview',
                'search' => false,
                'orderby' => false,
            ],
            'name' => [
                'title' => $this->l('Name'),
            ],
            'position' => [
                'title' => $this->l('Position'),
                'align' => 'center',
                'type' => 'select',
                'list' => [
                    'top' => $this->l('Top'),
                    'middle' => $this->l('Middle (after 4 products)'),
                    'bottom' => $this->l('Bottom'),
                ],
                'filter_key' => 'a!position',
            ],
            'keywords' => [
                'title' => $this->l('Keywords'),
                'maxlength' => 30,
            ],
            'date_start' => [
                'title' => $this->l('Start'),
                'type' => 'datetime',
                'align' => 'center',
            ],
            'date_end' => [
                'title' => $this->l('End'),
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

        $this->_where = 'AND a.id_shop = ' . (int)$this->context->shop->id;
        $this->_orderBy = 'position';
        $this->_orderWay = 'ASC';
    }

    /**
     * Render image preview in list
     */
    public function renderImagePreview($value, $row)
    {
        if (!empty($row['image'])) {
            $imgUrl = _MODULE_DIR_ . 'smartsearch/views/img/banners/' . $row['image'];
            return '<img src="' . $imgUrl . '" alt="" style="max-width: 150px; max-height: 50px; border-radius: 4px;">';
        }
        return '-';
    }

    public function renderForm()
    {
        $positions = [
            ['id' => 'top', 'name' => $this->l('Top - Above results')],
            ['id' => 'middle', 'name' => $this->l('Middle - After 4 products')],
            ['id' => 'bottom', 'name' => $this->l('Bottom - Below results')],
        ];

        // Get current image if editing
        $currentImage = '';
        if ($this->object && $this->object->id) {
            $currentImage = Db::getInstance()->getValue('
                SELECT image FROM `' . _DB_PREFIX_ . 'smartsearch_banners`
                WHERE id_smartsearch_banner = ' . (int)$this->object->id
            );
        }

        $this->fields_form = [
            'legend' => [
                'title' => $this->l('Search Banner'),
                'icon' => 'icon-picture-o',
            ],
            'input' => [
                [
                    'type' => 'text',
                    'label' => $this->l('Banner Name'),
                    'name' => 'name',
                    'required' => true,
                    'desc' => $this->l('Internal name for identification'),
                ],
                [
                    'type' => 'file',
                    'label' => $this->l('Banner Image'),
                    'name' => 'banner_image',
                    'required' => !$currentImage,
                    'desc' => $this->l('Recommended: 1200x150px for desktop, will be responsive. Formats: JPG, PNG, GIF, WEBP'),
                    'display_image' => true,
                    'image' => $currentImage ? '<img src="' . _MODULE_DIR_ . 'smartsearch/views/img/banners/' . $currentImage . '" style="max-width: 400px; margin: 10px 0;">' : '',
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('Link URL'),
                    'name' => 'link',
                    'desc' => $this->l('Optional: URL to open when banner is clicked'),
                ],
                [
                    'type' => 'select',
                    'label' => $this->l('Position'),
                    'name' => 'position',
                    'required' => true,
                    'options' => [
                        'query' => $positions,
                        'id' => 'id',
                        'name' => 'name',
                    ],
                ],
                [
                    'type' => 'text',
                    'label' => $this->l('Keywords'),
                    'name' => 'keywords',
                    'desc' => $this->l('Optional: Comma-separated keywords. Banner shows only for these searches. Leave empty to show always.'),
                ],
                [
                    'type' => 'datetime',
                    'label' => $this->l('Start Date'),
                    'name' => 'date_start',
                    'desc' => $this->l('Optional: When to start showing this banner'),
                ],
                [
                    'type' => 'datetime',
                    'label' => $this->l('End Date'),
                    'name' => 'date_end',
                    'desc' => $this->l('Optional: When to stop showing this banner'),
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
        $id = (int)Tools::getValue('id_smartsearch_banner');
        $name = pSQL(Tools::getValue('name'));
        $link = pSQL(Tools::getValue('link'));
        $position = pSQL(Tools::getValue('position'));
        $keywords = pSQL(Tools::getValue('keywords'));
        $dateStart = Tools::getValue('date_start') ?: null;
        $dateEnd = Tools::getValue('date_end') ?: null;
        $active = (int)Tools::getValue('active');

        // Validation
        if (empty($name)) {
            $this->errors[] = $this->l('Please enter a banner name.');
            return false;
        }

        // Handle image upload
        $image = '';
        if (isset($_FILES['banner_image']) && !empty($_FILES['banner_image']['name'])) {
            $uploadResult = $this->uploadBannerImage();
            if ($uploadResult['success']) {
                $image = $uploadResult['filename'];
            } else {
                $this->errors[] = $uploadResult['error'];
                return false;
            }
        } elseif ($id) {
            // Keep existing image
            $image = Db::getInstance()->getValue('
                SELECT image FROM `' . _DB_PREFIX_ . 'smartsearch_banners`
                WHERE id_smartsearch_banner = ' . $id
            );
        } else {
            $this->errors[] = $this->l('Please upload a banner image.');
            return false;
        }

        $now = date('Y-m-d H:i:s');

        if ($id) {
            // Delete old image if new one uploaded
            if (isset($_FILES['banner_image']) && !empty($_FILES['banner_image']['name'])) {
                $oldImage = Db::getInstance()->getValue('
                    SELECT image FROM `' . _DB_PREFIX_ . 'smartsearch_banners`
                    WHERE id_smartsearch_banner = ' . $id
                );
                if ($oldImage && file_exists($this->uploadDir . $oldImage)) {
                    unlink($this->uploadDir . $oldImage);
                }
            }

            // Update
            $result = Db::getInstance()->update('smartsearch_banners', [
                'name' => $name,
                'image' => $image,
                'link' => $link,
                'position' => $position,
                'keywords' => $keywords,
                'date_start' => $dateStart,
                'date_end' => $dateEnd,
                'active' => $active,
                'date_upd' => $now,
            ], 'id_smartsearch_banner = ' . $id);
        } else {
            // Insert
            $result = Db::getInstance()->insert('smartsearch_banners', [
                'name' => $name,
                'image' => $image,
                'link' => $link,
                'position' => $position,
                'keywords' => $keywords,
                'id_shop' => (int)$this->context->shop->id,
                'id_lang' => (int)$this->context->language->id,
                'active' => $active,
                'date_start' => $dateStart,
                'date_end' => $dateEnd,
                'date_add' => $now,
                'date_upd' => $now,
            ]);
        }

        if ($result) {
            $this->confirmations[] = $this->l('Banner saved successfully.');
            Tools::redirectAdmin($this->context->link->getAdminLink('AdminSmartSearchBanners'));
        } else {
            $this->errors[] = $this->l('Error saving banner.');
        }

        return $result;
    }

    /**
     * Upload banner image
     */
    protected function uploadBannerImage()
    {
        $file = $_FILES['banner_image'];

        // Check for upload errors
        if ($file['error'] !== UPLOAD_ERR_OK) {
            return ['success' => false, 'error' => $this->l('Upload error: ') . $file['error']];
        }

        // Check file type
        $allowedTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);

        if (!in_array($mimeType, $allowedTypes)) {
            return ['success' => false, 'error' => $this->l('Invalid file type. Allowed: JPG, PNG, GIF, WEBP')];
        }

        // Check file size (max 2MB)
        if ($file['size'] > 2 * 1024 * 1024) {
            return ['success' => false, 'error' => $this->l('File too large. Maximum 2MB allowed.')];
        }

        // Generate unique filename
        $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
        $filename = 'banner_' . uniqid() . '_' . time() . '.' . strtolower($extension);

        // Move uploaded file
        if (move_uploaded_file($file['tmp_name'], $this->uploadDir . $filename)) {
            return ['success' => true, 'filename' => $filename];
        }

        return ['success' => false, 'error' => $this->l('Failed to save uploaded file.')];
    }

    public function processDelete()
    {
        $id = (int)Tools::getValue('id_smartsearch_banner');

        if ($id) {
            // Delete image file
            $image = Db::getInstance()->getValue('
                SELECT image FROM `' . _DB_PREFIX_ . 'smartsearch_banners`
                WHERE id_smartsearch_banner = ' . $id
            );
            if ($image && file_exists($this->uploadDir . $image)) {
                unlink($this->uploadDir . $image);
            }

            Db::getInstance()->delete('smartsearch_banners', 'id_smartsearch_banner = ' . $id);
            $this->confirmations[] = $this->l('Banner deleted.');
        }

        return true;
    }

    public function processStatus()
    {
        $id = (int)Tools::getValue('id_smartsearch_banner');

        if ($id) {
            $current = Db::getInstance()->getValue('
                SELECT active FROM `' . _DB_PREFIX_ . 'smartsearch_banners`
                WHERE id_smartsearch_banner = ' . $id
            );

            Db::getInstance()->update('smartsearch_banners', [
                'active' => !$current,
                'date_upd' => date('Y-m-d H:i:s'),
            ], 'id_smartsearch_banner = ' . $id);
        }

        Tools::redirectAdmin($this->context->link->getAdminLink('AdminSmartSearchBanners'));
    }

    public function renderList()
    {
        $this->page_header_toolbar_btn['new'] = [
            'href' => self::$currentIndex . '&add' . $this->table . '&token=' . $this->token,
            'desc' => $this->l('Add new banner'),
            'icon' => 'process-icon-new',
        ];

        return parent::renderList();
    }

    public function initPageHeaderToolbar()
    {
        if (empty($this->display)) {
            $this->page_header_toolbar_btn['new_banner'] = [
                'href' => self::$currentIndex . '&add' . $this->table . '&token=' . $this->token,
                'desc' => $this->l('Add new banner'),
                'icon' => 'process-icon-new',
            ];
        }

        parent::initPageHeaderToolbar();
    }
}
