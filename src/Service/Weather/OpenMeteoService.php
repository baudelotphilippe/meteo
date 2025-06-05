<?php

namespace App\Service\Weather;

use App\Dto\WeatherData;
use App\Dto\ForecastData;
use Psr\Log\LoggerInterface;
use App\Config\CityCoordinates;
use App\Dto\HourlyForecastData;
use App\Service\Weather\WeatherProviderInterface;
use App\Service\Forecast\ForecastProviderInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use App\Service\HourlyForecast\HourlyForecastProviderInterface;
use Psr\Cache\CacheItemPoolInterface;
use Symfony\Contracts\HttpClient\Exception\{ClientExceptionInterface, ServerExceptionInterface, TransportExceptionInterface, RedirectionExceptionInterface};

class OpenMeteoService implements WeatherProviderInterface, ForecastProviderInterface, HourlyForecastProviderInterface
{
    private string $endpoint = 'https://api.open-meteo.com/v1/forecast';
    private array $hourlyData = [];

    public function __construct(
        private HttpClientInterface $client,
        private LoggerInterface $logger,
        private CacheItemPoolInterface $cache
    ) {}

    public function getWeather(): WeatherData
    {
        $cacheKey = 'openmeteo.current';
        $item = $this->cache->getItem($cacheKey);

        if (!$item->isHit()) {
            try {
                $data = $this->client->request('GET', $this->endpoint, [
                    'query' => [
                        'latitude' => CityCoordinates::LAT,
                        'longitude' => CityCoordinates::LON,
                        'current_weather' => true,
                        'hourly' => 'relative_humidity_2m',
                        'timezone' => 'auto'
                    ]
                ])->toArray();

                $weatherInfo = $this->getWeatherInfo($data['current_weather']['weathercode']);
                $target = (new \DateTimeImmutable())->format('Y-m-d\TH:00');
                $index = array_search($target, $data['hourly']['time']);

                $weather = new WeatherData(
                    provider: 'Open-Meteo',
                    temperature: $data['current_weather']['temperature'],
                    description: $weatherInfo['label'],
                    humidity: $data['hourly']['relative_humidity_2m'][$index],
                    wind: $data['current_weather']['windspeed'],
                    sourceName: 'Open-Meteo (ECMWF, DWD, NOAA)',
                    logoUrl: 'https://apps.homeycdn.net/app/com.spkes.openMeteo/21/0649a343-6f0b-4a54-9f68-ad818aaab853/drivers/weather/assets/images/large.png',
                    sourceUrl: 'https://open-meteo.com/en/docs',
                    icon: $weatherInfo['icon']
                );
                $item->set($weather);
                $item->expiresAfter(600); // 10 minutes
                $this->cache->save($item);
            } catch (TransportExceptionInterface $e) {
                $this->logger->error('Erreur API Weather OpenMeteo : ' . $e->getMessage());
                $weather = new WeatherData(
                    provider: 'Open-Meteo',
                    temperature: 0,
                    description: $e->getMessage(),
                    humidity: null,
                    wind: 0,
                    sourceName: 'Open-Meteo (ECMWF, DWD, NOAA)',
                    logoUrl: 'https://apps.homeycdn.net/app/com.spkes.openMeteo/21/0649a343-6f0b-4a54-9f68-ad818aaab853/drivers/weather/assets/images/large.png',
                    sourceUrl: 'https://open-meteo.com/en/docs',
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
        $cacheKey = 'openmeteo.forecast';
        $item = $this->cache->getItem($cacheKey);

        if (!$item->isHit()) {
            try {
                $response = $this->client->request('GET', $this->endpoint, [
                    'query' => [
                        'latitude' => CityCoordinates::LAT,
                        'longitude' => CityCoordinates::LON,
                        'daily' => 'temperature_2m_min,temperature_2m_max,weathercode',
                        'hourly' => 'temperature_2m,weathercode',
                        'timezone' => 'auto'
                    ]
                ]);

                $data = $response->toArray();
                $this->hourlyData = $data['hourly'];
                // $this->logger->info(print_r($data['hourly']));

                $forecasts = [];
                foreach ($data['daily']['time'] as $i => $day) {
                    $info = $this->getWeatherInfo($data['daily']['weathercode'][$i]);

                    $forecasts[] = new ForecastData(
                        provider: 'Open-Meteo',
                        date: new \DateTimeImmutable($day),
                        tmin: $data['daily']['temperature_2m_min'][$i],
                        tmax: $data['daily']['temperature_2m_max'][$i],
                        icon: $info['icon'],
                        emoji: $info['emoji'],

                    );
                }
                $item->set(["forecast" => $forecasts, "todayHourly" => $this->hourlyData]);
                $item->expiresAfter(1800); // 30 min
                $this->cache->save($item);
            } catch (TransportExceptionInterface | ClientExceptionInterface | ServerExceptionInterface | RedirectionExceptionInterface $e) {
                $this->logger->error('Erreur API Prévisions OpenMeteo : ' . $e->getMessage());
                $forecasts = [];
            }
        } else {
            $infos = $item->get();
            $forecasts = $infos["forecast"];
            $this->hourlyData = $infos["todayHourly"];
        }
        return $forecasts;
    }

    public function getTodayHourly(): array
    {
        if (empty($this->hourlyData)) {
            return []; // getForecast() n’a pas encore été appelé
        }

        $today = (new \DateTimeImmutable())->format('Y-m-d');
        $tomorrow = (new \DateTimeImmutable("+1 day"))->format('Y-m-d');

        $result = [];

        foreach ($this->hourlyData['time'] as $i => $iso) {
            $dt = new \DateTimeImmutable($iso);
            $date = $dt->format('Y-m-d');
            $time = $dt->format('G\h');

            if (($date === $today) || (($date === $tomorrow) && ($time === "0h"))) {
                $time = ($date === $tomorrow) ? '24h' : $time;
                $info = $this->getWeatherInfo($this->hourlyData['weathercode'][$i]);
                $result[] = new HourlyForecastData(
                    provider: 'Open-Meteo',
                    time: $time,
                    temperature: $this->hourlyData['temperature_2m'][$i],
                    description: $info['label'],
                    emoji: $info['emoji'],

                );
            }
        }

        return $result;
    }

    private function getWeatherInfo(int $code): array
    {
        $map = [
            0 => ['emoji' => '☀️', 'label' => 'Ciel clair', 'icon' => 'wi wi-day-sunny'],
            1 => ['emoji' => '🌤️', 'label' => 'Principalement clair', 'icon' => 'wi wi-day-sunny-overcast'],
            2 => ['emoji' => '⛅', 'label' => 'Partiellement nuageux', 'icon' => 'wi wi-day-cloudy'],
            3 => ['emoji' => '☁️', 'label' => 'Couvert', 'icon' => 'wi wi-cloudy'],

            45 => ['emoji' => '🌫️', 'label' => 'Brouillard', 'icon' => 'wi wi-fog'],
            48 => ['emoji' => '🌫️', 'label' => 'Brouillard', 'icon' => 'wi wi-fog'],

            51 => ['emoji' => '🌦️', 'label' => 'Bruine', 'icon' => 'wi wi-showers'],
            53 => ['emoji' => '🌦️', 'label' => 'Bruine', 'icon' => 'wi wi-showers'],
            55 => ['emoji' => '🌦️', 'label' => 'Bruine', 'icon' => 'wi wi-showers'],

            56 => ['emoji' => '🌧️', 'label' => 'Bruine verglaçante', 'icon' => 'wi wi-rain-mix'],
            57 => ['emoji' => '🌧️', 'label' => 'Bruine verglaçante', 'icon' => 'wi wi-rain-mix'],

            61 => ['emoji' => '🌧️', 'label' => 'Pluie', 'icon' => 'wi wi-rain'],
            63 => ['emoji' => '🌧️', 'label' => 'Pluie', 'icon' => 'wi wi-rain'],
            65 => ['emoji' => '🌧️', 'label' => 'Pluie', 'icon' => 'wi wi-rain'],

            66 => ['emoji' => '🌧️', 'label' => 'Pluie verglaçante', 'icon' => 'wi wi-rain-mix'],
            67 => ['emoji' => '🌧️', 'label' => 'Pluie verglaçante', 'icon' => 'wi wi-rain-mix'],

            71 => ['emoji' => '❄️', 'label' => 'Neige', 'icon' => 'wi wi-snow'],
            73 => ['emoji' => '❄️', 'label' => 'Neige', 'icon' => 'wi wi-snow'],
            75 => ['emoji' => '❄️', 'label' => 'Neige', 'icon' => 'wi wi-snow'],

            77 => ['emoji' => '❄️', 'label' => 'Grains de neige', 'icon' => 'wi wi-snow-wind'],

            80 => ['emoji' => '🌦️', 'label' => 'Averses', 'icon' => 'wi wi-showers'],
            81 => ['emoji' => '🌦️', 'label' => 'Averses', 'icon' => 'wi wi-showers'],
            82 => ['emoji' => '🌦️', 'label' => 'Averses', 'icon' => 'wi wi-showers'],

            85 => ['emoji' => '❄️', 'label' => 'Averses de neige', 'icon' => 'wi wi-snow'],
            86 => ['emoji' => '❄️', 'label' => 'Averses de neige', 'icon' => 'wi wi-snow'],

            95 => ['emoji' => '⛈️', 'label' => 'Orage', 'icon' => 'wi wi-thunderstorm'],
            96 => ['emoji' => '⛈️', 'label' => 'Orage avec grêle', 'icon' => 'wi wi-thunderstorm'],
            99 => ['emoji' => '⛈️', 'label' => 'Orage avec grêle', 'icon' => 'wi wi-thunderstorm'],
        ];

        return $map[$code] ?? ['emoji' => '🌡️', 'label' => 'Inconnu', 'icon' => 'wi wi-na'];
    }
}
