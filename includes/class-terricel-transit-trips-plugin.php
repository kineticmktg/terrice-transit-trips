<?php
/**
 * Trip child plugin coordinator.
 *
 * @package Terricel_Transit_Trips
 */

if (!defined('ABSPATH')) {
    exit;
}

class Terricel_Transit_Trips_Plugin {

    const ROLE_TRIP_COORDINATOR = 'trip_coordinator';
    const CAP_MANAGE_TRIPS = 'terricel_manage_trips';
    const OPTION_GOOGLE_API_KEY = 'terricel_trips_google_api_key';
    const OPTION_GOOGLE_RESTRICTED_IP = 'terricel_trips_google_restricted_ip';
    const OPTION_TRAVEL_BUFFER_PERCENT = 'terricel_trips_travel_buffer_percent';
    const OPTION_UNASSIGNED_NOTICE_HOURS = 'terricel_trips_unassigned_notice_hours';
    const OPTION_DRIVER_REMINDER_HOURS = 'terricel_trips_driver_reminder_hours';
    const OPTION_GOOGLE_MAPS_DIAGNOSTICS = 'terricel_trips_google_maps_diagnostics';
    const CRON_HOOK = 'terricel_transit_trips_notifications';

    private $module;

    public function hooks() {
        add_action('plugins_loaded', array($this, 'load_textdomain'));
        add_action('plugins_loaded', array($this, 'load'));
        add_filter('terricel_logistics_capabilities', array($this, 'filter_capabilities'));
        add_filter('terricel_logistics_role_capabilities', array($this, 'filter_role_capabilities'));
        add_filter('terricel_logistics_settings_tabs', array($this, 'filter_settings_tabs'));
        add_filter('terricel_logistics_modules', array($this, 'register_module'));
        add_filter('terricel_logistics_report_types', array($this, 'filter_report_types'));
        add_filter('terricel_logistics_report_filter_rows', array($this, 'filter_report_filter_rows'));
        add_filter('terricel_logistics_build_report', array($this, 'build_report'), 10, 5);
        add_filter('terricel_logistics_report_availability', array($this, 'filter_report_availability'), 10, 5);
        add_filter('terricel_logistics_kiosk_dashboards', array($this, 'filter_kiosk_dashboards'));
        add_filter('terricel_logistics_kiosk_dashboard_data', array($this, 'filter_kiosk_dashboard_data'), 10, 2);
        add_filter('terricel_logistics_kiosk_dashboard_styles', array($this, 'filter_kiosk_dashboard_styles'), 10, 2);
        add_filter('terricel_logistics_kiosk_dashboard_script', array($this, 'filter_kiosk_dashboard_script'), 10, 2);
        add_action('terricel_logistics_render_report_filters', array($this, 'render_report_filters'), 10, 2);
        add_action('terricel_logistics_driver_dashboard_assignments', array($this, 'render_driver_dashboard_trips'), 10, 2);
        add_filter('terricel_logistics_driver_scheduled_pto_requests', array($this, 'pass_through_driver_pto_requests'));
        add_action('admin_post_terricel_trips_save_trip_settings', array($this, 'save_trip_settings'));
        add_action('admin_post_terricel_trips_save_integrations', array($this, 'save_integrations'));
        add_action('admin_post_terricel_trips_save_tools', array($this, 'save_tools_settings'));
        add_action('wp_ajax_terricel_trip_report_groups', array($this, 'ajax_report_groups'));
        add_action('terricel_logistics_render_settings_tab_trips', array($this, 'render_trip_settings_tab'));
        add_action('terricel_logistics_render_settings_tab_integrations', array($this, 'render_integrations_tab'));
        add_action('terricel_logistics_render_tools_settings', array($this, 'render_tools_tab_section'));
        add_action(self::CRON_HOOK, array($this, 'send_due_trip_notifications'));
    }

