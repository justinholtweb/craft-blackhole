---
title: Troubleshooting
slug: troubleshooting
order: 40
summary: Nothing is being caught, the wrong things are, and the caching caveat everyone hits.
---

## Nothing is being caught

Work down this list:

1. **Is the plugin enabled?** The master switch at the top of the settings screen.
2. **Is the link on the page?** View source on a front-end page and search for the trap path. If it
   is not there, either **Add the link automatically** is off, or the page has no `</body>` for the
   plugin to splice before — a JSON or XML response, for instance, which is left alone on purpose.
   Only `text/html` responses are decorated.
3. **Are you logged in?** With **Never catch logged-in users** on, you cannot catch yourself. Try it
   in a private window.
4. **Is your address whitelisted?** Check **Whitelisted addresses**, and remember that a dotted
   prefix like `203.0.113.` covers the whole block.
5. **Is your user agent whitelisted?** The list ships with about forty substrings, matched
   case-insensitively. Anything containing `bot` is not automatically exempt, but plenty of real
   strings do contain a listed substring.

Then request the trap directly, as something that is obviously not a browser:

```sh
curl -A 'TestScraper/1.0' https://example.com/blackhole
```

If that does not produce a row in **Black Hole → Caught bots**, the plugin is not seeing the request
at all — check that nothing in front of PHP is answering `/blackhole` first.

## Everything is being caught, including me

Almost always one of two things:

**A proxy or CDN is hiding the real address.** Every visitor arrives looking like your load
balancer, so the first catch bans everyone. Set `ipHeaders` in `config/general.php` to whichever
header actually carries the client address (`CF-Connecting-IP` behind Cloudflare,
`X-Forwarded-For` behind most others) and Craft — and therefore Black Hole — will resolve it
correctly. Then `php craft blackhole/bots/purge` to clear the damage.

**A browser extension is prefetching links.** Link-prefetchers follow `display:none` anchors
happily. `rel="nofollow"` is supposed to stop them and does not always. This is exactly what
**Never catch logged-in users** exists for; leave it on.

If you have locked yourself out of the front end, the control panel is never blocked — that is not
a setting — so you can always get in and release yourself. Or from a shell:

```sh
php craft blackhole/bots/release 203.0.113.7
```

## Googlebot got caught

If it really was Googlebot, it followed a `Disallow`ed link, which Google does not do. So check, in
this order:

1. **Does your live `robots.txt` actually contain the directives?** Run
   `php craft blackhole/robots/show`. A file that was replaced by a deploy, or a `robots.txt`
   rendered from a template that nobody added `{{ craft.blackhole.robots() }}` to, is the usual
   cause. So is a stale directive left behind after the trap path was changed.
2. **Open the row and look at the hostname.** With **Resolve hostnames** on, a real Googlebot
   resolves to something under `googlebot.com` or `google.com`. A scraper wearing the name resolves
   to a datacenter or to nothing at all.
3. **Is "Verify good bots" on?** With it off, the user-agent allowlist is taken at face value and a
   real crawler is exempt for the same reason a fake one is — but that is not what caught it.

A caught crawler that verifies is worth reporting; a caught crawler that does not is the plugin
doing its job.

## A whitelisted crawler is still being blocked

The allowlist is checked when something reaches the trap, not when something that is **already
banned** makes a request. If an address was banned before you whitelisted it, the ban is still
there. Release it:

```sh
php craft blackhole/bots/release 203.0.113.7
```

Using **Whitelist** on the detail screen does both at once — it releases the address and adds it to
**Whitelisted addresses**.

## The block page never appears — a cached page is served instead

This is the real limitation, and it is not fixable from inside the plugin.

Blitz, Varnish, Cloudflare's page cache and their relatives all answer before Craft boots. A banned
bot asking for a cached URL gets the cached response, and the guard never runs. The trap URL and
the block page are both marked `no-store`, so those are never cached themselves — but the rest of
the site is between you and your cache.

The WordPress original's advice is "don't use this with caching". The more useful version: the
trap, the ledger, the alerts and the console all work exactly as documented, and only the
*blocking* of *already-cached* pages is affected. Most sites still want it. If you need the ban
enforced ahead of the cache, export the blocked addresses and feed them to whatever sits in front —
a firewall rule, a Cloudflare IP list, an nginx `deny`.

## A released address came back and was banned immediately

That is intended. A released address starts its hit count again from zero, so with **Trips before a
ban** at 1, the next trip re-bans it. A second chance really is one more trip, not a permanent
exemption.

## The trap path changed and everything broke

Changing **Trap path** changes the directives, and the ones already in your `robots.txt` are now
pointing at a path that no longer traps anything. Re-run:

```sh
php craft blackhole/robots/write
```

Then remove the stale `Disallow` line by hand — `write` adds, it does not clean up after an old
path it has no record of. The settings screen shows you the current directives and whether your
file matches.

## Where to look when nothing else explains it

Craft's logs, category `blackhole`:

```sh
tail -f storage/logs/web.log | grep blackhole
```

The guard fails open by design — a broken check must never take a site down for everyone — so a
problem inside it shows up as a log line and a request that was let through, not an error page.
