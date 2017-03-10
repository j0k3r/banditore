<img src="https://i.imgur.com/kAvg4w9.png" align="right" />

# Banditore

[![Travis Status](https://travis-ci.org/j0k3r/banditore.svg?branch=master)](https://travis-ci.org/j0k3r/banditore)
[![Coveralls Status](https://coveralls.io/repos/github/j0k3r/banditore/badge.svg?branch=master)](https://coveralls.io/github/j0k3r/banditore?branch=master)
[![Scrutinizer Status](https://scrutinizer-ci.com/g/j0k3r/banditore/badges/quality-score.png?b=master)](https://scrutinizer-ci.com/g/j0k3r/banditore/?branch=master)
[![Say Thanks !](https://img.shields.io/badge/Say%20Thanks-!-1EAEDB.svg)](https://saythanks.io/to/j0k3r)

Banditore retrieves new releases from your Github starred repositories and put them in a RSS feed, just for you.

![](http://i.imgur.com/XDCWLJV.png)

## Requirements

 - PHP >=5.5.9 (with `pdo_mysql`)
 - MySQL >= 5.7
 - Redis (mostly to cache requests to the Github API)
 - [RabbitMQ](https://www.rabbitmq.com/), which is optional (see below)
 - [Supervisor](http://supervisord.org/) (only if you use RabbitMQ)

## Installation

1. Clone the project

    ```bash
    git clone https://github.com/j0k3r/banditore.git
    ```

2. [Register a new OAuth Github application](https://github.com/settings/applications/new) and get the _Client ID_ & _Client Secret_ for the next step (for the _Authorization callback URL_ put `http://127.0.0.1:8000/callback`)

3. Install dependencies using [Composer](https://getcomposer.org/download/) and define your parameter during the installation

    ```bash
    SYMFONY_ENV=prod composer install -o --no-dev
    ```

    If you want to use:
     - **Sentry** to retrieve all errors, [register here](https://sentry.io/signup/) and get your dsn (in Project Settings > DSN).
     - **New Relic** to track performance, [register here](https://newrelic.com/signup) and get your New Relic API Key (in Account Settings > Integrations > API keys)

5. Setup the database

    ```bash
    php bin/console doctrine:database:create -e=prod
    php bin/console doctrine:schema:create -e=prod
    ```

4. You can now launch the website:

    ```bash
    php bin/console server:run -e=prod
    ```

    And access it at this address: `http://127.0.0.1:8000`

## Running the instance

Once the website is up, you know have to setup few things to retrieve new releases.
You have two choices:
- using crontab command (very simple and ok if you are alone)
- using RabbitMQ (might be better if you plan to have more than few persons but it's more complex) :call_me_hand:

### Without RabbitMQ

You just need to define these 2 cronjobs (replace all `/path/to/banditore` with real value):

```bash
# retrieve new release of each repo every 10 minutes
*/10  *   *   *   *   php /path/to/banditore/bin/console -e=prod banditore:sync:versions >> /path/to/banditore/var/logs/command-sync-versions.log 2>&1
# sync starred repos of each user every 5 minutes
*/5   *   *   *   *   php /path/to/banditore/bin/console -e=prod banditore:sync:starred-repos >> /path/banditore/to/var/logs/command-sync-repos.log 2>&1
```

### With RabbitMQ

1. You'll need to declare exchanges and queues. Replace `guest` by the user of your RabbitMQ instance (`guest` is the default one):

 ```bash
 php bin/rabbit vhost:mapping:create -p guest app/config/rabbit_vhost.yml
 ```

2. You now have two queues and two exchanges defined:
 - `banditore.sync_starred_repos`: will receive messages to sync starred repos of all users
 - `banditore.sync_versions`: will receive message to retrieve new release for repos

3. Enable these 2 cronjobs which will periodically push messages in queues (replace all `/path/to/banditore` with real value):

 ```bash
 # retrieve new release of each repo every 10 minutes
 */10  *   *   *   *   php /path/to/banditore/bin/console -e=prod banditore:sync:versions --use-queue >> /path/to/banditore/var/logs/command-sync-versions.log 2>&1
 # sync starred repos of each user every 5 minutes
 */5   *   *   *   *   php /path/to/banditore/bin/console -e=prod banditore:sync:starred-repos --use-queue >> /path/banditore/to/var/logs/command-sync-repos.log 2>&1
```

4. Setup Supervisor using the [sample file](data/supervisor.conf) from the repo. You can copy/paste it into `/etc/supervisor/conf.d/` and adjust path. The default file will launch:
  - 2 workers for sync starred repos
  - 4 workers to fetch new releases

 Once you've put the file in the supervisor conf repo, run `supervisorctl update && supervisorctl start all` (`update` will read your conf, `start all` will start all workers)


## Running the test suite

If you plan to contribute (you're awesome, I know that :v:), you'll need to install the project in a different way (for example, to retrieve dev packages):

```bash
git clone https://github.com/j0k3r/banditore.git
composer install -o
php bin/console doctrine:database:create -e=test
php bin/console doctrine:schema:create -e=test
php bin/console doctrine:fixtures:load --env=test -n
php bin/simple-phpunit -v
```

By default the `test` connexion login is `root` without password. You can change it in [app/config/config_test.yml](app/config/config_test.yml).

## How it works

Ok, if you goes that deeper in the readme, it means you're a bit more than interested, I like that.

_Coming soon…_
