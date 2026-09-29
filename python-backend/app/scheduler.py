"""
BCP AUTOMATIC CLASS SCHEDULING SYSTEM

Phase 3B - DEMO Timetable Optimization

Backend:
    Python
    Google OR-Tools CP-SAT

IMPORTANT:
    - Uses database-provided scheduling inputs.
    - Does not create student sections.
    - Does not change student memberships.
    - Does not generate random schedules.
    - Does not save schedules to the database.
    - Does not claim school-wide validation is complete.

ODD SECTIONS:
    F2F    = Monday, Wednesday, Friday
    ONLINE = Tuesday, Thursday, Saturday

EVEN SECTIONS:
    F2F    = Tuesday, Thursday, Saturday
    ONLINE = Monday, Wednesday, Friday
"""

import os

from collections import defaultdict

from ortools.sat.python import cp_model
from app.existing_schedules import normalize_existing, occupy_saved_intervals


# ============================================
# 1. SCHEDULING CONFIGURATION
# ============================================

DAYS = [
    "Monday",
    "Tuesday",
    "Wednesday",
    "Thursday",
    "Friday",
    "Saturday",
]

DAY_INDEX = {
    day: index
    for index, day in enumerate(DAYS)
}

MWF_DAYS = [0, 2, 4]

TTHS_DAYS = [1, 3, 5]

SLOT_MINUTES = 30

DAY_START_MINUTES = 6 * 60

DAY_END_MINUTES = 21 * 60

SLOTS_PER_DAY = (
    DAY_END_MINUTES - DAY_START_MINUTES
) // SLOT_MINUTES

TOTAL_WEEK_SLOTS = (
    len(DAYS) * SLOTS_PER_DAY
)

MAX_SOLVE_SECONDS = float(
    os.getenv("MAX_SOLVE_SECONDS", "120")
)


# ============================================
# 2. TIME CONVERSION
# ============================================

def clock_to_minutes(value):
    """
    Convert HH:MM or HH:MM:SS to minutes.
    """

    parts = str(value).split(":")

    if len(parts) < 2:
        raise ValueError(
            f"Invalid time value: {value}"
        )

    return (
        int(parts[0]) * 60
        + int(parts[1])
    )


def minutes_to_clock(minutes):
    """
    Convert minutes to HH:MM.
    """

    hours = minutes // 60

    remaining_minutes = minutes % 60

    return (
        f"{hours:02d}:"
        f"{remaining_minutes:02d}"
    )


def slot_to_clock(slot):
    """
    Convert a position within the school day
    to a readable time.
    """

    minutes = (
        DAY_START_MINUTES
        + slot * SLOT_MINUTES
    )

    return minutes_to_clock(minutes)


def hours_to_slots(value):
    """
    Convert academic hours into 30-minute slots.

    Example:
        1 hour  = 2 slots
        2 hours = 4 slots
    """

    minutes = round(float(value) * 60)

    if minutes <= 0:
        raise ValueError(
            f"Invalid class duration: {value}"
        )

    if minutes % SLOT_MINUTES != 0:
        raise ValueError(
            "Class duration must be compatible "
            "with the database time slots."
        )

    return minutes // SLOT_MINUTES


# ============================================
# 3. ODD / EVEN SECTION DAY PATTERN
# ============================================

def get_section_days(section_code, mode, section_type="REGULAR"):
    """BSIT demo: major sections are flexible; regular/cluster retain odd/even."""
    code = str(section_code)
    if len(code) != 5 or not code.isascii() or not code.isdigit() or int(code[-2:]) < 1:
        raise ValueError(f"Invalid section code: {code}")
    if mode not in ("F2F", "ONLINE"):
        raise ValueError(f"Invalid delivery mode: {mode}")
    if section_type == "MAJOR":
        return list(range(len(DAYS)))
    odd = int(code[-2:]) % 2 == 1
    f2f = MWF_DAYS if odd else TTHS_DAYS
    online = TTHS_DAYS if odd else MWF_DAYS
    return f2f if mode == "F2F" else online


# ============================================
# 4. RESOURCE AVAILABILITY
# ============================================

def validate_availability(
    availability_rows,
    day_name,
    start_slot,
    duration,
):
    """
    Check teacher or room availability.

    A meeting must fit inside at least one
    AVAILABLE window.

    Any overlapping UNAVAILABLE window
    invalidates the candidate.
    """

    start_minutes = (
        DAY_START_MINUTES
        + start_slot * SLOT_MINUTES
    )

    end_minutes = (
        start_minutes
        + duration * SLOT_MINUTES
    )

    available = False

    for row in availability_rows:

        if row["day_of_week"] != day_name:
            continue

        window_start = clock_to_minutes(
            row["start_time"]
        )

        window_end = clock_to_minutes(
            row["end_time"]
        )

        status = row[
            "availability_status"
        ]

        overlaps = (
            start_minutes < window_end
            and window_start < end_minutes
        )

        if (
            status == "UNAVAILABLE"
            and overlaps
        ):
            return False

        if (
            status == "AVAILABLE"
            and window_start <= start_minutes
            and end_minutes <= window_end
        ):
            available = True

    return available


# ============================================
# 5. DATABASE TIME SLOT VALIDATION
# ============================================

