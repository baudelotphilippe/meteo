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

class WttrinService implements WeatherProviderInterface, ForecastProviderInterface, HourlyForecastProviderInterface
{
    private string $baseUrl = 'https://wttr.in';
    private array $cachedForecastData = [];
    private const API_NAME = 'wttr.in';

    public function __construct(
        private HttpClientInterface $client,
        private LoggerInterface $logger,
        private CacheItemPoolInterface $cache,
        private LoggerInterface $meteoLogger,
        private bool $meteo_cache,
    ) {
    }

    public function getWeather(LocationCoordinatesInterface $locationCoordinates): WeatherData
    {
        $cacheKey = 'wttrin.current'.sprintf('%.6f_%.6f', $locationCoordinates->getLatitude(), $locationCoordinates->getLongitude());
        $item = $this->cache->getItem($cacheKey);

        if (!$item->isHit() || !$this->meteo_cache) {
            try {
                $forecastData = $this->getForecastApiData($locationCoordinates);

                if (empty($forecastData) || !isset($forecastData['current_condition'][0])) {
                    throw new \RuntimeException('Données wttr.in non disponibles');
                }

                $current = $forecastData['current_condition'][0];

                $weather = new WeatherData(
                    provider: 'wttr.in',
                    temperature: (float) $current['temp_C'],
                    description: $current['weatherDesc'][0]['value'] ?? 'Unknown',
                    humidity: (int) $current['humidity'],
                    wind: (float) $current['windspeedKmph'],
                    sourceName: 'wttr.in',
                    logoUrl: 'https://wttr.in/favicon.ico',
                    sourceUrl: 'https://wttr.in/',
                    icon: $this->getWeatherIcon((int) $current['weatherCode'])
                );
                $item->set($weather);
                $item->expiresAfter(600); // 10 minutes
                $this->cache->save($item);

                $this->meteoLogger->info('Interrogation '.self::API_NAME, [
                    'location' => $locationCoordinates->getName(),
                    'data' => 'success',
                ]);
            } catch (\Exception $e) {
                $this->logger->error('Erreur API wttr.in : '.$e->getMessage());
                $this->meteoLogger->error('Interrogation '.self::API_NAME, [
                    'location' => $locationCoordinates->getName(),
                    'error' => $e->getMessage(),
                ]);

                $weather = new WeatherData(
                    provider: 'wttr.in',
                    temperature: 0,
                    description: $e->getMessage(),
                    humidity: null,
                    wind: 0,
                    sourceName: 'wttr.in',
                    logoUrl: 'https://wttr.in/favicon.ico',
                    sourceUrl: 'https://wttr.in/',
                    icon: null,
                    enabled: false
                );
            }
        } else {
            $weather = $item->get();
        }

        return $weather;
    }

    private function getWeatherIcon(int $code): string
    {
        // Codes météo wttr.in basés sur WWO (World Weather Online)
        return match (true) {
            $code === 113 => 'wi wi-day-sunny',                    // Sunny
            $code === 116 => 'wi wi-day-cloudy',                   // Partly cloudy
            $code === 119 => 'wi wi-cloudy',                       // Cloudy
            $code === 122 => 'wi wi-cloud',                        // Overcast
            $code === 143 || $code === 248 => 'wi wi-fog',        // Mist / Fog
            $code === 176 || $code === 263 => 'wi wi-sprinkle',   // Patchy rain / Drizzle
            in_array($code, [179, 227, 230, 323, 326, 329, 332, 335, 338, 350, 368, 371, 374, 377]) => 'wi wi-snow', // Snow
            in_array($code, [182, 185, 281, 284, 311, 314, 317, 362, 365]) => 'wi wi-sleet', // Sleet
            in_array($code, [266, 293, 296, 299, 302, 305, 308, 353, 356, 359]) => 'wi wi-rain', // Rain
            in_array($code, [200, 386, 389, 392, 395]) => 'wi wi-thunderstorm', // Thunder
            $code === 260 => 'wi wi-fog',                          // Freezing fog
            default => $this->logUnknownWeatherCode($code),
        };
    }

    private function logUnknownWeatherCode(int $code): string
    {
        $this->logger->warning("Code météo wttr.in non reconnu : $code");

        return 'wi wi-na';
    }

