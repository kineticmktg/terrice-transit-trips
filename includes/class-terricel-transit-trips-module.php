<?php
/**
 * Trip coordination module.
 *
 * @package Terricel_Transit_Trips
 */

if (!defined('ABSPATH')) {
    exit;
}

class Terricel_Transit_Trips_Module extends Terricel_Logistics_Module {

    const TRIP_POST_TYPE = 'terricel_trip';
    const GROUP_POST_TYPE = 'terricel_trip_group';
    const MODULE_ID = 'trips';

    private $plugin;

    public function __construct(Terricel_Transit_Trips_Plugin $plugin) {
        $this->plugin = $plugin;
        $this->id = self::MODULE_ID;
        $this->name = __('Trips', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN);
        $this->description = __('Plan school trips, assign eligible buses and drivers, and notify operations or drivers when action is needed.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN);
        $this->phase = __('Trip coordination scaffold', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN);
        $this->capability = Terricel_Transit_Trips_Plugin::CAP_MANAGE_TRIPS;
    }

    protected function register_post_types() {
        $this->register_trip_post_type();
        $this->register_group_post_type();
    }

    protected function register_hooks() {
        if (is_admin()) {
            add_action('admin_menu', array($this, 'remove_duplicate_module_menu'), 1001);
            add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
            add_action('save_post_' . self::TRIP_POST_TYPE, array($this, 'save_trip_meta'), 10, 2);
            add_action('save_post_' . self::GROUP_POST_TYPE, array($this, 'save_group_meta'), 10, 2);
            add_action('save_post_' . Terricel_Logistics_Shared_Data::BUS_POST_TYPE, array($this, 'save_bus_trip_meta'), 20);
            add_filter('manage_' . self::TRIP_POST_TYPE . '_posts_columns', array($this, 'trip_columns'));
            add_action('manage_' . self::TRIP_POST_TYPE . '_posts_custom_column', array($this, 'render_trip_column'), 10, 2);
            add_filter('views_edit-' . self::TRIP_POST_TYPE, array($this, 'trip_list_views'));
            add_action('pre_get_posts', array($this, 'filter_trip_admin_list'));
            add_filter('manage_' . self::GROUP_POST_TYPE . '_posts_columns', array($this, 'group_columns'));
            add_action('manage_' . self::GROUP_POST_TYPE . '_posts_custom_column', array($this, 'render_group_column'), 10, 2);
            add_filter('enter_title_here', array($this, 'filter_title_placeholder'), 10, 2);
            add_action('edit_form_top', array($this, 'render_back_to_trips_button'));
            add_action('admin_head-edit.php', array($this, 'render_trip_list_header_actions_script'));
            add_action('admin_notices', array($this, 'render_admin_notices'));
            add_filter('redirect_post_location', array($this, 'filter_post_redirect'), 10, 2);
            add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_assets'));
            add_action('wp_ajax_terricel_trip_groups_for_school', array($this, 'ajax_trip_groups_for_school'));
            add_action('wp_ajax_terricel_create_trip_group', array($this, 'ajax_create_trip_group'));
            add_action('wp_ajax_terricel_trip_address_suggestions', array($this, 'ajax_trip_address_suggestions'));
            add_action('wp_ajax_terricel_trip_place_details', array($this, 'ajax_trip_place_details'));
            add_action('wp_ajax_terricel_trip_destination_estimate', array($this, 'ajax_trip_destination_estimate'));
            add_action('wp_ajax_terricel_trip_driver_conflicts', array($this, 'ajax_trip_driver_conflicts'));
            add_action('wp_ajax_terricel_trip_bus_availability', array($this, 'ajax_trip_bus_availability'));
        }
    }

    public function render_admin_page() {
        wp_safe_redirect(admin_url('edit.php?post_type=' . self::TRIP_POST_TYPE));
        exit;
    }

    public function remove_duplicate_module_menu() {
        remove_submenu_page('terricel-transit', 'terricel-transit-' . self::MODULE_ID);
        remove_submenu_page('terricel-transit', 'edit.php?post_type=' . self::GROUP_POST_TYPE);
    }

    private function register_trip_post_type() {
        register_post_type(
            self::TRIP_POST_TYPE,
            array(
                'labels' => array(
                    'name'          => __('Trips', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
                    'singular_name' => __('Trip', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
                    'add_new_item'  => __('Add New Trip', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
                    'edit_item'     => __('Edit Trip', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
                ),
                'public'             => false,
                'show_ui'            => true,
                'show_in_menu'       => 'terricel-transit',
                'show_in_rest'       => false,
                'supports'           => false,
                'capability_type'    => 'post',
                'map_meta_cap'       => false,
                'capabilities'       => $this->trip_post_type_capabilities(),
                'menu_icon'          => 'dashicons-tickets-alt',
            )
        );
    }

    private function register_group_post_type() {
        register_post_type(
            self::GROUP_POST_TYPE,
            array(
                'labels' => array(
                    'name'          => __('School Trip Groups', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
                    'singular_name' => __('School Trip Group', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
                    'add_new_item'  => __('Add New School Trip Group', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
                    'edit_item'     => __('Edit School Trip Group', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
                ),
                'public'             => false,
                'show_ui'            => true,
                'show_in_menu'       => 'terricel-transit',
                'show_in_rest'       => false,
                'supports'           => array('title'),
                'capability_type'    => 'post',
                'map_meta_cap'       => false,
                'capabilities'       => $this->trip_post_type_capabilities(),
                'menu_icon'          => 'dashicons-groups',
            )
        );
    }

    private function trip_post_type_capabilities() {
        return array(
            'edit_post'              => Terricel_Transit_Trips_Plugin::CAP_MANAGE_TRIPS,
            'read_post'              => 'terricel_access_transit',
            'delete_post'            => Terricel_Transit_Trips_Plugin::CAP_MANAGE_TRIPS,
            'edit_posts'             => Terricel_Transit_Trips_Plugin::CAP_MANAGE_TRIPS,
            'edit_others_posts'      => Terricel_Transit_Trips_Plugin::CAP_MANAGE_TRIPS,
            'publish_posts'          => Terricel_Transit_Trips_Plugin::CAP_MANAGE_TRIPS,
            'read_private_posts'     => 'terricel_access_transit',
            'delete_posts'           => Terricel_Transit_Trips_Plugin::CAP_MANAGE_TRIPS,
            'delete_private_posts'   => Terricel_Transit_Trips_Plugin::CAP_MANAGE_TRIPS,
            'delete_published_posts' => Terricel_Transit_Trips_Plugin::CAP_MANAGE_TRIPS,
            'delete_others_posts'    => Terricel_Transit_Trips_Plugin::CAP_MANAGE_TRIPS,
            'edit_private_posts'     => Terricel_Transit_Trips_Plugin::CAP_MANAGE_TRIPS,
            'edit_published_posts'   => Terricel_Transit_Trips_Plugin::CAP_MANAGE_TRIPS,
            'create_posts'           => Terricel_Transit_Trips_Plugin::CAP_MANAGE_TRIPS,
        );
    }

    public function add_meta_boxes() {
        add_meta_box('terricel_trip_details', __('Trip Details', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), array($this, 'render_trip_details_meta_box'), self::TRIP_POST_TYPE, 'normal', 'high');
        add_meta_box('terricel_trip_destination', __('Destination & Estimates', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), array($this, 'render_trip_destination_meta_box'), self::TRIP_POST_TYPE, 'normal');
        add_meta_box('terricel_trip_schedule', __('Dates & Times', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), array($this, 'render_trip_schedule_meta_box'), self::TRIP_POST_TYPE, 'normal');
        add_meta_box('terricel_trip_assignments', __('Buses & Drivers', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), array($this, 'render_trip_assignments_meta_box'), self::TRIP_POST_TYPE, 'normal');
        add_meta_box('terricel_trip_group_details', __('Group Details', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), array($this, 'render_group_details_meta_box'), self::GROUP_POST_TYPE, 'normal', 'high');
        add_meta_box('terricel_bus_trip_eligibility', __('Trip Eligibility', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), array($this, 'render_bus_trip_eligibility_meta_box'), Terricel_Logistics_Shared_Data::BUS_POST_TYPE, 'side');
    }

    public function render_trip_details_meta_box($post) {
        wp_nonce_field('terricel_trip_meta', 'terricel_trip_meta_nonce');
        wp_nonce_field('terricel_trip_group_ajax', 'terricel_trip_group_ajax_nonce');
        $school_id = (int) get_post_meta($post->ID, '_terricel_trip_school_id', true);
        $group_id = (int) get_post_meta($post->ID, '_terricel_trip_group_id', true);
        echo '<div class="terricel-trip-details-layout">';
        echo '<div class="terricel-trip-school-row">';
        echo '<div class="terricel-trip-school-field">';
        $this->render_post_select_field('terricel_trip_school_id', __('School', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), Terricel_Logistics_Shared_Data::SCHOOL_POST_TYPE, $school_id, __('Select school', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN));
        echo '</div>';
        echo '<div class="terricel-trip-school-field">';
        $this->render_group_select_field($school_id, $group_id);
        echo '</div>';
        echo '<div class="terricel-trip-group-action"><button type="button" class="button" id="terricel_trip_add_group_toggle">' . esc_html__('Add School Group', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</button></div>';
        echo '</div>';
        $this->render_inline_group_create_panel();
        echo '</div>';
    }

    public function render_trip_destination_meta_box($post) {
        $name = get_post_meta($post->ID, '_terricel_trip_location_name', true);
        $address = get_post_meta($post->ID, '_terricel_trip_destination_address', true);
        $mileage = get_post_meta($post->ID, '_terricel_trip_estimated_mileage', true);
        $travel_minutes = get_post_meta($post->ID, '_terricel_trip_estimated_travel_minutes', true);
        $maps_url = $this->get_trip_maps_url($post->ID);
        $estimate_help = __('Google travel time is increased by the trip buffer setting, rounded up, then rounded up to the next 10-minute slot. This can be manually overridden.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN);

        echo '<p><label for="terricel_trip_location_name"><strong>' . esc_html__('Location Name', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</strong></label></p>';
        echo '<div class="terricel-address-lookup"><input class="widefat" type="text" id="terricel_trip_location_name" name="terricel_trip_location_name" value="' . esc_attr($name) . '" autocomplete="off"><div id="terricel_trip_location_suggestions" class="terricel-address-suggestions" hidden></div></div>';
        echo '<p><label for="terricel_trip_destination_address"><strong>' . esc_html__('Destination Address', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</strong></label></p>';
        echo '<div class="terricel-address-lookup"><input class="widefat" type="text" id="terricel_trip_destination_address" name="terricel_trip_destination_address" value="' . esc_attr($address) . '" autocomplete="off"><div id="terricel_trip_address_suggestions" class="terricel-address-suggestions" hidden></div></div>';
        echo '<div class="terricel-trip-grid">';
        echo '<p><label for="terricel_trip_estimated_mileage"><strong>' . esc_html__('Estimated Round Trip Mileage', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</strong></label><br><input type="number" min="0" step="1" id="terricel_trip_estimated_mileage" name="terricel_trip_estimated_mileage" value="' . esc_attr($mileage) . '"></p>';
        echo '<p><label for="terricel_trip_estimated_travel_minutes"><strong>' . esc_html__('Estimated One-Way Travel Time', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</strong></label><br><input type="number" min="0" step="1" id="terricel_trip_estimated_travel_minutes" name="terricel_trip_estimated_travel_minutes" value="' . esc_attr($travel_minutes) . '"> ' . esc_html__('minutes', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . ' <span class="terricel-trip-estimate-help-wrap"><button type="button" class="terricel-trip-estimate-help" aria-expanded="false" aria-label="' . esc_attr__('Show estimated travel time details', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '"><span class="dashicons dashicons-info-outline"></span></button><span class="terricel-trip-estimate-popover" hidden>' . esc_html($estimate_help) . '</span></span></p>';
        echo '</div>';
        if (Terricel_Transit_Trips_Plugin::google_maps_diagnostics_enabled()) {
            echo '<details id="terricel_trip_route_options" class="terricel-route-options" hidden><summary>' . esc_html__('Google Route Options', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</summary><div id="terricel_trip_route_options_list"></div></details>';
        }

        if ($maps_url) {
            echo '<p><a class="button" target="_blank" rel="noopener" href="' . esc_url($maps_url) . '">' . esc_html__('Open Destination in Maps', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</a></p>';
        }
    }

    public function render_trip_schedule_meta_box($post) {
        $pickup_date = get_post_meta($post->ID, '_terricel_trip_pickup_date', true);
        $pickup_time = get_post_meta($post->ID, '_terricel_trip_pickup_time', true);
        $arrival_date = get_post_meta($post->ID, '_terricel_trip_arrival_date', true);
        $arrival_time = get_post_meta($post->ID, '_terricel_trip_arrival_time', true);
        $departure_date = get_post_meta($post->ID, '_terricel_trip_departure_date', true);
        $departure_time = get_post_meta($post->ID, '_terricel_trip_departure_time', true);
        $return_date = get_post_meta($post->ID, '_terricel_trip_return_date', true);
        $return_time = get_post_meta($post->ID, '_terricel_trip_return_time', true);

        $this->render_time_suggestions();
        echo '<div class="terricel-trip-schedule-grid">';
        $this->render_date_time_field('pickup', __('Pickup', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $pickup_date, $pickup_time);
        $this->render_date_time_field('arrival', __('Arrival', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $arrival_date ? $arrival_date : $pickup_date, $arrival_time);
        $this->render_date_time_field('departure', __('Departure', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $departure_date ? $departure_date : $pickup_date, $departure_time);
        $this->render_date_time_field('return', __('Return', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $return_date ? $return_date : $pickup_date, $return_time);
        echo '</div>';
    }

    public function render_trip_assignments_meta_box($post) {
        $buses_needed = max(1, (int) get_post_meta($post->ID, '_terricel_trip_buses_needed', true));
        $assignments = $this->get_trip_assignments($post->ID);
        $actuals = $this->get_trip_actuals($post->ID);
        $show_actuals = 'publish' === $post->post_status;
        $pending_assignments = get_post_meta($post->ID, '_terricel_trip_pending_assignments', true);
        $pending_conflicts = get_post_meta($post->ID, '_terricel_trip_pending_conflicts', true);
        if (is_array($pending_assignments) && !empty($pending_assignments)) {
            $assignments = $pending_assignments;
        }
        $pending_conflicts = is_array($pending_conflicts) ? $pending_conflicts : array();

        echo '<p><label for="terricel_trip_buses_needed"><strong>' . esc_html__('Buses Needed', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</strong></label><br>';
        echo '<input class="small-text" type="number" min="0" max="50" step="1" id="terricel_trip_buses_needed" name="terricel_trip_buses_needed" value="' . esc_attr($buses_needed) . '"></p>';

        if (!empty($pending_conflicts)) {
            echo '<div class="notice notice-warning inline"><p><strong>' . esc_html__('Driver conflicts require confirmation before assignments are saved.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</strong></p>';
            echo '<ul style="list-style:disc;margin-left:20px;">';
            foreach ($pending_conflicts as $conflict) {
                echo '<li>' . esc_html($conflict['message']) . '</li>';
            }
            echo '</ul>';
            echo '<p><label><input type="checkbox" name="terricel_trip_confirm_conflicts_pending" value="1"> ' . esc_html__('Confirm these conflicts and create route vacancies through Route Coverage for the selected drivers.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</label></p></div>';
        }

        echo '<table class="widefat striped terricel-trip-assignments"><thead><tr>';
        echo '<th>' . esc_html__('Bus Slot', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</th>';
        echo '<th>' . esc_html__('Trip Bus', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</th>';
        echo '<th>' . esc_html__('Driver', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</th>';
        echo '</tr></thead><tbody id="terricel_trip_assignment_rows">';

        for ($i = 0; $i < $buses_needed; $i++) {
            $assignment = isset($assignments[$i]) ? $assignments[$i] : array('bus_id' => 0, 'driver_id' => 0);
            echo '<tr class="terricel-trip-assignment-slot-row">';
            echo '<td>' . esc_html(sprintf(__('Bus %d', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $i + 1)) . '</td>';
            echo '<td>' . $this->get_bus_select('terricel_trip_assignments[' . $i . '][bus_id]', absint($assignment['bus_id']), $post->ID) . '</td>';
            echo '<td><div class="terricel-trip-driver-cell">' . $this->get_driver_select('terricel_trip_assignments[' . $i . '][driver_id]', absint($assignment['driver_id'])) . '<span class="terricel-trip-conflict-status" hidden></span></div></td>';
            echo '</tr>';
            if ($show_actuals) {
                $this->render_trip_actuals_row($i, $actuals[$i] ?? array());
            }
        }

        echo '</tbody></table>';
        echo '<input type="hidden" id="terricel_trip_confirm_conflicts_hidden" name="terricel_trip_confirm_conflicts" value="">';
        echo '<div id="terricel_trip_conflict_dialog" class="terricel-trip-conflict-dialog" hidden role="dialog" aria-modal="true" aria-labelledby="terricel_trip_conflict_title">';
        echo '<div class="terricel-trip-conflict-card">';
        echo '<h3 id="terricel_trip_conflict_title">' . esc_html__('Driver Route Conflicts', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</h3>';
        echo '<p>' . esc_html__('These selected drivers have regular route work during this trip. Confirming will create route vacancy posts and Route Coverage will handle them from there.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</p>';
        echo '<ul id="terricel_trip_conflict_list"></ul>';
        echo '<div class="terricel-trip-conflict-actions"><button type="button" class="button button-primary terricel-trip-conflict-confirm" id="terricel_trip_confirm_conflict_button">' . esc_html__('Confirm Route Vacancies', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</button><button type="button" class="button" id="terricel_trip_close_conflict_dialog">' . esc_html__('Review Driver Selection', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</button></div>';
        echo '</div></div>';
        echo '<script type="text/template" id="terricel_trip_assignment_row_template">';
        echo '<tr class="terricel-trip-assignment-slot-row"><td>' . esc_html__('Bus __NUMBER__', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</td><td>' . $this->get_bus_select('terricel_trip_assignments[__INDEX__][bus_id]', 0, $post->ID) . '</td><td><div class="terricel-trip-driver-cell">' . $this->get_driver_select('terricel_trip_assignments[__INDEX__][driver_id]', 0) . '<span class="terricel-trip-conflict-status" hidden></span></div></td></tr>';
        if ($show_actuals) {
            $this->render_trip_actuals_row('__INDEX__', array());
        }
        echo '</script>';
    }

    private function render_trip_actuals_row($index, $actuals) {
        $actuals = is_array($actuals) ? $actuals : array();
        echo '<tr class="terricel-trip-actuals-row"><td colspan="3">';
        echo '<div class="terricel-trip-actuals">';
        echo '<strong>' . esc_html__('Actuals', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</strong>';
        echo '<div class="terricel-trip-actuals-grid">';
        foreach ($this->get_trip_actual_groups() as $group) {
            echo '<fieldset class="terricel-trip-actual-group">';
            echo '<legend>' . esc_html($group['label']) . '</legend>';
            foreach ($group['fields'] as $key) {
                $field = $this->get_trip_actual_fields()[$key];
                $value = $actuals[$key] ?? '';
                $type = 'time' === $field['type'] ? 'time' : 'number';
                $step = 'time' === $field['type'] ? '60' : '1';
                $min = 'time' === $field['type'] ? '' : ' min="0"';
                echo '<label><span>' . esc_html($field['label']) . '</span><input type="' . esc_attr($type) . '" name="terricel_trip_actuals[' . esc_attr($index) . '][' . esc_attr($key) . ']" value="' . esc_attr($value) . '" step="' . esc_attr($step) . '"' . $min . '></label>';
            }
            echo '</fieldset>';
        }
        echo '</div></div>';
        echo '</td></tr>';
    }

    private function get_trip_actual_groups() {
        return array(
            array('label' => __('Left Yard', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), 'fields' => array('left_yard_time', 'pre_trip_mileage')),
            array('label' => __('Departed', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), 'fields' => array('departed_time', 'departed_mileage')),
            array('label' => __('Arrived', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), 'fields' => array('arrived_time', 'arrived_mileage')),
            array('label' => __('Returning', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), 'fields' => array('returning_time', 'returning_mileage')),
            array('label' => __('Post-trip', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), 'fields' => array('post_trip_time', 'post_trip_mileage')),
        );
    }

    private function get_trip_actual_fields() {
        return array(
            'left_yard_time' => array('label' => __('Time Left Yard', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), 'type' => 'time'),
            'pre_trip_mileage' => array('label' => __('Pre-trip Mileage', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), 'type' => 'mileage'),
            'departed_time' => array('label' => __('Time Departed', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), 'type' => 'time'),
            'departed_mileage' => array('label' => __('Departed Mileage', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), 'type' => 'mileage'),
            'arrived_time' => array('label' => __('Time Arrived', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), 'type' => 'time'),
            'arrived_mileage' => array('label' => __('Arrived Mileage', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), 'type' => 'mileage'),
            'returning_time' => array('label' => __('Time Returning', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), 'type' => 'time'),
            'returning_mileage' => array('label' => __('Returning Mileage', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), 'type' => 'mileage'),
            'post_trip_time' => array('label' => __('Post-trip Time', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), 'type' => 'time'),
            'post_trip_mileage' => array('label' => __('Post-trip Mileage', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), 'type' => 'mileage'),
        );
    }

    public function render_group_details_meta_box($post) {
        wp_nonce_field('terricel_trip_group_meta', 'terricel_trip_group_meta_nonce');
        $school_id = (int) get_post_meta($post->ID, '_terricel_trip_group_school_id', true);
        $first_name = get_post_meta($post->ID, '_terricel_trip_group_advisor_first_name', true);
        $last_name = get_post_meta($post->ID, '_terricel_trip_group_advisor_last_name', true);
        $main_phone = get_post_meta($post->ID, '_terricel_trip_group_advisor_main_phone', true);
        $main_phone_extension = get_post_meta($post->ID, '_terricel_trip_group_advisor_main_phone_extension', true);
        $emergency_phone = get_post_meta($post->ID, '_terricel_trip_group_advisor_emergency_phone', true);
        $email = get_post_meta($post->ID, '_terricel_trip_group_advisor_email', true);

        echo '<div class="terricel-group-details-grid">';
        $this->render_post_select_field('terricel_trip_group_school_id', __('School', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), Terricel_Logistics_Shared_Data::SCHOOL_POST_TYPE, $school_id, __('Select school', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN));
        $this->render_text_field('terricel_trip_group_advisor_first_name', __('Advisor First Name', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $first_name, 'text');
        $this->render_text_field('terricel_trip_group_advisor_last_name', __('Advisor Last Name', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $last_name, 'text');
        $this->render_text_field('terricel_trip_group_advisor_main_phone', __('Main Phone', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $main_phone, 'tel');
        $this->render_text_field('terricel_trip_group_advisor_main_phone_extension', __('Extension', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $main_phone_extension, 'text', 'numeric');
        $this->render_text_field('terricel_trip_group_advisor_emergency_phone', __('Emergency Phone', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $emergency_phone, 'tel');
        $this->render_text_field('terricel_trip_group_advisor_email', __('Email', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $email, 'email');
        echo '</div>';
    }

    public function render_bus_trip_eligibility_meta_box($post) {
        wp_nonce_field('terricel_bus_trip_eligibility', 'terricel_bus_trip_eligibility_nonce');
        $checked = (bool) get_post_meta($post->ID, '_terricel_bus_used_for_trips', true);
        echo '<p><label><input type="checkbox" name="terricel_bus_used_for_trips" value="1"' . checked($checked, true, false) . '> ' . esc_html__('Used for Trips', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</label></p>';
    }

    public function save_trip_meta($post_id, $post) {
        if (!$this->can_save($post_id, 'terricel_trip_meta_nonce', 'terricel_trip_meta')) {
            return;
        }

        $school_id = absint($_POST['terricel_trip_school_id'] ?? 0);
        $group_id = absint($_POST['terricel_trip_group_id'] ?? 0);
        if (!$this->group_belongs_to_school($group_id, $school_id)) {
            $group_id = 0;
        }
        $pickup_date = $this->sanitize_date($_POST['terricel_trip_pickup_date'] ?? '');
        $pickup_time = $this->sanitize_time($_POST['terricel_trip_pickup_time'] ?? '');
        $arrival_date = $this->sanitize_date($_POST['terricel_trip_arrival_date'] ?? '') ?: $pickup_date;
        $departure_date = $this->sanitize_date($_POST['terricel_trip_departure_date'] ?? '') ?: $pickup_date;
        $return_date = $this->sanitize_date($_POST['terricel_trip_return_date'] ?? '') ?: $pickup_date;
        $destination = sanitize_textarea_field(wp_unslash($_POST['terricel_trip_destination_address'] ?? ''));
        $location_name = sanitize_text_field(wp_unslash($_POST['terricel_trip_location_name'] ?? ''));
        $buses_needed = min(50, max(0, absint($_POST['terricel_trip_buses_needed'] ?? 0)));

        update_post_meta($post_id, '_terricel_trip_school_id', $school_id);
        update_post_meta($post_id, '_terricel_trip_group_id', $group_id);
        update_post_meta($post_id, '_terricel_trip_pickup_date', $pickup_date);
        update_post_meta($post_id, '_terricel_trip_pickup_time', $pickup_time);
        update_post_meta($post_id, '_terricel_trip_arrival_date', $arrival_date);
        update_post_meta($post_id, '_terricel_trip_arrival_time', $this->sanitize_time($_POST['terricel_trip_arrival_time'] ?? ''));
        update_post_meta($post_id, '_terricel_trip_departure_date', $departure_date);
        update_post_meta($post_id, '_terricel_trip_departure_time', $this->sanitize_time($_POST['terricel_trip_departure_time'] ?? ''));
        update_post_meta($post_id, '_terricel_trip_return_date', $return_date);
        update_post_meta($post_id, '_terricel_trip_return_time', $this->sanitize_time($_POST['terricel_trip_return_time'] ?? ''));
        update_post_meta($post_id, '_terricel_trip_location_name', $location_name);
        update_post_meta($post_id, '_terricel_trip_destination_address', $destination);
        update_post_meta($post_id, '_terricel_trip_buses_needed', $buses_needed);
        $estimated_mileage = $this->sanitize_decimal($_POST['terricel_trip_estimated_mileage'] ?? '');
        $estimated_minutes = absint($_POST['terricel_trip_estimated_travel_minutes'] ?? 0);
        if ('' === $estimated_mileage || $estimated_minutes < 1) {
            $estimate = $this->get_google_distance_estimate($school_id, $destination);
            if ('' === $estimated_mileage && $estimate['miles'] > 0) {
                $estimated_mileage = $estimate['miles'];
            }
            if ($estimated_minutes < 1 && $estimate['minutes'] > 0) {
                $estimated_minutes = $estimate['minutes'];
            }
        }
        if ('' !== $estimated_mileage) {
            $estimated_mileage = (string) ceil((float) $estimated_mileage);
        }

        update_post_meta($post_id, '_terricel_trip_estimated_mileage', $estimated_mileage);
        update_post_meta($post_id, '_terricel_trip_estimated_travel_minutes', $estimated_minutes);

        if ($pickup_date && $pickup_time && $estimated_minutes > 0 && empty($_POST['terricel_trip_arrival_time'])) {
            $arrival_timestamp = strtotime($pickup_date . ' ' . $pickup_time) + ($estimated_minutes * MINUTE_IN_SECONDS);
            update_post_meta($post_id, '_terricel_trip_arrival_date', date('Y-m-d', $arrival_timestamp));
            update_post_meta($post_id, '_terricel_trip_arrival_time', date('H:i', $arrival_timestamp));
        }

        $assignments = $this->sanitize_assignments($_POST['terricel_trip_assignments'] ?? array(), $buses_needed, $post_id);
        $conflicts = $this->get_assignment_conflicts($post_id, $assignments);
        $conflict_signature = $this->get_conflict_signature($conflicts);
        $confirmed = !empty($_POST['terricel_trip_confirm_conflicts']) || !empty($_POST['terricel_trip_confirm_conflicts_pending']) || $this->is_conflict_signature_confirmed($post_id, $conflict_signature);

        if (!empty($conflicts) && !$confirmed) {
            update_post_meta($post_id, '_terricel_trip_pending_assignments', $assignments);
            update_post_meta($post_id, '_terricel_trip_pending_conflicts', $conflicts);
            set_transient('terricel_trip_conflicts_' . get_current_user_id(), $post_id, 60);
            return;
        }

        delete_post_meta($post_id, '_terricel_trip_pending_assignments');
        delete_post_meta($post_id, '_terricel_trip_pending_conflicts');

        if (!empty($conflicts) && $confirmed) {
            update_post_meta($post_id, '_terricel_trip_route_vacancy_ids', $this->create_route_coverage_vacancies($post_id, $conflicts));
            update_post_meta($post_id, '_terricel_trip_confirmed_conflict_signature', $conflict_signature);
        }

        $old_assignments = $this->get_trip_assignments($post_id);
        update_post_meta($post_id, '_terricel_trip_assignments', $assignments);
        if (isset($_POST['terricel_trip_actuals'])) {
            update_post_meta($post_id, '_terricel_trip_actuals', $this->sanitize_trip_actuals($_POST['terricel_trip_actuals'], $buses_needed));
        }
        $this->maybe_queue_driver_assignment_notifications($post_id, $old_assignments, $assignments);
        $this->maybe_update_trip_title($post_id, $post, $school_id, $group_id, $pickup_date, $location_name);
        $this->maybe_keep_incomplete_trip_draft($post_id);
    }

    public function save_group_meta($post_id, $post) {
        if (!$this->can_save($post_id, 'terricel_trip_group_meta_nonce', 'terricel_trip_group_meta')) {
            return;
        }

        update_post_meta($post_id, '_terricel_trip_group_school_id', absint($_POST['terricel_trip_group_school_id'] ?? 0));
        update_post_meta($post_id, '_terricel_trip_group_advisor_first_name', $this->sanitize_person_name($_POST['terricel_trip_group_advisor_first_name'] ?? ''));
        update_post_meta($post_id, '_terricel_trip_group_advisor_last_name', $this->sanitize_person_name($_POST['terricel_trip_group_advisor_last_name'] ?? ''));
        update_post_meta($post_id, '_terricel_trip_group_advisor_main_phone', $this->sanitize_phone($_POST['terricel_trip_group_advisor_main_phone'] ?? ''));
        update_post_meta($post_id, '_terricel_trip_group_advisor_main_phone_extension', $this->sanitize_extension($_POST['terricel_trip_group_advisor_main_phone_extension'] ?? ''));
        update_post_meta($post_id, '_terricel_trip_group_advisor_emergency_phone', $this->sanitize_phone($_POST['terricel_trip_group_advisor_emergency_phone'] ?? ''));
        update_post_meta($post_id, '_terricel_trip_group_advisor_email', sanitize_email(wp_unslash($_POST['terricel_trip_group_advisor_email'] ?? '')));
    }

    public function save_bus_trip_meta($post_id) {
        if (!$this->can_save($post_id, 'terricel_bus_trip_eligibility_nonce', 'terricel_bus_trip_eligibility')) {
            return;
        }

        !empty($_POST['terricel_bus_used_for_trips'])
            ? update_post_meta($post_id, '_terricel_bus_used_for_trips', 1)
            : delete_post_meta($post_id, '_terricel_bus_used_for_trips');
    }

    public function filter_post_redirect($location, $post_id) {
        if (self::TRIP_POST_TYPE === get_post_type($post_id) && !empty($_POST['save'])) {
            return add_query_arg('terricel-trip-draft-saved', 1, admin_url('edit.php?post_type=' . self::TRIP_POST_TYPE));
        }

        if ((int) get_transient('terricel_trip_conflicts_' . get_current_user_id()) === absint($post_id)) {
            delete_transient('terricel_trip_conflicts_' . get_current_user_id());
            $location = add_query_arg('terricel-trip-conflicts', 1, $location);
        }

        if ((int) get_transient('terricel_trip_incomplete_publish_' . get_current_user_id()) === absint($post_id)) {
            delete_transient('terricel_trip_incomplete_publish_' . get_current_user_id());
            $location = add_query_arg('terricel-trip-incomplete', 1, $location);
        }

        return $location;
    }

    public function render_admin_notices() {
        if (!empty($_GET['terricel-trip-conflicts'])) {
            echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__('Trip details were saved, but driver/bus assignments were not changed because conflicts need confirmation.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</p></div>';
        }

        if (!empty($_GET['terricel-trip-incomplete'])) {
            echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__('Trip was saved as a draft because the staged trip workflow is not complete yet.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</p></div>';
        }

        if (!empty($_GET['terricel-trip-draft-saved'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Trip draft saved.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</p></div>';
        }

    }

    public function send_due_trip_notifications() {
        $now = current_time('timestamp');
        $unassigned_hours = absint(get_option(Terricel_Transit_Trips_Plugin::OPTION_UNASSIGNED_NOTICE_HOURS, 72));
        $driver_hours = absint(get_option(Terricel_Transit_Trips_Plugin::OPTION_DRIVER_REMINDER_HOURS, 48));

        foreach ($this->get_upcoming_trips(14) as $trip) {
            $pickup = $this->get_trip_pickup_timestamp($trip->ID);
            if (!$pickup || $pickup < $now) {
                continue;
            }

            if ($pickup - $now <= $unassigned_hours * HOUR_IN_SECONDS && !$this->trip_has_driver_assignments($trip->ID) && !get_post_meta($trip->ID, '_terricel_trip_unassigned_notice_sent', true)) {
                $this->queue_operations_notification($trip->ID, 'trip_unassigned', __('Trip Needs Driver Assignment', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $this->format_trip_notice_message($trip->ID));
                update_post_meta($trip->ID, '_terricel_trip_unassigned_notice_sent', current_time('mysql'));
            }

            if ($pickup - $now <= $driver_hours * HOUR_IN_SECONDS && !get_post_meta($trip->ID, '_terricel_trip_driver_reminder_sent', true)) {
                foreach ($this->get_trip_assigned_driver_user_ids($trip->ID) as $user_id) {
                    $this->queue_user_notification($user_id, 'trip_driver_reminder', __('Upcoming Trip Assignment', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $this->format_trip_notice_message($trip->ID), $this->get_driver_dashboard_url());
                }
                update_post_meta($trip->ID, '_terricel_trip_driver_reminder_sent', current_time('mysql'));
            }
        }
    }

    public function render_driver_dashboard_trips($driver_id) {
        $trips = $this->get_driver_upcoming_trips($driver_id);
        if (empty($trips)) {
            echo '<p>' . esc_html__('No future trip assignments are scheduled.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</p>';
            return;
        }

        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>' . esc_html__('Pickup', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</th>';
        echo '<th>' . esc_html__('School', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</th>';
        echo '<th>' . esc_html__('Destination', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</th>';
        echo '<th>' . esc_html__('Map', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($trips as $trip) {
            echo '<tr>';
            echo '<td>' . esc_html($this->format_trip_pickup($trip->ID)) . '</td>';
            echo '<td>' . esc_html($this->get_school_label((int) get_post_meta($trip->ID, '_terricel_trip_school_id', true))) . '</td>';
            echo '<td>' . esc_html(get_post_meta($trip->ID, '_terricel_trip_location_name', true)) . '</td>';
            echo '<td><a class="button" target="_blank" rel="noopener" href="' . esc_url($this->get_trip_maps_url($trip->ID)) . '">' . esc_html__('Open', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    }

    public function get_kiosk_trip_monitor_data() {
        $today = current_time('Y-m-d');
        $dates = $this->get_kiosk_trip_monitor_dates($today);
        $query_start = min($dates);
        $query_end = max($dates);
        $days = array();
        $trips_by_date = array();
        $total_trips = 0;
        $total_vacant_assignments = 0;

        foreach ($dates as $date) {
            $timestamp = strtotime($date);
            $trips_by_date[$date] = array();
            $days[$date] = array(
                'date'       => $date,
                'day_label'  => date_i18n('l', $timestamp),
                'date_label' => date_i18n('M j', $timestamp),
                'is_today'   => $date === $today,
                'trip_count' => 0,
                'items'      => array(),
            );
        }

        foreach ($this->get_trip_report_trips($query_start, $query_end) as $trip) {
            $trip_id = absint($trip->ID);
            $date = get_post_meta($trip_id, '_terricel_trip_pickup_date', true);
            if (!isset($trips_by_date[$date])) {
                continue;
            }

            $item = $this->get_kiosk_trip_monitor_item($trip_id);
            $trips_by_date[$date][] = $item;
            $total_trips++;
            $total_vacant_assignments += absint($item['vacant_assignments']);
        }

        foreach ($trips_by_date as $date => $items) {
            usort(
                $items,
                function ($first, $second) {
                    return strcmp($first['sort_time'], $second['sort_time']);
                }
            );
            $days[$date]['trip_count'] = count($items);
            $days[$date]['items'] = $items;
        }

        return array(
            'view'                     => 'week',
            'week_start'               => $query_start,
            'week_end'                 => $query_end,
            'days'                     => array_values($days),
            'total_trips'              => $total_trips,
            'total_vacant_assignments' => $total_vacant_assignments,
        );
    }

    private function get_kiosk_trip_monitor_dates($today) {
        $dates = array();
        $today_timestamp = strtotime($today);
        $today_timestamp = $today_timestamp ? $today_timestamp : current_time('timestamp');
        $current_day_number = (int) date('w', $today_timestamp);

        for ($day_number = 0; $day_number <= 6; $day_number++) {
            $offset = $day_number - $current_day_number;
            if ($offset < 0) {
                $offset += 7;
            }

            $timestamp = strtotime('+' . $offset . ' days', $today_timestamp);
            $dates[] = $timestamp ? date('Y-m-d', $timestamp) : current_time('Y-m-d');
        }

        return $dates;
    }

    public function render_report_filters($selected_type, $report_query) {
        $selected_school_id = isset($report_query['terricel_report_trip_school_id']) ? absint($report_query['terricel_report_trip_school_id']) : 0;
        $selected_group_id = isset($report_query['terricel_report_trip_group_id']) ? absint($report_query['terricel_report_trip_group_id']) : 0;
        $start_date = isset($report_query['terricel_report_start_date']) ? $this->sanitize_date($report_query['terricel_report_start_date']) : current_time('Y-m-d');
        $end_date = isset($report_query['terricel_report_end_date']) ? $this->sanitize_date($report_query['terricel_report_end_date']) : $start_date;
        $groups = $this->get_trip_report_groups_for_range($start_date, $end_date, $selected_school_id);

        echo '<tr class="terricel-report-filter-trip">';
        echo '<th scope="row"><label for="terricel_report_trip_school_id">' . esc_html__('School', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</label></th>';
        echo '<td><select id="terricel_report_trip_school_id" name="terricel_report_trip_school_id">';
        echo '<option value="0">' . esc_html__('All schools', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</option>';
        foreach ($this->get_trip_report_schools_for_range($start_date, $end_date) as $school) {
            echo '<option value="' . esc_attr($school['id']) . '"' . selected($selected_school_id, $school['id'], false) . '>' . esc_html($school['label']) . '</option>';
        }
        echo '</select></td>';
        echo '</tr>';

        echo '<tr class="terricel-report-filter-trip">';
        echo '<th scope="row"><label for="terricel_report_trip_group_id">' . esc_html__('School Group', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</label></th>';
        echo '<td><select id="terricel_report_trip_group_id" name="terricel_report_trip_group_id">';
        echo '<option value="0">' . esc_html__('All groups', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</option>';
        foreach ($groups as $group) {
            echo '<option value="' . esc_attr($group['id']) . '"' . selected($selected_group_id, $group['id'], false) . '>' . esc_html($group['label']) . '</option>';
        }
        echo '</select> <span class="description">' . esc_html__('Groups are limited to groups with trips during the selected date range.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</span></td>';
        echo '</tr>';

        echo '<script>';
        echo '(function(){var type=document.getElementById("terricel_report_type");var start=document.getElementById("terricel_report_start_date");var end=document.getElementById("terricel_report_end_date");var school=document.getElementById("terricel_report_trip_school_id");var group=document.getElementById("terricel_report_trip_group_id");if(!type||!start||!end||!school||!group){return;}var nonce=' . wp_json_encode(wp_create_nonce('terricel_trip_report_groups')) . ';function option(value,label,selected){var item=document.createElement("option");item.value=String(value);item.textContent=label;if(selected){item.selected=true;}return item;}function refill(select,items,allLabel,current){select.innerHTML="";select.appendChild(option("0",allLabel,current==="0"));(items||[]).forEach(function(row){select.appendChild(option(row.id,row.label,String(row.id)===String(current)));});if(select.selectedIndex<0){select.value="0";}}function refresh(){if(type.value!=="trips_by_school"){return;}var currentSchool=school.value;var currentGroup=group.value;var body=new URLSearchParams({action:"terricel_trip_report_groups",nonce:nonce,start_date:start.value,end_date:end.value,school_id:currentSchool});fetch(ajaxurl,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/x-www-form-urlencoded; charset=UTF-8"},body:body.toString()}).then(function(response){return response.json();}).then(function(json){if(!json||!json.success){return;}refill(school,json.data.schools||[],' . wp_json_encode(__('All schools', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN)) . ',currentSchool);refill(group,json.data.groups||[],' . wp_json_encode(__('All groups', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN)) . ',currentGroup);});}type.addEventListener("change",refresh);start.addEventListener("change",refresh);end.addEventListener("change",refresh);school.addEventListener("change",function(){group.value="0";refresh();});}());';
        echo '</script>';
    }

    public function ajax_report_groups() {
        if (!current_user_can('terricel_manage_operations')) {
            wp_send_json_error(array('message' => __('You do not have permission to view reports.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN)), 403);
        }

        check_ajax_referer('terricel_trip_report_groups', 'nonce');

        $start_date = $this->sanitize_date($_POST['start_date'] ?? '') ?: current_time('Y-m-d');
        $end_date = $this->sanitize_date($_POST['end_date'] ?? '') ?: $start_date;
        $school_id = absint($_POST['school_id'] ?? 0);

        wp_send_json_success(array(
            'schools' => $this->get_trip_report_schools_for_range($start_date, $end_date),
            'groups'  => $this->get_trip_report_groups_for_range($start_date, $end_date, $school_id),
        ));
    }

    public function get_report_availability($start_date, $end_date, $request = array()) {
        $request = is_array($request) ? $request : array();
        $school_id = isset($request['terricel_report_trip_school_id']) ? absint($request['terricel_report_trip_school_id']) : 0;
        $group_id = isset($request['terricel_report_trip_group_id']) ? absint($request['terricel_report_trip_group_id']) : 0;
        $schools = $this->get_trip_report_schools_for_range($start_date, $end_date);
        if ($school_id > 0 && !in_array($school_id, array_map('absint', wp_list_pluck($schools, 'id')), true)) {
            $school_id = 0;
        }

        $groups = $this->get_trip_report_groups_for_range($start_date, $end_date, $school_id);
        if ($group_id > 0 && !in_array($group_id, array_map('absint', wp_list_pluck($groups, 'id')), true)) {
            $group_id = 0;
        }

        return array(
            'has_data' => !empty($this->get_trip_report_trips($start_date, $end_date, $group_id, $school_id)),
            'filters'  => array(
                'terricel_report_trip_school_id' => array(
                    'all_label' => __('All schools', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
                    'options'   => $schools,
                ),
                'terricel_report_trip_group_id'  => array(
                    'all_label' => __('All groups', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
                    'options'   => $groups,
                ),
            ),
        );
    }

    public function build_trips_by_school_report($start_date, $end_date, $request) {
        $selected_school_id = isset($request['terricel_report_trip_school_id']) ? absint($request['terricel_report_trip_school_id']) : 0;
        $selected_group_id = isset($request['terricel_report_trip_group_id']) ? absint($request['terricel_report_trip_group_id']) : 0;
        $sections = array();

        foreach ($this->get_trip_report_trips($start_date, $end_date, $selected_group_id, $selected_school_id) as $trip) {
            $trip_id = absint($trip->ID);
            $school_id = absint(get_post_meta($trip_id, '_terricel_trip_school_id', true));
            $group_id = absint(get_post_meta($trip_id, '_terricel_trip_group_id', true));
            $school_label = $this->get_school_label($school_id);
            $group_label = $group_id > 0 ? get_the_title($group_id) : __('Unassigned Group', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN);
            $section_label = sprintf(
                /* translators: 1: group name, 2: school label. */
                __('%1$s - %2$s', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
                $group_label,
                $school_label
            );

            if (!isset($sections[$section_label])) {
                $sections[$section_label] = array(
                    'title'                    => $section_label,
                    'rows'                     => array(),
                    'total_actual_mileage'     => 0.0,
                    'has_total_actual_mileage' => false,
                );
            }

            $assignments = $this->get_trip_assignments($trip_id);
            $actuals = $this->get_trip_actuals($trip_id);
            $total_actual_mileage = $this->get_trip_total_actual_mileage($assignments, $actuals);
            if (null !== $total_actual_mileage) {
                $sections[$section_label]['total_actual_mileage'] += $total_actual_mileage;
                $sections[$section_label]['has_total_actual_mileage'] = true;
            }

            $sections[$section_label]['rows'][] = array(
                'pickup'      => $this->format_trip_report_pickup($trip_id),
                'school'      => $school_label,
                'advisor'     => $group_id > 0 ? $this->get_group_advisor_name($group_id) : __('Not set', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
                'destination' => $this->get_trip_destination_label($trip_id),
                'actual_mileage' => $this->format_report_mileage($total_actual_mileage),
                '_nested_table' => array(
                    'columns' => array(
                        array('key' => 'bus', 'label' => __('Bus', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), 'width' => 90),
                        array('key' => 'driver', 'label' => __('Driver', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), 'width' => 116),
                        array('key' => 'pre_trip_mileage', 'label' => __('Pre-trip Mileage', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), 'width' => 90),
                        array('key' => 'post_trip_mileage', 'label' => __('Post-trip Mileage', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), 'width' => 90),
                        array('key' => 'total_mileage', 'label' => __('Total Mileage', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), 'width' => 90),
                    ),
                    'rows' => $this->get_trip_report_assignment_rows($assignments, $actuals),
                ),
            );
        }

        ksort($sections, SORT_NATURAL | SORT_FLAG_CASE);
        foreach ($sections as &$section) {
            $section['summary_right'] = sprintf(
                /* translators: %s: mileage label. */
                __('Total Mileage: %s', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
                $this->format_report_mileage($section['has_total_actual_mileage'] ? $section['total_actual_mileage'] : null)
            );
            unset($section['total_actual_mileage'], $section['has_total_actual_mileage']);
        }
        unset($section);

        return array(
            'title'    => __('Trips by School', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
            'filename' => 'trips-by-school',
            'columns'  => array(
                array('key' => 'pickup', 'label' => __('Pickup', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), 'width' => 96),
                array('key' => 'school', 'label' => __('School', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), 'width' => 58),
                array('key' => 'advisor', 'label' => __('Advisor', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), 'width' => 68),
                array('key' => 'destination', 'label' => __('Destination', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), 'width' => 180),
                array('key' => 'actual_mileage', 'label' => __('Total Actual Mileage', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), 'width' => 110),
            ),
            'sections' => array_values($sections),
        );
    }

    private function get_trip_report_assignment_rows($assignments, $actuals) {
        $rows = array();
        $slot_count = max(count($assignments), count($actuals));

        for ($i = 0; $i < $slot_count; $i++) {
            $assignment = isset($assignments[$i]) && is_array($assignments[$i]) ? $assignments[$i] : array();
            $actual = isset($actuals[$i]) && is_array($actuals[$i]) ? $actuals[$i] : array();
            $bus_id = absint($assignment['bus_id'] ?? 0);
            $driver_id = absint($assignment['driver_id'] ?? 0);
            $pre_mileage = $this->get_actual_mileage_value($actual, 'pre_trip_mileage');
            $post_mileage = $this->get_actual_mileage_value($actual, 'post_trip_mileage');
            $total_mileage = $this->calculate_actual_mileage_total($pre_mileage, $post_mileage);

            $rows[] = array(
                'bus' => $bus_id > 0 ? get_the_title($bus_id) : __('No bus', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
                'driver' => $driver_id > 0 ? get_the_title($driver_id) : __('Vacant', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
                'pre_trip_mileage' => $this->format_report_mileage($pre_mileage, false),
                'post_trip_mileage' => $this->format_report_mileage($post_mileage, false),
                'total_mileage' => $this->format_report_mileage($total_mileage),
            );
        }

        return $rows;
    }

    private function get_trip_total_actual_mileage($assignments, $actuals) {
        $total = 0.0;
        $has_total = false;
        $slot_count = max(count($assignments), count($actuals));

        for ($i = 0; $i < $slot_count; $i++) {
            $actual = isset($actuals[$i]) && is_array($actuals[$i]) ? $actuals[$i] : array();
            $slot_total = $this->calculate_actual_mileage_total(
                $this->get_actual_mileage_value($actual, 'pre_trip_mileage'),
                $this->get_actual_mileage_value($actual, 'post_trip_mileage')
            );

            if (null !== $slot_total) {
                $total += $slot_total;
                $has_total = true;
            }
        }

        return $has_total ? $total : null;
    }

    private function get_actual_mileage_value($actual, $key) {
        if (!is_array($actual) || !isset($actual[$key]) || '' === (string) $actual[$key]) {
            return null;
        }

        return (float) $actual[$key];
    }

    private function calculate_actual_mileage_total($pre_mileage, $post_mileage) {
        if (null === $pre_mileage || null === $post_mileage) {
            return null;
        }

        return max(0, (float) $post_mileage - (float) $pre_mileage);
    }

    private function format_report_mileage($mileage, $include_unit = true) {
        if (null === $mileage || '' === (string) $mileage) {
            return __('Not set', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN);
        }

        $value = rtrim(rtrim(number_format_i18n((float) $mileage, 1), '0'), '.');
        return $include_unit ? sprintf(__('%s mi', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $value) : $value;
    }

    private function get_trip_report_schools_for_range($start_date, $end_date) {
        $schools = array();

        foreach ($this->get_trip_report_trips($start_date, $end_date) as $trip) {
            $school_id = absint(get_post_meta($trip->ID, '_terricel_trip_school_id', true));
            if ($school_id < 1 || isset($schools[$school_id])) {
                continue;
            }

            $schools[$school_id] = array(
                'id'    => $school_id,
                'label' => $this->get_school_label($school_id),
            );
        }

        uasort(
            $schools,
            function ($first, $second) {
                return strnatcasecmp($first['label'], $second['label']);
            }
        );

        return array_values($schools);
    }

    private function get_trip_report_groups_for_range($start_date, $end_date, $school_id = 0) {
        $groups = array();

        foreach ($this->get_trip_report_trips($start_date, $end_date, 0, $school_id) as $trip) {
            $group_id = absint(get_post_meta($trip->ID, '_terricel_trip_group_id', true));
            if ($group_id < 1 || isset($groups[$group_id])) {
                continue;
            }

            $groups[$group_id] = array(
                'id'    => $group_id,
                'label' => $this->get_group_select_label($group_id),
            );
        }

        uasort(
            $groups,
            function ($first, $second) {
                return strnatcasecmp($first['label'], $second['label']);
            }
        );

        return array_values($groups);
    }

    private function get_trip_report_trips($start_date, $end_date, $group_id = 0, $school_id = 0) {
        $start_date = $this->sanitize_date($start_date) ?: current_time('Y-m-d');
        $end_date = $this->sanitize_date($end_date) ?: $start_date;

        if (strtotime($end_date) < strtotime($start_date)) {
            $end_date = $start_date;
        }

        $meta_query = array(
            array(
                'key'     => '_terricel_trip_pickup_date',
                'value'   => array($start_date, $end_date),
                'compare' => 'BETWEEN',
                'type'    => 'DATE',
            ),
        );

        if ($school_id > 0) {
            $meta_query[] = array(
                'key'     => '_terricel_trip_school_id',
                'value'   => absint($school_id),
                'compare' => '=',
                'type'    => 'NUMERIC',
            );
        }

        if ($group_id > 0) {
            $meta_query[] = array(
                'key'     => '_terricel_trip_group_id',
                'value'   => absint($group_id),
                'compare' => '=',
                'type'    => 'NUMERIC',
            );
        }

        return get_posts(
            array(
                'post_type'      => self::TRIP_POST_TYPE,
                'post_status'    => 'publish',
                'posts_per_page' => 1000,
                'orderby'        => 'meta_value',
                'order'          => 'ASC',
                'meta_key'       => '_terricel_trip_pickup_date',
                'meta_query'     => $meta_query,
            )
        );
    }

    private function render_upcoming_trip_summary() {
        $trips = $this->get_upcoming_trips(30);
        echo '<h2>' . esc_html__('Upcoming Trips', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</h2>';
        if (empty($trips)) {
            echo '<div class="notice notice-info inline"><p>' . esc_html__('No upcoming trips are scheduled.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</p></div>';
            return;
        }

        echo '<table class="widefat striped"><thead><tr><th>' . esc_html__('Trip', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</th><th>' . esc_html__('Pickup', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</th><th>' . esc_html__('Assignments', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</th></tr></thead><tbody>';
        foreach ($trips as $trip) {
            echo '<tr><td><a href="' . esc_url(get_edit_post_link($trip->ID)) . '">' . esc_html(get_the_title($trip)) . '</a></td><td>' . esc_html($this->format_trip_pickup($trip->ID)) . '</td><td>' . esc_html(count($this->get_trip_assignments($trip->ID))) . '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private function get_assignment_conflicts($trip_id, $assignments, $start_date = '', $start_time = '', $end_date = '', $end_time = '') {
        $conflicts = array();
        $route_coverage = $this->get_route_coverage_module();
        if (!$route_coverage || !method_exists($route_coverage, 'get_trip_driver_route_conflicts')) {
            return $conflicts;
        }

        $start_date = $this->sanitize_date($start_date) ?: get_post_meta($trip_id, '_terricel_trip_pickup_date', true);
        $start_time = $this->sanitize_time($start_time) ?: get_post_meta($trip_id, '_terricel_trip_pickup_time', true);
        $end_date = $this->sanitize_date($end_date) ?: (get_post_meta($trip_id, '_terricel_trip_return_date', true) ?: $start_date);
        $end_time = $this->sanitize_time($end_time) ?: (get_post_meta($trip_id, '_terricel_trip_return_time', true) ?: '23:59');

        if (!$start_date || !$start_time || !$end_date || !$end_time) {
            return $conflicts;
        }

        foreach ($assignments as $assignment) {
            $driver_id = absint($assignment['driver_id']);
            if ($driver_id < 1) {
                continue;
            }

            foreach ($route_coverage->get_trip_driver_route_conflicts($driver_id, $start_date, $start_time, $end_date, $end_time) as $route_conflict) {
                $conflicts[] = $route_conflict;
            }
        }

        return $conflicts;
    }

    private function get_route_coverage_module() {
        if (function_exists('terricel_route_coverage')) {
            $module = terricel_route_coverage();
            if ($module) {
                return $module;
            }
        }

        return null;
    }

    private function create_route_coverage_vacancies($trip_id, $conflicts) {
        $route_coverage = $this->get_route_coverage_module();
        if (!$route_coverage || !method_exists($route_coverage, 'create_trip_route_vacancies')) {
            return array();
        }

        return $route_coverage->create_trip_route_vacancies($trip_id, $conflicts);
    }

    private function get_conflict_signature($conflicts) {
        $items = array();
        foreach ((array) $conflicts as $conflict) {
            if (!is_array($conflict)) {
                continue;
            }

            $items[] = implode(
                '|',
                array(
                    absint($conflict['driver_id'] ?? 0),
                    absint($conflict['route_id'] ?? 0),
                    sanitize_text_field($conflict['date'] ?? ''),
                    sanitize_key($conflict['run_key'] ?? ''),
                    $this->sanitize_time($conflict['start_time'] ?? ''),
                    $this->sanitize_time($conflict['end_time'] ?? ''),
                )
            );
        }

        sort($items);

        return empty($items) ? '' : wp_json_encode($items);
    }

    private function is_conflict_signature_confirmed($trip_id, $signature) {
        $signature = (string) $signature;
        if ('' === $signature) {
            return false;
        }

        $stored_signature = (string) get_post_meta($trip_id, '_terricel_trip_confirmed_conflict_signature', true);
        if ('' !== $stored_signature && hash_equals($stored_signature, $signature)) {
            return true;
        }

        foreach ($this->get_legacy_confirmed_conflict_signatures($trip_id) as $legacy_signature) {
            if (hash_equals((string) $legacy_signature, $signature)) {
                update_post_meta($trip_id, '_terricel_trip_confirmed_conflict_signature', $signature);
                return true;
            }
        }

        return false;
    }

    private function get_legacy_confirmed_conflict_signatures($trip_id) {
        $vacancy_ids = get_post_meta($trip_id, '_terricel_trip_route_vacancy_ids', true);
        $vacancy_ids = is_array($vacancy_ids) ? array_map('absint', $vacancy_ids) : array();
        $signatures = array();
        $conflicts = array();

        foreach ($vacancy_ids as $vacancy_id) {
            if ($vacancy_id < 1 || 'terricel_vacancy' !== get_post_type($vacancy_id)) {
                continue;
            }

            $driver_id = absint(get_post_meta($vacancy_id, '_terricel_vacancy_driver_id', true));
            $route_id = absint(get_post_meta($vacancy_id, '_terricel_vacancy_route_id', true));
            $date = $this->sanitize_date(get_post_meta($vacancy_id, '_terricel_vacancy_date', true));
            $runs = get_post_meta($vacancy_id, '_terricel_vacancy_runs', true);
            $runs = is_array($runs) ? $runs : array();

            foreach ($runs as $run) {
                if (!is_array($run)) {
                    continue;
                }

                $conflicts[] = array(
                    'driver_id'  => $driver_id,
                    'route_id'   => $route_id,
                    'date'       => $this->sanitize_date($run['date'] ?? '') ?: $date,
                    'run_key'    => sanitize_key($run['run_key'] ?? ''),
                    'start_time' => $this->sanitize_time($run['start_time'] ?? ''),
                    'end_time'   => $this->sanitize_time($run['end_time'] ?? ''),
                );
            }
        }

        $signature = $this->get_conflict_signature($conflicts);
        if ('' !== $signature) {
            $signatures[] = $signature;
        }

        return $signatures;
    }

    private function maybe_queue_driver_assignment_notifications($trip_id, $old_assignments, $new_assignments) {
        $old_driver_ids = $this->get_assignment_driver_ids($old_assignments);
        $new_driver_ids = $this->get_assignment_driver_ids($new_assignments);

        foreach (array_diff($new_driver_ids, $old_driver_ids) as $driver_id) {
            $user_id = (int) get_post_meta($driver_id, '_terricel_driver_user_id', true);
            if ($user_id > 0) {
                $this->queue_user_notification($user_id, 'trip_driver_assigned', __('New Trip Assignment', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $this->format_trip_notice_message($trip_id), $this->get_driver_dashboard_url());
            }
        }

        foreach (array_diff($old_driver_ids, $new_driver_ids) as $driver_id) {
            $user_id = (int) get_post_meta($driver_id, '_terricel_driver_user_id', true);
            if ($user_id > 0) {
                $this->queue_user_notification($user_id, 'trip_driver_unassigned', __('Trip Assignment Removed', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $this->format_trip_unassignment_notice_message($trip_id), $this->get_driver_dashboard_url());
            }
        }
    }

    private function get_assignment_driver_ids($assignments) {
        $ids = array();
        foreach ((array) $assignments as $assignment) {
            if (!is_array($assignment)) {
                continue;
            }

            $driver_id = absint($assignment['driver_id'] ?? 0);
            if ($driver_id > 0) {
                $ids[] = $driver_id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function queue_operations_notification($trip_id, $event_key, $subject, $message) {
        $url = get_edit_post_link($trip_id, 'raw');
        foreach (get_users(array('fields' => array('ID'))) as $user) {
            if (user_can($user->ID, Terricel_Transit_Trips_Plugin::CAP_MANAGE_TRIPS)) {
                $this->queue_user_notification($user->ID, $event_key, $subject, $message, $url);
            }
        }
    }

    private function queue_user_notification($user_id, $event_key, $subject, $message, $url = '') {
        if (function_exists('terricel_logistics_queue_user_notification')) {
            terricel_logistics_queue_user_notification(self::MODULE_ID, $user_id, $subject, $message, $url, $event_key);
        }
    }

    private function get_trip_assigned_driver_user_ids($trip_id) {
        $user_ids = array();
        foreach ($this->get_trip_assignments($trip_id) as $assignment) {
            $driver_id = absint($assignment['driver_id']);
            $user_id = $driver_id ? (int) get_post_meta($driver_id, '_terricel_driver_user_id', true) : 0;
            if ($user_id > 0) {
                $user_ids[] = $user_id;
            }
        }

        return array_values(array_unique($user_ids));
    }

    private function get_driver_upcoming_trips($driver_id) {
        $trips = $this->get_upcoming_trips(365);

        return array_values(array_filter($trips, function ($trip) use ($driver_id) {
            $return_timestamp = $this->get_trip_return_timestamp($trip->ID);
            $pickup_timestamp = $this->get_trip_pickup_timestamp($trip->ID);
            $comparison_timestamp = $return_timestamp ? $return_timestamp : $pickup_timestamp;
            if ($comparison_timestamp && $comparison_timestamp < current_time('timestamp')) {
                return false;
            }

            foreach ($this->get_trip_assignments($trip->ID) as $assignment) {
                if (absint($assignment['driver_id']) === absint($driver_id)) {
                    return true;
                }
            }
            return false;
        }));
    }

    private function get_upcoming_trips($days) {
        return get_posts(
            array(
                'post_type'      => self::TRIP_POST_TYPE,
                'post_status'    => array('publish', 'draft', 'pending', 'future'),
                'posts_per_page' => 100,
                'orderby'        => 'meta_value',
                'order'          => 'ASC',
                'meta_key'       => '_terricel_trip_pickup_date',
                'meta_query'     => array(
                    array(
                        'key'     => '_terricel_trip_pickup_date',
                        'value'   => array(current_time('Y-m-d'), date('Y-m-d', current_time('timestamp') + absint($days) * DAY_IN_SECONDS)),
                        'compare' => 'BETWEEN',
                        'type'    => 'DATE',
                    ),
                ),
            )
        );
    }

    private function trip_has_driver_assignments($trip_id) {
        foreach ($this->get_trip_assignments($trip_id) as $assignment) {
            if (absint($assignment['driver_id']) > 0) {
                return true;
            }
        }

        return false;
    }

    private function get_current_user_driver_id() {
        $user_id = get_current_user_id();
        if ($user_id < 1) {
            return 0;
        }

        $driver_id = (int) get_user_meta($user_id, '_terricel_linked_driver_id', true);
        if ($driver_id > 0 && Terricel_Logistics_Shared_Data::DRIVER_POST_TYPE === get_post_type($driver_id)) {
            return $driver_id;
        }

        $user = get_user_by('id', $user_id);
        if (!$user || empty($user->user_email)) {
            return 0;
        }

        $drivers = get_posts(
            array(
                'post_type'      => Terricel_Logistics_Shared_Data::DRIVER_POST_TYPE,
                'post_status'    => 'publish',
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'meta_key'       => '_terricel_driver_email',
                'meta_value'     => sanitize_email($user->user_email),
            )
        );

        return empty($drivers) ? 0 : absint($drivers[0]);
    }

    private function get_trip_assignments($trip_id) {
        $assignments = get_post_meta($trip_id, '_terricel_trip_assignments', true);
        return is_array($assignments) ? array_values($assignments) : array();
    }

    private function get_kiosk_trip_monitor_item($trip_id) {
        $assignments = $this->get_trip_assignments($trip_id);
        $buses_needed = absint(get_post_meta($trip_id, '_terricel_trip_buses_needed', true));
        $slot_count = max($buses_needed, count($assignments));
        $assignment_rows = array();
        $vacant_assignments = 0;

        for ($i = 0; $i < $slot_count; $i++) {
            $assignment = isset($assignments[$i]) && is_array($assignments[$i]) ? $assignments[$i] : array();
            $bus_id = absint($assignment['bus_id'] ?? 0);
            $driver_id = absint($assignment['driver_id'] ?? 0);
            $is_vacant = $driver_id < 1;

            if ($is_vacant) {
                $vacant_assignments++;
            }

            $assignment_rows[] = array(
                'slot'   => $this->get_assignment_slot_label($i),
                'bus'    => $bus_id > 0 ? get_the_title($bus_id) : __('No bus', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
                'driver' => $driver_id > 0 ? get_the_title($driver_id) : __('Vacant', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
                'vacant' => $is_vacant,
            );
        }

        $pickup_time = get_post_meta($trip_id, '_terricel_trip_pickup_time', true);
        $return_time = get_post_meta($trip_id, '_terricel_trip_return_time', true);
        $group_id = absint(get_post_meta($trip_id, '_terricel_trip_group_id', true));

        return array(
            'id'                 => $trip_id,
            'title'              => get_the_title($trip_id),
            'school'             => $this->get_school_label((int) get_post_meta($trip_id, '_terricel_trip_school_id', true)),
            'group'              => $group_id > 0 ? get_the_title($group_id) : '',
            'destination'        => $this->get_trip_destination_label($trip_id),
            'pickup_label'       => $pickup_time ? date_i18n(get_option('time_format'), strtotime($pickup_time)) : __('Pickup not set', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
            'return_label'       => $return_time ? date_i18n(get_option('time_format'), strtotime($return_time)) : '',
            'sort_time'          => $pickup_time ? $this->sanitize_time($pickup_time) : '99:99',
            'assignments'        => $assignment_rows,
            'vacant_assignments' => $vacant_assignments,
            'has_vacancy'        => $vacant_assignments > 0,
        );
    }

    private function get_trip_actuals($trip_id) {
        $actuals = get_post_meta($trip_id, '_terricel_trip_actuals', true);
        return is_array($actuals) ? array_values($actuals) : array();
    }

    private function sanitize_assignments($assignments, $limit, $trip_id = 0) {
        $assignments = is_array($assignments) ? $assignments : array();
        $clean = array();
        $used_bus_ids = array();

        foreach (array_slice($assignments, 0, $limit) as $assignment) {
            $bus_id = absint($assignment['bus_id'] ?? 0);
            $driver_id = absint($assignment['driver_id'] ?? 0);
            if ($bus_id > 0) {
                if (in_array($bus_id, $used_bus_ids, true) || ($trip_id > 0 && $this->bus_has_trip_conflict($bus_id, $trip_id))) {
                    $bus_id = 0;
                } else {
                    $used_bus_ids[] = $bus_id;
                }
            }
            if ($bus_id < 1 && $driver_id < 1) {
                continue;
            }
            $clean[] = array('bus_id' => $bus_id, 'driver_id' => $driver_id);
        }

        return $clean;
    }

    private function sanitize_trip_actuals($actuals, $limit) {
        $actuals = is_array($actuals) ? $actuals : array();
        $clean = array();
        $fields = $this->get_trip_actual_fields();

        for ($i = 0; $i < $limit; $i++) {
            $row = isset($actuals[$i]) && is_array($actuals[$i]) ? $actuals[$i] : array();
            $clean_row = array();

            foreach ($fields as $key => $field) {
                if ('time' === $field['type']) {
                    $clean_row[$key] = $this->sanitize_time($row[$key] ?? '');
                } else {
                    $clean_row[$key] = $this->sanitize_decimal($row[$key] ?? '');
                }
            }

            $clean[] = $clean_row;
        }

        return $clean;
    }

    private function get_trip_pickup_timestamp($trip_id) {
        $date = get_post_meta($trip_id, '_terricel_trip_pickup_date', true);
        $time = get_post_meta($trip_id, '_terricel_trip_pickup_time', true);
        if (!$date || !$time) {
            return 0;
        }

        return strtotime($date . ' ' . $time);
    }

    private function get_trip_return_timestamp($trip_id) {
        $date = get_post_meta($trip_id, '_terricel_trip_return_date', true);
        $time = get_post_meta($trip_id, '_terricel_trip_return_time', true);
        if (!$date || !$time) {
            return 0;
        }

        return strtotime($date . ' ' . $time);
    }

    private function format_trip_pickup($trip_id) {
        $date = get_post_meta($trip_id, '_terricel_trip_pickup_date', true);
        $time = get_post_meta($trip_id, '_terricel_trip_pickup_time', true);
        $date_label = $date ? date_i18n(get_option('date_format'), strtotime($date)) : __('Date not set', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN);
        $time_label = $time ? date_i18n(get_option('time_format'), strtotime($time)) : __('Time not set', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN);

        return $date_label . ' ' . $time_label;
    }

    private function format_trip_report_pickup($trip_id) {
        $date = get_post_meta($trip_id, '_terricel_trip_pickup_date', true);
        $time = get_post_meta($trip_id, '_terricel_trip_pickup_time', true);
        $date_label = $date ? strtoupper(date_i18n('M j, Y', strtotime($date))) : __('Date not set', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN);
        $time_label = $time ? date_i18n(get_option('time_format'), strtotime($time)) : __('Time not set', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN);

        return $date_label . ' ' . $time_label;
    }

    private function format_trip_return($trip_id) {
        $date = get_post_meta($trip_id, '_terricel_trip_return_date', true);
        $time = get_post_meta($trip_id, '_terricel_trip_return_time', true);
        $date_label = $date ? date_i18n(get_option('date_format'), strtotime($date)) : __('Date not set', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN);
        $time_label = $time ? date_i18n(get_option('time_format'), strtotime($time)) : __('Time not set', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN);

        return $date_label . ' ' . $time_label;
    }

    private function get_trip_destination_label($trip_id) {
        $location_name = get_post_meta($trip_id, '_terricel_trip_location_name', true);
        $address = get_post_meta($trip_id, '_terricel_trip_destination_address', true);

        return $location_name ? $location_name : ($address ? $address : __('Not set', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN));
    }

    private function format_trip_assignment_summary($trip_id) {
        $assignments = $this->get_trip_assignments($trip_id);
        $buses_needed = absint(get_post_meta($trip_id, '_terricel_trip_buses_needed', true));
        $slot_count = max($buses_needed, count($assignments));

        if ($slot_count < 1) {
            return esc_html__('None', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN);
        }

        $lines = array();
        for ($i = 0; $i < $slot_count; $i++) {
            $assignment = isset($assignments[$i]) && is_array($assignments[$i]) ? $assignments[$i] : array();
            $bus_id = absint($assignment['bus_id'] ?? 0);
            $driver_id = absint($assignment['driver_id'] ?? 0);
            $bus = $bus_id ? get_the_title($bus_id) : __('No bus', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN);
            $driver = $driver_id ? get_the_title($driver_id) : __('Vacant', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN);

            $lines[] = sprintf(
                '<div>%1$s: %2$s - %3$s</div>',
                esc_html($this->get_assignment_slot_label($i)),
                esc_html($bus),
                esc_html($driver)
            );
        }

        return '<div class="terricel-trip-assignment-summary">' . implode('', $lines) . '</div>';
    }

    private function get_assignment_slot_label($index) {
        $index = absint($index);
        $label = '';

        do {
            $label = chr(65 + ($index % 26)) . $label;
            $index = (int) floor($index / 26) - 1;
        } while ($index >= 0);

        return $label;
    }

    private function format_trip_last_modified($post_id) {
        $post = get_post($post_id);
        if (!$post) {
            return '';
        }

        $modified = get_date_from_gmt($post->post_modified_gmt ?: $post->post_modified, get_option('date_format') . ' ' . get_option('time_format'));
        $user_name = $this->get_last_modified_user_name($post_id);

        if ($user_name) {
            return sprintf(
                '<span>%1$s</span><br><span class="terricel-trip-modified-by">%2$s</span>',
                esc_html($modified),
                esc_html(sprintf(__('by %s', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $user_name))
            );
        }

        return esc_html($modified);
    }

    private function get_last_modified_user_name($post_id) {
        $user_id = absint(get_post_meta($post_id, '_edit_last', true));
        if ($user_id < 1) {
            $post = get_post($post_id);
            $user_id = $post ? absint($post->post_author) : 0;
        }

        if ($user_id < 1) {
            return '';
        }

        $user = get_userdata($user_id);
        if (!$user) {
            return '';
        }

        $first_name = get_user_meta($user_id, 'first_name', true);
        $last_name = get_user_meta($user_id, 'last_name', true);
        $name = trim($first_name . ' ' . $last_name);

        return $name ? $name : $user->display_name;
    }

    private function format_trip_notice_message($trip_id) {
        return sprintf(
            __('%1$s pickup for %2$s at %3$s. Destination: %4$s.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
            get_the_title($trip_id),
            $this->get_school_label((int) get_post_meta($trip_id, '_terricel_trip_school_id', true)),
            $this->format_trip_pickup($trip_id),
            get_post_meta($trip_id, '_terricel_trip_location_name', true)
        );
    }

    private function format_trip_unassignment_notice_message($trip_id) {
        return sprintf(
            __('You have been removed from %1$s pickup for %2$s at %3$s. Destination: %4$s.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
            get_the_title($trip_id),
            $this->get_school_label((int) get_post_meta($trip_id, '_terricel_trip_school_id', true)),
            $this->format_trip_pickup($trip_id),
            get_post_meta($trip_id, '_terricel_trip_location_name', true)
        );
    }

    private function get_trip_maps_url($trip_id) {
        $address = trim((string) get_post_meta($trip_id, '_terricel_trip_destination_address', true));
        if (!$address) {
            return '';
        }

        return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($address);
    }

    private function get_driver_dashboard_url() {
        return admin_url('admin.php?page=terricel-my-dashboard#terricel-driver-assignments');
    }

    private function get_school_origin_address($school_id) {
        $parts = array(
            get_post_meta($school_id, '_terricel_school_address_1', true),
            get_post_meta($school_id, '_terricel_school_address_2', true),
            get_post_meta($school_id, '_terricel_school_city', true),
            get_post_meta($school_id, '_terricel_school_state', true),
            get_post_meta($school_id, '_terricel_school_zip', true),
        );

        return trim(implode(' ', array_filter(array_map('trim', $parts))));
    }

    private function get_google_distance_estimate($school_id, $destination) {
        $api_key = get_option(Terricel_Transit_Trips_Plugin::OPTION_GOOGLE_API_KEY, '');
        $origin = $this->get_school_origin_address($school_id);
        if (!$api_key || !$origin || !$destination) {
            return array('miles' => 0, 'minutes' => 0, 'message' => __('Add a Google API key, school origin address, and destination before estimating.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN));
        }

        $url = add_query_arg(array('key' => $api_key), 'https://routes.googleapis.com/directions/v2:computeRoutes');
        $response = wp_remote_post(
            $url,
            array(
                'timeout' => 8,
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'X-Goog-FieldMask' => 'routes.distanceMeters,routes.duration,routes.staticDuration,routes.description,routes.routeLabels',
                ),
                'body' => wp_json_encode(
                    array(
                        'origin' => array(
                            'address' => $origin,
                        ),
                        'destination' => array(
                            'address' => $destination,
                        ),
                        'travelMode' => 'DRIVE',
                        'units' => 'IMPERIAL',
                        'computeAlternativeRoutes' => true,
                        'routingPreference' => 'TRAFFIC_AWARE_OPTIMAL',
                        'departureTime' => gmdate('Y-m-d\TH:i:s\Z', time() + (5 * MINUTE_IN_SECONDS)),
                        'trafficModel' => 'OPTIMISTIC',
                    )
                ),
            )
        );
        if (is_wp_error($response)) {
            return array('miles' => 0, 'minutes' => 0, 'message' => $response->get_error_message());
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if ($response_code < 200 || $response_code > 299) {
            $message = $body['error']['message'] ?? __('Google Routes API rejected the estimate request.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN);

            return array('miles' => 0, 'minutes' => 0, 'message' => $message);
        }

        $routes = $body['routes'] ?? array();
        $route = $this->get_fastest_google_route($routes);
        if (empty($route['distanceMeters']) || (empty($route['staticDuration']) && empty($route['duration']))) {
            return array('miles' => 0, 'minutes' => 0, 'message' => __('Google did not return a drivable route for this school and destination.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN));
        }

        $buffer = absint(get_option(Terricel_Transit_Trips_Plugin::OPTION_TRAVEL_BUFFER_PERCENT, 10));
        $one_way_meters = (float) $route['distanceMeters'];
        $one_way_seconds = $this->get_google_route_duration_seconds($route);

        $buffered_minutes = (int) ceil(($one_way_seconds / 60) * (1 + ($buffer / 100)));

        return array(
            'miles'           => (int) ceil(($one_way_meters / 1609.344) * 2),
            'minutes'         => $this->round_up_to_interval($buffered_minutes, 10),
            'origin'          => $origin,
            'destination'     => $destination,
            'maps_url'        => 'https://www.google.com/maps/dir/?api=1&origin=' . rawurlencode($origin) . '&destination=' . rawurlencode($destination) . '&travelmode=driving',
            'distance_meters' => $one_way_meters,
            'duration_seconds' => $one_way_seconds,
            'route_options'   => Terricel_Transit_Trips_Plugin::google_maps_diagnostics_enabled() ? $this->get_google_route_options($routes, $route, $buffer) : array(),
        );
    }

    private function get_google_route_options($routes, $selected_route, $buffer) {
        if (!is_array($routes) || empty($routes)) {
            return array();
        }

        $selected_duration = $this->get_google_route_duration_seconds($selected_route);
        $selected_distance = (int) ($selected_route['distanceMeters'] ?? 0);
        $options = array();

        foreach ($routes as $index => $route) {
            if (empty($route['distanceMeters']) || (empty($route['staticDuration']) && empty($route['duration']))) {
                continue;
            }

            $duration = $this->get_google_route_duration_seconds($route);
            $distance = (int) $route['distanceMeters'];

            $options[] = array(
                'index'              => $index + 1,
                'description'        => $route['description'] ?? '',
                'labels'             => $route['routeLabels'] ?? array(),
                'one_way_miles'      => round($distance / 1609.344, 1),
                'round_trip_miles'   => (int) ceil(($distance / 1609.344) * 2),
                'minutes'            => (int) ceil($duration / 60),
                'buffered_minutes'   => (int) ceil(($duration / 60) * (1 + ($buffer / 100))),
                'selected'           => $duration === $selected_duration && $distance === $selected_distance,
            );
        }

        return $options;
    }

    private function round_up_to_interval($value, $interval) {
        $value = absint($value);
        $interval = max(1, absint($interval));

        if ($value < 1) {
            return 0;
        }

        return (int) (ceil($value / $interval) * $interval);
    }

    private function get_fastest_google_route($routes) {
        if (!is_array($routes) || empty($routes)) {
            return array();
        }

        usort(
            $routes,
            function ($a, $b) {
                $a_duration = $this->get_google_route_duration_seconds($a);
                $b_duration = $this->get_google_route_duration_seconds($b);

                return $a_duration <=> $b_duration;
            }
        );

        return $routes[0];
    }

    private function get_google_route_duration_seconds($route) {
        $duration = $route['duration'] ?? $route['staticDuration'] ?? '';
        if ('' === $duration) {
            return PHP_FLOAT_MAX;
        }

        return (float) rtrim((string) $duration, 's');
    }

    public function enqueue_admin_assets($hook) {
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->post_type, array(self::TRIP_POST_TYPE, self::GROUP_POST_TYPE), true)) {
            return;
        }

        wp_register_style('terricel-transit-trips-admin', false, array(), TERRICEL_TRANSIT_TRIPS_VERSION);
        wp_enqueue_style('terricel-transit-trips-admin');
        wp_add_inline_style('terricel-transit-trips-admin', '.terricel-trip-grid,.terricel-group-details-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px 16px}.terricel-trip-grid p,.terricel-group-details-grid p{margin-top:0}.terricel-trip-details-layout{display:grid;gap:18px}.terricel-trip-school-row{display:grid;grid-template-columns:minmax(240px,1fr) minmax(260px,1fr) auto;gap:14px;align-items:end;max-width:980px}.terricel-trip-school-field p{margin:0}.terricel-trip-school-field select{min-height:40px}.terricel-trip-group-action .button{min-height:40px;padding:4px 18px}.terricel-trip-schedule-grid{display:grid;grid-template-columns:repeat(4,minmax(160px,1fr));gap:14px;max-width:1060px}.terricel-trip-time-card{border:1px solid #dcdcde;background:#fdfdfd;padding:12px;display:grid;gap:10px}.terricel-trip-time-card strong{font-size:13px}.terricel-trip-time-card label{display:grid;gap:4px;margin:0}.terricel-trip-time-card span{font-size:12px;color:#646970}.terricel-trip-estimate-help-wrap{position:relative;display:inline-flex;vertical-align:text-bottom}.terricel-trip-estimate-help{border:0;background:transparent;color:#2271b1;cursor:pointer;margin:0;padding:0;line-height:1}.terricel-trip-estimate-help .dashicons{font-size:16px;width:16px;height:16px;line-height:16px}.terricel-trip-estimate-help:focus{outline:1px solid #2271b1;outline-offset:1px;border-radius:50%}.terricel-trip-estimate-popover{position:absolute;z-index:1001;left:22px;top:-8px;width:280px;background:#1d2327;color:#fff;border-radius:3px;box-shadow:0 8px 18px rgba(0,0,0,.2);padding:8px 10px;font-size:12px;line-height:1.4}.terricel-trip-assignment-summary div{margin:0 0 3px}.terricel-trip-actuals{background:#f6f7f7;border-left:4px solid #2271b1;margin:4px 0 8px;padding:10px 12px}.terricel-trip-actuals-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px 12px;margin-top:8px}.terricel-trip-actuals-grid label{display:grid;gap:4px;margin:0}.terricel-trip-actuals-grid span{color:#646970;font-size:12px}.terricel-trip-actuals-grid input{width:100%;min-height:34px}.terricel-trip-modified-by{color:#646970}.terricel-trip-time-card input{width:100%;max-width:100%;min-height:36px}.terricel-trip-time-card.terricel-trip-step-locked{opacity:.45;pointer-events:none}.terricel-publish-gate{clear:both;color:#b32d2e;margin:8px 0 0}.terricel-trip-assignments select,.terricel-group-details-grid select,.terricel-group-details-grid input{max-width:100%;width:100%}.terricel-required{color:#b32d2e}.terricel-address-lookup{position:relative}.terricel-address-suggestions{position:absolute;z-index:1000;left:0;right:0;top:100%;background:#fff;border:1px solid #8c8f94;box-shadow:0 8px 16px rgba(0,0,0,.12);max-height:260px;overflow:auto}.terricel-address-suggestion{display:block;width:100%;padding:9px 11px;border:0;border-bottom:1px solid #f0f0f1;background:#fff;text-align:left;cursor:pointer}.terricel-address-suggestion:hover,.terricel-address-suggestion:focus{background:#f6f7f7}.terricel-address-suggestion strong{display:block}.terricel-address-suggestion span{display:block;color:#646970;font-size:12px}.terricel-route-options{margin:8px 0 14px}.terricel-route-options summary{cursor:pointer;color:#2271b1}.terricel-route-summary{background:#f6f7f7;border:1px solid #dcdcde;padding:8px 10px;margin:8px 0}.terricel-route-summary p{margin:0 0 4px}.terricel-route-option{display:grid;grid-template-columns:90px 1fr;gap:8px;border-top:1px solid #dcdcde;padding:8px 0}.terricel-route-option:first-child{border-top:0}.terricel-route-option strong{display:block}.terricel-route-option span{color:#646970}.terricel-route-selected{color:#008a20;font-weight:600}.terricel-trip-conflict-dialog{position:fixed;z-index:100000;inset:0;background:rgba(29,35,39,.45);display:flex;align-items:center;justify-content:center;padding:24px}.terricel-trip-conflict-dialog[hidden]{display:none}.terricel-trip-conflict-card{background:#fff;border:1px solid #c3c4c7;box-shadow:0 16px 38px rgba(0,0,0,.28);max-width:680px;width:100%;padding:20px}.terricel-trip-conflict-card h3{margin-top:0}.terricel-trip-conflict-card ul{list-style:disc;margin-left:22px;max-height:260px;overflow:auto}.terricel-trip-conflict-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:16px}.terricel-trip-conflict-confirm{background:#b32d2e!important;border-color:#8a2424!important;color:#fff!important}.terricel-trip-conflict-confirm.terricel-pulse{animation:terricelTripConflictPulse 1s infinite}.terricel-trip-conflict-confirm.terricel-confirmed{background:#008a20!important;border-color:#007017!important}.terricel-trip-conflict-confirm.terricel-confirmed:before{content:"\\f147";font-family:dashicons;vertical-align:middle;margin-right:4px}@keyframes terricelTripConflictPulse{0%,100%{box-shadow:0 0 0 0 rgba(179,45,46,.55)}50%{box-shadow:0 0 0 8px rgba(179,45,46,0)}}.terricel-group-contact-links{display:flex;gap:8px;flex-wrap:wrap;margin:14px 0 0}.terricel-group-contact-links a{text-decoration:none}.terricel-group-contact-links .dashicons{vertical-align:text-bottom}.terricel-trips-back-link{margin:0 0 12px}.terricel-inline-group-panel{border:1px solid #dcdcde;background:#fff;padding:14px;margin:0;max-width:980px}.terricel-inline-group-panel-header{display:flex;align-items:baseline;gap:10px;margin:0 0 12px}.terricel-inline-group-panel-header span{color:#646970}.terricel-inline-group-actions{margin:14px 0 0;display:flex;gap:8px;align-items:center}.terricel-trip-panel-locked .inside{opacity:.45;pointer-events:none}.terricel-trip-panel-locked:after{content:"Complete the previous trip step to continue.";display:block;margin:0 12px 12px;color:#646970;font-style:italic}@media (max-width:1100px){.terricel-trip-school-row{grid-template-columns:1fr 1fr}.terricel-trip-group-action{grid-column:1 / -1}.terricel-trip-schedule-grid{grid-template-columns:repeat(2,minmax(180px,1fr))}}@media (max-width:782px){.terricel-trip-school-row,.terricel-trip-schedule-grid{grid-template-columns:1fr}.terricel-inline-group-panel-header,.terricel-inline-group-actions,.terricel-trip-conflict-actions{align-items:stretch;flex-direction:column}.terricel-trip-group-action .button{width:100%;text-align:center}.terricel-trip-estimate-popover{left:auto;right:0;top:20px}}');
        wp_add_inline_style('terricel-transit-trips-admin', '.terricel-trip-actuals-grid{display:flex;flex-wrap:wrap;align-items:flex-start;gap:12px 14px}.terricel-trip-actual-group{border:1px solid #dcdcde;background:#fff;margin:0;padding:10px;min-width:170px;max-width:220px;flex:1 1 180px}.terricel-trip-actual-group legend{font-weight:600;color:#1d2327;padding:0 4px}.terricel-trip-actual-group label{display:grid;gap:4px;margin:8px 0 0}.terricel-trip-actual-group input{width:100%;min-height:34px}@media (max-width:782px){.terricel-trip-actual-group{max-width:none;flex-basis:100%}}');
        wp_add_inline_style('terricel-transit-trips-admin', '.terricel-trip-driver-cell{display:grid;grid-template-columns:minmax(220px,1fr) minmax(230px,auto);gap:10px;align-items:center}.terricel-trip-driver-cell select{min-width:0}.terricel-trip-conflict-status{display:inline-flex;align-items:center;gap:6px;min-height:36px;padding:6px 10px;border-left:4px solid #008a20;background:#f0f6ef;color:#005c12;font-weight:600;white-space:nowrap}.terricel-trip-conflict-status:before{content:"\\f147";font-family:dashicons;font-size:18px;line-height:1}.terricel-trip-conflict-status.terricel-status-neutral{border-left-color:#8c8f94;background:#f6f7f7;color:#50575e}.terricel-trip-conflict-status.terricel-status-neutral:before{content:""}.terricel-trip-conflict-driver{margin:0 0 14px}.terricel-trip-conflict-driver strong{display:block;margin:0 0 6px}.terricel-trip-conflict-driver ul{margin-top:0}@media (max-width:1100px){.terricel-trip-driver-cell{grid-template-columns:1fr}.terricel-trip-conflict-status{white-space:normal}}');

        if (self::TRIP_POST_TYPE !== $screen->post_type) {
            return;
        }

        wp_register_script('terricel-transit-trips-admin', false, array(), TERRICEL_TRANSIT_TRIPS_VERSION, true);
        wp_enqueue_script('terricel-transit-trips-admin');
        wp_add_inline_script('terricel-transit-trips-admin', $this->get_trip_admin_script());
    }

    private function get_trip_admin_script() {
        $post_id = absint($_GET['post'] ?? 0);
        $config = array(
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('terricel_trip_group_ajax'),
            'googleDiagnostics' => Terricel_Transit_Trips_Plugin::google_maps_diagnostics_enabled(),
            'confirmedConflictSignature' => $post_id > 0 ? (string) get_post_meta($post_id, '_terricel_trip_confirmed_conflict_signature', true) : '',
            'strings' => array(
                'selectGroup'       => __('Select group', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
                'selectSchoolFirst' => __('Select a school first', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
                'noGroups'          => __('No groups linked to this school yet.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
                'loading'           => __('Loading groups...', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
                'saving'            => __('Saving group...', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
                'saved'             => __('School group added and selected.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
                'selectSchool'      => __('Select a school before adding a group.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
                'enterGroupName'    => __('Enter a group name.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
                'requiredGroup'     => __('Enter the group name and advisor first and last name.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
                'addressLoading'    => __('Searching addresses...', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
                'addressEmpty'      => __('No address suggestions found.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
                'addressMissingKey' => __('Add a Google Maps API key with Places API (New) or Geocoding API enabled to use lookup.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
                'publishBlocked'    => __('Complete the staged trip workflow before publishing. Save Draft is still available.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
                'conflictsBlocked'  => __('Confirm driver route conflicts before publishing or updating. Save Draft is still available.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
                'conflictConfirmed' => __('Route Conflicts Confirmed', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
                'noConflicts'       => __('No Route Conflicts Detected', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
                'vacant'            => __('Vacant', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
                'error'             => __('Unable to complete the request.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
            ),
        );

        $script = <<<'JS'
(function(config){
function ready(callback){if(document.readyState==="loading"){document.addEventListener("DOMContentLoaded",callback);}else{callback();}}
function option(value,label){var item=document.createElement("option");item.value=String(value);item.textContent=label;return item;}
function post(action,data){data.action=action;data.nonce=config.nonce;return fetch(config.ajaxUrl,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/x-www-form-urlencoded; charset=UTF-8"},body:new URLSearchParams(data).toString()}).then(function(response){return response.json();}).then(function(json){if(!json||!json.success){throw new Error(json&&json.data&&json.data.message?json.data.message:config.strings.error);}return json.data;});}
function value(name){var input=document.querySelector("[name='"+name+"']");return input?input.value.trim():"";}
function setInput(name,nextValue,autoFlag){var input=document.querySelector("[name='"+name+"']");if(!input||input.value===nextValue){return;}if(!input.value||input.dataset.terricelDefaulted==="1"||autoFlag==="force"){input.value=nextValue;input.dataset.terricelDefaulted="1";var display=document.querySelector("[data-time-target='"+name+"']");if(display){display.value=formatFriendlyTime(nextValue);display.dataset.terricelDefaulted="1";}}}
function parseFriendlyTime(text){var raw=String(text||"").trim().toLowerCase().replace(/\s+/g,"");if(!raw){return "";}var meridiem="";if(raw.endsWith("am")||raw.endsWith("a")){meridiem="am";raw=raw.replace(/a(?:m)?$/,"");}else if(raw.endsWith("pm")||raw.endsWith("p")){meridiem="pm";raw=raw.replace(/p(?:m)?$/,"");}var hour=0;var minute=0;if(raw.indexOf(":")>-1){var parts=raw.split(":");hour=parseInt(parts[0],10);minute=parseInt(parts[1],10);}else if(/^\d{3,4}$/.test(raw)){hour=parseInt(raw.slice(0,-2),10);minute=parseInt(raw.slice(-2),10);}else if(/^\d{1,2}$/.test(raw)){hour=parseInt(raw,10);minute=0;}else{return "";}if(isNaN(hour)||isNaN(minute)||minute<0||minute>59){return "";}if(meridiem){if(hour<1||hour>12){return "";}if(meridiem==="pm"&&hour<12){hour+=12;}if(meridiem==="am"&&hour===12){hour=0;}}else if(hour<0||hour>23){return "";}return String(hour).padStart(2,"0")+":"+String(minute).padStart(2,"0");}
function formatFriendlyTime(value){var parts=String(value||"").split(":");if(parts.length<2){return "";}var hour=parseInt(parts[0],10);var minute=parseInt(parts[1],10);if(isNaN(hour)||isNaN(minute)){return "";}var suffix=hour>=12?"PM":"AM";var displayHour=hour%12;if(displayHour===0){displayHour=12;}return String(displayHour).padStart(2,"0")+":"+String(minute).padStart(2,"0")+" "+suffix;}
function addMinutes(dateValue,timeValue,minutes){if(!dateValue||!timeValue||!minutes){return null;}var parts=dateValue.split("-");var clock=timeValue.split(":");var date=new Date(Number(parts[0]),Number(parts[1])-1,Number(parts[2]),Number(clock[0]),Number(clock[1]));if(isNaN(date.getTime())){return null;}date.setMinutes(date.getMinutes()+Number(minutes));return {date:date.getFullYear()+"-"+String(date.getMonth()+1).padStart(2,"0")+"-"+String(date.getDate()).padStart(2,"0"),time:String(date.getHours()).padStart(2,"0")+":"+String(date.getMinutes()).padStart(2,"0")};}
ready(function(){
var school=document.getElementById("terricel_trip_school_id");var group=document.getElementById("terricel_trip_group_id");if(!school||!group){return;}
var toggle=document.getElementById("terricel_trip_add_group_toggle");var panel=document.getElementById("terricel_trip_add_group_panel");var save=document.getElementById("terricel_trip_save_group");var cancel=document.getElementById("terricel_trip_cancel_group");var message=document.getElementById("terricel_trip_group_message");
var destinationPanel=document.getElementById("terricel_trip_destination");var schedulePanel=document.getElementById("terricel_trip_schedule");var assignmentsPanel=document.getElementById("terricel_trip_assignments");var publishButton=document.getElementById("publish");var publishBox=document.getElementById("publishing-action");
var locationName=document.getElementById("terricel_trip_location_name");var destination=document.getElementById("terricel_trip_destination_address");var mileage=document.getElementById("terricel_trip_estimated_mileage");var travelMinutes=document.getElementById("terricel_trip_estimated_travel_minutes");var routeOptions=document.getElementById("terricel_trip_route_options");var routeOptionsList=document.getElementById("terricel_trip_route_options_list");
var conflictDialog=document.getElementById("terricel_trip_conflict_dialog");var conflictList=document.getElementById("terricel_trip_conflict_list");var conflictConfirm=document.getElementById("terricel_trip_confirm_conflict_button");var conflictClose=document.getElementById("terricel_trip_close_conflict_dialog");var conflictInput=document.getElementById("terricel_trip_confirm_conflicts_hidden");
var estimateTimer=0;var conflictTimer=0;var busAvailabilityTimer=0;var routeConflicts=[];var routeConflictSignature=config.confirmedConflictSignature||"";var routeConflictsConfirmed=!!routeConflictSignature;var unavailableBusIds=[];
var publishNotice=document.createElement("p");publishNotice.className="description terricel-publish-gate";publishNotice.textContent=config.strings.publishBlocked;if(publishBox){publishBox.insertAdjacentElement("afterend",publishNotice);}
function detailsReady(){return parseInt(school.value,10)>0&&parseInt(group.value,10)>0;}
function destinationReady(){return detailsReady()&&locationName&&locationName.value.trim()&&destination&&destination.value.trim()&&travelMinutes&&parseInt(travelMinutes.value,10)>0;}
function fieldPairReady(key){return value("terricel_trip_"+key+"_date")&&value("terricel_trip_"+key+"_time");}
function allDatesReady(){return ["pickup","arrival","departure","return"].every(fieldPairReady);}
function lockPanel(box,locked){if(!box){return;}box.classList.toggle("terricel-trip-panel-locked",locked);box.setAttribute("aria-disabled",locked?"true":"false");}
function lockCard(key,locked){var card=document.querySelector("[data-trip-time-card='"+key+"']");if(!card){return;}card.classList.toggle("terricel-trip-step-locked",locked);card.setAttribute("aria-disabled",locked?"true":"false");}
function syncWorkflow(){var hasDetails=detailsReady();var hasDestination=destinationReady();var hasPickup=fieldPairReady("pickup");var hasArrival=fieldPairReady("arrival");var hasDeparture=fieldPairReady("departure");var routeConflictsClear=!routeConflicts.length||routeConflictsConfirmed;var canAssign=hasDestination&&allDatesReady();var canPublish=canAssign&&routeConflictsClear;lockPanel(destinationPanel,!hasDetails);lockPanel(schedulePanel,!hasDestination);lockPanel(assignmentsPanel,!canAssign);lockCard("pickup",!hasDestination);lockCard("arrival",!hasDestination||!hasPickup);lockCard("departure",!hasDestination||!hasArrival);lockCard("return",!hasDestination||!hasDeparture);if(toggle){toggle.disabled=parseInt(school.value,10)<1;}if(publishButton){publishButton.disabled=!canPublish;publishButton.classList.toggle("disabled",!canPublish);}publishNotice.textContent=routeConflicts.length&&!routeConflictsConfirmed?config.strings.conflictsBlocked:config.strings.publishBlocked;publishNotice.style.display=canPublish?"none":"block";}
function setMessage(text,isError){if(!message){return;}message.textContent=text||"";message.style.color=isError?"#b32d2e":"";}
function fillGroups(groups,selected){group.innerHTML="";if(parseInt(school.value,10)<1){group.appendChild(option(0,config.strings.selectSchoolFirst));syncWorkflow();return;}group.appendChild(option(0,groups.length?config.strings.selectGroup:config.strings.noGroups));groups.forEach(function(item){var row=option(item.id,item.label);if(String(item.id)===String(selected)){row.selected=true;}group.appendChild(row);});syncWorkflow();}
function loadGroups(){var current=group.value;group.innerHTML="";group.appendChild(option(0,parseInt(school.value,10)>0?config.strings.loading:config.strings.selectSchoolFirst));setMessage("");if(parseInt(school.value,10)<1){fillGroups([],0);return;}post("terricel_trip_groups_for_school",{school_id:school.value}).then(function(data){fillGroups(data.groups||[],current);}).catch(function(error){fillGroups([],0);setMessage(error.message,true);});}
function clearPanel(){if(!panel){return;}panel.querySelectorAll("input").forEach(function(input){input.value="";});}
function recalcArrival(force){var estimate=travelMinutes?parseInt(travelMinutes.value,10):0;var next=addMinutes(value("terricel_trip_pickup_date"),value("terricel_trip_pickup_time"),estimate);if(next){setInput("terricel_trip_arrival_date",next.date,force?"force":undefined);setInput("terricel_trip_arrival_time",next.time,force?"force":undefined);}}
function recalcReturn(force){var estimate=travelMinutes?parseInt(travelMinutes.value,10):0;var next=addMinutes(value("terricel_trip_departure_date"),value("terricel_trip_departure_time"),estimate);if(next){setInput("terricel_trip_return_date",next.date,force?"force":undefined);setInput("terricel_trip_return_time",next.time,force?"force":undefined);}}
function refreshScheduleDependents(changedName,force){if(changedName==="terricel_trip_pickup_date"||changedName==="terricel_trip_pickup_time"){recalcArrival(force);if(changedName==="terricel_trip_pickup_date"){recalcReturn(force);}}else if(changedName==="terricel_trip_departure_date"||changedName==="terricel_trip_departure_time"){recalcReturn(force);}else{recalcArrival();recalcReturn();}}
function syncDates(){var pickupDate=document.querySelector("[name='terricel_trip_pickup_date']");["arrival","departure","return"].forEach(function(key){["date","time"].forEach(function(part){var input=document.querySelector("[name='terricel_trip_"+key+"_"+part+"']");if(input){input.addEventListener("input",function(){input.dataset.terricelDefaulted="0";});}});});document.querySelectorAll("#terricel_trip_schedule input").forEach(function(input){input.addEventListener("change",function(){var changedName=input.name||"";if(pickupDate&&pickupDate.value&&changedName==="terricel_trip_pickup_date"){["arrival","departure","return"].forEach(function(key){setInput("terricel_trip_"+key+"_date",pickupDate.value);});}refreshScheduleDependents(changedName,changedName==="terricel_trip_pickup_date"||changedName==="terricel_trip_pickup_time"||changedName==="terricel_trip_departure_date"||changedName==="terricel_trip_departure_time");refreshBusAvailability();checkDriverConflicts();syncWorkflow();});});}
function syncTimeInputs(){document.querySelectorAll(".terricel-trip-time-input").forEach(function(display){var target=document.querySelector("[name='"+display.dataset.timeTarget+"']");if(!target){return;}function apply(normalize,notify){if(!display.value.trim()){if(target.value){target.value="";if(notify){target.dispatchEvent(new Event("change",{bubbles:true}));}}target.dataset.terricelDefaulted="0";return;}var parsed=parseFriendlyTime(display.value);if(parsed){var changed=target.value!==parsed;target.value=parsed;target.dataset.terricelDefaulted=display.dataset.terricelDefaulted==="1"?"1":"0";if(normalize){display.value=formatFriendlyTime(parsed);}if(changed&&notify){target.dispatchEvent(new Event("change",{bubbles:true}));}}}display.addEventListener("input",function(){display.dataset.terricelDefaulted="0";if(target){target.dataset.terricelDefaulted="0";}apply(false,false);});display.addEventListener("change",function(){apply(true,true);});display.addEventListener("blur",function(){apply(true,true);});});}
function initEstimateHelp(){document.querySelectorAll(".terricel-trip-estimate-help").forEach(function(button){var popover=button.parentElement?button.parentElement.querySelector(".terricel-trip-estimate-popover"):null;if(!popover){return;}function close(){popover.hidden=true;button.setAttribute("aria-expanded","false");}button.addEventListener("click",function(event){event.preventDefault();event.stopPropagation();var isOpen=!popover.hidden;document.querySelectorAll(".terricel-trip-estimate-popover").forEach(function(item){item.hidden=true;});document.querySelectorAll(".terricel-trip-estimate-help").forEach(function(item){item.setAttribute("aria-expanded","false");});popover.hidden=isOpen;button.setAttribute("aria-expanded",isOpen?"false":"true");});document.addEventListener("click",function(event){if(!button.parentElement||!button.parentElement.contains(event.target)){close();}});document.addEventListener("keydown",function(event){if(event.key==="Escape"){close();}});});}
function renderRouteOptions(estimate){if(!config.googleDiagnostics||!routeOptions||!routeOptionsList){return;}routeOptionsList.innerHTML="";var items=estimate&&estimate.route_options?estimate.route_options:[];if(!items.length){routeOptions.hidden=true;return;}var summary=document.createElement("div");summary.className="terricel-route-summary";var from=document.createElement("p");from.textContent="Estimated from: "+(estimate.origin||"");var to=document.createElement("p");to.textContent="Estimated to: "+(estimate.destination||"");summary.appendChild(from);summary.appendChild(to);if(estimate.maps_url){var link=document.createElement("a");link.href=estimate.maps_url;link.target="_blank";link.rel="noopener";link.textContent="Open this same route in Google Maps";summary.appendChild(link);}routeOptionsList.appendChild(summary);items.forEach(function(item){var row=document.createElement("div");row.className="terricel-route-option";var label=document.createElement("strong");label.textContent="Route "+item.index+(item.selected?" - Selected":"");if(item.selected){label.className="terricel-route-selected";}var details=document.createElement("div");var title=document.createElement("strong");title.textContent=item.description||"Google route option";var facts=document.createElement("span");facts.textContent=item.minutes+" min one-way ("+item.buffered_minutes+" min with buffer), "+item.one_way_miles+" mi one-way, "+item.round_trip_miles+" mi round trip";details.appendChild(title);details.appendChild(facts);row.appendChild(label);row.appendChild(details);routeOptionsList.appendChild(row);});routeOptions.hidden=false;}
function requestEstimate(force,forceTripTimes){if(!destination||!destination.value.trim()||parseInt(school.value,10)<1){syncWorkflow();return;}post("terricel_trip_destination_estimate",{school_id:school.value,destination:destination.value}).then(function(data){if(data.estimate){if(mileage&&(force||!mileage.value||mileage.dataset.terricelAuto==="1")){mileage.value=data.estimate.miles?String(Math.ceil(Number(data.estimate.miles))):"";mileage.dataset.terricelAuto="1";}if(travelMinutes&&(force||!travelMinutes.value||travelMinutes.dataset.terricelAuto==="1")){travelMinutes.value=data.estimate.minutes?String(Math.ceil(Number(data.estimate.minutes))):"";travelMinutes.dataset.terricelAuto="1";}renderRouteOptions(data.estimate);recalcArrival(!!forceTripTimes);recalcReturn(!!forceTripTimes);refreshBusAvailability();checkDriverConflicts();syncWorkflow();}}).catch(function(error){renderRouteOptions(null);if(window.console){window.console.warn("Terricel trip estimate failed",error);}syncWorkflow();});}
function scheduleEstimate(force,forceTripTimes){window.clearTimeout(estimateTimer);estimateTimer=window.setTimeout(function(){requestEstimate(force,forceTripTimes);},650);}
function selectedDriverIds(){return Array.prototype.slice.call(document.querySelectorAll("#terricel_trip_assignment_rows select[name*='[driver_id]']")).map(function(select){return parseInt(select.value,10)||0;}).filter(function(id){return id>0;});}
function makeConflictSignature(conflicts){return JSON.stringify((conflicts||[]).map(function(item){return [item.driver_id||0,item.route_id||0,item.date||"",item.run_key||"",item.start_time||"",item.end_time||""].join("|");}).sort());}
function assignmentRows(){return Array.prototype.slice.call(document.querySelectorAll("#terricel_trip_assignment_rows .terricel-trip-assignment-slot-row"));}
function conflictStatusForRow(row){return row?row.querySelector(".terricel-trip-conflict-status"):null;}
function driverSelectForRow(row){return row?row.querySelector("select[name*='[driver_id]']"):null;}
function setRowStatus(status,text,neutral){if(!status){return;}status.textContent=text||"";status.hidden=!text;status.classList.toggle("terricel-status-neutral",!!neutral);}
function driverHasConflict(driverId){return routeConflicts.some(function(conflict){return parseInt(conflict.driver_id,10)===driverId;});}
function setConflictStatus(){assignmentRows().forEach(function(row){var select=driverSelectForRow(row);var status=conflictStatusForRow(row);var driverId=select?parseInt(select.value,10)||0:0;if(driverId<1){setRowStatus(status,config.strings.vacant,true);return;}if(routeConflicts.length&&driverHasConflict(driverId)){setRowStatus(status,routeConflictsConfirmed?config.strings.conflictConfirmed:"",false);return;}setRowStatus(status,config.strings.noConflicts,false);});}
function routeConflictText(conflict){var date=conflict.date||"";var run=conflict.run_name||conflict.run_key||"Run";var route=conflict.route_name||"Route";var time=(conflict.start_time||"")+"-"+(conflict.end_time||"");return route+" - "+date+" "+run+" ("+time+")";}
function groupedConflicts(conflicts){return (conflicts||[]).reduce(function(groups,conflict){var name=conflict.driver_name||"Driver";if(!groups[name]){groups[name]=[];}groups[name].push(conflict);return groups;},{});}
function setConflictConfirmation(confirmed){routeConflictsConfirmed=!!confirmed;if(conflictInput){conflictInput.value=routeConflictsConfirmed?"1":"";}if(conflictConfirm){conflictConfirm.classList.toggle("terricel-confirmed",routeConflictsConfirmed);conflictConfirm.classList.toggle("terricel-pulse",routeConflicts.length&&!routeConflictsConfirmed);conflictConfirm.textContent=routeConflictsConfirmed?config.strings.conflictConfirmed:"Confirm Route Vacancies";}setConflictStatus();syncWorkflow();}
function renderConflictDialog(conflicts){if(!conflictDialog||!conflictList){return;}conflictList.innerHTML="";var groups=groupedConflicts(conflicts);Object.keys(groups).forEach(function(driverName){var driverWrap=document.createElement("li");driverWrap.className="terricel-trip-conflict-driver";var title=document.createElement("strong");title.textContent=driverName;var routes=document.createElement("ul");groups[driverName].forEach(function(conflict){var item=document.createElement("li");item.textContent=routeConflictText(conflict);routes.appendChild(item);});driverWrap.appendChild(title);driverWrap.appendChild(routes);conflictList.appendChild(driverWrap);});conflictDialog.hidden=!conflicts.length;if(conflicts.length&&conflictConfirm){conflictConfirm.focus();}}
function clearRouteConflicts(){routeConflicts=[];routeConflictSignature="";if(conflictDialog){conflictDialog.hidden=true;}setConflictConfirmation(false);}
function checkDriverConflicts(){window.clearTimeout(conflictTimer);conflictTimer=window.setTimeout(function(){var drivers=selectedDriverIds();if(!drivers.length||!allDatesReady()){clearRouteConflicts();return;}post("terricel_trip_driver_conflicts",{post_id:value("post_ID"),drivers:drivers.join(","),pickup_date:value("terricel_trip_pickup_date"),pickup_time:value("terricel_trip_pickup_time"),return_date:value("terricel_trip_return_date"),return_time:value("terricel_trip_return_time")}).then(function(data){var conflicts=data.conflicts||[];var signature=data.signature||makeConflictSignature(conflicts);var previouslyConfirmed=!!signature&&(!!data.confirmed||signature===config.confirmedConflictSignature);if(signature!==routeConflictSignature&&!previouslyConfirmed){routeConflictsConfirmed=false;if(conflictInput){conflictInput.value="";}}routeConflicts=conflicts;routeConflictSignature=signature;if(previouslyConfirmed){routeConflictsConfirmed=true;if(conflictInput){conflictInput.value="1";}}if(conflicts.length){if(!routeConflictsConfirmed){renderConflictDialog(conflicts);}else if(conflictDialog){conflictDialog.hidden=true;}setConflictConfirmation(routeConflictsConfirmed);}else{clearRouteConflicts();}}).catch(function(error){if(window.console){window.console.warn("Terricel trip driver conflict check failed",error);}clearRouteConflicts();});},300);}
function busSelects(){return Array.prototype.slice.call(document.querySelectorAll("#terricel_trip_assignment_rows select[name*='[bus_id]']"));}
function syncBusOptions(){var changed=false;var selectedCounts={};busSelects().forEach(function(select){var value=parseInt(select.value,10)||0;if(value>0){selectedCounts[value]=(selectedCounts[value]||0)+1;}});busSelects().forEach(function(select){var ownValue=parseInt(select.value,10)||0;Array.prototype.slice.call(select.options).forEach(function(opt){var id=parseInt(opt.value,10)||0;if(id<1){opt.disabled=false;return;}var duplicateElsewhere=(selectedCounts[id]||0)>0&&id!==ownValue;var unavailable=unavailableBusIds.indexOf(id)>-1;opt.disabled=duplicateElsewhere||unavailable;});if(ownValue>0&&((selectedCounts[ownValue]||0)>1||unavailableBusIds.indexOf(ownValue)>-1)){select.value="0";changed=true;}});if(changed){syncBusOptions();}}
function refreshBusAvailability(){window.clearTimeout(busAvailabilityTimer);busAvailabilityTimer=window.setTimeout(function(){if(!allDatesReady()){unavailableBusIds=[];syncBusOptions();return;}post("terricel_trip_bus_availability",{post_id:value("post_ID"),pickup_date:value("terricel_trip_pickup_date"),pickup_time:value("terricel_trip_pickup_time"),return_date:value("terricel_trip_return_date"),return_time:value("terricel_trip_return_time")}).then(function(data){unavailableBusIds=(data.unavailable_bus_ids||[]).map(function(id){return parseInt(id,10)||0;}).filter(function(id){return id>0;});syncBusOptions();}).catch(function(error){if(window.console){window.console.warn("Terricel trip bus availability check failed",error);}unavailableBusIds=[];syncBusOptions();});},250);}
function syncBusSlots(){var needed=document.getElementById("terricel_trip_buses_needed");var rows=document.getElementById("terricel_trip_assignment_rows");var template=document.getElementById("terricel_trip_assignment_row_template");if(!needed||!rows||!template){return;}function slotRows(){return Array.prototype.slice.call(rows.querySelectorAll(".terricel-trip-assignment-slot-row"));}function apply(){var count=Math.max(0,Math.min(50,parseInt(needed.value,10)||0));var slots=slotRows();while(slots.length>count){var row=slots.pop();var next=row.nextElementSibling;if(next&&next.classList.contains("terricel-trip-actuals-row")){rows.removeChild(next);}rows.removeChild(row);}slots=slotRows();while(slots.length<count){var index=slots.length;var holder=document.createElement("tbody");holder.innerHTML=template.innerHTML.replace(/__INDEX__/g,String(index)).replace(/__NUMBER__/g,String(index+1));Array.prototype.slice.call(holder.children).forEach(function(child){rows.appendChild(child);});slots=slotRows();}syncBusOptions();setConflictStatus();checkDriverConflicts();}needed.addEventListener("input",apply);needed.addEventListener("change",apply);rows.addEventListener("change",function(event){if(event.target&&event.target.matches("select[name*='[driver_id]']")){setConflictStatus();checkDriverConflicts();}if(event.target&&event.target.matches("select[name*='[bus_id]']")){syncBusOptions();}});apply();}
function initAddressLookup(){function setup(input,menu,mode){if(!input||!menu){return;}var timer=0;function hide(){menu.hidden=true;menu.innerHTML="";}function showMessage(text){menu.innerHTML="<div class=\"terricel-address-suggestion\"><span></span></div>";menu.querySelector("span").textContent=text;menu.hidden=false;}function render(items){menu.innerHTML="";if(!items.length){showMessage(config.strings.addressEmpty);return;}items.forEach(function(item){var button=document.createElement("button");button.type="button";button.className="terricel-address-suggestion";button.dataset.placeId=item.placeId||"";button.dataset.address=item.address||"";button.dataset.name=item.name||"";var main=document.createElement("strong");main.textContent=item.mainText||item.text;var secondary=document.createElement("span");secondary.textContent=item.secondaryText||"";button.appendChild(main);button.appendChild(secondary);menu.appendChild(button);});menu.hidden=false;}input.addEventListener("input",function(){input.dataset.terricelManual="1";window.clearTimeout(timer);var text=input.value.trim();if(text.length<3){hide();syncWorkflow();return;}if(mode==="address"||destination&&destination.value.trim()){scheduleEstimate(true,true);}timer=window.setTimeout(function(){showMessage(config.strings.addressLoading);post("terricel_trip_address_suggestions",{input:text,school_id:school.value,mode:mode}).then(function(data){render(data.suggestions||[]);}).catch(function(error){showMessage(error.message||config.strings.addressMissingKey);});},350);syncWorkflow();});menu.addEventListener("click",function(event){var button=event.target.closest(".terricel-address-suggestion");if(!button){return;}if(button.dataset.name&&locationName){locationName.value=button.dataset.name;}if(button.dataset.address&&destination){destination.value=button.dataset.address;}hide();requestEstimate(true,true);syncWorkflow();});document.addEventListener("click",function(event){if(!menu.contains(event.target)&&event.target!==input){hide();}});}setup(locationName,document.getElementById("terricel_trip_location_suggestions"),"location");setup(destination,document.getElementById("terricel_trip_address_suggestions"),"address");if(destination){destination.addEventListener("change",function(){requestEstimate(true,true);});destination.addEventListener("blur",function(){requestEstimate(true,true);});}if(locationName){locationName.addEventListener("change",function(){scheduleEstimate(true,true);});locationName.addEventListener("blur",function(){scheduleEstimate(true,true);});}}
school.addEventListener("change",function(){if(panel){panel.hidden=true;}loadGroups();requestEstimate(true,true);});group.addEventListener("change",syncWorkflow);if(locationName){locationName.addEventListener("input",syncWorkflow);}if(mileage){mileage.addEventListener("input",function(){mileage.dataset.terricelAuto="0";});}if(travelMinutes){travelMinutes.addEventListener("input",function(){travelMinutes.dataset.terricelAuto="0";recalcArrival(true);recalcReturn(true);refreshBusAvailability();checkDriverConflicts();syncWorkflow();});travelMinutes.addEventListener("change",function(){travelMinutes.dataset.terricelAuto="0";recalcArrival(true);recalcReturn(true);refreshBusAvailability();checkDriverConflicts();syncWorkflow();});}
if(toggle&&panel){toggle.addEventListener("click",function(){if(parseInt(school.value,10)<1){setMessage(config.strings.selectSchool,true);return;}panel.hidden=!panel.hidden;if(!panel.hidden){var name=document.getElementById("terricel_trip_new_group_name");if(name){name.focus();}}});}
if(cancel&&panel){cancel.addEventListener("click",function(){panel.hidden=true;setMessage("");});}
if(conflictConfirm){conflictConfirm.addEventListener("click",function(){setConflictConfirmation(true);if(conflictDialog){conflictDialog.hidden=true;}});}
if(conflictClose){conflictClose.addEventListener("click",function(){if(conflictDialog){conflictDialog.hidden=true;}});}
if(conflictDialog){conflictDialog.addEventListener("click",function(event){if(event.target===conflictDialog){conflictDialog.hidden=true;}});}
if(save){save.addEventListener("click",function(){var name=document.getElementById("terricel_trip_new_group_name");var first=document.getElementById("terricel_trip_new_group_advisor_first_name");var last=document.getElementById("terricel_trip_new_group_advisor_last_name");var required=[name,first,last];var missing=required.find(function(input){return !input||!input.value.trim();});if(missing){setMessage(config.strings.requiredGroup,true);missing.focus();return;}if(parseInt(school.value,10)<1){setMessage(config.strings.selectSchool,true);return;}save.disabled=true;setMessage(config.strings.saving,false);post("terricel_create_trip_group",{school_id:school.value,group_name:name.value,advisor_first_name:first.value,advisor_last_name:last.value,advisor_main_phone:(document.getElementById("terricel_trip_new_group_advisor_main_phone")||{}).value||"",advisor_main_phone_extension:(document.getElementById("terricel_trip_new_group_advisor_main_phone_extension")||{}).value||"",advisor_emergency_phone:(document.getElementById("terricel_trip_new_group_advisor_emergency_phone")||{}).value||"",advisor_email:(document.getElementById("terricel_trip_new_group_advisor_email")||{}).value||""}).then(function(data){if(data.group){var row=option(data.group.id,data.group.label);row.selected=true;group.appendChild(row);group.value=String(data.group.id);}clearPanel();if(panel){panel.hidden=true;}setMessage(config.strings.saved,false);syncWorkflow();}).catch(function(error){setMessage(error.message,true);}).finally(function(){save.disabled=false;});});}
syncTimeInputs();syncDates();syncBusSlots();initEstimateHelp();initAddressLookup();if(destination&&destination.value.trim()&&parseInt(school.value,10)>0){requestEstimate(true,false);}refreshBusAvailability();checkDriverConflicts();syncWorkflow();
});
}(__CONFIG__));
JS;

        return str_replace('__CONFIG__', wp_json_encode($config), $script);
    }

    public function trip_columns($columns) {
        unset($columns['date']);

        return $this->insert_columns($columns, array(
            'terricel_school' => __('School', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
            'terricel_advisor' => __('Advisor', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
            'terricel_pickup' => __('Pickup', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
            'terricel_return' => __('Return', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
            'terricel_destination' => __('Destination', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
            'terricel_assignments' => __('Assignments', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
            'terricel_last_modified' => __('Last Modified', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
        ));
    }

    public function render_trip_column($column, $post_id) {
        if ('terricel_school' === $column) {
            echo esc_html($this->get_school_label((int) get_post_meta($post_id, '_terricel_trip_school_id', true)));
        } elseif ('terricel_advisor' === $column) {
            echo wp_kses_post($this->get_trip_group_advisor_link($post_id));
        } elseif ('terricel_pickup' === $column) {
            echo esc_html($this->format_trip_pickup($post_id));
        } elseif ('terricel_return' === $column) {
            echo esc_html($this->format_trip_return($post_id));
        } elseif ('terricel_destination' === $column) {
            echo esc_html($this->get_trip_destination_label($post_id));
        } elseif ('terricel_assignments' === $column) {
            echo wp_kses_post($this->format_trip_assignment_summary($post_id));
        } elseif ('terricel_last_modified' === $column) {
            echo wp_kses_post($this->format_trip_last_modified($post_id));
        }
    }

    public function trip_list_views($views) {
        $current_view = isset($_GET['terricel_trip_view']) ? sanitize_key(wp_unslash($_GET['terricel_trip_view'])) : 'today_plus';
        $base_url = admin_url('edit.php?post_type=' . self::TRIP_POST_TYPE);
        $custom_views = array(
            'today_plus' => sprintf(
                '<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
                esc_url($base_url),
                'today_plus' === $current_view ? ' class="current" aria-current="page"' : '',
                esc_html__('Today+', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
                esc_html(number_format_i18n($this->get_trip_list_count('today_plus')))
            ),
            'all' => sprintf(
                '<a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a>',
                esc_url(add_query_arg('terricel_trip_view', 'all', $base_url)),
                'all' === $current_view ? ' class="current" aria-current="page"' : '',
                esc_html__('All', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
                esc_html(number_format_i18n($this->get_trip_list_count('all')))
            ),
        );

        unset($views['all']);

        return array_merge($custom_views, $views);
    }

    public function filter_trip_admin_list($query) {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }

        $post_type = $query->get('post_type');
        if (self::TRIP_POST_TYPE !== $post_type) {
            return;
        }

        $current_view = isset($_GET['terricel_trip_view']) ? sanitize_key(wp_unslash($_GET['terricel_trip_view'])) : 'today_plus';
        if ('all' !== $current_view) {
            $meta_query = (array) $query->get('meta_query');
            $meta_query[] = array(
                'relation' => 'OR',
                array(
                    'key'     => '_terricel_trip_pickup_date',
                    'value'   => current_time('Y-m-d'),
                    'compare' => '>=',
                    'type'    => 'DATE',
                ),
                array(
                    'key'     => '_terricel_trip_pickup_date',
                    'compare' => 'NOT EXISTS',
                ),
                array(
                    'key'     => '_terricel_trip_pickup_date',
                    'value'   => '',
                    'compare' => '=',
                ),
            );
            $query->set('meta_query', $meta_query);
        }

        if (!$query->get('orderby')) {
            $query->set('meta_key', '_terricel_trip_pickup_date');
            $query->set('orderby', 'meta_value');
            $query->set('order', 'ASC');
        }
    }

    public function group_columns($columns) {
        return $this->insert_columns($columns, array(
            'terricel_school' => __('School', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
            'terricel_advisor' => __('Advisor', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
            'terricel_contact' => __('Contact', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
        ));
    }

    public function render_group_column($column, $post_id) {
        if ('terricel_school' === $column) {
            echo esc_html($this->get_school_label((int) get_post_meta($post_id, '_terricel_trip_group_school_id', true)));
        } elseif ('terricel_advisor' === $column) {
            echo esc_html($this->get_group_advisor_name($post_id));
        } elseif ('terricel_contact' === $column) {
            echo wp_kses_post($this->get_group_contact_links($post_id, false));
        }
    }

    public function filter_title_placeholder($placeholder, $post) {
        if ($post && self::GROUP_POST_TYPE === $post->post_type) {
            return __('Group name, class, grade, teacher, sport, or activity', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN);
        }

        return $placeholder;
    }

    public function render_back_to_trips_button($post) {
        if (!$post || !in_array($post->post_type, array(self::TRIP_POST_TYPE, self::GROUP_POST_TYPE), true)) {
            return;
        }

        $url = admin_url('edit.php?post_type=' . self::TRIP_POST_TYPE);
        $label = __('< Back to Trips', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN);
        if (self::GROUP_POST_TYPE === $post->post_type) {
            $url = admin_url('edit.php?post_type=' . self::GROUP_POST_TYPE);
            $label = __('< Back to School Trip Groups', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN);
        }

        echo '<p class="terricel-trips-back-link"><a class="button" href="' . esc_url($url) . '">' . esc_html($label) . '</a></p>';
    }

    public function render_trip_list_header_actions_script() {
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->post_type, array(self::TRIP_POST_TYPE, self::GROUP_POST_TYPE), true)) {
            return;
        }

        $trips_url = admin_url('edit.php?post_type=' . self::TRIP_POST_TYPE);
        $groups_url = admin_url('edit.php?post_type=' . self::GROUP_POST_TYPE);
        echo '<script>';
        echo '(function(){document.addEventListener("DOMContentLoaded",function(){var addButton=document.querySelector(".wrap .page-title-action");if(!addButton){return;}';
        if (self::GROUP_POST_TYPE === $screen->post_type) {
            echo 'var row=document.createElement("p");row.className="terricel-trips-back-link";var action=document.createElement("a");action.className="button";action.href=' . wp_json_encode($trips_url) . ';action.textContent=' . wp_json_encode(__('< Back to Trips', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN)) . ';row.appendChild(action);addButton.insertAdjacentElement("afterend",row);';
        } else {
            echo 'var action=document.createElement("a");action.className="page-title-action";action.href=' . wp_json_encode($groups_url) . ';action.textContent=' . wp_json_encode(__('Manage School Groups', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN)) . ';addButton.insertAdjacentElement("afterend",action);';
        }
        echo '});})();';
        echo '</script>';
    }

    private function render_post_select_field($name, $label, $post_type, $selected, $empty_label) {
        echo '<p>';
        if ($label) {
            echo '<label for="' . esc_attr($name) . '"><strong>' . esc_html($label) . '</strong></label><br>';
        }
        echo '<select class="widefat" id="' . esc_attr($name) . '" name="' . esc_attr($name) . '">';
        echo '<option value="0">' . esc_html($empty_label) . '</option>';
        foreach ($this->get_posts_for_select($post_type) as $post) {
            echo '<option value="' . esc_attr($post->ID) . '"' . selected($selected, $post->ID, false) . '>' . esc_html(get_the_title($post)) . '</option>';
        }
        echo '</select></p>';
    }

    private function render_text_field($name, $label, $value, $type = 'text', $inputmode = '', $required = false) {
        $is_phone_field = 'tel' === $type;
        $class = $is_phone_field ? 'terricel-phone-field' : '';
        $extra = $is_phone_field ? ' inputmode="numeric" autocomplete="tel" maxlength="14"' : '';
        if (!$is_phone_field && $inputmode) {
            $extra .= ' inputmode="' . esc_attr($inputmode) . '"';
        }
        if ($required) {
            $extra .= ' aria-required="true" data-terricel-required="1"';
        }

        echo '<p>';
        echo '<label for="' . esc_attr($name) . '"><strong>' . esc_html($label) . ($required ? ' <span class="terricel-required">*</span>' : '') . '</strong></label><br>';
        echo '<input class="' . esc_attr($class) . '" type="' . esc_attr($type) . '" id="' . esc_attr($name) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '"' . $extra . '>';
        echo '</p>';
    }

    private function render_group_select_field($school_id, $selected) {
        echo '<p><label for="terricel_trip_group_id"><strong>' . esc_html__('School Group', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</strong></label><br>';
        echo '<select class="widefat" id="terricel_trip_group_id" name="terricel_trip_group_id">';
        echo '<option value="0">' . esc_html($school_id > 0 ? __('Select group', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) : __('Select a school first', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN)) . '</option>';
        foreach ($this->get_groups_for_school($school_id) as $group) {
            echo '<option value="' . esc_attr($group->ID) . '"' . selected($selected, $group->ID, false) . '>' . esc_html($this->get_group_select_label($group->ID)) . '</option>';
        }
        echo '</select></p>';
    }

    private function render_inline_group_create_panel() {
        echo '<div class="terricel-inline-group-create">';
        echo '<div id="terricel_trip_add_group_panel" class="terricel-inline-group-panel" hidden>';
        echo '<div class="terricel-inline-group-panel-header">';
        echo '<strong>' . esc_html__('Add School Group', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</strong>';
        echo '<span>' . esc_html__('Create it here and keep building this trip.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</span>';
        echo '</div>';
        echo '<div class="terricel-group-details-grid">';
        $this->render_text_field('terricel_trip_new_group_name', __('Group Name', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), '', 'text', '', true);
        $this->render_text_field('terricel_trip_new_group_advisor_first_name', __('Advisor First Name', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), '', 'text', '', true);
        $this->render_text_field('terricel_trip_new_group_advisor_last_name', __('Advisor Last Name', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), '', 'text', '', true);
        $this->render_text_field('terricel_trip_new_group_advisor_main_phone', __('Main Phone', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), '', 'tel');
        $this->render_text_field('terricel_trip_new_group_advisor_main_phone_extension', __('Extension', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), '', 'text', 'numeric');
        $this->render_text_field('terricel_trip_new_group_advisor_emergency_phone', __('Emergency Phone', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), '', 'tel');
        $this->render_text_field('terricel_trip_new_group_advisor_email', __('Email', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), '', 'email');
        echo '</div>';
        echo '<p class="terricel-inline-group-actions"><button type="button" class="button button-primary" id="terricel_trip_save_group">' . esc_html__('Save School Group', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</button> <button type="button" class="button" id="terricel_trip_cancel_group">' . esc_html__('Cancel', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</button> <span id="terricel_trip_group_message" class="description"></span></p>';
        echo '</div></div>';
    }

    private function render_date_time_field($key, $label, $date, $time) {
        $name = 'terricel_trip_' . $key . '_time';
        $time_label = in_array($key, array('arrival', 'return'), true) ? __('Estimated Time', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) : __('Time', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN);
        echo '<div class="terricel-trip-time-card" data-trip-time-card="' . esc_attr($key) . '">';
        echo '<strong>' . esc_html($label) . '</strong>';
        echo '<label><span>' . esc_html__('Date', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</span><input type="date" name="terricel_trip_' . esc_attr($key) . '_date" value="' . esc_attr($date) . '"></label>';
        echo '<label><span>' . esc_html($time_label) . '</span><input type="text" class="terricel-trip-time-input" data-time-target="' . esc_attr($name) . '" value="' . esc_attr($this->format_time_display($time)) . '" placeholder="' . esc_attr__('8:00 AM', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '" list="terricel_trip_time_suggestions" inputmode="numeric" autocomplete="off"><input type="hidden" name="' . esc_attr($name) . '" value="' . esc_attr($time) . '"></label>';
        echo '</div>';
    }

    private function render_time_suggestions() {
        echo '<datalist id="terricel_trip_time_suggestions">';
        for ($hour = 5; $hour <= 22; $hour++) {
            foreach (array(0, 15, 30, 45) as $minute) {
                $value = sprintf('%02d:%02d', $hour, $minute);
                echo '<option value="' . esc_attr($this->format_time_display($value)) . '">';
            }
        }
        echo '</datalist>';
    }

    private function format_time_display($time) {
        if (!$this->sanitize_time($time)) {
            return '';
        }

        return date('h:i A', strtotime('1970-01-01 ' . $time));
    }

    private function get_bus_select($name, $selected, $trip_id) {
        $html = '<select name="' . esc_attr($name) . '"><option value="0">' . esc_html__('No bus', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</option>';
        foreach ($this->get_trip_eligible_buses($trip_id, $selected) as $bus) {
            $html .= '<option value="' . esc_attr($bus->ID) . '"' . selected($selected, $bus->ID, false) . '>' . esc_html(get_the_title($bus)) . '</option>';
        }
        $html .= '</select>';
        return $html;
    }

    private function get_driver_select($name, $selected) {
        $html = '<select name="' . esc_attr($name) . '"><option value="0">' . esc_html__('No driver', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</option>';
        foreach ($this->get_posts_for_select(Terricel_Logistics_Shared_Data::DRIVER_POST_TYPE) as $driver) {
            $html .= '<option value="' . esc_attr($driver->ID) . '"' . selected($selected, $driver->ID, false) . '>' . esc_html(get_the_title($driver)) . '</option>';
        }
        $html .= '</select>';
        return $html;
    }

    private function get_trip_eligible_buses($trip_id, $selected = 0) {
        return array_values(array_filter($this->get_trip_bus_pool(), function ($bus) use ($trip_id, $selected) {
            return absint($bus->ID) === absint($selected) || !$this->bus_has_trip_conflict($bus->ID, $trip_id);
        }));
    }

    private function get_trip_bus_pool() {
        return get_posts(
            array(
                'post_type'      => Terricel_Logistics_Shared_Data::BUS_POST_TYPE,
                'post_status'    => 'publish',
                'posts_per_page' => 300,
                'orderby'        => 'title',
                'order'          => 'ASC',
                'meta_key'       => '_terricel_bus_used_for_trips',
                'meta_value'     => 1,
            )
        );
    }

    private function bus_has_trip_conflict($bus_id, $trip_id) {
        $start_date = get_post_meta($trip_id, '_terricel_trip_pickup_date', true);
        $start_time = get_post_meta($trip_id, '_terricel_trip_pickup_time', true);
        $end_date = get_post_meta($trip_id, '_terricel_trip_return_date', true) ?: $start_date;
        $end_time = get_post_meta($trip_id, '_terricel_trip_return_time', true) ?: '23:59';

        return $this->bus_has_trip_conflict_for_window($bus_id, $trip_id, $start_date, $start_time, $end_date, $end_time);
    }

    private function bus_has_trip_conflict_for_window($bus_id, $trip_id, $start_date, $start_time, $end_date, $end_time) {
        $bus_id = absint($bus_id);
        $trip_id = absint($trip_id);
        $start_date = $this->sanitize_date($start_date);
        $start_time = $this->sanitize_time($start_time);
        $end_date = $this->sanitize_date($end_date) ?: $start_date;
        $end_time = $this->sanitize_time($end_time) ?: '23:59';

        if (!$start_date || !$start_time) {
            return false;
        }

        foreach ($this->get_all_trip_posts() as $trip) {
            if (absint($trip->ID) === $trip_id) {
                continue;
            }
            foreach ($this->get_trip_assignments($trip->ID) as $assignment) {
                if (absint($assignment['bus_id']) !== $bus_id) {
                    continue;
                }
                $other_start_date = get_post_meta($trip->ID, '_terricel_trip_pickup_date', true);
                $other_start_time = get_post_meta($trip->ID, '_terricel_trip_pickup_time', true);
                $other_end_date = get_post_meta($trip->ID, '_terricel_trip_return_date', true) ?: $other_start_date;
                $other_end_time = get_post_meta($trip->ID, '_terricel_trip_return_time', true) ?: '23:59';
                if ($this->windows_overlap($start_date, $start_time, $end_date, $end_time, $other_start_date, $other_start_time, $other_end_date, $other_end_time)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function get_unavailable_bus_ids_for_window($trip_id, $start_date, $start_time, $end_date, $end_time) {
        $unavailable = array();
        foreach ($this->get_trip_bus_pool() as $bus) {
            if ($this->bus_has_trip_conflict_for_window($bus->ID, $trip_id, $start_date, $start_time, $end_date, $end_time)) {
                $unavailable[] = absint($bus->ID);
            }
        }

        return array_values(array_unique($unavailable));
    }

    private function get_all_trip_posts() {
        return get_posts(
            array(
                'post_type'      => self::TRIP_POST_TYPE,
                'post_status'    => array('publish', 'draft', 'pending', 'future', 'private'),
                'posts_per_page' => 500,
                'orderby'        => 'date',
                'order'          => 'DESC',
            )
        );
    }

    private function get_posts_for_select($post_type) {
        return get_posts(array('post_type' => $post_type, 'post_status' => 'publish', 'posts_per_page' => 500, 'orderby' => 'title', 'order' => 'ASC'));
    }

    private function get_trip_list_count($view) {
        $args = array(
            'post_type'      => self::TRIP_POST_TYPE,
            'post_status'    => array('publish', 'draft', 'pending', 'future', 'private'),
            'posts_per_page' => 1,
            'fields'         => 'ids',
        );

        if ('today_plus' === $view) {
            $args['meta_query'] = array(
                array(
                    'relation' => 'OR',
                    array(
                        'key'     => '_terricel_trip_pickup_date',
                        'value'   => current_time('Y-m-d'),
                        'compare' => '>=',
                        'type'    => 'DATE',
                    ),
                    array(
                        'key'     => '_terricel_trip_pickup_date',
                        'compare' => 'NOT EXISTS',
                    ),
                    array(
                        'key'     => '_terricel_trip_pickup_date',
                        'value'   => '',
                        'compare' => '=',
                    ),
                ),
            );
        }

        $query = new WP_Query($args);

        return (int) $query->found_posts;
    }

    private function get_groups_for_school($school_id) {
        if ($school_id < 1) {
            return array();
        }

        $args = array('post_type' => self::GROUP_POST_TYPE, 'post_status' => 'publish', 'posts_per_page' => 500, 'orderby' => 'title', 'order' => 'ASC');
        $args['meta_key'] = '_terricel_trip_group_school_id';
        $args['meta_value'] = absint($school_id);
        return get_posts($args);
    }

    private function group_belongs_to_school($group_id, $school_id) {
        if ($group_id < 1 || $school_id < 1 || self::GROUP_POST_TYPE !== get_post_type($group_id)) {
            return false;
        }

        return absint(get_post_meta($group_id, '_terricel_trip_group_school_id', true)) === absint($school_id);
    }

    private function get_group_select_label($group_id) {
        $title = get_the_title($group_id);
        $advisor = $this->get_group_advisor_name($group_id);

        return sprintf(__('%1$s [%2$s]', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $title, $advisor);
    }

    private function get_group_select_options($school_id) {
        $options = array();
        foreach ($this->get_groups_for_school($school_id) as $group) {
            $options[] = array(
                'id'    => (int) $group->ID,
                'label' => $this->get_group_select_label($group->ID),
            );
        }

        return $options;
    }

    public function ajax_trip_groups_for_school() {
        if (!current_user_can(Terricel_Transit_Trips_Plugin::CAP_MANAGE_TRIPS)) {
            wp_send_json_error(array('message' => __('You do not have permission to manage trips.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN)), 403);
        }

        check_ajax_referer('terricel_trip_group_ajax', 'nonce');
        $school_id = absint($_POST['school_id'] ?? 0);

        wp_send_json_success(array('groups' => $this->get_group_select_options($school_id)));
    }

    public function ajax_create_trip_group() {
        if (!current_user_can(Terricel_Transit_Trips_Plugin::CAP_MANAGE_TRIPS)) {
            wp_send_json_error(array('message' => __('You do not have permission to manage trips.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN)), 403);
        }

        check_ajax_referer('terricel_trip_group_ajax', 'nonce');
        $school_id = absint($_POST['school_id'] ?? 0);
        $group_name = sanitize_text_field(wp_unslash($_POST['group_name'] ?? ''));
        $advisor_first_name = $this->sanitize_person_name($_POST['advisor_first_name'] ?? '');
        $advisor_last_name = $this->sanitize_person_name($_POST['advisor_last_name'] ?? '');
        if ($school_id < 1 || 'publish' !== get_post_status($school_id)) {
            wp_send_json_error(array('message' => __('Select a school before adding a group.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN)), 400);
        }
        if ('' === $group_name || '' === $advisor_first_name || '' === $advisor_last_name) {
            wp_send_json_error(array('message' => __('Enter the group name and advisor first and last name.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN)), 400);
        }

        $group_id = wp_insert_post(
            array(
                'post_type'   => self::GROUP_POST_TYPE,
                'post_status' => 'publish',
                'post_title'  => $group_name,
            ),
            true
        );
        if (is_wp_error($group_id)) {
            wp_send_json_error(array('message' => $group_id->get_error_message()), 500);
        }

        update_post_meta($group_id, '_terricel_trip_group_school_id', $school_id);
        update_post_meta($group_id, '_terricel_trip_group_advisor_first_name', $advisor_first_name);
        update_post_meta($group_id, '_terricel_trip_group_advisor_last_name', $advisor_last_name);
        update_post_meta($group_id, '_terricel_trip_group_advisor_main_phone', $this->sanitize_phone($_POST['advisor_main_phone'] ?? ''));
        update_post_meta($group_id, '_terricel_trip_group_advisor_main_phone_extension', $this->sanitize_extension($_POST['advisor_main_phone_extension'] ?? ''));
        update_post_meta($group_id, '_terricel_trip_group_advisor_emergency_phone', $this->sanitize_phone($_POST['advisor_emergency_phone'] ?? ''));
        update_post_meta($group_id, '_terricel_trip_group_advisor_email', sanitize_email(wp_unslash($_POST['advisor_email'] ?? '')));

        wp_send_json_success(
            array(
                'group' => array(
                    'id'    => (int) $group_id,
                    'label' => $this->get_group_select_label($group_id),
                ),
            )
        );
    }

    public function ajax_trip_address_suggestions() {
        if (!current_user_can(Terricel_Transit_Trips_Plugin::CAP_MANAGE_TRIPS)) {
            wp_send_json_error(array('message' => __('You do not have permission to manage trips.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN)), 403);
        }

        check_ajax_referer('terricel_trip_group_ajax', 'nonce');
        $input = sanitize_text_field(wp_unslash($_POST['input'] ?? ''));
        $school_id = absint($_POST['school_id'] ?? 0);
        $mode = sanitize_key(wp_unslash($_POST['mode'] ?? 'address'));
        if (strlen($input) < 3) {
            wp_send_json_success(array('suggestions' => array()));
        }

        $api_key = get_option(Terricel_Transit_Trips_Plugin::OPTION_GOOGLE_API_KEY, '');
        if (!$api_key) {
            wp_send_json_error(array('message' => __('Add a Google Maps API key with Places API (New) or Geocoding API enabled to use lookup.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN)), 400);
        }

        $suggestions = $this->get_text_search_suggestions($api_key, $input, $school_id, 'location' === $mode);
        if (is_wp_error($suggestions)) {
            $fallback = $this->get_geocoding_suggestions($api_key, $input);
            if (!is_wp_error($fallback)) {
                $suggestions = $fallback;
            }
        }
        if (is_wp_error($suggestions)) {
            wp_send_json_error(array('message' => $suggestions->get_error_message()), 400);
        }

        wp_send_json_success(array('suggestions' => $suggestions));
    }

    public function ajax_trip_place_details() {
        if (!current_user_can(Terricel_Transit_Trips_Plugin::CAP_MANAGE_TRIPS)) {
            wp_send_json_error(array('message' => __('You do not have permission to manage trips.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN)), 403);
        }

        check_ajax_referer('terricel_trip_group_ajax', 'nonce');
        $place_id = sanitize_text_field(wp_unslash($_POST['place_id'] ?? ''));
        $api_key = get_option(Terricel_Transit_Trips_Plugin::OPTION_GOOGLE_API_KEY, '');
        if (!$api_key) {
            wp_send_json_error(array('message' => __('Add a Google Maps API key with Places API enabled to use address lookup.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN)), 400);
        }
        if ('' === $place_id) {
            wp_send_json_error(array('message' => __('Select an address suggestion first.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN)), 400);
        }

        $response = wp_remote_get(
            add_query_arg(
                array(
                    'fields' => 'formattedAddress,displayName',
                    'key'    => $api_key,
                ),
                'https://places.googleapis.com/v1/places/' . rawurlencode($place_id)
            ),
            array('timeout' => 10)
        );

        if (is_wp_error($response)) {
            wp_send_json_error(array('message' => $response->get_error_message()), 500);
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        wp_send_json_success(
            array(
                'address' => sanitize_text_field($body['formattedAddress'] ?? ''),
                'name'    => sanitize_text_field($body['displayName']['text'] ?? ''),
            )
        );
    }

    public function ajax_trip_destination_estimate() {
        if (!current_user_can(Terricel_Transit_Trips_Plugin::CAP_MANAGE_TRIPS)) {
            wp_send_json_error(array('message' => __('You do not have permission to manage trips.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN)), 403);
        }

        check_ajax_referer('terricel_trip_group_ajax', 'nonce');
        $school_id = absint($_POST['school_id'] ?? 0);
        $destination = sanitize_text_field(wp_unslash($_POST['destination'] ?? ''));
        if ($school_id < 1 || '' === $destination) {
            wp_send_json_error(array('message' => __('Select a school and destination first.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN)), 400);
        }

        $estimate = $this->get_google_distance_estimate($school_id, $destination);
        if (empty($estimate['miles']) && empty($estimate['minutes'])) {
            wp_send_json_error(array('message' => $estimate['message'] ?? __('Unable to estimate mileage or travel time for this destination.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN)), 400);
        }

        wp_send_json_success(array('estimate' => $estimate));
    }

    public function ajax_trip_driver_conflicts() {
        if (!current_user_can(Terricel_Transit_Trips_Plugin::CAP_MANAGE_TRIPS)) {
            wp_send_json_error(array('message' => __('You do not have permission to manage trips.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN)), 403);
        }

        check_ajax_referer('terricel_trip_group_ajax', 'nonce');
        $trip_id = absint($_POST['post_id'] ?? 0);
        $drivers = array_filter(array_map('absint', explode(',', sanitize_text_field(wp_unslash($_POST['drivers'] ?? '')))));
        $start_date = $this->sanitize_date($_POST['pickup_date'] ?? '');
        $start_time = $this->sanitize_time($_POST['pickup_time'] ?? '');
        $end_date = $this->sanitize_date($_POST['return_date'] ?? '') ?: $start_date;
        $end_time = $this->sanitize_time($_POST['return_time'] ?? '');

        if (empty($drivers) || !$start_date || !$start_time || !$end_date || !$end_time) {
            wp_send_json_success(array('conflicts' => array()));
        }

        $assignments = array_map(
            static function($driver_id) {
                return array('bus_id' => 0, 'driver_id' => $driver_id);
            },
            array_values(array_unique($drivers))
        );

        $conflicts = $this->get_assignment_conflicts($trip_id, $assignments, $start_date, $start_time, $end_date, $end_time);
        $signature = $this->get_conflict_signature($conflicts);

        wp_send_json_success(
            array(
                'conflicts'  => $conflicts,
                'signature'  => $signature,
                'confirmed'  => $this->is_conflict_signature_confirmed($trip_id, $signature),
            )
        );
    }

    public function ajax_trip_bus_availability() {
        if (!current_user_can(Terricel_Transit_Trips_Plugin::CAP_MANAGE_TRIPS)) {
            wp_send_json_error(array('message' => __('You do not have permission to manage trips.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN)), 403);
        }

        check_ajax_referer('terricel_trip_group_ajax', 'nonce');
        $trip_id = absint($_POST['post_id'] ?? 0);
        $start_date = $this->sanitize_date($_POST['pickup_date'] ?? '');
        $start_time = $this->sanitize_time($_POST['pickup_time'] ?? '');
        $end_date = $this->sanitize_date($_POST['return_date'] ?? '') ?: $start_date;
        $end_time = $this->sanitize_time($_POST['return_time'] ?? '');

        if (!$start_date || !$start_time || !$end_date || !$end_time) {
            wp_send_json_success(array('unavailable_bus_ids' => array()));
        }

        wp_send_json_success(
            array(
                'unavailable_bus_ids' => $this->get_unavailable_bus_ids_for_window($trip_id, $start_date, $start_time, $end_date, $end_time),
            )
        );
    }

    private function get_text_search_suggestions($api_key, $input, $school_id = 0, $bias_to_school = false) {
        $query = $input;
        if ($bias_to_school && $school_id > 0) {
            $origin = $this->get_school_origin_address($school_id);
            if ($origin) {
                $query .= ' near ' . $origin;
            }
        }

        $response = wp_remote_post(
            'https://places.googleapis.com/v1/places:searchText',
            array(
                'timeout' => 10,
                'headers' => array(
                    'Content-Type'     => 'application/json',
                    'X-Goog-Api-Key'   => $api_key,
                    'X-Goog-FieldMask' => 'places.id,places.displayName,places.formattedAddress',
                ),
                'body'    => wp_json_encode(
                    array(
                        'textQuery'    => $query,
                        'languageCode' => 'en',
                        'regionCode'   => 'US',
                    )
                ),
            )
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!empty($body['error']['message'])) {
            return new WP_Error('terricel_places_text_search_error', sanitize_text_field($body['error']['message']));
        }

        $suggestions = array();
        foreach (array_slice(($body['places'] ?? array()), 0, 5) as $place) {
            if (empty($place['id'])) {
                continue;
            }

            $suggestions[] = array(
                'placeId'       => sanitize_text_field($place['id']),
                'text'          => sanitize_text_field($place['displayName']['text'] ?? $place['formattedAddress'] ?? ''),
                'mainText'      => sanitize_text_field($place['displayName']['text'] ?? $place['formattedAddress'] ?? ''),
                'secondaryText' => sanitize_text_field($place['formattedAddress'] ?? ''),
                'address'       => sanitize_text_field($place['formattedAddress'] ?? ''),
                'name'          => sanitize_text_field($place['displayName']['text'] ?? ''),
            );
        }

        return $suggestions;
    }

    private function get_geocoding_suggestions($api_key, $input) {
        $response = wp_remote_get(
            add_query_arg(
                array(
                    'address' => $input,
                    'region'  => 'us',
                    'key'     => $api_key,
                ),
                'https://maps.googleapis.com/maps/api/geocode/json'
            ),
            array('timeout' => 10)
        );

        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!empty($body['error_message'])) {
            return new WP_Error('terricel_geocoding_error', sanitize_text_field($body['error_message']));
        }
        if (!empty($body['status']) && !in_array($body['status'], array('OK', 'ZERO_RESULTS'), true)) {
            return new WP_Error('terricel_geocoding_status', sanitize_text_field($body['status']));
        }

        $suggestions = array();
        foreach (array_slice(($body['results'] ?? array()), 0, 5) as $result) {
            $address = sanitize_text_field($result['formatted_address'] ?? '');
            if (!$address) {
                continue;
            }

            $name = $this->get_geocoding_result_name($result, $address);
            $suggestions[] = array(
                'placeId'       => sanitize_text_field($result['place_id'] ?? ''),
                'text'          => $address,
                'mainText'      => $name,
                'secondaryText' => $address,
                'address'       => $address,
                'name'          => $name,
            );
        }

        return $suggestions;
    }

    private function get_geocoding_result_name($result, $fallback) {
        foreach (($result['address_components'] ?? array()) as $component) {
            $types = $component['types'] ?? array();
            if (in_array('establishment', $types, true) || in_array('point_of_interest', $types, true) || in_array('premise', $types, true)) {
                return sanitize_text_field($component['long_name'] ?? $fallback);
            }
        }

        return sanitize_text_field($fallback);
    }

    private function maybe_update_trip_title($post_id, $post, $school_id, $group_id, $pickup_date, $location_name) {
        $parts = array();
        if ($group_id > 0) {
            $group_name = get_the_title($group_id);
            $school_name = $school_id > 0 ? $this->get_school_label($school_id) : '';
            $parts[] = trim($group_name . ($school_name ? ' (' . $school_name . ')' : ''));
        }
        if ($location_name) {
            $parts[] = 'to ' . $location_name;
        }
        if ($pickup_date) {
            $parts[] = '| ' . $pickup_date;
        }

        $title = 'Trip' . (!empty($parts) ? ' ' . implode(' ', $parts) : '');

        if (!$title || $title === $post->post_title) {
            return;
        }

        remove_action('save_post_' . self::TRIP_POST_TYPE, array($this, 'save_trip_meta'), 10);
        wp_update_post(array('ID' => $post_id, 'post_title' => $title, 'post_name' => sanitize_title($title)));
        add_action('save_post_' . self::TRIP_POST_TYPE, array($this, 'save_trip_meta'), 10, 2);
    }

    private function maybe_keep_incomplete_trip_draft($post_id) {
        if ('publish' !== get_post_status($post_id) || $this->trip_is_ready_to_publish($post_id)) {
            return;
        }

        remove_action('save_post_' . self::TRIP_POST_TYPE, array($this, 'save_trip_meta'), 10);
        wp_update_post(array('ID' => $post_id, 'post_status' => 'draft'));
        add_action('save_post_' . self::TRIP_POST_TYPE, array($this, 'save_trip_meta'), 10, 2);
        set_transient('terricel_trip_incomplete_publish_' . get_current_user_id(), $post_id, 60);
    }

    private function trip_is_ready_to_publish($post_id) {
        $school_id = absint(get_post_meta($post_id, '_terricel_trip_school_id', true));
        $group_id = absint(get_post_meta($post_id, '_terricel_trip_group_id', true));
        $location_name = trim((string) get_post_meta($post_id, '_terricel_trip_location_name', true));
        $destination = trim((string) get_post_meta($post_id, '_terricel_trip_destination_address', true));
        $travel_minutes = absint(get_post_meta($post_id, '_terricel_trip_estimated_travel_minutes', true));
        if ($school_id < 1 || !$this->group_belongs_to_school($group_id, $school_id) || '' === $location_name || '' === $destination || $travel_minutes < 1) {
            return false;
        }

        foreach (array('pickup', 'arrival', 'departure', 'return') as $key) {
            if (!$this->sanitize_date(get_post_meta($post_id, '_terricel_trip_' . $key . '_date', true)) || !$this->sanitize_time(get_post_meta($post_id, '_terricel_trip_' . $key . '_time', true))) {
                return false;
            }
        }

        return true;
    }

    private function get_school_label($school_id) {
        if ($school_id < 1 || 'publish' !== get_post_status($school_id)) {
            return __('Unassigned', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN);
        }

        $short_name = get_post_meta($school_id, '_terricel_school_short_name', true);
        return $short_name ? $short_name : get_the_title($school_id);
    }

    private function get_group_advisor_name($post_id) {
        $first_name = get_post_meta($post_id, '_terricel_trip_group_advisor_first_name', true);
        $last_name = get_post_meta($post_id, '_terricel_trip_group_advisor_last_name', true);
        $name = trim($first_name . ' ' . $last_name);

        return $name ? $name : __('Not set', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN);
    }

    private function get_trip_group_advisor_link($trip_id) {
        $group_id = absint(get_post_meta($trip_id, '_terricel_trip_group_id', true));
        if ($group_id < 1 || self::GROUP_POST_TYPE !== get_post_type($group_id)) {
            return esc_html__('Not set', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN);
        }

        $advisor_name = $this->get_group_advisor_name($group_id);
        $url = get_edit_post_link($group_id, '');

        if (!$url) {
            return esc_html($advisor_name);
        }

        return '<a href="' . esc_url($url) . '">' . esc_html($advisor_name) . '</a>';
    }

    private function get_group_contact_links($post_id, $as_buttons = false) {
        $links = array();
        $class = $as_buttons ? 'button button-secondary' : '';
        $main_phone = get_post_meta($post_id, '_terricel_trip_group_advisor_main_phone', true);
        $main_phone_extension = get_post_meta($post_id, '_terricel_trip_group_advisor_main_phone_extension', true);
        $emergency_phone = get_post_meta($post_id, '_terricel_trip_group_advisor_emergency_phone', true);
        $email = get_post_meta($post_id, '_terricel_trip_group_advisor_email', true);

        if ($main_phone) {
            $main_phone_label = $main_phone_extension ? sprintf(__('%1$s ext. %2$s', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $main_phone, $main_phone_extension) : $main_phone;
            $links[] = '<a class="' . esc_attr($class) . '" href="tel:' . esc_attr($this->get_phone_href($main_phone)) . '"><span class="dashicons dashicons-phone"></span> ' . esc_html(sprintf(__('Main: %s', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $main_phone_label)) . '</a>';
        }

        if ($emergency_phone) {
            $links[] = '<a class="' . esc_attr($class) . '" href="tel:' . esc_attr($this->get_phone_href($emergency_phone)) . '"><span class="dashicons dashicons-warning"></span> ' . esc_html(sprintf(__('Emergency: %s', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $emergency_phone)) . '</a>';
        }

        if ($email) {
            $links[] = '<a class="' . esc_attr($class) . '" href="mailto:' . esc_attr($email) . '"><span class="dashicons dashicons-email"></span> ' . esc_html($email) . '</a>';
        }

        if (empty($links) && !$as_buttons) {
            return esc_html__('None', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN);
        }

        if (empty($links)) {
            return '';
        }

        return '<div class="terricel-group-contact-links">' . implode(' ', $links) . '</div>';
    }

    private function can_save($post_id, $nonce_key, $nonce_action) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return false;
        }

        if (wp_is_post_revision($post_id)) {
            return false;
        }

        if (empty($_POST[$nonce_key]) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST[$nonce_key])), $nonce_action)) {
            return false;
        }

        return current_user_can(Terricel_Transit_Trips_Plugin::CAP_MANAGE_TRIPS);
    }

    private function sanitize_date($value) {
        $value = sanitize_text_field(wp_unslash($value));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return '';
        }
        return strtotime($value) ? $value : '';
    }

    private function sanitize_time($value) {
        $value = sanitize_text_field(wp_unslash($value));
        return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value) ? $value : '';
    }

    private function sanitize_person_name($value) {
        $value = sanitize_text_field(wp_unslash($value));
        $value = trim(preg_replace('/\s+/', ' ', $value));

        return $value;
    }

    private function sanitize_phone($value) {
        if (class_exists('Terricel_Logistics_Shared_Data') && method_exists('Terricel_Logistics_Shared_Data', 'sanitize_phone_number')) {
            return Terricel_Logistics_Shared_Data::sanitize_phone_number($value);
        }

        $digits = preg_replace('/\D+/', '', (string) wp_unslash($value));
        return substr($digits, 0, 10);
    }

    private function sanitize_extension($value) {
        return substr(preg_replace('/\D+/', '', (string) wp_unslash($value)), 0, 8);
    }

    private function get_phone_href($phone) {
        if (class_exists('Terricel_Logistics_Shared_Data') && method_exists('Terricel_Logistics_Shared_Data', 'phone_href')) {
            return Terricel_Logistics_Shared_Data::phone_href($phone);
        }

        return preg_replace('/\D+/', '', (string) $phone);
    }

    private function sanitize_decimal($value) {
        $value = sanitize_text_field(wp_unslash($value));
        return '' === $value ? '' : max(0, (float) $value);
    }

    private function windows_overlap($a_start_date, $a_start_time, $a_end_date, $a_end_time, $b_start_date, $b_start_time, $b_end_date, $b_end_time) {
        if (!$a_start_date || !$a_start_time || !$a_end_date || !$a_end_time || !$b_start_date || !$b_start_time || !$b_end_date || !$b_end_time) {
            return false;
        }

        $a_start = strtotime($a_start_date . ' ' . $a_start_time);
        $a_end = strtotime($a_end_date . ' ' . $a_end_time);
        $b_start = strtotime($b_start_date . ' ' . $b_start_time);
        $b_end = strtotime($b_end_date . ' ' . $b_end_time);

        return $a_start && $a_end && $b_start && $b_end && $a_start < $b_end && $b_start < $a_end;
    }

    private function insert_columns($columns, $new_columns) {
        $date = array();
        if (isset($columns['date'])) {
            $date = array('date' => $columns['date']);
            unset($columns['date']);
        }
        return array_merge($columns, $new_columns, $date);
    }
}
