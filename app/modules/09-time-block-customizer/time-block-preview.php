<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/shared/auth.php';
authRequire();
/** Module 9 Phase 9B. Read-only visual time block preview; original Phase 9A UI remains unchanged. */
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Time Block Preview | BCP</title>
<link rel="stylesheet" href="../../assets/css/time-block-preview.css">
</head><body>
<main class="tb9b-app" id="tb9bApp">
<header class="tb9b-hero"><div><p class="tb9b-kicker">BCP · MODULE 9 · PHASE 9B</p>
<h1>Time Block Customizer</h1><p>Compare proposed changes against the actual database slots and saved schedules.</p></div>
<span class="tb9b-pill">PREVIEW ONLY · NO SAVING</span></header>
<div class="tb9b-alert" role="note"><strong>Global impact:</strong> The existing <code>time_slots</code> table is shared across academic periods. Changing a slot could affect ALL active periods and programs. This page never edits time slots, subject hours, or saved timetables. <a href="time-block-customizer.php">View original inventory</a></div>
<section class="tb9b-panel tb9b-control-panel"><div class="tb9b-heading"><div><span class="tb9b-step">01</span><h2>Preview an adjustment</h2></div><span class="tb9b-muted">Every input change refreshes the simulation.</span></div>
<div class="tb9b-form">
<label>Scheduling day<select id="tb9bDay" disabled><option>Loading…</option></select></label>
<label>Proposed action<select id="tb9bAction" disabled><option value="DISABLE">Disable a time range</option><option value="ENABLE">Enable a time range</option></select></label>
<label>Start time<select id="tb9bFrom" disabled><option>Loading…</option></select></label>
<label>End time<select id="tb9bTo" disabled><option>Loading…</option></select></label></div>
<div class="tb9b-actions"><button id="tb9bRefresh" type="button" disabled>Recheck impact</button><button id="tb9bPrint" type="button" class="tb9b-secondary" disabled>Print preview report</button></div>
<p id="tb9bStatus" class="tb9b-status" role="status" aria-live="polite">Loading actual time slots…</p></section>
<section id="tb9bResult" class="tb9b-result" hidden>
<section class="tb9b-panel"><div class="tb9b-heading"><div><span class="tb9b-step">02</span><h2>Current vs. proposed configuration</h2></div><span id="tb9bScope" class="tb9b-muted"></span></div>
<div class="tb9b-metrics" id="tb9bMetrics"></div>
<div class="tb9b-compare">
<div class="tb9b-timeline"><h3>Current database slots</h3><p>Original ACTIVE / INACTIVE status, unchanged.</p><div id="tb9bBefore" class="tb9b-grid" aria-label="Current database time slots"></div></div>
<div class="tb9b-timeline"><h3>Proposed simulation</h3><p>Highlighted blocks would change only in this preview.</p><div id="tb9bAfter" class="tb9b-grid" aria-label="Proposed time slots"></div></div></div>
<p class="tb9b-muted">Example 60-minute and 120-minute consecutive windows are derived from the database blocks. These are <strong>not</strong> new official class-duration rules.</p>
<div id="tb9bWindows" class="tb9b-window-list"></div></section>
<section class="tb9b-panel"><div class="tb9b-heading"><div><span class="tb9b-step">03</span><h2>Saved timetable impact</h2></div><span id="tb9bImpactCount" class="tb9b-count"></span></div>
<p id="tb9bImpactNote" class="tb9b-status"></p>
<div class="tb9b-table-wrap"><table><thead><tr><th>Booking</th><th>Batch / period</th><th>Program / section</th><th>Subject</th><th>Day / date</th><th>Scheduled time</th></tr></thead><tbody id="tb9bImpacts"></tbody></table></div>
<div class="tb9b-alert tb9b-endnote"><strong>Saving remains disabled.</strong> A preview with no listed impact is not approval to modify source slots. Complete school-policy review, period-specific configuration, fresh conflict audits, and a safe regeneration/replacement workflow are required before an edit feature can be introduced.</div>
</section></section>
</main><script>
(()=>{'use strict';
const $=id=>document.getElementById(id);const WEEK=['Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
let catalog=null,last=null,requestSerial=0;
function node(tag,text,className){const n=document.createElement(tag);if(text!==undefined)n.textContent=String(text);if(className)n.className=className;return n;}
function message(s,error=false){$('tb9bStatus').textContent=s;$('tb9bStatus').dataset.error=error?'true':'false';}
function time(v){return String(v).slice(0,5);}
function options(id,values,keep){const sel=$(id);sel.replaceChildren();for(const v of values)sel.add(new Option(v,v));if(values.includes(keep))sel.value=keep;sel.disabled=!values.length;}
function daySlots(){return (catalog?.slots??[]).filter(s=>s.day_of_week===$('tb9bDay').value).sort((a,b)=>time(a.start_time).localeCompare(time(b.start_time)));}
function selectedTimes(){const slots=daySlots(),oldFrom=$('tb9bFrom').value,oldTo=$('tb9bTo').value;
const starts=[...new Set(slots.map(s=>time(s.start_time)))],ends=[...new Set(slots.map(s=>time(s.end_time)))];
options('tb9bFrom',starts,oldFrom||'06:00');options('tb9bTo',ends,oldTo||'07:00');
if(!$('tb9bTo').value||$('tb9bTo').value<=$('tb9bFrom').value){const next=ends.find(v=>v>$('tb9bFrom').value);if(next)$('tb9bTo').value=next;}
}
function stat(parent,label,value){const e=node('div',undefined,'tb9b-metric');e.append(node('span',label),node('strong',value));parent.append(e);}
function slotGrid(id,rows,mode){const parent=$(id);parent.replaceChildren();const frag=document.createDocumentFragment();
for(const slot of rows){const b=node('div',undefined,'tb9b-slot '+(slot.is_active?'tb9b-on':'tb9b-off')+(mode==='after'&&slot.would_change?' tb9b-changed':''));
b.append(node('span',slot.start_time+'–'+slot.end_time),node('strong',slot.is_active?'ACTIVE':'INACTIVE'));
if(mode==='after'&&slot.would_change)b.append(node('small','PROPOSED'));
b.title='Time slot #'+slot.time_slot_id;frag.append(b);}parent.append(frag);}
function windowCard(label,orig,after){const box=node('div',undefined,'tb9b-window');box.append(node('h3',label));
box.append(node('p',`Current: ${orig.count} possible starts · Proposed: ${after.count} possible starts`));
box.append(node('p',after.examples.length?'Example proposed windows: '+after.examples.join(', '):'No consecutive windows of this duration remain.'));
return box;}
function draw(r){last=r;$('tb9bResult').hidden=false;$('tb9bPrint').disabled=false;
$('tb9bScope').textContent=r.requested_change.day+' · '+r.requested_change.day_pattern+' · '+r.requested_change.start_time+'–'+r.requested_change.end_time;
$('tb9bMetrics').replaceChildren();const m=$('tb9bMetrics');
stat(m,'Selected slots',r.selected_slot_ids.length);stat(m,'Would change',r.changed_slot_count);
stat(m,'Current active blocks',r.before.active_slots);stat(m,'Proposed active blocks',r.after.active_slots);
slotGrid('tb9bBefore',r.source_slots,'before');slotGrid('tb9bAfter',r.proposed_slots,'after');
const windows=$('tb9bWindows');windows.replaceChildren(windowCard('60-minute example',r.before.one_hour_windows,r.after.one_hour_windows),windowCard('120-minute example',r.before.two_hour_windows,r.after.two_hour_windows));
$('tb9bImpactCount').textContent=r.affected_saved_meeting_count+' affected';
$('tb9bImpactNote').textContent=r.no_effect?'This range already has the selected status. No time slots would change.':r.requested_change.action==='ENABLE'?'Enabling inactive blocks does not remove existing scheduled time. Availability and institutional policies still require review.':r.affected_saved_meeting_count?'These ACTIVE saved meetings use blocks that would be disabled. Do not apply the proposed change.':'No impacted ACTIVE saved meetings found in the checked tables. This is NOT a final authorization to change the global time slots.';
const body=$('tb9bImpacts');body.replaceChildren();if(!r.affected_saved_meetings.length){const tr=node('tr'),td=node('td','No affected ACTIVE meeting returned by this read-only check.');td.colSpan=6;tr.append(td);body.append(tr);return;}
for(const x of r.affected_saved_meetings){const tr=node('tr');for(const text of [x.booking_type+' #'+x.booking_id,'#'+x.batch_id+' / #'+x.academic_period_id,x.program_code+' / '+x.section_code,x.subject_code,x.weekday+(x.meeting_date?' · '+x.meeting_date:''),x.start_time+'–'+x.end_time])tr.append(node('td',text));body.append(tr);}}
async function preview(){if(!catalog||!$('tb9bFrom').value||!$('tb9bTo').value)return;
const serial=++requestSerial;$('tb9bRefresh').disabled=true;$('tb9bPrint').disabled=true;last=null;$('tb9bResult').hidden=true;
if($('tb9bFrom').value>=$('tb9bTo').value){message('End time must be after start time.',true);$('tb9bRefresh').disabled=false;return;}
message('Checking existing global time slots and ACTIVE saved meetings…');
try{const response=await fetch('time-block-preview-api.php',{method:'POST',credentials:'same-origin',cache:'no-store',headers:{'Accept':'application/json','Content-Type':'application/json'},body:JSON.stringify({day:$('tb9bDay').value,start_time:$('tb9bFrom').value,end_time:$('tb9bTo').value,action:$('tb9bAction').value})});
const r=await response.json();if(serial!==requestSerial)return;if(!response.ok||r.success!==true)throw Error(r.message||r.status||'Preview failed.');
draw(r);message(`Preview ready: ${r.changed_slot_count} slot(s) would change; ${r.affected_saved_meeting_count} ACTIVE meeting(s) affected. Nothing was saved.`);
}catch(e){if(serial===requestSerial)message(e.message||'Preview failed; check Apache log.',true);}finally{if(serial===requestSerial)$('tb9bRefresh').disabled=false;}}
async function init(){try{const response=await fetch('time-block-api.php?action=catalog',{cache:'no-store',credentials:'same-origin',headers:{Accept:'application/json'}});const r=await response.json();if(!response.ok||r.success!==true)throw Error(r.message||r.status||'Unable to load inventory.');
if(r.truncated)throw Error('Inventory is truncated; preview is blocked to avoid incomplete source coverage.');
const mandatory=['time_slot_id','day_of_week','day_pattern','start_time','end_time','is_active','data_origin'];if(!mandatory.every(c=>r.columns.includes(c)))throw Error('Unexpected time_slots schema. No preview will be produced.');
catalog=r;options('tb9bDay',WEEK,WEEK[0]);$('tb9bAction').disabled=false;selectedTimes();$('tb9bRefresh').disabled=false;await preview();
}catch(e){message(e.message||'Unable to load the database inventory.',true);}}
$('tb9bDay').addEventListener('change',()=>{selectedTimes();preview();});for(const id of ['tb9bAction','tb9bFrom','tb9bTo'])$(id).addEventListener('change',preview);
$('tb9bRefresh').addEventListener('click',preview);$('tb9bPrint').addEventListener('click',()=>{if(last)window.print();});init();
})();
</script></body></html>
