<?php

declare(strict_types=1);

namespace App\Service\ApiSources;

use App\Dto\ForecastData;
use App\Dto\LocationCoordinatesInterface;
use App\Dto\WeatherData;
use App\Service\Forecast\ForecastProviderInterface;
use App\Service\HourlyForecast\HourlyForecastProviderInterface;
use App\Service\Weather\WeatherProviderInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class MetNoService implements WeatherProviderInterface, ForecastProviderInterface, HourlyForecastProviderInterface
{
    private const API_NAME = 'MetNo';
    private string $endpoint = 'https://api.met.no/weatherapi/locationforecast/2.0/compact';
    private array $hourlyToday = [];

    public function __construct(
        private HttpClientInterface $client,
        private LoggerInterface $logger,
        private CacheItemPoolInterface $cache,
        private LoggerInterface $meteoLogger,
        private bool $meteo_cache
    ) {
    }

    public function getWeather(LocationCoordinatesInterface $locationCoordinates): WeatherData
    {
        $cacheKey = 'met.current'.sprintf('%.6f_%.6f', $locationCoordinates->getLatitude(), $locationCoordinates->getLongitude());
        $item = $this->cache->getItem($cacheKey);

        if (!$item->isHit() || !$this->meteo_cache) {
            try {
                $query = [
                    'lat' => $locationCoordinates->getLatitude(),
                    'lon' => $locationCoordinates->getLongitude(),
                ];

                $response = $this->client->request('GET', $this->endpoint, ['query' => $query]);

                $data = $response->toArray();
                $this->meteoLogger->info('Interrogation '.self::API_NAME, [
                    'query' => $query,
                    'endpoint' => $this->endpoint,
                    'data' => $data,
                ]);

                $first = $data['properties']['timeseries'][0];

                $details = $first['data']['instant']['details'];
                $symbolCode = $first['data']['next_1_hours']['summary']['symbol_code'] ?? null;
                $displayMeteo = $this->getSymbolMeta($symbolCode);

                $weather = new WeatherData(
                    provider: 'Met.no',
                    temperature: $details['air_temperature'],
                    description: $displayMeteo['label'],
                    humidity: $details['relative_humidity'] ?? null,
                    wind: $details['wind_speed'] ?? 0,
                    sourceName: 'MET Norway (Yr.no)',
                    logoUrl: 'https://www.met.no/_/asset/no.met.metno:00000196349af260/images/met-logo.svg',
                    sourceUrl: 'https://api.met.no/weatherapi/locationforecast/2.0/documentation',
                    icon: $displayMeteo['icon']
                );
                $item->set($weather);
                $item->expiresAfter(600); // 10 minutes
                $this->cache->save($item);
            } catch (TransportExceptionInterface $e) {
                $this->logger->error('Erreur API Weather Met.no : '.$e->getMessage());
                $this->meteoLogger->error('Interrogation '.self::API_NAME, [
                    'query' => $query,
                    'endpoint' => $this->endpoint,
                    'error' => $e->getMessage(),
                ]);

                $weather = new WeatherData(
                    provider: 'Met.no',
                    temperature: 0,
                    description: $e->getMessage(),
                    humidity: null,
                    wind: 0,
                    sourceName: 'MET Norway (Yr.no)',
                    logoUrl: 'https://www.met.no/_/image/9d963a8e-34d3-474e-8b53-70cfd6ddee6a:ff706c6507f82977d3453bd29eb71e4c44b60a0b/logo_met_no.svg',
                    sourceUrl: 'https://api.met.no/weatherapi/locationforecast/2.0/documentation',
                    icon: null,
                    enabled: false
                );
            }
        } else {
            $weather = $item->get();
        }

        return $weather;
    }

    private function getSymbolMeta(?string $code): array
    {
        return match ($code) {
            'clearsky_day' => ['label' => 'Ciel clair', 'emoji' => '☀️', 'icon' => 'wi wi-day-sunny'],
            'clearsky_night' => ['label' => 'Ciel clair', 'emoji' => '☀️', 'icon' => 'wi wi-night-clear'],

            'fair_day' => ['label' => 'Ensoleillé', 'emoji' => '🌤️', 'icon' => 'wi wi-day-sunny-overcast'],
            'fair_night' => ['label' => 'Ensoleillé', 'emoji' => '🌤️', 'icon' => 'wi wi-night-alt-partly-cloudy'],

            'partlycloudy_day' => ['label' => 'Partiellement nuageux', 'emoji' => '⛅', 'icon' => 'wi wi-day-cloudy'],
            'partlycloudy_night' => ['label' => 'Partiellement nuageux', 'emoji' => '⛅', 'icon' => 'wi wi-night-alt-cloudy'],

            'cloudy' => ['label' => 'Nuageux', 'emoji' => '☁️', 'icon' => 'wi wi-cloudy'],

            'fog' => ['label' => 'Brouillard', 'emoji' => '🌫️', 'icon' => 'wi wi-fog'],

            'lightrain', 'lightrain_day', 'lightrain_night', 'lightrainshowers_day' => ['label' => 'Pluie légère', 'emoji' => '🌦️', 'icon' => 'wi wi-showers'],

            'rain', 'rain_day', 'rain_night' => ['label' => 'Pluie', 'emoji' => '🌧️', 'icon' => 'wi wi-rain'],
            'rainshowers_day', 'rainshowers_night', 'rainshowers' => ['label' => 'Averses', 'emoji' => '🌦️', 'icon' => 'wi wi-showers'],

            'heavyrain', 'heavyrain_day', 'heavyrain_night' => ['label' => 'Pluie forte', 'emoji' => '🌧️', 'icon' => 'wi wi-rain-wind'],
            'heavyrainshowers_day', 'heavyrainshowers_night' => ['label' => 'Averses fortes', 'emoji' => '🌧️', 'icon' => 'wi wi-showers'],

            'lightsnow', 'lightsnow_day', 'lightsnow_night' => ['label' => 'Neige légère', 'emoji' => '❄️', 'icon' => 'wi wi-snow'],
            'snow', 'snow_day', 'snow_night' => ['label' => 'Neige', 'emoji' => '❄️', 'icon' => 'wi wi-snow'],
            'heavysnow', 'heavysnow_day', 'heavysnow_night' => ['label' => 'Neige forte', 'emoji' => '❄️', 'icon' => 'wi wi-snow-wind'],

            'snowshowers_day', 'snowshowers_night', 'snowshowers' => ['label' => 'Averses de neige', 'emoji' => '❄️', 'icon' => 'wi wi-snow'],

            'thunderstorm', 'thunderstorm_day', 'thunderstorm_night' => ['label' => 'Orage', 'emoji' => '⛈️', 'icon' => 'wi wi-thunderstorm'],
            'thunderstormshowers_day', 'thunderstormshowers_night' => ['label' => 'Orage avec averses', 'emoji' => '⛈️', 'icon' => 'wi wi-thunderstorm'],

            default => $this->logUnknownSymbol($code),
        };
    }

    private function logUnknownSymbol(string $code): array
    {
        $this->logger->warning("Unrecognized symbol code for Met.no : $code");

        return ['label' => 'Inconnu', 'emoji' => '🌡️', 'icon' => 'wi wi-na'];
    }

    public function getForecast(LocationCoordinatesInterface $locationCoordinates): array
    {
        $cacheKey = 'met.forecast'.sprintf('%.6f_%.6f', $locationCoordinates->getLatitude(), $locationCoordinates->getLongitude());
        $item = $this->cache->getItem($cacheKey);

        if (!$item->isHit() || !$this->meteo_cache) {
            $data = $this->getForecastApiInformations($locationCoordinates);
            // stock prévisions
            $this->hourlyToday = $data['properties']['timeseries'];

            $jours = [];

            foreach ($this->hourlyToday as $entry) {
                $date = new \DateTimeImmutable($entry['time']);
                $dayKey = $date->format('Y-m-d');

                if (!isset($entry['data']['instant']['details']['air_temperature'])) {
                    continue;
                }

                $temp = $entry['data']['instant']['details']['air_temperature'];
                $symbolCode =
                    $entry['data']['next_1_hours']['summary']['symbol_code']
                    ?? $entry['data']['next_6_hours']['summary']['symbol_code']
                    ?? $entry['data']['next_12_hours']['summary']['symbol_code']
                    ?? null;
                $heure = (new \DateTimeImmutable($entry['time']))->format('Y-m-d H:i');

                if (empty($symbolCode)) {
                    $this->logger->error("Symbol code manquant à $heure");
                }
                $jours[$dayKey][] = ['temp' => $temp, 'symbol' => $symbolCode];
            }

            $forecasts = [];
            $i = 0;
            foreach ($jours as $day => $dataList) {
                if ($i >= 7) {
                    break;
                }

                $temps = array_column($dataList, 'temp');
                $symbols = array_column($dataList, 'symbol');

                // prends au milieu de la journée pour savoir quel symbol utiliser
                $symbol = $symbols[(int) round(count($symbols) / 2)] ?? null;
                $displayMeteo = $this->getSymbolMeta($symbol);

                $forecasts[] = new ForecastData(
                    provider: 'Met.no',
                    date: new \DateTimeImmutable($day),
                    tmin: min($temps),
                    tmax: max($temps),
                    icon: $displayMeteo['icon'],
                    emoji: $displayMeteo['emoji'],
                );

                $i++;
            }
            $item->set(['forecast' => $forecasts, 'todayHourly' => $this->hourlyToday]);
            $item->expiresAfter(1800); // 30 min
            $this->cache->save($item);
        } else {
            $infos = $item->get();
            $forecasts = $infos['forecast'];
            $this->hourlyToday = $infos['todayHourly'];
        }

        return $forecasts;
    }

    public function getForecastApiInformations(LocationCoordinatesInterface $locationCoordinates): array
    {
        try {
            $response = $this->client->request('GET', $this->endpoint, [
                'query' => [
                    'lat' => $locationCoordinates->getLatitude(),
                    'lon' => $locationCoordinates->getLongitude(),
                ],
                'headers' => [
                    'User-Agent' => 'MonProjetMeteo/1.0 (mon@email.com)',
                ],
            ]);

            return $response->toArray();
        } catch (
            TransportExceptionInterface|
            ClientExceptionInterface|
            ServerExceptionInterface|
            RedirectionExceptionInterface $e
        ) {
            $this->logger->error('Erreur API Previsions Met.no : '.$e->getMessage());

            return [];
        }
    }

    public function getTodayHourly(LocationCoordinatesInterface $locationCoordinates): array
    {
        if (empty($this->hourlyToday)) {
            $data = $this->getForecastApiInformations($locationCoordinates);
            $this->hourlyToday = $data['properties']['timeseries'];
        }

        $today = (new \DateTimeImmutable())->setTimezone(new \DateTimeZone('Europe/Paris'));
        $tomorrow = (new \DateTimeImmutable('+1 day'))->setTimezone(new \DateTimeZone('Europe/Paris'));
        $result = [];

        foreach ($this->hourlyToday as $entry) {
            $dt = (new \DateTimeImmutable($entry['time']))->setTimezone(new \DateTimeZone('Europe/Paris'));
            if ($dt >= $today && $dt < $tomorrow) {
                $details = $entry['data']['instant']['details'] ?? [];
                if (!isset($details['air_temperature'])) {
                    continue;
                }
                $temp = $details['air_temperature'];
                $symbolCode = $entry['data']['next_1_hours']['summary']['symbol_code'] ?? null;
                $displayMeteo = $this->getSymbolMeta($symbolCode);

                try {
                    $result[] = new \App\Dto\HourlyForecastData(
                        provider: 'Met.no',
                        time: $dt,
                        temperature: $temp,
                        description: $displayMeteo['label'],
                        emoji: $displayMeteo['emoji'],
                    );
                } catch (\InvalidArgumentException $e) {
                    $this->logger->error('erreur :'.$e->getMessage());
                }
            }
        }

        return $result;
    }
}
