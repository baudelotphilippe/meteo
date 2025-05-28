<?php

namespace App\Service\Weather;

use App\Dto\WeatherData;
use App\Dto\ForecastData;
use Psr\Log\LoggerInterface;
use App\Config\CityCoordinates;
use App\Dto\HourlyForecastData;
use Psr\Cache\CacheItemInterface;
use Psr\Cache\CacheItemPoolInterface;
use App\Service\Weather\WeatherProviderInterface;
use App\Service\Forecast\ForecastProviderInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use App\Service\HourlyForecast\HourlyForecastProviderInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;

class OpenWeatherService implements WeatherProviderInterface, ForecastProviderInterface, HourlyForecastProviderInterface
{
    private string $endpointWeather = 'https://api.openweathermap.org/data/2.5/weather';
    private string $endpointForecast = 'https://api.openweathermap.org/data/2.5/forecast';

    private array $forecastData = [];


    public function __construct(private HttpClientInterface $client, private string $apiKey, private LoggerInterface $logger, private CacheItemPoolInterface $cache) {}

    public function getWeather(): WeatherData
    {
        $cacheKey = 'openweather.current';
        $item = $this->cache->getItem($cacheKey);

        if (!$item->isHit()) {
            try {
                $data = $this->client->request('GET', $this->endpointWeather, [
                    'query' => [
                        'q' => CityCoordinates::CITY,
                        'appid' => $this->apiKey,
                        'units' => 'metric',
                        'lang' => 'fr'
                    ]
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
                    icon: $this->iconFromCode($data['weather'][0]['icon'])
                );
                $item->set($weather);
                $item->expiresAfter(600); // 10 minutes
                $this->cache->save($item);
            } catch (TransportExceptionInterface $e) {
                $this->logger->error('Erreur API OpenWeather Met.no : ' . $e->getMessage());
                $weather = new WeatherData(
                    provider: 'OpenWeather',
                    temperature: 0,
                    description: $e->getMessage(),
                    humidity: null,
                    wind: 0,
                    sourceName: 'OpenWeatherMap',
                    logoUrl: 'https://openweathermap.org/themes/openweathermap/assets/img/logo_white_cropped.png',
                    sourceUrl: 'https://openweathermap.org/current',
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
        $cacheKey = 'openweather.forecast';
        $item = $this->cache->getItem($cacheKey);

        if (!$item->isHit()) {
            try {
                $response = $this->client->request('GET', $this->endpointForecast, [
                    'query' => [
                        'lat' => CityCoordinates::LAT,
                        'lon' => CityCoordinates::LON,
                        'units' => 'metric',
                        'lang' => 'fr',
                        'appid' => $this->apiKey
                    ]
                ]);

                $data = $response->toArray();
                $grouped = [];
                $this->forecastData = $data['list'];

                foreach ($data['list'] as $entry) {
                    $dt = new \DateTimeImmutable($entry['dt_txt']);
                    $dayKey = $dt->format('Y-m-d');
                    $grouped[$dayKey][] = $entry;
                }

                $forecasts = [];

                foreach (array_slice($grouped, 0, 7, true) as $day => $entries) {
                    $temps = array_map(fn($e) => $e['main']['temp'], $entries);
                    $descFreq = array_count_values(array_map(fn($e) => $e['weather'][0]['description'], $entries));
                    arsort($descFreq);
                    $mainDesc = array_key_first($descFreq);

                    $iconCode = $entries[0]['weather'][0]['icon'];
                    $icon = $this->iconFromCode($iconCode); // facultatif

                    $forecasts[] = new ForecastData(
                        provider: 'OpenWeather',
                        date: new \DateTimeImmutable($day),
                        tmin: min($temps),
                        tmax: max($temps),
                        description: $icon . ' ' . ucfirst($mainDesc)
                    );
                }
                $item->set($forecasts);
                $item->expiresAfter(1800); // 30 min
                $this->cache->save($item);
            } catch (
                TransportExceptionInterface |
                ClientExceptionInterface |
                ServerExceptionInterface |
                RedirectionExceptionInterface $e
            ) {
                $this->logger->error('Erreur API Met.no : ' . $e->getMessage());
                $forecasts = [];
            }
        } else {
            $forecasts = $item->get();
        }
        return $forecasts;
    }

    public function getTodayHourly(): array
    {
        if (empty($this->forecastData)) {
            return [];
        }

        $today = (new \DateTimeImmutable())->format('Y-m-d');
        $heuresSouhaitees = ['06:00', '09:00', '12:00', '15:00', '18:00', '21:00'];
        $result = [];

        foreach ($this->forecastData as $entry) {
            $dt = new \DateTimeImmutable($entry['dt_txt']);
            if ($dt->format('Y-m-d') !== $today) {
                continue;
            }

            $heure = $dt->format('H:i');
            if (!in_array($heure, $heuresSouhaitees)) {
                continue;
            }

            $icon = $this->iconFromCode($entry['weather'][0]['icon']);

            $result[] = new HourlyForecastData(
                provider: 'OpenWeather',
                time: $dt->format('H\hi'),
                temperature: $entry['main']['temp'],
                description: $entry['weather'][0]['description'],
                icon: $icon
            );
        }

        return $result;
    }


    private function iconFromCode(string $code): string
    {
        return match (substr($code, 0, 2)) {
            '01' => '☀️',
            '02' => '🌤️',
            '03', '04' => '☁️',
            '09', '10' => '🌧️',
            '11' => '⛈️',
            '13' => '❄️',
            '50' => '🌫️',
            default => '🌡️',
        };
    }
}
