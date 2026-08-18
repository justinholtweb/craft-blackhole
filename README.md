# Black Hole

A honeypot for bad bots, for Craft CMS 5.

The mechanism is one sentence long. **robots.txt tells every crawler to stay out of one path, an
invisible link on every page points at that path, and anything that follows the link has proved it
does not read robots.txt.**

Well-behaved crawlers never see the trap. Humans never see the link. Everything that arrives at the
trap URL got there by ignoring an explicit instruction — so it gets blocked, and every request it
makes afterwards is turned away before Craft does any real work.

Free. No editions, no licensing code.

If you know the WordPress plugin *Blackhole for Bad Bots*, this is the same idea, done the Craft
way — and everything Blackhole Pro charges for (hit thresholds, custom trigger paths, per-bot hit
logs, a redirect option, disabling for logged-in users) is here at no cost.

---

## Install

```sh
composer require justinholtweb/craft-blackhole
php craft plugin/install blackhole
```

Then do the one thing the plugin cannot do for you:

```sh
php craft blackhole/robots/write
```

That appends the trap's directives to `web/robots.txt`, creating the file if there isn't one, and
does nothing if they're already there. If you'd rather paste them yourself, the plugin's settings
screen shows the exact lines and tells you whether your live `robots.txt` currently contains them.

```
# Black Hole for bad bots — do not remove
User-agent: *
Disallow: /blackhole
```

**This step is not optional.** Without it, following the hidden link isn't disobedience — it's just
crawling, and you'd be banning honest crawlers for doing their job. The control panel puts a
warning at the top of the list until it sees the rule in your file.

That's the whole setup. The hidden link is injected into every front-end page automatically.

---

## How it works

### The link

```html
<a href="https://example.com/blackhole"
   title="Do NOT follow this link or you will be banned from this site!"
   rel="nofollow" style="display: none;" aria-hidden="true" tabindex="-1">&nbsp;</a>
```

`display:none` hides it from readers. `rel="nofollow"` is the second explicit instruction not to
follow it. `aria-hidden` and `tabindex="-1"` keep it out of the accessibility tree and out of the
tab order, so a screen reader never announces the trap and a keyboard user can't walk into it — a
honeypot that catches people is a bug.

It's spliced in immediately before the page's last `</body>`. Turn **Add the link automatically**
off and place it yourself instead:

```twig
{{ craft.blackhole.link }}
```

Placing it by hand is worth doing if you want it high in the markup, where a crawler that gives up
halfway down a long page still finds it. If the plugin sees the trap URL already on the page, it
leaves the page alone.

### The trap

Anything that requests `/blackhole` — or anything under it, by any HTTP method — is recorded and,
by default, blocked immediately. The response explains what happened and which address it happened
to.

### The block

Every front-end request runs one lookup against the ban list before Craft routes anything. A banned
address gets a 403 (configurable), a message, and nothing else — no templates rendered, no
database queried, no session started.

---

## Who never gets caught

**Addresses on the whitelist.** Exact addresses, dotted prefixes (`203.0.113.`), or CIDR ranges
(`203.0.113.0/24`, `2001:db8::/32`). Matching is done on packed bytes, so `2001:db8::1` and
`2001:0db8:0000:0000:0000:0000:0000:0001` are correctly the same address.

**Logged-in users**, unless you turn that off. Leave it on: one staff machine with an over-eager
link-prefetching browser extension can otherwise lock out the whole office.

**Control panel requests**, always. Not a setting.

**Whitelisted user agents** — Googlebot, Bingbot, Slurp, YandexBot, Baiduspider, Applebot,
facebookexternalhit, Slackbot, UptimeRobot and about thirty more, out of the box.

### About that last one

A user agent is a string the client chose. "Googlebot" costs a scraper nothing to type, and an
allowlist matched on it alone is a door with a sign on it instead of a lock.

So Black Hole does what Google, Bing, Yahoo, Yandex, Baidu and Apple all document:
**forward-confirmed reverse DNS.** A visitor claiming to be Googlebot has its address resolved to a
hostname, the hostname checked against `googlebot.com`, and that hostname resolved back to see the
same address again. Anyone can point a PTR record at `googlebot.com` for their own address block;
nobody can make Google's own DNS agree.

A claim that fails the check isn't whitelisted at all — and if it also followed the hidden link,
it's caught.

Only crawlers whose operators actually publish reverse DNS are asked to prove anything. DuckDuckBot
publishes a fixed address list instead, so it's whitelisted on its user agent like the rest.

The check costs two DNS lookups, and it only ever runs when something has already reached the trap.
Ordinary requests never touch it. Results are cached for a day.

---

## Settings

