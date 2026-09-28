<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/shared/auth.php';

authRequire();


/** Module 6 / Phase 6B: browser request preparation only. No save, no schedule generation. */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$APP_ROOT = '../../';$ACTIVE_NAV = 'special_class';

$role = htmlspecialchars($_SESSION['role'] ?? 'Admin', ENT_QUOTES, 'UTF-8');$initial = strtoupper(substr($_SESSION['first_name'] ?? 'U', 0, 1));$dashboardDate = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Special Class Scheduler | BCP</title>
<link rel="icon" href="../../images/BCP_LOGO.png" type="image/png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

<link rel="stylesheet" href="../../assets/css/special-class-scheduler.css">
</head>
<body>

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

    <main class="content sc-app" id="scApp">
      <header class="sc-hero">
        <div>
          <p class="sc-kicker">BCP CLASS SCHEDULING SYSTEM · MODULE 6</p>
          <h1>Special Class Scheduler</h1>
          <p>Prepare a recurring weekly class request for selected students. The regular, exam, and substitute timetables are not modified.</p>
        </div>
        <span class="sc-pill">DEMO · REQUEST ONLY</span>
      </header>
      
      <div class="sc-alert" role="note">
        <strong>Academic calendar &amp; school policy:</strong> A verified teaching-date window and approved session duration/delivery mode are required before generating any timetable. No schedules can be saved in this phase.
        <p id="scCalendarHint" role="status" aria-live="polite">Checking the academic calendar…</p>
      </div>
      
      <div class="sc-layout">
        <section class="sc-panel sc-config" aria-labelledby="scConfigTitle">
          <div class="sc-section-heading">
            <span class="sc-step">01</span>
            <div><h2 id="scConfigTitle">Class details</h2><p>Use existing program, curriculum and faculty records.</p></div>
          </div>
          <div class="sc-fields">
            <label>Academic period<select id="scPeriod" disabled><option>Loading…</option></select></label>
            <label>Program<select id="scProgram" disabled><option>Loading…</option></select></label>
            <label>Class type
              <select id="scType">
                <option value="REMEDIAL">Remedial class</option>
                <option value="IRREGULAR">Special / irregular class</option>
                <option value="OCTOBERIAN">Octoberian (generic request)</option>
              </select>
            </label>
            <label>Subject<select id="scSubject" disabled><option value="">Select program first</option></select></label>
            <label class="sc-wide">Authorized professor<select id="scTeacher" disabled><option value="">Choose a subject first</option></select></label>
          </div>
          <p class="sc-help" id="scTeacherHelp">Subject authorization does not establish time availability; this is verified after approved duration is provided.</p>
        </section>
        
        <section class="sc-panel sc-roster" aria-labelledby="scRosterTitle">
          <div class="sc-section-heading">
            <span class="sc-step">02</span>
            <div><h2 id="scRosterTitle">Participating students</h2><p>Select individual students, not their entire home/Cluster section.</p></div>
          </div>
          <div class="sc-search"><label for="scSearch">Find student or section</label><input type="search" id="scSearch" maxlength="60" placeholder="Student number, name or section…" disabled></div>
          <div class="sc-roster-tools">
            <span id="scRosterCount">Loading student roster…</span>
            <span class="sc-selected-badge"><strong id="scSelectedCount">0</strong> / 50 selected</span>
          </div>
          <div class="sc-student-list" id="scStudentList" aria-live="polite"><p class="sc-empty">Loading…</p></div>
          <div class="sc-pager">
            <button type="button" class="sc-secondary" id="scPrev" disabled>Previous</button>
            <span id="scPage">Page —</span>
            <button type="button" class="sc-secondary" id="scNext" disabled>Next</button>
          </div>
          <details class="sc-selected-list">
            <summary>Selected students <strong id="scSelectedSummary">(0)</strong></summary>
            <div id="scSelectedItems" class="sc-selected-items"><p>No participants selected yet.</p></div>
          </details>
          <p class="sc-help">Students can come from different official sections in the same program. Existing home and Major memberships stay unchanged.</p>
        </section>
        
        <section class="sc-panel sc-week" aria-labelledby="scWeekTitle">
          <div class="sc-section-heading">
            <span class="sc-step">03</span>
            <div><h2 id="scWeekTitle">Weekly recurrence</h2><p>Choose the date window and day(s). These are requested dates, not approved meeting times.</p></div>
          </div>
          <div class="sc-fields">
            <label>First date<input id="scStart" type="date"></label>
            <label>Last date<input id="scEnd" type="date"></label>
          </div>
          <fieldset class="sc-days">
            <legend>Weekly class days</legend>
            <div class="sc-day-grid">
              <label><input type="checkbox" name="scDay" value="Monday"><span>Mon</span></label>
              <label><input type="checkbox" name="scDay" value="Tuesday"><span>Tue</span></label>
              <label><input type="checkbox" name="scDay" value="Wednesday"><span>Wed</span></label>
              <label><input type="checkbox" name="scDay" value="Thursday"><span>Thu</span></label>
              <label><input type="checkbox" name="scDay" value="Friday"><span>Fri</span></label>
              <label><input type="checkbox" name="scDay" value="Saturday"><span>Sat</span></label>
            </div>
          </fieldset>
          <p class="sc-help">For this DEMO request, choose a date range no longer than 16 weeks. This is a technical preview limit, not an official BCP policy.</p>
          <div class="sc-actions">
            <button type="button" class="sc-primary" id="scPrepare" disabled>Prepare weekly request <span aria-hidden="true">→</span></button>
            <button type="button" class="sc-secondary" id="scCheckPolicy" disabled>Check school policy</button>
            <button type="button" class="sc-primary" id="scGenerate" disabled>Generate weekly preview</button>
            <button type="button" class="sc-secondary" id="scReset">Clear request</button>
          </div>
          <p role="status" aria-live="polite" id="scStatus" class="sc-status">Loading academic period…</p>
        </section>
        
        <section class="sc-panel sc-result" aria-labelledby="scResultTitle">
          <div class="sc-section-heading">
            <span class="sc-step">04</span>
            <div><h2 id="scResultTitle">Request summary</h2><p id="scResultHint">No request prepared yet.</p></div>
            <span id="scResultFlag" class="sc-pill sc-pill-neutral">UNSAVED</span>
          </div>
          <div id="scResult" class="sc-result-inner">
            <div class="sc-placeholder">
              <span aria-hidden="true"><i class="fa-solid fa-table-cells-large"></i></span>
              <strong>Review your request here</strong>
              <p>After selecting students and weekly days, prepare the request to verify current student memberships and subject authorization.</p>
            </div>
          </div>
        </section>
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

