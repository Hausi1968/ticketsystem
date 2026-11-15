<?php

namespace App\Sla;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Rechnet mit Geschäftszeiten: je Wochentag ein Zeitfenster (z. B. 08:00–17:00), ohne Feiertage.
 * Ohne Wochenplan (rund um die Uhr) zählt jede Minute.
 */
class BusinessHours
{
    /** Höchstens so viele Tage wird gesucht (Schutz vor Endlosschleifen bei leerem Wochenplan) */
    private const MAX_DAYS = 3660;

    /**
     * @param  array<int, array{0: string, 1: string}>|null  $week  ISO-Wochentag (1 = Montag) => [von, bis]; null = rund um die Uhr
     * @param  callable(int): array<string, true>  $holidays  Jahr => ['Y-m-d' => true]
     */
    public function __construct(
        private readonly string $timezone,
        private readonly ?array $week,
        private $holidays = null,
    ) {}

    public static function allDay(string $timezone = 'UTC'): self
    {
        return new self($timezone, null);
    }

    public function isAllDay(): bool
    {
        return $this->week === null;
    }

    /** Zeitpunkt nach $minutes Minuten Geschäftszeit ab $from */
    public function add(CarbonInterface $from, int $minutes): CarbonImmutable
    {
        $from = CarbonImmutable::instance($from)->utc();

        if ($minutes <= 0) {
            return $this->week === null ? $from : $this->nextOpen($from);
        }

        if ($this->week === null) {
            return $from->addMinutes($minutes);
        }

        $left = $minutes * 60;
        foreach ($this->windows($from) as [$start, $end]) {
            $start = $start->max($from);
            $available = $end->getTimestamp() - $start->getTimestamp();

            if ($available >= $left) {
                return $start->addSeconds($left);
            }

            $left -= max(0, $available);
        }

        return $from->addMinutes($minutes); // kein Geschäftstag gefunden: wie rund um die Uhr
    }

    /** Minuten Geschäftszeit zwischen zwei Zeitpunkten (0, wenn $to vor $from liegt) */
    public function minutesBetween(CarbonInterface $from, CarbonInterface $to): int
    {
        $from = CarbonImmutable::instance($from)->utc();
        $to = CarbonImmutable::instance($to)->utc();

        if ($to <= $from) {
            return 0;
        }

        if ($this->week === null) {
            return intdiv($to->getTimestamp() - $from->getTimestamp(), 60);
        }

        $seconds = 0;
        foreach ($this->windows($from) as [$start, $end]) {
            if ($start >= $to) {
                break;
            }
            $seconds += max(0, $end->min($to)->getTimestamp() - $start->max($from)->getTimestamp());
        }

        return intdiv($seconds, 60);
    }

    /** Beginn der nächsten Geschäftszeit (oder $at selbst, wenn gerade geöffnet) */
    public function nextOpen(CarbonInterface $at): CarbonImmutable
    {
        $at = CarbonImmutable::instance($at)->utc();

        if ($this->week === null) {
            return $at;
        }

        foreach ($this->windows($at) as [$start, $end]) {
            if ($end > $at) {
                return $start->max($at);
            }
        }

        return $at;
    }

    /**
     * Geöffnete Zeitfenster ab dem Tag von $from, in UTC.
     *
     * @return \Generator<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    private function windows(CarbonImmutable $from): \Generator
    {
        $day = $from->setTimezone($this->timezone)->startOfDay();

        for ($i = 0; $i < self::MAX_DAYS; $i++, $day = $day->addDay()) {
            $hours = $this->week[$day->isoWeekday()] ?? null;

            if ($hours === null || $this->isHoliday($day)) {
                continue;
            }

            [$open, $close] = $hours;
            $start = CarbonImmutable::parse($day->format('Y-m-d').' '.$open, $this->timezone)->utc();
            $end = CarbonImmutable::parse($day->format('Y-m-d').' '.$close, $this->timezone)->utc();

            if ($end <= $start || $end <= $from) {
                continue;
            }

            yield [$start, $end];
        }
    }

    private array $holidayCache = [];

    public function isHoliday(CarbonInterface $localDay): bool
    {
        if ($this->holidays === null) {
            return false;
        }

        $year = (int) $localDay->format('Y');
        $this->holidayCache[$year] ??= ($this->holidays)($year);

        return isset($this->holidayCache[$year][$localDay->format('Y-m-d')]);
    }
}
