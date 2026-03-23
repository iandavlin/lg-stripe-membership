<?php
/**
 * Webhook Handler
 *
 * REST endpoint at /wp-json/lg-membership/v1/stripe-webhook.
 * Verifies Stripe signatures, routes events, enforces idempotency.
 *
 * Events handled:
 *   checkout.session.completed
 *   invoice.paid
 *   customer.subscription.updated
 *   customer.subscription.deleted
 *   invoice.payment_failed
 *
 * @package LG_Stripe_Membership
 */

defined( 'ABSPATH' ) || exit;

class LGSM_Webhook_Handler {

    /** Transient prefix for idempotency. */
    private const EVENT_PREFIX = 'lgsm_event_';

    /** How long to remember processed events (seconds). */
    private const EVENT_TTL = DAY_IN_SECONDS;

    /**
     * Boot: register REST route.
     */
    public static function init(): void {
        add_action( 'rest_api_init', [ self::class, 'register_route' ] );
    }

    /**
     * Register the webhook endpoint.
     */
    public static function register_route(): void {
        register_rest_route( 'lg-membership/v1', '/stripe-webhook', [
            'methods'             => 'POST',
            'callback'            => [ self::class, 'handle' ],
            'permission_callback' => '__return_true', // Stripe signs the payload — no WP auth.
        ] );
    }

    /* ------------------------------------------------------------------
     * Main handler
     * ----------------------------------------------------------------*/

    /**
     * Process an incoming Stripe webhook event.
     */
    public static function handle( \WP_REST_Request $request ): \WP_REST_Response {
        // 1. Get raw body and signature header
        $payload   = $request->get_body();
        $sig       = $request->get_header( 'stripe-signature' );
        $secret    = LGSM_Admin_Settings::get_webhook_secret();

        if ( ! $sig || ! $secret ) {
            error_log( 'LGSM: Webhook missing signature or secret.' );
            return new \WP_REST_Response( 'Missing signature', 400 );
        }

        // 2. Verify signature
        try {
            $event = \Stripe\Webhook::constructEvent( $payload, $sig, $secret );
        } catch ( \Stripe\Exception\SignatureVerificationException $e ) {
            error_log( 'LGSM: Webhook signature verification failed — ' . $e->getMessage() );
            return new \WP_REST_Response( 'Invalid signature', 400 );
        } catch ( \Exception $e ) {
            error_log( 'LGSM: Webhook parse error — ' . $e->getMessage() );
            return new \WP_REST_Response( 'Parse error', 400 );
        }

        // 3. Idempotency check
        $event_id = $event->id;
        if ( get_transient( self::EVENT_PREFIX . $event_id ) ) {
            return new \WP_REST_Response( 'Already processed', 200 );
        }
        set_transient( self::EVENT_PREFIX . $event_id, true, self::EVENT_TTL );

        // 4. Route to handler
        error_log( "LGSM: Webhook received — {$event->type} ({$event_id})" );

        $result = match ( $event->type ) {
            'checkout.session.completed'      => self::on_checkout_completed( $event->data->object ),
            'invoice.paid'                    => self::on_invoice_paid( $event->data->object ),
            'customer.subscription.updated'   => self::on_subscription_updated( $event->data->object ),
            'customer.subscription.deleted'   => self::on_subscription_deleted( $event->data->object ),
            'invoice.payment_failed'          => self::on_payment_failed( $event->data->object ),
            default                           => 'Unhandled event type: ' . $event->type,
        };

        error_log( "LGSM: Webhook result — {$result}" );

        return new \WP_REST_Response( $result, 200 );
    }

    /* ------------------------------------------------------------------
     * Event handlers
     * ----------------------------------------------------------------*/

