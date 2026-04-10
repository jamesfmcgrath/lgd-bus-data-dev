# Bus Times Module — Project Specification

## Overview

`localgov_bus_data` is a standalone, reusable Drupal 10/11 module for UK council bus information. Cumberland Council is the initial client, but any UK council can install it, configure their GTFS source URL and geographic boundary, and serve timetables, departure boards, route maps, and real-time arrivals without code changes.

**Repository:** `web/modules/custom/localgov_bus_data`
**Tech stack:** Drupal 10.2+, PHP 8.3+, BODS GTFS bulk download, Leaflet.js + OpenStreetMap, NaPTAN
**Database:** MySQL 5.7+ / MariaDB 10.3+ required (uses `GROUP_CONCAT`; SQLite/PostgreSQL not supported)

---

## Architecture Decisions

| Concern | Choice | Rationale |
|---|---|---|
| Data format | GTFS (CSV) bulk download | Better tooling, simpler parsing, wider community support |
| Download auth | None required for GTFS | The BODS bulk GTFS ZIP is publicly accessible — no API key needed for Phases 1–4 |
| Geographic filter | GeoJSON polygon (primary) + bounding box fallback | Polygon gives accurate cross-boundary filtering; bbox is simpler fallback |
| API key storage | Drupal Key module *(Phase 5 only)* | Only needed for real-time SIRI-SM endpoints; not present in current codebase |
| Maps | Leaflet.js + OSM via `drupal/leaflet` contrib | Already on LGD, no licensing cost; now an active dependency |
| Geo fields | `drupal/geofield` | Stop lat/lon stored as geofield for Leaflet integration; now an active dependency |
| Real-time | Server-side proxy, polled every 30 s *(Phase 5)* | Avoids CORS, protects API key, falls back to schedule |
| Extensibility | No `DataProviderInterface` | Removed as premature — not needed until a second data source is in scope |
| Accessibility | WCAG 2.2 AA | UK GDS standard |
| Frontend | Progressive enhancement | Core content works without JS |
| Import strategy | Bulk GTFS download + GeoJSON/bbox filter + Migrate | Download full UK dataset (~700 MB–1 GB), trim to configured boundary, run Drupal Migrate |
| Cron execution | Batch API (web) / synchronous (Drush) | Batch API chunks prevent request timeouts; Drush runs full pipeline synchronously |

---

## Module Structure

