<?php
/**
 * Admin Settings
 *
 * Settings page for Stripe API keys, mode toggle, webhook signing secret,
 * tier mapping (Price ID → WP Role), and developing economy country list.
 *
 * @package LG_Stripe_Membership
 */

defined( 'ABSPATH' ) || exit;

class LGSM_Admin_Settings {

    /** Option keys */
    private const OPT_STRIPE_MODE       = 'lgsm_stripe_mode';
    private const OPT_TEST_SECRET_KEY   = 'lgsm_test_secret_key';
    private const OPT_TEST_PUBLISH_KEY  = 'lgsm_test_publishable_key';
    private const OPT_LIVE_SECRET_KEY   = 'lgsm_live_secret_key';
    private const OPT_LIVE_PUBLISH_KEY  = 'lgsm_live_publishable_key';
    private const OPT_WEBHOOK_SECRET    = 'lgsm_webhook_secret';
    private const OPT_TIER_MAP          = 'lgsm_tier_map';
    private const OPT_DEV_COUNTRIES     = 'lgsm_developing_countries';

    /**
     * Boot: register hooks.
     */
    public static function init(): void {
        add_action( 'admin_menu', [ self::class, 'register_menu' ] );
        add_action( 'admin_init', [ self::class, 'register_settings' ] );
    }

    /* ------------------------------------------------------------------
     * Menu
     * ----------------------------------------------------------------*/

    public static function register_menu(): void {
        add_options_page(
            'Stripe Membership',
            'Stripe Membership',
            'manage_options',
            'lgsm-settings',
            [ self::class, 'render_page' ],
        );
    }

    /* ------------------------------------------------------------------
     * Register settings
     * ----------------------------------------------------------------*/

    public static function register_settings(): void {
        // --- API Keys section ---
        add_settings_section( 'lgsm_keys', 'Stripe API Keys', '__return_false', 'lgsm-settings' );

        register_setting( 'lgsm-settings', self::OPT_STRIPE_MODE, [
            'type'              => 'string',
            'sanitize_callback' => [ self::class, 'sanitize_mode' ],
            'default'           => 'test',
        ] );
        add_settings_field( 'lgsm_mode', 'Mode', [ self::class, 'render_mode_field' ], 'lgsm-settings', 'lgsm_keys' );

        foreach ( [
            self::OPT_TEST_SECRET_KEY  => 'Test Secret Key',
            self::OPT_TEST_PUBLISH_KEY => 'Test Publishable Key',
            self::OPT_LIVE_SECRET_KEY  => 'Live Secret Key',
            self::OPT_LIVE_PUBLISH_KEY => 'Live Publishable Key',
            self::OPT_WEBHOOK_SECRET   => 'Webhook Signing Secret',
        ] as $key => $label ) {
            register_setting( 'lgsm-settings', $key, [
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default'           => '',
            ] );
            add_settings_field( $key, $label, function () use ( $key ) {
                $val = get_option( $key, '' );
                printf(
                    '<input type="password" name="%s" value="%s" class="regular-text" autocomplete="off">',
                    esc_attr( $key ),
                    esc_attr( $val ),
                );
            }, 'lgsm-settings', 'lgsm_keys' );
        }

        // --- Tier mapping section ---
        add_settings_section( 'lgsm_tiers', 'Tier Mapping', function () {
            echo '<p>Map each Stripe Price ID to a WordPress role. Add one row per price (monthly, yearly, developing, etc.).</p>';
        }, 'lgsm-settings' );

        register_setting( 'lgsm-settings', self::OPT_TIER_MAP, [
            'type'              => 'array',
            'sanitize_callback' => [ self::class, 'sanitize_tier_map' ],
            'default'           => [],
        ] );
        add_settings_field( 'lgsm_tier_map', 'Price &rarr; Role', [ self::class, 'render_tier_map' ], 'lgsm-settings', 'lgsm_tiers' );

        // --- Developing countries ---
        add_settings_section( 'lgsm_geo', 'Developing Economy Pricing', function () {
            echo '<p>Comma-separated ISO 3166-1 alpha-2 country codes (e.g. <code>IN,PH,ID,VN,NG</code>). Visitors from these countries are routed to developing-economy Price IDs.</p>';
        }, 'lgsm-settings' );

        register_setting( 'lgsm-settings', self::OPT_DEV_COUNTRIES, [
            'type'              => 'string',
            'sanitize_callback' => [ self::class, 'sanitize_countries' ],
            'default'           => '',
        ] );
        add_settings_field( 'lgsm_countries', 'Country Codes', function () {
            $val = get_option( self::OPT_DEV_COUNTRIES, '' );
            printf(
                '<textarea name="%s" rows="3" class="large-text">%s</textarea>',
                esc_attr( self::OPT_DEV_COUNTRIES ),
                esc_textarea( $val ),
            );
        }, 'lgsm-settings', 'lgsm_geo' );
    }

