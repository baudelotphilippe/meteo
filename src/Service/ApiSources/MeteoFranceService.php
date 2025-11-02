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

class MeteoFranceService implements WeatherProviderInterface, ForecastProviderInterface, HourlyForecastProviderInterface
{
    private string $baseUrl = 'https://webservice.meteofrance.com';
    private string $apiToken = '__Wj7dVSTjV9YGu1guveLyDq0g7S7TfTjaHBTPTpO0kj8__';
    private array $cachedForecastData = [];
    private const API_NAME = 'Météo-France';

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
        $cacheKey = 'meteofrance.current'.sprintf('%.6f_%.6f', $locationCoordinates->getLatitude(), $locationCoordinates->getLongitude());
        $item = $this->cache->getItem($cacheKey);

        if (!$item->isHit() || !$this->meteo_cache) {
            try {
                $forecastData = $this->getForecastApiData($locationCoordinates);

                if (empty($forecastData) || !isset($forecastData['forecast'])) {
                    throw new \RuntimeException('Données Météo-France non disponibles');
                }

                // Récupérer la température actuelle depuis les prévisions horaires
                $currentHourly = $forecastData['forecast'][0];
                $currentTemp = $currentHourly['T']['value'] ?? 0;
                $weatherIcon = $currentHourly['weather']['icon'] ?? 'p1j';
                $weatherDesc = $currentHourly['weather']['desc'] ?? 'Inconnu';

                $displayMeteo = $this->getWeatherMeta($weatherIcon);

                $weather = new WeatherData(
                    provider: 'Météo-France',
                    temperature: (float) $currentTemp,
                    description: $weatherDesc,
                    humidity: $currentHourly['humidity'] ?? null,
                    wind: $currentHourly['wind']['speed'] ?? 0,
                    sourceName: 'Météo-France',
                    logoUrl: 'https://meteofrance.com/themes/custom/meteofrance/favicon.ico',
                    sourceUrl: 'https://meteofrance.com/',
                    icon: $displayMeteo['icon']
                );
                $item->set($weather);
                $item->expiresAfter(600); // 10 minutes
                $this->cache->save($item);

                $this->meteoLogger->info('Interrogation '.self::API_NAME, [
                    'location' => $locationCoordinates->getName(),
                    'data' => 'success',
                ]);
            } catch (\Exception $e) {
                $this->logger->error('Erreur API Météo-France : '.$e->getMessage());
                $this->meteoLogger->error('Interrogation '.self::API_NAME, [
                    'location' => $locationCoordinates->getName(),
                    'error' => $e->getMessage(),
                ]);

                $weather = new WeatherData(
                    provider: 'Météo-France',
                    temperature: 0,
                    description: $e->getMessage(),
                    humidity: null,
                    wind: 0,
                    sourceName: 'Météo-France',
                    logoUrl: 'https://meteofrance.com/themes/custom/meteofrance/favicon.ico',
                    sourceUrl: 'https://meteofrance.com/',
                    icon: null,
                    enabled: false
                );
            }
        } else {
            $weather = $item->get();
        }

