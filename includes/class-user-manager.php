<?php
/**
 * User Manager
 *
 * Find/create/toggle WordPress users based on Stripe payment events.
 * Lookup order: stripe_customer_id meta → email.
 * Handles: new user creation, lapsed user reactivation, role assignment,
 * auth cookie, welcome email, payment_source tagging.
 * looth4 protection in every role-changing path.
 *
 * @package LG_Stripe_Membership
 */

defined( 'ABSPATH' ) || exit;

class LGSM_User_Manager {

    /**
     * Boot: nothing to hook for now — all methods are called by other classes.
     */
    public static function init(): void {
        // Reserved for future hooks (e.g. profile fields, account page).
    }

    /* ------------------------------------------------------------------
     * Core lookup / creation
     * ----------------------------------------------------------------*/

    /**
     * Find or create a WP user for a completed Stripe checkout.
     *
     * Returns [ 'user_id' => int, 'action' => 'created'|'reactivated'|'verified'|'skipped', 'message' => string ]
     *
     * @param string $customer_id   Stripe customer ID (cus_xxx).
     * @param string $sub_id        Stripe subscription ID (sub_xxx).
     * @param string $email         Customer email from Stripe.
     * @param string $name          Customer name from Stripe (may be empty).
     * @param string $role          WP role to assign (from tier mapping).
     */
    public static function find_or_create(
        string $customer_id,
        string $sub_id,
        string $email,
        string $name,
        string $role,
    ): array {

        // 1. Lookup by stripe_customer_id meta
        $users = get_users( [
            'meta_key'   => 'stripe_customer_id',
            'meta_value' => $customer_id,
            'number'     => 1,
            'fields'     => 'ids',
        ] );

        if ( ! empty( $users ) ) {
            $user_id = (int) $users[0];
            return self::handle_existing_user( $user_id, $customer_id, $sub_id, $role );
        }

        // 2. Lookup by email
        $user = get_user_by( 'email', $email );
        if ( $user ) {
            return self::handle_existing_user( $user->ID, $customer_id, $sub_id, $role );
        }

        // 3. Create new user
        return self::create_user( $customer_id, $sub_id, $email, $name, $role );
    }

    /**
     * Handle an existing WP user found by customer ID or email.
     */
    private static function handle_existing_user(
        int $user_id,
        string $customer_id,
        string $sub_id,
        string $role,
    ): array {

        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return [
                'user_id' => 0,
                'action'  => 'error',
                'message' => "User ID {$user_id} not found after lookup.",
            ];
        }

        // looth4 — hands off
        if ( self::is_protected( $user ) ) {
            return [
                'user_id' => $user_id,
                'action'  => 'skipped',
                'message' => "User {$user->user_email} has looth4 — skipped.",
            ];
        }

        $current_roles = (array) $user->roles;

        // Active paid with payment_source=patreon — should have been blocked pre-checkout
        $payment_source = get_user_meta( $user_id, 'payment_source', true );
        if (
            $payment_source === 'patreon'
            && ( in_array( 'looth2', $current_roles, true ) || in_array( 'looth3', $current_roles, true ) )
        ) {
            error_log( "LGSM: WARNING — active Patreon member {$user->user_email} reached find_or_create. Should have been blocked pre-checkout." );
            return [
                'user_id' => $user_id,
                'action'  => 'skipped',
                'message' => "User {$user->user_email} has active Patreon membership — skipped.",
            ];
        }

        // Toggle on: looth1 (lapsed) or already stripe-managed
        self::store_meta( $user_id, $customer_id, $sub_id );
        $user->set_role( $role );

        $action = in_array( 'looth1', $current_roles, true ) ? 'reactivated' : 'verified';

        error_log( "LGSM: {$action} user {$user->user_email} → {$role}" );

