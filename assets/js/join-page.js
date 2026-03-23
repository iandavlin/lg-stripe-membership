/**
 * Join Page — Stripe Embedded Checkout
 *
 * Renders tier selection cards from localized lgsmJoin data,
 * billing interval toggle, and mounts Stripe Embedded Checkout
 * when a tier is selected.
 *
 * @package LG_Stripe_Membership
 */
(function () {
    'use strict';

    var config = window.lgsmJoin || {};
    var stripe = null;
    var checkout = null;
    var currentInterval = config.defaultInterval || 'yearly';

    /* ------------------------------------------------------------------
     * Init
     * ----------------------------------------------------------------*/

    function init() {
        if (!config.publishableKey) {
            console.error('LGSM: Missing Stripe publishable key.');
            return;
        }

        stripe = Stripe(config.publishableKey);

        var tiersEl = document.getElementById('lgsm-tiers');
        if (tiersEl) {
            renderTiers();
        }

        // Manage subscription button (for logged-in members)
        var manageBtn = document.getElementById('lgsm-manage-btn');
        if (manageBtn) {
            manageBtn.addEventListener('click', openPortal);
        }

        // Back button
        var backBtn = document.getElementById('lgsm-back-btn');
        if (backBtn) {
            backBtn.addEventListener('click', goBack);
        }
    }

    /* ------------------------------------------------------------------
     * Render Tiers
     * ----------------------------------------------------------------*/

    function renderTiers() {
        var container = document.getElementById('lgsm-tiers');
        if (!container || !config.tiers || !config.tiers.length) {
            return;
        }

        var html = '';

        // Check if any tier has multiple intervals
        var hasMultipleIntervals = config.tiers.some(function (tier) {
            return tier.prices && tier.prices.length > 1;
        });

        // Billing toggle
        if (hasMultipleIntervals) {
            html += '<div class="lgsm-join__toggle">';
            html += '<div class="lgsm-toggle">';
            html += '<button type="button" class="lgsm-toggle__btn' + (currentInterval === 'monthly' ? ' lgsm-toggle__btn--active' : '') + '" data-interval="monthly">Monthly</button>';
            html += '<button type="button" class="lgsm-toggle__btn' + (currentInterval === 'yearly' ? ' lgsm-toggle__btn--active' : '') + '" data-interval="yearly">Yearly <span class="lgsm-toggle__badge">Save</span></button>';
            html += '</div>';
            html += '</div>';
        }

        // Tier cards
        html += '<div class="lgsm-tier-grid">';
        config.tiers.forEach(function (tier) {
            var price = getPriceForInterval(tier, currentInterval);
            if (!price) return;

            html += '<div class="lgsm-tier-card" data-role="' + esc(tier.role) + '">';
            html += '<h3 class="lgsm-tier-card__name">' + esc(tier.label || tier.role) + '</h3>';
            html += '<div class="lgsm-tier-card__price" data-monthly="' + esc(getDisplayPrice(tier, 'monthly')) + '" data-yearly="' + esc(getDisplayPrice(tier, 'yearly')) + '">' + esc(price.display || price.price_id) + '</div>';
            html += '<div class="lgsm-tier-card__interval">' + (currentInterval === 'monthly' ? 'per month' : 'per year') + '</div>';
            html += '<button type="button" class="lgsm-btn lgsm-btn--primary lgsm-tier-card__select" data-price-id="' + esc(price.price_id) + '">Select Plan</button>';
            html += '</div>';
        });
        html += '</div>';

        container.innerHTML = html;

        // Bind toggle buttons
        var toggleBtns = container.querySelectorAll('.lgsm-toggle__btn');
        toggleBtns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                currentInterval = btn.getAttribute('data-interval');
                renderTiers(); // Re-render with new interval
            });
        });

        // Bind select buttons
        var selectBtns = container.querySelectorAll('.lgsm-tier-card__select');
        selectBtns.forEach(function (btn) {
            btn.addEventListener('click', function () {
                var priceId = btn.getAttribute('data-price-id');
                selectTier(priceId);
            });
        });
    }

    /* ------------------------------------------------------------------
     * Price Helpers
     * ----------------------------------------------------------------*/

    function getPriceForInterval(tier, interval) {
        if (!tier.prices || !tier.prices.length) return null;

        // Try to find matching interval
        for (var i = 0; i < tier.prices.length; i++) {
            if (tier.prices[i].interval === interval) {
                return tier.prices[i];
            }
        }
        // Fallback to first price
        return tier.prices[0];
    }

    function getDisplayPrice(tier, interval) {
        var price = getPriceForInterval(tier, interval);
        return price ? (price.display || price.price_id) : '';
    }

    /* ------------------------------------------------------------------
     * Select Tier — Create Checkout Session
     * ----------------------------------------------------------------*/

    function selectTier(priceId) {
        var tiersEl = document.getElementById('lgsm-tiers');
        var checkoutEl = document.getElementById('lgsm-checkout');
        var loadingEl = document.getElementById('lgsm-loading');
        var errorEl = document.getElementById('lgsm-error');

        // Hide tiers, show loading
        tiersEl.style.display = 'none';
        errorEl.style.display = 'none';
        loadingEl.style.display = 'block';

        fetch(config.createCheckoutUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': config.nonce,
            },
            body: JSON.stringify({ price_id: priceId }),
        })
            .then(function (res) {
                return res.json().then(function (data) {
                    if (!res.ok) {
                        throw new Error(data.error || 'Checkout failed.');
                    }
                    return data;
                });
            })
            .then(function (data) {
                if (!data.clientSecret) {
                    throw new Error('No client secret returned.');
                }
                return mountCheckout(data.clientSecret);
            })
            .then(function () {
                loadingEl.style.display = 'none';
                checkoutEl.style.display = 'block';
            })
            .catch(function (err) {
                console.error('LGSM checkout error:', err);
                loadingEl.style.display = 'none';
                showError(err.message || 'Something went wrong. Please try again.');
                tiersEl.style.display = 'block';
            });
    }

    /* ------------------------------------------------------------------
     * Mount Stripe Embedded Checkout
     * ----------------------------------------------------------------*/

    function mountCheckout(clientSecret) {
        // Destroy previous instance if any
        if (checkout) {
            checkout.destroy();
            checkout = null;
        }

        return stripe
            .initEmbeddedCheckout({ clientSecret: clientSecret })
            .then(function (instance) {
                checkout = instance;
                checkout.mount('#lgsm-checkout-mount');
            });
    }

    /* ------------------------------------------------------------------
     * Go Back to Tier Selection
     * ----------------------------------------------------------------*/

    function goBack() {
        var tiersEl = document.getElementById('lgsm-tiers');
        var checkoutEl = document.getElementById('lgsm-checkout');
        var errorEl = document.getElementById('lgsm-error');

        if (checkout) {
            checkout.destroy();
            checkout = null;
        }

        checkoutEl.style.display = 'none';
        errorEl.style.display = 'none';
        tiersEl.style.display = 'block';
    }

    /* ------------------------------------------------------------------
     * Manage Subscription (Portal)
     * ----------------------------------------------------------------*/

    function openPortal() {
        var btn = document.getElementById('lgsm-manage-btn');
        if (btn) {
            btn.disabled = true;
            btn.textContent = 'Loading\u2026';
        }

        fetch(config.customerPortalUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-WP-Nonce': config.nonce,
            },
        })
            .then(function (res) {
                return res.json().then(function (data) {
                    if (!res.ok) {
                        throw new Error(data.error || 'Portal failed.');
                    }
                    return data;
                });
            })
            .then(function (data) {
                if (data.url) {
                    window.location.href = data.url;
                }
            })
            .catch(function (err) {
                console.error('LGSM portal error:', err);
                showError(err.message || 'Could not open subscription management.');
                if (btn) {
                    btn.disabled = false;
                    btn.textContent = 'Manage Subscription';
                }
            });
    }

    /* ------------------------------------------------------------------
     * Error Display
     * ----------------------------------------------------------------*/

    function showError(message) {
        var el = document.getElementById('lgsm-error');
        if (el) {
            el.textContent = message;
            el.style.display = 'block';
        }
    }

    /* ------------------------------------------------------------------
     * Escape HTML
     * ----------------------------------------------------------------*/

    function esc(str) {
        if (!str) return '';
        var div = document.createElement('div');
        div.appendChild(document.createTextNode(str));
        return div.innerHTML;
    }

    /* ------------------------------------------------------------------
     * Boot
     * ----------------------------------------------------------------*/

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
