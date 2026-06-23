# Simply Schedule Appointments — project notes

## Install-base scale

This plugin is installed on ~70,000 WordPress sites. Every change reaches that population. Default to the safer option in any security/UX or correctness/convenience trade-off, even when it costs more code or a worse local DX. A regression that ships at this scale is expensive in support load, reputation, and remediation; the cost of writing a few dozen extra LOC is not.

**Prefer surgical, minimal changes over refactors.** At this install base, blast radius matters more than code beauty. Narrow the fix to the actual bug or feature, leave surrounding code alone, and resist the urge to "clean up while you're here." A small targeted change is easier to review, easier to revert, and less likely to surprise the 70k sites that didn't ask for the cleanup. Refactors are a separate, deliberate decision — not a side effect of a bug fix.

## Auth & permission rules

- **Static site-wide tokens are fine as a "you're a real booking-page visitor" signal; they are not fine as an authorization fallback on any route that isn't fully public.** A static site-wide string can't differentiate callers, so it can't decide "authorized vs unauthorized" on a route admins also use. If you reintroduce one, it must only be honored by a permission check wired exclusively to routes safe for any unauth visitor.

- **Permission gates must default to strict.** A helper that "usually" requires real auth but accepts a weaker fallback under some conditions is a trap — the next person to add a route will not notice. If a route genuinely needs to be reachable by unauth booking-page visitors, name the check after that fact (e.g. `public_booking_permissions_check`) so attaching a route to it is a deliberate, reviewable act. Broader access means a *different* check, not a relaxation of an existing strict one.

## Releases, version bumps, and changelog

- **Don't touch the changelog, plugin version, or release metadata manually.** Releases, version bumps in the plugin header / `readme.txt` / `package.json`, and changelog entries are handled by automation (the `[version-bump]` commit flow visible in `git log`). Adding a manual version bump or changelog line in a feature/fix PR will collide with that automation and produce a wrong-shaped release. Land the code change; let the release pipeline write the version and changelog.
- **The one exception is a model's `$version` constant used to trigger a DB migration.** When a model class uses its `$version` property to gate a schema/data migration (compared against the stored version to decide whether to run the migration), bumping that constant *is* the migration trigger and must be done by hand as part of the change. That bump is unrelated to the plugin release version — it's a per-model migration marker, not a release artifact.
