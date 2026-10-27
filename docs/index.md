# omnilab/open-meteo

Open-Meteo's forecast, archive and air-quality APIs (`https://api.open-meteo.com/v1/`,
`https://archive-api.open-meteo.com/v1/`, `https://air-quality-api.open-meteo.com/v1/`) for
[omnisense/omnilab](https://github.com/glitchr-omnisense/omnilab): hourly, 15-minute and daily
weather at a point, forecast and archive (from 1940); the air quality (PM2.5, PM10, NO2, O3, the
European and US indices, pollens), hourly or daily, from 2013; as series. No key for
non-commercial use.

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

## Credentials

None for non-commercial use: Open-Meteo's free API takes no key, within 10,000 calls a day, 5,000
an hour and 600 a minute. A commercial use needs a subscription and its key (`api_key`): the
calls then go to the customer hosts (`customer-api`, `customer-archive-api`,
`customer-air-quality-api`). A self-hosted instance is named by `base_uri` (and `archive_uri`).

## Options

The factory's options, with the defaults it applies (`OpenMeteoSourceFactory`):

| Option | Type | Default | Required | Effect |
|---|---|---|---|---|
| `api` | `string` | `weather` | no | `weather`, or `air_quality` (the air-quality API: a source of its own); another is an `\InvalidArgumentException` at `create()` |
| `api_key` | `?string` | null | no | a commercial key, sent as `apikey`; with it the defaults of `base_uri` and `archive_uri` are the customer hosts |
| `timezone` | `string` | `auto` | no | the zone times and days are given in when a query does not say: `auto` (the point's own), `GMT`, `Europe/Paris`... |
| `models` | `?string` | null | no | the weather models asked (`icon_seamless`, `ecmwf_ifs025`...); null: Open-Meteo's `best_match`; not sent to the air-quality API |
| `archive_delay` | `int` | `5` | no | how many days the archive lags behind today: a period that ends earlier goes to the archive |
| `today` | `?string` | null | no | the day taken for today (`Y-m-d`), for tests; null: the real one |
| `archive_uri` | `string` | `https://archive-api.open-meteo.com/v1/` | no | the archive API (weather only) |
| `mailto` | `?string` | null | no | a contact address, sent in the User-Agent |
| `user_agent` | `?string` | null | no | the whole User-Agent, in place of the one made from `mailto` |
| `throttle` | `float` | `0` | no | seconds left between two calls: the limits are counted by the minute, the hour and the day |
| `base_uri` | `string` | `https://api.open-meteo.com/v1/` | no | the forecast API; for `air_quality`, the air-quality API (`https://air-quality-api.open-meteo.com/v1/`); the customer hosts with `api_key` |

## Capabilities

`capabilities()` answers `series`, `forecast`, `historical`; an operation it does not answer throws `NotSupportedException` before any call.

| Capability | Supported | |
|---|---|---|
| `entity` | no |  |
| `search` | no |  |
| `series` | yes | at a `Point`: the weather hourly, every 15 minutes or daily (an aggregation a suffix of the variable), from the archive, the forecast, or both joined; the air quality hourly, or days made from the hours |
| `products` | no |  |
| `occurrences` | no |  |
| `datasets` | no |  |
| `jobs` | no |  |
| `open` | no |  |
| `structure` | no |  |
| `cross_references` | no |  |
| `forecast` | yes | the forecast API, up to 16 days ahead; the air quality about five days ahead |
| `historical` | yes | the archive from 1940, the forecast API from 92 days back; the air quality from 2013-01-01 |
| `area` | no | a box is refused before any call |
| `station` | no | a station is refused before any call: the model's grid point answers |
| `cloud_cover` | no |  |

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

## Example

```php
<?php

require __DIR__.'/vendor/autoload.php';

use Omnilab\Model\Point;
use Omnilab\Model\Series;
use Omnilab\OpenMeteo\OpenMeteoSourceFactory;
use Omnilab\Source\SeriesQuery;
use Symfony\Component\HttpClient\HttpClient;

$http = HttpClient::create();
$meteo = (new OpenMeteoSourceFactory($http))->create(['mailto' => 'you@example.org']);
$air = (new OpenMeteoSourceFactory($http))->create(['mailto' => 'you@example.org', 'api' => 'air_quality']);
$strasbourg = new Point(48.5734, 7.7521);

// From the archive into the forecast: two calls, joined.
[$max] = $meteo->series(new SeriesQuery(['temperature_2m'], $strasbourg, new DateTimeImmutable('2026-10-19'), new DateTimeImmutable('2026-10-29'), SeriesQuery::DAILY, Series::MAX));
printf("%s (%s) at %s, forecast %s:\n  %s\n", $max->variable, $max->unit, $max->place, var_export($max->forecast, true), implode(' ', array_map(static fn (array $p) => $p[0]->format('d').': '.$p[1], $max->points)));

[$pm25] = $air->series(new SeriesQuery(['pm2_5'], $strasbourg, new DateTimeImmutable('2026-10-20'), new DateTimeImmutable('2026-10-26'), SeriesQuery::DAILY, Series::MAX));
printf("%s (%s) at %s:\n  %s\n", $pm25->variable, $pm25->unit, $pm25->place, implode(' ', array_map(static fn (array $p) => $p[0]->format('d').': '.$p[1], $pm25->points)));
```

```
temperature_2m_max (°C) at 48.57047,7.734802, forecast NULL:
  19: 15.9 20: 12.9 21: 11.3 22: 15.6 23: 16.1 24: 13.1 25: 15.3 26: 13.4 27: 18.2 28: 14.9 29: 14.2
pm2_5_max (μg/m³) at 48.6,7.799999:
  20: 31.4 21: 29.1 22: 6.4 23: 8 24: 4.6 25: 5.9 26: 9.5
```

(as answered on 2026-10-27: three calls - the archive to the 21st, the forecast from the 22nd,
the air's hours; `forecast` is null for the joined series, the grid points are the models')

```yaml
# config/packages/omnilab.yaml
omnilab:
    sources:
        meteo: { factory: open-meteo, options: { mailto: '%env(OMNILAB_MAILTO)%', api_key: '%env(default::OPEN_METEO_API_KEY)%' } }
        air: { factory: open-meteo, options: { mailto: '%env(OMNILAB_MAILTO)%', api: air_quality } }
```

## Errors

Every call goes through `HttpSource`: a 404 or a 410 (and a 204) is null - an empty page for a list -; a 429 is a `RateLimitedException` (`retryAfter` from `Retry-After`); a 5xx, a time-out or a host that does not answer an `UnavailableException`; any other 4xx a `ProviderException` with its `status` and what the service said; an answer that is not JSON a `ProviderException`, a web page answered for JSON an `UnavailableException`.

Open-Meteo's errors are `{"error": true, "reason": ...}`, read into the message (an unknown
variable, a day out of range: its 400); an answer without the block asked a `ProviderException`
with its reason; a day's budget spent is its 429, a `RateLimitedException`. Before any call
(`NotSupportedException`): a box or a station, a step other than `PT1H`, `PT15M`, `P1D`, an
aggregation of anything but days, an aggregation other than mean, max, min, sum; for the air,
a 15-minute step and days without an aggregation.

## Tests

`Tests/Fixtures` holds answers recorded on 2026-10-15 for Strasbourg (48.5734, 7.7521):
tomorrow's hourly temperature from the forecast, a week of daily maxima and precipitation from
the archive, a period across the boundary from both, and the 400 of an unknown variable; from the
air-quality API, PM2.5, PM10, NO2, O3 and the two indices hourly on 2026-10-14, PM10 and the
European index hourly from 10-08 to 10-14, and the 400 of a day before 2013. The tests fix the
source's today (the `today` option) to the day of the recording.

## Verified, and not

The air quality, against the real service on 2026-10-15, for Strasbourg on 2026-10-14 (its own
time zone): PM2.5 from 5.8 to 19 μg/m³ (8.2 at midnight), PM10 7.7 to 24.9, NO2 7 to 41.4, O3 16
to 73 μg/m³, the European index 22 to 49, the US index 41 to 51; from 10-08 to 10-14, the daily
mean of PM10 (6.59, 10.9, 5.65, 9.8, 15.29, 12.73, 12.92 μg/m³) and the daily maximum of the
European index (36, 42, 25, 29, 45, 44, 49); the next five days (120 hours, 111 with a value: the
forecast ends before the last day does); 2013-01-01 answered (7 μg/m³ of PM2.5 at midnight UTC),
2012-12-31 refused (400); `daily=pm2_5_mean` refused by the API (no daily variable); a box refused
by the API ("Bounding box calls not supported"); the customer host answering 401 without a key.

The weather, on 2026-10-15: tomorrow's hourly temperature in Strasbourg (the core's installation
script) and the recordings above. Again on 2026-10-27: the daily maxima of 19 to 29 October,
the archive and the forecast joined, and the daily maxima of PM2.5 of 20 to 26 October (the
example above); the core's installation script.

Not verified: a commercial key (none used: the customer host's 401 only), a self-hosted instance,
the 15-minute step against the models that have it, the day's budget spent.

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by Open-Meteo or the Copernicus Atmosphere Monitoring Service. Open-Meteo's
[terms](https://open-meteo.com/en/terms): the free API for non-commercial use within its limits,
a subscription for a commercial one; its data under [CC BY 4.0](https://creativecommons.org/licenses/by/4.0/)
("Weather data by Open-Meteo.com" with a link), CAMS credited for the air quality, as the README's
[Terms of use](https://github.com/glitchr-omnisense/omnilab-open-meteo#terms-of-use) says. Whoever
runs the package is responsible for complying with them and for any use made of what it returns:
see the README's [Responsibility](https://github.com/glitchr-omnisense/omnilab-open-meteo#responsibility)
section.