| Setting | Default | What it does |
| --- | --- | --- |
| **Enabled** | on | Master switch. Off stops trapping and blocking; the ledger is untouched. |
| **Trap path** | `blackhole` | Where the honeypot lives. Change it and the robots.txt directives change with it. |
| **Add the link automatically** | on | Splice the hidden link into every front-end page. |
| **Link warning** | *(see above)* | The link's `title`. It's the reason a ban is defensible. |
| **Trips before a ban** | 1 | How many times an address must reach the trap. |
| **Ban length** | 0 (forever) | Days a ban lasts. |
| **Never catch logged-in users** | on | |
| **Resolve hostnames** | on | Reverse DNS on caught addresses, for the record and the alert. |
| **Whitelisted addresses** | — | Exact, prefix or CIDR. |
| **Whitelisted user agents** | ~40 entries | Case-insensitive substrings. |
| **Verify good bots** | on | Forward-confirmed reverse DNS for the crawlers that publish it. |
| **Blocked status code** | 403 | 401, 403, 404, 410, 418, 429, 451 or 503. |
| **Blocked / caught message** | *(sensible defaults)* | |
| **Blocked / caught template** | — | Site templates to render instead of the built-in pages. |
| **Redirect blocked requests to** | — | Must be off-site; an on-site redirect would loop, so it's ignored. |
| **Email alerts** | off | One email the first time an address is banned, and never again for it. |
| **Alert recipients** | — | Empty means every active admin. |
| **Serve robots.txt** | off | Only takes effect when there's no `web/robots.txt`. |
| **Keep hit history for** | 90 days | Craft's garbage collector does the pruning. |

---

## The control panel

**Black Hole → Caught bots** lists every address the trap has taken, filterable by status
(blocked / on notice / released), searchable by address, hostname, user agent or requested URL, and
sortable on any of the columns that matter.

Each row opens onto everything recorded about that address — hostname, user agent, what it asked
for, where it came from, how many times it tripped the trap and how many times it's been turned
away since — plus its request history. You can release it, whitelist it, delete it, or block an
address by hand that never came near the trap.

---

## Twig

```twig
{{ craft.blackhole.link }}                  {# the hidden anchor #}
{{ craft.blackhole.link({ text: '.' }) }}   {# text, title, class, url, siteId #}
{{ craft.blackhole.trapUrl() }}
{{ craft.blackhole.robots() }}              {# the directives, for a robots.txt template #}
{{ craft.blackhole.isBlocked() }}           {# defaults to the current visitor #}
{{ craft.blackhole.isBlocked('203.0.113.7') }}
{{ craft.blackhole.counts() }}              {# { blocked: n, warned: n, released: n, all: n } #}
```

If your site renders `robots.txt` from a template, drop the directives straight in:

```twig
{{ craft.blackhole.robots() }}
```

---

## Console

```sh
php craft blackhole/robots/show              # the directives, and whether your file has them
php craft blackhole/robots/write             # add them to web/robots.txt (idempotent)

php craft blackhole/bots/list                # --status= --search= --limit=
php craft blackhole/bots/block 203.0.113.7   # --note=
php craft blackhole/bots/release 203.0.113.7
php craft blackhole/bots/delete 203.0.113.7
php craft blackhole/bots/purge
php craft blackhole/bots/prune               # apply the retention window
```

---

## Templates

Point **Blocked template** or **Caught template** at a site template to replace the built-in pages.

The blocked template gets `ip`, `message`, `userAgent`, `requestUri` and `statusCode`.
The caught template gets `caught`, `bot`, `ip`, `message`, `blocked`, `hits`, `threshold` and
`reason`.

If your template throws, the built-in page is served instead — a blocked request still has to end
with something sent.

---

## Things worth knowing

**A reverse-proxy cache sits in front of PHP.** Blitz, Varnish, Cloudflare's page cache and their
relatives all answer before Craft boots, so a banned bot can still be served a cached page. The
trap URL and the block page are both marked `no-store`, so those are never cached themselves, but
the rest of the site is between you and your cache. The WordPress original's advice is "don't use
this with caching"; the more useful version is that the trap and the ledger work fine, and only the
blocking of *cached* pages is affected.

**Get the real address.** Behind a proxy or a CDN, Craft needs to be told which header carries the
client address, or every visitor looks like your load balancer. That's `ipHeaders` in
`config/general.php` — Black Hole uses whatever Craft resolves, so configure it there once and
everything agrees.

**Blocking is always by address.** A user agent is a claim; a request is a fact. Nothing here bans
a user agent, and the only place claims are considered at all is the whitelist — which is exactly
why the whitelist verifies them.

**The addresses are personal data in some jurisdictions.** They're processed to keep the site up,
kept as long as your retention setting says, and deletable from the control panel or with
`blackhole/bots/purge`. Uninstalling the plugin drops both tables.

**Change the trap path if you like.** Anything routable that isn't inside the control panel. Change
it and re-run `blackhole/robots/write` — the old directive goes stale, and the settings screen will
tell you so.

---

## Requirements

Craft CMS 5.3+, PHP 8.2+. No runtime dependencies beyond Craft's own, no build step, no external
services.

---

## License

See [LICENSE.md](LICENSE.md).
