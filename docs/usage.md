---
title: Usage
slug: usage
order: 30
summary: The control panel, the console commands, the Twig variable, and overriding the pages.
---

## The control panel

**Black Hole → Caught bots** is the ledger: one row per address, because one row per address is the
whole model.

Filter by status — **blocked**, **on notice**, **released** — search by address, hostname, user
agent or requested URL, and sort on any column that matters. A banner sits at the top until the
plugin can see its directives in your live `robots.txt`.

Opening a row shows everything recorded about that address:

- hostname, user agent, what it asked for, where it said it came from
- how many times it tripped the trap, and how many times it has been turned away since
- first seen, last seen, when the ban lapses
- the full request history, subject to your retention setting

From there you can **release** it, **whitelist** it (which releases it and adds the address to the
allowlist), **delete** it, or **block an address by hand** that never came near the trap. **Empty
the hole** clears everything.

Releasing an address does not forget it. The row stays, marked released, so a repeat offender is
recognisable when it comes back.

## The console

Everything the control panel does, plus pruning.

```sh
php craft blackhole/robots/show              # the directives, and whether your file has them
php craft blackhole/robots/write             # add them to web/robots.txt (idempotent)

php craft blackhole/bots/list                # --status= --search= --limit=
php craft blackhole/bots/block 203.0.113.7   # --note=
php craft blackhole/bots/release 203.0.113.7
php craft blackhole/bots/delete 203.0.113.7
php craft blackhole/bots/purge
php craft blackhole/bots/prune               # apply the retention window now
```

`prune` is the same work Craft's garbage collector does on its own schedule — useful when you have
just turned the retention window down and want the effect immediately.

In a deploy script or a cron job, pass `--interactive=0` so the confirmations do not stall:

```sh
php craft blackhole/bots/purge --interactive=0
```

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

### Placing the link yourself

Turn **Add the link automatically** off and put it where you want:

```twig
{{ craft.blackhole.link }}
```

This is worth doing if you want the link high in the markup, where a crawler that gives up halfway
down a long page still finds it. If the plugin sees the trap URL already on the page, it leaves the
page alone — so you can leave auto-injection on while you move the link, and nothing is duplicated.

What it renders:

```html
<a href="https://example.com/blackhole"
   title="Do NOT follow this link or you will be banned from this site!"
   rel="nofollow" style="display:none;" aria-hidden="true" tabindex="-1">&nbsp;</a>
```

`display:none` hides it from readers. `rel="nofollow"` is the second explicit instruction not to
follow it. `aria-hidden` and `tabindex="-1"` keep it out of the accessibility tree and out of the
tab order, so a screen reader never announces the trap and a keyboard user cannot walk into it. **A
honeypot that catches people is a bug.**

## Your own pages

Point **Blocked template** or **Caught template** at a site template to replace the built-in pages.

The blocked template gets:

| Variable | |
| --- | --- |
| `ip` | the address being turned away |
| `message` | the configured blocked message |
| `userAgent` | what it claims to be |
| `requestUri` | what it asked for |
| `statusCode` | the configured status code |

The caught template gets `caught`, `bot`, `ip`, `message`, `blocked`, `hits`, `threshold` and
`reason`.

Both render in the **site** template mode, so they are ordinary templates in `templates/` and can
extend your layout like anything else — with one caveat: your layout is about to be shown to
something that just proved it ignores instructions, so keep it cheap.

**If your template throws, the built-in page is served instead.** A blocked request still has to
end with something sent, and a broken override must not take the site down.

## Serving robots.txt from a template

If `robots.txt` is a Twig template on your site rather than a file:

```twig
User-agent: *
Disallow: /admin

{{ craft.blackhole.robots() }}
```

`robots()` emits the comment line and the `User-agent` / `Disallow` pair, using whatever the trap
path is set to now — so changing the path in the settings does not leave a stale directive behind.
