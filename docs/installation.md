---
title: Installation
slug: installation
order: 10
summary: Requirements, install, and the one robots.txt step the plugin cannot do for you.
---

## Requirements

- Craft CMS 5.3 or later
- PHP 8.2 or later

No runtime dependencies beyond Craft's own, no build step, no external services. The only network
call anywhere is DNS, and only on a catch.

## Install

```sh
composer require justinholtweb/craft-blackhole
php craft plugin/install blackhole
```

Black Hole is free. There are no editions and no licensing code.

## Then tell robots.txt about the trap

```sh
php craft blackhole/robots/write
```

That appends the trap's directives to `web/robots.txt`, creating the file if there isn't one, and
does nothing if they are already there.

```
# Black Hole for bad bots — do not remove
User-agent: *
Disallow: /blackhole
```

**This step is not optional.** Without the rule, following the hidden link is not disobedience —
it is just crawling, and you would be banning honest crawlers for doing their job. The control
panel puts a warning at the top of the list until it sees the directives in your live file, and
**Settings → Black Hole** shows you the exact lines to paste if you would rather do it by hand.

There is no trailing slash on the `Disallow`, and that is deliberate. `Disallow` is a prefix match,
so `/blackhole` covers `/blackhole`, `/blackhole/` and `/blackhole/anything` — every form a crawler
might mangle the link into. `/blackhole/` would cover only the last, leaving the link itself
outside the rule the whole plugin depends on.

### If your robots.txt is a template

Sites that render `robots.txt` from Twig do not have a file to write to. Put the directives in the
template instead:

```twig
{{ craft.blackhole.robots() }}
```

### If you have no robots.txt at all

Turn on **Serve robots.txt** and Black Hole will answer `/robots.txt` itself, with the trap's
directives and a blanket `Allow: /`. The route is registered only when the setting is on *and*
`web/robots.txt` does not exist, so it can never shadow a real file.

## That is the whole setup

The hidden link is spliced into every front-end page automatically, immediately before the last
`</body>`. Nothing else needs configuring for the plugin to start working.

## Check it

```sh
php craft blackhole/robots/show
```

That prints the directives and tells you whether your live `robots.txt` currently contains them.

To watch a catch happen end to end, request the trap URL as something that is obviously not you:

```sh
curl -A 'TestScraper/1.0' https://example.com/blackhole   # caught
curl -A 'TestScraper/1.0' https://example.com/            # blocked
```

Then release yourself again from **Black Hole → Caught bots**, or:

```sh
php craft blackhole/bots/purge
```

## Behind a proxy or a CDN

Craft needs to be told which header carries the real client address, or every visitor looks like
your load balancer and one catch bans the world. That is `ipHeaders` in `config/general.php`. Black
Hole uses whatever Craft resolves, so configure it there once and everything agrees.

## Uninstalling

```sh
php craft plugin/uninstall blackhole
```

Both tables are dropped, which means every address the trap ever caught is forgotten. Export
anything you want to keep first.
