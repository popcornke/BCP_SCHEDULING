"""Independent read-only checks for Module 4's proposed examination timetable.

No OR-Tools dependency: this module inspects actual solver output, not model flags.
All times are local wall-clock HH:MM and every exam is precisely 60 minutes.
For shared students, membership is taken from actual student rows, not section numbering.
"""
from collections import defaultdict
from datetime import date


def _allowed_patterns(exam_count):
    if exam_count < 1 or exam_count > 9:
        return []
    if exam_count <= 3:
        return [(exam_count, 0, 0), (0, exam_count, 0), (0, 0, exam_count)]
    if exam_count == 4:
        return [(2, 2, 0), (0, 2, 2)]
    if exam_count == 5:
        return [(3, 2, 0), (2, 3, 0), (0, 3, 2), (0, 2, 3)]
    if exam_count == 6:
        return [(3, 3, 0), (0, 3, 3)]
    if exam_count == 7:
        return [(3, 2, 2), (2, 3, 2), (2, 2, 3)]
    if exam_count == 8:
        return [(3, 3, 2), (3, 2, 3), (2, 3, 3)]
    return [(3, 3, 3)]


def _distribution_counts(events, ordered_dates):
    counts = [0, 0, 0]
    index = {day: i for i, day in enumerate(ordered_dates)}
    for event in events:
        if event[0] in index:
            counts[index[event[0]]] += 1
    return tuple(counts)


def _check_distribution(label, events, ordered_dates, issues, fourth_cluster_major=False):
    counts = _distribution_counts(events, ordered_dates)
    if fourth_cluster_major:
        if counts != (0, 3, 1):
            issues.append(f'{label}: expected Day 2 = 3 Cluster exams and Day 3 = 1 Major exam; got {counts}.')
        return
    patterns = _allowed_patterns(len(events))
    if not patterns or counts not in patterns:
        issues.append(f'{label}: invalid exam-day distribution {counts}; compact consecutive-day distribution is required.')


def _clock(value):
    parts = str(value).split(':')
    if len(parts) < 2:
        raise ValueError(f'Invalid exam time: {value!r}')
    h, m = int(parts[0]), int(parts[1])
    if not (0 <= h <= 24 and 0 <= m < 60 and (h != 24 or m == 0)):
        raise ValueError(f'Invalid exam time: {value!r}')
    return 60 * h + m


