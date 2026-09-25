<?php
/**
 * Plugin Name:       Woo Export Contacts
 * Plugin URI:        https://github.com/SOYOO974/woo-export-contacts
 * Description:       Exportation CSV ultra-propre, dédoublonnée et performante des contacts marketing (Comptes, Commandes WooCommerce HPOS & Legacy, Paniers Abandonnés, Newsletter MailPoet, Alertes Waitlist). Optimisé pour Emailit, Klaviyo et Brevo.
 * Version:           2.0.0
 * Author:            SOYOO
 * Author URI:        https://soyoo.re
 * License:           GPLv2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       woo-export-contacts
 * Domain Path:       /languages
 * Requires at least: 5.8
 * Requires PHP:      7.4
 * WC requires at least: 5.0
 * WC tested up to:   9.4
 *
 * @package WooExportContacts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// 1. Définition des constantes globales
define( 'WOO_EXPORT_CONTACTS_VERSION', '2.0.0' );
define( 'WOO_EXPORT_CONTACTS_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'WOO_EXPORT_CONTACTS_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'WOO_EXPORT_CONTACTS_BASENAME', plugin_basename( __FILE__ ) );

// 2. Initialisation de Plugin Update Checker pour GitHub Releases
if ( file_exists( WOO_EXPORT_CONTACTS_PLUGIN_DIR . 'plugin-update-checker/plugin-update-checker.php' ) ) {
	require_once WOO_EXPORT_CONTACTS_PLUGIN_DIR . 'plugin-update-checker/plugin-update-checker.php';

	$woo_export_contacts_update_checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker(
		'https://github.com/SOYOO974/woo-export-contacts',
		__FILE__,
		'woo-export-contacts'
	);

	// Branche de production par défaut
	$woo_export_contacts_update_checker->setBranch( 'main' );

	// Support des assets de release GitHub (.zip)
	$woo_export_contacts_update_checker->getVcsApi()->enableReleaseAssets();
}

// 3. Déclaration de compatibilité WooCommerce High-Performance Order Storage (HPOS)
add_action( 'before_woocommerce_init', function() {
	if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
	}
} );

// 4. Chargement des dépendances et initialisation
require_once WOO_EXPORT_CONTACTS_PLUGIN_DIR . 'includes/class-woo-export-contacts-sanitizer.php';
require_once WOO_EXPORT_CONTACTS_PLUGIN_DIR . 'includes/class-woo-export-contacts-engine.php';
require_once WOO_EXPORT_CONTACTS_PLUGIN_DIR . 'includes/class-woo-export-contacts-admin.php';

function woo_export_contacts_init() {
	$admin = new Woo_Export_Contacts_Admin();
	$admin->init();
}
add_action( 'plugins_loaded', 'woo_export_contacts_init' );

// 5. Lien d'action rapide dans la liste des extensions WordPress
add_filter( 'plugin_action_links_' . WOO_EXPORT_CONTACTS_BASENAME, function( $links ) {
	$export_link = sprintf(
		'<a href="%s" style="font-weight: 700; color: #e2001a;">%s</a>',
		esc_url( admin_url( 'admin.php?page=conforama-export-contacts' ) ),
		esc_html__( 'Exporter les contacts', 'woo-export-contacts' )
	);
	array_unshift( $links, $export_link );
	return $links;
} );
