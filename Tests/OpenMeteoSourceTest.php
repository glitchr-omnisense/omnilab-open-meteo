<?php

namespace Omnilab\OpenMeteo\Tests;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Exception\ProviderException;
use Omnilab\Exception\RateLimitedException;
use Omnilab\Model\BBox;
use Omnilab\Model\Point;
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
 * (a variable Open-Meteo does not know: 400).
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
                default => 'error-variable.json',
            };

            return new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.$fixture), ['http_code' => 'error-variable.json' === $fixture ? 400 : 200]);
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
            $this->source()->series(new SeriesQuery(['temperature_2m'], self::strasbourg(), step: 'P1M'));
            self::fail('no monthly step');
        } catch (NotSupportedException) {
        }
        self::assertSame([Capability::SERIES, Capability::FORECAST, Capability::HISTORICAL], $this->source()->capabilities());
        self::assertCount(1, $this->calls, 'only the unknown variable was sent');

        $this->expectException(RateLimitedException::class);
        $this->source(new MockResponse('{"error":true,"reason":"Daily API request limit exceeded. Please try again tomorrow."}', ['http_code' => 429]))->series(new SeriesQuery(['temperature_2m'], self::strasbourg()));
    }
}