    public function load() {
        if (!class_exists('Terricel_Logistics_Module') || !class_exists('Terricel_Logistics_Shared_Data')) {
            add_action('admin_notices', array($this, 'render_missing_parent_notice'));
            return;
        }

        require_once TERRICEL_TRANSIT_TRIPS_PATH . 'includes/class-terricel-transit-trips-module.php';
        $this->module = new Terricel_Transit_Trips_Module($this);

        self::ensure_roles();
        $this->ensure_cron_scheduled();
    }

    public function load_textdomain() {
        load_plugin_textdomain(
            TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN,
            false,
            dirname(plugin_basename(TERRICEL_TRANSIT_TRIPS_FILE)) . '/languages'
        );
    }

    public function render_missing_parent_notice() {
        echo '<div class="notice notice-error"><p>' . esc_html__('Terricel Transit Trips requires the Terricel Transit Operations parent plugin to be active.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</p></div>';
    }

    public static function activate() {
        self::ensure_roles();

        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', self::CRON_HOOK);
        }

        if (!get_option(self::OPTION_TRAVEL_BUFFER_PERCENT, null)) {
            update_option(self::OPTION_TRAVEL_BUFFER_PERCENT, 10);
        }

        if (!get_option(self::OPTION_UNASSIGNED_NOTICE_HOURS, null)) {
            update_option(self::OPTION_UNASSIGNED_NOTICE_HOURS, 72);
        }

        if (!get_option(self::OPTION_DRIVER_REMINDER_HOURS, null)) {
            update_option(self::OPTION_DRIVER_REMINDER_HOURS, 48);
        }
    }

