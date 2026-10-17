# omnilab/open-meteo

Open-Meteo for [omniscient/omnilab](https://github.com/glitchr-studio/omnilab): weather series at a
point - hourly, every 15 minutes or daily, any variable Open-Meteo names - with the unit it
answers in, from the forecast API (92 days back to 16 days ahead) or the historical archive
(ERA5 and its successors, from 1940), joined when a period spans both. And, configured with
`api: air_quality`, the air at a point - PM2.5, PM10, NO2, O3, CO, SO2, dust, the pollens, the
European and US air-quality indices -, hourly or each day's mean, maximum, minimum or sum of the
hours, from 2013 to five days ahead (the CAMS models). No key for non-commercial use.

```php
$meteo = (new OpenMeteoSourceFactory($http))->create();
$tomorrow = new \DateTimeImmutable('tomorrow', new \DateTimeZone('Europe/Paris'));
[$temperature] = $meteo->series(new SeriesQuery(['temperature_2m'], new Point(48.5734, 7.7521), $tomorrow, $tomorrow));
$temperature->unit;                             // °C
$temperature->max();                            // the day's maximum
foreach ($temperature as [$time, $value]) {}    // 24 points, in the point's own time zone

[$max, $rain] = $meteo->series(new SeriesQuery(['temperature_2m', 'precipitation_sum'], $strasbourg,
    new \DateTimeImmutable('2025-07-01'), new \DateTimeImmutable('2025-07-31'), SeriesQuery::DAILY, Series::MAX));

$air = (new OpenMeteoSourceFactory($http))->create(['api' => 'air_quality']);
$day = new \DateTimeImmutable('2026-10-14');
[$pm25, $no2, $aqi] = $air->series(new SeriesQuery(['pm2_5', 'nitrogen_dioxide', 'european_aqi'], $strasbourg, $day, $day));
$pm25->unit;                                    // μg/m³
$pm25->max();                                   // 19 (Strasbourg, 2026-10-14)
$aqi->unit;                                     // EAQI
[$daily] = $air->series(new SeriesQuery(['european_aqi'], $strasbourg, new \DateTimeImmutable('2026-10-08'), $day, SeriesQuery::DAILY, Series::MAX));
$daily->values();                               // 36 42 25 29 45 44 49: each day's highest hour
```

```yaml
omnilab:
    sources:
        meteo:
            factory: open-meteo
            options:
                api_key: ~                 # a commercial key: the calls go to customer-api.open-meteo.com
                timezone: auto             # the times' and days' zone when a query does not say
                models: ~                  # best_match; icon_seamless, ecmwf_ifs025, meteofrance_seamless...
                base_uri: ~                # a self-hosted instance (open-meteo/open-meteo on Docker)
        air:
            factory: open-meteo
            options:
                api: air_quality           # the air-quality API: a source of its own
                api_key: ~                 # a commercial key: customer-air-quality-api.open-meteo.com
```

## Terms of use

Open-Meteo's [terms](https://open-meteo.com/en/terms): the free API is for non-commercial use,
fewer than 10 000 calls a day, 5 000 an hour and 600 a minute; a commercial use needs a
subscription and its key (`api_key`, which sends the calls to the customer hosts), or a
self-hosted instance (`base_uri`). The data is licensed under
[CC BY 4.0](https://creativecommons.org/licenses/by/4.0/): show "Weather data by Open-Meteo.com"
with a link wherever its series are shown. A day's budget spent is a `RateLimitedException`. The
air quality comes from the Copernicus Atmosphere Monitoring Service (CAMS): credit it too.

See [docs/](docs/index.md).

## Responsibility

This package is an independent, unofficial client: it is not affiliated with, endorsed by or
sponsored by Open-Meteo and the weather services it relays. It reaches their service on behalf of whoever runs it, and it does not
redistribute their data beyond the short excerpts recorded as test fixtures. Its MIT licence
covers this package's code only and grants no right to the service or to its data.

Whoever installs and runs this package is responsible for complying with the terms above, for
obtaining whatever they require (an account, a key, a licence or subscription, an attribution or
a citation, a rate limit to respect), and for any use made of what it returns. The authors
publish it free of charge, make no commercial use of it, and accept no liability for how it is
used (see [LICENSE](LICENSE)).

License: MIT.
