# Fases y estado del trabajo

## Fases

1. Mínima: multi-tenant con membresías, RLS, roles de base de datos, login de sesión y esqueleto de la SPA.
2. Libro mayor: catálogo de cuentas como seed, dimensiones, periodos, cierres con foto de saldos, consecutivos, saldos iniciales, con sus pantallas de consulta. Medición con 1M de líneas sintéticas.
3. Antes de cargar datos reales del cliente: MFA y segregación de funciones.
4. Motor de conceptos: estrategias y parámetros versionados, retenciones (retefuente, IVA, ICA), AIU, anticipos y retegarantía, con previsualización y validación de casos golden.
Después: CxC y CxP, tesorería, costos por obra, captura de facturas por foto desde WhatsApp, facturación electrónica y documento soporte con proveedor tecnológico, nómina, activos fijos, exógena.

## Estado

- Orden de bloques:
  1. Esqueleto: Docker Compose (postgres, php-fpm, nginx, worker, node), Laravel y Vite vacíos, CI con Pest y Vitest. En curso, dividido en pasos con un commit cada uno.
  2. Roles de Postgres y dos conexiones en Laravel. Test que falla si la aplicación es dueña, tiene BYPASSRLS o UPDATE o DELETE sobre asientos, líneas o bitácora.
  3. Tenancy: empresas, usuarios, membresías, middleware de contexto desde sesión, middleware de job que fija y limpia. Test de fuga con dos empresas.
  4. Libro en SQL: cuentas, terceros, centros de costo, obras, municipios (catálogo global), periodos, contadores, documentos, asientos, líneas, triggers de cuadre e inmutabilidad, RLS, con sus tests de integración.
  5. Dominio: Monto sobre brick/math, AsientoPropuesto que cuadra en memoria, acción Contabilizar. Tests unitarios.
  6. Formato golden con 2 o 3 casos ficticios, para que la contadora valide la forma.
  7. API y SPA: login, selector de empresa, plan de cuentas, auxiliar, balance. Luego datos sintéticos y medición.
- Pasos del bloque 1:
  0. Hecho. WSL, git actualizado, Docker con integración WSL.
  1 a 4. Hecho. Repo, .gitignore, .gitattributes, .env.example, db/init/01-roles-y-bases.sh (rol pyme_owner sin SUPERUSER ni BYPASSRLS, bases pyme y pyme_test, timezone UTC, PUBLIC sin CONNECT ni CREATE), compose.yaml con postgres y healthcheck por TCP. Commit build(db).
  5. Hecho. Imagen PHP 8.4 FPM con pdo_pgsql, intl, zip, bcmath, gmp y opcache, www-data con UID y GID del host, php.ini al final para no invalidar capas, servicio php en compose con ancla x-php. Commit build(infra).
  6. Hecho. Laravel 13.38.0 sobre PHP 8.4.27 con composer create-project dentro del contenedor (--no-scripts, pasos manuales), conectado a Postgres, sin el pipeline de assets de Laravel. Scripts de composer sin npm ni dev, sin post-create-project-cmd. Las variables DB_* de Compose prevalecen sobre backend/.env. Zona horaria de la aplicación en UTC a propósito. Locale en por ahora. Commit build(backend).
  7. Hecho. Pest con plugin de Laravel (versión en composer.lock); phpunit/phpunit ya no es dependencia directa, llega transitivamente. phpunit.xml con suites Unit, Integration y Feature y DB_DATABASE forzado con server y env force. tests/Pest.php: Unit sin TestCase de Laravel, Integration con TestCase y trait por test, Feature con RefreshDatabase. Test de conexión que exige pgsql, base pyme_test, UTC, Postgres 17 o superior y PDO no persistente, verificado también en rojo con DB_CONNECTION=sqlite. Se corre con vendor/bin/pest o php artisan test. Commit test(backend).
  8. Hecho. Paquete pyme-contable-frontend con React 19.3, Vite 8.3, TypeScript 6.0 (strict, noUncheckedIndexedAccess, exactOptionalPropertyTypes), Vitest 5.0 con jsdom 30 y Testing Library, oxlint. Scripts lint, typecheck, test y build en verde; test visto en rojo. Commit build(frontend).
  9. Hecho. nginx con / hacia Vite (websocket para HMR) y /api, /sanctum y /up hacia php-fpm con SCRIPT_FILENAME fijo, sin servir archivos de backend/public. Worker con queue:work como www-data, --tries=1, --max-jobs y --max-time, restart unless-stopped. Node con el mismo node_modules que npmd. HOME=/tmp en los contenedores PHP para herramientas CLI. SPA y API en http://localhost:${APP_HOST_PORT}. Verificado: /up 200, /api 404 de Laravel, / desde Vite, HMR, y un job encolado con Artisan::queue('about') procesado por el worker. Commit build(infra).
  10. Hecho. Repo privado en GitHub con push por SSH (llave ed25519 de WSL). Workflow ci en .github/workflows/ci.yml: actions fijadas por SHA (checkout v7.0.1, setup-node v7.1.0), permissions contents: read, persist-credentials false, concurrency por rama. Job backend vía Compose con .env generado en CI (build de imagen, Postgres con el mismo init, composer install, key:generate, pest), cerca de 1 min. Job frontend con Node 24: npm ci, lint, typecheck, test y build. Verificado en rojo con una rama que rompía el título. Commitlint pospuesto hasta que haya más desarrolladores. Pendiente evaluar caché de capas Docker si el backend se vuelve lento. Commit ci.
  11. En curso. Documentación en el repo como fuente única: docs/decisiones.md, docs/estado.md, docs/entorno.md, docs/preguntas-contadora.md y docs/desarrollo.md, más CLAUDE.md corto en la raíz. El proyecto de claude.ai sincroniza docs/ y CLAUDE.md desde GitHub (sincronización manual). Después se continúa en Claude Code con modo plan.
- Regla: el commit que cierra un paso actualiza docs/estado.md en el mismo commit.
- Redondeo, reinicio de consecutivos y reglas de cierre quedan configurables hasta que responda la contadora.
- El motor de conceptos espera sus respuestas.
- El acuerdo de alcance y propiedad del código con el cliente sigue abierto y asumo ese riesgo. Por eso el sistema debe poder exportar todos los datos de una empresa en formato abierto.
