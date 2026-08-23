---
title: Configuration
slug: configuration
order: 20
summary: Every setting, what it costs, and the two you should think hardest about.
---

Everything lives at **Settings → Plugins → Black Hole**. The defaults are the ones to ship with;
this page is for when you want to know why.

## The trap

| Setting | Default | What it does |
| --- | --- | --- |
| **Enabled** | on | The master switch. Off means nothing is trapped and nothing is blocked; everything already in the ledger stays there. |
| **Trap path** | `blackhole` | Where the honeypot lives, relative to the site root. |
| **Add the link automatically** | on | Splice the hidden link into every front-end page. |
| **Link warning** | *see below* | The link's `title` attribute. |

Change **Trap path** to anything routable that is not inside the control panel. The robots.txt
directives change with it — so re-run `php craft blackhole/robots/write` afterwards, because the
old `Disallow` line is now pointing at nothing and the new path is not covered by anything. The
settings screen will tell you that has happened.

The **Link warning** is not decoration. It is the sentence that makes a ban defensible:

> Do NOT follow this link or you will be banned from this site!

Anything that reached the trap did so after being told twice not to — once in `robots.txt`, once in
the link's own title — and a third time by `rel="nofollow"`.

## Who gets banned, and for how long

| Setting | Default | What it does |
| --- | --- | --- |
| **Trips before a ban** | 1 | How many times an address must reach the trap before it is banned. |
| **Ban length** | 0 | Days a ban lasts. `0` means forever. |
| **Never catch logged-in users** | on | |

**Trips before a ban** at `1` is the honeypot's whole premise: there is no innocent reason to be at
that URL. Raise it to 2 or 3 if you would rather put an address on notice first — it is recorded
either way, and shows in the control panel as *on notice* until it trips the threshold.

**Leave "Never catch logged-in users" on.** One staff machine with an over-eager link-prefetching
browser extension can otherwise lock out the whole office.

## The allowlist

| Setting | Default | What it does |
| --- | --- | --- |
| **Whitelisted addresses** | — | Exact address, dotted prefix, or CIDR range. |
| **Whitelisted user agents** | ~40 entries | Case-insensitive substrings. |
| **Verify good bots** | on | Forward-confirmed reverse DNS for the crawlers that publish it. |
| **Resolve hostnames** | on | Reverse DNS on caught addresses, for the record and the alert. |

Addresses accept three forms, IPv4 and IPv6 alike:

```
203.0.113.7          exact
203.0.113.           dotted prefix
203.0.113.0/24       CIDR
2001:db8::/32        CIDR, IPv6
```

Matching is done on packed bytes, so `2001:db8::1` and
`2001:0db8:0000:0000:0000:0000:0000:0001` are correctly the same address.

### Verify good bots

**Turn this off and the user-agent allowlist becomes decorative.** "Googlebot" is the single
easiest string in the world to type, and an allowlist matched on it alone is a door with a sign on
it instead of a lock.

With it on, a visitor claiming to be one of the crawlers that documents reverse DNS — Google, Bing,
Yahoo, Yandex, Baidu, Apple — has its address resolved to a hostname, the hostname checked against
the operator's domain, and that hostname resolved back to see the same address again. Anyone can
point a PTR record at `googlebot.com` for their own address block; nobody can make Google's own DNS
agree. A claim that fails the check is not whitelisted at all.

Crawlers that publish a fixed address list instead of reverse DNS — DuckDuckBot among them — are
whitelisted on their user agent like the rest.

The check costs two DNS lookups and only ever runs when something has already reached the trap.
Ordinary requests never touch it. Results are cached for a day.

## What a blocked request gets

| Setting | Default | What it does |
| --- | --- | --- |
| **Blocked status code** | 403 | 401, 403, 404, 410, 418, 429, 451 or 503. |
| **Blocked message** | *sensible default* | Shown on the block page. |
| **Caught message** | *sensible default* | Shown on the trap page. |
| **Blocked template** | — | A site template to render instead of the built-in page. |
| **Caught template** | — | Likewise, for the trap. |
| **Redirect blocked requests to** | — | Must be off-site. |

`410 Gone` is worth considering over `403`: it tells a crawler the URL is never coming back, and
some of them stop asking.

**The redirect must be off-site.** An on-site redirect loops, because the redirected request is
blocked too. Black Hole ignores same-host redirects and logs once, rather than letting the site
fail in a way nobody would attribute to this plugin.

## Alerts and retention

| Setting | Default | What it does |
| --- | --- | --- |
| **Email alerts** | off | One email the first time an address is banned. |
| **Alert recipients** | — | Empty means every active admin. |
| **Serve robots.txt** | off | Only takes effect when there is no `web/robots.txt`. |
| **Keep hit history for** | 90 days | `0` keeps everything. |

Alerts are latched per address, so a bot that spends the night hammering the site cannot spend the
night emailing you. Releasing an address and catching it again does not re-arm the latch — it fires
once per address, ever.

Retention prunes the per-request history on Craft's garbage collector. The ban itself is never
pruned while it is in force; only a released address is eventually forgotten entirely.

## Permissions

Two, under **Black Hole** in the user group settings:

- **View caught bots** — read the list and the detail screens
- **Block, release and delete bots** — nested under it: release, whitelist, delete, block by hand, empty the hole

Admins have both. The console commands are not gated by either, because reaching a console is
already the higher permission.

## Project config

Plugin settings are stored in project config like any other plugin's, so they travel between
environments with `php craft up`. The caught addresses are **not** — they are content, they live in
the database, and staging's bots are not production's.
