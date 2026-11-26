# 📂 GedFlow – Gestion électronique de documents avec circuits de validation

> **GedFlow** est une application de **GED** (gestion électronique de documents) en **Laravel 13**, **Inertia.js** et **React (TypeScript)**.
> On y dépose des documents (factures, contrats, procédures…), qui suivent un **circuit de validation par étapes** : visa des services concernés, délais, relances par e-mail. Chaque document garde ses **versions** et leur **empreinte SHA-256**. Le contenu des fichiers est **indexé pour la recherche plein texte**, l'accès est limité selon le **niveau de confidentialité** et toutes les actions sont tracées dans un **journal d'audit**.
>
> Autres fonctionnalités :
> - **certificat de validation PDF** avec QR code, vérifiable publiquement ;
> - **délégations** pendant les absences et **escalade** des étapes bloquées ;
> - **statistiques des délais** de validation ;
> - **API REST** sécurisée par jetons et documentée en OpenAPI.

![CI](https://github.com/mohammed-mrabet/gedflow/actions/workflows/ci.yml/badge.svg)

![Document à viser](docs/screenshots/03-document-a-viser.png)

## ✨ Fonctionnalités

**Documents et versions**
- Dépôt de PDF, Word, Excel, texte ou image (20 Mo maximum), avec type, service émetteur, mots-clés et niveau d'accès.
- Référence automatique par type et par année : `FAC-2026-00012`.
- Versions successives avec commentaire. Chaque fichier reçoit une **empreinte SHA-256** au dépôt. Le bouton « Vérifier l'intégrité » recalcule l'empreinte et signale tout fichier modifié sur le disque.
- Aperçu des PDF et des images dans le navigateur, téléchargement tracé.

**Circuits de validation** (le cœur du projet)
- Chaque type de document a un circuit configurable par l'administrateur : étapes ordonnées, service valideur (ou « service émetteur »), délai en heures.
  - *Facture* : visa du service demandeur, puis contrôle Finance.
  - *Contrat* : revue juridique, puis signature de la Direction.
- Les valideurs d'un service reçoivent la tâche dans leur **corbeille « À valider »** et par **e-mail**.
- Un **rejet exige un motif**. L'auteur dépose une version corrigée et lance un **nouveau tour**. L'historique de chaque tour est conservé.
- **Séparation des responsabilités** : personne ne peut viser son propre document, et l'administrateur ne valide pas à la place des services.
- **Délais et relances** : une commande planifiée relance toutes les heures les valideurs des étapes en retard (une fois par jour au maximum).
- Concurrence maîtrisée : verrou en base (`SELECT … FOR UPDATE`), pour que deux valideurs ne traitent jamais la même étape.
- À l'approbation, la date de fin de conservation est calculée (durée légale par type). Un filtre liste les documents dont la conservation est échue.

**Certificat de validation et vérification publique**
- À l'approbation, GedFlow génère un **certificat PDF**. Il récapitule :
  - la version approuvée et son empreinte SHA-256 ;
  - chaque visa (qui, quand, commentaire, « pour le compte de » en cas de délégation) ;
  - un **QR code**.
- Le QR code ouvre une **page publique** qui confirme l'authenticité du document sans connexion. On peut y **déposer un fichier reçu** : son empreinte est recalculée et comparée, et le fichier n'est pas conservé. Le titre des documents restreints n'est jamais affiché.
- Le PDF et le QR code sont produits sans moteur HTML : un petit générateur PDF maison et `bacon/bacon-qr-code` pour la matrice du QR code.

**Absences, délégations et escalade**
- Un valideur déclare une absence et désigne un remplaçant. Pendant cette période, le remplaçant reçoit les étapes du service et les vise « pour le compte de » l'absent ; la mention figure sur l'historique, le journal et le certificat.
- Règles :
  - pas de délégation en cascade ;
  - 60 jours au maximum, sans chevauchement ;
  - annulation immédiate possible ;
  - **règle des quatre yeux** : une même personne ne vise jamais deux étapes du même tour.
- **Escalade** : une étape en retard depuis plus de 48 h (paramétrable) est signalée au responsable du service et aux administrateurs, une seule fois.

**Statistiques des délais** (valideurs et administrateurs)
- Pour chaque étape : délai moyen et médian, part traitée dans les délais, taux de rejet.
- Cycle complet de la soumission à l'approbation, part des visas donnés par délégation, décisions par mois.
- Charge actuelle par service et **goulot d'étranglement** détecté automatiquement, avec des pistes d'action.

**API REST v1** (Laravel Sanctum)
- Jetons personnels créés dans « Accès API » : affichés une seule fois, valables 90 jours, révocables.
- Les **capacités** sont limitées au rôle : `documents:read`, `documents:write`, `tasks:decide`.
- Endpoints :
  - recherche (plein texte) et détail des documents, téléchargement d'une version ;
  - dépôt d'un document ou d'une nouvelle version, soumission ;
  - liste des étapes à viser et décision.
- Mêmes policies et même journal d'audit que l'interface ; limite de 60 requêtes par minute et par jeton.
- **Documentation OpenAPI 3** consultable avec Swagger UI sur `/api/docs`. Un test vérifie que la spécification décrit exactement les routes de l'API.

**Recherche**
- Le texte des PDF (`smalot/pdfparser`) et des documents Word est extrait **en tâche de fond** (file d'attente Laravel).
- Sur **PostgreSQL** : recherche plein texte native, avec index GIN et dictionnaire **français** (« pénalité » trouve « pénalités »). Sur SQLite (développement), une recherche simple par mots.
- Un extrait du contenu montre pourquoi le document correspond.

**Sécurité et traçabilité**
- Rôles : administrateur GED, valideur, contributeur, lecteur.
- Niveaux d'accès :
  - **Interne** : tous les collaborateurs ;
  - **Service** : le service émetteur et les valideurs du circuit ;
  - **Restreint** : l'auteur, les valideurs et les personnes autorisées une par une.
- Les mêmes règles existent en **policy** (une page) et en **scope SQL** (listes et recherche). Un test vérifie qu'elles donnent exactement le même résultat pour chaque profil.
- Les fichiers sont stockés hors du dossier public, sous un nom aléatoire, et servis uniquement après contrôle des droits.
- Connexion limitée à 5 tentatives par minute.
- **Journal d'audit non modifiable**, avec export **CSV** : BOM pour Excel et protection contre l'injection de formules.

## 🏗️ Architecture

```mermaid
flowchart LR
    U[Navigateur<br/>React + Inertia] --> C[Contrôleurs Laravel<br/>Policies, validation]
    C --> S[Services<br/>DocumentService, WorkflowService]
    S --> DB[(PostgreSQL<br/>index plein texte)]
    S --> FS[(Stockage privé<br/>fichiers + SHA-256)]
    S -->|tâches| Q[[File d'attente]]
    Q --> W[Worker<br/>extraction du texte, e-mails]
    W --> M[Mailpit / SMTP]
    SCH[Planificateur] -->|ged:remind-overdue| S
```

| Dossier | Contenu |
|---|---|
| `app/Services` | `DocumentService` (dépôt, versions, référence), `WorkflowService` (soumission, décisions, relances), `TextExtractor`, `AuditLogger` |
| `app/Policies` | Règles d'accès aux documents et aux tâches de validation |
| `app/Models` | `Document` (scopes `visibleTo` et `search`), `DocumentVersion`, `ReviewTask`, `WorkflowStep`, `ActivityLog`… |
| `resources/js/Pages` | Pages React : tableau de bord, documents, dépôt, détail, corbeille, circuits, journal |
| `app/Services` (suite) | `CertificateService` (PDF + QR code), `ValidationRights` (droits de visa et délégations), `StatisticsService` |
| `app/Http/Controllers/Api` | API REST v1 ; spécification dans `resources/openapi/openapi.json` |
| `tests/Feature` | 43 tests : circuit, rejets, quatre yeux, délégations, escalade, droits d'accès, certificat et vérification, statistiques, API et cohérence de la spécification OpenAPI |

## 🚀 Lancer le projet

### Avec Docker (recommandé)

```bash
docker compose up --build
```

| Service | Adresse |
|---|---|
| GedFlow | http://localhost:8080 |
| Mailpit (e-mails envoyés) | http://localhost:8025 |

Au premier démarrage, les migrations s'exécutent et des **données de démonstration fictives** sont chargées. La page de connexion liste les comptes ; le mot de passe est `password`. Le compose démarre aussi un **worker** (file d'attente) et un **planificateur** (relances).

### En local (PHP 8.3, Composer, Node 22)

```bash
composer setup     # dépendances, .env, base SQLite, données de démonstration, build de l'interface
composer dev       # serveur Laravel + Vite
```

## 🎬 Scénario de démonstration

1. Se connecter en **Karim** (`karim.valideur@gedflow.test`, valideur Achats). La facture « Transport Rapide » est **en retard** dans sa corbeille. Il peut la viser ou la rejeter (motif obligatoire).
2. Se connecter en **Sara** (`sara.contributrice@gedflow.test`). Ouvrir le contrat de maintenance : il a été **rejeté** par le Juridique, et la version 2 corrigée est déposée. Cliquer sur « Soumettre de nouveau » lance le tour 2.
3. Rechercher « pénalités » : le contrat est trouvé grâce au **contenu de son PDF**.
4. Se connecter en **Amina** (`admin@gedflow.test`). Modifier un circuit de validation, puis consulter et exporter le **journal d'audit**.
5. Toujours en **Amina** : ouvrir **Statistiques** (Finance est signalée comme goulot d'étranglement), puis une facture approuvée et son **certificat de validation**. Scanner le QR code avec un téléphone, ou cliquer sur « ouvrir », et déposer le PDF pour vérifier son authenticité.
6. Se connecter en **Youssef** (`youssef.juridique@gedflow.test`). Il remplace Nadia (Finance) cette semaine : le bon de commande apparaît dans sa corbeille « pour le compte de Nadia Tazi ».
7. Dans **Accès API**, créer un jeton puis l'essayer : `curl -H "Authorization: Bearer <jeton>" http://localhost:8080/api/v1/documents?q=facture`. Documentation : http://localhost:8080/api/docs.
8. Ouvrir Mailpit : les e-mails « À valider », « Approuvé », « Rejeté », « En retard » et « Escalade » y sont arrivés.

<details>
<summary>📸 Captures d'écran</summary>

| | |
|---|---|
| ![Connexion](docs/screenshots/00-connexion.png) | ![Tableau de bord](docs/screenshots/01-tableau-de-bord.png) |
| ![À valider](docs/screenshots/02-a-valider.png) | ![Recherche plein texte](docs/screenshots/04-recherche-plein-texte.png) |
| ![Documents](docs/screenshots/05-documents.png) | ![Document rejeté et version 2](docs/screenshots/06-document-rejete-v2.png) |
| ![Dépôt](docs/screenshots/07-depot.png) | ![Circuits](docs/screenshots/08-circuits.png) |
| ![Journal d'audit](docs/screenshots/09-journal-audit.png) | ![Statistiques](docs/screenshots/10-statistiques.png) |
| ![Certificat de validation](docs/screenshots/16-certificat.png) | ![Vérification publique](docs/screenshots/15-verification-publique.png) |
| ![Visa par délégation](docs/screenshots/12-delegation-a-valider.png) | ![Délégations](docs/screenshots/13-delegations.png) |
| ![Jetons d'API](docs/screenshots/14-jetons-api.png) | ![Document approuvé](docs/screenshots/11-document-approuve.png) |

*Captures de l'application réelle avec les données de démonstration (sociétés, personnes et montants fictifs).*
</details>

## 🧪 Tests et qualité

```bash
composer test          # PHPUnit (43 tests)
vendor/bin/pint --test # style du code PHP
npm run build          # vérification TypeScript + build
```

La CI GitHub Actions exécute :
- les tests sur **SQLite**, puis sur **PostgreSQL 16** (recherche plein texte et index GIN réels) ;
- la vérification du style (Pint) et du typage (TypeScript) ;
- le démarrage de toute la stack **Docker Compose**, avec contrôle de l'application, de la base et de l'envoi des e-mails.

## 🛠️ Stack technique

- **Back-end** : PHP 8.3, **Laravel 13** (Eloquent, Policies, Queues, Notifications, Scheduler), **Laravel Sanctum** (API).
- **Interface** : **Inertia.js 2**, **React 19**, **TypeScript**, **Tailwind CSS 4**, Vite.
- **Données** : **PostgreSQL 16** (plein texte), SQLite en développement.
- **Fichiers** : smalot/pdfparser pour l'extraction du texte, bacon/bacon-qr-code pour les QR codes.
- **API** : OpenAPI 3, Swagger UI.
- **Qualité** : PHPUnit, Laravel Pint.
- **Livraison** : Docker (Apache), Mailpit, GitHub Actions.

## 🗺️ Pistes d'amélioration

- Reconnaissance de caractères (OCR) pour les documents scannés
- Signature électronique qualifiée (eIDAS / Barid eSign) du certificat
- Stockage S3 / MinIO et chiffrement des fichiers
- Webhooks pour prévenir d'autres applications à chaque approbation

## 🤖 Méthode de travail

Ce projet a été conçu et développé avec l'aide d'un assistant IA (Claude) pour l'architecture, le code, les tests et la documentation. J'ai relu le code et je suis capable d'expliquer chaque choix technique.

## 👤 Auteur

**Mohammed MRABET** – Master Ingénierie Informatique, Faculté des Sciences d'Oujda
[LinkedIn](https://www.linkedin.com/in/mohammed-mrabet-b3021b410/) · [GitHub](https://github.com/mohammed-mrabet)
