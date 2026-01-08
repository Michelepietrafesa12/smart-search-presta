{**
 * SmartSearch 2.0 - Template barra di ricerca
 * Sovrascrive la barra di ricerca nativa di PrestaShop
 *}

<div id="smartsearch-widget" class="smartsearch-widget">
    <form method="get" action="{$smartsearch_search_url}" class="smartsearch-form">
        <div class="smartsearch-input-wrapper">
            <input
                type="text"
                name="s"
                id="smartsearch-input"
                class="smartsearch-input"
                placeholder="{$smartsearch_placeholder}"
                autocomplete="off"
                aria-label="{$smartsearch_placeholder}"
            >

            {if $smartsearch_voice_enabled}
            <button type="button" class="smartsearch-voice-btn" title="{l s='Ricerca vocale' mod='smartsearch'}">
                <svg viewBox="0 0 24 24" width="20" height="20">
                    <path fill="currentColor" d="M12 14c1.66 0 3-1.34 3-3V5c0-1.66-1.34-3-3-3S9 3.34 9 5v6c0 1.66 1.34 3 3 3zm-1 1.93c-3.94-.49-7-3.85-7-7.93h2c0 3.31 2.69 6 6 6s6-2.69 6-6h2c0 4.08-3.06 7.44-7 7.93V19h4v2H8v-2h4v-3.07z"/>
                </svg>
            </button>
            {/if}

            <button type="submit" class="smartsearch-submit-btn" aria-label="{l s='Cerca' mod='smartsearch'}">
                <svg viewBox="0 0 24 24" width="20" height="20">
                    <path fill="currentColor" d="M15.5 14h-.79l-.28-.27C15.41 12.59 16 11.11 16 9.5 16 5.91 13.09 3 9.5 3S3 5.91 3 9.5 5.91 16 9.5 16c1.61 0 3.09-.59 4.23-1.57l.27.28v.79l5 4.99L20.49 19l-4.99-5zm-6 0C7.01 14 5 11.99 5 9.5S7.01 5 9.5 5 14 7.01 14 9.5 11.99 14 9.5 14z"/>
                </svg>
            </button>
        </div>

        <div id="smartsearch-results" class="smartsearch-results smartsearch-2"></div>
    </form>
</div>
