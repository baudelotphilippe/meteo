<?php

declare(strict_types=1);

namespace App\Service\ApiSources;

use App\Dto\WeatherData;
use App\Dto\ForecastData;
use App\ValueObject\Time;
use Psr\Log\LoggerInterface;
use Psr\Cache\CacheItemPoolInterface;
use App\Dto\LocationCoordinatesInterface;
use App\Service\Weather\WeatherProviderInterface;
use App\Service\Forecast\ForecastProviderInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use App\Service\HourlyForecast\HourlyForecastProviderInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;

class MetNoService implements WeatherProviderInterface, ForecastProviderInterface, HourlyForecastProviderInterface
{
    private string $endpoint = 'https://api.met.no/weatherapi/locationforecast/2.0/compact';
    private array $hourlyToday = [];

    public function __construct(private HttpClientInterface $client, private LoggerInterface $logger, private CacheItemPoolInterface $cache) {}

    public function getWeather(LocationCoordinatesInterface $locationCoordinates): WeatherData
    {
        $cacheKey = 'met.current';
        $item = $this->cache->getItem($cacheKey);

        if (!$item->isHit()) {
            try {
                $response = $this->client->request('GET', $this->endpoint, [
                    'query' => [
                        'lat' => $locationCoordinates->getLatitude(),
                        'lon' => $locationCoordinates->getLatitude(),
                    ],
                    'headers' => [
                        'User-Agent' => 'MonProjetMeteo/1.0 (mon.email@exemple.com)',
                    ],
                ]);

                $data = $response->toArray();
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
                $this->logger->error('Erreur API Weather Met.no : ' . $e->getMessage());
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
        $cacheKey = 'met.forecast';
        $item = $this->cache->getItem($cacheKey);

        if (!$item->isHit()) {
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

                $data = $response->toArray();
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
            } catch (
                TransportExceptionInterface |
                ClientExceptionInterface |
                ServerExceptionInterface |
                RedirectionExceptionInterface $e
            ) {
                $this->logger->error('Erreur API Previsions Met.no : ' . $e->getMessage());
                $forecasts = [];
            }
        } else {
            $infos = $item->get();
            $forecasts = $infos['forecast'];
            $this->hourlyToday = $infos['todayHourly'];
        }

        return $forecasts;
    }

    public function getTodayHourly(): array
    {
        $today = (new \DateTimeImmutable())->format('Y-m-d');
        $tomorrow = (new \DateTimeImmutable('+1 day'))->format('Y-m-d');
        $cacheKey = 'metno.hourly.' . $today;

        // Récupère le cache existant
        $cacheItem = $this->cache->getItem($cacheKey);
        $stored = $cacheItem->isHit() ? $cacheItem->get() : [];

        foreach ($this->hourlyToday as $entry) {
            $dt = (new \DateTimeImmutable($entry['time']))->setTimezone(new \DateTimeZone('Europe/Paris'));
            $date = $dt->format('Y-m-d');
            $time = $dt->format('G\h');

            if ($date === $today || ($date === $tomorrow && $time === '0h')) {
                $time = ($date === $tomorrow && $time === '0h') ? '24h' : $time;
                $details = $entry['data']['instant']['details'] ?? [];
                if (!isset($details['air_temperature'])) {
                    continue;
                }
                $temp = $details['air_temperature'];
                $symbolCode = $entry['data']['next_1_hours']['summary']['symbol_code'] ?? null;
                $displayMeteo = $this->getSymbolMeta($symbolCode);
                $newData = [
                    'temp' => $temp,
                    'desc' => $displayMeteo['label'],
                    'icon' => $displayMeteo['icon'],
                    'emoji' => $displayMeteo['emoji'],
                ];

                $stored[$time] = $newData;
            }
        }

        ksort($stored);

        // Sauvegarde mise à jour
        $cacheItem->set($stored)->expiresAfter(86400);
        $this->cache->save($cacheItem);

        // Conversion en objets HourlyForecastData
        $result = [];
        foreach ($stored as $time => $data) {
            try {
                $result[] = new \App\Dto\HourlyForecastData(
                    provider: 'Met.no',
                    time: new Time($time),
                    temperature: $data['temp'],
                    description: $data['desc'],
                    emoji: $data['emoji'],
                );
            } catch (\InvalidArgumentException $e) {
                $this->logger->error("erreur :" . $e->getMessage());
            }
        }

        return $result;
    }
}
