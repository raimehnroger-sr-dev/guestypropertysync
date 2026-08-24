# Quay Holidays Guesty Property Sync Optimised 3.5.0

This is a **Quay Holidays-specific replacement build** based on the existing Guesty Property Sync v3.4.0 architecture and the July 2026 *Guesty Property Sync Plugin Optimisation Plan*.

It is intentionally **not a full rebuild** and it does **not use the Laclef frontend branch**. The Quay shortcode, Elementor integration, property sync model, Gravity Forms/booking assumptions, GuestyPay/Stripe flow and existing `property` custom post type are retained.

## Scope implemented from the optimisation plan

### Sprint 1 - Stability & Security

1. **Lazy-load quote / availability calls**
   - Search/listing cards no longer request a live quote/calendar on initial page load.
   - Starting prices are read from synced post meta.
   - Single-property calendars load only after explicit visitor interaction.
   - A skeleton loader is displayed during deferred calendar loading.

2. **Transient caching for calendar and quote data**
   - Calendar cache defaults to 60 minutes and is configurable.
   - Quote cache defaults to 15 minutes and is configurable.
   - Quote cache keys include listing, dates, guest composition and coupon.
   - Listing/calendar/reservation events invalidate affected caches.
   - Admin dashboard includes **Clear Optimised Caches**.

3. **Webhook HMAC authentication**
   - `/wp-json/guesty/v1/webhook` requires `X-Guesty-Signature`.
   - HMAC-SHA256 is calculated from the raw request body and configured Webhook Secret.
   - Comparison uses `hash_equals()`.
   - Invalid signatures return HTTP 401 and are logged.

### Sprint 2 - Search & Booking UX

4. **Store pricing meta during sync**
   - `_guesty_base_price`
   - `_guesty_min_nights`
   - `_guesty_property_type`
   - `_guesty_max_guests`
   - `_guesty_bedrooms`
   - `_guesty_bathrooms`
   - cancellation policy and house rules

5. **Advanced search filters**
   - Destination / city
   - Price minimum / maximum
   - Bedrooms
   - Bathrooms
   - Property type
   - Amenities/highlights
   - Recommended / price / newest / featured sorting
   - URL-preserved filter state and Reset Filters

6. **Booking form totals + coupon**
   - Exact quote is requested only after the visitor selects booking criteria.
   - Breakdown supports accommodation/nightly rate, nights, fees, taxes, promotions/coupon and total.
   - Coupon-aware quote responses use isolated short-lived cache keys.

7. **Checkout improvements**
   - Cancellation policy and house rules are synced locally and displayed at checkout.
   - Required cancellation-policy and Terms & Conditions consent is enforced.
   - The existing Quay Guesty/Stripe reservation workflow is retained.

### Sprint 3 - Admin & Visibility

8. **Dashboard revenue & booking stats**
   - Total properties
   - Monthly bookings
   - Monthly revenue
   - Average nightly rate
   - Recent bookings
   - Revenue chart
   - Reservation data cache and manual refresh

9. **Activity / event log**
   - Sync events
   - Webhook events
   - API errors
   - Listing IDs and timestamps
   - Retention setting and Clear Log control

## Additional stability hardening carried forward

This branch also includes the later OAuth token-safety fix because the Quay account has a strict access-token generation quota. This is separate from the nine-item July plan:

- persistent shared OAuth token vault
- token reuse for the token lifetime
- single-flight token generation lock
- no automatic token regeneration for every generic HTTP 401
- local token-generation safety guard and dashboard diagnostics

## Compatibility

Retained public shortcodes include:

- `[property_search_filter]`
- `[property_search_results]`
- `[property_calendar]`
- `[property_gallery]`
- `[property_amenities]`
- `[property_featured_amenities]`
- `[property_single_amenities]`
- `[favorites_list]`
- `[favorites_single]`
- `[guesty_overall_rating]`

## Deployment

Do **not** activate this build beside the existing Quay Guesty plugin. It intentionally retains the same global classes, shortcodes, AJAX actions, cron hooks and `property` post type so it can replace v3.4.0 without rebuilding Elementor templates.

1. Create a production-equivalent staging copy.
2. Back up the database and plugin directory.
3. Deactivate the current Quay Guesty Property Sync plugin.
4. Install and activate this ZIP.
5. Verify Guesty credentials, webhook secret, cache TTLs, booking URL, Terms URL and payment settings.
6. Run a manual property sync.
7. Complete the acceptance tests in `MIGRATION.md`.
8. Promote only after the existing Quay booking flow passes in staging.

## Validation boundary

Static syntax, cache/service smoke tests and security regression checks can be completed locally. Final acceptance still requires the real Quay WordPress/Elementor templates, Guesty account, signed webhook deliveries, Gravity Forms configuration and Stripe/GuestyPay test credentials.
