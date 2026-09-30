# Directives pour Agents Antigravity — Woo Export Contacts

## 🚫 PROTOCOLE DE DÉPLOIEMENT : ZÉRO DÉPLOIEMENT FTP AUTOMATIQUE

> [!CRITICAL]
> **INTERDICTION FORMELLE DE DÉPLOIEMENT FTP / SFTP AUTOMATIQUE ET DE PROPOSER DES TABLEAUX RÉCAPITULATIFS FTP SANS DEMANDE EXPLICITE DE JULIEN.**
> 
> Ce plugin est une extension in-house mutualisée hébergée sur GitHub (`SOYOO974/woo-export-contacts`) et équipée de **Plugin Update Checker (PUC v5)**.
> Les sites WordPress/WooCommerce sur lesquels l'extension est installée (Conforama.re, Le Comptoir de Cambaie, Jardin Naturel, etc.) se mettent à jour **automatiquement** via le système de releases GitHub.

---

## 🔄 WORKFLOW DE PUBLICATION & MISE À JOUR OBLIGATOIRE

Dès qu'une modification, correction de bug ou amélioration est apportée à cette extension :

1. **Incrémentation de Version** :
   - Mettre à jour la version sémantique dans l'en-tête de [`woo-export-contacts.php`](file:///C:/Antigravity/woo-plugins/woo-export-contacts/woo-export-contacts.php) (`Version: X.Y.Z`).
   - Mettre à jour la constante `WOO_EXPORT_CONTACTS_VERSION` dans [`woo-export-contacts.php`](file:///C:/Antigravity/woo-plugins/woo-export-contacts/woo-export-contacts.php).
   - Mettre à jour `Stable tag: X.Y.Z` et la section `= X.Y.Z =` du changelog dans [`readme.txt`](file:///C:/Antigravity/woo-plugins/woo-export-contacts/readme.txt).

2. **Validation & Linting Syntaxique** :
   - Exécuter impérativement `php -l` sur tous les fichiers PHP modifiés pour garantir zéro régression syntaxique :
     ```bash
     php -l woo-export-contacts.php
     php -l includes/class-woo-export-contacts-admin.php
     php -l includes/class-woo-export-contacts-engine.php
     php -l includes/class-woo-export-contacts-sanitizer.php
     ```

3. **Commit & Push Proactif sur GitHub** :
   - Effectuer automatiquement le cycle Git sans attendre de consigne :
     ```bash
     git add .
     git commit -m "feat/fix: <description explicite> (vX.Y.Z)"
     git push origin main
     ```

4. **Génération de l'Archive Release (.zip)** :
   - **ATTENTION** : Ne pas utiliser `Compress-Archive` PowerShell sur Windows (génère des antislashs `\` qui corrompent l'arborescence lors de l'extraction par WordPress sur serveur Linux).
   - Utiliser `tar` avec forward slashes `/` depuis le dossier parent :
     ```bash
     cd C:\Antigravity\woo-plugins
     tar -a -cf woo-export-contacts\woo-export-contacts.zip --exclude=.git --exclude=woo-export-contacts.zip woo-export-contacts
     ```

5. **Publication de la Release GitHub** :
   - Créer la release GitHub avec l'archive attachée via GitHub CLI :
     ```bash
     gh release create vX.Y.Z woo-export-contacts.zip --title "vX.Y.Z - <Titre court>" --notes "<Changelog formaté>"
     ```
   - Les sites clients détectent automatiquement la nouvelle version et l'appliquent via le tableau de bord WordPress.

---

## 🧱 ARCHITECTURE DU PLUGIN

- [`woo-export-contacts.php`](file:///C:/Antigravity/woo-plugins/woo-export-contacts/woo-export-contacts.php) : Point d'entrée, déclaration de compatibilité HPOS (`before_woocommerce_init`), chargement de `plugin-update-checker` (v5) configuré pour surveiller les release assets GitHub.
- [`includes/class-woo-export-contacts-admin.php`](file:///C:/Antigravity/woo-plugins/woo-export-contacts/includes/class-woo-export-contacts-admin.php) : Page d'administration sous WooCommerce, gestion des nonces CSRF, boutons de filtres dates rapides, configuration des colonnes CSV, champ éditable des domaines e-mails exclus et persistance en base (`wp_options` : `woo_export_contacts_excluded_domains`).
- [`includes/class-woo-export-contacts-engine.php`](file:///C:/Antigravity/woo-plugins/woo-export-contacts/includes/class-woo-export-contacts-engine.php) : Moteur d'extraction SQL haute performance (commandes HPOS `wc_orders`/legacy, comptes `wp_users`, paniers abandonnés CartFlows, abonnés MailPoet, alertes rupture), filtrage "Strictement Nouveaux Contacts" et streaming direct CSV (`php://output`) avec BOM UTF-8.
- [`includes/class-woo-export-contacts-sanitizer.php`](file:///C:/Antigravity/woo-plugins/woo-export-contacts/includes/class-woo-export-contacts-sanitizer.php) : Nettoyage et normalisation des données (Title Case pour noms/prénoms, format international E.164 +262/+33 pour téléphones, neutralisation des formules anti-injection DDE Excel, extraction et vérification étanche des domaines e-mails exclus).
