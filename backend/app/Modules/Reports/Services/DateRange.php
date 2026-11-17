<?php

namespace App\Modules\Reports\Services;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * A reporting period in whole local days (Australia/Sydney by default), converted to UTC bounds for queries.
 * ?from=2026-11-01&to=2026-11-30 includes the whole of 30 November local time. Default: the last 30 days.
 */
final class DateRange
{
    public function __construct(public readonly CarbonImmutable $from, public readonly CarbonImmutable $to, public readonly string $timezone) {}

    public static function fromRequest(Request $request): self
    {
        $v = $request->validate(['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $tz = config('marketplace.display_timezone');
        $to = isset($v['to']) ? CarbonImmutable::parse($v['to'], $tz) : CarbonImmutable::now($tz);
        $from = isset($v['from']) ? CarbonImmutable::parse($v['from'], $tz) : $to->subDays(29);
        if ($from->diffInDays($to) > 366) {
            throw ValidationException::withMessages(['from' => ['Choose a period of at most one year.']]);
        }

        return new self($from->startOfDay()->utc(), $to->endOfDay()->utc(), $tz);
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public function bounds(): array
    {
        return [$this->from, $this->to];
    }

    public function localDay(\DateTimeInterface $utc): string
    {
        return CarbonImmutable::instance($utc)->setTimezone($this->timezone)->format('Y-m-d');
    }

    /** @return list<string> every local day in the period */
    public function days(): array
    {
        $days = [];
        for ($d = $this->from->setTimezone($this->timezone)->startOfDay(); $d->lte($this->to->setTimezone($this->timezone)); $d = $d->addDay()) {
            $days[] = $d->format('Y-m-d');
        }

        return $days;
    }

    public function toApi(): array
    {
        return [
            'from' => $this->from->setTimezone($this->timezone)->format('Y-m-d'),
            'to' => $this->to->setTimezone($this->timezone)->format('Y-m-d'),
            'timezone' => $this->timezone,
        ];
    }
}
