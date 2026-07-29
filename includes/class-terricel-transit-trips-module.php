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
    const ORGANIZATION_POST_TYPE = 'terricel_trip_org';
    const MODULE_ID = 'trips';

    private $plugin;

    public function __construct(Terricel_Transit_Trips_Plugin $plugin) {
        $this->plugin = $plugin;
        $this->id = self::MODULE_ID;
        $this->name = __('Trips', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN);
        $this->description = __('Plan organization trips, assign eligible buses and drivers, and notify operations or drivers when action is needed.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN);
        $this->phase = __('Trip coordination scaffold', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN);
        $this->capability = Terricel_Transit_Trips_Plugin::CAP_MANAGE_TRIPS;
    }

    protected function register_post_types() {
        $this->register_cancelled_trip_status();
        $this->register_trip_post_type();
        $this->register_group_post_type();
        $this->register_organization_post_type();
    }

    private function register_cancelled_trip_status() {
        register_post_status(
            'cancelled',
            array(
                'label'                     => _x('Cancelled', 'trip status', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
                'public'                    => false,
                'internal'                  => false,
                'exclude_from_search'       => true,
                'show_in_admin_all_list'    => true,
                'show_in_admin_status_list' => true,
                'label_count'               => _n_noop('Cancelled <span class="count">(%s)</span>', 'Cancelled <span class="count">(%s)</span>', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
            )
        );
    }

    protected function register_hooks() {
        if (is_admin()) {
            add_action('admin_init', array($this, 'ensure_other_organization'));
            add_action('admin_menu', array($this, 'register_billing_menu'), 1000);
            add_action('admin_menu', array($this, 'remove_duplicate_module_menu'), 1001);
            add_filter('terricel_logistics_configuration_menu_items', array($this, 'add_configuration_menu_items'));
            add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
            add_action('save_post_' . self::TRIP_POST_TYPE, array($this, 'save_trip_meta'), 10, 2);
            add_action('save_post_' . self::GROUP_POST_TYPE, array($this, 'save_group_meta'), 10, 2);
            add_action('save_post_' . self::ORGANIZATION_POST_TYPE, array($this, 'save_organization_meta'), 10, 2);
            add_action('save_post_' . Terricel_Logistics_Shared_Data::BUS_POST_TYPE, array($this, 'save_bus_trip_meta'), 20);
            add_action('save_post_' . Terricel_Logistics_Shared_Data::SCHOOL_POST_TYPE, array($this, 'maybe_cancel_school_closed_trips'), 30, 2);
            add_action('wp_trash_post', array($this, 'prevent_deleting_records_with_past_trips'));
            add_action('before_delete_post', array($this, 'prevent_deleting_records_with_past_trips'));
            add_filter('manage_' . self::TRIP_POST_TYPE . '_posts_columns', array($this, 'trip_columns'));
            add_action('manage_' . self::TRIP_POST_TYPE . '_posts_custom_column', array($this, 'render_trip_column'), 10, 2);
            add_filter('views_edit-' . self::TRIP_POST_TYPE, array($this, 'trip_list_views'));
            add_action('pre_get_posts', array($this, 'filter_trip_admin_list'));
            add_filter('manage_' . self::GROUP_POST_TYPE . '_posts_columns', array($this, 'group_columns'));
            add_action('manage_' . self::GROUP_POST_TYPE . '_posts_custom_column', array($this, 'render_group_column'), 10, 2);
            add_filter('manage_' . self::ORGANIZATION_POST_TYPE . '_posts_columns', array($this, 'organization_columns'));
            add_action('manage_' . self::ORGANIZATION_POST_TYPE . '_posts_custom_column', array($this, 'render_organization_column'), 10, 2);
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
            add_action('wp_ajax_terricel_create_trip_organization', array($this, 'ajax_create_trip_organization'));
            add_action('admin_post_terricel_trips_download_trip_sheet', array($this, 'handle_download_trip_sheet'));
            add_action('admin_post_terricel_trips_view_invoice', array($this, 'handle_view_invoice'));
            add_action('admin_post_terricel_trips_send_invoice', array($this, 'handle_send_invoice'));
            add_action('admin_post_terricel_trips_void_invoice', array($this, 'handle_void_invoice'));
        }
    }

    public function render_admin_page() {
        wp_safe_redirect(admin_url('edit.php?post_type=' . self::TRIP_POST_TYPE));
        exit;
    }

    public function remove_duplicate_module_menu() {
        remove_submenu_page('terricel-transit', 'terricel-transit-' . self::MODULE_ID);
        remove_submenu_page('terricel-transit', 'edit.php?post_type=' . self::GROUP_POST_TYPE);
        remove_submenu_page('terricel-transit', 'edit.php?post_type=' . self::ORGANIZATION_POST_TYPE);
    }

    public function add_configuration_menu_items($items) {
        if (!current_user_can(Terricel_Transit_Trips_Plugin::CAP_MANAGE_TRIPS)) {
            return $items;
        }

        $items[] = array(
            'label' => __('Organizations', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
            'slug'  => 'edit.php?post_type=' . self::ORGANIZATION_POST_TYPE,
            'url'   => admin_url('edit.php?post_type=' . self::ORGANIZATION_POST_TYPE),
        );

        return $items;
    }

    public function register_billing_menu() {
        add_submenu_page(
            'terricel-transit',
            __('Trip Billing', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
            __('Billing', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
            Terricel_Transit_Trips_Plugin::CAP_MANAGE_TRIPS,
            'terricel-transit-trip-billing',
            array($this, 'render_billing_page')
        );
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
                    'name'          => __('Groups', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
                    'singular_name' => __('Group', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
                    'add_new_item'  => __('Add New Group', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
                    'edit_item'     => __('Edit Group', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
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

    private function register_organization_post_type() {
        register_post_type(
            self::ORGANIZATION_POST_TYPE,
            array(
                'labels' => array(
                    'name'          => __('Organizations', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
                    'singular_name' => __('Organization', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
                    'add_new_item'  => __('Add New Organization', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
                    'edit_item'     => __('Edit Organization', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
                ),
                'public'             => false,
                'show_ui'            => true,
                'show_in_menu'       => false,
                'show_in_rest'       => false,
                'supports'           => array('title'),
                'capability_type'    => 'post',
                'map_meta_cap'       => false,
                'capabilities'       => $this->trip_post_type_capabilities(),
                'menu_icon'          => 'dashicons-building',
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
        add_meta_box('terricel_trip_organization_details', __('Organization Details', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), array($this, 'render_organization_details_meta_box'), self::ORGANIZATION_POST_TYPE, 'normal', 'high');
        add_meta_box('terricel_bus_trip_eligibility', __('Trip Eligibility', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), array($this, 'render_bus_trip_eligibility_meta_box'), Terricel_Logistics_Shared_Data::BUS_POST_TYPE, 'side');
    }

    public function ensure_other_organization() {
        if (!current_user_can(Terricel_Transit_Trips_Plugin::CAP_MANAGE_TRIPS)) {
            return;
        }

        $existing = get_posts(
            array(
                'post_type'      => self::ORGANIZATION_POST_TYPE,
                'post_status'    => 'publish',
                'posts_per_page' => 1,
                'title'          => 'Other',
                'fields'         => 'ids',
            )
        );

        if (!empty($existing)) {
            return;
        }

        $organization_id = wp_insert_post(
            array(
                'post_type'   => self::ORGANIZATION_POST_TYPE,
                'post_status' => 'publish',
                'post_title'  => 'Other',
            ),
            true
        );

        if (!is_wp_error($organization_id) && $organization_id > 0) {
            update_post_meta($organization_id, '_terricel_trip_organization_short_name', 'Other');
            update_post_meta($organization_id, '_terricel_trip_organization_is_other', 1);
        }
    }

    public function render_trip_details_meta_box($post) {
        wp_nonce_field('terricel_trip_meta', 'terricel_trip_meta_nonce');
        wp_nonce_field('terricel_trip_group_ajax', 'terricel_trip_group_ajax_nonce');
        $school_id = (int) get_post_meta($post->ID, '_terricel_trip_school_id', true);
        $group_id = (int) get_post_meta($post->ID, '_terricel_trip_group_id', true);
        echo '<div class="terricel-trip-details-layout">';
        echo '<div class="terricel-trip-school-row">';
        echo '<div class="terricel-trip-school-field">';
        $this->render_organization_select_field('terricel_trip_school_id', __('Organization', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $school_id, __('Select organization', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN));
        echo '</div>';
        echo '<div class="terricel-trip-group-action"><button type="button" class="button" id="terricel_trip_add_organization_toggle">' . esc_html__('Add Organization', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</button></div>';
        echo '<div class="terricel-trip-school-field">';
        $this->render_group_select_field($school_id, $group_id);
        echo '</div>';
        echo '<div class="terricel-trip-group-action"><button type="button" class="button" id="terricel_trip_add_group_toggle">' . esc_html__('Add Group', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</button></div>';
        echo '</div>';
        $this->render_inline_organization_create_panel();
        $this->render_inline_group_create_panel();
        echo '<p class="description terricel-trip-billing-note">' . esc_html__('Organizations receive the bills for group activity.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</p>';
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
            echo '<td><div class="terricel-trip-driver-cell">' . $this->get_driver_select('terricel_trip_assignments[' . $i . '][driver_id]', absint($assignment['driver_id']), $post->ID) . '<span class="terricel-trip-conflict-status" hidden></span></div></td>';
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
        echo '<tr class="terricel-trip-assignment-slot-row"><td>' . esc_html__('Bus __NUMBER__', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</td><td>' . $this->get_bus_select('terricel_trip_assignments[__INDEX__][bus_id]', 0, $post->ID) . '</td><td><div class="terricel-trip-driver-cell">' . $this->get_driver_select('terricel_trip_assignments[__INDEX__][driver_id]', 0, $post->ID) . '<span class="terricel-trip-conflict-status" hidden></span></div></td></tr>';
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
        $billing_address_1 = get_post_meta($post->ID, '_terricel_trip_group_billing_address_1', true);
        $billing_address_2 = get_post_meta($post->ID, '_terricel_trip_group_billing_address_2', true);
        $billing_city = get_post_meta($post->ID, '_terricel_trip_group_billing_city', true);
        $billing_state = get_post_meta($post->ID, '_terricel_trip_group_billing_state', true);
        $billing_zip = get_post_meta($post->ID, '_terricel_trip_group_billing_zip', true);

        echo '<div class="terricel-group-details-grid">';
        if ($school_id > 0) {
            echo '<p><label for="terricel_trip_group_school_id_locked"><strong>' . esc_html__('Organization', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</strong></label><br>';
            echo '<input class="widefat" type="text" id="terricel_trip_group_school_id_locked" value="' . esc_attr($this->get_school_label($school_id)) . '" readonly>';
            echo '<input type="hidden" name="terricel_trip_group_school_id" value="' . esc_attr($school_id) . '">';
            echo '<span class="description">' . esc_html__('This group is locked to its assigned organization and can not be changed.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</span></p>';
        } else {
            $this->render_organization_select_field('terricel_trip_group_school_id', __('Organization', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $school_id, __('Select organization', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN));
        }
        $this->render_text_field('terricel_trip_group_advisor_first_name', __('Primary Contact First Name', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $first_name, 'text');
        $this->render_text_field('terricel_trip_group_advisor_last_name', __('Primary Contact Last Name', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $last_name, 'text');
        $this->render_text_field('terricel_trip_group_advisor_main_phone', __('Main Phone', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $main_phone, 'tel');
        $this->render_text_field('terricel_trip_group_advisor_main_phone_extension', __('Extension', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $main_phone_extension, 'text', 'numeric');
        $this->render_text_field('terricel_trip_group_advisor_emergency_phone', __('Emergency Phone', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $emergency_phone, 'tel');
        $this->render_text_field('terricel_trip_group_advisor_email', __('Email', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $email, 'email', '', true);
        echo '</div>';
        echo '<h3>' . esc_html__('Billing Address', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</h3>';
        echo '<div class="terricel-group-details-grid">';
        $this->render_text_field('terricel_trip_group_billing_address_1', __('Address 1', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $billing_address_1, 'text');
        $this->render_text_field('terricel_trip_group_billing_address_2', __('Address 2', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $billing_address_2, 'text');
        $this->render_text_field('terricel_trip_group_billing_city', __('City', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $billing_city, 'text');
        $this->render_text_field('terricel_trip_group_billing_state', __('State', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $billing_state, 'text');
        $this->render_text_field('terricel_trip_group_billing_zip', __('ZIP', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $billing_zip, 'text');
        echo '</div>';
        echo '<p class="description">' . esc_html__('Organizations receive the bills for group activity.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</p>';
    }

    public function render_organization_details_meta_box($post) {
        wp_nonce_field('terricel_trip_organization_meta', 'terricel_trip_organization_meta_nonce');
        $short_name = get_post_meta($post->ID, '_terricel_trip_organization_short_name', true);
        $address_1 = get_post_meta($post->ID, '_terricel_trip_organization_address_1', true);
        $address_2 = get_post_meta($post->ID, '_terricel_trip_organization_address_2', true);
        $city = get_post_meta($post->ID, '_terricel_trip_organization_city', true);
        $state = get_post_meta($post->ID, '_terricel_trip_organization_state', true);
        $zip = get_post_meta($post->ID, '_terricel_trip_organization_zip', true);
        $phone = get_post_meta($post->ID, '_terricel_trip_organization_phone', true);
        $email = get_post_meta($post->ID, '_terricel_trip_organization_email', true);

        echo '<div class="terricel-group-details-grid">';
        $this->render_text_field('terricel_trip_organization_short_name', __('Short Name / Nickname', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $short_name, 'text');
        $this->render_text_field('terricel_trip_organization_address_1', __('Address 1', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $address_1, 'text');
        $this->render_text_field('terricel_trip_organization_address_2', __('Address 2', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $address_2, 'text');
        $this->render_text_field('terricel_trip_organization_city', __('City', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $city, 'text');
        $this->render_text_field('terricel_trip_organization_state', __('State', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $state, 'text');
        $this->render_text_field('terricel_trip_organization_zip', __('ZIP', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $zip, 'text');
        $this->render_text_field('terricel_trip_organization_phone', __('Main Phone', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $phone, 'tel');
        $this->render_text_field('terricel_trip_organization_email', __('Email', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $email, 'email', '', !$this->is_other_organization($post->ID));
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
        if (!$this->is_valid_trip_organization($school_id)) {
            $school_id = 0;
        }
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
        $this->maybe_refresh_trip_invoice_pdf($post_id, true);
    }

    public function save_group_meta($post_id, $post) {
        if (!$this->can_save($post_id, 'terricel_trip_group_meta_nonce', 'terricel_trip_group_meta')) {
            return;
        }

        $existing_organization_id = absint(get_post_meta($post_id, '_terricel_trip_group_school_id', true));
        if ($existing_organization_id > 0) {
            $organization_id = $existing_organization_id;
        } else {
            $organization_id = absint($_POST['terricel_trip_group_school_id'] ?? 0);
            $organization_id = $this->is_valid_trip_organization($organization_id) ? $organization_id : 0;
        }
        update_post_meta($post_id, '_terricel_trip_group_school_id', $organization_id);
        update_post_meta($post_id, '_terricel_trip_group_advisor_first_name', $this->sanitize_person_name($_POST['terricel_trip_group_advisor_first_name'] ?? ''));
        update_post_meta($post_id, '_terricel_trip_group_advisor_last_name', $this->sanitize_person_name($_POST['terricel_trip_group_advisor_last_name'] ?? ''));
        update_post_meta($post_id, '_terricel_trip_group_advisor_main_phone', $this->sanitize_phone($_POST['terricel_trip_group_advisor_main_phone'] ?? ''));
        update_post_meta($post_id, '_terricel_trip_group_advisor_main_phone_extension', $this->sanitize_extension($_POST['terricel_trip_group_advisor_main_phone_extension'] ?? ''));
        update_post_meta($post_id, '_terricel_trip_group_advisor_emergency_phone', $this->sanitize_phone($_POST['terricel_trip_group_advisor_emergency_phone'] ?? ''));
        $email = sanitize_email(wp_unslash($_POST['terricel_trip_group_advisor_email'] ?? ''));
        update_post_meta($post_id, '_terricel_trip_group_advisor_email', $email);
        update_post_meta($post_id, '_terricel_trip_group_billing_address_1', sanitize_text_field(wp_unslash($_POST['terricel_trip_group_billing_address_1'] ?? '')));
        update_post_meta($post_id, '_terricel_trip_group_billing_address_2', sanitize_text_field(wp_unslash($_POST['terricel_trip_group_billing_address_2'] ?? '')));
        update_post_meta($post_id, '_terricel_trip_group_billing_city', sanitize_text_field(wp_unslash($_POST['terricel_trip_group_billing_city'] ?? '')));
        update_post_meta($post_id, '_terricel_trip_group_billing_state', sanitize_text_field(wp_unslash($_POST['terricel_trip_group_billing_state'] ?? '')));
        update_post_meta($post_id, '_terricel_trip_group_billing_zip', sanitize_text_field(wp_unslash($_POST['terricel_trip_group_billing_zip'] ?? '')));
        $this->maybe_flag_required_email_missing($post_id, $email, 'terricel-trip-group-email-required');
    }

    public function save_organization_meta($post_id, $post) {
        if (!$this->can_save($post_id, 'terricel_trip_organization_meta_nonce', 'terricel_trip_organization_meta')) {
            return;
        }

        update_post_meta($post_id, '_terricel_trip_organization_short_name', sanitize_text_field(wp_unslash($_POST['terricel_trip_organization_short_name'] ?? '')));
        update_post_meta($post_id, '_terricel_trip_organization_address_1', sanitize_text_field(wp_unslash($_POST['terricel_trip_organization_address_1'] ?? '')));
        update_post_meta($post_id, '_terricel_trip_organization_address_2', sanitize_text_field(wp_unslash($_POST['terricel_trip_organization_address_2'] ?? '')));
        update_post_meta($post_id, '_terricel_trip_organization_city', sanitize_text_field(wp_unslash($_POST['terricel_trip_organization_city'] ?? '')));
        update_post_meta($post_id, '_terricel_trip_organization_state', sanitize_text_field(wp_unslash($_POST['terricel_trip_organization_state'] ?? '')));
        update_post_meta($post_id, '_terricel_trip_organization_zip', sanitize_text_field(wp_unslash($_POST['terricel_trip_organization_zip'] ?? '')));
        update_post_meta($post_id, '_terricel_trip_organization_phone', $this->sanitize_phone($_POST['terricel_trip_organization_phone'] ?? ''));
        $email = sanitize_email(wp_unslash($_POST['terricel_trip_organization_email'] ?? ''));
        update_post_meta($post_id, '_terricel_trip_organization_email', $email);
        if (!$this->is_other_organization($post_id)) {
            $this->maybe_flag_required_email_missing($post_id, $email, 'terricel-trip-organization-email-required');
        }
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

        foreach (array('terricel-trip-group-email-required', 'terricel-trip-organization-email-required') as $notice_key) {
            if ((int) get_transient($notice_key . '_' . get_current_user_id()) === absint($post_id)) {
                delete_transient($notice_key . '_' . get_current_user_id());
                $location = add_query_arg($notice_key, 1, $location);
            }
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

        if (!empty($_GET['terricel-trip-group-email-required'])) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Groups require an email address before they can be published.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</p></div>';
        }

        if (!empty($_GET['terricel-trip-organization-email-required'])) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Organizations require an email address before they can be published.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</p></div>';
        }

    }

    public function render_billing_page() {
        if (!current_user_can(Terricel_Transit_Trips_Plugin::CAP_MANAGE_TRIPS)) {
            wp_die(esc_html__('You do not have permission to manage trip billing.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN));
        }

        $filters = $this->get_billing_request_filters();
        $counts = $this->get_billing_status_counts();
        $trips = $this->get_billing_trips($filters);
        foreach ($trips as $trip) {
            $this->maybe_refresh_trip_invoice_pdf(absint($trip->ID));
        }

        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('Trip Billing', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</h1>';
        echo '<style>.terricel-billing-filter-form{clear:both;margin:12px 0 10px;display:flex;gap:8px;align-items:center;flex-wrap:wrap}.terricel-billing-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.terricel-inline-action-form{display:inline-flex;margin:0}.terricel-billing-actions .button-link-delete{color:#b32d2e;text-decoration:none;border:0;background:transparent;padding:0;cursor:pointer}.terricel-billing-actions .button-link-delete:hover{text-decoration:underline}.terricel-button-danger{background:#b32d2e!important;border-color:#8a2424!important;color:#fff!important}.terricel-billing-sort-header{display:inline-flex;gap:6px;align-items:center}.terricel-billing-sort-controls{display:inline-flex;gap:2px}.terricel-billing-sort-controls a{text-decoration:none;color:#646970;font-size:14px;line-height:1}.terricel-billing-sort-controls a.current{color:#2271b1;font-weight:700}</style>';

        if (!empty($_GET['terricel-trip-invoice-sent'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Invoice PDF was emailed and stored on the trip record.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</p></div>';
        }
        if (!empty($_GET['terricel-trip-invoice-missing-mileage'])) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('This trip can not be invoiced because mileage is missing.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</p></div>';
        }
        if (!empty($_GET['terricel-trip-invoice-missing-recipient'])) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('This trip can not be invoiced because the billing email is missing.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</p></div>';
        }
        if (!empty($_GET['terricel-trip-invoice-failed'])) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('The invoice could not be sent. Please check the site mail configuration and try again.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</p></div>';
        }
        if (!empty($_GET['terricel-trip-invoice-voided'])) {
            echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__('Invoice was voided.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</p></div>';
        }
        if (!empty($_GET['terricel-trip-invoice-cancel-confirmation-missing'])) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Invoice was not canceled. Type CANCEL to confirm invoice cancellation.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</p></div>';
        }

        $this->render_billing_status_views($filters['status'], $counts);
        $this->render_billing_filters($filters);

        if (empty($trips)) {
            echo '<div class="notice notice-info inline"><p>' . esc_html__('No past trips match these billing filters.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</p></div>';
            echo '</div>';
            return;
        }

        echo '<table class="widefat striped"><thead><tr>';
        echo $this->get_billing_sort_arrows_header('title', __('Trip', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $filters);
        echo $this->get_billing_sort_arrows_header('pickup', __('Pickup', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $filters);
        echo '<th>' . esc_html__('Bill To', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</th>';
        echo '<th>' . esc_html__('Billable Hours', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</th>';
        echo '<th>' . esc_html__('Mileage', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</th>';
        echo '<th>' . esc_html__('Download Invoice', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</th>';
        echo '<th>' . esc_html__('Actions', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</th>';
        echo '</tr></thead><tbody>';

        foreach ($trips as $trip) {
            $trip_id = absint($trip->ID);
            $recipient = $this->get_trip_billing_recipient($trip_id);
            $totals = $this->get_trip_billing_totals($trip_id);
            echo '<tr>';
            echo '<td><a href="' . esc_url(get_edit_post_link($trip_id)) . '">' . esc_html(get_the_title($trip_id)) . '</a></td>';
            echo '<td>' . esc_html($this->format_trip_pickup($trip_id)) . '</td>';
            echo '<td>' . esc_html($recipient['name']) . '<br><a href="mailto:' . esc_attr($recipient['email']) . '">' . esc_html($recipient['email']) . '</a></td>';
            echo '<td>' . esc_html($totals['hours_label']) . '</td>';
            echo '<td>' . esc_html($totals['mileage_label']) . '</td>';
            echo '<td>' . wp_kses_post($this->get_invoice_download_link($trip_id)) . '</td>';
            echo '<td class="terricel-billing-actions">';
            if ($totals['missing_mileage']) {
                echo '<a class="button button-primary terricel-button-danger" href="' . esc_url($this->get_trip_actuals_update_url($trip_id)) . '">' . esc_html__('Update Mileage', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</a>';
                if ('voided' !== $this->get_trip_invoice_status($trip_id)) {
                    echo $this->get_invoice_void_form($trip_id);
                }
            } else {
                echo $this->get_invoice_view_form($trip_id);
                echo $this->get_invoice_email_form($trip_id);
                if ('voided' !== $this->get_trip_invoice_status($trip_id)) {
                    echo $this->get_invoice_void_form($trip_id);
                }
            }
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    public function handle_view_invoice() {
        if (!current_user_can(Terricel_Transit_Trips_Plugin::CAP_MANAGE_TRIPS)) {
            wp_die(esc_html__('You do not have permission to view invoices.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN));
        }

        $trip_id = absint($_GET['trip_id'] ?? 0);
        check_admin_referer('terricel_trips_view_invoice_' . $trip_id);

        $redirect = admin_url('admin.php?page=terricel-transit-trip-billing');
        if ($trip_id < 1 || self::TRIP_POST_TYPE !== get_post_type($trip_id)) {
            wp_safe_redirect(add_query_arg('terricel-trip-invoice-failed', 1, $redirect));
            exit;
        }

        $totals = $this->get_trip_billing_totals($trip_id);
        if ($totals['missing_mileage']) {
            wp_safe_redirect(add_query_arg('terricel-trip-invoice-missing-mileage', 1, $redirect));
            exit;
        }

        $attachment_id = $this->maybe_refresh_trip_invoice_pdf($trip_id);
        $url = $attachment_id ? wp_get_attachment_url($attachment_id) : '';
        if (!$url) {
            wp_safe_redirect(add_query_arg('terricel-trip-invoice-failed', 1, $redirect));
            exit;
        }

        wp_safe_redirect($url);
        exit;
    }

    public function handle_send_invoice() {
        if (!current_user_can(Terricel_Transit_Trips_Plugin::CAP_MANAGE_TRIPS)) {
            wp_die(esc_html__('You do not have permission to send invoices.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN));
        }

        $trip_id = absint($_POST['trip_id'] ?? 0);
        check_admin_referer('terricel_trips_send_invoice_' . $trip_id);

        $redirect = admin_url('admin.php?page=terricel-transit-trip-billing');
        $success_redirect = admin_url('admin.php?page=terricel-transit-trip-billing&billing_status=invoiced');
        if ($trip_id < 1 || self::TRIP_POST_TYPE !== get_post_type($trip_id)) {
            wp_safe_redirect(add_query_arg('terricel-trip-invoice-failed', 1, $redirect));
            exit;
        }

        $totals = $this->get_trip_billing_totals($trip_id);
        if ($totals['missing_mileage']) {
            wp_safe_redirect(add_query_arg('terricel-trip-invoice-missing-mileage', 1, $redirect));
            exit;
        }

        $recipient = $this->get_trip_billing_recipient($trip_id);
        if (empty($recipient['email']) || !is_email($recipient['email'])) {
            wp_safe_redirect(add_query_arg('terricel-trip-invoice-missing-recipient', 1, $redirect));
            exit;
        }

        $attachment_id = $this->maybe_refresh_trip_invoice_pdf($trip_id);
        if ($attachment_id < 1) {
            wp_safe_redirect(add_query_arg('terricel-trip-invoice-failed', 1, $redirect));
            exit;
        }

        $pdf_path = get_attached_file($attachment_id);
        $message = $this->render_trip_email_template(Terricel_Transit_Trips_Plugin::OPTION_EMAIL_INVOICE, $trip_id, $recipient, $totals);
        $sent = wp_mail(
            $recipient['email'],
            sprintf(__('Invoice for %s', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), get_the_title($trip_id)),
            $message,
            array('Content-Type: text/plain; charset=UTF-8'),
            $pdf_path && file_exists($pdf_path) ? array($pdf_path) : array()
        );

        if (!$sent) {
            wp_delete_attachment($attachment_id, true);
            wp_safe_redirect(add_query_arg('terricel-trip-invoice-failed', 1, $redirect));
            exit;
        }

        update_post_meta($trip_id, '_terricel_trip_invoice_attachment_ids', array($attachment_id));
        update_post_meta($trip_id, '_terricel_trip_invoice_sent_at', current_time('mysql'));
        update_post_meta($trip_id, '_terricel_trip_invoice_recipient_email', $recipient['email']);
        update_post_meta($trip_id, '_terricel_trip_invoice_status', 'invoiced');

        wp_safe_redirect(add_query_arg('terricel-trip-invoice-sent', 1, $success_redirect));
        exit;
    }

    public function handle_void_invoice() {
        if (!current_user_can(Terricel_Transit_Trips_Plugin::CAP_MANAGE_TRIPS)) {
            wp_die(esc_html__('You do not have permission to void invoices.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN));
        }

        $trip_id = absint($_POST['trip_id'] ?? 0);
        check_admin_referer('terricel_trips_void_invoice_' . $trip_id);

        $redirect = admin_url('admin.php?page=terricel-transit-trip-billing&billing_status=voided');
        $confirmation = isset($_POST['cancel_confirmation']) ? sanitize_text_field(wp_unslash($_POST['cancel_confirmation'])) : '';
        if ('CANCEL' !== $confirmation) {
            wp_safe_redirect(add_query_arg('terricel-trip-invoice-cancel-confirmation-missing', 1, $redirect));
            exit;
        }

        if ($trip_id < 1 || self::TRIP_POST_TYPE !== get_post_type($trip_id)) {
            wp_safe_redirect(add_query_arg('terricel-trip-invoice-failed', 1, $redirect));
            exit;
        }

        update_post_meta($trip_id, '_terricel_trip_invoice_status', 'voided');
        update_post_meta($trip_id, '_terricel_trip_invoice_voided_at', current_time('mysql'));

        wp_safe_redirect(add_query_arg('terricel-trip-invoice-voided', 1, $redirect));
        exit;
    }

    public function handle_download_trip_sheet() {
        if (!current_user_can(Terricel_Transit_Trips_Plugin::CAP_MANAGE_TRIPS)) {
            wp_die(esc_html__('You do not have permission to download trip sheets.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN));
        }

        $trip_id = absint($_GET['trip_id'] ?? 0);
        check_admin_referer('terricel_trips_download_trip_sheet_' . $trip_id);

        if ($trip_id < 1 || self::TRIP_POST_TYPE !== get_post_type($trip_id)) {
            wp_die(esc_html__('Trip not found.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN));
        }

        $pdf = $this->build_trip_sheet_pdf($trip_id);
        if ('' === $pdf) {
            wp_die(esc_html__('The trip sheet PDF could not be generated.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN));
        }

        nocache_headers();
        header('Content-Type: application/pdf');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name('trip-sheet-' . $trip_id . '.pdf') . '"');
        header('Content-Length: ' . strlen($pdf));
        echo $pdf;
        exit;
    }

    public function prevent_deleting_records_with_past_trips($post_id) {
        $post_type = get_post_type($post_id);
        if (!in_array($post_type, array(self::GROUP_POST_TYPE, self::ORGANIZATION_POST_TYPE, Terricel_Logistics_Shared_Data::SCHOOL_POST_TYPE), true)) {
            return;
        }

        if (!$this->record_has_past_trips($post_id, $post_type)) {
            return;
        }

        wp_die(
            esc_html__('This record can not be deleted because it is associated with one or more past trips.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
            esc_html__('Past Trips Found', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
            array('back_link' => true)
        );
    }

    private function record_has_past_trips($post_id, $post_type) {
        $meta_key = self::GROUP_POST_TYPE === $post_type ? '_terricel_trip_group_id' : '_terricel_trip_school_id';

        $past_trips = get_posts(
            array(
                'post_type'      => self::TRIP_POST_TYPE,
                'post_status'    => array('publish', 'draft', 'pending', 'future', 'private', 'cancelled'),
                'posts_per_page' => 1,
                'fields'         => 'ids',
                'meta_query'     => array(
                    array(
                        'key'     => $meta_key,
                        'value'   => absint($post_id),
                        'compare' => '=',
                        'type'    => 'NUMERIC',
                    ),
                    array(
                        'key'     => '_terricel_trip_pickup_date',
                        'value'   => current_time('Y-m-d'),
                        'compare' => '<',
                        'type'    => 'DATE',
                    ),
                ),
            )
        );

        return !empty($past_trips);
    }

    private function get_billing_request_filters() {
        $status = isset($_GET['billing_status']) ? sanitize_key(wp_unslash($_GET['billing_status'])) : 'non_invoiced';
        if (!in_array($status, array('non_invoiced', 'invoiced', 'voided'), true)) {
            $status = 'non_invoiced';
        }

        $orderby = isset($_GET['orderby']) ? sanitize_key(wp_unslash($_GET['orderby'])) : 'pickup';
        if (!in_array($orderby, array('title', 'pickup'), true)) {
            $orderby = 'pickup';
        }

        $order = isset($_GET['order']) ? strtoupper(sanitize_key(wp_unslash($_GET['order']))) : 'DESC';
        $order = 'ASC' === $order ? 'ASC' : 'DESC';

        return array(
            'status'          => $status,
            'search'          => sanitize_text_field(wp_unslash($_GET['s'] ?? '')),
            'organization_id' => absint($_GET['billing_organization_id'] ?? 0),
            'group_id'        => absint($_GET['billing_group_id'] ?? 0),
            'orderby'         => $orderby,
            'order'           => $order,
        );
    }

    private function get_billing_trips($filters = array()) {
        $filters = wp_parse_args(
            is_array($filters) ? $filters : array(),
            array(
                'status'          => 'non_invoiced',
                'search'          => '',
                'organization_id' => 0,
                'group_id'        => 0,
                'orderby'         => 'pickup',
                'order'           => 'DESC',
            )
        );

        $meta_query = array(
            array(
                'key'     => '_terricel_trip_pickup_date',
                'value'   => current_time('Y-m-d'),
                'compare' => '<=',
                'type'    => 'DATE',
            ),
        );

        if ($filters['organization_id'] > 0) {
            $meta_query[] = array(
                'key'     => '_terricel_trip_school_id',
                'value'   => absint($filters['organization_id']),
                'compare' => '=',
                'type'    => 'NUMERIC',
            );
        }

        if ($filters['group_id'] > 0) {
            $meta_query[] = array(
                'key'     => '_terricel_trip_group_id',
                'value'   => absint($filters['group_id']),
                'compare' => '=',
                'type'    => 'NUMERIC',
            );
        }

        $trips = get_posts(
            array(
                'post_type'      => self::TRIP_POST_TYPE,
                'post_status'    => 'publish',
                'posts_per_page' => 1000,
                'orderby'        => 'meta_value',
                'order'          => 'DESC',
                'meta_key'       => '_terricel_trip_pickup_date',
                'meta_query'     => $meta_query,
            )
        );

        $search = strtolower(trim((string) $filters['search']));
        $trips = array_values(
            array_filter(
                $trips,
                function($trip) use ($filters, $search) {
                    $trip_id = absint($trip->ID);
                    if (!$this->trip_has_ended_for_billing($trip_id)) {
                        return false;
                    }

                    if ($this->get_trip_invoice_status($trip_id) !== $filters['status']) {
                        return false;
                    }

                    if ('' === $search) {
                        return true;
                    }

                    $haystack = strtolower(
                        implode(
                            ' ',
                            array(
                                get_the_title($trip_id),
                                $this->format_trip_pickup($trip_id),
                                $this->get_trip_destination_label($trip_id),
                                $this->get_school_label(absint(get_post_meta($trip_id, '_terricel_trip_school_id', true))),
                                $this->get_trip_group_name($trip_id),
                                $this->get_trip_primary_contact_name($trip_id),
                            )
                        )
                    );

                    return false !== strpos($haystack, $search);
                }
            )
        );

        usort(
            $trips,
            function($first, $second) use ($filters) {
                $first_value = $this->get_billing_sort_value($first->ID, $filters['orderby']);
                $second_value = $this->get_billing_sort_value($second->ID, $filters['orderby']);
                $result = is_numeric($first_value) && is_numeric($second_value)
                    ? ((float) $first_value <=> (float) $second_value)
                    : strnatcasecmp((string) $first_value, (string) $second_value);

                return 'ASC' === $filters['order'] ? $result : -$result;
            }
        );

        return $trips;
    }

    private function get_billing_sort_value($trip_id, $orderby) {
        if ('title' === $orderby) {
            return get_the_title($trip_id);
        }

        $pickup_timestamp = $this->get_trip_pickup_timestamp($trip_id);
        return $pickup_timestamp ? $pickup_timestamp : 0;
    }

    private function trip_has_ended_for_billing($trip_id) {
        $return_timestamp = $this->get_trip_return_timestamp($trip_id);
        if (!$return_timestamp) {
            return false;
        }

        return $return_timestamp <= current_time('timestamp');
    }

    private function get_billing_status_counts() {
        $counts = array('non_invoiced' => 0, 'invoiced' => 0, 'voided' => 0);
        foreach ($this->get_billing_trips(array('status' => 'non_invoiced')) as $trip) {
            $counts['non_invoiced']++;
        }
        foreach ($this->get_billing_trips(array('status' => 'invoiced')) as $trip) {
            $counts['invoiced']++;
        }
        foreach ($this->get_billing_trips(array('status' => 'voided')) as $trip) {
            $counts['voided']++;
        }

        return $counts;
    }

    private function render_billing_status_views($current_status, $counts) {
        $views = array(
            'non_invoiced' => __('Non Invoiced', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
            'invoiced'     => __('Invoiced', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
            'voided'       => __('Voided', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
        );
        $links = array();
        foreach ($views as $status => $label) {
            $url = add_query_arg(array('page' => 'terricel-transit-trip-billing', 'billing_status' => $status), admin_url('admin.php'));
            $links[] = sprintf(
                '<li><a href="%1$s"%2$s>%3$s <span class="count">(%4$s)</span></a></li>',
                esc_url($url),
                $current_status === $status ? ' class="current" aria-current="page"' : '',
                esc_html($label),
                esc_html(number_format_i18n(absint($counts[$status] ?? 0)))
            );
        }

        echo '<ul class="subsubsub">' . wp_kses_post(implode(' | ', $links)) . '</ul>';
    }

    private function render_billing_filters($filters) {
        echo '<form method="get" class="terricel-billing-filter-form">';
        echo '<input type="hidden" name="page" value="terricel-transit-trip-billing">';
        echo '<input type="hidden" name="billing_status" value="' . esc_attr($filters['status']) . '">';
        echo '<input type="search" name="s" value="' . esc_attr($filters['search']) . '" placeholder="' . esc_attr__('Search trips', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '"> ';
        echo '<select name="billing_organization_id"><option value="0">' . esc_html__('All organizations', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</option>';
        foreach ($this->get_organizations_for_select() as $organization) {
            echo '<option value="' . esc_attr($organization->ID) . '"' . selected($filters['organization_id'], $organization->ID, false) . '>' . esc_html($this->get_organization_label($organization->ID)) . '</option>';
        }
        echo '</select> ';
        echo '<select name="billing_group_id"><option value="0">' . esc_html__('All groups', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</option>';
        foreach ($this->get_posts_for_select(self::GROUP_POST_TYPE) as $group) {
            echo '<option value="' . esc_attr($group->ID) . '"' . selected($filters['group_id'], $group->ID, false) . '>' . esc_html(get_the_title($group->ID)) . '</option>';
        }
        echo '</select> ';
        submit_button(__('Filter', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), 'secondary', 'filter_action', false);
        echo '</form>';
    }

    private function get_billing_sort_arrows_header($orderby, $label, $filters) {
        $ascending_url = $this->get_billing_sort_url($orderby, 'ASC', $filters);
        $descending_url = $this->get_billing_sort_url($orderby, 'DESC', $filters);
        $ascending_class = $filters['orderby'] === $orderby && 'ASC' === $filters['order'] ? ' class="current" aria-current="true"' : '';
        $descending_class = $filters['orderby'] === $orderby && 'DESC' === $filters['order'] ? ' class="current" aria-current="true"' : '';

        return '<th scope="col"><span class="terricel-billing-sort-header"><span>' . esc_html($label) . '</span><span class="terricel-billing-sort-controls"><a href="' . esc_url($ascending_url) . '"' . $ascending_class . ' title="' . esc_attr__('Sort ascending', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '">&uarr;</a><a href="' . esc_url($descending_url) . '"' . $descending_class . ' title="' . esc_attr__('Sort descending', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '">&darr;</a></span></span></th>';
    }

    private function get_billing_sort_url($orderby, $order, $filters) {
        return add_query_arg(
            array(
                'page'                    => 'terricel-transit-trip-billing',
                'billing_status'          => $filters['status'],
                's'                       => $filters['search'],
                'billing_organization_id' => $filters['organization_id'],
                'billing_group_id'        => $filters['group_id'],
                'orderby'                 => $orderby,
                'order'                   => $order,
            ),
            admin_url('admin.php')
        );
    }

    private function get_trip_billing_recipient($trip_id) {
        $organization_id = absint(get_post_meta($trip_id, '_terricel_trip_school_id', true));
        $group_id = absint(get_post_meta($trip_id, '_terricel_trip_group_id', true));
        $use_group = $this->is_other_organization($organization_id);

        if ($use_group && $group_id > 0) {
            return array(
                'name'      => get_the_title($group_id),
                'email'     => sanitize_email(get_post_meta($group_id, '_terricel_trip_group_advisor_email', true)),
                'contact'   => $this->get_group_advisor_name($group_id),
                'address'   => $this->get_group_billing_address($group_id),
                'source'    => 'group',
                'source_id' => $group_id,
            );
        }

        return array(
            'name'      => $this->get_school_label($organization_id),
            'email'     => $this->get_organization_billing_email($organization_id),
            'contact'   => $this->get_school_label($organization_id),
            'address'   => $this->get_school_origin_address($organization_id),
            'source'    => 'organization',
            'source_id' => $organization_id,
        );
    }

    private function get_trip_billing_totals($trip_id) {
        $assignments = $this->get_trip_assignments($trip_id);
        $actuals = $this->get_trip_actuals($trip_id);
        $slot_count = max(1, count($assignments), count($actuals));
        $hours_total = 0.0;
        $mileage_total = 0.0;
        $missing_hours = false;
        $missing_mileage = false;

        for ($index = 0; $index < $slot_count; $index++) {
            $row = isset($actuals[$index]) && is_array($actuals[$index]) ? $actuals[$index] : array();
            $hours = $this->get_billable_hours_for_row($trip_id, $row);
            if (null === $hours) {
                $missing_hours = true;
            } else {
                $hours_total += $hours;
            }

            $mileage = $this->get_billable_mileage_for_row($row);
            if (null === $mileage) {
                $missing_mileage = true;
            } else {
                $mileage_total += $mileage;
            }
        }

        return array(
            'hours'           => round($hours_total, 2),
            'mileage'         => round($mileage_total, 1),
            'hours_label'     => $missing_hours ? __('Missing time', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) : number_format_i18n($hours_total, 2),
            'mileage_label'   => $missing_mileage ? __('Missing mileage', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) : number_format_i18n($mileage_total, 1),
            'missing_hours'   => $missing_hours,
            'missing_mileage' => $missing_mileage,
        );
    }

    private function get_billable_hours_for_row($trip_id, $row) {
        $start_mode = get_option(Terricel_Transit_Trips_Plugin::OPTION_BILLABLE_HOURS_START, 'left_garage');
        $end_mode = get_option(Terricel_Transit_Trips_Plugin::OPTION_BILLABLE_HOURS_END, 'back_garage');
        $pickup_date = get_post_meta($trip_id, '_terricel_trip_pickup_date', true);
        $return_date = get_post_meta($trip_id, '_terricel_trip_return_date', true) ?: $pickup_date;

        $start_time = 'pickup' === $start_mode ? get_post_meta($trip_id, '_terricel_trip_pickup_time', true) : ($row['left_yard_time'] ?? '');
        $end_time = 'returned' === $end_mode ? get_post_meta($trip_id, '_terricel_trip_return_time', true) : ($row['post_trip_time'] ?? '');

        if (!$this->sanitize_time($start_time) || !$this->sanitize_time($end_time) || !$this->sanitize_date($pickup_date) || !$this->sanitize_date($return_date)) {
            return null;
        }

        $start_timestamp = strtotime($pickup_date . ' ' . $start_time);
        $end_timestamp = strtotime($return_date . ' ' . $end_time);
        if (!$start_timestamp || !$end_timestamp) {
            return null;
        }

        while ($end_timestamp < $start_timestamp) {
            $end_timestamp += DAY_IN_SECONDS;
        }

        return max(0, ($end_timestamp - $start_timestamp) / HOUR_IN_SECONDS);
    }

    private function get_billable_mileage_for_row($row) {
        $start_mode = get_option(Terricel_Transit_Trips_Plugin::OPTION_BILLABLE_MILEAGE_START, 'left_garage');
        $end_mode = get_option(Terricel_Transit_Trips_Plugin::OPTION_BILLABLE_MILEAGE_END, 'back_garage');
        $start_key = 'pickup' === $start_mode ? 'departed_mileage' : 'pre_trip_mileage';
        $end_key = 'returned' === $end_mode ? 'returning_mileage' : 'post_trip_mileage';
        $start = isset($row[$start_key]) ? $this->sanitize_decimal($row[$start_key]) : '';
        $end = isset($row[$end_key]) ? $this->sanitize_decimal($row[$end_key]) : '';

        if ('' === $start || '' === $end) {
            return null;
        }

        return max(0, (float) $end - (float) $start);
    }

    private function maybe_refresh_trip_invoice_pdf($trip_id, $force = false) {
        $trip_id = absint($trip_id);
        if ($trip_id < 1 || self::TRIP_POST_TYPE !== get_post_type($trip_id) || 'publish' !== get_post_status($trip_id) || 'voided' === $this->get_trip_invoice_status($trip_id)) {
            return 0;
        }

        if (!$this->trip_has_ended_for_billing($trip_id)) {
            return 0;
        }

        $totals = $this->get_trip_billing_totals($trip_id);
        if ($totals['missing_mileage']) {
            return 0;
        }

        $current_attachment_id = $this->get_current_trip_invoice_attachment_id($trip_id);
        if (!$force && $current_attachment_id > 0 && wp_get_attachment_url($current_attachment_id)) {
            return $current_attachment_id;
        }

        $invoice_number = $this->get_trip_invoice_number($trip_id);
        $version = max(1, absint(get_post_meta($trip_id, '_terricel_trip_invoice_version', true)));
        if ($current_attachment_id > 0) {
            $version++;
        }

        $modified_at = current_time('mysql');
        $attachment_id = $this->create_trip_invoice_pdf_attachment($trip_id, $this->get_trip_billing_recipient($trip_id), $totals, $version, $invoice_number, $modified_at);
        if ($attachment_id < 1) {
            return 0;
        }

        if ($current_attachment_id > 0 && $current_attachment_id !== $attachment_id) {
            wp_delete_attachment($current_attachment_id, true);
        }

        update_post_meta($trip_id, '_terricel_trip_invoice_attachment_id', $attachment_id);
        update_post_meta($trip_id, '_terricel_trip_invoice_attachment_ids', array($attachment_id));
        update_post_meta($trip_id, '_terricel_trip_invoice_number', $invoice_number);
        update_post_meta($trip_id, '_terricel_trip_invoice_version', $version);
        update_post_meta($trip_id, '_terricel_trip_invoice_modified_at', $modified_at);

        return $attachment_id;
    }

    private function create_trip_invoice_pdf_attachment($trip_id, $recipient, $totals, $version = 1, $invoice_number = '', $modified_at = '') {
        $invoice_number = $invoice_number ? $invoice_number : $this->get_trip_invoice_number($trip_id);
        $version = max(1, absint($version));
        $modified_at = $modified_at ? $modified_at : current_time('mysql');
        $pdf = $this->build_trip_invoice_pdf($trip_id, $recipient, $totals, $version, $invoice_number, $modified_at);
        if ('' === $pdf) {
            return 0;
        }

        $filename = sanitize_file_name('invoice-' . $invoice_number . '-V' . $version . '.pdf');
        $upload = wp_upload_bits($filename, null, $pdf);
        if (!empty($upload['error']) || empty($upload['file'])) {
            return 0;
        }

        $attachment_id = wp_insert_attachment(
            array(
                'post_mime_type' => 'application/pdf',
                'post_title'     => preg_replace('/\.pdf$/', '', $filename),
                'post_content'   => '',
                'post_status'    => 'inherit',
            ),
            $upload['file'],
            $trip_id
        );

        return is_wp_error($attachment_id) ? 0 : absint($attachment_id);
    }

    private function build_trip_invoice_pdf($trip_id, $recipient, $totals, $version = 1, $invoice_number = '', $modified_at = '') {
        $invoice_number = $invoice_number ? $invoice_number : $this->get_trip_invoice_number($trip_id);
        $modified_at = $modified_at ? $modified_at : (get_post_meta($trip_id, '_terricel_trip_invoice_modified_at', true) ?: current_time('mysql'));
        $lines = array(
            get_bloginfo('name'),
            __('Trip Invoice', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
            sprintf(__('Invoice #: %s', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $invoice_number),
            sprintf(__('Version: V%s', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), max(1, absint($version))),
            sprintf(__('Modified: %s', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), date_i18n(get_option('date_format') . ' ' . get_option('time_format'), strtotime($modified_at))),
            sprintf(__('Trip: %s', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), get_the_title($trip_id)),
            sprintf(__('Date: %s', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $this->format_trip_pickup($trip_id)),
            sprintf(__('Bill To: %s', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $recipient['name']),
            sprintf(__('Billing Email: %s', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $recipient['email']),
            sprintf(__('Billing Address: %s', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $recipient['address']),
            sprintf(__('Group: %s', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $this->get_trip_group_name($trip_id)),
            sprintf(__('Primary Contact: %s', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $this->get_trip_primary_contact_name($trip_id)),
            sprintf(__('Location: %s', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $this->get_trip_destination_label($trip_id)),
            sprintf(__('Driver(s): %s', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $this->get_trip_driver_names($trip_id)),
            sprintf(__('Billable Hours: %s', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $totals['hours_label']),
            sprintf(__('Total Mileage: %s', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $totals['mileage_label']),
            sprintf(__('Generated: %s', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), date_i18n(get_option('date_format') . ' ' . get_option('time_format'), current_time('timestamp'))),
        );

        return $this->build_simple_text_pdf($lines);
    }

    private function build_simple_text_pdf($lines) {
        $content = "BT\n/F1 12 Tf\n50 742 Td\n";
        $first = true;
        foreach ($lines as $line) {
            $wrapped = wordwrap(wp_strip_all_tags((string) $line), 72, "\n", true);
            foreach (explode("\n", $wrapped) as $wrapped_line) {
                if (!$first) {
                    $content .= "0 -18 Td\n";
                }
                $content .= '(' . $this->escape_pdf_text($wrapped_line) . ") Tj\n";
                $first = false;
            }
            $content .= "0 -8 Td\n";
        }
        $content .= "ET\n";

        $objects = array(
            1 => '<< /Type /Catalog /Pages 2 0 R >>',
            2 => '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            5 => "<< /Length " . strlen($content) . " >>\nstream\n" . $content . "endstream",
        );

        $pdf = "%PDF-1.4\n";
        $offsets = array(0);
        foreach ($objects as $id => $object) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $object . "\nendobj\n";
        }

        $xref_offset = strlen($pdf);
        $pdf .= "xref\n0 6\n0000000000 65535 f \n";
        for ($id = 1; $id <= 5; $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id]);
        }
        $pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n" . $xref_offset . "\n%%EOF";

        return $pdf;
    }

    private function build_trip_sheet_pdf($trip_id) {
        $assignments = $this->get_trip_assignments($trip_id);
        $actuals = $this->get_trip_actuals($trip_id);
        $buses_needed = absint(get_post_meta($trip_id, '_terricel_trip_buses_needed', true));
        $page_count = max(1, $buses_needed, count($assignments));
        $pages = array();

        for ($index = 0; $index < $page_count; $index++) {
            $assignment = isset($assignments[$index]) && is_array($assignments[$index]) ? $assignments[$index] : array();
            $actual = isset($actuals[$index]) && is_array($actuals[$index]) ? $actuals[$index] : array();
            $pages[] = $this->build_trip_sheet_page_content($trip_id, $index, $page_count, $assignment, $actual);
        }

        return $this->build_pdf_from_page_streams($pages);
    }

    private function build_trip_sheet_page_content($trip_id, $index, $page_count, $assignment, $actual) {
        $ops = '';
        $x = 70;
        $w = 472;
        $school_id = absint(get_post_meta($trip_id, '_terricel_trip_school_id', true));
        $group_id = absint(get_post_meta($trip_id, '_terricel_trip_group_id', true));
        $event_parts = array_filter(
            array(
                $this->get_trip_group_name($trip_id),
                sprintf(__('Primary Contact: %s', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $this->get_trip_primary_contact_name($trip_id)),
                $group_id > 0 ? sprintf(__('Emergency: %s', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), get_post_meta($group_id, '_terricel_trip_group_advisor_emergency_phone', true)) : '',
            ),
            function($part) {
                return '' !== trim(str_replace(array('Not set', 'Emergency:'), '', (string) $part));
            }
        );

        $this->pdf_rect($ops, $x, 735, $w, 30);
        $this->pdf_text($ops, 205, 745, __('Bus Field Trip Request', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), 20, true);
        $this->pdf_text($ops, 492, 745, sprintf(__('Page %1$s of %2$s', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $index + 1, $page_count), 8, false);

        $this->trip_sheet_field($ops, $x, 690, 236, 28, __('Day/Date:', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $this->format_trip_sheet_date($trip_id));
        $this->trip_sheet_field($ops, $x + 236, 690, 236, 28, __('Number of Buses Needed:', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), (string) max(1, absint(get_post_meta($trip_id, '_terricel_trip_buses_needed', true))));
        $this->trip_sheet_field($ops, $x, 645, 157, 28, __('Leave Garage:', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $this->format_time_display($actual['left_yard_time'] ?? ''));
        $this->trip_sheet_field($ops, $x + 157, 645, 158, 28, __('Leave School:', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $this->format_time_display(get_post_meta($trip_id, '_terricel_trip_pickup_time', true)));
        $this->trip_sheet_field($ops, $x + 315, 645, 157, 28, __('Time Returning:', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $this->format_time_display(get_post_meta($trip_id, '_terricel_trip_return_time', true)));
        $this->trip_sheet_field($ops, $x, 600, 236, 28, __('Pre-Trip Miles:', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $actual['pre_trip_mileage'] ?? '', true);
        $this->trip_sheet_field($ops, $x + 236, 600, 236, 28, __('Post Trip Miles:', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $actual['post_trip_mileage'] ?? '', true);
        $this->trip_sheet_large_field($ops, $x, 525, $w, 48, __('Trip Origin (School):', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $this->get_trip_sheet_origin_label($school_id));
        $this->trip_sheet_large_field($ops, $x, 477, $w, 48, __('Trip Destination:', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $this->get_trip_destination_label($trip_id));
        $this->trip_sheet_large_field($ops, $x, 429, $w, 48, __('Event:', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), implode(' | ', $event_parts));

        $this->pdf_rect($ops, $x, 380, $w, 34);
        $this->pdf_text($ops, 230, 391, __('Field Trip Log', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), 20, true);
        $this->pdf_text($ops, 95, 360, '*ATTENTION- IF GOING OUT OF STATE, DO SEPERATE MILEAGE FOR EACH STATE*', 12, true);

        $this->pdf_rect($ops, $x, 220, $w, 130);
        $this->pdf_line($ops, $x + 236, 220, $x + 236, 350);
        foreach (array(324, 298, 272, 246) as $line_y) {
            $this->pdf_line($ops, $x, $line_y, $x + $w, $line_y);
        }

        $bus_id = absint($assignment['bus_id'] ?? 0);
        $driver_id = absint($assignment['driver_id'] ?? 0);
        $this->trip_sheet_log_cell($ops, $x, 324, 236, 26, __('Bus Driver:', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $driver_id ? get_the_title($driver_id) : '');
        $this->trip_sheet_log_cell($ops, $x + 236, 324, 236, 26, __('Bus Number:', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $this->get_trip_sheet_bus_number($bus_id));
        $this->trip_sheet_log_cell($ops, $x, 298, 236, 26, __('Depart Time from School:', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $this->format_time_display(($actual['departed_time'] ?? '') ?: get_post_meta($trip_id, '_terricel_trip_pickup_time', true)));
        $this->trip_sheet_log_cell($ops, $x + 236, 298, 236, 26, __('Odometer Reading:', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $actual['departed_mileage'] ?? '');
        $this->trip_sheet_log_cell($ops, $x, 272, 236, 26, __('Destination Arrival Time:', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $this->format_time_display(($actual['arrived_time'] ?? '') ?: get_post_meta($trip_id, '_terricel_trip_arrival_time', true)));
        $this->trip_sheet_log_cell($ops, $x + 236, 272, 236, 26, __('Odometer Reading:', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $actual['arrived_mileage'] ?? '');
        $this->trip_sheet_log_cell($ops, $x, 246, 236, 26, __('Depart Time from Destination:', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $this->format_time_display(($actual['returning_time'] ?? '') ?: get_post_meta($trip_id, '_terricel_trip_departure_time', true)));
        $this->trip_sheet_log_cell($ops, $x + 236, 246, 236, 26, __('Odometer Reading:', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $actual['returning_mileage'] ?? '');
        $this->trip_sheet_log_cell($ops, $x, 220, 236, 26, __('Arrival Time at School:', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $this->format_time_display(($actual['post_trip_time'] ?? '') ?: get_post_meta($trip_id, '_terricel_trip_return_time', true)));
        $this->trip_sheet_log_cell($ops, $x + 236, 220, 236, 26, __('Odometer Reading:', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $actual['post_trip_mileage'] ?? '');

        $this->trip_sheet_large_field($ops, $x, 150, $w, 36, __('Bus Driver Signature:', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), '');
        $this->trip_sheet_check_row($ops, $x, 85, $w, __('WALK THROUGH THE BUS DIRECTLY AFTER DROPPING GROUP', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN));
        $this->trip_sheet_check_row($ops, $x, 45, $w, __('LOOK FOR ITEMS OR TRASH- IF ANY PLEASE PICK UP OR SWEEP', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN));

        return $ops;
    }

    private function build_pdf_from_page_streams($page_streams) {
        $objects = array(1 => '<< /Type /Catalog /Pages 2 0 R >>');
        $kids = array();
        $pages = array();
        $next_id = 3;
        foreach ($page_streams as $stream) {
            $page_id = $next_id++;
            $content_id = $next_id++;
            $kids[] = $page_id . ' 0 R';
            $pages[] = array('page_id' => $page_id, 'content_id' => $content_id);
            $objects[$content_id] = "<< /Length " . strlen($stream) . " >>\nstream\n" . $stream . "endstream";
        }
        $normal_font_id = $next_id++;
        $bold_font_id = $next_id++;
        foreach ($pages as $page) {
            $objects[$page['page_id']] = '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 ' . $normal_font_id . ' 0 R /F2 ' . $bold_font_id . ' 0 R >> >> /Contents ' . $page['content_id'] . ' 0 R >>';
        }
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($page_streams) . ' >>';
        $objects[$normal_font_id] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>';
        $objects[$bold_font_id] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = array(0);
        foreach ($objects as $id => $object) {
            $offsets[$id] = strlen($pdf);
            $pdf .= $id . " 0 obj\n" . $object . "\nendobj\n";
        }

        $size = max(array_keys($objects)) + 1;
        $xref_offset = strlen($pdf);
        $pdf .= "xref\n0 " . $size . "\n0000000000 65535 f \n";
        for ($id = 1; $id < $size; $id++) {
            $pdf .= sprintf("%010d 00000 n \n", $offsets[$id] ?? 0);
        }
        $pdf .= "trailer\n<< /Size " . $size . " /Root 1 0 R >>\nstartxref\n" . $xref_offset . "\n%%EOF";

        return $pdf;
    }

    private function pdf_rect(&$ops, $x, $y, $w, $h) {
        $ops .= sprintf("%.2F %.2F %.2F %.2F re S\n", $x, $y, $w, $h);
    }

    private function pdf_line(&$ops, $x1, $y1, $x2, $y2) {
        $ops .= sprintf("%.2F %.2F m %.2F %.2F l S\n", $x1, $y1, $x2, $y2);
    }

    private function pdf_text(&$ops, $x, $y, $text, $size = 10, $bold = false) {
        $ops .= 'BT ' . ($bold ? '/F2 ' : '/F1 ') . absint($size) . ' Tf ' . sprintf("%.2F %.2F Td ", $x, $y) . '(' . $this->escape_pdf_text($text) . ") Tj ET\n";
    }

    private function trip_sheet_field(&$ops, $x, $y, $w, $h, $label, $value = '', $underline = false) {
        $this->pdf_rect($ops, $x, $y, $w, $h);
        $this->pdf_text($ops, $x + 6, $y + $h - 18, $label, 12, true);
        if ('' !== (string) $value) {
            $this->pdf_text($ops, $x + min($w - 70, 112), $y + $h - 18, $value, 10, false);
        } elseif ($underline) {
            $this->pdf_line($ops, $x + 110, $y + 10, $x + $w - 34, $y + 10);
        }
    }

    private function trip_sheet_large_field(&$ops, $x, $y, $w, $h, $label, $value = '') {
        $this->pdf_rect($ops, $x, $y, $w, $h);
        $this->pdf_text($ops, $x + 6, $y + $h - 17, $label, 12, true);
        $this->pdf_wrapped_text($ops, $x + 8, $y + $h - 34, (string) $value, 9, $w - 16, 2);
    }

    private function trip_sheet_log_cell(&$ops, $x, $y, $w, $h, $label, $value = '') {
        $this->pdf_text($ops, $x + 6, $y + $h - 18, $label, 11, true);
        if ('' !== (string) $value) {
            $this->pdf_text($ops, $x + min($w - 80, 135), $y + $h - 18, $value, 9, false);
        }
    }

    private function trip_sheet_check_row(&$ops, $x, $y, $w, $label) {
        $this->pdf_rect($ops, $x, $y, $w, 36);
        $this->pdf_rect($ops, $x + 38, $y + 16, 6, 6);
        $this->pdf_text($ops, $x + 56, $y + 15, $label, 12, true);
    }

    private function pdf_wrapped_text(&$ops, $x, $y, $text, $size, $width, $max_lines = 2) {
        $text = trim(wp_strip_all_tags((string) $text));
        if ('' === $text) {
            return;
        }

        $chars = max(18, (int) floor($width / max(4, $size * 0.52)));
        $lines = array_slice(explode("\n", wordwrap($text, $chars, "\n", true)), 0, $max_lines);
        foreach ($lines as $line) {
            $this->pdf_text($ops, $x, $y, $line, $size, false);
            $y -= $size + 3;
        }
    }

    private function get_trip_sheet_download_url($trip_id) {
        return wp_nonce_url(
            add_query_arg(
                array(
                    'action'  => 'terricel_trips_download_trip_sheet',
                    'trip_id' => absint($trip_id),
                ),
                admin_url('admin-post.php')
            ),
            'terricel_trips_download_trip_sheet_' . absint($trip_id)
        );
    }

    private function format_trip_sheet_date($trip_id) {
        $date = get_post_meta($trip_id, '_terricel_trip_pickup_date', true);
        if (!$date) {
            return '';
        }

        return date_i18n('l, F j, Y', strtotime($date));
    }

    private function get_trip_sheet_bus_number($bus_id) {
        $bus_id = absint($bus_id);
        if ($bus_id < 1) {
            return '';
        }

        $number = get_post_meta($bus_id, '_terricel_bus_number', true);
        return $number ? $number : get_the_title($bus_id);
    }

    private function get_trip_sheet_origin_label($school_id) {
        $school_id = absint($school_id);
        if ($school_id < 1) {
            return '';
        }

        $label = $this->get_school_label($school_id);
        $address = $this->get_school_origin_address($school_id);
        return $address ? $label . ' - ' . $address : $label;
    }

    private function escape_pdf_text($text) {
        $text = wp_specialchars_decode((string) $text, ENT_QUOTES);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, get_bloginfo('charset') ? get_bloginfo('charset') : 'UTF-8');
        $text = str_replace(array('–', '—', '’', '“', '”'), array('-', '-', "'", '"', '"'), $text);
        $text = preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '', $text);

        return str_replace(array('\\', '(', ')'), array('\\\\', '\(', '\)'), $text);
    }

    private function render_trip_email_template($option, $trip_id, $recipient, $totals) {
        $template = get_option($option, Terricel_Transit_Trips_Plugin::default_email_template('invoice'));
        $replacements = array(
            '{organization_name}'     => $this->get_school_label(absint(get_post_meta($trip_id, '_terricel_trip_school_id', true))),
            '{group_name}'            => $this->get_trip_group_name($trip_id),
            '{primary_contact_name}'  => $this->get_trip_primary_contact_name($trip_id),
            '{location_of_trip}'      => $this->get_trip_destination_label($trip_id),
            '{date}'                  => $this->format_trip_pickup($trip_id),
            '{drivers_names}'         => $this->get_trip_driver_names($trip_id),
            '{billable_hours}'        => $totals['hours_label'],
            '{total_mileage}'         => $totals['mileage_label'],
        );

        return strtr(wp_strip_all_tags((string) $template), $replacements);
    }

    private function get_trip_invoice_attachment_ids($trip_id) {
        $current_attachment_id = $this->get_current_trip_invoice_attachment_id($trip_id);
        if ($current_attachment_id > 0) {
            return array($current_attachment_id);
        }

        $attachments = get_post_meta($trip_id, '_terricel_trip_invoice_attachment_ids', true);
        return is_array($attachments) ? array_values(array_filter(array_map('absint', $attachments))) : array();
    }

    private function get_current_trip_invoice_attachment_id($trip_id) {
        $attachment_id = absint(get_post_meta($trip_id, '_terricel_trip_invoice_attachment_id', true));
        if ($attachment_id > 0) {
            return $attachment_id;
        }

        $attachments = get_post_meta($trip_id, '_terricel_trip_invoice_attachment_ids', true);
        if (!is_array($attachments) || empty($attachments)) {
            return 0;
        }

        return absint(end($attachments));
    }

    private function get_trip_invoice_number($trip_id) {
        $invoice_number = sanitize_text_field(get_post_meta($trip_id, '_terricel_trip_invoice_number', true));
        if ($invoice_number) {
            return $invoice_number;
        }

        return 'INV-' . str_pad((string) absint($trip_id), 6, '0', STR_PAD_LEFT);
    }

    private function get_trip_invoice_status($trip_id) {
        $status = sanitize_key(get_post_meta($trip_id, '_terricel_trip_invoice_status', true));
        if ('voided' === $status) {
            return 'voided';
        }

        if ('invoiced' === $status || get_post_meta($trip_id, '_terricel_trip_invoice_sent_at', true)) {
            return 'invoiced';
        }

        return 'non_invoiced';
    }

    private function get_invoice_download_link($trip_id) {
        $attachment_id = $this->get_current_trip_invoice_attachment_id($trip_id);
        $url = $attachment_id ? wp_get_attachment_url($attachment_id) : '';
        if (!$url) {
            return esc_html__('None', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN);
        }

        return '<a href="' . esc_url($url) . '" download>' . esc_html(sprintf(__('View Invoice #%s', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $this->get_trip_invoice_number($trip_id))) . '</a>';
    }

    private function get_invoice_view_form($trip_id) {
        ob_start();
        echo '<form class="terricel-inline-action-form" method="get" action="' . esc_url(admin_url('admin-post.php')) . '" target="_blank">';
        wp_nonce_field('terricel_trips_view_invoice_' . $trip_id);
        echo '<input type="hidden" name="action" value="terricel_trips_view_invoice">';
        echo '<input type="hidden" name="trip_id" value="' . esc_attr($trip_id) . '">';
        submit_button(__('View Invoice', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), 'primary small', 'submit', false);
        echo '</form>';

        return ob_get_clean();
    }

    private function get_invoice_email_form($trip_id) {
        ob_start();
        echo '<form class="terricel-inline-action-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="return window.confirm(\'' . esc_js(__('Email this invoice PDF to the billing contact?', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN)) . '\');">';
        wp_nonce_field('terricel_trips_send_invoice_' . $trip_id);
        echo '<input type="hidden" name="action" value="terricel_trips_send_invoice">';
        echo '<input type="hidden" name="trip_id" value="' . esc_attr($trip_id) . '">';
        submit_button(__('Email Invoice', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), 'secondary small', 'submit', false);
        echo '</form>';

        return ob_get_clean();
    }

    private function get_invoice_void_form($trip_id) {
        ob_start();
        echo '<form class="terricel-inline-action-form" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" onsubmit="var value=window.prompt(\'' . esc_js(__('Type CANCEL to cancel this invoice.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN)) . '\'); if(value!==\'CANCEL\'){return false;} this.querySelector(\'[name=&quot;cancel_confirmation&quot;]\').value=value; return true;">';
        wp_nonce_field('terricel_trips_void_invoice_' . $trip_id);
        echo '<input type="hidden" name="action" value="terricel_trips_void_invoice">';
        echo '<input type="hidden" name="trip_id" value="' . esc_attr($trip_id) . '">';
        echo '<input type="hidden" name="cancel_confirmation" value="">';
        echo '<button type="submit" class="button-link-delete">' . esc_html__('Cancel Invoice', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</button>';
        echo '</form>';

        return ob_get_clean();
    }

    private function get_trip_actuals_update_url($trip_id) {
        return add_query_arg(
            array(
                'post'                      => absint($trip_id),
                'action'                    => 'edit',
                'terricel-highlight-actuals' => 1,
            ),
            admin_url('post.php')
        ) . '#terricel_trip_assignments';
    }

    public function send_due_trip_notifications() {
        $this->cancel_trips_for_current_school_closures();

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
                    $this->queue_user_notification($user_id, 'trip_driver_reminder', __('Upcoming Trip Assignment', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $this->format_trip_notice_message($trip->ID), $this->get_driver_dashboard_trip_url($trip->ID));
                }
                update_post_meta($trip->ID, '_terricel_trip_driver_reminder_sent', current_time('mysql'));
            }
        }
    }

    public function maybe_cancel_school_closed_trips($school_id, $post) {
        if (!$post || Terricel_Logistics_Shared_Data::SCHOOL_POST_TYPE !== $post->post_type) {
            return;
        }

        $this->cancel_trips_for_school_closures(absint($school_id));
    }

    private function cancel_trips_for_current_school_closures() {
        foreach ($this->get_posts_for_select(Terricel_Logistics_Shared_Data::SCHOOL_POST_TYPE) as $school) {
            $this->cancel_trips_for_school_closures($school->ID);
        }
    }

    private function cancel_trips_for_school_closures($school_id) {
        if ($school_id < 1 || Terricel_Logistics_Shared_Data::SCHOOL_POST_TYPE !== get_post_type($school_id)) {
            return;
        }

        foreach ($this->get_published_trips_for_organization($school_id) as $trip) {
            $pickup_date = $this->sanitize_date(get_post_meta($trip->ID, '_terricel_trip_pickup_date', true));
            if (!$pickup_date || !$this->school_is_closed_on_date($school_id, $pickup_date)) {
                continue;
            }

            $this->cancel_trip_for_school_closure($trip->ID, $school_id, $pickup_date);
        }
    }

    private function get_published_trips_for_organization($organization_id) {
        return get_posts(
            array(
                'post_type'      => self::TRIP_POST_TYPE,
                'post_status'    => 'publish',
                'posts_per_page' => 500,
                'meta_query'     => array(
                    array(
                        'key'     => '_terricel_trip_school_id',
                        'value'   => absint($organization_id),
                        'compare' => '=',
                        'type'    => 'NUMERIC',
                    ),
                    array(
                        'key'     => '_terricel_trip_pickup_date',
                        'value'   => current_time('Y-m-d'),
                        'compare' => '>=',
                        'type'    => 'DATE',
                    ),
                ),
            )
        );
    }

    private function school_is_closed_on_date($school_id, $date) {
        if ($this->entity_has_closure_on_date($school_id, $date)) {
            return true;
        }

        $district_id = absint(get_post_meta($school_id, '_terricel_school_district_id', true));
        return $district_id > 0 && $this->entity_has_closure_on_date($district_id, $date);
    }

    private function entity_has_closure_on_date($entity_id, $date) {
        $changes = get_post_meta($entity_id, '_terricel_schedule_changes', true);
        if (!is_array($changes)) {
            return false;
        }

        foreach ($changes as $change) {
            if (!is_array($change)) {
                continue;
            }

            $type = sanitize_key($change['type'] ?? '');
            if (!in_array($type, array('unplanned_closure', 'scheduled_closure', 'closure'), true)) {
                continue;
            }

            $start_date = $this->sanitize_date($change['date'] ?? '');
            $end_date = $this->sanitize_date($change['end_date'] ?? '') ?: $start_date;
            if ($start_date && $date >= $start_date && $date <= $end_date) {
                return true;
            }
        }

        return false;
    }

    private function cancel_trip_for_school_closure($trip_id, $school_id, $pickup_date) {
        if ('publish' !== get_post_status($trip_id)) {
            return;
        }

        wp_update_post(
            array(
                'ID'          => $trip_id,
                'post_status' => 'cancelled',
            )
        );

        update_post_meta($trip_id, '_terricel_trip_cancelled_for_school_closure', current_time('mysql'));
        update_post_meta($trip_id, '_terricel_trip_cancelled_school_id', absint($school_id));
        update_post_meta($trip_id, '_terricel_trip_cancelled_date', $pickup_date);

        $message = sprintf(
            __('%1$s has been cancelled because %2$s is closed on %3$s.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
            get_the_title($trip_id),
            $this->get_school_label($school_id),
            date_i18n(get_option('date_format'), strtotime($pickup_date))
        );

        foreach ($this->get_trip_assigned_driver_user_ids($trip_id) as $user_id) {
            $this->queue_user_notification($user_id, 'trip_school_closure_cancelled', __('Trip Cancelled', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $message, $this->get_driver_dashboard_url());
        }

        $this->queue_operations_notification($trip_id, 'trip_school_closure_cancelled', __('Trip Cancelled for School Closure', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $message);
    }

    public function render_driver_dashboard_trips($driver_id) {
        $trips = $this->get_driver_upcoming_trips($driver_id);
        if (empty($trips)) {
            echo '<p>' . esc_html__('No future trip assignments are scheduled.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</p>';
            return;
        }

        $target_trip_id = isset($_GET['terricel_trip_assignment']) ? absint($_GET['terricel_trip_assignment']) : 0;
        $highlighted_trip_key = '';

        echo '<style>';
        echo '@keyframes terricelTripAssignmentPulse{0%{background:inherit;}25%{background:#ffedd5;}60%{background:#fff7ed;}100%{background:inherit;}}';
        echo '.terricel-trip-dashboard-highlight td{animation:terricelTripAssignmentPulse 1.05s ease-in-out 0s 3;}';
        echo '</style>';

        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>' . esc_html__('Pickup', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</th>';
        echo '<th>' . esc_html__('Organization', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</th>';
        echo '<th>' . esc_html__('Destination', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</th>';
        echo '<th>' . esc_html__('Map', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</th>';
        echo '</tr></thead><tbody>';
        foreach ($trips as $trip) {
            $trip_key = 'trip-' . absint($trip->ID);
            $is_highlighted = $target_trip_id > 0 && absint($trip->ID) === $target_trip_id;
            if ($is_highlighted) {
                $highlighted_trip_key = $trip_key;
            }

            echo '<tr class="' . esc_attr($is_highlighted ? 'terricel-trip-dashboard-highlight' : '') . '" data-terricel-trip-assignment-key="' . esc_attr($trip_key) . '">';
            echo '<td>' . esc_html($this->format_trip_pickup($trip->ID)) . '</td>';
            echo '<td>' . esc_html($this->get_school_label((int) get_post_meta($trip->ID, '_terricel_trip_school_id', true))) . '</td>';
            echo '<td>' . esc_html(get_post_meta($trip->ID, '_terricel_trip_location_name', true)) . '</td>';
            echo '<td><a class="button" target="_blank" rel="noopener" href="' . esc_url($this->get_trip_maps_url($trip->ID)) . '">' . esc_html__('Open', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</a></td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        if ($highlighted_trip_key) {
            echo '<script>';
            echo '(function(){var key=' . wp_json_encode($highlighted_trip_key) . ';var section=document.getElementById("terricel-driver-assignments");if(section&&section.tagName.toLowerCase()==="details"){section.open=true;}var row=document.querySelector("[data-terricel-trip-assignment-key=\\"" + key.replace(/"/g,"\\\\\\"") + "\\"]");if(!row){return;}window.setTimeout(function(){row.scrollIntoView({behavior:"smooth",block:"center"});row.setAttribute("tabindex","-1");row.focus({preventScroll:true});},150);}());';
            echo '</script>';
        }
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
        echo '<th scope="row"><label for="terricel_report_trip_school_id">' . esc_html__('Organization', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</label></th>';
        echo '<td><select id="terricel_report_trip_school_id" name="terricel_report_trip_school_id">';
        echo '<option value="0">' . esc_html__('All organizations', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</option>';
        foreach ($this->get_trip_report_schools_for_range($start_date, $end_date) as $school) {
            echo '<option value="' . esc_attr($school['id']) . '"' . selected($selected_school_id, $school['id'], false) . '>' . esc_html($school['label']) . '</option>';
        }
        echo '</select></td>';
        echo '</tr>';

        echo '<tr class="terricel-report-filter-trip">';
        echo '<th scope="row"><label for="terricel_report_trip_group_id">' . esc_html__('Group', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</label></th>';
        echo '<td><select id="terricel_report_trip_group_id" name="terricel_report_trip_group_id">';
        echo '<option value="0">' . esc_html__('All groups', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</option>';
        foreach ($groups as $group) {
            echo '<option value="' . esc_attr($group['id']) . '"' . selected($selected_group_id, $group['id'], false) . '>' . esc_html($group['label']) . '</option>';
        }
        echo '</select> <span class="description">' . esc_html__('Groups are limited to groups with trips during the selected date range.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</span></td>';
        echo '</tr>';

        echo '<script>';
        echo '(function(){var type=document.getElementById("terricel_report_type");var start=document.getElementById("terricel_report_start_date");var end=document.getElementById("terricel_report_end_date");var school=document.getElementById("terricel_report_trip_school_id");var group=document.getElementById("terricel_report_trip_group_id");if(!type||!start||!end||!school||!group){return;}var nonce=' . wp_json_encode(wp_create_nonce('terricel_trip_report_groups')) . ';function option(value,label,selected){var item=document.createElement("option");item.value=String(value);item.textContent=label;if(selected){item.selected=true;}return item;}function refill(select,items,allLabel,current){select.innerHTML="";select.appendChild(option("0",allLabel,current==="0"));(items||[]).forEach(function(row){select.appendChild(option(row.id,row.label,String(row.id)===String(current)));});if(select.selectedIndex<0){select.value="0";}}function refresh(){if(type.value!=="trips_by_school"){return;}var currentSchool=school.value;var currentGroup=group.value;var body=new URLSearchParams({action:"terricel_trip_report_groups",nonce:nonce,start_date:start.value,end_date:end.value,school_id:currentSchool});fetch(ajaxurl,{method:"POST",credentials:"same-origin",headers:{"Content-Type":"application/x-www-form-urlencoded; charset=UTF-8"},body:body.toString()}).then(function(response){return response.json();}).then(function(json){if(!json||!json.success){return;}refill(school,json.data.schools||[],' . wp_json_encode(__('All organizations', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN)) . ',currentSchool);refill(group,json.data.groups||[],' . wp_json_encode(__('All groups', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN)) . ',currentGroup);});}type.addEventListener("change",refresh);start.addEventListener("change",refresh);end.addEventListener("change",refresh);school.addEventListener("change",function(){group.value="0";refresh();});}());';
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
                    'all_label' => __('All organizations', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
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
            'title'    => __('Trips by Organization', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
            'filename' => 'trips-by-organization',
            'columns'  => array(
                array('key' => 'pickup', 'label' => __('Pickup', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), 'width' => 96),
                array('key' => 'school', 'label' => __('Organization', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), 'width' => 58),
                array('key' => 'advisor', 'label' => __('Primary Contact', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), 'width' => 68),
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
                $this->queue_user_notification($user_id, 'trip_driver_assigned', __('New Trip Assignment', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), $this->format_trip_notice_message($trip_id), $this->get_driver_dashboard_trip_url($trip_id));
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
        $school_label = $this->get_school_label((int) get_post_meta($trip_id, '_terricel_trip_school_id', true));
        $destination_label = $this->get_trip_destination_label($trip_id);

        return array(
            'id'                 => $trip_id,
            'title'              => get_the_title($trip_id),
            'school'             => $school_label,
            'group'              => $group_id > 0 ? get_the_title($group_id) : '',
            'destination'        => $destination_label,
            'trip_name'          => trim($school_label . ' | ' . $destination_label),
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

    private function get_driver_dashboard_trip_url($trip_id) {
        return add_query_arg(
            array(
                'page'                    => 'terricel-my-dashboard',
                'terricel_trip_assignment' => absint($trip_id),
            ),
            admin_url('admin.php')
        ) . '#terricel-driver-assignments';
    }

    private function get_school_origin_address($school_id) {
        if (self::ORGANIZATION_POST_TYPE === get_post_type($school_id)) {
            $parts = array(
                get_post_meta($school_id, '_terricel_trip_organization_address_1', true),
                get_post_meta($school_id, '_terricel_trip_organization_address_2', true),
                get_post_meta($school_id, '_terricel_trip_organization_city', true),
                get_post_meta($school_id, '_terricel_trip_organization_state', true),
                get_post_meta($school_id, '_terricel_trip_organization_zip', true),
            );

            return trim(implode(' ', array_filter(array_map('trim', $parts))));
        }

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
            return array('miles' => 0, 'minutes' => 0, 'message' => __('Add a Google API key, organization origin address, and destination before estimating.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN));
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
            return array('miles' => 0, 'minutes' => 0, 'message' => __('Google did not return a drivable route for this organization and destination.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN));
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
        if (!$screen || !in_array($screen->post_type, array(self::TRIP_POST_TYPE, self::GROUP_POST_TYPE, self::ORGANIZATION_POST_TYPE), true)) {
            return;
        }

        wp_register_style('terricel-transit-trips-admin', false, array(), TERRICEL_TRANSIT_TRIPS_VERSION);
        wp_enqueue_style('terricel-transit-trips-admin');
        wp_add_inline_style('terricel-transit-trips-admin', '.terricel-trip-grid,.terricel-group-details-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px 16px}.terricel-trip-grid p,.terricel-group-details-grid p{margin-top:0}.terricel-trip-details-layout{display:grid;gap:18px}.terricel-trip-school-row{display:grid;grid-template-columns:minmax(240px,1fr) auto minmax(260px,1fr) auto;gap:14px;align-items:end;max-width:1180px}.terricel-trip-school-field p{margin:0}.terricel-trip-school-field select{min-height:40px}.terricel-trip-group-action .button{min-height:40px;padding:4px 18px}.terricel-trip-schedule-grid{display:grid;grid-template-columns:repeat(4,minmax(160px,1fr));gap:14px;max-width:1060px}.terricel-trip-time-card{border:1px solid #dcdcde;background:#fdfdfd;padding:12px;display:grid;gap:10px}.terricel-trip-time-card strong{font-size:13px}.terricel-trip-time-card label{display:grid;gap:4px;margin:0}.terricel-trip-time-card span{font-size:12px;color:#646970}.terricel-trip-estimate-help-wrap{position:relative;display:inline-flex;vertical-align:text-bottom}.terricel-trip-estimate-help{border:0;background:transparent;color:#2271b1;cursor:pointer;margin:0;padding:0;line-height:1}.terricel-trip-estimate-help .dashicons{font-size:16px;width:16px;height:16px;line-height:16px}.terricel-trip-estimate-help:focus{outline:1px solid #2271b1;outline-offset:1px;border-radius:50%}.terricel-trip-estimate-popover{position:absolute;z-index:1001;left:22px;top:-8px;width:280px;background:#1d2327;color:#fff;border-radius:3px;box-shadow:0 8px 18px rgba(0,0,0,.2);padding:8px 10px;font-size:12px;line-height:1.4}.terricel-trip-assignment-summary div{margin:0 0 3px}.terricel-trip-actuals{background:#f6f7f7;border-left:4px solid #2271b1;margin:4px 0 8px;padding:10px 12px}.terricel-trip-actuals-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px 12px;margin-top:8px}.terricel-trip-actuals-grid label{display:grid;gap:4px;margin:0}.terricel-trip-actuals-grid span{color:#646970;font-size:12px}.terricel-trip-actuals-grid input{width:100%;min-height:34px}.terricel-trip-modified-by{color:#646970}.terricel-trip-time-card input{width:100%;max-width:100%;min-height:36px}.terricel-trip-time-card.terricel-trip-step-locked{opacity:.45;pointer-events:none}.terricel-publish-gate{clear:both;color:#b32d2e;margin:8px 0 0}.terricel-trip-assignments select,.terricel-group-details-grid select,.terricel-group-details-grid input{max-width:100%;width:100%}.terricel-required{color:#b32d2e}.terricel-address-lookup{position:relative}.terricel-address-suggestions{position:absolute;z-index:1000;left:0;right:0;top:100%;background:#fff;border:1px solid #8c8f94;box-shadow:0 8px 16px rgba(0,0,0,.12);max-height:260px;overflow:auto}.terricel-address-suggestion{display:block;width:100%;padding:9px 11px;border:0;border-bottom:1px solid #f0f0f1;background:#fff;text-align:left;cursor:pointer}.terricel-address-suggestion:hover,.terricel-address-suggestion:focus{background:#f6f7f7}.terricel-address-suggestion strong{display:block}.terricel-address-suggestion span{display:block;color:#646970;font-size:12px}.terricel-route-options{margin:8px 0 14px}.terricel-route-options summary{cursor:pointer;color:#2271b1}.terricel-route-summary{background:#f6f7f7;border:1px solid #dcdcde;padding:8px 10px;margin:8px 0}.terricel-route-summary p{margin:0 0 4px}.terricel-route-option{display:grid;grid-template-columns:90px 1fr;gap:8px;border-top:1px solid #dcdcde;padding:8px 0}.terricel-route-option:first-child{border-top:0}.terricel-route-option strong{display:block}.terricel-route-option span{color:#646970}.terricel-route-selected{color:#008a20;font-weight:600}.terricel-trip-conflict-dialog{position:fixed;z-index:100000;inset:0;background:rgba(29,35,39,.45);display:flex;align-items:center;justify-content:center;padding:24px}.terricel-trip-conflict-dialog[hidden]{display:none}.terricel-trip-conflict-card{background:#fff;border:1px solid #c3c4c7;box-shadow:0 16px 38px rgba(0,0,0,.28);max-width:680px;width:100%;padding:20px}.terricel-trip-conflict-card h3{margin-top:0}.terricel-trip-conflict-card ul{list-style:disc;margin-left:22px;max-height:260px;overflow:auto}.terricel-trip-conflict-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:16px}.terricel-trip-conflict-confirm{background:#b32d2e!important;border-color:#8a2424!important;color:#fff!important}.terricel-trip-conflict-confirm.terricel-pulse{animation:terricelTripConflictPulse 1s infinite}.terricel-trip-conflict-confirm.terricel-confirmed{background:#008a20!important;border-color:#007017!important}.terricel-trip-conflict-confirm.terricel-confirmed:before{content:"\\f147";font-family:dashicons;vertical-align:middle;margin-right:4px}@keyframes terricelTripConflictPulse{0%,100%{box-shadow:0 0 0 0 rgba(179,45,46,.55)}50%{box-shadow:0 0 0 8px rgba(179,45,46,0)}}.terricel-group-contact-links{display:flex;gap:8px;flex-wrap:wrap;margin:14px 0 0}.terricel-group-contact-links a{text-decoration:none}.terricel-group-contact-links .dashicons{vertical-align:text-bottom}.terricel-trips-back-link{margin:0 0 12px}.terricel-inline-group-panel{border:1px solid #dcdcde;background:#fff;padding:14px;margin:0;max-width:980px}.terricel-inline-group-panel-header{display:flex;align-items:baseline;gap:10px;margin:0 0 12px}.terricel-inline-group-panel-header span{color:#646970}.terricel-inline-group-actions{margin:14px 0 0;display:flex;gap:8px;align-items:center}.terricel-trip-panel-locked .inside{opacity:.45;pointer-events:none}.terricel-trip-panel-locked:after{content:"Complete the previous trip step to continue.";display:block;margin:0 12px 12px;color:#646970;font-style:italic}@media (max-width:1100px){.terricel-trip-school-row{grid-template-columns:1fr 1fr}.terricel-trip-group-action{grid-column:1 / -1}.terricel-trip-schedule-grid{grid-template-columns:repeat(2,minmax(180px,1fr))}}@media (max-width:782px){.terricel-trip-school-row,.terricel-trip-schedule-grid{grid-template-columns:1fr}.terricel-inline-group-panel-header,.terricel-inline-group-actions,.terricel-trip-conflict-actions{align-items:stretch;flex-direction:column}.terricel-trip-group-action .button{width:100%;text-align:center}.terricel-trip-estimate-popover{left:auto;right:0;top:20px}}');
        wp_add_inline_style('terricel-transit-trips-admin', '@keyframes terricelActualsBillingPulse{0%,100%{border-left-color:#b32d2e;box-shadow:0 0 0 0 rgba(179,45,46,0)}35%{border-left-color:#b32d2e;box-shadow:0 0 0 5px rgba(179,45,46,.24);background:#fff5f5}70%{border-left-color:#b32d2e;box-shadow:0 0 0 0 rgba(179,45,46,0)}}.terricel-trip-actuals.terricel-highlight-actuals{animation:terricelActualsBillingPulse 1s ease-in-out 0s 3}');
        wp_add_inline_style('terricel-transit-trips-admin', '.terricel-trip-actuals-grid{display:flex;flex-wrap:wrap;align-items:flex-start;gap:12px 14px}.terricel-trip-actual-group{border:1px solid #dcdcde;background:#fff;margin:0;padding:10px;min-width:170px;max-width:220px;flex:1 1 180px}.terricel-trip-actual-group legend{font-weight:600;color:#1d2327;padding:0 4px}.terricel-trip-actual-group label{display:grid;gap:4px;margin:8px 0 0}.terricel-trip-actual-group input{width:100%;min-height:34px}@media (max-width:782px){.terricel-trip-actual-group{max-width:none;flex-basis:100%}}');
        wp_add_inline_style('terricel-transit-trips-admin', '.terricel-trip-driver-cell{display:grid;grid-template-columns:minmax(220px,1fr) minmax(230px,auto);gap:10px;align-items:center}.terricel-trip-driver-picker{display:grid;gap:6px}.terricel-trip-add-any-driver-wrap{display:inline-flex;align-items:center;gap:6px;margin:0;color:#50575e}.terricel-trip-driver-cell select{min-width:0}.terricel-trip-conflict-status{display:inline-flex;align-items:center;gap:6px;min-height:36px;padding:6px 10px;border-left:4px solid #008a20;background:#f0f6ef;color:#005c12;font-weight:600;white-space:nowrap}.terricel-trip-conflict-status:before{content:"\\f147";font-family:dashicons;font-size:18px;line-height:1}.terricel-trip-conflict-status.terricel-status-neutral{border-left-color:#8c8f94;background:#f6f7f7;color:#50575e}.terricel-trip-conflict-status.terricel-status-neutral:before{content:""}.terricel-trip-conflict-driver{margin:0 0 14px}.terricel-trip-conflict-driver strong{display:block;margin:0 0 6px}.terricel-trip-conflict-driver ul{margin-top:0}@media (max-width:1100px){.terricel-trip-driver-cell{grid-template-columns:1fr}.terricel-trip-conflict-status{white-space:normal}}');

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
                'selectSchoolFirst' => __('Select an organization first', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
                'noGroups'          => __('No groups linked to this organization yet.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
                'loading'           => __('Loading groups...', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
                'saving'            => __('Saving group...', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
                'saved'             => __('Group added and selected.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
                'selectSchool'      => __('Select an organization before adding a group.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
                'savingOrganization' => __('Saving organization...', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
                'organizationSaved' => __('Organization added and selected.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
                'enterOrganization' => __('Enter an organization name and email.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
                'enterGroupName'    => __('Enter a group name.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
                'requiredGroup'     => __('Enter the group name, primary contact first and last name, and email.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
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
var organizationToggle=document.getElementById("terricel_trip_add_organization_toggle");var organizationPanel=document.getElementById("terricel_trip_add_organization_panel");var organizationSave=document.getElementById("terricel_trip_save_organization");var organizationCancel=document.getElementById("terricel_trip_cancel_organization");var organizationMessage=document.getElementById("terricel_trip_organization_message");
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
function setOrganizationMessage(text,isError){if(!organizationMessage){return;}organizationMessage.textContent=text||"";organizationMessage.style.color=isError?"#b32d2e":"";}
function fillGroups(groups,selected){group.innerHTML="";if(parseInt(school.value,10)<1){group.appendChild(option(0,config.strings.selectSchoolFirst));syncWorkflow();return;}group.appendChild(option(0,groups.length?config.strings.selectGroup:config.strings.noGroups));groups.forEach(function(item){var row=option(item.id,item.label);if(String(item.id)===String(selected)){row.selected=true;}group.appendChild(row);});syncWorkflow();}
function loadGroups(){var current=group.value;group.innerHTML="";group.appendChild(option(0,parseInt(school.value,10)>0?config.strings.loading:config.strings.selectSchoolFirst));setMessage("");if(parseInt(school.value,10)<1){fillGroups([],0);return;}post("terricel_trip_groups_for_school",{school_id:school.value}).then(function(data){fillGroups(data.groups||[],current);}).catch(function(error){fillGroups([],0);setMessage(error.message,true);});}
function clearPanel(){if(!panel){return;}panel.querySelectorAll("input").forEach(function(input){input.value="";});}
function clearOrganizationPanel(){if(!organizationPanel){return;}organizationPanel.querySelectorAll("input").forEach(function(input){input.value="";});}
function recalcArrival(force){var estimate=travelMinutes?parseInt(travelMinutes.value,10):0;var next=addMinutes(value("terricel_trip_pickup_date"),value("terricel_trip_pickup_time"),estimate);if(next){setInput("terricel_trip_arrival_date",next.date,force?"force":undefined);setInput("terricel_trip_arrival_time",next.time,force?"force":undefined);}}
function recalcReturn(force){var estimate=travelMinutes?parseInt(travelMinutes.value,10):0;var next=addMinutes(value("terricel_trip_departure_date"),value("terricel_trip_departure_time"),estimate);if(next){setInput("terricel_trip_return_date",next.date,force?"force":undefined);setInput("terricel_trip_return_time",next.time,force?"force":undefined);}}
function refreshScheduleDependents(changedName,force){if(changedName==="terricel_trip_pickup_date"||changedName==="terricel_trip_pickup_time"){recalcArrival(force);if(changedName==="terricel_trip_pickup_date"){recalcReturn(force);}}else if(changedName==="terricel_trip_departure_date"||changedName==="terricel_trip_departure_time"){recalcReturn(force);}else{recalcArrival();recalcReturn();}}
function syncDates(){var pickupDate=document.querySelector("[name='terricel_trip_pickup_date']");["arrival","departure","return"].forEach(function(key){["date","time"].forEach(function(part){var input=document.querySelector("[name='terricel_trip_"+key+"_"+part+"']");if(input){input.addEventListener("input",function(){input.dataset.terricelDefaulted="0";});}});});document.querySelectorAll("#terricel_trip_schedule input").forEach(function(input){input.addEventListener("change",function(){var changedName=input.name||"";if(pickupDate&&pickupDate.value&&changedName==="terricel_trip_pickup_date"){["arrival","departure","return"].forEach(function(key){setInput("terricel_trip_"+key+"_date",pickupDate.value);});}refreshScheduleDependents(changedName,changedName==="terricel_trip_pickup_date"||changedName==="terricel_trip_pickup_time"||changedName==="terricel_trip_departure_date"||changedName==="terricel_trip_departure_time");syncDriverOptions();refreshBusAvailability();checkDriverConflicts();syncWorkflow();});});}
function syncTimeInputs(){document.querySelectorAll(".terricel-trip-time-input").forEach(function(display){var target=document.querySelector("[name='"+display.dataset.timeTarget+"']");if(!target){return;}function apply(normalize,notify){if(!display.value.trim()){if(target.value){target.value="";if(notify){target.dispatchEvent(new Event("change",{bubbles:true}));}}target.dataset.terricelDefaulted="0";return;}var parsed=parseFriendlyTime(display.value);if(parsed){var changed=target.value!==parsed;target.value=parsed;target.dataset.terricelDefaulted=display.dataset.terricelDefaulted==="1"?"1":"0";if(normalize){display.value=formatFriendlyTime(parsed);}if(changed&&notify){target.dispatchEvent(new Event("change",{bubbles:true}));}}}display.addEventListener("input",function(){display.dataset.terricelDefaulted="0";if(target){target.dataset.terricelDefaulted="0";}apply(false,false);});display.addEventListener("change",function(){apply(true,true);});display.addEventListener("blur",function(){apply(true,true);});});}
function initEstimateHelp(){document.querySelectorAll(".terricel-trip-estimate-help").forEach(function(button){var popover=button.parentElement?button.parentElement.querySelector(".terricel-trip-estimate-popover"):null;if(!popover){return;}function close(){popover.hidden=true;button.setAttribute("aria-expanded","false");}button.addEventListener("click",function(event){event.preventDefault();event.stopPropagation();var isOpen=!popover.hidden;document.querySelectorAll(".terricel-trip-estimate-popover").forEach(function(item){item.hidden=true;});document.querySelectorAll(".terricel-trip-estimate-help").forEach(function(item){item.setAttribute("aria-expanded","false");});popover.hidden=isOpen;button.setAttribute("aria-expanded",isOpen?"false":"true");});document.addEventListener("click",function(event){if(!button.parentElement||!button.parentElement.contains(event.target)){close();}});document.addEventListener("keydown",function(event){if(event.key==="Escape"){close();}});});}
function renderRouteOptions(estimate){if(!config.googleDiagnostics||!routeOptions||!routeOptionsList){return;}routeOptionsList.innerHTML="";var items=estimate&&estimate.route_options?estimate.route_options:[];if(!items.length){routeOptions.hidden=true;return;}var summary=document.createElement("div");summary.className="terricel-route-summary";var from=document.createElement("p");from.textContent="Estimated from: "+(estimate.origin||"");var to=document.createElement("p");to.textContent="Estimated to: "+(estimate.destination||"");summary.appendChild(from);summary.appendChild(to);if(estimate.maps_url){var link=document.createElement("a");link.href=estimate.maps_url;link.target="_blank";link.rel="noopener";link.textContent="Open this same route in Google Maps";summary.appendChild(link);}routeOptionsList.appendChild(summary);items.forEach(function(item){var row=document.createElement("div");row.className="terricel-route-option";var label=document.createElement("strong");label.textContent="Route "+item.index+(item.selected?" - Selected":"");if(item.selected){label.className="terricel-route-selected";}var details=document.createElement("div");var title=document.createElement("strong");title.textContent=item.description||"Google route option";var facts=document.createElement("span");facts.textContent=item.minutes+" min one-way ("+item.buffered_minutes+" min with buffer), "+item.one_way_miles+" mi one-way, "+item.round_trip_miles+" mi round trip";details.appendChild(title);details.appendChild(facts);row.appendChild(label);row.appendChild(details);routeOptionsList.appendChild(row);});routeOptions.hidden=false;}
function requestEstimate(force,forceTripTimes){if(!destination||!destination.value.trim()||parseInt(school.value,10)<1){syncWorkflow();return;}post("terricel_trip_destination_estimate",{school_id:school.value,destination:destination.value}).then(function(data){if(data.estimate){if(mileage&&(force||!mileage.value||mileage.dataset.terricelAuto==="1")){mileage.value=data.estimate.miles?String(Math.ceil(Number(data.estimate.miles))):"";mileage.dataset.terricelAuto="1";}if(travelMinutes&&(force||!travelMinutes.value||travelMinutes.dataset.terricelAuto==="1")){travelMinutes.value=data.estimate.minutes?String(Math.ceil(Number(data.estimate.minutes))):"";travelMinutes.dataset.terricelAuto="1";}renderRouteOptions(data.estimate);recalcArrival(!!forceTripTimes);recalcReturn(!!forceTripTimes);refreshBusAvailability();checkDriverConflicts();syncWorkflow();}}).catch(function(error){renderRouteOptions(null);if(window.console){window.console.warn("Terricel trip estimate failed",error);}syncWorkflow();});}
function scheduleEstimate(force,forceTripTimes){window.clearTimeout(estimateTimer);estimateTimer=window.setTimeout(function(){requestEstimate(force,forceTripTimes);},650);}
function selectedDriverIds(){return Array.prototype.slice.call(document.querySelectorAll("#terricel_trip_assignment_rows select[name*='[driver_id]']")).map(function(select){return parseInt(select.value,10)||0;}).filter(function(id){return id>0;});}
function dayKeyFromDate(dateValue){var parts=String(dateValue||"").split("-");if(parts.length!==3){return "";}var date=new Date(Number(parts[0]),Number(parts[1])-1,Number(parts[2]));if(isNaN(date.getTime())){return "";}return ["sunday","monday","tuesday","wednesday","thursday","friday","saturday"][date.getDay()];}
function selectedTripDayKeys(){var start=value("terricel_trip_pickup_date");if(!start){return [];}var end=value("terricel_trip_return_date")||start;var startParts=start.split("-");var endParts=end.split("-");var current=new Date(Number(startParts[0]),Number(startParts[1])-1,Number(startParts[2]));var last=new Date(Number(endParts[0]),Number(endParts[1])-1,Number(endParts[2]));if(isNaN(current.getTime())){return [];}if(isNaN(last.getTime())||last<current){last=new Date(current.getTime());}var days=[];while(current<=last){var key=dayKeyFromDate(current.getFullYear()+"-"+String(current.getMonth()+1).padStart(2,"0")+"-"+String(current.getDate()).padStart(2,"0"));if(key&&days.indexOf(key)<0){days.push(key);}current.setDate(current.getDate()+1);}return days;}
function driverSelects(){return Array.prototype.slice.call(document.querySelectorAll("#terricel_trip_assignment_rows select[name*='[driver_id]']"));}
function driverOptionEligible(option,days){if(!option||parseInt(option.value,10)<1||!days.length){return true;}var available=(option.dataset.terricelTripDays||"").split(",").filter(Boolean);return days.every(function(day){return available.indexOf(day)>-1;});}
function rowAllowsAnyDriver(select){var row=select?select.closest(".terricel-trip-assignment-slot-row"):null;var checkbox=row?row.querySelector(".terricel-trip-add-any-driver"):null;return !!(checkbox&&checkbox.checked);}
function syncDriverOptions(){var days=selectedTripDayKeys();driverSelects().forEach(function(select){var selectedOption=select.options[select.selectedIndex];var allowAny=rowAllowsAnyDriver(select);Array.prototype.slice.call(select.options).forEach(function(option){var eligible=allowAny||driverOptionEligible(option,days);var isSelected=option===selectedOption&&parseInt(option.value,10)>0;option.hidden=!eligible&&!isSelected;option.disabled=!eligible&&!isSelected;});});}
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
function syncBusSlots(){var needed=document.getElementById("terricel_trip_buses_needed");var rows=document.getElementById("terricel_trip_assignment_rows");var template=document.getElementById("terricel_trip_assignment_row_template");if(!needed||!rows||!template){return;}function slotRows(){return Array.prototype.slice.call(rows.querySelectorAll(".terricel-trip-assignment-slot-row"));}function apply(){var count=Math.max(0,Math.min(50,parseInt(needed.value,10)||0));var slots=slotRows();while(slots.length>count){var row=slots.pop();var next=row.nextElementSibling;if(next&&next.classList.contains("terricel-trip-actuals-row")){rows.removeChild(next);}rows.removeChild(row);}slots=slotRows();while(slots.length<count){var index=slots.length;var holder=document.createElement("tbody");holder.innerHTML=template.innerHTML.replace(/__INDEX__/g,String(index)).replace(/__NUMBER__/g,String(index+1));Array.prototype.slice.call(holder.children).forEach(function(child){rows.appendChild(child);});slots=slotRows();}syncDriverOptions();syncBusOptions();setConflictStatus();checkDriverConflicts();}needed.addEventListener("input",apply);needed.addEventListener("change",apply);rows.addEventListener("change",function(event){if(event.target&&event.target.matches(".terricel-trip-add-any-driver")){syncDriverOptions();setConflictStatus();checkDriverConflicts();}if(event.target&&event.target.matches("select[name*='[driver_id]']")){setConflictStatus();checkDriverConflicts();}if(event.target&&event.target.matches("select[name*='[bus_id]']")){syncBusOptions();}});apply();}
function initAddressLookup(){function setup(input,menu,mode){if(!input||!menu){return;}var timer=0;function hide(){menu.hidden=true;menu.innerHTML="";}function showMessage(text){menu.innerHTML="<div class=\"terricel-address-suggestion\"><span></span></div>";menu.querySelector("span").textContent=text;menu.hidden=false;}function render(items){menu.innerHTML="";if(!items.length){showMessage(config.strings.addressEmpty);return;}items.forEach(function(item){var button=document.createElement("button");button.type="button";button.className="terricel-address-suggestion";button.dataset.placeId=item.placeId||"";button.dataset.address=item.address||"";button.dataset.name=item.name||"";var main=document.createElement("strong");main.textContent=item.mainText||item.text;var secondary=document.createElement("span");secondary.textContent=item.secondaryText||"";button.appendChild(main);button.appendChild(secondary);menu.appendChild(button);});menu.hidden=false;}input.addEventListener("input",function(){input.dataset.terricelManual="1";window.clearTimeout(timer);var text=input.value.trim();if(text.length<3){hide();syncWorkflow();return;}if(mode==="address"||destination&&destination.value.trim()){scheduleEstimate(true,true);}timer=window.setTimeout(function(){showMessage(config.strings.addressLoading);post("terricel_trip_address_suggestions",{input:text,school_id:school.value,mode:mode}).then(function(data){render(data.suggestions||[]);}).catch(function(error){showMessage(error.message||config.strings.addressMissingKey);});},350);syncWorkflow();});menu.addEventListener("click",function(event){var button=event.target.closest(".terricel-address-suggestion");if(!button){return;}if(button.dataset.name&&locationName){locationName.value=button.dataset.name;}if(button.dataset.address&&destination){destination.value=button.dataset.address;}hide();requestEstimate(true,true);syncWorkflow();});document.addEventListener("click",function(event){if(!menu.contains(event.target)&&event.target!==input){hide();}});}setup(locationName,document.getElementById("terricel_trip_location_suggestions"),"location");setup(destination,document.getElementById("terricel_trip_address_suggestions"),"address");if(destination){destination.addEventListener("change",function(){requestEstimate(true,true);});destination.addEventListener("blur",function(){requestEstimate(true,true);});}if(locationName){locationName.addEventListener("change",function(){scheduleEstimate(true,true);});locationName.addEventListener("blur",function(){scheduleEstimate(true,true);});}}
school.addEventListener("change",function(){if(panel){panel.hidden=true;}if(organizationPanel){organizationPanel.hidden=true;}loadGroups();requestEstimate(true,true);});group.addEventListener("change",function(){if(parseInt(group.value,10)>0&&panel){panel.hidden=true;setMessage("");}syncWorkflow();});if(locationName){locationName.addEventListener("input",syncWorkflow);}if(mileage){mileage.addEventListener("input",function(){mileage.dataset.terricelAuto="0";});}if(travelMinutes){travelMinutes.addEventListener("input",function(){travelMinutes.dataset.terricelAuto="0";recalcArrival(true);recalcReturn(true);refreshBusAvailability();checkDriverConflicts();syncWorkflow();});travelMinutes.addEventListener("change",function(){travelMinutes.dataset.terricelAuto="0";recalcArrival(true);recalcReturn(true);refreshBusAvailability();checkDriverConflicts();syncWorkflow();});}
if(organizationToggle&&organizationPanel){organizationToggle.addEventListener("click",function(){organizationPanel.hidden=!organizationPanel.hidden;if(!organizationPanel.hidden){if(panel){panel.hidden=true;}var name=document.getElementById("terricel_trip_new_organization_name");if(name){name.focus();}}});}
if(organizationCancel&&organizationPanel){organizationCancel.addEventListener("click",function(){organizationPanel.hidden=true;setOrganizationMessage("");});}
if(toggle&&panel){toggle.addEventListener("click",function(){if(parseInt(school.value,10)<1){setMessage(config.strings.selectSchool,true);return;}panel.hidden=!panel.hidden;if(!panel.hidden){var name=document.getElementById("terricel_trip_new_group_name");if(name){name.focus();}}});}
if(cancel&&panel){cancel.addEventListener("click",function(){panel.hidden=true;setMessage("");});}
if(conflictConfirm){conflictConfirm.addEventListener("click",function(){setConflictConfirmation(true);if(conflictDialog){conflictDialog.hidden=true;}});}
if(conflictClose){conflictClose.addEventListener("click",function(){if(conflictDialog){conflictDialog.hidden=true;}});}
if(conflictDialog){conflictDialog.addEventListener("click",function(event){if(event.target===conflictDialog){conflictDialog.hidden=true;}});}
if(organizationSave){organizationSave.addEventListener("click",function(){var name=document.getElementById("terricel_trip_new_organization_name");var email=document.getElementById("terricel_trip_new_organization_email");if(!name||!name.value.trim()||!email||!email.value.trim()){setOrganizationMessage(config.strings.enterOrganization,true);var focusTarget=name&&name.value.trim()?email:name;if(focusTarget){focusTarget.focus();}return;}organizationSave.disabled=true;setOrganizationMessage(config.strings.savingOrganization,false);post("terricel_create_trip_organization",{organization_name:name.value,short_name:(document.getElementById("terricel_trip_new_organization_short_name")||{}).value||"",address_1:(document.getElementById("terricel_trip_new_organization_address_1")||{}).value||"",address_2:(document.getElementById("terricel_trip_new_organization_address_2")||{}).value||"",city:(document.getElementById("terricel_trip_new_organization_city")||{}).value||"",state:(document.getElementById("terricel_trip_new_organization_state")||{}).value||"",zip:(document.getElementById("terricel_trip_new_organization_zip")||{}).value||"",email:email.value||""}).then(function(data){if(data.organization){var row=option(data.organization.id,data.organization.label);row.selected=true;school.appendChild(row);school.value=String(data.organization.id);loadGroups();}clearOrganizationPanel();if(organizationPanel){organizationPanel.hidden=true;}setOrganizationMessage(config.strings.organizationSaved,false);requestEstimate(true,true);syncWorkflow();}).catch(function(error){setOrganizationMessage(error.message,true);}).finally(function(){organizationSave.disabled=false;});});}
if(save){save.addEventListener("click",function(){var name=document.getElementById("terricel_trip_new_group_name");var first=document.getElementById("terricel_trip_new_group_advisor_first_name");var last=document.getElementById("terricel_trip_new_group_advisor_last_name");var email=document.getElementById("terricel_trip_new_group_advisor_email");var required=[name,first,last,email];var missing=required.find(function(input){return !input||!input.value.trim();});if(missing){setMessage(config.strings.requiredGroup,true);missing.focus();return;}if(parseInt(school.value,10)<1){setMessage(config.strings.selectSchool,true);return;}save.disabled=true;setMessage(config.strings.saving,false);post("terricel_create_trip_group",{school_id:school.value,group_name:name.value,advisor_first_name:first.value,advisor_last_name:last.value,advisor_main_phone:(document.getElementById("terricel_trip_new_group_advisor_main_phone")||{}).value||"",advisor_main_phone_extension:(document.getElementById("terricel_trip_new_group_advisor_main_phone_extension")||{}).value||"",advisor_emergency_phone:(document.getElementById("terricel_trip_new_group_advisor_emergency_phone")||{}).value||"",advisor_email:email.value||"",billing_address_1:(document.getElementById("terricel_trip_new_group_billing_address_1")||{}).value||"",billing_address_2:(document.getElementById("terricel_trip_new_group_billing_address_2")||{}).value||"",billing_city:(document.getElementById("terricel_trip_new_group_billing_city")||{}).value||"",billing_state:(document.getElementById("terricel_trip_new_group_billing_state")||{}).value||"",billing_zip:(document.getElementById("terricel_trip_new_group_billing_zip")||{}).value||""}).then(function(data){if(data.group){var row=option(data.group.id,data.group.label);row.selected=true;group.appendChild(row);group.value=String(data.group.id);}clearPanel();if(panel){panel.hidden=true;}setMessage(config.strings.saved,false);syncWorkflow();}).catch(function(error){setMessage(error.message,true);}).finally(function(){save.disabled=false;});});}
function highlightActualsFromBilling(){var params=new URLSearchParams(window.location.search||"");if(params.get("terricel-highlight-actuals")!=="1"){return;}var panel=document.getElementById("terricel_trip_assignments");if(panel&&panel.classList.contains("closed")){panel.classList.remove("closed");}var actuals=document.querySelectorAll(".terricel-trip-actuals");if(!actuals.length){return;}actuals.forEach(function(item){item.classList.add("terricel-highlight-actuals");});window.setTimeout(function(){actuals[0].scrollIntoView({behavior:"smooth",block:"center"});},150);}
syncTimeInputs();syncDates();syncBusSlots();initEstimateHelp();initAddressLookup();if(destination&&destination.value.trim()&&parseInt(school.value,10)>0){requestEstimate(true,false);}refreshBusAvailability();checkDriverConflicts();syncWorkflow();highlightActualsFromBilling();
});
}(__CONFIG__));
JS;

        return str_replace('__CONFIG__', wp_json_encode($config), $script);
    }

    public function trip_columns($columns) {
        unset($columns['date']);

        return $this->insert_columns($columns, array(
            'terricel_school' => __('Organization', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
            'terricel_advisor' => __('Primary Contact', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
            'terricel_pickup' => __('Pickup', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
            'terricel_return' => __('Return', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
            'terricel_destination' => __('Destination', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
            'terricel_assignments' => __('Assignments', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
            'terricel_last_modified' => __('Last Modified', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
            'terricel_trip_sheet' => __('Trip Sheet', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
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
        } elseif ('terricel_trip_sheet' === $column) {
            echo '<a class="button button-small" href="' . esc_url($this->get_trip_sheet_download_url($post_id)) . '">' . esc_html__('Download', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</a>';
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
            'terricel_school' => __('Organization', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
            'terricel_advisor' => __('Primary Contact', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
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

    public function organization_columns($columns) {
        return $this->insert_columns($columns, array(
            'terricel_short_name' => __('Short Name', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
            'terricel_address' => __('Address', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
            'terricel_contact' => __('Contact', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
        ));
    }

    public function render_organization_column($column, $post_id) {
        if ('terricel_short_name' === $column) {
            echo esc_html(get_post_meta($post_id, '_terricel_trip_organization_short_name', true));
        } elseif ('terricel_address' === $column) {
            echo esc_html($this->get_school_origin_address($post_id));
        } elseif ('terricel_contact' === $column) {
            $links = array();
            $phone = get_post_meta($post_id, '_terricel_trip_organization_phone', true);
            $email = get_post_meta($post_id, '_terricel_trip_organization_email', true);
            if ($phone) {
                $links[] = '<a href="tel:' . esc_attr($this->get_phone_href($phone)) . '">' . esc_html($phone) . '</a>';
            }
            if ($email) {
                $links[] = '<a href="mailto:' . esc_attr($email) . '">' . esc_html($email) . '</a>';
            }
            echo wp_kses_post(implode('<br>', $links));
        }
    }

    public function filter_title_placeholder($placeholder, $post) {
        if ($post && self::GROUP_POST_TYPE === $post->post_type) {
            return __('Group name, class, grade, teacher, sport, or activity', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN);
        }

        if ($post && self::ORGANIZATION_POST_TYPE === $post->post_type) {
            return __('Organization name', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN);
        }

        return $placeholder;
    }

    public function render_back_to_trips_button($post) {
        if (!$post || !in_array($post->post_type, array(self::TRIP_POST_TYPE, self::GROUP_POST_TYPE, self::ORGANIZATION_POST_TYPE), true)) {
            return;
        }

        $url = admin_url('edit.php?post_type=' . self::TRIP_POST_TYPE);
        $label = __('< Back to Trips', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN);
        if (self::GROUP_POST_TYPE === $post->post_type) {
            $url = admin_url('edit.php?post_type=' . self::GROUP_POST_TYPE);
            $label = __('< Back to Groups', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN);
        } elseif (self::ORGANIZATION_POST_TYPE === $post->post_type) {
            $url = admin_url('edit.php?post_type=' . self::ORGANIZATION_POST_TYPE);
            $label = __('< Back to Organizations', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN);
        }

        echo '<p class="terricel-trips-back-link">';
        if (function_exists('terricel_logistics_render_dynamic_admin_back_button')) {
            terricel_logistics_render_dynamic_admin_back_button($url, $label);
        } else {
            echo '<a class="button" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
        }
        echo '</p>';
    }

    public function render_trip_list_header_actions_script() {
        $screen = get_current_screen();
        if (!$screen || !in_array($screen->post_type, array(self::TRIP_POST_TYPE, self::GROUP_POST_TYPE, self::ORGANIZATION_POST_TYPE), true)) {
            return;
        }

        $trips_url = admin_url('edit.php?post_type=' . self::TRIP_POST_TYPE);
        $groups_url = admin_url('edit.php?post_type=' . self::GROUP_POST_TYPE);
        $organizations_url = admin_url('edit.php?post_type=' . self::ORGANIZATION_POST_TYPE);
        echo '<script>';
        echo '(function(){document.addEventListener("DOMContentLoaded",function(){var addButton=document.querySelector(".wrap .page-title-action");if(!addButton){return;}';
        if (self::GROUP_POST_TYPE === $screen->post_type || self::ORGANIZATION_POST_TYPE === $screen->post_type) {
            echo 'var row=document.createElement("p");row.className="terricel-trips-back-link";var action=document.createElement("a");action.className="button";action.href=' . wp_json_encode($trips_url) . ';action.textContent=' . wp_json_encode(__('< Back to Trips', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN)) . ';row.appendChild(action);addButton.insertAdjacentElement("afterend",row);';
        } else {
            echo 'var groupsAction=document.createElement("a");groupsAction.className="page-title-action";groupsAction.href=' . wp_json_encode($groups_url) . ';groupsAction.textContent=' . wp_json_encode(__('Manage Groups', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN)) . ';addButton.insertAdjacentElement("afterend",groupsAction);var organizationsAction=document.createElement("a");organizationsAction.className="page-title-action";organizationsAction.href=' . wp_json_encode($organizations_url) . ';organizationsAction.textContent=' . wp_json_encode(__('Manage Organizations', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN)) . ';groupsAction.insertAdjacentElement("afterend",organizationsAction);';
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

    private function render_organization_select_field($name, $label, $selected, $empty_label) {
        echo '<p>';
        if ($label) {
            echo '<label for="' . esc_attr($name) . '"><strong>' . esc_html($label) . '</strong></label><br>';
        }
        echo '<select class="widefat" id="' . esc_attr($name) . '" name="' . esc_attr($name) . '">';
        echo '<option value="0">' . esc_html($empty_label) . '</option>';
        foreach ($this->get_organizations_for_select() as $organization) {
            echo '<option value="' . esc_attr($organization->ID) . '"' . selected($selected, $organization->ID, false) . '>' . esc_html($this->get_organization_label($organization->ID)) . '</option>';
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
            $extra .= ' required aria-required="true" data-terricel-required="1"';
        }

        echo '<p>';
        echo '<label for="' . esc_attr($name) . '"><strong>' . esc_html($label) . ($required ? ' <span class="terricel-required">*</span>' : '') . '</strong></label><br>';
        echo '<input class="' . esc_attr($class) . '" type="' . esc_attr($type) . '" id="' . esc_attr($name) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '"' . $extra . '>';
        echo '</p>';
    }

    private function render_group_select_field($school_id, $selected) {
        echo '<p><label for="terricel_trip_group_id"><strong>' . esc_html__('Group', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</strong></label><br>';
        echo '<select class="widefat" id="terricel_trip_group_id" name="terricel_trip_group_id">';
        echo '<option value="0">' . esc_html($school_id > 0 ? __('Select group', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) : __('Select an organization first', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN)) . '</option>';
        foreach ($this->get_groups_for_school($school_id) as $group) {
            echo '<option value="' . esc_attr($group->ID) . '"' . selected($selected, $group->ID, false) . '>' . esc_html($this->get_group_select_label($group->ID)) . '</option>';
        }
        echo '</select></p>';
    }

    private function render_inline_organization_create_panel() {
        echo '<div class="terricel-inline-group-create">';
        echo '<div id="terricel_trip_add_organization_panel" class="terricel-inline-group-panel" hidden>';
        echo '<div class="terricel-inline-group-panel-header">';
        echo '<strong>' . esc_html__('Add Organization', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</strong>';
        echo '<span>' . esc_html__('Organizations receive the bills for group activity.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</span>';
        echo '</div>';
        echo '<div class="terricel-group-details-grid">';
        $this->render_text_field('terricel_trip_new_organization_name', __('Organization Name', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), '', 'text');
        $this->render_text_field('terricel_trip_new_organization_short_name', __('Short Name / Nickname', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), '', 'text');
        $this->render_text_field('terricel_trip_new_organization_address_1', __('Address 1', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), '', 'text');
        $this->render_text_field('terricel_trip_new_organization_address_2', __('Address 2', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), '', 'text');
        $this->render_text_field('terricel_trip_new_organization_city', __('City', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), '', 'text');
        $this->render_text_field('terricel_trip_new_organization_state', __('State', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), '', 'text');
        $this->render_text_field('terricel_trip_new_organization_zip', __('ZIP', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), '', 'text');
        $this->render_text_field('terricel_trip_new_organization_email', __('Email', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), '', 'email');
        echo '</div>';
        echo '<p class="terricel-inline-group-actions"><button type="button" class="button button-primary" id="terricel_trip_save_organization">' . esc_html__('Save Organization', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</button> <button type="button" class="button" id="terricel_trip_cancel_organization">' . esc_html__('Cancel', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</button> <span id="terricel_trip_organization_message" class="description"></span></p>';
        echo '</div></div>';
    }

    private function render_inline_group_create_panel() {
        echo '<div class="terricel-inline-group-create">';
        echo '<div id="terricel_trip_add_group_panel" class="terricel-inline-group-panel" hidden>';
        echo '<div class="terricel-inline-group-panel-header">';
        echo '<strong>' . esc_html__('Add Group', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</strong>';
        echo '<span>' . esc_html__('Create it here and keep building this trip. Organizations receive the bills for group activity.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</span>';
        echo '</div>';
        echo '<div class="terricel-group-details-grid">';
        $this->render_text_field('terricel_trip_new_group_name', __('Group Name', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), '', 'text');
        $this->render_text_field('terricel_trip_new_group_advisor_first_name', __('Primary Contact First Name', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), '', 'text');
        $this->render_text_field('terricel_trip_new_group_advisor_last_name', __('Primary Contact Last Name', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), '', 'text');
        $this->render_text_field('terricel_trip_new_group_advisor_main_phone', __('Main Phone', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), '', 'tel');
        $this->render_text_field('terricel_trip_new_group_advisor_main_phone_extension', __('Extension', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), '', 'text', 'numeric');
        $this->render_text_field('terricel_trip_new_group_advisor_emergency_phone', __('Emergency Phone', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), '', 'tel');
        $this->render_text_field('terricel_trip_new_group_advisor_email', __('Email', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), '', 'email');
        $this->render_text_field('terricel_trip_new_group_billing_address_1', __('Billing Address 1', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), '', 'text');
        $this->render_text_field('terricel_trip_new_group_billing_address_2', __('Billing Address 2', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), '', 'text');
        $this->render_text_field('terricel_trip_new_group_billing_city', __('Billing City', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), '', 'text');
        $this->render_text_field('terricel_trip_new_group_billing_state', __('Billing State', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), '', 'text');
        $this->render_text_field('terricel_trip_new_group_billing_zip', __('Billing ZIP', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN), '', 'text');
        echo '</div>';
        echo '<p class="terricel-inline-group-actions"><button type="button" class="button button-primary" id="terricel_trip_save_group">' . esc_html__('Save Group', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</button> <button type="button" class="button" id="terricel_trip_cancel_group">' . esc_html__('Cancel', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</button> <span id="terricel_trip_group_message" class="description"></span></p>';
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

    private function get_driver_select($name, $selected, $trip_id = 0) {
        $trip_day_keys = $this->get_trip_assignment_day_keys($trip_id);
        $options = '<option value="0">' . esc_html__('No driver', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</option>';
        $has_selected_ineligible_driver = false;

        foreach ($this->get_posts_for_select(Terricel_Logistics_Shared_Data::DRIVER_POST_TYPE) as $driver) {
            $driver_day_keys = $this->get_trip_driver_available_day_keys($driver->ID);
            $is_selected = absint($selected) === absint($driver->ID);
            $is_eligible = $this->driver_covers_trip_day_keys($driver_day_keys, $trip_day_keys);

            $label = get_the_title($driver);
            if (!$is_eligible && $is_selected) {
                $has_selected_ineligible_driver = true;
                $label = sprintf(
                    /* translators: %s: driver name. */
                    __('%s (currently assigned; not eligible for selected trip dates)', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN),
                    $label
                );
            }

            $options .= '<option value="' . esc_attr($driver->ID) . '"' . selected($selected, $driver->ID, false) . (!$is_eligible && !$is_selected ? ' hidden disabled' : '') . ' data-terricel-trip-days="' . esc_attr(implode(',', $driver_day_keys)) . '">' . esc_html($label) . '</option>';
        }

        $html = '<div class="terricel-trip-driver-picker">';
        $html .= '<label class="terricel-trip-add-any-driver-wrap"><input type="checkbox" class="terricel-trip-add-any-driver" value="1"' . checked($has_selected_ineligible_driver, true, false) . '> ' . esc_html__('Add Any Driver', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN) . '</label>';
        $html .= '<select name="' . esc_attr($name) . '" class="terricel-trip-driver-select">' . $options . '</select>';
        $html .= '</div>';
        return $html;
    }

    private function get_trip_driver_available_day_keys($driver_id) {
        $logistics = function_exists('terricel_logistics') ? terricel_logistics() : null;
        $days = $logistics && method_exists($logistics, 'regular_schedule_days') ? array_keys($logistics->regular_schedule_days()) : array('monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday');
        $available = array();

        foreach ($days as $day_key) {
            $date = $this->get_next_date_for_day_key($day_key);
            $has_evening = $logistics && method_exists($logistics, 'is_driver_available_for_run') && $logistics->is_driver_available_for_run($driver_id, $date, 'evening');
            $has_extra = $logistics && method_exists($logistics, 'driver_allows_extra_runs_for_date') && $logistics->driver_allows_extra_runs_for_date($driver_id, $date);
            if ($has_evening || $has_extra) {
                $available[] = $day_key;
            }
        }

        return $available;
    }

    private function driver_covers_trip_day_keys($driver_day_keys, $trip_day_keys) {
        if (empty($trip_day_keys)) {
            return true;
        }

        return count(array_diff($trip_day_keys, $driver_day_keys)) === 0;
    }

    private function get_trip_assignment_day_keys($trip_id) {
        $pickup_date = $trip_id ? (string) get_post_meta($trip_id, '_terricel_trip_pickup_date', true) : '';
        $return_date = $trip_id ? (string) get_post_meta($trip_id, '_terricel_trip_return_date', true) : '';

        return $this->get_day_keys_for_date_range($pickup_date, $return_date);
    }

    private function get_day_keys_for_date_range($start_date, $end_date = '') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $start_date)) {
            return array();
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $end_date)) {
            $end_date = $start_date;
        }

        $start = strtotime($start_date . ' 00:00:00');
        $end = strtotime($end_date . ' 00:00:00');
        if (!$start || !$end) {
            return array();
        }

        if ($end < $start) {
            $end = $start;
        }

        $days = array();
        for ($time = $start; $time <= $end; $time += DAY_IN_SECONDS) {
            $days[] = strtolower(date('l', $time));
        }

        return array_values(array_unique($days));
    }

    private function get_next_date_for_day_key($day_key) {
        $day_key = sanitize_key($day_key);
        $today = current_time('Y-m-d');
        $today_key = strtolower(date('l', strtotime($today)));
        if ($today_key === $day_key) {
            return $today;
        }

        $timestamp = strtotime('next ' . $day_key, current_time('timestamp'));
        return $timestamp ? date('Y-m-d', $timestamp) : $today;
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

    private function get_organizations_for_select() {
        $organizations = array_merge(
            $this->get_posts_for_select(Terricel_Logistics_Shared_Data::SCHOOL_POST_TYPE),
            $this->get_posts_for_select(self::ORGANIZATION_POST_TYPE)
        );

        usort(
            $organizations,
            function ($first, $second) {
                return strnatcasecmp($this->get_organization_label($first->ID), $this->get_organization_label($second->ID));
            }
        );

        return $organizations;
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
        if ($group_id < 1 || !$this->is_valid_trip_organization($school_id) || self::GROUP_POST_TYPE !== get_post_type($group_id)) {
            return false;
        }

        return absint(get_post_meta($group_id, '_terricel_trip_group_school_id', true)) === absint($school_id);
    }

    private function is_valid_trip_organization($organization_id) {
        return $organization_id > 0
            && 'publish' === get_post_status($organization_id)
            && in_array(get_post_type($organization_id), array(Terricel_Logistics_Shared_Data::SCHOOL_POST_TYPE, self::ORGANIZATION_POST_TYPE), true);
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
        $advisor_email = sanitize_email(wp_unslash($_POST['advisor_email'] ?? ''));
        if (!$this->is_valid_trip_organization($school_id)) {
            wp_send_json_error(array('message' => __('Select an organization before adding a group.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN)), 400);
        }
        if ('' === $group_name || '' === $advisor_first_name || '' === $advisor_last_name || !is_email($advisor_email)) {
            wp_send_json_error(array('message' => __('Enter the group name, primary contact first and last name, and a valid email.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN)), 400);
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
        update_post_meta($group_id, '_terricel_trip_group_advisor_email', $advisor_email);
        update_post_meta($group_id, '_terricel_trip_group_billing_address_1', sanitize_text_field(wp_unslash($_POST['billing_address_1'] ?? '')));
        update_post_meta($group_id, '_terricel_trip_group_billing_address_2', sanitize_text_field(wp_unslash($_POST['billing_address_2'] ?? '')));
        update_post_meta($group_id, '_terricel_trip_group_billing_city', sanitize_text_field(wp_unslash($_POST['billing_city'] ?? '')));
        update_post_meta($group_id, '_terricel_trip_group_billing_state', sanitize_text_field(wp_unslash($_POST['billing_state'] ?? '')));
        update_post_meta($group_id, '_terricel_trip_group_billing_zip', sanitize_text_field(wp_unslash($_POST['billing_zip'] ?? '')));

        wp_send_json_success(
            array(
                'group' => array(
                    'id'    => (int) $group_id,
                    'label' => $this->get_group_select_label($group_id),
                ),
            )
        );
    }

    public function ajax_create_trip_organization() {
        if (!current_user_can(Terricel_Transit_Trips_Plugin::CAP_MANAGE_TRIPS)) {
            wp_send_json_error(array('message' => __('You do not have permission to manage trips.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN)), 403);
        }

        check_ajax_referer('terricel_trip_group_ajax', 'nonce');
        $name = sanitize_text_field(wp_unslash($_POST['organization_name'] ?? ''));
        $email = sanitize_email(wp_unslash($_POST['email'] ?? ''));
        if ('' === $name || !is_email($email)) {
            wp_send_json_error(array('message' => __('Enter an organization name and valid email.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN)), 400);
        }

        $organization_id = wp_insert_post(
            array(
                'post_type'   => self::ORGANIZATION_POST_TYPE,
                'post_status' => 'publish',
                'post_title'  => $name,
            ),
            true
        );
        if (is_wp_error($organization_id)) {
            wp_send_json_error(array('message' => $organization_id->get_error_message()), 500);
        }

        update_post_meta($organization_id, '_terricel_trip_organization_short_name', sanitize_text_field(wp_unslash($_POST['short_name'] ?? '')));
        update_post_meta($organization_id, '_terricel_trip_organization_address_1', sanitize_text_field(wp_unslash($_POST['address_1'] ?? '')));
        update_post_meta($organization_id, '_terricel_trip_organization_address_2', sanitize_text_field(wp_unslash($_POST['address_2'] ?? '')));
        update_post_meta($organization_id, '_terricel_trip_organization_city', sanitize_text_field(wp_unslash($_POST['city'] ?? '')));
        update_post_meta($organization_id, '_terricel_trip_organization_state', sanitize_text_field(wp_unslash($_POST['state'] ?? '')));
        update_post_meta($organization_id, '_terricel_trip_organization_zip', sanitize_text_field(wp_unslash($_POST['zip'] ?? '')));
        update_post_meta($organization_id, '_terricel_trip_organization_email', $email);

        wp_send_json_success(
            array(
                'organization' => array(
                    'id'    => (int) $organization_id,
                    'label' => $this->get_organization_label($organization_id),
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
        if (!$this->is_valid_trip_organization($school_id) || '' === $destination) {
            wp_send_json_error(array('message' => __('Select an organization and destination first.', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN)), 400);
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

        $title = !empty($parts) ? implode(' ', $parts) : __('Draft trip', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN);

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
        return $this->get_organization_label($school_id);
    }

    private function get_organization_label($organization_id) {
        if ($organization_id < 1 || 'publish' !== get_post_status($organization_id)) {
            return __('Unassigned', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN);
        }

        if (self::ORGANIZATION_POST_TYPE === get_post_type($organization_id)) {
            $short_name = get_post_meta($organization_id, '_terricel_trip_organization_short_name', true);
            return $short_name ? $short_name : get_the_title($organization_id);
        }

        $short_name = get_post_meta($organization_id, '_terricel_school_short_name', true);
        return $short_name ? $short_name : get_the_title($organization_id);
    }

    private function is_other_organization($organization_id) {
        if ($organization_id < 1 || self::ORGANIZATION_POST_TYPE !== get_post_type($organization_id)) {
            return false;
        }

        if (get_post_meta($organization_id, '_terricel_trip_organization_is_other', true)) {
            return true;
        }

        return 0 === strcasecmp(trim((string) get_the_title($organization_id)), 'Other');
    }

    private function get_organization_billing_email($organization_id) {
        if (self::ORGANIZATION_POST_TYPE === get_post_type($organization_id)) {
            return sanitize_email(get_post_meta($organization_id, '_terricel_trip_organization_email', true));
        }

        return sanitize_email(get_post_meta($organization_id, '_terricel_school_email', true));
    }

    private function get_group_billing_address($group_id) {
        $parts = array(
            get_post_meta($group_id, '_terricel_trip_group_billing_address_1', true),
            get_post_meta($group_id, '_terricel_trip_group_billing_address_2', true),
            trim(
                implode(
                    ' ',
                    array_filter(
                        array(
                            get_post_meta($group_id, '_terricel_trip_group_billing_city', true),
                            get_post_meta($group_id, '_terricel_trip_group_billing_state', true),
                            get_post_meta($group_id, '_terricel_trip_group_billing_zip', true),
                        ),
                        'strlen'
                    )
                )
            ),
        );

        $parts = array_filter(array_map('trim', $parts), 'strlen');
        return $parts ? implode(', ', $parts) : '';
    }

    private function get_trip_group_name($trip_id) {
        $group_id = absint(get_post_meta($trip_id, '_terricel_trip_group_id', true));
        return $group_id > 0 ? get_the_title($group_id) : __('Not set', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN);
    }

    private function get_trip_primary_contact_name($trip_id) {
        $group_id = absint(get_post_meta($trip_id, '_terricel_trip_group_id', true));
        return $group_id > 0 ? $this->get_group_advisor_name($group_id) : __('Not set', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN);
    }

    private function get_trip_driver_names($trip_id) {
        $names = array();
        foreach ($this->get_trip_assignments($trip_id) as $assignment) {
            $driver_id = absint($assignment['driver_id'] ?? 0);
            if ($driver_id > 0) {
                $names[] = get_the_title($driver_id);
            }
        }

        $names = array_values(array_unique(array_filter($names)));
        return $names ? implode(', ', $names) : __('No drivers assigned', TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN);
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

    private function maybe_flag_required_email_missing($post_id, $email, $notice_key) {
        if (is_email($email)) {
            return;
        }

        set_transient($notice_key . '_' . get_current_user_id(), absint($post_id), 60);
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