def build_available_starts(
    time_slots,
    duration,
    mode,
    section_code,
    section_type="REGULAR",
):
    """
    Return valid lesson start times.

    IMPORTANT:

    Only existing database time slots
    may be used.

    No manually generated timetable
    positions are added.

    The optimizer may combine consecutive
    30-minute database slots to satisfy
    the required lesson duration.
    """

    allowed_days = get_section_days(
        section_code,
        mode,
        section_type,
    )

    database_slots = defaultdict(set)

    for row in time_slots:

        day_name = row["day_of_week"]

        if day_name not in DAY_INDEX:
            continue

        day_index = DAY_INDEX[day_name]

        expected_pattern = (
            "MWF"
            if day_index in MWF_DAYS
            else "TTHS"
        )

        if (
            row["day_pattern"]
            != expected_pattern
        ):
            continue

        start_minutes = clock_to_minutes(
            row["start_time"]
        )

        end_minutes = clock_to_minutes(
            row["end_time"]
        )

        offset = (
            start_minutes
            - DAY_START_MINUTES
        )

        if offset < 0:
            continue

        if (
            offset % SLOT_MINUTES != 0
        ):
            continue

        if (
            end_minutes - start_minutes
            != SLOT_MINUTES
        ):
            continue

        if end_minutes > DAY_END_MINUTES:
            continue

        slot_index = (
            offset // SLOT_MINUTES
        )

        database_slots[
            day_index
        ].add(slot_index)

    valid_starts = []

    for day_index in allowed_days:

        for position in range(
            SLOTS_PER_DAY - duration + 1
        ):

            consecutive = all(

                position + offset
                in database_slots[day_index]

                for offset in range(duration)

            )

            if not consecutive:
                continue

            global_start = (
                day_index * SLOTS_PER_DAY
                + position
            )

            valid_starts.append(
                global_start
            )

    return valid_starts


# ============================================
# 6. RESPONSE HELPERS
# ============================================

def failure(status, message, **details):
    """
    Return a consistent failure response.

    Never create fallback assignments.
    """

    response = {
        "success": False,
        "status": status,
        "message": message,
        "database_write": False,
        "assignments": [],
    }

    response.update(details)

    return response


# ============================================
# 7. MAIN SCHEDULING OPTIMIZER
# ============================================

