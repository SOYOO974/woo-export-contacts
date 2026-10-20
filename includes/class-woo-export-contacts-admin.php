<?php
/**
 * Admin interface and form handler for Woo Export Contacts.
 *
 * @package WooExportContacts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Woo_Export_Contacts_Admin
 */
class Woo_Export_Contacts_Admin {

	/**
	 * Initialise les hooks d'administration.
	 */
	public function init() {
		// 1. Neutralisation préventive de l'ancien extrait legacy s'il est actif sur le site
		add_action( 'admin_menu', array( $this, 'unregister_legacy_duplicate' ), 1 );
		add_action( 'admin_menu', array( $this, 'register_admin_menus' ), 999 );
		add_action( 'admin_post_conforama_export_contacts_csv', array( $this, 'handle_export_submission' ), 1 );
		add_action( 'admin_post_woo_export_contacts_csv', array( $this, 'handle_export_submission' ), 1 );
		add_action( 'admin_post_woo_export_contacts_update_checkpoint', array( $this, 'handle_checkpoint_update' ), 1 );
		add_action( 'admin_init', array( $this, 'maybe_suppress_admin_notices_early' ) );
	}

	/**
	 * Neutralise l'enregistrement de l'ancien extrait de code legacy pour éviter les formulaires en doublon.
	 */
	public function unregister_legacy_duplicate() {
		if ( function_exists( 'conforama_export_contacts_register_menu' ) ) {
			remove_action( 'admin_menu', 'conforama_export_contacts_register_menu', 99 );
		}
		if ( function_exists( 'conforama_export_contacts_process_csv' ) ) {
			remove_action( 'admin_post_conforama_export_contacts_csv', 'conforama_export_contacts_process_csv' );
		}
	}

