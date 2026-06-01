.PHONY: up down restart bash migrate cc logs db-shell cert reset test-setup test upgrade
# .env.test.local is a file target — Make skips the recipe if the file already exists.
# Delete .env.test.local to force regeneration (e.g. after running setup.sh again).

up:
	docker compose -f docker-compose.dev.yml up -d

down:
	docker compose -f docker-compose.dev.yml down

restart:
	docker compose -f docker-compose.dev.yml restart

bash:
	docker compose -f docker-compose.dev.yml exec app bash

migrate:
	docker compose -f docker-compose.dev.yml exec app php bin/console doctrine:migrations:migrate --no-interaction

cc:
	docker compose -f docker-compose.dev.yml exec app php bin/console cache:clear

logs:
	docker compose -f docker-compose.dev.yml logs -f

db-shell:
	docker compose -f docker-compose.dev.yml exec db sh -c 'mysql -u$$MYSQL_USER -p$$MYSQL_PASSWORD $$MYSQL_DATABASE'

upgrade:
	@bash upgrade.sh

reset:
	docker compose -f docker-compose.dev.yml down -v
	docker run --rm -v dashddi-dev-dev_ssl_certs:/ssl -v $(CURDIR)/docker/ssl:/src:ro alpine sh -c 'cp /src/cert.pem /src/key.pem /ssl/ && chmod 644 /ssl/*.pem'
	docker compose -f docker-compose.dev.yml up -d --build
	@echo "Waiting for app container…"
	@until docker compose -f docker-compose.dev.yml exec -T app php -r 'echo "ok";' 2>/dev/null | grep -q ok; do sleep 2; done
	docker compose -f docker-compose.dev.yml exec -T app php bin/console doctrine:migrations:migrate --no-interaction --allow-no-migration
	docker compose -f docker-compose.dev.yml exec -T app php bin/console doctrine:fixtures:load --no-interaction --purge-exclusions=scheduled_task

.env.test.local:
	$(eval PASS := $(shell grep 'MYSQL_PASSWORD:' docker-compose.dev.yml | head -1 | sed 's/.*MYSQL_PASSWORD: //;s/[[:space:]].*//'))
	$(eval KEY  := $(shell grep 'APP_ENCRYPTION_KEY:' docker-compose.dev.yml | head -1 | sed 's/.*APP_ENCRYPTION_KEY: //;s/[[:space:]].*//;s/"//g'))
	@sed -e 's|{MYSQL_PASSWORD}|$(PASS)|g' -e 's|{APP_ENCRYPTION_KEY}|$(KEY)|g' .env.test.local.dist > .env.test.local
	@echo "Created .env.test.local"

test-setup: .env.test.local
	$(eval ROOT_PASS := $(shell grep 'MYSQL_ROOT_PASSWORD:' docker-compose.dev.yml | head -1 | sed 's/.*MYSQL_ROOT_PASSWORD: //;s/[[:space:]].*//'))
	docker compose -f docker-compose.dev.yml exec -T db mysql -u root -p$(ROOT_PASS) -e "CREATE DATABASE IF NOT EXISTS \`dashddi_test\`; GRANT ALL PRIVILEGES ON \`dashddi_test\`.* TO 'dash'@'%'; FLUSH PRIVILEGES;"
	docker compose -f docker-compose.dev.yml exec -T app php bin/console doctrine:migrations:migrate --env=test --no-interaction --allow-no-migration
	docker compose -f docker-compose.dev.yml exec -T app php bin/console doctrine:fixtures:load --env=test --group=test --no-interaction

test: .env.test.local
	docker compose -f docker-compose.dev.yml exec -T app php vendor/bin/phpunit

cert:
	mkdir -p docker/ssl
	openssl req -x509 -nodes -days 3650 -newkey rsa:2048 \
	  -keyout docker/ssl/key.pem \
	  -out  docker/ssl/cert.pem \
	  -subj "/CN=ipam.local" \
	  -addext "subjectAltName=DNS:ipam.local,DNS:localhost,IP:127.0.0.1"