    public function getForecast(LocationCoordinatesInterface $locationCoordinates): array
    {
        $cacheKey = 'wttrin.forecast'.sprintf('%.6f_%.6f', $locationCoordinates->getLatitude(), $locationCoordinates->getLongitude());
        $item = $this->cache->getItem($cacheKey);

        if (!$item->isHit() || !$this->meteo_cache) {
            $data = $this->getForecastApiData($locationCoordinates);

            if (empty($data) || !isset($data['weather'])) {
                $this->logger->warning('wttr.in API: Pas de données de prévisions disponibles');

                return [];
            }

            $this->cachedForecastData = $data;
            $dailyForecasts = $data['weather'];

            $forecasts = [];
            foreach (array_slice($dailyForecasts, 0, 7) as $day) {
                $date = new \DateTimeImmutable($day['date']);
                $weatherCode = (int) $day['hourly'][4]['weatherCode']; // Utilise les données de 12h

                $forecasts[] = new ForecastData(
                    provider: 'wttr.in',
                    date: $date,
                    tmin: (float) $day['mintempC'],
                    tmax: (float) $day['maxtempC'],
                    icon: $this->getWeatherIcon($weatherCode),
                    emoji: $this->getWeatherEmoji($weatherCode)
                );
            }

            $item->set(['forecast' => $forecasts, 'fullData' => $data]);
            $item->expiresAfter(1800); // 30 min
            $this->cache->save($item);
        } else {
            $infos = $item->get();
            $forecasts = $infos['forecast'];
            $this->cachedForecastData = $infos['fullData'];
        }

        return $forecasts;
    }

    private function getWeatherEmoji(int $code): string
    {
        return match (true) {
            $code === 113 => '☀️',                                 // Sunny
            $code === 116 => '⛅',                                 // Partly cloudy
            $code === 119, $code === 122 => '☁️',                 // Cloudy/Overcast
            $code === 143 || $code === 248 => '🌫️',              // Mist/Fog
            in_array($code, [176, 263, 266, 281, 284]) => '🌦️',  // Drizzle
            in_array($code, [179, 227, 230, 323, 326, 329, 332, 335, 338, 350, 368, 371, 374, 377]) => '❄️', // Snow
            in_array($code, [182, 185, 311, 314, 317, 362, 365]) => '🌨️', // Sleet
            in_array($code, [293, 296, 299, 302, 305, 308, 353, 356, 359]) => '🌧️', // Rain
            in_array($code, [200, 386, 389, 392, 395]) => '⛈️',   // Thunder
            default => '🌡️',
        };
    }

    private function getForecastApiData(LocationCoordinatesInterface $locationCoordinates): array
    {
        try {
            $lat = $locationCoordinates->getLatitude();
            $lon = $locationCoordinates->getLongitude();

            $response = $this->client->request('GET', "{$this->baseUrl}/{$lat},{$lon}", [
                'query' => [
                    'format' => 'j1',
                    'lang' => 'fr',
                ],
                'headers' => [
                    'User-Agent' => 'MeteoApp/1.0',
                ],
            ]);

            return $response->toArray();
        } catch (
            TransportExceptionInterface|
            ClientExceptionInterface|
            ServerExceptionInterface|
            RedirectionExceptionInterface $e
        ) {
            $this->logger->error('Erreur API wttr.in forecast : '.$e->getMessage());

            return [];
        }
    }

    public function getTodayHourly(LocationCoordinatesInterface $locationCoordinates): array
    {
        if (empty($this->cachedForecastData)) {
            $this->cachedForecastData = $this->getForecastApiData($locationCoordinates);
        }

        if (empty($this->cachedForecastData) || !isset($this->cachedForecastData['weather'])) {
            $this->logger->warning('wttr.in API: Pas de données horaires disponibles');

            return [];
        }

        $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'));
        $tomorrow = (new \DateTimeImmutable('+1 day', new \DateTimeZone('Europe/Paris')));
        $result = [];

        // Récupérer les données horaires d'aujourd'hui et potentiellement de demain
        $weatherDays = array_slice($this->cachedForecastData['weather'], 0, 2);

        foreach ($weatherDays as $dayIndex => $day) {
            if (!isset($day['hourly'])) {
                continue;
            }

            $baseDate = new \DateTimeImmutable($day['date'], new \DateTimeZone('Europe/Paris'));

            foreach ($day['hourly'] as $entry) {
                $hour = (int) ($entry['time'] / 100);
                $dt = $baseDate->setTime($hour, 0);

                // Ne garder que les heures entre maintenant et demain à la même heure
                if ($dt >= $now && $dt < $tomorrow) {
                    $weatherCode = (int) $entry['weatherCode'];

                    try {
                        $result[] = new HourlyForecastData(
                            provider: 'wttr.in',
                            time: $dt,
                            temperature: (float) $entry['tempC'],
                            description: $entry['lang_fr'][0]['value'] ?? $entry['weatherDesc'][0]['value'] ?? 'Unknown',
                            emoji: $this->getWeatherEmoji($weatherCode)
                        );
                    } catch (\InvalidArgumentException $e) {
                        $this->logger->error('Erreur wttr.in hourly: '.$e->getMessage());
                    }
                }
            }
        }

        return $result;
    }
}
