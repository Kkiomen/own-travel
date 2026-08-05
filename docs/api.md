# Using this app's data from another app

Everything the dashboard shows is available as JSON, so another app - a proxy that gathers
several apps into one place - can render the same board without scraping anything itself.

- **Base URL** - wherever this app runs, e.g. `http://localhost:8000`. All paths below are
  relative to it.
- **Interactive reference** - `GET /api/docs` (Swagger UI), machine-readable contract at
  `GET /api/openapi.json`. That document is generated from the code, so it cannot fall
  behind the routes.
- **Read-only.** Nothing here writes. Scanning and pruning stay scheduled commands.

---

## 1. Authorization

**There is no authentication, and the app is not built to have any.** It is single-owner:
the dashboard has no login either, and the protection is that nothing is exposed to the
internet. Requests need no header, no key, no cookie:

```bash
curl http://localhost:8000/api/v1/dashboard
```

That is fine while both apps sit on the same private machine. It is *not* fine if the
proxy app is public, because "no auth" means anyone who finds the port reads the board and
can hammer the endpoints. Pick one of these before exposing anything.

### Option A - never expose the port (recommended, nothing to build)

Publish only what the proxy needs, over a network the internet cannot reach:

- **Same host:** keep `APP_PORT` bound to `127.0.0.1` and let the proxy call
  `http://127.0.0.1:8000` from the same machine.
- **Same Docker host:** put the proxy on this app's `edge` network and call it by service
  name - `http://app`. Do not publish the port at all.
- **Different machine:** an SSH tunnel (`ssh -L 8000:127.0.0.1:8000 vps`) or a private
  overlay network (Tailscale/WireGuard).

The proxy app then does the calling server-side, and the browser never talks to this app.

### Option B - a reverse proxy in front, with a shared secret

If the proxy runs elsewhere and must reach this app over the public internet, terminate
auth *before* the app. Caddy:

```caddyfile
deals.example.com {
    @api path /api/*
    basicauth @api {
        proxy $2a$14$...   # htpasswd -B -n proxy
    }
    reverse_proxy 127.0.0.1:8000
}
```

and the client sends `Authorization: Basic ...`. nginx's `auth_basic` does the same. This
costs no application code and keeps the "no auth in the app" rule intact.

### Option C - a token in the app

If you would rather have the app itself check a header (`X-Deals-Token`), say so - it is
one middleware over the `api` routes plus one `.env` value. It is deliberately not built
yet, because a secret that only ever travels between two of your own containers buys less
than option A.

### CORS

