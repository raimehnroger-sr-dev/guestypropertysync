# Quay Holidays Implementation Scope

## Source architecture

- Baseline: Quay Holidays Guesty Property Sync v3.4.0
- Delivery model: targeted optimisation, not a full rebuild
- Frontend family: Quay production integration
- Elementor integration: retained
- Property custom post type and synced metadata: retained
- Booking/payment flow: existing Guesty + Stripe/GuestyPay assumptions retained

## Sprint mapping from the July 2026 plan

### Sprint 1 - Stability & Security

- HMAC-secured webhook
- Lazy calendar/availability loading
- Calendar and exact-quote caching
- Manual cache clearing and invalidation

### Sprint 2 - Search & Booking UX

- Store search/pricing/policy metadata during sync
- Advanced local filters
- Live price breakdown and coupon-aware quotes
- Checkout policy/rules/consent improvements

### Sprint 3 - Admin & Visibility

- Booking/revenue KPIs and recent bookings
- Activity/event log

## Deliberately excluded from this branch

- Laclef-specific frontend templates and styling
- The later Quay/Laclef combined single-page UI branch
- Google Forms as the authoritative checkout/payment form
- Any full rewrite of the Elementor templates

Those can be developed separately without changing the scope of this optimisation-plan edition.
