<?php
// ============================================================
// SIDEBAR.PHP (includes/)
// Shared responsive sidebar
// ============================================================

$APP_ROOT   = $APP_ROOT   ?? '../';
$ACTIVE_NAV = $ACTIVE_NAV ?? '';
?>

<style>
    /* Shared Sidebar */
    .sidebar {
        width: 210px;
        flex-shrink: 0;
        background: #1a3a8c;
        color: #fff;
        display: flex;
        flex-direction: column;
        position: fixed;
        top: 0;
        left: 0;
        height: 100dvh;
        overflow: hidden;
        z-index: 1200;
        font-family: 'Plus Jakarta Sans', 'Segoe UI', sans-serif;
        transition: width .25s ease, transform .28s ease, box-shadow .28s ease;
        will-change: transform;
    }

    .sidebar.collapsed {
        width: 0;
    }

    .sidebar-header {
        flex-shrink: 0;
    }

    .sidebar-nav {
        flex: 1;
        min-height: 0;
        overflow-y: auto;
        overflow-x: hidden;
        padding-bottom: 20px;
        display: flex;
        flex-direction: column;
        overscroll-behavior: contain;
    }

    .sidebar-nav::-webkit-scrollbar {
        width: 4px;
    }

    .sidebar-nav::-webkit-scrollbar-track {
        background: transparent;
    }

    .sidebar-nav::-webkit-scrollbar-thumb {
        background: rgba(255, 255, 255, .25);
        border-radius: 4px;
    }

    .sidebar-nav::-webkit-scrollbar-thumb:hover {
        background: rgba(255, 255, 255, .45);
    }

    .sidebar-logo {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 14px 16px 12px;
        border-bottom: 1px solid rgba(255, 255, 255, .12);
    }

    .sidebar-logo a {
        display: inline-flex;
        line-height: 0;
    }

    .sidebar-logo a:hover .sidebar-logo-img {
        opacity: .85;
        transition: opacity .2s;
    }

    .sidebar-logo-img {
        width: 46px;
        height: auto;
        object-fit: contain;
    }

    .sidebar-notif {
        position: relative;
        cursor: pointer;
        color: #c8d8f5;
        display: flex;
        align-items: center;
    }

    .sidebar-notif:hover {
        color: #fff;
    }

    .sidebar-notif-badge {
        position: absolute;
        top: -3px;
        right: -3px;
        width: 8px;
        height: 8px;
        background: #ef4444;
        border-radius: 50%;
        border: 2px solid #1a3a8c;
        display: none;
    }

    .sidebar-notif-badge.has-notif {
        display: block;
    }

    .sidebar-brand {
        padding: 10px 16px;
        border-bottom: 1px solid rgba(255, 255, 255, .12);
        white-space: nowrap;
    }

    .sidebar-brand-2 {
        border-top: none;
        border-bottom: 1px solid rgba(255, 255, 255, .12);
        padding: 10px 16px;
    }

    .sidebar-brand .brand-title {
        font-size: .82rem;
        font-weight: 700;
        line-height: 1.2;
        color: #fff;
    }

    .sidebar-brand .brand-sub {
        font-size: .65rem;
        color: #8ab4f8;
        margin-top: 2px;
    }

    .sidebar-divider {
        height: 1px;
        background: rgba(255, 255, 255, .12);
        margin: 8px 0;
        flex-shrink: 0;
    }

    .sidebar-item,
    a.sidebar-item {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px 20px;
        font-size: .82rem;
        color: #c8d8f5;
        cursor: pointer;
        transition: color .15s ease, background .15s ease;
        text-decoration: none;
        white-space: nowrap;
        width: 100%;
        background: none;
        border: none;
        text-align: left;
        font-family: inherit;
        box-sizing: border-box;
    }

    .sidebar-item:hover {
        color: #fff;
    }

    .sidebar-item i,
    .sidebar-item svg {
        flex-shrink: 0;
        width: 16px;
        text-align: center;
    }

    .sidebar-item span {
        flex: 1;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .sidebar-item .arrow {
        margin-left: auto;
        opacity: .7;
        flex-shrink: 0;
        transition: transform .2s ease;
    }

    .sidebar-item.open .arrow {
        transform: rotate(180deg);
    }

    .nav-group {
        display: flex;
        flex-direction: column;
        margin: 2px 8px;
        border-radius: 10px;
        overflow: hidden;
        transition: background .15s;
        flex-shrink: 0;
    }

    .nav-group:hover,
    .nav-group:has(.sidebar-item.open),
    .nav-group:has(.sidebar-item.active) {
        background: rgba(255, 255, 255, .13);
    }

    .nav-group:hover .sidebar-item,
    .nav-group:has(.sidebar-item.open) .sidebar-item,
    .nav-group:has(.sidebar-item.active) .sidebar-item {
        color: #fff;
    }

    .dropdown-menu {
        max-height: 0;
        overflow: hidden;
        transition: max-height .25s ease;
        background: rgba(0, 0, 0, .15);
    }

    .dropdown-menu.open {
        max-height: 280px;
    }

    .dropdown-item {
        display: block;
        padding: 8px 20px 8px 44px;
        font-size: .78rem;
        color: #a8c0f0;
        text-decoration: none;
        white-space: nowrap;
        transition: background .15s, color .15s;
    }

    .dropdown-item:hover {
        background: rgba(255, 255, 255, .1);
        color: #fff;
    }

    /* ============================================================
   LOGOUT
   ============================================================ */

    .sidebar-logout-form {
        margin: auto 12px 0;
        padding-top: 16px;
        border-top: 1px solid rgba(255, 255, 255, .12);
    }

    .sidebar-logout {
        display: flex;
        align-items: center;
        gap: 10px;
        padding: 10px 16px;
        width: 100%;
        font-family: inherit;
        font-size: .85rem;
        font-weight: 600;
        color: #fca5a5;
        background: rgba(239, 68, 68, .1);
        border: 1px solid rgba(239, 68, 68, .2);
        border-radius: 8px;
        cursor: pointer;
        text-align: left;
        transition: all .2s ease;
    }

    .sidebar-logout i {
        flex-shrink: 0;
        width: 16px;
        text-align: center;
    }

    .sidebar-logout:hover {
        background: #ef4444;
        color: #fff;
        border-color: #ef4444;
        box-shadow: 0 4px 12px rgba(239, 68, 68, .25);
    }

    /* ============================================================
   MOBILE / TABLET BACKDROP
   ============================================================ */

    .sidebar-backdrop {
        position: fixed;
        inset: 0;
        z-index: 1190;
        background: rgba(15, 23, 42, .46);
        -webkit-backdrop-filter: blur(3px);
        backdrop-filter: blur(3px);
        opacity: 0;
        visibility: hidden;
        pointer-events: none;
        transition: opacity .24s ease, visibility .24s ease;
    }

    .sidebar-backdrop.show {
        opacity: 1;
        visibility: visible;
        pointer-events: auto;
    }

    body.sidebar-open {
        overflow: hidden;
    }

    /* ============================================================
   TABLET / MOBILE
   ============================================================ */

    @media(max-width: 1024px) {

        .sidebar,
        .sidebar.collapsed {
            width: min(290px, 86vw);
            transform: translateX(-100%);
            box-shadow: none;
        }

        .sidebar.open,
        .sidebar.collapsed.open {
            transform: translateX(0);
            box-shadow: 18px 0 42px rgba(15, 23, 42, .28);
        }

        /*
       Important:
       module content becomes full width.
       Sidebar no longer pushes/takes half the screen.
    */
        .main,
        body:has(.sidebar.collapsed) .main,
        body:has(.sidebar.open) .main {
            margin-left: 0 !important;
            width: 100%;
            max-width: 100%;
        }
    }

    @media(max-width: 600px) {

        .sidebar,
        .sidebar.collapsed {
            width: min(280px, 88vw);
        }
    }

    @media(min-width: 1025px) {
        .sidebar-backdrop {
            display: none !important;
        }
    }

    @media(prefers-reduced-motion: reduce) {

        .sidebar,
        .sidebar-backdrop,
        .dropdown-menu,
        .sidebar-item .arrow {
            transition: none !important;
        }
    }
</style>

<aside class="sidebar" id="sidebar">
    <div class="sidebar-header">
        <div class="sidebar-logo">
            <a href="<?= $APP_ROOT ?>dashboard/dashboard.php" title="Go to Dashboard">
                <img src="<?= $APP_ROOT ?>assets/images/BCP_LOGO.png" alt="BCP Logo" class="sidebar-logo-img" />
            </a>
            <span class="sidebar-notif" id="bellBtn" title="Notifications">
                <i class="fa-solid fa-bell"></i>
                <span class="sidebar-notif-badge" id="bellBadge"></span>
            </span>
        </div>
    </div>

    <div class="sidebar-nav">
        <!-- =====================================================
             MAIN NAVIGATION
             ===================================================== -->
        <div class="sidebar-brand sidebar-brand-2">
            <div class="brand-title">Main Navigation</div>
            <div class="brand-sub">General</div>
        </div>

        <div class="nav-group">
            <a href="<?= $APP_ROOT ?>dashboard/dashboard.php" class="sidebar-item <?= $ACTIVE_NAV === 'dashboard' ? 'active' : '' ?>" title="Dashboard">
                <i class="fa-solid fa-gauge"></i>
                <span>Dashboard</span>
            </a>
        </div>

        <div class="sidebar-divider"></div>

        <!-- =====================================================
             SYSTEM MODULES
             ===================================================== -->
        <div class="sidebar-brand sidebar-brand-2">
            <div class="brand-title">System Modules</div>
            <div class="brand-sub">Tools & Schedulers</div>
        </div>

        <!-- =====================================================
             MODULE 1: SECTION ASSIGNMENT
             ===================================================== -->
        <div class="nav-group">
            <button type="button" class="sidebar-item <?= in_array($ACTIVE_NAV, ['section_assignment', 'schedule_preview', 'student_schedule_view'], true) ? 'active open' : '' ?> dropdown-trigger" data-target="dropSection" title="Section Assignment">
                <i class="fa-solid fa-users-viewfinder"></i>
                <span>Section Assignment</span>
                <i class="fa-solid fa-chevron-down arrow"></i>
            </button>
            <div class="dropdown-menu <?= in_array($ACTIVE_NAV, ['section_assignment', 'schedule_preview', 'student_schedule_view'], true) ? 'open' : '' ?>" id="dropSection">
                <a href="<?= $APP_ROOT ?>section/schedule-preview.php" class="dropdown-item">Schedule Preview</a>
                <a href="<?= $APP_ROOT ?>section/student-schedule-view.php" class="dropdown-item">Student Schedule View</a>
            </div>
        </div>

        <!-- =====================================================
             MODULE 2: TEACHER MAPPING
             ===================================================== -->
        <div class="nav-group">
            <a href="<?= $APP_ROOT ?>modules/02-teacher-schedule-mapping/teacher-schedule-mapping.php" class="sidebar-item <?= $ACTIVE_NAV === 'teacher_mapping' ? 'active' : '' ?>" title="Teacher Mapping">
                <i class="fa-solid fa-chalkboard-user"></i>
                <span>Teacher Mapping</span>
            </a>
        </div>

        <!-- =====================================================
             MODULE 3: CONFLICT CHECKER
             ===================================================== -->
        <div class="nav-group">
            <a href="<?= $APP_ROOT ?>modules/03-conflict-checker/conflict-checker.php" class="sidebar-item <?= $ACTIVE_NAV === 'conflict_checker' ? 'active' : '' ?>" title="Conflict Checker">
                <i class="fa-solid fa-triangle-exclamation"></i>
                <span>Conflict Checker</span>
            </a>
        </div>

        <!-- =====================================================
             MODULE 4: EXAM TIMETABLE
             ===================================================== -->
        <div class="nav-group">
            <a href="<?= $APP_ROOT ?>modules/04-exam-timetable-generator/exam-timetable-generator.php" class="sidebar-item <?= $ACTIVE_NAV === 'exam_generator' ? 'active' : '' ?>" title="Exam Timetable">
                <i class="fa-solid fa-file-signature"></i>
                <span>Exam Timetable</span>
            </a>
        </div>

        <!-- =====================================================
             MODULE 5: SUBSTITUTE TRACKER
             ===================================================== -->
        <div class="nav-group">
            <a href="<?= $APP_ROOT ?>modules/05-substitute-assignment-tracker/substitute-assignment-tracker.php" class="sidebar-item <?= $ACTIVE_NAV === 'substitute_tracker' ? 'active' : '' ?>" title="Substitute Tracker">
                <i class="fa-solid fa-user-clock"></i>
                <span>Substitute Tracker</span>
            </a>
        </div>

        <!-- =====================================================
             MODULE 6: SPECIAL CLASS SCHEDULER
             ===================================================== -->
        <div class="nav-group">
            <button type="button" class="sidebar-item <?= in_array($ACTIVE_NAV, ['special_class', 'special_preview', 'special_demo_preview', 'special_demo_test'], true) ? 'active open' : '' ?> dropdown-trigger" data-target="dropSpecial" title="Special Class Scheduler">
                <i class="fa-solid fa-star"></i>
                <span>Special Class Scheduler</span>
                <i class="fa-solid fa-chevron-down arrow"></i>
            </button>
            <div class="dropdown-menu <?= in_array($ACTIVE_NAV, ['special_class', 'special_preview', 'special_demo_preview', 'special_demo_test'], true) ? 'open' : '' ?>" id="dropSpecial">
                <a href="<?= $APP_ROOT ?>modules/06-special-class-scheduler/special-class-scheduler.php" class="dropdown-item">Main Tool</a>
                <a href="<?= $APP_ROOT ?>modules/06-special-class-scheduler/special-class-preview.php" class="dropdown-item">Class Preview</a>
                <a href="<?= $APP_ROOT ?>modules/06-special-class-scheduler/special-class-demo-preview.php" class="dropdown-item">Demo Preview</a>
                <a href="<?= $APP_ROOT ?>modules/06-special-class-scheduler/special-class-demo-test.php" class="dropdown-item">Demo Test</a>
            </div>
        </div>

        <!-- =====================================================
             MODULE 7: ROOM AVAILABILITY
             ===================================================== -->
        <div class="nav-group">
            <a href="<?= $APP_ROOT ?>modules/07-room-availability-checker/room-availability-checker.php" class="sidebar-item <?= $ACTIVE_NAV === 'room_checker' ? 'active' : '' ?>" title="Room Availability">
                <i class="fa-solid fa-door-open"></i>
                <span>Room Availability</span>
            </a>
        </div>

        <!-- =====================================================
             MODULE 8: SCHEDULE CLONING
             ===================================================== -->
        <div class="nav-group">
            <a href="<?= $APP_ROOT ?>modules/08-schedule-cloning-tool/schedule-cloning-tool.php" class="sidebar-item <?= $ACTIVE_NAV === 'schedule_cloning' ? 'active' : '' ?>" title="Schedule Cloning">
                <i class="fa-solid fa-clone"></i>
                <span>Schedule Cloning</span>
            </a>
        </div>

        <!-- =====================================================
             MODULE 9: TIME BLOCK CUSTOMIZER
             ===================================================== -->
        <div class="nav-group">
            <button type="button" class="sidebar-item <?= in_array($ACTIVE_NAV, ['time_blocks', 'time_block_preview'], true) ? 'active open' : '' ?> dropdown-trigger" data-target="dropTimeBlocks" title="Time Block Customizer">
                <i class="fa-solid fa-clock"></i>
                <span>Time Block Customizer</span>
                <i class="fa-solid fa-chevron-down arrow"></i>
            </button>
            <div class="dropdown-menu <?= in_array($ACTIVE_NAV, ['time_blocks', 'time_block_preview'], true) ? 'open' : '' ?>" id="dropTimeBlocks">
                <a href="<?= $APP_ROOT ?>modules/09-time-block-customizer/time-block-customizer.php" class="dropdown-item">Main Tool</a>
                <a href="<?= $APP_ROOT ?>modules/09-time-block-customizer/time-block-preview.php" class="dropdown-item">Time Block Preview</a>
            </div>
        </div>

        <!-- =====================================================
             MODULE 10: CALENDAR INTEGRATION
             ===================================================== -->
        <div class="nav-group">
            <a href="<?= $APP_ROOT ?>modules/10-calendar-integration/calendar-integration.php" class="sidebar-item <?= $ACTIVE_NAV === 'calendar_integration' ? 'active' : '' ?>" title="Calendar Integration">
                <i class="fa-solid fa-calendar-days"></i>
                <span>Calendar Integration</span>
            </a>
        </div>

        <!-- =====================================================
             LOGOUT
             ===================================================== -->
        <form method="POST" action="/logout.php" class="sidebar-logout-form">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(function_exists('authCsrf') ? authCsrf() : '', ENT_QUOTES, 'UTF-8') ?>">
            <button type="submit" class="sidebar-logout">
                <i class="fa-solid fa-right-from-bracket"></i>
                <span>Logout</span>
            </button>
        </form>

    </div>
</aside>

<!-- Mobile/Tablet Background Overlay -->
<div class="sidebar-backdrop" id="sidebarBackdrop" aria-hidden="true"></div>

<!-- ============================================================
     SHARED SIDEBAR JAVASCRIPT
     ============================================================ -->
<script>
    (() => {
        'use strict';

        const BREAKPOINT = 1024;

        function initSidebar() {
            const sidebar = document.getElementById('sidebar');
            const backdrop = document.getElementById('sidebarBackdrop');
            const hamburger = document.getElementById('hamburgerBtn');

            if (!sidebar) {
                return;
            }

            const isMobile = () => window.innerWidth <= BREAKPOINT;

            function setExpanded(value) {
                if (!hamburger) return;
                hamburger.setAttribute('aria-expanded', value ? 'true' : 'false');
                hamburger.setAttribute('aria-controls', 'sidebar');
            }

            function closeDrawer() {
                sidebar.classList.remove('open');
                backdrop?.classList.remove('show');
                backdrop?.setAttribute('aria-hidden', 'true');
                document.body.classList.remove('sidebar-open');
                setExpanded(false);
            }

            function openDrawer() {
                sidebar.classList.remove('collapsed');
                sidebar.classList.add('open');
                backdrop?.classList.add('show');
                backdrop?.setAttribute('aria-hidden', 'false');
                document.body.classList.add('sidebar-open');
                setExpanded(true);
            }

            /*
             * IMPORTANT
             * Some existing module pages still have: sidebar.classList.toggle('collapsed')
             * We stop those old listeners here so the responsive sidebar is controlled centrally.
             */
            if (hamburger && hamburger.dataset.sidebarResponsiveBound !== '1') {
                hamburger.dataset.sidebarResponsiveBound = '1';

                hamburger.addEventListener('click', event => {
                    event.preventDefault();
                    event.stopImmediatePropagation();

                    /* MOBILE / TABLET */
                    if (isMobile()) {
                        if (sidebar.classList.contains('open')) {
                            closeDrawer();
                        } else {
                            openDrawer();
                        }
                        return;
                    }

                    /* DESKTOP */
                    closeDrawer();
                    sidebar.classList.toggle('collapsed');
                    setExpanded(!sidebar.classList.contains('collapsed'));
                }, true);
            }

            /* CLICK BACKDROP */
            backdrop?.addEventListener('click', closeDrawer);

            /* ESCAPE KEY */
            document.addEventListener('keydown', event => {
                if (event.key === 'Escape' && isMobile() && sidebar.classList.contains('open')) {
                    closeDrawer();
                    hamburger?.focus();
                }
            });

            /* SIDEBAR DROPDOWNS */
            document.querySelectorAll('.dropdown-trigger').forEach(trigger => {
                if (trigger.dataset.bound === '1') return;

                trigger.dataset.bound = '1';

                trigger.addEventListener('click', function(event) {
                    event.preventDefault();

                    const wasOpen = this.classList.contains('open');

                    /* Close other dropdowns */
                    document.querySelectorAll('.dropdown-trigger.open').forEach(other => {
                        if (other === this) return;

                        other.classList.remove('open');
                        document.getElementById(other.getAttribute('data-target'))?.classList.remove('open');
                    });

                    const menu = document.getElementById(this.getAttribute('data-target'));

                    this.classList.toggle('open', !wasOpen);
                    menu?.classList.toggle('open', !wasOpen);
                });
            });

            /* MOBILE: Close drawer after opening a real navigation link. */
            sidebar.querySelectorAll('a.sidebar-item, a.dropdown-item').forEach(link => {
                link.addEventListener('click', () => {
                    if (isMobile()) {
                        closeDrawer();
                    }
                });
            });

            /* RESPONSIVE RESIZE */
            let previousMobile = isMobile();

            function syncSidebar() {
                const nowMobile = isMobile();

                if (nowMobile) {
                    /* Mobile uses drawer mode, not collapsed desktop mode. */
                    sidebar.classList.remove('collapsed');

                    if (!previousMobile) {
                        closeDrawer();
                    }
                } else {
                    /* Back to desktop: remove mobile-only states. */
                    sidebar.classList.remove('open');
                    backdrop?.classList.remove('show');
                    backdrop?.setAttribute('aria-hidden', 'true');
                    document.body.classList.remove('sidebar-open');

                    setExpanded(!sidebar.classList.contains('collapsed'));
                }

                previousMobile = nowMobile;
            }

            window.addEventListener('resize', syncSidebar, {
                passive: true
            });

            /* INITIAL STATE */
            if (isMobile()) {
                /* Sidebar starts CLOSED on mobile/tablet. */
                sidebar.classList.remove('collapsed', 'open');
                closeDrawer();
            } else {
                setExpanded(!sidebar.classList.contains('collapsed'));
            }
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', initSidebar, {
                once: true
            });
        } else {
            initSidebar();
        }
    })();
</script>