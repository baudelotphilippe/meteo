<?php

declare(strict_types=1);

namespace App\Service\Geocode;

use App\Dto\LocationCoordinates;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class GeocodeService
{
    public function __construct(private readonly HttpClientInterface $httpclient)
    {
    }

    public function get(string $location): LocationCoordinates
    {
        $response = $this->httpclient->request('GET', 'https://nominatim.openstreetmap.org/search', [
            'query' => [
                'format' => 'json',
                'q' => $location,
            ],
        ])->toArray();

        if (count($response) == 0) {
            throw new \InvalidArgumentException('Location not found : '.$location);
        }

        $name = $response[0]['name'] ?: $location;
        $locationCoordinates = new LocationCoordinates($name, (float) $response[0]['lat'], (float) $response[0]['lon'], 'Europe/Paris');

        return $locationCoordinates;
    }
}
