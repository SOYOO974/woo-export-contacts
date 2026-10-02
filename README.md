# Woo Export Contacts (Emailit & ManyChat WhatsApp)

Extension WooCommerce haute performance pour l'exportation CSV nettoyée, dédoublonnée et prête à l'emploi des contacts marketing à destination d'**Emailit**, **ManyChat (WhatsApp)**, Klaviyo ou Brevo.

Développée et maintenue par **SOYOO** pour Conforama.re et les sites e-commerce WooCommerce à fort trafic.

---

## 🚀 Fonctionnalités Clés

1. **Multi-Sources de Capture** :
   - 🛍️ **Commandes WooCommerce** : Compatible nativement avec le nouveau stockage de commandes haute performance **HPOS** (`wc_orders` et `wc_order_addresses`) ainsi qu'avec le stockage de métadonnées classique (`wp_posts` / `wp_postmeta`).
   - 👤 **Comptes WordPress** : Nouveaux utilisateurs enregistrés (`wp_users` et `wp_usermeta`).
   - 🛒 **Paniers Abandonnés** : Capture en temps réel depuis les tables d'abandon de panier (CartFlows / modules internes).
   - 📩 **Newsletter MailPoet** : Extraction des abonnés actifs confirmés (`mailpoet_subscribers`).
   - 🔔 **Alertes Rupture / Waitlist** : Intégration des demandes de réassort Back In Stock Notifier.

2. **Profils Dédiés Emailit & ManyChat WhatsApp** :
   - **💬 ManyChat WhatsApp Ready (Recommandé WhatsApp)** : `phone, first_name, last_name, tags`. Exclut automatiquement l'e-mail pour contourner le blocage d'approbation manuelle (*« Request approval »*) de ManyChat. Filtre et dédoublonne strictement par numéro de téléphone mobile au format international E.164 (+262 / +33) pour éviter de gonfler les paliers de facturation avec des contacts sans numéro WhatsApp.
   - **💬 ManyChat WhatsApp + Email** : `phone, first_name, last_name, email, tags`. Pour les comptes ManyChat disposant déjà de l'approbation du canal Email.
   - **🚀 Emailit Ready** : `email, first_name, last_name, phone, tags` (avec tags automatiques configurables, ex. `confo_client`, `confo_panier_abandonne`, `confo_newsletter`).
   - **📊 Format Complet / Audit** : `Prénom, Nom, Email, Téléphone, Source, Tag Emailit, Date d'activité`.
   - **⚡ Format Simplifié** : `Prénom, Nom, Email`.

3. **♾️ Option "Tout l'Historique" (Depuis la création du site)** :
   - Détection automatique et dynamique de la date la plus ancienne en base de données (première commande WooCommerce HPOS/legacy ou premier utilisateur inscrit).
   - Raccourci rapide en 1 clic et case à cocher dédiée pour exporter l'intégralité des contacts sans restriction de date de début.
   - Nom de fichier explicite : `contacts-...-tout-historique-au-...csv`.

4. **Règle Avancée "Strictement Nouveaux Contacts"** :
   - Exclut automatiquement tout contact ayant déjà passé commande ou créé un compte avant la date de début sélectionnée.
   - Idéal pour isoler les vrais nouveaux prospects et éviter de sur-solliciter la base client existante dans Emailit ou ManyChat.

5. **Nettoyage & Normalisation Automatisés** :
   - **Prénoms et Noms** : Nettoyage des caractères parasites et mise en forme intelligente de la casse (*Title Case* : `Jean-Pierre`, `Dupont`).
   - **Téléphones** : Détection et normalisation automatique au format international **E.164** (+262 pour La Réunion / Mayotte, +33 pour la France métropolitaine). Nettoyage des zéros résiduels (`+262 0692...` -> `+262692...`).
   - **Emails** : Validation rigoureuse (`is_email`), passage en minuscules et dédoublonnage automatique avec fusion des données les plus complètes.
   - **Protection Anti-Injection CSV** : Neutralisation systématique des formules malveillantes Excel (`=`, `@`, `+`, `-`).
   - **🚫 Exclusion Intelligente des Domaines Internes** : Détection et exclusion automatique du domaine du site courant et de `soyoo.re`, avec champ éditable permettant d'ajouter n'importe quel autre domaine à bannir (partenaires, collaborateurs). Persistance de la configuration en base de données.

6. **Mises à Jour Automatiques GitHub & Unification Anti-Doublon** :
   - Intégration native de `plugin-update-checker` (PUC v5).
   - Détection et installation transparente des mises à jour directement depuis les releases du dépôt [SOYOO974/woo-export-contacts](https://github.com/SOYOO974/woo-export-contacts).
   - Neutralisation automatique de tout ancien snippet ou menu orphelin legacy pour un tableau de bord 100% propre.

---

## 🛠️ Configuration & Installation

1. Téléchargez la dernière archive `.zip` depuis les Releases GitHub ou clonez le dépôt dans votre dossier `wp-content/plugins/` :
   ```bash
   git clone https://github.com/SOYOO974/woo-export-contacts.git woo-export-contacts
   ```
2. Activez l'extension via le menu **Extensions > Extensions installées** dans WordPress.
3. Accédez à l'interface d'export dans **WooCommerce > Export Contacts** (`wp-admin/admin.php?page=conforama-export-contacts`).
4. Choisissez la période désirée (ou tout l'historique), votre profil d'export (Emailit ou ManyChat) et téléchargez votre CSV en 1 clic.

---

## 🔒 Sécurité & Performance

- **Contrôle d'accès** : Requiert la capacité administrateur `manage_woocommerce`.
- **Validation CSRF** : Sécurisation de chaque export via jeton Nonce WordPress.
- **Streaming direct** : Émission en streaming direct (`php://output`) avec BOM UTF-8 pour ouverture immédiate et sans artefact dans Microsoft Excel.
- **Tolérance gros volumes** : Augmentation temporaire de l'allocation mémoire (1024M) et du temps d'exécution (600s) pour traiter plusieurs dizaines de milliers de lignes sans crash.

---

## 👨‍💻 Auteur

- **Auteur** : [SOYOO](https://soyoo.re) (Julien Vanwinsberghe)
- **Dépôt GitHub** : [https://github.com/SOYOO974/woo-export-contacts](https://github.com/SOYOO974/woo-export-contacts)
- **Licence** : GPLv2 or later
