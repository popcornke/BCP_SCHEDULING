"""BCP Module 4: DEMO exam timetable preview, no database writes.
Inputs come from the PHP database loader, NEVER from the browser directly.
BCP exam-day policy suspends recurring regular classes on the exact selected exam dates.
The weekly class timetable remains saved and resumes on non-exam dates.
Each section receives one fixed same-program proctor for the whole exam period;
that proctor may handle another section later when exam-duty times do not overlap.
"""
from collections import defaultdict
from datetime import date
from ortools.sat.python import cp_model
from app.exam_gap_audit import audit_zero_exam_gaps

DAYS = ('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday')


def minutes(clock):
    parts = str(clock).split(':')
    if len(parts) < 2:
        raise ValueError('Invalid time: ' + str(clock))
    return int(parts[0]) * 60 + int(parts[1])


def covers(windows, day, start, end):
    return any(w['day_of_week'] == day and w['availability_status'] == 'AVAILABLE'
               and minutes(w['start_time']) <= start and end <= minutes(w['end_time'])
               for w in windows) and not any(
        w['day_of_week'] == day and w['availability_status'] == 'UNAVAILABLE'
        and start < minutes(w['end_time']) and minutes(w['start_time']) < end
        for w in windows)


def blocked(existing, key, resource_id, day, start, end):
    return any(int(m[key]) == resource_id and m['day_of_week'] == day
               and start < minutes(m['end_time']) and minutes(m['start_time']) < end
               for m in existing if m.get(key) is not None)


def reject(code, message, **details):
    return {'success': False, 'status': code, 'message': message,
            'database_write': False, **details}


def allowed_day_patterns(exam_count):
    """Compact BCP exam-day distributions, max three exams per day."""
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


