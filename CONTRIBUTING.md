# Contributing

Contributions are welcome, of course.

## Setting up an Environment

You locally need:

 - PHP >= 8.2 (with `pdo_mysql`) with [Composer](https://getcomposer.org/) installed
 - Node.js 22 (use `nvm install`) with Yarn installed
 - Docker

Install deps:

```
yarn
composer i
```

Then you can use Docker (used for test or dev):

```
docker run -d --name banditore-mysql -e MYSQL_ALLOW_EMPTY_PASSWORD=yes -p 3306:3306 mysql:latest
docker run -d --name banditore-redis -p 6379:6379 redis:latest
docker run -d --name banditore-rabbit -e RABBITMQ_DEFAULT_USER=user -e RABBITMQ_DEFAULT_PASS=password -p 5672:5672 rabbitmq:4-management
```

## Running Tests

You can setup the database and the project using:

```
make prepare
```

Once it's ok, launch tests:

```
php bin/phpunit -v
```

## Linting

Linter is used only on PHP files:

```
php bin/php-cs-fixer fix
php bin/phpstan analyse
```
