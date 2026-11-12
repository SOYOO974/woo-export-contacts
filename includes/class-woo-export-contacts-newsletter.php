<?php
/**
 * Native Newsletter Opt-in Module for Woo Export Contacts.
 *
 * Provides:
 * - Dedicated lightweight table `{$wpdb->prefix}wec_newsletter_subscribers`
 * - Idempotent public API (`is_subscribed`, `subscribe`, `unsubscribe`) + standardized hook `woo_export_contacts_newsletter_subscribed`
 * - Optional WooCommerce Checkout opt-in checkbox (CNIL compliant, HPOS compatible)
 * - Lightweight Edge-Cache-safe AJAX shortcode `[woo_newsletter_form]` with zero external dependencies
 *
 * @package WooExportContacts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Woo_Export_Contacts_Newsletter
 */
class Woo_Export_Contacts_Newsletter {

	/**
	 * Schema version for automatic migration on plugin update (PUC).
	 */
	const DB_VERSION = '1.0.0';

	/**
	 * Option name storing the installed DB schema version.
	 */
	const OPTION_DB_VERSION = 'woo_export_contacts_db_version';

	/**
	 * Option name storing newsletter collection settings.
	 */
	const OPTION_SETTINGS = 'woo_export_contacts_newsletter_settings';

	/**
	 * Whether table existence has been verified during the current request.
	 *
	 * @var bool
	 */
	private static $table_ensured = false;

	/**
	 * Cached flag for MailPoet subscribers table presence.
	 *
	 * @var bool|null
	 */
	private static $has_mailpoet_table = null;

	/**
	 * Counter for unique DOM IDs when multiple shortcodes appear on the same page.
	 *
	 * @var int
	 */
	private static $shortcode_instance = 0;

	/**
	 * Whether inline CSS/JS assets for the shortcode have already been printed.
	 *
	 * @var bool
	 */
	private static $shortcode_assets_printed = false;

	/**
	 * Registers runtime hooks for DB check, Checkout opt-in, and AJAX shortcode.
	 *
	 * @return void
	 */
	public function init() {
		self::maybe_install_table();

		// Shortcode & AJAX endpoints (Edge-Cache compatible).
		add_shortcode( 'woo_newsletter_form', array( $this, 'render_shortcode' ) );
		add_action( 'wp_ajax_wec_newsletter_subscribe', array( $this, 'handle_ajax_subscribe' ) );
		add_action( 'wp_ajax_nopriv_wec_newsletter_subscribe', array( $this, 'handle_ajax_subscribe' ) );

		// Optional WooCommerce Checkout opt-in checkbox.
		$settings = self::get_settings();
		if ( ! empty( $settings['enable_checkout_optin'] ) ) {
			add_action( 'woocommerce_after_checkout_billing_form', array( $this, 'render_checkout_optin_field' ), 20 );
			add_action( 'woocommerce_checkout_order_processed', array( $this, 'capture_checkout_optin' ), 20, 3 );
		}
	}

	/**
	 * Resets static caches (primarily for automated unit/integration tests).
	 *
	 * @return void
	 */
	public static function reset_runtime_cache() {
		self::$table_ensured      = false;
		self::$has_mailpoet_table = null;
	}

