<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/shared/auth.php';

authRequire();
/** Module 9 Phase 9A: time-slot inventory, not an edit or save UI. */

$APP_ROOT = '../../';
$ACTIVE_NAV = 'time_blocks';

// Safe variables for Topbar
$role = htmlspecialchars($_SESSION['role'] ?? 'Admin', ENT_QUOTES, 'UTF-8');
$initial = strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1));
$dashboardDate = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
?>
<!doctype html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>Time Block Customizer | BCP</title>
    <link rel="icon" href="../../images/BCP_LOGO.png" type="image/png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

    <!-- Naka-cache buster para kagat agad ang bagong CSS layout -->
    <link rel="stylesheet" href="../../assets/css/time-block-customizer.css?v=<?= time() ?>">
</head>

<body>

    <?php require_once __DIR__ . '/../../includes/sidebar.php'; ?>

    <div class="main">
        <!-- TOPBAR COMPONENT -->
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

        <!-- MAIN CONTENT -->
        <main class="content tb-app" id="tbApp">
            <header class="tb-hero">
                <div>
                    <p class="tb-kicker">BCP CLASS SCHEDULING SYSTEM · MODULE 9</p>
                    <h1>Time Block Customizer</h1>
                    <p>Review the exact database time slots before any changes to school scheduling hours.</p>
                </div>
                <span class="tb-pill">READ-ONLY · PHASE 9A</span>
            </header>

            <section class="tb-alert" role="note">
                <strong>Existing schedules are protected.</strong> Editing, disabling and deleting time slots are not enabled. This inventory does not change the source timetable, class batches or examination batches.
            </section>

            <section class="tb-panel">
                <div class="tb-heading">
                    <div>
                        <h2>Time-slot inventory</h2>
                        <p>All time values shown below come directly from <code>time_slots</code>.</p>
                    </div>
                    <button id="tbPrint" type="button" disabled>Print inventory</button>
                </div>

                <div class="tb-stats" id="tbStats" aria-live="polite">
                    <div><span>Loading</span><strong>…</strong></div>
                </div>

                <div class="tb-filters">
                    <label>Day
                        <select id="tbDay" disabled>
                            <option value="">All days</option>
                        </select>
                    </label>
                    <label>Record status
                        <select id="tbState" disabled>
                            <option value="">All statuses</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </label>
                    <label>Find time slot
                        <input id="tbSearch" type="search" placeholder="Search by time, day, or ID" disabled>
                    </label>
                </div>

                <p class="tb-status" id="tbStatus" role="status">Loading saved database time slots…</p>

                <div class="tb-table-scroll">
                    <table>
                        <thead id="tbHead"></thead>
                        <tbody id="tbRows"></tbody>
                    </table>
                </div>

                <p class="tb-footnote" id="tbNote">No time-block changes can be saved from this page.</p>
            </section>
        </main>

        <!-- FOOTER COMPONENT -->
        <div class="footer">
            Scheduling System &copy; <?= $dashboardDate->format('Y') ?> Bestlink College of the Philippines
        </div>
    </div>

    <script>
        (() => {
            'use strict';

            // Sidebar Hamburger Logic
            const hamburgerBtn = document.getElementById('hamburgerBtn');
            const sidebar = document.getElementById('sidebar');
            if (hamburgerBtn && sidebar) {
                hamburgerBtn.addEventListener('click', () => {
                    sidebar.classList.toggle('collapsed');
                });
            }

            const $ = id => document.getElementById(id);
            let data = null;
            const cell = (tag, value) => {
                const n = document.createElement(tag);
                n.textContent = String(value ?? '—');
                return n;
            };

            function slotActive(row) {
                if (!data.columns.includes('is_active')) return null;
                return String(row.is_active) === '1';
            }

            function draw() {
                if (!data) return;
                const day = $('tbDay').value.toLowerCase(),
                    state = $('tbState').value,
                    search = $('tbSearch').value.trim().toLowerCase();
                const rows = data.slots.filter(row => {
                    if (day && String(row.day_of_week ?? '').toLowerCase() !== day) return false;
                    const active = slotActive(row);
                    if (state === 'active' && active !== true) return false;
                    if (state === 'inactive' && active !== false) return false;
                    return !search || data.columns.some(k => String(row[k] ?? '').toLowerCase().includes(search));
                });
                const body = $('tbRows');
                body.replaceChildren();
                const fragment = document.createDocumentFragment();
                for (const row of rows) {
                    const tr = document.createElement('tr');
                    for (const col of data.columns) tr.append(cell('td', row[col]));
                    fragment.append(tr);
                }
                body.append(fragment);
                $('tbStatus').textContent = `Showing ${rows.length} of ${data.slot_count} recorded time slots (read-only).`;
                if (!rows.length) {
                    const tr = document.createElement('tr'),
                        td = cell('td', 'No records match these filters.');
                    td.colSpan = data.columns.length;
                    tr.append(td);
                    body.append(tr);
                }
            }

            function statistic(label, value) {
                const box = document.createElement('div');
                box.append(cell('span', label), cell('strong', value));
                $('tbStats').append(box);
            }
            async function load() {
                try {
                    const response = await fetch('time-block-api.php?action=catalog', {
                        credentials: 'same-origin',
                        cache: 'no-store',
                        headers: {
                            Accept: 'application/json'
                        }
                    });
                    const result = await response.json();
                    if (!response.ok || result.success !== true) throw Error(result.message || result.status || 'Unable to load time slots.');
                    data = result;
                    $('tbStats').replaceChildren();
                    statistic('Saved time slots', data.slot_count);
                    statistic('ACTIVE class batches', data.saved_schedule_guard.active_class_batches);
                    statistic('ACTIVE exam batches', data.saved_schedule_guard.active_exam_batches);
                    $('tbHead').replaceChildren();
                    const heading = document.createElement('tr');
                    for (const name of data.columns) heading.append(cell('th', name.replaceAll('_', ' ')));
                    $('tbHead').append(heading);
                    const days = [...new Set(data.slots.map(x => String(x.day_of_week ?? '')).filter(Boolean))];
                    for (const day of days) $('tbDay').add(new Option(day, day.toLowerCase()));
                    $('tbDay').disabled = !data.columns.includes('day_of_week');
                    $('tbState').disabled = !data.columns.includes('is_active');
                    $('tbSearch').disabled = false;
                    $('tbPrint').disabled = false;
                    $('tbNote').textContent = data.truncated ? 'More than 10,000 rows found: this inventory displays only the first 10,000. No changes were made.' : 'No editing or database write. The exact slot schema and official scheduling policies must be reviewed before activating the customizer.';
                    draw();
                } catch (e) {
                    $('tbStatus').textContent = e.message;
                    $('tbStatus').dataset.error = 'true';
                }
            }
            for (const id of ['tbDay', 'tbState', 'tbSearch']) $(id).addEventListener(id === 'tbSearch' ? 'input' : 'change', draw);
            $('tbPrint').addEventListener('click', () => {
                if (data) window.print();
            });
            load();
        })();
    </script>
</body>

</html>