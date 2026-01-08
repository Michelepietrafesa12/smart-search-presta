{*
* SmartSearch 2.0 - Admin Dashboard Template
* Pannello unificato con tabs: Impostazioni, Boosting, Banner
*}

<div class="smartsearch-dashboard">
    {* Header con logo e versione *}
    <div class="panel smartsearch-header">
        <div class="row">
            <div class="col-md-8">
                <h2>
                    <i class="icon-search"></i> Smart Search 2.0
                    <small class="text-muted">v{$module_version}</small>
                </h2>
                <p class="text-muted">{l s='Ricerca dinamica intelligente con AI, fuzzy search, sinonimi e analytics' mod='smartsearch'}</p>
            </div>
            <div class="col-md-4 text-right">
                <div class="smartsearch-stats">
                    <span class="badge badge-success">{l s='Modulo Attivo' mod='smartsearch'}</span>
                </div>
            </div>
        </div>
    </div>

    {* Tabs Navigation *}
    <ul class="nav nav-tabs smartsearch-tabs" role="tablist">
        <li class="{if $active_tab == 'settings'}active{/if}">
            <a href="{$current_url}&tab=settings" role="tab">
                <i class="icon-cogs"></i> {l s='Impostazioni' mod='smartsearch'}
            </a>
        </li>
        <li class="{if $active_tab == 'boosting'}active{/if}">
            <a href="{$current_url}&tab=boosting" role="tab">
                <i class="icon-rocket"></i> {l s='Boosting' mod='smartsearch'}
            </a>
        </li>
        <li class="{if $active_tab == 'banners'}active{/if}">
            <a href="{$current_url}&tab=banners" role="tab">
                <i class="icon-picture-o"></i> {l s='Banner' mod='smartsearch'}
            </a>
        </li>
    </ul>

    {* Tabs Content *}
    <div class="tab-content smartsearch-tab-content">
        {* Settings Tab *}
        <div class="tab-pane {if $active_tab == 'settings'}active{/if}" id="tab-settings">
            {$tabs_content.settings nofilter}
        </div>

        {* Boosting Tab *}
        <div class="tab-pane {if $active_tab == 'boosting'}active{/if}" id="tab-boosting">
            {$tabs_content.boosting nofilter}
        </div>

        {* Banners Tab *}
        <div class="tab-pane {if $active_tab == 'banners'}active{/if}" id="tab-banners">
            {$tabs_content.banners nofilter}
        </div>
    </div>

    {* Footer *}
    <div class="panel smartsearch-footer">
        <div class="row">
            <div class="col-md-6">
                <small class="text-muted">
                    <i class="icon-info-circle"></i>
                    {l s='Smart Search 2.0 - Developed by Michele Pietrafesa' mod='smartsearch'}
                </small>
            </div>
            <div class="col-md-6 text-right">
                <small class="text-muted">
                    <i class="icon-leaf"></i> {l s='Impatto minimo sulle performance' mod='smartsearch'}
                </small>
            </div>
        </div>
    </div>
</div>
