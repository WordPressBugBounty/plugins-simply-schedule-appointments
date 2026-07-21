# Simply Schedule Appointments — project notes

## Install-base scale

This plugin is installed on ~70,000 WordPress sites. Every change reaches that population. Default to the safer option in any security/UX or correctness/convenience trade-off, even when it costs more code or a worse local DX. A regression that ships at this scale is expensive in support load, reputation, and remediation; the cost of writing a few dozen extra LOC is not.

**Prefer surgical, minimal changes over refactors.** At this install base, blast radius matters more than code beauty. Narrow the fix to the actual bug or feature, leave surrounding code alone, and resist the urge to "clean up while you're here." A small targeted change is easier to review, easier to revert, and less likely to surprise the 70k sites that didn't ask for the cleanup. Refactors are a separate, deliberate decision — not a side effect of a bug fix.

## Ship only the plugin — every fix MUST live inside `simply-schedule-appointments/`

**We ship exactly one artifact to customers: the SSA plugin ZIP.** Nothing outside the plugin directory reaches their site. A fix is only real if it lives entirely within `wp-content/plugins/simply-schedule-appointments/`. Anything you change *outside* that directory — an mu-plugin, `wp-config.php`, another plugin, the active theme, a `.htaccess`/nginx/server tweak, a WP drop-in, a DB option set by hand — exists only on your local box and will be **absent on all ~70k customer sites.** A fix made that way is not a fix; it ships as a no-op and the customer's bug is still there.

- **The fix must be self-contained in SSA code.** If a correct fix appears to require editing something outside the plugin, that's a signal the approach is wrong — rework it so SSA handles the situation from *within* (a guard, a defensive check, the right hook), rather than changing the environment around it.
- **A bug triggered by something external is still fixed inside SSA.** When another plugin/host/flow provokes the misbehavior (e.g. Jetpack applying the `plugin_action_links` filter during a plugin-update AJAX request, which surfaced SSA echoing its Basic-edition upgrade `<style>` into the JSON body → "Update failed" on every plugin update), we cannot tell 70k customers to change Jetpack or their host. SSA must behave correctly regardless of what else is installed. The fix is defensive SSA code (here: never emit output from a filter callback), not a change to the trigger.
- **Local reproduction scaffolding is throwaway and never part of the shipped change.** Faking the edition (temporarily editing `VERSION`), simulating a trigger with a local mu-plugin, or tweaking DB options to reproduce is fine — but it lives *outside* the plugin dir (or is reverted immediately) and is never committed. Only the in-plugin fix ships; delete the scaffolding when you're done.
- **Be proactive when the real fix would land outside SSA — surface it immediately, don't paper over it.** If the genuine root cause or the correct fix lives outside the plugin (another plugin, the host, WP core, the customer's config), do **not** silently contort SSA into an in-plugin workaround to mask it. Stop and raise it with the dev right away, with the evidence, so the team can decide the one question that matters: *is this actually an SSA bug, or is it out of scope?* Both answers are actionable — if it **is** SSA's, fix it in-plugin per the rule above; if it **isn't**, the SSA team can go back to the customer with a clear explanation of the real cause and why a plugin-side fix doesn't apply, instead of promising a fix that can't ship. Quietly shipping an in-plugin hack for a non-SSA bug is its own regression: it adds permanent surface area to 70k sites to hide a problem SSA didn't cause, and it lets the customer keep believing SSA is at fault.

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

## Settings schema — bump the schema `version` when adding a field

- **Adding a new field to a settings schema (`get_schema()` in any `SSA_*_Settings` class) does NOTHING for existing installs unless you also bump that schema's `version` (the `'YYYY-MM-DD'` string).** `SSA_Settings::get()` (`includes/class-settings.php`) merges schema defaults into the stored option **only when** the stored `schema_version` is *older* than the schema's `version` — if `stored >= current`, it `continue`s and skips the merge entirely. So a new field's `default_value` never reaches the ~70k sites that already saved settings under the current version; the key is simply absent from `->get()`.
- **Why it slips past local testing:** fresh installs and any site with no stored value get the full defaults, so the field "works on my machine." Only existing installs (the overwhelming majority) miss it. Bumping the `version` re-runs the merge (`array_merge(defaults, stored)` — existing values win, no data loss) and re-stamps `schema_version`.
- **Defensive read regardless:** an absent key reads as falsy, so `! empty( $settings['new_flag'] )` is a safe "off by default" even before the merge runs — but the toggle won't surface in the admin UI until the version bump makes the key present. When adding a setting that must appear in the UI, bump the version.

## Keep this file growing — ask to capture new knowledge

This file is the shared, durable memory of how SSA actually works. It only stays useful if it grows as we learn. **During any session, whenever you (Claude) learn something non-obvious about this plugin or repo — and especially when the dev explains background, the big picture, a use case, code logic, a data/flow path, a scenario, an edge case, or the reasoning behind why something is the way it is — you MUST ask the dev whether that piece of information should be added to this `CLAUDE.md` for future sessions.**

- Ask at the moment you learn it (or at a natural pause), not only at the end. Phrase it as a concrete offer, e.g. *"This explanation of how X works seems worth keeping — want me to add it to CLAUDE.md?"*
- The point of asking is twofold: it captures hard-won context so the next session doesn't re-discover it, and it serves as a standing **reminder to the dev** to keep contributing to this file. Even if the dev declines, asking does its job.
- Bias toward asking. It is better to ask about something the dev decides to skip than to silently lose knowledge that would have saved a future session hours.
- Only the dev decides what goes in — never add to this file unprompted. Ask first, then add what they approve, matching the existing style (short, concrete, with the "why" and the failure mode it prevents).
- Good candidates: anything that was surprising, anything that "only makes sense once someone explains it," anything that caused or could cause a bug, and any constraint that isn't obvious from reading the code alone.