```
localgov_bus_data/
├── localgov_bus_data.info.yml
├── localgov_bus_data.module              # hook_cron(), hook_theme(), hook_views_*, hook_preprocess_*
├── localgov_bus_data.permissions.yml
├── localgov_bus_data.routing.yml         # Admin config, import log, content tabs, structure routes, autocomplete
├── localgov_bus_data.links.menu.yml
├── localgov_bus_data.links.task.yml
├── localgov_bus_data.services.yml        # 5 services: import, downloader, filter, naptan, logger channel
├── drush.services.yml
├── composer.json
├── config/
│   ├── schema/localgov_bus_data.schema.yml
│   └── install/
│       ├── localgov_bus_data.settings.yml               # default settings (placeholder values)
│       ├── core.entity_form_display.localgov_bus_*.yml  # 5 entity form displays
│       ├── core.entity_view_display.localgov_bus_*.yml  # 5 entity view displays
│       ├── migrate_plus.migration_group.localgov_bus_data.yml
│       ├── migrate_plus.migration.localgov_bus_data_routes.yml
│       ├── migrate_plus.migration.localgov_bus_data_stops.yml
│       ├── migrate_plus.migration.localgov_bus_data_calendars.yml
│       ├── migrate_plus.migration.localgov_bus_data_trips.yml
│       ├── migrate_plus.migration.localgov_bus_data_stop_times.yml
│       ├── views.view.localgov_bus_routes.yml           # route list (page)
│       ├── views.view.localgov_bus_stop_search.yml      # stop search with locality filter + Leaflet attachment
│       ├── views.view.localgov_bus_stop_detail.yml      # stop detail / departures + Leaflet attachment
│       └── views.view.localgov_bus_timetable.yml        # timetable grid (route page + stop page displays)
└── src/
    ├── Batch/
    │   └── GtfsImportBatch.php             # Batch API callbacks (5 operations: download, extract, filter, migrate, enrich)
    ├── Controller/
    │   ├── BusDataStructureController.php  # /admin/content/bus-data landing + /admin/structure/bus-data/*
    │   ├── BusStopAutocompleteController.php
    │   └── ImportLogController.php
    ├── Drush/Commands/
    │   └── BusTimesCommands.php            # bus-times:import + bus-times:seed
    ├── Entity/
    │   ├── BusDataEntityAccessControlHandler.php
    │   ├── BusDataEntityListBuilder.php
    │   ├── BusRoute.php + BusRouteListBuilder.php
    │   ├── BusStop.php + BusStopListBuilder.php
    │   ├── BusCalendar.php + BusCalendarListBuilder.php
    │   ├── BusTrip.php + BusTripListBuilder.php
    │   └── BusStopTime.php + BusStopTimeListBuilder.php
    ├── Form/
    │   └── SettingsForm.php
    ├── Plugin/
    │   └── views/
    │       ├── style/BusTimetableStyle.php   # Pivot plugin: stop × trip matrix (stops as rows, trips as cols)
    │       └── filter/LocalitySelect.php     # Custom select filter built from distinct bustimes_locality values
    └── Service/
        ├── GtfsImportService.php    # Core orchestrator: download → filter → stage → migrate → delete → enrich → log
        ├── GtfsDownloader.php       # HTTP download with Range-request chunking, fallback to full-file
        ├── GtfsFilter.php           # GeoJSON polygon + bbox filter; memory-efficient CSV streaming
        └── NaptanImportService.php  # NaPTAN CSV download + match by ATCO code + enrich BusStop entities

localgov_bus_data_homepage/          # Optional submodule: public landing page at /buses
tests/
└── src/Unit/
    ├── GtfsFilterTest.php      # 5 tests using real temp CSV files
    └── GtfsDownloaderTest.php  # 5 tests with mocked HTTP client, config, logger
```

---

## Content Entity Types (GTFS mapping)

All entity IDs carry the `localgov_bus_` prefix.

| Entity | Machine name | Key fields |
|---|---|---|
| Route | `localgov_bus_route` | `bustimes_route_id`, `bustimes_route_short_name`, `bustimes_route_long_name`, `bustimes_agency_id`, `bustimes_route_type` |
| Stop | `localgov_bus_stop` | `bustimes_stop_id` (ATCO code), `bustimes_stop_name`, `bustimes_stop_lat`, `bustimes_stop_lon`, `bustimes_wheelchair_boarding`, `bustimes_indicator`, `bustimes_street`, `bustimes_locality` |
| Trip | `localgov_bus_trip` | `bustimes_trip_id`, `bustimes_route_id` (→localgov_bus_route), `bustimes_service_id` (→localgov_bus_calendar), `bustimes_direction_id`, `bustimes_trip_headsign` |
| Stop time | `localgov_bus_stop_time` | `bustimes_trip_id` (→localgov_bus_trip), `bustimes_stop_id` (→localgov_bus_stop), `bustimes_arrival_time`, `bustimes_departure_time`, `bustimes_stop_sequence` |
| Calendar | `localgov_bus_calendar` | `bustimes_service_id`, `bustimes_monday`–`bustimes_sunday`, `bustimes_start_date`, `bustimes_end_date` |

All entities: `localgov_bus_data` module as provider, cacheable, no revisions, no bundles. Entity references on `BusTrip` and `BusStopTime` use Drupal entity reference fields (not raw string IDs) so Views can join across entity types.

---

## Data Import Pipeline

**Strategy: bulk GTFS download → GeoJSON/bbox filter → Drupal Migrate**

