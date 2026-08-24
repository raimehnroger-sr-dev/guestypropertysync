# Changelog

## 3.5.0 - Quay Holidays optimisation-plan branch

- Created as a separate Quay Holidays replacement branch from the production-compatible optimisation build.
- Retains Quay shortcodes, Elementor integration, property CPT/meta model and Guesty/Stripe booking assumptions.
- Implements all nine items from the July 2026 Guesty Property Sync Plugin Optimisation Plan.
- Carries forward the OAuth token-reuse and token-generation safety patch.
- Does not include the Laclef-specific search/single/checkout frontend branch.

### Critical
- Lazy availability/calendar/quote loading.
- Configurable calendar and quote transient caching with targeted invalidation.
- HMAC-SHA256 secured Guesty webhook with HTTP 401 rejection.

### High
- Stored pricing/filter/policy metadata during sync.
- Local advanced property search filters and URL state.
- Live quote totals and coupon handling.
- Cancellation policy, house rules and required checkout consent.

### Medium
- Cached booking/revenue dashboard.
- Activity/event logging with retention controls.
