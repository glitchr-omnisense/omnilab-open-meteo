<?php

namespace Omnilab\OpenMeteo;

use Omnilab\Config;
use Omnilab\Source\SourceFactory;
use Omnilab\Source\SourceInterface;
use Symfony\Component\HttpClient\HttpClient;

/**
 * Open-Meteo: weather series, forecast and archive - no key needed for
 * non-commercial use.
 *
 *   options:
 *     api_key: ~                       # a commercial key: the calls go to customer-api.open-meteo.com
 *     timezone: auto                   # the times' and the days' zone when a query does not say: auto (the point's), GMT, Europe/Paris...
 *     models: ~                        # "best_match" when not set; "icon_seamless", "ecmwf_ifs025"...
 *     archive_delay: 5                 # days the archive lags behind today
 *     base_uri: 'https://api.open-meteo.com/v1/'               # or a self-hosted instance
 *     archive_uri: 'https://archive-api.open-meteo.com/v1/'
 *     today: ~                         # the day taken for today (tests): the real one when not set
 */
final class OpenMeteoSourceFactory extends SourceFactory
{
    protected function populate(Config $c): void
    {
        $key = $c->get('api_key');
        $c->defaults([
            'omnilab.factory_name' => 'open-meteo',
            'omnilab.required_options' => [],
            'api_key' => null,
            'timezone' => 'auto',
            'models' => null,
            'archive_delay' => 5,
            'today' => null,
            'base_uri' => $key ? OpenMeteoSource::CUSTOMER_URI : OpenMeteoSource::BASE_URI,
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
        );
    }
}
