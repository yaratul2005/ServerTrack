<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * ServerTrack_Dashboard  v4.3
 *
 * v4.3 — CLASS-NAME & ROUTING FIX
 *
 *   D1 — register_menu() created a 'servertrack-sources' submenu that
 *        called ServerTrack_Admin::render_page() directly. That function
 *        lands on the 'general' tab (no ?tab=sources equivalent exists in
 *        Settings). Fixed: 'servertrack-sources' now redirects to
 *        admin.php?page=servertrack-settings&tab=sources so the correct
 *        view is shown.
 *
 *   D2 — The KPI block used class="st-kpi" / "st-kpi-label" / "st-kpi-val"
 *        / "st-kpi-sub" — these are the 