# Déploiement

Tout passe par GitHub Actions (`.github/workflows/ci.yml`), sur le même modèle que kadens. Rien n'est construit sur le serveur.

## Déroulé

1. **test** : lints, PHPStan, PHPUnit sur MariaDB 10.11. Tourne sur chaque PR et chaque push sur `main`.
2. **build** : `composer install --no-dev`, compilation des assets, archive `release.tar.gz` publiée en artifact.
3. **deploy** (push sur `main` uniquement, après `test` et `build`) :
   - attend l'approbation manuelle sur l'environnement `production` ;
   - rsync de la release vers `APP_DIR` (`.env.local` et `var/` sont protégés) ;
   - `cache:clear`, `cache:warmup`, migrations Doctrine sur le serveur.

Tant que la variable `SSH_HOST` n'existe pas, le job `deploy` est ignoré.

## Configuration GitHub

Settings > Environments : créer `production` et ajouter un reviewer obligatoire (c'est le gate manuel).

Settings > Secrets and variables > Actions :

| Type     | Nom               | Valeur                                                    |
|----------|-------------------|-----------------------------------------------------------|
| Secret   | `SSH_PRIVATE_KEY` | Clé privée dont la clé publique est dans `~/.ssh/authorized_keys` du serveur |
| Variable | `SSH_HOST`        | Hôte du serveur                                           |
| Variable | `SSH_USER`        | Utilisateur SSH                                           |
| Variable | `SSH_PORT`        | Port SSH                                                  |
| Variable | `APP_DIR`         | Chemin absolu de l'app sur le serveur                     |
| Variable | `REMOTE_PHP`      | Binaire PHP CLI ≥ 8.4.1 (optionnel, `php` par défaut)      |
| Variable | `APP_URL`         | URL publique, affichée sur le déploiement (optionnel)     |

## Pré-requis serveur

- PHP ≥ 8.4.1 avec `ctype`, `iconv`, `intl`, `pdo_mysql`, `gd` (redimensionnement des images), et MariaDB 10.11. Composer n'est pas nécessaire, `vendor/` arrive avec la release.
- Document root sur `APP_DIR/public`.
- Un fichier `APP_DIR/.env.local`, jamais écrasé par le rsync :

```dotenv
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=<openssl rand -hex 32>
DATABASE_URL="mysql://<user>:<password>@<host>:3306/cf8j9_ranko?serverVersion=10.11.19-MariaDB&charset=utf8mb4"
MAILER_DSN=null://null
# Contact exigé par MusicBrainz (URL ou email)
MUSICBRAINZ_USER_AGENT="Ranko/0.1 ( https://github.com/CallMeTrinity/ranko )"
```

## Worker Messenger (imports MusicBrainz)

Les imports de discographies passent par une file Messenger (transport Doctrine,
table `messenger_messages`). Il faut un worker pour la vider, sinon les artistes
restent en `pending`.

Le mutualisé Infomaniak n'a ni processus permanent ni cron shell : son planificateur
de tâches appelle une URL. Cette URL fait tourner le worker dans la requête, jusqu'à
`CRON_TIME_LIMIT` secondes ou jusqu'à ce que la file soit vide. L'appel suivant
reprend là où le précédent s'est arrêté.

1. Dans `.env.local`, ajouter un secret (sans lui, la route répond 403) :

   ```dotenv
   APP_CRON_SECRET=<openssl rand -hex 32>
   CRON_TIME_LIMIT=20
   ```

2. Dans le Manager Infomaniak, créer une tâche planifiée qui appelle, chaque minute
   (ou à l'intervalle le plus court proposé) :

   ```
   https://<domaine>/cron/messenger?token=<APP_CRON_SECRET>
   ```

Réponse : `{"status":"ok","handled":6,"retried":0,"failed":0,"remaining":0}`.
`remaining` est le nombre de messages encore en file. `busy` signifie qu'un appel
précédent tourne encore : un verrou empêche deux workers en parallèle.

**Durée d'un appel** : le budget n'est vérifié qu'entre deux messages, donc un appel
dure jusqu'à `CRON_TIME_LIMIT` + la durée d'un message (environ 10 s au pire). Garder
ce total sous le `max_execution_time` de PHP et sous le délai d'attente du
planificateur. Avec une limite à 30 s, mettre `CRON_TIME_LIMIT=15`.

Garder l'URL privée : elle contient le secret. Changer `APP_CRON_SECRET` si elle fuite.

**Sans attendre le planificateur** (il ne passe que toutes les 15 min) :

- back-office, liste des artistes : bouton « Traiter la file (n) ». Il lance le même
  worker, avec le même budget ; recliquer tant qu'il reste des messages ;
- en SSH, sans limite de temps : `bin/console messenger:consume async --time-limit=600 -vv`,
  et `bin/console messenger:stats` pour voir ce qui reste.

Le bouton et l'URL partagent un verrou : si l'un tourne, l'autre répond « déjà en cours ».

Le rate limit MusicBrainz (1 requête/s) est partagé entre le worker et le back-office
via le cache et un verrou fichier (`LOCK_DSN=flock`, valeur par défaut). Un 503 de
MusicBrainz fait échouer le message, que Messenger rejoue après 5 s, 15 s puis 45 s.
Après 3 échecs, il part dans le transport `failed` :

```bash
bin/console messenger:failed:show
bin/console messenger:failed:retry
```

Sur un VPS, on peut se passer de l'URL et lancer un vrai worker permanent
(`bin/console messenger:consume async`) sous systemd ou Supervisor.

## Premier administrateur

```bash
bin/console app:user:create --admin
```

La commande demande l'email, le mot de passe (saisie masquée) et le pseudo.

## Images (pochettes)

Les pochettes sont copiées en local dans `var/media/` à l'import des titres, puis
redimensionnées à la demande par LiipImagine dans `public/media/cache/`. Les deux
dossiers survivent aux déploiements (exclus du rsync). Apache sert les variantes avec un
cache navigateur d'un an (`public/.htaccess`, nécessite `mod_headers`).

Pour copier les pochettes importées avant cette mise en place, une fois, sur le serveur :

```bash
php bin/console app:catalog:store-covers
```

La commande ne traite que les pochettes pas encore copiées : la relancer reprend les échecs
(miroirs archive.org parfois indisponibles).