const $=id=>document.getElementById(id);
const state={catalog:null,period:1,program:null,subject:null,faculty:[],students:[],selected:new Map(),page:1,totalPages:0,search:'',rosterRequest:0,facultyRequest:0,prepared:null,policyReady:false,calendarReady:false,calendarRequest:0,busy:false};

const node=(tag,txt,cls)=>{const n=document.createElement(tag);if(cls)n.className=cls;if(txt!==undefined)n.textContent=String(txt);return n;};
const announce=(msg,kind='info')=>{$('scStatus').textContent=msg;$('scStatus').dataset.kind=kind;};

async function api(file,method,params){
 const url=new URL(file,window.location.href);
 if(method==='GET')for(const [k,v] of Object.entries(params))if(v!==null&&v!==undefined&&v!=='')url.searchParams.set(k,String(v));
 const response=await fetch(url,{method,credentials:'same-origin',cache:'no-store',headers:method==='POST'?{'Content-Type':'application/json','Accept':'application/json'}:{'Accept':'application/json'},body:method==='POST'?JSON.stringify(params):undefined});
 let data;try{data=await response.json();}catch{throw Error('API did not return JSON. Check Apache/PHP error log.');}
 if(!response.ok||!data.success)throw Error(data.message||data.status||'Request failed.');return data;
}