    public static function deactivate() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
    }

    public static function ensure_roles() {
        add_role(
            self::ROLE_TRIP_COORDINATOR,
            __('Trip Coordinator', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
            array(
                'read'                    => true,
                'level_0'                 => true,
                'terricel_access_transit' => true,
                'terricel_manage_operations' => true,
                self::CAP_MANAGE_TRIPS    => true,
                'terricel_view_monitor'   => true,
            )
        );

        $role = get_role(self::ROLE_TRIP_COORDINATOR);
        if ($role) {
            foreach (self::trip_coordinator_capabilities() as $capability) {
                $role->add_cap($capability);
            }
        }

        foreach (array('administrator', 'terricel_dispatcher', 'terricel_admin') as $role_key) {
            $role = get_role($role_key);
            if ($role) {
                $role->add_cap(self::CAP_MANAGE_TRIPS);
            }
        }

        $driver = get_role('terricel_driver');
        if ($driver) {
            $driver->remove_cap(self::CAP_MANAGE_TRIPS);
        }
    }

    public static function trip_coordinator_capabilities() {
        return array(
            'read',
            'level_0',
            'terricel_access_transit',
            'terricel_manage_operations',
            self::CAP_MANAGE_TRIPS,
            'terricel_view_monitor',
            'edit_posts',
            'edit_others_posts',
            'edit_published_posts',
            'publish_posts',
            'delete_posts',
            'delete_others_posts',
            'delete_published_posts',
        );
    }

    public function filter_capabilities($capabilities) {
        $capabilities[] = self::CAP_MANAGE_TRIPS;
        return array_values(array_unique($capabilities));
    }

    public function filter_role_capabilities($role_capabilities) {
        $role_capabilities[self::ROLE_TRIP_COORDINATOR] = self::trip_coordinator_capabilities();

        foreach (array('terricel_dispatcher', 'terricel_admin') as $role_key) {
            if (!isset($role_capabilities[$role_key])) {
                continue;
            }

            $role_capabilities[$role_key][] = self::CAP_MANAGE_TRIPS;
            $role_capabilities[$role_key] = array_values(array_unique($role_capabilities[$role_key]));
        }

        if (isset($role_capabilities['terricel_driver'])) {
            $role_capabilities['terricel_driver'] = array_values(array_diff($role_capabilities['terricel_driver'], array(self::CAP_MANAGE_TRIPS)));
        }

        return $role_capabilities;
    }

    public function filter_settings_tabs($tabs) {
        $tabs['trips'] = array(
            'label'      => __('Trips', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
            'capability' => self::CAP_MANAGE_TRIPS,
        );
        $tabs['integrations'] = array(
            'label'      => __('Integrations', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
            'capability' => 'terricel_manage_operations',
        );

        return $tabs;
    }

    public function register_module($modules) {
        if ($this->module) {
            $modules[] = $this->module;
        }

        return $modules;
    }

    public function filter_report_types($types) {
        $types['trips_by_school'] = __('Trips by School', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN);
        return $types;
    }

    public function filter_report_filter_rows($rows) {
        $rows['trips_by_school'] = 'terricel-report-filter-trip';
        return $rows;
    }

    public function render_report_filters($selected_type, $report_query) {
        if ($this->module) {
            $this->module->render_report_filters($selected_type, is_array($report_query) ? $report_query : array());
        }
    }

    public function build_report($report, $type, $start_date, $end_date, $request) {
        if ('trips_by_school' !== $type || !$this->module) {
            return $report;
        }

        return $this->module->build_trips_by_school_report($start_date, $end_date, is_array($request) ? $request : array());
    }

    public function filter_report_availability($data, $type, $start_date, $end_date, $request) {
        if ('trips_by_school' !== $type || !$this->module) {
            return $data;
        }

        return $this->module->get_report_availability($start_date, $end_date, is_array($request) ? $request : array());
    }

    public function filter_kiosk_dashboards($dashboards) {
        $dashboards = is_array($dashboards) ? $dashboards : array();
        $dashboards['trips'] = array(
            'label'       => __('Trips', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
            'plain_label' => 'Trips',
            'title'       => 'Terricel Trips Monitor',
            'shortcode'   => '[terricel_kiosk_dashboard dashboard="trips"]',
            'subtitle'    => __('Trip Monitor', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
        );

        return $dashboards;
    }

    public function filter_kiosk_dashboard_data($data, $dashboard) {
        if ('trips' !== $dashboard || !$this->module) {
            return $data;
        }

        return $this->module->get_kiosk_trip_monitor_data();
    }

    public function filter_kiosk_dashboard_styles($styles, $dashboard) {
        if ('trips' !== $dashboard) {
            return $styles;
        }

        return $styles
            . '.terricel-kiosk-dashboard[data-dashboard="trips"] .terricel-dispatch-summary{font-size:30px;margin-bottom:22px}'
            . '.terricel-kiosk-dashboard[data-dashboard="trips"] .terricel-dispatch-summary strong{font-size:40px}'
            . '.terricel-kiosk-dashboard[data-dashboard="trips"] .terricel-dispatch-day-head{padding:14px 16px}'
            . '.terricel-kiosk-dashboard[data-dashboard="trips"] .terricel-dispatch-day-title{width:100%;font-size:32px;line-height:1.08}'
            . '.terricel-kiosk-dashboard[data-dashboard="trips"] .terricel-dispatch-day-count{font-size:20px;margin-top:8px}'
            . '.terricel-kiosk-dashboard[data-dashboard="trips"] .terricel-dispatch-items{gap:16px;padding:16px}'
            . '.terricel-kiosk-dashboard[data-dashboard="trips"] .terricel-dispatch-empty{font-size:20px;line-height:1.25}'
            . '.terricel-trip-monitor-item{border-left:7px solid #62b6ff;padding:18px}'
            . '.terricel-trip-monitor-item.has-vacancy{border-left-color:#ffb84d}'
            . '.terricel-trip-monitor-destination{font-size:40px;color:#fff;font-weight:900;line-height:1.06;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
            . '.terricel-trip-monitor-time{font-size:32px;color:#e6f0fb;font-weight:800;line-height:1.1;margin-top:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
            . '.terricel-trip-monitor-meta{font-size:24px;color:#becbda;line-height:1.16;margin-top:9px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
            . '.terricel-trip-monitor-assignments{margin-top:14px;display:grid;gap:8px}'
            . '.terricel-trip-monitor-assignment{font-size:22px;color:#e6f0fb;line-height:1.14;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}'
            . '.terricel-trip-monitor-vacant{color:#ffdfaa;font-weight:900}'
            . '.terricel-dispatch-day[data-density="medium"] .terricel-trip-monitor-item{padding:14px}'
            . '.terricel-dispatch-day[data-density="medium"] .terricel-trip-monitor-destination{font-size:30px}'
            . '.terricel-dispatch-day[data-density="medium"] .terricel-trip-monitor-time{font-size:24px}'
            . '.terricel-dispatch-day[data-density="medium"] .terricel-trip-monitor-meta{font-size:19px}'
            . '.terricel-dispatch-day[data-density="medium"] .terricel-trip-monitor-assignment{font-size:17px}'
            . '.terricel-dispatch-day[data-density="compact"] .terricel-trip-monitor-item{padding:9px}'
            . '.terricel-dispatch-day[data-density="compact"] .terricel-trip-monitor-destination{font-size:22px}'
            . '.terricel-dispatch-day[data-density="compact"] .terricel-trip-monitor-time{font-size:18px}'
            . '.terricel-dispatch-day[data-density="compact"] .terricel-trip-monitor-meta,.terricel-dispatch-day[data-density="compact"] .terricel-trip-monitor-assignment{font-size:14px}'
            . '.terricel-dispatch-day[data-density="tight"] .terricel-trip-monitor-item{padding:6px}'
            . '.terricel-dispatch-day[data-density="tight"] .terricel-trip-monitor-destination{font-size:17px}'
            . '.terricel-dispatch-day[data-density="tight"] .terricel-trip-monitor-time{font-size:14px}'
            . '.terricel-dispatch-day[data-density="tight"] .terricel-trip-monitor-meta,.terricel-dispatch-day[data-density="tight"] .terricel-trip-monitor-assignment{font-size:11px}';
    }

    public function filter_kiosk_dashboard_script($script, $dashboard) {
        if ('trips' !== $dashboard) {
            return $script;
        }

        return $script . 'window.terricelKioskRenderers.trips=function(context){var payload=context.payload||{};var data=payload.data||{};var days=Array.isArray(data.days)?data.days:[];var grid=context.grid;var escapeHtml=context.escapeHtml;var setOnlineStatus=context.setOnlineStatus;var totalTrips=Number(data.total_trips||0);var totalVacant=Number(data.total_vacant_assignments||0);var busiest=0;days.forEach(function(day){var count=Array.isArray(day.items)?day.items.length:0;if(count>busiest){busiest=count;}});var density=busiest>8?"compact":(busiest>4?"medium":"comfortable");var html="<div class=\"terricel-dispatch-summary\"><span><strong>"+totalTrips+"</strong> trips this week</span><span><strong>"+totalVacant+"</strong> vacant assignments</span></div>";grid.className="terricel-dispatch-board";grid.setAttribute("data-density",density);html+="<div class=\"terricel-dispatch-week-grid\" style=\"--terricel-dispatch-day-count:"+Math.max(days.length,1)+";\">";days.forEach(function(day){var items=Array.isArray(day.items)?day.items:[];html+="<section class=\"terricel-dispatch-day"+(day.is_today?" is-today":"")+"\" data-density=\"comfortable\">";html+="<div class=\"terricel-dispatch-day-head\"><div class=\"terricel-dispatch-day-title\">"+escapeHtml(day.day_label)+" | "+escapeHtml(day.date_label)+"</div><div class=\"terricel-dispatch-day-count\">"+escapeHtml(day.trip_count)+" trips</div></div>";html+="<div class=\"terricel-dispatch-items\">";if(items.length){items.forEach(function(item){html+="<article class=\"terricel-dispatch-item terricel-trip-monitor-item"+(item.has_vacancy?" has-vacancy":"")+"\">";html+="<div class=\"terricel-trip-monitor-destination\">"+escapeHtml(item.destination||"Trip")+"</div>";html+="<div class=\"terricel-trip-monitor-time\">"+escapeHtml(item.pickup_label||"Pickup not set")+(item.return_label?" - "+escapeHtml(item.return_label):"")+"</div>";html+="<div class=\"terricel-trip-monitor-meta\">"+escapeHtml(item.school||"School not set")+(item.group?" | "+escapeHtml(item.group):"")+"</div>";if(Array.isArray(item.assignments)&&item.assignments.length){html+="<div class=\"terricel-trip-monitor-assignments\">";item.assignments.forEach(function(assignment){html+="<div class=\"terricel-trip-monitor-assignment"+(assignment.vacant?" terricel-trip-monitor-vacant":"")+"\">"+escapeHtml(assignment.slot)+": "+escapeHtml(assignment.bus)+" - "+escapeHtml(assignment.driver)+"</div>";});html+="</div>";}html+="</article>";});}else{html+="<div class=\"terricel-dispatch-empty\">No trips scheduled.</div>";}html+="</div></section>";});html+="</div>";grid.innerHTML=html;if(typeof window.requestAnimationFrame==="function"){window.requestAnimationFrame(function(){grid.querySelectorAll(".terricel-dispatch-day").forEach(function(column){var items=column.querySelector(".terricel-dispatch-items");if(!items){return;}["comfortable","medium","compact","tight"].some(function(level){column.setAttribute("data-density",level);return items.scrollHeight<=items.clientHeight+1;});});});}setOnlineStatus("Trip monitor data refreshed.");};';
    }

    public function ajax_report_groups() {
        if (!$this->module) {
            wp_send_json_error(array('message' => __('Trips module is not available.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN)), 500);
        }

        $this->module->ajax_report_groups();
    }

    public function render_trip_settings_tab() {
        if (!current_user_can(self::CAP_MANAGE_TRIPS)) {
            wp_die(esc_html__('You do not have permission to manage trip settings.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN));
        }

        $unassigned_hours = absint(get_option(self::OPTION_UNASSIGNED_NOTICE_HOURS, 72));
        $driver_hours = absint(get_option(self::OPTION_DRIVER_REMINDER_HOURS, 48));

        echo '<h2>' . esc_html__('Trip Settings', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('terricel_trips_save_trip_settings');
        echo '<input type="hidden" name="action" value="terricel_trips_save_trip_settings">';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="terricel_trips_unassigned_notice_hours">' . esc_html__('Unassigned Trip Notice', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</label></th>';
        echo '<td><input class="small-text" type="number" min="1" max="720" step="1" id="terricel_trips_unassigned_notice_hours" name="terricel_trips_unassigned_notice_hours" value="' . esc_attr($unassigned_hours) . '"> ' . esc_html__('hours before pickup', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</td></tr>';
        echo '<tr><th scope="row"><label for="terricel_trips_driver_reminder_hours">' . esc_html__('Driver Trip Reminder', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</label></th>';
        echo '<td><input class="small-text" type="number" min="1" max="720" step="1" id="terricel_trips_driver_reminder_hours" name="terricel_trips_driver_reminder_hours" value="' . esc_attr($driver_hours) . '"> ' . esc_html__('hours before pickup', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</td></tr>';
        echo '</tbody></table>';
        submit_button(__('Save Trip Settings', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN));
        echo '</form>';
    }

    public function render_integrations_tab() {
        if (!current_user_can('terricel_manage_operations')) {
            wp_die(esc_html__('You do not have permission to manage integrations.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN));
        }

        $api_key = get_option(self::OPTION_GOOGLE_API_KEY, '');
        $restricted_ip = get_option(self::OPTION_GOOGLE_RESTRICTED_IP, '');
        $site_ip = $this->get_site_outbound_ip();
        $buffer = absint(get_option(self::OPTION_TRAVEL_BUFFER_PERCENT, 10));

        echo '<h2>' . esc_html__('Integrations', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</h2>';
        echo '<details style="max-width:900px;margin:12px 0 18px;">';
        echo '<summary style="cursor:pointer;font-weight:600;">' . esc_html__('Google Maps API setup instructions', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</summary>';
        echo '<div style="padding:10px 0 0;">';
        echo '<p>' . esc_html__('This plugin uses a Google Maps API key for server-side trip mileage, travel-time estimates, and destination address lookup. It does not use OAuth.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</p>';
        echo '<p><strong>' . esc_html__('Detected site outbound IP:', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</strong> <code>' . esc_html($site_ip ? $site_ip : __('Unable to detect', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN)) . '</code></p>';
        echo '<ol>';
        echo '<li>' . esc_html__('If you are on the "Create OAuth client ID" screen, click Cancel or go back. Do not create an OAuth client for this plugin.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</li>';
        echo '<li>' . esc_html__('In Google Cloud Console, open APIs & Services, then Library, and enable Routes API, Places API (New), and Geocoding API.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</li>';
        echo '<li>' . esc_html__('Open APIs & Services, then Credentials, then choose Create Credentials > API key.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</li>';
        echo '<li>' . esc_html__('Restrict the key to Routes API, Places API (New), and Geocoding API, then set Application restrictions to IP addresses.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</li>';
        echo '<li>' . esc_html__('Add this WordPress server outbound IP address in Google Cloud API key Application restrictions.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</li>';
        echo '<li>' . esc_html__('Paste the API key below and save. The driver map button does not need this key; it uses a normal map URL.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</li>';
        echo '</ol>';
        echo '</div>';
        echo '</details>';

        if ($restricted_ip && $site_ip && $restricted_ip !== $site_ip) {
            echo '<div class="notice notice-error inline"><p>' . esc_html__('Please update API IP address restrictions in the linked google console account', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</p></div>';
        }

        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('terricel_trips_save_integrations');
        echo '<input type="hidden" name="action" value="terricel_trips_save_integrations">';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="terricel_trips_google_api_key">' . esc_html__('Google Maps API Key', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</label></th>';
        echo '<td><input class="regular-text" type="password" id="terricel_trips_google_api_key" name="terricel_trips_google_api_key" value="' . esc_attr($api_key) . '" autocomplete="off"></td></tr>';
        echo '<tr><th scope="row"><label for="terricel_trips_travel_buffer_percent">' . esc_html__('Travel Time Buffer', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</label></th>';
        echo '<td><input class="small-text" type="number" min="0" max="100" step="1" id="terricel_trips_travel_buffer_percent" name="terricel_trips_travel_buffer_percent" value="' . esc_attr($buffer) . '">%</td></tr>';
        echo '</tbody></table>';
        submit_button(__('Save Integrations', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN));
        echo '</form>';
    }

    public function render_tools_tab_section() {
        if (!current_user_can('terricel_manage_operations')) {
            return;
        }

        $diagnostics_enabled = self::google_maps_diagnostics_enabled();

        echo '<div class="postbox" style="max-width:1100px;">';
        echo '<div class="inside">';
        echo '<h3>' . esc_html__('Trip Google Maps Diagnostics', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</h3>';
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        wp_nonce_field('terricel_trips_save_tools');
        echo '<input type="hidden" name="action" value="terricel_trips_save_tools">';
        echo '<label><input type="checkbox" name="terricel_trips_google_maps_diagnostics" value="1"' . checked($diagnostics_enabled, true, false) . '> ' . esc_html__('Enable Google Maps route diagnostics on Trip edit screens', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</label>';
        echo '<p class="description">' . esc_html__('When enabled, Trip edit screens show the exact school origin, destination, matching Google Maps link, and route alternatives returned by Google. Leave this off unless actively troubleshooting route estimate differences.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</p>';
        submit_button(__('Save Trip Tools', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), 'secondary', 'submit', false);
        echo '</form>';
        echo '</div>';
        echo '</div>';
    }

    public function save_trip_settings() {
        if (!current_user_can(self::CAP_MANAGE_TRIPS)) {
            wp_die(esc_html__('You do not have permission to save trip settings.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN));
        }

        check_admin_referer('terricel_trips_save_trip_settings');
        update_option(self::OPTION_UNASSIGNED_NOTICE_HOURS, min(720, max(1, absint($_POST['terricel_trips_unassigned_notice_hours'] ?? 72))));
        update_option(self::OPTION_DRIVER_REMINDER_HOURS, min(720, max(1, absint($_POST['terricel_trips_driver_reminder_hours'] ?? 48))));

        wp_safe_redirect(admin_url('admin.php?page=terricel-transit-settings&tab=trips&trip-settings-updated=1'));
        exit;
    }

    public function save_integrations() {
        if (!current_user_can('terricel_manage_operations')) {
            wp_die(esc_html__('You do not have permission to save integrations.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN));
        }

        check_admin_referer('terricel_trips_save_integrations');
        update_option(self::OPTION_GOOGLE_API_KEY, sanitize_text_field(wp_unslash($_POST['terricel_trips_google_api_key'] ?? '')));
        update_option(self::OPTION_GOOGLE_RESTRICTED_IP, $this->get_site_outbound_ip());
        update_option(self::OPTION_TRAVEL_BUFFER_PERCENT, min(100, max(0, absint($_POST['terricel_trips_travel_buffer_percent'] ?? 10))));

        wp_safe_redirect(admin_url('admin.php?page=terricel-transit-settings&tab=integrations&integrations-updated=1'));
        exit;
    }

    public function save_tools_settings() {
        if (!current_user_can('terricel_manage_operations')) {
            wp_die(esc_html__('You do not have permission to save trip tools settings.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN));
        }

        check_admin_referer('terricel_trips_save_tools');
        update_option(self::OPTION_GOOGLE_MAPS_DIAGNOSTICS, !empty($_POST['terricel_trips_google_maps_diagnostics']) ? '1' : '0');

        wp_safe_redirect(admin_url('admin.php?page=terricel-transit-settings&tab=tools&tools-settings-updated=1'));
        exit;
    }

    public static function google_maps_diagnostics_enabled() {
        return '1' === get_option(self::OPTION_GOOGLE_MAPS_DIAGNOSTICS, '0');
    }

    private function get_site_outbound_ip() {
        $cached = get_transient('terricel_trips_site_outbound_ip');
        if ($cached) {
            return $cached;
        }

        foreach (array('https://api.ipify.org', 'https://ifconfig.me/ip') as $url) {
            $response = wp_remote_get($url, array('timeout' => 4));
            if (is_wp_error($response)) {
                continue;
            }

            $ip = $this->sanitize_ip_address(wp_remote_retrieve_body($response));
            if ($ip) {
                set_transient('terricel_trips_site_outbound_ip', $ip, HOUR_IN_SECONDS);
                return $ip;
            }
        }

        return '';
    }

    private function sanitize_ip_address($value) {
        $value = trim(sanitize_text_field(wp_unslash($value)));

        return filter_var($value, FILTER_VALIDATE_IP) ? $value : '';
    }

    public function ensure_cron_scheduled() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'hourly', self::CRON_HOOK);
        }
    }

    public function send_due_trip_notifications() {
        if (!$this->module) {
            return;
        }

        $this->module->send_due_trip_notifications();
    }

    public function render_driver_dashboard_trips($driver_id, $user_id) {
        if (!$this->module || absint($driver_id) < 1) {
            return;
        }

        $this->module->render_driver_dashboard_trips(absint($driver_id));
    }

    public function pass_through_driver_pto_requests($requests) {
        return $requests;
    }
}
