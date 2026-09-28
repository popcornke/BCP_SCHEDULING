"""Phase 3C independent, reject-only audit for BSIT DEMO timetable previews.

Does not call OR-Tools, modify assignments, or write to a database.
An AUDIT_PASSED result is NOT school-wide saved-schedule clearance.
"""
from collections import Counter, defaultdict
from app.existing_schedules import cross_program_conflicts

DAYS = ("Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday")
MWF = {"Monday", "Wednesday", "Friday"}
TTHS = {"Tuesday", "Thursday", "Saturday"}
DAY_START, DAY_END, SLOT = 360, 1260, 30


def minutes(value):
    parts = str(value).split(":")
    if len(parts) not in (2, 3):
        raise ValueError(f"Invalid clock time: {value}")
    h, m = int(parts[0]), int(parts[1])
    if h < 0 or h > 23 or m < 0 or m > 59:
        raise ValueError(f"Invalid clock time: {value}")
    return h * 60 + m


def overlapping(a, b):
    return a["start"] < b["end"] and b["start"] < a["end"]


def available(windows, day, start, end):
    allowed = False
    for row in windows:
        if row["day_of_week"] != day:
            continue
        a, b = minutes(row["start_time"]), minutes(row["end_time"])
        if row["availability_status"] == "UNAVAILABLE" and start < b and a < end:
            return False
        if row["availability_status"] == "AVAILABLE" and a <= start and end <= b:
            allowed = True
    return allowed


def allowed_days(section, mode):
    code = str(section["section_code"])
    if len(code) != 5 or not code.isascii() or not code.isdigit() or int(code[-2:]) < 1:
        raise ValueError(f"Invalid demo section code {code}")
    if mode not in ("F2F", "ONLINE"):
        raise ValueError(f"Invalid delivery mode {mode}")
    # This exception is BSIT-only; other programs require their own confirmed policy.
    if section["section_type"] == "MAJOR" and int(section["year_level"]) == 4:
        return set(DAYS)
    odd = int(code[-2:]) % 2 == 1
    f2f = MWF if odd else TTHS
    return f2f if mode == "F2F" else set(DAYS) - f2f


