<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/shared/auth.php';

authRequire();


/** BCP Module 8 Phase 8A. Read-only inventory, no timetable generation or save. */


$APP_ROOT = '../../';
require_once __DIR__ . '/../../includes/sidebar.php';
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Schedule Cloning Tool | BCP</title>
<link rel="stylesheet" href="../../assets/css/schedule-cloning-tool.css">
</head><body><main class="clone-app" id="cloneApp">
<header class="clone-hero"><div><p class="clone-kicker">BCP CLASS SCHEDULING SYSTEM · MODULE 8</p>
<h1>Schedule Cloning Tool</h1><p>Inspect a saved source timetable and a separately prepared target academic period. No timetable is copied by this check.</p></div>
<span class="clone-pill">READ-ONLY · DEMO</span></header>
<section class="clone-panel" aria-labelledby="cloneTitle"><div class="clone-heading"><span class="clone-step">01</span><div><h2 id="cloneTitle">Select source &amp; target</h2><p>Only existing ACTIVE DEMO timetables can be selected as a source.</p></div></div>
<div class="clone-form">
<label>Source timetable<select id="cloneSource" disabled><option>Loading source batches…</option></select></label>
<label>Target academic period<select id="cloneTarget" disabled><option>Loading academic periods…</option></select></label>
</div><p class="clone-status" id="cloneStatus" role="status" aria-live="polite">Loading saved timetable records…</p>
<p class="clone-help">The target period and its official sections/curriculum must exist in the database first. This module does not create a new academic period, section, section-subject assignment, or student enrollment.</p>
<div class="clone-actions"><button id="cloneCheck" type="button" disabled>Check target readiness</button><button id="clonePrint" type="button" class="clone-secondary" disabled>Print readiness report</button></div>
</section>
<section class="clone-panel" id="cloneResult" aria-labelledby="cloneResultTitle" hidden><div class="clone-heading"><span class="clone-step">02</span><div><h2 id="cloneResultTitle">Source / target readiness</h2><p id="cloneSubtitle">This is an inventory comparison, not a generated schedule.</p></div></div>
<div class="clone-stats" id="cloneStats"></div><div id="cloneFindings"></div>
<div class="clone-warning"><strong>Cloning and saving are not enabled.</strong> Matching section names or subject codes alone does not prove that the target timetable is conflict-free. An approved target curriculum, updated teacher/room availability, school-wide conflict audit, and a safe transaction workflow are still required.</div>
</section>
</main><script>
(()=>{'use strict';
const $=id=>document.getElementById(id);
const el=(tag,content,cls)=>{const n=document.createElement(tag);if(content!==undefined)n.textContent=String(content);if(cls)n.className=cls;return n;};
let catalog=null,result=null;
const setStatus=(message,bad=false)=>{$('cloneStatus').textContent=message;$('cloneStatus').dataset.error=bad?'true':'false';};
async function api(params){const url=new URL('schedule-cloning-api.php',location.href);for(const [k,v] of Object.entries(params))url.searchParams.set(k,String(v));
const response=await fetch(url,{credentials:'same-origin',cache:'no-store',headers:{Accept:'application/json'}});
let payload;try{payload=await response.json();}catch{throw Error('PHP did not return JSON. Check the Apache error log.');}
if(!response.ok||payload.success!==true)throw Error(payload.message||payload.status||'Unable to load the clone readiness report.');
return payload;}
const periodLabel=p=>`${p.academic_year} · Semester ${p.semester} (${p.period_status})`;
function populateTargets(){const source=catalog.source_batches.find(b=>String(b.batch_id)===$('cloneSource').value);const target=$('cloneTarget');const prior=target.value;
target.replaceChildren(new Option('Select an existing target period',''));
for(const p of catalog.periods){if(source && Number(p.academic_period_id)===Number(source.academic_period_id))continue;
target.add(new Option(periodLabel(p),String(p.academic_period_id)));}
if([...target.options].some(o=>o.value===prior))target.value=prior;
target.disabled=target.options.length<=1;$('cloneCheck').disabled=target.disabled||!$('cloneSource').value||!target.value;
result=null;$('cloneResult').hidden=true;$('clonePrint').disabled=true;
if(target.disabled)setStatus('No different DEMO academic period exists yet. Your saved source remains unchanged. Prepare the target period and its approved data outside this module first.');
else setStatus('Choose a target academic period, then check its existing section and subject records.');}
function addStat(label,value){const box=el('div');box.append(el('span',label),el('strong',value));$('cloneStats').append(box);}
function addFinding(code,message){const item=el('li');item.append(el('strong',code.replaceAll('_',' ')),el('p',message));return item;}
function addMappingTable(title,items,columns){const section=el('section',undefined,'clone-table-section');section.append(el('h3',title));
const scroll=el('div',undefined,'clone-table-scroll'),table=el('table'),head=el('thead'),row=el('tr');for(const col of columns)row.append(el('th',col[0]));head.append(row);table.append(head);
const body=el('tbody');for(const item of items){const tr=el('tr');for(const col of columns)tr.append(el('td',item[col[1]]??'—'));body.append(tr);}table.append(body);scroll.append(table);section.append(scroll);return section;}
function render(r){$('cloneResult').hidden=false;$('clonePrint').disabled=false;$('cloneStats').replaceChildren();$('cloneFindings').replaceChildren();
$('cloneSubtitle').textContent=`Source: ${r.source.program_code} · ${r.source.academic_year} semester ${r.source.semester} · batch #${r.source.batch_id} → Target: ${periodLabel(r.target_period)}`;
addStat('Source meetings',r.source_meeting_count);addStat('Source sections',r.source_section_count);addStat('Section–subject pairs',r.source_section_subject_count);addStat('Target sections',r.target_section_count);
const findings=$('cloneFindings');const h=el('h3','Inventory findings');findings.append(h);
if(r.blockers.length){const ul=el('ul',undefined,'clone-findings');for(const b of r.blockers)ul.append(addFinding(b.code,b.message));findings.append(ul);}
else findings.append(el('p','No blocker found in this limited mapping inventory. Clone authorization is STILL disabled: final requirements have not been verified.','clone-notice'));
findings.append(el('h3','Still required before any future cloning or saving'));
const ul=el('ul',undefined,'clone-requirements');for(const requirement of r.unverified_requirements)ul.append(el('li',requirement));findings.append(ul);
findings.append(addMappingTable('Section mappings',r.section_mapping,[['Source section','section_code'],['Type','section_type'],['Year','year_level'],['Target section ID','target_section_id'],['Status','mapping_status']]));
findings.append(addMappingTable('Section–subject mappings',r.section_subject_mapping,[['Section','section_code'],['Subject code','subject_code'],['Target section–subject ID','target_section_subject_id'],['Status','mapping_status']]));
}
async function check(){if(!$('cloneSource').value||!$('cloneTarget').value)return;$('cloneCheck').disabled=true;$('clonePrint').disabled=true;setStatus('Checking target record mappings (read-only)…');
try{const data=await api({action:'assess',source_batch_id:$('cloneSource').value,target_period_id:$('cloneTarget').value});result=data;render(data);setStatus(`Readiness inventory complete. ${data.blockers.length} recorded blocker(s). Cloning remains disabled.`);}
catch(err){setStatus(err.message,true);$('cloneResult').hidden=true;result=null;}finally{$('cloneCheck').disabled=$('cloneTarget').disabled||!$('cloneTarget').value;}}
async function init(){try{catalog=await api({action:'catalog'});const sources=$('cloneSource');sources.replaceChildren(new Option('Select a saved ACTIVE source batch',''));
for(const b of catalog.source_batches)sources.add(new Option(`${b.program_code} · ${b.academic_year} S${b.semester} · Batch #${b.batch_id} · ${b.meeting_count} meetings`,String(b.batch_id)));
sources.disabled=catalog.source_batches.length===0;sources.addEventListener('change',populateTargets);
$('cloneTarget').addEventListener('change',()=>{$('cloneCheck').disabled=!$('cloneTarget').value;$('cloneResult').hidden=true;$('clonePrint').disabled=true;});
$('cloneCheck').addEventListener('click',check);$('clonePrint').addEventListener('click',()=>{if(result)window.print();});
if(sources.disabled)setStatus('No ACTIVE DEMO source timetable exists. No cloning operation is available.');
else {sources.value=String(catalog.source_batches[0].batch_id);populateTargets();}}
catch(err){setStatus(err.message,true);}}
init();
})();
</script></body></html>
