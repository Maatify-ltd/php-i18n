<?php

declare(strict_types=1);

namespace Maatify\I18n\Tests\Support;

use DateTimeImmutable;
use DateTimeZone;
use Maatify\SharedCommon\Contracts\ClockInterface;

final class PreviewTestClock implements ClockInterface
{
    public DateTimeImmutable $instant;

    public function __construct()
    {
        $this->instant = new DateTimeImmutable('2026-11-14T15:00:00.987654+02:00');
    }

    public function now(): DateTimeImmutable
    {
        return $this->instant;
    }

    public function getTimezone(): DateTimeZone
    {
        return $this->instant->getTimezone();
    }
}
