# omnilab/open-meteo

## Installation

```sh
composer require omnilab/open-meteo
```

```php
use Omnilab\OpenMeteo\OpenMeteoSourceFactory;

$meteo = (new OpenMeteoSourceFactory($httpClient))->create([
    'api_key' => null,           // optional: a commercial key
    'timezone' => 'auto',        // optional
    'models' => null,            // optional
    'archive_delay' => 5,        // optional: days the archive lags behind today
    'base_uri' => null,          // optional: a self-hosted instance's forecast API
    'archive_uri' => null,       // optional: its archive
]);
```

With the Symfony bundle, the factory is registered as `open-meteo` (see the core's docs).

## Calls

`series(SeriesQuery)` only, at a `Point` (a `BBox` is refused: no `Capability::AREA`):

| `SeriesQuery` | Open-Meteo |
|---|---|
| `variables` | Open-Meteo's names: `temperature_2m`, `relative_humidity_2m`, `precipitation`, `rain`, `snowfall`, `cloud_cover`, `wind_speed_10m`, `wind_direction_10m`, `shortwave_radiation`... (see its documentation) |
| `place` | `latitude`, `longitude`, and `elevation` when the point has one |
| `from`, `to` | `start_date`, `end_date`: whole days, both included; `from` today and `to` six days later when not given |
| `step` | `PT1H` → `hourly`, `PT15M` → `minutely_15` (where the models have it), `P1D` → `daily` |
| `aggregation` | daily only: `mean`, `max`, `min`, `sum` become the variable's suffix (`temperature_2m` → `temperature_2m_max`); a name that ends with one already (`precipitation_sum`) is kept |
| `timezone` | `timezone`: `auto` (the point's own), `GMT`, `Europe/Paris`...; the option's when null |

Times are asked as UNIX times (`timeformat=unixtime`) and given as `DateTimeImmutable` in the time
zone of the answer: exact across a change of summer time. The unit of each series is Open-Meteo's
(`°C`, `mm`, `%`, `km/h`, `W/m²`).

**Which API**: a period that ends more than `archive_delay` days ago (five) goes to the archive
(`archive-api.open-meteo.com/v1/archive`); one that ends later to the forecast
(`api.open-meteo.com/v1/forecast`), which answers from 92 days ago; a period that starts in the
archive and ends later is asked of both - the archive up to the day before the boundary, the
forecast from it - and joined, point after point. `Series::$forecast` is false for the archive
alone, true for a forecast that starts today or later, null otherwise.

`Series::$place` is the point of the model's grid that answered (its latitude, longitude and
elevation), not the point asked; for a joined period, the forecast's.

## What Open-Meteo does not give here

No box (one point a call here, though Open-Meteo takes several); no air quality, marine,
flood, climate-change or ensemble APIs yet (each is an endpoint of its own: a `series()` of them
would be a small addition); no `current` conditions apart from the hour that holds now; no
monthly step.

## Tests

`Tests/Fixtures` holds answers recorded on 2026-10-15 for Strasbourg (48.5734, 7.7521):
tomorrow's hourly temperature from the forecast, a week of daily maxima and precipitation from
the archive, a period across the boundary from both, and the 400 of an unknown variable. The
tests fix the source's today (the `today` option) to the day of the recording.
