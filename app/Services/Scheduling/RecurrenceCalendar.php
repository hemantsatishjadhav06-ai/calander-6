<?php

declare(strict_types=1);

namespace App\Services\Scheduling;

use App\Models\RecurringPostSeries;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class RecurrenceCalendar
{
    public function instant(RecurringPostSeries $series): CarbonImmutable
    {
        // One stable local-date key: a DST fold never creates a second occurrence.
        // Nonexistent spring-forward times roll forward by the timezone's gap.
        return CarbonImmutable::parse($series->next_date.' '.$series->local_time, $series->timezone)->utc();
    }

    public function nextDate(RecurringPostSeries $series): string
    {
        $date = CarbonImmutable::parse($series->next_date, 'UTC');

        return match ($series->frequency) {
            'daily' => $date->addDays($series->interval)->toDateString(),
            'weekly' => $date->addWeeks($series->interval)->toDateString(),
            'monthly' => $this->month($series, $date),
            default => throw new InvalidArgumentException('Unsupported recurrence frequency.'),
        };
    }

    private function month(RecurringPostSeries $series, CarbonImmutable $date): string
    {
        $month = $date->startOfMonth()->addMonths($series->interval);
        $anchor = CarbonImmutable::parse($series->starts_on, 'UTC')->day;

        return $month->day(min($anchor, $month->daysInMonth))->toDateString();
    }
}