def _audit(payload, result):
    errors = []
    data = payload["scheduling_input"]
    program = payload.get("program", {})
    if program.get("program_code") != "BSIT" or payload.get("data_origin") != "DEMO":
        return ["This audit is configured for BSIT DEMO inputs only."], 0, 0
    sections = {int(s["section_id"]): s for s in data["sections"]}
    teachers = {int(t["teacher_id"]): t for t in data["teachers"]}
    rooms = {int(r["room_id"]): r for r in data["rooms"]}
    subject_rows = data["section_subjects"]
    required = {}
    assignment_ids = set()
    for entry in subject_rows:
        section_id, subject_id = int(entry["section_id"]), int(entry["subject_id"])
        sid = int(entry["section_subject_id"])
        if sid in assignment_ids or (section_id, subject_id) in required:
            errors.append(f"Duplicate required section-subject assignment: {section_id}/{subject_id}")
        assignment_ids.add(sid)
        required[section_id, subject_id] = entry
        if section_id not in sections:
            errors.append(f"Required subject {subject_id} references unknown section {section_id}")
    expected = Counter(
        (int(e["section_id"]), int(e["subject_id"]), mode)
        for e in subject_rows
        for mode, key in (("F2F", "f2f_hours"), ("ONLINE", "online_hours"))
        if float(e[key]) > 0
    )
    auth = {(int(a["teacher_id"]), int(a["subject_id"])) for a in data["authorizations"]}
    teacher_windows = defaultdict(list)
    room_windows = defaultdict(list)
    for w in data["teacher_availability"]:
        teacher_windows[int(w["teacher_id"])].append(w)
    for w in data["room_availability"]:
        room_windows[int(w["room_id"])].append(w)
    slots = {(r["day_of_week"], minutes(r["start_time"]), minutes(r["end_time"]), r["day_pattern"])
             for r in data["time_slots"]}
    observed = Counter()
    normalized = []
    rooms_by_section = defaultdict(set)
    teacher_for_subject = defaultdict(set)
    teacher_daily = defaultdict(int)
    teacher_weekly = defaultdict(int)
    for index, raw in enumerate(result.get("assignments", []), 1):
        try:
            section_id, subject_id = int(raw["section_id"]), int(raw["subject_id"])
            teacher_id = int(raw["teacher_id"])
            mode, day = raw["delivery_mode"], raw["day_of_week"]
            start, end, duration = minutes(raw["start_time"]), minutes(raw["end_time"]), int(raw["duration_minutes"])
            key = (section_id, subject_id, mode)
            label = f"Meeting {index} (section {section_id}, subject {subject_id}, {mode}, {day})"
            observed[key] += 1
            if section_id not in sections or teacher_id not in teachers or key not in expected or day not in DAYS:
                errors.append(f"{label}: unknown section, teacher, subject assignment, mode or day")
                continue
            section = sections[section_id]
            if int(teachers[teacher_id]["program_id"]) != int(section["program_id"]):
                errors.append(f"{label}: teacher belongs to another program")
            if raw.get("section_code") != section["section_code"] or raw.get("subject_code") != required[section_id, subject_id]["subject_code"]:
                errors.append(f"{label}: incorrect section/subject code")
            if mode not in ("F2F", "ONLINE") or day not in allowed_days(section, mode):
                errors.append(f"{label}: wrong delivery day for section policy")
            if start < DAY_START or end > DAY_END or end <= start or end - start != duration:
                errors.append(f"{label}: invalid duration or operating hours")
                continue
            expected_minutes = round(float(required[section_id, subject_id]["f2f_hours" if mode == "F2F" else "online_hours"]) * 60)
            if duration != expected_minutes:
                errors.append(f"{label}: duration differs from database requirement")
            if start % SLOT or end % SLOT:
                errors.append(f"{label}: not aligned to database 30-minute slots")
            else:
                pattern = "MWF" if day in MWF else "TTHS"
                for t in range(start, end, SLOT):
                    if (day, t, t + SLOT, pattern) not in slots:
                        errors.append(f"{label}: missing consecutive database time slot at {t}")
                        break
            if (teacher_id, subject_id) not in auth:
                errors.append(f"{label}: teacher not authorized for this subject")
            if not available(teacher_windows[teacher_id], day, start, end):
                errors.append(f"{label}: teacher unavailable")
            room_id = raw.get("room_id")
            if mode == "ONLINE":
                if room_id is not None or raw.get("room_name") is not None:
                    errors.append(f"{label}: online meeting must have no physical room")
            else:
                if room_id is None or int(room_id) not in rooms:
                    errors.append(f"{label}: F2F meeting has no eligible room")
                else:
                    room_id = int(room_id)
                    room = rooms[room_id]
                    rooms_by_section[section_id].add(room_id)
                    if raw.get("room_name") != room["room_name"]:
                        errors.append(f"{label}: room ID/name mismatch")
                    if int(room["capacity"]) < int(section["student_count"]):
                        errors.append(f"{label}: insufficient room capacity")
                    if room.get("program_id") is not None and int(room["program_id"]) != int(section["program_id"]):
                        errors.append(f"{label}: room restricted to another program")
                    if not available(room_windows[room_id], day, start, end):
                        errors.append(f"{label}: room unavailable")
            teacher_for_subject[section_id, subject_id].add(teacher_id)
            teacher_daily[teacher_id, day] += duration
            teacher_weekly[teacher_id] += duration
            normalized.append(dict(section_id=section_id, subject_id=subject_id, teacher_id=teacher_id,
                                   room_id=room_id, mode=mode, day=day, start=start, end=end,
                                   label=label))
        except (TypeError, ValueError, KeyError, AttributeError) as exc:
            errors.append(f"Meeting {index}: malformed assignment ({exc})")

    for key in sorted(set(expected) | set(observed)):
        if observed[key] != expected[key]:
            errors.append(f"Section {key[0]} subject {key[1]} {key[2]}: expected {expected[key]}, found {observed[key]}")
    if result.get("required_meetings") != sum(expected.values()) or result.get("returned_meetings") != len(result.get("assignments", [])):
        errors.append("Solver-reported meeting counts do not match the actual requirements/output")
    for key, ts in teacher_for_subject.items():
        if len(ts) > 1:
            errors.append(f"Section {key[0]} subject {key[1]}: different F2F/Online teachers")
    for sec, rs in rooms_by_section.items():
        if len(rs) > 1:
            errors.append(f"Section {sec}: inconsistent F2F room")
    for teacher_id, teacher in teachers.items():
        if teacher_weekly[teacher_id] > round(float(teacher["max_weekly_hours"]) * 60):
            errors.append(f"Teacher {teacher_id}: weekly workload exceeded")
        for day in DAYS:
            if teacher_daily[teacher_id, day] > round(float(teacher["max_daily_hours"]) * 60):
                errors.append(f"Teacher {teacher_id} {day}: daily workload exceeded")

    # Four independent overlap indexes: other sections CAN teach different subjects simultaneously.
    for resource, field in (("SECTION", "section_id"), ("SUBJECT", "subject_id"),
                            ("TEACHER", "teacher_id"), ("ROOM", "room_id")):
        index = defaultdict(list)
        for row in normalized:
            if row[field] is not None:
                index[row[field], row["day"]].append(row)
        for (resource_id, day), rows in index.items():
            rows.sort(key=lambda r: (r["start"], r["end"]))
            for a, b in zip(rows, rows[1:]):
                if overlapping(a, b):
                    errors.append(f"{resource} overlap {resource_id} on {day}: {a['label']} vs {b['label']}")

    per_section = defaultdict(list)
    for row in normalized:
        per_section[row["section_id"]].append(row)
    for section_id, section in sections.items():
        rows = per_section[section_id]
        year = int(section["year_level"])
        kind = section["section_type"]
        f2f_days = allowed_days(section, "F2F")
        online_days = allowed_days(section, "ONLINE")
        f2f = [r for r in rows if r["mode"] == "F2F"]
        online = [r for r in rows if r["mode"] == "ONLINE"]
        if kind == "MAJOR" and year == 4:
            if {r["day"] for r in f2f} & {r["day"] for r in online}:
                errors.append(f"Major section {section['section_code']}: mixed F2F/Online day")
        if year in (1, 2, 3) and kind == "REGULAR":
            counts = [sum(r["day"] == day for r in online) for day in DAYS if day in online_days]
            low, high = divmod(len(online), len(online_days))
            if any(n < low or n > low + (1 if high else 0) for n in counts):
                errors.append(f"Section {section['section_code']}: unbalanced online distribution {counts}")
        for day in f2f_days:
            daily = sorted((r for r in f2f if r["day"] == day), key=lambda r: r["start"])
            count = len(daily)
            if kind == "REGULAR" and year == 1 and count != 3:
                errors.append(f"Section {section['section_code']} {day}: first-year F2F count {count}, expected 3")
            if kind == "REGULAR" and year == 2 and count not in (2, 3):
                errors.append(f"Section {section['section_code']} {day}: second-year F2F count {count}, expected 2 or 3")
            if kind == "REGULAR" and year == 3 and count not in (0, 3):
                errors.append(f"Section {section['section_code']} {day}: third-year F2F count {count}, expected 0 or 3")
            if kind == "CLUSTER" and year == 4 and count not in (0, len(f2f)):
                errors.append(f"Cluster {section['section_code']} {day}: cluster subjects must be on one F2F day")
            if kind == "MAJOR" and count > 1:
                errors.append(f"Major {section['section_code']} {day}: more than one major F2F subject")
            if daily:
                span = daily[-1]["end"] - daily[0]["start"]
                total = sum(r["end"] - r["start"] for r in daily)
                expected_gap = 30 if kind == "REGULAR" and year in (1, 2, 3) and count == 3 else 0
                if span != total + expected_gap:
                    errors.append(f"Section {section['section_code']} {day}: F2F break/gap policy violated")

    # Linked Cluster/Major shares students: both time overlap and mixed mode SAME DAY are forbidden.
    for link in data["major_links"]:
        home_id, major_id = int(link["home_section_id"]), int(link["major_section_id"])
        if home_id not in sections or major_id not in sections:
            errors.append(f"Invalid Cluster/Major link {home_id}->{major_id}")
            continue
        if sections[home_id]["section_type"] != "CLUSTER" or sections[major_id]["section_type"] != "MAJOR":
            errors.append(f"Invalid Cluster/Major section types {home_id}->{major_id}")
        for home in per_section[home_id]:
            for major in per_section[major_id]:
                if home["day"] != major["day"]:
                    continue
                if overlapping(home, major):
                    errors.append(f"Shared-student overlap: {home['label']} vs {major['label']}")
                if home["mode"] != major["mode"]:
                    errors.append(f"Shared-student mixed delivery day: {home['label']} vs {major['label']}")
    # Phase 4B independent comparison against the persisted timetable snapshot.
    saved_errors, saved_count = cross_program_conflicts(payload, result)
    errors.extend(saved_errors)
    if result.get("fixed_existing_meetings") != saved_count or not result.get("existing_snapshot_constraints_applied"):
        errors.append("Saved schedule snapshot was not fully applied to optimizer")
    return list(dict.fromkeys(errors)), sum(expected.values()), len(result.get("assignments", []))


def audit_schedule(payload, result):
    """Fail closed on malformed inputs or errors; never silently approve a preview."""
    try:
        errors, required, returned = _audit(payload, result)
    except Exception as exc:
        errors = [f"Audit could not complete: {type(exc).__name__}: {exc}"]
        required, returned = 0, len(result.get("assignments", []))
    return {
        "passed": not errors,
        "status": "AUDIT_FAILED" if errors else "AUDIT_PASSED",
        "required_meetings": required,
        "returned_meetings": returned,
        "total_conflicts": len(errors),
        "errors": errors,
        "school_wide_validation_complete": False,
        "database_write": False,
    }
