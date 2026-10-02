<?php
/**
 * Sanitizer utility for Woo Export Contacts.
 *
 * @package WooExportContacts
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Woo_Export_Contacts_Sanitizer
 */
class Woo_Export_Contacts_Sanitizer {

	/**
	 * Nettoie et met en forme un prénom ou nom.
	 *
	 * @param string|null $name Nom ou prénom brut.
	 * @return string
	 */
	public static function clean_name( $name ) {
		if ( empty( $name ) || ! is_string( $name ) ) {
			return '';
		}

		// Remplacement des sauts de ligne et tabulations par un espace.
		$clean = preg_replace( '/[\r\n\t]+/', ' ', $name );

		// Conservation uniquement des lettres, chiffres, tirets, apostrophes et espaces.
		$clean = trim( preg_replace( '/[^\p{L}\p{N}\s\'-]/u', '', $clean ) );

		// Élimination des espaces multiples.
		$clean = preg_replace( '/\s+/', ' ', $clean );

		if ( function_exists( 'mb_convert_case' ) ) {
			return mb_convert_case( $clean, MB_CASE_TITLE, 'UTF-8' );
		}

		return ucwords( strtolower( $clean ) );
	}

	/**
	 * Nettoie et normalise un numéro de téléphone au standard international E.164.
	 * Supporte spécifiquement La Réunion (+262), Mayotte (+262 / +269) et la France métropolitaine (+33).
	 *
	 * @param string|null $phone Numéro brut.
	 * @return string Numéro au format international ou nettoyé.
	 */
	public static function clean_phone( $phone ) {
		if ( empty( $phone ) || ! is_string( $phone ) ) {
			return '';
		}

		// Conservation des chiffres et du '+'
		$clean = preg_replace( '/[^0-9+]/', '', trim( $phone ) );

		// Normalisation de l'indicatif 00 en +
		if ( 0 === strpos( $clean, '00' ) ) {
			$clean = '+' . substr( $clean, 2 );
		}

		// Numéros locaux Réunion (0692 / 0693 / 0262) -> +262
		if ( preg_match( '/^0([26]9[23]\d{6})$/', $clean, $matches ) ) {
			return '+262' . $matches[1];
		}

		// Mayotte (0269 / 0639) -> +262
		if ( preg_match( '/^0(269\d{6}|639\d{6})$/', $clean, $matches ) ) {
			return '+262' . $matches[1];
		}

		// Saisie locale sans le 0 initial pour Réunion (262... ou 692... / 693...)
		if ( preg_match( '/^262([26]9[23]\d{6})$/', $clean, $matches ) ) {
			return '+262' . $matches[1];
		}
		if ( preg_match( '/^([26]9[23]\d{6})$/', $clean, $matches ) ) {
			return '+262' . $matches[1];
		}

		// Numéros mobiles métropole (06 / 07) -> +33
		if ( preg_match( '/^0([67]\d{8})$/', $clean, $matches ) ) {
			return '+33' . $matches[1];
		}

		// Numéros fixes métropole (01 / 02 / 03 / 04 / 05 / 09) -> +33
		if ( preg_match( '/^0([1-59]\d{8})$/', $clean, $matches ) ) {
			return '+33' . $matches[1];
		}

		// Cas avec indicatif +262 suivi d'un 0 résiduel (ex. +2620692... ou +262 0262...)
		if ( preg_match( '/^\+2620([26]9[23]\d{6})$/', $clean, $matches ) ) {
			return '+262' . $matches[1];
		}

		// Cas avec indicatif +33 suivi d'un 0 résiduel (ex. +3306... ou +33(0)6...)
		if ( preg_match( '/^\+330([1-9]\d{8})$/', $clean, $matches ) ) {
			return '+33' . $matches[1];
		}

		// Métropole saisi sans le 0 (336... / 337...)
		if ( preg_match( '/^33([1-9]\d{8})$/', $clean, $matches ) ) {
			return '+33' . $matches[1];
		}

		return $clean;
	}

	/**
	 * Vérifie si un numéro de téléphone est valide au format international E.164.
	 *
	 * @param string|null $phone Numéro brut ou nettoyé.
	 * @return bool True si le numéro respecte le standard international E.164 (+ et 8 à 15 chiffres).
	 */
	public static function is_valid_phone( $phone ) {
		if ( empty( $phone ) || ! is_string( $phone ) ) {
			return false;
		}

		$cleaned = self::clean_phone( $phone );
		return (bool) preg_match( '/^\+[1-9]\d{7,14}$/', $cleaned );
	}

