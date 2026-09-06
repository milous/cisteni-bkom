.PHONY: build install test sync shell

build:
	docker compose build

install:
	docker compose run --rm php composer install

test:
	docker compose run --rm php ./vendor/bin/phpunit tests/

sync:
	docker compose run --rm php php sync.php

shell:
	docker compose run --rm php sh
