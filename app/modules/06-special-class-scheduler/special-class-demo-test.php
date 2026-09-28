<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/shared/auth.php';

authRequire();


/** Isolated Phase 6F LOCAL DEMO page. No official scheduling policy or save action. */
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1','::1'], true)) {
    http_response_code(403);
    exit('This read-only DEMO page is accessible from localhost only.');
}
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>BCP — Isolated Weekly Class DEMO</title>
<link rel="stylesheet" href="../../assets/css/special-class-scheduler.css">
</head><body><main class="sc-app">
<header class="sc-hero"><div><p class="sc-kicker">BCP CLASS SCHEDULING SYSTEM · MODULE 6 · ISOLATED TEST</p>
<h1>DEMO Weekly Schedule Test</h1><p>Try the existing conflict checker against current DEMO database records. Nothing is saved.</p></div>
<span class="sc-pill">SIMULATION · NOT SCHOOL APPROVAL</span></header>
<section class="sc-panel"><div class="sc-alert" role="note"><strong>Test values only:</strong> BSIT · Academic period #1 ·
60-minute face-to-face session · sample test window September 25–December 31, 2026.
These dates and duration are <strong>not official BCP policy</strong>. The regular calendar and policy gates remain untouched.
Octoberian generation remains unavailable pending its separate rules.</div>
<div class="sc-fields">
<label>Special-class category<select id="type"><option value="REMEDIAL">Remedial (DEMO)</option><option value="IRREGULAR">Special / irregular (DEMO)</option></select></label>
<label>Subject<select id="subject"><option value="">Loading subjects…</option></select></label>
<label>Authorized teacher<select id="teacher" disabled><option value="">Select subject first</option></select></label>
<label>Weekly weekday<select id="weekday"><option value="Thursday">Thursday</option><option value="Monday">Monday</option><option value="Tuesday">Tuesday</option><option value="Wednesday">Wednesday</option><option value="Friday">Friday</option><option value="Saturday">Saturday</option></select></label>
<label>First date<input type="date" id="start" min="2026-09-25" max="2026-12-31" value="2026-10-01"></label>
<label>Last date<input type="date" id="end" min="2026-09-25" max="2026-12-31" value="2026-10-29"></label>
</div></section>
<section class="sc-panel"><h2>Choose actual DEMO participants</h2>
<p>Choose up to 50 students. Only selected students are checked; home and Major sections remain unchanged.</p>
<div class="sc-search"><label for="search">Search name, number, or section</label><input type="search" id="search" maxlength="60" placeholder="Search students"></div>
<div class="sc-roster-tools"><span id="found">Loading roster…</span><strong id="selected">0 selected</strong></div>
<div class="sc-student-list" id="roster"></div>
<div class="sc-pager"><button type="button" class="sc-secondary" id="prev" disabled>Previous</button>
<span id="page">—</span><button type="button" class="sc-secondary" id="next" disabled>Next</button></div>
</section>
<section class="sc-panel"><label><input type="checkbox" id="ack">
I understand the sample calendar and 60-minute session are DEMO test settings, not institutional approval.
</label>
<div class="sc-actions"><button type="button" id="generate" class="sc-primary" disabled>Run conflict-checked DEMO preview</button></div>
<p role="status" aria-live="polite" id="status" class="sc-status">Loading current DEMO records…</p>
</section>
<section class="sc-panel" id="result" hidden><h2>Read-only DEMO result</h2><p id="summary"></p>
<div id="meetings"></div><p>Test preview only. No official calendar/policy was approved and no database records were written.</p></section>
</main>
<script>
(()=>{'use strict';
const $=id=>document.getElementById(id), selected=new Map();
let page=1,pages=0,busy=false,rosterNonce=0;
const note=(value,bad=false)=>{$('status').textContent=value;$('status').dataset.kind=bad?'error':'info'};
const elt=(tag,text)=>{const n=document.createElement(tag);if(text!==undefined)n.textContent=String(text);return n};
async function get(file,params){const url=new URL(file,location.href);Object.entries(params).forEach(([k,v])=>url.searchParams.set(k,String(v)));
 const resp=await fetch(url,{cache:'no-store',credentials:'same-origin'});const data=await resp.json();if(!resp.ok||!data.success)throw Error(data.message||data.status||'Request failed');return data;}
function update(){ $('selected').textContent=selected.size+' selected';$('generate').disabled=busy||selected.size<1||!$('subject').value||!$('teacher').value||!$('start').value||!$('end').value||!$('ack').checked;}
async function loadSubjects(){const data=await get('special-class-api.php',{period_id:1});
 $('subject').replaceChildren(new Option('Choose a subject',''));(data.subjects||[]).filter(s=>Number(s.program_id)===4).forEach(s=>$('subject').add(new Option(s.subject_code+' — '+s.subject_title,String(s.subject_id))));note('Select a subject, professor, students and dates to test.');}
async function loadFaculty(){ $('teacher').disabled=true;$('teacher').replaceChildren(new Option('Loading…',''));update();if(!$('subject').value)return;
 const current=$('subject').value;const data=await get('special-class-preparation.php',{action:'faculty',period_id:1,program_id:4,subject_id:current});if(current!==$('subject').value)return;
 $('teacher').replaceChildren(new Option('Choose authorized professor',''));(data.faculty||[]).forEach(t=>$('teacher').add(new Option(t.teacher_name,String(t.teacher_id))));$('teacher').disabled=false;update();}
async function loadStudents(){const nonce=++rosterNonce;const data=await get('special-class-preparation.php',{action:'students',period_id:1,program_id:4,page,search:$('search').value.trim()});if(nonce!==rosterNonce)return;
 pages=Number(data.total_pages||0);$('found').textContent=data.total+' students found';$('page').textContent='Page '+page+' / '+Math.max(1,pages);$('prev').disabled=page<=1;$('next').disabled=page>=pages;
 $('roster').replaceChildren();(data.students||[]).forEach(st=>{const row=elt('label');row.className='sc-student-item';const box=elt('input');box.type='checkbox';box.checked=selected.has(Number(st.student_id));
 box.addEventListener('change',()=>{const id=Number(st.student_id);if(box.checked&&selected.size>=50){box.checked=false;note('Select up to 50 students.',true);return;}box.checked?selected.set(id,st.student_number):selected.delete(id);update();});row.append(box,elt('span',st.student_number+' · '+st.first_name+' '+st.last_name+' · '+st.home_section+(st.major_section?' / Major '+st.major_section:'')));$('roster').append(row);});update();}
$('subject').addEventListener('change',()=>loadFaculty().catch(e=>note(e.message,true)));$('teacher').addEventListener('change',update);
$('prev').addEventListener('click',()=>{if(page>1){page--;loadStudents().catch(e=>note(e.message,true));}});
$('next').addEventListener('click',()=>{if(page<pages){page++;loadStudents().catch(e=>note(e.message,true));}});
let searchTimer; $('search').addEventListener('input',()=>{clearTimeout(searchTimer);searchTimer=setTimeout(()=>{page=1;loadStudents().catch(e=>note(e.message,true));},280);});
for(const id of ['start','end','ack','weekday','type'])$(id).addEventListener('change',update);
$('generate').addEventListener('click',async()=>{if(busy||!$('ack').checked)return;busy=true;update();$('result').hidden=true;note('Checking the active class/exam/substitution records with Python OR-Tools. Please wait…');
 try{const response=await fetch('special-class-demo-preview.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/json','Accept':'application/json'},body:JSON.stringify({demo_acknowledged:true,period_id:1,program_id:4,class_type:$('type').value,subject_id:Number($('subject').value),teacher_id:Number($('teacher').value),student_ids:[...selected.keys()],start_date:$('start').value,end_date:$('end').value,weekdays:[$('weekday').value]})});
 const data=await response.json();if(!response.ok||!data.success)throw Error(data.message||data.status||'No valid DEMO schedule');
 if(data.status!=='SPECIAL_CLASS_DEMO_PREVIEW_READY'||data.independent_audit?.passed!==true)throw Error('Incomplete or unverified DEMO response.');
 $('summary').textContent=`${data.participant_count} students · ${data.occurrence_count} weekly meetings · 60-minute F2F (SIMULATED, UNSAVED).`;
 const table=elt('table'),head=elt('tr');['Date','Day','Start','End','Room ID','Teacher ID'].forEach(t=>head.append(elt('th',t)));table.append(head);
 data.assignments.forEach(a=>{const tr=elt('tr');[a.meeting_date,a.day_of_week,a.start_time,a.end_time,a.room_id,a.teacher_id].forEach(v=>tr.append(elt('td',v===null?'Online':v)));table.append(tr);});
 $('meetings').replaceChildren(table);$('result').hidden=false;note('DEMO preview passed independent audit. No records were saved.');
 }catch(e){note(e.message,true);}finally{busy=false;update();}});
Promise.all([loadSubjects(),loadStudents()]).catch(e=>note(e.message,true));
})();
</script></body></html>
