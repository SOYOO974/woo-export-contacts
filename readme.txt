=== Woo Export Contacts ===
Contributors: SOYOO
Tags: woocommerce, export, contacts, emailit, klaviyo, csv, hpos
Requires at least: 5.8
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 2.2.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Exportation CSV ultra-propre, dédoublonnée et performante des contacts marketing WooCommerce (HPOS, Comptes, Paniers abandonnés, MailPoet). Optimisé pour ManyChat WhatsApp et Emailit.

== Description ==

Woo Export Contacts est une extension conçue pour exporter facilement et rapidement vos contacts clients et prospects au format CSV prêt pour ManyChat (WhatsApp), Emailit, Klaviyo ou Brevo.

= Fonctionnalités =
* Prise en charge native de WooCommerce HPOS (High-Performance Order Storage).
* Profil d'export ManyChat WhatsApp sans e-mail (Phone, First Name, Last Name, Tags) contournant le blocage "Request approval".
* Filtrage strict et dédoublonnage par numéro de téléphone mobile au format international E.164 (+262 Réunion/Mayotte, +33 Métropole).
* Détection automatique des paniers abandonnés (CartFlows), abonnés MailPoet et alertes rupture de stock.
* Normalisation automatique des prénoms/noms (Title Case) et des numéros de téléphone (+262 Réunion/Mayotte, +33 Métropole).
* Exclusion personnalisable des domaines d'e-mails (insertion automatique du domaine du site et de soyoo.re).
* Règle de sélection "Strictement Nouveaux Contacts" excluant les clients historiques.
* Streaming direct du fichier CSV avec BOM UTF-8 (ouverture Excel sans caractères corrompus).
* Mises à jour automatiques via GitHub et plugin-update-checker.

== Installation ==

1. Téléversez le dossier du plugin dans le répertoire `/wp-content/plugins/`.
2. Activez le plugin via le menu 'Extensions' dans WordPress.
3. Rendez-vous dans WooCommerce > Export Contacts Emailit & WhatsApp.

== Changelog ==

= 2.2.1 =
* Neutralisation intégrale de toutes les notifications d'administration WordPress parasites (WooCommerce, WP Rocket, thèmes, TGMPA) sur la page de réglages de l'extension.
* Intégration d'un verrou multi-niveaux (PHP admin_notices suppression, CSS d'isolation et balise wp-header-end) pour préserver un en-tête épuré et lisible.

= 2.2.0 =
* Ajout du format d'export dédié ManyChat (WhatsApp) sans e-mail (Phone, First Name, Last Name, Tags) pour contourner l'approbation manuelle "Request approval" de ManyChat.
* Filtrage strict des numéros : seuls les contacts disposant d'un numéro international valide E.164 (+262 / +33) sont exportés.
* Dédoublonnage automatique par numéro de téléphone (en conservant les données client les plus récentes) pour éviter les doublons ManyChat et optimiser la facturation par paliers de contacts.
* Format d'export alternatif ManyChat WhatsApp + Email disponible.
* Interface d'administration enrichie avec conseils dynamiques et adaptation du bouton d'action selon le format sélectionné.

= 2.1.0 =
* Remplacement de l'option d'exclusion statique par une liste éditable de domaines e-mails à exclure.
* Insertion automatique du nom de domaine du site actuel et de soyoo.re par défaut.
* Filtrage précis et étanche des adresses e-mails par domaine et sous-domaine.
* Persistance en base de données et bouton rapide de rétablissement des valeurs par défaut.

= 2.0.0 =
* Version initiale complète standardisée pour SOYOO et Conforama.re.
* Compatibilité HPOS et legacy posts.
* Auto-update via GitHub Releases avec PUC v5.
