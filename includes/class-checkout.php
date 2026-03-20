<?php
/**
 * Checkout
 *
 * Creates Stripe Checkout Sessions in Embedded mode. Handles CF-IPCountry
 * price routing for developing economy pricing. Pre-checkout double payment
 * block for active Patreon members. Return URL handler for post-payment
 * user creation and auto-login.
 *
 * Phase 2 — stubbed for now. Webhook pipeline (Phase 1) handles everything
 * when tested with Payment Links.
 *
 * @package LG_Stripe_Membership
 */

defined( 'ABSPATH' ) || exit;

class LGSM_Checkout {

    /**
     * Boot: register REST routes and shortcode.
     */
    public static function init(): void {
        add_action( 'rest_api_init', [ self::class, 'register_routes' ] );
        // Shortcode and return URL handler will be added in Phase 2.
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

    /**
     * Create a Stripe Checkout Session (Embedded mode).
     *
     * Expects JSON body: { price_id: string, email?: string }
     * Returns: { client_secret: string } or { error: string }
     *
     * Phase 2 implementation.
     */
    public static function create_session( \WP_REST_Request $request ): \WP_REST_Response {
        // TODO Phase 2: Full implementation with CF-IPCountry routing,
        // double payment block, and Embedded Checkout session creation.
        return new \WP_REST_Response( [ 'error' => 'Checkout not yet implemented. Use Stripe Payment Links for testing.' ], 501 );
    }

    /**
     * Create a Stripe Customer Portal session.
     *
     * Logged-in users only. Redirects to Stripe-hosted billing portal.
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
}
