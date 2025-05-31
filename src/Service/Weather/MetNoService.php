<?php

namespace App\Service\Weather;

use App\Dto\WeatherData;
use App\Dto\ForecastData;
use Psr\Log\LoggerInterface;
use App\Config\CityCoordinates;
use Psr\Cache\CacheItemPoolInterface;
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

    public function getWeather(): WeatherData
    {
        $cacheKey = 'met.current';
        $item = $this->cache->getItem($cacheKey);

        if (!$item->isHit()) {
            try {
                $response = $this->client->request('GET', $this->endpoint, [
                    'query' => [
                        'lat' => CityCoordinates::LAT,
                        'lon' => CityCoordinates::LON,
                    ],
                    'headers' => [
                        'User-Agent' => 'MonProjetMeteo/1.0 (mon.email@exemple.com)'
                    ]
                ]);

                $data = $response->toArray();
                $first = $data['properties']['timeseries'][0];

                $details = $first['data']['instant']['details'];
                $symbolCode = $first['data']['next_1_hours']['summary']['symbol_code'] ?? null;

                $weather = new WeatherData(
                    provider: 'Met.no',
                    temperature: $details['air_temperature'],
                    description: $this->translateSymbol($symbolCode ?? 'inconnu'),
                    humidity: $details['relative_humidity'] ?? null,
                    wind: $details['wind_speed'] ?? 0,
                    sourceName: 'MET Norway (Yr.no)',
                    logoUrl: 'https://www.met.no/_/asset/no.met.metno:00000196349af260/images/met-logo.svg',
                    sourceUrl: 'https://api.met.no/weatherapi/locationforecast/2.0/documentation',
                    icon: $this->iconFromSymbol($symbolCode)
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
                    icon: null
                );
            }
        } else {
            $weather = $item->get();
        }
        return $weather;
    }

    public function getForecast(): array
    {
        $cacheKey = 'met.forecast';
        $item = $this->cache->getItem($cacheKey);

        if (!$item->isHit()) {
            try {
                $response = $this->client->request('GET', $this->endpoint, [
                    'query' => [
                        'lat' => CityCoordinates::LAT,
                        'lon' => CityCoordinates::LON,
                    ],
                    'headers' => [
                        'User-Agent' => 'MonProjetMeteo/1.0 (mon@email.com)'
                    ]
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

                    $jours[$dayKey][] = $temp;
                }

                $forecasts = [];
                $i = 0;
                foreach ($jours as $day => $temps) {
                    if ($i >= 7) break;

                    $forecasts[] = new ForecastData(
                        provider: 'Met.no',
                        date: new \DateTimeImmutable($day),
                        tmin: min($temps),
                        tmax: max($temps),
                        description: null
                    );

                    $i++;
                }
                $item->set(["forecast" => $forecasts, "todayHourly" => $this->hourlyToday]);
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
            $forecasts = $infos["forecast"];
            $this->hourlyToday = $infos["todayHourly"];
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
            $time = $dt->format('H:i');

            if ($date === $today || ($date === $tomorrow && $time === '00:00')) {
                $key = ($date === $tomorrow && $time === '00:00') ? '24:00' : $time;
                $details = $entry['data']['instant']['details'] ?? [];
                if (!isset($details['air_temperature'])) {
                    continue;
                }
                $temp = $details['air_temperature'];
                $symbolCode = $entry['data']['next_1_hours']['summary']['symbol_code'] ?? null;

                $newData = [
                    'temp' => $temp,
                    'desc' => $this->translateSymbol($symbolCode ?? 'inconnu'),
                    'icon' => $symbolCode,
                ];

                $stored[$key] = $newData;
            }
        }

        ksort($stored);

        // Sauvegarde mise à jour
        $cacheItem->set($stored)->expiresAfter(86400);
        $this->cache->save($cacheItem);

        // Conversion en objets HourlyForecastData
        $result = [];
        foreach ($stored as $time => $data) {
            if ($time === '24:00') {
                $displayTime = '0h';
            } else {
                $hour = ltrim(explode(':', $time)[0], '0');
                $displayTime = $hour . 'h';
            }
            $result[] = new \App\Dto\HourlyForecastData(
                provider: 'Met.no',
                time: $displayTime,
                temperature: $data['temp'],
                description: $data['desc'],
                icon: $this->iconFromSymbol($data['icon']),
            );
        }

        return $result;
    }


    private function iconFromSymbol(?string $code): string
    {
        if (!$code) return '🌡️';

        return match (true) {
            str_contains($code, 'clearsky') => '☀️',
            str_contains($code, 'cloudy') => '☁️',
            str_contains($code, 'fair') => '🌤️',
            str_contains($code, 'partlycloudy') => '⛅',
            str_contains($code, 'rain') => '🌧️',
            str_contains($code, 'snow') => '❄️',
            str_contains($code, 'fog') => '🌫️',
            str_contains($code, 'thunderstorm') => '⛈️',
            default => '🌡️',
        };
    }

    private function translateSymbol(string $symbol): string
    {
        // $this->logger->info($symbol);
        return match ($symbol) {
            'clearsky_day', 'clearsky_night' => 'Ciel clair',
            'fair_day', 'fair_night' => 'Ensoleillé',
            'partlycloudy_day', 'partlycloudy_night' => 'Partiellement nuageux',
            'cloudy' => 'Nuageux',
            'lightrain', 'lightrain_day', 'lightrain_night' => 'Pluie légère',
            'rain', 'rain_day', 'rain_night', 'rainshowers_day' => 'Pluie',
            'heavyrain' => 'Pluie forte',
            'snow', 'heavysnow' => 'Neige',
            'fog' => 'Brouillard',
            'thunderstorm' => 'Orage',
            default => 'Inconnu',
        };
    }
}
