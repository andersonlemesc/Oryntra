<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Parses an agent's weekday business-hours config and derives the open windows
 * (minus a lunch break) used to generate appointment slots, plus a human-readable
 * summary injected into agent prompts.
 *
 * Shape (all optional; missing day = closed):
 *   {
 *     "days": {
 *       "mon": {"enabled": true, "open": "09:00", "close": "19:00",
 *               "break_start": "12:00", "break_end": "13:00"},
 *       ... "sun": {"enabled": false}
 *     }
 *   }
 */
class BusinessHours
{
    private const DAYS = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];

    private const DAY_LABELS = [
        'mon' => 'Segunda',
        'tue' => 'Terça',
        'wed' => 'Quarta',
        'thu' => 'Quinta',
        'fri' => 'Sexta',
        'sat' => 'Sábado',
        'sun' => 'Domingo',
    ];

    /**
     * @param  array<string, array{enabled: bool, open: string, close: string, break_start: string|null, break_end: string|null}> $days
     */
    private function __construct(private readonly array $days) {}

    /**
     * @param array<string, mixed>|null $config
     */
    public static function fromArray(?array $config): self
    {
        $days = [];

        $raw = is_array($config['days'] ?? null) ? $config['days'] : [];

        foreach (self::DAYS as $key) {
            $day = is_array($raw[$key] ?? null) ? $raw[$key] : [];

            $days[$key] = [
                'enabled' => (bool) ($day['enabled'] ?? false),
                'open' => is_string($day['open'] ?? null) ? $day['open'] : '09:00',
                'close' => is_string($day['close'] ?? null) ? $day['close'] : '18:00',
                'break_start' => is_string($day['break_start'] ?? null) ? $day['break_start'] : null,
                'break_end' => is_string($day['break_end'] ?? null) ? $day['break_end'] : null,
            ];
        }

        return new self($days);
    }

    public function isConfigured(): bool
    {
        foreach ($this->days as $day) {
            if ($day['enabled'] === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * Open windows (minus the lunch break) for every day in [$rangeStart, $rangeEnd],
     * clipped to the range. Each window is a [start, end] pair of CarbonImmutable in
     * the supplied timezone.
     *
     * @return array<int, array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    public function openIntervals(CarbonImmutable $rangeStart, CarbonImmutable $rangeEnd, string $timezone): array
    {
        $intervals = [];
        $cursorDay = $rangeStart->setTimezone($timezone)->startOfDay();
        $lastDay = $rangeEnd->setTimezone($timezone)->startOfDay();

        while ($cursorDay->lessThanOrEqualTo($lastDay)) {
            $key = self::DAYS[$cursorDay->dayOfWeekIso - 1];
            $day = $this->days[$key];

            if ($day['enabled'] === true) {
                foreach ($this->dayWindows($cursorDay, $day, $timezone) as $window) {
                    [$start, $end] = $window;
                    $clippedStart = $start->max($rangeStart);
                    $clippedEnd = $end->min($rangeEnd);

                    if ($clippedStart->lessThan($clippedEnd)) {
                        $intervals[] = [$clippedStart, $clippedEnd];
                    }
                }
            }

            $cursorDay = $cursorDay->addDay();
        }

        return $intervals;
    }

    /**
     * @param  array{enabled: bool, open: string, close: string, break_start: string|null, break_end: string|null} $day
     * @return array<int, array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function dayWindows(CarbonImmutable $date, array $day, string $timezone): array
    {
        $open = $this->at($date, (string) $day['open'], $timezone);
        $close = $this->at($date, (string) $day['close'], $timezone);

        // Overnight: a close at/before the open time means the next day
        // (e.g. open 18:00, close 00:00 → midnight; close 02:00 → 2am next day).
        if ($close->lessThanOrEqualTo($open)) {
            $close = $close->addDay();
        }

        $breakStart = $day['break_start'] !== null ? $this->at($date, $day['break_start'], $timezone) : null;
        $breakEnd = $day['break_end'] !== null ? $this->at($date, $day['break_end'], $timezone) : null;

        if ($breakStart !== null && $breakEnd !== null
            && $breakStart->greaterThan($open) && $breakEnd->lessThan($close)
            && $breakEnd->greaterThan($breakStart)) {
            return [[$open, $breakStart], [$breakEnd, $close]];
        }

        return [[$open, $close]];
    }

    private function at(CarbonImmutable $date, string $time, string $timezone): CarbonImmutable
    {
        [$hour, $minute] = array_pad(array_map('intval', explode(':', $time)), 2, 0);

        return $date->setTimezone($timezone)->setTime($hour, $minute);
    }

    /**
     * Human-readable summary for prompt injection, e.g.
     * "Segunda a sexta: 09:00–19:00 (almoço 12:00–13:00); Sábado: 09:00–14:00; Domingo: fechado".
     */
    public function toHuman(): string
    {
        $lines = [];

        foreach (self::DAYS as $key) {
            $day = $this->days[$key];
            $label = self::DAY_LABELS[$key];

            if ($day['enabled'] !== true) {
                $lines[] = "{$label}: fechado";

                continue;
            }

            $text = "{$label}: {$day['open']}–{$day['close']}";

            if ($day['break_start'] !== null && $day['break_end'] !== null) {
                $text .= " (almoço {$day['break_start']}–{$day['break_end']})";
            }

            $lines[] = $text;
        }

        return implode('; ', $lines);
    }

    /**
     * Build appointment slots of $durationMinutes within the open windows, skipping
     * any slot that overlaps a busy window. Slots step by the duration.
     *
     * @param  array<int, array{start: \DateTimeInterface|string, end: \DateTimeInterface|string}> $busy
     * @return array<int, array{start: string, end: string}>
     */
    public function slots(CarbonImmutable $rangeStart, CarbonImmutable $rangeEnd, int $durationMinutes, array $busy, string $timezone, int $limit = 20): array
    {
        $slots = [];

        foreach ($this->openIntervals($rangeStart, $rangeEnd, $timezone) as [$windowStart, $windowEnd]) {
            $cursor = $windowStart;

            while ($cursor->copy()->addMinutes($durationMinutes)->lessThanOrEqualTo($windowEnd)) {
                $slotEnd = $cursor->addMinutes($durationMinutes);

                if (! $this->overlapsBusy($cursor, $slotEnd, $busy)) {
                    $slots[] = ['start' => $cursor->toIso8601String(), 'end' => $slotEnd->toIso8601String()];

                    if (count($slots) >= $limit) {
                        return $slots;
                    }
                }

                $cursor = $slotEnd;
            }
        }

        return $slots;
    }

    /**
     * @param array<int, array{start: \DateTimeInterface|string, end: \DateTimeInterface|string}> $busy
     */
    private function overlapsBusy(CarbonImmutable $start, CarbonImmutable $end, array $busy): bool
    {
        foreach ($busy as $window) {
            $busyStart = CarbonImmutable::parse($window['start']);
            $busyEnd = CarbonImmutable::parse($window['end']);

            if ($start->lessThan($busyEnd) && $end->greaterThan($busyStart)) {
                return true;
            }
        }

        return false;
    }
}
