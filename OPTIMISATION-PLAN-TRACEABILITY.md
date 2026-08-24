# Quay Holidays Optimisation Plan Traceability

Build: **Quay Holidays Guesty Property Sync Optimised 3.5.0**

This build follows the July 2026 optimisation plan as an incremental upgrade to the existing Quay Holidays Guesty Property Sync v3.4.0. It preserves the existing Quay integration model rather than replacing it with the separate Laclef frontend branch.

| Priority | Plan item | Status | Primary implementation |
|---|---|---|---|
| Critical | 1. Lazy-load quote / availability calls | Implemented | `includes/class-guesty-property-short-code.php`, `includes/js/guesty-calendar.js`, `includes/js/guesty-search.js`, `includes/css/guesty-calendar.css` |
| Critical | 2. Transient caching for calendar / availability | Implemented | `includes/api/class-guesty-api.php`, `includes/cache/class-guesty-transient-cache.php`, calendar/quote services |
| Critical | 3. Webhook HMAC authentication | Implemented | `includes/class-guesty-property-sync.php`, `admin/settings.php` |
| High | 4. Store pricing meta during sync | Implemented | `includes/class-guesty-property-sync-manager.php`, `admin/class-property-metabox.php` |
| High | 5. Advanced search filters | Implemented | `includes/class-guesty-property-short-code.php`, search JS/CSS |
| High | 6. Booking totals + coupon | Implemented; Guesty staging validation required | booking shortcode/JS and quote service |
| High | 7. Checkout page improvements | Implemented; Quay form/payment validation required | booking shortcode/JS/CSS, synced policy meta |
| Medium | 8. Dashboard revenue & booking stats | Implemented; live Reservations API validation required | `admin/dashboard.php`, `includes/api/class-guesty-api.php` |
| Medium | 9. Activity / event log | Implemented | `includes/class-guesty-activity-log.php`, `admin/activity-log.php` |

## Critical acceptance criteria

- Search/listing page load does not fire a Guesty quote/calendar request for every property.
- Property cards display the locally synced starting price.
- Single-property calendar is deferred until interaction and shows a loader.
- Repeated calendar and quote requests use the configured cache.
- Listing/calendar/reservation events invalidate affected cached data.
- Invalid webhook signatures return HTTP 401 and do not mutate listings.

## High-priority acceptance criteria

- Property sync stores the plan's pricing/filter metadata without extra frontend API calls.
- Search filters operate through local WordPress metadata before remote availability discovery.
- Date/guest selection produces an itemised Guesty quote and coupon-aware total.
- Checkout displays cancellation policy and house rules and requires consent before payment.

## Medium-priority acceptance criteria

- Dashboard exposes current-month booking/revenue KPIs and recent bookings using cached reservation data.
- Sync, webhook and API failures are visible in the Guesty Activity Log.

## Additional Quay stability item

OAuth token persistence and generation safeguards are included because of the account's token-generation cap. This is an operational hardening item carried forward from the later Quay work and is not one of the nine items in the July plan.
