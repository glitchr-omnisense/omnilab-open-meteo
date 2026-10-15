<?php

namespace Omnilab\OpenMeteo;

use Omnilab\Exception\NotSupportedException;
use Omnilab\Exception\ProviderException;
use Omnilab\Model\Point;
use Omnilab\Model\Series;
use Omnilab\Source\Capability;
use Omnilab\Source\HttpSource;
use Omnilab\Source\SeriesQuery;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Open-Meteo (open-meteo.com): weather series at a point - hourly,
 * every 15 minutes or daily, any variable Open-Meteo names
 * ("temperature_2m", "precipitation", "wind_speed_10m", "cloud_cover"...),
 * with the unit it answers in. Or, configured with the air-quality API
 * ($api = AIR_QUALITY), air-quality series at a point: hourly, or daily as
 * the mean, maximum, minimum or sum of the hours ("pm2_5", "pm10",
 * "nitrogen_dioxide", "ozone", "european_aqi", "us_aqi", the pollens...),
 * from the CAMS models, 2013 to five days ahead.
 *
 * Two APIs, chosen by the period: the forecast API (api.open-meteo.com)
 * from 92 days ago to 16 days ahead, the historical archive
 * (archive-api.open-meteo.com: ERA5 and its successors, from 1940) for
 * what ended more than $archiveDelay days ago; a period that starts in the
 * archive and ends in the forecast's window is asked of both and joined.
 *
 * Free without a key for non-commercial use (open-meteo.com/en/terms:
 * fewer than 10 000 calls a day, 5 000 an hour, 600 a minute, and credit
 * given to Open-Meteo). With a key (api_key), the calls go to the
 * customer-api hosts; a self-hosted instance is a base_uri away.
 */
final class OpenMeteoSource extends HttpSource
{
    public const BASE_URI = 'https://api.open-meteo.com/v1/';
    public const ARCHIVE_URI = 'https://archive-api.open-meteo.com/v1/';
    public const CUSTOMER_URI = 'https://customer-api.open-meteo.com/v1/';
    public const CUSTOMER_ARCHIVE_URI = 'https://customer-archive-api.open-meteo.com/v1/';
    public const AIR_QUALITY_URI = 'https://air-quality-api.open-meteo.com/v1/';
    public const CUSTOMER_AIR_QUALITY_URI = 'https://customer-air-quality-api.open-meteo.com/v1/';

    /** The API a source asks: the weather (forecast and archive), or the air quality. */
    public const WEATHER = 'weather';
    public const AIR_QUALITY = 'air_quality';

    /** The first day the air-quality API answers (CAMS European and global, as Open-Meteo serves them). */
    public const AIR_QUALITY_FIRST_DAY = '2013-01-01';

    /** How far back the forecast API answers, in days. */
    public const FORECAST_PAST_DAYS = 92;

    /** The steps Open-Meteo serves, and the block of its answer each comes in. */
    private const STEPS = [SeriesQuery::HOURLY => 'hourly', SeriesQuery::DAILY => 'daily', 'PT15M' => 'minutely_15'];

    /** Open-Meteo's suffixes of a daily variable, and the aggregation each means. */
    private const AGGREGATIONS = ['_mean' => Series::MEAN, '_max' => Series::MAX, '_min' => Series::MIN, '_sum' => Series::SUM];

    /**
     * @param string      $timezone     what times and days are given in when the query does not say: "auto" (the point's own), "GMT", "Europe/Paris"...
     * @param int         $archiveDelay how many days the archive lags behind today
     * @param string|null $models       the weather models to ask for ("best_match" when null)
     * @param string|null $today        the day taken for today (a test's), the real one when null
     * @param string      $api          WEATHER, or AIR_QUALITY: then $baseUri is the air-quality API's, $archiveUri and $models are not used
     */
    public function __construct(
        HttpClientInterface $http,
        string $baseUri = self::BASE_URI,
        private readonly string $archiveUri = self::ARCHIVE_URI,
        private readonly ?string $apiKey = null,
        private readonly string $timezone = 'auto',
        private readonly int $archiveDelay = 5,
        private readonly ?string $models = null,
        private readonly ?string $today = null,
        array $headers = [],
        float $throttle = 0.0,
        private readonly string $api = self::WEATHER,
    ) {
        if (!\in_array($api, [self::WEATHER, self::AIR_QUALITY], true)) {
            throw new \InvalidArgumentException(\sprintf('Open-Meteo\'s APIs here are "%s" and "%s", not "%s".', self::WEATHER, self::AIR_QUALITY, $api));
        }
        parent::__construct($http, $baseUri, $headers, $throttle);
    }

    public function getName(): string
    {
        return 'open-meteo';
    }

