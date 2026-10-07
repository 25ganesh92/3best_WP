# 3Best

3Best is a lead-generation platform for local services. A visitor chooses the
service they need and the place they need it, answers a short set of questions,
verifies their mobile number, and submits a single request — instead of opening
a dozen tabs and calling businesses one at a time.

The platform is built around service/location pages: a dedicated, indexable page
for each approved combination of a service and a place, each carrying its own
generated copy and its own request form.

---

## Project Status

> ### DEMO BUILD — FOR CEO / PRODUCT REVIEW
>
> **This repository is an implementation checkpoint for review. It is NOT
> production-ready and is not deployed anywhere.**

No production integration is connected. Phone verification, lead delivery and
location lookup all run against development providers or are switched off.
Remaining integrations, production hardening and deployment will proceed **only
after product and implementation approval**.

The verified state of this checkpoint is commit
`b00c8db010fcfe68e5e98bfdbb98065a84016149`.

---

## What 3Best Does

The customer journey this build demonstrates:

1. **Search for a local service** — choose from the service catalogue on the
   homepage or the services page.
2. **Enter a location** — a city, state or ZIP code, resolved against a location
   catalogue with autocomplete.
3. **Land on a service/location page** — a page built for that service in that
   place, with its own copy, benefits, FAQ and request form.
4. **Describe the job** — pick the closest request type from the service's
   subcategories, then give name, email, mobile number and the address where the
   work is needed.
5. **Verify the mobile number** — a six-digit code confirms the request is
   genuine before it is accepted.
6. **Submit the request** — the lead is stored first, then queued for delivery to
   whoever will act on it.

**What the product does not yet do:** 3Best does not currently match, rank,
select or display actual service providers, and no provider directory exists.
"Your 3 best matches" describes the product direction, not implemented
behaviour. There is no payment, booking, quoting or messaging functionality.

---

## Current Implementation

### Implemented

Verified to exist and to work in this repository.

- **Service catalogue** — 16 services across 4 categories, with 64
  subcategories. Slugs are curated by hand as the SEO surface.
- **Location system** — a catalogue of 33 areas, with city, state and ZIP
  lookup, normalisation, canonicalisation and caching.
- **Dynamic service/location pages** — `/{service}-in-{location}/` resolved from
  the URL, with generated copy, canonical URLs, 301 aliases and legacy ZIP
  redirects. 14 combinations are currently approved and indexable.
- **Lead capture** — validation, sanitisation, storage, and a consent record
  capturing the exact wording shown, its version, timestamp, IP and user agent.
- **Phone verification flow** — code hashing, single-use tokens bound to the
  phone number, attempt limits, resend cooldown and a daily send cap.
- **Rate limiting** — a dedicated table with atomic increment, failing closed on
  write paths and open on read-only search.
- **Lead delivery queue** — retry with exponential backoff, permanent-failure
  handling and stale-job reclaim, driven by WP-Cron.
- **SEO output** — title, description, canonical, Open Graph and Twitter tags,
  plus three JSON-LD graphs (BreadcrumbList, Service, FAQPage). Indexability is
  gated on the approved set.
- **Admin screens** — 3Best → Overview, Leads, SEO Pages, Settings. The lead
  list deliberately hides contact details; settings report credential *state*,
  never values.
- **Editable content architecture** — 12 server-rendered Gutenberg blocks, 7
  block patterns, and a single editable layout that every service/location page
  renders through.
- **Content pages** — Home, About, Services, How it works, Contact, Privacy
  Policy, Terms of Service.
- **Regression suites** — four suites, 438 assertions.

### Demo / Prototype

Exists to demonstrate the flow; not production behaviour.

- **Development OTP provider** — issues verification codes to the debug log and
  **sends no SMS**. Double-gated: it requires an explicit opt-in constant *and*
  a non-production environment, and refuses to run otherwise. This is the active
  provider in this build.
- **Nominatim / OpenStreetMap location provider** — fine for development, and
  the active provider in this build. Not intended for production use.
- **Lead delivery is switched off** — leads are stored and queued, but nothing
  is sent anywhere.
- **Placeholder branding and imagery** — the 3Best name, logo and copy are
  stand-ins designed to be replaced.

### Deferred

Intentionally not implemented yet.

- **Google Places location provider** — a deliberate, documented stub that
  refuses to run. The class specifies exactly what the implementation owes the
  rest of the system; the provider factory will not select it while it is
  unavailable, so the site falls back rather than failing.
- **Twilio Verify** — provider code is complete but no credentials are
  configured, so it is inactive.
