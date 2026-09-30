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
		add_action( 'admin_menu', array( $this, 'register_admin_menus' ), 99 );
		add_action( 'admin_post_conforama_export_contacts_csv', array( $this, 'handle_export_submission' ) );
		add_action( 'admin_post_woo_export_contacts_csv', array( $this, 'handle_export_submission' ) );
	}

	/**
	 * Enregistre les pages de menu dans l'administration WooCommerce.
	 */
	public function register_admin_menus() {
		// Menu principal visible sous WooCommerce
		add_submenu_page(
			'woocommerce',
			__( 'Export Contacts (Emailit)', 'woo-export-contacts' ),
			__( 'Export Contacts Emailit', 'woo-export-contacts' ),
			'manage_woocommerce',
			'conforama-export-contacts',
			array( $this, 'render_admin_page' )
		);

		// Alias sous-jacent pour compatibilité d'URL directe page=woo-export-contacts
		add_submenu_page(
			null,
			__( 'Export Contacts (Emailit)', 'woo-export-contacts' ),
			__( 'Export Contacts Emailit', 'woo-export-contacts' ),
			'manage_woocommerce',
			'woo-export-contacts',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Intercepte et traite la soumission du formulaire d'export.
	 */
	public function handle_export_submission() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
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
	 * Affiche l'interface d'exportation de contacts.
	 */
	public function render_admin_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Vous n\'avez pas les permissions nécessaires pour accéder à cette page.', 'woo-export-contacts' ) );
		}

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

		$default_start_date = gmdate( 'Y-m-01' ); // 1er jour du mois en cours
		$default_end_date   = gmdate( 'Y-m-d' );    // Aujourd'hui
		$action_url         = admin_url( 'admin-post.php' );

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

		$version_display = defined( 'WOO_EXPORT_CONTACTS_VERSION' ) ? WOO_EXPORT_CONTACTS_VERSION : '2.1.0';
		$badge_text      = $is_confo ? 'Conforama.re &bull; v' . $version_display : esc_html( get_bloginfo( 'name' ) ) . ' &bull; v' . $version_display;
		?>
		<div class="wrap" style="max-width: 980px; margin-top: 25px; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;">
			
			<!-- EN-TETE CONFORAMA / SOYOO -->
			<div style="display: flex; align-items: center; justify-content: space-between; border-bottom: 3px solid #e2001a; padding-bottom: 16px; margin-bottom: 25px;">
				<div>
					<h1 style="font-weight: 800; font-size: 24px; color: #111111; margin: 0 0 6px 0; display: flex; align-items: center; gap: 10px;">
						<span class="dashicons dashicons-email-alt2" style="font-size: 32px; width: 32px; height: 32px; color: #e2001a;"></span>
						Export Contacts Marketing &bull; Spécial Emailit
					</h1>
					<p style="color: #666666; font-size: 13px; margin: 0;">
						Générez un fichier CSV nettoyé, dédoublonné et prêt à importer en 1 clic dans <strong>Emailit</strong> (ou Klaviyo, Brevo).
					</p>
				</div>
				<div style="display: flex; align-items: center; gap: 8px;">
					<span style="background: <?php echo $hpos_enabled ? '#dcfce7; color: #166534;' : '#fef3c7; color: #92400e;'; ?> font-weight: 700; font-size: 11px; padding: 5px 10px; border-radius: 20px; text-transform: uppercase;">
						<?php echo $hpos_enabled ? '⚡ HPOS Actif' : '📦 Commandes Legacy'; ?>
					</span>
					<div style="background: #fff000; color: #111111; font-weight: 800; font-size: 11px; padding: 6px 12px; border-radius: 20px; text-transform: uppercase; letter-spacing: 0.5px;">
						<?php echo wp_kses_post( $badge_text ); ?>
					</div>
				</div>
			</div>

			<div style="background: #ffffff; border: 1px solid #dcdcde; border-radius: 8px; padding: 28px; box-shadow: 0 2px 8px rgba(0,0,0,0.04);">
				<form method="POST" action="<?php echo esc_url( $action_url ); ?>" id="conforama-export-form">
					<?php wp_nonce_field( 'conforama_export_contacts_action', 'conforama_export_contacts_nonce' ); ?>
					<input type="hidden" name="action" value="conforama_export_contacts_csv">

					<!-- 1. PLAGE DE DATES AVEC RACCOURCIS -->
					<div style="margin-bottom: 24px;">
						<label style="display: block; font-weight: 700; font-size: 14px; margin-bottom: 10px; color: #111111;">
							📅 Période de capture des contacts
						</label>

						<!-- Raccourcis rapides -->
						<div style="display: flex; flex-wrap: wrap; gap: 8px; margin-bottom: 14px;">
							<button type="button" class="button button-small confo-quick-date" data-start="<?php echo esc_attr( gmdate( 'Y-m-d', strtotime( '-7 days' ) ) ); ?>" data-end="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>">7 derniers jours</button>
							<button type="button" class="button button-small confo-quick-date" data-start="<?php echo esc_attr( gmdate( 'Y-m-d', strtotime( '-30 days' ) ) ); ?>" data-end="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>">30 derniers jours</button>
							<button type="button" class="button button-small confo-quick-date" data-start="<?php echo esc_attr( gmdate( 'Y-m-01' ) ); ?>" data-end="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>">Mois en cours</button>
							<button type="button" class="button button-small confo-quick-date" data-start="<?php echo esc_attr( gmdate( 'Y-m-01', strtotime( 'first day of last month' ) ) ); ?>" data-end="<?php echo esc_attr( gmdate( 'Y-m-t', strtotime( 'last month' ) ) ); ?>">Mois dernier</button>
							<?php if ( $is_confo ) : ?>
							<button type="button" class="button button-small confo-quick-date" style="border-color: #e2001a; color: #e2001a; font-weight: 600;" data-start="2026-09-05" data-end="<?php echo esc_attr( gmdate( 'Y-m-d' ) ); ?>">💥 Période Soldes (Depuis le 05/09)</button>
							<?php endif; ?>
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
					</div>

					<hr style="border: 0; border-top: 1px solid #eeeeee; margin: 24px 0;">

					<!-- 2. FILTRAGE CLIENTS EXISTANTS -->
					<div style="margin-bottom: 24px;">
						<label style="display: block; font-weight: 700; font-size: 14px; margin-bottom: 10px; color: #111111;">
							🎯 Règle de sélection des contacts
						</label>
						<div style="display: flex; flex-direction: column; gap: 12px; background: #fafafa; border: 1px solid #eeeeee; border-radius: 6px; padding: 14px 16px;">
							<label style="display: flex; align-items: flex-start; gap: 10px; cursor: pointer;">
								<input type="radio" name="strict_new" value="1" checked style="margin-top: 3px;">
								<span>
									<strong style="color: #111111;">Strictement Nouveaux Contacts (Recommandé pour Emailit)</strong><br>
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
								🔍 Sources à inclure
							</label>
							<div style="display: flex; flex-direction: column; gap: 10px;">
								<label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
									<input type="checkbox" name="sources[]" value="orders" checked>
									<span>🛍️ Commandes WooCommerce (Clients &amp; Invités)</span>
								</label>
								<label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
									<input type="checkbox" name="sources[]" value="users" checked>
									<span>👤 Comptes clients WordPress créés</span>
								</label>
								<?php if ( $has_cartflows ) : ?>
								<label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
									<input type="checkbox" name="sources[]" value="abandoned_carts" checked>
									<span>🛒 Paniers abandonnés (Cart Abandonment)</span>
								</label>
								<?php endif; ?>
								<?php if ( $has_mailpoet ) : ?>
								<label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
									<input type="checkbox" name="sources[]" value="mailpoet" checked>
									<span>📩 Abonnés Newsletter (MailPoet)</span>
								</label>
								<?php endif; ?>
								<?php if ( $has_waitlist ) : ?>
								<label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
									<input type="checkbox" name="sources[]" value="waitlist" checked>
									<span>🔔 Alertes Rupture / Waitlist</span>
								</label>
								<?php endif; ?>
							</div>
						</div>

						<!-- Options de format -->
						<div>
							<label style="display: block; font-weight: 700; font-size: 14px; margin-bottom: 12px; color: #111111;">
								⚙️ Configuration de l'export Emailit
							</label>
							<div style="display: flex; flex-direction: column; gap: 12px;">
								<div>
									<label for="csv_columns" style="display: block; font-size: 12px; font-weight: 600; color: #555555; margin-bottom: 4px;">Structure des colonnes :</label>
									<select id="csv_columns" name="csv_columns" style="width: 100%; padding: 6px 10px; border-radius: 4px;">
										<option value="emailit_ready" selected>🚀 Format Emailit Ready (Email, First Name, Last Name, Phone, Tags)</option>
										<option value="full">📊 Format Complet / Audit (Prénom, Nom, Email, Téléphone, Source, Tag, Date)</option>
										<option value="simple">⚡ Format Simplifié (Prénom, Nom, Email)</option>
									</select>
								</div>
								<div>
									<label for="csv_delimiter" style="display: block; font-size: 12px; font-weight: 600; color: #555555; margin-bottom: 4px;">Séparateur CSV :</label>
									<select id="csv_delimiter" name="csv_delimiter" style="width: 100%; padding: 6px 10px; border-radius: 4px;">
										<option value="comma" selected>Virgule (,) — Standard Emailit, Klaviyo, Brevo</option>
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
											🚫 Domaines e-mails à exclure :
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

					<!-- ENCART CONSEIL EMAILIT -->
					<div style="background: #fff8e5; border-left: 4px solid #ffcc00; padding: 12px 16px; margin-bottom: 25px; border-radius: 0 4px 4px 0;">
						<p style="margin: 0; font-size: 13px; color: #444444; line-height: 1.5;">
							💡 <strong>Spécifique Emailit :</strong> Le mode <em>Emailit Ready</em> inclut la colonne <code>Tags</code> (ex. <code><?php echo esc_attr( $default_tag ); ?>_client</code>, <code><?php echo esc_attr( $default_tag ); ?>_panier_abandonne</code>, <code><?php echo esc_attr( $default_tag ); ?>_newsletter</code>). Lors de l'import dans Emailit, vous pourrez mapper directement la colonne <code>Tags</code> pour segmenter automatiquement vos listes sans aucune manipulation manuelle.
						</p>
					</div>

					<!-- BOUTON D'ACTION -->
					<div>
						<button type="submit" class="button button-primary button-hero" style="background-color: #e2001a; border-color: #b50015; font-weight: 700; padding: 6px 28px; display: inline-flex; align-items: center; gap: 8px; text-shadow: none; box-shadow: 0 2px 6px rgba(226,0,26,0.3);">
							<span class="dashicons dashicons-download" style="font-size: 20px; line-height: 28px;"></span>
							Générer et Télécharger le CSV Emailit
						</button>
					</div>
				</form>
			</div>
		</div>

		<script type="text/javascript">
		document.querySelectorAll('.confo-quick-date').forEach(function(btn) {
			btn.addEventListener('click', function() {
				document.getElementById('start_date').value = this.dataset.start;
				document.getElementById('end_date').value = this.dataset.end;
			});
		});

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
		</script>
		<?php
	}
}