function makeOptions(select,rows,value,label,empty){select.replaceChildren();select.add(new Option(empty,''));rows.forEach(row=>select.add(new Option(label(row),String(row[value]))));}

function invalidate(){state.prepared=null;state.policyReady=false;$('scCheckPolicy').disabled=true;$('scGenerate').disabled=true;$('scResultHint').textContent='Request changed. Prepare again to validate the latest selections.';
 const placeholder = node('div', undefined, 'sc-placeholder');
 const icon = node('span'); icon.innerHTML = '<i class="fa-solid fa-table-cells-large"></i>';
 placeholder.append(icon, node('strong', 'Review your request here'), node('p', 'After selecting students and weekly days, prepare the request to verify current student memberships and subject authorization.'));
 $('scResult').replaceChildren(placeholder);$('scResultFlag').textContent='UNSAVED';updateButton();
}

function updateButton(){
 $('scPrepare').disabled=state.busy||!state.calendarReady||!state.catalog||!$('scSubject').value||!$('scTeacher').value||state.selected.size<1||!$('scStart').value||!$('scEnd').value||!document.querySelector('input[name="scDay"]:checked');
 $('scCheckPolicy').disabled=state.busy||!state.prepared;$('scGenerate').disabled=state.busy||!state.prepared||!state.policyReady;
 $('scSelectedCount').textContent=state.selected.size;$('scSelectedSummary').textContent=`(${state.selected.size})`;
}

function periodOptions(){const p=state.catalog.period;makeOptions($('scPeriod'),[p],'academic_period_id',x=>`${x.academic_year} · Semester ${x.semester}`,'Choose period');$('scPeriod').value=String(p.academic_period_id);$('scPeriod').disabled=true;}

function showSubjects(){const p=state.program;const rows=state.catalog.subjects.filter(x=>Number(x.program_id)===p);
 makeOptions($('scSubject'),rows,'subject_id',x=>`${x.subject_code} — ${x.subject_title}`,'Choose an existing subject');$('scSubject').disabled=rows.length===0;
 $('scTeacher').replaceChildren(new Option('Choose a subject first',''));$('scTeacher').disabled=true;state.subject=null;state.faculty=[];$('scTeacherHelp').textContent=rows.length?'Choose a subject to see authorized professors.':'No active subjects for this program and period in the DEMO catalog.';
}

function showPrograms(){const programs=state.catalog.programs;makeOptions($('scProgram'),programs,'program_id',p=>`${p.program_code} — ${p.program_name}`,'Choose program');$('scProgram').disabled=false;
 const bsit=programs.find(p=>p.program_code==='BSIT');$('scProgram').value=bsit?String(bsit.program_id):'';state.program=Number($('scProgram').value)||null;showSubjects();}
 
async function checkCalendar(){
 const serial=++state.calendarRequest;
 state.calendarReady=false;updateButton();
 $('scCalendarHint').textContent='Checking the academic teaching dates…';
 if(!state.program)return;
 try{
   const data=await api('./special-class-preview.php','GET',{period_id:state.period,program_id:state.program,class_type:$('scType').value});
   if(serial!==state.calendarRequest)return;
   const calendar=data.calendar||{};
   state.calendarReady=calendar.calendar_ready===true;
   if(state.calendarReady){
      $('scCalendarHint').textContent=`Registered teaching window: ${calendar.teaching_start_date} to ${calendar.teaching_end_date}. This does not confirm the class-duration policy.`;
      $('scStart').min=calendar.teaching_start_date;$('scStart').max=calendar.teaching_end_date;
      $('scEnd').min=calendar.teaching_start_date;$('scEnd').max=calendar.teaching_end_date;
   } else {
      $('scCalendarHint').textContent='Academic-calendar approval pending. Prepare and Generate are disabled until the school teaching-date window is recorded.';
      for(const id of ['scStart','scEnd']){$(id).removeAttribute('min');$(id).removeAttribute('max');}
   }
 }catch(err){if(serial!==state.calendarRequest)return;state.calendarReady=false;$('scCalendarHint').textContent='Calendar check failed: '+err.message;}
 updateButton();
}

