<img src="https://i.imgur.com/kAvg4w9.png" align="right" />

# Banditore

![CI](https://github.com/j0k3r/banditore/workflows/CI/badge.svg)
[![codecov](https://codecov.io/github/j0k3r/banditore/graph/badge.svg?token=vGOatWHRG8)](https://codecov.io/github/j0k3r/banditore)
![PHPStan level max](https://img.shields.io/badge/PHPStan-level%20max-brightgreen.svg?style=flat)

Banditore retrieves new releases from your GitHub starred repositories and put them in a RSS feed, just for you.

![](https://i.imgur.com/XDCWLJV.png)

## Requirements

 - PHP >= 8.4 (with `pdo_mysql`)
 - MySQL >= 5.7
 - Redis (to cache requests to the GitHub API)
 - [RabbitMQ](https://www.rabbitmq.com/), which is optional (see below)
 - [Supervisor](http://supervisord.org/) (only if you use RabbitMQ)

## Docker

The Compose setup runs the application (PHP 8.4 FPM and nginx on Alpine), MariaDB 12.3 LTS and Redis 8.10 Alpine. Supervisor manages nginx and PHP-FPM inside the application container; build tools and Composer stay in a separate build stage. Both MySQL and PostgreSQL PDO drivers are included. RabbitMQ is not needed: use the scheduled commands below to synchronize repositories and releases.

1. Copy the configuration:

    ```bash
    cp .env.docker.example .env.docker
    ```

    Edit `.env.docker`: set your GitHub OAuth application's credentials and replace all three secret/password placeholders (for example, use `openssl rand -hex 32` for each). Use URL-safe database passwords, or URL-encode them in `DATABASE_URL` in the Compose file. Register `http://localhost:8000/callback` as the OAuth callback URL. If you change the address or port, update `APP_PORT`, `PROJECT_HOST`, `PROJECT_SCHEME` and the OAuth callback URL together.

2. Build and start the services:

    ```bash
    docker compose --env-file .env.docker up -d --build
    ```

3. Initialize a **new, empty database** once, using the current schema, then mark the historical migrations as applied:

    ```bash
    docker compose --env-file .env.docker exec --user www-data app php bin/console doctrine:schema:create
    docker compose --env-file .env.docker exec --user www-data app php bin/console doctrine:migrations:sync-metadata-storage
    docker compose --env-file .env.docker exec --user www-data app php bin/console doctrine:migrations:version --add --all --no-interaction
    ```

    For an existing installation, import its database instead and apply only its pending migrations. Never mark pending migrations as applied on an existing database.

4. Open <http://localhost:8000> and log in. Add these jobs to the **host's crontab**, replacing `/path/to/banditore` with the checkout path:

    ```cron
    */10 * * * * cd /path/to/banditore && docker compose --env-file .env.docker exec -T --user www-data app php bin/console banditore:sync:versions
    */5  * * * * cd /path/to/banditore && docker compose --env-file .env.docker exec -T --user www-data app php bin/console banditore:sync:starred-repos
    ```

    The application container only serves HTTP; it does not run these jobs automatically. Do not pass `--use-queue` with this setup.

MariaDB data and application sessions are stored in named volumes. `docker compose --env-file .env.docker down` preserves them; **adding `--volumes` deletes them**. The app listens on localhost only; MariaDB and Redis are not exposed to the host. For a public instance, use an HTTPS reverse proxy and adapt the port binding and URL settings.

Back up the database before changing MariaDB major versions and follow MariaDB's supported upgrade procedure; changing the image tag is not a substitute for a database upgrade.

After updating the code, rebuild with `up -d --build` and run pending migrations:

```bash
docker compose --env-file .env.docker exec --user www-data app php bin/console doctrine:migrations:migrate --no-interaction
```

The `Docker` CI workflow builds and starts this Compose stack on pull requests and pushes to `master`. It initializes a fresh database, validates the schema and container, checks Redis connectivity from the app, and smoke-tests HTTP routes. It uses dummy OAuth credentials and does not call GitHub.

## Installation

1. Clone the project

    ```bash
    git clone https://github.com/j0k3r/banditore.git
    ```

2. [Register a new OAuth GitHub application](https://github.com/settings/applications/new) and get the _Client ID_ & _Client Secret_ for the next step (for the _Authorization callback URL_ put `http://127.0.0.1:8000/callback`)

3. Install dependencies using [Composer](https://getcomposer.org/download/) and define your parameter during the installation

    ```bash
    APP_ENV=prod composer install -o --no-dev
    ```

    If you want to use:
     - **Sentry** to retrieve all errors, [register here](https://sentry.io/signup/) and get your dsn (in Project Settings > DSN).

4. Setup the database

    ```bash
    php bin/console doctrine:database:create -e prod
    php bin/console doctrine:schema:create -e prod
    ```

5. The application serves its frontend assets directly from `public/`, so no Node/Yarn install step is required (it's locked on `font-awesome@4.7.0` & `purecss@3.0.0`).

6. You can now launch the website:

    ```bash
    php -S localhost:8000 -t public/
    ```

    And access it at this address: `http://127.0.0.1:8000`

## Running the instance

Once the website is up, you now have to setup few things to retrieve new releases.
You have two choices:
- using crontab command (very simple and ok if you are alone)
- using RabbitMQ (might be better if you plan to have more than few persons but it's more complex) :call_me_hand:

### Without RabbitMQ

You just need to define these 2 cronjobs (replace all `/path/to/banditore` with real value):

```bash
# retrieve new release of each repo every 10 minutes
*/10  *   *   *   *   php /path/to/banditore/bin/console -e prod banditore:sync:versions >> /path/to/banditore/var/logs/command-sync-versions.log 2>&1
# sync starred repos of each user every 5 minutes
*/5   *   *   *   *   php /path/to/banditore/bin/console -e prod banditore:sync:starred-repos >> /path/banditore/to/var/logs/command-sync-repos.log 2>&1
```

### With RabbitMQ

1. You'll need to declare exchanges and queues. Replace `guest` by the user of your RabbitMQ instance (`guest` is the default one):

 ```bash
 php bin/console messenger:setup-transports -vvv sync_starred_repos
 php bin/console messenger:setup-transports -vvv sync_versions
 ```

2. You now have two queues and two exchanges defined:
 - `banditore.sync_starred_repos`: will receive messages to sync starred repos of all users
 - `banditore.sync_versions`: will receive message to retrieve new release for repos

3. Enable these 2 cronjobs which will periodically push messages in queues (replace all `/path/to/banditore` with real value):

 ```bash
 # retrieve new release of each repo every 10 minutes
 */10  *   *   *   *   php /path/to/banditore/bin/console -e prod banditore:sync:versions --use-queue >> /path/to/banditore/var/logs/command-sync-versions.log 2>&1
 # sync starred repos of each user every 5 minutes
 */5   *   *   *   *   php /path/to/banditore/bin/console -e prod banditore:sync:starred-repos --use-queue >> /path/banditore/to/var/logs/command-sync-repos.log 2>&1
```

4. Setup Supervisor using the [sample file](data/supervisor.conf) from the repo. You can copy/paste it into `/etc/supervisor/conf.d/` and adjust path. The default file will launch:
  - 2 workers for sync starred repos
  - 4 workers to fetch new releases

 Once you've put the file in the supervisor conf repo, run `supervisorctl update && supervisorctl start all` (`update` will read your conf, `start all` will start all workers)

### Monitoring

There is a status page available at `/status`, it returns a json with some information about the freshness of fetched versions:

```json
{
    "latest": {
        "date": "2019-09-17 19:50:50.000000",
        "timezone_type": 3,
        "timezone": "Europe\/Berlin"
    },
    "diff": 1736,
    "is_fresh": true
}
```

- `latest`: the latest created version as a DateTime
- `diff`: the difference between now and the latest created version (in seconds)
- `is_fresh`: indicate if everything is fine by comparing the `diff` above with the `status_minute_interval_before_alert` parameter

For example, I've setup a check on [updown.io](https://updown.io/r/P7qer) to check that status page and if the page contains `"is_fresh":true`. So I receive an alert when `is_fresh` is false: which means there is a potential issue on the server.

### Measuring release-sync bottlenecks

Timings are opt-in (`VERSIONS_SYNC_METRICS=0` by default). After deploying and
refreshing the application cache, restart the existing workers so they also use the
new code. Then run a bounded diagnostic consumer as the usual worker user,
alongside the existing workers:

```bash
VERSIONS_SYNC_METRICS=1 php bin/console messenger:consume sync_versions -e prod --no-debug --limit=500 --time-limit=600
```

This **processes real queued messages**; it is not a dry run. The limits are checked
between messages, so a single large import can exceed ten minutes. No new sync wave
is needed. Do not enable the flag globally for a first measurement.

Each handled message writes one JSON line to
`var/log/versions-sync-metrics-YYYY-MM-DD.log`, independently of the production
error-triggered logger. Three daily files are retained. No tokens or release bodies
are logged. Context fields include:

- `repo_id`, `repo`, `status`: `completed`, `partial`, `fetch_failed`,
  `rate_limit_unavailable`, `no_client`, `repo_not_found`, or `failed` (exception).
- `tags`: number of refs returned; `existing_version_lookups`: number of existence
  checks attempted, including checks for already-known tags.
- `new_versions`: versions included in successful flushes during this message.
- `timings_ms`: cumulative wall-clock milliseconds for `repo_lookup`, `rate_limit`
  (the initial check only; the final quota request has been removed), `tags` (probe and full list), `existing_versions`, `version_details`
  (release lookup and tag/commit/blob fallback), `markdown` (including retry sleeps),
  `flush`, `hub`, and the handler `total`.

Timers include cache access, response decoding and failed calls, not just network or
SQL execution. The total also includes untimed work such as entity hydration and
pending-insertion checks. The first message may also include lazy dependency
initialization/client discovery in the total; distinguish this from steady-state
costs. Worker startup, message reception/acknowledgement, and writing the metrics log
are excluded. Exceptions still propagate; retry and acknowledgement logic is unchanged.

For example, aggregate one capture with `jq` (use a fresh file or select only the
capture's timestamps when comparing runs):

```bash
jq -s '
  map(select(.message == "Versions sync metrics") | .context) as $rows
  | ($rows | map(.timings_ms.total) | add) as $total
  | {
      messages: ($rows | length),
      statuses: ($rows | group_by(.status) | map({status: .[0].status, count: length})),
      tags: ($rows | map(.tags) | add),
      lookups: ($rows | map(.existing_version_lookups) | add),
      new_versions: ($rows | map(.new_versions) | add),
      phases: ([$rows[] | .timings_ms | to_entries[]]
        | group_by(.key)
        | map({phase: .[0].key, ms: (map(.value) | add)})
        | map(. + {percent_of_total: (if $total > 0 then 100 * .ms / $total else 0 end)})
        | sort_by(-.ms))
    }
' var/log/versions-sync-metrics-YYYY-MM-DD.log
```

Compare cumulative phase times, and separate messages with/without new versions.
Repeat across several captures: a FIFO slice of 500 repositories may not represent
all repositories. `completed` does not imply a new version was found.

During the same window, record RabbitMQ's ready/unacknowledged counts, consumer count
and acknowledgement rate; worker CPU/restarts; MySQL load, slow queries/lock waits;
and GitHub 403/429 responses. Queue decline is a **net** rate if publishers are active.
Summed handler time across concurrent workers is not elapsed capture time.

To quantify repositories that could be skipped, run this read-only SQL query against
the application database (it scans the eligible repositories; run off-peak if needed):

```sql
SELECT COUNT(*) AS eligible_repos,
       COALESCE(SUM(NOT EXISTS (
           SELECT 1 FROM star s WHERE s.repo_id = r.id
       )), 0) AS repos_without_subscribers
FROM repo r
WHERE r.removed_at IS NULL;
```

On this integration branch, periodic scheduling excludes repositories without a
subscriber and persists the removal flag immediately after a 404/451. Explicit
repository selection is unchanged, and 403 access restrictions do not mark a
repository as removed. The subscriber filter affects new sync waves, not messages
already queued; do not purge the existing queue to apply it.

## Running the test suite

If you plan to contribute (you're awesome, I know that :v:), you'll need to install the project in a different way (for example, to retrieve dev packages):

```bash
git clone https://github.com/j0k3r/banditore.git
composer install -o
php bin/console doctrine:database:create -e=test
php bin/console doctrine:schema:create -e=test
php bin/console doctrine:fixtures:load --env=test -n
php bin/phpunit
```

Test environment defaults, including the database connection, are defined in `.env.test`.

## How it works

Ok, if you goes that deeper in the readme, it means you're a bit more than interested, I like that.

### Retrieving new release / tag

This is the complex part of the app. Here is a simplified solution to achieve it.

#### New release

It's not as easy as using the `/repos/:owner/:repo/releases` API endpoint to retrieve latest release for a given repo. Because not all repo owner use that feature (which is a shame in my case).

All information for a release are available on that endpoint:
- name of the tag (ie: v1.0.0)
- name of the release (ie: yay first release)
- published date
- description of the release

> Check a new release of that repo as example: https://api.github.com/repos/j0k3r/banditore/releases/5770680

#### New tag

Some owners also use tag which is a bit more complex to retrieve all information because a tag only contains information about the SHA-1 of the commit which was used to make the tag.
We only have these information:
- name of the tag (ie: v1.4.2)
- name of the release will be the name of the tag, in that case

> Check tag list of swarrot/SwarrotBundle as example: https://api.github.com/repos/swarrot/SwarrotBundle/tags

After retrieving the tag, we need to retrieve the commit to get these information:
- date of the commit
- message of the commit

> Check a commit from the previous tag list as example: https://api.github.com/repos/swarrot/SwarrotBundle/commits/84c7c57622e4666ae5706f33cd71842639b78755

### GitHub Client Discovery

This is the most important piece of the app. One thing that I ran though is hitting the rate limit on GitHub.
The rate limit for a given authenticated client is 5.000 calls per hour. This limit is **never** reached when looking for new release (thanks to the [conditional requests](https://developer.github.com/v3/#conditional-requests) of the GitHub API) on a daily basis.

But when new user sign in, we need to sync all its starred repositories and also all their releases / tags. And here come the gourmand part:
- one call for the list of release
- one call to retrieve information of each tag (if the repo doesn't have release)
- one call for each release to convert markdown text to html

Let's say the repo:
- has 50 tags: 1 (get tag list) + 50 (get commit information) + 50 (convert markdown) = 101 calls.
- has 50 releases: 1 (get tag list) + 50 (get each release) + 50 (convert markdown) = 101 calls.

And keep in mind that some repos got also 1.000+ tags (!!).

To avoid hitting the limit in such case and wait 1 hour to be able to make requests again I created the [GitHub Client Discovery class](src/AppBundle/Github/ClientDiscovery.php).
It aims to find the best client with enough rate limit remain (defined as 50).
- it first checks using the GitHub OAuth app
- then it checks using all user GitHub token

Which means, if you have 5 users on the app, you'll be able to make (1 + 5) x 5.000 = 30.000 calls per hour
