=== Woo Export Contacts ===
Contributors: SOYOO
Tags: woocommerce, export, contacts, emailit, klaviyo, csv, hpos
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 2.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Exportation CSV ultra-propre, dédoublonnée et performante des contacts marketing WooCommerce (HPOS, Comptes, Paniers abandonnés, MailPoet).

== Description ==

Woo Export Contacts est une extension conçue pour exporter facilement et rapidement vos contacts clients et prospects au format CSV prêt pour Emailit, Klaviyo ou Brevo.

= Fonctionnalités =
* Prise en charge native de WooCommerce HPOS (High-Performance Order Storage).
* Détection automatique des paniers abandonnés (CartFlows), abonnés MailPoet et alertes rupture de stock.
* Normalisation automatique des prénoms/noms (Title Case) et des numéros de téléphone (+262 Réunion/Mayotte, +33 Métropole).
* Règle de sélection "Strictement Nouveaux Contacts" excluant les clients historiques.
* Streaming direct du fichier CSV avec BOM UTF-8 (ouverture Excel sans caractères corrompus).
* Mises à jour automatiques via GitHub et plugin-update-checker.

== Installation ==

1. Téléversez le dossier du plugin dans le répertoire `/wp-content/plugins/`.
2. Activez le plugin via le menu 'Extensions' dans WordPress.
3. Rendez-vous dans WooCommerce > Export Contacts Emailit.

== Changelog ==

= 2.0.0 =
* Version initiale complète standardisée pour SOYOO et Conforama.re.
* Compatibilité HPOS et legacy posts.
* Auto-update via GitHub Releases avec PUC v5.