    public function capabilities(): array
    {
        return [Capability::SERIES, Capability::FORECAST, Capability::HISTORICAL];
    }

    public function series(SeriesQuery $query): array
    {
        if (self::AIR_QUALITY === $this->api) {
            return $this->airQuality($query);
        }
        if (!$query->place instanceof Point) {
            throw NotSupportedException::operation('open-meteo', 'serve a series over an area (a Point only)');
        }
        $block = self::STEPS[$query->step] ?? throw NotSupportedException::operation('open-meteo', \sprintf('serve a step of %s (PT1H, PT15M or P1D)', $query->step));
        if (null !== $query->aggregation && 'daily' !== $block) {
            throw NotSupportedException::operation('open-meteo', 'aggregate other than by day');
        }
        $variables = array_map(fn (string $v) => $this->variable($v, $block, $query->aggregation), $query->variables);

        $timezone = $query->timezone ?? $this->timezone;
        $today = new \DateTimeImmutable($this->today ?? 'today', new \DateTimeZone('UTC'));
        $from = self::day($query->from ?? $today);
        $to = self::day($query->to ?? $from->modify('+6 days'));
        $boundary = $today->modify(\sprintf('-%d days', $this->archiveDelay));

        $answers = [];
        if ($from < $boundary) {
            // The archive, up to the day before the boundary (or the end of the period).
            $end = $to < $boundary ? $to : $boundary->modify('-1 day');
            $answers[] = [false, $this->call($this->archiveUri, 'archive', $query->place, $block, $variables, $from, $end, $timezone)];
        }
        if ($to >= $boundary) {
            $start = $from < $boundary ? $boundary : $from;
            if ($start < $today->modify(\sprintf('-%d days', self::FORECAST_PAST_DAYS))) {
                $start = $today->modify(\sprintf('-%d days', self::FORECAST_PAST_DAYS));
            }
            $answers[] = [true, $this->call($this->baseUri, 'forecast', $query->place, $block, $variables, $start, $to, $timezone)];
        }

        $series = [];
        foreach ($variables as $variable) {
            $points = [];
            $unit = null;
            $place = $query->place;
            foreach ($answers as [, $data]) {
                $zone = new \DateTimeZone((string) ($data['timezone'] ?? 'GMT'));
                $times = $data[$block]['time'] ?? [];
                $values = $data[$block][$variable] ?? [];
                $unit ??= $data[$block.'_units'][$variable] ?? null;
                if (isset($data['latitude'], $data['longitude'])) {
                    // The point of the model's grid that answered.
                    $place = new Point((float) $data['latitude'], (float) $data['longitude'], isset($data['elevation']) ? (float) $data['elevation'] : null);
                }
                foreach ($times as $i => $time) {
                    $value = $values[$i] ?? null;
                    $points[] = [(new \DateTimeImmutable('@'.(int) $time))->setTimezone($zone), \is_int($value) || \is_float($value) ? $value : null];
                }
            }
            $series[] = new Series(
                variable: $variable,
                unit: $unit,
                place: $place,
                points: $points,
                step: $query->step,
                aggregation: $this->aggregation($variable, $block),
                forecast: match (true) {
                    1 === \count($answers) && !$answers[0][0] => false,
                    1 === \count($answers) && $from >= $today => true,
                    default => null,
                },
                source: 'open-meteo',
            );
        }

        return $series;
    }

    /**
     * Air-quality series at a point: the hours Open-Meteo answers, or each
     * day's mean, maximum, minimum or sum of them, the day in the answer's
     * time zone (one call, whatever the step).
     *
     * @return list<Series>
     */
    private function airQuality(SeriesQuery $query): array
    {
        if (!$query->place instanceof Point) {
            throw NotSupportedException::operation('open-meteo', 'serve air quality over an area (a Point only: the air-quality API takes no box)');
        }
        if (!\in_array($query->step, [SeriesQuery::HOURLY, SeriesQuery::DAILY], true)) {
            throw NotSupportedException::operation('open-meteo', \sprintf('serve air quality by a step of %s (PT1H, or P1D aggregated)', $query->step));
        }
        $daily = SeriesQuery::DAILY === $query->step;
        if ($daily !== (null !== $query->aggregation) || ($daily && !\in_array($query->aggregation, self::AGGREGATIONS, true))) {
            throw NotSupportedException::operation('open-meteo', $daily ? 'serve air quality by day but as the mean, max, min or sum of its hours' : 'aggregate air quality other than by day');
        }
        $today = new \DateTimeImmutable($this->today ?? 'today', new \DateTimeZone('UTC'));
        $from = self::day($query->from ?? $today);
        $to = self::day($query->to ?? $from->modify('+6 days'));
        $data = $this->call($this->baseUri, 'air-quality', $query->place, 'hourly', $query->variables, $from, $to, $query->timezone ?? $this->timezone);

        $zone = new \DateTimeZone((string) ($data['timezone'] ?? 'GMT'));
        $place = isset($data['latitude'], $data['longitude']) ? new Point((float) $data['latitude'], (float) $data['longitude'], isset($data['elevation']) ? (float) $data['elevation'] : null) : $query->place;
        // The past comes from the models' earlier runs and reanalyses, which the answer does not tell apart.
        $forecast = $from >= $today ? true : null;
        $series = [];
        foreach ($query->variables as $variable) {
            $unit = $data['hourly_units'][$variable] ?? null;
            $points = [];
            foreach ($data['hourly']['time'] ?? [] as $i => $time) {
                $value = $data['hourly'][$variable][$i] ?? null;
                $points[] = [(new \DateTimeImmutable('@'.(int) $time))->setTimezone($zone), \is_int($value) || \is_float($value) ? $value : null];
            }
            if ($daily) {
                $points = self::days($points, (string) $query->aggregation, $zone);
            }
            $series[] = new Series(
                variable: $daily ? $variable.'_'.$query->aggregation : $variable,
                unit: \is_string($unit) && '' !== $unit ? $unit : null,
                place: $place,
                points: $points,
                step: $query->step,
                aggregation: $daily ? $query->aggregation : null,
                forecast: $forecast,
                source: 'open-meteo',
            );
        }

        return $series;
    }

