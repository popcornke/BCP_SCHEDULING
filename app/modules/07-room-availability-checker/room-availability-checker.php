<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/shared/auth.php';

authRequire();

/** Module 7 / DEMO: self-contained, read-only room availability viewer. */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$APP_ROOT = '../../';
$ACTIVE_NAV = 'room_checker';

// Consistent session variables for Topbar
$role = htmlspecialchars($_SESSION['role'] ?? 'Admin', ENT_QUOTES, 'UTF-8');
$username = trim((string) ($_SESSION['auth_username'] ?? $_SESSION['first_name'] ?? 'Admin'));
$initial = strtoupper(substr($username !== '' ? $username : 'A', 0, 1));
$dashboardDate = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Room Availability Checker | BCP</title>
    <link rel="icon" href="../../assets/images/BCP_LOGO.png" type="image/png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <link rel="stylesheet" href="../../assets/css/room-availability-checker.css">
</head>

<body class="bcp-ra-page">

    <?php require_once __DIR__ . '/../../includes/sidebar.php'; ?>

    <div class="main">
        <div class="topbar">
            <button class="hamburger" id="hamburgerBtn" aria-label="Toggle sidebar">
                <i class="fa-solid fa-bars"></i>
            </button>
            <span class="topbar-spacer"></span>
            <div class="topbar-right">
                <span class="role-badge">
                    <i class="fa-solid fa-user-tie" style="color:#1a3a8c;"></i>
                    <?= $role ?>
                </span>
                <a href="../../auth/account.php" class="avatar" title="Account Settings">
                    <?= $initial ?>
                </a>
            </div>
        </div>

        <main class="content bcp-ra" id="raApp">
            <div class="bcp-ra__shell">

                <header class="bcp-ra__header">
                    <div>
                        <p class="bcp-ra__eyebrow"><span class="bcp-ra__eyebrow-dot"></span> BCP CLASS SCHEDULING SYSTEM</p>
                        <h1>Room Availability Checker<span class="bcp-ra__title-dot">.</span></h1>
                        <p>Find a suitable room using current saved timetable records. No room is reserved by a search.</p>
                    </div>
                    <span class="bcp-ra__chip">READ-ONLY · DEMO</span>
                </header>

                <section class="bcp-ra__panel" aria-labelledby="raFiltersTitle">
                    <div class="bcp-ra__section-head">
                        <div>
                            <h2 id="raFiltersTitle">Find a room</h2>
                            <p>Select a date and an actual time interval. Filters update automatically.</p>
                        </div>
                    </div>

                    <form id="raForm" class="bcp-ra__controls">
                        <div class="bcp-ra__control">
                            <label for="raPeriod">Academic period</label>
                            <select id="raPeriod" required>
                                <option value="">Loading…</option>
                            </select>
                        </div>
                        <div class="bcp-ra__control">
                            <label for="raProgram">Program</label>
                            <select id="raProgram">
                                <option value="0">All programs</option>
                            </select>
                        </div>
                        <div class="bcp-ra__control">
                            <label for="raDate">Date</label>
                            <input id="raDate" type="date" required>
                        </div>
                        <div class="bcp-ra__control">
                            <label for="raStart">Start time</label>
                            <input id="raStart" type="time" step="1800" value="08:00" required>
                        </div>
                        <div class="bcp-ra__control">
                            <label for="raEnd">End time</label>
                            <input id="raEnd" type="time" step="1800" value="10:00" required>
                        </div>
                        <div class="bcp-ra__control">
                            <label for="raCapacity">Minimum seats</label>
                            <input id="raCapacity" type="number" min="0" max="10000" step="1" value="0">
                        </div>
                        <div class="bcp-ra__control">
                            <label for="raType">Room type</label>
                            <select id="raType">
                                <option value="">All room types</option>
                                <option value="GENERAL">General</option>
                                <option value="LABORATORY">Laboratory</option>
                                <option value="SPECIALIZED">Specialized</option>
                            </select>
                        </div>
                        <div class="bcp-ra__control">
                            <label for="raDisplay">Display</label>
                            <select id="raDisplay">
                                <option value="ALL">All rooms</option>
                                <option value="AVAILABLE">Available only</option>
                                <option value="OCCUPIED">Occupied only</option>
                                <option value="UNAVAILABLE">Unavailable / unconfigured</option>
                                <option value="NOT_ELIGIBLE">Not eligible for request</option>
                            </select>
                        </div>
                    </form>

                    <div class="bcp-ra__filter-actions">
                        <div class="bcp-ra__search-box">
                            <i class="fa-solid fa-magnifying-glass"></i>
                            <input id="raSearch" type="search" placeholder="Search room or building e.g. BSIT-201" maxlength="70">
                        </div>
                        <button type="button" id="raPrint" class="bcp-ra__btn-secondary" disabled><i class="fa-solid fa-print"></i> Print report</button>
                    </div>

                    <div id="raStatus" role="status" aria-live="polite" class="bcp-ra__status">
                        <i class="fa-solid fa-circle-notch fa-spin bcp-ra__status-icon"></i> Loading saved scheduling data…
                    </div>
                </section>

                <section class="bcp-ra__panel" aria-labelledby="raResultsTitle">
                    <div class="bcp-ra__results-head">
                        <div>
                            <h2 id="raResultsTitle">Room availability</h2>
                            <p id="raSubtitle">Select a date and time to view current occupancy.</p>
                        </div>
                        <span class="bcp-ra__chip" id="raCount">—</span>
                    </div>

                    <div class="bcp-ra__summary">
                        <div class="bcp-ra__summary-card"><span>Available</span><strong id="raAvailable">—</strong></div>
                        <div class="bcp-ra__summary-card"><span>Occupied</span><strong id="raOccupied">—</strong></div>
                        <div class="bcp-ra__summary-card"><span>Unavailable</span><strong id="raUnavailable">—</strong></div>
                        <div class="bcp-ra__summary-card"><span>Not eligible</span><strong id="raNotEligible">—</strong></div>
                    </div>

                    <div id="raRows" class="bcp-ra__rooms">
                        <div class="bcp-ra__empty">Checking current room records…</div>
                    </div>

                    <p class="bcp-ra__disclaimer">As-recorded availability only. Regular classes repeat by weekday; examination and saved special-class bookings use exact dates. This module does not verify approved teaching dates, holidays, cancelled classes, or unsaved previews. No database changes.</p>
                </section>
            </div>

            <!-- OFFICIAL PRINT REPORT (Hidden sa screen) -->
            <div id="raPrintDocument" class="bcp-ra-print-document">
                <div class="bcp-ra-print-header">
                    <img src="../../assets/images/BCP_LOGO.png" alt="BCP Logo" class="bcp-ra-print-logo">
                    <h3>BESTLINK COLLEGE OF THE PHILIPPINES</h3>
                    <h1>Room Availability Report</h1>
                    <div class="bcp-ra-print-meta">
                        <span><strong>Period:</strong> <span id="printPeriod">—</span></span>
                        <span><strong>Date & Time:</strong> <span id="printDateTime">—</span></span>
                        <span><strong>Generated:</strong> <span><?= htmlspecialchars($dashboardDate->format('M j, Y · g:i A')) ?></span></span>
                    </div>
                </div>
                <div class="bcp-ra-print-summary">
                    <span><strong>Available:</strong> <span id="printAvailable">—</span></span>
                    <span><strong>Occupied:</strong> <span id="printOccupied">—</span></span>
                    <span><strong>Unavailable:</strong> <span id="printUnavailable">—</span></span>
                </div>
                <table class="bcp-ra-print-table">
                    <thead>
                        <tr>
                            <th>Room & Type</th>
                            <th>Building & Capacity</th>
                            <th>Status</th>
                            <th>Conflict Notes</th>
                        </tr>
                    </thead>
                    <tbody id="printTableBody">
                        <!-- Javascript populate -->
                    </tbody>
                </table>
                <div class="bcp-ra-print-footer">
                    <span>Prepared by: ________________________</span>
                    <span>Checked by: ________________________</span>
                    <span>Approved by: ________________________</span>
                </div>
            </div>
        </main>

        <div class="footer">
            Scheduling System &copy; <?= $dashboardDate->format('Y') ?> Bestlink College of the Philippines
        </div>
    </div>

    <script>
        (() => {
            'use strict';

            // SIDEBAR TOGGLE
            const hamburgerBtn = document.getElementById('hamburgerBtn');
            const sidebar = document.getElementById('sidebar');
            if (hamburgerBtn && sidebar) {
                hamburgerBtn.addEventListener('click', () => {
                    sidebar.classList.toggle('collapsed');
                });
            }

            const $ = id => document.getElementById(id);
            let catalog = null,
                report = null,
                requestNo = 0,
                timer = null;

            /* --- PREMIUM CUSTOM SELECT DROPDOWN LOGIC --- */
            function upgradeSelects() {
                document.querySelectorAll('.bcp-ra__control select').forEach(selectElem => {
                    if (selectElem.parentElement.classList.contains('bcp-custom-select-initialized')) return;

                    selectElem.style.display = 'none';

                    const wrapper = document.createElement("div");
                    wrapper.className = "bcp-custom-select-wrapper bcp-custom-select-initialized";
                    selectElem.parentNode.insertBefore(wrapper, selectElem);
                    wrapper.appendChild(selectElem);

                    const trigger = document.createElement("div");
                    trigger.className = "bcp-custom-select-trigger";

                    const triggerText = document.createElement("span");
                    triggerText.className = "bcp-custom-select-text";

                    const arrow = document.createElement("i");
                    arrow.className = "fa-solid fa-chevron-down bcp-custom-select-arrow";
                    trigger.append(triggerText, arrow);

                    const optionsList = document.createElement("div");
                    optionsList.className = "bcp-custom-select-options";
                    wrapper.append(trigger, optionsList);

                    function sync() {
                        optionsList.innerHTML = '';
                        wrapper.classList.toggle('is-disabled', selectElem.disabled);

                        if (selectElem.options.length === 0) {
                            triggerText.textContent = "Loading...";
                            return;
                        }

                        let selectedLabel = "";
                        Array.from(selectElem.options).forEach(opt => {
                            if (opt.selected) selectedLabel = opt.text;
                            const item = document.createElement("div");
                            item.className = "bcp-custom-select-option";
                            item.textContent = opt.text;
                            if (opt.selected) item.classList.add('is-selected');

                            item.addEventListener('click', (e) => {
                                e.stopPropagation();
                                selectElem.value = opt.value;
                                selectElem.dispatchEvent(new Event('change'));
                                closeAllCustomSelects();
                            });
                            optionsList.appendChild(item);
                        });
                        triggerText.textContent = selectedLabel || "Select an option";
                    }

                    const observer = new MutationObserver(sync);
                    observer.observe(selectElem, {
                        childList: true,
                        attributes: true,
                        attributeFilter: ['disabled']
                    });
                    selectElem.addEventListener('change', sync);

                    trigger.addEventListener('click', (e) => {
                        if (selectElem.disabled) return;
                        e.stopPropagation();
                        const isOpen = optionsList.classList.contains('is-open');
                        closeAllCustomSelects();
                        if (!isOpen) {
                            optionsList.classList.add('is-open');
                            trigger.classList.add('is-active');
                            const selected = optionsList.querySelector('.is-selected');
                            if (selected) optionsList.scrollTop = selected.offsetTop - 10;
                        }
                    });
                    sync();
                });
            }

            function closeAllCustomSelects() {
                document.querySelectorAll('.bcp-custom-select-options').forEach(elem => elem.classList.remove('is-open'));
                document.querySelectorAll('.bcp-custom-select-trigger').forEach(elem => elem.classList.remove('is-active'));
            }
            document.addEventListener('click', closeAllCustomSelects);
            /* ------------------------------------------- */

            // HELPERS
            function status(message, bad = false) {
                const s = $('raStatus');
                s.dataset.state = bad ? 'error' : 'normal';
                s.innerHTML = `<i class="fa-solid ${bad ? 'fa-triangle-exclamation' : 'fa-circle-info'} bcp-ra__status-icon"></i> ${message}`;
            }

            const formatDate = () => {
                const d = new Date();
                return [
                    d.getFullYear(),
                    String(d.getMonth() + 1).padStart(2, '0'),
                    String(d.getDate()).padStart(2, '0')
                ].join('-');
            };

            const el = (name, text, cls) => {
                const n = document.createElement(name);
                if (text !== undefined) n.textContent = String(text);
                if (cls) n.className = cls;
                return n;
            };

            // API CALL
            async function api(params, signal) {
                const url = new URL('room-availability-api.php', location.href);
                for (const [key, val] of Object.entries(params)) {
                    url.searchParams.set(key, String(val));
                }

                const res = await fetch(url, {
                    signal,
                    cache: 'no-store',
                    credentials: 'same-origin',
                    headers: {
                        Accept: 'application/json'
                    }
                });

                let data;
                try {
                    data = await res.json();
                } catch (err) {
                    throw Error('PHP did not return JSON. Check the Apache error log.');
                }

                if (!res.ok || !data.success) {
                    throw Error(data.message || data.status || 'Room availability query failed.');
                }
                return data;
            }

            function options(select, items, id, label, first) {
                select.replaceChildren();
                if (first) {
                    select.add(new Option(first.label, first.value));
                }
                for (const item of items) {
                    select.add(new Option(label(item), String(item[id])));
                }
                select.dispatchEvent(new Event('change'));
            }

            function query() {
                return {
                    action: 'check',
                    period_id: $('raPeriod').value,
                    program_id: $('raProgram').value,
                    date: $('raDate').value,
                    start_time: $('raStart').value,
                    end_time: $('raEnd').value,
                    capacity: $('raCapacity').value || '0',
                    room_type: $('raType').value
                };
            }

            function valid(q) {
                if (!q.period_id || !q.date || !q.start_time || !q.end_time) {
                    status('Select academic period, date and both times.', true);
                    return false;
                }
                if (q.start_time >= q.end_time || q.start_time < '06:00' || q.end_time > '21:00') {
                    status('Choose a valid interval inside 6:00 AM–9:00 PM.', true);
                    return false;
                }
                if (Number(q.capacity) < 0 || Number(q.capacity) > 10000) {
                    status('Enter a valid minimum capacity.', true);
                    return false;
                }
                return true;
            }

            function issueText(room) {
                const pieces = [
                    ...(room.eligibility_notes || []),
                    ...(room.issues || []).map(i => i.message)
                ];
                return [...new Set(pieces)].join(' ');
            }

            function visible(room) {
                const selection = $('raDisplay').value;
                if (selection === 'AVAILABLE' && room.availability !== 'AVAILABLE') return false;
                if (selection === 'OCCUPIED' && room.availability !== 'OCCUPIED') return false;
                if (selection === 'UNAVAILABLE' && !['UNAVAILABLE', 'UNCONFIGURED'].includes(room.availability)) return false;
                if (selection === 'NOT_ELIGIBLE' && room.availability !== 'NOT_ELIGIBLE') return false;

                const search = $('raSearch').value.trim().toLowerCase();
                if (!search) return true;

                return [room.room_name, room.building, room.program_code, room.room_type].some(x => {
                    return String(x || '').toLowerCase().includes(search);
                });
            }

            // RENDER FUNCTION
            function render() {
                if (!report) return;
                const rows = report.rooms.filter(visible);

                $('raCount').textContent = `${rows.length} of ${report.rooms.length} rooms`;
                $('raRows').replaceChildren();

                if (rows.length === 0) {
                    $('raRows').append(el('div', 'No rooms match the current filters. Try another time, capacity or program.', 'bcp-ra__empty'));
                    return;
                }

                for (const r of rows) {
                    const card = el('article', undefined, 'bcp-ra__room-card');
                    const top = el('div', undefined, 'bcp-ra__room-top');
                    const meta = el('div');

                    const buildingText = r.building || 'Building not set';
                    const programText = r.program_code || 'Shared room';

                    meta.append(
                        el('strong', r.room_name),
                        el('p', [buildingText, programText, r.room_type, `${r.capacity} seats`].join(' · '))
                    );

                    const availClass = 'bcp-ra__state bcp-ra__state--' + r.availability.toLowerCase();
                    top.append(meta, el('span', r.availability.replaceAll('_', ' '), availClass));
                    card.append(top);

                    const notes = issueText(r);
                    if (notes) {
                        card.append(el('p', notes, 'bcp-ra__note'));
                    }

                    if (r.bookings && r.bookings.length > 0) {
                        const detail = document.createElement('details');
                        detail.className = 'bcp-ra__details';
                        const sum = document.createElement('summary');
                        sum.textContent = `${r.bookings.length} overlapping saved booking(s)`;
                        detail.append(sum);

                        const ul = document.createElement('ul');
                        for (const b of r.bookings) {
                            const progText = b.program_code || 'Unknown program';
                            const typeText = b.type.replaceAll('_', ' ');
                            ul.append(el('li', `${typeText} #${b.reference_id} · ${progText} · ${b.start_time}–${b.end_time}`));
                        }
                        detail.append(ul);
                        card.append(detail);
                    }
                    $('raRows').append(card);
                }
            }

            let controller = null;
            async function check() {
                const q = query();
                if (!valid(q)) {
                    report = null;
                    $('raPrint').disabled = true;
                    $('raRows').replaceChildren(el('div', 'Fix the date/time inputs and retry.', 'bcp-ra__empty'));
                    return;
                }

                const seq = ++requestNo;
                if (controller) controller.abort();
                controller = new AbortController();

                $('raStatus').dataset.state = 'normal';
                $('raStatus').innerHTML = `<i class="fa-solid fa-circle-notch fa-spin bcp-ra__status-icon"></i> Checking ACTIVE class, exam, special-class and room records…`;
                $('raPrint').disabled = true;

                try {
                    const data = await api(q, controller.signal);
                    if (seq !== requestNo) return;
                    report = data;

                    $('raAvailable').textContent = data.summary.available;
                    $('raOccupied').textContent = data.summary.occupied;

                    const unconf = data.summary.unconfigured || 0;
                    $('raUnavailable').textContent = data.summary.unavailable + unconf;
                    $('raNotEligible').textContent = data.summary.not_eligible;

                    $('raSubtitle').textContent = `${data.date} · ${data.day_of_week} · ${data.start_time}–${data.end_time} · ${data.period.academic_year}, Semester ${data.period.semester}`;

                    status(`Checked ${data.summary.total} rooms. Available: ${data.summary.available}. Not reserved by this search.`);
                    $('raPrint').disabled = false;
                    render();

                } catch (e) {
                    if (seq !== requestNo || e.name === 'AbortError') return;
                    report = null;
                    status(e.message, true);
                    $('raRows').replaceChildren(el('div', e.message, 'bcp-ra__empty'));
                }
            }

            function schedule() {
                clearTimeout(timer);
                timer = setTimeout(check, 220);
            }

            async function init() {
                try {
                    catalog = await api({
                        action: 'catalog'
                    });

                    options($('raPeriod'), catalog.periods, 'academic_period_id', x => `${x.academic_year} · Semester ${x.semester} (${x.period_status})`);
                    options($('raProgram'), catalog.programs, 'program_id', x => `${x.program_code} — ${x.program_name}`, {
                        label: 'All programs',
                        value: '0'
                    });
                    upgradeSelects();

                    $('raDate').value = formatDate();
                    $('raForm').addEventListener('submit', e => e.preventDefault());

                    const inputs = ['raPeriod', 'raProgram', 'raDate', 'raStart', 'raEnd', 'raCapacity', 'raType'];
                    for (const id of inputs) {
                        $(id).addEventListener('input', schedule);
                        $(id).addEventListener('change', schedule);
                    }

                    const filters = ['raDisplay', 'raSearch'];
                    for (const id of filters) {
                        $(id).addEventListener('input', render);
                        $(id).addEventListener('change', render);
                    }

                    // PRINT LOGIC
                    $('raPrint').addEventListener('click', () => {
                        if (!report) {
                            status('No report generated yet to print.', true);
                            return;
                        }
                        const rows = report.rooms.filter(visible);
                        if (rows.length === 0) {
                            status('No visible rooms to print.', true);
                            return;
                        }

                        const per = $('raPeriod');
                        let selectedText = '—';
                        if (per.options && per.selectedIndex >= 0) {
                            selectedText = per.options[per.selectedIndex].text;
                        }

                        $('printPeriod').textContent = selectedText;
                        $('printDateTime').textContent = `${$('raDate').value} (${$('raStart').value} - ${$('raEnd').value})`;

                        $('printAvailable').textContent = report.summary.available;
                        $('printOccupied').textContent = report.summary.occupied;

                        const unconf = report.summary.unconfigured || 0;
                        $('printUnavailable').textContent = report.summary.unavailable + unconf;

                        const tbody = $('printTableBody');
                        tbody.replaceChildren();

                        for (const r of rows) {
                            const tr = document.createElement('tr');

                            const td1 = document.createElement('td');
                            td1.innerHTML = `<strong>${r.room_name}</strong><br>${r.room_type}`;

                            const td2 = document.createElement('td');
                            td2.innerHTML = `${r.building || 'N/A'}<br>Capacity: ${r.capacity}`;

                            const td3 = document.createElement('td');
                            td3.textContent = r.availability.replaceAll('_', ' ');

                            const td4 = document.createElement('td');
                            td4.textContent = issueText(r) || '—';

                            tr.append(td1, td2, td3, td4);
                            tbody.appendChild(tr);
                        }
                        window.print();
                    });

                    check();

                } catch (e) {
                    status(e.message, true);
                    $('raRows').replaceChildren(el('div', e.message, 'bcp-ra__empty'));
                }
            }

            init();
        })();
    </script>
</body>

</html>