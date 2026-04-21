# Embed Your Booking Form on Any Website

You can embed the Simply Schedule Appointments booking form on any website — Wix, Squarespace, a static HTML page, or any other platform that allows custom HTML/JavaScript.

## How to Get the Embed Code

1. In your WordPress admin, go to **Simply Schedule Appointments → Settings → Embed SSA**.
2. You'll see a text box with a JavaScript snippet. Click the **Copy** button to copy it to your clipboard.
3. Paste the snippet into any page on your external website where you want the booking form to appear.

The booking form will load as an inline iframe wherever you place the snippet.

<!-- Screenshot placeholder: add a screenshot of the Settings → Embed SSA admin page here -->

## Example

Here's what the embed snippet looks like (your site URL will be filled in automatically):

```html
<script type="text/javascript">
  (function(d) {
    const pScript = d.currentScript;
    var xhr = new XMLHttpRequest();
    xhr.open('GET', 'https://YOUR-SITE.com/wp-json/ssa/v1/embed_booking_app/', true);
    xhr.onreadystatechange = function () {
      if (xhr.readyState === 4 && xhr.status === 200) {
        var script = d.createElement('script');
        script.type = 'text/javascript';
        script.text = eval(xhr.responseText);
        pScript.parentNode.insertBefore(script, pScript);
      }
    };
    xhr.send();
  })(document);
</script>
```

Place this wherever you want the booking form to appear in your page's HTML. No additional CSS or JavaScript is needed — the snippet loads everything automatically.

## Customizing via the Admin UI

The **Settings → Embed SSA** page lets you configure the embed before copying the snippet:

- **Appointment Type** dropdown — select a specific type, or leave as "All Appointment Types" to show the full list.
- **Accent Color** picker — set a custom accent color for the booking interface.

The generated snippet updates automatically as you change these options. Copy it once you're happy with the configuration.

## What Gets Loaded

When a visitor loads your external page:

1. The snippet fetches the booking form configuration from your WordPress site.
2. An iframe is created containing the full booking interface.
3. The iframe automatically resizes to fit its content (no scrollbars).
4. If you have analytics (Google Analytics, Google Tag Manager, Facebook Pixel, or Segment) on your external page, booking events will be tracked automatically.

## Troubleshooting

### The booking form doesn't appear

- **Check your browser's developer console** (F12 → Console tab) for errors.
- **Content Security Policy (CSP)**: If your site uses a strict CSP, the embed may be blocked. You'll need to allow `'unsafe-eval'` in your `script-src` directive and allow `frame-src` for your WordPress domain.
- **Verify your WordPress site is accessible**: Open `https://YOUR-SITE.com/wp-json/ssa/v1/embed_booking_app/` directly in your browser. You should see JavaScript code, not an error page.

### The form appears but has a scrollbar / wrong height

- Make sure nothing on your page is constraining the iframe's height (e.g., a container with `overflow: hidden` or a fixed height).
- The iframe resizer script handles height automatically, but CSS conflicts can interfere.

### Analytics events aren't firing

- The analytics bridge looks for GA (`ga` or `gtag`), Google Tag Manager (`dataLayer`), Facebook Pixel (`fbq`), and Segment (`analytics`) on your page. Make sure your analytics scripts load **before** the SSA embed snippet.
- Open your browser console and look for `postMessage` events to verify the iframe is sending them.

## Advanced: Manual Iframe Embedding

If you need more control, you can skip the snippet and embed the booking iframe directly:

```html
<iframe
  src="https://YOUR-SITE.com/wp-json/ssa/v1/embed-inner?types=1,2&accent_color=0066cc"
  style="width: 100%; border: none; min-height: 600px;"
></iframe>
```

### Supported URL Parameters

| Parameter | Example | Description |
|-----------|---------|-------------|
| `types` | `1,2,5` | Show only specific appointment types (comma-separated IDs) |
| `label` | `consultation` | Filter by appointment type label/slug |
| `accent_color` | `0066cc` | Primary color (hex value without `#`) |
| `background` | `f5f5f5` | Background color (hex value without `#`) |
| `font` | `Roboto` | Font family name |
| `padding` | `20` | Internal padding in pixels |
| `availability_start_date` | `2024-06-01` | Earliest date to show availability |
| `availability_end_date` | `2024-12-31` | Latest date to show availability |

**Note:** When using a direct iframe, you won't get automatic height resizing or analytics tracking. For those features, include these scripts on your page:

```html
<script src="https://YOUR-SITE.com/wp-content/plugins/simply-schedule-appointments/assets/js/iframe-outer.js"></script>
<script src="https://YOUR-SITE.com/wp-content/plugins/simply-schedule-appointments/assets/js/ssa-tracking.js"></script>
```

## Requirements

- Your WordPress site must be publicly accessible (the external site needs to reach it).
- The visitor's browser must have JavaScript enabled.
- No WordPress plugins or server configuration should be blocking the REST API at `/wp-json/ssa/v1/`.
