# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project intent

Private, non-commercial travel-deals app, meant to run 24/7 on a VPS. Goal: scan the web (blogs, forums, airline/OTA pages, deal sites) for cheap flights and cheap trips, and notify the owner when something worth buying appears — the working threshold is **≤150 PLN one-way** (`DEAL_MAX_ONE_WAY_PLN`). Because it is private and not redistributed, scraping any source is acceptable here.

**There is no authentication and none should be added** — it is a single-owner app. Fortify, the `User` model, login/register/settings pages and the `auth`/`verified` middleware were all removed on purpose. `/` renders the Dashboard directly.

Because there is no login, **the dashboard also asks not to be indexed**, in three places that cover different failure modes: `public/robots.txt` disallows everything, `PreventIndexing` puts `X-Robots-Tag: noindex, nofollow, noarchive` on *every* response (global middleware, so JSON and `/up` are covered as well), and the head carries the same as a `<meta name="robots">` for anything that fetches a page without honouring the header. Note the tension in that combination: a crawler that obeys `robots.txt` never fetches the page, so it never reads the `noindex` - which means a URL discovered elsewhere can still be listed bare. Allowing the crawl is what makes `noindex` binding. **None of this is a security control** - the real protection is not exposing the port.

## What is built

`deals:scan` (scheduled hourly in `routes/console.php`, run by the `scheduler` container) asks every configured source for offers, rates them, drops anything above the price gate, stores what it has not seen before and hands the good ones to the notifier. `deals:prune` (daily) clears out what has expired. The dashboard at `/` ranks what is left.

Live sources, all verified against real responses:

| Source | Endpoint | Notes |
| --- | --- | --- |
| `ryanair` | `services-api.ryanair.com/farfnd/v4/oneWayFares` | Public, no key, filters by price and date itself. One request per departure airport. |
| `ryanair-return` | `.../farfnd/v4/roundTripFares` | Pairs both legs itself and caps the stay via `durationFrom`/`durationTo`. **`limit` above 20 is rejected with `InvalidLimit`** (the one-way endpoint accepts 100) — it answers cheapest-first, so 20 is the best twenty pairings per airport, and `page` is ignored. |
| `wizzair` | `be.wizzair.com/{version}/Api/search/timetable` | Version is bumped every few weeks and is scraped from the public site (cached 24 h) with a configured fallback. Needs explicit routes — see `config/deals.wizzair.routes`. **Days with no flight come back priced at 0**, and are dropped. One request returns both directions, so the adapter pairs the legs itself. **The timetable names no airports, only codes** — `WizzAirStationDirectory` reads the whole station list (`/Api/asset/map?languageCode=pl-pl`, ~660 kB, cached a week) so its offers do not sit on the board as a bare `LTN` next to Ryanair's `Londyn-Luton`. A directory that cannot be reached is not an outage: the offer still shows, under its code. |
| `fly4free`, `wakacyjni-piraci` | RSS + article scraping | Price and offer type come from the Polish headline; length, board and hotel standard from the article. Items without a price are skipped. **fly4free's feed paginates (`?paged=N`, 20 posts a page) and is walked `deals.feeds[].pages` deep**; Wakacyjni Piraci answer with the same 28 offers whatever page is asked for. loter.pl blocks us and is not wired up. |

### Finding the trips in a feed

A blog's front page is a day of posts - a handful of offers - so three things decide how many trips a scan actually finds, and all three were getting in the way:

