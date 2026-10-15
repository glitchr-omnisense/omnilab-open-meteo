<?php

namespace Omnilab\OpenMeteo\Tests;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Exception\ProviderException;
use Omnilab\Exception\RateLimitedException;
use Omnilab\Model\BBox;
use Omnilab\Model\Identifier;
use Omnilab\Model\Point;
use Omnilab\Model\Scheme;
use Omnilab\Model\Series;
use Omnilab\OpenMeteo\OpenMeteoSourceFactory;
use Omnilab\Source\Capability;
use Omnilab\Source\SeriesQuery;
use Omnilab\Source\SourceInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Fixtures recorded from Open-Meteo on 2026-10-15 for Strasbourg
 * (48.5734, 7.7521), in its own time zone (timezone=auto, Europe/Paris):
 * forecast-hourly.json (api.open-meteo.com: the hourly temperature on
 * 2026-10-16), archive-daily.json (archive-api.open-meteo.com: the daily
 * maximum and precipitation, 2026-09-01 to 09-07), split-archive.json and
 * split-forecast.json (the daily maximum from 10-05 to 10-09 from the
 * archive, from 10-10 to 10-12 from the forecast), error-variable.json
 * (a variable Open-Meteo does not know: 400); from its air-quality API
 * (air-quality-api.open-meteo.com), air-quality-hourly.json (PM2.5, PM10,
 * NO2, O3, the European and US indices, hourly on 2026-10-14),
 * air-quality-week.json (PM10 and the European index, hourly from 10-08 to
 * 10-14), air-quality-error-range.json (a day before 2013: 400).
 */
final class OpenMeteoSourceTest extends TestCase
{
    /** @var list<array{string, array<string, string>}> */
    private array $calls = [];

    private static function strasbourg(): Point
    {
        return new Point(48.5734, 7.7521);
    }

