<?php

namespace App\Service;

use App\Dto\WeatherData;
use App\Dto\ForecastData;
use App\Dto\HourlyForecastData;
use App\Config\CityCoordinates;
use App\Service\WeatherProviderInterface;
use App\Service\HourlyForecastProviderInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class OpenMeteoService  implements WeatherProviderInterface, ForecastProviderInterface, HourlyForecastProviderInterface
{
    private string $endpoint = 'https://api.open-meteo.com/v1/forecast';
    private array $hourlyData = [];

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
                'hourly' => 'temperature_2m,weathercode',
                'timezone' => 'auto'
            ]
        ]);

        $data = $response->toArray();

        $this->hourlyData = $data['hourly'];
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
            if ($dt->format('Y-m-d') !== $today) {
                continue;
            }

            $heure = $dt->format('H:i');
            if (!in_array($heure, $heuresSouhaitees)) {
                continue;
            }

            $temp = $this->hourlyData['temperature_2m'][$i];
            $code = $this->hourlyData['weathercode'][$i];
            $info = $this->translateWeatherCode($code);

            $result[] = new HourlyForecastData(
                provider: 'Open-Meteo',
                time: $dt->format('H\hi'),
                temperature: $temp,
                description: $info['label'],
                icon: $info['icon']
            );
        }

        return $result;
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
