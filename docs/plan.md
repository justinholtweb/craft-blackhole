# Black Hole — build plan

## What it is

A honeypot for bad bots, in the shape of the WordPress plugin *Blackhole for Bad Bots*, done the
Craft way.

The mechanism is one sentence long: **robots.txt tells every crawler to stay out of one path, an
invisible link on every page points at that path, and anything that follows the link has proved it
does not obey robots.txt.** Well-behaved crawlers never see the trap. Humans never see the link.
Everything that arrives at the trap URL got there by ignoring an explicit instruction.

Free. No editions, no licensing code — including the things Blackhole Pro charges for (hit
thresholds, custom trigger paths, per-bot hit logs, a redirect option, disabling for logged-in
users).

## Why not just block by user agent

Because a user agent is a string the client chooses. The whole point of a honeypot is that it
tests *behaviour*, not *claims*. Black Hole blocks by IP, and only ever after the IP has done
something no legitimate client does.

The one place claims matter is the whitelist, and that is exactly where they are dangerous: "I am
Googlebot" is the single easiest way to walk through a user-agent allowlist. So Black Hole does
what Google, Bing, Yandex, Baidu and Apple all document — **forward-confirmed reverse DNS**. A hit
claiming to be Googlebot is verified by resolving the IP to a hostname, checking the hostname's
domain, and resolving that hostname back to the IP. A spoofer fails at the first step. This only
runs when a trap is actually tripped, so it costs nothing on ordinary requests.

## Moving parts

### Tables

- `blackhole_bots` — one row per caught IP: hostname, user agent, the URI that tripped it, the
  referrer, hit count, status, first/last seen, ban expiry, note.
- `blackhole_hits` — one row per request from a caught IP, trap trip or blocked attempt, with a
  cascading key. Pruned by retention.

### Services

- `guard` — the interceptor. Runs at `Application::EVENT_BEFORE_REQUEST`, answers one question
  (is this IP banned?) off a cached map, and if so serves the block page and ends the request.
- `trap` — the honeypot endpoint's brain: eligibility, whitelisting, catching, thresholds.
- `bots` — the store. Finding, blocking, releasing, deleting, pruning, counting.
- `whitelist` — IP patterns (exact / prefix / CIDR v4 + v6), user-agent substrings, and FCrDNS
  verification with a cache.
- `robots` — the directives, and what the site's `web/robots.txt` currently says about them.
- `notifier` — the email alert, once per catch.

### Front end

- `craft.blackhole.link` — the hidden anchor. Auto-injected before the last `</body>` unless the
  page already contains the trap URL, so placing it by hand wins.
- The trap route, plus everything under it, so `/blackhole/anything` still trips.
- The block response: a status code, a message, an overridable site template, or a redirect.

### Control panel

- Caught bots index — search, status filter, sort, pagination, bulk empty.
- Per-bot detail with the full hit log, and release / block / whitelist / delete.
- Settings, with a live read of what `web/robots.txt` actually contains.

### Console

- `blackhole/bots` — list, block, release, delete, purge, prune.
- `blackhole/robots` — show the directives, write them into `web/robots.txt`.

## Order of work

1. Composer, plugin class, install migration, settings model. ✅
2. Records, IP helper, whitelist service. ✅
3. Trap service + site controller + the two front-end templates. ✅
4. Guard + response injection. ✅
5. Bots store, CP controller, CP templates. ✅
6. Robots service, controller, console commands. ✅
7. Notifier + garbage collection. ✅
8. Twig variable, translations, icons. ✅
9. Integration checks, README, CHANGELOG, CLAUDE.md. ✅

## Things to be careful about

- **The session is expensive.** Checking whether someone is logged in starts a session, and a
  session means a `Set-Cookie` on every response, which defeats full-page caching. So the guard
  checks the ban list *first* and only asks about the user once the IP is already banned.
- **A reverse-proxy cache sits in front of PHP.** Blitz, Varnish and Cloudflare all answer before
  Craft boots, so a banned bot can still be served a cached page. The trap URL and the block page
  are marked `no-store` so at least they are never cached themselves; the rest is documentation.
- **`gethostbyaddr()` blocks.** It only ever runs on a trap trip, and the result is cached.
- **Never trap the control panel.** No CP request, no console request, and never a logged-in user
  unless the setting says otherwise.
