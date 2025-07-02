<?php

declare(strict_types=1);

namespace App\Service\ApiSources;

use App\Dto\ForecastData;
use App\Dto\HourlyForecastData;
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

class OpenWeatherService implements WeatherProviderInterface, ForecastProviderInterface, HourlyForecastProviderInterface
{
    private string $endpointWeather = 'https://api.openweathermap.org/data/2.5/weather';
    private string $endpointForecast = 'https://api.openweathermap.org/data/2.5/forecast';

    private array $hourlyToday = [];

    public function __construct(
        private HttpClientInterface $client,
        private string $apiKey,
        private LoggerInterface $logger,
        private CacheItemPoolInterface $cache,
    ) {
    }

    public function getWeather(LocationCoordinatesInterface $locationCoordinates): WeatherData
    {
        if (empty($this->apiKey)) {
            throw new \RuntimeException('Clé API OpenWeather absente.');
        }

        $cacheKey = 'openweather.current';
        $item = $this->cache->getItem($cacheKey);

        if (!$item->isHit()) {
            try {
                $data = $this->client->request('GET', $this->endpointWeather, [
                    'query' => [
                        'q' => $locationCoordinates->getName(),
                        'appid' => $this->apiKey,
                        'units' => 'metric',
                        'lang' => 'fr',
                    ],
                ])->toArray();

                $weather = new WeatherData(
                    provider: 'OpenWeather',
                    temperature: $data['main']['temp'],
                    description: $data['weather'][0]['description'],
                    humidity: $data['main']['humidity'],
                    wind: $data['wind']['speed'],
                    sourceName: 'OpenWeatherMap',
                    logoUrl: 'https://openweathermap.org/themes/openweathermap/assets/img/logo_white_cropped.png',
                    sourceUrl: 'https://openweathermap.org/current',
                    icon: $this->iconFromCode($data['weather'][0]['icon'])['icon']
                );
                $item->set($weather);
                $item->expiresAfter(600); // 10 minutes
                $this->cache->save($item);
            } catch (ClientExceptionInterface|TransportExceptionInterface $e) {
                $this->logger->error('Erreur API OpenWeather Met.no : '.$e->getMessage());
                $weather = new WeatherData(
                    provider: 'OpenWeather',
                    temperature: 0,
                    description: $e->getMessage(),
                    humidity: null,
                    wind: 0,
                    sourceName: 'OpenWeatherMap',
                    logoUrl: 'https://openweathermap.org/themes/openweathermap/assets/img/logo_white_cropped.png',
                    sourceUrl: 'https://openweathermap.org/current',
                    icon: null,
                    enabled: false
                );
            }
        } else {
            $weather = $item->get();
        }

        return $weather;
    }

    public function getForecast(LocationCoordinatesInterface $locationCoordinates): array
    {
        $cacheKey = 'openweather.forecast';
        $item = $this->cache->getItem($cacheKey);

        if (!$item->isHit()) {
            try {
                $response = $this->client->request('GET', $this->endpointForecast, [
                    'query' => [
                        'lat' => $locationCoordinates->getLatitude(),
                        'lon' => $locationCoordinates->getLongitude(),
                        'units' => 'metric',
                        'lang' => 'fr',
                        'appid' => $this->apiKey,
                    ],
                ]);

                $data = $response->toArray();
                $grouped = [];
                $this->hourlyToday = $data['list'];
                foreach ($data['list'] as $entry) {
                    $dt = new \DateTimeImmutable($entry['dt_txt']);
                    $dayKey = $dt->format('Y-m-d');
                    $grouped[$dayKey][] = $entry;
                }

                $forecasts = [];

                foreach (array_slice($grouped, 0, 7, true) as $day => $entries) {
                    $temps = array_map(fn ($e) => $e['main']['temp'], $entries);
                    $descFreq = array_count_values(array_map(fn ($e) => $e['weather'][0]['description'], $entries));
                    arsort($descFreq);
                    $mainDesc = array_key_first($descFreq);

                    $iconCode = $entries[0]['weather'][0]['icon'];
                    $icon = $this->iconFromCode($iconCode);

                    $forecasts[] = new ForecastData(
                        provider: 'OpenWeather',
                        date: new \DateTimeImmutable($day),
                        tmin: min($temps),
                        tmax: max($temps),
                        icon: $icon['icon'],
                        emoji: $icon['emoji']
                    );
                }
                $item->set(['forecast' => $forecasts, 'todayHourly' => $this->hourlyToday]);
                $item->expiresAfter(1800); // 30 min
                $this->cache->save($item);
            } catch (
                TransportExceptionInterface|
                ClientExceptionInterface|
                ServerExceptionInterface|
                RedirectionExceptionInterface $e
            ) {
                $this->logger->error('Erreur API Met.no : '.$e->getMessage());
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
        $cacheKey = 'openweather.hourly.'.$today;

        // Récupère le cache existant
        $cacheItem = $this->cache->getItem($cacheKey);

        $stored = $cacheItem->isHit() ? $cacheItem->get() : [];

        $result = [];

        foreach ($this->hourlyToday as $entry) {
            $dt = new \DateTimeImmutable($entry['dt_txt']);
            $date = $dt->format('Y-m-d');
            $time = $dt->format('G\h');
            if ($date === $today || ($date === $tomorrow && $time === '0h')) {
                $time = ($date === $tomorrow) ? '24h' : $time;

                // ajoute ou écrase
                $stored[$time] = [
                    'temp' => $entry['main']['temp'],
                    'desc' => $entry['weather'][0]['description'],
                    'icon' => $entry['weather'][0]['icon'],
                ];
            }
        }

        // Tri par heure pour affichage ordonné
        ksort($stored);
        // Sauvegarde mise à jour
        $cacheItem->set($stored)->expiresAfter(86400); // 24h
        $this->cache->save($cacheItem);

        // Conversion en HourlyForecastData[]
        foreach ($stored as $time => $data) {
            $result[] = new HourlyForecastData(
                provider: 'OpenWeather',
                time: $time,
                temperature: $data['temp'],
                description: $data['desc'],
                emoji: $this->iconFromCode($data['icon'])['emoji']
            );
        }

        return $result;
    }

    private function iconFromCode(string $code): array
    {
        return match (substr($code, 0, 2)) {
            '01' => ['emoji' => '☀️', 'icon' => 'wi wi-day-sunny'],
            '02' => ['emoji' => '🌤️', 'icon' => 'wi wi-day-sunny-overcast'],
            '03', '04' => ['emoji' => '☁️', 'icon' => 'wi wi-cloudy'],
            '09', '10' => ['emoji' => '🌧️', 'icon' => 'wi wi-rain'],
            '11' => ['emoji' => '⛈️', 'icon' => 'wi wi-thunderstorm'],
            '13' => ['emoji' => '❄️', 'icon' => 'wi wi-snow'],
            '50' => ['emoji' => '🌫️', 'icon' => 'wi wi-fog'],
            default => $this->logUnknownSymbol($code),
        };
    }

    private function logUnknownSymbol(string $code): array
    {
        $this->logger->warning("Unrecognized symbol code for OpenWeather : $code");

        return ['emoji' => '🌡️', 'icon' => 'wi wi-na'];
    }
}