    /* ------------------------------------------------------------------
     * Field renderers
     * ----------------------------------------------------------------*/

    public static function render_mode_field(): void {
        $mode = get_option( self::OPT_STRIPE_MODE, 'test' );
        ?>
        <label><input type="radio" name="<?php echo esc_attr( self::OPT_STRIPE_MODE ); ?>" value="test" <?php checked( $mode, 'test' ); ?>> Test</label>&nbsp;&nbsp;
        <label><input type="radio" name="<?php echo esc_attr( self::OPT_STRIPE_MODE ); ?>" value="live" <?php checked( $mode, 'live' ); ?>> Live</label>
        <p class="description">&#9888; Live mode charges real cards.</p>
        <?php
    }

    public static function render_tier_map(): void {
        $rows = get_option( self::OPT_TIER_MAP, [] );
        if ( ! is_array( $rows ) || empty( $rows ) ) {
            $rows = [ [ 'price_id' => '', 'role' => 'looth2', 'label' => '', 'interval' => 'yearly', 'price_display' => '', 'dev_price_id' => '' ] ];
        }
        $intervals = [ 'monthly' => 'Monthly', 'yearly' => 'Yearly' ];
        ?>
        <table class="widefat" id="lgsm-tier-table">
            <thead>
                <tr>
                    <th>Stripe Price ID</th>
                    <th>WP Role</th>
                    <th>Label</th>
                    <th>Interval</th>
                    <th>Display Price</th>
                    <th>Dev Economy Price ID</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ( $rows as $i => $row ) : ?>
                <tr>
                    <td><input type="text" name="<?php echo esc_attr( self::OPT_TIER_MAP ); ?>[<?php echo (int) $i; ?>][price_id]" value="<?php echo esc_attr( $row['price_id'] ?? '' ); ?>" class="regular-text" placeholder="price_xxx"></td>
                    <td>
                        <select name="<?php echo esc_attr( self::OPT_TIER_MAP ); ?>[<?php echo (int) $i; ?>][role]">
                            <option value="looth1" <?php selected( $row['role'] ?? '', 'looth1' ); ?>>looth1 (free)</option>
                            <option value="looth2" <?php selected( $row['role'] ?? '', 'looth2' ); ?>>looth2 (standard)</option>
                            <option value="looth3" <?php selected( $row['role'] ?? '', 'looth3' ); ?>>looth3 (premium)</option>
                        </select>
                    </td>
                    <td><input type="text" name="<?php echo esc_attr( self::OPT_TIER_MAP ); ?>[<?php echo (int) $i; ?>][label]" value="<?php echo esc_attr( $row['label'] ?? '' ); ?>" class="regular-text" placeholder="e.g. Looth Lite"></td>
                    <td>
                        <select name="<?php echo esc_attr( self::OPT_TIER_MAP ); ?>[<?php echo (int) $i; ?>][interval]">
                            <?php foreach ( $intervals as $val => $lbl ) : ?>
                                <option value="<?php echo esc_attr( $val ); ?>" <?php selected( $row['interval'] ?? 'yearly', $val ); ?>><?php echo esc_html( $lbl ); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td><input type="text" name="<?php echo esc_attr( self::OPT_TIER_MAP ); ?>[<?php echo (int) $i; ?>][price_display]" value="<?php echo esc_attr( $row['price_display'] ?? '' ); ?>" style="width:100px" placeholder="$10/mo"></td>
                    <td><input type="text" name="<?php echo esc_attr( self::OPT_TIER_MAP ); ?>[<?php echo (int) $i; ?>][dev_price_id]" value="<?php echo esc_attr( $row['dev_price_id'] ?? '' ); ?>" class="regular-text" placeholder="price_xxx (optional)"></td>
                    <td><button type="button" class="button lgsm-remove-row">&times;</button></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <p><button type="button" class="button" id="lgsm-add-row">+ Add Tier</button></p>
        <script>
        document.getElementById('lgsm-add-row').addEventListener('click', function(){
            var tbody = document.querySelector('#lgsm-tier-table tbody');
            var idx = tbody.rows.length;
            var tr = document.createElement('tr');
            tr.innerHTML = '<td><input type="text" name="lgsm_tier_map['+idx+'][price_id]" class="regular-text" placeholder="price_xxx"></td>'
                + '<td><select name="lgsm_tier_map['+idx+'][role]"><option value="looth1">looth1</option><option value="looth2" selected>looth2</option><option value="looth3">looth3</option></select></td>'
                + '<td><input type="text" name="lgsm_tier_map['+idx+'][label]" class="regular-text" placeholder="e.g. Looth Lite"></td>'
                + '<td><select name="lgsm_tier_map['+idx+'][interval]"><option value="monthly">Monthly</option><option value="yearly" selected>Yearly</option></select></td>'
                + '<td><input type="text" name="lgsm_tier_map['+idx+'][price_display]" style="width:100px" placeholder="$10/mo"></td>'
                + '<td><input type="text" name="lgsm_tier_map['+idx+'][dev_price_id]" class="regular-text" placeholder="price_xxx (optional)"></td>'
                + '<td><button type="button" class="button lgsm-remove-row">&times;</button></td>';
            tbody.appendChild(tr);
        });
        document.addEventListener('click', function(e){
            if(e.target.classList.contains('lgsm-remove-row')){
                e.target.closest('tr').remove();
            }
        });
        </script>
        <?php
    }

