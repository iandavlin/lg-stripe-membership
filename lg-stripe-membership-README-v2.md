# lg-stripe-membership

**Stripe-powered membership billing for The Looth Group**

A lightweight WordPress plugin that handles membership payments via Stripe, automatic role assignment, and coexistence with the existing Patreon billing pipeline.

---

## Table of Contents

- [Overview](#overview)
- [User Experience Flow](#user-experience-flow)
- [Architecture](#architecture)
- [Stripe Configuration](#stripe-configuration)
- [Webhook Events](#webhook-events)
- [Patreon Coexistence](#patreon-coexistence)
- [Monthly Reconciliation Cron](#monthly-reconciliation-cron)
- [Features](#features)
- [Plugin File Structure](#plugin-file-structure)
- [Edge Cases](#edge-cases)
- [Panel Review](#panel-review)
- [Build Phases](#build-phases)

---

## Overview

The Looth Group currently manages membership billing through Patreon, with a custom OAuth onboarding plugin (`lg-patreon-onboard`) and a CSV-based role sync (`lg-patreon-sync`). This plugin adds Stripe as a parallel billing path, allowing new members to join and pay without ever leaving loothgroup.com.

Both billing systems (Stripe and Patreon) coexist by writing to the same WordPress role system. TierGate doesn't care how someone got their role — it just checks it.

### WordPress Roles

| Role | Purpose | Managed By |
|------|---------|------------|
| `looth1` | No active subscription (cancelled, lapsed, never paid) | Stripe webhooks / Patreon CSV sync |
| `looth2` | Looth Lite — standard membership | Stripe webhooks / Patreon CSV sync |
| `looth3` | Looth Pro — full membership | Stripe webhooks / Patreon CSV sync |
| `looth4` | Admin / bypass — never touched by any automated system | Manual only |

**Tier count is configurable.** The plugin uses an admin settings page to map Stripe Price IDs → WordPress roles. One tier, two tiers, five tiers — add or remove rows in settings. No code changes required. Currently planning for one or two paid tiers — final decision deferred until launch.

---

## User Experience Flow

### New Member Signup (No Existing Account)

```
1. Member hits join prompt
   (soft gate on content, join button, pricing page, etc.)
        │
2. Tier selection page on loothgroup.com (/join/)
   - Card(s) for each tier
   - Monthly / Yearly toggle
   - Promo code field available
        │
3. Stripe Embedded Checkout renders on the same page
   - No redirect. Payment form lives inside /join/ page.
   - Stripe handles card entry, validation, Apple Pay, Google Pay,
     Link, 3D Secure, 40+ payment methods
   - Adaptive Pricing auto-displays local currency
   - Developing economy pricing silently applied via CF-IPCountry
        │
4. Payment succeeds → Stripe fires checkout.session.completed
        │
5. Success handler on /join/ page (via return_url + session_id):
   - Checks: does a WP user with this email exist?
   - NO → Creates WP user (username from name/email)
   - Stores stripe_customer_id (cus_xxx) in usermeta
   - Stores payment_source = "stripe" in usermeta
   - Sets role (looth2/looth3) based on Price ID → role mapping
   - Sets auth cookie → user is logged in
   - Sends welcome email with password reset link (background)
   - Redirects to homepage
        │
6. Member is logged in on the homepage.
   TierGate sees their role, gated content is now visible.
```

### Returning Member — Resubscribe (Lapsed/Cancelled Account)

```
1. Former member hits join prompt
        │
2. Same /join/ page, same Embedded Checkout
        │
3. Payment succeeds
        │
4. Success handler:
   - Checks: does a WP user with this email exist?
   - YES, and they're looth1 (cancelled/lapsed) → TOGGLE BACK ON
     - Attaches stripe_customer_id
     - Sets payment_source = "stripe"
     - Sets role to whatever they paid for
     - Sets auth cookie → logged in
     - Redirects to homepage
   - YES, and they're active looth2/3 → BLOCK
     - "You already have an active membership"
   - YES, and they're looth4 → DO NOTHING
     - Admin/bypass, hands off
```

### Pre-Checkout Double Payment Block

Before creating the Stripe Checkout Session, check the submitted email:
- Does a WP user with this email have `payment_source = patreon` and an active paid role?
- If yes → block checkout. Show message: "You already have an active membership through Patreon."
- This prevents dual billing before payment is even attempted.

### Returning Member — Billing Management

- "Manage Billing" button in member profile/account area
- Links to Stripe Customer Portal (hosted by Stripe)
- Member can: update payment method, switch monthly↔yearly, cancel, pause, view invoices
- All changes fire webhooks → plugin updates roles accordingly

### Cancellation

1. Member cancels via Stripe Customer Portal
2. Stripe sets `cancel_at_period_end = true`
3. Member retains access for remainder of billing period
4. Period ends → Stripe fires `customer.subscription.deleted`
5. Webhook handler sets role to `looth1`
6. TierGate blocks gated content on next visit

### Subscription Pause

1. Member pauses via Stripe Customer Portal
2. Payment collection stops for configured duration
3. Access behavior during pause: configurable (keep access or restrict)
4. Pause ends → billing resumes automatically

### Failed Payment

1. Card fails on renewal
2. Stripe retries automatically (Smart Retries — ML-optimized timing, up to ~3 weeks)
3. Stripe sends automated dunning emails to the member
4. If all retries fail → subscription canceled → webhook → looth1
5. No cron, no custom retry logic, no code on our side

---

## Architecture

### Core Components

```
┌─────────────────────────────────────────────────────────┐
│                    loothgroup.com (main WP install)      │
│                                                         │
│  ┌───────────────────────┐  ┌──────────────────────────┐│
│  │  /join/ page           │  │  REST API                ││
│  │  Tier selection        │  │                          ││
│  │  + billing toggle      │  │  /lg-membership/v1/      ││
│  │  + Embedded Checkout   │  │    /create-checkout      ││
│  │    (no redirect)       │  │    /stripe-webhook       ││
│  │  + CF-IPCountry price  │  │    /customer-portal      ││
│  │    routing             │  │                          ││
│  └───────────┬────────────┘  └──────────┬───────────────┘│
│              │                          │                │
│  ┌───────────▼──────────────────────────▼───────────────┐│
│  │              lg-stripe-membership plugin              ││
│  │                                                      ││
│  │  - Admin settings (tier map, price IDs, countries)   ││
│  │  - Webhook handler (event → role assignment)         ││
│  │  - Checkout session creator (Embedded mode)          ││
│  │  - User manager (create OR toggle existing users)    ││
│  │  - Monthly reconciliation cron                       ││
│  │  - Gift membership handler (v2)                      ││
│  │  - Bulk/institutional subscription handler (v2)      ││
│  └──────────────────────────────────────────────────────┘│
│                                                         │
│  ┌─────────────────┐  ┌──────────────────────────────┐  │
│  │  lg-patreon-     │  │  lg-patreon-sync             │  │
│  │  onboard         │  │  (CSV role sync)             │  │
│  │  (OAuth flow)    │  │  ** Updated to skip          │  │
│  │                  │  │    payment_source=stripe **   │  │
│  └─────────────────┘  └──────────────────────────────┘  │
│                                                         │
│  ┌──────────────────────────────────────────────────────┐│
│  │  TierGate                                            ││
│  │  (checks role, doesn't care about payment source)    ││
│  └──────────────────────────────────────────────────────┘│
└─────────────────────────────────────────────────────────┘
                          │
                          │ webhooks
                          ▼
              ┌───────────────────────┐
              │       Stripe          │
              │                       │
              │  Products & Prices    │
              │  Customers            │
              │  Subscriptions        │
              │  Embedded Checkout    │
              │  Customer Portal      │
              │  Adaptive Pricing     │
              │  Coupons/Promo Codes  │
              │  Invoicing & Dunning  │
              │  Smart Retries        │
              │  Payment Links (test) │
              └───────────────────────┘
```

### User Meta Schema

```
stripe_customer_id     → cus_xxxxxxxxxxxxx
stripe_subscription_id → sub_xxxxxxxxxxxxx
payment_source         → "stripe" | "patreon" | "manual"
lgpo_patreon_user_id   → (existing, from lg-patreon-onboard)
```

### User Lookup / Creation Logic

On successful checkout:

1. Look up WP user by `stripe_customer_id` meta → found? Update role, done.
2. Look up WP user by email → found?
   a. looth1 (lapsed) → toggle back on: attach stripe IDs, set payment_source, upgrade role
   b. looth2/3 with payment_source=patreon → block (double payment prevention)
   c. looth4 → skip, do nothing
   d. looth2/3 with payment_source=stripe → update subscription ID, verify role
3. No match → create new WP user, assign role, store meta

Email changes are blocked in WordPress, so email remains a reliable fallback lookup.

---

## Stripe Configuration

### Products & Prices

One Product per tier. Multiple Prices per Product (billing interval × region).

**Example — Two Tier Setup:**

| Product | Price ID | Amount | Interval | Region |
|---------|----------|--------|----------|--------|
| Looth Lite | `price_lite_mo_std` | $X/mo | Monthly | Standard |
| Looth Lite | `price_lite_yr_std` | $Y/yr | Annual | Standard |
| Looth Lite | `price_lite_mo_dev` | $A/mo | Monthly | Developing |
| Looth Lite | `price_lite_yr_dev` | $B/yr | Annual | Developing |
| Looth Pro | `price_pro_mo_std` | $X/mo | Monthly | Standard |
| Looth Pro | `price_pro_yr_std` | $Y/yr | Annual | Standard |
| Looth Pro | `price_pro_mo_dev` | $A/mo | Monthly | Developing |
| Looth Pro | `price_pro_yr_dev` | $B/yr | Annual | Developing |

**One Tier Setup:** Same structure, half the rows. Configurable in admin settings.

### Checkout Mode: Embedded

The plugin uses Stripe Embedded Checkout (`ui_mode: 'embedded'`). This renders Stripe's full Checkout experience inside a component on the `/join/` page. No redirect to Stripe. All Stripe Checkout features are available: Apple Pay, Google Pay, Link, 3D Secure, promo codes, 40+ payment methods.

The Checkout Session is created server-side via REST endpoint. The client receives a `client_secret` and mounts the Embedded Checkout component using Stripe.js.

### Adaptive Pricing (Currency Localization)

Stripe Adaptive Pricing is enabled in the Dashboard. It automatically:
- Detects the customer's location via IP
- Displays prices in their local currency
- Handles currency conversion
- Customers pay a 2–4% conversion fee (not charged to you)

**Note:** Adaptive Pricing handles currency DISPLAY, not PPP discounts. The developing economy price routing (CF-IPCountry → different Price ID) provides the actual discount. Adaptive Pricing runs on top of whatever Price ID you pass — so a developing economy member sees their discounted price displayed in their local currency.

### Developing Economy Pricing (PPP Discount)

- Silent, IP-based routing. No public-facing toggle or pricing page.
- Cloudflare `CF-IPCountry` header determines country.
- Country → price ID lookup table stored in plugin settings.
- Members in qualifying countries see the developing price as "the price."
- Members in standard countries never know the developing price exists.
- Stripe Radar can flag card-country / IP-country mismatches as a VPN guardrail.

### Customer Portal Configuration

Configured in Stripe Dashboard (not in code):

- Allow: update payment method, cancel subscription, switch billing interval, pause subscription
- Return URL: homepage or member profile page

### Payment Links (Testing & Bootstrapping)

Stripe Payment Links can be created in the Dashboard with zero code. They support subscriptions, promo codes, and free trials. Use Payment Links to:
- Test the entire subscription lifecycle before the plugin frontend exists
- Validate webhook handler logic against real Stripe events
- Bootstrap early beta members while the /join/ page is being built

Payment Links fire the same webhook events as Embedded Checkout, so the webhook handler works identically regardless of how the checkout was initiated.

---

## Webhook Events

The plugin registers a single REST endpoint:

```
POST /wp-json/lg-membership/v1/stripe-webhook
```

### Events to Handle

| Event | Action |
|-------|--------|
| `checkout.session.completed` | Find or create WP user, assign role, store Stripe IDs |
| `invoice.paid` | Re-verify role is current (handles renewals, card recovery) |
| `customer.subscription.updated` | Handle plan changes, pause/resume, tag in FluentCRM if canceling at period end |
| `customer.subscription.deleted` | Set role to `looth1` — access revoked |
| `invoice.payment_failed` | Optional: tag in FluentCRM for personal outreach |

### Webhook Security

- Verify webhook signature using Stripe's signing secret
- Reject unsigned or tampered payloads
- Check for duplicate event IDs (idempotency)
- Return 200 OK on successful processing
- Stripe retries up to 30 times over ~3 days on failure

### Race Condition: Webhook vs Return URL

Stripe fires the webhook almost instantly — sometimes before the return URL handler executes. Rule: **the return URL handler owns user creation and auth cookie.** The webhook is the backup verifier. If the user already exists when the webhook fires, it just confirms/updates the role.

---

## Patreon Coexistence

### The Rule

Each automated system only manages its own members. The `payment_source` usermeta field is the boundary.

| System | Manages | Skips |
|--------|---------|-------|
| Stripe webhooks | `payment_source = stripe` | `patreon`, `manual`, `looth4` |
| Stripe monthly cron | `payment_source = stripe` | `patreon`, `manual`, `looth4` |
| Patreon CSV sync | `payment_source = patreon` (or no tag — legacy) | `stripe`, `manual`, `looth4` |
| Manual / Admin | `looth4` and edge cases | — |

### Required Change to lg-patreon-sync

The CSV sync executor must be updated to skip Stripe members **before Stripe goes live**:

```php
$payment_source = get_user_meta( $user->ID, 'payment_source', true );
if ( $payment_source === 'stripe' ) {
    continue; // Not my problem
}
```

### Double Payment Prevention

Before creating a Checkout Session, check the email:
- WP user exists with `payment_source = patreon` and active paid role → block checkout
- Show message directing them to contact admin if they want to switch billing sources
- Prevents accidental dual billing at the point of entry

---

## Monthly Reconciliation Cron

Runs once per month via WP Cron. Safety net only — webhooks handle 99.9% of role changes.

### Logic

1. Query all WP users where `payment_source = stripe` AND role is `looth2` or `looth3`
2. For each: call Stripe API to check if customer has an active subscription
3. If no active subscription → set role to `looth1`, log it
4. Skip `looth4` always
5. Skip users without `stripe_customer_id` always
6. Email summary to admin

---

## Features

### v1 — Core

- [ ] Stripe Embedded Checkout integration (no redirect)
- [ ] Webhook endpoint with signature verification and idempotency
- [ ] User creation for new members + toggle-back-on for lapsed members
- [ ] Double payment block (prevent Stripe checkout for active Patreon members)
- [ ] Admin settings page with tier mapping (Price ID → WP Role)
- [ ] Developing economy silent pricing (CF-IPCountry routing)
- [ ] Stripe Adaptive Pricing enabled (currency localization)
- [ ] Monthly/yearly billing support
- [ ] Promo codes / coupons (Stripe-native, enabled on Embedded Checkout)
- [ ] Customer Portal link (manage billing, cancel, pause, update payment)
- [ ] Cancellation handling (end of billing period)
- [ ] Subscription pause support
- [ ] Failed payment handling (Stripe dunning + eventual role downgrade)
- [ ] Monthly reconciliation cron
- [ ] payment_source usermeta tagging
- [ ] Update lg-patreon-sync to respect payment_source
- [ ] Welcome email with password reset link
- [ ] looth4 protection in all role-changing functions

### v2 — Extended

- [ ] Gift memberships (buyer pays, recipient gets invite to activate)
- [ ] Bulk / institutional subscriptions (schools, multi-person shops)
- [ ] Admin dashboard: past_due members, upcoming cancellations, recent churn
- [ ] FluentCRM integration: tags for payment source, cancellation warnings, winback triggers
- [ ] Free trial support

### Future

- [ ] Netflix-style discovery page with mixed free/paid content
- [ ] Member directory as dynamic homepage
- [ ] Self-serve tier upgrade/downgrade via Customer Portal
- [ ] Patreon → Stripe migration flow for existing members

---

## Plugin File Structure

```
lg-stripe-membership/
├── lg-stripe-membership.php          # Main plugin file, hooks, constants
├── README.md                         # This file
├── composer.json                     # Stripe PHP SDK dependency
│
├── includes/
│   ├── class-admin-settings.php      # Settings page, tier mapping, country list
│   ├── class-checkout.php            # Embedded Checkout Session creation, price routing
│   ├── class-webhook-handler.php     # REST endpoint, event processing, signature verification
│   ├── class-user-manager.php        # User create/find/toggle, meta management, auth cookie
│   ├── class-reconciler.php          # Monthly cron, Stripe API audit
│   ├── class-gift-membership.php     # Gift purchase + redemption flow (v2)
│   └── class-bulk-membership.php     # Institutional subscriptions (v2)
│
├── templates/
│   ├── join-page.php                 # Tier selection + Embedded Checkout mount (shortcode)
│   └── gift-redeem.php               # Gift activation page (v2)
│
└── assets/
    ├── css/
    │   └── join-page.css             # Tier card styling
    └── js/
        └── join-page.js              # Billing toggle, Embedded Checkout init, Stripe.js
```

---

## Edge Cases

### Critical — Must handle in v1

**1. Duplicate webhook events.**
Store processed event IDs, skip duplicates. Every handler must be idempotent.

**2. Webhook vs return URL race condition.**
Return URL handler owns user creation + auth cookie. Webhook is backup verifier only.

**3. Returning member re-subscribes.**
Same `cus_` ID, new `sub_` ID. Look up by `stripe_customer_id` meta, update role and sub ID. Don't create a new account. This is the normal "toggle back on" flow.

**4. Double billing — Stripe + Patreon on same email.**
Blocked at checkout time. Pre-checkout email check prevents Stripe Checkout Session creation for active Patreon members.

**5. looth4 protection.**
Every function that calls `set_role()` checks for looth4 first. No exceptions.

**6. $0 invoice from promo code.**
100% off coupon = $0 first charge. Still create/activate the account — subscription is valid.

### Important — Should handle in v1

**7. Card update during past_due.**
`invoice.paid` handler must always re-verify and set the correct role, even if user appears to have it.

**8. Gift to existing user (v2).**
Collision detection same as lg-patreon-onboard pattern.

### Low Risk — Accept or defer

**9. Developing economy user moves countries.**
Subscription stays at original price. Not worth solving.

**10. Stripe API rate limits on cron.**
Only matters at thousands of Stripe members. Batch API calls if needed later.

---

## Panel Review

*Per the multi-expert panel protocol — compressed dialectic summary.*

### Key Tensions

**1. Embedded Checkout vs Hosted Checkout (Revised)**

- **Previous decision:** Hosted Checkout (redirect) was chosen for simplicity.
- **New information:** Stripe now offers Embedded Checkout (`ui_mode: 'embedded'`) — the full hosted Checkout experience rendered inside a component on the site. No redirect, no iframe, same feature set (Apple Pay, Google Pay, Link, 3D Secure, promo codes, 40+ payment methods), Stripe handles PCI compliance.
- **UX agent:** Strongly endorses. This is the "never leave the site" dream without any custom payment form code.
- **Architecture agent:** Agrees. Embedded Checkout is marginally more code than hosted (need to mount the JS component), but dramatically less than building with Elements. Stripe.js handles everything.
- **Revised resolution:** Embedded Checkout. Best of both worlds — seamless UX, zero payment form maintenance.

**2. Developing Economy Pricing + Adaptive Pricing (Revised)**

- **Previous plan:** Custom CF-IPCountry routing to different Price IDs.
- **New information:** Stripe Adaptive Pricing auto-converts prices to local currency via IP. Free for the merchant; customer pays 2–4% conversion fee.
- **Domain expert:** Adaptive Pricing handles currency display but NOT purchasing power discounts. A luthier in Indonesia still pays the same USD-equivalent amount, just displayed in IDR. The whole point of developing economy pricing is a lower actual price.
- **Resolution:** Use BOTH. CF-IPCountry routes to a lower Price ID (the actual discount). Adaptive Pricing displays that lower price in local currency. They're complementary, not competing.

**3. User Creation Model (Simplified)**

- **Previous plan:** Stripe always creates new WP users.
- **Revised approach:** Stripe checks for existing users first. Creates only if none found. Toggles lapsed users (looth1) back on. Blocks active Patreon members at checkout time.
- **Architecture agent:** This eliminates the most dangerous edge cases — no more duplicate accounts, no more collision resolution. One user, multiple possible payment sources over time, role toggles up and down.
- **UX agent:** Returning members get their old account back with all their history. Huge win for community continuity.
- **Resolution:** Unanimous. Check-then-create/toggle is the correct pattern.

**4. Payment Links as a Testing Bootstrap**

- **Architecture agent:** Payment Links let you test the entire webhook pipeline with real Stripe events before the frontend exists. Build the webhook handler first, test with Payment Links, then build the /join/ page with Embedded Checkout.
- **Resolution:** Use Payment Links in Phase 1 for webhook testing. Replace with Embedded Checkout in Phase 2.

**5. Patreon Sync Collision Risk (Unchanged)**

- **Architecture agent:** Still the highest-risk item. Update lg-patreon-sync FIRST.
- **Resolution:** Blocking prerequisite. No Stripe members can exist until the sync respects `payment_source`.

---

## Build Phases

### Phase 0 — Prerequisites
1. Update `lg-patreon-sync` to check `payment_source` meta and skip `stripe` members
2. Set up Stripe Products and Prices in test mode
3. Configure Stripe Customer Portal in Dashboard
4. Enable Adaptive Pricing in Dashboard
5. Install Stripe CLI for local webhook testing
6. Create Payment Links for each tier (testing only)

### Phase 1 — Webhook Pipeline (test with Payment Links)
1. Plugin scaffold, autoloading, main plugin file
2. Admin settings page with tier mapping
3. Webhook handler with signature verification + idempotency
4. User manager (find/create/toggle logic)
5. Monthly reconciliation cron
6. **Test entire lifecycle using Payment Links + Stripe CLI**

### Phase 2 — Frontend + Embedded Checkout
1. Checkout Session creation endpoint (Embedded mode)
2. Tier selection page with Embedded Checkout mount (shortcode)
3. Monthly/yearly toggle
4. Developing economy price routing (CF-IPCountry)
5. Pre-checkout double payment block
6. Return URL handler (auto-login)
7. "Manage Billing" button → Customer Portal
8. Welcome email

### Phase 3 — Extended Features
1. Gift memberships
2. Bulk/institutional subscriptions
3. Admin reporting dashboard
4. FluentCRM tag integration

---

## Dependencies

- **Stripe PHP SDK** (`stripe/stripe-php`) via Composer
- **Stripe.js** (client-side, loaded from js.stripe.com for Embedded Checkout)
- **WordPress 6.x+**
- **PHP 8.3+**
- **Cloudflare Pro** (for CF-IPCountry header)
- **TierGate** (existing — handles content gating based on roles)
- **FluentCRM** (existing — for tagging and email automation)
- **lg-patreon-sync** (existing — must be updated to respect payment_source)
- **lg-patreon-onboard** (existing — continues to handle Patreon OAuth flow)
