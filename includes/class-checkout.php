<?php
/**
 * Checkout
 *
 * Creates Stripe Checkout Sessions in Embedded mode. Handles CF-IPCountry
 * price routing for developing economy pricing. Pre-checkout double payment
 * block for active Patreon members. Return URL handler for post-payment
 * user creation and auto-login. [lgsm_join] shortcode for the /join/ page.
 *
 * @package LG_Stripe_Membership
 */

defined( 'ABSPATH' ) || exit;

class LGSM_Checkout {

    /**
     * Boot: register REST routes, shortcode, and return URL handler.
     */
    public static function init(): void {
        add_action( 'rest_api_init', [ self::class, 'register_routes' ] );
        add_action( 'template_redirect', [ self::class, 'handle_return' ] );
        add_shortcode( 'lgsm_join', [ self::class, 'render_shortcode' ] );
    }

    /**
     * Register REST endpoints for checkout.
     */
    public static function register_routes(): void {
        // POST /lg-membership/v1/create-checkout — creates a Stripe Checkout Session
        register_rest_route( 'lg-membership/v1', '/create-checkout', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'create_session' ],
            'permission_callback' => '__return_true', // Public endpoint — guest checkout
        ] );

        // POST /lg-membership/v1/customer-portal — redirects to Stripe billing portal
        register_rest_route( 'lg-membership/v1', '/customer-portal', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'create_portal_session' ],
            'permission_callback' => function () {
                return is_user_logged_in();
            },
        ] );
    }

    /* ------------------------------------------------------------------
     * Create Checkout Session (Embedded mode)
     * ----------------------------------------------------------------*/

    /**
     * Create a Stripe Checkout Session (Embedded mode).
     *
     * Expects JSON body: { price_id: string, email?: string }
     * Returns: { clientSecret: string } or { error: string }
     */
    public static function create_session( \WP_REST_Request $request ): \WP_REST_Response {
        $params   = $request->get_json_params();
        $price_id = sanitize_text_field( $params['price_id'] ?? '' );
        $email    = sanitize_email( $params['email'] ?? '' );

        // Validate price_id exists in tier map
        if ( ! $price_id || ! LGSM_Admin_Settings::get_role_for_price( $price_id ) ) {
            return new \WP_REST_Response( [ 'error' => 'Invalid or unmapped price ID.' ], 400 );
        }

        // Block active Patreon members
        if ( $email && LGSM_User_Manager::is_active_patreon_member( $email ) ) {
            return new \WP_REST_Response( [
                'error' => 'You already have an active membership through Patreon. Please cancel your Patreon subscription before switching to Stripe billing.',
            ], 409 );
        }

        // Geo-routing: developing economy price swap
        $country = self::detect_country();
        if ( $country && LGSM_Admin_Settings::is_developing_country( $country ) ) {
            $dev_price = LGSM_Admin_Settings::get_dev_price_id( $price_id );
            if ( $dev_price ) {
                $price_id = $dev_price;
            }
        }

        // Build Checkout Session params
        $session_params = [
            'ui_mode'               => 'embedded',
            'mode'                  => 'subscription',
            'line_items'            => [ [ 'price' => $price_id, 'quantity' => 1 ] ],
            'return_url'            => home_url( '/join/?session_id={CHECKOUT_SESSION_ID}' ),
            'allow_promotion_codes' => true,
        ];

        // If email provided, check for existing Stripe customer
        if ( $email ) {
            $existing_user = get_user_by( 'email', $email );
            if ( $existing_user ) {
                $customer_id = LGSM_User_Manager::get_customer_id( $existing_user->ID );
                if ( $customer_id ) {
                    $session_params['customer'] = $customer_id;
                } else {
                    $session_params['customer_email'] = $email;
                }
            } else {
                $session_params['customer_email'] = $email;
            }
        }

        try {
            $stripe  = new \Stripe\StripeClient( LGSM_Admin_Settings::get_secret_key() );
            $session = $stripe->checkout->sessions->create( $session_params );

            return new \WP_REST_Response( [ 'clientSecret' => $session->client_secret ] );
        } catch ( \Stripe\Exception\ApiErrorException $e ) {
            error_log( 'LGSM: create-checkout error — ' . $e->getMessage() );
            return new \WP_REST_Response( [ 'error' => 'Could not create checkout session. Please try again.' ], 500 );
        }
    }

    /* ------------------------------------------------------------------
     * Return URL handler
     * ----------------------------------------------------------------*/

    /**
     * Handle the return URL after Stripe Embedded Checkout.
     *
     * Fires on template_redirect. Detects session_id query param on the
     * /join/ page, retrieves the session, creates/verifies the WP user,
     * sets auth cookie, and redirects to homepage.
     */
    public static function handle_return(): void {
        // Only process if session_id is present
        $session_id = isset( $_GET['session_id'] )
            ? sanitize_text_field( wp_unslash( $_GET['session_id'] ) )
            : '';

        if ( ! $session_id ) {
            return;
        }

        // Only run on the page that has our shortcode
        global $post;
        if ( ! $post || ! has_shortcode( $post->post_content ?? '', 'lgsm_join' ) ) {
            return;
        }

        try {
            $stripe  = new \Stripe\StripeClient( LGSM_Admin_Settings::get_secret_key() );
            $session = $stripe->checkout->sessions->retrieve( $session_id, [
                'expand' => [ 'subscription', 'subscription.items.data.price' ],
            ] );
        } catch ( \Exception $e ) {
            error_log( 'LGSM: Return URL — failed to retrieve session: ' . $e->getMessage() );
            wp_safe_redirect( home_url( '/join/?checkout=error' ) );
            exit;
        }

        // Verify payment completed
        if ( ( $session->status ?? '' ) !== 'complete' ) {
            error_log( 'LGSM: Return URL — session not complete: ' . ( $session->status ?? 'unknown' ) );
            wp_safe_redirect( home_url( '/join/?checkout=error' ) );
            exit;
        }

        $customer_id = $session->customer ?? '';
        $sub_id      = $session->subscription->id ?? $session->subscription ?? '';
        $email       = $session->customer_details->email ?? $session->customer_email ?? '';
        $name        = trim( $session->customer_details->name ?? '' );

        if ( ! $customer_id || ! $email ) {
            error_log( 'LGSM: Return URL — missing customer ID or email.' );
            wp_safe_redirect( home_url( '/join/?checkout=error' ) );
            exit;
        }

        // Resolve role from subscription price
        $role = null;
        if ( is_object( $session->subscription ) ) {
            $items = $session->subscription->items->data ?? [];
            if ( ! empty( $items ) ) {
                $sub_price_id = $items[0]->price->id ?? '';
                if ( $sub_price_id ) {
                    $role = LGSM_Admin_Settings::get_role_for_price( $sub_price_id );
                }
            }
        }

        if ( ! $role ) {
            error_log( "LGSM: Return URL — could not resolve role for session {$session_id}" );
            wp_safe_redirect( home_url( '/join/?checkout=error' ) );
            exit;
        }

        // Create or verify the WP user (idempotent — safe if webhook already ran)
        $sub_id_str = is_object( $sub_id ) ? $sub_id->id : (string) $sub_id;
        $result = LGSM_User_Manager::find_or_create( $customer_id, $sub_id_str, $email, $name, $role );

        error_log( "LGSM: Return URL — {$result['message']}" );

        // Auto-login
        if ( ! empty( $result['user_id'] ) ) {
            LGSM_User_Manager::login_user( $result['user_id'] );
        }

        wp_safe_redirect( home_url() );
        exit;
    }

    /* ------------------------------------------------------------------
     * Shortcode: [lgsm_join]
     * ----------------------------------------------------------------*/

    /**
     * Render the join page shortcode.
     */
    public static function render_shortcode( array $atts = [] ): string {
        // Enqueue assets
        wp_enqueue_script( 'stripe-js', 'https://js.stripe.com/v3/', [], null, true );
        wp_enqueue_script(
            'lgsm-join',
            LGSM_PLUGIN_URL . 'assets/js/join-page.js',
            [ 'stripe-js' ],
            filemtime( LGSM_PLUGIN_DIR . 'assets/js/join-page.js' ),
            true,
        );
        wp_enqueue_style(
            'lgsm-join',
            LGSM_PLUGIN_URL . 'assets/css/join-page.css',
            [],
            filemtime( LGSM_PLUGIN_DIR . 'assets/css/join-page.css' ),
        );
        wp_localize_script( 'lgsm-join', 'lgsmJoin', [
            'publishableKey'    => LGSM_Admin_Settings::get_publishable_key(),
            'createCheckoutUrl' => rest_url( 'lg-membership/v1/create-checkout' ),
            'customerPortalUrl' => rest_url( 'lg-membership/v1/customer-portal' ),
            'nonce'             => wp_create_nonce( 'wp_rest' ),
            'tiers'             => LGSM_Admin_Settings::get_tiers_for_frontend(),
            'defaultInterval'   => 'yearly',
        ] );

        ob_start();

        // Checkout error state
        $checkout_status = isset( $_GET['checkout'] )
            ? sanitize_text_field( wp_unslash( $_GET['checkout'] ) )
            : '';

        if ( $checkout_status === 'error' ) {
            echo '<div class="lgsm-join__alert lgsm-join__alert--error">';
            echo '<p>Something went wrong with your checkout. Please try again or <a href="mailto:support@loothgroup.com">contact support</a>.</p>';
            echo '</div>';
        }

        // Check logged-in user state
        if ( is_user_logged_in() ) {
            $user    = wp_get_current_user();
            $roles   = $user->roles;
            $source  = get_user_meta( $user->ID, 'payment_source', true );

            // Admin — no subscription needed
            if ( in_array( 'looth4', $roles, true ) || in_array( 'administrator', $roles, true ) ) {
                echo '<div class="lgsm-join__manage">';
                echo '<h2>Admin Account</h2>';
                echo '<p>Admin accounts have full access without a subscription.</p>';
                echo '</div>';
                return ob_get_clean();
            }

            // Active Stripe subscriber — show manage button
            if ( $source === 'stripe' && ( in_array( 'looth2', $roles, true ) || in_array( 'looth3', $roles, true ) ) ) {
                echo '<div class="lgsm-join__manage">';
                echo '<h2>You\'re a member!</h2>';
                echo '<p>Manage your subscription, update payment method, or view invoices.</p>';
                echo '<button type="button" class="lgsm-btn lgsm-btn--primary" id="lgsm-manage-btn">Manage Subscription</button>';
                echo '</div>';
                return ob_get_clean();
            }

            // Active Patreon subscriber — show message
            if ( $source === 'patreon' && ( in_array( 'looth2', $roles, true ) || in_array( 'looth3', $roles, true ) ) ) {
                echo '<div class="lgsm-join__manage">';
                echo '<h2>Patreon Membership Active</h2>';
                echo '<p>Your membership is managed through Patreon. To switch to Stripe billing, please cancel your Patreon subscription first.</p>';
                echo '</div>';
                return ob_get_clean();
            }
        }

        // Normal flow — tier selection + checkout
        ?>
        <div class="lgsm-join">
            <div class="lgsm-join__tiers" id="lgsm-tiers">
                <!-- JS renders tier cards here -->
            </div>
            <div class="lgsm-join__checkout" id="lgsm-checkout" style="display:none">
                <div id="lgsm-checkout-mount"></div>
                <button type="button" class="lgsm-btn lgsm-btn--back" id="lgsm-back-btn">&larr; Choose a different plan</button>
            </div>
            <div class="lgsm-join__error" id="lgsm-error" style="display:none"></div>
            <div class="lgsm-join__loading" id="lgsm-loading" style="display:none">
                <div class="lgsm-spinner"></div>
                <p>Loading checkout&hellip;</p>
            </div>
        </div>
        <?php

        return ob_get_clean();
    }

    /* ------------------------------------------------------------------
     * Customer Portal
     * ----------------------------------------------------------------*/

    /**
     * Create a Stripe Customer Portal session.
     *
     * Logged-in users only. Returns portal URL for frontend redirect.
     */
    public static function create_portal_session( \WP_REST_Request $request ): \WP_REST_Response {
        $user_id     = get_current_user_id();
        $customer_id = LGSM_User_Manager::get_customer_id( $user_id );

        if ( ! $customer_id ) {
            return new \WP_REST_Response( [ 'error' => 'No Stripe customer ID found for your account.' ], 400 );
        }

        try {
            $stripe  = new \Stripe\StripeClient( LGSM_Admin_Settings::get_secret_key() );
            $session = $stripe->billingPortal->sessions->create( [
                'customer'   => $customer_id,
                'return_url' => home_url(),
            ] );

            return new \WP_REST_Response( [ 'url' => $session->url ] );
        } catch ( \Exception $e ) {
            error_log( 'LGSM: Portal session error — ' . $e->getMessage() );
            return new \WP_REST_Response( [ 'error' => 'Could not create billing portal session.' ], 500 );
        }
    }

    /* ------------------------------------------------------------------
     * Helpers
     * ----------------------------------------------------------------*/

    /**
     * Detect visitor country code.
     *
     * Priority: CF-IPCountry header (Cloudflare) → GeoIP Detect plugin → debug override.
     */
    private static function detect_country(): string {
        // Debug override (test mode only)
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG && isset( $_GET['geo'] ) ) {
            $geo = sanitize_text_field( wp_unslash( $_GET['geo'] ) );
            if ( preg_match( '/^[A-Z]{2}$/', strtoupper( $geo ) ) ) {
                return strtoupper( $geo );
            }
        }

        // Cloudflare header (primary)
        if ( ! empty( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) {
            return strtoupper( sanitize_text_field( $_SERVER['HTTP_CF_IPCOUNTRY'] ) );
        }

        // GeoIP Detect plugin fallback
        if ( function_exists( 'geoip_detect2_get_info_from_current_ip' ) ) {
            $info = geoip_detect2_get_info_from_current_ip();
            if ( $info && ! empty( $info->country->isoCode ) ) {
                return strtoupper( $info->country->isoCode );
            }
        }

        return '';
    }
}
