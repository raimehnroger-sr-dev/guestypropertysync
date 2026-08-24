# Build Report - Quay Holidays Guesty Property Sync Optimised 3.5.0

## Basis

- Baseline integration: Quay Holidays Guesty Property Sync v3.4.0
- Scope: July 2026 Guesty Property Sync Plugin Optimisation Plan
- Delivery approach: incremental replacement, not a full rebuild
- Frontend branch: Quay-native; no Laclef frontend code included

## Implemented plan items

1. Lazy-load quote / availability calls
2. Transient caching for calendar and quote data
3. Webhook HMAC signature authentication
4. Store pricing/filter metadata during sync
5. Advanced property search filters
6. Booking totals and coupon-aware quotes
7. Checkout cancellation policy, house rules and consent
8. Dashboard revenue and booking statistics
9. Sync/API/webhook activity log

Additional operational hardening: persistent OAuth token reuse and token-generation safeguards.

## Automated validation

- 34 non-vendor PHP files: syntax validation passed
- 5 plugin JavaScript files: Node syntax validation passed
- Critical optimisation smoke test: passed
- Service-layer cache/search/quote smoke test: passed
- Internal `$this->method()` static scan on core classes: no unresolved methods
- Executable/frontend code scan: no Laclef frontend markers

## Staging acceptance still required

- Quay Elementor layouts and dynamic tags
- Real Guesty OAuth credentials and token lifecycle
- Signed Guesty webhook deliveries
- Full property sync and metadata mapping
- Search/date availability against real inventory
- Coupon and exact quote payloads
- Gravity Forms booking field mapping
- Stripe/GuestyPay test payment
- Reservation creation and confirmation
- Dashboard Reservations API values

This build should replace, not run beside, the existing Quay Guesty plugin because it intentionally retains the same global integration identifiers.
