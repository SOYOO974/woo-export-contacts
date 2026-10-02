# Contexte Technique du Projet — Woo Export Contacts

## 📌 Présentation

**Woo Export Contacts** est une extension WooCommerce propriétaire développée par l'agence **SOYOO**. Elle permet d'extraire, dédoublonner, nettoyer et normaliser les contacts marketing (clients, commandes, comptes, paniers abandonnés, abonnés MailPoet, alertes réassort) sous la forme d'un flux CSV optimisé pour l'import dans **Emailit**, Klaviyo et Brevo.

- **Dépôt GitHub** : `SOYOO974/woo-export-contacts`
- **Branche principale** : `main`
- **Mécanisme de mise à jour** : Plugin Update Checker (PUC v5) connecté aux Releases GitHub.
- **Politique de déploiement** : Aucun déploiement FTP manuel. Les mises à jour s'effectuent par commit/push Git + publication d'une Release GitHub contenant l'archive `.zip`. Les sites WordPress clients consomment la mise à jour automatiquement.

---

## ⚙️ Spécifications Fonctionnelles

1. **Multi-sources de données** :
   - Commandes WooCommerce : compatible nativement HPOS (`wc_orders` et `wc_order_addresses`) et mode hérité (`wp_posts` / `wp_postmeta`).
   - Comptes WordPress : utilisateurs enregistrés (`wp_users` et `wp_usermeta`).
   - Paniers abandonnés : intégration automatique avec la table CartFlows (`cartflows_ca_cart_abandonment`).
   - Abonnés MailPoet : intégration avec la table MailPoet (`mailpoet_subscribers`).
   - Alertes rupture : intégration avec la table Back In Stock Notifier (`cwginstocknotifier`).

2. **Règle "Strictement Nouveaux Contacts"** :
   - Exclut les e-mails ayant déjà une commande ou un compte créé avant la date de début de l'export.

3. **Exclusion dynamique des domaines e-mails** :
   - Détection automatique du nom de domaine du site actuel (`wp_parse_url(home_url(), PHP_URL_HOST)` sans `www.`) et de `soyoo.re`.
   - Champ éditable dans l'interface admin permettant de spécifier des domaines supplémentaires à exclure.
   - Sauvegarde persistante de la sélection dans `wp_options` (`woo_export_contacts_excluded_domains`).
   - Bouton de réinitialisation rapide vers les valeurs par défaut.
   - Filtrage strict au niveau du domaine et des sous-domaines (sans faux positifs de suffixe).

4. **Sanitisation & Sécurité** :
   - Nettoyage des noms et prénoms avec casse intelligente (*Title Case*).
   - Normalisation des téléphones au format international E.164 (+262 Réunion/Mayotte, +33 Métropole).
   - Validation stricte des e-mails (`is_email`) et dédoublonnage automatique.
   - Protection contre l'injection de formules tableur CSV (DDE Injection).
   - Augmentation temporaire du timeout (600s) et de la mémoire (1024M) pour les gros volumes.

5. **Profil ManyChat WhatsApp & Dédoublonnage Téléphonique** :
   - Mode ManyChat WhatsApp Ready (`phone, first_name, last_name, tags`) sans e-mail pour contourner le blocage d'approbation manuelle (*« Request approval »*) de ManyChat.
   - Filtrage strict : seuls les contacts possédant un numéro valide au standard E.164 (+262 Réunion/Mayotte, +33 Métropole) sont retenus.
   - Dédoublonnage par numéro de téléphone : en cas de multiples commandes associées à des e-mails différents avec le même numéro, la commande la plus récente est conservée.
   - Optimisation de la facturation ManyChat en évitant l'import de contacts fantômes non contactables sur WhatsApp.

6. **Option "Tout l'Historique" & Neutralisation Anti-Doublon** :
   - Raccourci rapide et option permettant d'extraire la totalité des contacts depuis la date d'origine du site (détectée automatiquement en base via `MIN(user_registered)` et `MIN(date_created_gmt)`).
   - Neutralisation automatique de l'ancien snippet legacy sur Conforama.re (`conforama_export_contacts_register_menu` et hooks associés) pour éliminer les doublons de menu et de formulaire sur l'interface.

