# Changelog

All notable changes to Black Hole are documented here.

## 5.0.0 — 2026-08-18

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