	/**
	 * Nettoie et valide une adresse e-mail.
	 *
	 * @param string|null $email Adresse e-mail brute.
	 * @return string|false Adresse e-mail en minuscules ou false si invalide.
	 */
	public static function clean_email( $email ) {
		if ( empty( $email ) || ! is_string( $email ) ) {
			return false;
		}

		$cleaned = strtolower( trim( preg_replace( '/[\r\n\t\s]+/', '', $email ) ) );

		if ( ! is_email( $cleaned ) ) {
			return false;
		}

		return $cleaned;
	}

	/**
	 * Protection contre les injections de formules CSV (DDE / CSV Injection).
	 * Préfixe les champs dangereux par une apostrophe si le premier caractère est une formule (=, @, -, +).
	 * Ne modifie pas les numéros de téléphone valides commençant par +.
	 *
	 * @param string $value Valeur de la cellule.
	 * @param string $field_name Nom du champ pour exceptions ciblées.
	 * @return string
	 */
	public static function escape_csv_value( $value, $field_name = '' ) {
		if ( ! is_string( $value ) || '' === $value ) {
			return $value;
		}

		// Ne pas altérer le préfixe '+' des numéros de téléphone internationaux valides.
		if ( 'phone' === $field_name && preg_match( '/^\+[0-9]+$/', $value ) ) {
			return $value;
		}

		$first_char = substr( $value, 0, 1 );
		if ( in_array( $first_char, array( '=', '+', '-', '@', "\t", "\r" ), true ) ) {
			return "'" . $value;
		}

		return $value;
	}

	/**
	 * Analyse, nettoie et extrait une liste de domaines valides à exclure.
	 * Accepte une chaîne multi-lignes, séparée par virgules ou points-virgules, ou un tableau.
	 * Gère les variantes avec @, https://, www., sous-dossiers ou espaces.
	 *
	 * @param string|array|null $raw_domains Domaines bruts fournis.
	 * @return array Liste unique de noms de domaine propres en minuscules.
	 */
	public static function parse_domains_list( $raw_domains ) {
		if ( empty( $raw_domains ) ) {
			return array();
		}

		if ( is_array( $raw_domains ) ) {
			$raw_domains = implode( "\n", $raw_domains );
		}

		if ( ! is_string( $raw_domains ) ) {
			return array();
		}

		$entries = preg_split( '/[\r\n,;\s]+/', $raw_domains, -1, PREG_SPLIT_NO_EMPTY );
		$clean_domains = array();

		foreach ( $entries as $entry ) {
			$domain = strtolower( trim( $entry ) );

			// Supprime le protocole (ex. https://, http://)
			$domain = preg_replace( '#^https?://#i', '', $domain );

			// Si une adresse e-mail complète a été saisie (ex. contact@domaine.re), on extrait le domaine
			if ( false !== strpos( $domain, '@' ) ) {
				$parts  = explode( '@', $domain );
				$domain = end( $parts );
			}

			// Supprime les @, www. et points initiaux
			$domain = ltrim( $domain, '@.' );
			$domain = preg_replace( '/^www\./i', '', $domain );

			// Supprime les ports (:8080) ou chemins (/page) éventuels
			$domain = preg_replace( '/[:\/].*$/', '', $domain );

			// Nettoyage des caractères non autorisés dans un domaine
			$domain = preg_replace( '/[^a-z0-9.-]/', '', $domain );

			// Supprime les points résiduels aux extrémités
			$domain = trim( $domain, '.' );

			if ( ! empty( $domain ) ) {
				$clean_domains[] = $domain;
			}
		}

		return array_values( array_unique( $clean_domains ) );
	}

	/**
	 * Vérifie si une adresse e-mail appartient à l'un des domaines spécifiés (ou sous-domaines).
	 *
	 * @param string $email Adresse e-mail à vérifier.
	 * @param array  $domains Liste des domaines assainis.
	 * @return bool True si l'email appartient à l'un des domaines, false sinon.
	 */
	public static function is_email_in_domains( $email, array $domains ) {
		if ( empty( $email ) || empty( $domains ) ) {
			return false;
		}

		$email        = strtolower( trim( $email ) );
		$email_domain = substr( strrchr( $email, '@' ), 1 );

		if ( empty( $email_domain ) ) {
			return false;
		}

		foreach ( $domains as $domain ) {
			$domain = strtolower( trim( $domain ) );
			if ( empty( $domain ) ) {
				continue;
			}

			// Correspondance exacte ou sous-domaine (ex. team@sub.domaine.re correspond à domaine.re)
			if ( $email_domain === $domain || ( strlen( $email_domain ) > strlen( $domain ) && substr( $email_domain, -( strlen( $domain ) + 1 ) ) === '.' . $domain ) ) {
				return true;
			}
		}

		return false;
	}
}