```
ddev drush bus-times:import [--dry-run] [--full]
```

1. Check source is enabled in settings; skip with notice if not
2. Download the full national GTFS bulk ZIP from BODS (`/timetable/download/gtfs-file/all/`, ~700 MB–1 GB) using HTTP Range requests in chunks; falls back to full-file if Range not supported
3. Validate ZIP entries for path traversal before extraction
4. Extract to a temp directory
5. Run `GtfsFilter` — trim to configured GeoJSON polygon (or bounding box fallback), retaining full trip stop sequences for cross-boundary routes
6. Stage filtered GTFS CSV files into a fixed staging directory (`public://bus-times/gtfs/`)
7. Run all five Migrate migrations in dependency order after rollback:
   `localgov_bus_data_routes` → `localgov_bus_data_stops` → `localgov_bus_data_calendars` → `localgov_bus_data_trips` → `localgov_bus_data_stop_times`
8. Detect and delete entities no longer present in source (incremental: track_changes; full: rollback/re-import)
9. Run NaPTAN enrichment if enabled — downloads NaPTAN CSV, matches by ATCO code, updates indicator/street/locality fields
10. Write result to the `localgov_bus_data_import_log` DB table (timestamp, status, row counts, duration, message)
11. Clean up temp directories

**Execution modes:**

| Mode | Entry point | How |
|---|---|---|
| CLI (synchronous) | `drush bus-times:import` | Full pipeline in one process; `--full` forces rollback/re-import |
| Web UI (Batch API) | Settings form "Import now" | Always full; 5 operations chunked to prevent timeout |
| Cron (scheduled) | `hook_cron()` | Incremental by default; auto-triggers full weekly; 3600 s minimum gap guard |

**Seed command (development only):**

```
ddev drush bus-times:seed
```

Inserts idempotent fixture data: 5 routes, 26 stops, 3 calendars, 40 trips, ~200 stop times. No BODS connection required.

---

## BODS Data Access

### GTFS bulk download (Phases 1–4) ✅ Active

The BODS bulk GTFS endpoint is **publicly accessible with no authentication**:

```
GET https://data.bus-data.dft.gov.uk/timetable/download/gtfs-file/all/
```

The URL is stored in module settings (`source.bulk_gtfs_url`) so it can be overridden. No API key required.

### BODS real-time API (Phase 5)

Real-time data requires a free BODS API key (register at data.bus-data.dft.gov.uk). Two relevant feeds:

| Feed | Format | Update freq | Purpose |
|---|---|---|---|
| SIRI-SM (Stop Monitoring) | XML | On-demand | Departures at a stop with `AimedDepartureTime` + `ExpectedDepartureTime`. Keyed by ATCO code. |
| SIRI-VM (Vehicle Monitoring) | XML | Every 10 s | Live GPS positions for all buses in England. Filter by bounding box. |

**Use SIRI-VM (XML), not GTFS-RT (protobuf)** — avoids the `google/protobuf` PHP extension dependency.

The API key will be stored in a Drupal Key module entity. A new `SiriSmClient` service will be needed for Phase 5 — the old `BodsApiClient` was removed from the codebase as it was built speculatively for the authenticated dataset API, not for real-time feeds.

*Real-time proxy deferred to Phase 5. Departure board shows scheduled times only until then.*

---

## Admin UI

### Settings form (`/admin/config/localgov-bus-data/settings`) ✅ Complete

| Field | Notes |
|---|---|
| Bulk GTFS URL | Default: `https://data.bus-data.dft.gov.uk/timetable/download/gtfs-file/all/` |
| Geographic boundary (GeoJSON) | Polygon/MultiPolygon string; primary filter method |
| Geographic boundary (bbox) | N/S/E/W fallback if no GeoJSON provided |
| Enable this source | Toggle for cron |
| Import Now button | Triggers Batch API full import |
| Import schedule | Cron expression, default `0 3 * * *` |
| Log retention | Days, default 30 |
| NaPTAN enabled / URL | Optional enrichment step |

