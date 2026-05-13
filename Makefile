.PHONY: up down restart bash migrate cc logs db-shell cert reset

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
	docker compose exec db mysql -u ipam -pipam_password ipam

reset:
	docker compose down -v
	docker compose up -d --build
	@echo "Waiting for app container…"
	@until docker compose exec -T app php -r 'echo ok;' 2>/dev/null | grep -q ok; do sleep 2; done
	docker compose exec -T app php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
	docker compose exec -T app php bin/console doctrine:fixtures:load --no-interaction

cert:
	mkdir -p docker/ssl
	openssl req -x509 -nodes -days 3650 -newkey rsa:2048 \
	  -keyout docker/ssl/key.pem \
	  -out  docker/ssl/cert.pem \
	  -subj "/CN=ipam.local" \
	  -addext "subjectAltName=DNS:ipam.local,DNS:localhost,IP:127.0.0.1"
