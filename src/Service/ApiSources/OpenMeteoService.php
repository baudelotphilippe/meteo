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

class OpenMeteoService implements WeatherProviderInterface, ForecastProviderInterface, HourlyForecastProviderInterface
{
    private string $endpoint = 'https://api.open-meteo.com/v1/forecast';
    private array $hourlyToday = [];
    private const API_NAME = 'Open-Meteo';

    public function __construct(
        private HttpClientInterface $client,
        private LoggerInterface $logger,
        private CacheItemPoolInterface $cache,
        private LoggerInterface $meteoLogger,
    ) {
    }

    public function getWeather(LocationCoordinatesInterface $locationCoordinates): WeatherData
    {
        $cacheKey = 'openmeteo.current'.sprintf('%.6f_%.6f', $locationCoordinates->getLatitude(), $locationCoordinates->getLongitude());
        $item = $this->cache->getItem($cacheKey);

        if (!$item->isHit()) {
            try {
                $query = [
                    'latitude' => $locationCoordinates->getLatitude(),
                    'longitude' => $locationCoordinates->getLongitude(),
                    'current_weather' => true,
                    'hourly' => 'relative_humidity_2m',
                    'timezone' => 'auto',
                ];

                $data = $this->client->request('GET', $this->endpoint, ['query' => $query])->toArray();

                $this->meteoLogger->info('Interrogation '.self::API_NAME, [
                    'query' => $query,
                    'endpoint' => $this->endpoint,
                    'data' => $data,
                ]);

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
                $this->meteoLogger->error('Interrogation API Weather OpenMeteo ', [
                    'query' => $query,
                    'endpoint' => $this->endpoint,
                    'error' => $e->getMessage(),
                ]);

                $this->logger->error('Erreur API Weather OpenMeteo : '.$e->getMessage());
                $this->meteoLogger->error('Erreur API Weather OpenMeteo : '.$e->getMessage());

                $weather = new WeatherData(
                    provider: 'Open-Meteo',
                    temperature: 0,
                    description: $e->getMessage(),
                    humidity: null,
                    wind: 0,
                    sourceName: 'Open-Meteo (ECMWF, DWD, NOAA)',
                    logoUrl: 'https://apps.homeycdn.net/app/com.spkes.openMeteo/21/0649a343-6f0b-4a54-9f68-ad818aaab853/drivers/weather/assets/images/large.png',
                    sourceUrl: 'https://open-meteo.com/en/docs',
                    icon: null,
                    enabled: false
                );
            }
        } else {
            $weather = $item->get();
        }

        return $weather;
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

        return $map[$code] ?? $this->logUnknownSymbol($code);
    }

    private function logUnknownSymbol(int $code): array
    {
        $this->logger->warning("Unrecognized symbol code for OpenMeteo : $code");

        return ['label' => 'inconnu', 'emoji' => '🌡️', 'icon' => 'wi wi-na'];
    }

    public function getForecast(LocationCoordinatesInterface $locationCoordinates): array
    {
        $cacheKey = 'openmeteo.forecast'.sprintf('%.6f_%.6f', $locationCoordinates->getLatitude(), $locationCoordinates->getLongitude());

        $item = $this->cache->getItem($cacheKey);

        if (!$item->isHit()) {
            $data = $this->getForecastApiInformations($locationCoordinates);
            $this->hourlyToday = $data['hourly'];

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
                    'latitude' => $locationCoordinates->getLatitude(),
                    'longitude' => $locationCoordinates->getLongitude(),
                    'daily' => 'temperature_2m_min,temperature_2m_max,weathercode',
                    'hourly' => 'temperature_2m,weathercode',
                    'timezone' => 'auto',
                ],
            ]);

            return $response->toArray();
        } catch (
            TransportExceptionInterface|
            ClientExceptionInterface|
            ServerExceptionInterface|
            RedirectionExceptionInterface $e
        ) {
            $this->logger->error('Erreur API Prévisions OpenMeteo : '.$e->getMessage());

            return [];
        }
    }

    public function getTodayHourly(LocationCoordinatesInterface $locationCoordinates): array
    {
        if (empty($this->hourlyToday)) {
            $data = $this->getForecastApiInformations($locationCoordinates);
            $this->hourlyToday = $data['hourly'];
        }

        $today = (new \DateTimeImmutable())->setTimezone(new \DateTimeZone('Europe/Paris'));
        $tomorrow = (new \DateTimeImmutable('+1 day'))->setTimezone(new \DateTimeZone('Europe/Paris'));

        $result = [];

        foreach ($this->hourlyToday['time'] as $i => $iso) {
            $dt = new \DateTimeImmutable($iso);
            if ($dt >= $today && $dt < $tomorrow) {
                $info = $this->getWeatherInfo($this->hourlyToday['weathercode'][$i]);
                try {
                    $result[] = new HourlyForecastData(
                        provider: 'Open-Meteo',
                        time: $dt,
                        temperature: $this->hourlyToday['temperature_2m'][$i],
                        description: $info['label'],
                        emoji: $info['emoji'],
                    );
                } catch (\InvalidArgumentException $e) {
                    $this->logger->error('erreur :'.$e->getMessage());
                }
            }
        }

        return $result;
    }
}
