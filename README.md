<img src="https://i.imgur.com/kAvg4w9.png" align="right" />

# Banditore

![CI](https://github.com/j0k3r/banditore/workflows/CI/badge.svg)
[![codecov](https://codecov.io/github/j0k3r/banditore/graph/badge.svg?token=vGOatWHRG8)](https://codecov.io/github/j0k3r/banditore)
![PHPStan level max](https://img.shields.io/badge/PHPStan-level%20max-brightgreen.svg?style=flat)

Banditore retrieves new releases from your GitHub starred repositories and put them in a RSS feed, just for you.

![](https://i.imgur.com/XDCWLJV.png)

## Requirements

 - PHP >= 8.2 (with `pdo_mysql`)
 - MySQL >= 5.7
 - Redis (to cache requests to the GitHub API)
 - [RabbitMQ](https://www.rabbitmq.com/), which is optional (see below)
 - [Supervisor](http://supervisord.org/) (only if you use RabbitMQ)
 - [NVM](https://github.com/nvm-sh/nvm#install--update-script) & [Yarn](https://classic.yarnpkg.com/lang/en/docs/install/) to install assets

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

5. Install assets

    ```bash
    nvm install
    yarn install
    ```

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
 */10  *   *   *   *   php /path/to/banditore/bin/console -e prod banditore:sync:versions --use_queue >> /path/to/banditore/var/logs/command-sync-versions.log 2>&1
 # sync starred repos of each user every 5 minutes
 */5   *   *   *   *   php /path/to/banditore/bin/console -e prod banditore:sync:starred-repos --use_queue >> /path/banditore/to/var/logs/command-sync-repos.log 2>&1
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
