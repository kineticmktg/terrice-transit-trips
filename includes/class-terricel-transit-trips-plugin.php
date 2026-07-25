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
    const CRON_HOOK = 'terricel_transit_trips_notifications';

    private $module;

    public function hooks() {
        add_action('plugins_loaded', array($this, 'load_textdomain'));
        add_action('plugins_loaded', array($this, 'load'));
        add_filter('terricel_logistics_capabilities', array($this, 'filter_capabilities'));
        add_filter('terricel_logistics_role_capabilities', array($this, 'filter_role_capabilities'));
        add_filter('terricel_logistics_settings_tabs', array($this, 'filter_settings_tabs'));
        add_filter('terricel_logistics_modules', array($this, 'register_module'));
        add_filter('terricel_logistics_driver_pto_summary', array($this, 'render_driver_dashboard_trips'), 10, 3);
        add_filter('terricel_logistics_driver_scheduled_pto_requests', array($this, 'pass_through_driver_pto_requests'));
        add_action('admin_post_terricel_trips_save_trip_settings', array($this, 'save_trip_settings'));
        add_action('admin_post_terricel_trips_save_integrations', array($this, 'save_integrations'));
        add_action('terricel_logistics_render_settings_tab_trips', array($this, 'render_trip_settings_tab'));
        add_action('terricel_logistics_render_settings_tab_integrations', array($this, 'render_integrations_tab'));
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
        echo '<p>' . esc_html__('This plugin uses a Google Maps API key for server-side trip mileage and travel-time estimates. It does not use OAuth.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</p>';
        echo '<p><strong>' . esc_html__('Detected site outbound IP:', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</strong> <code>' . esc_html($site_ip ? $site_ip : __('Unable to detect', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN)) . '</code></p>';
        echo '<ol>';
        echo '<li>' . esc_html__('If you are on the "Create OAuth client ID" screen, click Cancel or go back. Do not create an OAuth client for this plugin.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</li>';
        echo '<li>' . esc_html__('In Google Cloud Console, open APIs & Services, then Library, and enable Routes API.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</li>';
        echo '<li>' . esc_html__('Open APIs & Services, then Credentials, then choose Create Credentials > API key.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</li>';
        echo '<li>' . esc_html__('Restrict the key to the Routes API and set Application restrictions to IP addresses.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</li>';
        echo '<li>' . esc_html__('Add this WordPress server outbound IP address in Google, then paste the same IP into the Google API Restricted IP field below.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</li>';
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
        echo '<tr><th scope="row"><label for="terricel_trips_google_restricted_ip">' . esc_html__('Google API Restricted IP', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</label></th>';
        echo '<td><input class="regular-text" type="text" id="terricel_trips_google_restricted_ip" name="terricel_trips_google_restricted_ip" value="' . esc_attr($restricted_ip) . '" placeholder="' . esc_attr($site_ip) . '"> ';
        echo '<p class="description">' . esc_html__('Enter the IP address saved in Google Cloud for this API key. If it does not match the detected site IP, the plugin will show a warning.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</p></td></tr>';
        echo '<tr><th scope="row"><label for="terricel_trips_travel_buffer_percent">' . esc_html__('Travel Time Buffer', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</label></th>';
        echo '<td><input class="small-text" type="number" min="0" max="100" step="1" id="terricel_trips_travel_buffer_percent" name="terricel_trips_travel_buffer_percent" value="' . esc_attr($buffer) . '">%</td></tr>';
        echo '</tbody></table>';
        submit_button(__('Save Integrations', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN));
        echo '</form>';
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
        update_option(self::OPTION_GOOGLE_RESTRICTED_IP, $this->sanitize_ip_address($_POST['terricel_trips_google_restricted_ip'] ?? ''));
        update_option(self::OPTION_TRAVEL_BUFFER_PERCENT, min(100, max(0, absint($_POST['terricel_trips_travel_buffer_percent'] ?? 10))));

        wp_safe_redirect(admin_url('admin.php?page=terricel-transit-settings&tab=integrations&integrations-updated=1'));
        exit;
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

    public function render_driver_dashboard_trips($summary, $driver_id, $user_id) {
        if (!$this->module || absint($driver_id) < 1) {
            return $summary;
        }

        $this->module->render_driver_dashboard_trips(absint($driver_id));
        return $summary;
    }

    public function pass_through_driver_pto_requests($requests) {
        return $requests;
    }
}
