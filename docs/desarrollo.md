# Desarrollo

Todos los comandos se corren desde la raíz del repo, dentro de WSL. Ver [entorno.md](entorno.md).

## Primera vez

```bash
cp .env.example .env            # ajustar UID y GID con: id -u && id -g
cp backend/.env.example backend/.env
docker compose build php
docker compose run --rm -u www-data php composer install
docker compose run --rm -u www-data php php artisan key:generate
docker compose run --rm -u www-data php php artisan migrate
npmd npm ci
docker compose up -d
```

SPA y API en http://localhost:8080 (mismo origen). Health de Laravel en `/up`.

## Día a día

```bash
docker compose up -d                 # levantar todo
docker compose ps                    # estado de los servicios
docker compose logs -f php worker    # logs del backend
docker compose down                  # apagar (conserva la base)
```

## Backend

```bash
docker compose run --rm -u www-data php vendor/bin/pest                         # todos los tests
docker compose run --rm -u www-data php vendor/bin/pest --testsuite=Integration
docker compose run --rm -u www-data php php artisan migrate
docker compose run --rm -u www-data php composer require <paquete>
```

Comprobar el worker:

```bash
docker compose exec -u www-data php php artisan tinker --execute="Illuminate\Support\Facades\Artisan::queue('about');"
docker compose logs worker | tail -2
```

## Frontend

```bash
npmd npm run lint
npmd npm run typecheck
npmd npm test
npmd npm install -D <paquete>
```

Nunca `npm` suelto: en WSL resuelve al npm de Windows.

## Base de datos

- `pyme`: desarrollo. `pyme_test`: tests, forzada en phpunit.xml.
- `db/init/` corre solo al crear el volumen. Para reaplicarlo (borra los datos): `docker compose down -v`.
- Consola: `docker compose exec postgres psql -U postgres -d pyme`.
- Desde un cliente en Windows: `127.0.0.1:5433`, usuario `pyme_owner`.

## Commits

Conventional Commits: `tipo(alcance): descripción`. Ver [CLAUDE.md](../CLAUDE.md).
El commit que cierra un paso actualiza [estado.md](estado.md).
