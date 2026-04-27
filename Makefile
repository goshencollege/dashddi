.PHONY: up down restart bash migrate cc logs db-shell

up:
	docker compose up -d

down:
	docker compose down

restart:
	docker compose restart

bash:
	docker compose exec app bash

migrate:
	docker compose exec app php bin/console doctrine:migrations:migrate --no-interaction

cc:
	docker compose exec app php bin/console cache:clear

logs:
	docker compose logs -f

db-shell:
	docker compose exec db psql -U ipam -d ipam
