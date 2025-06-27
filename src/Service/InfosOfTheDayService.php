<?php

declare(strict_types=1);

namespace App\Service;

use App\Config\CityCoordinates;

class InfosOfTheDayService
{
    private readonly \DateTimeZone $tz;
    private readonly \DateTimeImmutable $today;

    public function __construct()
    {
        $this->tz = new \DateTimeZone('Europe/Paris');
        $this->today = new \DateTimeImmutable('today', $this->tz);
    }

    private function ephemeride()
    {
        $sun = date_sun_info($this->today->getTimestamp(), CityCoordinates::LAT, CityCoordinates::LON);

        return [
            'sunrise' => (new \DateTime("@{$sun['sunrise']}"))->setTimezone($this->tz)->format('G\hi'),
            'sunset' => (new \DateTime("@{$sun['sunset']}"))->setTimezone($this->tz)->format('G\hi'),
        ];
    }

    public function getInfosOfTheDay(): array
    {
        $formatter = new \IntlDateFormatter(
            'fr_FR',
            \IntlDateFormatter::FULL,
            \IntlDateFormatter::NONE,
            $this->tz,
            \IntlDateFormatter::GREGORIAN,
            'EEEE d MMMM y'
        );

        return ['date' => $formatter->format($this->today), 'ephemeride' => $this->ephemeride()];
    }
}