async function catalog(){announce('Loading program and academic period…', 'loading');
 try{const data=await api('./special-class-api.php','GET',{period_id:state.period});state.catalog=data;state.period=Number(data.period.academic_period_id);periodOptions();showPrograms();$('scSearch').disabled=!state.program;await loadRoster();await checkCalendar();announce(state.calendarReady?'Ready. Select a subject, authorized professor, students and approved weekly dates.':'Academic calendar pending. Student and faculty selection is available, but schedule preparation is blocked.','info');updateButton();}
 catch(err){announce(err.message,'error');$('scResultHint').textContent='Catalog unavailable.';}}
 
async function loadFaculty(){const serial=++state.facultyRequest;state.faculty=[];$('scTeacher').disabled=true;$('scTeacher').replaceChildren(new Option('Loading authorized faculty…',''));updateButton();
 
 // ==========================================
 // FIXED: Restored logical OR operator (||)
 const subjectId=Number($('scSubject').value);state.subject=subjectId || null;if(!subjectId){$('scTeacher').replaceChildren(new Option('Choose a subject first',''));return;}
 // ==========================================
 
 try{const data=await api('./special-class-preparation.php','GET',{action:'faculty',period_id:state.period,program_id:state.program,subject_id:subjectId});
 if(serial!==state.facultyRequest)return;state.faculty=data.faculty;makeOptions($('scTeacher'),data.faculty,'teacher_id',t=>`${t.teacher_name} · ${t.employee_no}`,'Choose authorized professor');$('scTeacher').disabled=!data.faculty.length;
 $('scTeacherHelp').textContent=data.faculty.length?`${data.faculty.length} subject-authorized professor(s). Actual date/time availability is not yet checked.`:'No authorized DEMO professor found. The other faculty group must provide an approved subject assignment.';
 }catch(err){if(serial!==state.facultyRequest)return;$('scTeacher').replaceChildren(new Option('Unable to load faculty',''));$('scTeacherHelp').textContent=err.message;announce(err.message,'error');}updateButton();}
 
async function loadRoster(){const serial=++state.rosterRequest;if(!state.program){$('scStudentList').replaceChildren(node('p','Select program to view students.','sc-empty'));return;}$('scStudentList').replaceChildren(node('p','Loading students…','sc-empty'));$('scPrev').disabled=true;$('scNext').disabled=true;
 try{const data=await api('./special-class-preparation.php','GET',{action:'students',period_id:state.period,program_id:state.program,page:state.page,search:state.search});if(serial!==state.rosterRequest)return;
 state.students=data.students;state.totalPages=data.total_pages;$('scRosterCount').textContent=`${data.total} match(es) · showing ${data.students.length}`;$('scPage').textContent=`Page ${data.total_pages?data.page:0} / ${data.total_pages}`;
 $('scPrev').disabled=state.page<=1;$('scNext').disabled=state.page>=data.total_pages;renderRoster();}
 catch(err){if(serial!==state.rosterRequest)return;state.students=[];$('scStudentList').replaceChildren(node('p',err.message,'sc-empty'));$('scRosterCount').textContent='Roster unavailable';announce(err.message,'error');}}
 
function studentLabel(st){return `${st.student_number} · ${st.first_name} ${st.last_name} · ${st.home_section}${st.major_section?' + '+st.major_section:''}`;}

function renderRoster(){const target=$('scStudentList');target.replaceChildren();if(!state.students.length){target.append(node('p','No matching DEMO students for this program.','sc-empty'));return;}
 for(const st of state.students){const row=node('label',undefined,'sc-student');const check=node('input');check.type='checkbox';check.checked=state.selected.has(Number(st.student_id));check.disabled=!check.checked&&state.selected.size>=50;
 check.addEventListener('change',()=>{const id=Number(st.student_id);if(check.checked){if(state.selected.size>=50){check.checked=false;return;}state.selected.set(id,st);}else state.selected.delete(id);invalidate();renderSelected();renderRoster();});
 const details=node('span');details.append(node('strong',`${st.first_name} ${st.last_name}`),node('small',`${st.student_number} · ${st.home_section}${st.major_section?' + '+st.major_section:''}`));row.append(check,details);target.append(row);}}
 
