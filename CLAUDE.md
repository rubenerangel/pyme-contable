# PyME Contable

Sistema contable por conceptos para pymes de construcción en Colombia. Laravel 13 + PostgreSQL 17 + SPA React/TypeScript, en monorepo con Docker Compose. Desarrollador único: Rubén, ingeniero senior. No expliques lo básico.

## Documentación (fuente de verdad, léela antes de proponer cambios)

- [docs/decisiones.md](docs/decisiones.md): stack, arquitectura, decisiones de diseño, multi-tenant, requisitos no funcionales, tests.
- [docs/estado.md](docs/estado.md): fases, bloques y avance. Dice qué sigue.
- [docs/entorno.md](docs/entorno.md): WSL, Docker, puertos, alias npmd.
- [docs/desarrollo.md](docs/desarrollo.md): comandos.
- [docs/preguntas-contadora.md](docs/preguntas-contadora.md): criterio contable pendiente.
- [docs/workflow.md](docs/workflow.md): flujo arquitecto, tester y coder con agentes.
- [docs/glosario.md](docs/glosario.md): términos del dominio y su nombre en código.

## Invariantes (nunca se negocian)

- Ningún asiento contabilizado descuadrado persiste (constraint trigger diferido). Documentos contabilizados inmutables, se corrigen por reverso.
- Aislamiento por empresa con RLS (FORCE) y global scope. La aplicación nunca asigna company_id ni se conecta como dueña de las tablas.
- Consecutivos sin huecos con tabla de contadores y bloqueo de fila, nunca secuencias.
- Montos: NUMERIC en Postgres, brick/math en PHP, string en la API. Nunca float ni number de JS.
- Todo corre en UTC. America/Bogota solo explícita en dominio (reloj inyectado) y en presentación.
- Toda regla contable o fiscal es hipótesis hasta que la contadora la valide. Si una decisión depende de criterio contable, va a docs/preguntas-contadora.md, no se decide.

## Arquitectura

- Capas: dominio, aplicación (acciones), infraestructura, entrada. Controladores, jobs y comandos delgados.
- Dominio y acciones sin facades, helpers globales (now, auth, config, app, request) ni Eloquent estático. Dependencias por constructor.
- Interfaces solo con segunda implementación o necesidad real de test.
- PHP con declare(strict_types=1). TypeScript en strict.
- Código en inglés: identificadores, comentarios, tests, mensajes de assert, SQL, archivos y CI. Términos de dominio según docs/glosario.md. Español solo en lo que ve el usuario final, docs/ y la descripción de los commits.

## Librerías: Context7

Antes de usar o configurar una API de librería, consulta Context7 para la versión de composer.lock o package-lock.json. Si no está, dilo y marca el código como no verificado. Indica en una línea qué consultaste.

## Tests

- tests/Unit: dominio sin Laravel ni base. tests/Integration: invariantes en Postgres real. tests/Feature: API.
- Cuadre con RefreshDatabase: SET CONSTRAINTS ALL IMMEDIATE al final del test, nunca al inicio.
- Concurrencia con DatabaseTruncation y conexiones separadas con commit real.
- Todo test de invariante se ve fallar a propósito antes de darlo por bueno.

## Forma de trabajo

- Rubén quiere entender cada paso. En cambios no triviales: modo plan, propuesta corta, aprobación, cambios pequeños con sus tests.
- La sesión principal es el arquitecto: delega tests al agente tester y código al agente coder según docs/workflow.md.
- Distingue lo verificado de lo supuesto. No inventes normativa, tarifas, cuentas del PUC, APIs ni versiones.
- Cuestiona decisiones si ves riesgo o exceso de abstracción. Si este archivo y docs/ se contradicen, avisa en vez de elegir.
- Comandos: siempre vía docker compose o npmd desde la raíz (ver docs/desarrollo.md). Nunca npm ni php del host.
- Respuestas en español, cortas, sin guion largo.

## Commits

- Conventional Commits: tipo(alcance): descripción. Tipos: feat, fix, refactor, perf, test, docs, build, ci, chore. Alcances: db, backend, frontend, infra, ci, golden, docs.
- Tipo y alcance en inglés; descripción en español, imperativo, minúscula, sin punto final. Cambio incompatible: "!" y pie "BREAKING CHANGE:".
- Commit solo con tests en verde. El commit que cierra un paso actualiza docs/estado.md en el mismo commit.
