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

class WeatherApiService implements WeatherProviderInterface, ForecastProviderInterface, HourlyForecastProviderInterface
{
    private const API_NAME = 'WeatherApi';
    private string $endpointWeather = 'https://api.weatherapi.com/v1/current.json';
    private string $endpointForecast = 'https://api.weatherapi.com/v1/forecast.json';
    private array $hourlyToday = [];

    public function __construct(
        private HttpClientInterface $client,
        private string $apiKey,
        private LoggerInterface $logger,
        private CacheItemPoolInterface $cache,
        private LoggerInterface $meteoLogger,
        private bool $meteo_cache,
    ) {
    }

    public function getWeather(LocationCoordinatesInterface $locationCoordinates): WeatherData
    {
        if (empty($this->apiKey)) {
            throw new \RuntimeException('Clé API WeatherApi absente.');
        }

        $cacheKey = 'weatherapi.current'.sprintf('%.6f_%.6f', $locationCoordinates->getLatitude(), $locationCoordinates->getLongitude());

        $item = $this->cache->getItem($cacheKey);

        if (!$item->isHit() || !$this->meteo_cache) {
            try {
                $query = [
                    'key' => $this->apiKey,
                    'q' => $locationCoordinates->getName(),
                    'lang' => 'fr',
                ];
                $data = $this->client->request('GET', $this->endpointWeather, ['query' => $query])->toArray();
                $this->meteoLogger->info('Interrogation '.self::API_NAME, [
                    'query' => $query,
                    'endpoint' => $this->endpointWeather,
                    'data' => $data,
                ]);
                $weather = new WeatherData(
                    provider: 'WeatherAPI',
                    temperature: $data['current']['temp_c'],
                    description: $data['current']['condition']['text'],
                    humidity: $data['current']['humidity'],
                    wind: $data['current']['wind_kph'],
                    sourceName: 'WeatherAPI',
                    logoUrl: 'https://cdn.weatherapi.com/v4/images/weatherapi_logo.png',
                    sourceUrl: 'https://www.weatherapi.com/docs/',
                    icon: $this->iconFromCondition($data['current']['condition']['text'])['icon']
                );
                $item->set($weather);
                $item->expiresAfter(600); // 10 minutes
                $this->cache->save($item);
            } catch (ClientExceptionInterface|TransportExceptionInterface $e) {
                $this->logger->error('Erreur API WeatherAPI Met.no : '.$e->getMessage());
                $this->meteoLogger->info('Interrogation '.self::API_NAME, [
                    'query' => $query,
                    'endpoint' => $this->endpointWeather,
                    'error' => $e->getMessage(),
                ]);
                $weather = new WeatherData(
                    provider: 'WeatherAPI',
                    temperature: 0,
                    description: $e->getMessage(),
                    humidity: null,
                    wind: 0,
                    sourceName: 'WeatherAPI',
                    logoUrl: 'https://cdn.weatherapi.com/v4/images/weatherapi_logo.png',
                    sourceUrl: 'https://www.weatherapi.com/docs/',
                    icon: null,
                    enabled: false
                );
            }
        } else {
            $weather = $item->get();
        }

        return $weather;
    }

