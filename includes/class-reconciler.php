<?php
/**
 * Reconciler
 *
 * Monthly WP Cron job. Queries all users with payment_source=stripe and
 * looth2/looth3 roles, verifies active Stripe subscription via API.
 * Downgrades orphans to looth1. Emails admin summary.
 * Skips looth4 and non-Stripe users.
 *
 * @package LG_Stripe_Membership
 */

defined( 'ABSPATH' ) || exit;

class LGSM_Reconciler {

    /** WP Cron hook name. */
    private const CRON_HOOK = 'lgsm_monthly_reconciliation';

    /**
     * Boot: register cron schedule and hook.
     */
    public static function init(): void {
        add_filter( 'cron_schedules', [ self::class, 'add_monthly_schedule' ] );
        add_action( self::CRON_HOOK, [ self::class, 'run' ] );

        // Schedule if not already scheduled
        if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
            wp_schedule_event( time(), 'monthly', self::CRON_HOOK );
        }
    }

    /**
     * Add a "monthly" interval to WP Cron (WP doesn't have one by default).
     */
    public static function add_monthly_schedule( array $schedules ): array {
        $schedules['monthly'] = [
            'interval' => 30 * DAY_IN_SECONDS,
            'display'  => 'Once Monthly',
        ];
        return $schedules;
    }

    /* ------------------------------------------------------------------
     * Main reconciliation
     * ----------------------------------------------------------------*/

    /**
     * Run the monthly reconciliation.
     *
     * Can also be triggered manually from admin for testing.
     */
    public static function run(): void {
        error_log( 'LGSM: Starting monthly reconciliation.' );

        $secret_key = LGSM_Admin_Settings::get_secret_key();
        if ( ! $secret_key ) {
            error_log( 'LGSM: Reconciliation aborted — no Stripe secret key configured.' );
            return;
        }

        $stripe = new \Stripe\StripeClient( $secret_key );

        // Find all Stripe-managed users with paid roles
        $users = self::get_stripe_paid_users();

        $results = [
            'checked'    => 0,
            'ok'         => 0,
            'downgraded' => [],
            'errors'     => [],
            'skipped'    => 0,
        ];

        foreach ( $users as $user ) {
            $results['checked']++;

            $customer_id = get_user_meta( $user->ID, 'stripe_customer_id', true );

            // No customer ID — can't verify, skip but flag
            if ( ! $customer_id ) {
                $results['errors'][] = "User {$user->user_email} (ID {$user->ID}) has payment_source=stripe but no stripe_customer_id.";
                continue;
            }

            // looth4 double-check (should never appear, but safety)
            if ( in_array( 'looth4', (array) $user->roles, true ) ) {
                $results['skipped']++;
                continue;
            }

            // Check Stripe for active subscription
            $has_active = self::customer_has_active_sub( $stripe, $customer_id );

            if ( $has_active === null ) {
                // API error — don't downgrade, just log
                $results['errors'][] = "API error checking customer {$customer_id} ({$user->user_email}).";
                continue;
            }

            if ( $has_active ) {
                $results['ok']++;
            } else {
                // No active subscription — downgrade
                $downgraded = LGSM_User_Manager::downgrade( $user->ID );
                if ( $downgraded ) {
                    $results['downgraded'][] = $user->user_email;
                    error_log( "LGSM: Reconciliation downgraded {$user->user_email} to looth1." );
                }
            }
        }

        error_log( sprintf(
            'LGSM: Reconciliation complete — checked: %d, ok: %d, downgraded: %d, errors: %d, skipped: %d',
            $results['checked'],
            $results['ok'],
            count( $results['downgraded'] ),
            count( $results['errors'] ),
            $results['skipped'],
        ) );

        // Email admin summary
        self::send_summary( $results );
    }

    /* ------------------------------------------------------------------
     * Helpers
     * ----------------------------------------------------------------*/

    /**
     * Get all WP users with payment_source=stripe and a paid role (looth2/looth3).
     *
     * @return \WP_User[]
     */
    private static function get_stripe_paid_users(): array {
        return get_users( [
            'meta_key'   => 'payment_source',
            'meta_value' => 'stripe',
            'role__in'   => [ 'looth2', 'looth3' ],
            'number'     => -1,
        ] );
    }

    /**
     * Check if a Stripe customer has at least one active (or trialing/past_due) subscription.
     *
     * @return bool|null True if active, false if not, null on API error.
     */
    private static function customer_has_active_sub( \Stripe\StripeClient $stripe, string $customer_id ): ?bool {
        try {
            $subs = $stripe->subscriptions->all( [
                'customer' => $customer_id,
                'status'   => 'all',
                'limit'    => 10,
            ] );
        } catch ( \Exception $e ) {
            error_log( 'LGSM: Reconciliation API error for ' . $customer_id . ' — ' . $e->getMessage() );
            return null;
        }

        foreach ( $subs->data as $sub ) {
            // Active, trialing, or past_due (still retrying) all count as "has subscription"
            if ( in_array( $sub->status, [ 'active', 'trialing', 'past_due' ], true ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Email the admin a reconciliation summary.
     */
    private static function send_summary( array $results ): void {
        $admin_email = get_option( 'admin_email' );
        if ( ! $admin_email ) {
            return;
        }

        $site_name = wp_specialchars_decode( get_option( 'blogname' ), ENT_QUOTES );
        $subject   = sprintf( '[%s] Stripe Membership Reconciliation Report', $site_name );

        $lines = [
            'Monthly Stripe Membership Reconciliation',
            '=========================================',
            '',
            sprintf( 'Checked:    %d', $results['checked'] ),
            sprintf( 'OK:         %d', $results['ok'] ),
            sprintf( 'Downgraded: %d', count( $results['downgraded'] ) ),
            sprintf( 'Errors:     %d', count( $results['errors'] ) ),
            sprintf( 'Skipped:    %d', $results['skipped'] ),
        ];

        if ( ! empty( $results['downgraded'] ) ) {
            $lines[] = '';
            $lines[] = 'Downgraded users:';
            foreach ( $results['downgraded'] as $email ) {
                $lines[] = '  - ' . $email;
            }
        }

        if ( ! empty( $results['errors'] ) ) {
            $lines[] = '';
            $lines[] = 'Errors:';
            foreach ( $results['errors'] as $err ) {
                $lines[] = '  - ' . $err;
            }
        }

        wp_mail( $admin_email, $subject, implode( "\n", $lines ) );
    }

    /* ------------------------------------------------------------------
     * Manual trigger (for admin/testing)
     * ----------------------------------------------------------------*/

    /**
     * Check if reconciliation is due or run it manually.
     * Can be wired to an admin button later.
     */
    public static function trigger_manual(): void {
        self::run();
    }
}