	/**
	 * Détection précoce sur admin_init pour désactiver les notifications avant leur exécution.
	 */
	public function maybe_suppress_admin_notices_early() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_text_field( wp_unslash( $_GET['page'] ) ) : '';
		if ( in_array( $page, array( 'conforama-export-contacts', 'woo-export-contacts' ), true ) ) {
			$this->prepare_admin_screen();
		}
	}

	/**
	 * Prépare l'écran d'administration et neutralise toutes les notifications admin tierces.
	 */
	public function prepare_admin_screen() {
		$this->clear_all_admin_notices();

		// Filet de sécurité au cas où des extensions tierces ajoutent des hooks plus tard (ex: sur current_screen ou admin_head)
		add_action( 'in_admin_header', array( $this, 'clear_all_admin_notices' ), PHP_INT_MAX );
		add_action( 'admin_head', array( $this, 'clear_all_admin_notices' ), PHP_INT_MAX );
		add_action( 'admin_print_styles', array( $this, 'inject_clean_screen_styles' ) );
	}

	/**
	 * Supprime tous les hooks enregistrés sur les actions de notifications WordPress.
	 */
	public function clear_all_admin_notices() {
		remove_all_actions( 'admin_notices' );
		remove_all_actions( 'all_admin_notices' );
		remove_all_actions( 'user_admin_notices' );
		remove_all_actions( 'network_admin_notices' );
	}

	/**
	 * Injecte les styles CSS d'isolation pour masquer tout résidu de notification.
	 */
	public function inject_clean_screen_styles() {
		?>
		<style type="text/css">
			.woocommerce_page_conforama-export-contacts .notice,
			.woocommerce_page_conforama-export-contacts .notice-error,
			.woocommerce_page_conforama-export-contacts .notice-warning,
			.woocommerce_page_conforama-export-contacts .notice-success,
			.woocommerce_page_conforama-export-contacts .notice-info,
			.woocommerce_page_conforama-export-contacts .updated,
			.woocommerce_page_conforama-export-contacts .error,
			.woocommerce_page_conforama-export-contacts .is-dismissible,
			.woocommerce_page_conforama-export-contacts .woocommerce-message,
			.woocommerce_page_conforama-export-contacts .woocommerce-error,
			.woocommerce_page_conforama-export-contacts .update-nag,
			.woocommerce_page_conforama-export-contacts #wpbody-content > .notice,
			.woocommerce_page_conforama-export-contacts #wpbody-content > div.updated,
			.woocommerce_page_conforama-export-contacts #wpbody-content > div.error,
			.woocommerce_page_conforama-export-contacts .woocommerce-layout__header,
			.woocommerce_page_conforama-export-contacts .woocommerce-layout__notice-list,
			.admin_page_woo-export-contacts .notice,
			.admin_page_woo-export-contacts .notice-error,
			.admin_page_woo-export-contacts .notice-warning,
			.admin_page_woo-export-contacts .notice-success,
			.admin_page_woo-export-contacts .notice-info,
			.admin_page_woo-export-contacts .updated,
			.admin_page_woo-export-contacts .error,
			.admin_page_woo-export-contacts .is-dismissible,
			.admin_page_woo-export-contacts .woocommerce-message,
			.admin_page_woo-export-contacts .woocommerce-error,
			.admin_page_woo-export-contacts .update-nag,
			.admin_page_woo-export-contacts #wpbody-content > .notice,
			.admin_page_woo-export-contacts #wpbody-content > div.updated,
			.admin_page_woo-export-contacts #wpbody-content > div.error,
			.admin_page_woo-export-contacts .woocommerce-layout__header,
			.admin_page_woo-export-contacts .woocommerce-layout__notice-list,
			.woo-export-contacts-wrap .notice,
			.woo-export-contacts-wrap .updated,
			.woo-export-contacts-wrap .error,
			.woo-export-contacts-wrap .woocommerce-message,
			.woo-export-contacts-wrap .woocommerce-error,
			.woo-export-contacts-wrap .update-nag,
			.woo-export-contacts-wrap ~ .notice,
			.woo-export-contacts-wrap ~ .updated,
			.woo-export-contacts-wrap ~ .error {
				display: none !important;
			}
		</style>
		<?php
	}

	/**
	 * Enregistre les pages de menu dans l'administration WooCommerce.
	 */
	public function register_admin_menus() {
		// 1. Nettoyage de tout sous-menu orphelin ou legacy déjà enregistré
		remove_submenu_page( 'woocommerce', 'conforama-export-contacts' );
		remove_submenu_page( 'woocommerce', 'woo-export-contacts' );

		// 2. Désactivation du rendu de la fonction legacy si elle a été attachée au hook d'écran
		if ( function_exists( 'conforama_export_contacts_render_admin_page' ) ) {
			remove_action( 'woocommerce_page_conforama-export-contacts', 'conforama_export_contacts_render_admin_page' );
		}

		// 3. Menu principal visible sous WooCommerce (intitulé court et clair)
		$capability = current_user_can( 'manage_woocommerce' ) ? 'manage_woocommerce' : 'manage_options';

		$main_page = add_submenu_page(
			'woocommerce',
			__( 'Export Contacts (Emailit & WhatsApp)', 'woo-export-contacts' ),
			__( 'Export Contacts', 'woo-export-contacts' ),
			$capability,
			'conforama-export-contacts',
			array( $this, 'render_admin_page' )
		);

		// Alias sous-jacent pour compatibilité d'URL directe page=woo-export-contacts
		$alias_page = add_submenu_page(
			null,
			__( 'Export Contacts (Emailit & WhatsApp)', 'woo-export-contacts' ),
			__( 'Export Contacts', 'woo-export-contacts' ),
			$capability,
			'woo-export-contacts',
			array( $this, 'render_admin_page' )
		);

		if ( $main_page ) {
			add_action( 'load-' . $main_page, array( $this, 'prepare_admin_screen' ) );
		}
		if ( $alias_page ) {
			add_action( 'load-' . $alias_page, array( $this, 'prepare_admin_screen' ) );
		}
	}

	/**
	 * Intercepte et traite la soumission du formulaire d'export.
	 */
	public function handle_export_submission() {
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Action non autorisée : permissions insuffisantes.', 'woo-export-contacts' ) );
		}

		// Validation du jeton de sécurité (supporte à la fois conforama_export_contacts_nonce et woo_export_contacts_nonce)
		$nonce_verified = false;
		if ( isset( $_POST['conforama_export_contacts_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['conforama_export_contacts_nonce'] ) ), 'conforama_export_contacts_action' ) ) {
			$nonce_verified = true;
		} elseif ( isset( $_POST['woo_export_contacts_nonce'] ) && wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['woo_export_contacts_nonce'] ) ), 'woo_export_contacts_action' ) ) {
			$nonce_verified = true;
		}

		if ( ! $nonce_verified ) {
			wp_die( esc_html__( 'Échec de la validation de sécurité CSRF (nonce expiré). Veuillez rafraîchir la page et réessayer.', 'woo-export-contacts' ) );
		}

		// Sauvegarde persistante de la liste des domaines exclus configurés par l'utilisateur
		if ( isset( $_POST['excluded_domains'] ) ) {
			$raw_excluded = sanitize_textarea_field( wp_unslash( $_POST['excluded_domains'] ) );
			update_option( 'woo_export_contacts_excluded_domains', $raw_excluded );
		}

		// Délégation au moteur d'exportation
		Woo_Export_Contacts_Engine::export_csv( wp_unslash( $_POST ) );
	}

	/**
	 * Met à jour ou réinitialise manuellement le point de repère (date du dernier export).
	 */
	public function handle_checkpoint_update() {
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Action non autorisée : permissions insuffisantes.', 'woo-export-contacts' ) );
		}

		if ( ! isset( $_POST['woo_export_contacts_checkpoint_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['woo_export_contacts_checkpoint_nonce'] ) ), 'woo_export_contacts_checkpoint_action' ) ) {
			wp_die( esc_html__( 'Échec de la validation de sécurité CSRF. Veuillez rafraîchir la page et réessayer.', 'woo-export-contacts' ) );
		}

		$operation = isset( $_POST['checkpoint_operation'] ) ? sanitize_text_field( wp_unslash( $_POST['checkpoint_operation'] ) ) : 'set';
		$channel   = isset( $_POST['checkpoint_channel'] ) ? sanitize_text_field( wp_unslash( $_POST['checkpoint_channel'] ) ) : 'all';
		if ( ! in_array( $channel, array( 'all', 'email', 'whatsapp' ), true ) ) {
			$channel = 'all';
		}

		if ( 'reset' === $operation ) {
			Woo_Export_Contacts_Engine::update_manual_checkpoint( $channel, null );
		} else {
			$manual_date = isset( $_POST['manual_checkpoint_date'] ) ? sanitize_text_field( wp_unslash( $_POST['manual_checkpoint_date'] ) ) : '';
			$manual_time = isset( $_POST['manual_checkpoint_time'] ) ? sanitize_text_field( wp_unslash( $_POST['manual_checkpoint_time'] ) ) : '00:00';

			if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $manual_date ) ) {
				if ( preg_match( '/^\d{2}:\d{2}$/', $manual_time ) ) {
					$manual_time .= ':00';
				} elseif ( ! preg_match( '/^\d{2}:\d{2}:\d{2}$/', $manual_time ) ) {
					$manual_time = '00:00:00';
				}
				Woo_Export_Contacts_Engine::update_manual_checkpoint( $channel, $manual_date . ' ' . $manual_time );
			}
		}

		wp_safe_redirect( admin_url( 'admin.php?page=conforama-export-contacts&checkpoint_updated=1' ) );
		exit;
	}

	/**
	 * Formate une entrée de dernier export pour l'affichage dans l'interface.
	 *
	 * @param array|null $entry Entrée d'historique d'export.
	 * @return array Données formatées pour l'UI.
	 */
	private function format_checkpoint_for_ui( $entry ) {
		if ( empty( $entry ) || empty( $entry['timestamp_local'] ) ) {
			return array(
				'exists'          => false,
				'timestamp_local' => '',
				'date_ymd'        => '',
				'time_hm'         => '',
				'display_date'    => 'Aucun export enregistré',
				'display_meta'    => 'Le prochain export initialisera ce compteur automatiquement.',
			);
		}

		$ts_local = $entry['timestamp_local'];
		$ts_unix  = strtotime( $ts_local );
		$date_ymd = gmdate( 'Y-m-d', $ts_unix );
		$time_hm  = gmdate( 'H:i', $ts_unix );
		$date_fr  = gmdate( 'd/m/Y \à H:i', $ts_unix );
		$count    = isset( $entry['count'] ) ? (int) $entry['count'] : 0;

		if ( $count < 0 ) {
			$meta = 'Point de repère défini manuellement';
		} elseif ( 1 === $count ) {
			$meta = '1 contact exporté lors de cette extraction';
		} else {
			$meta = sprintf( '%d contacts exportés lors de cette extraction', $count );
		}

		if ( ! empty( $entry['user_login'] ) ) {
			$meta .= ' (par ' . $entry['user_login'] . ')';
		}

		return array(
			'exists'          => true,
			'timestamp_local' => $ts_local,
			'date_ymd'        => $date_ymd,
			'time_hm'         => $time_hm,
			'display_date'    => $date_fr,
			'display_meta'    => $meta,
		);
	}

	/**
	 * Affiche l'interface d'exportation de contacts.
	 */
	public function render_admin_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Vous n\'avez pas les permissions nécessaires pour accéder à cette page.', 'woo-export-contacts' ) );
		}

		// Verrouillage anti-doublon : garantit un rendu unique même si un hook tiers se ré-exécute
		static $rendered = false;
		if ( $rendered ) {
			return;
		}
		$rendered = true;

		global $wpdb;

		// Détection des tables et fonctionnalités tierces
		$table_cartflows = $wpdb->prefix . 'cartflows_ca_cart_abandonment';
		$has_cartflows   = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_cartflows ) ) === $table_cartflows;

		$table_mailpoet = $wpdb->prefix . 'mailpoet_subscribers';
		$has_mailpoet   = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_mailpoet ) ) === $table_mailpoet;

		$table_waitlist = $wpdb->prefix . 'cwginstocknotifier';
		$has_waitlist   = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_waitlist ) ) === $table_waitlist;

		$hpos_enabled = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

		$today_local        = function_exists( 'current_time' ) ? current_time( 'Y-m-d' ) : gmdate( 'Y-m-d' );
		$default_start_date = gmdate( 'Y-m-01', strtotime( $today_local ) ); // 1er jour du mois en cours
		$default_end_date   = $today_local;                                 // Aujourd'hui
		$action_url         = admin_url( 'admin-post.php' );

		// Récupération de l'historique des derniers exports (par canal et global)
		$last_exports = Woo_Export_Contacts_Engine::get_last_exports();
		$ui_email     = $this->format_checkpoint_for_ui( $last_exports['email'] ?? $last_exports['any'] );
		$ui_whatsapp  = $this->format_checkpoint_for_ui( $last_exports['whatsapp'] ?? $last_exports['any'] );
		$ui_any       = $this->format_checkpoint_for_ui( $last_exports['any'] );

		// Calcul de la date d'origine du site (première commande ou premier utilisateur enregistré)
		$earliest_date   = '2015-01-01';
		$first_user_date = $wpdb->get_var( "SELECT MIN(user_registered) FROM {$wpdb->users} WHERE user_registered != '0000-00-00 00:00:00'" );
		if ( ! empty( $first_user_date ) ) {
			$earliest_date = gmdate( 'Y-m-d', strtotime( $first_user_date ) );
		}
		if ( $hpos_enabled ) {
			$first_order_date = $wpdb->get_var( "SELECT MIN(date_created_gmt) FROM {$wpdb->prefix}wc_orders WHERE date_created_gmt IS NOT NULL" );
		} else {
			$first_order_date = $wpdb->get_var( "SELECT MIN(post_date) FROM {$wpdb->posts} WHERE post_type IN ('shop_order', 'shop_order_placehold') AND post_date != '0000-00-00 00:00:00'" );
		}
		if ( ! empty( $first_order_date ) ) {
			$first_order_ymd = gmdate( 'Y-m-d', strtotime( $first_order_date ) );
			if ( $first_order_ymd < $earliest_date && $first_order_ymd > '2000-01-01' ) {
				$earliest_date = $first_order_ymd;
			}
		}

		// Préfixe de tag par défaut
		$site_host   = wp_parse_url( home_url(), PHP_URL_HOST );
		$is_confo    = false !== stripos( (string) $site_host, 'conforama' );
		$site_domain = $site_host ? strtolower( preg_replace( '/^www\./i', '', $site_host ) ) : '';
		$default_tag = $is_confo ? 'confo' : sanitize_key( preg_replace( '/[^a-zA-Z0-9]/', '', (string) get_bloginfo( 'name' ) ) );
		if ( empty( $default_tag ) ) {
			$default_tag = 'confo';
		}

		// Domaines exclus par défaut : domaine du site courant + soyoo.re
		$default_domains = array();
		if ( ! empty( $site_domain ) ) {
			$default_domains[] = $site_domain;
		}
		if ( ! in_array( 'soyoo.re', $default_domains, true ) ) {
			$default_domains[] = 'soyoo.re';
		}

		$saved_excluded_domains = get_option( 'woo_export_contacts_excluded_domains', null );
		if ( null !== $saved_excluded_domains && is_string( $saved_excluded_domains ) ) {
			$excluded_domains_val = $saved_excluded_domains;
		} else {
			$excluded_domains_val = implode( "\n", $default_domains );
		}

		$version_display = defined( 'WOO_EXPORT_CONTACTS_VERSION' ) ? WOO_EXPORT_CONTACTS_VERSION : '2.4.0';
		$badge_text      = $is_confo ? 'Conforama.re &bull; v' . $version_display : esc_html( get_bloginfo( 'name' ) ) . ' &bull; v' . $version_display;
		?>
		<div class="wrap woo-export-contacts-wrap" style="max-width: 980px; margin-top: 25px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;">
			
			<style type="text/css">
				.woo-export-contacts-wrap .notice,
				.woo-export-contacts-wrap .updated,
				.woo-export-contacts-wrap .error,
				.woo-export-contacts-wrap .is-dismissible,
				.woo-export-contacts-wrap .woocommerce-message,
				.woo-export-contacts-wrap .woocommerce-error,
				.woo-export-contacts-wrap .update-nag,
				#wpbody-content > .notice,
				#wpbody-content > .updated,
				#wpbody-content > .error,
				#wpbody-content > .woocommerce-message,
				#wpbody-content > .update-nag {
					display: none !important;
				}
				.woo-checkpoint-card {
					background: #f8fafc;
					border: 1px solid #cbd5e1;
					border-left: 4px solid #0f766e;
					border-radius: 8px;
					padding: 18px 22px;
					margin-bottom: 24px;
				}
				.woo-checkpoint-channel-box {
					background: #ffffff;
					border: 1px solid #e2e8f0;
					border-radius: 6px;
					padding: 10px 14px;
					transition: all 0.2s ease;
				}
				.woo-checkpoint-channel-box.is-active-channel {
					border-color: #0d9488;
					box-shadow: 0 0 0 1px #0d9488;
					background: #f0fdfa;
				}
			</style>

			<!-- EN-TETE CONFORAMA / SOYOO -->
			<div style="display: flex; align-items: center; justify-content: space-between; border-bottom: 3px solid #e2001a; padding-bottom: 16px; margin-bottom: 25px;">
				<div>
					<h1 style="font-weight: 800; font-size: 24px; color: #111111; margin: 0 0 6px 0; display: flex; align-items: center; gap: 10px;">
						<span class="dashicons dashicons-email-alt2" style="font-size: 32px; width: 32px; height: 32px; color: #e2001a;"></span>
						Export Contacts Marketing &bull; Emailit &amp; ManyChat WhatsApp
					</h1>
					<p style="color: #666666; font-size: 13px; margin: 0;">
						Générez un fichier CSV nettoyé, dédoublonné et prêt à importer en 1 clic dans <strong>Emailit</strong> (ou Klaviyo, Brevo) et <strong>ManyChat (WhatsApp)</strong>.
					</p>
				</div>
				<div style="display: flex; align-items: center; gap: 8px;">
					<span style="background: <?php echo $hpos_enabled ? '#dcfce7; color: #166534;' : '#fef3c7; color: #92400e;'; ?> font-weight: 700; font-size: 11px; padding: 5px 10px; border-radius: 20px; text-transform: uppercase;">
						<?php echo $hpos_enabled ? 'HPOS Actif' : 'Commandes Legacy'; ?>
					</span>
					<div style="background: #fff000; color: #111111; font-weight: 800; font-size: 11px; padding: 6px 12px; border-radius: 20px; text-transform: uppercase; letter-spacing: 0.5px;">
						<?php echo wp_kses_post( $badge_text ); ?>
					</div>
				</div>
			</div>
			<hr class="wp-header-end" style="display: none !important;">

			<div style="background: #ffffff; border: 1px solid #dcdcde; border-radius: 8px; padding: 28px; box-shadow: 0 2px 8px rgba(0,0,0,0.04);">

				<!-- BANDEAU POINT DE REPERE : DERNIER EXPORT & TELECHARGEMENT INCREMENTAL 1-CLIC -->
				<div class="woo-checkpoint-card">
					<div style="display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 16px;">
						<div style="flex: 1 1 460px;">
							<div style="display: flex; align-items: center; gap: 8px; margin-bottom: 10px;">
								<span class="dashicons dashicons-backup" style="color: #0f766e; font-size: 20px; width: 20px; height: 20px;"></span>
								<strong style="font-size: 14px; color: #0f172a;">Point de repère &bull; Suivi du dernier export</strong>
								<button type="button" id="toggle-manual-checkpoint-btn" style="background: none; border: none; color: #0284c7; font-size: 12px; text-decoration: underline; cursor: pointer; margin-left: 6px; padding: 0;">
									Modifier / Réinitialiser la date
								</button>
							</div>

							<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 12px;">
								<div id="checkpoint-box-email" class="woo-checkpoint-channel-box is-active-channel">
									<div style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: #475569; margin-bottom: 3px;">
										Canal Emailit / CRM
									</div>
									<div style="font-size: 14px; font-weight: 700; color: <?php echo $ui_email['exists'] ? '#0f172a' : '#94a3b8'; ?>;">
										<?php echo esc_html( $ui_email['display_date'] ); ?>
									</div>
									<div style="font-size: 11px; color: #64748b; margin-top: 2px;">
										<?php echo esc_html( $ui_email['display_meta'] ); ?>
									</div>
								</div>

								<div id="checkpoint-box-whatsapp" class="woo-checkpoint-channel-box">
									<div style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: #475569; margin-bottom: 3px;">
										Canal ManyChat WhatsApp
									</div>
									<div style="font-size: 14px; font-weight: 700; color: <?php echo $ui_whatsapp['exists'] ? '#0f172a' : '#94a3b8'; ?>;">
										<?php echo esc_html( $ui_whatsapp['display_date'] ); ?>
									</div>
									<div style="font-size: 11px; color: #64748b; margin-top: 2px;">
										<?php echo esc_html( $ui_whatsapp['display_meta'] ); ?>
									</div>
								</div>
							</div>
						</div>

						<div style="display: flex; flex-direction: column; align-items: flex-end; gap: 6px;">
							<button type="button" id="one-click-since-last-btn" class="button button-primary" <?php echo ! $ui_any['exists'] ? 'disabled' : ''; ?> style="background: #0f766e; border-color: #115e59; color: #ffffff; font-weight: 700; padding: 8px 18px; height: auto; line-height: 1.4; display: inline-flex; align-items: center; gap: 8px; border-radius: 6px; box-shadow: 0 2px 5px rgba(15,118,110,0.25);">
								<span class="dashicons dashicons-download" style="font-size: 18px; width: 18px; height: 18px;"></span>
								<span id="one-click-since-last-label">
									<?php if ( $ui_email['exists'] ) : ?>
										Télécharger les nouveaux depuis le <?php echo esc_html( $ui_email['display_date'] ); ?>
									<?php else : ?>
										Aucun dernier export enregistré
									<?php endif; ?>
								</span>
							</button>
							<small id="one-click-since-last-hint" style="font-size: 11px; color: #475569;">
								<?php if ( $ui_any['exists'] ) : ?>
									Extrait uniquement le delta exact (+1s) et met à jour le point de repère.
								<?php else : ?>
									Lancez un premier export ci-dessous (ou définissez une date manuellement).
								<?php endif; ?>
							</small>
						</div>
					</div>

					<!-- TIROIR D'EDITION MANUELLE DU POINT DE REPERE (HORS FORMULAIRE PRINCIPAL) -->
					<div id="manual-checkpoint-drawer" style="display: none; margin-top: 16px; padding-top: 14px; border-top: 1px dashed #cbd5e1;">
						<form method="POST" action="<?php echo esc_url( $action_url ); ?>" style="display: flex; flex-wrap: wrap; align-items: flex-end; gap: 12px;">
							<?php wp_nonce_field( 'woo_export_contacts_checkpoint_action', 'woo_export_contacts_checkpoint_nonce' ); ?>
							<input type="hidden" name="action" value="woo_export_contacts_update_checkpoint">

							<div>
								<label style="display: block; font-size: 11px; font-weight: 700; color: #334155; margin-bottom: 4px;">Canal à modifier :</label>
								<select name="checkpoint_channel" style="font-size: 12px; min-height: 32px;">
									<option value="all">Tous les canaux (Emailit + WhatsApp)</option>
									<option value="email">Canal Emailit / CRM uniquement</option>
									<option value="whatsapp">Canal ManyChat WhatsApp uniquement</option>
								</select>
							</div>

							<div>
								<label style="display: block; font-size: 11px; font-weight: 700; color: #334155; margin-bottom: 4px;">Date du dernier export :</label>
								<input type="date" name="manual_checkpoint_date" value="<?php echo esc_attr( $ui_any['exists'] ? $ui_any['date_ymd'] : $default_start_date ); ?>" style="font-size: 12px; min-height: 32px; padding: 0 8px;">
							</div>

							<div>
								<label style="display: block; font-size: 11px; font-weight: 700; color: #334155; margin-bottom: 4px;">Heure :</label>
								<input type="time" name="manual_checkpoint_time" value="<?php echo esc_attr( $ui_any['exists'] ? $ui_any['time_hm'] : '00:00' ); ?>" style="font-size: 12px; min-height: 32px; padding: 0 8px;">
							</div>

							<button type="submit" name="checkpoint_operation" value="set" class="button button-secondary" style="min-height: 32px; font-weight: 600;">
								Enregistrer cette date
							</button>

							<?php if ( $ui_any['exists'] ) : ?>
							<button type="submit" name="checkpoint_operation" value="reset" class="button button-link-delete" style="min-height: 32px;">
								Réinitialiser le compteur
							</button>
							<?php endif; ?>
						</form>
					</div>
				</div>

				<form method="POST" action="<?php echo esc_url( $action_url ); ?>" id="conforama-export-form">
					<?php wp_nonce_field( 'conforama_export_contacts_action', 'conforama_export_contacts_nonce' ); ?>
					<input type="hidden" name="action" value="conforama_export_contacts_csv">
					<input type="hidden" id="since_last_export" name="since_last_export" value="0">
					<input type="hidden" id="since_last_timestamp" name="since_last_timestamp" value="">

					<!-- 1. PLAGE DE DATES AVEC RACCOURCIS -->
					<div style="margin-bottom: 24px;">
						<label style="display: block; font-weight: 700; font-size: 14px; margin-bottom: 10px; color: #111111;">
							1. Période de capture des contacts
						</label>

						<!-- Raccourcis rapides -->
						<div style="display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 14px;">
							<button type="button" id="quick-btn-since-last" class="button button-small" <?php echo ! $ui_any['exists'] ? 'disabled' : ''; ?> style="border-color: #0f766e; color: #0f766e; background: #f0fdfa; font-weight: 700;">
								Depuis le dernier export<?php echo $ui_email['exists'] ? ' (' . esc_html( $ui_email['display_date'] ) . ')' : ''; ?>
							</button>
							<button type="button" class="button button-small confo-quick-date" data-start="<?php echo esc_attr( gmdate( 'Y-m-d', strtotime( $today_local . ' -7 days' ) ) ); ?>" data-end="<?php echo esc_attr( $today_local ); ?>">7 derniers jours</button>
							<button type="button" class="button button-small confo-quick-date" data-start="<?php echo esc_attr( gmdate( 'Y-m-d', strtotime( $today_local . ' -30 days' ) ) ); ?>" data-end="<?php echo esc_attr( $today_local ); ?>">30 derniers jours</button>
							<button type="button" class="button button-small confo-quick-date" data-start="<?php echo esc_attr( gmdate( 'Y-m-01', strtotime( $today_local ) ) ); ?>" data-end="<?php echo esc_attr( $today_local ); ?>">Mois en cours</button>
							<button type="button" class="button button-small confo-quick-date" data-start="<?php echo esc_attr( gmdate( 'Y-m-01', strtotime( 'first day of last month' ) ) ); ?>" data-end="<?php echo esc_attr( gmdate( 'Y-m-t', strtotime( 'last month' ) ) ); ?>">Mois dernier</button>
							<?php if ( $is_confo ) : ?>
							<button type="button" class="button button-small confo-quick-date" style="border-color: #e2001a; color: #e2001a; font-weight: 600;" data-start="2026-09-05" data-end="<?php echo esc_attr( $today_local ); ?>">Période Soldes (Depuis le 05/09)</button>
							<?php endif; ?>
							<button type="button" class="button button-small confo-quick-date confo-all-time-btn" data-start="<?php echo esc_attr( $earliest_date ); ?>" data-end="<?php echo esc_attr( $today_local ); ?>" style="border-color: #2563eb; color: #1d4ed8; font-weight: 700; background: #eff6ff;">
								Tout l'historique (Depuis le début : <?php echo esc_html( gmdate( 'd/m/Y', strtotime( $earliest_date ) ) ); ?>)
							</button>
						</div>

						<!-- Bandeau d'état quand le mode "Depuis le dernier export" est sélectionné -->
						<div id="since-last-active-banner" style="display: none; background: #f0fdfa; border: 1px solid #99f6e4; color: #115e59; padding: 10px 14px; border-radius: 6px; margin-bottom: 12px; font-size: 13px; align-items: center; justify-content: space-between;">
							<span>
								<strong>Mode incrémental précis activé :</strong> extraction des contacts enregistrés après le <strong id="since-last-banner-time"></strong> (à la seconde près, zéro doublon).
							</span>
							<button type="button" id="cancel-since-last-btn" style="background: none; border: none; color: #0f766e; text-decoration: underline; cursor: pointer; font-size: 12px; font-weight: 600;">
								Revenir aux dates manuelles
							</button>
						</div>

						<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
							<div>
								<span style="font-size: 13px; font-weight: 600; color: #333333; display: block; margin-bottom: 4px;">Date de début (inclus) :</span>
								<input type="date" id="start_date" name="start_date" required value="<?php echo esc_attr( $default_start_date ); ?>" style="width: 100%; padding: 8px 12px; border-radius: 4px; border: 1px solid #8c8f94; font-size: 14px;">
							</div>
							<div>
								<span style="font-size: 13px; font-weight: 600; color: #333333; display: block; margin-bottom: 4px;">Date de fin (inclus) :</span>
								<input type="date" id="end_date" name="end_date" required value="<?php echo esc_attr( $default_end_date ); ?>" style="width: 100%; padding: 8px 12px; border-radius: 4px; border: 1px solid #8c8f94; font-size: 14px;">
							</div>
						</div>

						<div style="margin-top: 14px;">
							<label style="display: inline-flex; align-items: center; gap: 8px; cursor: pointer; font-size: 13px; font-weight: 600; color: #1e3a8a; background: #eff6ff; padding: 8px 14px; border-radius: 6px; border: 1px solid #bfdbfe;">
								<input type="checkbox" id="export_all_time" name="export_all_time" value="1">
								<span>Exporter l'intégralité des contacts depuis l'origine du site (ignore la date de début)</span>
							</label>
						</div>
					</div>

					<hr style="border: 0; border-top: 1px solid #eeeeee; margin: 24px 0;">

					<!-- 2. FILTRAGE CLIENTS EXISTANTS -->
					<div style="margin-bottom: 24px;">
						<label style="display: block; font-weight: 700; font-size: 14px; margin-bottom: 10px; color: #111111;">
							2. Règle de sélection des contacts
						</label>
						<div style="display: flex; flex-direction: column; gap: 12px; background: #fafafa; border: 1px solid #eeeeee; border-radius: 6px; padding: 14px 16px;">
							<label style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer;">
								<input type="radio" name="strict_new" value="1" checked style="margin-top: 3px;">
								<span>
									<strong style="color: #111111;">Strictement Nouveaux Contacts (Recommandé pour Emailit &amp; WhatsApp)</strong><br>
									<small style="color: #666666;">Exclut automatiquement tout email ayant déjà commandé ou créé un compte avant la date de début. Idéal pour cibler vos vrais nouveaux prospects sans relancer des clients déjà en base.</small>
								</span>
							</label>
							<label style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer;">
								<input type="radio" name="strict_new" value="0" style="margin-top: 3px;">
								<span>
									<strong style="color: #111111;">Tous les contacts actifs sur la période</strong><br>
									<small style="color: #666666;">Inclut toute personne ayant passé commande, créé un compte, abandonné un panier ou souscrit à la newsletter sur la période, même si elle était déjà cliente par le passé.</small>
								</span>
							</label>
						</div>
					</div>

					<hr style="border: 0; border-top: 1px solid #eeeeee; margin: 24px 0;">

					<!-- 3. SOURCES A SCANNER & FORMAT DU FICHIER -->
					<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 25px;">
						
						<!-- Sources -->
						<div>
							<label style="display: block; font-weight: 700; font-size: 14px; margin-bottom: 12px; color: #111111;">
								3. Sources à inclure
							</label>
							<div style="display: flex; flex-direction: column; gap: 10px;">
								<label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
									<input type="checkbox" name="sources[]" value="orders" checked>
									<span>Commandes WooCommerce (Clients &amp; Invités)</span>
								</label>
								<label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
									<input type="checkbox" name="sources[]" value="users" checked>
									<span>Comptes clients WordPress créés</span>
								</label>
								<?php if ( $has_cartflows ) : ?>
								<label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
									<input type="checkbox" name="sources[]" value="abandoned_carts" checked>
									<span>Paniers abandonnés (Cart Abandonment)</span>
								</label>
								<?php endif; ?>
								<?php if ( $has_mailpoet ) : ?>
								<label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
									<input type="checkbox" name="sources[]" value="mailpoet" checked>
									<span>Abonnés Newsletter (MailPoet)</span>
								</label>
								<?php endif; ?>
								<?php if ( $has_waitlist ) : ?>
								<label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
									<input type="checkbox" name="sources[]" value="waitlist" checked>
									<span>Alertes Rupture / Waitlist</span>
								</label>
								<?php endif; ?>
							</div>
						</div>

						<!-- Options de format -->
						<div>
							<label style="display: block; font-weight: 700; font-size: 14px; margin-bottom: 12px; color: #111111;">
								4. Configuration de l'export &amp; Destination
							</label>
							<div style="display: flex; flex-direction: column; gap: 12px;">
								<div>
									<label for="csv_columns" style="display: block; font-size: 12px; font-weight: 600; color: #555555; margin-bottom: 4px;">Destination &amp; Structure des colonnes :</label>
									<select id="csv_columns" name="csv_columns" style="width: 100%; padding: 6px 10px; border-radius: 4px;">
										<option value="emailit_ready" selected>Format Emailit Ready (Email, First Name, Last Name, Phone, Tags)</option>
										<option value="manychat_whatsapp">Format ManyChat WhatsApp (Phone, First Name, Last Name, Tags — Sans e-mail, 100% Mobile)</option>
										<option value="manychat_whatsapp_email">Format ManyChat WhatsApp + Email (Phone, First Name, Last Name, Email, Tags)</option>
										<option value="full">Format Complet / Audit (Prénom, Nom, Email, Téléphone, Source, Tag, Date)</option>
										<option value="simple">Format Simplifié (Prénom, Nom, Email)</option>
									</select>
								</div>
								<div>
									<label for="csv_delimiter" style="display: block; font-size: 12px; font-weight: 600; color: #555555; margin-bottom: 4px;">Séparateur CSV :</label>
									<select id="csv_delimiter" name="csv_delimiter" style="width: 100%; padding: 6px 10px; border-radius: 4px;">
										<option value="comma" selected>Virgule (,) — Standard ManyChat, Emailit, Klaviyo, Brevo</option>
										<option value="semicolon">Point-virgule (;) — Standard Excel France</option>
									</select>
								</div>
								<div>
									<label for="tag_prefix" style="display: block; font-size: 12px; font-weight: 600; color: #555555; margin-bottom: 4px;">Préfixe des Tags (ex. <?php echo esc_attr( $default_tag ); ?>_client) :</label>
									<input type="text" id="tag_prefix" name="tag_prefix" value="<?php echo esc_attr( $default_tag ); ?>" style="width: 100%; padding: 6px 10px; border-radius: 4px; border: 1px solid #8c8f94; font-size: 13px;">
								</div>
								<div>
									<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 4px;">
										<label for="excluded_domains" style="font-size: 12px; font-weight: 600; color: #555555;">
											Domaines e-mails à exclure :
										</label>
										<button type="button" id="reset_excluded_domains_btn" style="background: none; border: none; padding: 0; color: #0073aa; font-size: 11px; text-decoration: underline; cursor: pointer;">
											Rétablir par défaut
										</button>
									</div>
									<textarea id="excluded_domains" name="excluded_domains" rows="3" style="width: 100%; padding: 6px 10px; border-radius: 4px; border: 1px solid #8c8f94; font-size: 12px; font-family: Consolas, Monaco, monospace; line-height: 1.4;" placeholder="<?php echo esc_attr( implode( "\n", $default_domains ) ); ?>"><?php echo esc_textarea( $excluded_domains_val ); ?></textarea>
									<small style="color: #666666; font-size: 11px; display: block; margin-top: 2px;">
										Un domaine par ligne (ou séparés par des virgules). Les e-mails de ces domaines (ex. <code>@<?php echo esc_html( ! empty( $site_domain ) ? $site_domain : 'domaine.re' ); ?></code>, <code>@soyoo.re</code>) ne seront pas exportés.
									</small>
								</div>
							</div>
						</div>

					</div>

					<!-- ENCART CONSEIL DYNAMIQUE -->
					<div id="export-advice-box" style="background: #fff8e5; border-left: 4px solid #ffcc00; padding: 14px 18px; margin-bottom: 20px; border-radius: 0 4px 4px 0; transition: all 0.25s ease;">
						<div id="export-advice-emailit">
							<p style="margin: 0; font-size: 13px; color: #444444; line-height: 1.5;">
								<strong>Spécifique Emailit :</strong> Le mode <em>Emailit Ready</em> inclut la colonne <code>Tags</code> (ex. <code><?php echo esc_attr( $default_tag ); ?>_client</code>, <code><?php echo esc_attr( $default_tag ); ?>_panier_abandonne</code>, <code><?php echo esc_attr( $default_tag ); ?>_newsletter</code>). Lors de l'import dans Emailit, vous pourrez mapper directement la colonne <code>Tags</code> pour segmenter automatiquement vos listes sans aucune manipulation manuelle.
							</p>
						</div>
						<div id="export-advice-manychat" style="display: none;">
							<p style="margin: 0; font-size: 13px; color: #065f46; line-height: 1.6;">
								<strong>Spécifique ManyChat WhatsApp :</strong><br>
								&bull; <strong>100% Numéros Mobiles E.164 :</strong> Seuls les contacts ayant un numéro de téléphone valide (+262 / +33) sont exportés et dédoublonnés.<br>
								&bull; <strong>Sans e-mail (Recommandé) :</strong> Contourne immédiatement le blocage <em>« Request approval »</em> de ManyChat pour le canal Email et évite de gonfler inutilement votre palier de facturation avec des contacts sans numéro WhatsApp.<br>
								&bull; <strong>Mapping ManyChat :</strong> Lors de l'import CSV dans ManyChat, mappez la colonne <code>phone</code> sur le champ système <strong>WhatsApp ID</strong>.
							</p>
						</div>
					</div>

					<!-- OPTION DE MEMORISATION DU POINT DE REPERE & BOUTON D'ACTION -->
					<div style="display: flex; flex-direction: column; gap: 14px;">
						<div>
							<label style="display: inline-flex; align-items: center; gap: 8px; cursor: pointer; font-size: 13px; font-weight: 600; color: #0f172a;">
								<input type="checkbox" id="save_last_export" name="save_last_export" value="1" checked>
								<span>Enregistrer la date et l'heure de cet export comme nouveau point de repère</span>
								<small id="save-checkpoint-auto-note" style="display: none; color: #d97706; font-weight: 500;">(décoché automatiquement car la période sélectionnée est dans le passé)</small>
							</label>
						</div>

						<div>
							<button type="submit" id="export-submit-btn" class="button button-primary button-hero" style="background-color: #e2001a; border-color: #b50015; font-weight: 700; padding: 6px 28px; display: inline-flex; align-items: center; gap: 8px; text-shadow: none; box-shadow: 0 2px 6px rgba(226,0,26,0.3);">
								<span class="dashicons dashicons-download" style="font-size: 20px; line-height: 28px;"></span>
								<span id="export-submit-label">Générer et Télécharger le CSV Emailit</span>
							</button>
						</div>
					</div>
				</form>
			</div>
		</div>

		<script type="text/javascript">
		var startDateInput         = document.getElementById('start_date');
		var endDateInput           = document.getElementById('end_date');
		var exportAllTimeCheckbox  = document.getElementById('export_all_time');
		var sinceLastInput         = document.getElementById('since_last_export');
		var sinceLastTsInput       = document.getElementById('since_last_timestamp');
		var sinceLastBanner        = document.getElementById('since-last-active-banner');
		var sinceLastBannerTime    = document.getElementById('since-last-banner-time');
		var cancelSinceLastBtn     = document.getElementById('cancel-since-last-btn');
		var quickBtnSinceLast      = document.getElementById('quick-btn-since-last');
		var oneClickSinceLastBtn   = document.getElementById('one-click-since-last-btn');
		var oneClickSinceLastLabel = document.getElementById('one-click-since-last-label');
		var saveLastExportCheckbox = document.getElementById('save_last_export');
		var saveCheckpointAutoNote = document.getElementById('save-checkpoint-auto-note');
		var checkpointBoxEmail     = document.getElementById('checkpoint-box-email');
		var checkpointBoxWhatsapp  = document.getElementById('checkpoint-box-whatsapp');
		var toggleManualDrawerBtn  = document.getElementById('toggle-manual-checkpoint-btn');
		var manualCheckpointDrawer = document.getElementById('manual-checkpoint-drawer');
		var exportForm             = document.getElementById('conforama-export-form');

		var radioStrictNew         = document.querySelector('input[name="strict_new"][value="1"]');
		var radioAllActive         = document.querySelector('input[name="strict_new"][value="0"]');
		var earliestDateStr        = <?php echo wp_json_encode( $earliest_date ); ?>;
		var todayDateStr           = <?php echo wp_json_encode( $today_local ); ?>;
		var checkpointsData        = <?php echo wp_json_encode( array( 'email' => $ui_email, 'whatsapp' => $ui_whatsapp, 'any' => $ui_any ) ); ?>;

		function getActiveChannelKey() {
			var columnsSelect = document.getElementById('csv_columns');
			if (!columnsSelect) return 'email';
			var val = columnsSelect.value;
			return (val === 'manychat_whatsapp' || val === 'manychat_whatsapp_email') ? 'whatsapp' : 'email';
		}

		function getActiveCheckpoint() {
			var ch = getActiveChannelKey();
			if (checkpointsData[ch] && checkpointsData[ch].exists) {
				return checkpointsData[ch];
			}
			if (checkpointsData.any && checkpointsData.any.exists) {
				return checkpointsData.any;
			}
			return { exists: false };
		}

		function deactivateSinceLastMode() {
			if (sinceLastInput) sinceLastInput.value = '0';
			if (sinceLastTsInput) sinceLastTsInput.value = '';
			if (sinceLastBanner) sinceLastBanner.style.display = 'none';
			if (startDateInput && (!exportAllTimeCheckbox || !exportAllTimeCheckbox.checked)) {
				startDateInput.readOnly = false;
				startDateInput.style.backgroundColor = '#ffffff';
			}
		}

		function activateSinceLastMode() {
			var cp = getActiveCheckpoint();
			if (!cp || !cp.exists) return false;

			if (exportAllTimeCheckbox && exportAllTimeCheckbox.checked) {
				exportAllTimeCheckbox.checked = false;
			}
			if (sinceLastInput) sinceLastInput.value = '1';
			if (sinceLastTsInput) sinceLastTsInput.value = cp.timestamp_local;
			if (startDateInput) {
				startDateInput.value = cp.date_ymd;
				startDateInput.readOnly = true;
				startDateInput.style.backgroundColor = '#f0fdfa';
			}
			if (endDateInput) {
				endDateInput.value = todayDateStr;
			}
			if (radioStrictNew) {
				radioStrictNew.checked = true;
			}
			if (saveLastExportCheckbox) {
				saveLastExportCheckbox.checked = true;
				if (saveCheckpointAutoNote) saveCheckpointAutoNote.style.display = 'none';
			}
			if (sinceLastBannerTime) {
				sinceLastBannerTime.textContent = cp.display_date;
			}
			if (sinceLastBanner) {
				sinceLastBanner.style.display = 'flex';
			}
			return true;
		}

		function syncSaveCheckpointWithEndDate() {
			if (!endDateInput || !saveLastExportCheckbox) return;
			if (endDateInput.value && endDateInput.value < todayDateStr) {
				saveLastExportCheckbox.checked = false;
				if (saveCheckpointAutoNote) saveCheckpointAutoNote.style.display = 'inline';
			} else {
				saveLastExportCheckbox.checked = true;
				if (saveCheckpointAutoNote) saveCheckpointAutoNote.style.display = 'none';
			}
		}

		if (toggleManualDrawerBtn && manualCheckpointDrawer) {
			toggleManualDrawerBtn.addEventListener('click', function(e) {
				e.preventDefault();
				manualCheckpointDrawer.style.display = (manualCheckpointDrawer.style.display === 'none') ? 'block' : 'none';
			});
		}

		if (quickBtnSinceLast) {
			quickBtnSinceLast.addEventListener('click', function() {
				activateSinceLastMode();
			});
		}

		if (cancelSinceLastBtn) {
			cancelSinceLastBtn.addEventListener('click', function() {
				deactivateSinceLastMode();
			});
		}

		if (oneClickSinceLastBtn) {
			oneClickSinceLastBtn.addEventListener('click', function() {
				if (activateSinceLastMode() && exportForm) {
					exportForm.requestSubmit ? exportForm.requestSubmit() : exportForm.submit();
					setTimeout(function() {
						window.location.reload();
					}, 2500);
				}
			});
		}

		if (exportForm) {
			exportForm.addEventListener('submit', function() {
				if (saveLastExportCheckbox && saveLastExportCheckbox.checked) {
					setTimeout(function() {
						window.location.reload();
					}, 2500);
				}
			});
		}

		if (startDateInput) {
			startDateInput.addEventListener('change', function() {
				if (sinceLastInput && sinceLastInput.value === '1') {
					deactivateSinceLastMode();
				}
			});
		}

		if (endDateInput) {
			endDateInput.addEventListener('change', function() {
				syncSaveCheckpointWithEndDate();
			});
		}

		document.querySelectorAll('.confo-quick-date').forEach(function(btn) {
			btn.addEventListener('click', function() {
				deactivateSinceLastMode();
				if (this.classList.contains('confo-all-time-btn')) {
					if (exportAllTimeCheckbox) {
						exportAllTimeCheckbox.checked = true;
						exportAllTimeCheckbox.dispatchEvent(new Event('change'));
					} else {
						if (startDateInput) startDateInput.value = this.dataset.start;
						if (endDateInput) endDateInput.value     = this.dataset.end;
						if (radioAllActive) radioAllActive.checked = true;
					}
				} else {
					if (exportAllTimeCheckbox && exportAllTimeCheckbox.checked) {
						exportAllTimeCheckbox.checked = false;
						if (startDateInput) {
							startDateInput.readOnly = false;
							startDateInput.style.backgroundColor = '#ffffff';
						}
					}
					if (startDateInput) startDateInput.value = this.dataset.start;
					if (endDateInput) endDateInput.value     = this.dataset.end;
				}
				syncSaveCheckpointWithEndDate();
			});
		});

		if (exportAllTimeCheckbox) {
			exportAllTimeCheckbox.addEventListener('change', function() {
				if (this.checked) {
					deactivateSinceLastMode();
					if (startDateInput) {
						startDateInput.dataset.previousVal = startDateInput.value;
						startDateInput.value               = earliestDateStr;
						startDateInput.readOnly            = true;
						startDateInput.style.backgroundColor = '#f3f4f6';
					}
					if (radioAllActive) {
						radioAllActive.checked = true;
					}
				} else {
					if (startDateInput) {
						startDateInput.readOnly            = false;
						startDateInput.style.backgroundColor = '#ffffff';
						if (startDateInput.dataset.previousVal) {
							startDateInput.value = startDateInput.dataset.previousVal;
						}
					}
				}
			});
		}

		var defaultExcludedDomains = <?php echo wp_json_encode( implode( "\n", $default_domains ) ); ?>;
		var resetDomainsBtn = document.getElementById('reset_excluded_domains_btn');
		if (resetDomainsBtn) {
			resetDomainsBtn.addEventListener('click', function(e) {
				e.preventDefault();
				var textarea = document.getElementById('excluded_domains');
				if (textarea) {
					textarea.value = defaultExcludedDomains;
				}
			});
		}

		var columnsSelect  = document.getElementById('csv_columns');
		var adviceBox      = document.getElementById('export-advice-box');
		var adviceEmailit  = document.getElementById('export-advice-emailit');
		var adviceManychat = document.getElementById('export-advice-manychat');
		var submitBtn      = document.getElementById('export-submit-btn');
		var submitLabel    = document.getElementById('export-submit-label');

		function updateExportFormatUI() {
			if (!columnsSelect) return;
			var val = columnsSelect.value;
			var isManychat = (val === 'manychat_whatsapp' || val === 'manychat_whatsapp_email');
			var cp = getActiveCheckpoint();

			if (checkpointBoxEmail && checkpointBoxWhatsapp) {
				if (isManychat) {
					checkpointBoxEmail.classList.remove('is-active-channel');
					checkpointBoxWhatsapp.classList.add('is-active-channel');
				} else {
					checkpointBoxWhatsapp.classList.remove('is-active-channel');
					checkpointBoxEmail.classList.add('is-active-channel');
				}
			}

			if (oneClickSinceLastBtn && oneClickSinceLastLabel) {
				if (cp && cp.exists) {
					oneClickSinceLastBtn.disabled = false;
					oneClickSinceLastLabel.textContent = (isManychat ? 'Télécharger les nouveaux WhatsApp depuis le ' : 'Télécharger les nouveaux Emailit depuis le ') + cp.display_date;
				} else {
					oneClickSinceLastBtn.disabled = true;
					oneClickSinceLastLabel.textContent = 'Aucun dernier export enregistré';
				}
			}

			if (quickBtnSinceLast) {
				if (cp && cp.exists) {
					quickBtnSinceLast.disabled = false;
					quickBtnSinceLast.textContent = 'Depuis le dernier export (' + cp.display_date + ')';
				} else {
					quickBtnSinceLast.disabled = true;
					quickBtnSinceLast.textContent = 'Depuis le dernier export';
				}
			}

			if (sinceLastInput && sinceLastInput.value === '1') {
				activateSinceLastMode();
			}

			if (isManychat) {
				if (adviceBox) {
					adviceBox.style.background = '#f0fdf4';
					adviceBox.style.borderLeftColor = '#25d366';
				}
				if (adviceEmailit) adviceEmailit.style.display = 'none';
				if (adviceManychat) adviceManychat.style.display = 'block';
				if (submitLabel) submitLabel.textContent = 'Générer et Télécharger le CSV ManyChat (WhatsApp)';
				if (submitBtn) {
					submitBtn.style.backgroundColor = '#075e54';
					submitBtn.style.borderColor = '#05463e';
					submitBtn.style.boxShadow = '0 2px 6px rgba(7,94,84,0.3)';
				}
			} else {
				if (adviceBox) {
					adviceBox.style.background = '#fff8e5';
					adviceBox.style.borderLeftColor = '#ffcc00';
				}
				if (adviceEmailit) adviceEmailit.style.display = 'block';
				if (adviceManychat) adviceManychat.style.display = 'none';
				if (submitLabel) submitLabel.textContent = 'Générer et Télécharger le CSV Emailit';
				if (submitBtn) {
					submitBtn.style.backgroundColor = '#e2001a';
					submitBtn.style.borderColor = '#b50015';
					submitBtn.style.boxShadow = '0 2px 6px rgba(226,0,26,0.3)';
				}
			}
		}

		if (columnsSelect) {
			columnsSelect.addEventListener('change', updateExportFormatUI);
			updateExportFormatUI();
		}

		// Nettoyage immédiat de tout reliquat de notifications admin dans le DOM
		function purgeNoticeElements() {
			document.querySelectorAll('.notice, .updated, .error, .woocommerce-message, .woocommerce-error, .update-nag').forEach(function(el) {
				el.remove();
			});
		}
		purgeNoticeElements();
		window.addEventListener('DOMContentLoaded', purgeNoticeElements);
		window.addEventListener('load', purgeNoticeElements);
		</script>
		<?php
	}
}