def solve_schedule(payload):

    data = payload.get(
        "scheduling_input",
        payload,
    )

    # ========================================
    # 7.1 VALIDATE INPUT STRUCTURE
    # ========================================

    required_keys = [
        "sections",
        "section_subjects",
        "teachers",
        "authorizations",
        "teacher_availability",
        "rooms",
        "room_availability",
        "time_slots",
        "major_links",
        "existing_meetings",
    ]

    missing_fields = [

        key
        for key in required_keys

        if key not in data

    ]

    if missing_fields:

        return failure(
            "INVALID_INPUT",
            "Missing scheduling input fields.",
            missing_fields=missing_fields,
        )

    if payload.get("program", {}).get("program_code") != "BSIT":
        return failure(
            "PROGRAM_POLICY_NOT_CONFIGURED",
            "Only BSIT-specific scheduling policies are configured in this demo solver.",
        )

    if payload.get("data_origin") != "DEMO":

        return failure(
            "DEMO_ONLY",
            "This scheduler currently accepts "
            "DEMO scheduling inputs only.",
        )

    sections = {

        int(row["section_id"]): row

        for row in data["sections"]

    }

    teachers = {

        int(row["teacher_id"]): row

        for row in data["teachers"]

    }

    rooms = {

        int(row["room_id"]): row

        for row in data["rooms"]

    }

    assignments = data[
        "section_subjects"
    ]

    if not sections:

        return failure(
            "NO_SECTIONS",
            "No sections were provided.",
        )

    if not assignments:

        return failure(
            "NO_SUBJECT_ASSIGNMENTS",
            "No required section-subject "
            "assignments were provided.",
        )

    if not teachers:

        return failure(
            "NO_TEACHERS",
            "No active teachers were provided.",
        )

    if not rooms:

        return failure(
            "NO_ROOMS",
            "No available rooms were provided.",
        )

    if not data["time_slots"]:

        return failure(
            "NO_TIME_SLOTS",
            "No database time slots were provided.",
        )

    # Phase 4B: fail closed when the saved timetable snapshot is incomplete,
    # malformed, already belongs to this program, or has existing conflicts.
    try:
        saved_snapshot = normalize_existing(payload)
    except (ValueError, KeyError, TypeError) as exc:
        return failure("INVALID_EXISTING_SCHEDULES", str(exc))

    # ========================================
    # 7.2 BUILD RESOURCE LOOKUPS
    # ========================================

    teacher_authorizations = defaultdict(set)

    for row in data["authorizations"]:

        subject_id = int(
            row["subject_id"]
        )

        teacher_id = int(
            row["teacher_id"]
        )

        teacher_authorizations[
            subject_id
        ].add(teacher_id)

    teacher_windows = defaultdict(list)

    for row in data[
        "teacher_availability"
    ]:

        teacher_id = int(
            row["teacher_id"]
        )

        teacher_windows[
            teacher_id
        ].append(row)

    room_windows = defaultdict(list)

    for row in data[
        "room_availability"
    ]:

        room_id = int(
            row["room_id"]
        )

        room_windows[
            room_id
        ].append(row)

    # ========================================
    # 7.3 CREATE REQUIRED MEETINGS
    # ========================================

    meetings = []

    for assignment in assignments:

        section_id = int(
            assignment["section_id"]
        )

        if section_id not in sections:

            return failure(
                "INVALID_SECTION",
                f"Unknown section ID: {section_id}",
            )

        section = sections[section_id]

        section_code = section[
            "section_code"
        ]

        subject_id = int(
            assignment["subject_id"]
        )

        for mode, hours in [

            (
                "F2F",
                assignment["f2f_hours"],
            ),

            (
                "ONLINE",
                assignment["online_hours"],
            ),

        ]:

            if float(hours) <= 0:
                continue

            duration = hours_to_slots(
                hours
            )

            valid_starts = build_available_starts(
                data["time_slots"],
                duration,
                mode,
                section_code,
                section["section_type"],
            )

            if not valid_starts:

                return failure(
                    "NO_VALID_TIME_SLOTS",

                    f"No valid {mode} time slots "
                    f"for subject "
                    f"{assignment['subject_code']} "
                    f"in section {section_code}.",
                )

            meetings.append({

                "section_id":
                    section_id,

                "section_code":
                    section_code,

                "section_subject_id":
                    int(
                        assignment[
                            "section_subject_id"
                        ]
                    ),

                "subject_id":
                    subject_id,

                "subject_code":
                    assignment["subject_code"],

                "subject_title":
                    assignment["subject_title"],

                "mode":
                    mode,

                "duration":
                    duration,

                "valid_starts":
                    valid_starts,

            })

    # ========================================
    # 7.4 CREATE OR-TOOLS MODEL
    # ========================================

    model = cp_model.CpModel()

    # ========================================
    # 8. SECTION ROOM ASSIGNMENT
    # ========================================

    section_room_variables = {}

    section_room_choices = defaultdict(dict)

    room_intervals = defaultdict(list)

    for section_id, section in sections.items():

        required_capacity = int(
            section["student_count"]
        )

        section_meetings = [

            meeting
            for meeting in meetings

            if (
                meeting["section_id"]
                == section_id

                and meeting["mode"]
                == "F2F"
            )

        ]

        if not section_meetings:
            continue

        candidate_rooms = []

        for room_id, room in rooms.items():

            # Room must accommodate all students.

            if (
                int(room["capacity"])
                < required_capacity
            ):
                continue

            # Do not assign BSIT to rooms
            # restricted to another program.

            room_program = room.get(
                "program_id"
            )

            if (
                room_program is not None
                and int(room_program)
                != int(section["program_id"])
            ):
                continue

            usable = True

            for meeting in section_meetings:

                possible = any(

                    validate_availability(

                        room_windows[room_id],

                        DAYS[
                            start // SLOTS_PER_DAY
                        ],

                        start % SLOTS_PER_DAY,

                        meeting["duration"],

                    )

                    for start in meeting[
                        "valid_starts"
                    ]

                )

                if not possible:

                    usable = False

                    break

            if usable:

                candidate_rooms.append(
                    room_id
                )

        # Reuse the semester room from the ACTIVE baseline on replacement.
        if data.get("replacement_room_locks") is not None:
            locks = data["replacement_room_locks"]
            if not isinstance(locks, dict):
                return failure("INVALID_ROOM_LOCKS", "Invalid replacement room locks.")
            must_lock = (
                section["section_type"] == "REGULAR"
                and int(section["year_level"]) in (1, 2, 3)
            ) or (
                section["section_type"] == "CLUSTER"
                and int(section["year_level"]) == 4
            )
            if must_lock:
                fixed_id = locks.get(str(section_id), locks.get(section_id))
                if fixed_id is None:
                    return failure("MISSING_SEMESTER_ROOM", f"Section {section['section_code']} has no saved semester room.")
                candidate_rooms = [room_id for room_id in candidate_rooms if room_id == int(fixed_id)]

        if not candidate_rooms:

            return failure(
                "NO_ELIGIBLE_ROOM",

                f"No eligible room for "
                f"section {section['section_code']}.",
            )

        # One selected room per section.
        #
        # Different sections may reuse the
        # same physical room at different times.

        room_var = model.NewIntVarFromDomain(

            cp_model.Domain.FromValues(
                candidate_rooms
            ),

            f"section_room_{section_id}",

        )

        section_room_variables[
            section_id
        ] = room_var

        choices = []

        for room_id in candidate_rooms:

            selected = model.NewBoolVar(

                f"section_{section_id}"
                f"_room_{room_id}"

            )

            model.Add(

                room_var == room_id

            ).OnlyEnforceIf(selected)

            section_room_choices[
                section_id
            ][room_id] = selected

            choices.append(selected)

        model.AddExactlyOne(
            choices
        )

    # ========================================
    # 9. LESSON VARIABLES
    # ========================================

    section_intervals = defaultdict(list)

    subject_intervals = defaultdict(list)

    teacher_intervals = defaultdict(list)

    teacher_weekly_load = defaultdict(list)

    teacher_daily_load = defaultdict(list)

    section_f2f = defaultdict(list)

    for index, meeting in enumerate(meetings):

        section_id = meeting[
            "section_id"
        ]

        subject_id = meeting[
            "subject_id"
        ]

        duration = meeting[
            "duration"
        ]

        valid_starts = meeting[
            "valid_starts"
        ]

        # ====================================
        # 9.1 LESSON START
        # ====================================

        start = model.NewIntVarFromDomain(

            cp_model.Domain.FromValues(
                valid_starts
            ),

            f"meeting_{index}_start",

        )

        end = model.NewIntVar(

            0,

            TOTAL_WEEK_SLOTS,

            f"meeting_{index}_end",

        )

        model.Add(
            end == start + duration
        )

        # ====================================
        # 9.2 LESSON DAY
        # ====================================

        day = model.NewIntVar(

            0,

            len(DAYS) - 1,

            f"meeting_{index}_day",

        )

        model.AddDivisionEquality(

            day,

            start,

            SLOTS_PER_DAY,

        )

        # ====================================
        # 9.3 POSITION WITHIN THE DAY
        # ====================================

        position = model.NewIntVar(

            0,

            SLOTS_PER_DAY - 1,

            f"meeting_{index}_position",

        )

        model.AddModuloEquality(

            position,

            start,

            SLOTS_PER_DAY,

        )

        position_end = model.NewIntVar(

            1,

            SLOTS_PER_DAY,

            f"meeting_{index}_position_end",

        )

        model.Add(

            position_end
            == position + duration

        )

        # ====================================
        # 9.4 LESSON INTERVAL
        # ====================================

        interval = model.NewIntervalVar(

            start,

            duration,

            end,

            f"meeting_{index}_interval",

        )

        meeting.update({

            "start_var": start,

            "end_var": end,

            "day_var": day,

            "position_var": position,

            "position_end_var":
                position_end,

            "interval": interval,

        })

        section_intervals[
            section_id
        ].append(interval)

        # The same subject ID must not overlap
        # across different sections.
        #
        # Different subjects may run
        # simultaneously in different sections.

        subject_intervals[
            subject_id
        ].append(interval)

        # ====================================
        # 10. TEACHER ASSIGNMENT
        # ====================================

        eligible_teachers = []

        for teacher_id in sorted(
            teacher_authorizations[subject_id]
        ):

            if teacher_id not in teachers:
                continue

            # Teachers belong to their own program only.
            if int(teachers[teacher_id]["program_id"]) != int(
                sections[section_id]["program_id"]
            ):
                continue

            eligible_starts = [

                value

                for value in valid_starts

                if validate_availability(

                    teacher_windows[
                        teacher_id
                    ],

                    DAYS[
                        value // SLOTS_PER_DAY
                    ],

                    value % SLOTS_PER_DAY,

                    duration,

                )

            ]

            if eligible_starts:

                eligible_teachers.append(

                    (
                        teacher_id,
                        eligible_starts,
                    )

                )

        if not eligible_teachers:

            return failure(
                "NO_ELIGIBLE_TEACHER",

                f"No available authorized teacher "
                f"for subject "
                f"{meeting['subject_code']} "
                f"in section "
                f"{meeting['section_code']}.",
            )

        teacher_var = model.NewIntVarFromDomain(

            cp_model.Domain.FromValues(

                [
                    teacher_id

                    for teacher_id, _
                    in eligible_teachers
                ]

            ),

            f"meeting_{index}_teacher",

        )

        meeting[
            "teacher_var"
        ] = teacher_var

        teacher_choices = []

        for (
            teacher_id,
            eligible_starts,
        ) in eligible_teachers:

            selected = model.NewBoolVar(

                f"meeting_{index}"
                f"_teacher_{teacher_id}"

            )

            model.Add(

                teacher_var == teacher_id

            ).OnlyEnforceIf(selected)

            # The selected teacher must be
            # available for the whole meeting.

            model.AddAllowedAssignments(

                [start],

                [
                    (value,)

                    for value in eligible_starts
                ],

            ).OnlyEnforceIf(selected)

            optional_teacher_interval = (

                model.NewOptionalIntervalVar(

                    start,

                    duration,

                    end,

                    selected,

                    f"teacher_{teacher_id}"
                    f"_meeting_{index}",

                )

            )

            optional_teacher_interval = (

                model.NewOptionalIntervalVar(

                    start,

                    duration,

                    end,

                    selected,

                    f"teacher_{teacher_id}"
                    f"_meeting_{index}",

                )

            )

            teacher_intervals[
                teacher_id
            ].append(
                optional_teacher_interval
            )

            # Weekly teaching workload.

            teacher_weekly_load[
                teacher_id
            ].append(

                duration * selected

            )

            # ====================================
            # 10.1 DAILY TEACHER WORKLOAD
            # ====================================

            eligible_days = sorted({

                value // SLOTS_PER_DAY

                for value in eligible_starts

            })

            for day_index in eligible_days:

                on_day = model.NewBoolVar(

                    f"teacher_{teacher_id}"
                    f"_meeting_{index}"
                    f"_day_{day_index}",

                )

                model.Add(

                    day == day_index

                ).OnlyEnforceIf(
                    on_day
                )

                model.Add(

                    day != day_index

                ).OnlyEnforceIf(
                    on_day.Not()
                )

                selected_on_day = model.NewBoolVar(

                    f"teacher_{teacher_id}"
                    f"_meeting_{index}"
                    f"_selected_day_{day_index}",

                )

                model.Add(

                    selected_on_day <= selected

                )

                model.Add(

                    selected_on_day <= on_day

                )

                model.Add(

                    selected_on_day
                    >= selected + on_day - 1

                )

                teacher_daily_load[

                    (
                        teacher_id,
                        day_index,
                    )

                ].append(

                    duration * selected_on_day

                )

            teacher_choices.append(
                selected
            )

        model.AddExactlyOne(
            teacher_choices
        )

        # ====================================
        # 11. ROOM ASSIGNMENT
        # ====================================

        if meeting["mode"] == "F2F":

            section_f2f[
                section_id
            ].append(meeting)

            for room_id, selected in (

                section_room_choices[
                    section_id
                ].items()

            ):

                eligible_room_starts = [

                    value

                    for value in valid_starts

                    if validate_availability(

                        room_windows[
                            room_id
                        ],

                        DAYS[
                            value // SLOTS_PER_DAY
                        ],

                        value % SLOTS_PER_DAY,

                        duration,

                    )

                ]

                if not eligible_room_starts:

                    model.Add(
                        selected == 0
                    )

                    continue

                model.AddAllowedAssignments(

                    [start],

                    [
                        (value,)

                        for value
                        in eligible_room_starts
                    ],

                ).OnlyEnforceIf(selected)

                optional_room_interval = (

                    model.NewOptionalIntervalVar(

                        start,

                        duration,

                        end,

                        selected,

                        f"room_{room_id}"
                        f"_meeting_{index}",

                    )

                )

                room_intervals[
                    room_id
                ].append(
                    optional_room_interval
                )

        # ONLINE classes have no physical room.

    # Phase 3C: same teacher teaches this section-subject in both delivery modes.
    meetings_per_subject = defaultdict(list)
    for meeting in meetings:
        meetings_per_subject[meeting["section_subject_id"]].append(meeting)
    for pair in meetings_per_subject.values():
        for other in pair[1:]:
            model.Add(other["teacher_var"] == pair[0]["teacher_var"])

    # BSIT fourth-year MAJOR: F2F and ONLINE cannot occur on the same day.
    for sec_id, sec in sections.items():
        if sec["section_type"] != "MAJOR" or int(sec["year_level"]) != 4:
            continue
        f2f = [m for m in meetings if m["section_id"] == sec_id and m["mode"] == "F2F"]
        online = [m for m in meetings if m["section_id"] == sec_id and m["mode"] == "ONLINE"]
        for f in f2f:
            for o in online:
                model.Add(f["day_var"] != o["day_var"])

    # Phase 4B: existing assignments are FIXED, never planning variables.
    # Insert before AddNoOverlap so old and new use the same resource indexes.
    occupy_saved_intervals(
        model, saved_snapshot, section_intervals, subject_intervals,
        teacher_intervals, room_intervals,
    )

    # ========================================
    # 12. HARD CONFLICT CONSTRAINTS
    # ========================================

    # ====================================
    # 12.1 SAME SECTION
    # ====================================

    for intervals in section_intervals.values():

        model.AddNoOverlap(
            intervals
        )

    # ====================================
    # 12.2 SAME SUBJECT
    # ====================================

    for intervals in subject_intervals.values():

        model.AddNoOverlap(
            intervals
        )

    # ====================================
    # 12.3 SAME TEACHER
    # ====================================

    for intervals in teacher_intervals.values():

        model.AddNoOverlap(
            intervals
        )

    # ====================================
    # 12.4 SAME ROOM
    # ====================================

    for intervals in room_intervals.values():

        model.AddNoOverlap(
            intervals
        )

    # IMPORTANT:
    #
    # There is NO global constraint that
    # prevents different sections from
    # having classes at the same time.
    #
    # Simultaneous classes are allowed when
    # section, subject, teacher, room and
    # shared-student constraints are satisfied.

    # ========================================
    # 13. TEACHER WORKLOAD
    # ========================================

    for teacher_id, teacher in teachers.items():

        weekly_limit = hours_to_slots(

            teacher["max_weekly_hours"]

        )

        model.Add(

            sum(

                teacher_weekly_load[
                    teacher_id
                ]

            ) <= weekly_limit

        )

        daily_limit = hours_to_slots(

            teacher["max_daily_hours"]

        )

        for day_index in range(
            len(DAYS)
        ):

            model.Add(

                sum(

                    teacher_daily_load[

                        (
                            teacher_id,
                            day_index,
                        )

                    ]

                ) <= daily_limit

            )

    # ========================================
    # 14. CLUSTER / MAJOR CONFLICTS
    # ========================================

    for link in data["major_links"]:

        home_id = int(
            link["home_section_id"]
        )

        major_id = int(
            link["major_section_id"]
        )

        if (
            home_id not in sections
            or major_id not in sections
        ):

            return failure(
                "INVALID_MAJOR_LINK",

                "A Cluster/Major relationship "
                "references an unknown section.",
            )

        home_section = sections[
            home_id
        ]

        major_section = sections[
            major_id
        ]

        if (
            home_section["section_type"]
            != "CLUSTER"

            or major_section["section_type"]
            != "MAJOR"
        ):

            return failure(
                "INVALID_MAJOR_LINK",

                "Invalid Cluster/Major section types.",
            )

        if (
            home_section["program_id"]
            != major_section["program_id"]

            or home_section["academic_period_id"]
            != major_section["academic_period_id"]
        ):

            return failure(
                "INVALID_MAJOR_LINK",

                "Linked sections must belong "
                "to the same program and "
                "academic period.",
            )

        # ====================================
        # 14.1 PREVENT TIME OVERLAP
        # ====================================

        # Students shared by the linked
        # sections cannot attend two
        # simultaneous classes.

        model.AddNoOverlap(

            section_intervals[home_id]
            + section_intervals[major_id]

        )

        # ====================================
        # 14.2 PREVENT MIXED DELIVERY DAYS
        # ====================================

        # If the Cluster and Major sections
        # share students, an F2F meeting
        # and an ONLINE meeting cannot
        # occur on the same calendar day.
        #
        # This is important when a Cluster
        # section is ODD and its linked
        # Major section is EVEN.

        home_meetings = [

            meeting

            for meeting in meetings

            if meeting["section_id"]
            == home_id

        ]

        major_meetings = [

            meeting

            for meeting in meetings

            if meeting["section_id"]
            == major_id

        ]

        for home_meeting in home_meetings:

            for major_meeting in major_meetings:

                if (

                    home_meeting["mode"]
                    == major_meeting["mode"]

                ):
                    continue

                model.Add(

                    home_meeting["day_var"]
                    != major_meeting["day_var"]

                )

    # ========================================
    # 15. DAILY SUBJECT DISTRIBUTION
    # ========================================

    # Use each section's OWN F2F day pattern.
    #
    # ODD:
    #     MWF
    #
    # EVEN:
    #     TThS

    for section_id, section in sections.items():

        lessons = section_f2f[
            section_id
        ]

        if not lessons:
            continue

        year_level = int(
            section["year_level"]
        )

        section_type = section[
            "section_type"
        ]

        section_code = section[
            "section_code"
        ]

        f2f_days = get_section_days(

            section_code,

            "F2F",
            section_type,
        )

        for day_index in f2f_days:

            day_flags = []

            # ====================================
            # 15.1 IDENTIFY MEETINGS ON THIS DAY
            # ====================================

            for meeting in lessons:

                present = model.NewBoolVar(

                    f"section_{section_id}"
                    f"_meeting_"
                    f"{meeting['section_subject_id']}"
                    f"_day_{day_index}",

                )

                model.Add(

                    meeting["day_var"]
                    == day_index

                ).OnlyEnforceIf(
                    present
                )

                model.Add(

                    meeting["day_var"]
                    != day_index

                ).OnlyEnforceIf(
                    present.Not()
                )

                meeting.setdefault(

                    "f2f_day_flags",
                    {}

                )[day_index] = present

                day_flags.append(
                    present
                )

            count = sum(
                day_flags
            )

            # ====================================
            # 15.2 MAJOR SECTIONS
            # ====================================

            # Demo major sections have only
            # one required major subject.

            if section_type == "MAJOR":

                model.Add(
                    count <= 1
                )

                continue

            # ====================================
            # 15.3 FIRST YEAR
            # ====================================

            # 9 F2F subjects:
            # 3 subjects on each F2F day.

            if year_level == 1:

                model.Add(
                    count == 3
                )

            # ====================================
            # 15.4 SECOND YEAR
            # ====================================

            # 8 F2F subjects:
            #
            # 3-3-2
            # 3-2-3
            # 2-3-3

            elif year_level == 2:

                model.Add(
                    count >= 2
                )

                model.Add(
                    count <= 3
                )

            # ====================================
            # 15.5 THIRD YEAR
            # ====================================

            # 6 F2F subjects:
            #
            # Two days containing 3 subjects.
            # One vacant F2F day.

            elif year_level == 3:

                active_day = model.NewBoolVar(

                    f"third_year_"
                    f"section_{section_id}"
                    f"_day_{day_index}_active",

                )

                model.Add(

                    count == 3

                ).OnlyEnforceIf(
                    active_day
                )

                model.Add(

                    count == 0

                ).OnlyEnforceIf(
                    active_day.Not()
                )

            # ====================================
            # 15.6 FOURTH-YEAR CLUSTER
            # ====================================

            elif (
                year_level == 4
                and section_type == "CLUSTER"
            ):

                active_day = model.NewBoolVar(

                    f"fourth_year_"
                    f"section_{section_id}"
                    f"_day_{day_index}_active",

                )

                model.Add(

                    count == len(lessons)

                ).OnlyEnforceIf(
                    active_day
                )

                model.Add(

                    count == 0

                ).OnlyEnforceIf(
                    active_day.Not()
                )

            # ====================================
            # 16. DAILY BREAK / GAP CONSTRAINTS
            # ====================================

            first_candidates = []

            last_candidates = []

            total_duration = sum(

                meeting["duration"]
                * meeting["f2f_day_flags"][day_index]

                for meeting in lessons

            )

            for meeting in lessons:

                present = meeting[
                    "f2f_day_flags"
                ][day_index]

                first = model.NewIntVar(

                    0,

                    SLOTS_PER_DAY,

                    f"first_{section_id}"
                    f"_{day_index}"
                    f"_{meeting['section_subject_id']}",

                )

                last = model.NewIntVar(

                    0,

                    SLOTS_PER_DAY,

                    f"last_{section_id}"
                    f"_{day_index}"
                    f"_{meeting['section_subject_id']}",

                )

                model.Add(

                    first
                    == meeting["position_var"]

                ).OnlyEnforceIf(
                    present
                )

                model.Add(

                    first
                    == SLOTS_PER_DAY

                ).OnlyEnforceIf(
                    present.Not()
                )

                model.Add(

                    last
                    == meeting["position_end_var"]

                ).OnlyEnforceIf(
                    present
                )

                model.Add(

                    last == 0

                ).OnlyEnforceIf(
                    present.Not()
                )

                first_candidates.append(
                    first
                )

                last_candidates.append(
                    last
                )

            earliest = model.NewIntVar(

                0,

                SLOTS_PER_DAY,

                f"earliest_"
                f"{section_id}_{day_index}",

            )

            latest = model.NewIntVar(

                0,

                SLOTS_PER_DAY,

                f"latest_"
                f"{section_id}_{day_index}",

            )

            model.AddMinEquality(

                earliest,

                first_candidates,

            )

            model.AddMaxEquality(

                latest,

                last_candidates,

            )

            # ====================================
            # 16.1 FIRST YEAR BREAK
            # ====================================

            # Exactly one 30-minute gap.
            #
            # The gap must occur between
            # consecutive subjects.

            if year_level == 1:

                model.Add(

                    latest - earliest
                    == total_duration + 1

                )

            # ====================================
            # 16.2 SECOND YEAR BREAK
            # ====================================

            elif year_level == 2:

                three_subjects = model.NewBoolVar(

                    f"second_year_"
                    f"section_{section_id}"
                    f"_day_{day_index}"
                    f"_three_subjects",

                )

                model.Add(

                    count == 3

                ).OnlyEnforceIf(
                    three_subjects
                )

                model.Add(

                    count == 2

                ).OnlyEnforceIf(
                    three_subjects.Not()
                )

                # Three subjects:
                # one 30-minute break.

                model.Add(

                    latest - earliest
                    == total_duration + 1

                ).OnlyEnforceIf(
                    three_subjects
                )

                # Two subjects:
                # no scheduled break.

                model.Add(

                    latest - earliest
                    == total_duration

                ).OnlyEnforceIf(
                    three_subjects.Not()
                )

            # ====================================
            # 16.3 THIRD YEAR BREAK
            # ====================================

            elif year_level == 3:

                active_day = model.NewBoolVar(

                    f"third_year_break_"
                    f"section_{section_id}"
                    f"_day_{day_index}_active",

                )

                model.Add(

                    count == 3

                ).OnlyEnforceIf(
                    active_day
                )

                model.Add(

                    count == 0

                ).OnlyEnforceIf(
                    active_day.Not()
                )

                # One 30-minute break on
                # a three-subject F2F day.

                model.Add(

                    latest - earliest
                    == total_duration + 1

                ).OnlyEnforceIf(
                    active_day
                )

            # ====================================
            # 16.4 FOURTH YEAR: NO BREAK
            # ====================================

            elif (
                year_level == 4
                and section_type == "CLUSTER"
            ):

                active_day = model.NewBoolVar(

                    f"fourth_year_break_"
                    f"section_{section_id}"
                    f"_day_{day_index}_active",

                )

                model.Add(

                    count == len(lessons)

                ).OnlyEnforceIf(
                    active_day
                )

                model.Add(

                    count == 0

                ).OnlyEnforceIf(
                    active_day.Not()
                )

                # Classes must be consecutive.

                model.Add(

                    latest - earliest
                    == total_duration

                ).OnlyEnforceIf(
                    active_day
                )

    # Phase 3C: balance Years 1-3 ONLINE meetings across their three online days.
    # Phase 4E: retain the day flags for a SOFT compact-online objective.
    # The original teacher, subject, section, room, time-slot, and saved-schedule
    # restrictions above remain HARD and are not relaxed by this preference.
    online_gap_variables = []
    for sec_id, sec in sections.items():
        if int(sec["year_level"]) not in (1, 2, 3) or sec["section_type"] != "REGULAR":
            continue
        online = [m for m in meetings if m["section_id"] == sec_id and m["mode"] == "ONLINE"]
        allowed = get_section_days(sec["section_code"], "ONLINE", sec["section_type"])
        low, rem = divmod(len(online), len(allowed))
        high = low + (1 if rem else 0)
        for d in allowed:
            flags = []
            first_candidates = []
            last_candidates = []
            for m in online:
                flag = model.NewBoolVar(f"online_{sec_id}_{m['section_subject_id']}_{d}")
                model.Add(m["day_var"] == d).OnlyEnforceIf(flag)
                model.Add(m["day_var"] != d).OnlyEnforceIf(flag.Not())
                flags.append(flag)

                # An absent meeting cannot become the earliest or latest class.
                first = model.NewIntVar(0, SLOTS_PER_DAY,
                    f"online_first_{sec_id}_{m['section_subject_id']}_{d}")
                last = model.NewIntVar(0, SLOTS_PER_DAY,
                    f"online_last_{sec_id}_{m['section_subject_id']}_{d}")
                model.Add(first == m["position_var"]).OnlyEnforceIf(flag)
                model.Add(first == SLOTS_PER_DAY).OnlyEnforceIf(flag.Not())
                model.Add(last == m["position_end_var"]).OnlyEnforceIf(flag)
                model.Add(last == 0).OnlyEnforceIf(flag.Not())
                first_candidates.append(first)
                last_candidates.append(last)

            # Keep the PREVIOUS hard daily distribution unchanged.
            model.Add(sum(flags) >= low)
            model.Add(sum(flags) <= high)

            # For normal BSIT Years 1-3, low >= 1, so every online day is active.
            # A future curriculum with fewer than three online lessons skips
            # this optional optimization rather than creating an invalid span.
            if low == 0:
                continue

            earliest = model.NewIntVar(0, SLOTS_PER_DAY,
                f"online_earliest_{sec_id}_{d}")
            latest = model.NewIntVar(0, SLOTS_PER_DAY,
                f"online_latest_{sec_id}_{d}")
            model.AddMinEquality(earliest, first_candidates)
            model.AddMaxEquality(latest, last_candidates)

            total_class_slots = sum(
                m["duration"] * present for m, present in zip(online, flags)
            )
            gap = model.NewIntVar(0, SLOTS_PER_DAY,
                f"online_vacant_slots_{sec_id}_{d}")
            model.Add(gap == latest - earliest - total_class_slots)
            online_gap_variables.append(gap)

    # ========================================
    # 17. SOFT OPTIMIZATION
    # ========================================

    # Prefer earlier F2F classes for
    # first-year sections.
    #
    # This is a preference, not a
    # mandatory morning-shift rule.

    first_year_positions = [

        meeting["position_var"]

        for meeting in meetings

        if (

            meeting["mode"] == "F2F"

            and int(

                sections[
                    meeting["section_id"]
                ]["year_level"]

            ) == 1

        )

    ]

    # One combined objective: minimizing empty ONLINE time is the primary
    # SOFT preference. When two timetables have the same total ONLINE gaps,
    # retain the prior preference for earlier first-year F2F classes.
    # One extra ONLINE vacant slot outweighs every possible change to the
    # bounded first-year F2F-position objective. HARD rules still take priority.
    # Feasibility-first production solve.