- **Depth.** WordPress serves the archive behind the feed 20 posts at a time, so `RssFeedSource` walks `pages` of it (`?paged=N`, configured per feed) and stops early where the archive runs out or a page repeats itself. A dozen pages is about ten days back - comfortably inside `retention_days` - for a dozen requests an hour. A feed that does not paginate stays at `pages => 1`.
- **What counts as a flight.** Most packages sell the flights as part of the deal ("Loty i 4* hotel za 579 PLN"), and `OfferTypeClassifier` used to read every one of them as a flight - which priced them against the one-way gate, where all of them were thrown away. **Naming a flight is not enough to make it one:** what separates the two is the stay, so a headline naming a bed, a board or a number of nights is a trip however loudly it also advertises the flights.
- **How a price may be phrased.** `OfferPriceParser` wanted "za"/"od"/"tylko"; Wakacyjni Piraci write "poniżej 1000 zł" and "niecałe 2000 zł/os", which lost most of that feed. The amount still has to be introduced by a word promising a price - a bare number is deliberately not enough, because "1300 zł za dobę. Sprawdziłem, jak wyglądają wakacje…" is an article about prices, not something anyone can book.

Together these took the trips a scan keeps from 6 to 111 against the live feeds. Because walking the archive multiplies the articles behind it, `SearchCriteria` carries `maxTripPrice` and the feed source does not open the article behind an offer already over budget - it would be dropped whatever the article said.

### Reading blog articles

**One parser per blog, named in `config/deals.feeds`.** That is what the `ArticleParser` port is for — a shared scraper with per-site branches would be the wrong shape. `TripAttributeReader` holds what they *do* share: the Polish phrasing for length, board, standard, airports and dates.

- `WakacyjniPiraciArticleParser` — the site is a single-page app, but the article ships with the page as **escaped Contentful rich-text JSON** (`\"value\":\"...\"`). Its text nodes are the offer's own bullet list and nothing else, which makes it the best source on either blog: *"7 nocy w 3\* hotelu Agrabella"*, *"wylot z Gdańska (za dopłatą również z Krakowa lub Warszawy)"*, *"proponowany termin: 31 sierpnia - 7 września"*. The bullets come first and the write-up after, so the first paragraph-length node ends the facts. Dates are searched across *all* nodes, not just the bullets — bold runs split them into fragments too short to be a bullet.
- `Fly4freeArticleParser` — everything comes from the schema.org/meta description, which states the whole offer in one sentence.

Departure cities are **looked up from a dictionary**, not pattern-matched: Polish puts the first in the genitive ("z Gdańska") and lists the rest after "lub", where no `z ...` pattern reaches them. Destinations go through the same kind of map so a label reads "Kreta", not "Krecie" — unrecognised places are left out rather than printed in the wrong case.

**Dates are resolved, not quoted.** "31 sierpnia" tells you nothing until you can see it against the other dates, so `TripAttributeReader::dates()` turns each phrase into a real `TravelWindow` (from, optional to, and the original wording). The year is never written down, so it comes from when the article was published — a month already behind it belongs to next year. The dashboard draws them as month grids (`DealCalendar`), with a flight's own departure and return marked differently from the days a blog offer merely names.