### Import log (`/admin/config/localgov-bus-data/import-log`) ✅ Complete

Lightweight DB table (not a Views page). Timestamp, status, row counts per migration, duration, message. Capped at 100 rows shown.

### Content admin (`/admin/content/bus-data`) ✅ Complete

Primary "Bus Data" tab under `/admin/content` with five sub-tabs (Stops, Routes, Calendars, Trips, Stop Times).

### Structure admin (`/admin/structure/bus-data/*`) ✅ Complete

Field UI routes for each entity type under Structure.

---

## Views

Four Views shipped in `config/install/`. The stop detail and timetable grid are combined in `localgov_bus_timetable` as separate page displays with a shared attachment.

| View machine name | Page path | Purpose |
|---|---|---|
| `localgov_bus_routes` | `/buses/routes` | All routes — number, name, operator. Exposed filter by route number or name. |
| `localgov_bus_stop_search` | `/buses/stops` | Stop search by name (autocomplete) or area (locality select). Leaflet map attachment showing filtered stops. |
| `localgov_bus_stop_detail` | `/buses/stops/%` | Departures from a stop grouped by trip. Day filter (weekdays/Sat/Sun). Leaflet map showing the stop. Links to timetable carry direction + schedule as query params. |
| `localgov_bus_timetable` | `/buses/routes/%/%` | Full timetable grid (route page): stop × trip matrix via `BusTimetableStyle` plugin. Direction + day exposed filters. Also has a stop-based display. |

**Custom Views plugins:**

- `BusTimetableStyle` — style plugin that pivots `BusStopTime` rows into a stop × trip matrix. Stops as rows, trips as columns ordered by first-stop departure time.
- `LocalitySelect` — filter plugin that builds a select list from distinct non-null `bustimes_locality` values for area browsing.

**URL pattern:** route pages use `agency_id/route_short_name` (e.g. `/buses/routes/CBLGD/60`); stop pages use the ATCO code (e.g. `/buses/stops/030053490`).

**Direction and schedule state** carried as query strings: `?bustimes_direction_id=0&bustimes_weekdays=1`. Default is outbound + weekdays.

All migrations use `track_changes: true` for incremental update detection.

---

## Configuration Schema

All settings under `localgov_bus_data.settings`:

```yaml
source:
  bulk_gtfs_url:     string
  boundary_geojson:  string  # GeoJSON Polygon/MultiPolygon — primary filter
  bounding_box:              # fallback if no GeoJSON
    north: float
    south: float
    east:  float
    west:  float
  enabled: boolean

import:
  schedule:      string   # cron expression, default '0 3 * * *'
  log_retention: integer  # days, default 30

naptan:
  enabled: boolean
  url:     string  # default: https://naptan.api.dft.gov.uk/v1/access-nodes?dataFormat=csv
```

The bulk GTFS download timeout is fixed at 600 seconds in `GtfsDownloader`, not in config.

---

## Database Tables

| Table | Created by | Purpose |
|---|---|---|
| `localgov_bus_route` | Entity type install | GTFS routes |
| `localgov_bus_stop` | Entity type install | GTFS stops (with NaPTAN enrichment fields) |
| `localgov_bus_calendar` | Entity type install | GTFS calendars |
| `localgov_bus_trip` | Entity type install | GTFS trips |
| `localgov_bus_stop_time` | Entity type install | GTFS stop times |
| `localgov_bus_data_import_log` | `hook_schema()` | Import run history |
| `migrate_map_localgov_bus_data_*` | Drupal Migrate (runtime) | Source→entity ID maps |

---

## Dependencies

