<?php

declare(strict_types=1);

namespace App\ValueObject;

use DateTimeImmutable;

final class Time
{

    private \DateTimeImmutable $date;
    private const TIME_FORMAT = "G\h";

    public function __construct(string $date)
    {
        $testDate = \DateTimeImmutable::createFromFormat(self::TIME_FORMAT, $date);
        if (!$testDate) {
            throw new \InvalidArgumentException("Invalid time format. Expected:" . self::TIME_FORMAT);
        }
        $this->date = $testDate;
    }

    public function format(): string
    {
        return $this->date->format(self::TIME_FORMAT);
    }
}
