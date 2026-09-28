<?php
declare(strict_types=1);
/** BCP Module 3: deterministic, read-only audit of saved DEMO meeting rows. */

function ccMinutes(string $time): int {
    $parts = explode(':', $time);
    return ((int)($parts[0] ?? 0)) * 60 + (int)($parts[1] ?? 0);
}
function ccOverlap(array $a, array $b): bool {
    return $a['day_of_week'] === $b['day_of_week']
        && ccMinutes((string)$a['start_time']) < ccMinutes((string)$b['end_time'])
        && ccMinutes((string)$b['start_time']) < ccMinutes((string)$a['end_time']);
}
function ccLabel(array $m): string {
    return (string)($m['program_code'] ?? '?') . ' / ' . (string)($m['section_code'] ?? '?')
        . ' / ' . (string)($m['subject_code'] ?? '?') . ' / ' . (string)($m['delivery_mode'] ?? '?')
        . ' / ' . (string)($m['day_of_week'] ?? '?') . ' '
        . substr((string)($m['start_time'] ?? '?'),0,5) . '–' . substr((string)($m['end_time'] ?? '?'),0,5);
}
/**
 * $meetings: rows from ACTIVE batches for ONE academic period.
 * $expected: rows with section_subject_id and batch_id for required section-subjects.
 * $memberships: rows with student_id, home_section_id, major_section_id.
 * $batchCounts: program/batch rows including empty ACTIVE batches.
 * Returns an audit summary, not approval for saving or a Python independent rerun.
 */