- **Zapier lead delivery** — provider and queue are complete but delivery is
  disabled and no webhook is configured.
- **Provider matching and directory** — selecting and presenting the "3 best"
  providers for a request.
- **XML sitemap**, **lead export**, **a GDPR/CCPA erasure path**, and a
  **read-only admin role** below `manage_options`.
- **Production hardening and deployment** — tracked separately in
  `project/docs/deployment-runbook.html`.

---

## Technology Stack

Only what this repository actually uses.

| Layer | Technology |
|---|---|
| Language | PHP 8.1+ (developed and tested on 8.2.12) |
| CMS | WordPress 6.7+ (developed against 7.1.2) |
| Database | MySQL 5.7+ or MariaDB 10.3+, InnoDB, `utf8mb4` |
| Web server | Apache with `mod_rewrite` |
| Frontend | Hand-written CSS (one stylesheet) and vanilla JavaScript (5 files) |
| Editor | Gutenberg blocks — 12 server-rendered blocks, 7 patterns |
| Fonts | Inter, self-hosted as WOFF2 |

Required PHP extensions, enforced at plugin boot: **curl, mbstring, json,
openssl**. `intl` is deliberately *not* required.

**There is no build step.** No Composer, no Node, no npm, no bundler, no Docker.
The repository contains no `composer.json`, `package.json`, `Dockerfile` or
equivalent. The PHP and the assets run exactly as they are committed.

---

## Local Demo Requirements

- **XAMPP** (or any equivalent Apache + MySQL + PHP stack)
- **Apache** with `mod_rewrite` enabled
- **MySQL or MariaDB** with InnoDB
- **PHP 8.1 or newer** — 8.2.12 is what this build was developed and tested on
- **A WordPress 6.7+ installation** — see the important note below
- Project served from the web root, e.g. `C:\xampp\htdocs\3best`
- A current browser

---

## Local Setup

> **Important: this repository is not a complete WordPress site.**
>
> It contains the custom theme, the custom plugin and the project documentation
> — not WordPress core, not `wp-config.php`, and not the site database. You
> supply a WordPress installation and drop these two directories into it.

1. **Install WordPress 6.7+** into your web root, for example
   `C:\xampp\htdocs\3best`. Use a stock download; core is unmodified by this
   project.

2. **Clone this repository** and copy its two application directories into that
   installation, preserving the paths:

   ```
   wp-content/plugins/3best-core/
   wp-content/themes/3best/
   ```

   `project/` is development documentation and tooling. It is not loaded by
   WordPress and does not need to be deployed.

3. **Start Apache** from the XAMPP control panel.

4. **Start MySQL / MariaDB** from the XAMPP control panel.

5. **Create a database** with the `utf8mb4` character set, and a user with full
   privileges on it.

6. **Configure `wp-config.php`.** WordPress ships `wp-config-sample.php`; copy it
   and fill in your database credentials and fresh salts. This file is local-only
   and is excluded from the repository — never commit it.

   To exercise the demo verification flow you also need, in `wp-config.php`:

   ```php
   define( 'WP_ENVIRONMENT_TYPE', 'development' );
   define( 'THREEBEST_ALLOW_DEV_OTP', true );
   define( 'WP_DEBUG', true );
   define( 'WP_DEBUG_LOG', true );
   define( 'WP_DEBUG_DISPLAY', false );
   ```

   `THREEBEST_ALLOW_DEV_OTP` makes the development verification provider
   available. It must never exist in a production configuration, and the
   provider refuses to run when the environment type is `production` regardless.

   The five third-party credential constants
   (`THREEBEST_GOOGLE_MAPS_API_KEY`, `THREEBEST_TWILIO_ACCOUNT_SID`,
   `THREEBEST_TWILIO_AUTH_TOKEN`, `THREEBEST_TWILIO_VERIFY_SERVICE_SID`,
   `THREEBEST_ZAPIER_WEBHOOK_URL`) are **not needed for the demo** and should be
   left undefined.

7. **Run the WordPress installer**, then in the admin:
   - Activate the **3Best Core** plugin. Activation creates the four
     `*_threebest_*` tables, seeds the approved service/location combinations and
     the default settings, seeds the editable landing layout, and flushes
     rewrite rules. **No separate migration command is required.**
   - Activate the **3Best** theme.
   - Set **Settings → Permalinks** to `/%postname%/` and save once. This is
     required — without it the service/location URLs return 404.

8. **Create the content pages.** See the next section: this step is manual, and
   it is the one part of the demo the repository cannot reproduce for you.

