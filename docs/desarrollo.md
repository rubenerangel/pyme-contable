# Desarrollo

Todos los comandos se corren desde la raíz del repo, dentro de WSL. Ver [entorno.md](entorno.md).

## Primera vez

```bash
cp .env.example .env            # ajustar UID y GID con: id -u && id -g
cp backend/.env.example backend/.env
docker compose build php
docker compose run --rm -u www-data php composer install
docker compose run --rm -u www-data php php artisan key:generate
docker compose run --rm -u www-data php php artisan migrate --database=pgsql_owner
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
docker compose run --rm -u www-data php php artisan migrate --database=pgsql_owner
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

## Hook de agentes

```bash
python3 -m unittest discover -s .claude/hooks -v
```

Corre con el python3 de WSL (ver [entorno.md](entorno.md)), no en un contenedor. Para ver una regla en rojo, quítala de role_guard.py y vuelve a correr.

## Gate de tests

```bash
python3 scripts/gate.py red                          # fin del paso 3: tests nuevos en rojo
python3 scripts/gate.py red --expect-pass '<id>'     # un control que debe pasar desde el inicio
python3 scripts/gate.py red --allow-removed '<id>'   # un test que el paso borra a propósito
python3 scripts/gate.py red --replace                # repetir red en una ronda de correcciones
python3 scripts/gate.py lock                         # paso solo de tests, todo en verde
python3 scripts/gate.py green                        # fin del paso 4: mismos hashes y todo en verde
python3 -m unittest discover -s scripts -v           # tests del gate y del runner de mutaciones
```

Corre con el python3 de WSL y lanza las suites en Docker (necesita `docker compose up -d`). Los ids están en scripts/gate-baseline.txt. Ver [workflow.md](workflow.md), Gate de tests.

## Mutaciones SQL

```bash
python3 scripts/mutants.py                           # todas, en backend/tests/database/mutations
python3 scripts/mutants.py account_no_force          # solo las nombradas
```

Igual que el gate: python3 de WSL y Pest en Docker. Ver [workflow.md](workflow.md), Mutaciones SQL.

## Base de datos

- `pyme`: desarrollo. `pyme_test`: tests, forzada en phpunit.xml.
- `db/init/` corre solo al crear el volumen. Para reaplicarlo (borra los datos): `docker compose down -v`.
- Consola: `docker compose exec postgres psql -U postgres -d pyme`.
- Desde un cliente en Windows: `127.0.0.1:5433`, usuario `pyme_owner`.
- La aplicación se conecta como `pyme_app` (conexión `pgsql`). Las migraciones corren como `pyme_owner` (conexión `pgsql_owner`): sin `--database=pgsql_owner`, `migrate` falla por permisos, y eso es lo esperado.
- Cada migración que crea una tabla da sus GRANT a `pyme_app` de forma explícita. No hay default privileges.

## Commits

Conventional Commits: `tipo(alcance): descripción`. Ver [CLAUDE.md](../CLAUDE.md).
El commit que cierra un paso actualiza [estado.md](estado.md).

### Ramas y PR

- `main` solo avanza por PR con squash y los checks `backend`, `frontend`, `guards` e `infra` en verde. No se hace commit directo en `main`.
- Cada paso va en su propia rama, creada desde GitHub y no desde el `main` local:

  ```bash
  git fetch origin
  git switch -c <tipo>/<paso> origin/main
  ```

- Después del merge: `git switch main && git pull`. El repo tiene `pull.ff only`, así que si `main` local diverge, `git pull` falla en vez de mezclar. Revisa la divergencia con `git log --oneline origin/main..main` antes de tocar nada.
- Un commit que quedó sin subir en `main` local entra escondido en el siguiente squash. Así pasó con los pasos 4 y 5 del bloque 4 en el PR #2; los commits originales están en la etiqueta `bloque-4-pasos-4-5`.