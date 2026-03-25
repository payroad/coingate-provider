.PHONY: build test test-integration filter shell

build:
	docker compose build

test: build
	docker compose run --rm php sh -c "composer install --no-interaction --no-progress -q && vendor/bin/phpunit --testsuite unit"

test-integration: build
	docker compose run --rm \
		-e COINGATE_API_KEY=$(COINGATE_API_KEY) \
		-e COINGATE_BASE_URL=$(COINGATE_BASE_URL) \
		php sh -c "composer install --no-interaction --no-progress -q && vendor/bin/phpunit --testsuite integration --display-skipped"

filter: build
	docker compose run --rm php sh -c "composer install --no-interaction --no-progress -q && vendor/bin/phpunit --filter=$(FILTER)"

shell: build
	docker compose run --rm php sh