function ccAudit(array $meetings, array $expected, array $memberships, array $batchCounts, bool $studentTableAvailable): array {
    $issues = [];
    $countsByType = [];
    $add = static function(string $type, string $message, array $ids = [], array $details = []) use (&$issues, &$countsByType): void {
        $countsByType[$type] = ($countsByType[$type] ?? 0) + 1;
        $issues[] = [
            'type' => $type, 'message' => $message,
            'meeting_ids' => array_values(array_map('intval', $ids)),
            'details' => $details,
        ];
    };
    $activePrograms = [];
    foreach ($batchCounts as $b) {
        $programId = (int)$b['program_id'];
        if (isset($activePrograms[$programId])) {
            $add('MULTIPLE_ACTIVE_BATCHES', 'Program has multiple ACTIVE DEMO batches in this period.', [],
                ['program_code'=>$b['program_code']]);
        }
        $activePrograms[$programId] = true;
        if ((int)$b['meeting_count'] === 0) {
            $add('EMPTY_ACTIVE_BATCH', 'An ACTIVE batch has no saved class meetings.', [], ['batch_id'=>(int)$b['batch_id']]);
        }
    }
    $actual = [];
    $activeBatchIds = array_fill_keys(array_map(static fn($b)=>(int)$b['batch_id'], $batchCounts), true);
    foreach ($meetings as $m) {
        $id = (int)$m['meeting_id'];
        $sectionSubjectId = (int)($m['section_subject_id'] ?? 0);
        $mode = (string)($m['delivery_mode'] ?? '');
        $batchId = (int)($m['batch_id'] ?? 0);
        $key = $batchId . ':' . $sectionSubjectId;
        $actual[$key][$mode][] = $m;
        $context = ['meeting'=>ccLabel($m)];
        if (!isset($activeBatchIds[$batchId])) {
            $add('INVALID_BATCH','Meeting references an unrecognized ACTIVE batch.',[$id],$context);
        }
        if ($sectionSubjectId===0 || (int)($m['section_id']??0)===0 || (int)($m['subject_id']??0)===0
            || (int)($m['teacher_id']??0)===0 || ($m['section_code']??null)===null || ($m['subject_code']??null)===null) {
            $add('BROKEN_REFERENCE','Meeting has a missing section, subject, teacher or section-subject reference.',[$id],$context);
        }
        if ((int)($m['batch_program_id']??0) !== (int)($m['section_program_id']??-1)
            || (int)($m['batch_program_id']??0) !== (int)($m['subject_program_id']??-1)
            || (int)($m['batch_program_id']??0) !== (int)($m['teacher_program_id']??-1)
            || (int)($m['academic_period_id']??0) !== (int)($m['section_period_id']??-1)) {
            $add('OWNERSHIP_MISMATCH','Class, subject, teacher, or section belongs to a different program or period.',[$id],$context);
        }
        $start = ccMinutes((string)($m['start_time']??'00:00'));
        $end = ccMinutes((string)($m['end_time']??'00:00'));
        $day = (string)($m['day_of_week']??'');
        if (!in_array($day,['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'],true)
            || $start < 360 || $end > 1260 || $end <= $start) {
            $add('INVALID_TIME','Meeting is outside the school operating window or has invalid times/day.',[$id],$context);
        }
        if (($mode==='F2F' && $m['room_id']===null)
            || ($mode==='ONLINE' && $m['room_id']!==null)
            || !in_array($mode,['F2F','ONLINE'],true)) {
            $add('ROOM_MODE_MISMATCH','F2F needs a physical room; Online cannot have a room.',[$id],$context);
        }
        if ($mode==='F2F' && $m['room_id']!==null && (int)($m['room_capacity']??0) < (int)($m['section_student_count']??0)) {
            $add('ROOM_CAPACITY','Classroom capacity is below the section student count.',[$id],$context);
        }
        if ($mode==='F2F' && $m['room_id']!==null && $m['room_program_id']!==null
            && (int)$m['room_program_id'] !== (int)$m['batch_program_id']) {
            $add('ROOM_PROGRAM_MISMATCH','Assigned room belongs to another program.',[$id],$context);
        }
    }
    $expectedKeys = [];
    foreach ($expected as $row) {
        $key = (int)$row['batch_id'].':'.(int)$row['section_subject_id'];
        $expectedKeys[$key] = true;
        $set = $actual[$key] ?? [];
        foreach (['F2F','ONLINE'] as $mode) {
            $found = $set[$mode] ?? [];
            if (count($found)!==1) {
                $add('INCOMPLETE_SECTION_SUBJECT','Expected exactly one ' . $mode . ' meeting for section-subject.',
                    array_map(static fn($m)=>(int)$m['meeting_id'],$found),
                    ['section_subject_id'=>(int)$row['section_subject_id'],'batch_id'=>(int)$row['batch_id'], 'mode'=>$mode, 'found'=>count($found)]);
            }
        }
        if (count($set['F2F']??[])===1 && count($set['ONLINE']??[])===1
            && (int)$set['F2F'][0]['teacher_id'] !== (int)$set['ONLINE'][0]['teacher_id']) {
            $add('TEACHER_CONSISTENCY','F2F and Online for one section-subject have different teachers.',
                [(int)$set['F2F'][0]['meeting_id'],(int)$set['ONLINE'][0]['meeting_id']],
                ['section_subject_id'=>(int)$row['section_subject_id']]);
        }
    }
    foreach ($actual as $key=>$set) {
        if (!isset($expectedKeys[$key])) {
            $ids=[];
            foreach($set as $rows) foreach($rows as $m) $ids[]=(int)$m['meeting_id'];
            $add('UNEXPECTED_MEETING','Saved meeting is not required by an active section-subject of this program.', $ids, ['key'=>$key]);
        }
    }
    $sectionsForStudent=[];
    foreach ($memberships as $row) {
        $home=(int)$row['home_section_id'];
        $major=$row['major_section_id']===null ? 0 : (int)$row['major_section_id'];
        if ($major!==0 && $major!==$home) {
            $a=min($home,$major); $b=max($home,$major);
            $sectionsForStudent[$a.':'.$b] = ['home'=>$home,'major'=>$major];
        }
    }
    // Each unordered pair is checked once; equal end/start is not an overlap.
    for ($i=0,$n=count($meetings);$i<$n;$i++) {
        $a=$meetings[$i];
        for($j=$i+1;$j<$n;$j++) {
            $b=$meetings[$j];
            if ($a['day_of_week']!==$b['day_of_week']) continue;
            $aId=(int)$a['meeting_id']; $bId=(int)$b['meeting_id'];
            $pair=[$aId,$bId];
            $ctx=['first'=>ccLabel($a),'second'=>ccLabel($b)];
            $timeConflict=ccOverlap($a,$b);
            if ($timeConflict && (int)$a['teacher_id']!==0 && (int)$a['teacher_id']===(int)$b['teacher_id']) {
                $add('TEACHER_OVERLAP','One professor is assigned to overlapping classes.',$pair,$ctx);
            }
            if ($timeConflict && $a['room_id']!==null && $b['room_id']!==null
                && $a['delivery_mode']==='F2F' && $b['delivery_mode']==='F2F'
                && (int)$a['room_id']===(int)$b['room_id']) {
                $add('ROOM_OVERLAP','The same physical room is occupied by overlapping classes.',$pair,$ctx);
            }
            if ($timeConflict && (int)$a['section_id']!==0 && (int)$a['section_id']===(int)$b['section_id']) {
                $add('SECTION_OVERLAP','A section has overlapping classes.',$pair,$ctx);
            }
            if ($timeConflict && (int)$a['subject_id']!==0 && (int)$a['subject_id']===(int)$b['subject_id']) {
                $add('SUBJECT_OVERLAP','The same subject ID has overlapping classes.',$pair,$ctx);
            }
            $sectionA=(int)$a['section_id']; $sectionB=(int)$b['section_id'];
            if ($sectionA && $sectionB && $sectionA!==$sectionB) {
                $sharedKey=min($sectionA,$sectionB).':'.max($sectionA,$sectionB);
                if (isset($sectionsForStudent[$sharedKey])) {
                    if ($timeConflict) {
                        $add('SHARED_STUDENT_OVERLAP','Cluster and Major sections with shared students have overlapping classes.',$pair,$ctx);
                    }
                    if (($a['delivery_mode']==='F2F' && $b['delivery_mode']==='ONLINE')
                        || ($a['delivery_mode']==='ONLINE' && $b['delivery_mode']==='F2F')) {
                        $add('SHARED_STUDENT_MIXED_DAY','Shared students have Cluster/Major F2F and Online classes on the same day.',$pair,$ctx);
                    }
                }
            }
        }
    }
    $warnings=[];
    if (!$studentTableAvailable) $warnings[]='Student table is not available: shared-student checks were NOT performed.';
    if ($studentTableAvailable && count($memberships)===0) $warnings[]='No DEMO student memberships found for selected period: shared-student checks could not be verified.';
    if (!$batchCounts) $warnings[]='No ACTIVE DEMO timetable exists in this academic period: no saved timetable was audited.';
    return [
        'passed'=>count($issues)===0 && count($warnings)===0,
        'status'=> $issues ? 'CONFLICTS_FOUND' : ($warnings ? 'CHECK_INCOMPLETE' : 'SAVED_SCHEDULE_CHECK_PASSED'),
        'total_issues'=>count($issues), 'issue_counts'=>$countsByType, 'issues'=>$issues,
        'warnings'=>$warnings, 'checked_meetings'=>count($meetings),
        'checked_batches'=>count($batchCounts), 'checked_section_subjects'=>count($expected),
        'checked_student_memberships'=>count($memberships),
        'shared_section_pairs'=>count($sectionsForStudent),
        'independent_python_audit_rerun'=>false, 'database_write'=>false,
    ];
}
