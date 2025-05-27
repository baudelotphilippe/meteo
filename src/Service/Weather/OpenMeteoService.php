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
use Symfony\Contracts\HttpClient\Exception\{ClientExceptionInterface, ServerExceptionInterface, TransportExceptionInterface, RedirectionExceptionInterface};

class OpenMeteoService implements WeatherProviderInterface, ForecastProviderInterface, HourlyForecastProviderInterface
{
    private string $endpoint = 'https://api.open-meteo.com/v1/forecast';
    private array $hourlyData = [];

    public function __construct(
        private HttpClientInterface $client,
        private LoggerInterface $logger
    ) {}

    public function getWeather(): WeatherData
    {
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

            return new WeatherData(
                provider: 'Open-Meteo',
                temperature: $data['current_weather']['temperature'],
                description: $weatherInfo['label'],
                humidity:  $data['hourly']['relative_humidity_2m'][$index],
                wind: $data['current_weather']['windspeed'],
                sourceName: 'Open-Meteo (ECMWF, DWD, NOAA)',
                logoUrl: 'https://apps.homeycdn.net/app/com.spkes.openMeteo/21/0649a343-6f0b-4a54-9f68-ad818aaab853/drivers/weather/assets/images/large.png',
                sourceUrl: 'https://open-meteo.com/en/docs',
                icon: $weatherInfo['icon']
            );
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Erreur API Weather OpenMeteo : ' . $e->getMessage());
            return new WeatherData(
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
    }

    public function getForecast(): array
    {
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

            $forecasts = [];
            foreach ($data['daily']['time'] as $i => $day) {
                $info = $this->getWeatherInfo($data['daily']['weathercode'][$i]);

                $forecasts[] = new ForecastData(
                    provider: 'Open-Meteo',
                    date: new \DateTimeImmutable($day),
                    tmin: $data['daily']['temperature_2m_min'][$i],
                    tmax: $data['daily']['temperature_2m_max'][$i],
                    description: $info['icon'] . ' ' . $info['label']
                );
            }

            return $forecasts;
        } catch (TransportExceptionInterface | ClientExceptionInterface | ServerExceptionInterface | RedirectionExceptionInterface $e) {
            $this->logger->error('Erreur API Prévisions OpenMeteo : ' . $e->getMessage());
            return [];
        }
    }

    public function getTodayHourly(): array
    {
        if (empty($this->hourlyData)) {
            return []; // getForecast() n’a pas encore été appelé
        }

        $today = (new \DateTimeImmutable())->format('Y-m-d');
        $heuresSouhaitees = ['06:00', '09:00', '12:00', '15:00', '18:00', '21:00'];
        $result = [];

        foreach ($this->hourlyData['time'] as $i => $iso) {
            $dt = new \DateTimeImmutable($iso);
            if ($dt->format('Y-m-d') !== $today || !in_array($dt->format('H:i'), $heuresSouhaitees)) {
                continue;
            }

            $info = $this->getWeatherInfo($this->hourlyData['weathercode'][$i]);

            $result[] = new HourlyForecastData(
                provider: 'Open-Meteo',
                time: $dt->format('H\hi'),
                temperature: $this->hourlyData['temperature_2m'][$i],
                description: $info['label'],
                icon: $info['icon']
            );
        }

        return $result;
    }

    private function getWeatherInfo(int $code): array
    {
        $map = [
            0 => ['icon' => '☀️', 'label' => 'Ciel clair'],
            1 => ['icon' => '🌤️', 'label' => 'Principalement clair'],
            2 => ['icon' => '⛅', 'label' => 'Partiellement nuageux'],
            3 => ['icon' => '☁️', 'label' => 'Couvert'],
            45, 48 => ['icon' => '🌫️', 'label' => 'Brouillard'],
            51, 53, 55 => ['icon' => '🌦️', 'label' => 'Bruine'],
            56, 57 => ['icon' => '🌧️', 'label' => 'Bruine verglaçante'],
            61, 63, 65 => ['icon' => '🌧️', 'label' => 'Pluie'],
            66, 67 => ['icon' => '🌧️', 'label' => 'Pluie verglaçante'],
            71, 73, 75 => ['icon' => '❄️', 'label' => 'Neige'],
            77 => ['icon' => '❄️', 'label' => 'Grains de neige'],
            80, 81, 82 => ['icon' => '🌦️', 'label' => 'Averses'],
            85, 86 => ['icon' => '❄️', 'label' => 'Averses de neige'],
            95 => ['icon' => '⛈️', 'label' => 'Orage'],
            96, 99 => ['icon' => '⛈️', 'label' => 'Orage avec grêle'],
        ];

        return $map[$code] ?? ['icon' => '🌡️', 'label' => 'Inconnu'];
    }
}
