<?php
/**
 * SmartSearch 2.0 - Admin Analytics Controller
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class AdminSmartSearchAnalyticsController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
    }

    public function initContent()
    {
        // Redirect to Dashboard
        Tools::redirectAdmin($this->context->link->getAdminLink('AdminSmartSearchDashboard'));
    }
}
