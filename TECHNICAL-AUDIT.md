# Technical Audit - Quay Holidays Optimisation Branch 3.5.0

## Architecture decision

This package is an incremental optimisation of the Quay Holidays Guesty Property Sync v3.4.0 architecture. It deliberately preserves public shortcodes, the `property` CPT, Elementor dynamic-tag integration and the existing Guesty/Stripe booking assumptions.

## API performance

- Search/listing rendering uses synced post meta instead of automatic per-property live quote calls.
- Single-property calendar loading is deferred until visitor interaction.
- Calendar and quote responses use short-lived deterministic caches.
- Identical quote work is guarded by request locks.
- Listing/reservation/calendar changes can invalidate affected cached data.
- OAuth access tokens are stored persistently and reused rather than regenerated for normal API traffic.

## Security

- Guesty webhook requires HMAC-SHA256 signature verification against the configured Webhook Secret.
- Signature comparison uses `hash_equals()`.
- Invalid signatures return 401 before sync/delete processing.
- Public calendar/availability actions validate listing/date input and apply throttling.

## Search and booking

- Filterable metadata is stored during sync.
- Search filtering is primarily local through `WP_Query` metadata.
- Exact pricing is requested only for explicit booking criteria.
- Checkout surfaces cancellation policy, house rules and Terms consent.
- The existing production payment/reservation flow remains the integration boundary and must be tested with Quay credentials on staging.

## Admin visibility

- Dashboard contains booking/revenue KPIs and recent bookings using cached reservation data.
- Activity log records sync, webhook and API events.
- Cache clearing and reservation-stat refresh controls are available to administrators.

## Known validation boundary

Local validation cannot prove compatibility with Quay's live Guesty tenant, signed webhook format, Elementor templates, Gravity Forms field mappings or Stripe/GuestyPay credentials. Production promotion requires staging acceptance.
