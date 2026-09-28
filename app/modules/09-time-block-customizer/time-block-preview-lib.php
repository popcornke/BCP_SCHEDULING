<?php
declare(strict_types=1);
/** Pure Phase 9B helpers. Time slots are global in current DB (no academic_period_id). */
final class Tb9bPreviewException extends RuntimeException {
    public function __construct(public int $httpStatus, public string $statusCode, string $message) {
        parent::__construct($message);
    }
}
function tb9bFail(int $status, string $code, string $message): never {
    throw new Tb9bPreviewException($status, $code, $message);
}
function tb9bClock(mixed $value): int {
    if (!is_string($value) || !preg_match('/^(0[6-9]|1[0-9]|20|21):[0-5][0-9]$/D', $value)) {
        tb9bFail(422, 'INVALID_TIME', 'Use a time between 06:00 and 21:00.');
    }
    [$h, $m] = array_map('intval', explode(':', $value));
    return $h * 60 + $m;
}
function tb9bTime(int $minutes): string {
    return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
}
function tb9bOverlap(int $a0, int $a1, int $b0, int $b1): bool {
    return $a0 < $b1 && $b0 < $a1;
}
function tb9bNormalizeSlots(array $records, string $day): array {
    $expectedPattern = in_array($day, ['Monday', 'Wednesday', 'Friday'], true) ? 'MWF' : 'TTHS';
    $normalized=[];
    foreach ($records as $rec) {
        if (($rec['day_of_week'] ?? '') !== $day || ($rec['day_pattern'] ?? '') !== $expectedPattern) {
            tb9bFail(409, 'SOURCE_SLOT_PATTERN_MISMATCH', 'The saved weekday/day-pattern mapping is inconsistent.');
        }
        $start = substr((string)($rec['start_time'] ?? ''), 0, 5);
        $end = substr((string)($rec['end_time'] ?? ''), 0, 5);
        $a=tb9bClock($start); $b=tb9bClock($end);
        if ($a >= $b || $a < 360 || $b > 1260) {
            tb9bFail(409, 'SOURCE_SLOT_INVALID', 'Existing database slots contain an invalid time interval.');
        }
        $normalized[]=['time_slot_id'=>(int)$rec['time_slot_id'], 'day_of_week'=>$day,
            'day_pattern'=>$expectedPattern,'start_time'=>$start,'end_time'=>$end,
            'start_minute'=>$a,'end_minute'=>$b,'is_active'=>(int)$rec['is_active']===1];
    }
    usort($normalized,static fn($x,$y)=>$x['start_minute']<=>$y['start_minute']);
    for ($i=1; $i<count($normalized); $i++) {
        if ($normalized[$i]['start_minute'] < $normalized[$i-1]['end_minute']) {
            tb9bFail(409,'SOURCE_SLOT_OVERLAP','The source database has overlapping time slots. No reliable preview is possible.');
        }
    }
    return $normalized;
}
function tb9bPlan(array $records, string $day, string $from, string $to, string $action): array {
    if (!in_array($day,['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'],true)) {
        tb9bFail(422,'INVALID_DAY','Choose Monday through Saturday.');
    }
    if (!in_array($action,['DISABLE','ENABLE'],true)) {
        tb9bFail(422,'INVALID_CHANGE','Choose a preview action: DISABLE or ENABLE.');
    }
    $a=tb9bClock($from); $b=tb9bClock($to);
    if ($a >= $b || $b-$a > 240) tb9bFail(422,'INVALID_INTERVAL','Select a positive interval of at most 4 hours.');
    $rows=tb9bNormalizeSlots($records,$day);
    if (!$rows) tb9bFail(409,'NO_SOURCE_SLOTS','No source database slots exist on this weekday.');
    $position=$a; $selectedIds=[]; $changed=[]; $current=[]; $proposed=[];
    foreach ($rows as $row) {
        $start=$row['start_minute']; $end=$row['end_minute'];
        if (tb9bOverlap($start,$end,$a,$b) && ($start<$a || $end>$b)) {
            tb9bFail(422,'SLOT_BOUNDARY_MISMATCH','The chosen interval cuts through an existing database slot.');
        }
        $inside=$start >= $a && $end <= $b;
        if ($inside) {
            if ($start !== $position) tb9bFail(422,'SLOT_COVERAGE_GAP','The requested interval has missing or noncontiguous source slots.');
            $position=$end; $selectedIds[]=$row['time_slot_id'];
        }
        $next=$inside ? $action==='ENABLE' : $row['is_active'];
        if ($inside && $row['is_active']!==$next) $changed[]=$row;
        $current[]=$row;
        $proposed[]=$row; // Replace status in an in-memory copy only.
        $proposed[count($proposed)-1]['is_active']=$next;
        $proposed[count($proposed)-1]['in_proposed_range']=$inside;
        $proposed[count($proposed)-1]['would_change']=$inside && $row['is_active']!==$next;
    }
    if (!$selectedIds || $position!==$b) tb9bFail(422,'SLOT_COVERAGE_GAP','The requested interval is not fully covered by saved source slots.');
    return ['day'=>$day,'day_pattern'=>$rows[0]['day_pattern'],'from'=>$from,'to'=>$to,
        'action'=>$action,'source_slots'=>$current,'proposed_slots'=>$proposed,
        'selected_slot_ids'=>$selectedIds,'changed_slots'=>$changed];
}
function tb9bWindows(array $slots,int $minutes): array {
    $active=array_values(array_filter($slots,static fn($s)=>$s['is_active']===true));
    $windows=[]; $n=count($active);
    for($i=0;$i<$n;$i++) {
        $start=$active[$i]['start_minute']; $end=$start;
        for($j=$i;$j<$n;$j++) {
            if ($active[$j]['start_minute']!==$end) break;
            $end=$active[$j]['end_minute'];
            if ($end-$start===$minutes) { $windows[]=tb9bTime($start).'–'.tb9bTime($end); break; }
            if ($end-$start>$minutes) break;
        }
    }
    return ['duration_minutes'=>$minutes,'count'=>count($windows),'examples'=>array_slice($windows,0,8)];
}
function tb9bImpact(array $meetings,array $changed): array {
    $items=[];
    foreach ($meetings as $m) {
        $start=tb9bClock(substr((string)$m['start_time'],0,5));
        $end=tb9bClock(substr((string)$m['end_time'],0,5));
        foreach ($changed as $slot) {
            if (tb9bOverlap($start,$end,$slot['start_minute'],$slot['end_minute'])) {
                $items[]=$m; break;
            }
        }
    }
    return $items;
}