# Hard constraints remain unchanged.
# Soft optimization is temporarily disabled
# so CP-SAT can find a complete valid timetable faster.

    # ========================================
    # 18. RUN OR-TOOLS OPTIMIZER
    # ========================================

    solver = cp_model.CpSolver()

    solver.parameters.max_time_in_seconds = (
        MAX_SOLVE_SECONDS
    )

    solver.parameters.num_search_workers = int(
        os.getenv("SOLVER_WORKERS", "2")
    )

    # Diagnostic / presolve configuration.
    #
    # These settings do NOT relax any scheduling rule.
    # They only help CP-SAT simplify and diagnose
    # the model before and during search.
    solver.parameters.log_search_progress = True
    solver.parameters.cp_model_presolve = True
    solver.parameters.symmetry_level = 2

    status = solver.Solve(
        model
    )

    solver_status = solver.StatusName(
        status
    )

    # ========================================
    # 18.1 HANDLE NO SOLUTION
    # ========================================

    if status not in (

        cp_model.OPTIMAL,

        cp_model.FEASIBLE,

    ):

        if status == cp_model.INFEASIBLE:

            result_status = (
                "INFEASIBLE"
            )

            message = (
                "The optimizer proved that "
                "the current scheduling "
                "constraints are infeasible."
            )

        elif status == cp_model.MODEL_INVALID:

            result_status = (
                "MODEL_INVALID"
            )

            message = (
                "The optimization model "
                "contains an invalid constraint "
                "or configuration."
            )

        else:

            result_status = (
                "NO_SOLUTION_FOUND"
            )

            message = (
                "The optimizer did not find "
                "a complete feasible timetable "
                "within the available solve time."
            )

        return failure(

            result_status,

            message,

            solver_status=solver_status,

            solve_seconds=solver.WallTime(),

            solver_branches=solver.NumBranches(),

            solver_conflicts=solver.NumConflicts(),

            required_meetings=len(meetings),

            returned_meetings=0,

            school_wide_validation_complete=False,

        )
        

    # ========================================
    # 19. EXTRACT GENERATED ASSIGNMENTS
    # ========================================

    result = []

    for meeting in meetings:

        start_value = solver.Value(

            meeting["start_var"]

        )

        day_index = (

            start_value // SLOTS_PER_DAY

        )

        start_slot = (

            start_value % SLOTS_PER_DAY

        )

        end_slot = (

            start_slot + meeting["duration"]

        )

        section_id = meeting[
            "section_id"
        ]

        teacher_id = solver.Value(

            meeting["teacher_var"]

        )

        room_id = None

        room_name = None

        if meeting["mode"] == "F2F":

            room_id = solver.Value(

                section_room_variables[
                    section_id
                ]

            )

            room_name = rooms[
                room_id
            ]["room_name"]

        # Online meetings have no room.

        result.append({

            "section_subject_id": meeting["section_subject_id"],

            "section_id":
                section_id,

            "section_code":
                meeting["section_code"],

            "section_type":
                sections[
                    section_id
                ]["section_type"],

            "subject_id":
                meeting["subject_id"],

            "subject_code":
                meeting["subject_code"],

            "subject_title":
                meeting["subject_title"],

            "delivery_mode":
                meeting["mode"],

            "day_of_week":
                DAYS[day_index],

            "start_time":
                slot_to_clock(start_slot),

            "end_time":
                slot_to_clock(end_slot),

            "duration_minutes":
                (
                    meeting["duration"]
                    * SLOT_MINUTES
                ),

            "teacher_id":
                teacher_id,

            "teacher_name":
                teachers[
                    teacher_id
                ]["teacher_name"],

            "room_id":
                room_id,

            "room_name":
                room_name,

        })

    # ========================================
    # 20. SORT PREVIEW OUTPUT
    # ========================================

    # Group by section.
    #
    # F2F meetings appear before
    # ONLINE meetings.

    result.sort(

        key=lambda row: (

            row["section_code"],

            0
            if row["delivery_mode"] == "F2F"
            else 1,

            DAY_INDEX[
                row["day_of_week"]
            ],

            row["start_time"],

        )

    )

    # ========================================
    # 21. RETURN UNSAVED PREVIEW
    # ========================================

    return {

        "success": True,

        "status": "DEMO_PREVIEW_GENERATED",

        "solver_status":
            solver_status,

        "solver_objective":
            solver.ObjectiveValue(),

        "solve_seconds":
            solver.WallTime(),

        "solver_branches":
            solver.NumBranches(),

        "solver_conflicts":
            solver.NumConflicts(),

        "sections":
            len(sections),

        "required_meetings":
            len(meetings),

        "returned_meetings":
            len(result),

        "database_write":
            False,

        "school_wide_validation_complete":
            False,

        "fixed_existing_meetings": len(saved_snapshot),
        "existing_snapshot_constraints_applied": True,

        "assignments":
            result,

    }