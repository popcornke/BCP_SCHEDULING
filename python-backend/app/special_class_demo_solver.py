"""ISOLATED DEMO ONLY: OR-Tools weekly special-class preview, NO database writes.

Requires a CURRENT server-built input snapshot, including actual participant memberships,
all ACTIVE class/exam/special meetings and substitute duties, time slots and availability.
Not connected to the live preview endpoint until school-approved duration has been recorded.
"""
from datetime import date, datetime
from zoneinfo import ZoneInfo

from .special_class_demo_audit import (audit_special_class, available, clock,
                                  covered_by_slots, occurrences, SpecialClassAuditError)


def reject(code, message, **extra):
    return {'success': False, 'status': code, 'message': message,
            'database_write': False, 'saving_enabled': False, **extra}


def solve_demo_special_class(data):
    """Select ONE consistent recurring weekly time/room using OR-Tools CP-SAT.

    Test duration is supplied by localhost PHP server as a fixed simulation value.
    No school approval is created, assumed, or stored. No DB access or writes.
    """
    try:
        policy = data['policy']
        if policy.get('demo_test_mode') is not True or policy.get('policy_source') != 'ISOLATED_DEMO_SIMULATION':
            return reject('DEMO_MODE_REQUIRED', 'Only isolated DEMO simulation requests are accepted.')
        if policy.get('duration_verified_from_database') is True or policy.get('approval_reference'):
            return reject('OFFICIAL_POLICY_NOT_ACCEPTED', 'Do not send purported school approval metadata to DEMO solver.')
        minutes = policy.get('test_duration_minutes')
        if type(minutes) is not int or not 30 <= minutes <= 900 or minutes % 30:
            return reject('INVALID_DEMO_DURATION', 'DEMO test duration is missing or invalid.')
        request = data['request']
        if request.get('delivery_mode') != 'F2F' or minutes != 60:
            return reject('INVALID_DEMO_CONFIG', 'Isolated Phase 6F DEMO only tests 60-minute F2F sessions.')
        if request.get('delivery_mode') not in ('F2F', 'ONLINE'):
            return reject('MODE_REQUIRED', 'A valid DEMO delivery mode is required.')
        if request.get('class_type') == 'OCTOBERIAN' and policy.get('octoberian_rules_approved') is not True:
            return reject('OCTOBERIAN_POLICY_PENDING',
                          'Generic Octoberian requests are allowed, but scheduling is blocked until its specific policies are approved.')
        if not request.get('teacher_authorized'):
            return reject('UNAUTHORIZED_PROFESSOR', 'Professor has no verified subject authorization.')
        dates = occurrences(data)
        # PHP DEMO endpoint checks its explicit test window. Official calendar gate is not used.
        if date.fromisoformat(dates[0][0]) < datetime.now(ZoneInfo('Asia/Manila')).date():
            return reject('PAST_SPECIAL_CLASS_DATE', 'A new prospective request cannot start on a past date.')
        if not 1 <= len(request['participants']) <= 50:
            return reject('INVALID_PARTICIPANTS', 'Choose actual participating students (1–50).')
        teacher_id = int(request['teacher_id'])
        program_id = int(request['program_id'])
        selected_count = len(request['participants'])
        first_day = dates[0][1]
        all_days = {day for _, day in dates}
        # A single recurring time must exist on EVERY requested weekday.
        edges = sorted({clock(slot['start_time']) for slot in data['time_slots']
                        if slot['day_of_week'] == first_day and int(slot.get('is_active', 1))})
        options = []
        for start in edges:
            end = start + minutes
            if start < 360 or end > 1260:
                continue
            if not all(covered_by_slots(data['time_slots'], weekday, start, end) for weekday in all_days):
                continue
            if not all(available(data['teacher_availability'], teacher_id, 'teacher_id', weekday, start, end)
                       for weekday in all_days):
                continue
            rooms = [None] if request['delivery_mode'] == 'ONLINE' else [int(r['room_id']) for r in data['rooms']
                     if r['status'] == 'AVAILABLE' and int(r['capacity']) >= selected_count and
                     (r.get('program_id') is None or int(r['program_id']) == program_id) and
                     all(available(data['room_availability'], int(r['room_id']), 'room_id', weekday, start, end)
                         for weekday in all_days)]
            for room_id in rooms:
                assignments = [dict(meeting_date=d, day_of_week=weekday,
                                    start_time=f'{start // 60:02d}:{start % 60:02d}',
                                    end_time=f'{end // 60:02d}:{end % 60:02d}',
                                    teacher_id=teacher_id, room_id=room_id,
                                    delivery_mode=request['delivery_mode']) for d, weekday in dates]
                # Use the independent checker to reject every resource/student/load violation.
                audit = audit_special_class(data, assignments)
                if audit['passed']:
                    options.append((start, room_id, assignments))
        if not options:
            return reject('NO_CONFLICT_FREE_WEEKLY_SLOT',
                          'No single recurring time/room covers all selected dates without student, professor, room, exam, substitute or load conflicts.',
                          checked_weekly_dates=len(dates))
        try:
            from ortools.sat.python import cp_model
        except ImportError:
            return reject('ORTOOLS_UNAVAILABLE', 'Install OR-Tools in the existing Python backend environment.')
        model = cp_model.CpModel()
        chosen = [model.NewBoolVar(f'weekly_option_{i}') for i in range(len(options))]
        model.AddExactlyOne(chosen)
        # Prefer the earliest compatible block, with a stable room tie-breaker.
        model.Minimize(sum((start * 100000 + (room_id or 0)) * flag
                           for flag, (start, room_id, _) in zip(chosen, options)))
        solver = cp_model.CpSolver()
        solver.parameters.max_time_in_seconds = 20.0
        solver.parameters.num_search_workers = 4
        status = solver.Solve(model)
        if status not in (cp_model.OPTIMAL, cp_model.FEASIBLE):
            return reject('SOLVER_DID_NOT_FINISH', 'The optimizer did not find a valid weekly assignment.')
        assignments = options[next(i for i, flag in enumerate(chosen) if solver.Value(flag))][2]
        independent = audit_special_class(data, assignments)
        if not independent['passed']:
            return reject('INDEPENDENT_AUDIT_FAILED',
                          'Independent validation rejected the chosen weekly timetable.',
                          audit=independent)
        return {'success': True, 'status': 'SPECIAL_CLASS_DEMO_PREVIEW_READY',
                'phase': 'ISOLATED_DEMO_UNSAVED_PREVIEW', 'official_policy_approved': False, 'demo_only': True, 'assignments': assignments,
                'occurrence_count': len(assignments), 'independent_audit': independent,
                'database_write': False, 'saving_enabled': False,
                'notice': 'DEMO SIMULATION ONLY. Sample duration/calendar are not school approvals. Read-only; never use as an official timetable.'}
    except (ValueError, TypeError, KeyError, SpecialClassAuditError) as exc:
        return reject('INVALID_SPECIAL_CLASS_INPUT', str(exc))
