# Déploiement

Tout passe par GitHub Actions (`.github/workflows/ci.yml`), sur le même modèle que kadens. Rien n'est construit sur le serveur.

## Déroulé

1. **test** : lints, PHPStan, PHPUnit sur MariaDB 11.4. Tourne sur chaque PR et chaque push sur `main`.
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

- PHP ≥ 8.4.1 avec `ctype`, `iconv`, `intl`, `pdo_mysql`, et MariaDB 11.4. Composer n'est pas nécessaire, `vendor/` arrive avec la release.
- Document root sur `APP_DIR/public`.
- Un fichier `APP_DIR/.env.local`, jamais écrasé par le rsync :

```dotenv
APP_ENV=prod
APP_DEBUG=0
APP_SECRET=<openssl rand -hex 32>
DATABASE_URL="mysql://<user>:<password>@<host>:3306/<db>?serverVersion=11.4.4-MariaDB&charset=utf8mb4"
MAILER_DSN=null://null
```
