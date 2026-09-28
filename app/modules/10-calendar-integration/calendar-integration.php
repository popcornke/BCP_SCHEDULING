<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/shared/auth.php';

authRequire();
/** Module 10: independent, read-only local DEMO calendar. */

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

$APP_ROOT = '../../';
$ACTIVE_NAV = 'calendar_integration';

// Safe variables for Topbar
$role = htmlspecialchars($_SESSION['role'] ?? 'Admin', ENT_QUOTES, 'UTF-8');
$initial = strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1));
$dashboardDate = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
?>
<!doctype html>
<html lang="en">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Calendar Integration | BCP</title>
  <link rel="icon" href="../../images/BCP_LOGO.png" type="image/png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

  <!-- Corrected CSS path with cache buster to fix overlap instantly -->
  <link rel="stylesheet" href="../../assets/css/calendar-integration.css?v=<?= time() ?>">
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

    <!-- CALENDAR CONTENT -->
    <main id="bcCalendar" class="content calendar-app">
      <header class="calendar-hero">
        <div>
          <p class="calendar-eyebrow">BCP CLASS SCHEDULING SYSTEM · MODULE 10</p>
          <h1>Calendar Integration</h1>
          <p class="calendar-muted">One view for saved class meetings, examination dates, faculty duties, and substitutes.</p>
        </div><span class="calendar-badge">READ-ONLY · DEMO</span>
      </header>

      <section class="calendar-panel calendar-filters" aria-label="Calendar filters">
        <div class="calendar-filter-grid">
          <label>Academic period<select id="ciPeriod" disabled>
              <option>Loading periods…</option>
            </select></label>
          <label>Program<select id="ciProgram" disabled>
              <option>Loading programs…</option>
            </select></label>
          <label>Professor / proctor<select id="ciTeacher" disabled>
              <option>Loading faculty…</option>
            </select></label>
          <label>Show<select id="ciKind">
              <option value="ALL">All saved events</option>
              <option value="REGULAR_CLASS">Regular classes</option>
              <option value="SUBSTITUTE_CLASS">Substitute duties</option>
              <option value="EXAM">Examinations</option>
              <option value="SPECIAL_CLASS">Saved special classes</option>
            </select></label>
          <label>Find section / subject / room<input id="ciSearch" type="search" maxlength="100" placeholder="e.g. 11001, IM101, Room 201"></label>
        </div>
        <div class="calendar-toolbar">
          <div class="calendar-navigation"><button type="button" id="ciPrev" aria-label="Previous month">←</button>
            <button type="button" id="ciToday">Today</button>
            <button type="button" id="ciNext" aria-label="Next month">→</button>
            <strong id="ciMonth" aria-live="polite">Loading calendar…</strong>
          </div>
          <div class="calendar-actions"><button type="button" id="ciRefresh">Refresh saved records</button>
            <button type="button" id="ciPrint" disabled>Print selected day</button>
          </div>
        </div>
        <p id="ciStatus" role="status" aria-live="polite">Loading saved calendar…</p>
        <p class="calendar-warning">Weekly class meetings are expanded from saved patterns; this is not proof of actual attendance. Exams and classes may appear together. Holidays, cancellations and school-calendar dates are not verified. No booking or changes are made.</p>
      </section>

      <section class="calendar-overview" aria-label="Visible month summary">
        <div><span>Regular class occurrences</span><strong id="ciRegular">—</strong></div>
        <div><span>Substitute occurrences</span><strong id="ciSubs">—</strong></div>
        <div><span>Saved examinations</span><strong id="ciExams">—</strong></div>
        <div><span>Saved special classes</span><strong id="ciSpecial">—</strong></div>
      </section>

      <div class="calendar-workspace">
        <section class="calendar-panel calendar-month-panel" aria-labelledby="ciMonthTitle">
          <div class="calendar-section-title">
            <div><span class="calendar-step">01</span>
              <h2 id="ciMonthTitle">Monthly view</h2>
            </div>
            <span class="calendar-muted">Select a date to inspect its saved meetings.</span>
          </div>
          <div class="calendar-legend"><span class="calendar-legend-regular">Class</span><span class="calendar-legend-exam">Exam</span>
            <span class="calendar-legend-sub">Substitute</span><span class="calendar-legend-special">Special</span>
          </div>
          <div class="calendar-week-head" aria-hidden="true"><span>Mon</span><span>Tue</span><span>Wed</span><span>Thu</span><span>Fri</span><span>Sat</span><span>Sun</span></div>
          <div class="calendar-month-grid" id="ciGrid" aria-label="Calendar days"></div>
          <p class="calendar-muted calendar-grid-note">Month counts reflect selected filters. The first/last row may include adjacent-month dates.</p>
        </section>
        <section class="calendar-panel calendar-agenda" aria-labelledby="ciAgendaTitle">
          <div class="calendar-section-title">
            <div><span class="calendar-step">02</span>
              <h2 id="ciAgendaTitle">Day schedule</h2>
            </div>
            <span class="calendar-badge" id="ciDayCount">0 events</span>
          </div>
          <h3 id="ciSelected">Choose a date</h3>
          <div id="ciDayEvents" class="calendar-day-events" aria-live="polite"></div>
        </section>
      </div>

      <section class="calendar-panel calendar-print-area" id="ciPrintArea" aria-label="Printable selected-day summary">
        <h2>BCP · Saved Calendar — Selected Day</h2>
        <p id="ciPrintContext"></p>
        <p>Read-only saved records. Regular classes are weekly patterns, not confirmed daily attendance. This is not a conflict-clearance or room-reservation report.</p>
        <div id="ciPrintEvents"></div>
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

      const root = document.getElementById('bcCalendar');
      const $ = id => document.getElementById(id);
      const create = (tag, content, cls) => {
        const el = document.createElement(tag);
        if (content !== undefined && content !== null) el.textContent = String(content);
        if (cls) el.className = cls;
        return el;
      };
      const weekday = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
      const types = {
        REGULAR_CLASS: 'Regular class',
        SUBSTITUTE_CLASS: 'Substitute duty',
        EXAM: 'Examination',
        SPECIAL_CLASS: 'Special class'
      };
      const pad = n => String(n).padStart(2, '0');
      const fmt = d => `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}`;
      const local = (date) => {
        const [y, m, d] = date.split('-').map(Number);
        return new Date(y, m - 1, d);
      };
      const longDate = (date) => local(date).toLocaleDateString('en-PH', {
        weekday: 'long',
        month: 'long',
        day: 'numeric',
        year: 'numeric'
      });
      const clock = (t) => {
        if (!t) return '—';
        const [h, m] = t.split(':').map(Number);
        return `${h%12||12}:${pad(m)} ${h>=12?'PM':'AM'}`;
      };
      let selected = fmt(new Date()),
        month = new Date(new Date().getFullYear(), new Date().getMonth(), 1);
      let all = [],
        visible = [],
        loading = 0,
        periods = [],
        teachers = [],
        programs = [];

      function status(message, error = false) {
        $('ciStatus').textContent = message;
        $('ciStatus').dataset.error = error ? 'yes' : 'no';
      }
      async function api(params) {
        const url = new URL('calendar-api.php', location.href);
        for (const [k, v] of Object.entries(params)) url.searchParams.set(k, String(v));
        const res = await fetch(url, {
          credentials: 'same-origin',
          cache: 'no-store',
          headers: {
            Accept: 'application/json'
          }
        });
        let body;
        try {
          body = await res.json();
        } catch {
          throw Error('PHP did not return JSON. Check the Apache error log.');
        }
        if (!res.ok || body.success !== true) throw Error(body.message || body.status || 'Calendar request failed.');
        return body;
      }

      function option(select, value, text) {
        select.add(new Option(text, String(value)));
      }

      function range() {
        const first = new Date(month.getFullYear(), month.getMonth(), 1);
        const mondayIndex = (first.getDay() + 6) % 7;
        const start = new Date(month.getFullYear(), month.getMonth(), 1 - mondayIndex);
        const daysInMonth = new Date(month.getFullYear(), month.getMonth() + 1, 0).getDate();
        const cells = Math.ceil((mondayIndex + daysInMonth) / 7) * 7;
        const end = new Date(start.getFullYear(), start.getMonth(), start.getDate() + cells - 1);
        return {
          start: fmt(start),
          end: fmt(end),
          cells
        };
      }

      function eventText(e) {
        return `${e.start_time}–${e.end_time} · ${e.subject_code||'Special class'} · ${e.section_code||'Individual participants'}`;
      }

      function match(e) {
        const kind = $('ciKind').value;
        if (kind !== 'ALL' && e.type !== kind) return false;
        const q = $('ciSearch').value.trim().toLocaleLowerCase();
        if (!q) return true;
        return [e.subject_code, e.subject_title, e.section_code, e.room_name, e.teacher_name, e.program_code, e.original_teacher_name]
          .some(x => String(x ?? '').toLocaleLowerCase().includes(q));
      }

      function effective() {
        visible = all.filter(match);
        const within = visible.filter(e => e.date.slice(0, 7) === fmt(month).slice(0, 7));
        for (const [id, type] of [
            ['ciRegular', 'REGULAR_CLASS'],
            ['ciSubs', 'SUBSTITUTE_CLASS'],
            ['ciExams', 'EXAM'],
            ['ciSpecial', 'SPECIAL_CLASS']
          ])
          $(id).textContent = String(within.filter(e => e.type === type).length);
        renderGrid();
        renderDay();
      }

      function renderGrid() {
        const r = range();
        $('ciMonth').textContent = month.toLocaleDateString('en-PH', {
          month: 'long',
          year: 'numeric'
        });
        const grid = $('ciGrid');
        grid.replaceChildren();
        const byDate = new Map();
        for (const e of visible) {
          if (!byDate.has(e.date)) byDate.set(e.date, []);
          byDate.get(e.date).push(e);
        }
        for (let i = 0; i < r.cells; i++) {
          const d = new Date(...[local(r.start).getFullYear(), local(r.start).getMonth(), local(r.start).getDate() + i]);
          const key = fmt(d);
          const items = byDate.get(key) || [];
          const button = create('button', undefined, 'calendar-day');
          button.type = 'button';
          button.dataset.date = key;
          button.setAttribute('aria-label', `${longDate(key)}, ${items.length} saved events`);
          if (d.getMonth() !== month.getMonth()) button.classList.add('calendar-outside');
          if (key === selected) {
            button.classList.add('calendar-selected');
            button.setAttribute('aria-pressed', 'true');
          } else button.setAttribute('aria-pressed', 'false');
          if (key === fmt(new Date())) button.classList.add('calendar-current');
          const top = create('span', undefined, 'calendar-day-top');
          top.append(create('strong', String(d.getDate())));
          if (items.length) top.append(create('small', `${items.length} event${items.length===1?'':'s'}`));
          button.append(top);
          for (const e of items.slice(0, 3)) {
            const tag = create('span', `${e.start_time} ${e.subject_code||e.type}`, `calendar-chip calendar-${e.type.toLowerCase()}`);
            tag.title = `${types[e.type]} · ${eventText(e)}`;
            button.append(tag);
          }
          if (items.length > 3) button.append(create('small', `+${items.length-3} more`, 'calendar-more'));
          button.addEventListener('click', () => {
            selected = key;
            if (d.getMonth() !== month.getMonth()) {
              month = new Date(d.getFullYear(), d.getMonth(), 1);
              load();
            } else {
              renderGrid();
              renderDay();
            }
          });
          grid.append(button);
        }
      }

      function eventCard(e) {
        const card = create('article', undefined, `calendar-event calendar-event-${e.type.toLowerCase()}`);
        const head = create('div', undefined, 'calendar-event-head');
        head.append(create('span', types[e.type], 'calendar-event-type'));
        head.append(create('strong', `${clock(e.start_time)} – ${clock(e.end_time)}`));
        card.append(head);
        card.append(create('h4', `${e.subject_code||'Special'} · ${e.subject_title||''}`));
        card.append(create('p', `${e.program_code||'—'} · ${e.section_code||e.class_type||'Individual participants'} · ${e.delivery_mode||'—'}`));
        const line = create('p', `Professor / proctor: ${e.teacher_name||'—'}${e.original_teacher_name?' · Original: '+e.original_teacher_name:''}`);
        card.append(line);
        card.append(create('p', `Room: ${e.room_name||'Online / not assigned'} · ${e.type==='EXAM'?'Exam':'Meeting'} #${e.reference_id}`));
        if (e.note) card.append(create('p', e.note, 'calendar-event-note'));
        return card;
      }

      function renderDay() {
        const list = visible.filter(e => e.date === selected);
        $('ciSelected').textContent = longDate(selected);
        $('ciDayCount').textContent = `${list.length} event${list.length===1?'':'s'}`;
        $('ciPrintContext').textContent = `${longDate(selected)} · ${$('ciPeriod').selectedOptions[0]?.textContent||'—'} · ${$('ciProgram').selectedOptions[0]?.textContent||'All programs'} · ${$('ciTeacher').selectedOptions[0]?.textContent||'All faculty'}`;
        const day = $('ciDayEvents'),
          print = $('ciPrintEvents');
        day.replaceChildren();
        print.replaceChildren();
        if (!list.length) {
          day.append(create('p', 'No matching saved meetings for this date and filters. This does not confirm that the school is closed.', 'calendar-empty'));
          print.append(create('p', 'No matching saved meetings.'));
        }
        for (const e of list) {
          day.append(eventCard(e));
          print.append(eventCard(e));
        }
      }
      async function load() {
        const token = ++loading;
        status('Loading saved meetings…');
        $('ciRefresh').disabled = true;
        $('ciPrint').disabled = true;
        const r = range();
        try {
          const body = await api({
            action: 'events',
            period_id: $('ciPeriod').value,
            program_id: $('ciProgram').value,
            teacher_id: $('ciTeacher').value,
            start_date: r.start,
            end_date: r.end
          });
          if (token !== loading) return;
          all = body.events || [];
          effective();
          const warning = (body.warnings || []).join(' ');
          status(`Loaded ${body.event_count} saved event occurrences. ${warning} No records were changed.`);
        } catch (err) {
          if (token !== loading) return;
          all = [];
          effective();
          status(err.message, true);
        } finally {
          if (token === loading) {
            $('ciRefresh').disabled = false;
            $('ciPrint').disabled = false;
          }
        }
      }

      function teacherOptions() {
        const select = $('ciTeacher'),
          old = select.value;
        select.replaceChildren();
        option(select, 0, 'All faculty / proctors');
        for (const t of teachers) {
          option(select, t.teacher_id, `${t.teacher_name} (#${t.teacher_id})`);
        }
        if ([...select.options].some(o => o.value === old)) select.value = old;
      }
      async function init() {
        try {
          const c = await api({
            action: 'catalog'
          });
          periods = c.periods || [];
          programs = c.programs || [];
          teachers = c.teachers || [];
          const p = $('ciPeriod'),
            pr = $('ciProgram');
          p.replaceChildren();
          pr.replaceChildren();
          for (const row of periods) option(p, row.academic_period_id, `${row.academic_year} · Semester ${row.semester} (${row.period_status})`);
          option(pr, 0, 'All college programs');
          for (const row of programs) option(pr, row.program_id, `${row.program_code} · ${row.program_name}`);
          if (!periods.length) {
            status('No DEMO academic period exists; the calendar cannot load.', true);
            return;
          }
          p.disabled = false;
          pr.disabled = false;
          $('ciTeacher').disabled = false;
          teacherOptions();
          p.addEventListener('change', load);
          pr.addEventListener('change', () => {
            teacherOptions();
            load();
          });
          $('ciTeacher').addEventListener('change', load);
          $('ciKind').addEventListener('change', effective);
          $('ciSearch').addEventListener('input', effective);
          $('ciPrev').addEventListener('click', () => {
            month = new Date(month.getFullYear(), month.getMonth() - 1, 1);
            selected = fmt(month);
            load();
          });
          $('ciNext').addEventListener('click', () => {
            month = new Date(month.getFullYear(), month.getMonth() + 1, 1);
            selected = fmt(month);
            load();
          });
          $('ciToday').addEventListener('click', () => {
            selected = fmt(new Date());
            month = new Date(new Date().getFullYear(), new Date().getMonth(), 1);
            load();
          });
          $('ciRefresh').addEventListener('click', load);
          $('ciPrint').addEventListener('click', () => {
            renderDay();
            window.print();
          });
          document.addEventListener('keydown', e => {
            if (e.altKey && !e.ctrlKey && !e.metaKey && e.key === 'ArrowLeft') {
              $('ciPrev').click();
              e.preventDefault();
            }
            if (e.altKey && !e.ctrlKey && !e.metaKey && e.key === 'ArrowRight') {
              $('ciNext').click();
              e.preventDefault();
            }
          });
          await load();
        } catch (err) {
          status(err.message, true);
        }
      }
      init();
    })();
  </script>
</body>

</html>