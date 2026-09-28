<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/shared/auth.php';

authRequire();

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function dashEsc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$role = (string) ($_SESSION['role'] ?? 'Admin');
$username = trim((string) ($_SESSION['first_name'] ?? 'Admin'));
$initial = strtoupper(substr($username !== '' ? $username : 'U', 0, 1));
$dashboardDate = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Teacher Schedule Mapping | BCP</title>
  <link rel="icon" href="../../assets/images/BCP_LOGO.png" type="image/png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  
  <link rel="stylesheet" href="../../assets/css/teacher-schedule-view.css">
</head>
<body class="bcp-teacher-schedule-page">

<?php 
$APP_ROOT = '../../';
$ACTIVE_NAV = 'teacher_mapping';
require_once __DIR__ . '/../../includes/sidebar.php'; 
?>

<div class="main">
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
            <a href="../../auth/account.php" class="avatar" title="Account Settings">
                <?= dashEsc($initial) ?>
            </a>
        </div>
    </div>

    <main class="content bcp-teacher-map">
        <div class="bcp-teacher-map__shell">
            <header class="bcp-teacher-map__header">
                <div>
                    <span class="bcp-teacher-map__eyebrow"><span class="bcp-teacher-map__eyebrow-dot"></span> BCP CLASS SCHEDULING · MODULE 2</span>
                    <h1>Teacher Schedule Mapping<span class="bcp-teacher-map__title-dot">.</span></h1>
                    <p>Read-only faculty schedules, teaching load, authorized subjects, and availability.</p>
                </div>
                <span class="bcp-teacher-map__chip">DEMO · READ ONLY</span>
            </header>

            <section class="bcp-teacher-map__panel bcp-teacher-map__panel--filters" aria-label="Faculty filters">
                <div class="bcp-teacher-map__filters">
                    <div class="bcp-custom-select-field">
                        <label for="tmPeriod">Academic period</label>
                        <select id="tmPeriod" disabled><option>Loading…</option></select>
                    </div>
                    <div class="bcp-custom-select-field">
                        <label for="tmProgram">Program / Department</label>
                        <select id="tmProgram" disabled><option>Loading…</option></select>
                    </div>
                    <div class="bcp-teacher-search-wrapper">
                        <label for="tmSearch">Search professor</label>
                        <div class="bcp-students-search-box">
                            <i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>
                            <input id="tmSearch" type="search" maxlength="80" placeholder="Name or employee number" autocomplete="off">
                            <div id="tmSearchSuggestions" class="bcp-autocomplete-dropdown" hidden></div>
                        </div>
                    </div>
                    <div class="bcp-custom-select-field">
                        <label for="tmTeacher">Professor</label>
                        <select id="tmTeacher" disabled><option value="">Select a professor</option></select>
                    </div>
                </div>
                <p id="tmStatus" class="bcp-teacher-map__status" role="status" aria-live="polite">Loading academic periods and faculty…</p>
            </section>

            <section id="tmProfile" class="bcp-teacher-map__panel" hidden>
                <div class="bcp-teacher-map__profile">
                    <div>
                        <span class="bcp-teacher-map__eyebrow">FACULTY TIMETABLE</span>
                        <h2 id="tmName"></h2>
                        <p id="tmMeta" class="bcp-teacher-map__muted"></p>
                    </div>
                    <span id="tmBatch" class="bcp-teacher-map__chip"></span>
                </div>
                <div id="tmStats" class="bcp-teacher-map__stats"></div>
                <div id="tmCheck" class="bcp-teacher-map__check" role="status"></div>
            </section>

            <section id="tmResults" hidden>
                <div class="bcp-teacher-map__panel">
                    <div class="bcp-teacher-map__sectionhead">
                        <h2>Face-to-Face Teaching</h2>
                        <span id="tmF2fCount" class="bcp-teacher-map__muted"></span>
                    </div>
                    <div id="tmF2fTable"></div>
                </div>
                
                <div class="bcp-teacher-map__panel">
                    <div class="bcp-teacher-map__sectionhead">
                        <h2>Online Teaching</h2>
                        <span id="tmOnlineCount" class="bcp-teacher-map__muted"></span>
                    </div>
                    <div id="tmOnlineTable"></div>
                </div>
                
                <div class="bcp-teacher-map__panel">
                    <h2>Daily Teaching Load</h2>
                    <div id="tmDaily" class="bcp-teacher-map__daily"></div>
                </div>
                
                <div class="bcp-teacher-map__split">
                    <div class="bcp-teacher-map__panel">
                        <h2>Authorized Subjects</h2>
                        <p class="bcp-teacher-map__muted">Permission to teach is not the same as a saved teaching assignment.</p>
                        <div id="tmAuthorizations"></div>
                    </div>
                    
                    <div class="bcp-teacher-map__panel">
                        <h2>Professor Availability</h2>
                        <p class="bcp-teacher-map__muted">Configured availability in the selected academic period.</p>
                        <div id="tmAvailability"></div>
                    </div>
                </div>
            </section>
            
            <div id="tmEmpty" class="bcp-teacher-map__empty">Choose a professor to view their saved teaching timetable.</div>
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

    const $ = id => document.getElementById(id);
    const days = ['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
    const api = './teacher-schedule.php';
    let catalog = null;
    let teachers = [];
    let listAbort = null;
    let detailAbort = null;
    let requestSequence = 0;

    function upgradeSelects() {
        document.querySelectorAll('.bcp-teacher-map__filters select').forEach(selectElem => {
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
    
    const node = (tag, value, css='') => {
        const item = document.createElement(tag);
        if (css) item.className = css;
        if (value !== undefined && value !== null) item.textContent = String(value);
        return item;
    };
    function announce(message, state='info') {
        $('tmStatus').textContent = message;
        $('tmStatus').dataset.state = state;
    }
    function resetDetails(message='Choose a professor to view their saved teaching timetable.') {
        $('tmProfile').hidden = true;
        $('tmResults').hidden = true;
        $('tmEmpty').hidden = false;
        $('tmEmpty').textContent = message;
    }
    function setOptions(select, rows, id, label, selected) {
        select.replaceChildren();
        rows.forEach(row => select.add(new Option(label(row), String(id(row)))));
        if (selected !== undefined && selected !== null) select.value = String(selected);
        select.disabled = rows.length === 0;
        upgradeSelects();
    }
    async function getJson(url, signal) {
        const response = await fetch(url, {credentials:'same-origin',cache:'no-store',signal});
        let data;
        try { data = await response.json(); }
        catch { throw new Error('The API returned an invalid response. Check the PHP error log.'); }
        if (!response.ok || data.success !== true) throw new Error(data.message || data.status || 'Unable to load data.');
        return data;
    }
    const params = more => new URLSearchParams({
        program:$('tmProgram').value, period_id:$('tmPeriod').value, ...more,
    });

    function renderSuggestions(filtered) {
        const suggestionsBox = $('tmSearchSuggestions');
        suggestionsBox.replaceChildren();
        const query = $('tmSearch').value.trim();
        if (!filtered.length || !query) {
            suggestionsBox.hidden = true;
            return;
        }

        filtered.slice(0, 8).forEach(t => {
            const div = document.createElement('div');
            div.className = 'bcp-autocomplete-item';
            div.textContent = `${t.teacher_name} · ${t.employee_no} · ${t.saved_meetings} meetings`;
            div.addEventListener('click', () => {
                $('tmSearch').value = t.teacher_name;
                $('tmTeacher').value = String(t.teacher_id);$('tmTeacher').dispatchEvent(new Event('change'));
                suggestionsBox.hidden = true;
            });
            suggestionsBox.appendChild(div);
        });
        suggestionsBox.hidden = false;
    }

    function renderTeacherOptions(preferred='') {
        const search = $('tmSearch').value.trim().toLowerCase();
        const filtered = teachers.filter(t => (`${t.teacher_name} ${t.employee_no}`).toLowerCase().includes(search));
        const select = $('tmTeacher');
        select.replaceChildren(new Option('Select a professor…',''));
        filtered.forEach(t => select.add(new Option(`${t.teacher_name} · ${t.employee_no} · ${t.saved_meetings} meetings`,String(t.teacher_id))));
        select.disabled = filtered.length === 0;
        if (preferred && filtered.some(t => String(t.teacher_id) === preferred)) select.value = preferred;
        
        renderSuggestions(filtered);
        upgradeSelects();
        return filtered.length;
    }

    function valueLabel(value) { return value === null || value === undefined || value === '' ? '—' : String(value); }
    function time(value) {
        if (!value) return '—';
        const [h,m]=String(value).split(':').map(Number);
        return `${h%12 || 12}:${String(m).padStart(2,'0')} ${h>=12?'PM':'AM'}`;
    }
    function renderTable(id, headers, rows) {
        const root = $(id); root.replaceChildren();
        if (rows.length === 0) { root.appendChild(node('p','No records for this category.','bcp-teacher-map__muted')); return; }
        const wrap=node('div',undefined,'bcp-teacher-map__tablewrap');
        const table=node('table',undefined,'bcp-teacher-map__table');
        const head=node('thead'); const hr=node('tr');
        headers.forEach(label=>hr.appendChild(node('th',label)));
        head.appendChild(hr); table.appendChild(head);
        const body=node('tbody');
        rows.forEach(values=>{
            const row=node('tr');
            values.forEach(v=>row.appendChild(node('td',valueLabel(v))));
            body.appendChild(row);
        });
        table.appendChild(body); wrap.appendChild(table); root.appendChild(wrap);
    }
    function renderMeetings(id, meetings) {
        const sorted = [...meetings].sort((a,b)=>days.indexOf(a.day_of_week)-days.indexOf(b.day_of_week) || a.start_time.localeCompare(b.start_time));
        renderTable(id, ['Day','Time','Section','Subject','Room','Duration'], sorted.map(m=>[
            m.day_of_week, `${time(m.start_time)} – ${time(m.end_time)}`, m.section_code,
            `${m.subject_code} — ${m.subject_title}`, m.room_name || (m.delivery_mode==='ONLINE'?'No room (Online)':'Room missing'),
            `${m.duration_minutes} min`,
        ]));
    }
    function addStat(label,value) {
        const card=node('div',undefined,'bcp-teacher-map__stat');
        card.appendChild(node('span',label));
        card.appendChild(node('strong',value));
        $('tmStats').appendChild(card);
    }
    function renderDaily(items, maxHours) {
        const root=$('tmDaily'); root.replaceChildren();
        items.forEach(day=>{
            const row=node('div',undefined,'bcp-teacher-map__day');
            const top=node('div',undefined,'bcp-teacher-map__daytop');
            top.appendChild(node('strong',day.day_of_week));
            top.appendChild(node('span',`${day.teaching_hours} / ${maxHours} hrs`));
            row.appendChild(top);
            const track=node('div',undefined,'bcp-teacher-map__track');
            const fill=node('div',undefined,'bcp-teacher-map__fill');
            fill.style.width=`${Math.min(100, 100*day.teaching_hours/Math.max(maxHours,1))}%`;
            fill.setAttribute('aria-label',`${day.teaching_hours} teaching hours on ${day.day_of_week}`);
            track.appendChild(fill); row.appendChild(track); root.appendChild(row);
        });
    }
    function showTeacher(data) {
        const {teacher,summary,meetings}=data;
        $('tmName').textContent=teacher.teacher_name;
        $('tmMeta').textContent=`${teacher.employee_no} · ${data.program.program_code} · ${data.period.academic_year}, Semester ${data.period.semester}`;
        $('tmBatch').textContent=data.batch_id ? `ACTIVE DEMO BATCH #${data.batch_id}` : 'NO ACTIVE DEMO TIMETABLE';
        $('tmStats').replaceChildren();
        addStat('Teaching load',`${summary.weekly_teaching_hours} / ${summary.max_weekly_hours} hrs/week`);
        addStat('F2F hours',`${summary.f2f_hours} hrs`);
        addStat('Online hours',`${summary.online_hours} hrs`);
        addStat('Sections / subject assignments',`${summary.sections} / ${summary.assigned_subject_sections}`);
        
        const report=$('tmCheck'); report.replaceChildren();
        report.dataset.ok=String(data.mapping_validation.passed);
        report.appendChild(node('strong',data.mapping_validation.passed ? 'No issues found in checked faculty mapping.' : `${data.mapping_validation.total_issues} faculty mapping issue(s) found.`));
        report.appendChild(node('p',`Teacher time conflicts: ${data.teacher_overlap_check.total_conflicts}. This is a faculty-level check, not a new full school-wide audit.`));
        if (!data.mapping_validation.passed) {
            const issues=node('ul');
            data.mapping_validation.issues.forEach(issue=>issues.appendChild(node('li',`${issue.type}: ${issue.message}`)));
            report.appendChild(issues);
        }
        
        const f2f=meetings.filter(m=>m.delivery_mode==='F2F');
        const online=meetings.filter(m=>m.delivery_mode==='ONLINE');
        $('tmF2fCount').textContent=`${f2f.length} class(es)`;
        $('tmOnlineCount').textContent=`${online.length} class(es)`;
        renderMeetings('tmF2fTable',f2f);
        renderMeetings('tmOnlineTable',online);
        renderDaily(data.daily_load,summary.max_daily_hours);
        renderTable('tmAuthorizations',['Subject','Title','Year level'],data.authorized_subjects.map(a=>[a.subject_code,a.subject_title,a.year_level]));
        renderTable('tmAvailability',['Day','Time','Status'],data.availability.map(a=>[
            a.day_of_week, `${time(a.start_time)} – ${time(a.end_time)}`,a.availability_status,
        ]));
        
        $('tmProfile').hidden=false; $('tmResults').hidden=false; $('tmEmpty').hidden=true;
    }
    
    async function loadTeacher() {
        detailAbort?.abort();
        const current=++requestSequence;
        resetDetails();
        const id=$('tmTeacher').value;
        if (!id) return;
        detailAbort=new AbortController();
        announce('Loading professor timetable…','loading');
        try {
            const data=await getJson(`${api}?${params({teacher_id:id})}`,detailAbort.signal);
            if (current!==requestSequence) return;
            showTeacher(data);
            announce(`Loaded ${data.teacher.teacher_name} · ${data.meeting_count} saved meeting(s).`,'success');
        } catch (error) {
            if (error.name==='AbortError' || current!==requestSequence) return;
            announce(error.message,'error'); resetDetails('Unable to load this professor’s timetable.');
        }
    }
    
    async function loadFaculty() {
        listAbort?.abort(); detailAbort?.abort();
        const current=++requestSequence;
        listAbort=new AbortController();
        resetDetails(); teachers=[];
        $('tmTeacher').replaceChildren(new Option('Loading faculty…',''));$('tmTeacher').disabled=true;
        announce('Loading selected program faculty…','loading');
        try {
            const data=await getJson(`${api}?${params({})}`,listAbort.signal);
            if (current!==requestSequence) return;
            teachers=data.teachers;
            const shown=renderTeacherOptions();
            announce(`${data.total_teachers} ${data.program.program_code} DEMO professor(s) · ${shown} shown · ${data.has_saved_schedule?'ACTIVE batch #'+data.batch_id:'no ACTIVE timetable'}.`,'success');
            if (!shown) resetDetails('No DEMO professors found for this program and search.');
        } catch(error) {
            if (error.name==='AbortError' || current!==requestSequence) return;
            announce(error.message,'error'); resetDetails('Unable to load faculty records.');
        }
    }
    
    async function loadCatalog(periodId=null,programCode='BSIT') {
        listAbort?.abort(); detailAbort?.abort();
        ++requestSequence;
        resetDetails();
        $('tmPeriod').disabled=true; $('tmProgram').disabled=true;
        announce('Loading programs and academic periods…','loading');
        try {
            const query=periodId?`?periodId=${encodeURIComponent(periodId)}`:'';
            catalog=await getJson(`../../api/program-catalog.php${query}`);
            const periods=catalog.periods.filter(p=>p.period_status==='DEMO');
            if (!periods.length) throw new Error('No DEMO academic period is available.');
            const currentPeriod=periods.find(p=>String(p.academic_period_id)===String(periodId)) || periods[0];
            setOptions($('tmPeriod'),periods,p=>p.academic_period_id,p=>`${p.academic_year} · Semester ${p.semester}`,currentPeriod.academic_period_id);
            setOptions($('tmProgram'),catalog.programs,p=>p.program_code,p=>`${p.program_code} — ${p.program_name}`,programCode);
            await loadFaculty();
        } catch(error) { announce(error.message,'error'); resetDetails('Unable to load the database program catalog.'); }
    }
    
    $('tmPeriod').addEventListener('change',()=>loadCatalog($('tmPeriod').value,$('tmProgram').value));$('tmProgram').addEventListener('change',loadFaculty);
    $('tmTeacher').addEventListener('change',loadTeacher);$('tmSearch').addEventListener('input',()=>{
        ++requestSequence; detailAbort?.abort(); resetDetails();
        renderTeacherOptions();
    });

    document.addEventListener('click', (e) => {
        if (!$('tmSearch').contains(e.target) && !$('tmSearchSuggestions').contains(e.target)) {$('tmSearchSuggestions').hidden = true;
        }
    });

    document.addEventListener('keydown',event=>{
        if (event.key==='/' && !['INPUT','TEXTAREA','SELECT'].includes(document.activeElement?.tagName) && !event.ctrlKey && !event.altKey && !event.metaKey) {
            event.preventDefault(); $('tmSearch').focus();
        }
        if (event.key==='Escape' && document.activeElement===$('tmSearch')) {
            $('tmSearch').blur();$('tmSearchSuggestions').hidden = true;
        }
    });
    
    loadCatalog();
    upgradeSelects();
})();
</script>
</body>
</html>