<?php

namespace App\Service;

use App\Dto\WeatherData;
use App\Dto\ForecastData;
use App\Config\CityCoordinates;
use App\Service\WeatherProviderInterface;
use App\Service\ForecastProviderInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

class MetNoService implements WeatherProviderInterface, ForecastProviderInterface
{
    private string $endpoint = 'https://api.met.no/weatherapi/locationforecast/2.0/compact';

    public function __construct(private HttpClientInterface $client) {}

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

            $first = $data['properties']['timeseries'][0]['data']['instant']['details'];

            return new WeatherData(
                provider: 'Met.no',
                temperature: $first['air_temperature'],
                description: null,
                humidity: $first['relative_humidity'] ?? null,
                wind: $first['wind_speed'],
                sourceName: 'MET Norway (Yr.no)',
                logoUrl: 'https://www.met.no/_/asset/no.met.metno:00000196349af260/images/met-logo.svg',
                sourceUrl: 'https://api.met.no/weatherapi/locationforecast/2.0/documentation'
            );
        } catch (TransportExceptionInterface $e) {
            // Optionnel : en cas d’erreur réseau, retourne une valeur par défaut ou null
            return new WeatherData( provider: 'Met.no',
                temperature: 0,
                description: null,
                humidity: null,
                wind: 0,
                sourceName: 'MET Norway (Yr.no)',
                logoUrl: 'https://www.met.no/_/image/9d963a8e-34d3-474e-8b53-70cfd6ddee6a:ff706c6507f82977d3453bd29eb71e4c44b60a0b/logo_met_no.svg',
                sourceUrl: 'https://api.met.no/weatherapi/locationforecast/2.0/documentation');
        }
    }

     public function getForecast(): array
    {
        $response = $this->client->request('GET', 'https://api.met.no/weatherapi/locationforecast/2.0/compact', [
            'query' => [
                'lat' => CityCoordinates::LAT,
                'lon' => CityCoordinates::LON,
            ],
            'headers' => [
                'User-Agent' => 'MonProjetMeteo/1.0 (mon@email.com)'
            ]
        ]);

        $data = $response->toArray();
        $timeseries = $data['properties']['timeseries'];

        $jours = [];

        foreach ($timeseries as $entry) {
            $date = new \DateTimeImmutable($entry['time']);
            $dayKey = $date->format('Y-m-d');

            if (!isset($entry['data']['instant']['details']['air_temperature'])) {
                continue;
            }

            $temp = $entry['data']['instant']['details']['air_temperature'];

            $jours[$dayKey][] = $temp;
        }

        $result = [];
        $i = 0;
        foreach ($jours as $day => $temps) {
            if ($i >= 7) break;

            $result[] = new ForecastData(
                provider: 'Met.no',
                date: new \DateTimeImmutable($day),
                tmin: min($temps),
                tmax: max($temps),
                description:null
            );

            $i++;
        }

        return $result;
    }
}
