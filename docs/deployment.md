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

- PHP ≥ 8.4.1 avec `ctype`, `iconv`, `intl`, `pdo_mysql`, et MariaDB 10.11. Composer n'est pas nécessaire, `vendor/` arrive avec la release.
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
table `messenger_messages`). Sur un mutualisé, pas de processus permanent : un cron
relance le worker chaque minute, et `--time-limit` le fait s'arrêter avant le suivant.

```cron
* * * * * cd APP_DIR && REMOTE_PHP bin/console messenger:consume async --time-limit=55 --memory-limit=128M --quiet
```

Remplacer `APP_DIR` et `REMOTE_PHP` par les mêmes valeurs que dans GitHub. Le rate
limit MusicBrainz (1 requête/s) est partagé entre le worker et le back-office via le
cache et un verrou fichier (`LOCK_DSN=flock`, valeur par défaut) : rien à configurer.

Les messages en échec après 3 tentatives atterrissent dans le transport `failed` :

```bash
bin/console messenger:failed:show
bin/console messenger:failed:retry
```

## Premier administrateur

```bash
bin/console app:user:create --admin
```

La commande demande l'email, le mot de passe (saisie masquée) et le pseudo.