| Module | Type | Purpose |
|---|---|---|
| `drupal/migrate_plus` | Required | Migration config entities and migration groups |
| `drupal/migrate_source_csv` | Required | CSV source plugin for Migrate |
| `drupal/geofield` | Required | Geo field storage for stop lat/lon + Leaflet rendering |
| `drupal/leaflet` | Required | Leaflet.js map integration for stop search, stop detail, route map |
| `drupal/key` | Phase 5 only | Required for SIRI-SM/SIRI-VM API key storage; not in current codebase |

PHP: Guzzle (bundled with Drupal core), ZipArchive (PHP ext).

---

## Update Hook History

Update hooks 10001–10008 are all superseded. The module is pre-release (alpha) and no production sites have installed it. Fresh installs get the correct schema directly via entity type install and `hook_schema()`. If an update hook is ever needed in future it will start at 10009.

---

## Known Issues / Open Items

- **Holiday dates / calendar_dates.txt** — Services that run only on specific dates (bank holidays, school days, date exceptions) are not imported. Affected services show a "specific dates" note on the timetable page. Full support is on hold.
- **Real-time departures** — The departure board shows scheduled times only. Phase 5 is scoped but not started.
- **Stop URL slugs** — Stop pages use the ATCO code in the URL. Slug-based URLs (e.g. `/buses/stops/carlisle-bus-station`) are a future improvement via Pathauto.
- **SQLite/PostgreSQL** — `GROUP_CONCAT` is used in the module hook layer. Only MySQL/MariaDB is supported.
- **GTFS-RT protobuf** — If GTFS-RT vehicle positions are ever needed instead of SIRI-VM, `google/protobuf` composer package + pre-generated PHP stubs would be required.

---

## Phased Delivery

| Phase | Deliverable | Status |
|---|---|---|
| **1 — Foundation** | Module scaffold, settings form, geographic filtering (GeoJSON + bbox), GtfsFilter service, GtfsDownloader, unit tests | ✅ Complete |
| **2 — Data import** | Five content entities, five Migrate migrations, Drush orchestration (`bus-times:import` + `bus-times:seed`), Batch API import, cron, import log, admin CRUD UI | ✅ Complete |
| **3 — Page displays** | Four Views at `/buses/*`; timetable pivot plugin; weekday/Sat/Sun tab logic; locality browse; autocomplete stop search; Leaflet map attachments | ✅ Complete |
| **4 — NaPTAN enrichment** | Three new BusStop fields (`bustimes_indicator`, `bustimes_street`, `bustimes_locality`); NaPTAN CSV download + match by ATCO code; enriched stop names in search | ✅ Complete |
| **Holiday dates** | Import of `calendar_dates.txt` (bank holidays, school days, date-specific exceptions) | ⏸️ On hold |
| **5 — Real-time departure times** | Server-side SIRI-SM proxy, 30 s JS polling, live departure board on stop pages, graceful fallback to scheduled times | 🔜 Next — 5 days estimated |
| **6 — Live bus position map** | SIRI-VM vehicle positions endpoint, GeoJSON proxy, Leaflet map with moving bus markers, 30 s poll via `leaflet-realtime` | 🔜 Stretch in Phase 5 — risk of overrun |
| **7 — AI natural language widget** | "What time is the next bus from Whitehaven?" — NLP over GTFS data + real-time proxy; scoped to route + stop lookups only | 🔮 Future |
| **8 — Fare information** | NeTEx fares feed from BODS; operator fare structure parsing; A→B fare lookup | 🔮 Future — complex, needs own discovery |
| **9 — Route planning** | A→B journey planner — Traveline API/widget or self-hosted OpenTripPlanner | 🔮 Future — most complex, needs dedicated scoping |

---

## Phase 5 — Real-Time Departure Times

### Scope

Live "due in X mins" departure board on the stop detail page (`/buses/stops/%`), powered by BODS SIRI-SM. The departure board will be layered over the existing scheduled-time Views content using AJAX — the page loads instantly with static data and the live layer replaces/enriches it after page load.

### Architecture

```
Browser JS → /buses/api/stop/{atco}/departures (Drupal proxy)
           → BODS SIRI-SM ?StopPointRef={atco}&api_key={key}
           → Cached XML parse → JSON response
           → JS updates departure times in place
```

