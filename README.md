# Carnet DevOps (skills-devops)

Mini-application Laravel 13 pour **pratiquer la plateforme du VPS** : chaque carte
de la page d'accueil prouve qu'une brique fonctionne, et chaque encadré
« Ce qu'il faut retenir » donne la commande serveur correspondante.

| Carte | Ce qu'elle prouve |
| ----- | ----------------- |
| Version | quelle image (SHA du commit) tourne, dans quel environnement, quel conteneur |
| HTTPS / proxy | nginx-proxy + Let's Encrypt, IP réelle du visiteur (`trustProxies`) |
| Base de données | PostgreSQL + migrations (compteur de visites) |
| Cache | Valkey (Redis) |
| Worker | bouton « Envoyer un job » → traité par le conteneur `worker` |
| Scheduler | battement chaque minute écrit par le conteneur `scheduler` |

`GET /status` renvoie la même chose en JSON, `GET /up` sert au contrôle de santé.

Pile : FrankenPHP (PHP 8.4, OPcache), PostgreSQL 18, Valkey 8.1, worker,
scheduler, sauvegarde chiffrée — décrite dans `compose.prod.yaml` et
`platform.env`, déployée par `/app/vps-platform/bin/deploy.sh`.

---

## Déployer sur le VPS

**Toute la procédure est dans [`docs/DEPLOIEMENT.md`](docs/DEPLOIEMENT.md)** : les environnements un par un (staging, production), le premier déploiement, comment livrer une
version, comment mettre à jour une variable ou le compose, le retour arrière, les sauvegardes, l'observabilité, et quoi faire en cas de problème. Elle suit le modèle de la
plateforme ([`templates/docs-projet/`](https://github.com/wilfreidlando/vcam-infra-deploy/tree/main/templates/docs-projet)) et sert d'exemple à tous les projets.

**Aucun nom à créer chez l'hébergeur DNS.** La zone `visibilitycam.com` contient déjà un enregistrement générique `*.visibilitycam.com` qui pointe sur le serveur : tout nom à **un seul
niveau** (`skills-devops.visibilitycam.com`, `skills-devops-staging.visibilitycam.com`) arrive donc déjà sur le VPS ; `nginx-proxy` le route grâce à `VIRTUAL_HOST` et `acme-companion`
obtient le certificat tout seul. Vérifier qu'un nom est libre : `/app/vps-platform/bin/vps-hosts.sh --free <nom>`.

Prérequis : la plateforme est installée (guides 1 à 3 de [`vcam-infra-deploy`](https://github.com/wilfreidlando/vcam-infra-deploy), `/app/vps-platform` présent, `nginx-proxy` qui tourne).
Les commandes se tapent **en `root` sur le VPS**.

En deux lignes, une fois les dossiers et les `.env` prêts :

```bash
cd /app/skills-devops/staging && /app/vps-platform/bin/deploy.sh watch      # le staging suit main
cd /app/skills-devops/prod    && /app/vps-platform/bin/deploy.sh promote    # la production reçoit l'image testée en staging
```

---

## Exercices (dans l'ordre)

1. **Le cycle complet** : changez un texte dans
   `resources/views/dashboard.blade.php`, poussez sur `main`, lancez `watch`
   (ou attendez le cron), vérifiez le nouveau SHA en staging, puis `promote`.
2. **Le retour arrière** : `deploy.sh rollback prod` depuis
   `/app/skills-devops/prod` → l'ancien SHA revient. `deploy.sh status` montre
   l'actuel et le précédent de chaque environnement.
3. **Le retour automatique** : poussez un commit qui casse `/up` (par exemple une
   erreur de syntaxe PHP dans `routes/web.php`) → `watch` échoue au contrôle de
   santé et remet la version précédente tout seul. Annulez ensuite le commit.
4. **Docker relance les processus** :
   `docker kill skills-devops-staging-worker-1` puis `docker ps` quelques
   secondes plus tard → il est revenu (`restart: unless-stopped`).
5. **Les journaux** :
   `docker logs -f skills-devops-staging-worker-1`, puis dans Grafana
   filtrez sur `app=skills-devops` (guide observabilité).
6. **La sauvegarde** : les sauvegardes **vivent sur S3, pas sur le serveur**. Lancer
   `/app/vps-platform/bin/deploy.sh backup prod` depuis `/app/skills-devops/prod`, puis
   `docker logs --tail 3 skills-devops-prod-backup-1` : la ligne `uploaded to s3://…` prouve l'envoi, et `docker exec skills-devops-prod-backup-1 ls -A /backups` ne montre
   que le marqueur d'envoi. Le staging, lui, n'est pas sauvegardé (`BACKUP_DISABLED=1`).
7. **Observer le projet** : Grafana → Plateforme → **Application — vue d'ensemble** → `skills-devops` : disponibilité, conteneurs, mémoire, redémarrages, journaux.

## Tout retirer du VPS

```bash
cd /app/skills-devops/staging && docker compose -p skills-devops-staging -f compose.prod.yaml --env-file .env.staging down
cd /app/skills-devops/prod    && docker compose -p skills-devops-prod    -f compose.prod.yaml --env-file .env         down
```

`down` **arrête et retire les conteneurs sans toucher aux volumes** (base, sauvegardes). Supprimer les volumes (`docker volume rm`) puis le dossier `/app/skills-devops`
est une **décision séparée, prise par la personne qui gère le serveur** : la plateforme ne supprime jamais de volume. Penser aussi à retirer la ligne cron et, pour un dépôt privé, la clé de déploiement.

## En local (facultatif)

```bash
composer install && cp .env.example .env && php artisan key:generate
php artisan migrate && php artisan test
php artisan serve
```
