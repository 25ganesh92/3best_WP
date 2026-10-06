# project/ — development documentation and tooling

**Not part of the running application. Exclude this directory when deploying.**

Nothing in WordPress, the `3best` theme or the `3best-core` plugin loads anything
here. It exists because these three artefacts previously survived only in a
Windows temp directory, where they could have been cleared at any time.

## Contents

| Path | What it is |
|---|---|
| `docs/deployment-runbook.html` | The ordered 15-stage checklist for taking 3Best from local XAMPP to cPanel. Phase 11. Tick state is stored in the page itself. |
| `tools/migrate-pages.php` | One-off script that moved Home, About, How-it-works and Services from PHP templates into editable block content. Already run on 1 Oct 2026. Idempotent. |
| `tools/legacy-templates/` | The four theme templates as they were *before* that migration, kept as a reference for what the block content must reproduce. Not loadable by the theme from here. |

## Safety

`.htaccess` denies all, and each directory carries an `index.php` to silence
listing — the same pattern as `wp-content/plugins/3best-core/tests/`. Verified
returning 403 over HTTP.

This matters most for `tools/migrate-pages.php`: it loads WordPress, assumes the
first user and rewrites four pages. Unlike the test suites it has **no CLI guard
of its own**, so the `.htaccess` is the only thing keeping it off a URL. If it is
ever moved, re-check that. Run it only from the command line, and only
deliberately.

## Related

`wp-content/plugins/3best-core/tests/` holds the four regression suites and is
likewise development-only. Exclude that when deploying too.
