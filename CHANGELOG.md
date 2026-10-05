# Changelog

All notable changes to Black Hole are documented here.

## 5.0.1 — 2026-10-05

> {warning} Behind a proxy or CDN, Black Hole now needs Craft's `trustedHosts` set to your proxy's addresses (as well as `ipHeaders`) before it will ban the address a forwarding header gives. Until then those visits are let through, with the reason on the trap page — banning on an address anyone can type into `X-Forwarded-For` let anyone ban anyone.

### Security

- **Anybody could get innocent visitors banned.** Every request that reached the trap counted, so an
  `<img src>` pointing at it on a busy page elsewhere put every one of that page's visitors on this
  site's blocklist — their browsers fetched it without cookies, so "never catch logged-in users"
  couldn't help. A visit now counts only as a page load the browser made from this site, or when
  the `Sec-Fetch-*` headers are absent, as they are from crawlers; cross-site requests and embedded
  resources are let through.
- **Anybody could name the address to ban.** Under Craft's default `trustedHosts` (`any`), the
  address came from `X-Forwarded-For` if the request carried one, so a single request to the trap
  could ban any address its sender chose. A forwarded address is now only banned once `trustedHosts`
  names the proxy it came through.

### Fixed

- `blackhole/bots/block` printed a PHP warning instead of the address when given something that
  isn't one: `"“$ip”"` interpolates a variable called `ip”`.

### Changed

- PHPStan and ECS configuration.

## 5.0.0 — 2026-08-23

Initial release. Versioned 5.x to match the Craft major it targets, as the rest of this plugin
family is.

### Added

- **The trap.** A hidden, `nofollow`, `aria-hidden`, untabbable link on every front-end page,
  pointing at a path robots.txt tells every crawler to stay out of. Anything that follows it is
  recorded and blocked.
- **The guard.** One ban-list lookup at `Application::EVENT_BEFORE_REQUEST` — before routing,
  before the session, before Craft does any real work.
- **Forward-confirmed reverse DNS** for the crawlers that publish it (Google, Bing, Yahoo, Yandex,
  Baidu, Apple), so a spoofed `Googlebot` user agent does not walk through the allowlist.
- **Whitelisting** by exact address, dotted prefix, or CIDR range — IPv4 and IPv6, compared on
  packed bytes — and by user-agent substring, with about forty crawlers listed out of the box.
- **robots.txt support**: generated directives, a live read of what the site's `robots.txt`
  actually says, `blackhole/robots/write` to add them idempotently, and an opt-in route that serves
  `robots.txt` on sites that have no file of their own.
- **Thresholds and ban lengths.** Put an address on notice for a few trips before banning it, and
  let bans lapse after a set number of days.
- **The control panel**: a filterable, searchable, sortable list of caught addresses; a detail
  screen with the full request history; release, whitelist, delete, block-by-hand and empty-the-hole.
- **Console commands** for everything the control panel does, plus `prune`.
- **Email alerts**, latched to one message per address so a bot that spends the night hammering the
  site cannot spend the night emailing you.
- **Overridable pages.** Site templates for the block page and the trap page, with the built-in
  pages as the fallback if yours throws.
- **`craft.blackhole`** — `link()`, `trapUrl()`, `robots()`, `isBlocked()`, `counts()`.
- Retention pruning on Craft's garbage collector.
- 77 integration checks.