    /* ------------------------------------------------------------------
     * Sanitizers
     * ----------------------------------------------------------------*/

    public static function sanitize_mode( string $val ): string {
        return in_array( $val, [ 'test', 'live' ], true ) ? $val : 'test';
    }

    public static function sanitize_tier_map( mixed $input ): array {
        if ( ! is_array( $input ) ) {
            return [];
        }
        $clean = [];
        foreach ( $input as $row ) {
            $price_id      = sanitize_text_field( $row['price_id'] ?? '' );
            $role          = sanitize_text_field( $row['role'] ?? '' );
            $label         = sanitize_text_field( $row['label'] ?? '' );
            $interval      = sanitize_text_field( $row['interval'] ?? 'yearly' );
            $price_display = sanitize_text_field( $row['price_display'] ?? '' );
            $dev_price_id  = sanitize_text_field( $row['dev_price_id'] ?? '' );

            if ( $price_id !== '' && in_array( $role, [ 'looth1', 'looth2', 'looth3' ], true ) ) {
                if ( ! in_array( $interval, [ 'monthly', 'yearly' ], true ) ) {
                    $interval = 'yearly';
                }
                $clean[] = compact( 'price_id', 'role', 'label', 'interval', 'price_display', 'dev_price_id' );
            }
        }
        return $clean;
    }

    public static function sanitize_countries( string $val ): string {
        $codes = array_map( 'trim', explode( ',', strtoupper( $val ) ) );
        $codes = array_filter( $codes, fn( $c ) => preg_match( '/^[A-Z]{2}$/', $c ) );
        return implode( ',', $codes );
    }

    /* ------------------------------------------------------------------
     * Page renderer
     * ----------------------------------------------------------------*/

