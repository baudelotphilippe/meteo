<?php

namespace App\Service;

use App\Dto\WeatherData;
use App\Dto\ForecastData;
use Psr\Log\LoggerInterface;
use App\Config\CityCoordinates;
use App\Service\WeatherProviderInterface;
use App\Service\ForecastProviderInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;

class MetNoService implements WeatherProviderInterface, ForecastProviderInterface, HourlyForecastProviderInterface
{
    private string $endpoint = 'https://api.met.no/weatherapi/locationforecast/2.0/compact';
    private array $timeseries = [];


    public function __construct(private HttpClientInterface $client, private LoggerInterface $logger) {}

    public function getWeather(): WeatherData
    {
        try {
            $response = $this->client->request('GET', $this->endpoint, [
                'query' => [
                    'lat' => CityCoordinates::LAT,
                    'lon' => CityCoordinates::LON,
                ],
                'headers' => [
                    'User-Agent' => 'MonProjetMeteo/1.0 (mon.email@exemple.com)'
                ]
            ]);

            $data = $response->toArray();
            $first = $data['properties']['timeseries'][0];

            $details = $first['data']['instant']['details'];
            $symbolCode = $first['data']['next_1_hours']['summary']['symbol_code'] ?? null;

            return new WeatherData(
                provider: 'Met.no',
                temperature: $details['air_temperature'],
                description: $this->translateSymbol($symbolCode ?? 'inconnu'),
                humidity: $details['relative_humidity'] ?? null,
                wind: $details['wind_speed'] ?? 0,
                sourceName: 'MET Norway (Yr.no)',
                logoUrl: 'https://www.met.no/_/asset/no.met.metno:00000196349af260/images/met-logo.svg',
                sourceUrl: 'https://api.met.no/weatherapi/locationforecast/2.0/documentation',
                icon: $this->iconFromSymbol($symbolCode)
            );
        } catch (TransportExceptionInterface $e) {
            $this->logger->error('Erreur API Weather Met.no : ' . $e->getMessage());
            return new WeatherData(
                provider: 'Met.no',
                temperature: 0,
                description: $e->getMessage(),
                humidity: null,
                wind: 0,
                sourceName: 'MET Norway (Yr.no)',
                logoUrl: 'https://www.met.no/_/image/9d963a8e-34d3-474e-8b53-70cfd6ddee6a:ff706c6507f82977d3453bd29eb71e4c44b60a0b/logo_met_no.svg',
                sourceUrl: 'https://api.met.no/weatherapi/locationforecast/2.0/documentation',
                icon: null
            );
        }
    }

    public function getForecast(): array
    {
        try {
            $response = $this->client->request('GET', $this->endpoint, [
                'query' => [
                    'lat' => CityCoordinates::LAT,
                    'lon' => CityCoordinates::LON,
                ],
                'headers' => [
                    'User-Agent' => 'MonProjetMeteo/1.0 (mon@email.com)'
                ]
            ]);

            $data = $response->toArray();
            $this->timeseries = $data['properties']['timeseries'];

            $jours = [];

            foreach ($this->timeseries as $entry) {
                $date = new \DateTimeImmutable($entry['time']);
                $dayKey = $date->format('Y-m-d');

                if (!isset($entry['data']['instant']['details']['air_temperature'])) {
                    continue;
                }

                $temp = $entry['data']['instant']['details']['air_temperature'];

                $jours[$dayKey][] = $temp;
            }
dump($jours);

            $result = [];
            $i = 0;
            foreach ($jours as $day => $temps) {
                if ($i >= 7) break;

                $result[] = new ForecastData(
                    provider: 'Met.no',
                    date: new \DateTimeImmutable($day),
                    tmin: min($temps),
                    tmax: max($temps),
                    description: null
                );

                $i++;
            }
            return $result;
        } catch (
            TransportExceptionInterface |
            ClientExceptionInterface |
            ServerExceptionInterface |
            RedirectionExceptionInterface $e
        ) {
            $this->logger->error('Erreur API Previsions Met.no : ' . $e->getMessage());
            return [];
        }
    }

    public function getTodayHourly(): array
    {
        $result = [];
        $today = (new \DateTimeImmutable())->format('Y-m-d');
        $heuresSouhaitees = ['06:00', '09:00', '12:00', '15:00', '18:00', '21:00'];

        foreach ($this->timeseries as $entry) {
            $dt = (new \DateTimeImmutable($entry['time']))->setTimezone(new \DateTimeZone('Europe/Paris'));

            if ($dt->format('Y-m-d') !== $today) {
                continue;
            }

            $heure = $dt->format('H:i');
            if (!in_array($heure, $heuresSouhaitees)) {
                continue;
            }

            $details = $entry['data']['instant']['details'] ?? [];
            if (!isset($details['air_temperature'])) {
                continue;
            }

            $temp = $details['air_temperature'];
            $symbolCode = $entry['data']['next_1_hours']['summary']['symbol_code'] ?? null;

            $result[] = new \App\Dto\HourlyForecastData(
                provider: 'Met.no',
                time: $dt->format('H\hi'),
                temperature: $temp,
                description: $this->translateSymbol($symbolCode ?? 'inconnu'),
                icon: $this->iconFromSymbol($symbolCode)
            );
        }

        return $result;
    }
    private function iconFromSymbol(?string $code): string
    {
        if (!$code) return '🌡️';

        return match (true) {
            str_contains($code, 'clearsky') => '☀️',
            str_contains($code, 'cloudy') => '☁️',
            str_contains($code, 'fair') => '🌤️',
            str_contains($code, 'partlycloudy') => '⛅',
            str_contains($code, 'rain') => '🌧️',
            str_contains($code, 'snow') => '❄️',
            str_contains($code, 'fog') => '🌫️',
            str_contains($code, 'thunderstorm') => '⛈️',
            default => '🌡️',
        };
    }

    private function translateSymbol(string $symbol): string
    {
        // $this->logger->info($symbol);
        return match ($symbol) {
            'clearsky_day', 'clearsky_night' => 'Ciel clair',
            'fair_day', 'fair_night' => 'Ensoleillé',
            'partlycloudy_day', 'partlycloudy_night' => 'Partiellement nuageux',
            'cloudy' => 'Nuageux',
            'lightrain', 'lightrain_day', 'lightrain_night' => 'Pluie légère',
            'rain', 'rain_day', 'rain_night', 'rainshowers_day' => 'Pluie',
            'heavyrain' => 'Pluie forte',
            'snow', 'heavysnow' => 'Neige',
            'fog' => 'Brouillard',
            'thunderstorm' => 'Orage',
            default => 'Inconnu',
        };
    }
}
