# External Embed — Internal Developer Reference

Added by Mohamad Amin Abdulkhalek in commit `1ea48ea12` (Aug 26, 2024, "Embed Booking App #1631").

## End-to-End Flow

```
External website                         WordPress (SSA)
──────────────                           ───────────────
1. Paste <script> snippet
      │
      ▼
2. XHR GET /wp-json/ssa/v1/embed_booking_app/
      │                                  ──► get_embed_code()
      │                                      │ calls ssa_booking($defaults)
      │                                      │ returns JS string
      ◄──────────────────────────────────────┘
      │  (Content-Type: application/javascript)
      │  (Access-Control-Allow-Origin: *)
      ▼
3. eval() returned JS
      │ - DOMParser creates iframe element
      │ - inserts before <script>
      │ - loads iframe-outer.js + ssa-tracking.js
      ▼
4. iframe src = /wp-json/ssa/v1/embed-inner
      │                                  ──► get_embed_inner_output()
      │                                      │ sends text/html
      │                                      │ renders iframe-inner.php
      ◄──────────────────────────────────────┘
      ▼
5. Vue booking app runs inside iframe
      │ - postMessage events → parent page
      │ - ssa-tracking.js fans to GA/GTM/FB/Segment
      │ - iframe-outer.js auto-resizes height
```

## Files & Classes

| File | Role |
|------|------|
| `includes/class-embed-booking-app-api.php` | REST controller for `/embed_booking_app/` endpoint |
| `includes/class-shortcodes.php` | Registers `/embed`, `/embed-inner`, `/embed-inner-admin` routes; `ssa_booking()` renders the iframe |
| `includes/class-bootstrap.php:147,165` | Assembles `api.root` and `api.embed_url` |
| `booking-app-new/iframe-inner.php` | Full HTML page served inside the iframe — loads Vue booking app |
| `assets/js/iframe-outer.js` | iframe-resizer library (auto-adjusts iframe height from parent) |
| `assets/js/ssa-tracking.js` | postMessage listener — bridges booking events to analytics platforms |
| `admin-app/src/components/settings/EmbedSSA/SettingsEmbedSSA.vue` | Admin UI: generates and displays the copyable embed snippet |
| `admin-app/src/store/modules/booking_embed/` | Vuex store module for fetching embed preview in admin |

## REST Endpoints

| Route | Method | Callback | Permission | Purpose |
|-------|--------|----------|------------|---------|
| `/ssa/v1/embed_booking_app/` | GET | `SSA_Embed_Booking_App_Api::get_embed_code()` | `__return_true` (public) | Returns JS payload that creates the booking iframe |
| `/ssa/v1/embed-inner` | GET | `SSA_Shortcodes::get_embed_inner_output()` | `__return_true` (public) | Returns full HTML page (iframe content) |
| `/ssa/v1/embed` | GET | `SSA_Shortcodes::get_embed_output()` | `__return_true` (public) | Returns raw shortcode output (admin preview) |
| `/ssa/v1/embed-inner-admin` | GET | `SSA_Shortcodes::get_embed_inner_admin_output()` | `ssa_manage_appointments` | Admin-only iframe content |

## CORS Posture

Only `/embed_booking_app/` sets CORS headers (in `class-embed-booking-app-api.php:106-108`):

```
Access-Control-Allow-Origin: *
Access-Control-Allow-Methods: GET
Access-Control-Allow-Headers: Content-Type
```

The `/embed-inner` endpoint does not need CORS — it loads as an iframe `src`, which is not subject to same-origin policy for navigation.

## Admin UI Snippet Generation

The Vue component at `SettingsEmbedSSA.vue` contains the snippet as a raw `<textarea>` with a placeholder string `domain` in the XHR URL. At mount time (line 59), `domain` is replaced with `this.api.root` (e.g., `https://example.com/wp-json/ssa/v1`).

The `api.root` value comes from `class-bootstrap.php:147`:
```php
'root' => untrailingslashit(home_url(rest_get_url_prefix().'/ssa/v1'))
```

## Known Issues & Caveats

1. **`eval()` usage**: The snippet uses `eval(xhr.responseText)` to execute the returned JS. Sites with strict Content Security Policy (`script-src` without `'unsafe-eval'`) will silently fail.

2. **Hardcoded version string**: `class-embed-booking-app-api.php:81-82` hardcodes `?ver=6.7.27` on the helper script URLs instead of using `SIMPLY_SCHEDULE_APPOINTMENTS_VERSION`. This means cached versions won't bust on plugin updates.

3. **Parameterization**: `get_embed_code()` reads query params from the request, intersects them against the shortcode defaults allowlist, and passes them through to `ssa_booking()`. Customers can append `?types=1,2&accent_color=0066cc` to the endpoint URL in the snippet. The admin UI (Settings → Embed SSA) provides a dropdown to select appointment types and an accent color picker, which update the snippet automatically.

4. **Analytics dependency**: `ssa-tracking.js` looks for GA, GTM, Facebook Pixel, Segment, and MonsterInsights on the host page. If none are present, events are silently dropped (no error).

## iframe-inner.php Supported Query Parameters

These are accepted by the Vue booking app when loading via `/embed-inner?key=value`:

| Parameter | Example | Purpose |
|-----------|---------|---------|
| `types` | `1,2,5` | Comma-separated appointment type IDs to display |
| `label` | `consultation` | Appointment type label/slug filter |
| `flow` | `...` | Booking flow override |
| `fallback_flow` | `...` | Fallback flow if primary is unavailable |
| `date_view` | `...` | Date picker view mode |
| `time_view` | `...` | Time picker view mode |
| `accent_color` | `0066cc` | Primary accent color (hex, no #) |
| `background` | `ffffff` | Background color |
| `font` | `Roboto` | Font family |
| `padding` | `20` | Padding in px |
| `booking_url` | `...` | URL of the page where booking is embedded |
| `booking_post_id` | `...` | WordPress post ID context |
| `booking_title` | `...` | Page title context |
| `redirect_post_id` | `...` | Post ID to redirect to after booking |
| `availability_start_date` | `2024-01-01` | Earliest available date |
| `availability_end_date` | `2024-12-31` | Latest available date |