def audit_zero_exam_gaps(assignments, sections, students, exam_dates, program_code='BSIT'):
    """Return evidence-based, independently computed section/student gap checks.

    'No vacant' means consecutive exam intervals within each student's actual
    combination of sections on every date when that student has >=2 exams.
    Section-level checks are also enforced for separately printed exam sheets.
    """
    issues = []
    by_section = defaultdict(list)
    section_ids = {int(x['section_id']) for x in sections.values()}
    expected_dates = {date.fromisoformat(value).isoformat() for value in exam_dates}
    for x in assignments:
        try:
            sid = int(x['section_id'])
            actual_date = date.fromisoformat(str(x['exam_date'])).isoformat()
            start, end = _clock(x['start_time']), _clock(x['end_time'])
            if sid not in section_ids:
                issues.append(f'Unknown section {sid} in examination output.')
            if actual_date not in expected_dates:
                issues.append(f'Exam assigned outside selected dates: {actual_date}.')
            if end - start != 60:
                issues.append(f'Exam in section {sid} has duration {end-start}, not 60 minutes.')
            by_section[sid].append((actual_date, start, end, str(x.get('subject_code', 'UNKNOWN'))))
        except (ValueError, KeyError, TypeError) as exc:
            issues.append(f'Invalid examination assignment: {exc}')

    def check(label, events):
        dates = defaultdict(list)
        for item in events:
            dates[item[0]].append(item)
        for day, slots in dates.items():
            slots.sort(key=lambda item: (item[1], item[2]))
            for earlier, later in zip(slots, slots[1:]):
                if later[1] < earlier[2]:
                    issues.append(f'{label}: exams overlap on {day} ({earlier[3]} / {later[3]}).')
                elif later[1] > earlier[2]:
                    issues.append(f'{label}: vacant {later[1]-earlier[2]} minutes on {day} '
                                  f'between {earlier[3]} and {later[3]}.')
            if len(slots) > 3:
                issues.append(f'{label}: {len(slots)} exams on {day}; limit is three.')

    for sid in sorted(section_ids):
        sec = sections[sid]
        rows = by_section[sid]
        check(f'Section {sec["section_code"]}', rows)
        is_bsit_special = (
            str(program_code).upper() == 'BSIT'
            and int(sec['year_level']) == 4
            and str(sec['section_type']).upper() in {'CLUSTER', 'MAJOR'}
        )
        if not is_bsit_special:
            _check_distribution(f'Section {sec["section_code"]}', rows,
                                [date.fromisoformat(value).isoformat() for value in exam_dates], issues)

    # Independent BSIT fourth-year policy: verify final assigned exam dates
    # and hours without reading any CP-SAT variables, and without pairing
    # Cluster/Major sections by section number.
    dates_in_order = [date.fromisoformat(value).isoformat() for value in exam_dates]
    cluster_sections_checked = 0
    major_sections_checked = 0
    for sid in sorted(section_ids):
        sec = sections[sid]
        if str(program_code).upper() != 'BSIT' or int(sec['year_level']) != 4:
            continue
        section_type = str(sec['section_type']).upper()
        rows = by_section[sid]
        label = f"Fourth-year {section_type} {sec['section_code']}"
        if section_type == 'CLUSTER':
            cluster_sections_checked += 1
            if len(rows) != 3 or len({x[3] for x in rows}) != 3:
                issues.append(f'{label}: exactly three distinct Cluster exams are required.')
            actual_days = {x[0] for x in rows}
            if actual_days != {dates_in_order[1]}:
                issues.append(f'{label}: all three Cluster exams must be on Day 2 so Day 3 Major follows without a skipped exam day.')
            # The section-level `check()` above independently rejects any
            # overlap or vacant time between Cluster exams.
        elif section_type == 'MAJOR':
            major_sections_checked += 1
            if not rows:
                issues.append(f'{label}: a Major exam is missing.')
            elif any(x[0] != dates_in_order[2] for x in rows):
                issues.append(f'{label}: every Major exam must be on Day 3.')


    unique_groups = set()
    roster_count = defaultdict(set)
    for student in students:
        try:
            home = int(student['home_section_id'])
            ids = {home}
            if student.get('major_section_id') is not None:
                ids.add(int(student['major_section_id']))
            if not ids.issubset(section_ids):
                issues.append('Student references an unknown home or major section.')
                continue
            group = tuple(sorted(ids))
            unique_groups.add(group)
            for sid in group:
                roster_count[sid].add(str(student['student_id']))
        except (ValueError, KeyError, TypeError) as exc:
            issues.append(f'Invalid student section membership: {exc}')

    if not students:
        issues.append('Student section memberships were not loaded.')
    for sid in sorted(section_ids):
        expected = int(sections[sid]['student_count'])
        if len(roster_count[sid]) != expected:
            issues.append(f'Section {sections[sid]["section_code"]} has {len(roster_count[sid])} '
                          f'student memberships, expected {expected}.')

    for group in sorted(unique_groups):
        combined = [row for sid in group for row in by_section[sid]]
        label = 'Student section group ' + '/'.join(sections[sid]['section_code'] for sid in group)
        check(label, combined)
        group_sections = [sections[sid] for sid in group]
        special_fourth = (
            str(program_code).upper() == 'BSIT'
            and all(int(sec['year_level']) == 4 for sec in group_sections)
            and any(str(sec['section_type']).upper() == 'CLUSTER' for sec in group_sections)
            and any(str(sec['section_type']).upper() == 'MAJOR' for sec in group_sections)
        )
        _check_distribution(label, combined, dates_in_order, issues, special_fourth)

    return {
        'passed': not issues,
        'status': 'ZERO_GAP_AUDIT_PASSED' if not issues else 'ZERO_GAP_AUDIT_FAILED',
        'total_issues': len(issues),
        'issues': issues[:30],
        'checked_students': len(students),
        'checked_student_groups': len(unique_groups),
        'checked_sections': len(section_ids),
        'fourth_year_cluster_sections_checked': cluster_sections_checked,
        'fourth_year_major_sections_checked': major_sections_checked,
        'vacant_minutes': 0 if not issues else None,
        'compact_day_distribution_checked': True,
    }