`api/*` answers any origin (`Access-Control-Allow-Origin: *`, Laravel's default), so a
browser-side client works out of the box. Credentials are not supported, which is another
reason to keep option A: a public URL plus open CORS means any web page can read your
board.

---

## 2. Calling an endpoint

Plain HTTP GET, no body, everything in the query string.

```bash
# the whole board, exactly as the dashboard renders it
curl -s http://localhost:8000/api/v1/dashboard | jq

# cheapest round trips out of Wrocław, twenty of them
curl -s "http://localhost:8000/api/v1/deals?type=round_trip&origin=WRO&sort=price&limit=20" | jq

# what fits leave booked 12-20 September
curl -s "http://localhost:8000/api/v1/dashboard?from=2026-09-12&to=2026-09-20" | jq
```

```ts
// From the proxy app (server-side, so the API never has to be public):
const board = await fetch(
    'http://app:80/api/v1/dashboard?' +
        new URLSearchParams({ type: 'round_trip', weekends: '1', limit: '30' }),
    { headers: { Accept: 'application/json' } },
).then((r) => r.json());
```

```php
// Laravel proxy app:
$board = Http::acceptJson()
    ->get(config('services.deals.url').'/api/v1/dashboard', [
        'type' => 'round_trip',
        'weekends' => 1,
    ])
    ->json();
```

Booleans accept `1`/`0` or `true`/`false`. Omit a parameter to not filter by it.

---

## 3. The endpoints

| Method | Path | Use it for |
| --- | --- | --- |
| GET | `/api/v1/dashboard` | **The whole screen in one request.** Deals, undated trips, the filters that took effect, airport facets, totals, thresholds, currency. |
| GET | `/api/v1/deals` | Just the list, with `meta`. Lighter when you do not need facets and tiles. |
| GET | `/api/v1/deals/{id}` | One offer, for a link straight to it. |
| GET | `/api/v1/deals/airports` | Origin/destination options under the current filters. |
| GET | `/api/v1/deals/summary` | Totals per kind and the price/score gates. |
| GET | `/api/v1/meta` | Vocabulary and bounds for building the controls. |
| GET | `/api/openapi.json` | The OpenAPI 3.1 contract. |
| GET | `/api/docs` | Swagger UI. |

### `GET /api/v1/dashboard`

```jsonc
{
  "deals": [ /* Deal[] - see §5 */ ],
  "sort": "score",
  "type": null,
  "weekends": false,
  "steals": false,
  "origin": null,
  "destination": null,
  "from": null,
  "to": null,
  "undated_trips": [ /* Deal[] - only when from+to were given */ ],
  "airports": {
    "origins": [{ "code": "WRO", "label": "Wrocław" }],
    "destinations": [{ "code": "TRF", "label": "Oslo-Torp" }]
  },
  "totals": {
    "flight":     { "count": 1872,  "cheapest": 61.8 },
    "round_trip": { "count": 37566, "cheapest": 123.6 },
    "trip":       { "count": 161,   "cheapest": 87 }
  },
  "thresholds": { "flight": 300, "round_trip": 600, "trip": 2500, "score": 60 },
  "currency": "PLN"
}
```

The eight filter keys are echoed back **after** unusable values were dropped, so the UI can
render its controls from the response rather than from what it thought it asked for.

### `GET /api/v1/deals`

```jsonc
{
  "data": [ /* Deal[] */ ],
  "undated_trips": [ /* Deal[] */ ],
  "meta": {
    "count": 3,
    "limit": 3,
    "currency": "PLN",
    "filters": { "sort": "price", "type": null, "weekends": false, "steals": false,
                 "origin": null, "destination": null, "from": null, "to": null },
    "generated_at": "2026-08-05T17:20:42+00:00"
  }
}
```

### `GET /api/v1/deals/{id}`

`id` is the `id` field of a deal. Answers `{"data": {...}}`, or **404** when the offer is
gone - including a flight that has since departed. Do not treat 404 as an error to retry:
it means the deal is no longer bookable, so drop it from your cache.

```bash
curl -s http://localhost:8000/api/v1/deals/c13a2dd180c125789e2a1735ab4316bbb387d7ff
```

### `GET /api/v1/deals/airports`

```json
{ "origins": [{ "code": "WRO", "label": "Wrocław" }], "destinations": [] }
```

Takes the same filters (except `sort`/`limit`). **Each side ignores its own filter**: ask
with `origin=WRO` and `destinations` lists where Wrocław actually flies, while `origins`
still lists every departure airport so the user can change their mind. With `type=trip`
both lists are empty - blog offers carry no IATA code, so hide the controls rather than
show them empty.

### `GET /api/v1/deals/summary`

```json
{
  "totals": { "flight": {"count": 1872, "cheapest": 61.8}, "round_trip": {...}, "trip": {...} },
  "thresholds": { "flight": 300, "round_trip": 600, "trip": 2500, "score": 60 },
  "currency": "PLN"
}
```

Counted across everything kept, not over the page returned - the tiles stay true while a
filter is applied.

### `GET /api/v1/meta`

```json
{
  "sorts": ["newest", "score", "price"],
  "types": ["flight", "round_trip", "trip"],
  "boards": ["all_inclusive", "full_board", "half_board", "breakfast", "room_only"],
  "currency": "PLN",
  "thresholds": { "flight": 300, "round_trip": 600, "trip": 2500, "score": 60 },
  "stay": { "minimum_nights": 2, "maximum_nights": 10 },
  "preferred_origin": "WRO",
  "window_days": 90,
  "limits": { "default": 60, "maximum": 200 }
}
```

Values only, never display copy: what a `round_trip` is *called* is your app's decision.
Read it once at boot to build the filter controls - then a new sort or board added here
appears in your UI without a deploy.

---

## 4. Filters

All of them work on `/api/v1/dashboard` and `/api/v1/deals`; the airport endpoint takes
everything except `sort` and `limit`.

| Parameter | Values | Meaning |
| --- | --- | --- |
| `sort` | `score` (default), `price`, `newest` | `score` is the 0-100 rating, `price` the total, `newest` when it was found. The home airport (`meta.preferred_origin`) is listed first whatever the sort. |
| `type` | `flight`, `round_trip`, `trip` | One leg / both legs priced together / a package from a blog. Omit for all. |
| `weekends` | `1` | Round trips that work as a weekend: out Friday from 15:00 or Saturday before noon, back Sunday, at most three nights. |
| `steals` | `1` | Far enough below what that route normally costs, and under the steal ceiling. |
| `origin`, `destination` | IATA, e.g. `WRO` | Flights only - a blog trip has no airport code, so combining these with `type=trip` returns nothing. |
| `from`, `to` | `YYYY-MM-DD` | Booked leave. Both or neither. |
| `limit` | 1-200 | Default `meta.limits.default` (60), capped at `meta.limits.maximum` (200). |

Four behaviours worth knowing, because they are deliberate and will look like bugs
otherwise:

1. **Unusable input is ignored, not rejected.** `?sort=xx&origin=zzzz&from=2026-13-99`
   answers 200 with the defaults. There is no validation error to display - read
   `meta.filters` (or the echoed keys on `/dashboard`) to see what actually applied. The
   board is a set of links, and a hand-edited one should show everything rather than fail.
2. **Filtering and sorting happen server-side, always.** Hundreds of thousands of deals are
   stored and at most 200 are sent. Sorting or filtering the page you received would rank
   the wrong ones and can show an empty tab while the database is full of matches. Change
   the query string, re-fetch.
3. **A holiday must contain the whole journey.** `from`/`to` match a round trip on *both*
   its departure and its return, a one-way on its departure. Leaving on the last day off is
   no holiday, and a cheap way out whose return lands after work starts again is exactly
   what a price-sorted list would otherwise hide. Bounds are whole days.
4. **There is nothing beyond `meta.window_days`.** Deals are only collected 90 days ahead,
   so a holiday further out finds nothing - say so in the UI rather than showing "no
   results".

### `undated_trips`

Most blog articles never name their terms. Those offers cannot be shown to fit a holiday -
or to miss it - so when (and only when) `from`+`to` are given, they come back in a separate
`undated_trips` array. Render them under their own heading ("dates not given"), never mixed
into the matches. Without a holiday the array is empty and every trip is in `deals`.

---

## 5. The deal object

The API sends numbers and codes, never formatted strings - currency notation, date format
and language are your app's decisions.

| Field | Type | Notes |
| --- | --- | --- |
| `id` | string | Fingerprint: kind + route + dates + price. **Changes when the price changes** - a cheaper seat on the same route is a new deal, and the old `id` starts answering 404. Use it as a key, not as a permanent identity. |
| `source` | string | `ryanair`, `ryanair-return`, `ryanair-pairs`, `wizzair`, `fly4free`, `wakacyjni-piraci`. Several queries hit the same airline - map the code to an airline name in your UI. |
| `type` | enum | `flight` \| `round_trip` \| `trip`. |
| `title` | string | The headline. Polish for blog offers. |
| `price` | number | **What the whole offer costs** - both legs for a round trip, the package for a trip. Say what it covers next to the number. |
| `currency` | string | Always matches the response's `currency` today; read it per deal anyway. |
| `url` | string | Where to book, or the article. |
| `origin`, `destination` | `{code, city, country}` \| null | Null for blog trips. `city` falls back to the code when the airport is unnamed. |
| `departs_at`, `returns_at` | ISO 8601 \| null | Flights and round trips. Real departure instants. |
| `published_at` | ISO 8601 \| null | Blog offers only - when the article appeared. **Not interchangeable with `departs_at`**: a publication date in the past means nothing, a departure in the past means the offer is gone. |
| `weekend` | bool | Verdict decided when the deal was found and stored, so it does not change under you. |
| `steal` | bool | Cheap *relative to this route*, and under the ceiling. |
| `typical_price` | number \| null | The median total for the route, once at least five pairings were priced. |
| `discount` | int \| null | Percent below `typical_price`. |
| `days` | int \| null | Nights - from the two legs for a round trip, from the article for a trip. Null when unknown. |
| `board` | enum \| null | `meta.boards`. Null means the article never said - do not render "unknown". |
| `hotel_stars` | int \| null | 1-5. |
| `trip_destination`, `hotel` | string \| null | Blog trips. Destination is already in the nominative ("Kreta", not "Krecie"). |
| `departure_cities` | string[] | Cities a blog trip can be flown from. |
| `dates` | `{from, to, label}[]` | Terms an article names. **Several windows are alternatives, not one long stay** - "4 July" *or* "12-15 September". `label` is the original Polish wording. |
| `highlights` | string[] | The offer's own bullet points. |
| `has_details` | bool | Whether there is enough beyond the headline to be worth a details view. |
| `score` | int \| null | 0-100. Flights rated on what they cost, trips on cost per day. |
| `price_per_day` | number \| null | What one day costs, when the length is known. |

**Dates arrive in two shapes.** A flight carries an instant (`2026-10-29T22:30:00+00:00`),
a blog offer a bare day (`2026-09-12`). The day an instant belongs to is the *local* one -
cutting the first ten characters out of the string puts a 22:30 UTC departure a day early.
Convert, do not slice.

---

## 6. Rebuilding the views

### The board

One call to `/api/v1/dashboard` gives every piece:

| Part of the screen | Where it comes from |
| --- | --- |
| Stat tiles ("X flights, cheapest Y") | `totals` + `currency`; `thresholds` for "under budget" markers |
| Filter bar | `meta` for the options, `airports` for the two selects, the echoed filter keys for current state |
| Deal cards | `deals` |
| "Dates not given" section | `undated_trips` (only when a holiday is set) |
| Empty state | `deals.length === 0` - distinguish "no matches for these filters" from "nothing found at all" using `totals` |

Wire the controls so **every change re-fetches** with a new query string, and keep the
query string in your app's URL - that way a filtered board stays linkable, exactly as it is
here.

Two details that make the filter bar behave:

- After a fetch, rebuild the two airport selects from `airports`. A value already chosen
  can legitimately drop out of its own list when another filter changes - keep it as an
  option anyway, or the control silently stops showing what it is doing and cannot be
  cleared.
- Hide the airport selects entirely when `type=trip`, since both lists come back empty.

### A deal card

Card = title, route (`origin.city` → `destination.city`), the price with what it covers,
the score, and badges for `weekend` / `steal` / `board` / `hotel_stars`. Rules the current
UI follows and yours should too:

- **A verdict never rests on colour alone.** Good/acceptable is always paired with a word
  or an icon.
- Show `discount` only when `typical_price` is present - "-43% vs usual 242 PLN" is the
  whole argument for a steal.
- `days` is null often enough that "? nights" must not appear; drop the line instead.

### The calendar

For a round trip, draw the outbound day in one hue, the return in another, and the days
between as a band joining them - marking both ends alike leaves the middle blank and the
trip reads as two unrelated dates. For a blog trip, draw each entry of `dates` as its own
range, since they are alternatives. Use identity colours for out/back/stay, not the
good/warn status colours, and give the calendar a key.

### Reusing the existing components

If the other app is also Vue + Tailwind, the fastest path is to copy rather than rewrite:

```
resources/js/components/deals/*   DealCard, DealDetails, DealCalendar, FilterBar,
                                  StatTile, ScoreRing, Meter, EmptyState
resources/js/lib/dealFormat.ts    money, dates, durations, Polish labels and plurals
resources/js/types/deals.ts       the types this API answers with
resources/css/app.css             the tokens: good, warn, track, leg-out, leg-back,
                                  stay, .surface, .numeric
```

They are driven entirely by props, so feeding them the JSON above renders the same board.
`dealFormat.ts` is where all the Polish copy lives (`typeLabels`, `boardLabels`,
`sourceLabel`, `nightsLabel`); the API deliberately does not serve those strings, so this
file is the one place to change wording.

---

## 7. Operating it

- **Data refreshes hourly** (`deals:scan`), and `deals:prune` clears expired offers daily.
  Polling more often than every few minutes buys nothing; cache for a minute or two.
- **`generated_at`** on `/api/v1/deals` is when the response was built, not when the scan
  ran.
- **Errors** come back as JSON (`{"message": "..."}`) with the usual status codes. The only
  one you will meet in normal use is 404 from `/deals/{id}`. With `APP_DEBUG=true` the body
  also carries the exception and a stack trace - read `message` and ignore the rest, and
  keep debug off wherever the proxy can be reached.
- **`X-Robots-Tag: noindex, nofollow, noarchive`** is on every response, including these -
  do not strip it when proxying.
