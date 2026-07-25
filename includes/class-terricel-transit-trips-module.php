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
                'supports'           => array('title'),
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
        add_meta_box('terricel_trip_assignments', __('Buses & Drivers', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), array($this, 'render_trip_assignments_meta_box'), self::TRIP_POST_TYPE, 'normal');
        add_meta_box('terricel_trip_group_details', __('Group Details', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), array($this, 'render_group_details_meta_box'), self::GROUP_POST_TYPE, 'normal', 'high');
        add_meta_box('terricel_bus_trip_eligibility', __('Trip Eligibility', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), array($this, 'render_bus_trip_eligibility_meta_box'), Terricel_Logistics_Shared_Data::BUS_POST_TYPE, 'side');
    }

    public function render_trip_details_meta_box($post) {
        wp_nonce_field('terricel_trip_meta', 'terricel_trip_meta_nonce');
        $school_id = (int) get_post_meta($post->ID, '_terricel_trip_school_id', true);
        $group_id = (int) get_post_meta($post->ID, '_terricel_trip_group_id', true);
        $pickup_date = get_post_meta($post->ID, '_terricel_trip_pickup_date', true);
        $pickup_time = get_post_meta($post->ID, '_terricel_trip_pickup_time', true);
        $arrival_date = get_post_meta($post->ID, '_terricel_trip_arrival_date', true);
        $arrival_time = get_post_meta($post->ID, '_terricel_trip_arrival_time', true);
        $departure_date = get_post_meta($post->ID, '_terricel_trip_departure_date', true);
        $departure_time = get_post_meta($post->ID, '_terricel_trip_departure_time', true);
        $return_date = get_post_meta($post->ID, '_terricel_trip_return_date', true);
        $return_time = get_post_meta($post->ID, '_terricel_trip_return_time', true);

        echo '<div class="terricel-trip-grid">';
        $this->render_post_select_field('terricel_trip_school_id', __('School', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), Terricel_Logistics_Shared_Data::SCHOOL_POST_TYPE, $school_id, __('Select school', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN));
        $this->render_group_select_field($school_id, $group_id);
        $this->render_date_time_field('pickup', __('Pickup', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $pickup_date, $pickup_time);
        $this->render_date_time_field('arrival', __('Arrival', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $arrival_date ? $arrival_date : $pickup_date, $arrival_time);
        $this->render_date_time_field('departure', __('Departure', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $departure_date ? $departure_date : $pickup_date, $departure_time);
        $this->render_date_time_field('return', __('Return', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $return_date ? $return_date : $pickup_date, $return_time);
        echo '</div>';
    }

    public function render_trip_destination_meta_box($post) {
        $name = get_post_meta($post->ID, '_terricel_trip_location_name', true);
        $address = get_post_meta($post->ID, '_terricel_trip_destination_address', true);
        $mileage = get_post_meta($post->ID, '_terricel_trip_estimated_mileage', true);
        $travel_minutes = get_post_meta($post->ID, '_terricel_trip_estimated_travel_minutes', true);
        $maps_url = $this->get_trip_maps_url($post->ID);

        echo '<p><label for="terricel_trip_location_name"><strong>' . esc_html__('Location Name', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</strong></label></p>';
        echo '<p><input class="widefat" type="text" id="terricel_trip_location_name" name="terricel_trip_location_name" value="' . esc_attr($name) . '"></p>';
        echo '<p><label for="terricel_trip_destination_address"><strong>' . esc_html__('Destination Address', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</strong></label></p>';
        echo '<p><textarea class="widefat" rows="3" id="terricel_trip_destination_address" name="terricel_trip_destination_address">' . esc_textarea($address) . '</textarea></p>';
        echo '<div class="terricel-trip-grid">';
        echo '<p><label for="terricel_trip_estimated_mileage"><strong>' . esc_html__('Estimated Round Trip Mileage', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</strong></label><br><input type="number" min="0" step="0.1" id="terricel_trip_estimated_mileage" name="terricel_trip_estimated_mileage" value="' . esc_attr($mileage) . '"></p>';
        echo '<p><label for="terricel_trip_estimated_travel_minutes"><strong>' . esc_html__('Estimated One-Way Travel Time', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</strong></label><br><input type="number" min="0" step="1" id="terricel_trip_estimated_travel_minutes" name="terricel_trip_estimated_travel_minutes" value="' . esc_attr($travel_minutes) . '"> ' . esc_html__('minutes', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</p>';
        echo '</div>';

        if ($maps_url) {
            echo '<p><a class="button" target="_blank" rel="noopener" href="' . esc_url($maps_url) . '">' . esc_html__('Open Destination in Maps', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</a></p>';
        }
    }

    public function render_trip_assignments_meta_box($post) {
        $buses_needed = max(1, (int) get_post_meta($post->ID, '_terricel_trip_buses_needed', true));
        $assignments = $this->get_trip_assignments($post->ID);
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
            echo '<p><label><input type="checkbox" name="terricel_trip_confirm_conflicts" value="1"> ' . esc_html__('Confirm these conflicts and remove conflicting default route assignments for selected drivers.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</label></p></div>';
        }

        echo '<table class="widefat striped terricel-trip-assignments"><thead><tr>';
        echo '<th>' . esc_html__('Bus Slot', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</th>';
        echo '<th>' . esc_html__('Trip Bus', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</th>';
        echo '<th>' . esc_html__('Driver', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</th>';
        echo '</tr></thead><tbody>';

        for ($i = 0; $i < $buses_needed; $i++) {
            $assignment = isset($assignments[$i]) ? $assignments[$i] : array('bus_id' => 0, 'driver_id' => 0);
            echo '<tr>';
            echo '<td>' . esc_html(sprintf(__('Bus %d', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $i + 1)) . '</td>';
            echo '<td>' . $this->get_bus_select('terricel_trip_assignments[' . $i . '][bus_id]', absint($assignment['bus_id']), $post->ID) . '</td>';
            echo '<td>' . $this->get_driver_select('terricel_trip_assignments[' . $i . '][driver_id]', absint($assignment['driver_id'])) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
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
        update_post_meta($post_id, '_terricel_trip_estimated_mileage', $estimated_mileage);
        update_post_meta($post_id, '_terricel_trip_estimated_travel_minutes', $estimated_minutes);

        if ($pickup_date && $pickup_time && $estimated_minutes > 0 && empty($_POST['terricel_trip_arrival_time'])) {
            $arrival_timestamp = strtotime($pickup_date . ' ' . $pickup_time) + ($estimated_minutes * MINUTE_IN_SECONDS);
            update_post_meta($post_id, '_terricel_trip_arrival_date', date('Y-m-d', $arrival_timestamp));
            update_post_meta($post_id, '_terricel_trip_arrival_time', date('H:i', $arrival_timestamp));
        }

        $assignments = $this->sanitize_assignments($_POST['terricel_trip_assignments'] ?? array(), $buses_needed);
        $conflicts = $this->get_assignment_conflicts($post_id, $assignments);
        $confirmed = !empty($_POST['terricel_trip_confirm_conflicts']);

        if (!empty($conflicts) && !$confirmed) {
            update_post_meta($post_id, '_terricel_trip_pending_assignments', $assignments);
            update_post_meta($post_id, '_terricel_trip_pending_conflicts', $conflicts);
            set_transient('terricel_trip_conflicts_' . get_current_user_id(), $post_id, 60);
            return;
        }

        delete_post_meta($post_id, '_terricel_trip_pending_assignments');
        delete_post_meta($post_id, '_terricel_trip_pending_conflicts');

        if (!empty($conflicts) && $confirmed) {
            $this->remove_conflicting_driver_routes($conflicts);
        }

        $old_assignments = $this->get_trip_assignments($post_id);
        update_post_meta($post_id, '_terricel_trip_assignments', $assignments);
        $this->maybe_queue_driver_assignment_notifications($post_id, $old_assignments, $assignments);
        $this->maybe_update_trip_title($post_id, $post, $school_id, $pickup_date, $location_name);
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
        if ((int) get_transient('terricel_trip_conflicts_' . get_current_user_id()) === absint($post_id)) {
            delete_transient('terricel_trip_conflicts_' . get_current_user_id());
            $location = add_query_arg('terricel-trip-conflicts', 1, $location);
        }

        return $location;
    }

    public function render_admin_notices() {
        if (!empty($_GET['terricel-trip-conflicts'])) {
            echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__('Trip details were saved, but driver/bus assignments were not changed because conflicts need confirmation.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</p></div>';
        }

        $page = isset($_GET['page']) ? sanitize_key(wp_unslash($_GET['page'])) : '';
        if (in_array($page, array('terricel-my-dashboard', 'terricel-driver-dashboard'), true)) {
            $driver_id = $this->get_current_user_driver_id();
            if ($driver_id > 0) {
                $this->render_driver_dashboard_trips($driver_id);
            }
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
                    $this->queue_user_notification($user_id, 'trip_driver_reminder', __('Upcoming Trip Assignment', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $this->format_trip_notice_message($trip->ID), $this->get_trip_maps_url($trip->ID));
                }
                update_post_meta($trip->ID, '_terricel_trip_driver_reminder_sent', current_time('mysql'));
            }
        }
    }

    public function render_driver_dashboard_trips($driver_id) {
        $trips = $this->get_driver_upcoming_trips($driver_id);
        if (empty($trips)) {
            return;
        }

        echo '<h2>' . esc_html__('Trip Assignments', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</h2>';
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

    private function get_assignment_conflicts($trip_id, $assignments) {
        $conflicts = array();
        $start_date = get_post_meta($trip_id, '_terricel_trip_pickup_date', true);
        $start_time = get_post_meta($trip_id, '_terricel_trip_pickup_time', true);
        $end_date = get_post_meta($trip_id, '_terricel_trip_return_date', true) ?: $start_date;
        $end_time = get_post_meta($trip_id, '_terricel_trip_return_time', true) ?: '23:59';

        foreach ($assignments as $assignment) {
            $driver_id = absint($assignment['driver_id']);
            if ($driver_id < 1) {
                continue;
            }

            foreach ($this->get_driver_default_route_conflicts($driver_id, $start_date, $start_time, $end_date, $end_time) as $route_conflict) {
                $conflicts[] = array(
                    'driver_id' => $driver_id,
                    'route_id'  => $route_conflict['route_id'],
                    'message'   => sprintf(
                        __('%1$s conflicts with default route %2$s (%3$s-%4$s).', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
                        get_the_title($driver_id),
                        get_the_title($route_conflict['route_id']),
                        $route_conflict['start_time'],
                        $route_conflict['end_time']
                    ),
                );
            }
        }

        return $conflicts;
    }

    private function get_driver_default_route_conflicts($driver_id, $start_date, $start_time, $end_date, $end_time) {
        $conflicts = array();
        if (!$start_date || !$start_time || !$end_date || !$end_time || !function_exists('terricel_logistics')) {
            return $conflicts;
        }

        $route_ids = terricel_logistics()->get_driver_default_route_ids($driver_id);
        foreach ($route_ids as $route_id) {
            foreach ($this->get_route_runs_for_date($route_id, $start_date) as $run) {
                $run_start = isset($run['start_time']) ? $run['start_time'] : '';
                $run_end = isset($run['end_time']) && $run['end_time'] ? $run['end_time'] : '';
                if (!$run_end && function_exists('terricel_logistics')) {
                    $run_end = terricel_logistics()->get_default_run_end_time($run_start);
                }
                if ($this->windows_overlap($start_date, $start_time, $end_date, $end_time, $start_date, $run_start, $start_date, $run_end)) {
                    $conflicts[] = array('route_id' => $route_id, 'start_time' => $run_start, 'end_time' => $run_end);
                }
            }
        }

        return $conflicts;
    }

    private function remove_conflicting_driver_routes($conflicts) {
        foreach ($conflicts as $conflict) {
            $driver_id = absint($conflict['driver_id']);
            $route_id = absint($conflict['route_id']);
            if ($driver_id < 1 || $route_id < 1 || !function_exists('terricel_logistics')) {
                continue;
            }

            $route_ids = array_values(array_diff(array_map('absint', terricel_logistics()->get_driver_default_route_ids($driver_id)), array($route_id)));
            if (empty($route_ids)) {
                delete_post_meta($driver_id, '_terricel_driver_default_route_ids');
                delete_post_meta($driver_id, '_terricel_driver_default_route_id');
                continue;
            }

            update_post_meta($driver_id, '_terricel_driver_default_route_ids', $route_ids);
            update_post_meta($driver_id, '_terricel_driver_default_route_id', $route_ids[0]);
        }
    }

    private function get_route_runs_for_date($route_id, $date) {
        if (!function_exists('terricel_logistics')) {
            return array();
        }

        $day_key = strtolower(date('l', strtotime($date)));
        $schedule = get_post_meta($route_id, '_terricel_route_coverage_route_schedule', true);
        $schedule = is_array($schedule) ? $schedule : array();
        $runs = isset($schedule[$day_key]) && is_array($schedule[$day_key]) ? $schedule[$day_key] : array();

        return terricel_logistics()->apply_route_schedule_changes_to_runs($route_id, $date, $runs);
    }

    private function maybe_queue_driver_assignment_notifications($trip_id, $old_assignments, $new_assignments) {
        $old_driver_ids = array_filter(array_map('absint', wp_list_pluck($old_assignments, 'driver_id')));
        foreach ($new_assignments as $assignment) {
            $driver_id = absint($assignment['driver_id']);
            if ($driver_id < 1 || in_array($driver_id, $old_driver_ids, true)) {
                continue;
            }

            $user_id = (int) get_post_meta($driver_id, '_terricel_driver_user_id', true);
            if ($user_id > 0) {
                $this->queue_user_notification($user_id, 'trip_driver_assigned', __('New Trip Assignment', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $this->format_trip_notice_message($trip_id), $this->get_trip_maps_url($trip_id));
            }
        }
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
        $trips = $this->get_upcoming_trips(60);
        return array_values(array_filter($trips, function ($trip) use ($driver_id) {
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

    private function sanitize_assignments($assignments, $limit) {
        $assignments = is_array($assignments) ? $assignments : array();
        $clean = array();

        foreach (array_slice($assignments, 0, $limit) as $assignment) {
            $bus_id = absint($assignment['bus_id'] ?? 0);
            $driver_id = absint($assignment['driver_id'] ?? 0);
            if ($bus_id < 1 && $driver_id < 1) {
                continue;
            }
            $clean[] = array('bus_id' => $bus_id, 'driver_id' => $driver_id);
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

    private function format_trip_pickup($trip_id) {
        $date = get_post_meta($trip_id, '_terricel_trip_pickup_date', true);
        $time = get_post_meta($trip_id, '_terricel_trip_pickup_time', true);
        $date_label = $date ? date_i18n(get_option('date_format'), strtotime($date)) : __('Date not set', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN);
        $time_label = $time ? date_i18n(get_option('time_format'), strtotime($time)) : __('Time not set', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN);

        return $date_label . ' ' . $time_label;
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

    private function get_trip_maps_url($trip_id) {
        $address = trim((string) get_post_meta($trip_id, '_terricel_trip_destination_address', true));
        if (!$address) {
            return '';
        }

        return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($address);
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
            return array('miles' => 0, 'minutes' => 0);
        }

        $url = add_query_arg(array('key' => $api_key), 'https://routes.googleapis.com/directions/v2:computeRoutes');
        $response = wp_remote_post(
            $url,
            array(
                'timeout' => 8,
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'X-Goog-FieldMask' => 'routes.distanceMeters,routes.duration',
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
                    )
                ),
            )
        );
        if (is_wp_error($response)) {
            return array('miles' => 0, 'minutes' => 0);
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        $route = $body['routes'][0] ?? array();
        if (empty($route['distanceMeters']) || empty($route['duration'])) {
            return array('miles' => 0, 'minutes' => 0);
        }

        $buffer = absint(get_option(Terricel_Transit_Trips_Plugin::OPTION_TRAVEL_BUFFER_PERCENT, 10));
        $one_way_meters = (float) $route['distanceMeters'];
        $one_way_seconds = (float) rtrim((string) $route['duration'], 's');

        return array(
            'miles'   => round(($one_way_meters / 1609.344) * 2, 1),
            'minutes' => (int) round(($one_way_seconds / 60) * (1 + ($buffer / 100))),
        );
    }

    public function enqueue_admin_assets($hook) {
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->post_type, array(self::TRIP_POST_TYPE, self::GROUP_POST_TYPE), true)) {
            return;
        }

        wp_register_style('terricel-transit-trips-admin', false, array(), TERRICEL_TRANSIT_TRIPS_VERSION);
        wp_enqueue_style('terricel-transit-trips-admin');
        wp_add_inline_style('terricel-transit-trips-admin', '.terricel-trip-grid,.terricel-group-details-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px 16px}.terricel-trip-grid p,.terricel-group-details-grid p{margin-top:0}.terricel-trip-assignments select,.terricel-group-details-grid select,.terricel-group-details-grid input{max-width:100%;width:100%}.terricel-group-contact-links{display:flex;gap:8px;flex-wrap:wrap;margin:14px 0 0}.terricel-group-contact-links a{text-decoration:none}.terricel-group-contact-links .dashicons{vertical-align:text-bottom}.terricel-trips-back-link{margin:0 0 12px}');
    }

    public function trip_columns($columns) {
        return $this->insert_columns($columns, array(
            'terricel_school' => __('School', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
            'terricel_pickup' => __('Pickup', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
            'terricel_destination' => __('Destination', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
            'terricel_assignments' => __('Assignments', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
        ));
    }

    public function render_trip_column($column, $post_id) {
        if ('terricel_school' === $column) {
            echo esc_html($this->get_school_label((int) get_post_meta($post_id, '_terricel_trip_school_id', true)));
        } elseif ('terricel_pickup' === $column) {
            echo esc_html($this->format_trip_pickup($post_id));
        } elseif ('terricel_destination' === $column) {
            echo esc_html(get_post_meta($post_id, '_terricel_trip_location_name', true));
        } elseif ('terricel_assignments' === $column) {
            echo esc_html(count($this->get_trip_assignments($post_id)));
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
                'key'     => '_terricel_trip_pickup_date',
                'value'   => current_time('Y-m-d'),
                'compare' => '>=',
                'type'    => 'DATE',
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

    private function render_text_field($name, $label, $value, $type = 'text', $inputmode = '') {
        $is_phone_field = 'tel' === $type;
        $class = $is_phone_field ? 'terricel-phone-field' : '';
        $extra = $is_phone_field ? ' inputmode="numeric" autocomplete="tel" maxlength="14"' : '';
        if (!$is_phone_field && $inputmode) {
            $extra .= ' inputmode="' . esc_attr($inputmode) . '"';
        }

        echo '<p>';
        echo '<label for="' . esc_attr($name) . '"><strong>' . esc_html($label) . '</strong></label><br>';
        echo '<input class="' . esc_attr($class) . '" type="' . esc_attr($type) . '" id="' . esc_attr($name) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '"' . $extra . '>';
        echo '</p>';
    }

    private function render_group_select_field($school_id, $selected) {
        echo '<p><label for="terricel_trip_group_id"><strong>' . esc_html__('School Group', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</strong></label><br>';
        echo '<select class="widefat" id="terricel_trip_group_id" name="terricel_trip_group_id">';
        echo '<option value="0">' . esc_html__('Select group', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</option>';
        foreach ($this->get_groups_for_school($school_id) as $group) {
            echo '<option value="' . esc_attr($group->ID) . '"' . selected($selected, $group->ID, false) . '>' . esc_html(get_the_title($group)) . '</option>';
        }
        echo '</select></p>';
    }

    private function render_date_time_field($key, $label, $date, $time) {
        echo '<p><strong>' . esc_html($label) . '</strong><br>';
        echo '<input type="date" name="terricel_trip_' . esc_attr($key) . '_date" value="' . esc_attr($date) . '"> ';
        echo '<input type="time" name="terricel_trip_' . esc_attr($key) . '_time" value="' . esc_attr($time) . '"></p>';
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
        $buses = get_posts(
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

        return array_values(array_filter($buses, function ($bus) use ($trip_id, $selected) {
            return absint($bus->ID) === absint($selected) || !$this->bus_has_trip_conflict($bus->ID, $trip_id);
        }));
    }

    private function bus_has_trip_conflict($bus_id, $trip_id) {
        $start_date = get_post_meta($trip_id, '_terricel_trip_pickup_date', true);
        $start_time = get_post_meta($trip_id, '_terricel_trip_pickup_time', true);
        $end_date = get_post_meta($trip_id, '_terricel_trip_return_date', true) ?: $start_date;
        $end_time = get_post_meta($trip_id, '_terricel_trip_return_time', true) ?: '23:59';

        if (!$start_date || !$start_time) {
            return false;
        }

        foreach ($this->get_upcoming_trips(365) as $trip) {
            if (absint($trip->ID) === absint($trip_id)) {
                continue;
            }
            foreach ($this->get_trip_assignments($trip->ID) as $assignment) {
                if (absint($assignment['bus_id']) !== absint($bus_id)) {
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
                    'key'     => '_terricel_trip_pickup_date',
                    'value'   => current_time('Y-m-d'),
                    'compare' => '>=',
                    'type'    => 'DATE',
                ),
            );
        }

        $query = new WP_Query($args);

        return (int) $query->found_posts;
    }

    private function get_groups_for_school($school_id) {
        $args = array('post_type' => self::GROUP_POST_TYPE, 'post_status' => 'publish', 'posts_per_page' => 500, 'orderby' => 'title', 'order' => 'ASC');
        if ($school_id > 0) {
            $args['meta_key'] = '_terricel_trip_group_school_id';
            $args['meta_value'] = absint($school_id);
        }
        return get_posts($args);
    }

    private function maybe_update_trip_title($post_id, $post, $school_id, $pickup_date, $location_name) {
        if (!$school_id && !$pickup_date && !$location_name) {
            return;
        }

        $title = trim($this->get_school_label($school_id) . ' - ' . $pickup_date . ' - ' . $location_name, ' -');
        if (!$title || $title === $post->post_title) {
            return;
        }

        remove_action('save_post_' . self::TRIP_POST_TYPE, array($this, 'save_trip_meta'), 10);
        wp_update_post(array('ID' => $post_id, 'post_title' => $title, 'post_name' => sanitize_title($title)));
        add_action('save_post_' . self::TRIP_POST_TYPE, array($this, 'save_trip_meta'), 10, 2);
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
