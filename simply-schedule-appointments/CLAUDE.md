# Simply Schedule Appointments — project notes

## Install-base scale

This plugin is installed on ~70,000 WordPress sites. Every change reaches that population. Default to the safer option in any security/UX or correctness/convenience trade-off, even when it costs more code or a worse local DX. A regression that ships at this scale is expensive in support load, reputation, and remediation; the cost of writing a few dozen extra LOC is not.

**Prefer surgical, minimal changes over refactors.** At this install base, blast radius matters more than code beauty. Narrow the fix to the actual bug or feature, leave surrounding code alone, and resist the urge to "clean up while you're here." A small targeted change is easier to review, easier to revert, and less likely to surprise the 70k sites that didn't ask for the cleanup. Refactors are a separate, deliberate decision — not a side effect of a bug fix.

## Edition builds — ALWAYS code for all four (Basic / Plus / Pro / Business)

We ship **four separate builds** from this one codebase: **Basic (1), Plus (2), Pro (3), Business (4)** (`ssa_editions()` in `simply-schedule-appointments.php`). They are not feature-flagged at runtime — **lower editions are physically smaller ZIPs with files removed at build time.** Any change that touches a feature class must be written so it still behaves correctly on an edition where that class's file does not exist. Forgetting this is exactly how the purge `500 — DELETE FROM <empty> WHERE …` shipped to production.

**How the builds are cut (`Gruntfile.js`).** The builds cascade, each stripping more on top of the previous:
- `business` = the full codebase (everything).
- `pro` = business **minus** Staff and Resources files — including `includes/class-staff-appointment-model.php` and `includes/class-resource-appointment-model.php`.
- `plus` = pro **minus** Webhooks, Payments/Stripe/PayPal, SMS, WooCommerce, etc.
- `basic` = plus **minus** Google Calendar, Gravity/Formidable Forms, Mailchimp, Zoom/Webex, MemberPress, reminders, license, etc.

So e.g. `class-staff-appointment-model.php` exists **only in Business** — it is absent from Pro, Plus, and Basic.

**What a missing class becomes at runtime — the trap.** The container loop in `simply-schedule-appointments.php` does `class_exists( $class ) ? new $class( $this ) : $this->missing`. When a file was stripped, the property is set to an **`SSA_Missing` stub**, NOT `null`. `SSA_Missing::__get()` returns `$this` and `SSA_Missing::__call()` returns `null`. Consequences:
- `! empty( $this->plugin->staff_appointment_model )` is **true** even when the feature is absent — the stub is a truthy object. `empty()`/`isset()` checks do **not** detect a stripped feature.
- `$this->plugin->staff_appointment_model->get_table_name()` returns `null` (and any method chain returns `null`), so building SQL from it yields `DELETE FROM  WHERE …` → SQL syntax error → 500. The original purge code called `->get_table_name()` on these models with no guard, which worked in dev and blew up on every non-Business customer.

**Why this slips past local testing.** Dev checkouts carry the full repo and the version string is `6.x`; `get_current_edition()` reads the first version digit, finds it isn't `1–4`, and **defaults to `max()` = Business**. So the dev box always behaves like the one edition where every file is present — the only edition where these bugs are invisible. "Works on my machine / can't reproduce" is the signature of an edition bug.

**Rules when coding a feature or fix:**
- Before calling `$this->plugin-><x>_model` / any feature class, ask "is this file in every edition, or stripped below some tier?" If it can be stripped, guard the call. Don't trust `! empty()` — check `instanceof SSA_Missing`, or validate the actual result (e.g. a non-empty table name) before using it.
- A feature being unreachable in the UI of a lower edition does **not** mean its code path is unreachable — shared/core code (purge, cleanup, cron, exports) runs on all editions and may touch higher-tier models.
- When a customer hits an error you can't reproduce, check their edition first (the version prefix: `2.x` = Plus, `3.x` = Pro, etc.). Reproduce by forcing that edition, not on the default Business dev box.

## Auth & permission rules

- **Static site-wide tokens are fine as a "you're a real booking-page visitor" signal; they are not fine as an authorization fallback on any route that isn't fully public.** A static site-wide string can't differentiate callers, so it can't decide "authorized vs unauthorized" on a route admins also use. If you reintroduce one, it must only be honored by a permission check wired exclusively to routes safe for any unauth visitor.

- **Permission gates must default to strict.** A helper that "usually" requires real auth but accepts a weaker fallback under some conditions is a trap — the next person to add a route will not notice. If a route genuinely needs to be reachable by unauth booking-page visitors, name the check after that fact (e.g. `public_booking_permissions_check`) so attaching a route to it is a deliberate, reviewable act. Broader access means a *different* check, not a relaxation of an existing strict one.

## Frontend translation strings

- **Every user-facing string in the admin app or booking app must be defined in that app's `strings.js`** (`admin-app/src/store/strings.js` or `booking-app-new/src/store/strings.js`). That file is the single source of truth.
- **NEVER edit or tamper with `languages/{admin-app,booking-app-new}-translations.php` — they are BUILD ARTIFACTS, not source.** `build-translations.php` parses each `strings.js` into a PHP array, wraps every value in `__( …, 'simply-schedule-appointments' )`, and (over)writes the `*-translations.php` file from scratch. Treat these files as read-only generated output. A string hand-added here but absent from `strings.js` looks fine until the next build silently deletes it — and a string added to `strings.js` but not regenerated renders blank on customer sites until someone rebuilds. This is exactly what happened with the purge modal's `backup_toggle_label`/`backup_help`: they were hand-written into the generated PHP and never put in `strings.js`, leaving a time-bomb that the next `build-translations.php` run would have wiped.
- **At runtime the Vue apps read the generated PHP (localized as `window.ssa_translations`), and fall back to `strings.js` only when that is empty** (see `defineTranslations`). In the real admin/booking page `window.ssa_translations` is always populated, so a key present in the generated PHP but missing from `strings.js` still renders — until someone regenerates, at which point it vanishes. The reverse (in `strings.js`, not yet regenerated into the PHP) renders blank on live. Both are bugs.
- **Workflow when adding/changing a string:** add it to the correct `strings.js`, then run `php build-translations.php` and commit the regenerated `languages/*-translations.php` alongside it. A clean change leaves `strings.js` and the generated file in sync (regeneration is a zero diff).

## Releases, version bumps, and changelog

- **Don't touch the changelog, plugin version, or release metadata manually.** Releases, version bumps in the plugin header / `readme.txt` / `package.json`, and changelog entries are handled by automation (the `[version-bump]` commit flow visible in `git log`). Adding a manual version bump or changelog line in a feature/fix PR will collide with that automation and produce a wrong-shaped release. Land the code change; let the release pipeline write the version and changelog.
- **The one exception is a model's `$version` constant used to trigger a DB migration.** When a model class uses its `$version` property to gate a schema/data migration (compared against the stored version to decide whether to run the migration), bumping that constant *is* the migration trigger and must be done by hand as part of the change. That bump is unrelated to the plugin release version — it's a per-model migration marker, not a release artifact.
