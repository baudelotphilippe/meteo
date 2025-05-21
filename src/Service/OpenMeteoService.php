<?php

namespace App\Service;

use App\Dto\WeatherData;
use App\Dto\ForecastData;
use App\Config\CityCoordinates;
use App\Service\WeatherProviderInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class OpenMeteoService  implements WeatherProviderInterface, ForecastProviderInterface
{
    private string $endpoint = 'https://api.open-meteo.com/v1/forecast';

    public function __construct(private HttpClientInterface $client) {}

    public function getWeather(): WeatherData
    {
        $data = $this->client->request('GET', $this->endpoint, [
            'query' => [
                'latitude' => CityCoordinates::LAT,
                'longitude' => CityCoordinates::LON,
                'current_weather' => true,
                'timezone' => 'auto'
            ]
        ])->toArray();
        return new WeatherData(
            provider: 'Open-Meteo',
            temperature: $data['current_weather']['temperature'],
            description: null,
            humidity: null,
            wind: $data['current_weather']['windspeed'],
            sourceName: 'Open-Meteo (ECMWF, DWD, NOAA)',
            logoUrl: 'https://apps.homeycdn.net/app/com.spkes.openMeteo/21/0649a343-6f0b-4a54-9f68-ad818aaab853/drivers/weather/assets/images/large.png',
            sourceUrl: 'https://open-meteo.com/en/docs'
        );
    }


    public function getForecast(): array
    {
        $response = $this->client->request('GET', 'https://api.open-meteo.com/v1/forecast', [
            'query' => [
                'latitude' => CityCoordinates::LAT,
                'longitude' => CityCoordinates::LON,
                'daily' => 'temperature_2m_min,temperature_2m_max,weathercode',
                'timezone' => 'auto'
            ]
        ]);

        $data = $response->toArray();

        $dates = $data['daily']['time'];
        $tmins = $data['daily']['temperature_2m_min'];
        $tmaxs = $data['daily']['temperature_2m_max'];
        $codes = $data['daily']['weathercode'];

        $forecasts = [];

        foreach ($dates as $i => $day) {
            $info = $this->translateWeatherCode($codes[$i]);

            $forecasts[] = new ForecastData(
                provider: 'Open-Meteo',
                date: new \DateTimeImmutable($day),
                tmin: $tmins[$i],
                tmax: $tmaxs[$i],
                description: $info['icon'] . ' ' . $info['label']
            );
        }

        return $forecasts;
    }

    private function translateWeatherCode(int $code): array
    {
        return match ($code) {
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
            default => ['icon' => '🌡️', 'label' => 'Inconnu'],
        };
    }
}
