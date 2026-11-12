# Notes internes avant mise en ligne

- Confirmer la dénomination légale exacte de l’entreprise.
- Obtenir son adresse professionnelle complète et son numéro RCCM/immatriculation, s’il existe.
- Créer la boîte `contact@devsitwo.ci` dans cPanel avant de rendre l’adresse publique.
- Confirmer les coordonnées exactes de l’hébergeur dans le contrat cPanel.
- Configurer une tâche Cron quotidienne dans cPanel : `php -q /chemin/vers/le-site/cleanup.php` (remplacer par le chemin réel du compte).
- Régler la rotation des sauvegardes pour supprimer les copies expirées elles aussi; le script de purge efface la base et les photos privées du site actif.
- Vérifier auprès d’un conseil local les mentions à afficher et le calendrier de conservation pour les documents comptables ou autres obligations applicables.

Le site enregistre uniquement le moyen de paiement mobile money et la référence de transaction déclarés par le client. Il ne reçoit pas de capture d’écran ni de reçu de paiement.