Details beyond the queryable few live in the `trip_details` JSON column (destination, hotel, departure cities, dates, the offer's bullets); nothing sorts or filters on them, so one column beats six. The dashboard opens them in a modal — clicking a card shows the details, and only the button leaves for the source.

`RssFeedSource` opens the article behind every **trip** (never behind a flight - the headline already says everything, and each fetch is a request to somebody's blog) and hands the HTML to `BlogArticleParser`. Pages are cached for a week by `ArticleFetcher`, so an hourly scan does not re-download the same offers all day.

**Only the offer's own summary is parsed** - headline, schema.org `description`, meta description. The article body is deliberately never searched: Fly4free paste advertising widgets for *other* operators into the text ("Zarzis od 2639 PLN na 7 dni"), the contents change between requests, and the markers around them sit *after* the offers, so no simple cut removes them. Reading a duration out of one silently mis-rates the deal. **Missing detail is fine; wrong detail is not** - and a trip with no known length is still scored, on its total price.

Wakacyjni Piraci render as a single-page app, so only their headline and JSON-LD are usable; their board badge lives deep in the markup among unrelated offers and is left unread.

### Scoring and the two gates

`DealScorer` puts everything on one 0-100 scale so flights and trips can share a sorted list. Flights are rated on what they cost outright, trips on what a day of them costs (`PriceBand` does the linear interpolation), and a trip whose length the article never gave up falls back to its total price. All three bands live in `config/deals.php`.

The gates are separate on purpose, in `DealEvaluator`:

- **price** decides what is *kept* — everything within budget is stored and shown, because the point is not having to hunt;
- **score** decides what is *worth being told about* — a cheap but mediocre offer stays on the dashboard without earning an alert.

Defaults reflect how the app is actually used: one-way flights up to 300 PLN (100 or less scores full marks), trips up to 2500 PLN, and a weekend getaway around 400 PLN rated as a find.

Notification is `LogDealNotifier` for now — deliberately a placeholder. The `DealNotifier` port exists so adding Telegram or e-mail is one adapter plus one binding in `DealServiceProvider`.

Dedup is by fingerprint: type + route + dates + price (feed items use their link instead of a route). **The source is deliberately not part of it** — several queries hit the same flights, and the same seats on the same days at the same price are one offer however many of them turned it up. Names are not part of it either, which is why finding a known offer again **fills in airport names it was stored without** rather than discarding the better version — that is what let 2 000 nameless Wizz Air rows recover instead of waiting to expire. A **price drop produces a new fingerprint on purpose**, so a cheaper seat on a known route alerts again.

`deals:prune` (scheduled daily) drops flights once they have departed and anything older than `deals.retention_days`. The listing query excludes departed flights too, so the dashboard stays honest between prunes.

**`departs_at` and `published_at` are not interchangeable.** A flight has a departure; a blog offer has a publication date. They were briefly the same column, which made every blog offer look like a flight that had already left — and would have deleted the lot on the first prune. Keep them apart.

### Round trips

A one-way bargain is not a holiday: a 60 PLN seat out with the way back a month later is unbookable as leave. `DealType::RoundTrip` covers both legs priced together, and `StayWindow` (`config/deals.stay`, 2–10 nights) bounds how long the stay may be. Ryanair enforces it server-side and the adapter re-checks the answer; Wizz Air cannot, so `WizzAirTimetableSource` pairs each outbound day with the cheapest return inside the window itself, and falls back to a one-way offer when there is no way back.

One-way flights are still collected — they are useful on their own — but round trips are the default tab.

**We pair the legs ourselves.** Filtering the pairings an airline hands back cannot find weekends: its answer is whatever is cheapest, and the cheapest pairing on a route is almost never Friday-to-Sunday (proof: zero weekend hits out of 423 pairings). `ryanair-pairs` therefore asks `/oneWayFares/{from}/{to}/cheapestPerDay` for both directions — real per-day prices with real departure times — and `RoundTripPairing` (domain) decides what goes with what: the cheapest way back, plus separately the cheapest way back that makes it a weekend. Wizz Air is paired the same way from its two-direction timetable.

Routes come from what the one-way search already found cheap, so nothing is configured by hand; `deals.ryanair.pairing` bounds the cost (airports, `routes_per_airport`). It is a request per route per month per direction — a full scan takes about 90 seconds.

`ryanair-return` (the airline's own pairing) is kept alongside: one request per airport covering *every* destination, where ours covers ten routes deeply.

**A steal is relative to the route.** 308 PLN to London is a good weekend; the same money to Gdańsk is not. Because a scan prices a whole month of a route in both directions, `RoundTripPairing` knows the median total for that route and stamps it on every pairing (`typical_price_minor_units`); `Steal` (domain) flags anything at least `deals.steal.minimum_discount` below it *and* under `deals.steal.ceiling`. Both conditions matter — a half-price long-haul is still not a weekend anyone here is booking. The median needs at least five pairings, otherwise there is no "usual" to speak of.

**Wizz Air's `departureDate` is a date, not a departure.** The real times sit in `departureDates`, and one entry per time is a separate flight — using midnight made every Wizz pairing fail the "Friday from 15:00" rule silently. The timetable endpoint also refuses a window wider than about a month (HTTP 400), so it is walked month by month and the legs pooled before pairing, which is what brings the far side of the search window into range at all.

`WeekendGetaway` (domain) decides what counts: out Friday from 15:00 or Saturday before 12:00, back on Sunday, **and no more than three nights** — otherwise "Friday out, Sunday nine days later" qualifies on weekdays alone. The verdict is worked out once, when the deal is found, and stored in `weekend_getaway` so the dashboard can filter on it without the database knowing what a weekend is. Change the rule and existing rows keep their old verdict until they are re-found.

### Listing

**Ordering and every filter happen in the query** — `DealListing` carries the sort, the kind, weekends-only, origin and destination, plus `preferredOrigin` (`deals.preferred_origin`, WRO) which lists the home airport first whatever the sort — never in the Vue page. Hundreds of deals are kept and only `deals.dashboard_limit` are sent: sorting or filtering the page instead would rank the wrong ones and could show an empty tab while the database is full of matches. The stat tiles come from `DealRepository::summarise()` for the same reason.

**The airport lists are facets, not a fixed inventory.** `availableAirports()` takes the same `DealListing` and runs it through the same filters as the listing itself (`onlyWhatIsAskedFor()` serves both, so they cannot drift apart), with one twist: each side ignores its own filter. Pick Wrocław and "Dokąd" offers where Wrocław actually flies; narrowing it by the destination as well would leave the list holding only the value already chosen. Offering every airport regardless meant most choices led to an empty page. Two consequences worth knowing: with `type=trip` both lists come back empty - blog offers carry no IATA code - and the controls hide themselves rather than sit there empty; and a value already picked can drop out of its own list when another filter changes, so `SelectField` keeps it as an option so the filter still shows what it is doing and can be cleared.

### Searching against booked leave

`HolidayWindow` (`?from=&to=` on the dashboard) is leave already granted, and it is a different idea from `TravelWindow` — that one is a date an *offer* can be taken on, read off a blog. **The whole journey has to fit inside it**: a round trip is matched on `departs_at` *and* `returns_at`, a one-way on its departure alone. Leaving on the last day off is no holiday, and a cheap way out whose return lands after work starts again is the half a price-sorted list will happily hide. The bounds are whole days — leave booked for the 12th covers a flight at seven in the evening.

It filters what has already been found; it does not steer the scan. Nothing outside the rolling `deals.window_days` (90) was ever collected, so a holiday further out than that finds nothing until that window is widened.

**Trip dates live in `deal_travel_windows`, a row per window, not a first/last pair on the deal.** An article naming several terms is naming *alternatives* — "4 lipca" **or** "12-15 września" is two separate chances to go — and collapsing them into one span would invent a three-month trip and match leave in August that was never on offer. They are written twice on purpose: the `trip_details` JSON is what the offer shows the reader, these rows are what SQL can filter on, and one column cannot do both without picking a dialect (tests run on SQLite, the app on Postgres).

**Undated trips are shown, but apart.** Only a minority of articles name their terms in the summary `BlogArticleParser` is allowed to read — 13 of 112 when this was built — so hiding the rest would bury most of the blog offers, while listing them among the matches would claim a fit nobody can stand behind. They come back in a separate `undated_trips` prop (`DealListing::$undatedTripsOnly`) under their own heading, and only once a holiday is actually asked for.

A half-given or backwards range is treated as no filter at all, exactly like an unusable IATA code: the dashboard is a set of links, and a hand-edited one should show everything rather than fail.

## Running it — everything is Dockerized

There is no local PHP/Node workflow; the host PHP is 8.4.0 and cannot even resolve the dependency tree (Pest 5 needs ≥8.4.1). Run *all* PHP, Composer, npm and artisan commands inside containers.

```bash
composer prod    # docker compose up -d --build           (VPS / production-like)
composer dev     # docker compose -f compose.yaml -f compose.dev.yaml up
composer setup   # first run: copy .env, build images, generate APP_KEY

# one-off commands (dev image has the dev dependencies, prod image does not)
docker compose -f compose.yaml -f compose.dev.yaml run --rm --no-deps --entrypoint sh app -c "php artisan test"
docker compose -f compose.yaml -f compose.dev.yaml run --rm --no-deps --entrypoint sh app -c "php artisan test --filter=SomeTest"
docker compose -f compose.yaml -f compose.dev.yaml run --rm --no-deps --entrypoint sh app -c "vendor/bin/pint --parallel"
docker compose -f compose.yaml -f compose.dev.yaml run --rm --no-deps --entrypoint sh app -c "vendor/bin/phpstan analyse --memory-limit=1G"
docker compose -f compose.yaml -f compose.dev.yaml run --rm --no-deps --entrypoint sh vite -c "npx eslint . && npx prettier --check resources/"

# Composer dependency changes must run in a container too, never on the host:
docker run --rm -v "${PWD}:/app" -w /app composer:2 require some/package
```

`composer.json` pins `config.platform.php` to **8.4.24**, matching the runtime image; keep them in sync if the base image moves.

`phpstan analyse` crashes its parallel workers inside the container without a raised memory limit — hence `--memory-limit=1G` in the `types:check` script.

### Services and exposure

Only `app` is published to the outside world (`APP_PORT`, default 8000). Postgres and Redis have **no host binding at all**; Adminer (`8091`) and Mailpit (`8025`) bind to `127.0.0.1` only, so they are reachable over an SSH tunnel from the VPS but never from the internet. Keep it that way when adding tooling.

Two networks enforce this: `backend` is `internal: true` (no route to the internet), `edge` is a normal bridge. `app`, `worker` and `scheduler` sit on both — they need outbound internet for scraping *and* the internal services. Datastores sit on `backend` only.

`worker` (queue) and `scheduler` (`schedule:work`) run the same image as `app` with a different command, distinguished by `CONTAINER_ROLE`. `docker/entrypoint.sh` waits for Postgres, refuses to boot without `APP_KEY`, runs `migrate --force` **only in the `app` container** (so workers never race the schema), then caches config/routes/views/events. Config is cached at boot, so an `.env` change requires a container restart, not just a file save.

Scheduled deal scans belong in `routes/console.php`; queued scraping work should be dispatched to the `high` or `default` queue (those are the only two the worker consumes).

## Architecture

**Stack:** Laravel 13 (PHP 8.4) + Inertia v3 + Vue 3 + TypeScript + Tailwind v4, Pest 5, Postgres 17, Redis 7 (queue + cache + session).

Three generated-code mechanisms matter more than the file tree here:

- **Wayfinder** (`@laravel/vite-plugin-wayfinder`, `formVariants: true`) generates typed route/action helpers into `resources/js/routes/`, `resources/js/actions/` and `resources/js/wayfinder/`. These directories are **gitignored and regenerated by Vite** — never edit them by hand. Import route helpers from `@/routes/...` in Vue instead of hardcoding URLs; adding a *named* route in PHP is what makes a helper appear. Because Wayfinder shells out to `php artisan`, the asset build needs PHP — that is why the `assets` Docker stage is a PHP image with Node bolted on, not a plain Node image.
- **Inertia v3 Vite plugin** (`inertia()` in `vite.config.ts`) auto-resolves pages from `resources/js/pages/**`, so a new page is just a `.vue` file there plus a route. `resources/js/app.ts` wraps every page in `AppLayout` — the old name-based layout switch is gone along with the auth/settings pages. SSR is disabled in `config/inertia.php`.
- **Shared props** flow through `App\Http\Middleware\HandleInertiaRequests::share()` (currently `name`); add globally-needed props there and mirror the type in `resources/js/types/global.d.ts`. Flash-message toasts are wired via `resources/js/lib/flashToast.ts` — flash from the server rather than toasting manually in Vue. Appearance (light/dark) is a cookie handled by `HandleAppearance` + `useAppearance.ts`; `appearance` is excluded from cookie encryption in `bootstrap/app.php`.

### The interface

The starter kit's sidebar panel is gone — sidebar, breadcrumbs, `AppShell`/`AppContent` and the auth/settings leftovers were deleted. `AppLayout` is a sticky header (`AppHeader`, with the brand mark and the light/dark switch) over a single full-width column, because the app has exactly one screen and nothing to navigate to.

The design system lives in `resources/css/app.css` as CSS custom properties, mirrored into Tailwind through `@theme inline`. Beyond the usual shadcn tokens it defines **reserved status colours** — `good` (a bargain), `warn` (merely acceptable) — plus `track` for meter rails and the `shadow-card`/`shadow-lift` pair. Both status colours are always paired with a word or an icon, so a verdict never rests on colour alone. Two shared classes carry the rest: `.surface` (the one elevation language for cards, tiles and the header) and `.numeric` (tabular figures; large standalone numbers deliberately do without).

`DealCalendar` draws a stay the way a travel agent's date picker does: the outbound day filled in one hue, the way back in another, and the days between them as a band joining the two - marking both ends alike left the middle blank, so a trip read as two unrelated dates. `leg-out`/`leg-back`/`stay` are identity, not status, which is why they are their own tokens rather than a reuse of `good`/`warn`; the pair clears the colourblind separation gate in both modes and the calendar carries a key regardless. **Dates arrive in two shapes** - a blog names a bare day, an airline an instant - and the day an instant belongs to is the local one: a flight at 22:30 UTC leaves on the 29th here, so cutting the date out of the string put a whole stay on the calendar a day early.

Page-specific components sit in `resources/js/components/deals/` — `DealCard`, `StatTile`, `ScoreRing`, `Meter`, `FilterBar` and its controls, `EmptyState`. Formatting of money, dates, durations and source names is centralised in `resources/js/lib/dealFormat.ts`; **the controller sends numbers and codes, never formatted strings**, so currency and date notation are decided in one place.

The remaining generic UI components are shadcn-style (`components.json`, `reka-ui`, `class-variance-authority`) under `resources/js/components/ui/`; compose with `cn()` from `@/lib/utils`.

Exceptions render as JSON for `api/*` or `expectsJson()` requests (`bootstrap/app.php`) — relevant if the app grows an API for a mobile notifier.

## Ports and adapters — mandatory for anything outside the app

**Every** interaction with the outside world — scraping a blog, calling a flight API, parsing an RSS feed, sending a notification, hitting a headless browser — goes through a port (an interface owned by the domain) and an adapter (the implementation that knows about HTTP, HTML and vendor quirks). The domain never sees Guzzle, a DOM crawler, an HTTP response, or a vendor DTO.

```
app/
  Domain/Deal/                <- pure PHP. No framework, no HTTP, no Eloquent.
    Deal.php                      entity, owns its dedup fingerprint
    DealType.php                  flight | trip
    ValueObject/                  Money, Airport, SearchCriteria
    Port/                         DealSource, DealRepository, DealNotifier
    Service/DealEvaluator.php     the price gate
    Exception/DealSourceUnavailable.php
  Infrastructure/             <- adapters. One class per external system.
    DealSource/
      RyanairFareFinderSource.php
      WizzAirTimetableSource.php  + WizzAirApiVersionResolver
      RssFeedSource.php           one instance per feed, configured in config/deals.php
      InMemoryDealSource.php      test double, lives next to the real ones
      ConfiguredDealSources.php   builds the source list from config, lazily
      Parser/                     OfferPriceParser, OfferTypeClassifier
    Persistence/EloquentDealRepository.php  (+ Eloquent/DealRecord)
    Notification/LogDealNotifier.php
  Application/Deal/           <- use cases the delivery layer calls
    ScanForDeals.php, ScanReport.php, SearchCriteriaFactory.php
  Http/Controllers/           <- delivery layer: thin, calls a use case
  Console/Commands/           <- delivery layer: thin, calls a use case
```

`ConfiguredDealSources` assembles the list **when a scan asks for it**, not when the container boots — build it eagerly in a provider and configuration can no longer be overridden in tests.

Rules that make this hold:

- The port is defined by what the **domain** needs, never by what the vendor returns. If a new source forces a change to the port's signature, the port was modelled around an adapter — rework it.
- Adapters translate at the boundary: vendor JSON/HTML in, domain objects out. A parse failure is an adapter concern; it throws a domain-level exception (`DealSourceUnavailable`), not a `RequestException`.
- Bind ports to adapters in `AppServiceProvider`. Multiple sources are registered as a tagged collection so `ScanForDeals` iterates over `DealSource[]` without knowing which sites exist — adding a site means adding one adapter class plus one binding, and touching nothing else.
- One adapter per source. Do not add `if ($site === 'x')` branches inside a shared scraper.
- Rate-limiting, retries, backoff, user-agent rotation and caching of raw responses belong in the adapter layer (or a decorator around it), never in the domain.

## Code quality standards

These are requirements for this project, not aspirations. Hold to them when adding code, and refactor toward them when touching existing code.

- **Everything is tested.** No feature lands without tests. Domain logic gets fast unit tests (no framework, no DB); use cases and HTTP endpoints get feature tests. A bug fix starts with a failing test that reproduces it.
- **Tests never touch the network.** Test the domain against in-memory adapters; test each real adapter against saved fixtures (store scraped HTML/JSON under `tests/Fixtures/`) with `Http::fake()`. Ports exist precisely so this is easy — if a test needs the internet, the seam is in the wrong place.
- **SOLID.** Single responsibility per class; depend on the port, never on the concrete adapter; keep interfaces narrow (a notifier that only sends does not also format).
- **KISS / YAGNI.** Build the case in front of you. No abstraction layer for a second flight API that does not exist yet, no configuration option nobody sets. The ports-and-adapters split is the one structural investment made up front, because retrofitting it onto scrapers is expensive.
- **DRY, but about knowledge.** Shared *behaviour* gets extracted; two scrapers that happen to look alike stay separate, because they change for different reasons.
- **Design patterns where they pay for themselves** — strategy for interchangeable deal sources, adapter at every boundary, decorator for cross-cutting adapter concerns (retry, cache, rate limit), factory for building domain objects out of messy scraped input, value objects instead of primitive `float $price` / `string $currency` pairs. Do not reach for a pattern that the problem does not ask for.
- **All code, comments, names, commit messages and log output in English.** Conversation with the user stays Polish; the codebase does not.

## Conventions

- PHP style is Pint `laravel` preset; PHPStan level 7 over `app/ bootstrap/app.php config/ database/ routes/` is enforced by `composer test`, so annotate array shapes and generics on new services/models.
- Prettier (`.prettierrc`): 4-space indent, single quotes, 150 print width, `prettier-plugin-tailwindcss` sorts classes. Run it — CI checks formatting.
- `resources/js/types/global.d.ts` **must keep a top-level `import`**. Without one the file stops being a module and its `declare module 'vue'` block replaces Vue's real types instead of extending them — which shows up as a cascade of nonsense errors like `Module "vue" has no exported member 'computed'` across unrelated files.
- Tests are class-based PHPUnit style on top of Pest; `tests/Feature` and `tests/Unit` are separate suites and run against in-memory SQLite with the `sync` queue — not against the Postgres container.
- **`tests/bootstrap.php` is what keeps that true, and it must not be bypassed.** The container exports `DB_CONNECTION=pgsql` from `.env` into `$_SERVER`, which Laravel's env repository reads *before* anything PHPUnit sets — `<env>` entries in `phpunit.xml` lose that race even with `force="true"`, because they only reach `putenv()`. Until this was pinned in the bootstrap file, every `php artisan test` run connected to the live database and `RefreshDatabase` emptied it. `TestEnvironmentTest` fails loudly if it regresses.
- Inertia v3 embeds its page payload in a `<script data-page="app" type="application/json">` tag, and its own `assertInertia` helper (which expects view data) does not see it. Use the `inertiaPage()` / `inertiaProps()` helpers on `Tests\TestCase` instead.
- The npm lockfile is written on Windows and lacks musl-linux binaries, so the Docker build uses `npm install`, not `npm ci`.
