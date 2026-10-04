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

## Déployer sur le VPS — sans créer aucun sous-domaine

**Pourquoi rien à créer chez Contabo :** la zone `visibilitycam.com` contient
déjà `*.visibilitycam.com → 207.180.203.19`. Tout nom à **un seul niveau**
(`skills-devops.visibilitycam.com`, `skills-devops-staging.visibilitycam.com`)
arrive donc déjà sur le VPS ; nginx-proxy le route grâce à `VIRTUAL_HOST` et
acme-companion obtient le certificat tout seul.

Prérequis : la plateforme est installée (guides 1 à 3 de vcam-infra-deploy,
`/app/vps-platform` présent, `nginx-proxy` qui tourne). Toutes les commandes
se tapent **en root sur le VPS**.

### 1. Vérifier que les noms sont libres et résolus

```bash
/app/vps-platform/bin/vps-hosts.sh --free skills-devops.visibilitycam.com
/app/vps-platform/bin/vps-hosts.sh --free skills-devops-staging.visibilitycam.com
dig +short skills-devops-staging.visibilitycam.com   # doit afficher 207.180.203.19
```

Si `dig` affiche autre chose, une ligne explicite existe chez Contabo pour ce
nom : choisissez un autre nom (et changez-le dans les `.env`, étape 3).

### 2. Donner au serveur l'accès au dépôt (clé de déploiement, lecture seule)

```bash
ssh-keygen -t ed25519 -N "" -C "vps skills-devops" -f /root/.ssh/deploy_skills_devops
cat /root/.ssh/deploy_skills_devops.pub
```

Sur GitHub : dépôt **skills-devops → Settings → Deploy keys → Add deploy key**,
titre `vps`, coller la clé, **ne pas** cocher *Allow write access*.

Puis `nano /root/.ssh/config` et ajouter à la fin :

```
Host github-skills-devops
    HostName github.com
    User git
    IdentityFile /root/.ssh/deploy_skills_devops
    IdentitiesOnly yes
```

Vérifier : `ssh -T git@github-skills-devops` → « Hi wilfreidlando/skills-devops! … ».

### 3. Un dossier par environnement + les secrets

```bash
mkdir -p /app/skills-devops
git clone git@github-skills-devops:wilfreidlando/skills-devops.git /app/skills-devops/staging
git clone git@github-skills-devops:wilfreidlando/skills-devops.git /app/skills-devops/prod

cd /app/skills-devops/staging && cp .env.staging.example .env.staging
cd /app/skills-devops/prod    && cp .env.production.example .env
```

Dans **chacun** des deux fichiers (`nano .env.staging`, `nano .env`), remplacer
les `CHANGE_ME` par des valeurs différentes pour staging et prod :

```bash
echo "base64:$(openssl rand -base64 32)"   # → APP_KEY
openssl rand -hex 24                       # → DB_PASSWORD
openssl rand -hex 32                       # → BACKUP_PASSPHRASE (à garder hors du VPS !)
```

Les variables `BACKUP_S3_*` / `AWS_*` peuvent rester vides : la sauvegarde
reste alors locale (volume `backups`). Pour l'envoyer sur MEGA S4, voir le
guide 4 de la plateforme.

```bash
chmod 600 /app/skills-devops/staging/.env.staging /app/skills-devops/prod/.env
```

### 4. Premier déploiement en staging

```bash
cd /app/skills-devops/staging
/app/vps-platform/bin/deploy.sh watch
```

`watch` construit l'image du dernier commit de `main`, vérifie les noms,
sauvegarde, migre, démarre, contrôle la santé. Ouvrir
<https://skills-devops-staging.visibilitycam.com> (le certificat peut prendre
une minute la première fois). Cliquer « Envoyer un job », recharger : la carte
Worker passe au vert ; la carte Scheduler passe au vert dans la minute.

### 5. Promotion en production (la même image)

```bash
cd /app/skills-devops/prod
/app/vps-platform/bin/deploy.sh promote     # tapez « oui »
```

Ouvrir <https://skills-devops.visibilitycam.com> : la carte Version affiche le
**même SHA** qu'en staging, avec `prod` comme environnement.

### 6. (Facultatif) Staging automatique

`crontab -e`, ajouter :

```cron
*/2 * * * * cd /app/skills-devops/staging && /app/vps-platform/bin/deploy.sh watch >> /var/log/vps-deploy.log 2>&1
```

Chaque `git push` sur `main` part alors en staging en moins de deux minutes.

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
6. **La sauvegarde** :
   `docker exec skills-devops-prod-backup-1 ls -l /backups` — il y a
   au moins la sauvegarde `pre-deploy` de la promotion.

## Tout retirer du VPS

```bash
cd /app/skills-devops/staging && docker compose -p skills-devops-staging -f compose.prod.yaml --env-file .env.staging down -v
cd /app/skills-devops/prod    && docker compose -p skills-devops-prod    -f compose.prod.yaml --env-file .env         down -v
rm -rf /app/skills-devops      # puis retirer la ligne cron et la clé de déploiement GitHub
```

`down -v` **supprime les données** (base, sauvegardes locales) : réservé à ce
projet d'apprentissage.

## En local (facultatif)

```bash
composer install && cp .env.example .env && php artisan key:generate
php artisan migrate && php artisan test
php artisan serve
```