- **Server-side proxy** — required to protect the API key and avoid CORS issues
- **30 s server-side cache** — protects BODS rate limits when multiple users hit the same stop
- **AJAX only** — never embed `#cache['max-age'] = 0` in the initial page render (it bubbles up and destroys page caching)
- **Graceful fallback** — if proxy fails or returns empty, show static scheduled times with no visible error

### Key Risks

- **SIRI-SM StopPointRef format** — should be the NaPTAN ATCO code (same as `bustimes_stop_id`) but verify against live Cumberland data before building the UI
- **BODS API key** — Cumberland Council must register at data.bus-data.dft.gov.uk if they haven't already; allow time for approval
- **Rate limiting** — a background queue worker pre-warming the cache for popular stops would be the robust solution, but is scope creep for this phase

### Phase 6 — Live Bus Position Map (Stretch / Risk)

SIRI-VM vehicle positions filtered to Cumberland bounding box, returned as GeoJSON, polled by `leaflet-realtime` every 30 s. Main risk: **vehicle-to-trip matching is unreliable** — the live data does not always indicate which specific journey a vehicle is running, which affects accuracy. Matching on `LineRef` (route number) + direction gives "a bus on route 60" but not ETA-per-stop. Flag to client before starting.

---

## Future Phases — Cumberland/LGD Roadmap

The following items have been requested by Cumberland Council as part of LGD co-funding discussions. All are buildable as additions to this module. Complexity ratings are indicative.

| Item | Complexity | Notes |
|---|---|---|
| Live departure times (Phase 5) | Medium | Part of original estimate. Most straightforward item. |
| Live bus position map (Phase 6) | Medium-High | Vehicle-to-trip matching unreliable. Risk of scope creep. |
| AI natural language widget | Medium | Simpler if scoped to route + stop lookups only. Adding fares or routing increases complexity significantly. |
| Fare information | High | NeTEx format is complex. Fare structures vary wildly by operator. Consistent cross-council coverage is challenging. Needs own discovery phase. |
| Route planning | Very High | OpenTripPlanner requires separate Java infrastructure + hosting. Traveline API/widgets are simpler but have usage limits and no Drupal integration. Needs dedicated scoping before estimating. |

**Recommended build order:** Live departures → Live map → AI widget → Fares → Route planning.

---

## Phase 4 — NaPTAN Enrichment ✅ Complete

GTFS `stops.txt` provides only `stop_id`, `stop_name`, `stop_lat`, `stop_lon`, and `wheelchair_boarding`. Stop names are not unique — "Market Place" and "Church Street" appear at multiple distinct physical stops. NaPTAN provides `Indicator` (e.g. `opp`, `adj`, `Stop A`), `Street`, and `LocalityName` to disambiguate.

A stop entry now reads: **Market Place** (opp Post Office), English Street, Carlisle

NaPTAN data is downloaded from `https://naptan.api.dft.gov.uk/v1/access-nodes?dataFormat=csv`, filtered to Cumbria by ATCO prefix `090`, and matched to existing `BusStop` entities by ATCO code. The `bustimes_services` field (comma-separated route short names) remains as a useful secondary disambiguation signal.

---

## Testing Strategy

Run all custom module tests: `ddev exec vendor/bin/phpunit --testsuite custom`

| Type | Status | What |
|---|---|---|
| **Unit** | ✅ 10 tests passing | `GtfsFilter` (5 tests, real temp CSV files), `GtfsDownloader` (5 tests, mocked HTTP client) |
| **Kernel** | 🔜 Phase 5 | Entity schema, import pipeline, config schema — needs database |
| **Functional** | 🔜 Phase 5 | Settings form, admin routes, permissions — needs full Drupal install |
| **Manual** | ✅ Phase 4 | Verified against Cumberland's live BODS GTFS data and NaPTAN feed |
