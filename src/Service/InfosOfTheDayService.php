<?php

declare(strict_types=1);

namespace App\Service;

use App\Config\LocationCoordinatesInterface;

class InfosOfTheDayService
{
    private readonly \DateTimeZone $tz;
    private readonly \DateTimeImmutable $today;

    public function __construct()
    {
        $this->tz = new \DateTimeZone('Europe/Paris');
        $this->today = new \DateTimeImmutable('today', $this->tz);
    }

    public function getInfosOfTheDay(LocationCoordinatesInterface $locationCoordinates): array
    {
        $formatter = new \IntlDateFormatter(
            'fr_FR',
            \IntlDateFormatter::FULL,
            \IntlDateFormatter::NONE,
            $this->tz,
            \IntlDateFormatter::GREGORIAN,
            'EEEE d MMMM y'
        );

        return ['date' => $formatter->format($this->today), 'ephemeride' => $this->ephemeride($locationCoordinates)];
    }

    private function ephemeride(LocationCoordinatesInterface $locationCoordinates)
    {
        $sun = date_sun_info($this->today->getTimestamp(), $locationCoordinates->getLatitude(), $locationCoordinates->getLongitude());

        return [
            'sunrise' => (new \DateTime("@{$sun['sunrise']}"))->setTimezone($this->tz)->format('G\hi'),
            'sunset' => (new \DateTime("@{$sun['sunset']}"))->setTimezone($this->tz)->format('G\hi'),
        ];
    }
}
