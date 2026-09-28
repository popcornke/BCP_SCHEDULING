<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/shared/auth.php';

authRequire();

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

$APP_ROOT = '../../';$ACTIVE_NAV = 'conflict_checker';

// Safe escape helper function para maiwasan ang undefined function error
function dashEsc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$role = (string) ($_SESSION['role'] ?? 'Admin');$initial = strtoupper(substr($_SESSION['first_name'] ?? 'A', 0, 1));$dashboardDate = new DateTimeImmutable('now', new DateTimeZone('Asia/Manila'));
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Conflict Checker | BCP Class Scheduling</title>
  <link rel="icon" href="../../assets/images/BCP_LOGO.png" type="image/png">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
  
  <link rel="stylesheet" href="../../assets/css/conflict-checker.css">
</head>
<body class="bcp-conflict-page">

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

    <main class="content bcp-conflict">
      <div class="bcp-conflict__shell">
        <header class="bcp-conflict__header">
          <div>
            <p class="bcp-conflict__eyebrow"><span class="bcp-conflict__eyebrow-dot"></span> BCP CLASS SCHEDULING SYSTEM</p>
            <h1>Conflict Checker<span class="bcp-conflict__title-dot">.</span></h1>
            <p>Check saved class meetings across all ACTIVE DEMO programs in the selected academic period.</p>
          </div>
          <span class="bcp-conflict__tag">DEMO · READ ONLY</span>
        </header>
        
        <section class="bcp-conflict__panel bcp-conflict__toolbar" aria-label="Audit settings">
          <div class="bcp-conflict__control">
            <label for="ccPeriod">Academic year / semester</label>
            <div class="bcp-custom-select-wrapper">
                <select id="ccPeriod" disabled><option>Loading academic periods…</option></select>
            </div>
          </div>
          <button type="button" id="ccRun" class="bcp-conflict__btn-primary" disabled><i class="fa-solid fa-shield-halved"></i> Run Saved-Schedule Check</button>
          <p id="ccStatus" class="bcp-conflict__status" role="status" aria-live="polite"><i class="fa-solid fa-circle-notch fa-spin bcp-conflict__status-icon"></i> Loading…</p>
        </section>
        
        <section id="ccSummary" class="bcp-conflict__panel" hidden aria-label="Audit results">
          <div class="bcp-conflict__summary">
            <div class="bcp-conflict__summary-card"><span>Audit result</span><strong id="ccOutcome">—</strong></div>
            <div class="bcp-conflict__summary-card"><span>ACTIVE batches</span><strong id="ccBatches">0</strong></div>
            <div class="bcp-conflict__summary-card"><span>Saved meetings checked</span><strong id="ccMeetings">0</strong></div>
            <div class="bcp-conflict__summary-card"><span>Issues found</span><strong id="ccIssueCount">0</strong></div>
          </div>
          <p id="ccScope" class="bcp-conflict__muted"></p>
          <div id="ccWarnings" class="bcp-conflict__warnings" hidden></div>
        </section>
        
        <section id="ccFindings" class="bcp-conflict__panel" hidden>
          <div class="bcp-conflict__findingsbar">
            <div>
              <h2>Detailed findings</h2>
              <p id="ccTotalLabel" class="bcp-conflict__muted"></p>
            </div>
            <div class="bcp-conflict__filters">
              <div class="bcp-conflict__control">
                <label for="ccType">Issue type</label>
                <div class="bcp-custom-select-wrapper">
                    <select id="ccType"><option value="">All issue types</option></select>
                </div>
              </div>
              <div class="bcp-conflict__control">
                <label for="ccSearch">Search findings</label>
                <div class="bcp-conflict-search-box">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input id="ccSearch" type="search" autocomplete="off" placeholder="Section, subject, meeting ID…">
                </div>
              </div>
            </div>
          </div>
          <div id="ccIssueList" class="bcp-conflict__issues"></div>
        </section>
        
        <p class="bcp-conflict__footer">This read-only report checks saved records. It does not rerun the Python optimizer or replace the independent pre-save audit.</p>
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
  let report = null;
  let requestId = 0;
  
  const node = (tag, value, cls='') => {
    const el = document.createElement(tag);
    if (cls) el.className = cls;
    if (value !== undefined) el.textContent = String(value);
    return el;
  };

  function upgradeSelects() {
      document.querySelectorAll('.bcp-conflict__control select').forEach(selectElem => {
          if (selectElem.parentElement.classList.contains('bcp-custom-select-initialized')) return;
          
          selectElem.style.display = 'none';
          const wrapper = selectElem.parentElement;
          wrapper.classList.add('bcp-custom-select-initialized');
          
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
  
  function status(message, kind='info') {
    const statusEl = $('ccStatus');
    const iconMap = {
        success: "fa-circle-check",
        error: "fa-triangle-exclamation",
        warning: "fa-triangle-exclamation",
        info: "fa-circle-info",
        loading: "fa-circle-notch fa-spin"
    };
    statusEl.dataset.kind = kind;
    statusEl.innerHTML = `<i class="fa-solid ${iconMap[kind] || iconMap.info} bcp-conflict__status-icon"></i> ${message}`;
  }
  
  async function json(url) {
    const response = await fetch(url,{credentials:'same-origin',cache:'no-store'});
    let data;
    try { data=await response.json(); }
    catch { throw new Error('API returned invalid JSON. Check the PHP error log.'); }
    if (!response.ok || data.success!==true) throw new Error(data.message || data.status || 'Unable to load audit.');
    return data;
  }
  
  function makeIssue(issue) {
    const item=node('article',undefined,'bcp-conflict__issue');
    const head=node('div',undefined,'bcp-conflict__issuehead');
    head.append(node('strong',issue.type),node('span','Meeting IDs: '+(issue.meeting_ids?.join(', ') || 'n/a')));
    item.append(head,node('p',issue.message));
    const info=issue.details || {};
    if (info.first) item.append(node('p',info.first,'bcp-conflict__detail'));
    if (info.second) item.append(node('p',info.second,'bcp-conflict__detail'));
    if (info.meeting) item.append(node('p',info.meeting,'bcp-conflict__detail'));
    const other=Object.entries(info).filter(([key])=>!['first','second','meeting'].includes(key));
    if (other.length) item.append(node('p',other.map(([key,value])=>key+': '+value).join(' · '),'bcp-conflict__detail'));
    return item;
  }
  
  function renderIssues() {
    if (!report) return;
    const type=$('ccType').value;
    const keyword=$('ccSearch').value.trim().toLowerCase();
    const results=(report.audit.issues || []).filter(issue =>
      (!type || issue.type===type) && (!keyword || JSON.stringify(issue).toLowerCase().includes(keyword)));
    $('ccIssueList').replaceChildren();
    if (!results.length) {
      $('ccIssueList').append(node('div',report.audit.total_issues===0
        ? 'No issues were found by this saved-record audit. Check warnings and coverage before treating this as complete validation.'
        : 'No findings match the current filters.','bcp-conflict__empty'));
    } else {
      results.forEach(item=>$('ccIssueList').append(makeIssue(item)));
    }
    $('ccTotalLabel').textContent=results.length+' of '+report.audit.total_issues+' findings shown';
  }
  
  function render(data) {
    report=data;
    const audit=data.audit;
    $('ccSummary').hidden=false;
    $('ccFindings').hidden=false;
    $('ccOutcome').textContent=audit.status;
    $('ccOutcome').dataset.ok=audit.passed?'true':'false';
    $('ccBatches').textContent=audit.checked_batches;
    $('ccMeetings').textContent=audit.checked_meetings;
    $('ccIssueCount').textContent=audit.total_issues;
    $('ccScope').textContent='Programs checked: '+(data.active_programs.length?data.active_programs.join(', '):'none')
      +' · Section-subjects: '+audit.checked_section_subjects
      +' · Student memberships: '+audit.checked_student_memberships;
    const warningBox=$('ccWarnings');
    warningBox.replaceChildren();
    warningBox.hidden=!audit.warnings.length;
    (audit.warnings||[]).forEach(w=>warningBox.append(node('p',w)));
    const options=Object.keys(audit.issue_counts||{}).sort();
    
    $('ccType').replaceChildren(new Option('All issue types',''));
    options.forEach(t=>$('ccType').add(new Option(t+' ('+audit.issue_counts[t]+')',t)));$('ccSearch').value='';
    renderIssues();
    status(audit.total_issues+' issue(s) found in '+audit.checked_meetings+' saved meetings.'+(audit.warnings.length?' Coverage warnings need attention.':''),
      audit.total_issues?'error':audit.warnings.length?'warning':'success');
  }
  
  async function run() {
    const seq=++requestId;
    $('ccRun').disabled=true;
    status('Checking all ACTIVE DEMO schedules for the selected academic period…', 'loading');
    try {
      const result=await json('./conflict-check.php?period_id='+encodeURIComponent($('ccPeriod').value));
      if(seq!==requestId) return;
      render(result);
    } catch(e) {
      if(seq!==requestId) return;
      report=null;
      $('ccSummary').hidden=true;
      $('ccFindings').hidden=true;
      status(e.message,'error');
    } finally { if(seq===requestId) $('ccRun').disabled=false; }
  }
  
  async function initialize() {
    try {
      const catalog=await json('../../api/program-catalog.php');
      const periods=(catalog.periods||[]).filter(p=>p.period_status==='DEMO');
      const period=$('ccPeriod');
      period.replaceChildren();
      periods.forEach(p=>period.add(new Option(p.academic_year+' · Semester '+p.semester+' (DEMO)',String(p.academic_period_id))));
      period.disabled=!periods.length;
      $('ccRun').disabled=!periods.length;
      if (!periods.length) { status('No DEMO academic period is available.','warning'); return; }
      const selection=catalog.selected_period?.academic_period_id;
      if(selection && periods.some(p=>Number(p.academic_period_id)===Number(selection))) period.value=String(selection);
      upgradeSelects();
      await run();
    } catch(e) { status(e.message,'error'); }
  }
  
  $('ccRun').addEventListener('click',run);$('ccPeriod').addEventListener('change',run);
  $('ccType').addEventListener('change',renderIssues);$('ccSearch').addEventListener('input',renderIssues);
  
  initialize();
})();
</script>
</body>
</html>