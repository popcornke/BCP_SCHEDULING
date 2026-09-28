"""Phase 4B: validate immutable saved meeting snapshot; no DB access/writes.

Use once per preview request. Does not replace a final transactionally locked
validation at save time. The same IDs across programs identify shared resources.
"""
from collections import defaultdict

DAYS = ("Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday")
START, END, GRID, SLOTS_PER_DAY = 360, 1260, 30, 30
RESOURCES = ("section_id", "subject_id", "teacher_id", "room_id")


def clock_minutes(value):
    text = str(value)
    parts = text.split(":")
    if len(parts) not in (2, 3) or not all(p.isdecimal() for p in parts):
        raise ValueError(f"Invalid clock time: {text!r}")
    hh, mm = int(parts[0]), int(parts[1])
    ss = int(parts[2]) if len(parts) == 3 else 0
    if not (0 <= hh <= 23 and 0 <= mm <= 59 and ss == 0):
        raise ValueError(f"Invalid clock time: {text!r}")
    return hh * 60 + mm


def overlap(first, second):
    return first["start"] < second["end"] and second["start"] < first["end"]


def normalize_existing(payload):
    """Fail closed on missing, foreign-period, malformed or internally conflicting snapshots."""
    source = payload.get("scheduling_input")
    if not isinstance(source, dict) or not isinstance(source.get("existing_meetings"), list):
        raise ValueError("Saved schedule snapshot is missing: existing_meetings")
    period = int(payload["academic_period"]["academic_period_id"])
    program = int(payload["program"]["program_id"])
    data_origin = str(payload["data_origin"])
    normalized = []
    identifiers = set()
    slots = {
        (s["day_of_week"], clock_minutes(s["start_time"]), clock_minutes(s["end_time"]))
        for s in source.get("time_slots", [])
    }
    for raw in source["existing_meetings"]:
        meeting_id = int(raw["meeting_id"])
        if meeting_id in identifiers:
            raise ValueError(f"Duplicate saved meeting ID: {meeting_id}")
        identifiers.add(meeting_id)
        if int(raw["academic_period_id"]) != period or str(raw["data_origin"]) != data_origin:
            raise ValueError(f"Saved meeting {meeting_id} has a different period or data origin")
        program_id = int(raw["program_id"])
        if program_id == program:
            raise ValueError("Selected program already has an active timetable; explicit replacement is required")
        if (int(raw["section_program_id"]) != program_id
                or int(raw["teacher_program_id"]) != program_id
                or int(raw["subject_program_id"]) != program_id
                or int(raw["section_academic_period_id"]) != period):
            raise ValueError(f"Saved meeting {meeting_id} has a wrong section/teacher/subject/period")
        mode = str(raw["delivery_mode"])
        room_id = None if raw["room_id"] is None else int(raw["room_id"])
        if mode not in ("F2F", "ONLINE") or (mode == "F2F") != (room_id is not None):
            raise ValueError(f"Saved meeting {meeting_id} has an invalid delivery mode/room")
        if room_id is not None:
            owner = raw["room_program_id"]
            if owner is not None and int(owner) != program_id:
                raise ValueError(f"Saved meeting {meeting_id} uses a room owned by another program")
            if int(raw["room_capacity"]) < int(raw["section_student_count"]):
                raise ValueError(f"Saved meeting {meeting_id} exceeds room capacity")
        day = str(raw["day_of_week"])
        start, end = clock_minutes(raw["start_time"]), clock_minutes(raw["end_time"])
        if day not in DAYS or not (START <= start < end <= END) or start % GRID or end % GRID:
            raise ValueError(f"Saved meeting {meeting_id} has invalid day/time or grid alignment")
        for minute in range(start, end, GRID):
            if (day, minute, minute + GRID) not in slots:
                raise ValueError(f"Saved meeting {meeting_id} uses a missing database time slot")
        normalized.append({
            "meeting_id": meeting_id, "batch_id": int(raw["batch_id"]),
            "program_id": program_id, "section_id": int(raw["section_id"]),
            "subject_id": int(raw["subject_id"]), "teacher_id": int(raw["teacher_id"]),
            "room_id": room_id, "day": day, "start": start, "end": end,
        })

    # Reject pre-existing conflicts instead of building a model on a corrupt baseline.
    for field in RESOURCES:
        grouped = defaultdict(list)
        for record in normalized:
            if record[field] is not None:
                grouped[(record[field], record["day"])].append(record)
        for (resource_id, day), rows in grouped.items():
            rows.sort(key=lambda r: (r["start"], r["end"]))
            for position, first in enumerate(rows):
                for second in rows[position + 1:]:
                    if second["start"] >= first["end"]:
                        break
                    if overlap(first, second):
                        raise ValueError(
                            f"Existing {field} conflict ({resource_id}, {day}): "
                            f"saved meetings {first['meeting_id']} and {second['meeting_id']}"
                        )
    return normalized


def occupy_saved_intervals(model, normalized, section_intervals,
                           subject_intervals, teacher_intervals, room_intervals):
    """Append fixed immutable intervals before the existing AddNoOverlap loops."""
    indexes = (section_intervals, subject_intervals, teacher_intervals, room_intervals)
    for row in normalized:
        day_index = DAYS.index(row["day"])
        start = day_index * SLOTS_PER_DAY + (row["start"] - START) // GRID
        duration = (row["end"] - row["start"]) // GRID
        fixed = model.NewIntervalVar(start, duration, start + duration,
                                     f"saved_meeting_{row['meeting_id']}")
        for field, index in zip(RESOURCES, indexes):
            if row[field] is not None:
                index[row[field]].append(fixed)


def cross_program_conflicts(payload, result):
    """Independent preview-vs-snapshot check; does not mutate assignments."""
    saved = normalize_existing(payload)
    errors = []
    by_resource = defaultdict(list)
    for old in saved:
        for field in RESOURCES:
            if old[field] is not None:
                by_resource[(field, old[field], old["day"])].append(old)
    for number, fresh in enumerate(result.get("assignments", []), 1):
        day = str(fresh["day_of_week"])
        start = clock_minutes(fresh["start_time"])
        end = clock_minutes(fresh["end_time"])
        if day not in DAYS or start >= end:
            errors.append(f"Preview meeting {number}: invalid day/time")
            continue
        for field in RESOURCES:
            value = fresh.get(field)
            if value is None:
                continue
            for old in by_resource.get((field, int(value), day), []):
                if start < old["end"] and old["start"] < end:
                    errors.append(
                        f"Preview meeting {number} overlaps saved meeting {old['meeting_id']} "
                        f"on {field}={value}, {day}, {fresh['start_time']}-{fresh['end_time']}"
                    )
    return errors, len(saved)
