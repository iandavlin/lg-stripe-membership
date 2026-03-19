# lg-stripe-membership — Setup Guide & Claude Code Prompt

---

## Edge Cases & Failure Modes

Priority ranked. Updated to reflect simplified user model (toggle, don't duplicate).

### Critical — Must handle in v1

**1. Webhook replay / duplicate events**
Stripe can send the same event more than once. Store processed event IDs and skip duplicates.
```php
$event_id = $event->id; // evt_xxxxx
if ( get_transient( 'lgsm_event_' . $event_id ) ) {
    return new WP_REST_Response( 'Already processed', 200 );
}
set_transient( 'lgsm_event_' . $event_id, true, DAY_IN_SECONDS );
```

**2. Webhook arrives before return URL (race condition)**
Stripe fires the webhook almost instantly. The return URL handler and webhook could both try to act simultaneously. **Rule: return URL handler owns user creation and auth cookie. Webhook is the backup verifier.** If user already exists when webhook fires, it just confirms the role.

**3. Returning member re-subscribes**
Same `cus_` ID, new `sub_` ID. Lookup by `stripe_customer_id` meta finds the existing user. Update their role and subscription ID. Don't create a new account. This is just the normal "toggle back on" flow.

**4. Double billing — Stripe + Patreon same email**
**Blocked at checkout time.** Before creating the Checkout Session, check: does a WP user with this email have `payment_source = patreon` and an active paid role? If yes, refuse to create the session. No collision possible.

**5. looth4 protection**
Admin/bypass users must NEVER have their role changed. Every function that calls `set_role()` checks first:
```php
if ( in_array( 'looth4', (array) $user->roles ) ) {
    return; // Hands off
}
```

**6. $0 invoice from promo code**
A 100% off coupon means $0 first charge. Still create/activate the account — the subscription is valid.

### Important — Should handle in v1

**7. Card update during past_due**
Member's card fails → `past_due` → they update card → retry succeeds → `invoice.paid` fires. The handler must ALWAYS re-verify the role. Don't assume current state is correct.

**8. Subscription pause**
Member pauses via Customer Portal. Stripe fires `customer.subscription.updated` with `pause_collection` set. Decide: do they keep access during pause? If not, webhook handler should set a temporary restricted state. If yes, do nothing until the pause ends or subscription is canceled.

### Low Risk — Accept or defer

**9. Developing economy user moves countries**
Subscription stays at original price. Not worth solving.

**10. Stripe API rate limits on cron**
Only matters at thousands of Stripe members. Batch if needed.

**11. Multiple subscriptions on one customer**
Stripe allows multiple active subs. Webhook handler should use the highest tier or most recent. Define the rule and log it.

---

## Git Setup Guide

### 1. Create the GitHub repo

Go to https://github.com/new

- **Repository name:** `lg-stripe-membership`
- **Description:** Stripe membership billing plugin for The Looth Group
- **Public**
- **Add .gitignore:** select "WordPress" template
- **Add license:** GPL-2.0 (matches WordPress ecosystem)
- **Don't** initialize with a README (we already have one)

### 2. Set up SSH keys on your server (if not already done)

```bash
# On your EC2 instance
ssh-keygen -t ed25519 -C "ian@loothgroup.com"
# Press enter for default location, set passphrase or leave empty

cat ~/.ssh/id_ed25519.pub
```

Add the public key at https://github.com/settings/keys → "New SSH key"

Test:
```bash
ssh -T git@github.com
# Should say: "Hi [username]! You've successfully authenticated..."
```

### 3. Initialize the repo locally

```bash
cd /var/www/html/wp-content/plugins
mkdir lg-stripe-membership
cd lg-stripe-membership

git init
git branch -M main

# Copy in the README.md

# Create directory structure
mkdir -p includes templates assets/css assets/js

# Create placeholder main plugin file
cat > lg-stripe-membership.php << 'EOF'
<?php
/**
 * Plugin Name: LG Stripe Membership
 * Description: Stripe-powered membership billing for The Looth Group.
 * Version: 0.1.0
 * Author: Ian Davlin
 * Text Domain: lg-stripe-membership
 * Requires PHP: 8.3
 */

defined( 'ABSPATH' ) || exit;

define( 'LGSM_VERSION', '0.1.0' );
define( 'LGSM_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'LGSM_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Autoload classes
spl_autoload_register( function( $class ) {
    $prefix = 'LGSM_';
    if ( strpos( $class, $prefix ) !== 0 ) {
        return;
    }
    $relative = strtolower( str_replace( '_', '-', substr( $class, strlen( $prefix ) ) ) );
    $file = LGSM_PLUGIN_DIR . 'includes/class-' . $relative . '.php';
    if ( file_exists( $file ) ) {
        require_once $file;
    }
});
EOF

# Create .gitignore
cat > .gitignore << 'EOF'
/vendor/
composer.lock
.vscode/
.idea/
*.swp
*.swo
.DS_Store
Thumbs.db
.env
*.log
node_modules/
EOF

# Create composer.json
cat > composer.json << 'EOF'
{
    "name": "loothgroup/lg-stripe-membership",
    "description": "Stripe membership billing for The Looth Group",
    "type": "wordpress-plugin",
    "require": {
        "php": ">=8.3",
        "stripe/stripe-php": "^16.0"
    },
    "autoload": {
        "classmap": ["includes/"]
    }
}
EOF

git add -A
git commit -m "Initial scaffold: plugin structure, README, composer config"

git remote add origin git@github.com:YOUR_GITHUB_USERNAME/lg-stripe-membership.git
git push -u origin main
```

### 4. Install Stripe SDK

```bash
cd /var/www/html/wp-content/plugins/lg-stripe-membership
composer install
```

### 5. Install Stripe CLI (for webhook testing)

```bash
# On your EC2 instance
curl -s https://packages.stripe.dev/api/security/keypair/stripe-cli-gpg/public | gpg --dearmor | sudo tee /usr/share/keyrings/stripe.gpg
echo "deb [signed-by=/usr/share/keyrings/stripe.gpg] https://packages.stripe.dev/stripe-cli-debian-local stable main" | sudo tee -a /etc/apt/sources.list.d/stripe.list
sudo apt update
sudo apt install stripe

# Login
stripe login

# Forward webhooks to your dev site
stripe listen --forward-to https://dev.loothgroup.com/wp-json/lg-membership/v1/stripe-webhook

# Trigger test events
stripe trigger checkout.session.completed
stripe trigger customer.subscription.deleted
stripe trigger invoice.payment_failed
```

### 6. Development workflow

```bash
git checkout -b feature/webhook-handler
# make changes, test
git add -A
git commit -m "Add webhook handler with signature verification"
git push -u origin feature/webhook-handler

# When ready
git checkout main
git merge feature/webhook-handler
git push origin main
```

### 7. Deploy to production

```bash
ssh into production
cd /var/www/html/wp-content/plugins/lg-stripe-membership
git pull origin main
composer install --no-dev
```

---

## Claude Code Prompt

Copy everything below this line as the opening context for a Claude Code session.

---

````markdown
# Project: lg-stripe-membership

## What This Is

A WordPress plugin that handles Stripe-powered membership billing for The Looth Group (loothgroup.com), an international community of ~1,500 professional luthiers. The plugin manages user accounts based on Stripe payment events, coexisting with an existing Patreon billing pipeline.

## Environment

- WordPress on AWS EC2 Ubuntu
- Nginx, PHP 8.3, MariaDB, Redis
- Cloudflare Pro (provides CF-IPCountry header for geo-routing)
- BuddyBoss theme (community platform)
- Key existing plugins:
  - TierGate (content gating by WP role)
  - FluentCRM (email automation)
  - lg-patreon-onboard (Patreon OAuth account creation)
  - lg-patreon-sync (CSV-based Patreon role sync)
- Stripe PHP SDK via Composer (stripe/stripe-php)
- Stripe.js loaded client-side for Embedded Checkout

## WordPress Roles

- looth1: no active subscription (cancelled/lapsed/free)
- looth2: Looth Lite (paid standard membership)
- looth3: Looth Pro (paid full membership)
- looth4: admin/bypass — NEVER touch this role in any automated code

## Architecture Overview

### 1. Checkout Session Creation
REST endpoint creates a Stripe Checkout Session in EMBEDDED mode (`ui_mode: 'embedded'`). The checkout form renders inside the /join/ page — no redirect. Before creating the session, check the submitted email: if a WP user exists with `payment_source = patreon` and an active paid role, BLOCK the checkout (double payment prevention).

Price routing: Cloudflare CF-IPCountry header determines country. A lookup table in plugin settings maps countries to "developing economy" Price IDs. Standard countries get standard Price IDs. Stripe Adaptive Pricing (enabled in Dashboard) handles local currency display on top of this.

### 2. Return URL Handler
After successful payment, Stripe redirects to a return URL with session_id. This handler:
- Retrieves the Checkout Session from Stripe
- Checks if a WP user exists (by stripe_customer_id meta, then by email)
- If EXISTS and looth1: TOGGLE BACK ON — attach Stripe IDs, set payment_source=stripe, upgrade role
- If EXISTS and looth4: DO NOTHING
- If EXISTS and active paid with payment_source=patreon: should have been blocked pre-checkout
- If NOT EXISTS: CREATE new WP user, assign role, store meta
- Sets auth cookie (wp_set_auth_cookie) → user is logged in immediately
- Sends welcome email with password reset link (for new users)
- Redirects to homepage

THIS HANDLER OWNS USER CREATION. The webhook is the backup.

### 3. Webhook Handler
REST endpoint at `/wp-json/lg-membership/v1/stripe-webhook`. Receives Stripe events, verifies signature, routes to handlers.

Key events:
- `checkout.session.completed` — Find or verify WP user, confirm role. If return URL handler already created the user, just verify. If not (e.g., user closed browser), handle creation here.
- `invoice.paid` — Re-verify role is current. Always set correct role, don't skip. Handles renewals and card recovery.
- `customer.subscription.updated` — Plan changes, pause/resume. Tag in FluentCRM if canceling at period end.
- `customer.subscription.deleted` — Set role to looth1.
- `invoice.payment_failed` — Optional: FluentCRM tag for personal outreach.

IDEMPOTENCY: Store processed event IDs in transients. Skip duplicates.

### 4. Admin Settings
Settings page with:
- Stripe API keys (test + live) and mode toggle
- Webhook signing secret
- Tier mapping table (Price ID → WP Role → Label)
- Developing economy country list
- Price ID pairs (standard/developing × tier × interval)

### 5. Monthly Reconciliation Cron
Runs once/month via WP Cron. Queries all users with `payment_source=stripe` and looth2/looth3 roles. For each, checks Stripe API for active subscription. Downgrades orphans to looth1. Emails admin summary. Skips looth4 and non-Stripe users always.

### 6. Customer Portal
Simple endpoint that generates a Stripe Billing Portal session and redirects. Surfaced as a "Manage Billing" button in member profile. Members can: update payment, switch monthly↔yearly, cancel, pause, view invoices.

## Critical Design Rules

- **payment_source meta is the boundary.** Stripe code only touches payment_source=stripe users. Patreon sync only touches payment_source=patreon users. looth4 is untouchable by all.
- **Idempotent webhook handling.** Store processed event IDs, skip duplicates.
- **Return URL handler owns user creation and auth cookie.** Webhook is backup.
- **Tier mapping is config, not code.** Admin settings page maps Price IDs to roles.
- **looth4 check in EVERY function that calls set_role().** No exceptions.
- **Developing economy pricing is silent.** No public mention anywhere.
- **Double payment block.** Pre-checkout email check prevents Stripe checkout for active Patreon members.
- **Email changes are blocked in WordPress.** Email is a reliable fallback lookup.

## User Lookup / Creation Logic (in order)

1. Look up by `stripe_customer_id` meta → found? Update role, done.
2. Look up by email → found?
   a. looth1 → toggle back on (attach Stripe IDs, set payment_source, upgrade role)
   b. active paid + payment_source=patreon → should have been blocked pre-checkout, log error
   c. looth4 → skip
   d. active paid + payment_source=stripe → update sub ID, verify role
3. Not found → create new user (username from name/email), assign role, store all meta

## File Structure

```
lg-stripe-membership/
├── lg-stripe-membership.php          # Main plugin file, hooks, constants, autoloader
├── README.md
├── composer.json                     # stripe/stripe-php
├── includes/
│   ├── class-admin-settings.php      # Settings page, tier map, country list
│   ├── class-checkout.php            # Embedded Checkout Session creation, price routing
│   ├── class-webhook-handler.php     # REST endpoint, signature verify, event routing
│   ├── class-user-manager.php        # Find/create/toggle users, meta, auth cookie
│   ├── class-reconciler.php          # Monthly cron
│   ├── class-gift-membership.php     # v2 — gift flow
│   └── class-bulk-membership.php     # v2 — institutional subs
├── templates/
│   ├── join-page.php                 # Tier selection + Embedded Checkout mount (shortcode)
│   └── gift-redeem.php               # v2
└── assets/
    ├── css/join-page.css
    └── js/join-page.js               # Billing toggle, Stripe.js init, Embedded Checkout mount
```

## Coding Conventions

- PHP 8.3+ features welcome (typed properties, enums, match, named arguments)
- Class-based architecture, one class per file
- Prefix all functions/hooks with lgsm_
- Class names prefixed LGSM_ (autoloader expects this)
- WordPress coding standards for formatting
- error_log() for debug with prefix: 'LGSM: '
- Stripe API calls wrapped in try/catch
- All REST endpoints under namespace lg-membership/v1
- Admin settings: wp_options with lgsm_ prefix
- User meta keys: stripe_customer_id, stripe_subscription_id, payment_source

## Build Order

Start with Phase 1 — webhook pipeline tested with Payment Links:
1. Admin settings page with tier mapping
2. Webhook handler with signature verification + idempotency
3. User manager (find/create/toggle logic)
4. Monthly reconciliation cron
5. Test with Stripe CLI + Payment Links (no frontend needed yet)

Then Phase 2 — Embedded Checkout frontend:
1. Checkout Session creation endpoint (Embedded mode, CF-IPCountry routing)
2. Join page with Embedded Checkout mount (shortcode)
3. Return URL handler (auto-login)
4. Double payment pre-checkout block
5. "Manage Billing" → Customer Portal

Do NOT build gift memberships, bulk subscriptions, or FluentCRM integration yet.

## Testing

- Stripe test mode (sk_test_ / pk_test_ keys)
- Stripe CLI: `stripe listen --forward-to localhost/wp-json/lg-membership/v1/stripe-webhook`
- Test cards: 4242424242424242 (success), 4000000000000341 (decline), 4000000000003220 (3DS)
- Test with Payment Links first (zero frontend code needed)
- Verify: user created/toggled with correct role, stripe_customer_id in meta, payment_source=stripe
- Test cancellation: role flips to looth1 at period end
- Test re-subscribe: existing looth1 user gets role back, no duplicate account
- Test with looth4 user: role NEVER changes
- Test with active Patreon member email: checkout blocked
- Test $0 promo code: account still created
- Test duplicate webhook event: second event is a no-op
````

---

## Notes

- Replace `YOUR_GITHUB_USERNAME` in the git remote command
- The Claude Code prompt (everything inside the code fence above) is designed to be the opening context for a Claude Code session
- Phase 0 prerequisites should be completed before any coding begins
- Payment Links are a zero-code way to test the webhook pipeline immediately
