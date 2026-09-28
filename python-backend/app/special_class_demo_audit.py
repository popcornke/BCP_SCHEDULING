"""ISOLATED DEMO ONLY: independent, reject-only audit for ONE recurring weekly special class.
No database access, schedule modifications, or auto-repair. Input is a fresh PHP DB snapshot.
"""
from collections import defaultdict
from datetime import date


class SpecialClassAuditError(ValueError):
    pass


def clock(value):
    parts = str(value).split(':')
    if len(parts) < 2 or not parts[0].isdigit() or not parts[1].isdigit():
        raise SpecialClassAuditError('Invalid clock time')
    h, m = int(parts[0]), int(parts[1])
    if not (0 <= h <= 24 and 0 <= m < 60 and (h < 24 or m == 0)):
        raise SpecialClassAuditError('Invalid clock time')
    return 60 * h + m


def overlapping(a, b, c, d):
    return a < d and c < b


def row_interval(row):
    return clock(row['start_time']), clock(row['end_time'])


def available(windows, resource_id, field, weekday, start, end):
    selected = [w for w in windows if int(w[field]) == resource_id
                and w['day_of_week'] == weekday]
    good = any(w['availability_status'] == 'AVAILABLE'
               and clock(w['start_time']) <= start < end <= clock(w['end_time'])
               for w in selected)
    blocked = any(w['availability_status'] == 'UNAVAILABLE'
                  and overlapping(start, end, *row_interval(w)) for w in selected)
    return good and not blocked


def covered_by_slots(slots, weekday, start, end):
    """Entire block must be a contiguous union of enabled DATABASE time_slots."""
    intervals = sorted((clock(s['start_time']), clock(s['end_time'])) for s in slots
                       if s['day_of_week'] == weekday and bool(int(s.get('is_active', 1))))
    edge = start
    for left, right in intervals:
        if right <= edge:
            continue
        if left > edge:
            return False
        edge = max(edge, right)
        if edge >= end:
            return True
    return False


def occurrences(data):
    result = data['weekly_occurrences']
    if not isinstance(result, list) or not result or len(result) > 96:
        raise SpecialClassAuditError('Invalid weekly occurrence count')
    parsed = []
    for item in result:
        d = date.fromisoformat(item['date'])
        if d.strftime('%A') != item['day_of_week'] or d.weekday() > 5:
            raise SpecialClassAuditError('Weekly date / weekday mismatch')
        parsed.append((d.isoformat(), item['day_of_week']))
    if len(parsed) != len(set(parsed)) or parsed != sorted(parsed):
        raise SpecialClassAuditError('Duplicate or unsorted weekly dates')
    if any((date.fromisoformat(b[0]) - date.fromisoformat(a[0])).days != 7
           for a, b in zip(parsed, parsed[1:]) if a[1] == b[1] and
           len({x[1] for x in parsed}) == 1):
        raise SpecialClassAuditError('Weekly recurrence is not consecutive')
    return parsed


