# Déploiement de skills-devops

> **Pour toute l'équipe.** Comment ce projet est déployé sur le VPS, environnement par environnement. À jour au **2026-10-05**, version du contrat de la plateforme : **2.5**.
> La plateforme (outils, règles, runbooks) : <https://github.com/wilfreidlando/vcam-infra-deploy>. Profil de projet : **A, standard (staging puis production)**
> ([profils](https://github.com/wilfreidlando/vcam-infra-deploy/blob/main/docs/reference/profils-de-projet.md)). Ce projet est le **projet pilote** : il sert d'exemple complet
> ([guide 18](https://github.com/wilfreidlando/vcam-infra-deploy/blob/main/guides/18-le-projet-pilote-skills-devops.md)).

## 1. La carte du projet : un environnement par ligne

| Environnement | Adresse | Dossier sur le serveur | Fichier d'environnement | Version déployée | Qui déploie, et comment | Sauvegardée ? |
| --- | --- | --- | --- | --- | --- | --- |
| **staging** | `https://skills-devops-staging.visibilitycam.com` | `/app/skills-devops/staging` | `.env.staging` (600) | `main` (`STAGING_BRANCH=main` dans `platform.env` ; pas de `BRANCH_PROD` : la production reçoit ce que le staging a validé) | à la main : `deploy.sh watch` (la tâche planifiée est décrite au § 5, **à poser**) | **non** (`BACKUP_DISABLED=1`) |
| **production** | `https://skills-devops.visibilitycam.com` | `/app/skills-devops/prod` | `.env` (600) | **la version validée en staging**, jamais construite en production | le responsable du projet : `deploy.sh promote` | **oui**, chaque nuit à 03:15 UTC, **sur S3** |

## 2. Ce qui tourne

| Service | Rôle | Réseaux | Santé | Données |
| --- | --- | --- | --- | --- |
| `skills-devops-web` | Application Laravel 13 sur FrankenPHP (PHP 8.4) | `nginx-proxy` (seul service public), privé, `observability` | `curl http://127.0.0.1:8000/up` | — |
| `worker` | File d'attente (arrêt volontaire toutes les heures, redémarré par Docker : normal) | privé | aucune | — |
| `scheduler` | Tâches planifiées, un battement par minute | privé | aucune | — |
| `skills-devops-db` | PostgreSQL 18 | privé **seulement** | `pg_isready` par le réseau | volume `skills-devops-<env>_db-data` |
| `skills-devops-redis` | Valkey 8.1 (cache, file) | privé **seulement** | `valkey-cli ping` | volume `skills-devops-<env>_redis-data` |
| `backup` | Sauvegarde chiffrée de la base, envoyée sur S3 puis effacée du serveur | privé | un envoi réussi il y a moins de 36 h (« starting » jusqu'au premier passage nocturne) | volume `…_backups` (quelques octets : le marqueur) |

Aucun port n'est publié sur Internet. Le projet Docker s'appelle `skills-devops-<env>` : **ne jamais le renommer** ni renommer un volume.

## 3. Les variables : leur nom et leur rôle (jamais leur valeur)

| Variable | Rôle | Diffère par environnement ? | D'où elle vient |
| --- | --- | --- | --- |
| `APP_KEY` | Chiffrement de l'application | **oui, jamais la même** | `echo "base64:$(openssl rand -base64 32)"`, conservée pour toujours |
| `DB_PASSWORD` | Mot de passe de la base | **oui** | `openssl rand -hex 24` |
| `APP_PUBLIC_HOST` | Nom public | oui | le DNS (`*.visibilitycam.com` pointe déjà sur le serveur) |
| `DEPLOYMENT` | `prod` ou `staging` : étiquette des journaux et des tableaux | oui | fixe |
| `LOG_CHANNEL=stderr`, `LOG_STDERR_FORMATTER` | Journaux **en JSON**, lus par Grafana | non | exemples du dépôt |
| `BACKUP_PASSPHRASE` | Chiffrement des sauvegardes | oui | `openssl rand -hex 32`, **conservée aussi hors du serveur** |
| `BACKUP_DISABLED` | `1` dans `.env.staging` : le staging n'est pas sauvegardé | oui (staging seulement) | fixe |
| `BACKUP_S3_ENDPOINT`, `BACKUP_S3_BUCKET`, `AWS_*` | Destination des sauvegardes | **production seulement** | le bucket de la plateforme |

Modèles : `.env.production.example` et `.env.staging.example` à la racine. Droits des `.env` : `600`, propriétaire `root`.

## 4. Premier déploiement d'un environnement

> **Commandes.** Elles s'écrivent `/app/vps-platform/bin/deploy.sh …`, ou simplement `vps-deploy …` quand les commandes courtes de la plateforme sont installées (`vps` donne l'aide, `vps where` dit où est la plateforme).

```bash
# 1. Les noms sont-ils libres ? (rien n'est modifié)
/app/vps-platform/bin/vps-hosts.sh --free skills-devops-staging.visibilitycam.com

# 2. Le dossier de l'environnement et son fichier de secrets
sudo mkdir -p /app/skills-devops && cd /app/skills-devops
sudo git clone https://github.com/wilfreidlando/skills-devops.git staging      # idem : prod
cd staging && sudo cp .env.staging.example .env.staging && sudo chmod 600 .env.staging
sudo nano .env.staging        # remplacer les CHANGE_ME ; production : cp .env.production.example .env

# 3. Contrôle avant déploiement (ne change rien) : accès git, platform.env, compose, noms
sudo /app/vps-platform/bin/deploy.sh check staging

# 4. Staging : construire puis déployer      |  Production : promouvoir la version du staging
sudo /app/vps-platform/bin/deploy.sh watch   |   cd ../prod && sudo /app/vps-platform/bin/deploy.sh promote
```

Le dépôt est **public** : un clone en HTTPS suffit. Pour un dépôt **privé**, une clé de déploiement SSH en lecture seule par dépôt (alias `github-skills-devops`) : [guide 3](https://github.com/wilfreidlando/vcam-infra-deploy/blob/main/guides/03-installer-plateforme.md).

**Vérifier** : `deploy.sh status` ; `curl -fsS https://<adresse>/up` répond 200 ; la page d'accueil affiche le **SHA** déployé et l'environnement ; Grafana, **Plateforme → Application — vue d'ensemble**, choisir `skills-devops`.

## 5. Livrer une nouvelle version

| Étape | Qui | Commande ou action | Vérification |
| --- | --- | --- | --- |
| 1. Merger sur `main` | le développeur | merge request | — |
| 2. Le staging se met à jour | à la main : `cd /app/skills-devops/staging && deploy.sh watch` | — | `deploy.sh status`, le nouveau SHA sur la page d'accueil |
| 3. **Recette** | le responsable du projet | cliquer « Envoyer un job » (carte Worker au vert), attendre la minute (carte Scheduler), `/status` | toutes les cartes au vert |
| 4. Version avec une **migration** risquée | le responsable | `deploy.sh backup prod` (une sauvegarde à la main, sur S3) | « uploaded to s3://… » dans `docker logs skills-devops-prod-backup-1` |
| 5. Production | le responsable | `cd /app/skills-devops/prod && deploy.sh promote` (taper « oui ») | `/up` 200, même SHA qu'en staging |

**Rendre le staging automatique** (à poser par le responsable de la plateforme) : `crontab -e` de `root`, puis

```cron
*/2 * * * * cd /app/skills-devops/staging && /app/vps-platform/bin/deploy.sh watch >> /var/log/vps-deploy.log 2>&1
```

La production n'est **jamais** déployée automatiquement.

## 6. Mettre à jour ce qui est déjà là

| Je change… | Je fais | Coupure | Retour arrière |
| --- | --- | --- | --- |
| Le **code** | § 5 | quelques secondes en production | `deploy.sh rollback <env>` |
| Une **variable** | éditer `.env` / `.env.staging` du serveur, puis `deploy.sh up <env> <version courante>` (voir `deploy.sh status`) | quelques secondes | remettre l'ancienne valeur, même commande |
| `compose.prod.yaml` ou `platform.env` | un commit, puis § 5 (lus **dans le commit déployé**) | comme une livraison | `deploy.sh rollback <env>` |
| La version de **PostgreSQL** | staging d'abord, avec une copie restaurée de la production | selon le cas | restauration (§ 8) |

## 7. Retour arrière

- **Automatique** : si la nouvelle version ne répond pas à `/up` après la bascule, l'ancienne est remise.
- **À la main** : `cd /app/skills-devops/<env> && deploy.sh rollback <env>`. **Les migrations ne sont pas annulées.**

## 8. Sauvegarde et restauration

- Production : sauvegarde chiffrée **chaque nuit à 03:15 UTC**, envoyée sur **S3**, conservée 30 jours, **aucune copie sur le disque du serveur**. Staging : **pas de sauvegarde**.
- À la demande : `deploy.sh backup prod`.
- **Restaurer** : `restore.sh prod s3://<bucket>/<préfixe>/skills-devops-prod/<fichier>` avec la phrase de chiffrement de la production.
- **Exercice mensuel** avec table-témoin : [procédure](https://github.com/wilfreidlando/vcam-infra-deploy/blob/main/docs/runbooks/exercice-de-restauration.md). Premier exercice (2026-10-04) : il a révélé que la restauration ne remplaçait pas la base ; corrigé,
  **à refaire avec l'agent à jour**.

## 9. Observabilité de ce projet

| Quoi | Où |
| --- | --- |
| Disponibilité et certificat | les deux adresses sont dans la liste des sites sondés ; alerte si le site ne répond plus |
| Journaux | Grafana → Explore → Loki : `{app="skills-devops", deployment="prod"}` ; **JSON** |
| État d'ensemble | Grafana → Plateforme → **Application — vue d'ensemble** → `skills-devops` |
| Métriques | `/metrics`, **réservé au réseau privé** (404 depuis Internet) ; labels `observability.*` et réseau `observability` sur le web seul |
| Tableaux et alertes propres | aucun pour l'instant ; le modèle est dans la plateforme (`templates/observabilite-projet/`) |

## 10. En cas de problème

| Symptôme | Où aller |
| --- | --- |
| Le site ne répond plus | [runbook « site en panne »](https://github.com/wilfreidlando/vcam-infra-deploy/blob/main/docs/runbooks/site-en-panne.md) |
| `deploy.sh` s'arrête avec une erreur | [runbook « déploiement en échec »](https://github.com/wilfreidlando/vcam-infra-deploy/blob/main/docs/runbooks/deploiement-en-echec.md) |
| Un conteneur redémarre sans cesse (le `worker` une fois par heure : normal) | [runbook « conteneur en boucle »](https://github.com/wilfreidlando/vcam-infra-deploy/blob/main/docs/runbooks/conteneur-en-boucle.md) |
| La base est inaccessible | [runbook « base inaccessible »](https://github.com/wilfreidlando/vcam-infra-deploy/blob/main/docs/runbooks/base-inaccessible.md) |

**Qui prévenir** : le responsable du projet pilote ; plateforme : le responsable de la plateforme.

## 11. Écarts connus avec le standard (à résorber)

| Écart | Pourquoi | Risque | Pour le résorber | Échéance |
| --- | --- | --- | --- | --- |
| **Le staging ne semble pas encore automatique** | La tâche planifiée (§ 5) ne paraît pas posée : aucune entrée dans `/etc/cron*`, pas de fichier `/var/log/vps-deploy.log`, déploiements du staging lancés à la main. **À confirmer** : le crontab de `root` n'est pas lisible sans accès root | Un merge n'arrive en staging que quand quelqu'un lance la commande | Poser la ligne `cron` du § 5 (responsable de la plateforme) | à décider |
| **Exercice de restauration à refaire** | Le premier (2026-10-04) a révélé que la restauration ne remplaçait pas la base ; l'agent a été corrigé depuis | La restauration n'a pas été rejouée avec l'agent à jour | Refaire l'exercice mensuel avec la table-témoin (§ 8) | à décider |
| **Phrase de chiffrement des sauvegardes** | Sa conservation **hors du serveur** n'est pas vérifiable depuis le serveur | Sans elle, les sauvegardes sont illisibles | Le responsable confirme où elle est conservée | à décider |
| **Dépôt public à titre provisoire** | Migration vers GitLab décidée, pas encore faite | Le dépôt et ses liens (`github.com`) changeront | Migrer, puis mettre à jour les liens de cette fiche | à décider |

## 12. Historique de ce document

| Date | Changement |
| --- | --- |
| 2026-10-05 | Création d'après le modèle de la plateforme : environnements, sauvegardes sur S3 (staging non sauvegardé), observabilité, mise à jour de ce qui existe |
| 2026-10-05 | Contrôle en profondeur : contrat 2.5 ; relue contre le serveur (sauvegarde nocturne à 03:15 UTC avec envois S3 constatés, worker relancé toutes les heures par `--max-time`) |
