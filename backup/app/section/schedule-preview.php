<?php
declare(strict_types=1);

/*
 * BCP Scheduling System
 * Phase 3D - Timetable Preview
 *
 * Frontend only.
 * No database writes.
 * No student or section assignment.
 */
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">

    <meta
        name="viewport"
        content="width=device-width, initial-scale=1"
    >

    <title>BSIT Timetable Preview | BCP</title>

    <link
        rel="stylesheet"
        href="../assets/css/schedule-preview.css"
    >
</head>

<body>

<main class="bcp-preview">

    <div class="bcp-preview-container">

        <!-- HEADER -->

        <header class="bcp-preview-header">

            <div>

                <span class="bcp-preview-eyebrow">
                    BCP CLASS SCHEDULING SYSTEM
                </span>

                <h1>Timetable Preview</h1>

                <p>
                    BSIT · AY 2026–2027 ·
                    First Semester
                </p>

            </div>

            <span class="bcp-preview-demo">
                DEMO ENVIRONMENT
            </span>

        </header>


        <!-- GENERATION CONTROLS -->

        <section class="bcp-preview-panel">

            <div class="bcp-preview-toolbar">

                <div>

                    <h2>Generate Class Schedule</h2>

                    <p>
                        Generate and independently
                        validate the BSIT timetable.
                    </p>

                </div>

                <button
                    type="button"
                    id="bcpGenerateButton"
                    class="bcp-preview-primary"
                >
                    Generate Schedule
                </button>

            </div>

            <div
                id="bcpGenerationStatus"
                class="bcp-preview-status"
                role="status"
                aria-live="polite"
            >
                Ready to generate your timetable.
            </div>

        </section>


        <!-- SUMMARY -->

        <section
            id="bcpSummary"
            class="bcp-preview-summary"
            hidden
        >

            <div class="bcp-preview-stat">

                <span>Sections</span>

                <strong id="bcpSectionCount">
                    0
                </strong>

            </div>

            <div class="bcp-preview-stat">

                <span>Generated Meetings</span>

                <strong id="bcpMeetingCount">
                    0
                </strong>

            </div>

            <div class="bcp-preview-stat">

                <span>Audit Result</span>

                <strong id="bcpAuditStatus">
                    —
                </strong>

            </div>

            <div class="bcp-preview-stat">

                <span>Solving Time</span>

                <strong id="bcpSolveTime">
                    —
                </strong>

            </div>

        </section>


        <!-- FILTER -->

        <section
            id="bcpFilterPanel"
            class="bcp-preview-panel"
            hidden
        >

            <div class="bcp-preview-filter">

                <div>

                    <h2>Section Timetables</h2>

                    <p>
                        Search a section to view
                        its class assignments.
                    </p>

                </div>

                <input
                    type="search"
                    id="bcpSectionSearch"
                    placeholder="Search section, e.g. 11001"
                    aria-label="Search section"
                    autocomplete="off"
                >

            </div>

        </section>


        <!-- GENERATED SCHEDULES -->

        <div
            id="bcpTimetableResults"
            class="bcp-preview-results"
            aria-live="polite"
        ></div>


        <!-- EMPTY STATE -->

        <div
            id="bcpEmptyState"
            class="bcp-preview-empty"
        >

            <div class="bcp-preview-empty-icon">
                ◷
            </div>

            <h2>No timetable generated yet</h2>

            <p>
                Click Generate Schedule to create
                and validate your BSIT timetable.
            </p>

        </div>

    </div>

</main>