def audit_special_class(data, meetings):
    """Returns independent audit findings. Does not accept solver status as proof."""
    issues = []
    def issue(code, message, **details):
        issues.append({'code': code, 'message': message, **details})

    try:
        request = data['request']
        policy = data['policy']
        duration = policy.get('test_duration_minutes')
        if not isinstance(duration, int) or duration < 30 or duration > 900 or duration % 30:
            raise SpecialClassAuditError('DEMO test duration is missing or invalid')
        if policy.get('demo_test_mode') is not True or policy.get('policy_source') != 'ISOLATED_DEMO_SIMULATION':
            raise SpecialClassAuditError('Explicit isolated DEMO policy source is required; never use an official approval flag')
        if request.get('recurrence') != 'WEEKLY' or request.get('class_type') not in ('REMEDIAL', 'IRREGULAR', 'OCTOBERIAN'):
            raise SpecialClassAuditError('Unsupported class category or frequency')
        if not request.get('teacher_authorized'):
            raise SpecialClassAuditError('Teacher subject authorization not verified')
        selected_students = [int(x['student_id']) for x in request['participants']]
        if not (1 <= len(selected_students) <= 50) or len(selected_students) != len(set(selected_students)):
            raise SpecialClassAuditError('Invalid or duplicate participating student IDs')
        home_major = {int(x['student_id']): {int(y) for y in
                      [x['home_section_id'], x.get('major_section_id')] if y is not None}
                      for x in request['participants']}
        selected_sections = set().union(*(home_major.values()))
        dates = occurrences(data)
        if not isinstance(meetings, list) or len(meetings) != len(dates):
            issue('INCOMPLETE_WEEKLY_SCHEDULE', 'Expected exactly one meeting per requested date')
            return {'passed': False, 'total_issues': len(issues), 'issues': issues}
        rooms = {int(r['room_id']): r for r in data['rooms']}
        teacher = int(request['teacher_id'])
        program = int(request['program_id'])
        starts, ends, modes, room_ids = set(), set(), set(), set()
        for idx, (target_date, weekday) in enumerate(dates):
            meeting = meetings[idx]
            day = meeting.get('meeting_date')
            if day != target_date:
                issue('DATE_MISMATCH', 'Meeting date differs from the requested weekly date', date=target_date)
                continue
            try:
                s, e = row_interval(meeting)
                room_id = meeting.get('room_id')
                room_id = int(room_id) if room_id is not None else None
                mode = meeting.get('delivery_mode')
                if e - s != duration or s < 360 or e > 1260:
                    issue('INVALID_TIME', 'Duration or operating window is invalid', date=day)
                if int(meeting['teacher_id']) != teacher:
                    issue('TEACHER_CHANGED', 'Professor differs from approved request', date=day)
                if not covered_by_slots(data['time_slots'], weekday, s, e):
                    issue('DATABASE_SLOT_MISSING', 'Meeting is not covered by contiguous database time slots', date=day)
                if not available(data['teacher_availability'], teacher, 'teacher_id', weekday, s, e):
                    issue('TEACHER_UNAVAILABLE', 'Professor availability does not cover the meeting', date=day)
                if mode not in ('F2F', 'ONLINE') or (mode == 'ONLINE' and room_id is not None) or (mode == 'F2F' and room_id is None):
                    issue('ROOM_MODE_MISMATCH', 'F2F needs a room; Online must not have a room', date=day)
                if room_id is not None:
                    room = rooms.get(room_id)
                    if not room or room['status'] != 'AVAILABLE' or (room.get('program_id') is not None
                        and int(room['program_id']) != program) or int(room['capacity']) < len(selected_students):
                        issue('INELIGIBLE_ROOM', 'Room, department, status or capacity is invalid', date=day)
                    if not available(data['room_availability'], room_id, 'room_id', weekday, s, e):
                        issue('ROOM_UNAVAILABLE', 'Room availability does not cover the meeting', date=day)
                starts.add(s); ends.add(e); modes.add(mode); room_ids.add(room_id)
                for regular in data['existing_classes']:
                    if regular['day_of_week'] != weekday or not overlapping(s, e, *row_interval(regular)):
                        continue
                    if int(regular['teacher_id']) == teacher:
                        issue('REGULAR_TEACHER_CONFLICT', 'Professor has a regular class', date=day, meeting_id=regular['meeting_id'])
                    if room_id is not None and regular.get('room_id') is not None and int(regular['room_id']) == room_id:
                        issue('REGULAR_ROOM_CONFLICT', 'Room is occupied by a regular class', date=day, meeting_id=regular['meeting_id'])
                    if int(regular['section_id']) in selected_sections and any(int(regular['section_id']) in membership for membership in home_major.values()):
                        issue('REGULAR_STUDENT_CONFLICT', 'A participating student has a regular class', date=day, meeting_id=regular['meeting_id'])
                for exam in data['existing_exams']:
                    if exam['exam_date'] != day or not overlapping(s, e, *row_interval(exam)):
                        continue
                    if int(exam['proctor_id']) == teacher:
                        issue('EXAM_PROCTOR_CONFLICT', 'Professor is already proctoring an exam', date=day)
                    if room_id is not None and int(exam['room_id']) == room_id:
                        issue('EXAM_ROOM_CONFLICT', 'Room already has an exam', date=day)
                    if int(exam['section_id']) in selected_sections:
                        issue('EXAM_STUDENT_CONFLICT', 'A participating student has an exam', date=day)
                for duty in data['existing_substitutions']:
                    if duty['duty_date'] == day and int(duty['substitute_teacher_id']) == teacher and overlapping(s, e, *row_interval(duty)):
                        issue('SUBSTITUTE_DUTY_CONFLICT', 'Professor has a substitute duty', date=day)
                for special in data['existing_special_classes']:
                    if special['meeting_date'] != day or not overlapping(s, e, *row_interval(special)):
                        continue
                    if int(special['teacher_id']) == teacher:
                        issue('SPECIAL_TEACHER_CONFLICT', 'Professor has another special class', date=day)
                    if room_id is not None and special.get('room_id') is not None and int(special['room_id']) == room_id:
                        issue('SPECIAL_ROOM_CONFLICT', 'Room is reserved for another special class', date=day)
                    if set(int(x) for x in special['participant_student_ids']) & set(selected_students):
                        issue('SPECIAL_STUDENT_CONFLICT', 'A participant has another special class', date=day)
            except (ValueError, TypeError, KeyError, SpecialClassAuditError) as exc:
                issue('INVALID_MEETING_RECORD', 'Malformed meeting: ' + str(exc), date=target_date)
        if len(starts) != 1 or len(ends) != 1 or len(modes) != 1 or len(room_ids) != 1:
            issue('INCONSISTENT_WEEKLY_TIME', 'All weekly occurrences must use the same time, delivery mode and room')
        # Teaching load: active regular classes count weekly, future special duties count on their real dates.
        daily_limit = int(request['max_daily_hours']) * 60
        weekly_limit = int(request['max_weekly_hours']) * 60
        regular_daily = defaultdict(int)
        regular_weekly = 0
        for row in data['existing_classes']:
            if int(row['teacher_id']) == teacher:
                minutes = row_interval(row)[1] - row_interval(row)[0]
                regular_daily[row['day_of_week']] += minutes
                regular_weekly += minutes
        if regular_weekly + duration * len({d[1] for d in dates}) > weekly_limit:
            issue('WEEKLY_LOAD_EXCEEDED', 'Regular + special recurring weekly hours exceed faculty limit')
        for day, weekday in dates:
            other = sum(row_interval(x)[1] - row_interval(x)[0]
                        for x in data['existing_special_classes']
                        if x['meeting_date'] == day and int(x['teacher_id']) == teacher)
            duty = sum(row_interval(x)[1] - row_interval(x)[0]
                       for x in data['existing_substitutions']
                       if x['duty_date'] == day and int(x['substitute_teacher_id']) == teacher)
            if regular_daily[weekday] + other + duty + duration > daily_limit:
                issue('DAILY_LOAD_EXCEEDED', 'Faculty daily load exceeds allowed maximum', date=day)
    except (ValueError, TypeError, KeyError, SpecialClassAuditError) as exc:
        issue('INVALID_INPUT', 'Request or source snapshot is incomplete: ' + str(exc))
    return {'passed': not issues, 'total_issues': len(issues), 'issues': issues,
            'checked_occurrences': len(meetings) if isinstance(meetings, list) else 0,
            'database_write': False}
