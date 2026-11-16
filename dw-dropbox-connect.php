<?php
/**
 * Plugin Name: DW Dropbox Connect
 * Plugin URI: https://github.com/davidwilliamcosta/dw-dropbox-connect
 * Description: Conecta uma conta pessoal do Dropbox ao seletor de mídia e publica galerias de pastas do Dropbox no site, que acompanham as mudanças feitas na pasta.
 * Version: 0.2.0
 * Requires at least: 6.2
 * Requires PHP: 7.4
 * Author: David William da Costa
 * Author URI: https://github.com/davidwilliamcosta
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: dw-dropbox-connect
 * Update URI: https://github.com/davidwilliamcosta/dw-dropbox-connect
 *
 * @package DW_Dropbox_Connect
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

define( 'DWDC_VERSION', '0.2.0' );
define( 'DWDC_FILE', __FILE__ );
define( 'DWDC_PATH', plugin_dir_path( __FILE__ ) );
define( 'DWDC_URL', plugin_dir_url( __FILE__ ) );

require_once DWDC_PATH . 'includes/class-dwdc-crypto.php';
require_once DWDC_PATH . 'includes/class-dwdc-normalizer.php';
require_once DWDC_PATH . 'includes/class-dwdc-oauth.php';
require_once DWDC_PATH . 'includes/class-dwdc-api.php';
require_once DWDC_PATH . 'includes/class-dwdc-importer.php';
require_once DWDC_PATH . 'includes/class-dwdc-exporter.php';
require_once DWDC_PATH . 'includes/class-dwdc-rest.php';
require_once DWDC_PATH . 'includes/class-dwdc-feeds.php';
require_once DWDC_PATH . 'includes/class-dwdc-gallery.php';
require_once DWDC_PATH . 'includes/class-dwdc-feeds-admin.php';
require_once DWDC_PATH . 'includes/class-dwdc-updater.php';
require_once DWDC_PATH . 'includes/class-dwdc-plugin.php';

register_activation_hook( __FILE__, array( 'DWDC_Plugin', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'DWDC_Feeds', 'clear_schedule' ) );

add_action( 'before_woocommerce_init', static function () {
    if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', DWDC_FILE, true );
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', DWDC_FILE, true );
    }
} );

add_action( 'plugins_loaded', static function () {
    DWDC_Plugin::instance();
} );
