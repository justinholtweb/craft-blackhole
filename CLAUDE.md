# Black Hole — Craft CMS 5 Plugin

## Project Overview

Black Hole is a honeypot for bad bots. robots.txt tells every crawler to stay out of one path, an
invisible link on every page points at that path, and anything that follows the link has proved it
does not read robots.txt — so it gets blocked by IP, permanently, before Craft does any real work.

Distributed as `justinholtweb/craft-blackhole`. **Free — no editions, no licensing code.**

Reference point: the WordPress *Blackhole for Bad Bots* plugin. Same idea, done the Craft way, with
everything Blackhole Pro charges for (thresholds, custom trigger paths, per-bot hit logs, redirect,
disable-for-logged-in) included at no cost.

## Tech Stack

- **PHP 8.2+**, **Craft CMS 5.3+**, Yii2, Twig
- No build step, no front-end assets, no runtime dependencies beyond Craft's own, no outbound HTTP.
  The only network call anywhere is DNS, and only on a catch.

## Architecture

### Namespace & package

- Namespace: `justinholtweb\blackhole`
- Package: `justinholtweb/craft-blackhole`
- Handle: `blackhole`

### The load-bearing idea

**Behaviour, not claims.** Blocking is always by address, never by user agent, because a user agent
is a string the client picked and a request is a fact. The one place claims are considered is the
whitelist, which is exactly why the whitelist verifies them by forward-confirmed reverse DNS.

### Services

- `guard` — the door. `Application::EVENT_BEFORE_REQUEST`, one ban lookup, deny and `end()`.
- `trap` — the link, the URLs, eligibility, and springing the trap on a visitor.
- `bots` — the ledger. The only thing that reads or writes `blackhole_bots` / `blackhole_hits`.
- `whitelist` — address patterns, user-agent substrings, FCrDNS with a day-long cache.
- `robots` — the directives, what `web/robots.txt` actually says, and writing them in.
- `notifier` — the alert, latched on `notifiedAt` so it fires once per address ever.

### Tables

`blackhole_bots` (unique on `ip` — one row per address is the whole model) and `blackhole_hits`
(cascading key, pruned by retention).

## The order of the checks in `Guard::check()` is the design

Ban lookup first, whitelist and logged-in test after. Asking whether someone is logged in **starts
a session**, a session means a `Set-Cookie` on every response, and a `Set-Cookie` on every response
is a full-page cache that never hits again. A plugin has no business doing that to a site on the
off chance the reader is a robot. So the session is only touched once the address is already banned.

## Traps found while building this

- **`strtotime()` on a Craft datetime column is a timezone bug waiting to happen.** Craft stores
  UTC with no zone on the string; `strtotime()` reads a bare string in PHP's *default* timezone.
  On the harness (America/Los_Angeles) a ban that lapsed an hour ago read as lapsing seven hours
  from now — so temporary bans lasted the wrong length, by an amount that depends on where the
  server is, and permanent bans looked fine the whole time. `DateTimeHelper::toDateTime()` assumes
  UTC for a zone-less string, which is the assumption the column was written under. See
  `Bots::timestamp()`.
- **Never build a robots.txt path by parsing `UrlHelper::siteUrl()`.** On a site with
  `omitScriptNameInUrls` off, `siteUrl('blackhole')` is `/index.php?p=blackhole`, and the parsed
  path is `/index.php` — so the generated directive would be `Disallow: /index.php`, which takes
  the entire site out of the index. `Trap::robotsPath()` composes the site's base path with the
  trap path instead and never looks at the URL format.
- **`Disallow` is a prefix match, so no trailing slash.** `Disallow: /blackhole` covers
  `/blackhole`, `/blackhole/` and `/blackhole/anything` — every form a crawler might mangle the
  link into. `Disallow: /blackhole/` covers only the last, and the link itself would sit outside
  the rule the whole plugin depends on.
- **A whitelist matched on user agent alone is decorative.** "Googlebot" is the single easiest
  string in the world to type. The integration checks assert that a spoofed Googlebot from a
  documentation-range address is *not* whitelisted; that check is the plugin's reason to exist over
  the WordPress original.
- **The forward half of FCrDNS is the half that matters.** Anyone who controls the reverse zone for
  their own address block can point a PTR record at `googlebot.com`. They cannot make
  `googlebot.com`'s own DNS agree. Checking only the PTR name is worse than not checking.
- **`gethostbynamel()` is IPv4-only**, so confirming an IPv6 crawler needs `dns_get_record()` —
  which is disabled on some shared hosts, hence the fallback rather than a choice between them.
- **An on-site redirect for blocked requests loops**, because that request is blocked too.
  `Guard::redirectIsSafe()` ignores same-host redirects and logs once rather than letting the site
  fail in a way nobody would attribute to this plugin.
- **A flood needs a write budget.** A blocked bot speeds up rather than going away, so
  `noteBlockedAttempt()` is one arithmetic `UPDATE` on the hot path and gates the history row
  behind a 60-second cache lease. Counting every attempt is worth one cheap `UPDATE`; writing a row
  for each one turns a bad crawler into a disk-space problem.
- **Craft's editable table posts rows, not strings** — `Settings::validateList()` normalises
  `['value' => …]` in the validator, and needs `skipOnEmpty => false`, or clearing a table is the
  one case the normaliser never sees. Same family as the Fold gotcha.
- **The plugin's own site pages render in `TEMPLATE_MODE_CP`.** `blackhole/_site/*` are plugin
  templates being served to the front end, so `renderTemplate($name, $vars, View::TEMPLATE_MODE_CP)`
  is required; author overrides render in `TEMPLATE_MODE_SITE`.
- **Serving `robots.txt` must never shadow a real file.** The route is only registered when the
  setting is on *and* `web/robots.txt` does not exist.

See also `[[craft-plugin-gotchas]]` in the shared memory for family-wide traps.

## Testing

No local PHP on this Mac. Everything runs inside the plugin-testing container:

```sh
docker exec -w /var/www/html ddev-plugin-testing-web php /var/www/craft-blackhole/tests/integration/checks.php   # 77 checks
docker exec ddev-plugin-testing-web bash -c 'find /var/www/craft-blackhole/src -name "*.php" -print0 | xargs -0 -n1 php -l'
```

The checks are idempotent and self-cleaning. Every address they touch is in a documentation range
(RFC 5737 `203.0.113.0/24`, RFC 3849 `2001:db8::/32`) so it can never collide with a real caught
bot, they sweep up strays from a run that died mid-way, and they put every setting they changed
back at the end.

Live end-to-end, against the harness:

```sh
curl -sk -A 'EvilScraper/1.0' https://plugin-testing.ddev.site/blackhole   # 403, caught
curl -sk -A 'EvilScraper/1.0' https://plugin-testing.ddev.site/            # 403, blocked
docker exec -w /var/www/html ddev-plugin-testing-web php craft blackhole/bots/purge --interactive=0
```

`ddev exec` runs with `set -u`, so `docker exec ddev-plugin-testing-web …` is more reliable for
scripted work, and the Bash tool's working directory persists between calls — `cd` to the repo
explicitly.

## Coding conventions

- `Craft::t('blackhole', '…')` for user-facing strings; `src/translations/en/blackhole.php` lists
  them all
- Business logic in services; controllers stay thin
- Never nest a `<form>` in a CP template
- Never mark plugin settings `required` — it blocks fresh installs
- The guard fails open. A broken check must never take a site down for everyone.