        return [
            'user_id' => $user_id,
            'action'  => $action,
            'message' => ucfirst( $action ) . " {$user->user_email} → {$role}.",
        ];
    }

    /**
     * Create a brand-new WP user.
     */
    private static function create_user(
        string $customer_id,
        string $sub_id,
        string $email,
        string $name,
        string $role,
    ): array {

        $username = self::generate_username( $email, $name );
        $password = wp_generate_password( 20, true, true );

        $user_id = wp_insert_user( [
            'user_login'   => $username,
            'user_email'   => $email,
            'user_pass'    => $password,
            'display_name' => $name ?: $email,
            'role'         => $role,
        ] );

        if ( is_wp_error( $user_id ) ) {
            error_log( 'LGSM: Failed to create user ' . $email . ' — ' . $user_id->get_error_message() );
            return [
                'user_id' => 0,
                'action'  => 'error',
                'message' => 'User creation failed: ' . $user_id->get_error_message(),
            ];
        }

        self::store_meta( $user_id, $customer_id, $sub_id );

        // Queue welcome email with password reset link
        self::send_welcome_email( $user_id );

        error_log( "LGSM: Created user {$email} (ID {$user_id}) → {$role}" );

        return [
            'user_id' => $user_id,
            'action'  => 'created',
            'message' => "Created {$email} → {$role}.",
        ];
    }

    /* ------------------------------------------------------------------
     * Role management
     * ----------------------------------------------------------------*/

    /**
     * Set a user's role, respecting looth4 protection.
     *
     * @return bool True if role was changed, false if skipped.
     */
    public static function set_role( int $user_id, string $role ): bool {
        $user = get_userdata( $user_id );
        if ( ! $user || self::is_protected( $user ) ) {
            return false;
        }

        $user->set_role( $role );
        error_log( "LGSM: Set role for {$user->user_email} → {$role}" );
        return true;
    }

    /**
     * Downgrade a user to looth1 (cancelled/lapsed).
     */
    public static function downgrade( int $user_id ): bool {
        return self::set_role( $user_id, 'looth1' );
    }

    /**
     * Check if a user has looth4 (protected/admin bypass).
     */
    public static function is_protected( \WP_User $user ): bool {
        return in_array( 'looth4', (array) $user->roles, true );
    }

    /* ------------------------------------------------------------------
     * Auth cookie (for return URL handler)
     * ----------------------------------------------------------------*/

    /**
     * Log the user in by setting the auth cookie.
     */
    public static function login_user( int $user_id ): void {
        if ( ! headers_sent() ) {
            wp_set_current_user( $user_id );
            wp_set_auth_cookie( $user_id, true );
        }
    }

    /* ------------------------------------------------------------------
     * Meta helpers
     * ----------------------------------------------------------------*/

    /**
     * Store all Stripe-related user meta.
     */
    private static function store_meta( int $user_id, string $customer_id, string $sub_id ): void {
        update_user_meta( $user_id, 'stripe_customer_id', sanitize_text_field( $customer_id ) );
        update_user_meta( $user_id, 'stripe_subscription_id', sanitize_text_field( $sub_id ) );
        update_user_meta( $user_id, 'payment_source', 'stripe' );
    }

    /**
     * Get a user's Stripe customer ID.
     */
    public static function get_customer_id( int $user_id ): string {
        return get_user_meta( $user_id, 'stripe_customer_id', true ) ?: '';
    }

    /**
     * Get a user's Stripe subscription ID.
     */
    public static function get_subscription_id( int $user_id ): string {
        return get_user_meta( $user_id, 'stripe_subscription_id', true ) ?: '';
    }

    /**
     * Get a user's payment source.
     */
    public static function get_payment_source( int $user_id ): string {
        return get_user_meta( $user_id, 'payment_source', true ) ?: '';
    }

    /**
     * Find a WP user ID by Stripe customer ID.
     *
     * @return int User ID or 0 if not found.
     */
    public static function find_by_customer_id( string $customer_id ): int {
        $users = get_users( [
            'meta_key'   => 'stripe_customer_id',
            'meta_value' => $customer_id,
            'number'     => 1,
            'fields'     => 'ids',
        ] );
        return ! empty( $users ) ? (int) $users[0] : 0;
    }

    /* ------------------------------------------------------------------
     * Username generation
     * ----------------------------------------------------------------*/

    /**
     * Generate a unique username from name or email.
     */
    private static function generate_username( string $email, string $name ): string {
        // Try name-based username first
        if ( $name !== '' ) {
            $base = sanitize_user( strtolower( str_replace( ' ', '.', $name ) ), true );
            if ( $base !== '' && ! username_exists( $base ) ) {
                return $base;
            }
        }

        // Fall back to email prefix
        $base = sanitize_user( strtolower( explode( '@', $email )[0] ), true );
        if ( $base === '' ) {
            $base = 'member';
        }

        if ( ! username_exists( $base ) ) {
            return $base;
        }

        // Append number to avoid collision
        $i = 2;
        while ( username_exists( $base . $i ) ) {
            $i++;
        }
        return $base . $i;
    }

    /* ------------------------------------------------------------------
     * Welcome email
     * ----------------------------------------------------------------*/

    /**
     * Send a welcome email with a password reset link.
     */
    private static function send_welcome_email( int $user_id ): void {
        $user = get_userdata( $user_id );
        if ( ! $user ) {
            return;
        }

        $reset_key  = get_password_reset_key( $user );
        if ( is_wp_error( $reset_key ) ) {
            error_log( 'LGSM: Failed to generate password reset key for ' . $user->user_email );
            return;
        }

        $reset_url = network_site_url( "wp-login.php?action=rp&key={$reset_key}&login=" . rawurlencode( $user->user_login ), 'login' );
        $site_name = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );

        $subject = sprintf( 'Welcome to %s!', $site_name );
        $message = sprintf(
            "Welcome to %s!\n\n"
            . "Your account has been created. To set your password and get started, visit:\n\n"
            . "%s\n\n"
            . "If you didn't request this, you can safely ignore this email.\n\n"
            . "— The %s Team",
            $site_name,
            $reset_url,
            $site_name,
        );

        wp_mail( $user->user_email, $subject, $message );
    }

    /* ------------------------------------------------------------------
     * Pre-checkout validation
     * ----------------------------------------------------------------*/

    /**
     * Check if an email belongs to an active Patreon member.
     * Used by Checkout class to block double billing.
     *
     * @return bool True if the user has an active Patreon membership and should be blocked.
     */
    public static function is_active_patreon_member( string $email ): bool {
        $user = get_user_by( 'email', $email );
        if ( ! $user ) {
            return false;
        }

        $payment_source = get_user_meta( $user->ID, 'payment_source', true );
        if ( $payment_source !== 'patreon' ) {
            return false;
        }

        $roles = (array) $user->roles;
        return in_array( 'looth2', $roles, true ) || in_array( 'looth3', $roles, true );
    }
}
