---
title: FAQ
slug: faq
order: 50
summary: Is it really free, will it ban real people, and what about caching.
---

## Is Black Hole really free?

Yes. No editions, no licensing code, no trial. Everything the WordPress original's paid tier
charges for — hit thresholds, a custom trigger path, per-bot hit logs, a redirect option, disabling
for logged-in users — is here at no cost.

## Will it ban real visitors?

It should not, and the design goes out of its way to make sure. The link is `display:none`,
`aria-hidden` and `tabindex="-1"`, so it is not in the accessibility tree and not in the tab order:
a screen reader never announces it and a keyboard user cannot walk into it. **A honeypot that
catches people is a bug.**

The realistic risk is a link-prefetching browser extension on a staff machine, which is why
**Never catch logged-in users** is on by default. Leave it on.

## Will it ban Google?

Not if your `robots.txt` has the directives, because Google honours `Disallow`. That is the one
setup step the plugin cannot do for you, and the control panel nags until it can see the rule in
your live file.

Something arriving with Googlebot's user agent that fails forward-confirmed reverse DNS is not
Google, and gets treated accordingly.

## Why block by address rather than by user agent?

Because a user agent is a string the client picked and a request is a fact. Blocking on the claim
means blocking whatever the next scraper decides to call itself, and the one before it has already
changed its mind. The only place claims are considered at all is the allowlist — which is exactly
why the allowlist verifies them.

## What is forward-confirmed reverse DNS?

The check Google, Bing, Yahoo, Yandex, Baidu and Apple all document for verifying their crawlers.
The visitor's address is resolved to a hostname, the hostname is checked against the operator's
domain, and that hostname is resolved back to see the same address again.

The forward half is the half that matters. Anyone who controls the reverse zone for their own
address block can point a PTR record at `googlebot.com`; nobody can make `googlebot.com`'s own DNS
agree. Checking only the PTR name would be worse than not checking.

## Does it work with Blitz, Varnish or Cloudflare?

Partly, and honestly: the trap, the ledger, the alerts and the console all work exactly as
documented. What a full-page cache breaks is the *blocking of already-cached pages* — the cache
answers before Craft boots, so the guard never runs for that request. The trap URL and the block
page are themselves `no-store` and never cached.

If you need the ban enforced ahead of the cache, feed the blocked addresses to whatever sits in
front: a firewall rule, a Cloudflare IP list, an nginx `deny`.

## Will it slow my site down?

No. Every front-end request costs one indexed lookup against the ban list, and that is all — before
routing, before templates, before the session. Nothing else in the plugin runs on an ordinary
request.

The session is deliberately not touched until an address is already banned, because asking whether
someone is logged in *starts* a session, a session means a `Set-Cookie` on every response, and a
`Set-Cookie` on every response is a full-page cache that never hits again. A plugin has no business
doing that to a site on the off chance the reader is a robot.

DNS verification only ever runs on a catch, and its results are cached for a day.

## What happens when a bot keeps hammering the site after it is banned?

Each attempt is counted with one arithmetic `UPDATE`, and the per-request history row is gated
behind a short cache lease. A blocked bot speeds up rather than going away, and writing a row for
every attempt would turn a bad crawler into a disk-space problem.

## Can I change the trap path?

Yes, to anything routable that is not inside the control panel. Re-run
`php craft blackhole/robots/write` afterwards and remove the stale directive for the old path — the
settings screen shows you what your file says versus what it should say.

## Does it work on a multi-site install?

Yes. The trap URL is per-site, so each site's pages carry a link to their own, and
`craft.blackhole.link({ siteId: … })` lets you be explicit. The ban list is shared, because an
address is an address regardless of which of your sites it was rude to.

## Is IPv6 supported?

Throughout. Addresses are compared on packed bytes, so `2001:db8::1` and its fully expanded form
are correctly the same address, and CIDR ranges like `2001:db8::/32` work the same as IPv4 ones.

## Are the caught addresses personal data?

In some jurisdictions, yes. They are processed to keep the site up, kept as long as your retention
setting says, and deletable from the control panel or with `php craft blackhole/bots/purge`.
Uninstalling the plugin drops both tables.

## Does it phone home?

No. There are no outbound HTTP requests anywhere in the plugin. The only network call it makes at
all is DNS, and only on a catch.

## Which versions are supported?

Craft CMS 5.3+, PHP 8.2+.
