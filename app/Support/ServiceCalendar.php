<?php
namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Validation\ValidationException;

/** Minutes are consumed only inside configured local working intervals. */
final class ServiceCalendar
{
    public static function validate(array $rules): void
    {
        if (($rules['clock'] ?? 'continuous') !== 'business') return;
        $days=$rules['weekdays'] ?? []; $periods=$rules['periods'] ?? [];
        if (!$days || !$periods) throw ValidationException::withMessages(['escalation_rules'=>'Chọn ngày làm việc và ít nhất một khung giờ.']);
        $end=-1;
        foreach ($periods as $period) {
            $start=self::minute($period['start']); $stop=self::minute($period['end']);
            if ($start >= $stop || $start < $end) throw ValidationException::withMessages(['escalation_rules'=>'Khung giờ phải tăng dần, không chồng nhau hoặc qua đêm.']);
            $end=$stop;
        }
    }

    private static function minute(string $time): int
    {
        [$hour,$minute]=array_map('intval',explode(':',$time)); return $hour*60+$minute;
    }

    private static function intervals(CarbonImmutable $day, array $rules): array
    {
        if (!in_array($day->dayOfWeekIso,$rules['weekdays'],true) || in_array($day->format('Y-m-d'),$rules['holidays'] ?? [],true)) return [];
        return array_map(fn($p)=>[$day->setTimeFromTimeString($p['start']),$day->setTimeFromTimeString($p['end'])],$rules['periods']);
    }

    public static function addSeconds(CarbonInterface $start, int $seconds, array $rules): CarbonImmutable
    {
        $cursor=CarbonImmutable::instance($start);
        if (($rules['clock'] ?? 'continuous') !== 'business') return $cursor->addSeconds($seconds);
        $zone=$cursor->timezoneName; $cursor=$cursor->setTimezone($rules['timezone']);
        if ($seconds<=0) return $cursor->setTimezone($zone);
        for ($day=0;$day<3660;$day++) {
            foreach (self::intervals($cursor->startOfDay(),$rules) as [$from,$until]) {
                $at=$cursor->max($from);
                $available=max(0,$until->getTimestamp()-$at->getTimestamp());
                if ($available >= $seconds) return $at->addSeconds($seconds)->setTimezone($zone);
                $seconds-=$available;
            }
            $cursor=$cursor->addDay()->startOfDay();
        }
        throw ValidationException::withMessages(['escalation_rules'=>'Thời hạn quá dài so với lịch làm việc (giới hạn tính 10 năm).']);
    }

    public static function secondsBetween(CarbonInterface $start, CarbonInterface $end, array $rules): int
    {
        if ($end->lte($start)) return 0;
        if (($rules['clock'] ?? 'continuous') !== 'business') return $end->getTimestamp()-$start->getTimestamp();
        $from=CarbonImmutable::instance($start)->setTimezone($rules['timezone']);
        $to=CarbonImmutable::instance($end)->setTimezone($rules['timezone']); $total=0;
        for ($day=$from->startOfDay(),$i=0;$day->lt($to) && $i<3660;$day=$day->addDay(),$i++) {
            foreach (self::intervals($day,$rules) as [$a,$b]) $total+=max(0,$b->min($to)->getTimestamp()-$a->max($from)->getTimestamp());
        }
        if ($day->lt($to)) throw new \RuntimeException('Khoảng thời gian SLA vượt giới hạn.');
        return $total;
    }
}