	/**
	 * Returns the full SQL table name for native newsletter subscribers.
	 *
	 * @return string
	 */
	public static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'wec_newsletter_subscribers';
	}

	/**
	 * Ensures the subscribers table is installed or upgraded when DB_VERSION changes.
	 *
	 * @return void
	 */
	public static function maybe_install_table() {
		if ( self::$table_ensured ) {
			return;
		}

		$installed_ver = get_option( self::OPTION_DB_VERSION, '' );
		if ( self::DB_VERSION !== $installed_ver ) {
			self::install_table();
		} else {
			self::$table_ensured = true;
		}
	}

	/**
	 * Creates or updates the `{$wpdb->prefix}wec_newsletter_subscribers` table.
	 *
	 * @return void
	 */
	public static function install_table() {
		global $wpdb;

		$table_name      = self::get_table_name();
		$charset_collate = method_exists( $wpdb, 'get_charset_collate' ) ? $wpdb->get_charset_collate() : 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			email varchar(191) NOT NULL,
			first_name varchar(100) NOT NULL DEFAULT '',
			last_name varchar(100) NOT NULL DEFAULT '',
			source varchar(64) NOT NULL DEFAULT 'direct',
			status varchar(20) NOT NULL DEFAULT 'subscribed',
			ip_hash varchar(64) NOT NULL DEFAULT '',
			subscribed_at datetime NOT NULL,
			updated_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY email (email),
			KEY status_subscribed_at (status, subscribed_at)
		) {$charset_collate};";

		$upgrade_file = defined( 'ABSPATH' ) ? ABSPATH . 'wp-admin/includes/upgrade.php' : '';
		if ( $upgrade_file && file_exists( $upgrade_file ) ) {
			require_once $upgrade_file;
		}

		if ( function_exists( 'dbDelta' ) ) {
			dbDelta( $sql );
		} else {
			// Fallback for lightweight CLI test harnesses where upgrade.php is not loaded.
			$fallback_sql = preg_replace( '/^CREATE TABLE\s+/i', 'CREATE TABLE IF NOT EXISTS ', $sql );
			$wpdb->query( $fallback_sql ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		}

		update_option( self::OPTION_DB_VERSION, self::DB_VERSION, true );
		self::$table_ensured = true;
	}

	/**
	 * Returns merged newsletter settings with safe defaults.
	 *
	 * @return array{enable_checkout_optin:bool,checkout_label:string}
	 */
	public static function get_settings() {
		$defaults = array(
			'enable_checkout_optin' => false,
			'checkout_label'        => 'Recevoir nos offres exclusives et nouveautés par e-mail (désinscription en 1 clic)',
		);

		$saved = get_option( self::OPTION_SETTINGS, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		$label = isset( $saved['checkout_label'] ) ? trim( sanitize_text_field( (string) $saved['checkout_label'] ) ) : '';
		if ( '' === $label ) {
			$label = $defaults['checkout_label'];
		}

		return array(
			'enable_checkout_optin' => ! empty( $saved['enable_checkout_optin'] ),
			'checkout_label'        => $label,
		);
	}

	/**
	 * Persists newsletter settings from admin form submission.
	 *
	 * @param array $raw Raw settings input.
	 * @return void
	 */
	public static function save_settings( array $raw ) {
		$defaults = array(
			'checkout_label' => 'Recevoir nos offres exclusives et nouveautés par e-mail (désinscription en 1 clic)',
		);

		$enable = ! empty( $raw['enable_checkout_optin'] ) && '1' === (string) $raw['enable_checkout_optin'];
		$label  = isset( $raw['checkout_label'] ) ? trim( sanitize_text_field( (string) $raw['checkout_label'] ) ) : '';
		if ( '' === $label ) {
			$label = $defaults['checkout_label'];
		}

		update_option(
			self::OPTION_SETTINGS,
			array(
				'enable_checkout_optin' => $enable,
				'checkout_label'        => $label,
			),
			true
		);
	}

	/**
	 * Returns the count of currently active native subscribers.
	 *
	 * @return int
	 */
	public static function get_active_subscribers_count() {
		global $wpdb;

		self::maybe_install_table();
		$table = self::get_table_name();

		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) !== $table ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			return 0;
		}

		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table} WHERE status = 'subscribed'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Checks whether an email address is actively subscribed in `wec_newsletter_subscribers`
	 * (or actively subscribed in MailPoet if present and not explicitly unsubscribed natively).
	 *
	 * @param string $email Raw email address.
	 * @return bool
	 */
	public static function is_subscribed( string $email ): bool {
		global $wpdb;

		$normalized = Woo_Export_Contacts_Sanitizer::clean_email( $email );
		if ( ! $normalized ) {
			return false;
		}

		self::maybe_install_table();
		$table = self::get_table_name();

		$native_status = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT status FROM {$table} WHERE email = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$normalized
			)
		);

		if ( 'subscribed' === $native_status ) {
			return (bool) apply_filters( 'woo_export_contacts_is_subscribed', true, $normalized );
		}

		if ( 'unsubscribed' === $native_status ) {
			return (bool) apply_filters( 'woo_export_contacts_is_subscribed', false, $normalized );
		}

		// Fallback check against MailPoet active subscribers when installed.
		$is_mailpoet_sub = self::is_mailpoet_subscribed( $normalized );

		return (bool) apply_filters( 'woo_export_contacts_is_subscribed', $is_mailpoet_sub, $normalized );
	}

	/**
	 * Records a newsletter opt-in idempotently and triggers `woo_export_contacts_newsletter_subscribed`
	 * strictly on initial subscription or re-subscription (never on duplicate active calls).
	 *
	 * @param string $email   Raw email address.
	 * @param array  $context Optional metadata (`source`, `first_name`, `last_name`, `order_id`, etc.).
	 * @return bool True when email is valid and subscribed (including idempotent repeat calls), false otherwise.
	 */
	public static function subscribe( string $email, array $context = array() ): bool {
		global $wpdb;

		$normalized = Woo_Export_Contacts_Sanitizer::clean_email( $email );
		if ( ! $normalized ) {
			return false;
		}

		self::maybe_install_table();
		$table = self::get_table_name();

		$raw_source = isset( $context['source'] ) ? sanitize_key( (string) $context['source'] ) : '';
		$source     = '' !== $raw_source ? substr( $raw_source, 0, 64 ) : 'direct';

		$first_name = isset( $context['first_name'] ) ? Woo_Export_Contacts_Sanitizer::clean_name( (string) $context['first_name'] ) : '';
		$last_name  = isset( $context['last_name'] ) ? Woo_Export_Contacts_Sanitizer::clean_name( (string) $context['last_name'] ) : '';

		// Automatic name enrichment from existing WordPress account when caller only passed email (e.g. 1-click loyalty mission).
		if ( ( '' === $first_name || '' === $last_name ) && function_exists( 'get_user_by' ) ) {
			$wp_user = get_user_by( 'email', $normalized );
			if ( $wp_user && ! empty( $wp_user->ID ) ) {
				if ( '' === $first_name ) {
					$candidate_fn = get_user_meta( $wp_user->ID, 'billing_first_name', true );
					if ( empty( $candidate_fn ) ) {
						$candidate_fn = $wp_user->first_name ?? '';
					}
					$first_name = Woo_Export_Contacts_Sanitizer::clean_name( (string) $candidate_fn );
				}
				if ( '' === $last_name ) {
					$candidate_ln = get_user_meta( $wp_user->ID, 'billing_last_name', true );
					if ( empty( $candidate_ln ) ) {
						$candidate_ln = $wp_user->last_name ?? '';
					}
					$last_name = Woo_Export_Contacts_Sanitizer::clean_name( (string) $candidate_ln );
				}
			}
		}

		$first_name = self::truncate_str( $first_name, 100 );
		$last_name  = self::truncate_str( $last_name, 100 );
		$ip_hash    = isset( $context['ip_hash'] ) && is_string( $context['ip_hash'] )
			? substr( preg_replace( '/[^a-f0-9]/i', '', $context['ip_hash'] ), 0, 64 )
			: self::get_visitor_ip_hash();
		$now_local  = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );

		$existing = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT id, first_name, last_name, status FROM {$table} WHERE email = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$normalized
			)
		);

		// Case 1: Already actively subscribed -> idempotent no-op (enrich missing names only, do NOT re-fire reward hook).
		if ( $existing && 'subscribed' === $existing->status ) {
			$update_data   = array();
			$update_format = array();

			if ( '' === (string) $existing->first_name && '' !== $first_name ) {
				$update_data['first_name'] = $first_name;
				$update_format[]           = '%s';
			}
			if ( '' === (string) $existing->last_name && '' !== $last_name ) {
				$update_data['last_name'] = $last_name;
				$update_format[]          = '%s';
			}

			if ( ! empty( $update_data ) ) {
				$update_data['updated_at'] = $now_local;
				$update_format[]           = '%s';
				$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
					$table,
					$update_data,
					array( 'id' => (int) $existing->id ),
					$update_format,
					array( '%d' )
				);
			}

			return true;
		}

		// Case 2: Re-subscription of a previously unsubscribed contact.
		if ( $existing && 'subscribed' !== $existing->status ) {
			$merged_fn = '' !== $first_name ? $first_name : (string) $existing->first_name;
			$merged_ln = '' !== $last_name ? $last_name : (string) $existing->last_name;

			$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$table,
				array(
					'first_name'    => $merged_fn,
					'last_name'     => $merged_ln,
					'source'        => $source,
					'status'        => 'subscribed',
					'ip_hash'       => $ip_hash,
					'subscribed_at' => $now_local,
					'updated_at'    => $now_local,
				),
				array( 'id' => (int) $existing->id ),
				array( '%s', '%s', '%s', '%s', '%s', '%s', '%s' ),
				array( '%d' )
			);

			if ( false === $updated ) {
				return false;
			}

			$hook_context = array_merge(
				$context,
				array(
					'source'         => $source,
					'first_name'     => $merged_fn,
					'last_name'      => $merged_ln,
					'optin_at'       => $now_local,
					'is_resubscribe' => true,
				)
			);

			do_action( 'woo_export_contacts_newsletter_subscribed', $normalized, $hook_context );
			return true;
		}

		// Case 3: First-time native subscription (atomic UNIQUE insert protected against concurrent double-clicks).
		$prev_suppress = $wpdb->suppress_errors( true );
		$inserted      = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$table,
			array(
				'email'         => $normalized,
				'first_name'    => $first_name,
				'last_name'     => $last_name,
				'source'        => $source,
				'status'        => 'subscribed',
				'ip_hash'       => $ip_hash,
				'subscribed_at' => $now_local,
				'updated_at'    => $now_local,
			),
			array( '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);
		$wpdb->suppress_errors( $prev_suppress );

		if ( ! $inserted ) {
			// Concurrent request may have inserted the exact same email milliseconds ago.
			$race_status = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->prepare(
					"SELECT status FROM {$table} WHERE email = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$normalized
				)
			);
			return ( 'subscribed' === $race_status );
		}

		$hook_context = array_merge(
			$context,
			array(
				'source'         => $source,
				'first_name'     => $first_name,
				'last_name'      => $last_name,
				'optin_at'       => $now_local,
				'is_resubscribe' => false,
			)
		);

		do_action( 'woo_export_contacts_newsletter_subscribed', $normalized, $hook_context );

		return true;
	}

	/**
	 * Marks a subscriber as `unsubscribed` in `wec_newsletter_subscribers`.
	 *
	 * @param string $email Raw email address.
	 * @return bool True if updated or already unsubscribed, false if invalid email or not found.
	 */
	public static function unsubscribe( string $email ): bool {
		global $wpdb;

		$normalized = Woo_Export_Contacts_Sanitizer::clean_email( $email );
		if ( ! $normalized ) {
			return false;
		}

		self::maybe_install_table();
		$table     = self::get_table_name();
		$now_local = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );

		$existing = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT id, status FROM {$table} WHERE email = %s LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$normalized
			)
		);

		if ( ! $existing ) {
			return false;
		}

		if ( 'unsubscribed' === $existing->status ) {
			return true;
		}

		$updated = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$table,
			array(
				'status'     => 'unsubscribed',
				'updated_at' => $now_local,
			),
			array( 'id' => (int) $existing->id ),
			array( '%s', '%s' ),
			array( '%d' )
		);

		return false !== $updated;
	}

	/**
	 * Renders the optional CNIL-compliant newsletter opt-in checkbox on WooCommerce Checkout.
	 * Skipped automatically if the logged-in customer is already subscribed.
	 *
	 * @param mixed $checkout WC_Checkout instance.
	 * @return void
	 */
	public function render_checkout_optin_field( $checkout = null ) {
		$settings = self::get_settings();
		if ( empty( $settings['enable_checkout_optin'] ) ) {
			return;
		}

		if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) {
			$current_user = wp_get_current_user();
			if ( $current_user && ! empty( $current_user->user_email ) && self::is_subscribed( $current_user->user_email ) ) {
				return;
			}
		}

		$label = $settings['checkout_label'];
		?>
		<div class="wec-checkout-newsletter-wrap" style="margin: 14px 0 8px; clear: both;">
			<p class="form-row form-row-wide" id="wec_newsletter_optin_field" style="margin: 0;">
				<label class="woocommerce-form__label woocommerce-form__label-for-checkbox checkbox" style="display: inline-flex; align-items: flex-start; gap: 8px; cursor: pointer; font-weight: 400; line-height: 1.45;">
					<input type="checkbox" class="woocommerce-form__input woocommerce-form__input-checkbox input-checkbox" name="wec_newsletter_optin" id="wec_newsletter_optin" value="1" style="margin-top: 3px;" />
					<span><?php echo esc_html( $label ); ?></span>
				</label>
			</p>
		</div>
		<?php
	}

	/**
	 * Captures the checkout newsletter opt-in upon WooCommerce order creation (HPOS & Legacy compatible).
	 *
	 * @param int   $order_id    WooCommerce Order ID.
	 * @param array $posted_data Checkout posted data.
	 * @param mixed $order       WC_Order object.
	 * @return void
	 */
	public function capture_checkout_optin( $order_id, $posted_data = array(), $order = null ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Verified upstream by WC_Checkout::process_checkout().
		if ( empty( $_POST['wec_newsletter_optin'] ) || '1' !== sanitize_text_field( wp_unslash( $_POST['wec_newsletter_optin'] ) ) ) {
			return;
		}

		if ( ! is_object( $order ) && function_exists( 'wc_get_order' ) ) {
			$order = wc_get_order( $order_id );
		}

		$email      = '';
		$first_name = '';
		$last_name  = '';

		if ( is_object( $order ) && method_exists( $order, 'get_billing_email' ) ) {
			$email      = (string) $order->get_billing_email();
			$first_name = method_exists( $order, 'get_billing_first_name' ) ? (string) $order->get_billing_first_name() : '';
			$last_name  = method_exists( $order, 'get_billing_last_name' ) ? (string) $order->get_billing_last_name() : '';

			if ( method_exists( $order, 'update_meta_data' ) && method_exists( $order, 'save_meta_data' ) ) {
				$order->update_meta_data( '_wec_newsletter_optin', 'yes' );
				$order->save_meta_data();
			}
		} elseif ( is_array( $posted_data ) ) {
			$email      = isset( $posted_data['billing_email'] ) ? (string) $posted_data['billing_email'] : '';
			$first_name = isset( $posted_data['billing_first_name'] ) ? (string) $posted_data['billing_first_name'] : '';
			$last_name  = isset( $posted_data['billing_last_name'] ) ? (string) $posted_data['billing_last_name'] : '';
		}

		if ( '' === $email ) {
			return;
		}

		self::subscribe(
			$email,
			array(
				'source'     => 'checkout',
				'first_name' => $first_name,
				'last_name'  => $last_name,
				'order_id'   => (int) $order_id,
			)
		);
	}

	/**
	 * Renders the lightweight AJAX newsletter form `[woo_newsletter_form]`.
	 * Zero external dependencies, Edge-Cache compatible (Cloudflare Enterprise / WP Rocket), zero emojis.
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string HTML markup.
	 */
	public function render_shortcode( $atts = array() ) {
		$atts = shortcode_atts(
			array(
				'placeholder'           => 'Votre adresse e-mail',
				'button_text'           => 'S’inscrire',
				'show_firstname'        => 'no',
				'firstname_placeholder' => 'Votre prénom',
				'source'                => 'shortcode',
				'privacy_text'          => '',
				'layout'                => 'inline',
				'class'                 => '',
			),
			is_array( $atts ) ? $atts : array(),
			'woo_newsletter_form'
		);

		self::$shortcode_instance++;
		$form_id        = 'wec-nl-form-' . self::$shortcode_instance;
		$show_firstname = in_array( strtolower( (string) $atts['show_firstname'] ), array( 'yes', '1', 'true' ), true );
		$is_stacked     = 'stacked' === strtolower( (string) $atts['layout'] );
		$source         = sanitize_key( (string) $atts['source'] );
		if ( '' === $source ) {
			$source = 'shortcode';
		}

		$wrapper_classes = array( 'wec-nl-widget' );
		if ( $is_stacked ) {
			$wrapper_classes[] = 'wec-nl-widget--stacked';
		}
		if ( ! empty( $atts['class'] ) ) {
			foreach ( preg_split( '/\s+/', (string) $atts['class'], -1, PREG_SPLIT_NO_EMPTY ) as $custom_cls ) {
				$wrapper_classes[] = sanitize_html_class( $custom_cls );
			}
		}

		$prefill_email = '';
		$prefill_fn    = '';
		if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() ) {
			$user = wp_get_current_user();
			if ( $user && ! empty( $user->user_email ) ) {
				$prefill_email = $user->user_email;
				$prefill_fn    = $user->first_name ?? '';
			}
		}

		ob_start();

		if ( ! self::$shortcode_assets_printed ) {
			self::$shortcode_assets_printed = true;
			$this->print_shortcode_assets();
		}
		?>
		<div class="<?php echo esc_attr( implode( ' ', array_filter( $wrapper_classes ) ) ); ?>">
			<form id="<?php echo esc_attr( $form_id ); ?>" class="wec-nl-form" method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" novalidate>
				<input type="hidden" name="action" value="wec_newsletter_subscribe" />
				<input type="hidden" name="source" value="<?php echo esc_attr( $source ); ?>" />
				<input type="hidden" name="wec_js_check" value="0" />
				<div class="wec-nl-hp" aria-hidden="true">
					<label for="<?php echo esc_attr( $form_id ); ?>-hp">Website</label>
					<input type="text" id="<?php echo esc_attr( $form_id ); ?>-hp" name="wec_hp_website" value="" tabindex="-1" autocomplete="off" />
				</div>

				<div class="wec-nl-fields">
					<?php if ( $show_firstname ) : ?>
						<label class="wec-sr-only" for="<?php echo esc_attr( $form_id ); ?>-fn"><?php echo esc_html( $atts['firstname_placeholder'] ); ?></label>
						<input
							type="text"
							id="<?php echo esc_attr( $form_id ); ?>-fn"
							name="first_name"
							class="wec-nl-input wec-nl-input--fn"
							placeholder="<?php echo esc_attr( $atts['firstname_placeholder'] ); ?>"
							value="<?php echo esc_attr( $prefill_fn ); ?>"
							autocomplete="given-name"
						/>
					<?php endif; ?>

					<label class="wec-sr-only" for="<?php echo esc_attr( $form_id ); ?>-email"><?php echo esc_html( $atts['placeholder'] ); ?></label>
					<input
						type="email"
						id="<?php echo esc_attr( $form_id ); ?>-email"
						name="email"
						class="wec-nl-input wec-nl-input--email"
						placeholder="<?php echo esc_attr( $atts['placeholder'] ); ?>"
						value="<?php echo esc_attr( $prefill_email ); ?>"
						required
						autocomplete="email"
					/>

					<button type="submit" class="wec-nl-submit" data-default-label="<?php echo esc_attr( $atts['button_text'] ); ?>">
						<span class="wec-nl-submit-label"><?php echo esc_html( $atts['button_text'] ); ?></span>
					</button>
				</div>

				<?php if ( '' !== trim( (string) $atts['privacy_text'] ) ) : ?>
					<p class="wec-nl-privacy"><?php echo esc_html( $atts['privacy_text'] ); ?></p>
				<?php endif; ?>

				<div class="wec-nl-feedback" role="status" aria-live="polite" hidden></div>
			</form>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Outputs scoped CSS and zero-dependency Vanilla JS once per page when `[woo_newsletter_form]` is used.
	 *
	 * @return void
	 */
	private function print_shortcode_assets() {
		$ajax_url = admin_url( 'admin-ajax.php' );
		?>
		<style id="wec-newsletter-styles">
			.wec-nl-widget {
				--wec-btn-bg: #141614;
				--wec-btn-bg-hover: #292d29;
				--wec-btn-color: #ffffff;
				--wec-input-bg: #ffffff;
				--wec-input-color: #141614;
				--wec-border: #d1d5db;
				--wec-radius: 6px;
				width: 100%;
				max-width: 540px;
				font-family: inherit;
			}
			.wec-sr-only,
			.wec-nl-hp {
				position: absolute !important;
				width: 1px !important;
				height: 1px !important;
				padding: 0 !important;
				margin: -1px !important;
				overflow: hidden !important;
				clip: rect(0, 0, 0, 0) !important;
				white-space: nowrap !important;
				border: 0 !important;
			}
			.wec-nl-fields {
				display: flex;
				flex-wrap: wrap;
				gap: 8px;
				align-items: stretch;
			}
			.wec-nl-widget--stacked .wec-nl-fields {
				flex-direction: column;
			}
			.wec-nl-input {
				flex: 1 1 200px;
				min-height: 44px;
				padding: 10px 14px;
				font-size: 15px;
				line-height: 1.4;
				color: var(--wec-input-color);
				background-color: var(--wec-input-bg);
				border: 1px solid var(--wec-border);
				border-radius: var(--wec-radius);
				box-sizing: border-box;
				margin: 0;
			}
			.wec-nl-input--fn {
				flex: 1 1 140px;
			}
			.wec-nl-input:focus {
				outline: none;
				border-color: var(--wec-btn-bg);
			}
			.wec-nl-submit {
				flex: 0 0 auto;
				min-height: 44px;
				padding: 10px 20px;
				font-size: 15px;
				font-weight: 600;
				line-height: 1.4;
				color: var(--wec-btn-color);
				background-color: var(--wec-btn-bg);
				border: 1px solid var(--wec-btn-bg);
				border-radius: var(--wec-radius);
				cursor: pointer;
				transition: opacity 0.15s ease, background-color 0.15s ease;
				white-space: nowrap;
			}
			.wec-nl-submit:hover {
				background-color: var(--wec-btn-bg-hover);
				border-color: var(--wec-btn-bg-hover);
			}
			.wec-nl-submit[disabled] {
				opacity: 0.65;
				cursor: wait;
			}
			.wec-nl-privacy {
				margin: 6px 0 0;
				font-size: 12px;
				line-height: 1.4;
				opacity: 0.78;
			}
			.wec-nl-feedback {
				margin-top: 10px;
				padding: 9px 12px;
				border-radius: var(--wec-radius);
				font-size: 13.5px;
				line-height: 1.45;
				display: flex;
				align-items: center;
				gap: 8px;
			}
			.wec-nl-feedback[hidden] {
				display: none !important;
			}
			.wec-nl-feedback--success {
				background-color: #ecfdf5;
				border: 1px solid #a7f3d0;
				color: #065f46;
			}
			.wec-nl-feedback--error {
				background-color: #fef2f2;
				border: 1px solid #fecaca;
				color: #991b1b;
			}
		</style>
		<script id="wec-newsletter-script">
		(function() {
			if (window.__wecNewsletterBound) return;
			window.__wecNewsletterBound = true;

			var ajaxUrl = <?php echo wp_json_encode( $ajax_url ); ?>;
			var checkSvg = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" style="flex-shrink:0;"><path d="M20 6 9 17l-5-5"/></svg>';

			document.addEventListener('submit', function(e) {
				var form = e.target;
				if (!form || !form.classList || !form.classList.contains('wec-nl-form')) return;
				e.preventDefault();

				var emailInput = form.querySelector('input[name="email"]');
				var jsCheck    = form.querySelector('input[name="wec_js_check"]');
				var submitBtn  = form.querySelector('.wec-nl-submit');
				var btnLabel   = form.querySelector('.wec-nl-submit-label');
				var feedback   = form.querySelector('.wec-nl-feedback');

				function showMessage(msg, isSuccess) {
					if (!feedback) return;
					feedback.hidden = false;
					feedback.className = 'wec-nl-feedback ' + (isSuccess ? 'wec-nl-feedback--success' : 'wec-nl-feedback--error');
					if (isSuccess) {
						feedback.innerHTML = checkSvg + '<span></span>';
						feedback.querySelector('span').textContent = msg;
					} else {
						feedback.textContent = msg;
					}
				}

				var emailVal = emailInput ? emailInput.value.trim() : '';
				if (!emailVal || emailVal.indexOf('@') < 1 || emailVal.indexOf('.') < 3) {
					showMessage('Veuillez saisir une adresse e-mail valide.', false);
					if (emailInput) emailInput.focus();
					return;
				}

				if (jsCheck) jsCheck.value = '1';
				var defaultLabel = submitBtn ? (submitBtn.getAttribute('data-default-label') || 'S’inscrire') : 'S’inscrire';
				if (submitBtn) submitBtn.disabled = true;
				if (btnLabel) btnLabel.textContent = 'Inscription...';

				var formData = new FormData(form);

				fetch(ajaxUrl, {
					method: 'POST',
					credentials: 'same-origin',
					body: formData
				})
				.then(function(res) { return res.json(); })
				.then(function(payload) {
					if (payload && payload.success) {
						var msg = (payload.data && payload.data.message) ? payload.data.message : 'Votre inscription à la newsletter est confirmée.';
						showMessage(msg, true);
						form.reset();
					} else {
						var errMsg = (payload && payload.data && payload.data.message) ? payload.data.message : 'Impossible d’enregistrer votre inscription. Veuillez réessayer.';
						showMessage(errMsg, false);
					}
				})
				.catch(function() {
					showMessage('Erreur de connexion. Veuillez vérifier votre réseau et réessayer.', false);
				})
				.finally(function() {
					if (submitBtn) submitBtn.disabled = false;
					if (btnLabel) btnLabel.textContent = defaultLabel;
				});
			});
		})();
		</script>
		<?php
	}

	/**
	 * Handles Edge-Cache-safe AJAX submission from `[woo_newsletter_form]`.
	 *
	 * @return void
	 */
	public function handle_ajax_subscribe() {
		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : '';
		if ( 'POST' !== $request_method ) {
			wp_send_json_error( array( 'message' => 'Méthode HTTP non autorisée.' ), 405 );
		}

		// 1. Honeypot trap: silently pretend success to bots without writing to DB.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Public Edge-Cached endpoint secured via Honeypot + Same-Origin + JS check + IP Rate-Limiting.
		$honeypot = isset( $_POST['wec_hp_website'] ) ? trim( sanitize_text_field( wp_unslash( $_POST['wec_hp_website'] ) ) ) : '';
		if ( '' !== $honeypot ) {
			wp_send_json_success( array( 'message' => 'Votre inscription à la newsletter est confirmée.' ) );
		}

		// 2. JS interaction flag check (blocks raw curl/scraper POST bots).
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$js_check = isset( $_POST['wec_js_check'] ) ? sanitize_text_field( wp_unslash( $_POST['wec_js_check'] ) ) : '';
		if ( '1' !== $js_check ) {
			wp_send_json_error( array( 'message' => 'Veuillez activer JavaScript pour valider votre inscription.' ), 400 );
		}

		// 3. Same-Origin validation when Origin/Referer header is present.
		if ( ! self::is_same_origin_request() ) {
			wp_send_json_error( array( 'message' => 'Origine de la requête non autorisée.' ), 403 );
		}

		// 4. Per-IP rate limiting (max 5 attempts / 10 minutes).
		$ip_hash = self::get_visitor_ip_hash();
		if ( '' !== $ip_hash && function_exists( 'get_transient' ) ) {
			$rl_key   = 'wec_nl_rl_' . substr( $ip_hash, 0, 20 );
			$attempts = (int) get_transient( $rl_key );
			if ( $attempts >= 5 ) {
				wp_send_json_error( array( 'message' => 'Trop de tentatives rapprochées. Veuillez patienter quelques minutes.' ), 429 );
			}
			set_transient( $rl_key, $attempts + 1, 10 * MINUTE_IN_SECONDS );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$raw_email  = isset( $_POST['email'] ) ? sanitize_text_field( wp_unslash( $_POST['email'] ) ) : '';
		$normalized = Woo_Export_Contacts_Sanitizer::clean_email( $raw_email );
		if ( ! $normalized ) {
			wp_send_json_error( array( 'message' => 'Veuillez saisir une adresse e-mail valide.' ), 422 );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$first_name = isset( $_POST['first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['first_name'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$source = isset( $_POST['source'] ) ? sanitize_key( wp_unslash( $_POST['source'] ) ) : 'shortcode';
		if ( '' === $source ) {
			$source = 'shortcode';
		}

		$already_subscribed = self::is_subscribed( $normalized );

		$ok = self::subscribe(
			$normalized,
			array(
				'source'     => $source,
				'first_name' => $first_name,
				'ip_hash'    => $ip_hash,
			)
		);

		if ( ! $ok ) {
			wp_send_json_error( array( 'message' => 'Impossible d’enregistrer votre inscription pour le moment.' ), 500 );
		}

		$message = $already_subscribed
			? 'Vous êtes déjà inscrit à notre newsletter.'
			: 'Votre inscription à la newsletter est bien enregistrée.';

		wp_send_json_success(
			array(
				'already_subscribed' => $already_subscribed,
				'message'            => $message,
			)
		);
	}

	/**
	 * Checks if an email is actively subscribed in MailPoet (`mailpoet_subscribers`).
	 *
	 * @param string $normalized_email Lowercase validated email.
	 * @return bool
	 */
	private static function is_mailpoet_subscribed( string $normalized_email ): bool {
		global $wpdb;

		$mp_table = $wpdb->prefix . 'mailpoet_subscribers';
		if ( null === self::$has_mailpoet_table ) {
			self::$has_mailpoet_table = ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $mp_table ) ) === $mp_table ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		}

		if ( ! self::$has_mailpoet_table ) {
			return false;
		}

		$found = $wpdb->get_var( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prepare(
				"SELECT 1 FROM {$mp_table} WHERE LOWER(email) = %s AND status = 'subscribed' LIMIT 1", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$normalized_email
			)
		);

		return ! empty( $found );
	}

	/**
	 * Computes a GDPR-safe salted SHA-256 hash of the visitor IP address.
	 *
	 * @return string 64-char hex string or empty string if unavailable.
	 */
	public static function get_visitor_ip_hash(): string {
		$candidates = array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_REAL_IP', 'REMOTE_ADDR' );
		$raw_ip     = '';

		foreach ( $candidates as $header ) {
			if ( ! empty( $_SERVER[ $header ] ) ) {
				$val = trim( sanitize_text_field( wp_unslash( $_SERVER[ $header ] ) ) );
				if ( filter_var( $val, FILTER_VALIDATE_IP ) ) {
					$raw_ip = $val;
					break;
				}
			}
		}

		if ( '' === $raw_ip ) {
			return '';
		}

		$salt = function_exists( 'wp_salt' ) ? wp_salt( 'auth' ) : 'woo_export_contacts_ip_salt';
		return hash_hmac( 'sha256', $raw_ip, $salt );
	}

	/**
	 * Validates that Origin or Referer (when supplied by the browser) matches the site host.
	 *
	 * @return bool
	 */
	private static function is_same_origin_request(): bool {
		$site_host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( empty( $site_host ) ) {
			return true;
		}
		$site_host = strtolower( preg_replace( '/^www\./i', '', (string) $site_host ) );

		$origin = ! empty( $_SERVER['HTTP_ORIGIN'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '';
		if ( '' !== $origin && 'null' !== strtolower( $origin ) ) {
			$origin_host = wp_parse_url( $origin, PHP_URL_HOST );
			if ( ! empty( $origin_host ) ) {
				$origin_host = strtolower( preg_replace( '/^www\./i', '', (string) $origin_host ) );
				return ( $origin_host === $site_host );
			}
		}

		$referer = ! empty( $_SERVER['HTTP_REFERER'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
		if ( '' !== $referer ) {
			$ref_host = wp_parse_url( $referer, PHP_URL_HOST );
			if ( ! empty( $ref_host ) ) {
				$ref_host = strtolower( preg_replace( '/^www\./i', '', (string) $ref_host ) );
				return ( $ref_host === $site_host );
			}
		}

		return true;
	}

	/**
	 * Safely truncates a UTF-8 string to a maximum character length.
	 *
	 * @param string $value      Input string.
	 * @param int    $max_length Max characters.
	 * @return string
	 */
	private static function truncate_str( string $value, int $max_length ): string {
		if ( function_exists( 'mb_substr' ) ) {
			return mb_substr( $value, 0, $max_length, 'UTF-8' );
		}
		return substr( $value, 0, $max_length );
	}
}