9. **Open the site** in a browser, e.g. `http://localhost/3best/`.

---

## Database / Demo Content Status

**This is the most important limitation to understand before cloning.**

### What the repository can reproduce automatically

Plugin activation handles all of this with no manual steps:

- The four `*_threebest_*` database tables (schema version `1.2.0`), created in
  code via `dbDelta` — there is no SQL dump to import.
- The **14 approved service/location combinations**, seeded on activation.
- Default plugin settings.
- The single editable landing layout that all service/location pages render
  through.
- The service and location catalogues, which live in version-controlled PHP
  (`wp-content/plugins/3best-core/data/`), not in the database.

This means the **service/location pages work immediately** after activation and
a permalink flush.

### What the repository cannot reproduce

The **seven WordPress content pages** — Home, About, Services, How it works,
Contact, Privacy Policy and Terms of Service — exist only in the local
development database. They are **not** in this repository, and nothing creates
them on activation.

The related site options are also not reproduced: the static front page
assignment, the permalink structure and the search-engine visibility setting.

### Is there a seeding mechanism?

**No. There is currently no seeder or importer for the content pages.**

`project/tools/` contains three scripts that can populate page *content*, but
every one of them requires the pages to **already exist**:

| Script | Requires |
|---|---|
| `migrate-pages.php` | Pages with slugs `home`, `about`, `services`, `how-it-works`; skips any that are missing |
| `set-privacy-policy.php` | A published page at **ID 3** with slug `privacy-policy` |
| `set-terms-of-service.php` | A published page at **ID 12** with slug `terms-of-service` |

The two legal-content scripts are hardcoded to those specific page IDs, which
will not generally match a fresh WordPress installation.

### What this means for someone cloning the repository

A fresh clone gives you a working **plugin and theme**: the catalogues, the
database schema, the approved combinations, the full service/location page
system, the lead and verification flow, the admin screens and the test suites.

It does **not** give you the seven static content pages. Those must be created
by hand — with matching slugs, and matching IDs if the legal-content scripts are
to be used — before the site looks like the reviewed demo.

**Building a proper seeding/import mechanism is identified, deliberately
deferred, and not part of this checkpoint.**

---

## Routes / Current Pages

All routes below were verified against this commit. The 14 checks in the
verification run are marked ✓.

### Content pages

| Route | Expected | |
|---|---|---|
| `/` | 200 | ✓ |
| `/services/` | 200 | ✓ |
| `/about/` | 200 | ✓ |
| `/how-it-works/` | 200 | ✓ |
| `/contact/` | 200 | ✓ |
| `/privacy-policy/` | 200 | ✓ |
| `/terms-of-service/` | 200 | ✓ |

### Service/location pages

Pattern: `/{service}-in-{location}/`. 14 combinations are approved and
indexable; a valid but unapproved combination still renders, but is marked
`noindex`.

| Route | Behaviour | |
|---|---|---|
| `/plumber-in-newark-nj/` | 200 — canonical landing page | ✓ |
| `/plumber-in-newark-new-jersey/` | 301 → `/plumber-in-newark-nj/` (alias) | ✓ |
| `/plumber-in-07102/` | 301 → `/plumber-in-newark-nj/` (legacy ZIP) | ✓ |
| `/plumber-in-07030/` | 301 → `/plumber-in-hoboken-nj/` (legacy ZIP) | ✓ |
| `/wizard-in-newark-nj/` | 404 — unknown service | ✓ |
| `/no-such-page/` | 404 | ✓ |

### REST API — namespace `threebest/v1`

| Endpoint | Method | Access |
|---|---|---|
| `/wp-json/threebest/v1/locations` | GET | Public, nonce-protected, rate limited |
| `/wp-json/threebest/v1/otp/request` | POST | Public, nonce-protected, rate limited |
| `/wp-json/threebest/v1/otp/verify` | POST | Public, nonce-protected, rate limited |
| `/wp-json/threebest/v1/leads` | POST | Public, nonce-protected, rate limited |
| `/wp-json/threebest/v1/status` | GET | Capability-gated — returns **401** anonymously ✓ |

### Admin

**3Best → Overview · Leads · SEO Pages · Settings**

---

## Verification

Verified at commit `b00c8db010fcfe68e5e98bfdbb98065a84016149`, on PHP 8.2.12.

**Regression suites — 438/438 assertions passing, 0 failures:**

