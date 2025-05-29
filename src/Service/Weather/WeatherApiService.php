<?php

namespace App\Service\Weather;

use App\Dto\WeatherData;
use App\Dto\ForecastData;
use Psr\Log\LoggerInterface;
use App\Config\CityCoordinates;
use App\Dto\HourlyForecastData;
use Psr\Cache\CacheItemPoolInterface;
use App\Service\Forecast\ForecastProviderInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use App\Service\HourlyForecast\HourlyForecastProviderInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;

class WeatherApiService implements WeatherProviderInterface, ForecastProviderInterface, HourlyForecastProviderInterface
{
    private string $endpointWeather = 'https://api.weatherapi.com/v1/current.json';
    private string $endpointForecast =  'https://api.weatherapi.com/v1/forecast.json';
    private array $hourlyToday = [];

    public function __construct(
        private HttpClientInterface $client,
        private string $apiKey,
        private LoggerInterface $logger,
        private CacheItemPoolInterface $cache
    ) {}

    public function getWeather(): WeatherData
    {
        $cacheKey = 'weatherapi.current';
        $item = $this->cache->getItem($cacheKey);

        if (!$item->isHit()) {
            try {
                $response = $this->client->request('GET', $this->endpointWeather, [
                    'query' => [
                        'key' => $this->apiKey,
                        'q' => 'Poitiers',
                        'lang' => 'fr'
                    ]
                ]);

                $data = $response->toArray();
                $weather = new WeatherData(
                    provider: 'WeatherAPI',
                    temperature: $data['current']['temp_c'],
                    description: $data['current']['condition']['text'],
                    humidity: $data['current']['humidity'],
                    wind: $data['current']['wind_kph'],
                    sourceName: 'WeatherAPI',
                    logoUrl: 'https://cdn.weatherapi.com/v4/images/weatherapi_logo.png',
                    sourceUrl: 'https://www.weatherapi.com/docs/',
                    icon: $this->iconFromCondition($data['current']['condition']['text'])
                );
                $item->set($weather);
                $item->expiresAfter(600); // 10 minutes
                $this->cache->save($item);
            } catch (TransportExceptionInterface $e) {
                $this->logger->error('Erreur API WeatherAPI Met.no : ' . $e->getMessage());
                $weather = new WeatherData(
                    provider: 'WeatherAPI',
                    temperature: 0,
                    description: $e->getMessage(),
                    humidity: null,
                    wind: 0,
                    sourceName: 'WeatherAPI',
                    logoUrl: 'https://cdn.weatherapi.com/v4/images/weatherapi_logo.png',
                    sourceUrl: 'https://www.weatherapi.com/docs/',
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
        $cacheKey = 'weatherapi.forecast';
        $item = $this->cache->getItem($cacheKey);

        if (!$item->isHit()) {
            try {
                $response = $this->client->request('GET', $this->endpointForecast, [
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

                                $item->set(["forecast"=>$forecasts, "todayHourly"=>$this->hourlyToday]);

                $item->expiresAfter(1800); // 30 min
                $this->cache->save($item);
            } catch (
                TransportExceptionInterface |
                ClientExceptionInterface |
                ServerExceptionInterface |
                RedirectionExceptionInterface $e
            ) {
                $this->logger->error('Erreur API Prévisions WeatherAPI : ' . $e->getMessage());
                return [];
            }
        } else {
            $infos = $item->get();
            $forecasts=$infos["forecast"];
            $this->hourlyToday=$infos["todayHourly"];
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
        $t = mb_strtolower($text); // mieux pour les accents

        return match (true) {
            str_contains($t, 'orage') => '⛈️',
            str_contains($t, 'neige'), str_contains($t, 'averses de neige') => '❄️',
            str_contains($t, 'grêle') => '🧊',
            str_contains($t, 'pluie'), str_contains($t, 'averses') => '🌧️',
            str_contains($t, 'bruine') => '🌦️',
            str_contains($t, 'brouillard'), str_contains($t, 'brume') => '🌫️',
            str_contains($t, 'ensoleillé'), str_contains($t, 'soleil') => '☀️',
            str_contains($t, 'partiellement couvert'), str_contains($t, 'partiellement nuageux') => '⛅',
            str_contains($t, 'couvert'), str_contains($t, 'nuageux') => '☁️',
            str_contains($t, 'venteux'), str_contains($t, 'rafales') => '💨',
            str_contains($t, 'gel'), str_contains($t, 'givré') => '🥶',
            str_contains($t, 'beau temps') => '🌞',
            default => '🌡️',
        };
    }
}