        return $weather;
    }

    private function getWeatherMeta(string $code): array
    {
        // Codes météo Météo-France
        return match ($code) {
            '1', 'p1j', 'p1n' => ['label' => 'Ensoleillé', 'emoji' => '☀️', 'icon' => 'wi wi-day-sunny'],
            'p1bisj', 'p1bisn' => ['label' => 'Ensoleillé voilé', 'emoji' => '🌤️', 'icon' => 'wi wi-day-sunny-overcast'],

            '2', 'p2j', 'p2n' => ['label' => 'Éclaircies', 'emoji' => '🌤️', 'icon' => 'wi wi-day-sunny-overcast'],
            'p2bisj', 'p2bisn' => ['label' => 'Éclaircies voilées', 'emoji' => '🌤️', 'icon' => 'wi wi-day-sunny-overcast'],

            '3', 'p3j', 'p3n' => ['label' => 'Nuageux', 'emoji' => '☁️', 'icon' => 'wi wi-cloudy'],
            'p3bisj', 'p3bisn' => ['label' => 'Nuageux partiel', 'emoji' => '⛅', 'icon' => 'wi wi-day-cloudy'],

            '4', 'p4j', 'p4n' => ['label' => 'Couvert', 'emoji' => '☁️', 'icon' => 'wi wi-cloudy'],
            'p4bisj', 'p4bisn' => ['label' => 'Couvert léger', 'emoji' => '☁️', 'icon' => 'wi wi-cloudy'],

            '5', 'p5j', 'p5n' => ['label' => 'Brouillard', 'emoji' => '🌫️', 'icon' => 'wi wi-fog'],
            'p5bisj', 'p5bisn' => ['label' => 'Brouillard léger', 'emoji' => '🌫️', 'icon' => 'wi wi-fog'],

            '6', 'p6j', 'p6n' => ['label' => 'Bruine', 'emoji' => '🌧️', 'icon' => 'wi wi-sprinkle'],
            'p6bisj', 'p6bisn' => ['label' => 'Bruine faible', 'emoji' => '🌦️', 'icon' => 'wi wi-sprinkle'],

            '7', 'p7j', 'p7n' => ['label' => 'Pluie', 'emoji' => '🌧️', 'icon' => 'wi wi-rain'],
            'p7bisj', 'p7bisn' => ['label' => 'Pluie modérée', 'emoji' => '🌧️', 'icon' => 'wi wi-rain'],

            '8', 'p8j', 'p8n' => ['label' => 'Averses', 'emoji' => '🌦️', 'icon' => 'wi wi-showers'],
            'p8bisj', 'p8bisn' => ['label' => 'Averses isolées', 'emoji' => '🌧️', 'icon' => 'wi wi-showers'],

            '9', 'p9j', 'p9n' => ['label' => 'Orage', 'emoji' => '⛈️', 'icon' => 'wi wi-thunderstorm'],
            'p9bisj', 'p9bisn' => ['label' => 'Orage localisé', 'emoji' => '⛈️', 'icon' => 'wi wi-thunderstorm'],

            '10', 'p10j', 'p10n' => ['label' => 'Neige', 'emoji' => '❄️', 'icon' => 'wi wi-snow'],
            'p10bisj', 'p10bisn' => ['label' => 'Neige faible', 'emoji' => '🌨️', 'icon' => 'wi wi-snow'],

            '11', 'p11j', 'p11n' => ['label' => 'Grêle', 'emoji' => '🌨️', 'icon' => 'wi wi-hail'],
            'p11bisj', 'p11bisn' => ['label' => 'Grêle locale', 'emoji' => '🌨️', 'icon' => 'wi wi-hail'],

            '12', 'p12j', 'p12n' => ['label' => 'Pluie verglaçante', 'emoji' => '🌧️', 'icon' => 'wi wi-rain-mix'],
            'p12bisj', 'p12bisn' => ['label' => 'Verglas faible', 'emoji' => '🌧️', 'icon' => 'wi wi-rain-mix'],

            '13', 'p13j', 'p13n' => ['label' => 'Neige faible', 'emoji' => '❄️', 'icon' => 'wi wi-snow'],
            'p13bisj', 'p13bisn' => ['label' => 'Neige très faible', 'emoji' => '❄️', 'icon' => 'wi wi-snow'],

            '14', 'p14j', 'p14n' => ['label' => 'Pluie et neige', 'emoji' => '🌨️', 'icon' => 'wi wi-sleet'],
            'p14bisj', 'p14bisn' => ['label' => 'Pluie/neige légère', 'emoji' => '🌨️', 'icon' => 'wi wi-sleet'],

            '15', 'p15j', 'p15n' => ['label' => 'Averses de neige', 'emoji' => '❄️', 'icon' => 'wi wi-snow'],
            'p15bisj', 'p15bisn' => ['label' => 'Averses de neige faibles', 'emoji' => '❄️', 'icon' => 'wi wi-snow'],

            '16', 'p16j', 'p16n' => ['label' => 'Orageux', 'emoji' => '⛈️', 'icon' => 'wi wi-thunderstorm'],
            'p16bisj', 'p16bisn' => ['label' => 'Orage modéré', 'emoji' => '⛈️', 'icon' => 'wi wi-thunderstorm'],

            default => $this->logUnknownWeather($code),
        };
    }

    private function logUnknownWeather(string $code): array
    {
        $this->logger->warning("Code météo Météo-France non reconnu : $code");

        return ['label' => 'Inconnu', 'emoji' => '🌡️', 'icon' => 'wi wi-na'];
    }

    public function getForecast(LocationCoordinatesInterface $locationCoordinates): array
    {
        $cacheKey = 'meteofrance.forecast'.sprintf('%.6f_%.6f', $locationCoordinates->getLatitude(), $locationCoordinates->getLongitude());
        $item = $this->cache->getItem($cacheKey);

        if (!$item->isHit() || !$this->meteo_cache) {
            $data = $this->getForecastApiData($locationCoordinates);

            if (empty($data) || !isset($data['daily_forecast'])) {
                $this->logger->warning('Météo-France API: Pas de données de prévisions disponibles');

                return [];
            }

            $this->cachedForecastData = $data;
            $dailyForecasts = $data['daily_forecast'];

            $forecasts = [];
            foreach (array_slice($dailyForecasts, 0, 7) as $day) {
                $weatherIcon = $day['weather12H']['icon'] ?? 'p1j';
                $displayMeteo = $this->getWeatherMeta($weatherIcon);

                $date = new \DateTimeImmutable('@'.$day['dt']);

                $forecasts[] = new ForecastData(
                    provider: 'Météo-France',
                    date: $date,
                    tmin: $day['T']['min'] ?? 0,
                    tmax: $day['T']['max'] ?? 0,
                    icon: $displayMeteo['icon'],
                    emoji: $displayMeteo['emoji']
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

    private function getForecastApiData(LocationCoordinatesInterface $locationCoordinates): array
    {
        try {
            // Utilisation de l'API non-officielle (mobile app endpoint)
            $lat = $locationCoordinates->getLatitude();
            $lon = $locationCoordinates->getLongitude();

            $response = $this->client->request('GET', "{$this->baseUrl}/forecast", [
                'query' => [
                    'lat' => $lat,
                    'lon' => $lon,
                    'lang' => 'fr',
                    'token' => $this->apiToken,
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
            $this->logger->error('Erreur API Météo-France forecast : '.$e->getMessage());

            return [];
        }
    }

    public function getTodayHourly(LocationCoordinatesInterface $locationCoordinates): array
    {
        if (empty($this->cachedForecastData)) {
            $this->cachedForecastData = $this->getForecastApiData($locationCoordinates);
        }

        if (empty($this->cachedForecastData) || !isset($this->cachedForecastData['forecast'])) {
            $this->logger->warning('Météo-France API: Pas de données horaires disponibles');

            return [];
        }

        $hourlyData = $this->cachedForecastData['forecast'];
        $now = new \DateTimeImmutable('now', new \DateTimeZone('Europe/Paris'));
        $tomorrow = (new \DateTimeImmutable('+1 day', new \DateTimeZone('Europe/Paris')));
        $result = [];

        foreach ($hourlyData as $entry) {
            $dt = (new \DateTimeImmutable('@'.$entry['dt']))->setTimezone(new \DateTimeZone('Europe/Paris'));
            // Ne garder que les heures entre maintenant et demain à la même heure
            if ($dt >= $now && $dt < $tomorrow) {
                $weatherIcon = $entry['weather']['icon'] ?? 'p1j';
                $displayMeteo = $this->getWeatherMeta($weatherIcon);

                try {
                    $result[] = new HourlyForecastData(
                        provider: 'Météo-France',
                        time: $dt,
                        temperature: $entry['T']['value'] ?? 0,
                        description: $entry['weather']['desc'] ?? 'Inconnu',
                        emoji: $displayMeteo['emoji']
                    );
                } catch (\InvalidArgumentException $e) {
                    $this->logger->error('Erreur Météo-France hourly: '.$e->getMessage());
                }
            }
        }

        return $result;
    }
}