    private function iconFromCondition(string $text): array
    {
        $t = mb_strtolower($text);

        return match (true) {
            str_contains($t, 'orage') => ['emoji' => '⛈️', 'icon' => 'wi wi-thunderstorm'],
            str_contains($t, 'neige'), str_contains($t, 'averses de neige') => ['emoji' => '❄️', 'icon' => 'wi wi-snow'],
            str_contains($t, 'grêle') => ['emoji' => '🧊', 'icon' => 'wi wi-hail'],
            str_contains($t, 'pluie'), str_contains($t, 'averses') => ['emoji' => '🌧️', 'icon' => 'wi wi-rain'],
            str_contains($t, 'bruine') => ['emoji' => '🌦️', 'icon' => 'wi wi-showers'],
            str_contains($t, 'brouillard'), str_contains($t, 'brume') => ['emoji' => '🌫️', 'icon' => 'wi wi-fog'],
            str_contains($t, 'ensoleillé'), str_contains($t, 'soleil'), str_contains($t, 'clair'), str_contains($t, 'dégagé') => ['emoji' => '☀️', 'icon' => 'wi wi-day-sunny'],
            str_contains($t, 'partiellement couvert'), str_contains($t, 'partiellement nuageux'), str_contains($t, 'éclaircies'), str_contains($t, 'variable'), str_contains($t, 'mitigé') => ['emoji' => '⛅', 'icon' => 'wi wi-day-cloudy'],
            str_contains($t, 'couvert'), str_contains($t, 'nuageux') => ['emoji' => '☁️', 'icon' => 'wi wi-cloudy'],
            str_contains($t, 'tempête'), str_contains($t, 'fortes rafales') => ['emoji' => '🌪️', 'icon' => 'wi wi-storm-showers'],
            str_contains($t, 'venteux'), str_contains($t, 'rafales') => ['emoji' => '💨', 'icon' => 'wi wi-strong-wind'],
            str_contains($t, 'gel'), str_contains($t, 'givré') => ['emoji' => '🥶', 'icon' => 'wi wi-snowflake-cold'],
            str_contains($t, 'beau temps') => ['emoji' => '🌞', 'icon' => 'wi wi-day-sunny'],
            default => $this->logUnknownSymbol($text),
        };
    }

    private function logUnknownSymbol(string $code): array
    {
        $this->logger->warning("Unrecognized symbol code for WeatherApiService : $code");

        return ['label' => 'Inconnu', 'emoji' => '🌡️', 'icon' => 'wi wi-na'];
    }

    public function getForecast(LocationCoordinatesInterface $locationCoordinates): array
    {
        $cacheKey = 'weatherapi.forecast'.sprintf('%.6f_%.6f', $locationCoordinates->getLatitude(), $locationCoordinates->getLongitude());
        $item = $this->cache->getItem($cacheKey);

        if (!$item->isHit() || !$this->meteo_cache) {
            $data = $this->getForecastApiInformations($locationCoordinates);
            $this->hourlyToday = $data['forecast']['forecastday'];

            $forecasts = [];
            foreach ($data['forecast']['forecastday'] as $day) {
                $picto = $this->iconFromCondition($day['day']['condition']['text']);
                $forecasts[] = new ForecastData(
                    provider: 'WeatherAPI',
                    date: new \DateTimeImmutable($day['date']),
                    tmin: $day['day']['mintemp_c'],
                    tmax: $day['day']['maxtemp_c'],
                    icon: $picto['icon'],
                    emoji: $picto['emoji']
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
            $response = $this->client->request('GET', $this->endpointForecast, [
                'query' => [
                    'key' => $this->apiKey,
                    'q' => $locationCoordinates->getName(),
                    'days' => 3,
                    'lang' => 'fr',
                ],
            ]);

            return $response->toArray();
        } catch (
            TransportExceptionInterface|
            ClientExceptionInterface|
            ServerExceptionInterface|
            RedirectionExceptionInterface $e
        ) {
            $this->logger->error('Erreur API Prévisions WeatherAPI : '.$e->getMessage());

            return [];
        }
    }

    public function getTodayHourly(LocationCoordinatesInterface $locationCoordinates): array
    {
        if (empty($this->hourlyToday)) {
            $data = $this->getForecastApiInformations($locationCoordinates);
            $this->hourlyToday = $data['forecast']['forecastday'];
        }

        $today = (new \DateTimeImmutable())->setTimezone(new \DateTimeZone('Europe/Paris'));
        $tomorrow = (new \DateTimeImmutable('+1 day'))->setTimezone(new \DateTimeZone('Europe/Paris'));
        $result = [];

        $infos = ['today' => 0, 'tomorrow' => 1];
        foreach ($infos as $day => $dayposition) {
            foreach ($this->hourlyToday[$dayposition]['hour'] as $hour) {
                $dt = new \DateTimeImmutable($hour['time']);
                if ($dt >= $today && $dt < $tomorrow) {
                    try {
                        $result[] = new HourlyForecastData(
                            provider: 'WeatherAPI',
                            time: $dt,
                            temperature: $hour['temp_c'],
                            description: $hour['condition']['text'],
                            emoji: $this->iconFromCondition($hour['condition']['text'])['emoji']
                        );
                    } catch (\InvalidArgumentException $e) {
                        $this->logger->error('erreur :'.$e->getMessage());
                    }
                }
            }
        }

        return $result;
    }
}
