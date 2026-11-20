# Mesurer la synchronisation des stars

Branche dédiée : `metrics-starred-repos-sync`, basée sur `master`.
Branche de déploiement commune : `integration/release-sync-metrics`, qui conserve les optimisations et les métriques des versions.

Cette instrumentation ne change ni les appels GitHub, ni la pagination, ni les écritures, ni les règles d'acquittement/retry. Aucune migration. Elle est désactivée par défaut (`STARRED_REPOS_SYNC_METRICS=0`) et indépendante de `VERSIONS_SYNC_METRICS`.

## Capture en production

Après le déploiement habituel de la branche commune, rafraîchir le cache Symfony et redémarrer les workers pour charger le nouveau code. Sous l'utilisateur habituel des workers, lancer un worker temporaire :

```bash
STARRED_REPOS_SYNC_METRICS=1 php bin/console messenger:consume \
  sync_starred_repos -e prod --no-debug \
  --limit=100 --time-limit=600
```

- Ce worker **consomme et modifie réellement les données** ; ce n'est pas un dry run.
- Utiliser un backlog existant : inutile de republier une vague.
- Ne pas activer le flag globalement pour la première capture. Un worker supplémentaire augmente temporairement la concurrence : noter le nombre total de workers actifs.
- Les limites sont vérifiées entre les messages ; un gros utilisateur peut dépasser les dix minutes à lui seul.
- Noter le SHA déployé, les horaires, les erreurs et les redémarrages. Observer RabbitMQ (ready, unacknowledged, consommateurs, acquittements).

Une ligne JSON par invocation du handler dans `var/log/starred-repos-sync-metrics-YYYY-MM-DD.log`. Trois fichiers journaliers sont conservés. Le canal dédié n'attend pas une erreur pour écrire et est exclu des logs généraux/console. Une erreur d'écriture de ces diagnostics ne doit pas faire échouer la synchro (`whatfailuregroup`). Si le fichier manque, vérifier le flag et les permissions.

## Lecture des métriques

Le message JSON est `Starred repos sync metrics`, les mesures sont dans `context` :

- `user_id`, `user` : identifiant et pseudo GitHub, sans jeton ni contenu des dépôts ; réserver néanmoins ces logs aux opérateurs.
- `status` : `completed`, `fetch_failed` (première page indisponible), `partial` (page suivante indisponible et exception absorbée par le comportement existant), `no_client`, `user_not_found`, `rate_limit_unavailable`, `failed` (exception propagée).
- `page_requests` : tentatives de lecture de pages, y compris les erreurs et la page vide finale. Ce sont des appels au client, **pas un compteur réseau** : le cache peut répondre localement, et le transport peut effectuer plusieurs tentatives.
- `stars_seen` : nombre cumulé d'entrées renvoyées par les pages réussies. Ce n'est pas le total réel d'un compte si le parcours échoue, ni un compteur dédupliqué.
- `existing_stars` : nombre d'associations locales au premier chargement ; zéro aussi si cette étape n'a pas été atteinte.
- `repo_lookups` : tentatives de `RepoRepository::find()`, pas nécessairement des SELECT (identity map Doctrine).
- `rate_limit_checks` : consultations explicites du quota dans le handler, pas nécessairement des requêtes réseau. N'inclut pas la découverte du client.
- `flushes` : flushs réussis ; `added_stars` : associations nouvelles incluses dans ces flushs, pas celles uniquement mises en attente avant une erreur.
- `removed_stars` : associations identifiées pour suppression après retour réussi de la méthode de suppression ; pas un comptage SQL des lignes affectées en présence de concurrence.

`completed` ne signifie pas qu'une nouvelle star a été trouvée. Les états de diagnostic ne changent pas la valeur de retour historique du handler : par exemple une erreur de lecture peut rester acquittée. Les changements d'état en mémoire ne sont pas des preuves d'écriture en base.

### Durées cumulées (`timings_ms`)

| Phase | Contenu |
| --- | --- |
| `user_lookup` | Recherche de l'utilisateur |
| `rate_limit` | Toutes les consultations explicites du quota, y compris celles destinées aux logs |
| `github_pages` | Lecture des pages de stars, cache et décodage inclus, y compris les tentatives échouées |
| `existing_stars` | Chargement initial des identifiants d'associations locales |
| `repo_lookup` | Recherches individuelles des dépôts |
| `repo_processing` | Comparaisons, hydratation et mise en attente des entités, hors recherche et flush |
| `flush` | Flushs Doctrine, y compris ceux qui échouent |
| `cleanup` | Relecture des stars locales, différence et suppression des anciennes associations |
| `total` | Temps du handler avant écriture du récapitulatif |

Ces durées ne sont ni du SQL pur ni du réseau pur. Le total inclut aussi du travail non ventilé (notifications Redis, logs, initialisation éventuelle). Les phases sont disjointes ; leur somme peut légèrement différer à cause des arrondis. La réception/acquittement Messenger, les pauses entre messages et les redémarrages ne sont pas mesurés. Les compteurs repartent de zéro à chaque invocation, y compris lors d'un retry.

## Agrégation d'une capture

Le journal s'enrichit au fil des exécutions : choisir un fichier ne contenant que la fenêtre voulue avant d'agréger. Ne pas additionner `total` aux phases pour calculer leur part.

```bash
jq -s '
  map(select(.message == "Starred repos sync metrics") | .context) as $rows
  | ($rows | map(.timings_ms.total) | add // 0) as $total
  | {
      messages: ($rows | length),
      statuses: ($rows | group_by(.status) | map({status: .[0].status, count: length})),
      page_requests: ($rows | map(.page_requests) | add // 0),
      stars_seen: ($rows | map(.stars_seen) | add // 0),
      rate_limit_checks: ($rows | map(.rate_limit_checks) | add // 0),
      repo_lookups: ($rows | map(.repo_lookups) | add // 0),
      phases: ([$rows[] | .timings_ms | to_entries[] | select(.key != "total")]
        | group_by(.key)
        | map({phase: .[0].key, ms: (map(.value) | add)})
        | map(. + {percent_of_total: (if $total > 0 then 100 * .ms / $total else 0 end)})
        | sort_by(-.ms)),
      slowest: ($rows | sort_by(-.timings_ms.total) | .[:10]
        | map({user_id, status, stars_seen, page_requests, total_ms: .timings_ms.total}))
    }
' var/log/starred-repos-sync-metrics-YYYY-MM-DD.log
```

Comparer ensuite les parts `rate_limit`, `github_pages`, `repo_lookup`, `repo_processing` et `flush` avant de choisir une optimisation. La somme des durées de plusieurs workers n'est pas le temps écoulé de la capture, et la baisse du backlog est un débit net si des producteurs continuent à publier.
