.PHONY: build local prepare test

build: prepare test

local:
	php bin/console doctrine:database:create --if-not-exists --env=test
	php bin/console doctrine:schema:create --env=test
	php bin/console doctrine:fixtures:load --env=test -n
	php bin/console cache:clear --env=test

prepare:
	composer install --no-interaction -o --prefer-dist
	php bin/console doctrine:database:create --if-not-exists --env=test
	php bin/console doctrine:schema:create --env=test
	php bin/console doctrine:fixtures:load --env=test -n
	php bin/console cache:clear --env=test

test:
	php bin/simple-phpunit --coverage-html build/coverage

reset:
	php bin/console doctrine:schema:drop --force --env=test
	php bin/console doctrine:schema:create --env=test
	php bin/console doctrine:fixtures:load --env=test -n
