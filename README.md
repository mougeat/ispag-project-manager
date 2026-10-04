# ISPAG Project Manager 🗂️

Gestion des **projets et offres** d'ISPAG : de la demande client à la livraison. C'est le plugin central de l'écosystème ISPAG (CRM, Achats et Tank Builder s'appuient dessus).

## Fonctionnalités

### Projets et offres
- Listes des projets et des offres (sélections), avec filtres et recherche.
- Création d'un projet ou d'une offre : choix du type, création à la volée de l'entreprise et du contact, alerte de doublon, copie depuis un projet existant. Un utilisateur standard ne voit que le nom du projet.
- Fiche projet : articles groupés, sous-articles, rabais, prix de vente, prix manuels, documents, notes, historique.
- Réplication d'un projet, transformation d'une offre en projet.

### Articles et prix
- Catalogue d'**articles standard** : prix de vente historisés, prix d'achat par fournisseur, import de prix, références ISPAG.
- Calcul du prix de vente d'un réservoir : prix d'achat × dédouanement × coefficient, plus transport au m³ si le prix est trop faible. Aucun prix de vente n'est calculé quand le prix d'achat est absent ou nul.
- Types d'article configurables (images, fournisseur par défaut, types choisissables par les utilisateurs sans droit de gestion).

### Suivi et automatisation
- Phases du projet (étapes, barre de progression, prochaine étape) et automatisations de phase.
- E-mails clients par étape, brouillons d'e-mail, rappels de plans, notifications (Telegram).
- Bons de livraison PDF avec QR code cliquable, accusé de réception de livraison.
- Calendrier des livraisons, synchronisation Baïkal (CalDAV).

### Documents et IA
- Gestion des pièces jointes par projet.
- Analyse de documents (plans, offres fournisseurs) avec Mistral / Gemini : clé Mistral saisissable dans les réglages.

### Administration
- Droits ISPAG (écran « ISPAG Rights ») : droits par rôle, diagnostic du compte connecté.
- Tables de référence modifiables (types d'article, phases, statuts, types de documents).
- Réglages ISPAG (coefficients, dédouanement, RPLP, etc.), page d'accueil installée par le plugin.
- Mise à jour automatique depuis GitHub (suivi de la branche choisie dans les réglages).
- Traductions françaises et allemandes (fichiers `languages/`, générés par `tools/i18n/build.py`).

## Shortcodes

| Shortcode | Rôle |
|---|---|
| `[ispag_projets actif="1"]` | Liste des projets |
| `[ispag_projets qotation=1]` | Liste des offres (sélections) |
| `[ispag_creation_projet]` | Nouveau projet |
| `[ispag_creation_projet qotation=1]` | Nouvelle offre |
| `[ispag_achats]` | Liste des achats (plugin ISPAG Achats) |
| `[ispag_detail]` | Détail d'un projet |

## Dépendances
À activer en premier : il fournit les bibliothèques PDF, les tables de référence et les droits partagés. Les plugins **ISPAG CRM**, **ISPAG Achats** et **ISPAG Tank Builder** puis le thème **GeneratePress Child ISPAG** viennent ensuite.

---
© 2026 ISPAG

## Application mobile (PWA, hors ligne)

Adresse : `https://<site>/ispag-app/` (à ajouter à l'écran d'accueil de l'iPhone : Safari → Partager → « Sur l'écran d'accueil »).

- **Consulter** : à chaque ouverture avec réseau, l'application télécharge un instantané des projets actifs de l'utilisateur
  (chef de projet, créateur ou ingénieur ; tous les projets avec le droit `real_all_orders`) — **sans aucun prix** — et le
  garde dans IndexedDB. Il reste consultable sans réseau.
- **Livrer** : sélection des articles, nom du réceptionnaire, signature au doigt. Hors ligne, la livraison est mise en file
  d'attente sur le téléphone puis envoyée automatiquement au retour du réseau (identifiant unique : jamais de doublon).
  Le serveur génère le PDF signé, enregistre le document dans le projet, marque les articles « livrés » et notifie le chef de projet.
- **Sécurité** : connexion une fois (droit `manage_order` requis), jeton propre à l'appareil (table `ispag_mobile_tokens`,
  90 jours, renouvelé à l'usage, révocable en supprimant la ligne). Les droits sont revérifiés à chaque appel.
- **API** (`/wp-json/ispag/v1/mobile/`) : `login`, `logout`, `snapshot`, `deliveries` — classe `ISPAG_Mobile_App`,
  fichiers de l'application dans `assets/mobile/`.
- Limite iOS : Safari peut vider le stockage d'une application non ouverte pendant des semaines → ouvrir l'application avec réseau avant de partir.
