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

class OpenWeatherService implements WeatherProviderInterface, ForecastProviderInterface, HourlyForecastProviderInterface
{
    private const API_NAME = 'OpenWeather';
    private string $endpointWeather = 'https://api.openweathermap.org/data/2.5/weather';
    private string $endpointForecast = 'https://api.openweathermap.org/data/2.5/forecast';
    private array $hourlyToday = [];

    public function __construct(
        private HttpClientInterface $client,
        private string $apiKey,
        private LoggerInterface $logger,
        private CacheItemPoolInterface $cache,
        private LoggerInterface $meteoLogger,
        private bool $meteo_cache
    ) {
    }

    public function getWeather(LocationCoordinatesInterface $locationCoordinates): WeatherData
    {
        if (empty($this->apiKey)) {
            throw new \RuntimeException('Clé API OpenWeather absente.');
        }

        $cacheKey = 'openweather.current'.sprintf('%.6f_%.6f', $locationCoordinates->getLatitude(), $locationCoordinates->getLongitude());
        $item = $this->cache->getItem($cacheKey);

        if (!$item->isHit() || !$this->meteo_cache) {
            try {
                $query = [
                    'q' => $locationCoordinates->getName(),
                    'appid' => $this->apiKey,
                    'units' => 'metric',
                    'lang' => 'fr',
                ];

                $data = $this->client->request('GET', $this->endpointWeather, ['query' => $query])->toArray();

                $this->meteoLogger->info('Interrogation '.self::API_NAME, [
                    'query' => $query,
                    'endpoint' => $this->endpointWeather,
                    'data' => $data,
                ]);

                $weather = new WeatherData(
                    provider: 'OpenWeather',
                    temperature: $data['main']['temp'],
                    description: $data['weather'][0]['description'],
                    humidity: $data['main']['humidity'],
                    wind: $data['wind']['speed'],
                    sourceName: 'OpenWeatherMap',
                    logoUrl: 'https://openweathermap.org/themes/openweathermap/assets/img/logo_white_cropped.png',
                    sourceUrl: 'https://openweathermap.org/current',
                    icon: $this->iconFromCode($data['weather'][0]['icon'])['icon']
                );
                $item->set($weather);
                $item->expiresAfter(600); // 10 minutes
                $this->cache->save($item);
            } catch (ClientExceptionInterface|TransportExceptionInterface $e) {
                $this->logger->error('Erreur API OpenWeather Met.no : '.$e->getMessage());
                $this->meteoLogger->error('Interrogation '.self::API_NAME, [
                    'query' => $query,
                    'endpoint' => $this->endpointWeather,
                    'error' => $e->getMessage(),
                ]);

                $weather = new WeatherData(
                    provider: 'OpenWeather',
                    temperature: 0,
                    description: $e->getMessage(),
                    humidity: null,
                    wind: 0,
                    sourceName: 'OpenWeatherMap',
                    logoUrl: 'https://openweathermap.org/themes/openweathermap/assets/img/logo_white_cropped.png',
                    sourceUrl: 'https://openweathermap.org/current',
                    icon: null,
                    enabled: false
                );
            }
        } else {
            $weather = $item->get();
        }

        return $weather;
    }

    private function iconFromCode(string $code): array
    {
        return match (substr($code, 0, 2)) {
            '01' => ['emoji' => '☀️', 'icon' => 'wi wi-day-sunny'],
            '02' => ['emoji' => '🌤️', 'icon' => 'wi wi-day-sunny-overcast'],
            '03', '04' => ['emoji' => '☁️', 'icon' => 'wi wi-cloudy'],
            '09', '10' => ['emoji' => '🌧️', 'icon' => 'wi wi-rain'],
            '11' => ['emoji' => '⛈️', 'icon' => 'wi wi-thunderstorm'],
            '13' => ['emoji' => '❄️', 'icon' => 'wi wi-snow'],
            '50' => ['emoji' => '🌫️', 'icon' => 'wi wi-fog'],
            default => $this->logUnknownSymbol($code),
        };
    }

    private function logUnknownSymbol(string $code): array
    {
        $this->logger->warning("Unrecognized symbol code for OpenWeather : $code");

        return ['emoji' => '🌡️', 'icon' => 'wi wi-na'];
    }

    public function getForecast(LocationCoordinatesInterface $locationCoordinates): array
    {
        $cacheKey = 'openweather.forecast'.sprintf('%.6f_%.6f', $locationCoordinates->getLatitude(), $locationCoordinates->getLongitude());
        $item = $this->cache->getItem($cacheKey);

        if (!$item->isHit() || !$this->meteo_cache) {
            $data = $this->getForecastApiInformations($locationCoordinates);
            $grouped = [];
            $this->hourlyToday = $data['list'];
            foreach ($data['list'] as $entry) {
                $dt = new \DateTimeImmutable($entry['dt_txt']);
                $dayKey = $dt->format('Y-m-d');
                $grouped[$dayKey][] = $entry;
            }

            $forecasts = [];

            foreach (array_slice($grouped, 0, 7, true) as $day => $entries) {
                $temps = array_map(fn ($e) => $e['main']['temp'], $entries);
                $descFreq = array_count_values(array_map(fn ($e) => $e['weather'][0]['description'], $entries));
                arsort($descFreq);
                $mainDesc = array_key_first($descFreq);

                $iconCode = $entries[0]['weather'][0]['icon'];
                $icon = $this->iconFromCode($iconCode);

                $forecasts[] = new ForecastData(
                    provider: 'OpenWeather',
                    date: new \DateTimeImmutable($day),
                    tmin: min($temps),
                    tmax: max($temps),
                    icon: $icon['icon'],
                    emoji: $icon['emoji']
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
                    'lat' => $locationCoordinates->getLatitude(),
                    'lon' => $locationCoordinates->getLongitude(),
                    'units' => 'metric',
                    'lang' => 'fr',
                    'appid' => $this->apiKey,
                ],
            ]);

            return $response->toArray();
        } catch (
            TransportExceptionInterface|
            ClientExceptionInterface|
            ServerExceptionInterface|
            RedirectionExceptionInterface $e
        ) {
            $this->logger->error('Erreur API Prévisions API Met.no : '.$e->getMessage());

            return [];
        }
    }

    public function getTodayHourly(LocationCoordinatesInterface $locationCoordinates): array
    {
        if (empty($this->hourlyToday)) {
            $data = $this->getForecastApiInformations($locationCoordinates);
            $this->hourlyToday = $data['list'];
        }

        $today = (new \DateTimeImmutable())->setTimezone(new \DateTimeZone('Europe/Paris'));
        $tomorrow = (new \DateTimeImmutable('+1 day'))->setTimezone(new \DateTimeZone('Europe/Paris'));
        $result = [];
        foreach ($this->hourlyToday as $entry) {
            $dt = new \DateTimeImmutable($entry['dt_txt']);
            if ($dt >= $today && $dt < $tomorrow) {
                try {
                    $result[] = new HourlyForecastData(
                        provider: 'OpenWeather',
                        time: $dt,
                        temperature: $entry['main']['temp'],
                        description: $entry['weather'][0]['description'],
                        emoji: $this->iconFromCode($entry['weather'][0]['icon'])['emoji']
                    );
                } catch (\InvalidArgumentException $e) {
                    $this->logger->error('erreur :'.$e->getMessage());
                }
            }
        }

        return $result;
    }
}