    /**
     * checkout.session.completed
     *
     * Backup path — the return URL handler is primary for user creation.
     * If the user already exists, just verify. If not, create them.
     */
    private static function on_checkout_completed( object $session ): string {
        // Only handle subscription checkouts
        if ( ( $session->mode ?? '' ) !== 'subscription' ) {
            return 'Skipped — not a subscription checkout.';
        }

        $customer_id = $session->customer ?? '';
        $sub_id      = $session->subscription ?? '';
        $email       = $session->customer_details->email ?? $session->customer_email ?? '';

        if ( ! $customer_id || ! $email ) {
            return 'Missing customer ID or email.';
        }

        // Resolve the role from the subscription's price
        $role = self::resolve_role_from_subscription( $sub_id );
        if ( ! $role ) {
            error_log( "LGSM: checkout.session.completed — could not resolve role for sub {$sub_id}" );
            return "Could not resolve role for subscription {$sub_id}.";
        }

        $name = trim( ( $session->customer_details->name ?? '' ) );

        $result = LGSM_User_Manager::find_or_create( $customer_id, $sub_id, $email, $name, $role );

        return $result['message'];
    }

    /**
     * invoice.paid
     *
     * Fires on every successful payment (initial + renewals).
     * Always re-verify/set the correct role — don't assume current state.
     */
    private static function on_invoice_paid( object $invoice ): string {
        $customer_id = $invoice->customer ?? '';
        $sub_id      = $invoice->subscription ?? '';

        if ( ! $customer_id || ! $sub_id ) {
            return 'Missing customer or subscription ID.';
        }

        $user_id = LGSM_User_Manager::find_by_customer_id( $customer_id );
        if ( ! $user_id ) {
            return "No WP user for customer {$customer_id} — may be handled by checkout handler.";
        }

        $role = self::resolve_role_from_subscription( $sub_id );
        if ( ! $role ) {
            return "Could not resolve role for subscription {$sub_id}.";
        }

        // Update subscription ID (may be new if they re-subscribed)
        update_user_meta( $user_id, 'stripe_subscription_id', sanitize_text_field( $sub_id ) );

        $changed = LGSM_User_Manager::set_role( $user_id, $role );

        return $changed
            ? "invoice.paid — set user {$user_id} → {$role}."
            : "invoice.paid — user {$user_id} role unchanged (protected or same).";
    }

    /**
     * customer.subscription.updated
     *
     * Handles plan changes, pause/resume, cancel_at_period_end.
     */
    private static function on_subscription_updated( object $subscription ): string {
        $customer_id = $subscription->customer ?? '';
        $sub_id      = $subscription->id ?? '';

        if ( ! $customer_id ) {
            return 'Missing customer ID.';
        }

        $user_id = LGSM_User_Manager::find_by_customer_id( $customer_id );
        if ( ! $user_id ) {
            return "No WP user for customer {$customer_id}.";
        }

        $status = $subscription->status ?? '';

        // Canceling at period end — user keeps access until period ends
        if ( ! empty( $subscription->cancel_at_period_end ) ) {
            error_log( "LGSM: User {$user_id} subscription {$sub_id} set to cancel at period end." );
            self::fcrm_tag_user( $user_id, 'Stripe Canceling' );
            return "Subscription {$sub_id} will cancel at period end.";
        }

        // Paused
        if ( ! empty( $subscription->pause_collection ) ) {
            error_log( "LGSM: User {$user_id} subscription {$sub_id} paused." );
            // Keep access during pause (configurable in future)
            return "Subscription {$sub_id} paused — keeping access.";
        }

        // Active — may be a plan change, re-verify role
        if ( $status === 'active' ) {
            $role = self::resolve_role_from_subscription( $sub_id );
            if ( $role ) {
                update_user_meta( $user_id, 'stripe_subscription_id', sanitize_text_field( $sub_id ) );
                LGSM_User_Manager::set_role( $user_id, $role );
                return "subscription.updated — set user {$user_id} → {$role}.";
            }
        }

        return "subscription.updated — status={$status}, no role change.";
    }

