<?php
declare(strict_types=1);
/**
 * Phase 6E reusable academic-calendar validation. NO database writes.
 * A completed metadata record is not independent authentication of school approval:
 * the institution's authorized evidence-review process must establish that first.
 */
final class ScCalendarViolation extends RuntimeException {
    public function __construct(public int $httpStatus, public string $statusCode, string $message) {
        parent::__construct($message);
    }
}
function scCalendarRecord(PDO $pdo, int $periodId): ?array {
    $stmt = $pdo->prepare('SELECT academic_period_id,teaching_start_date,teaching_end_date,calendar_status,approval_reference,approved_by,approval_evidence_sha256,approved_at FROM academic_period_calendars WHERE academic_period_id=:p LIMIT 1');
    $stmt->execute(['p'=>$periodId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row === false ? null : $row;
}
function scCalendarDate(mixed $raw): ?DateTimeImmutable {
    if (!is_string($raw) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $raw)) return null;
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $raw, new DateTimeZone('Asia/Manila'));
    return $d && $d->format('Y-m-d') === $raw ? $d : null;
}
function scCalendarIsReady(?array $r): bool {
    if ($r === null || ($r['calendar_status'] ?? '') !== 'APPROVED') return false;
    $first = scCalendarDate($r['teaching_start_date'] ?? null);
    $last  = scCalendarDate($r['teaching_end_date'] ?? null);
    if ($first === null || $last === null || $first > $last) return false;
    if (!is_string($r['approval_reference'] ?? null) || trim($r['approval_reference']) === '') return false;
    if (!is_string($r['approved_by'] ?? null) || trim($r['approved_by']) === '') return false;
    if (!is_string($r['approved_at'] ?? null) || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $r['approved_at'])) return false;
    $hash = $r['approval_evidence_sha256'] ?? null;
    return is_string($hash) && (bool)preg_match('/^[a-f0-9]{64}$/iD', $hash);
}
function scCalendarPublic(?array $r): array {
    $ready = scCalendarIsReady($r);
    return [
        'calendar_status'=>$r['calendar_status'] ?? 'NOT_RECORDED',
        'calendar_ready'=>$ready,
        'teaching_start_date'=>$ready ? $r['teaching_start_date'] : null,
        'teaching_end_date'=>$ready ? $r['teaching_end_date'] : null,
        'approval_record_complete'=>$ready,
        'note'=>'Date boundaries cover the registered teaching window only; closures/holidays require separate school-calendar integration.'
    ];
}
/** Check BOTH selected request range and every occurrence; do not silently trim out-of-range dates. */
function scCalendarAssertRange(?array $r, mixed $startRaw, mixed $endRaw, array $occurrences, bool $prospective=true): void {
    if (!scCalendarIsReady($r)) {
        throw new ScCalendarViolation(409, 'SPECIAL_CLASS_CALENDAR_PENDING', 'The institution-approved teaching-date window is not registered or is incomplete. No schedule was prepared or generated.');
    }
    $first = scCalendarDate($startRaw);
    $last = scCalendarDate($endRaw);
    if (!$first || !$last || $first > $last) {
        throw new ScCalendarViolation(422, 'INVALID_DATE_RANGE', 'Choose valid ordered weekly start and end dates.');
    }
    $min = $r['teaching_start_date']; $max = $r['teaching_end_date'];
    if ($first->format('Y-m-d') < $min || $last->format('Y-m-d') > $max) {
        throw new ScCalendarViolation(422, 'DATES_OUTSIDE_ACADEMIC_PERIOD', "The requested range must be within the approved teaching window {$min} to {$max}. No dates were moved automatically.");
    }
    if ($prospective && $first->format('Y-m-d') < (new DateTimeImmutable('today', new DateTimeZone('Asia/Manila')))->format('Y-m-d')) {
        throw new ScCalendarViolation(422, 'PAST_SPECIAL_CLASS_DATE', 'New special-class requests cannot include past dates. Use a prospective start date.');
    }
    if ($occurrences === []) {
        throw new ScCalendarViolation(422, 'NO_WEEKLY_DATES', 'Choose weekly days with at least one occurrence.');
    }
    foreach ($occurrences as $row) {
        $day = scCalendarDate($row['date'] ?? null);
        if ($day === null || $day < $first || $day > $last || $day->format('Y-m-d') < $min || $day->format('Y-m-d') > $max) {
            throw new ScCalendarViolation(422, 'OCCURRENCE_OUTSIDE_ACADEMIC_PERIOD', 'A proposed weekly occurrence is invalid or lies outside the approved teaching window.');
        }
        if (isset($row['day_of_week']) && $row['day_of_week'] !== $day->format('l')) {
            throw new ScCalendarViolation(422, 'INVALID_WEEKLY_DATE', 'A proposed occurrence has an incorrect weekday.');
        }
    }
}
