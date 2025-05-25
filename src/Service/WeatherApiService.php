<?php

namespace App\Service;

use App\Dto\WeatherData;
use App\Dto\ForecastData;
use App\Config\CityCoordinates;
use App\Dto\HourlyForecastData;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class WeatherApiService implements WeatherProviderInterface, ForecastProviderInterface, HourlyForecastProviderInterface
{
    private array $hourlyToday = [];

    public function __construct(
        private HttpClientInterface $client,
        private string $apiKey
    ) {}

    public function getWeather(): WeatherData
    {
        $response = $this->client->request('GET', 'https://api.weatherapi.com/v1/current.json', [
            'query' => [
                'key' => $this->apiKey,
                'q' => 'Poitiers',
                'lang' => 'fr'
            ]
        ]);

        $data = $response->toArray();

        return new WeatherData(
            provider: 'WeatherAPI',
            temperature: $data['current']['temp_c'],
            description: $data['current']['condition']['text'],
            humidity: $data['current']['humidity'],
            wind: $data['current']['wind_kph'],
            sourceName: 'WeatherAPI',
            logoUrl: 'https://cdn.weatherapi.com/v4/images/weatherapi_logo.png',
            sourceUrl: 'https://www.weatherapi.com/docs/'
        );
    }

    public function getForecast(): array
    {
        $response = $this->client->request('GET', 'https://api.weatherapi.com/v1/forecast.json', [
            'query' => [
                'key' => $this->apiKey,
                'q' => CityCoordinates::CITY,
                'days' => 3,
                'lang' => 'fr'
            ]
        ]);

        $data = $response->toArray();
        $this->hourlyToday = $data['forecast']['forecastday'][0]['hour'];

        $forecasts = [];
        foreach ($data['forecast']['forecastday'] as $day) {
            $forecasts[] = new ForecastData(
                provider: 'WeatherAPI',
                date: new \DateTimeImmutable($day['date']),
                tmin: $day['day']['mintemp_c'],
                tmax: $day['day']['maxtemp_c'],
                description: $this->iconFromCondition($day['day']['condition']['text']) . ' ' . ucfirst($day['day']['condition']['text'])
            );
        }

        return $forecasts;
    }


    public function getTodayHourly(): array
    {
        $heuresSouhaitees = ['06:00', '09:00', '12:00', '15:00',  '18:00', '21:00'];

        $result = [];

        foreach ($this->hourlyToday as $hour) {
            $heure = (new \DateTimeImmutable($hour['time']))->format('H:i');
            if (in_array($heure, $heuresSouhaitees)) {
                $result[] = new HourlyForecastData(
                    provider: 'WeatherAPI',
                    time: (new \DateTimeImmutable($hour['time']))->format('H\hi'),
                    temperature: $hour['temp_c'],
                    description: $hour['condition']['text'],
                    icon: $this->iconFromCondition($hour['condition']['text'])
                );
            }
        }

        return $result;
    }

    private function iconFromCondition(string $text): string
    {
        $t = strtolower($text);

        return match (true) {
            str_contains($t, 'orage') => '⛈️',
            str_contains($t, 'neige') => '❄️',
            str_contains($t, 'pluie') => '🌧️',
            str_contains($t, 'nuage'), str_contains($t, 'couvert') => '☁️',
            str_contains($t, 'bruine') => '🌦️',
            str_contains($t, 'ensoleillé'), str_contains($t, 'soleil') => '☀️',
            str_contains($t, 'brouillard') => '🌫️',
            default => '🌡️',
        };
    }
}
