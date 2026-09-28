<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/shared/auth.php';

authRequire();

/** Module 4: DEMO examination preview, guarded save, stored timetable viewing and printing. */

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

// Safe escape helper function
function dashEsc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$APP_ROOT = '../../';$ACTIVE_NAV = 'exam_generator';

// Safe variables for Topbar (Consistent session checking)
$role = (string) ($_SESSION['role'] ?? 'Admin');$username = trim((string) ($_SESSION['auth_username'] ?? $_SESSION['first_name'] ?? 'Admin'));
$initial = strtoupper(substr($username !== '' ? $username : 'A', 0, 1));$dashboardDate = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Exam Timetable Generator | BCP</title>
<link rel="icon" href="../../assets/images/BCP_LOGO.png" type="image/png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">

<link rel="stylesheet" href="../../assets/css/exam-timetable-generator.css">
</head>
<body class="bcp-exam-page">

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

    <main class="content bcp-exam">
      <div class="bcp-exam__shell">
          <header class="bcp-exam__header">
            <div>
              <p class="bcp-exam__eyebrow"><span class="bcp-exam__eyebrow-dot"></span> BCP CLASS SCHEDULING SYSTEM</p>
              <h1>Exam Timetable Generator<span class="bcp-exam__title-dot">.</span></h1>
              <p>Set one BCP-wide three-day examination period, then generate each program separately. Every saved program must use the same unified dates.</p>
            </div>
            <span class="bcp-exam__chip" title="Examination Management">DEMO · EXAM MANAGEMENT</span>
          </header>
          
          <section class="bcp-exam__panel" aria-label="Examination configuration">
           <div class="bcp-exam__controls">
             <div class="bcp-exam__control">
               <label for="examPeriod">Academic year / semester</label>
               <select id="examPeriod" required><option value="">Loading…</option></select>
             </div>
             <div class="bcp-exam__control">
               <label for="examProgram">Program</label>
               <select id="examProgram" required><option value="">Loading…</option></select>
             </div>
             <div class="bcp-exam__control">
               <label for="examWindow">Exam hours</label>
               <select id="examWindow">
                 <option value="06-21">6:00 AM – 9:00 PM</option>
                 <option value="18-21">6:00 PM – 9:00 PM (may be infeasible)</option>
               </select>
             </div>
             <div class="bcp-exam__control">
               <label for="examDate1">Day 1 · Date</label>
               <input id="examDate1" type="date" required>
             </div>
             <div class="bcp-exam__control">
               <label for="examDate2">Day 2 · Date</label>
               <input id="examDate2" type="date" required>
             </div>
             <div class="bcp-exam__control">
               <label for="examDate3">Day 3 · Date</label>
               <input id="examDate3" type="date" required>
             </div>
           </div>
           
           <div class="bcp-exam__actions">
             <button type="button" class="bcp-exam__btn-secondary" id="examSetDates" disabled><i class="fa-solid fa-calendar-check"></i> Set Unified Exam Dates</button>
             <button type="button" class="bcp-exam__btn-primary" id="examGenerate" disabled><i class="fa-solid fa-wand-magic-sparkles"></i> Generate Exam Preview</button>
             <button type="button" class="bcp-exam__btn-secondary bcp-exam__btn-success" id="examSave" disabled><i class="fa-solid fa-floppy-disk"></i> Confirm &amp; Save DEMO</button>
             <button type="button" class="bcp-exam__btn-secondary" id="examPrint" disabled><i class="fa-solid fa-print"></i> Print Timetable</button>
           </div>
           <p id="examStatus" class="bcp-exam__status" role="status" aria-live="polite"><i class="fa-solid fa-circle-notch fa-spin bcp-exam__status-icon"></i> Loading academic periods…</p>
          </section>
          
          <section id="examReport" hidden>
            <div class="bcp-exam__panel">
                <div class="bcp-exam__report-heading">
                  <div>
                    <p class="bcp-exam__eyebrow">BESTLINK COLLEGE OF THE PHILIPPINES · EXAMINATION TIMETABLE</p>
                    <h2 id="reportTitle">Examination Schedule</h2>
                    <p id="reportInfo"></p>
                  </div>
                  <span class="bcp-exam__chip bcp-exam__chip--warning" id="examReportBadge">UNSAVED DEMO PREVIEW</span>
                </div>
                
                <div class="bcp-exam__stats">
                  <div class="bcp-exam__stat-card"><span>Required exams</span><strong id="examRequired">—</strong></div>
                  <div class="bcp-exam__stat-card"><span>Scheduled exams</span><strong id="examAssigned">—</strong></div>
                  <div class="bcp-exam__stat-card"><span>Exam audit issues</span><strong id="examIssues">—</strong></div>
                  <div class="bcp-exam__stat-card"><span>Solver status</span><strong id="examSolver">—</strong></div>
                </div>
                
                <p class="bcp-exam__note">Day 3 is optional per student. All exams on a student's exam day must be consecutive with no vacant time, including actual Cluster + Major combinations. Cluster and Major are printed as separate official section timetables. DEMO timetable; check approved exam requirements before official use.</p>
            </div>
            
            <div id="examSections" class="bcp-exam__sections"></div>
            
            <footer class="bcp-exam__print-footer">
              <span>Prepared by: ________________________________</span>
              <span>Checked by: ________________________________</span>
              <span>Approved by: ________________________________</span>
            </footer>
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
 let result=null; let pending=false; let hasSaved=false; let activeExamBatchId=null;
 let unifiedConfigured=false; let unifiedLocked=false; let examDatesDirty=false; let unifiedActivePrograms=[];
 
 const make=(tag,text,cls)=>{const n=document.createElement(tag);if(cls)n.className=cls;if(text!==undefined)n.textContent=String(text);return n;};
 const format=time=>{const [hh,mm]=String(time).split(':').map(Number);return `${hh%12||12}:${String(mm).padStart(2,'0')} ${hh>=12?'PM':'AM'}`;};
 const niceDate=iso=>new Date(iso+'T12:00:00').toLocaleDateString('en-PH',{month:'long',day:'numeric',year:'numeric'});
 
 const dateInputValue=d=>`${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`;
 const nextSchoolDay=iso=>{
   const d=new Date(iso+'T12:00:00');
   do{d.setDate(d.getDate()+1);}while(d.getDay()===0);
   return dateInputValue(d);
 };

 function upgradeSelects() {
    document.querySelectorAll('.bcp-exam__control select').forEach(selectElem => {
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

 function setStatus(message, kind='info') {
    const statusEl = $('examStatus');
    const iconMap = {
        success: "fa-circle-check",
        error: "fa-triangle-exclamation",
        warning: "fa-triangle-exclamation",
        info: "fa-circle-info",
        loading: "fa-circle-notch fa-spin"
    };
    statusEl.dataset.kind = kind;
    statusEl.innerHTML = `<i class="fa-solid ${iconMap[kind] || iconMap.info} bcp-exam__status-icon"></i> ${message}`;
 }
 
 const currentDates=()=>[1,2,3].map(i=>$('examDate'+i).value);
 const datesValid=dates=>!dates.some(x=>!x)&&new Set(dates).size===3&&dates[0]<dates[1]&&dates[1]<dates[2];
 
 function applyDateLock(){
   for(let i=1;i<=3;i++)$('examDate'+i).disabled=unifiedLocked;
   $('examSetDates').disabled=pending||unifiedLocked;
   $('examSetDates').innerHTML=unifiedLocked?'<i class="fa-solid fa-lock"></i> Unified Exam Dates Locked':(unifiedConfigured?'<i class="fa-solid fa-calendar-check"></i> Update Unified Exam Dates':'<i class="fa-solid fa-calendar-plus"></i> Set Unified Exam Dates');
 }
 
 function invalidatePreview(message='Examination configuration changed. Generate a fresh preview before saving.'){
   if(result?.preview_token){result=null;$('examReport').hidden=true;$('examSave').disabled=true;$('examPrint').disabled=true;}$('examGenerate').disabled=pending||!unifiedConfigured||examDatesDirty;
   if(message)setStatus(message,'info');
 }
 
 async function api(endpoint,method,body){const r=await fetch('./'+endpoint,{method,credentials:'same-origin',cache:'no-store',headers:{'Content-Type':'application/json'},body:body?JSON.stringify(body):undefined});
   let data;try{data=await r.json();}catch{throw Error('Endpoint returned invalid JSON. Check PHP error log.');}
   if(!r.ok||data.success!==true)throw Error(data.message||data.status||'Exam request failed.');return data;}
   
 async function loadUnifiedPeriod(){
   unifiedConfigured=false;unifiedLocked=false;examDatesDirty=false;unifiedActivePrograms=[];
   $('examSetDates').disabled=true;$('examGenerate').disabled=true;
   const periodId=$('examPeriod').value;
   if(!periodId)return;
   try{
     const data=await api('exam-period.php?period_id='+encodeURIComponent(periodId),'GET');
     unifiedConfigured=Boolean(data.configured);unifiedLocked=Boolean(data.locked);unifiedActivePrograms=data.active_programs||[];
     if(Array.isArray(data.exam_dates)&&data.exam_dates.length===3){for(let i=1;i<=3;i++)$('examDate'+i).value=data.exam_dates[i-1];}
     else{for(let i=1;i<=3;i++)$('examDate'+i).value='';}
     applyDateLock();
     $('examGenerate').disabled=!unifiedConfigured;
     if(unifiedConfigured){
       const programs=unifiedActivePrograms.length?` Active exam programs: ${unifiedActivePrograms.join(', ')}.`:'';
       setStatus(unifiedLocked?`Unified BCP exam dates are locked because ${data.active_exam_batch_count} ACTIVE exam batch(es) already exist.${programs}`:'Unified BCP exam dates are set. You may update them until the first ACTIVE exam timetable is saved.','info');
     }else{
       setStatus('Choose Day 1, review the auto-filled Day 2 and Day 3, then click Set Unified Exam Dates before generating.','info');
     }
   }catch(e){applyDateLock();setStatus(e.message,'error');}
 }
 
 async function saveUnifiedPeriod(){
   if(pending||unifiedLocked)return;
   const dates=currentDates();
   if(!datesValid(dates)){setStatus('Choose three different Monday–Saturday dates in ascending order.','error');return;}
   pending=true;$('examSetDates').disabled=true;$('examGenerate').disabled=true;
   setStatus('Saving the BCP-wide examination dates for this academic period…','loading');
   try{
     const data=await api('exam-period.php','POST',{period_id:Number($('examPeriod').value),exam_dates:dates});
     unifiedConfigured=true;unifiedLocked=Boolean(data.locked);examDatesDirty=false;unifiedActivePrograms=data.active_programs||[];
     if(Array.isArray(data.exam_dates))for(let i=1;i<=3;i++)$('examDate'+i).value=data.exam_dates[i-1];
     applyDateLock();$('examGenerate').disabled=false;
     setStatus(unifiedLocked?'Unified examination dates are already locked and unchanged.':'Unified BCP examination dates saved. All program exam generators for this academic period must use these same dates.','success');
   }catch(e){setStatus(e.message,'error');}
   finally{pending=false;applyDateLock();$('examGenerate').disabled=!unifiedConfigured||examDatesDirty;}
 }
 
 async function loadSaved(){
   result=null;hasSaved=false;activeExamBatchId=null;$('examReport').hidden=true;$('examPrint').disabled=true;$('examSave').disabled=true;
   $('examGenerate').disabled=true;$('examGenerate').innerHTML='<i class="fa-solid fa-wand-magic-sparkles"></i> Generate Exam Preview';$('examSave').innerHTML='<i class="fa-solid fa-floppy-disk"></i> Confirm & Save DEMO';
   const program=$('examProgram').value;
   if(!program){setStatus('Choose a program.','info');return;}
   try{const data=await api('exam-saved.php?period_id='+encodeURIComponent($('examPeriod').value)+'&program='+encodeURIComponent(program),'GET');
     if(data.has_saved_exams){hasSaved=true;activeExamBatchId=Number(data.exam_batch_id);result=data;data.solver_status='SAVED';
       for(let i=1;i<=3;i++){$('examDate'+i).value=data.exam_dates[i-1];}
       render(data);$('examReportBadge').textContent='SAVED DEMO · BATCH #'+data.exam_batch_id;
       $('examReportBadge').classList.remove('bcp-exam__chip--warning');
       $('examGenerate').disabled=false;$('examGenerate').innerHTML='<i class="fa-solid fa-code-compare"></i> Regenerate Exam Preview';$('examSave').innerHTML='<i class="fa-solid fa-triangle-exclamation"></i> Confirm & Replace DEMO';
       setStatus(`Loaded ACTIVE DEMO exam batch #${data.exam_batch_id}: ${data.returned_exams} saved exams. You may print it or generate a replacement preview; the current batch will remain unchanged until confirmation.`,'success');
     }else{$('examGenerate').disabled=!unifiedConfigured||examDatesDirty;
       if(unifiedConfigured)setStatus(unifiedLocked?'Unified BCP exam dates are locked. Generate this program using the same school-wide dates.':'Unified dates are ready. Generate the program preview when ready.','info');}
   }catch(e){setStatus(e.message,'error');}
 }
 
 async function init(){try{const data=await api('exam-preview.php','GET');const p=$('examPeriod');p.replaceChildren();
   for(const item of data.periods||[]){const opt=new Option(`${item.academic_year} · Semester ${item.semester} (DEMO)`,item.academic_period_id);p.add(opt);}
   const programs=$('examProgram');programs.replaceChildren();
   for(const item of data.programs||[]){programs.add(new Option(`${item.program_code} · ${item.program_name}`,item.program_code));}
   if(!(data.periods||[]).length)throw Error('No DEMO academic period is available.');
   if(!(data.programs||[]).length)throw Error('No active college program is available.');
   if([...programs.options].some(o=>o.value==='BSIT'))programs.value='BSIT';
   upgradeSelects();
   await loadUnifiedPeriod();await loadSaved();
 }catch(e){setStatus(e.message,'error');}}
 
 function printSection(section,entries,dates){
   const paper=make('article',undefined,'bcp-exam__sheet');
   const title=make('div',undefined,'bcp-exam__sheet-top');
   title.append(make('h3',section.print_title || `Section ${section.section_code} · Year ${section.year_level}`),make('span',section.section_type));
   paper.append(title);
   const grid=make('div',undefined,'bcp-exam__day-grid');
   for(let d=1;d<=3;d++){
     const day=make('section',undefined,'bcp-exam__day');day.append(make('h4',`DAY ${d} · ${niceDate(dates[d-1])}`));
     const rows=entries.filter(x=>x.exam_day===d).sort((a,b)=>a.start_time.localeCompare(b.start_time));
     if(!rows.length){day.append(make('p','No examination for this section.','bcp-exam__empty-day'));grid.append(day);continue;}
     
     const wrap = make('div', undefined, 'bcp-exam__tablewrap');
     const table=make('table');const thead=make('thead');const tr=make('tr');
     for(const heading of ['Time','Subject','Room','Proctor'])tr.append(make('th',heading));thead.append(tr);table.append(thead);
     const tbody=make('tbody');for(const e of rows){const t=make('tr');for(const value of [`${format(e.start_time)} – ${format(e.end_time)}`,`${e.subject_code} · ${e.subject_title}`,e.room_name,e.proctor_name])t.append(make('td',value));tbody.append(t);}table.append(tbody);
     wrap.append(table);
     day.append(wrap);
     grid.append(day);
   }
   paper.append(grid);paper.append(make('p','Each exam is 60 minutes. All exams shown for the section are consecutive per exam day. Fourth-year students must also check their individual Cluster and Major membership.','bcp-exam__sheet-note'));
   return paper;
 }
 
 function render(data){$('examReport').hidden=false;$('reportTitle').textContent=`${data.program||$('examProgram').value} · Examination Timetable`;
  $('reportInfo').textContent=`${data.period.academic_year} · Semester ${data.period.semester} · Class batch #${data.class_batch_id} · ${data.exam_dates.map((x,i)=>`Day ${i+1}:${niceDate(x)}`).join('  |  ')}`;
  $('examRequired').textContent=data.required_exams;$('examAssigned').textContent=data.returned_exams;
  
  $('examIssues').textContent=(data.issues || []).length;
  
  $('examSolver').textContent=data.solver_status||'SAVED';
  const groups=new Map();for(const row of data.assignments){if(!groups.has(row.section_id))groups.set(row.section_id,{section_code:row.section_code,section_type:row.section_type,year_level:row.year_level,rows:[]});groups.get(row.section_id).rows.push(row);}
  const target=$('examSections');target.replaceChildren();
  const ordered=[...groups.values()].sort((a,b)=>a.year_level-b.year_level||a.section_code.localeCompare(b.section_code));
  for(const group of ordered)target.append(printSection(group,group.rows,data.exam_dates));
  $('examPrint').disabled=false;
 }
 
 $('examGenerate').addEventListener('click',async()=>{
 if(pending)return;
 const dates=currentDates();
 if(!unifiedConfigured){setStatus('Set the unified BCP examination dates before generating a program timetable.','error');return;}
 if(examDatesDirty){setStatus('The date fields changed. Save the unified examination dates before generating.','error');return;}
 if(!datesValid(dates)){setStatus('Choose three different dates in ascending order.','error');return;}
 pending=true;result=null;$('examReport').hidden=true;$('examPrint').disabled=true;$('examSave').disabled=true;$('examGenerate').disabled=true;
 setStatus('Python OR-Tools is checking three exam days, rooms, fixed section proctors, student conflicts, and same-subject paper rotation. Please wait…','loading');
 const program=$('examProgram').value;
 if(!program){setStatus('Choose a program.','error');pending=false;return;}
 try{const replacing=hasSaved;const data=await api(replacing?'exam-replacement-preview.php':'exam-preview.php','POST',{program,period_id:Number($('examPeriod').value),exam_dates:dates,exam_window:$('examWindow').value,...(replacing?{replace_exam_batch_id:activeExamBatchId}:{})});
 if(data.status!=='EXAM_PREVIEW_READY'||data.returned_exams!==data.required_exams||(data.issues||[]).length||data.gap_audit?.passed!==true||!data.preview_token||(hasSaved && (!data.replacement_preview||Number(data.replace_exam_batch_id)!==activeExamBatchId)))throw Error('Generated preview is incomplete, failed the result audit or has a changed replacement baseline.');
 result=data;render(data);$('examReportBadge').textContent=hasSaved?'UNSAVED REPLACEMENT PREVIEW':'UNSAVED DEMO PREVIEW';$('examReportBadge').classList.add('bcp-exam__chip--warning');$('examSave').disabled=false;setStatus(`Zero-gap ${hasSaved?'replacement ':''}preview ready: ${data.returned_exams} / ${data.required_exams} exams; ${data.gap_audit.checked_student_groups} student groups checked. ${hasSaved?'The previous ACTIVE exam batch is unchanged. ':''}Review the proposed timetable before confirming.`, 'success');}
 catch(e){setStatus(e.message,'error');}finally{pending=false;$('examGenerate').disabled=!unifiedConfigured||examDatesDirty;applyDateLock();}
 });
 
 $('examPeriod').addEventListener('change',async()=>{if(!pending){await loadUnifiedPeriod();await loadSaved();}});$('examProgram').addEventListener('change',async()=>{if(!pending)await loadSaved();});
 $('examSetDates').addEventListener('click',saveUnifiedPeriod);$('examDate1').addEventListener('change',()=>{
   if(pending||unifiedLocked)return;
   const d1=$('examDate1').value;
   if(d1){const d2=nextSchoolDay(d1);$('examDate2').value=d2;$('examDate3').value=nextSchoolDay(d2);}
   examDatesDirty=true;invalidatePreview('Day 2 and Day 3 were auto-filled. Review the dates, then save the unified BCP examination period.');
 });
 for(const id of ['examDate2','examDate3']){
   $(id).addEventListener('change',()=>{if(pending||unifiedLocked)return;examDatesDirty=true;invalidatePreview('Unified examination dates changed. Save them before generating.');});
 }
 $('examWindow').addEventListener('change',()=>{if(!pending)invalidatePreview('Exam hours changed. Generate a fresh preview before saving.');});
 
 $('examSave').addEventListener('click',async()=>{
  if(pending||!result?.preview_token)return;
  const replacing=hasSaved;
  if(replacing && (!result.replacement_preview||Number(result.replace_exam_batch_id)!==activeExamBatchId))return;
  if(!window.confirm(replacing?`Replace ACTIVE exam batch #${activeExamBatchId} with this validated DEMO preview? The old batch will remain in the database as SUPERSEDED.`:'Save this new DEMO examination timetable?'))return;
  pending=true;$('examSave').disabled=true;$('examGenerate').disabled=true;
  setStatus('Rechecking current database facts and exam conflicts before saving…','loading');
  try{const saved=await api(replacing?'exam-replacement-save.php':'exam-save.php','POST',{preview_token:result.preview_token});
      if(saved.status!==(replacing?'EXAM_REPLACED':'EXAM_SAVED'))throw Error('Exam save did not finish.');
      await loadUnifiedPeriod();
      await loadSaved();
  }catch(e){setStatus(e.message,'error');$('examSave').disabled=false;}
  finally{pending=false;$('examGenerate').disabled=!unifiedConfigured||examDatesDirty;applyDateLock();}
 });
 
 $('examPrint').addEventListener('click' ,()=>{if(result&&!pending)window.print();});
 init();
})();
</script>
</body></html>