    public static function render_page(): void {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }
        ?>
        <div class="wrap">
            <h1>Stripe Membership Settings</h1>
            <form method="post" action="options.php">
                <?php
                settings_fields( 'lgsm-settings' );
                do_settings_sections( 'lgsm-settings' );
                submit_button();
                ?>
            </form>
        </div>
        <?php
    }

    /* ------------------------------------------------------------------
     * Public helpers (used by other classes)
     * ----------------------------------------------------------------*/

    /**
     * Get the active Stripe secret key based on current mode.
     */
    public static function get_secret_key(): string {
        $mode = get_option( self::OPT_STRIPE_MODE, 'test' );
        return $mode === 'live'
            ? get_option( self::OPT_LIVE_SECRET_KEY, '' )
            : get_option( self::OPT_TEST_SECRET_KEY, '' );
    }

    /**
     * Get the active Stripe publishable key based on current mode.
     */
    public static function get_publishable_key(): string {
        $mode = get_option( self::OPT_STRIPE_MODE, 'test' );
        return $mode === 'live'
            ? get_option( self::OPT_LIVE_PUBLISH_KEY, '' )
            : get_option( self::OPT_TEST_PUBLISH_KEY, '' );
    }

    /**
     * Get the webhook signing secret.
     */
    public static function get_webhook_secret(): string {
        return get_option( self::OPT_WEBHOOK_SECRET, '' );
    }

    /**
     * Get the current Stripe mode.
     */
    public static function get_mode(): string {
        return get_option( self::OPT_STRIPE_MODE, 'test' );
    }

    /**
     * Look up a WP role by Stripe Price ID.
     *
     * @return string|null Role slug or null if not mapped.
     */
    public static function get_role_for_price( string $price_id ): ?string {
        $map = get_option( self::OPT_TIER_MAP, [] );
        foreach ( $map as $row ) {
            if ( ( $row['price_id'] ?? '' ) === $price_id ) {
                return $row['role'] ?? null;
            }
            // Also check developing economy price ID
            if ( ( $row['dev_price_id'] ?? '' ) !== '' && ( $row['dev_price_id'] ?? '' ) === $price_id ) {
                return $row['role'] ?? null;
            }
        }
        return null;
    }

    /**
     * Get all tier mappings.
     *
     * @return array Array of { price_id, role, label, interval, price_display, dev_price_id }.
     */
    public static function get_tier_map(): array {
        return get_option( self::OPT_TIER_MAP, [] );
    }

    /**
     * Get the developing economy Price ID for a given standard Price ID.
     *
     * @return string|null Dev price ID or null if none configured.
     */
    public static function get_dev_price_id( string $price_id ): ?string {
        $map = get_option( self::OPT_TIER_MAP, [] );
        foreach ( $map as $row ) {
            if ( ( $row['price_id'] ?? '' ) === $price_id ) {
                $dev = $row['dev_price_id'] ?? '';
                return $dev !== '' ? $dev : null;
            }
        }
        return null;
    }

    /**
     * Get tier data grouped by role for the frontend join page.
     *
     * Returns safe data only — no secrets, no dev price IDs.
     *
     * @return array [ { role, label, prices: [ { price_id, interval, display } ] } ]
     */
    public static function get_tiers_for_frontend(): array {
        $map    = get_option( self::OPT_TIER_MAP, [] );
        $groups = [];

        foreach ( $map as $row ) {
            $role  = $row['role'] ?? '';
            $label = $row['label'] ?? '';
            if ( $role === '' || $role === 'looth1' ) {
                continue; // Skip free tier — not selectable on join page
            }

            if ( ! isset( $groups[ $role ] ) ) {
                $groups[ $role ] = [
                    'role'   => $role,
                    'label'  => $label,
                    'prices' => [],
                ];
            }

            $groups[ $role ]['prices'][] = [
                'price_id' => $row['price_id'] ?? '',
                'interval' => $row['interval'] ?? 'yearly',
                'display'  => $row['price_display'] ?? '',
            ];

            // Use the first non-empty label for the group
            if ( $groups[ $role ]['label'] === '' && $label !== '' ) {
                $groups[ $role ]['label'] = $label;
            }
        }

        return array_values( $groups );
    }

    /**
     * Get developing economy country codes.
     *
     * @return string[] Array of ISO 3166-1 alpha-2 codes.
     */
    public static function get_developing_countries(): array {
        $raw = get_option( self::OPT_DEV_COUNTRIES, '' );
        if ( $raw === '' ) {
            return [];
        }
        return array_map( 'trim', explode( ',', $raw ) );
    }

    /**
     * Check if a country code qualifies for developing economy pricing.
     */
    public static function is_developing_country( string $country_code ): bool {
        return in_array( strtoupper( $country_code ), self::get_developing_countries(), true );
    }
}
