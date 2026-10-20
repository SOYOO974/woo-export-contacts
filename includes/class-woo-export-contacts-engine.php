<?php
/**
 * Data extraction and CSV generation engine for Woo Export Contacts.
 *
 * @package WooExportContacts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Woo_Export_Contacts_Engine
 */
class Woo_Export_Contacts_Engine {

	/**
	 * Nom de l'option stockant les métadonnées des derniers exports par canal.
	 */
	const OPTION_LAST_EXPORTS = 'woo_export_contacts_last_exports';

	/**
	 * Détermine le canal logique ('whatsapp' ou 'email') selon le mode de colonnes CSV.
	 *
	 * @param string $columns_mode Mode de colonnes sélectionné.
	 * @return string 'whatsapp' ou 'email'.
	 */
	public static function get_channel_for_mode( $columns_mode ) {
		return in_array( $columns_mode, array( 'manychat_whatsapp', 'manychat_whatsapp_email' ), true )
			? 'whatsapp'
			: 'email';
	}

	/**
	 * Récupère l'historique des derniers exports enregistrés (par canal et global).
	 *
	 * @return array Structure contenant les clés 'any', 'email', 'whatsapp'.
	 */
	public static function get_last_exports() {
		$saved = get_option( self::OPTION_LAST_EXPORTS, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}

		return array(
			'any'      => isset( $saved['any'] ) && is_array( $saved['any'] ) ? $saved['any'] : null,
			'email'    => isset( $saved['email'] ) && is_array( $saved['email'] ) ? $saved['email'] : null,
			'whatsapp' => isset( $saved['whatsapp'] ) && is_array( $saved['whatsapp'] ) ? $saved['whatsapp'] : null,
		);
	}

	/**
	 * Enregistre les métadonnées d'un export comme nouveau point de repère.
	 *
	 * @param string $columns_mode    Mode de colonnes utilisé.
	 * @param string $timestamp_local Date/heure locale WordPress (Y-m-d H:i:s).
	 * @param string $timestamp_gmt   Date/heure GMT/UTC (Y-m-d H:i:s).
	 * @param int    $count           Nombre de contacts exportés.
	 * @param bool   $strict_new      Si le filtre strictement nouveaux était actif.
	 * @return void
	 */
	public static function save_last_export_checkpoint( $columns_mode, $timestamp_local, $timestamp_gmt, $count, $strict_new = true ) {
		$history      = self::get_last_exports();
		$channel      = self::get_channel_for_mode( $columns_mode );
		$current_user = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
		$user_login   = ( $current_user && ! empty( $current_user->user_login ) ) ? $current_user->user_login : '';

		$entry = array(
			'timestamp_local' => $timestamp_local,
			'timestamp_gmt'   => $timestamp_gmt,
			'count'           => (int) $count,
			'columns_mode'    => $columns_mode,
			'channel'         => $channel,
			'strict_new'      => (bool) $strict_new,
			'user_login'      => $user_login,
		);

		$history['any']      = $entry;
		$history[ $channel ] = $entry;

		update_option( self::OPTION_LAST_EXPORTS, $history, false );
		update_option( 'woo_export_contacts_last_export', $timestamp_local, false );
	}

	/**
	 * Définit ou réinitialise manuellement la date du dernier export.
	 *
	 * @param string      $target_channel 'all', 'email' ou 'whatsapp'.
	 * @param string|null $datetime_local Date/heure locale 'Y-m-d H:i:s' ou null pour réinitialiser.
	 * @return void
	 */
	public static function update_manual_checkpoint( $target_channel, $datetime_local = null ) {
		$history = self::get_last_exports();

		if ( empty( $datetime_local ) ) {
			if ( 'email' === $target_channel ) {
				$history['email'] = null;
				$history['any']   = $history['whatsapp'];
			} elseif ( 'whatsapp' === $target_channel ) {
				$history['whatsapp'] = null;
				$history['any']      = $history['email'];
			} else {
				$history = array(
					'any'      => null,
					'email'    => null,
					'whatsapp' => null,
				);
			}
			update_option( self::OPTION_LAST_EXPORTS, $history, false );
			if ( empty( $history['any'] ) ) {
				delete_option( 'woo_export_contacts_last_export' );
			}
			return;
		}

		$datetime_gmt = function_exists( 'get_gmt_from_date' ) ? get_gmt_from_date( $datetime_local ) : $datetime_local;
		$current_user = function_exists( 'wp_get_current_user' ) ? wp_get_current_user() : null;
		$user_login   = ( $current_user && ! empty( $current_user->user_login ) ) ? $current_user->user_login : '';

		$entry = array(
			'timestamp_local' => $datetime_local,
			'timestamp_gmt'   => $datetime_gmt,
			'count'           => -1, // Défini manuellement
			'columns_mode'    => 'whatsapp' === $target_channel ? 'manychat_whatsapp' : 'emailit_ready',
			'channel'         => 'whatsapp' === $target_channel ? 'whatsapp' : 'email',
			'strict_new'      => true,
			'user_login'      => $user_login,
		);

		if ( 'email' === $target_channel ) {
			$history['email'] = $entry;
			$history['any']   = $entry;
		} elseif ( 'whatsapp' === $target_channel ) {
			$history['whatsapp'] = $entry;
			$history['any']      = $entry;
		} else {
			$history['email']    = array_merge( $entry, array( 'channel' => 'email', 'columns_mode' => 'emailit_ready' ) );
			$history['whatsapp'] = array_merge( $entry, array( 'channel' => 'whatsapp', 'columns_mode' => 'manychat_whatsapp' ) );
			$history['any']      = $history['email'];
		}

		update_option( self::OPTION_LAST_EXPORTS, $history, false );
		update_option( 'woo_export_contacts_last_export', $datetime_local, false );
	}

