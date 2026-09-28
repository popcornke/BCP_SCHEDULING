<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/shared/auth.php';

authRequire();

date_default_timezone_set('Asia/Manila');
$today = date('Y-m-d');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Safe escape helper function
function dashEsc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$APP_ROOT = '../../';$ACTIVE_NAV = 'substitute_tracker';

$role = (string) ($_SESSION['role'] ?? 'Admin');
$username = trim((string) ($_SESSION['first_name'] ?? 'Admin'));
$initial = strtoupper(substr($username !== '' ? $username : 'U', 0, 1));$dashboardDate = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Substitute Assignment Tracker | BCP</title>
<link rel="icon" href="../../assets/images/BCP_LOGO.png" type="image/png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

<link rel="stylesheet" href="../../assets/css/substitute-assignment-tracker.css">
</head>
<body class="bcp-sub-page">

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
                <?= dashEsc($role) ?>
            </span>
            <a href="../../auth/account.php" class="avatar" title="Account Settings">
                <?= dashEsc($initial) ?>
            </a>
        </div>
    </div>

    <main class="content bcp-sub" id="subApp">
     <div class="bcp-sub__shell">
         
         <header class="bcp-sub__header">
          <div>
           <p class="bcp-sub__eyebrow"><span class="bcp-sub__eyebrow-dot"></span> BCP CLASS SCHEDULING SYSTEM</p>
           <h1>Substitute Assignment Tracker<span class="bcp-sub__title-dot">.</span></h1>
           <p>Temporary coverage for a specific class date. Original class schedules and faculty profiles stay unchanged.</p>
          </div>
          <span class="bcp-sub__chip">LOCAL DEMO</span>
         </header>
         
         <section class="bcp-sub__panel" aria-label="Select timetable and duty date">
          <div class="bcp-sub__controls">
              <div class="bcp-sub__control">
                  <label for="subPeriod">Academic period</label>
                  <select id="subPeriod" aria-label="Academic period"></select>
              </div>
              <div class="bcp-sub__control">
                  <label for="subProgram">Program</label>
                  <select id="subProgram" aria-label="Program"></select>
              </div>
              <div class="bcp-sub__control">
                  <label for="subDate">Duty date</label>
                  <input id="subDate" aria-label="Duty date" type="date" value="<?= dashEsc($today) ?>">
              </div>
              <div class="bcp-sub__actions">
                  <button type="button" id="subRefresh" class="bcp-sub__btn-primary"><i class="fa-solid fa-rotate-right"></i> Refresh</button>
                  <button type="button" id="subPrint" class="bcp-sub__btn-secondary"><i class="fa-solid fa-print"></i> Print</button>
              </div>
          </div>
          <p class="bcp-sub__caption">A substitute covers only this date and selected F2F/Online meeting. This is not leave approval or a permanent change to the teaching timetable.</p>
          <div class="bcp-sub__status" id="subStatus" role="status" aria-live="polite"><i class="fa-solid fa-circle-notch fa-spin bcp-sub__status-icon"></i> Loading DEMO class meetings…</div>
         </section>
         
         <section class="bcp-sub__summary" aria-label="Schedule summary">
          <div class="bcp-sub__summary-card"><span>Classes for selected day</span><strong id="subTotal">—</strong></div>
          <div class="bcp-sub__summary-card"><span>Covered by substitute</span><strong id="subCovered">—</strong></div>
          <div class="bcp-sub__summary-card"><span>Unchanged assignments</span><strong id="subOriginal">—</strong></div>
         </section>
         
         <section class="bcp-sub__panel" aria-label="Scheduled class meetings">
          <div class="bcp-sub__section-head">
           <div>
            <h2>Classes & substitute coverage</h2>
            <p id="subDayLabel">Choose a date to view class meetings.</p>
           </div>
           <div class="bcp-sub__search-box">
             <i class="fa-solid fa-magnifying-glass"></i>
             <input id="subSearch" type="search" placeholder="Find section or subject e.g. 11001" aria-label="Find section or subject">
           </div>
          </div>
          <div class="bcp-sub__meetings" id="subMeetings">
              <div class="bcp-sub__empty">Loading…</div>
          </div>
         </section>
         
         <section class="bcp-sub__panel" aria-label="Assignment history">
          <div class="bcp-sub__section-head">
           <div>
            <h2>Substitution history</h2>
            <p>Recorded and cancelled duties remain visible. Records from replaced class batches are historical only.</p>
           </div>
          </div>
          <div class="bcp-sub__history-scroll">
           <table class="bcp-sub__history-table">
            <thead>
             <tr>
              <th>Duty date</th>
              <th>Class</th>
              <th>Original professor</th>
              <th>Substitute professor</th>
              <th>Status</th>
              <th>Action</th>
             </tr>
            </thead>
            <tbody id="subHistory"></tbody>
           </table>
          </div>
         </section>
         
     </div>

     <!-- MODAL -->
     <div class="bcp-sub-modal" id="subModal" hidden>
      <div class="bcp-sub-modal__shade" id="subModalShade"></div>
      <section class="bcp-sub-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="subModalTitle">
       <div class="bcp-sub-modal__header">
        <div>
         <p class="bcp-sub__eyebrow">ONE-DAY CLASS COVERAGE</p>
         <h2 id="subModalTitle">Assign substitute professor</h2>
        </div>
        <button id="subClose" class="bcp-sub-modal__close" type="button" aria-label="Close dialog">
         <i class="fa-solid fa-xmark"></i>
        </button>
       </div>
       <div class="bcp-sub-modal__body">
           <p id="subSelectedMeeting" class="bcp-sub__selected-meeting"></p>
           <div class="bcp-sub__control bcp-sub__control--modal">
               <label for="subCandidate">Available, authorized professor</label>
               <select id="subCandidate"></select>
               <p id="subCandidateInfo" class="bcp-sub__caption" style="margin-top: 8px;"></p>
           </div>
           <div class="bcp-sub__control bcp-sub__control--modal">
               <label for="subReason">Reason for temporary substitution</label>
               <textarea id="subReason" maxlength="500" rows="3" placeholder="e.g., Assigned professor reported unavailable for this date."></textarea>
           </div>
           <p class="bcp-sub__caption">Only a scheduling record is created. Faculty leave approval must be managed by the Faculty Management System.</p>
       </div>
       <div class="bcp-sub-modal__footer">
        <button type="button" class="bcp-sub__btn-secondary" id="subCancelDialog">Cancel</button>
        <button type="button" class="bcp-sub__btn-primary" id="subConfirm"><i class="fa-solid fa-check"></i> Confirm assignment</button>
       </div>
      </section>
     </div>
     
     <!-- OFFICIAL PRINT REPORT (Hidden on screen, visible only when printed) -->
     <div id="subPrintDocument" class="bcp-sub-print-document">
         <div class="bcp-sub-print-header">
             <img src="../../assets/images/BCP_LOGO.png" alt="BCP Logo" class="bcp-sub-print-logo">
             <h3>BESTLINK COLLEGE OF THE PHILIPPINES</h3>
             <h1>Substitute Assignment Report</h1>
             <div class="bcp-sub-print-meta">
                 <span><strong>Program:</strong> <span id="printProgram">—</span></span>
                 <span><strong>Duty Date:</strong> <span id="printDutyDate">—</span></span>
                 <span><strong>Generated:</strong> <span><?= dashEsc($dashboardDate->format('M j, Y · g:i A')) ?></span></span>
             </div>
         </div>
         <div class="bcp-sub-print-summary">
             <span><strong>Total Classes:</strong> <span id="printTotal">—</span></span>
             <span><strong>Covered by Substitute:</strong> <span id="printCovered">—</span></span>
             <span><strong>Original Professor:</strong> <span id="printOriginal">—</span></span>
         </div>
         <table class="bcp-sub-print-table">
             <thead>
                 <tr>
                     <th>Section & Subject</th>
                     <th>Time & Room</th>
                     <th>Original Professor</th>
                     <th>Substitute Assigned</th>
                     <th>Status</th>
                 </tr>
             </thead>
             <tbody id="printTableBody">
             </tbody>
         </table>
         <div class="bcp-sub-print-footer">
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

