<?php
/**
 * Plugin Name:       Woo Export Contacts
 * Plugin URI:        https://github.com/SOYOO974/woo-export-contacts
 * Description:       Exportation CSV ultra-propre, dédoublonnée et performante des contacts marketing (Comptes, Commandes WooCommerce HPOS & Legacy, Newsletter Native & MailPoet, Paniers Abandonnés, Alertes Waitlist) + collecte opt-in newsletter native (Checkout, Shortcode AJAX & hook fidélité). Optimisé pour ManyChat (WhatsApp), Emailit, Klaviyo et Brevo.
 * Version:           2.5.0
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
define( 'WOO_EXPORT_CONTACTS_VERSION', '2.5.0' );
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
require_once WOO_EXPORT_CONTACTS_PLUGIN_DIR . 'includes/class-woo-export-contacts-newsletter.php';
require_once WOO_EXPORT_CONTACTS_PLUGIN_DIR . 'includes/class-woo-export-contacts-engine.php';
require_once WOO_EXPORT_CONTACTS_PLUGIN_DIR . 'includes/class-woo-export-contacts-admin.php';

register_activation_hook( __FILE__, array( 'Woo_Export_Contacts_Newsletter', 'install_table' ) );

if ( ! function_exists( 'woo_export_contacts_is_subscribed' ) ) {
	/**
	 * Vérifie si une adresse e-mail est activement inscrite à la newsletter
	 * (table native `wec_newsletter_subscribers` ou abonné actif MailPoet).
	 *
	 * @param string $email Adresse e-mail brute ou normalisée.
	 * @return bool
	 */
	function woo_export_contacts_is_subscribed( string $email ): bool {
		return Woo_Export_Contacts_Newsletter::is_subscribed( $email );
	}
}

if ( ! function_exists( 'woo_export_contacts_subscribe_newsletter' ) ) {
	/**
	 * Inscrit une adresse e-mail à la newsletter de manière idempotente
	 * et déclenche `woo_export_contacts_newsletter_subscribed` lors d'une première inscription ou ré-inscription.
	 *
	 * @param string $email   Adresse e-mail brute ou normalisée.
	 * @param array  $context Métadonnées optionnelles (`source`, `first_name`, `last_name`, `order_id`...).
	 * @return bool
	 */
	function woo_export_contacts_subscribe_newsletter( string $email, array $context = array() ): bool {
		return Woo_Export_Contacts_Newsletter::subscribe( $email, $context );
	}
}

function woo_export_contacts_init() {
	$newsletter = new Woo_Export_Contacts_Newsletter();
	$newsletter->init();

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