<script>
(function () {
    "use strict";

    const DAYS = [
        "Monday",
        "Tuesday",
        "Wednesday",
        "Thursday",
        "Friday",
        "Saturday"
    ];

    const generateButton = document.getElementById(
        "bcpGenerateButton"
    );

    const statusElement = document.getElementById(
        "bcpGenerationStatus"
    );

    const summary = document.getElementById(
        "bcpSummary"
    );

    const filterPanel = document.getElementById(
        "bcpFilterPanel"
    );

    const sectionSearch = document.getElementById(
        "bcpSectionSearch"
    );

    const timetableResults = document.getElementById(
        "bcpTimetableResults"
    );

    const emptyState = document.getElementById(
        "bcpEmptyState"
    );

    let generatedAssignments = [];


    // ========================================
    // CREATE SAFE HTML ELEMENTS
    // ========================================

    function element(tag, className, value) {

        const node = document.createElement(tag);

        if (className) {
            node.className = className;
        }

        if (value !== undefined && value !== null) {
            node.textContent = String(value);
        }

        return node;
    }


    // ========================================
    // FORMAT TIME
    // ========================================

    function formatTime(value) {

        if (!value) {
            return "—";
        }

        const parts = String(value).split(":");

        const hour = Number(parts[0]);
        const minute = Number(parts[1]);

        const period = hour >= 12 ? "PM" : "AM";

        const formattedHour = hour % 12 || 12;

        return (
            formattedHour +
            ":" +
            String(minute).padStart(2, "0") +
            " " +
            period
        );
    }


    function formatTimeRange(row) {

        return (
            formatTime(row.start_time) +
            " – " +
            formatTime(row.end_time)
        );
    }


    // ========================================
    // STATUS MESSAGE
    // ========================================

    function setStatus(message, type) {

        statusElement.textContent = message;

        statusElement.dataset.status = type || "info";
    }


    // ========================================
    // BUILD DAY TABLE
    // ========================================

    function createScheduleTable(assignments) {

        const wrapper = element(
            "div",
            "bcp-preview-table-wrap"
        );

        const table = element(
            "table",
            "bcp-preview-table"
        );

        const thead = element("thead");

        const headerRow = element("tr");

        [
            "Day",
            "Time",
            "Subject",
            "Teacher",
            "Room"
        ].forEach(function (title) {

            headerRow.appendChild(
                element("th", "", title)
            );

        });

        thead.appendChild(headerRow);

        table.appendChild(thead);

        const tbody = element("tbody");

        const sorted = [...assignments].sort(
            function (a, b) {

                const dayDifference =
                    DAYS.indexOf(a.day_of_week) -
                    DAYS.indexOf(b.day_of_week);

                if (dayDifference !== 0) {
                    return dayDifference;
                }

                return a.start_time.localeCompare(
                    b.start_time
                );
            }
        );

        sorted.forEach(function (meeting) {

            const row = element("tr");

            const values = [

                meeting.day_of_week,

                formatTimeRange(meeting),

                meeting.subject_code +
                    " — " +
                    meeting.subject_title,

                meeting.teacher_name,

                meeting.room_name || "Online"

            ];

            values.forEach(function (value) {

                row.appendChild(
                    element("td", "", value)
                );

            });

            tbody.appendChild(row);

        });

        table.appendChild(tbody);

        wrapper.appendChild(table);

        return wrapper;
    }


    // ========================================
    // BUILD SECTION CARD
    // ========================================

    function createSectionCard(
        sectionCode,
        assignments
    ) {

        const card = element(
            "article",
            "bcp-preview-section"
        );

        const heading = element(
            "div",
            "bcp-preview-section-heading"
        );

        const titleGroup = element("div");

        titleGroup.appendChild(
            element(
                "span",
                "bcp-preview-section-label",
                "BSIT SECTION"
            )
        );

        titleGroup.appendChild(
            element(
                "h2",
                "",
                sectionCode
            )
        );

        heading.appendChild(titleGroup);

        const sectionType =
            assignments[0]?.section_type || "REGULAR";

        heading.appendChild(
            element(
                "span",
                "bcp-preview-section-type",
                sectionType
            )
        );

        card.appendChild(heading);


        // F2F schedule

        const f2fMeetings = assignments.filter(
            row => row.delivery_mode === "F2F"
        );

        card.appendChild(
            element(
                "h3",
                "bcp-preview-mode-heading",
                "Face-to-Face Schedule"
            )
        );

        card.appendChild(
            createScheduleTable(f2fMeetings)
        );


        // Online schedule

        const onlineMeetings = assignments.filter(
            row => row.delivery_mode === "ONLINE"
        );

        card.appendChild(
            element(
                "h3",
                "bcp-preview-mode-heading bcp-preview-online-heading",
                "Online Schedule"
            )
        );

        card.appendChild(
            createScheduleTable(onlineMeetings)
        );

        return card;
    }


    // ========================================
    // RENDER GENERATED SCHEDULE
    // ========================================

    function renderSchedules() {

        timetableResults.replaceChildren();

        const keyword = sectionSearch.value
            .trim()
            .toLowerCase();

        const groups = new Map();

        generatedAssignments.forEach(function (row) {

            const code = String(row.section_code);

            if (!code.toLowerCase().includes(keyword)) {
                return;
            }

            if (!groups.has(code)) {
                groups.set(code, []);
            }

            groups.get(code).push(row);

        });

        const sectionCodes = Array.from(
            groups.keys()
        ).sort();

        if (sectionCodes.length === 0) {

            timetableResults.appendChild(
                element(
                    "p",
                    "bcp-preview-no-results",
                    "No matching sections found."
                )
            );

            return;
        }

        sectionCodes.forEach(function (code) {

            timetableResults.appendChild(
                createSectionCard(
                    code,
                    groups.get(code)
                )
            );

        });
    }


    // ========================================
    // GENERATE AND VALIDATE
    // ========================================

    async function generateSchedule() {

        if (generateButton.disabled) {
            return;
        }

        generateButton.disabled = true;

        generateButton.textContent =
            "Generating Schedule...";

        generatedAssignments = [];

        timetableResults.replaceChildren();

        summary.hidden = true;

        filterPanel.hidden = true;

        emptyState.hidden = true;

        sectionSearch.value = "";

        setStatus(
            "Python OR-Tools is generating the timetable. " +
            "This may take approximately two minutes.",
            "loading"
        );

        try {

            const response = await fetch(

                "../api/generate.php?" +
                new URLSearchParams({

                    program: "BSIT",

                    academic_year: "2026-2027",

                    semester: "1"

                }).toString(),

                {
                    method: "GET",
                    cache: "no-store"
                }

            );

            const result = await response.json();

            if (!response.ok || result.success !== true) {

                const auditErrors =
                    result.audit?.errors || [];

                const message =
                    auditErrors.length > 0
                        ? auditErrors.slice(0, 5).join(" | ")
                        : result.message ||
                          result.status ||
                          "Schedule generation failed.";

                throw new Error(message);
            }

            if (
                result.status !== "DEMO_PREVIEW_GENERATED" ||
                result.audit?.passed !== true ||
                !Array.isArray(result.assignments) ||
                result.assignments.length !==
                    result.required_meetings
            ) {

                throw new Error(
                    "Generated schedule did not pass " +
                    "the required preview validation."
                );
            }

            generatedAssignments = result.assignments;

            document.getElementById(
                "bcpSectionCount"
            ).textContent = result.sections;

            document.getElementById(
                "bcpMeetingCount"
            ).textContent =
                result.returned_meetings +
                " / " +
                result.required_meetings;

            document.getElementById(
                "bcpAuditStatus"
            ).textContent =
                result.audit.status;

            document.getElementById(
                "bcpSolveTime"
            ).textContent =
                Number(result.solve_seconds).toFixed(1) +
                "s";

            summary.hidden = false;

            filterPanel.hidden = false;

            setStatus(
                "Timetable generated successfully. " +
                "Independent demo audit passed. " +
                "The schedule has not been saved.",
                "success"
            );

            renderSchedules();

        } catch (error) {

            emptyState.hidden = false;

            setStatus(
                error.message ||
                "Unable to generate timetable.",
                "error"
            );

            console.error(
                "BCP scheduling error:",
                error
            );

        } finally {

            generateButton.disabled = false;

            generateButton.textContent =
                "Generate Schedule";

        }

    }


    // ========================================
    // EVENT HANDLERS
    // ========================================

    generateButton.addEventListener(
        "click",
        generateSchedule
    );

    sectionSearch.addEventListener(
        "input",
        renderSchedules
    );

})();
</script>

</body>
</html>