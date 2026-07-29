<?php
/**
 * Plugin Name: Terricel Transit Trips
 * Plugin URI: https://kineticmktg.com
 * Description: Child trip coordination module for Terricel Transit Operations.
 * Version: 0.2.24
 * Author: Kinetic Marketing LLC
 * Author URI: https://kineticmktg.com
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: terricel-transit-trips
 */

if (!defined('ABSPATH')) {
    exit;
}

define('TERRICEL_TRANSIT_TRIPS_VERSION', '0.2.24');
define('TERRICEL_TRANSIT_TRIPS_FILE', __FILE__);
define('TERRICEL_TRANSIT_TRIPS_PATH', plugin_dir_path(__FILE__));
define('TERRICEL_TRANSIT_TRIPS_URL', plugin_dir_url(__FILE__));
define('TERRICEL_TRANSIT_TRIPS_TEXT_DOMAIN', 'terricel-transit-trips');

require_once TERRICEL_TRANSIT_TRIPS_PATH . 'includes/class-terricel-transit-trips-plugin.php';

register_activation_hook(__FILE__, array('Terricel_Transit_Trips_Plugin', 'activate'));
register_deactivation_hook(__FILE__, array('Terricel_Transit_Trips_Plugin', 'deactivate'));

function terricel_transit_trips() {
    static $plugin = null;

    if (null === $plugin) {
        $plugin = new Terricel_Transit_Trips_Plugin();
    }

    return $plugin;
}

terricel_transit_trips()->hooks();
