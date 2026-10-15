<?php

namespace Omnilab\OpenMeteo;

use Omnilab\Config;
use Omnilab\Source\SourceFactory;
use Omnilab\Source\SourceInterface;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Open-Meteo: weather series, forecast and archive; or air-quality series
 * (api: air_quality) - no key needed for non-commercial use.
 *
 *   options:
 *     api: weather                     # or air_quality: PM2.5, PM10, NO2, O3, the European and US indices, pollens... (a source of its own)
 *     api_key: ~                       # a commercial key: the calls go to customer-api.open-meteo.com
 *     timezone: auto                   # the times' and the days' zone when a query does not say: auto (the point's), GMT, Europe/Paris...
 *     models: ~                        # "best_match" when not set; "icon_seamless", "ecmwf_ifs025"...
 *     archive_delay: 5                 # days the archive lags behind today
 *     base_uri: 'https://api.open-meteo.com/v1/'               # or a self-hosted instance; https://air-quality-api.open-meteo.com/v1/ for air_quality
 *     archive_uri: 'https://archive-api.open-meteo.com/v1/'
 *     today: ~                         # the day taken for today (tests): the real one when not set
 */
final class OpenMeteoSourceFactory extends SourceFactory
{
    protected function populate(Config $c): void
    {
        $key = $c->get('api_key');
        $airQuality = OpenMeteoSource::AIR_QUALITY === $c->get('api');
        $c->defaults([
            'omnilab.factory_name' => 'open-meteo',
            'omnilab.required_options' => [],
            'api' => OpenMeteoSource::WEATHER,
            'api_key' => null,
            'timezone' => 'auto',
            'models' => null,
            'archive_delay' => 5,
            'today' => null,
            'base_uri' => match (true) {
                $airQuality => $key ? OpenMeteoSource::CUSTOMER_AIR_QUALITY_URI : OpenMeteoSource::AIR_QUALITY_URI,
                default => $key ? OpenMeteoSource::CUSTOMER_URI : OpenMeteoSource::BASE_URI,
            },
            'archive_uri' => $key ? OpenMeteoSource::CUSTOMER_ARCHIVE_URI : OpenMeteoSource::ARCHIVE_URI,
        ]);
    }

    protected function build(Config $c): SourceInterface
    {
        return new OpenMeteoSource(
            $this->http ?? HttpClient::create(),
            (string) $c->get('base_uri'),
            (string) $c->get('archive_uri'),
            $c->get('api_key'),
            (string) $c->get('timezone'),
            (int) $c->get('archive_delay'),
            $c->get('models'),
            $c->get('today'),
            ['User-Agent' => self::userAgent($c)],
            (float) $c->get('throttle'),
            (string) $c->get('api'),
        );
    }
}
