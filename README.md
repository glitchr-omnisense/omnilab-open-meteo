# omnilab/open-meteo

Open-Meteo for [glitchr/omnilab](https://github.com/glitchr-studio/omnilab): weather series at a
point - hourly, every 15 minutes or daily, any variable Open-Meteo names - with the unit it
answers in, from the forecast API (92 days back to 16 days ahead) or the historical archive
(ERA5 and its successors, from 1940), joined when a period spans both. No key for non-commercial
use.

```php
$meteo = (new OpenMeteoSourceFactory($http))->create();
$tomorrow = new \DateTimeImmutable('tomorrow', new \DateTimeZone('Europe/Paris'));
[$temperature] = $meteo->series(new SeriesQuery(['temperature_2m'], new Point(48.5734, 7.7521), $tomorrow, $tomorrow));
$temperature->unit;                             // °C
$temperature->max();                            // the day's maximum
foreach ($temperature as [$time, $value]) {}    // 24 points, in the point's own time zone

[$max, $rain] = $meteo->series(new SeriesQuery(['temperature_2m', 'precipitation_sum'], $strasbourg,
    new \DateTimeImmutable('2025-07-01'), new \DateTimeImmutable('2025-07-31'), SeriesQuery::DAILY, Series::MAX));
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
```

## Terms of use

Open-Meteo's [terms](https://open-meteo.com/en/terms): the free API is for non-commercial use,
fewer than 10 000 calls a day, 5 000 an hour and 600 a minute; a commercial use needs a
subscription and its key (`api_key`, which sends the calls to the customer hosts), or a
self-hosted instance (`base_uri`). The data is licensed under
[CC BY 4.0](https://creativecommons.org/licenses/by/4.0/): show "Weather data by Open-Meteo.com"
with a link wherever its series are shown. A day's budget spent is a `RateLimitedException`.

See [docs/](docs/index.md).

License: MIT.
