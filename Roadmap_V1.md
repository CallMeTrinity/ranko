# Roadmap V1 : site de classements

Oct 10, 2026 · @Antonin

## Mode Duel

Le bracket à élimination ne donne pas un classement complet, seulement un vainqueur. Le 2e n'a perdu que contre le 1er, le reste est flou. On garde l'idée du duel, mais pilotée par un tri par fusion.

- **Rigoureux** : un merge sort interactif où chaque comparaison est une question "A ou B ?". Environ n·log2(n) questions : \~25 pour 10 albums, \~280 pour 50 titres.
- **Accessible** : comme trier un jeu de cartes en faisant des petits tas triés puis en les fusionnant, sauf que c'est toi qui dis quelle carte gagne.
- **Bonus** : pour ajouter un élément à un classement existant, une insertion dichotomique suffit, environ log2(n) questions (4 pour 15 titres).
- **À ne pas rater** : persister l'état du duel en BDD pour pouvoir reprendre en cours de route.

## Modèle

Un seul `Ranking` générique : un type d'élément (artiste, album, titre) + un périmètre optionnel (tout, un artiste, un album). Il couvre les classements imbriqués et transverses.

Le "titre préféré d'un album" = le n°1 du classement de cet album. Pas besoin de champ dédié.

## Roadmap V1 (musique uniquement)

### Phase 0 : Socle

- [x] Symfony stable, Docker, PostgreSQL
- [x] CI GitHub Actions, PHPStan, PHPUnit

### Phase 1 : Catalogue

- [ ] Entités `Artist`, `Album`, `Track` + `ExternalReference`
- [ ] `CatalogProviderInterface` + `MusicBrainzProvider`, pochettes via Cover Art Archive
- [ ] Import asynchrone d'une discographie (Messenger), respect du rate limit
- [ ] Back-office (EasyAdmin) : chercher, importer, masquer (lives, compilations)

### Phase 2 : Classements

- [ ] Auth utilisateur
- [ ] `Ranking` + `RankingEntry` (position), visibilité public/privé
- [ ] Drag & drop (Stimulus + SortableJS), navigation artiste > albums > titres

### Phase 3 : Mode Duel

- [ ] Merge sort interactif avec état persisté
- [ ] Insertion dichotomique pour ajouter un élément

### Phase 4 : Social

- [ ] Profil public + lien de partage
- [ ] Voir les classements des autres
- [ ] Comparaison : taux d'accord entre deux classements (corrélation de Kendall sur les éléments communs)

### Phase 5 : Mise en prod

- [ ] Déploiement, README avec schéma d'archi

### Hors V1

Films (TMDB), livres, propositions des utilisateurs, classements agrégés.

## Noms simples

Podium, Palmarès, Versus, Mes Tops, Classé. Vérifier la dispo du domaine avant de s'attacher.

## Récap

Duel par merge sort plutôt que bracket, un `Ranking` générique avec périmètre, V1 = musique seule en 5 phases, social en fin de V1.
