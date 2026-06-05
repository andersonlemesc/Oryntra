<?php

declare(strict_types=1);

use App\Support\BusinessHours;
use Carbon\CarbonImmutable;

function glowHours(): BusinessHours
{
    $weekday = ['enabled' => true, 'open' => '09:00', 'close' => '18:00', 'break_start' => '12:00', 'break_end' => '13:00'];

    return BusinessHours::fromArray([
        'days' => [
            'mon' => $weekday,
            'tue' => $weekday,
            'wed' => $weekday,
            'thu' => $weekday,
            'fri' => $weekday,
            'sat' => ['enabled' => true, 'open' => '09:00', 'close' => '14:00'],
            'sun' => ['enabled' => false],
        ],
    ]);
}

it('generates duration-sized slots within working hours and skips the lunch break', function () {
    // 2026-06-01 is a Monday.
    $start = CarbonImmutable::parse('2026-06-01T00:00:00-03:00');
    $end = CarbonImmutable::parse('2026-06-01T23:59:00-03:00');

    $slots = glowHours()->slots($start, $end, 60, [], 'America/Sao_Paulo', limit: 50);
    $starts = array_map(fn (array $s): string => CarbonImmutable::parse($s['start'])->format('H:i'), $slots);

    // 09,10,11 then lunch gap, 13,14,15,16,17 — no 12:00, no 18:00 start.
    expect($starts)->toBe(['09:00', '10:00', '11:00', '13:00', '14:00', '15:00', '16:00', '17:00']);
});

it('skips slots overlapping a busy window', function () {
    $start = CarbonImmutable::parse('2026-06-01T00:00:00-03:00');
    $end = CarbonImmutable::parse('2026-06-01T23:59:00-03:00');

    $busy = [['start' => CarbonImmutable::parse('2026-06-01T10:00:00-03:00'), 'end' => CarbonImmutable::parse('2026-06-01T10:40:00-03:00')]];

    $slots = glowHours()->slots($start, $end, 60, $busy, 'America/Sao_Paulo', limit: 50);
    $starts = array_map(fn (array $s): string => CarbonImmutable::parse($s['start'])->format('H:i'), $slots);

    // 10:00 slot (10:00-11:00) overlaps busy -> dropped.
    expect($starts)->not->toContain('10:00')
        ->and($starts)->toContain('09:00')
        ->and($starts)->toContain('11:00');
});

it('returns no slots on a closed day', function () {
    // 2026-06-07 is a Sunday (closed).
    $start = CarbonImmutable::parse('2026-06-07T00:00:00-03:00');
    $end = CarbonImmutable::parse('2026-06-07T23:59:00-03:00');

    expect(glowHours()->slots($start, $end, 60, [], 'America/Sao_Paulo'))->toBe([]);
});

it('respects a different service duration', function () {
    $start = CarbonImmutable::parse('2026-06-01T00:00:00-03:00');
    $end = CarbonImmutable::parse('2026-06-01T23:59:00-03:00');

    $slots = glowHours()->slots($start, $end, 90, [], 'America/Sao_Paulo', limit: 50);
    $starts = array_map(fn (array $s): string => CarbonImmutable::parse($s['start'])->format('H:i'), $slots);

    // 90min: morning 09:00-12:00 fits 09:00 and 10:30 (ends 12:00); afternoon 13:00-18:00
    // fits 13:00, 14:30, 16:00 (16:00-17:30); 17:30 would end 19:00 > close.
    expect($starts)->toBe(['09:00', '10:30', '13:00', '14:30', '16:00']);
});

it('handles overnight hours that cross midnight', function () {
    // Monday opens 18:00, closes 00:00 (midnight). Range spans into Tuesday.
    $hours = BusinessHours::fromArray([
        'days' => [
            'mon' => ['enabled' => true, 'open' => '18:00', 'close' => '00:00'],
            'tue' => ['enabled' => false],
        ],
    ]);

    $start = CarbonImmutable::parse('2026-06-01T00:00:00-03:00');
    $end = CarbonImmutable::parse('2026-06-02T12:00:00-03:00');

    $slots = $hours->slots($start, $end, 60, [], 'America/Sao_Paulo', limit: 50);
    $starts = array_map(fn (array $s): string => CarbonImmutable::parse($s['start'])->format('H:i'), $slots);

    // 18:00 → 23:00; the 23:00 slot ends exactly at 00:00. No slot starts at/after midnight.
    expect($starts)->toBe(['18:00', '19:00', '20:00', '21:00', '22:00', '23:00']);
});

it('handles late-night close past midnight', function () {
    // Bar: Friday 20:00 → 02:00 next day.
    $hours = BusinessHours::fromArray([
        'days' => ['fri' => ['enabled' => true, 'open' => '20:00', 'close' => '02:00']],
    ]);

    // 2026-06-05 is a Friday.
    $start = CarbonImmutable::parse('2026-06-05T00:00:00-03:00');
    $end = CarbonImmutable::parse('2026-06-06T12:00:00-03:00');

    $slots = $hours->slots($start, $end, 60, [], 'America/Sao_Paulo', limit: 50);
    $starts = array_map(fn (array $s): string => CarbonImmutable::parse($s['start'])->format('d H:i'), $slots);

    // 20:00..23:00 Friday, then 00:00, 01:00 Saturday (01:00 ends 02:00 = close).
    expect($starts)->toBe(['05 20:00', '05 21:00', '05 22:00', '05 23:00', '06 00:00', '06 01:00']);
});

it('summarizes hours for the prompt', function () {
    expect(glowHours()->toHuman())
        ->toContain('Segunda: 09:00–18:00 (almoço 12:00–13:00)')
        ->toContain('Sábado: 09:00–14:00')
        ->toContain('Domingo: fechado');
});
