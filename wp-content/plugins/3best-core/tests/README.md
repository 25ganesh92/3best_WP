# 3Best Core — regression suites

Development tooling. **Not part of the running application and not for production.**

## Running

From the command line, with the local site reachable over HTTP:

```
php wp-content/plugins/3best-core/tests/suite1-portability.php
php wp-content/plugins/3best-core/tests/suite2-functional.php
php wp-content/plugins/3best-core/tests/suite3-security.php
```

Each exits `0` on success and `1` if any assertion failed.

| Suite | Covers |
|---|---|
| `suite1-portability.php` | Linux/cPanel portability: autoloader filename case, byte-order marks, UTF-8, path separators, hard-coded table prefixes, banned functions, ext-intl independence, plugin header, full lint |
| `suite2-functional.php` | Pages and theme, service catalogue, location system, dynamic SEO URLs, canonical and redirect behaviour, indexability, the full landing → OTP → lead → delivery integration, delivery queue success/retry/backoff/permanent/stale, schema and indexes |
| `suite3-security.php` | Access control, rate limiting and failure modes, client identity, OTP protection, lead-submission bypass attempts, request size, injection, secret and log hygiene, admin screens |

## Why these cannot hurt anything

Four independent guards, because any one could be defeated by a hosting quirk
and the consequence would be a test harness mutating live data.

1. **Command line only.** Every entry point exits `403` on a web request before
   anything else runs — independent of what the web server does with `.php`
   files in this directory.
2. **Never against production.** `bootstrap.php` refuses to continue when
   `wp_get_environment_type()` is `production`. WordPress defaults to
   `production` when nothing says otherwise, so the safe answer is the default.
3. **Not a runtime dependency.** Nothing in `3best-core` references this
   directory. The autoloader maps only `class-`, `interface-` and `trait-`
   prefixed files, which none of these are. A normal WordPress request never
   opens them.
4. **Not web-reachable.** `.htaccess` denies all, and `index.php` silences
   directory listing.

They also contain **no credentials and no secrets**. The suites assert the
*absence* of credentials from responses, markup and logs; they never supply one.

## State handling

Each suite parks what it touches and restores it on shutdown — the settings
option, the service/location rows, and the delivery state of pre-existing leads.
Rows the suites create are identified by their own values (a `p8a+`/`p8b+`
email, a `555-08xx`/`555-09xx` phone), never by "the newest row". Every
outbound HTTP request that is not to this site is blocked and counted, so no
SMS is sent and no webhook is called.

The development OTP records are never deleted.

## Deployment

**Exclude this directory when deploying.** The guards make it safe if it ships,
but it is development tooling and has no reason to be on a production host.