def solve_exam(data):
    try:
        program_code = str(data.get('program_code') or '').strip().upper()
        if not program_code or data.get('data_origin') != 'DEMO':
            return reject('PROGRAM_NOT_CONFIGURED', 'A valid DEMO program is required for examination generation.')
        days = [date.fromisoformat(x) for x in data['exam_dates']]
        if len(days) != 3 or len(set(days)) != 3 or days != sorted(days):
            return reject('INVALID_DATES', 'Choose three distinct examination dates in ascending order.')
        if any(x.weekday() >= 6 for x in days):
            return reject('INVALID_DATES', 'Sunday exams are not enabled in this DEMO.')
        hour_start, hour_end = int(data['start_hour']), int(data['end_hour'])
        if (hour_start, hour_end) not in ((6, 21), (18, 21)):
            return reject('INVALID_EXAM_WINDOW', 'Select 06:00–21:00 or 18:00–21:00.')
        slots_per_day = hour_end - hour_start
        sections = {int(r['section_id']): r for r in data['sections']}
        rooms = {int(r['room_id']): r for r in data['rooms']}
        teachers = {int(r['teacher_id']): r for r in data['teachers']}
        exams = data['exams']
        existing = data['existing_classes']
        existing_exams = data.get('existing_exams', [])
        suspend_regular_classes = bool(data.get('regular_classes_suspended_on_exam_dates', False))

        def saved_exam_blocked(key, resource_id, actual_date, start, end):
            return any(
                row.get(key) is not None
                and int(row[key]) == resource_id
                and str(row.get('exam_date')) == actual_date.isoformat()
                and start < minutes(row['end_time'])
                and minutes(row['start_time']) < end
                for row in existing_exams
            )

        def saved_same_subject_blocked(year_level, subject_id, actual_date, start, end):
            return any(
                int(row.get('program_id', -1)) == int(data['program_id'])
                and int(row.get('year_level', -1)) == year_level
                and int(row.get('subject_id', -1)) == subject_id
                and str(row.get('exam_date')) == actual_date.isoformat()
                and start < minutes(row['end_time'])
                and minutes(row['start_time']) < end
                for row in existing_exams
            )
        db_slots = defaultdict(set)
        for row in data['time_slots']:
            db_slots[row['day_of_week']].add((minutes(row['start_time']), minutes(row['end_time'])))
        room_windows = defaultdict(list)
        teacher_windows = defaultdict(list)
        for x in data['room_availability']:
            room_windows[int(x['room_id'])].append(x)
        for x in data['teacher_availability']:
            teacher_windows[int(x['teacher_id'])].append(x)
        if not sections or not rooms or not exams or not teachers:
            return reject('MISSING_RESOURCES', 'Sections, exams, rooms, or proctors are missing.')
        if len(exams) > 500:
            return reject('TOO_MANY_EXAMS', 'Demo preview supports at most 500 section-subject exams.')
        ids = [int(e['section_subject_id']) for e in exams]
        if len(ids) != len(set(ids)):
            return reject('DUPLICATE_EXAMS', 'Duplicate section-subject examination input.')
        # Validate section references. Proctors are NOT tied to subject teachers.
        # Module 4 assigns one fixed same-program proctor per section for the
        # entire selected exam period. A proctor may serve another section later
        # when the actual duty intervals do not overlap.
        eligible_proctor_ids = sorted(
            teacher_id for teacher_id, teacher in teachers.items()
            if int(teacher['program_id']) == int(data['program_id'])
        )
        if not eligible_proctor_ids:
            return reject('NO_PROGRAM_PROCTORS',
                          'No active same-program proctors are available for this examination timetable.')
        for e in exams:
            sec = sections.get(int(e['section_id']))
            if not sec or int(sec['program_id']) != int(data['program_id']):
                return reject('BAD_SECTION', 'An exam references a missing or foreign section.')

        model = cp_model.CpModel()
        # Paper-rotation rule: only the SAME subject within the SAME year level
        # is prevented from running simultaneously. Different subjects and
        # different year levels may run in parallel when other resources allow.
        by_subject_year, by_section, by_room, by_teacher = (defaultdict(list) for _ in range(4))
        section_proctor = {}
        entries = []

        for idx, e in enumerate(exams):
            sid = int(e['section_id'])
            sec = sections[sid]
            count = int(sec['student_count'])
            if sid not in section_proctor:
                section_proctor[sid] = model.NewIntVarFromDomain(
                    cp_model.Domain.FromValues(eligible_proctor_ids),
                    f'proctor_section_{sid}'
                )
            proctor_var = section_proctor[sid]
            eligible = []

            for day_idx, actual_date in enumerate(days):
                weekday = actual_date.strftime('%A')
                for hour in range(hour_start, hour_end):
                    start_min, end_min = 60 * hour, 60 * (hour + 1)
                    if ((start_min, start_min + 30) not in db_slots[weekday]
                            or (start_min + 30, end_min) not in db_slots[weekday]):
                        continue

                    if saved_same_subject_blocked(int(sec['year_level']), int(e['subject_id']), actual_date, start_min, end_min):
                        continue

                    for teacher_id in eligible_proctor_ids:
                        if not covers(teacher_windows[teacher_id], weekday, start_min, end_min):
                            continue
                        if (not suspend_regular_classes and
                                blocked(existing, 'teacher_id', teacher_id, weekday, start_min, end_min)):
                            continue
                        if saved_exam_blocked('proctor_id', teacher_id, actual_date, start_min, end_min):
                            continue

                        for room_id, room in rooms.items():
                            if int(room['capacity']) < count:
                                continue
                            # Department/program protection: Module 4 only receives
                            # active same-program teachers and program/shared rooms. Foreign
                            # program-owned resources are never eligible.
                            if room.get('program_id') is not None and int(room['program_id']) != int(data['program_id']):
                                continue
                            if not covers(room_windows[room_id], weekday, start_min, end_min):
                                continue
                            if (not suspend_regular_classes and
                                    blocked(existing, 'room_id', room_id, weekday, start_min, end_min)):
                                continue
                            if saved_exam_blocked('room_id', room_id, actual_date, start_min, end_min):
                                continue
                            slot = day_idx * slots_per_day + hour - hour_start
                            eligible.append((slot, room_id, teacher_id))

            if not eligible:
                return reject(
                    'NO_ELIGIBLE_EXAM_SLOT',
                    f"No available one-hour exam slot, room, and same-program section proctor "
                    f"for {e['subject_code']} / section {sec['section_code']}. "
                    'Choose other dates, widen the exam window, or review proctor availability.'
                )

            allowed_starts = sorted(set(slot for slot, _, _ in eligible))
            start = model.NewIntVarFromDomain(cp_model.Domain.FromValues(allowed_starts), f'start_{idx}')
            end = model.NewIntVar(1, 3 * slots_per_day, f'end_{idx}')
            model.Add(end == start + 1)

            allowed_rooms = sorted(set(room for _, room, _ in eligible))
            room_var = model.NewIntVarFromDomain(cp_model.Domain.FromValues(allowed_rooms), f'room_{idx}')
            model.AddAllowedAssignments([start, room_var, proctor_var], eligible)

            day_var = model.NewIntVar(0, 2, f'day_{idx}')
            model.AddDivisionEquality(day_var, start, slots_per_day)
            subject_year_key = (int(sec['year_level']), int(e['subject_id']))
            by_subject_year[subject_year_key].append(start)
            by_section[sid].append(start)

            for room_id in allowed_rooms:
                selected = model.NewBoolVar(f'room_{idx}_{room_id}')
                model.Add(room_var == room_id).OnlyEnforceIf(selected)
                model.Add(room_var != room_id).OnlyEnforceIf(selected.Not())
                by_room[room_id].append(
                    model.NewOptionalIntervalVar(start, 1, end, selected,
                                                 f'room_interval_{idx}_{room_id}')
                )

            for teacher_id in sorted(set(teacher for _, _, teacher in eligible)):
                selected = model.NewBoolVar(f'proctor_{idx}_{teacher_id}')
                model.Add(proctor_var == teacher_id).OnlyEnforceIf(selected)
                model.Add(proctor_var != teacher_id).OnlyEnforceIf(selected.Not())
                by_teacher[teacher_id].append(
                    model.NewOptionalIntervalVar(start, 1, end, selected,
                                                 f'proctor_interval_{idx}_{teacher_id}')
                )

            entries.append({
                'exam': e, 'section': sec, 'start': start, 'room': room_var,
                'proctor': proctor_var, 'day': day_var, 'eligible': set(eligible)
            })

        # BCP paper-rotation policy:
        # - the same subject within the same year level must alternate between sections;
        # - different subjects may run at the same time;
        # - the same subject in another year level is not globally blocked;
        # - a section itself can never sit two exams simultaneously.
        for group in (by_subject_year, by_section):
            for slots in group.values():
                if len(slots) > 1:
                    model.AddAllDifferent(slots)

        # Rooms and proctors may be reused later on the same day, but never at
        # overlapping exam-duty times. This is what allows one teacher to finish
        # one section and then proctor another section/year afterward.
        for intervals in by_room.values():
            if len(intervals) > 1:
                model.AddNoOverlap(intervals)
        for intervals in by_teacher.values():
            if len(intervals) > 1:
                model.AddNoOverlap(intervals)

        # ==============================================================
        # BSIT 4TH YEAR SPECIAL POLICY: CLUSTER ON DAY 2; MAJOR ON DAY 3.
        # These restrictions apply to OFFICIAL section types, not section
        # numbers or assumed one-to-one Cluster/Major student pairings.
        # Section-level and actual-student zero-gap rules remain below.
        # ==============================================================
        fourth_year_exams = defaultdict(list)
        if program_code == 'BSIT':
            for row in entries:
                sec = row['section']
                if int(sec['year_level']) == 4:
                    fourth_year_exams[int(row['exam']['section_id'])].append(row)

        cluster_count = 0
        major_count = 0
        for sid, sec in sections.items():
            if program_code != 'BSIT' or int(sec['year_level']) != 4:
                continue
            section_type = str(sec['section_type']).upper()
            rows = fourth_year_exams[sid]
            if section_type == 'CLUSTER':
                # Approved BSIT 1st-semester DEMO: three Cluster exams.
                # Fail rather than silently scheduling incomplete Cluster data.
                if len(rows) != 3 or len({int(r['exam']['subject_id']) for r in rows}) != 3:
                    return reject(
                        'INVALID_FOURTH_YEAR_CLUSTER',
                        f"Fourth-year BSIT Cluster {sec['section_code']} needs exactly "
                        'three distinct examination subjects for this DEMO policy.',
                        section_id=sid, received_exams=len(rows),
                    )
                # BCP no-skipped-day rule: because the linked Major exam is on
                # Day 3, all three Cluster exams are fixed to Day 2. This avoids
                # the tiring Day 1 + Day 3 pattern with an empty Day 2.
                for row in rows:
                    model.Add(row['day'] == 1)  # Day 2, zero-based index.
                # Contiguity of these 3 exams is already a HARD rule in the
                # section-level compact_groups model below.
                cluster_count += 1
            elif section_type == 'MAJOR':
                if not rows:
                    return reject(
                        'MISSING_FOURTH_YEAR_MAJOR',
                        f"Fourth-year BSIT Major {sec['section_code']} has no exam.",
                        section_id=sid,
                    )
                for row in rows:
                    model.Add(row['day'] == 2)  # Day 3, zero-based index.
                major_count += 1
        # ==============================================================

        # Merge the two official section memberships for fourth-year students.
        students = data['students']
        memberships = defaultdict(set)
        for st in students:
            members = {int(st['home_section_id'])}
            if st.get('major_section_id') is not None:
                members.add(int(st['major_section_id']))
            for sec_id in members:
                memberships[sec_id].add(str(st['student_id']))
        if not students:
            return reject('STUDENTS_NOT_LOADED', 'Student memberships are required for shared-section examination checking.')
        # Require actual DEMO roster coverage for each section; protect against missing links.
        for sid, sec in sections.items():
            if len(memberships[sid]) != int(sec['student_count']):
                return reject('INCOMPLETE_STUDENT_MEMBERSHIP',
                    f"Section {sec['section_code']} has {len(memberships[sid])} mapped students but expects {sec['student_count']}.")
        linked_pairs = set()
        for st in students:
            if st.get('major_section_id') is not None:
                linked_pairs.add((int(st['home_section_id']), int(st['major_section_id'])))
        for a, b in linked_pairs:
            if a not in by_section or b not in by_section:
                return reject('INVALID_SHARED_SECTION', 'Shared student section has missing exams.')
            model.AddAllDifferent(by_section[a] + by_section[b])
        # Every individual student, including Cluster + Major, <= 3 exams per day.
        # Aggregate by unique section membership signature instead of 500 copies.
        groups = {tuple(sorted({int(st['home_section_id'])} | (
            {int(st['major_section_id'])} if st.get('major_section_id') is not None else set())))
            for st in students}
        # Section-level contiguity keeps each independently printable Cluster,
        # Major and regular-section exam sheet free of vacant time as well.
        # Actual student groups are checked independently below; no assumption
        # that one Cluster always maps to a specific Major is made.
        compact_groups = sorted(groups | {(sid,) for sid in sections})
        objectives = []
        for group_number, group in enumerate(compact_groups):
            group_entries = [row for row in entries if int(row['exam']['section_id']) in group]
            if not group_entries:
                return reject('STUDENT_EXAMS_MISSING', f'Student group {group} has no assigned exams.')
            if len(group_entries) > 9:
                return reject('TOO_MANY_EXAMS_PER_STUDENT_GROUP',
                              f'Student group {group} has {len(group_entries)} exams; three days at three exams per day supports at most nine.')

            used_flags = []
            count_vars = []
            for d in range(3):
                flags = []
                first_candidates = []
                last_candidates = []
                day_start = d * slots_per_day
                day_end = day_start + slots_per_day
                for i, row in enumerate(group_entries):
                    flag = model.NewBoolVar(f'g{group_number}_d{d}_exam{i}')
                    model.Add(row['day'] == d).OnlyEnforceIf(flag)
                    model.Add(row['day'] != d).OnlyEnforceIf(flag.Not())
                    flags.append(flag)

                    # Absent exams cannot change the first/last active time.
                    first = model.NewIntVar(day_start, day_end,
                                            f'g{group_number}_d{d}_first_{i}')
                    last = model.NewIntVar(day_start, day_end,
                                           f'g{group_number}_d{d}_last_{i}')
                    model.Add(first == row['start']).OnlyEnforceIf(flag)
                    model.Add(first == day_end).OnlyEnforceIf(flag.Not())
                    model.Add(last == row['start'] + 1).OnlyEnforceIf(flag)
                    model.Add(last == day_start).OnlyEnforceIf(flag.Not())
                    first_candidates.append(first)
                    last_candidates.append(last)

                count_var = model.NewIntVar(0, 3, f'g{group_number}_count_d{d}')
                model.Add(count_var == sum(flags))
                count_vars.append(count_var)
                used = model.NewBoolVar(f'g{group_number}_used_d{d}')
                model.Add(count_var >= 1).OnlyEnforceIf(used)
                model.Add(count_var == 0).OnlyEnforceIf(used.Not())
                used_flags.append(used)

                earliest = model.NewIntVar(day_start, day_end,
                                            f'g{group_number}_d{d}_earliest')
                latest = model.NewIntVar(day_start, day_end,
                                          f'g{group_number}_d{d}_latest')
                model.AddMinEquality(earliest, first_candidates)
                model.AddMaxEquality(latest, last_candidates)
                # HARD RULE: every occupied exam hour must be consecutive.
                model.Add(latest - earliest == count_var).OnlyEnforceIf(used)

            # Actual fourth-year Cluster + Major students have the BCP-specific
            # 3+1 pattern: three Cluster exams on Day 2, one Major on Day 3.
            group_sections = [sections[sid] for sid in group]
            special_fourth = (
                program_code == 'BSIT'
                and group in groups
                and all(int(sec['year_level']) == 4 for sec in group_sections)
                and any(str(sec['section_type']).upper() == 'CLUSTER' for sec in group_sections)
                and any(str(sec['section_type']).upper() == 'MAJOR' for sec in group_sections)
            )
            if special_fourth:
                model.AddAllowedAssignments(count_vars, [(0, 3, 1)])
            else:
                patterns = allowed_day_patterns(len(group_entries))
                if not patterns:
                    return reject('INVALID_EXAM_DAY_DISTRIBUTION',
                                  f'No BCP day-distribution policy is defined for {len(group_entries)} exams.')
                model.AddAllowedAssignments(count_vars, patterns)

            # Only real student groups count in the secondary objective.
            if group in groups:
                objectives.extend(used_flags)

        # Hard day distributions already minimize unnecessary attendance days.
        # Secondary preference: use earlier available hours where all hard rules allow.
        day_weight = len(entries) * (3 * slots_per_day - 1) + 1
        model.Minimize(day_weight * sum(objectives) + sum(row['start'] for row in entries))
        solver = cp_model.CpSolver()
        solver.parameters.max_time_in_seconds = 100
        solver.parameters.num_search_workers = 8
        status = solver.Solve(model)
        if status not in (cp_model.OPTIMAL, cp_model.FEASIBLE):
            return reject('NO_FEASIBLE_EXAMS',
                          'No complete zero-vacant-time exam timetable was found. '
                          'Check the selected exam dates, rooms, proctor availability, '
                          'student/section conflicts, and fourth-year rules. No exam was saved.',
                          solver_status=solver.StatusName(status),
                          zero_gap_required=True)
        result = []
        for row in entries:
            slot, room_id = solver.Value(row['start']), solver.Value(row['room'])
            d, h = divmod(slot, slots_per_day)
            hour = hour_start + h
            e, sec = row['exam'], row['section']
            proctor_id = solver.Value(row['proctor'])
            result.append({'section_subject_id': int(e['section_subject_id']),
                'section_id': int(e['section_id']), 'section_code': sec['section_code'],
                'section_type': sec['section_type'], 'year_level': int(sec['year_level']),
                'subject_id': int(e['subject_id']), 'subject_code': e['subject_code'],
                'subject_title': e['subject_title'], 'proctor_id': proctor_id,
                'proctor_name': teachers[proctor_id]['teacher_name'],
                'room_id': room_id, 'room_name': rooms[room_id]['room_name'],
                'exam_day': d + 1, 'exam_date': days[d].isoformat(),
                'start_time': f'{hour:02d}:00', 'end_time': f'{hour+1:02d}:00'})
        result.sort(key=lambda e: (e['year_level'], e['section_code'], e['exam_day'], e['start_time']))
        # Second-pass audit from assigned values (not CP-SAT booleans).
        problems = []
        for kind, resource in (('teacher', lambda x: (x['proctor_id'],)),
                               ('room', lambda x: (x['room_id'],)),
                               ('subject-within-year', lambda x: (x['year_level'], x['subject_id'])),
                               ('section', lambda x: (x['section_id'],))):
            seen = set()
            for x in result:
                key = (resource(x), x['exam_date'], x['start_time'])
                if key in seen:
                    problems.append(f'{kind} overlap on {x["exam_date"]} {x["start_time"]}')
                seen.add(key)
        section_proctor_audit = {}
        for x in result:
            sid = int(x['section_id'])
            pid = int(x['proctor_id'])
            if sid in section_proctor_audit and section_proctor_audit[sid] != pid:
                problems.append(f'Section {x["section_code"]} changed proctor during the exam period.')
            section_proctor_audit[sid] = pid
            if pid not in teachers or int(teachers[pid]['program_id']) != int(data['program_id']):
                problems.append(f'Section {x["section_code"]} has a foreign-program proctor.')

        day_counts = defaultdict(int)
        for st in students:
            membership = {int(st['home_section_id'])}
            if st.get('major_section_id') is not None:
                membership.add(int(st['major_section_id']))
            by_day = defaultdict(int)
            for x in result:
                if x['section_id'] in membership:
                    by_day[x['exam_date']] += 1
            if any(n > 3 for n in by_day.values()):
                problems.append('Student daily exam limit exceeded.')
                break
        # Fourth-year policy is also independently checked against actual
        # output below; the solver flags alone cannot authorize the preview.
        # Independent output audit: checks section-level and ACTUAL per-student
        # home + major membership combinations without consulting CP-SAT flags.
        gap_audit = audit_zero_exam_gaps(result, sections, students,
                                        [d.isoformat() for d in days], program_code=program_code)
        if not gap_audit['passed']:
            problems.extend(gap_audit['issues'])
        if problems:
            return reject('EXAM_AUDIT_FAILED',
                          'Generated exam schedule failed independent result checks.',
                          issues=problems[:30], gap_audit=gap_audit)
        return {'success': True, 'status': 'EXAM_PREVIEW_READY', 'database_write': False,
                'solver_status': solver.StatusName(status), 'solve_seconds': round(solver.WallTime(), 2),
                'required_exams': len(exams), 'returned_exams': len(result), 'issues': [],
                'gap_audit': gap_audit, 'zero_gap_required': True,
                'fourth_year_exam_policy': {
                    'enabled': program_code == 'BSIT',
                    'cluster_days': [2] if program_code == 'BSIT' else [],
                    'major_day': 3 if program_code == 'BSIT' else None,
                    'cluster_exams_same_day_consecutive': program_code == 'BSIT',
                    'cluster_sections_checked': cluster_count,
                    'major_sections_checked': major_count,
                    'actual_student_memberships_checked': True,
                },
                'proctor_policy': (f'One fixed active {program_code} proctor is assigned per section for the entire exam period. '
                                   'The same proctor may serve another section/year later at non-overlapping times; '
                                   'foreign-program/department proctors are not eligible.'),
                'paper_rotation_policy': ('Different subjects may run in parallel. The same subject within the same '
                                          'year level must use different exam times so test papers can rotate between sections.'),
                'day_distribution_policy': ('Compact BCP distribution is enforced: 6 exams use 3+3 on consecutive days; '
                                            '4-5 exams use two consecutive days; 7-9 exams are balanced across all three days. '
                                            'Day 1 + Day 3 with an empty Day 2 is not allowed.'),
                'class_policy': ('Selected examination dates suspend recurring regular classes; '
                                 'saved weekly classes remain stored and resume on non-exam dates.'),
                'exam_dates': [d.isoformat() for d in days],
                'linked_student_section_pairs': [{'home_section_id': a, 'major_section_id': b}
                                                 for a, b in sorted(linked_pairs)],
                'assignments': result}
    except (ValueError, KeyError, TypeError) as exc:
        return reject('INVALID_EXAM_INPUT', str(exc))
