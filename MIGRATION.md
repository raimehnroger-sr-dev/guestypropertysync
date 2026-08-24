# Quay Holidays Migration and Acceptance Guide

## 1. Prepare staging

Use a production-equivalent staging clone. Back up the database and `wp-content/plugins`. Record the current Guesty credentials, payment settings, booking-page URL, forms/templates and webhook configuration.

## 2. Deactivate old plugins

Do not activate this package beside the current Quay Holidays plugin or any experimental Guesty build. They share global classes, AJAX actions, cron hooks, the `property` post type and shortcodes.

## 3. Install

Upload and activate the new ZIP. Activation creates or upgrades:

- `{prefix}guesty_optimized_calendar`
- `{prefix}guesty_optimized_quotes`
- `{prefix}guesty_activity_log`

Existing property posts remain in place.

## 4. Configure

Open **Guesty > Settings** and verify:

- Guesty client ID and secret
- 60-minute calendar cache default
- 15-minute exact quote cache default
- Calendar warmup window and batch
- Webhook Secret
- Booking Page URL
- Terms and Conditions URL
- Default currency
- Contact information
- GuestyPay/Stripe keys used by the existing booking flow
- Optional Google Maps key

## 5. Configure the secured webhook

The endpoint is shown in Settings. The sender must calculate HMAC-SHA256 from the exact raw JSON request body using the configured secret and provide it in `X-Guesty-Signature`.

Subscribe to the listing, calendar and reservation events used by the account. Confirm on staging that a valid delivery receives HTTP 200 and a deliberately invalid signature receives HTTP 401.

## 6. Sync metadata

Run a manual property sync. Confirm several property posts contain:

- `_guesty_base_price`
- `_guesty_min_nights`
- `_guesty_property_type`
- `_guesty_max_guests`
- `_guesty_bedrooms`
- `_guesty_bathrooms`
- `property_cancellation_policy`
- `property_house_rules`

## 7. Critical acceptance tests

1. Open a search/listing page with 20+ properties and inspect the browser Network panel.
2. Confirm no `guesty_check_availability`, `guesty_booking_data`, calendar or quote request fires merely because the page loaded.
3. Confirm each card displays the stored **From** price.
4. Open a single property page and confirm no calendar request fires before interaction.
5. Click **Check availability** and confirm exactly one calendar request occurs and the skeleton loader displays.
6. Repeat the same calendar range and confirm the response is served from cache.
7. Request the same exact quote twice and confirm the second request does not call Guesty.
8. Change guests or coupon and confirm a separate quote cache key/result is used.
9. Trigger a valid listing/calendar/reservation webhook and confirm affected cache entries are invalidated.
10. Send an invalid signature and confirm HTTP 401 plus an activity-log entry.
11. Use **Clear Optimised Caches** and confirm cached calendars/quotes are removed.

## 8. Optimisation-summary acceptance tests

1. Test destination, property type, price, bedroom, bathroom, amenity and sort filters.
2. Copy the results URL into a private window and confirm filter state is preserved.
3. Select dates/guests and confirm the live breakdown shows accommodation rate × nights, fees, taxes, coupon/promotion and total.
4. Confirm invalid coupons show a controlled error and valid coupons update the quote.
5. Confirm checkout displays cancellation policy and house rules.
6. Confirm both consent boxes are mandatory even if the submit button is re-enabled by another script.
7. Confirm dashboard totals/recent bookings/chart match Guesty for the same date range.
8. Confirm sync, webhook and API events appear in **Guesty > Activity Log**.
9. Complete GuestyPay/Stripe tokenization, reservation creation, payment method attachment and confirmation in test mode.

## 9. Production rollout

Use a maintenance window. Deactivate the production plugin, activate this build, verify settings, run a property sync, clear page/CDN caches, and complete a controlled booking test.

## Rollback

Deactivate this plugin and reactivate the former production plugin. The optimization tables may remain; they do not replace or delete the existing property posts.