function renderSelected(){const target=$('scSelectedItems');target.replaceChildren();if(!state.selected.size){target.append(node('p','No participants selected yet.'));updateButton();return;}
 for(const st of state.selected.values()){const row=node('div',undefined,'sc-selected-row');row.append(node('span',studentLabel(st)));const button=node('button','Remove','sc-text-button');button.type='button';button.addEventListener('click',()=>{state.selected.delete(Number(st.student_id));invalidate();renderSelected();renderRoster();});row.append(button);target.append(row);}updateButton();}
 
function formatDate(date){return new Date(date+'T12:00:00').toLocaleDateString('en-PH',{weekday:'short',month:'short',day:'numeric',year:'numeric'});}

function renderPrepared(result){const root=$('scResult');root.replaceChildren();$('scResultFlag').textContent='PREPARED · UNSAVED';$('scResultHint').textContent='Verified current subject authorization and student memberships; class time has not been assigned.';
 const info=node('div',undefined,'sc-result-grid');for(const [label,value] of [['Class type',result.class_type],['Subject',result.subject.subject_code+' — '+result.subject.subject_title],['Professor',result.teacher.teacher_name],['Students',result.participant_count],['Recurrence','Weekly'],['Occurrences',result.occurrence_count]]){const box=node('div',undefined,'sc-result-fact');box.append(node('small',label),node('strong',value));info.append(box);}root.append(info);
 root.append(node('h3','Proposed weekly dates'));const dates=node('div',undefined,'sc-date-list');for(const d of result.weekly_occurrences)dates.append(node('span',formatDate(d.date),'sc-date'));root.append(dates);
 const notice=node('div',undefined,'sc-result-warning');notice.append(node('strong','Not a generated timetable'),node('p','This request has no assigned time or room yet. Check the institution-approved policy and run the independent weekly preview before treating any assignments as feasible. No data was saved.'));root.append(notice);
}

async function prepare(){if(state.busy||$('scPrepare').disabled)return;state.busy=true;updateButton();announce('Verifying current faculty authorization, student memberships and weekly dates…', 'loading');
 const body={action:'prepare',period_id:state.period,program_id:state.program,class_type:$('scType').value,subject_id:Number($('scSubject').value),teacher_id:Number($('scTeacher').value),student_ids:[...state.selected.keys()],start_date:$('scStart').value,end_date:$('scEnd').value,weekdays:[...document.querySelectorAll('input[name="scDay"]:checked')].map(x=>x.value)};
 try{const data=await api('./special-class-preparation.php','POST',body);state.prepared=data;state.policyReady=false;renderPrepared(data);announce(`Prepared ${data.occurrence_count} proposed weekly date(s) for ${data.participant_count} student(s). UNSAVED; no time or room assigned.`,'success');$('scResult').scrollIntoView({behavior:'smooth',block:'nearest'});}
 catch(err){state.prepared=null;invalidate();announce(err.message,'error');}finally{state.busy=false;updateButton();if(state.prepared)checkPolicy();}
}

async function checkPolicy(){
 if(!state.prepared||state.busy)return;
 const original=state.prepared;
 try{
   const result=await api('./special-class-preview.php','GET',{
     period_id:state.period,program_id:state.program,class_type:original.class_type});
   if(state.prepared!==original)return;
   state.policyReady=Boolean(result.calendar?.calendar_ready===true && result.schedule_preview_available && result.status==='SPECIAL_CLASS_POLICY_RECORDED');
   const banner=node('div',undefined,'sc-result-warning');
   banner.append(node('strong',state.policyReady?'Policy approval record is available':'School policy pending'),
     node('p',state.policyReady?'Preview can be requested. No schedule has been generated or saved.':'Academic calendar, duration/delivery or Octoberian policy is pending. Generation and saving remain disabled.'));
   $('scResult').append(banner);updateButton();
 }catch(e){if(state.prepared!==original)return;state.policyReady=false;updateButton();announce(e.message,'error');}
}

