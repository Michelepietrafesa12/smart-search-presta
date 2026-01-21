{**
 * SmartSearch - Template per slider prodotti consigliati
 * Usato in pagina prodotto e carrello
 *}

{if isset($smartsearch_recommendations) && $smartsearch_recommendations|count > 0}
<div class="smartsearch-recommendations smartsearch-recommendations-{$smartsearch_rec_type|escape:'html':'UTF-8'}" id="smartsearch-recommendations">
    <div class="smartsearch-rec-header">
        <h2 class="smartsearch-rec-title">{$smartsearch_rec_title|escape:'html':'UTF-8'}</h2>
        <div class="smartsearch-rec-nav">
            <button type="button" class="smartsearch-rec-prev" aria-label="Precedente">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="15 18 9 12 15 6"></polyline>
                </svg>
            </button>
            <button type="button" class="smartsearch-rec-next" aria-label="Successivo">
                <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <polyline points="9 18 15 12 9 6"></polyline>
                </svg>
            </button>
        </div>
    </div>

    <div class="smartsearch-rec-slider-wrapper">
        <div class="smartsearch-rec-slider" id="smartsearch-rec-slider">
            {foreach from=$smartsearch_recommendations item=product}
            <div class="smartsearch-rec-item" data-product-id="{$product.id|intval}">
                <a href="{$product.url|escape:'html':'UTF-8'}" class="smartsearch-rec-link">
                    <div class="smartsearch-rec-image-wrapper">
                        {if $product.image}
                        <img src="{$product.image|escape:'html':'UTF-8'}"
                             alt="{$product.name|escape:'html':'UTF-8'}"
                             class="smartsearch-rec-image"
                             loading="lazy">
                        {else}
                        <div class="smartsearch-rec-no-image">
                            <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1">
                                <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                                <circle cx="8.5" cy="8.5" r="1.5"></circle>
                                <polyline points="21 15 16 10 5 21"></polyline>
                            </svg>
                        </div>
                        {/if}

                        {if $product.price_old}
                        <span class="smartsearch-rec-discount-badge">
                            -{math equation="round((1 - new/old) * 100)" new=$product.price_raw old=$product.price_old|regex_replace:"/[^0-9.]/":""}%
                        </span>
                        {/if}

                        {if !$product.in_stock}
                        <span class="smartsearch-rec-outofstock-badge">Esaurito</span>
                        {/if}
                    </div>

                    <div class="smartsearch-rec-info">
                        {if $product.manufacturer}
                        <span class="smartsearch-rec-brand">{$product.manufacturer|escape:'html':'UTF-8'}</span>
                        {/if}

                        <h3 class="smartsearch-rec-name">{$product.name|escape:'html':'UTF-8'}</h3>

                        <div class="smartsearch-rec-price-wrapper">
                            <span class="smartsearch-rec-price">{$product.price|escape:'html':'UTF-8'}</span>
                            {if $product.price_old}
                            <span class="smartsearch-rec-price-old">{$product.price_old|escape:'html':'UTF-8'}</span>
                            {/if}
                        </div>
                    </div>
                </a>
            </div>
            {/foreach}
        </div>
    </div>
</div>

<style>
/* SmartSearch Recommendations Slider */
.smartsearch-recommendations {
    margin: 30px 0;
    padding: 20px;
    background: #f8f9fa;
    border-radius: 12px;
}

.smartsearch-rec-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.smartsearch-rec-title {
    font-size: 1.25rem;
    font-weight: 600;
    color: #1e293b;
    margin: 0;
}

.smartsearch-rec-nav {
    display: flex;
    gap: 8px;
}

.smartsearch-rec-prev,
.smartsearch-rec-next {
    width: 40px;
    height: 40px;
    border: 1px solid #e2e8f0;
    background: #fff;
    border-radius: 50%;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: all 0.2s ease;
    color: #64748b;
}

.smartsearch-rec-prev:hover,
.smartsearch-rec-next:hover {
    background: #f97316;
    border-color: #f97316;
    color: #fff;
}

.smartsearch-rec-prev:disabled,
.smartsearch-rec-next:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.smartsearch-rec-slider-wrapper {
    overflow: hidden;
    position: relative;
}

.smartsearch-rec-slider {
    display: flex;
    gap: 16px;
    transition: transform 0.3s ease;
    will-change: transform;
}

.smartsearch-rec-item {
    flex: 0 0 calc(25% - 12px);
    min-width: 200px;
    max-width: 280px;
}

@media (max-width: 1200px) {
    .smartsearch-rec-item {
        flex: 0 0 calc(33.333% - 11px);
    }
}

@media (max-width: 768px) {
    .smartsearch-rec-item {
        flex: 0 0 calc(50% - 8px);
        min-width: 160px;
    }

    .smartsearch-rec-title {
        font-size: 1.1rem;
    }
}

@media (max-width: 480px) {
    .smartsearch-rec-item {
        flex: 0 0 calc(100% - 0px);
        min-width: 100%;
    }

    .smartsearch-recommendations {
        padding: 15px;
    }
}

.smartsearch-rec-link {
    display: block;
    background: #fff;
    border-radius: 10px;
    overflow: hidden;
    text-decoration: none;
    color: inherit;
    box-shadow: 0 2px 8px rgba(0,0,0,0.06);
    transition: all 0.2s ease;
    height: 100%;
}

