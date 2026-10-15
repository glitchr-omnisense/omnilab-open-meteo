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

## Air quality

With `api: air_quality` the source asks Open-Meteo's air-quality API
(`air-quality-api.open-meteo.com/v1/air-quality`; `customer-air-quality-api` with a key): a source
of its own, configured beside the weather's.

```php
$air = (new OpenMeteoSourceFactory($httpClient))->create(['api' => 'air_quality']);
```

| `SeriesQuery` | Open-Meteo |
|---|---|
| `variables` | its names: `pm2_5`, `pm10`, `nitrogen_dioxide`, `ozone`, `carbon_monoxide`, `sulphur_dioxide`, `ammonia`, `dust`, `aerosol_optical_depth`, `uv_index`, the pollens (`birch_pollen`, `grass_pollen`, `alder_pollen`, `mugwort_pollen`, `olive_pollen`, `ragweed_pollen`), the indices (`european_aqi`, `us_aqi`, and each pollutant's, `european_aqi_pm2_5`, `us_aqi_ozone`...) |
| `place` | a `Point` only (the API answers no box) |
| `from`, `to` | `start_date`, `end_date`: whole days, from 2013-01-01 to about five days ahead (a day out of range is the API's 400); today and six days on when not given |
| `step` | `PT1H` (`hourly`), or `P1D` with an aggregation |
| `aggregation` | daily only, and required: `mean`, `max`, `min`, `sum` of the day's hours that have a value (null when none has), the day in the answer's time zone; the series is named `pm2_5_mean`, `european_aqi_max`... (the API has no daily variable: the source makes the days, from one hourly call) |
| `timezone` | as the weather's |

Units are Open-Meteo's: `μg/m³` for the pollutants, `grains/m³` for the pollens, `EAQI` and `USAQI`
for the indices (integers), none for the aerosol optical depth and the UV index. `Series::$place`
is the CAMS grid point that answered (48.6, 7.8 for Strasbourg). `Series::$forecast` is true for a
period from today on, null otherwise: the API serves the past from the models' earlier runs and
reanalyses without saying which. The weather's `models` option is not sent (the air-quality API
chooses its own domain); a 15-minute step, an hourly aggregation and a box are refused before any
call.

## What Open-Meteo does not give here

No box (one point a call here, though Open-Meteo takes several); no marine, flood,
climate-change or ensemble APIs yet (each is an endpoint of its own: a `series()` of them would be
a small addition); no `current` conditions apart from the hour that holds now; no monthly step;
for the air, no choice of the CAMS domain (`domains`: the API's automatic choice).

## Tests

`Tests/Fixtures` holds answers recorded on 2026-10-15 for Strasbourg (48.5734, 7.7521):
tomorrow's hourly temperature from the forecast, a week of daily maxima and precipitation from
the archive, a period across the boundary from both, and the 400 of an unknown variable; from the
air-quality API, PM2.5, PM10, NO2, O3 and the two indices hourly on 2026-10-14, PM10 and the
European index hourly from 10-08 to 10-14, and the 400 of a day before 2013. The tests fix the
source's today (the `today` option) to the day of the recording.

## Verified

The air quality, against the real service on 2026-10-15, for Strasbourg on 2026-10-14 (its own
time zone): PM2.5 from 5.8 to 19 μg/m³ (8.2 at midnight), PM10 7.7 to 24.9, NO2 7 to 41.4, O3 16
to 73 μg/m³, the European index 22 to 49, the US index 41 to 51; from 10-08 to 10-14, the daily
mean of PM10 (6.59, 10.9, 5.65, 9.8, 15.29, 12.73, 12.92 μg/m³) and the daily maximum of the
European index (36, 42, 25, 29, 45, 44, 49); the next five days (120 hours, 111 with a value: the
forecast ends before the last day does); 2013-01-01 answered (7 μg/m³ of PM2.5 at midnight UTC),
2012-12-31 refused (400); `daily=pm2_5_mean` refused by the API (no daily variable); a box refused
by the API ("Bounding box calls not supported"); the customer host answering 401 without a key.