	/**
	 * Exécute le processus complet d'extraction, filtrage et streaming CSV.
	 *
	 * @param array $args Paramètres soumis par le formulaire d'export.
	 * @return void
	 */
	public static function export_csv( $args ) {
		// Blindage mémoire et timeout pour gros volumes (ex. Conforama).
		@set_time_limit( 600 );
		@ini_set( 'memory_limit', '1024M' );

		global $wpdb;

		// Capture du timestamp exact de début d'extraction (évite tout trou temporel avec le prochain export)
		$execution_local = function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
		$execution_gmt   = function_exists( 'current_time' ) ? current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' );

		$is_all_time     = ( ! empty( $args['export_all_time'] ) && '1' === (string) $args['export_all_time'] ) || ( ! empty( $args['start_date'] ) && $args['start_date'] <= '2015-01-01' );
		$is_since_last   = ! $is_all_time && ! empty( $args['since_last_export'] ) && '1' === (string) $args['since_last_export'];
		$save_checkpoint = ! isset( $args['save_last_export'] ) || '1' === (string) $args['save_last_export'];

		$start_date_raw  = ! empty( $args['start_date'] ) ? sanitize_text_field( $args['start_date'] ) : '';
		$end_date_raw    = ! empty( $args['end_date'] ) ? sanitize_text_field( $args['end_date'] ) : '';
		$strict_new      = ! $is_all_time && ! empty( $args['strict_new'] ) && '1' === (string) $args['strict_new'];
		$raw_sources     = ! empty( $args['sources'] ) && is_array( $args['sources'] )
			? array_map( 'sanitize_text_field', $args['sources'] )
			: array( 'orders', 'users' );
		$delimiter_key   = ! empty( $args['csv_delimiter'] ) ? sanitize_text_field( $args['csv_delimiter'] ) : 'comma';
		$columns_mode    = ! empty( $args['csv_columns'] ) ? sanitize_text_field( $args['csv_columns'] ) : 'emailit_ready';
		$tag_prefix      = ! empty( $args['tag_prefix'] )
			? sanitize_key( $args['tag_prefix'] )
			: 'confo';

		// Domaines à exclure : domaine du site courant + soyoo.re par défaut
		$site_host            = wp_parse_url( home_url(), PHP_URL_HOST );
		$site_domain          = $site_host ? strtolower( preg_replace( '/^www\./i', '', $site_host ) ) : '';
		$default_auto_domains = array_filter( array_unique( array( $site_domain, 'soyoo.re' ) ) );

		if ( isset( $args['excluded_domains'] ) ) {
			$raw_excluded_domains = $args['excluded_domains'];
		} else {
			$saved_domains        = get_option( 'woo_export_contacts_excluded_domains', null );
			$raw_excluded_domains = ( null !== $saved_domains ) ? $saved_domains : $default_auto_domains;
		}

		$excluded_domains = Woo_Export_Contacts_Sanitizer::parse_domains_list( $raw_excluded_domains );

		// Rétrocompatibilité avec les filtres existants
		$excluded_domains = apply_filters( 'woo_export_contacts_excluded_domains', $excluded_domains );
		$excluded_domains = apply_filters( 'woo_export_contacts_internal_domains', $excluded_domains );

		if ( $is_all_time ) {
			$start_date_raw = '2000-01-01';
		} elseif ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start_date_raw ) ) {
			$start_date_raw = gmdate( 'Y-m-01' );
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end_date_raw ) ) {
			$end_date_raw = gmdate( 'Y-m-d' );
		}

		$start_datetime = $start_date_raw . ' 00:00:00';
		$end_datetime   = $end_date_raw . ' 23:59:59';
		$start_gmt      = function_exists( 'get_gmt_from_date' ) ? get_gmt_from_date( $start_datetime ) : $start_datetime;
		$end_gmt        = function_exists( 'get_gmt_from_date' ) ? get_gmt_from_date( $end_datetime ) : $end_datetime;

		// Mode incrémental "Depuis le dernier export" : précision à la seconde près (+1s après le dernier export)
		if ( $is_since_last ) {
			$checkpoint_local = '';
			if ( ! empty( $args['since_last_timestamp'] ) ) {
				$candidate = sanitize_text_field( $args['since_last_timestamp'] );
				if ( preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $candidate ) ) {
					$checkpoint_local = $candidate;
				}
			}

			if ( empty( $checkpoint_local ) ) {
				$last_exports = self::get_last_exports();
				$channel      = self::get_channel_for_mode( $columns_mode );
				if ( ! empty( $last_exports[ $channel ]['timestamp_local'] ) ) {
					$checkpoint_local = $last_exports[ $channel ]['timestamp_local'];
				} elseif ( ! empty( $last_exports['any']['timestamp_local'] ) ) {
					$checkpoint_local = $last_exports['any']['timestamp_local'];
				}
			}

			if ( ! empty( $checkpoint_local ) ) {
				$next_second_ts = strtotime( $checkpoint_local ) + 1;
				$start_datetime = gmdate( 'Y-m-d H:i:s', $next_second_ts );
				$start_date_raw = substr( $checkpoint_local, 0, 10 );
				$start_gmt      = function_exists( 'get_gmt_from_date' ) ? get_gmt_from_date( $start_datetime ) : $start_datetime;
			}
		}

		$delimiter = ( 'semicolon' === $delimiter_key ) ? ';' : ',';

		// Structure de collecte : [ 'email' => [ 'first_name', 'last_name', 'email', 'phone', 'source', 'tag', 'date' ] ]
		$contacts = array();

		// 2. Collecte des données par source
		if ( in_array( 'orders', $raw_sources, true ) ) {
			self::collect_orders( $start_gmt, $end_gmt, $start_datetime, $end_datetime, $tag_prefix, $contacts );
		}

		if ( in_array( 'users', $raw_sources, true ) ) {
			self::collect_users( $start_gmt, $end_gmt, $tag_prefix, $contacts );
		}

		if ( in_array( 'abandoned_carts', $raw_sources, true ) ) {
			self::collect_abandoned_carts( $start_datetime, $end_datetime, $tag_prefix, $contacts );
		}

		if ( in_array( 'mailpoet', $raw_sources, true ) ) {
			self::collect_mailpoet( $start_datetime, $end_datetime, $tag_prefix, $contacts );
		}

		if ( in_array( 'waitlist', $raw_sources, true ) ) {
			self::collect_waitlist( $start_datetime, $end_datetime, $tag_prefix, $contacts );
		}

		// 3. Filtrage "Strictement Nouveaux Contacts" (exclut quiconque a déjà commandé ou créé un compte avant)
		if ( $strict_new && ! empty( $contacts ) ) {
			self::filter_strictly_new( $contacts, $start_gmt, $start_datetime );
		}

		// 4. Exclusion des e-mails appartenant aux domaines exclus
		$skip_exclusion = isset( $args['exclude_internal'] ) && ( '0' === (string) $args['exclude_internal'] || false === $args['exclude_internal'] );
		if ( ! $skip_exclusion && ! empty( $excluded_domains ) && ! empty( $contacts ) ) {
			self::filter_excluded_domains( $contacts, $excluded_domains );
		}

		// 5. Streaming du fichier CSV vers le navigateur (et sauvegarde du point de repère)
		self::stream_csv(
			$contacts,
			$columns_mode,
			$delimiter,
			$start_date_raw,
			$end_date_raw,
			$strict_new,
			$is_all_time,
			$is_since_last,
			$save_checkpoint,
			$execution_local,
			$execution_gmt
		);
	}

	/**
	 * Source 1 : Commandes WooCommerce (Priorité maximale : HPOS ou tables de métadonnées de posts).
	 */
	private static function collect_orders( $start_gmt, $end_gmt, $start_local, $end_local, $tag_prefix, &$contacts ) {
		global $wpdb;

		$hpos_enabled = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

		if ( $hpos_enabled ) {
			$orders_table    = $wpdb->prefix . 'wc_orders';
			$addresses_table = $wpdb->prefix . 'wc_order_addresses';
			$orders_sql      = $wpdb->prepare(
				"SELECT o.id, o.date_created_gmt AS order_date, o.billing_email,
						a.first_name AS billing_first_name, a.last_name AS billing_last_name, a.phone AS billing_phone
				 FROM {$orders_table} o
				 LEFT JOIN {$addresses_table} a ON (o.id = a.order_id AND a.address_type = 'billing')
				 WHERE o.status NOT IN ('wc-trash', 'wc-auto-draft', 'wc-failed', 'trash', 'auto-draft')
				   AND o.billing_email IS NOT NULL AND o.billing_email != ''
				   AND o.date_created_gmt >= %s AND o.date_created_gmt <= %s
				 ORDER BY o.date_created_gmt ASC",
				$start_gmt,
				$end_gmt
			);
		} else {
			$orders_sql = $wpdb->prepare(
				"SELECT p.ID, p.post_date AS order_date,
						em.meta_value AS billing_email,
						fn.meta_value AS billing_first_name,
						ln.meta_value AS billing_last_name,
						ph.meta_value AS billing_phone
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} em ON (p.ID = em.post_id AND em.meta_key = '_billing_email' AND em.meta_value != '')
				 LEFT JOIN {$wpdb->postmeta} fn ON (p.ID = fn.post_id AND fn.meta_key = '_billing_first_name')
				 LEFT JOIN {$wpdb->postmeta} ln ON (p.ID = ln.post_id AND ln.meta_key = '_billing_last_name')
				 LEFT JOIN {$wpdb->postmeta} ph ON (p.ID = ph.post_id AND ph.meta_key = '_billing_phone')
				 WHERE p.post_type IN ('shop_order', 'shop_order_placehold')
				   AND p.post_status NOT IN ('trash', 'auto-draft', 'wc-failed')
				   AND p.post_date >= %s AND p.post_date <= %s
				 ORDER BY p.post_date ASC",
				$start_local,
				$end_local
			);
		}

		$orders = $wpdb->get_results( $orders_sql );
		if ( empty( $orders ) ) {
			return;
		}

		foreach ( $orders as $o ) {
			$email = Woo_Export_Contacts_Sanitizer::clean_email( $o->billing_email );
			if ( ! $email ) {
				continue;
			}

			$contacts[ $email ] = array(
				'first_name' => Woo_Export_Contacts_Sanitizer::clean_name( $o->billing_first_name ),
				'last_name'  => Woo_Export_Contacts_Sanitizer::clean_name( $o->billing_last_name ),
				'email'      => $email,
				'phone'      => Woo_Export_Contacts_Sanitizer::clean_phone( $o->billing_phone ),
				'source'     => 'Commande WooCommerce',
				'tag'        => $tag_prefix . '_client',
				'date'       => $o->order_date,
			);
		}
	}

	/**
	 * Source 2 : Comptes WordPress créés sur la période.
	 */
	private static function collect_users( $start_gmt, $end_gmt, $tag_prefix, &$contacts ) {
		global $wpdb;

		$users_sql = $wpdb->prepare(
			"SELECT u.ID, u.user_email, u.user_registered,
					fn.meta_value AS first_name, ln.meta_value AS last_name,
					bfn.meta_value AS billing_first_name, bln.meta_value AS billing_last_name,
					bph.meta_value AS billing_phone
			 FROM {$wpdb->users} u
			 LEFT JOIN {$wpdb->usermeta} fn ON (u.ID = fn.user_id AND fn.meta_key = 'first_name')
			 LEFT JOIN {$wpdb->usermeta} ln ON (u.ID = ln.user_id AND ln.meta_key = 'last_name')
			 LEFT JOIN {$wpdb->usermeta} bfn ON (u.ID = bfn.user_id AND bfn.meta_key = 'billing_first_name')
			 LEFT JOIN {$wpdb->usermeta} bln ON (u.ID = bln.user_id AND bln.meta_key = 'billing_last_name')
			 LEFT JOIN {$wpdb->usermeta} bph ON (u.ID = bph.user_id AND bph.meta_key = 'billing_phone')
			 WHERE u.user_registered >= %s AND u.user_registered <= %s
			 ORDER BY u.user_registered ASC",
			$start_gmt,
			$end_gmt
		);

		$users = $wpdb->get_results( $users_sql );
		if ( empty( $users ) ) {
			return;
		}

		foreach ( $users as $u ) {
			$email = Woo_Export_Contacts_Sanitizer::clean_email( $u->user_email );
			if ( ! $email ) {
				continue;
			}

			$first_name = ! empty( $u->billing_first_name ) ? $u->billing_first_name : $u->first_name;
			$last_name  = ! empty( $u->billing_last_name ) ? $u->billing_last_name : $u->last_name;

			if ( ! isset( $contacts[ $email ] ) ) {
				$contacts[ $email ] = array(
					'first_name' => Woo_Export_Contacts_Sanitizer::clean_name( $first_name ),
					'last_name'  => Woo_Export_Contacts_Sanitizer::clean_name( $last_name ),
					'email'      => $email,
					'phone'      => Woo_Export_Contacts_Sanitizer::clean_phone( $u->billing_phone ),
					'source'     => 'Compte WordPress',
					'tag'        => $tag_prefix . '_compte',
					'date'       => $u->user_registered,
				);
			} else {
				// Enrichissement du numéro s'il manquait dans la commande
				if ( empty( $contacts[ $email ]['phone'] ) && ! empty( $u->billing_phone ) ) {
					$contacts[ $email ]['phone'] = Woo_Export_Contacts_Sanitizer::clean_phone( $u->billing_phone );
				}
			}
		}
	}

	/**
	 * Source 3 : Paniers abandonnés (CartFlows ou module interne SOYOO).
	 */
	private static function collect_abandoned_carts( $start_local, $end_local, $tag_prefix, &$contacts ) {
		global $wpdb;

		$ca_table = $wpdb->prefix . 'cartflows_ca_cart_abandonment';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $ca_table ) ) !== $ca_table ) {
			return;
		}

		$ca_sql = $wpdb->prepare(
			"SELECT id, email, other_fields, time
			 FROM {$ca_table}
			 WHERE email IS NOT NULL AND email != ''
			   AND time >= %s AND time <= %s
			 ORDER BY time ASC",
			$start_local,
			$end_local
		);

		$carts = $wpdb->get_results( $ca_sql );
		if ( empty( $carts ) ) {
			return;
		}

		foreach ( $carts as $ca ) {
			$email = Woo_Export_Contacts_Sanitizer::clean_email( $ca->email );
			if ( ! $email ) {
				continue;
			}

			$first_name = '';
			$last_name  = '';
			$phone      = '';

			if ( ! empty( $ca->other_fields ) ) {
				$fields = maybe_unserialize( $ca->other_fields );
				if ( is_string( $fields ) ) {
					$decoded = json_decode( $fields, true );
					if ( is_array( $decoded ) ) {
						$fields = $decoded;
					}
				}
				if ( is_array( $fields ) ) {
					$first_name = $fields['wcf_first_name'] ?? $fields['billing_first_name'] ?? '';
					$last_name  = $fields['wcf_last_name'] ?? $fields['billing_last_name'] ?? '';
					$phone      = $fields['wcf_phone_number'] ?? $fields['billing_phone'] ?? $fields['phone'] ?? '';
				}
			}

			if ( ! isset( $contacts[ $email ] ) ) {
				$contacts[ $email ] = array(
					'first_name' => Woo_Export_Contacts_Sanitizer::clean_name( $first_name ),
					'last_name'  => Woo_Export_Contacts_Sanitizer::clean_name( $last_name ),
					'email'      => $email,
					'phone'      => Woo_Export_Contacts_Sanitizer::clean_phone( $phone ),
					'source'     => 'Panier Abandonné',
					'tag'        => $tag_prefix . '_panier_abandonne',
					'date'       => $ca->time,
				);
			}
		}
	}

	/**
	 * Source 4 : Abonnés Newsletter MailPoet.
	 */
	private static function collect_mailpoet( $start_local, $end_local, $tag_prefix, &$contacts ) {
		global $wpdb;

		$mp_table = $wpdb->prefix . 'mailpoet_subscribers';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $mp_table ) ) !== $mp_table ) {
			return;
		}

		$mp_sql = $wpdb->prepare(
			"SELECT email, first_name, last_name, created_at
			 FROM {$mp_table}
			 WHERE status = 'subscribed'
			   AND email IS NOT NULL AND email != ''
			   AND created_at >= %s AND created_at <= %s
			 ORDER BY created_at ASC",
			$start_local,
			$end_local
		);

		$mp_subscribers = $wpdb->get_results( $mp_sql );
		if ( empty( $mp_subscribers ) ) {
			return;
		}

		foreach ( $mp_subscribers as $sub ) {
			$email = Woo_Export_Contacts_Sanitizer::clean_email( $sub->email );
			if ( ! $email ) {
				continue;
			}

			if ( ! isset( $contacts[ $email ] ) ) {
				$contacts[ $email ] = array(
					'first_name' => Woo_Export_Contacts_Sanitizer::clean_name( $sub->first_name ),
					'last_name'  => Woo_Export_Contacts_Sanitizer::clean_name( $sub->last_name ),
					'email'      => $email,
					'phone'      => '',
					'source'     => 'Newsletter MailPoet',
					'tag'        => $tag_prefix . '_newsletter',
					'date'       => $sub->created_at,
				);
			}
		}
	}

	/**
	 * Source 5 : Alertes Rupture / Waitlist (Back In Stock Notifier CWG).
	 */
	private static function collect_waitlist( $start_local, $end_local, $tag_prefix, &$contacts ) {
		global $wpdb;

		$wl_table = $wpdb->prefix . 'cwginstocknotifier';
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wl_table ) ) !== $wl_table ) {
			return;
		}

		$wl_sql = $wpdb->prepare(
			"SELECT email, time
			 FROM {$wl_table}
			 WHERE email IS NOT NULL AND email != ''
			   AND time >= %s AND time <= %s
			 ORDER BY time ASC",
			$start_local,
			$end_local
		);

		$wl_subscribers = $wpdb->get_results( $wl_sql );
		if ( empty( $wl_subscribers ) ) {
			return;
		}

		foreach ( $wl_subscribers as $wl ) {
			$email = Woo_Export_Contacts_Sanitizer::clean_email( $wl->email );
			if ( ! $email ) {
				continue;
			}

			if ( ! isset( $contacts[ $email ] ) ) {
				$contacts[ $email ] = array(
					'first_name' => '',
					'last_name'  => '',
					'email'      => $email,
					'phone'      => '',
					'source'     => 'Alerte Rupture Stock',
					'tag'        => $tag_prefix . '_waitlist',
					'date'       => $wl->time,
				);
			}
		}
	}

	/**
	 * Filtre strictement nouveaux : supprime tout email ayant déjà commandé ou créé un compte avant la date de début.
	 */
	private static function filter_strictly_new( &$contacts, $start_gmt, $start_local ) {
		global $wpdb;

		$emails_to_check  = array_keys( $contacts );
		$older_emails_map = array();
		$chunks           = array_chunk( $emails_to_check, 400 );
		$hpos_enabled     = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

		foreach ( $chunks as $chunk ) {
			$placeholders = implode( ',', array_fill( 0, count( $chunk ), '%s' ) );

			// A. Vérification comptes antérieurs
			$old_users_sql   = $wpdb->prepare(
				"SELECT DISTINCT LOWER(user_email) FROM {$wpdb->users}
				 WHERE user_registered < %s AND LOWER(user_email) IN ($placeholders)",
				array_merge( array( $start_gmt ), $chunk )
			);
			$found_old_users = $wpdb->get_col( $old_users_sql );
			if ( ! empty( $found_old_users ) ) {
				foreach ( $found_old_users as $oe ) {
					$cleaned = strtolower( trim( $oe ) );
					if ( ! empty( $cleaned ) ) {
						$older_emails_map[ $cleaned ] = true;
					}
				}
			}

			// B. Vérification commandes antérieures
			if ( $hpos_enabled ) {
				$orders_table   = $wpdb->prefix . 'wc_orders';
				$old_orders_sql = $wpdb->prepare(
					"SELECT DISTINCT LOWER(billing_email) FROM {$orders_table}
					 WHERE date_created_gmt < %s AND LOWER(billing_email) IN ($placeholders)",
					array_merge( array( $start_gmt ), $chunk )
				);
			} else {
				$old_orders_sql = $wpdb->prepare(
					"SELECT DISTINCT LOWER(em.meta_value)
					 FROM {$wpdb->posts} p
					 INNER JOIN {$wpdb->postmeta} em ON (p.ID = em.post_id AND em.meta_key = '_billing_email')
					 WHERE p.post_type IN ('shop_order', 'shop_order_placehold')
					   AND p.post_status NOT IN ('trash', 'auto-draft', 'wc-failed')
					   AND p.post_date < %s
					   AND LOWER(em.meta_value) IN ($placeholders)",
					array_merge( array( $start_local ), $chunk )
				);
			}

			$found_old_orders = $wpdb->get_col( $old_orders_sql );
			if ( ! empty( $found_old_orders ) ) {
				foreach ( $found_old_orders as $oe ) {
					$cleaned = strtolower( trim( $oe ) );
					if ( ! empty( $cleaned ) ) {
						$older_emails_map[ $cleaned ] = true;
					}
				}
			}
		}

		// Élimination des contacts existants
		if ( ! empty( $older_emails_map ) ) {
			foreach ( $older_emails_map as $old_email => $val ) {
				if ( isset( $contacts[ $old_email ] ) ) {
					unset( $contacts[ $old_email ] );
				}
			}
		}
	}

	/**
	 * Élimine les e-mails appartenant aux domaines exclus de l'export.
	 *
	 * @param array $contacts Tableau de contacts référencé.
	 * @param array $excluded_domains Liste des domaines assainis à exclure.
	 * @return void
	 */
	private static function filter_excluded_domains( &$contacts, array $excluded_domains ) {
		if ( empty( $excluded_domains ) || empty( $contacts ) ) {
			return;
		}

		foreach ( array_keys( $contacts ) as $email ) {
			if ( Woo_Export_Contacts_Sanitizer::is_email_in_domains( $email, $excluded_domains ) ) {
				unset( $contacts[ $email ] );
			}
		}
	}

	/**
	 * Méthode de rétrocompatibilité pour le filtrage des domaines internes.
	 *
	 * @deprecated 2.1.0 Utiliser filter_excluded_domains() à la place.
	 * @param array $contacts Tableau de contacts référencé.
	 * @param array $internal_domains Liste des domaines à exclure.
	 * @return void
	 */
	private static function filter_internal_domains( &$contacts, array $internal_domains ) {
		$clean_domains = Woo_Export_Contacts_Sanitizer::parse_domains_list( $internal_domains );
		self::filter_excluded_domains( $contacts, $clean_domains );
	}

	/**
	 * Streaming direct du CSV vers la sortie HTTP.
	 */
	private static function stream_csv(
		$contacts,
		$columns_mode,
		$delimiter,
		$start_date_raw,
		$end_date_raw,
		$strict_new,
		$is_all_time = false,
		$is_since_last = false,
		$save_checkpoint = true,
		$execution_local = '',
		$execution_gmt = ''
	) {
		$is_manychat = in_array( $columns_mode, array( 'manychat_whatsapp', 'manychat_whatsapp_email' ), true );

		// 1. Filtrage et dédoublonnage final avant comptage et envoi des en-têtes
		if ( $is_manychat ) {
			$deduped_by_phone = array();
			foreach ( $contacts as $c ) {
				$raw_phone = isset( $c['phone'] ) ? trim( $c['phone'] ) : '';
				if ( empty( $raw_phone ) || ! Woo_Export_Contacts_Sanitizer::is_valid_phone( $raw_phone ) ) {
					continue;
				}
				$clean_phone = Woo_Export_Contacts_Sanitizer::clean_phone( $raw_phone );
				if ( isset( $deduped_by_phone[ $clean_phone ] ) ) {
					// Conserver la donnée la plus récente si disponible
					$existing_date = strtotime( $deduped_by_phone[ $clean_phone ]['date'] ?? '1970-01-01' );
					$current_date  = strtotime( $c['date'] ?? '1970-01-01' );
					if ( $current_date > $existing_date ) {
						$deduped_by_phone[ $clean_phone ] = $c;
					}
				} else {
					$deduped_by_phone[ $clean_phone ] = $c;
				}
			}
			$contacts = $deduped_by_phone;
		} else {
			$valid_contacts = array();
			foreach ( $contacts as $key => $c ) {
				$email = isset( $c['email'] ) ? sanitize_email( strtolower( trim( $c['email'] ) ) ) : '';
				if ( ! empty( $email ) && is_email( $email ) ) {
					$valid_contacts[ $key ] = $c;
				}
			}
			$contacts = $valid_contacts;
		}

		// 2. Enregistrement du point de repère "Dernier export" en base de données
		if ( $save_checkpoint ) {
			$ts_local = ! empty( $execution_local ) ? $execution_local : ( function_exists( 'current_time' ) ? current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' ) );
			$ts_gmt   = ! empty( $execution_gmt ) ? $execution_gmt : ( function_exists( 'current_time' ) ? current_time( 'mysql', true ) : gmdate( 'Y-m-d H:i:s' ) );
			self::save_last_export_checkpoint( $columns_mode, $ts_local, $ts_gmt, count( $contacts ), $strict_new );
		}

		while ( ob_get_level() > 0 ) {
			@ob_end_clean();
		}

		if ( function_exists( 'apache_setenv' ) ) {
			@apache_setenv( 'no-gzip', '1' );
		}
		@ini_set( 'zlib.output_compression', 'Off' );

		$filter_tag  = $strict_new ? 'nouveaux-strict' : 'tous-actifs';
		$site_host   = wp_parse_url( home_url(), PHP_URL_HOST );
		$site_slug   = $site_host ? sanitize_file_name( preg_replace( '/^www\./', '', $site_host ) ) : 'contacts';
		$file_prefix = $is_manychat ? 'contacts-manychat-whatsapp' : 'contacts';

		if ( $is_all_time ) {
			$filename = sprintf(
				'%s-%s-tout-historique-au-%s-%s.csv',
				$file_prefix,
				$site_slug,
				sanitize_file_name( $end_date_raw ),
				$filter_tag
			);
		} elseif ( $is_since_last ) {
			$filename = sprintf(
				'%s-%s-depuis-dernier-export-%s-au-%s-%s.csv',
				$file_prefix,
				$site_slug,
				sanitize_file_name( $start_date_raw ),
				sanitize_file_name( $end_date_raw ),
				$filter_tag
			);
		} else {
			$filename = sprintf(
				'%s-%s-%s-au-%s-%s.csv',
				$file_prefix,
				$site_slug,
				sanitize_file_name( $start_date_raw ),
				sanitize_file_name( $end_date_raw ),
				$filter_tag
			);
		}

		header( 'Content-Type: text/csv; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );
		header( 'Cache-Control: must-revalidate, post-check=0, pre-check=0' );

		$output = fopen( 'php://output', 'w' );

		// Émission du BOM UTF-8 (assure l'affichage direct des accents sans corruption dans Microsoft Excel)
		fputs( $output, "\xEF\xBB\xBF" );

		// 3. Ligne d'en-tête CSV selon le mode choisi
		if ( 'emailit_ready' === $columns_mode ) {
			// Format standardisé 100% prêt à mapper pour Emailit, Klaviyo ou Brevo
			fputcsv( $output, array( 'email', 'first_name', 'last_name', 'phone', 'tags' ), $delimiter );
		} elseif ( 'manychat_whatsapp' === $columns_mode ) {
			// Format ManyChat WhatsApp sans e-mail (Phone, First Name, Last Name, Tags)
			fputcsv( $output, array( 'phone', 'first_name', 'last_name', 'tags' ), $delimiter );
		} elseif ( 'manychat_whatsapp_email' === $columns_mode ) {
			// Format ManyChat WhatsApp avec e-mail (Phone, First Name, Last Name, Email, Tags)
			fputcsv( $output, array( 'phone', 'first_name', 'last_name', 'email', 'tags' ), $delimiter );
		} elseif ( 'simple' === $columns_mode ) {
			fputcsv( $output, array( 'Prénom', 'Nom', 'Email' ), $delimiter );
		} else {
			fputcsv( $output, array( 'Prénom', 'Nom', 'Email', 'Téléphone', 'Source', 'Tag Emailit', 'Date d\'activité' ), $delimiter );
		}

		// 4. Écriture ligne par ligne avec protection anti-injection CSV
		foreach ( $contacts as $c ) {
			$email = isset( $c['email'] ) ? sanitize_email( strtolower( trim( $c['email'] ) ) ) : '';
			$phone = isset( $c['phone'] ) ? trim( $c['phone'] ) : '';

			$first_name     = isset( $c['first_name'] ) ? trim( $c['first_name'] ) : '';
			$last_name      = isset( $c['last_name'] ) ? trim( $c['last_name'] ) : '';
			$formatted_date = ! empty( $c['date'] ) ? gmdate( 'Y-m-d H:i', strtotime( $c['date'] ) ) : '';

			// Escaping anti-injection DDE Excel
			$esc_first_name = Woo_Export_Contacts_Sanitizer::escape_csv_value( $first_name, 'first_name' );
			$esc_last_name  = Woo_Export_Contacts_Sanitizer::escape_csv_value( $last_name, 'last_name' );
			$esc_phone      = Woo_Export_Contacts_Sanitizer::escape_csv_value( Woo_Export_Contacts_Sanitizer::clean_phone( $phone ), 'phone' );
			$esc_email      = $email; // Adresse e-mail déjà validée
			$esc_tag        = Woo_Export_Contacts_Sanitizer::escape_csv_value( $c['tag'] ?? '', 'tag' );
			$esc_source     = Woo_Export_Contacts_Sanitizer::escape_csv_value( $c['source'] ?? '', 'source' );

			if ( 'emailit_ready' === $columns_mode ) {
				fputcsv( $output, array(
					$esc_email,
					$esc_first_name,
					$esc_last_name,
					$esc_phone,
					$esc_tag,
				), $delimiter );
			} elseif ( 'manychat_whatsapp' === $columns_mode ) {
				fputcsv( $output, array(
					$esc_phone,
					$esc_first_name,
					$esc_last_name,
					$esc_tag,
				), $delimiter );
			} elseif ( 'manychat_whatsapp_email' === $columns_mode ) {
				fputcsv( $output, array(
					$esc_phone,
					$esc_first_name,
					$esc_last_name,
					$esc_email,
					$esc_tag,
				), $delimiter );
			} elseif ( 'simple' === $columns_mode ) {
				fputcsv( $output, array(
					$esc_first_name,
					$esc_last_name,
					$esc_email,
				), $delimiter );
			} else {
				fputcsv( $output, array(
					$esc_first_name,
					$esc_last_name,
					$esc_email,
					$esc_phone,
					$esc_source,
					$esc_tag,
					$formatted_date,
				), $delimiter );
			}
		}

		fclose( $output );

		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}
		exit;
	}
}