    private function source(?MockResponse $response = null, array $options = []): SourceInterface
    {
        $http = new MockHttpClient(function (string $method, string $url) use ($response): MockResponse {
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            $endpoint = parse_url($url, \PHP_URL_HOST).parse_url($url, \PHP_URL_PATH);
            $this->calls[] = [$endpoint, $query];
            if ($response) {
                return $response;
            }
            // The customer hosts answer as the free ones.
            $fixture = match ([str_replace('customer-', '', $endpoint), $query['hourly'] ?? $query['daily'] ?? null, $query['start_date'] ?? null, $query['end_date'] ?? null]) {
                ['api.open-meteo.com/v1/forecast', 'temperature_2m', '2026-10-16', '2026-10-16'] => 'forecast-hourly.json',
                ['archive-api.open-meteo.com/v1/archive', 'temperature_2m_max,precipitation_sum', '2026-09-01', '2026-09-07'] => 'archive-daily.json',
                ['archive-api.open-meteo.com/v1/archive', 'temperature_2m_max', '2026-10-05', '2026-10-09'] => 'split-archive.json',
                ['api.open-meteo.com/v1/forecast', 'temperature_2m_max', '2026-10-10', '2026-10-12'] => 'split-forecast.json',
                ['air-quality-api.open-meteo.com/v1/air-quality', 'pm2_5,pm10,nitrogen_dioxide,ozone,european_aqi,us_aqi', '2026-10-14', '2026-10-14'] => 'air-quality-hourly.json',
                ['air-quality-api.open-meteo.com/v1/air-quality', 'pm10,european_aqi', '2026-10-08', '2026-10-14'] => 'air-quality-week.json',
                ['air-quality-api.open-meteo.com/v1/air-quality', 'pm2_5', '2026-10-14', '2026-10-14'] => 'air-quality-hourly.json',
                ['air-quality-api.open-meteo.com/v1/air-quality', 'pm2_5', '2012-12-31', '2012-12-31'] => 'air-quality-error-range.json',
                default => 'error-variable.json',
            };

            return new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.$fixture), ['http_code' => str_contains($fixture, 'error') ? 400 : 200]);
        });

        return (new OpenMeteoSourceFactory($http))->create($options + ['today' => '2026-10-15']);
    }

    public function testTomorrowsHourlyTemperatureFromTheForecast(): void
    {
        $series = $this->source()->series(new SeriesQuery(['temperature_2m'], self::strasbourg(), new \DateTimeImmutable('2026-10-16'), new \DateTimeImmutable('2026-10-16')));

        self::assertCount(1, $series);
        [$temperature] = $series;
        self::assertSame('temperature_2m', $temperature->variable);
        self::assertSame('°C', $temperature->unit);
        self::assertSame('PT1H', $temperature->step);
        self::assertNull($temperature->aggregation);
        self::assertTrue($temperature->forecast);
        self::assertSame('open-meteo', $temperature->source);
        self::assertCount(24, $temperature);
        self::assertSame('2026-10-16T00:00:00+02:00', $temperature->points[0][0]->format(\DATE_ATOM), 'local midnight, in the point\'s own time zone');
        self::assertSame(12.2, $temperature->points[0][1]);
        self::assertSame(20.4, $temperature->max());
        self::assertSame(10.4, $temperature->min());
        self::assertSame(20.4, $temperature->at(new \DateTimeImmutable('2026-10-16T17:00:00+02:00')));
        self::assertEquals(new Point(48.57047, 7.734802, 142.0), $temperature->place, 'the point of the model\'s grid that answered');

        [$endpoint, $query] = $this->calls[0];
        self::assertSame('api.open-meteo.com/v1/forecast', $endpoint);
        self::assertSame(['latitude' => '48.5734', 'longitude' => '7.7521', 'hourly' => 'temperature_2m', 'start_date' => '2026-10-16', 'end_date' => '2026-10-16', 'timezone' => 'auto', 'timeformat' => 'unixtime'], $query);
    }

    public function testDailyAggregatesFromTheArchive(): void
    {
        $query = new SeriesQuery(['temperature_2m', 'precipitation_sum'], self::strasbourg(), new \DateTimeImmutable('2026-09-01'), new \DateTimeImmutable('2026-09-07'), SeriesQuery::DAILY, Series::MAX);
        [$max, $rain] = $this->source()->series($query);

        self::assertSame('archive-api.open-meteo.com/v1/archive', $this->calls[0][0]);
        self::assertSame('temperature_2m_max,precipitation_sum', $this->calls[0][1]['daily'], 'the aggregation names the variable; a name that already says its own is kept');
        self::assertSame(['temperature_2m_max', '°C', Series::MAX], [$max->variable, $max->unit, $max->aggregation]);
        self::assertSame(['precipitation_sum', 'mm', Series::SUM], [$rain->variable, $rain->unit, $rain->aggregation]);
        self::assertFalse($max->forecast, 'the archive: a reanalysis, not a forecast');
        self::assertSame([25.4, 25.6, 28.1, 32.0, 25.6, 26.5, 31.0], $max->values());
        self::assertSame('2026-09-04', $max->points[3][0]->format('Y-m-d'));
        self::assertSame(7.5, $rain->max());
        self::assertSame('P1D', $max->step);

        $this->expectException(NotSupportedException::class);
        $this->expectExceptionMessage('aggregate by "median"');
        $this->source()->series($query->with(['aggregation' => 'median']));
    }

    public function testAPeriodAcrossTheArchiveAndTheForecastIsJoined(): void
    {
        $query = new SeriesQuery(['temperature_2m'], self::strasbourg(), new \DateTimeImmutable('2026-10-05'), new \DateTimeImmutable('2026-10-12'), SeriesQuery::DAILY, Series::MAX);
        [$max] = $this->source()->series($query);

        self::assertSame(['archive-api.open-meteo.com/v1/archive', 'api.open-meteo.com/v1/forecast'], array_column($this->calls, 0));
        self::assertSame(['2026-10-05', '2026-10-09'], [$this->calls[0][1]['start_date'], $this->calls[0][1]['end_date']], 'the archive up to five days before today');
        self::assertSame(['2026-10-10', '2026-10-12'], [$this->calls[1][1]['start_date'], $this->calls[1][1]['end_date']]);
        self::assertSame(['2026-10-05', '2026-10-06', '2026-10-07', '2026-10-08', '2026-10-09', '2026-10-10', '2026-10-11', '2026-10-12'], array_map(static fn (\DateTimeImmutable $t) => $t->format('Y-m-d'), $max->times()));
        self::assertSame([22.2, 22.2, 23.6, 15.8, 13.1, 17.3, 15.6, 18.6], $max->values());
        self::assertNull($max->forecast, 'mixed');
    }

    public function testACommercialKeyGoesToTheCustomerHosts(): void
    {
        $this->source(null, ['api_key' => 'secret'])->series(new SeriesQuery(['temperature_2m'], self::strasbourg(), new \DateTimeImmutable('2026-10-16'), new \DateTimeImmutable('2026-10-16')));

        self::assertSame('customer-api.open-meteo.com/v1/forecast', $this->calls[0][0]);
        self::assertSame('secret', $this->calls[0][1]['apikey']);
    }

    public function testASelfHostedInstance(): void
    {
        $source = (new OpenMeteoSourceFactory(new MockHttpClient(function (string $method, string $url): MockResponse {
            $this->calls[] = [$url, []];

            return new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/forecast-hourly.json'));
        })))->create(['base_uri' => 'http://meteo.internal:8080/v1/', 'today' => '2026-10-15']);
        [$temperature] = $source->series(new SeriesQuery(['temperature_2m'], self::strasbourg(), new \DateTimeImmutable('2026-10-16'), new \DateTimeImmutable('2026-10-16')));

        self::assertStringStartsWith('http://meteo.internal:8080/v1/forecast?', $this->calls[0][0]);
        self::assertCount(24, $temperature);
    }

    public function testWhatOpenMeteoRefusesAndWhatItCannotDo(): void
    {
        try {
            $this->source()->series(new SeriesQuery(['nope'], self::strasbourg(), new \DateTimeImmutable('2026-10-16'), new \DateTimeImmutable('2026-10-16')));
            self::fail('an unknown variable is a 400');
        } catch (ProviderException $e) {
            self::assertSame(400, $e->status);
            self::assertStringContainsString('invalid String value nope', $e->getMessage(), 'Open-Meteo\'s reason');
        }
        try {
            $this->source()->series(new SeriesQuery(['temperature_2m'], new BBox(7.68, 48.53, 7.83, 48.64)));
            self::fail('a box is not a point');
        } catch (NotSupportedException) {
        }
        try {
            $this->source()->series(new SeriesQuery(['temperature_2m'], Identifier::of(Scheme::GHCN, 'FR000007190')));
            self::fail('a station is not a grid point');
        } catch (NotSupportedException) {
        }
        try {
            $this->source()->series(new SeriesQuery(['temperature_2m'], self::strasbourg(), step: 'P1M'));
            self::fail('no monthly step');
        } catch (NotSupportedException) {
        }
        self::assertSame([Capability::SERIES, Capability::FORECAST, Capability::HISTORICAL], $this->source()->capabilities());
        self::assertCount(1, $this->calls, 'only the unknown variable was sent');

        $this->expectException(RateLimitedException::class);
        $this->source(new MockResponse('{"error":true,"reason":"Daily API request limit exceeded. Please try again tomorrow."}', ['http_code' => 429]))->series(new SeriesQuery(['temperature_2m'], self::strasbourg()));
    }

    public function testAirQualityHourlyAtAPoint(): void
    {
        $day = new \DateTimeImmutable('2026-10-14');
        $series = $this->source(null, ['api' => 'air_quality'])->series(new SeriesQuery(['pm2_5', 'pm10', 'nitrogen_dioxide', 'ozone', 'european_aqi', 'us_aqi'], self::strasbourg(), $day, $day));

        [$endpoint, $query] = $this->calls[0];
        self::assertSame('air-quality-api.open-meteo.com/v1/air-quality', $endpoint);
        self::assertSame(['latitude' => '48.5734', 'longitude' => '7.7521', 'hourly' => 'pm2_5,pm10,nitrogen_dioxide,ozone,european_aqi,us_aqi', 'start_date' => '2026-10-14', 'end_date' => '2026-10-14', 'timezone' => 'auto', 'timeformat' => 'unixtime'], $query, 'one call for every variable');
        self::assertSame(['pm2_5', 'pm10', 'nitrogen_dioxide', 'ozone', 'european_aqi', 'us_aqi'], array_map(static fn (Series $s) => $s->variable, $series), 'in the order asked');
        self::assertSame(['μg/m³', 'μg/m³', 'μg/m³', 'μg/m³', 'EAQI', 'USAQI'], array_map(static fn (Series $s) => $s->unit, $series));
        [$pm25, , $no2, $ozone, $european, $us] = $series;
        self::assertCount(24, $pm25);
        self::assertSame(['2026-10-14T00:00:00+02:00', 8.2], [$pm25->points[0][0]->format(\DATE_ATOM), $pm25->points[0][1]], 'local midnight');
        self::assertSame([5.8, 19.0], [$pm25->min(), $pm25->max()]);
        self::assertSame([7.0, 41.4], [$no2->min(), $no2->max()]);
        self::assertSame(73.0, $ozone->max());
        self::assertSame([22, 49], [$european->min(), $european->max()], 'an index is an integer');
        self::assertSame(51, $us->max());
        self::assertSame(['PT1H', null, null, 'open-meteo'], [$pm25->step, $pm25->aggregation, $pm25->forecast, $pm25->source], 'the past: the models\' runs or reanalyses, not said');
        self::assertEquals(new Point(48.6, 7.799999, 142.0), $pm25->place, 'the point of the CAMS grid that answered');
    }

    public function testAirQualityDailyFromTheHours(): void
    {
        $air = $this->source(null, ['api' => 'air_quality']);
        $query = new SeriesQuery(['pm10', 'european_aqi'], self::strasbourg(), new \DateTimeImmutable('2026-10-08'), new \DateTimeImmutable('2026-10-14'), SeriesQuery::DAILY, Series::MEAN);
        [$pm10, $mean] = $air->series($query);
        [, $max] = $air->series($query->with(['aggregation' => Series::MAX]));

        self::assertSame('pm10,european_aqi', $this->calls[0][1]['hourly'], 'the hours asked, the days made of them');
        self::assertSame(['pm10_mean', 'μg/m³', 'P1D', Series::MEAN], [$pm10->variable, $pm10->unit, $pm10->step, $pm10->aggregation]);
        self::assertSame([6.59, 10.9, 5.65, 9.8, 15.29, 12.73, 12.92], $pm10->values());
        self::assertSame(['2026-10-08T00:00:00+02:00', '2026-10-14T00:00:00+02:00'], [$pm10->points[0][0]->format(\DATE_ATOM), $pm10->points[6][0]->format(\DATE_ATOM)]);
        self::assertSame([22.25, 26.63, 19.5, 22.67, 33.67, 30.17, 31.29], $mean->values());
        self::assertSame(['european_aqi_max', [36, 42, 25, 29, 45, 44, 49]], [$max->variable, $max->values()]);
    }

    public function testWhatTheAirQualityApiRefuses(): void
    {
        $air = $this->source(null, ['api' => 'air_quality']);
        $refused = [
            'a box' => new SeriesQuery(['pm2_5'], new BBox(7.68, 48.53, 7.83, 48.64)),
            'a day without an aggregation' => new SeriesQuery(['pm2_5'], self::strasbourg(), step: SeriesQuery::DAILY),
            'an hour aggregated' => new SeriesQuery(['pm2_5'], self::strasbourg(), aggregation: Series::MAX),
            '15 minutes' => new SeriesQuery(['pm2_5'], self::strasbourg(), step: 'PT15M'),
            'a median' => new SeriesQuery(['pm2_5'], self::strasbourg(), step: SeriesQuery::DAILY, aggregation: 'median'),
        ];
        foreach ($refused as $what => $query) {
            try {
                $air->series($query);
                self::fail($what);
            } catch (NotSupportedException) {
            }
        }
        self::assertSame([], $this->calls, 'before any call');
        try {
            $air->series(new SeriesQuery(['pm2_5'], self::strasbourg(), new \DateTimeImmutable('2012-12-31'), new \DateTimeImmutable('2012-12-31')));
            self::fail('before 2013');
        } catch (ProviderException $e) {
            self::assertStringContainsString('out of allowed range from 2013-01-01', $e->getMessage());
        }
        $this->source(null, ['api' => 'air_quality', 'api_key' => 'secret', 'models' => 'icon_seamless'])->series(new SeriesQuery(['pm2_5'], self::strasbourg(), new \DateTimeImmutable('2026-10-14'), new \DateTimeImmutable('2026-10-14')));
        self::assertSame('customer-air-quality-api.open-meteo.com/v1/air-quality', $this->calls[1][0], 'a key: the customer host');
        self::assertArrayNotHasKey('models', $this->calls[1][1], 'the weather\'s models are not the air quality\'s');
        self::assertSame([Capability::SERIES, Capability::FORECAST, Capability::HISTORICAL], $air->capabilities());
        $this->expectException(\InvalidArgumentException::class);
        $this->source(null, ['api' => 'marine']);
    }
}