| Suite | Assertions | Covers |
|---|---|---|
| `suite1-portability.php` | 23 | Linux/cPanel portability, filename case, encoding, banned functions, full lint of 101 files |
| `suite2-functional.php` | 147 | Pages, catalogues, location system, SEO URLs, redirects, indexability, the full landing → verification → lead → delivery integration, schema |
| `suite3-security.php` | 138 | Access control, rate limiting, client identity, verification protection, submission bypass attempts, injection, secret and log hygiene |
| `suite4-editing.php` | 130 | Block architecture, theme tokens, patterns, editable page content, the landing layout and its fallback |

**Route checks — 14/14 correct**, as marked above.

The suites live in `wp-content/plugins/3best-core/tests/` and are development
tooling only: command-line only, refusing to run against a production
environment, not web-reachable, and not a runtime dependency. They contain no
credentials — they assert the *absence* of secrets from responses, markup and
logs. See that directory's own README before running them.

---

## Security / Demo Notes

- **This is not a production deployment.** It runs on a local development stack
  and is not hosted anywhere.
- **No production credentials or integrations are configured.** Phone
  verification uses a development provider that sends no SMS; lead delivery is
  switched off; location lookup uses a development provider; the Google Places
  provider is an unimplemented stub.
- **Local configuration must never be committed.** `wp-config.php`, the debug
  log and the uploads directory are excluded from this repository by
  `.gitignore`, and the repository contains no credentials, API keys, tokens,
  webhook URLs, salts or database dumps of any kind.
- **Credentials are read from constants** defined in `wp-config.php`, outside
  version control. Code and admin screens report whether a credential is
  configured — never its value.
- **Production deployment requires a separate hardening and configuration
  step**, documented in `project/docs/deployment-runbook.html`. That includes
  real credentials, fresh salts, HTTPS, disabling debug output, removing the
  development verification opt-in, and switching the providers over.
- **No real personal data is present.** Contact details in the test suites are
  fictional fixtures.

---

## Planned Next Steps

All future work. None of this is current functionality.

1. **CEO / product approval** of the direction and flow in this checkpoint.
2. **Provider matching and directory** — the selection and presentation logic
   behind "your 3 best".
3. **Lead delivery** — connect and test the real delivery integration end to end.
4. **Phone verification** — switch from the development provider to Twilio
   Verify and test with real numbers.
5. **Location services** — implement the Google Places provider and move off the
   development provider.
6. **Additional platform functionality** — sitemap, lead export, a data-erasure
   path, a read-only admin role, and replacing placeholder branding and imagery.
7. **Production hardening and deployment** — the full checklist in
   `project/docs/deployment-runbook.html`.

---

## Repository Structure

```
wp-content/plugins/3best-core/     All business logic. Theme-independent.
├── data/                          Service and location catalogues (PHP config)
├── includes/                      Bootstrap, schema, settings, autoloader
├── location/                      Providers, normalisation, canonicalisation
├── otp/                           Phone verification providers and service
├── leads/                         Validation, storage, consent
├── delivery/                      Delivery queue and providers
├── security/                      Rate limiting and request guards
├── seo/                           URL routing, metadata, structured data
├── rest/                          REST controllers and router
├── frontend/                      Blocks, forms, asset loading
├── admin/                         Overview, Leads, SEO Pages, Settings
└── tests/                         Four regression suites (dev tooling)

wp-content/themes/3best/           Presentation only. No business logic.
├── assets/                        One stylesheet, 5 JS files, images, fonts
├── inc/                           Setup, enqueue, template tags, patterns
├── template-parts/                Landing page sections
└── *.php                          Page templates

project/                           Development docs and tooling. Not deployed.
├── docs/                          Deployment runbook
└── tools/                         One-off content scripts, pre-migration templates
```

The split is deliberate and enforced: **all business logic lives in the plugin,
the theme contains none.** Suite 4 asserts that the blocks carry no business
logic of their own.

---

## For Review

What would be most useful to look at:

- **Product direction** — does the service/location page model, and the single
  verified request per job, match where the business should go?
- **User flow** — the journey from search, to a service/location page, to a
  verified submitted request.
- **UI** — the homepage, a service/location page such as
  `/plumber-in-newark-nj/`, and the request form.
- **Technical architecture** — the plugin/theme split, the provider pattern that
  lets verification, location and delivery backends be swapped without touching
  business logic, and the SEO URL system.
- **Implementation quality** — the four regression suites, the security posture
  (rate limiting, consent records, secret hygiene), and the fact that
  unimplemented work is an explicit, documented refusal rather than silent
  half-behaviour.
- **Proposed next-phase integrations** — the priority and sequence of provider
  matching, lead delivery, phone verification and location services.

---

**Demo build. Not production-ready. Not deployed.**