async function generateWeeklyPreview(){
 if(!state.policyReady||!state.prepared||state.busy)return;
 const previous=state.prepared;
 state.busy=true;updateButton();announce('Checking current database facts and searching for a conflict-free weekly timetable…', 'loading');
 const body={action:'preview',period_id:state.period,program_id:state.program,class_type:previous.class_type,
   subject_id:Number($('scSubject').value),teacher_id:Number($('scTeacher').value),student_ids:[...state.selected.keys()],
   start_date:$('scStart').value,end_date:$('scEnd').value,
   weekdays:[...document.querySelectorAll('input[name="scDay"]:checked')].map(x=>x.value)};
 try{
   const result=await api('./special-class-preview.php','POST',body);
   if(state.prepared!==previous)return;
   if(result.status!=='SPECIAL_CLASS_PREVIEW_READY'||result.independent_audit?.passed!==true)throw Error('Weekly preview was not independently verified.');
   renderPrepared(previous);$('scResult').querySelector('.sc-result-warning')?.remove();$('scResultFlag').textContent='PREVIEW · UNSAVED';
   $('scResultHint').textContent='Proposed weekly meetings passed the independent checker. No data was saved.';
   const list=node('div',undefined,'sc-date-list');
   for(const meeting of result.assignments){
     list.append(node('span',`${meeting.meeting_date} · ${meeting.start_time}–${meeting.end_time} · ${meeting.delivery_mode} · ${meeting.room_id===null?'ONLINE':'Room ID '+meeting.room_id}`,'sc-date'));
   }
   $('scResult').append(node('h3','Conflict-checked weekly preview'),list);
   announce(`Unsaved preview: ${result.occurrence_count} meetings, ${result.independent_audit.total_issues} reported issues. No database write.`,'success');
 }catch(err){announce(err.message,'error');}finally{state.busy=false;updateButton();}
}

function clearRequest(){state.selected.clear();state.subject=null;state.faculty=[];state.page=1;state.search='';$('scSearch').value='';$('scType').value='REMEDIAL';$('scStart').value='';$('scEnd').value='';document.querySelectorAll('input[name="scDay"]').forEach(x=>x.checked=false);showSubjects();invalidate();renderSelected();loadRoster();announce('Request cleared. No database records were changed.', 'info');}

$('scProgram').addEventListener('change',()=>{state.program=Number($('scProgram').value)||null;state.selected.clear();state.page=1;state.search='';$('scSearch').value='';$('scSearch').disabled=!state.program;state.facultyRequest++;showSubjects();invalidate();renderSelected();loadRoster();checkCalendar();});
$('scSubject').addEventListener('change',()=>{invalidate();loadFaculty();});$('scTeacher').addEventListener('change',invalidate);
$('scType').addEventListener('change',()=>{invalidate();checkCalendar();});['scStart','scEnd'].forEach(id=>$(id).addEventListener('change',invalidate));document.querySelectorAll('input[name="scDay"]').forEach(x=>x.addEventListener('change',invalidate));
let searchTimer;$('scSearch').addEventListener('input',()=>{clearTimeout(searchTimer);state.page=1;state.search=$('scSearch').value.trim();searchTimer=setTimeout(loadRoster,230);});
$('scPrev').addEventListener('click',()=>{if(state.page>1){state.page--;loadRoster();}});$('scNext').addEventListener('click',()=>{if(state.page<state.totalPages){state.page++;loadRoster();}});
$('scPrepare').addEventListener('click',prepare);$('scCheckPolicy').addEventListener('click',checkPolicy);$('scGenerate').addEventListener('click',generateWeeklyPreview);$('scReset').addEventListener('click',clearRequest);

document.addEventListener('keydown',e=>{if((e.ctrlKey||e.metaKey)&&e.key.toLowerCase()==='k'){e.preventDefault();if(!$('scSearch').disabled)$('scSearch').focus();}});

catalog();
})();
</script>
</body>
</html>