.smartsearch-rec-link:hover {
    transform: translateY(-4px);
    box-shadow: 0 8px 20px rgba(0,0,0,0.1);
}

.smartsearch-rec-image-wrapper {
    position: relative;
    aspect-ratio: 1;
    background: #f8fafc;
    overflow: hidden;
}

.smartsearch-rec-image {
    width: 100%;
    height: 100%;
    object-fit: contain;
    padding: 10px;
}

.smartsearch-rec-no-image {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #cbd5e1;
}

.smartsearch-rec-discount-badge {
    position: absolute;
    top: 8px;
    left: 8px;
    background: #dc2626;
    color: #fff;
    font-size: 0.75rem;
    font-weight: 600;
    padding: 4px 8px;
    border-radius: 4px;
}

.smartsearch-rec-outofstock-badge {
    position: absolute;
    bottom: 8px;
    left: 8px;
    right: 8px;
    background: rgba(0,0,0,0.7);
    color: #fff;
    font-size: 0.75rem;
    font-weight: 500;
    padding: 6px;
    text-align: center;
    border-radius: 4px;
}

.smartsearch-rec-info {
    padding: 12px;
}

.smartsearch-rec-brand {
    display: block;
    font-size: 0.7rem;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    color: #64748b;
    margin-bottom: 4px;
}

.smartsearch-rec-name {
    font-size: 0.9rem;
    font-weight: 500;
    color: #1e293b;
    margin: 0 0 8px 0;
    line-height: 1.3;
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
    min-height: 2.6em;
}

.smartsearch-rec-price-wrapper {
    display: flex;
    align-items: center;
    gap: 8px;
    flex-wrap: wrap;
}

.smartsearch-rec-price {
    font-size: 1.1rem;
    font-weight: 700;
    color: #059669;
}

.smartsearch-rec-price-old {
    font-size: 0.85rem;
    color: #94a3b8;
    text-decoration: line-through;
}

/* Stile specifico per carrello */
.smartsearch-recommendations-cart {
    background: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
    border: 2px solid #f59e0b;
}

.smartsearch-recommendations-cart .smartsearch-rec-title {
    color: #92400e;
}

.smartsearch-recommendations-cart .smartsearch-rec-prev:hover,
.smartsearch-recommendations-cart .smartsearch-rec-next:hover {
    background: #f59e0b;
    border-color: #f59e0b;
}
</style>

<script>
(function() {
    'use strict';

    document.addEventListener('DOMContentLoaded', function() {
        initRecommendationsSlider();
    });

    function initRecommendationsSlider() {
        var container = document.getElementById('smartsearch-recommendations');
        if (!container) return;

        var slider = document.getElementById('smartsearch-rec-slider');
        var prevBtn = container.querySelector('.smartsearch-rec-prev');
        var nextBtn = container.querySelector('.smartsearch-rec-next');

        if (!slider || !prevBtn || !nextBtn) return;

        var items = slider.querySelectorAll('.smartsearch-rec-item');
        var currentIndex = 0;
        var itemsToShow = getItemsToShow();
        var maxIndex = Math.max(0, items.length - itemsToShow);

        function getItemsToShow() {
            var width = window.innerWidth;
            if (width <= 480) return 1;
            if (width <= 768) return 2;
            if (width <= 1200) return 3;
            return 4;
        }

        function updateSlider() {
            var itemWidth = items[0].offsetWidth + 16; // include gap
            slider.style.transform = 'translateX(-' + (currentIndex * itemWidth) + 'px)';

            prevBtn.disabled = currentIndex === 0;
            nextBtn.disabled = currentIndex >= maxIndex;
        }

        prevBtn.addEventListener('click', function() {
            if (currentIndex > 0) {
                currentIndex--;
                updateSlider();
            }
        });

        nextBtn.addEventListener('click', function() {
            if (currentIndex < maxIndex) {
                currentIndex++;
                updateSlider();
            }
        });

        // Touch swipe support
        var touchStartX = 0;
        var touchEndX = 0;

        slider.addEventListener('touchstart', function(e) {
            touchStartX = e.changedTouches[0].screenX;
        }, { passive: true });

        slider.addEventListener('touchend', function(e) {
            touchEndX = e.changedTouches[0].screenX;
            handleSwipe();
        }, { passive: true });

        function handleSwipe() {
            var diff = touchStartX - touchEndX;
            if (Math.abs(diff) > 50) {
                if (diff > 0 && currentIndex < maxIndex) {
                    currentIndex++;
                    updateSlider();
                } else if (diff < 0 && currentIndex > 0) {
                    currentIndex--;
                    updateSlider();
                }
            }
        }

        // Recalculate on resize
        var resizeTimeout;
        window.addEventListener('resize', function() {
            clearTimeout(resizeTimeout);
            resizeTimeout = setTimeout(function() {
                itemsToShow = getItemsToShow();
                maxIndex = Math.max(0, items.length - itemsToShow);
                if (currentIndex > maxIndex) {
                    currentIndex = maxIndex;
                }
                updateSlider();
            }, 200);
        });

        // Initial state
        updateSlider();
    }
})();
</script>
{/if}