const hamburgerBtn = document.getElementById('hamburgerBtn');
const sidebar = document.getElementById('sidebar');
if(hamburgerBtn && sidebar) {
    hamburgerBtn.addEventListener('click', () => {
        sidebar.classList.toggle('collapsed');
    });
}

const el=id=>document.getElementById(id);
let state={period:null,program:null,date:el('subDate').value,meetings:[],history:[],csrf:'',selected:null,loading:false,nonce:0};

/* --- PREMIUM CUSTOM SELECT DROPDOWN LOGIC --- */
function upgradeSelects() {
    document.querySelectorAll('.bcp-sub__control select').forEach(selectElem => {
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

function status(message, kind='info') {
    const statusEl = el('subStatus');
    const iconMap = {
        success: "fa-circle-check",
        error: "fa-triangle-exclamation",
        warning: "fa-triangle-exclamation",
        info: "fa-circle-info",
        loading: "fa-circle-notch fa-spin"
    };
    statusEl.dataset.kind = kind;
    statusEl.innerHTML = `<i class="fa-solid ${iconMap[kind] || iconMap.info} bcp-sub__status-icon"></i> ${message}`;
}
const node=(tag,txt,cls)=>{const n=document.createElement(tag);if(cls)n.className=cls;if(txt!==undefined)n.textContent=String(txt);return n;};
const fmt=time=>{const [h,m]=String(time).split(':').map(Number);return `${h%12||12}:${String(m).padStart(2,'0')} ${h>=12?'PM':'AM'}`;};
const dateLabel=d=>new Date(d+'T12:00:00').toLocaleDateString('en-PH',{weekday:'long',month:'long',day:'numeric',year:'numeric'});

async function api(method,params){const url=new URL('./substitute-api.php',window.location.href);
 if(method==='GET'){for(const [k,v] of Object.entries(params))if(v!==null&&v!==undefined&&v!=='')url.searchParams.set(k,v);}
 const r=await fetch(url,{method,credentials:'same-origin',cache:'no-store',headers:method==='POST'?{'Content-Type':'application/json'}:{},body:method==='POST'?JSON.stringify(params):undefined});
 let data;try{data=await r.json();}catch{throw Error('API returned invalid JSON. Check the PHP error log.');}
 if(!r.ok||!data.success)throw Error(data.message||data.status||'Request failed.');return data;}
 
function options(select,rows,valueField,textField,selected){
    select.replaceChildren();
    for(const row of rows){
        const o=new Option(textField(row),row[valueField]);
        select.add(o);
    }
    if(selected!==undefined&&rows.some(r=>String(r[valueField])===String(selected))) select.value=String(selected);
    select.dispatchEvent(new Event('change')); 
}

async function load(){const request=++state.nonce;status('Loading saved class meetings…', 'loading');
 const date=el('subDate').value,period=el('subPeriod').value,program=el('subProgram').value;
 try{const data=await api('GET',{action:'catalog',period_id:period,program,date});if(request!==state.nonce)return;
  state.period=data.selected_period_id;state.program=data.selected_program.program_code;state.date=data.selected_date;
  state.meetings=data.meetings;state.history=data.history;state.csrf=data.csrf_token;
  
  options(el('subPeriod'),data.periods,'academic_period_id',r=>`${r.academic_year} · Semester ${r.semester}`,state.period);
  options(el('subProgram'),data.programs,'program_code',r=>`${r.program_code} — ${r.program_name}`,state.program);
  upgradeSelects(); 
  
  el('subDate').value=state.date;render();status(`Loaded ${data.meetings.length} classes for ${data.selected_program.program_code} on ${dateLabel(data.selected_date)}.`,'success');
 }catch(e){if(request!==state.nonce)return;state.meetings=[];state.history=[];render();status(e.message,'error');}}
 
function render(){const covered=state.meetings.filter(x=>x.substitute_assignment_id!==null).length;
 el('subTotal').textContent=state.meetings.length;el('subCovered').textContent=covered;el('subOriginal').textContent=state.meetings.length-covered;
 el('subDayLabel').textContent=state.date?dateLabel(state.date):'No date selected';renderMeetings();renderHistory();}
 
function renderMeetings(){const target=el('subMeetings');target.replaceChildren();const search=el('subSearch').value.trim().toLowerCase();
 const items=state.meetings.filter(x=>[x.section_code,x.subject_code,x.subject_title,x.original_teacher_name,x.substitute_teacher_name||''].join(' ').toLowerCase().includes(search));
 if(!items.length){target.append(node('div',state.meetings.length?'No classes match your search.':'No regular class meetings for the selected program and day.','bcp-sub__empty'));return;}
 for(const m of items){const c=node('article',undefined,'bcp-sub__meeting-card');const top=node('div',undefined,'bcp-sub__meeting-top');
  const info=node('div');info.append(node('strong',`${m.section_code} · ${m.subject_code} — ${m.subject_title}`),node('p',`${fmt(m.start_time)} – ${fmt(m.end_time)} · ${m.delivery_mode} · ${m.room_name}`));
  const tag=node('span',m.substitute_assignment_id!==null?'COVERED':'ORIGINAL PROFESSOR',m.substitute_assignment_id!==null?'bcp-sub__chip bcp-sub__chip--covered':'bcp-sub__chip');top.append(info,tag);c.append(top);
  c.append(node('p',m.substitute_assignment_id!==null?`Original: ${m.original_teacher_name}  |  Substitute: ${m.substitute_teacher_name}`:`Assigned professor: ${m.original_teacher_name}`,'bcp-sub__muted'));
  const actions=node('div',undefined,'bcp-sub__meeting-actions');
  
  const button=document.createElement('button');
  button.type='button';
  button.className=m.substitute_assignment_id!==null?'bcp-sub__btn-secondary':'bcp-sub__btn-primary';
  button.disabled=m.substitute_assignment_id!==null;
  if(m.substitute_assignment_id!==null) {
      button.innerHTML = '<i class="fa-solid fa-user-check"></i> Substitute assigned';
  } else {
      button.innerHTML = '<i class="fa-solid fa-user-plus"></i> Find substitute';
      button.addEventListener('click',()=>openMeeting(m));
  }
  
  actions.append(button);c.append(actions);target.append(c);
 }}
 
function renderHistory(){const body=el('subHistory');body.replaceChildren();if(!state.history.length){const tr=node('tr');const td=node('td','No substitute assignments recorded yet.','bcp-sub__empty-cell');td.colSpan=6;tr.append(td);body.append(tr);return;}
 for(const h of state.history){const tr=node('tr');for(const value of [h.duty_date,`${h.section_code} · ${h.subject_code} · ${fmt(h.start_time)}–${fmt(h.end_time)}`,h.original_teacher_name,h.substitute_teacher_name])tr.append(node('td',value));
  const badge=node('span',h.status==='ACTIVE'&&Number(h.source_batch_active)!==1?'HISTORICAL':h.status,'bcp-sub__chip '+(h.status==='ACTIVE'&&Number(h.source_batch_active)===1?'bcp-sub__chip--covered':''));const cell=node('td');cell.append(badge);tr.append(cell);
  const action=node('td');if(h.status==='ACTIVE'&&Number(h.source_batch_active)===1){const b=node('button','Cancel duty','bcp-sub__link-btn');b.type='button';b.addEventListener('click',()=>cancelDuty(h));action.append(b);}else action.textContent=h.cancellation_reason||'—';tr.append(action);body.append(tr);}}
  
function closeModal(){el('subModal').hidden=true;state.selected=null;el('subReason').value='';el('subCandidate').replaceChildren();el('subCandidate').dispatchEvent(new Event('change'));}

async function openMeeting(meeting){state.selected=meeting;el('subModal').hidden=false;el('subSelectedMeeting').textContent=`${state.date} · ${meeting.section_code} · ${meeting.subject_code} · ${fmt(meeting.start_time)}–${fmt(meeting.end_time)} · Original: ${meeting.original_teacher_name}`;
 el('subCandidate').replaceChildren();el('subCandidate').dispatchEvent(new Event('change'));el('subCandidateInfo').innerHTML='<i class="fa-solid fa-circle-notch fa-spin"></i> Checking current faculty qualifications and time conflicts…';el('subConfirm').disabled=true;
 try{const data=await api('GET',{action:'candidates',period_id:state.period,meeting_id:meeting.meeting_id,date:state.date});
  if(state.selected?.meeting_id!==meeting.meeting_id)return;
  const list=data.candidates.filter(c=>c.eligible);options(el('subCandidate'),list,'teacher_id',c=>`${c.teacher_name} (${c.employee_no})`);
  el('subCandidateInfo').textContent=list.length?`${list.length} eligible professor(s). Ineligible faculty are excluded.`:'No eligible faculty found. Check subject authorizations, availability, saved classes and exam duties.';
  el('subConfirm').disabled=list.length===0;
 }catch(e){el('subCandidateInfo').textContent=e.message;el('subConfirm').disabled=true;}}
 
async function assign(){if(!state.selected||state.loading)return;const t=el('subCandidate').value;
 const reason=el('subReason').value.trim();if(reason.length<5){el('subCandidateInfo').textContent='Please provide a reason (at least five characters).';return;}
 if(!window.confirm('Confirm this one-day DEMO substitute assignment? The original saved timetable will NOT change.'))return;
 state.loading=true;el('subConfirm').disabled=true;
 try{const data=await api('POST',{action:'assign',period_id:state.period,meeting_id:state.selected.meeting_id,duty_date:state.date,substitute_teacher_id:t,reason,csrf_token:state.csrf});
  closeModal();await load();status(`Substitute assignment #${data.substitute_assignment_id} saved. Original class timetable is unchanged.`,'success');
 }catch(e){el('subCandidateInfo').textContent=e.message;el('subConfirm').disabled=false;status(e.message,'error');}finally{state.loading=false;}}
 
async function cancelDuty(h){if(state.loading)return;const reason=window.prompt(`Reason for cancelling substitute assignment #${h.substitute_assignment_id}:`);if(reason===null)return;
 if(reason.trim().length<5){status('Cancellation reason must have at least five characters.','error');return;}
 if(!window.confirm('Cancel this substitute duty? The original timetable will remain unchanged.'))return;
 state.loading=true;try{await api('POST',{action:'cancel',period_id:state.period,substitute_assignment_id:h.substitute_assignment_id,cancellation_reason:reason.trim(),csrf_token:state.csrf});
 await load();status('Substitute duty cancelled. Historical record retained.','success');}catch(e){status(e.message,'error');}finally{state.loading=false;}}
 
el('subRefresh').addEventListener('click',load);
el('subPeriod').addEventListener('change',()=>{el('subProgram').replaceChildren();el('subProgram').dispatchEvent(new Event('change'));load();});
el('subProgram').addEventListener('change',load);
el('subDate').addEventListener('change',load);
el('subSearch').addEventListener('input',renderMeetings);
el('subClose').addEventListener('click',closeModal);
el('subCancelDialog').addEventListener('click',closeModal);
el('subModalShade').addEventListener('click',closeModal);
el('subConfirm').addEventListener('click',assign);

document.addEventListener('keydown',e=>{if(e.key==='Escape'&&!el('subModal').hidden)closeModal();});

/* PRINT LOGIC */
el('subPrint').addEventListener('click', () => {
    if (!state.meetings.length) {
        status('No classes available to print for this date.', 'error');
        return;
    }

    const progSelect = el('subProgram');
    el('printProgram').textContent = progSelect.options[progSelect.selectedIndex]?.text || '—';
    el('printDutyDate').textContent = dateLabel(state.date);

    const covered = state.meetings.filter(x => x.substitute_assignment_id !== null).length;
    el('printTotal').textContent = state.meetings.length;
    el('printCovered').textContent = covered;
    el('printOriginal').textContent = state.meetings.length - covered;

    const tbody = el('printTableBody');
    tbody.replaceChildren();
    
    const sorted = [...state.meetings].sort((a,b) => a.start_time.localeCompare(b.start_time));
    
    for (const m of sorted) {
        const tr = document.createElement('tr');
        
        const td1 = document.createElement('td');
        td1.innerHTML = `<strong>${m.section_code}</strong><br>${m.subject_code} - ${m.subject_title}`;
        
        const td2 = document.createElement('td');
        td2.innerHTML = `${fmt(m.start_time)} – ${fmt(m.end_time)}<br>${m.room_name} (${m.delivery_mode})`;
        
        const td3 = document.createElement('td');
        td3.textContent = m.original_teacher_name;
        
        const td4 = document.createElement('td');
        td4.textContent = m.substitute_teacher_name || '—';
        
        const td5 = document.createElement('td');
        td5.textContent = m.substitute_assignment_id !== null ? 'COVERED' : 'ORIGINAL';
        
        tr.append(td1, td2, td3, td4, td5);
        tbody.appendChild(tr);
    }

    window.print();
});

upgradeSelects();
load();
})();
</script>
</body>
</html>