# MG Multiservice Groupe — site PHP/MySQL

Le site public conserve son apparence. Le serveur PHP enregistre les demandes et les réglages dans MySQL, protège l’administration et donne à chaque client un espace de suivi. Deux archives sont générées : le ZIP cPanel ne contient pas les fichiers Docker; le ZIP d’aperçu local contient des identifiants de développement et ne doit jamais être envoyé sur un hébergement public.

## Vue d’ensemble

```text
Site public ─┐
Espace client ├── HTTPS → Application PHP / API → Base MySQL
Administration┘                         └──────→ Photos privées du chantier
```

L’application vérifie la session et les droits avant de servir les informations du dossier ou ses photos privées.

## Voir le site en local

Docker Desktop doit être démarré. Depuis PowerShell, lance le projet :

```powershell
cd "C:\Users\HP\Documents\ChatGPT\projet evra"
docker compose -f docker-compose.yml up --build
```

Lors de la première ouverture, va sur `http://localhost:8080/setup`. Utilise ces identifiants MySQL réservés à la prévisualisation locale :

- Hôte : `db`
- Base : `mg_multiservice`
- Utilisateur : `mg_user`
- Mot de passe : `mg_local_only_change_me`

Choisis le mot de passe de l’administration (12 caractères minimum). Le site est ensuite sur `http://localhost:8080`, l’administration sur `/admin` et l’espace client sur `/client`. Garde la fenêtre PowerShell ouverte pendant l’aperçu. `Ctrl+C` arrête les services; le volume Docker conserve la base locale lors du prochain démarrage.

## Espace client et administration

Après une demande de devis, le client reçoit une référence et un code. Il peut créer immédiatement un compte, ou revenir plus tard sur `/client` et convertir le dossier avec son e-mail, sa référence et son code. Le compte réunit les demandes, devis et photos privées liés à cet e-mail.

Le client décrit son besoin sans saisir de prix. Dans `/admin`, l’entreprise détaille les matériaux, quantités, prix unitaires, main-d’œuvre et frais. Elle peut fixer une avance en FCFA, puis renseigner les coordonnées Wave, Orange Money, MTN MoMo et/ou Moov Money. Après acceptation, le client peut imprimer ou enregistrer le devis en PDF. La date d’acceptation est stockée en UTC.

Le tableau de bord admin affiche les chantiers en cours, le total des devis acceptés pendant le mois courant (estimation), les avances confirmées pendant le mois, et le taux d’acceptation des devis répondus. Il signale les devis sans réponse depuis plus de sept jours et prépare des liens de contact WhatsApp/e-mail; l’équipe décide et effectue elle-même la relance.

Exemple fictif pour comprendre le calcul : bois et panneaux, 6 × 20 000 F = 120 000 F; quincaillerie = 35 000 F; main-d’œuvre = 150 000 F; transport et frais = 25 000 F. Total : 330 000 F. L’admin pourrait demander une avance fixe de 99 000 F (30 % dans cet exemple). Les prix et l’avance réels sont déterminés pour chaque chantier.

Après paiement, le client indique seulement l’opérateur et la référence de transaction. Le site n’accepte pas de capture d’écran ou de reçu. L’entreprise vérifie la transaction dans son compte opérateur et confirme manuellement sa réception dans l’admin. Le statut « En cours » est bloqué tant que l’avance demandée n’est pas confirmée. Aucun paiement n’est encaissé automatiquement. Un devis refusé peut être révisé et renvoyé; un dossier peut être marqué « Annulée ».

Le mot de passe client oublié peut être réinitialisé avec un lien à usage unique envoyé par e-mail, valide 30 minutes. Cela dépend de la configuration de `mail()` chez l’hébergeur. Les anciens codes de suivi peuvent être générés de nouveau dans l’admin. Après une demande sans compte, le client peut imprimer ses codes ou ouvrir WhatsApp pour se les envoyer.

Dans **Administration → Informations pratiques**, configure séparément l’adresse publique/expéditeur et l’adresse qui reçoit les notifications. Les e-mails sortants utilisent l’adresse publique configurée (par exemple `amultis@devsitwo.ci`); les alertes de demande vont à l’adresse de notification, qui peut rester votre Gmail. Le message après enregistrement indique les deux valeurs. Les notifications et liens de réinitialisation nécessitent l’envoi PHP configuré chez l’hébergeur.

Les photos d’avancement sont stockées dans `data/progress`, bloqué depuis le web, et l’API ne les sert qu’au client associé au dossier et à l’administration. Elles ne sont pas publiées dans la galerie publique.

## Conservation automatique des données

Les données d’un dossier terminé et ses photos privées sont supprimées trois ans après la clôture. Les dossiers annulés et leurs photos sont supprimés après un an. Toute demande inactive pendant 12 mois est automatiquement classée « Annulée », sauf les chantiers « En cours »; son délai de conservation d’un an commence alors. Les comptes sans demande et les liens de réinitialisation expirés sont également supprimés. La purge s’exécute uniquement par le programme CLI `cleanup.php`, jamais pendant une visite du site.

