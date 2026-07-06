# AD lite

Tekstversie van ad.nl. Geen plaatjes, video's, liveblogs, scripts, trackers of cookies.

A text-only mirror of a public news RSS feed, in the exact spirit of
[noslite](https://github.com/noslite/noslite): read the feed, throw away
everything that isn't words, render tiny static HTML pages.

It's outlet-agnostic. Point `src/config.php` at any outlet's public feed and it
becomes `<that outlet>lite`. Out of the box it's configured for **ad.nl**.

## About paywalls (read this)

adlite reads a **public RSS feed**. It shows exactly what the publisher chooses
to syndicate for free: headlines, summaries, and full text where the feed
provides it. That is all noslite ever did — NOS is free public broadcasting, and
noslite simply reformats its feed.

adlite does **not** unlock premium/paywalled AD articles, and isn't built to be
combined with a paywall-bypass extension. If a story is premium, the "Lees bij
ad.nl" link takes you to the original. For most daily headline-reading the free
feed is plenty.

## How it works

```
src/config.php      ← the only file you normally edit (feed URL, name, colour)
src/adlite.php      ← fetch feed → strip to text → write static HTML (+ .gz)
templates/*.html    ← minimalist markup (index + article)
site/               ← output; also holds the static assets (css, icon, sw, 404)
```

No Composer, no Twig, no dependencies — just PHP with the standard `simplexml`,
`dom`, `mbstring` and `zlib` extensions (all default in php-cli).

## Run it locally

```bash
php src/adlite.php          # writes site/index.html + site/l/<id>.html (+ .gz)
```

Then preview:

```bash
php -S localhost:8000 -t site
# open http://localhost:8000
```

Point it at a different outlet without editing files:

```bash
ADLITE_FEED_URL="https://www.ad.nl/sport/rss.xml" php src/adlite.php
```

## View it daily (no server of your own)

The included GitHub Action rebuilds the site on a schedule and publishes it to
GitHub Pages, so there's nothing running on your machine:

1. Create a new GitHub repo and push this folder to it (`main` branch).
2. Repo **Settings → Pages → Build and deployment → Source: GitHub Actions**.
3. The workflow in `.github/workflows/build.yml` runs every ~30 min (and on
   demand from the **Actions** tab). Your site appears at
   `https://<you>.github.io/<repo>/`.

### Make it an app on Android

The site ships a web app manifest and a service worker, so:

- Open your Pages URL in Chrome on Android → menu → **Add to Home screen**.
- It launches full-screen like an app, and the last-loaded headlines stay
  readable offline (handy on the metro).

## Switch to a different news outlet

Edit `src/config.php`: change `name`, `feed_url`, `source_name/url`,
`theme_color`. Article ids are derived generically from each item's guid/link,
so no per-outlet URL parsing is needed. That's the whole "make a lite version of
X" recipe.