    /**
     * Hourly points made daily: each day's aggregate of the hours that have
     * a value (null when none has), at the day's midnight.
     *
     * @param list<array{\DateTimeImmutable, int|float|null}> $points
     *
     * @return list<array{\DateTimeImmutable, int|float|null}>
     */
    private static function days(array $points, string $aggregation, \DateTimeZone $zone): array
    {
        $days = [];
        foreach ($points as [$time, $value]) {
            $days[$time->format('Y-m-d')] ??= [];
            if (null !== $value) {
                $days[$time->format('Y-m-d')][] = $value;
            }
        }
        $daily = [];
        foreach ($days as $day => $values) {
            $daily[] = [new \DateTimeImmutable($day, $zone), $values ? match ($aggregation) {
                Series::MEAN => round(array_sum($values) / \count($values), 2),
                Series::MAX => max($values),
                Series::MIN => min($values),
                Series::SUM => round(array_sum($values), 2),
            } : null];
        }

        return $daily;
    }

    /**
     * @param list<string> $variables
     *
     * @return array<string, mixed>
     */
    private function call(string $base, string $endpoint, Point $point, string $block, array $variables, \DateTimeImmutable $from, \DateTimeImmutable $to, string $timezone): array
    {
        $data = $this->getJson(rtrim($base, '/').'/'.$endpoint, [
            'latitude' => $point->latitude,
            'longitude' => $point->longitude,
            'elevation' => $point->elevation,
            $block => implode(',', $variables),
            'start_date' => $from->format('Y-m-d'),
            'end_date' => $to->format('Y-m-d'),
            'timezone' => $timezone,
            'timeformat' => 'unixtime',
            // The air-quality API names its models otherwise (domains): its own choice.
            'models' => self::AIR_QUALITY === $this->api ? null : $this->models,
            'apikey' => $this->apiKey,
        ]);
        if (null === $data || true === ($data['error'] ?? false)) {
            throw new ProviderException('open-meteo', (string) ($data['reason'] ?? 'no answer for '.$endpoint));
        }

        return $data;
    }

    /** "temperature_2m" with a daily maximum asked: "temperature_2m_max", Open-Meteo's name for it. */
    private function variable(string $variable, string $block, ?string $aggregation): string
    {
        if ('daily' !== $block || null === $aggregation) {
            return $variable;
        }
        $suffix = array_search($aggregation, self::AGGREGATIONS, true);
        if (false === $suffix) {
            throw NotSupportedException::operation('open-meteo', \sprintf('aggregate by "%s" (mean, max, min, sum)', $aggregation));
        }

        // A name that says its own aggregation already ("precipitation_sum") is kept.
        return null !== $this->aggregation($variable, $block) ? $variable : $variable.$suffix;
    }

    /** What a daily variable's suffix says it is: "precipitation_sum" a sum. */
    private function aggregation(string $variable, string $block): ?string
    {
        if ('daily' !== $block) {
            return null;
        }
        foreach (self::AGGREGATIONS as $suffix => $aggregation) {
            if (str_ends_with($variable, $suffix)) {
                return $aggregation;
            }
        }

        return null;
    }

    /** The calendar day of a date, as written: a time of day and a time zone do not move it. */
    private static function day(\DateTimeInterface $date): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date->format('Y-m-d'), new \DateTimeZone('UTC'));
    }
}