    /**
     * customer.subscription.deleted
     *
     * Subscription fully ended — downgrade to looth1.
     */
    private static function on_subscription_deleted( object $subscription ): string {
        $customer_id = $subscription->customer ?? '';

        if ( ! $customer_id ) {
            return 'Missing customer ID.';
        }

        $user_id = LGSM_User_Manager::find_by_customer_id( $customer_id );
        if ( ! $user_id ) {
            return "No WP user for customer {$customer_id}.";
        }

        $downgraded = LGSM_User_Manager::downgrade( $user_id );

        return $downgraded
            ? "subscription.deleted — downgraded user {$user_id} to looth1."
            : "subscription.deleted — user {$user_id} skipped (protected).";
    }

    /**
     * invoice.payment_failed
     *
     * Stripe handles retries + dunning. We just log it.
     */
    private static function on_payment_failed( object $invoice ): string {
        $customer_id = $invoice->customer ?? '';
        $user_id     = $customer_id ? LGSM_User_Manager::find_by_customer_id( $customer_id ) : 0;

        error_log( "LGSM: Payment failed for customer {$customer_id} (user {$user_id})." );

        if ( $user_id ) {
            self::fcrm_tag_user( $user_id, 'Stripe Payment Failed' );
        }

        return "invoice.payment_failed — logged for customer {$customer_id}.";
    }

    /* ------------------------------------------------------------------
     * Helpers
     * ----------------------------------------------------------------*/

    /**
     * Retrieve a Stripe Subscription and resolve its Price ID to a WP role.
     *
     * @return string|null Role slug or null on failure.
     */
    private static function resolve_role_from_subscription( string $sub_id ): ?string {
        if ( ! $sub_id ) {
            return null;
        }

        try {
            $stripe = new \Stripe\StripeClient( LGSM_Admin_Settings::get_secret_key() );
            $sub    = $stripe->subscriptions->retrieve( $sub_id, [ 'expand' => [ 'items.data.price' ] ] );
        } catch ( \Exception $e ) {
            error_log( 'LGSM: Failed to retrieve subscription ' . $sub_id . ' — ' . $e->getMessage() );
            return null;
        }

        // Use the first line item's price ID
        $items = $sub->items->data ?? [];
        if ( empty( $items ) ) {
            return null;
        }

        $price_id = $items[0]->price->id ?? '';
        if ( ! $price_id ) {
            return null;
        }

        return LGSM_Admin_Settings::get_role_for_price( $price_id );
    }

    /**
     * Tag a user in FluentCRM. Creates the tag if it doesn't exist.
     *
     * @param int    $user_id  WordPress user ID.
     * @param string $tag_name Tag title (e.g. "Stripe Canceling").
     */
    private static function fcrm_tag_user( int $user_id, string $tag_name ): void {
        if ( ! class_exists( '\FluentCrm\App\Models\Tag' ) ||
             ! class_exists( '\FluentCrm\App\Models\Subscriber' ) ) {
            error_log( "LGSM: FluentCRM not available — cannot tag user {$user_id} with \"{$tag_name}\"." );
            return;
        }

        // Find or create the tag
        $tag = \FluentCrm\App\Models\Tag::where( 'title', $tag_name )->first();
        if ( ! $tag ) {
            $tag = \FluentCrm\App\Models\Tag::create( [
                'title' => $tag_name,
                'slug'  => sanitize_title( $tag_name ),
            ] );
        }

        if ( ! $tag || ! $tag->id ) {
            error_log( "LGSM: Failed to find/create FluentCRM tag \"{$tag_name}\"." );
            return;
        }

        // Find the subscriber by user ID
        $subscriber = \FluentCrm\App\Models\Subscriber::where( 'user_id', $user_id )->first();
        if ( ! $subscriber ) {
            // Try by email
            $user = get_userdata( $user_id );
            if ( $user ) {
                $subscriber = \FluentCrm\App\Models\Subscriber::where( 'email', $user->user_email )->first();
            }
        }

        if ( ! $subscriber ) {
            error_log( "LGSM: No FluentCRM subscriber for user {$user_id} — cannot apply tag \"{$tag_name}\"." );
            return;
        }

        $subscriber->attachTags( [ $tag->id ] );
        error_log( "LGSM: Tagged user {$user_id} with \"{$tag_name}\" (tag #{$tag->id})." );
    }
}
