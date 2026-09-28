<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/shared/auth.php';

authRequire();
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$APP_ROOT = '../';$ACTIVE_NAV = 'student_schedule_view';

// Safe escape helper para sa Topbar
function dashEsc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Safe variables for Topbar
$role = (string) ($_SESSION['role'] ?? 'Admin');
$username = trim((string) ($_SESSION['first_name'] ?? 'Admin'));
$initial = strtoupper(substr($username !== '' ? $username : 'U', 0, 1));$dashboardDate = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Student Class Schedule | BCP</title>
  <link rel="icon" href="../assets/images/BCP_LOGO.png" type="image/png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  
  <link rel="stylesheet" href="../assets/css/student-schedule-view.css">
</head>
<body class="bcp-student-schedule-page">

<?php require_once __DIR__ . '/../includes/sidebar.php'; ?>

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
                <?= dashEsc($role) ?>
            </span>
            <a href="../auth/account.php" class="avatar" title="Account Settings">
                <?= dashEsc($initial) ?>
            </a>
        </div>
    </div>

    <main class="content bcp-students-view">
      <div class="bcp-students-inner">
        <header class="bcp-students-header">
          <div>
            <span class="bcp-students-eyebrow"><span class="bcp-students-eyebrow-dot"></span> BCP CLASS SCHEDULING · MODULE 1</span>
            <h1>Student Class Schedule<span class="bcp-students-title-dot">.</span></h1>
            <p>BSIT · 2026–2027 · First Semester · Read-only DEMO</p>
          </div>
          <span class="bcp-students-badge" title="DEMO Environment">DEMO DATA</span>
        </header>
        
        <section class="bcp-students-panel bcp-students-panel--filters" aria-label="Student search and selection">
          <div class="bcp-students-field-group">
            <label for="studentSearch">Find a student</label>
            <div class="bcp-students-search-box">
              <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
              <input id="studentSearch" type="search" autocomplete="off" maxlength="60" placeholder="Type student number, name, or section (e.g. 41001)">
              <!-- Autocomplete Floating Results -->
              <div id="searchSuggestions" class="bcp-autocomplete-dropdown" hidden></div>
            </div>
            <p id="studentListStatus" class="bcp-students-muted" role="status">Loading student records…</p>
          </div>

          <div class="bcp-students-field-group" style="margin-top: 16px;">
            <label for="studentSelect">Select student</label>
            <div class="bcp-custom-select-wrap">
              <select id="studentSelect" disabled><option value="">Loading…</option></select>
            </div>
          </div>
          <p class="bcp-students-muted" style="margin-top: 12px;">Displays up to 50 matching students. Search or pick from suggestions to view timetable.</p>
        </section>
        
        <section id="studentProfile" class="bcp-students-panel" hidden aria-label="Student Profile">
          <div class="bcp-students-profile">
            <div>
              <span class="bcp-students-eyebrow">STUDENT TIMETABLE</span>
              <h2 id="studentName"></h2>
              <p id="studentInfo" class="bcp-students-muted"></p>
            </div>
            <span id="studentBatch" class="bcp-students-badge"></span>
          </div>
          <p id="studentAudit" class="bcp-students-alert" role="status" style="margin-top: 16px;"></p>
        </section>
        
        <section id="studentResults" hidden aria-label="Student Schedules">
          <div class="bcp-students-panel">
            <h2>Face-to-Face Schedule</h2>
            <div id="f2fTable"></div>
          </div>
          <div class="bcp-students-panel">
            <h2>Online Schedule</h2>
            <div id="onlineTable"></div>
          </div>
        </section>
        
        <div id="studentEmpty" class="bcp-students-empty">
          <div class="bcp-students-empty-icon"><i class="fa-regular fa-calendar-xmark" aria-hidden="true"></i></div>
          <h2>No student selected</h2>
          <p>Select a student above to view their saved class timetable.</p>
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
  
  const hamburgerBtn = document.getElementById('hamburgerBtn');
  const sidebar = document.getElementById('sidebar');
  if(hamburgerBtn && sidebar) {
      hamburgerBtn.addEventListener('click', () => {
          sidebar.classList.toggle('collapsed');
      });
  }

  const api = '../api/student-schedule.php';
  const periodId = 1;
  const days = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
  const el = id => document.getElementById(id);
  const search = el('studentSearch');
  const select = el('studentSelect');
  const suggestionsBox = el('searchSuggestions');
  let listController = null;
  let detailController = null;
  let debounce = null;
  
  const textNode = (tag, value) => { const node=document.createElement(tag); node.textContent=value ?? '—'; return node; };
  const status = msg => { el('studentListStatus').textContent = msg; };
  
  const clear = () => {
    el('studentProfile').hidden = true;
    el('studentResults').hidden = true;
    el('studentEmpty').hidden = false;
    el('studentEmpty').querySelector('h2').textContent = 'No student selected';
    el('studentEmpty').querySelector('p').textContent = 'Select a student above to view their saved class timetable.';
  };

  /* --- PREMIUM CUSTOM SELECT DROPDOWN LOGIC --- */
  function upgradeSelects() {
      document.querySelectorAll('.bcp-students-panel select').forEach(selectElem => {
          if (selectElem.parentElement.classList.contains('bcp-custom-select-wrapper')) return;
          selectElem.style.display = 'none';
          
          const wrapper = document.createElement("div");
          wrapper.className = "bcp-custom-select-wrapper";
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
          observer.observe(selectElem, { childList: true, attributes: true, attributeFilter: ['disabled'] });
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
  
  async function request(params, signal) {
    const query = new URLSearchParams({period_id:String(periodId),...params});
    const response = await fetch(`${api}?${query}`, {signal,credentials:'same-origin',cache:'no-store'});
    const result = await response.json();
    if (!response.ok || result.success !== true) throw new Error(result.message || result.status || 'Request failed.');
    return result;
  }
  
  function table(target, meetings) {
    const root = el(target); root.replaceChildren();
    if (!meetings.length) { 
      const p = textNode('p','No meetings for this delivery mode.');
      p.className = 'bcp-students-muted';
      root.appendChild(p); 
      return; 
    }
    const wrap=document.createElement('div'); wrap.className='bcp-students-table-wrap';
    const table=document.createElement('table'); table.className='bcp-students-table';
    const head=document.createElement('thead'); const hr=document.createElement('tr');
    ['Day','Time','Section','Subject','Professor','Room'].forEach(x=>hr.appendChild(textNode('th',x)));
    head.appendChild(hr); table.appendChild(head);
    const body=document.createElement('tbody');
    meetings.sort((a,b)=>days.indexOf(a.day_of_week)-days.indexOf(b.day_of_week) || a.start_time.localeCompare(b.start_time));
    meetings.forEach(m=>{
      const row=document.createElement('tr');
      [m.day_of_week,`${m.start_time}–${m.end_time}`,m.section_code,
       `${m.subject_code} — ${m.subject_title}`,m.teacher_name,m.room_name || 'Online'].forEach(value=>row.appendChild(textNode('td',value)));
      body.appendChild(row);
    });
    table.appendChild(body); wrap.appendChild(table); root.appendChild(wrap);
  }

  // Render Autocomplete Suggestions List
  function renderSuggestions(students) {
    suggestionsBox.replaceChildren();
    if (!students.length || !search.value.trim()) {
      suggestionsBox.hidden = true;
      return;
    }

    students.slice(0, 8).forEach(s => {
      const div = document.createElement('div');
      div.className = 'bcp-autocomplete-item';
      div.textContent = `${s.student_number} · ${s.first_name} ${s.last_name} · ${s.home_section}`;
      div.addEventListener('click', () => {
        search.value = s.student_number;
        select.value = s.student_number;
        select.dispatchEvent(new Event('change'));
        suggestionsBox.hidden = true;
      });
      suggestionsBox.appendChild(div);
    });
    suggestionsBox.hidden = false;
  }
  
  async function loadList() {
    listController?.abort(); listController=new AbortController();
    detailController?.abort(); clear(); select.disabled=true;
    select.replaceChildren(new Option('Loading students…',''));
    try {
      const data = await request({search:search.value.trim()},listController.signal);
      select.replaceChildren(new Option('Choose a DEMO student…',''));
      data.students.forEach(s=>select.add(new Option(`${s.student_number} · ${s.first_name} ${s.last_name} · ${s.home_section}${s.major_section ? ' + '+s.major_section : ''}`,s.student_number)));
      select.disabled=data.students.length===0;
      status(`${data.total_demo_students} DEMO students · ${data.shown} result(s) shown.`);
      
      renderSuggestions(data.students);

      if (!data.students.length) {
        el('studentEmpty').hidden = false;
        el('studentEmpty').querySelector('h2').textContent = 'No matching student found';
        el('studentEmpty').querySelector('p').textContent = 'Try adjusting your search criteria.';
      }
      upgradeSelects();
    } catch (error) {
      if (error.name==='AbortError') return;
      status(error.message); select.replaceChildren(new Option('Unable to load students',''));
      suggestionsBox.hidden = true;
      el('studentEmpty').hidden = false;
      el('studentEmpty').querySelector('h2').textContent = 'System Error';
      el('studentEmpty').querySelector('p').textContent = 'Could not load student records.';
      upgradeSelects();
    }
  }
  
  async function loadStudent() {
    detailController?.abort(); clear();
    if (!select.value) return;
    detailController=new AbortController();
    el('studentEmpty').querySelector('h2').textContent = 'Loading timetable';
    el('studentEmpty').querySelector('p').textContent = 'Please wait while we retrieve the saved class schedule…';
    try {
      const data=await request({student_number:select.value},detailController.signal);
      el('studentName').textContent=data.student.full_name;
      el('studentInfo').textContent=`${data.student.student_number} · ${data.student.program_code} · Home section ${data.student.home_section}${data.student.major_section ? ' · Major section '+data.student.major_section : ''} · ${data.meeting_count} class meetings`;
      el('studentBatch').textContent=`ACTIVE DEMO Batch #${data.batch_id}`;
      const audit=el('studentAudit');
      audit.textContent=data.student_overlap_check.passed
        ? 'Student overlap check: no overlapping class times found. School-wide audit was not rerun.'
        : `WARNING: ${data.student_overlap_check.conflicts.length} student schedule conflict(s) detected. Review before use.`;
      audit.dataset.ok=data.student_overlap_check.passed?'yes':'no';
      table('f2fTable',data.meetings.filter(m=>m.delivery_mode==='F2F'));
      table('onlineTable',data.meetings.filter(m=>m.delivery_mode==='ONLINE'));
      el('studentProfile').hidden=false; el('studentResults').hidden=false; el('studentEmpty').hidden=true;
    } catch(error) {
      if (error.name==='AbortError') return;
      el('studentEmpty').hidden = false;
      el('studentEmpty').querySelector('h2').textContent = 'Failed to load';
      el('studentEmpty').querySelector('p').textContent = error.message;
    }
  }
  
  search.addEventListener('input',()=>{clearTimeout(debounce); debounce=setTimeout(loadList,250);});
  
  // Close autocomplete on outside click
  document.addEventListener('click', (e) => {
    if (!search.contains(e.target) && !suggestionsBox.contains(e.target)) {
      suggestionsBox.hidden = true;
    }
  });

  select.addEventListener('change',loadStudent);
  
  upgradeSelects();
  loadList();
})();
</script>
</body>
</html>