Après l’installation, fais d’abord une sauvegarde MySQL et teste avec une demande fictive. La commande `php -q /chemin/du/site/cleanup.php` lance une simulation par défaut et affiche les IDs concernés sans rien modifier. Dans phpMyAdmin, tu peux vieillir la date du dossier fictif avec `UPDATE quote_requests SET last_activity_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 13 MONTH) WHERE id = ID_TEST;`, puis vérifier la simulation. Après vérification et une nouvelle sauvegarde, `php -q /chemin/du/site/cleanup.php --apply` applique les changements. Le Cron quotidien cPanel utilise `--apply` uniquement après ces vérifications. Les sauvegardes externes doivent suivre les mêmes durées de conservation.

La page publique possède un bouton WhatsApp et un consentement obligatoire lié à `/confidentialite`. Chaque demande enregistre la date et la version du consentement. Les pages légales doivent afficher l’identité et les coordonnées exactes de l’entreprise.

Les connexions admin/client sont limitées par adresse IP; le formulaire de devis utilise un champ piège et une limite de fréquence. Dans l’admin, un original JPG/PNG/WebP de 30 Mo maximum est redimensionné à 1 920 px maximum et compressé avant l’envoi; le résultat ne dépasse pas 5 Mo. Le serveur vérifie à nouveau le type, la taille et une limite de 40 mégapixels. L’exécution de PHP est bloquée dans `assets/uploads`.

## Installation sur le sous-domaine cPanel

Cette version demande PHP 8.3, l’extension PDO MySQL (`pdo_mysql`) et `mod_rewrite`. Le sous-domaine doit avoir son propre dossier racine afin de laisser le site déjà en ligne intact.

1. Dans cPanel, crée une base MySQL et un utilisateur, puis accorde à cet utilisateur tous les privilèges sur cette base.
2. Dans MultiPHP Manager, sélectionne PHP 8.3 et vérifie que PDO MySQL (`pdo_mysql`) et `mod_rewrite` sont disponibles.
3. Téléverse et extrais l’archive cPanel dans le dossier racine du sous-domaine. Elle ne comprend aucun fichier Docker. Affiche les fichiers cachés pour vérifier les `.htaccess`.
4. Dès l’extraction, ouvre `https://ton-sous-domaine/setup` en HTTPS. Entre l’adresse officielle HTTPS du site, les identifiants MySQL cPanel et crée le mot de passe administrateur.
5. Teste `/`, `/admin`, `/client`, l’envoi d’une demande et l’ajout d’une photo. HTTPS doit déjà fonctionner avant d’ouvrir `/setup` ou de saisir un mot de passe; ne diffuse que des liens HTTPS.

Après l’installation, change tout mot de passe provisoire. Renouvelle les accès admin, MySQL et e-mail lorsqu’un collaborateur quitte l’équipe ou si un secret a été exposé. Mets à jour `config.php` de façon privée après un changement du mot de passe MySQL, et invalide les sessions actives si un accès est suspect. Garde les clés secrètes hors du ZIP et renouvelle-les après toute exposition.

Le programme d’installation crée les tables MySQL et stocke les identifiants dans `config.php`. Ce fichier est privé, ignoré par Git et bloqué par `.htaccess`. Ne téléverse pas le `config.php` de l’aperçu local : configure séparément la base cPanel depuis `/setup`. Les anciennes demandes qui existaient seulement dans la base SQLite de l’ancien serveur ne sont pas transférées automatiquement.

## Fichiers principaux

- `index.html`, `styles.css`, `hero.css`, `script.js` : site public et son apparence.
- `client.html`, `client.js` : espace client.
- `admin.html`, `admin.js` : espace professionnel.
- `api.php` : demandes, photos, sessions et suivi des clients.
- `setup.php` : première configuration de MySQL et du mot de passe administrateur.
- `Dockerfile.php`, `docker-compose.yml` : aperçu local PHP/MySQL; présents seulement dans `mg-multiservice-site-local-preview.zip`, jamais dans le ZIP cPanel.

## Mode d’emploi rapide pour l’administration

1. Connecte-toi sur `/admin` et ouvre une nouvelle demande.
2. Prépare le devis avec les lignes de matériaux, les quantités, les prix unitaires, la main-d’œuvre et les frais; vérifie le total et fixe l’avance.
3. Suis la décision dans le dossier. Après un refus, révise et renvoie le devis, ou annule la demande.
4. Après acceptation, vérifie la référence d’avance auprès de l’opérateur mobile money, puis confirme la réception dans l’admin avant de démarrer.
5. Ajoute les photos d’avancement au bon dossier. Elles sont réservées à l’admin et au client associé.
6. À la fin du chantier, marque le dossier terminé. La purge est exécutée par le Cron cPanel; les sauvegardes doivent respecter le même calendrier. Vérifie d’abord la simulation et sauvegarde la base.

Les relances listées dans l’admin sont des rappels à traiter par l’équipe; aucun SMS ou message n’est envoyé automatiquement.
