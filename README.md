# Travel

Finds cheap flights and cheap trips, rates them, and puts the good ones on one page.

It scans airline fare APIs and Polish deal blogs on a schedule, pairs outbound and return legs itself, works out whether an offer is a genuine bargain for that route, and keeps the result in a dashboard you can filter. Built to run unattended on a small VPS.

> Personal project, run for one household. There is **no authentication** — do not expose it to the internet without putting something in front of it.

## What it does

- **Flights** — cheapest one-way fares from a watch list of airports.
- **Round trips** — both legs priced together, with a stay short enough to actually take off work (2–10 nights, configurable). Legs are paired here, not by the airline.
- **Weekends** — out Friday afternoon or Saturday morning, back Sunday, no more than three nights. The departure *time* is part of the rule: a Friday flight at 06:00 costs a day of leave.
- **Trips from blogs** — RSS plus the article behind it, for length, board, hotel, departure airports and the dates the offer runs on.
- **Scoring** — everything lands on one 0–100 scale so a flight and a package holiday can share a sorted list. Flights and round trips are judged on what they cost outright, packages on what a day of them costs.
- **Steals** — an offer far below what that route normally costs. Cheap is relative: 308 PLN to London is a good weekend, the same money to Gdańsk is not.

### Sources

| Source | Endpoint | Notes |
| --- | --- | --- |
| `ryanair` | `farfnd/v4/oneWayFares` | Public, no key. One request per departure airport. |
| `ryanair-return` | `farfnd/v4/roundTripFares` | The airline's own pairing — broad, every destination. |
| `ryanair-pairs` | `farfnd/v4/oneWayFares/{from}/{to}/cheapestPerDay` | Real per-day prices in both directions, paired by us. This is what finds weekends. |
| `wizzair` | `be.wizzair.com/{version}/Api/search/timetable` | Version is discovered from the public site. Needs an explicit route list. |
| `fly4free`, `wakacyjni-piraci` | RSS + article scraping | One parser per blog. |

## Running it

Everything runs in Docker — the app, the queue worker, the scheduler, Postgres and Redis. Only the app publishes a port.

```bash
cp .env.example .env
# set DB_PASSWORD, then:
docker compose run --rm app php artisan key:generate
docker compose up -d --build
```

The dashboard is on `http://localhost:8000`. Postgres and Redis have no host binding at all; Adminer (`8091`) and Mailpit (`8025`) bind to `127.0.0.1`, so they are reachable over an SSH tunnel and nowhere else.

For development, mount the source and get Vite with hot reload:

```bash
docker compose -f compose.yaml -f compose.dev.yaml up
```

### Commands

```bash
docker compose exec app php artisan deals:scan    # ask every source now (~2 min)
docker compose exec app php artisan deals:prune   # forget departed flights and stale offers
```

Both are scheduled — a scan every hour, a prune daily.

## Configuration

`config/deals.php` holds everything worth tuning, all overridable from `.env`:

| Setting | Default | What it means |
| --- | --- | --- |
| `DEAL_DEPARTURE_AIRPORTS` | `KRK,WAW,KTW,WRO,GDN,POZ` | Airports to watch |
| `DEAL_PREFERRED_ORIGIN` | `WRO` | Home airport — its departures are listed first |
| `DEAL_MAX_ONE_WAY_PLN` | `300` | Price ceiling for a single leg |
| `DEAL_MAX_ROUND_TRIP_PLN` | `600` | Price ceiling for both legs |
| `DEAL_MAX_TRIP_PLN` | `2500` | Price ceiling for a package holiday |
| `DEAL_MIN_NIGHTS` / `DEAL_MAX_NIGHTS` | `2` / `10` | How long a trip may last |
| `DEAL_STEAL_DISCOUNT` | `0.4` | How far below the usual price counts as a steal |
| `DEAL_WINDOW_DAYS` | `90` | How far ahead to look |

Two gates, deliberately separate: **price** decides what is kept and shown, **score** decides what is worth an alert. Nothing within budget is ever hidden.

## Development

The host does not need PHP or Node — run everything through the containers:

```bash
docker compose -f compose.yaml -f compose.dev.yaml run --rm --no-deps --entrypoint sh app -c "php artisan test"
docker compose -f compose.yaml -f compose.dev.yaml run --rm --no-deps --entrypoint sh app -c "vendor/bin/pint --parallel"
docker compose -f compose.yaml -f compose.dev.yaml run --rm --no-deps --entrypoint sh app -c "vendor/bin/phpstan analyse --memory-limit=1G"
docker compose -f compose.yaml -f compose.dev.yaml run --rm --no-deps --entrypoint sh vite -c "npx eslint . && npx vue-tsc --noEmit"
```

**217 tests**, PHPStan at level 7, Pint, ESLint and vue-tsc all clean.

Adapter tests run against responses captured from the live APIs, so a change in how a payload is read fails locally rather than silently on the VPS. No test touches the network.

## Architecture

Laravel 13 + Inertia + Vue 3 + TypeScript + Tailwind, on PHP 8.4 and Postgres 17.

Ports and adapters, because every source is somebody else's HTML or JSON and all of them break eventually:

```
app/
  Domain/Deal/          pure PHP - entities, value objects, scoring rules
    Port/               DealSource, DealRepository, DealNotifier
    Service/            DealScorer, DealEvaluator, RoundTripPairing,
                        WeekendGetaway, Steal
  Infrastructure/       one adapter per external system
    DealSource/         airlines, blogs, per-blog article parsers
    Persistence/        Eloquent
    Notification/
  Application/Deal/     ScanForDeals, PruneStaleDeals
```

The domain never sees an HTTP client or a DOM. Adding a source is one adapter class plus one entry in `config/deals.php`.

## Known limits

- **Notifications are a placeholder.** Deals land in the database and on the dashboard; `LogDealNotifier` only writes to the log. The port is there — Telegram or e-mail is one adapter away.
- **Fly4free rarely publish dates in HTML.** They sit in a JavaScript widget, so the calendar is usually sparse for their offers. Wakacyjni Piraci publish them reliably.
- **Scraping is brittle by nature.** Wizz Air bumps its API version every few weeks (handled), and a blog redesign will break its parser. That is what the fixtures and per-source failure handling are for: one dead source never stops a scan.
- **`tests/Fixtures/Articles/` contains copies of third-party articles** used as test input. They are someone else's content — think about whether you want them in a public repository before pushing.

## Licence

None. Personal project, published as-is.
