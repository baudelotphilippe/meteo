<?php

namespace App\Service;

use App\Dto\WeatherData;
use App\Dto\ForecastData;
use App\Config\CityCoordinates;
use App\Service\WeatherProviderInterface;
use App\Service\ForecastProviderInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class OpenWeatherService implements WeatherProviderInterface, ForecastProviderInterface
{
    private string $endpoint = 'https://api.openweathermap.org/data/2.5/weather';

    public function __construct(private HttpClientInterface $client, private string $apiKey) {}

    public function getWeather(): WeatherData
    {
        $data = $this->client->request('GET', $this->endpoint, [
            'query' => [
                'q' => CityCoordinates::CITY,
                'appid' => $this->apiKey,
                'units' => 'metric',
                'lang' => 'fr'
            ]
        ])->toArray();

        return new WeatherData(
            provider: 'OpenWeather',
            temperature: $data['main']['temp'],
            description: $data['weather'][0]['description'],
            humidity: $data['main']['humidity'],
            wind: $data['wind']['speed'],
            sourceName: 'OpenWeatherMap',
            logoUrl: 'https://openweathermap.org/themes/openweathermap/assets/img/logo_white_cropped.png',
            sourceUrl: 'https://openweathermap.org/current'
        );
    }

    public function getForecast(): array
    {
        $response = $this->client->request('GET', 'https://api.openweathermap.org/data/2.5/forecast', [
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

        return $forecasts;
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
