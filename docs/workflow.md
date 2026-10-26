# Flujo de trabajo con agentes

Tres roles de construcción y tres revisores en Claude Code. Rubén aprueba planes y commits.

## Roles

- Arquitecto: la sesión principal (Opus). Planifica, escribe la especificación de cada paso, coordina, revisa, provoca los rojos de las invariantes, escribe docs y hace los commits.
- Tester: agente `tester` (Sonnet, esfuerzo high, [.claude/agents/tester.md](../.claude/agents/tester.md)). Solo escribe tests.
- Coder: agente `coder` (Sonnet, esfuerzo high, [.claude/agents/coder.md](../.claude/agents/coder.md)). Solo escribe código de producción y configuración.
- Revisor de código: agente `code-reviewer` (Opus 5.5 fijado por id, esfuerzo high, [.claude/agents/code-reviewer.md](../.claude/agents/code-reviewer.md)). Solo lectura. Busca bugs de corrección y tests que no prueban nada, sin conocer el plan ni la especificación.
- Revisor de seguridad: agente `security-reviewer` (Opus 5.5 fijado por id, esfuerzo high, [.claude/agents/security-reviewer.md](../.claude/agents/security-reviewer.md)). Solo lectura: reporta hallazgos con evidencia, no edita.
- Revisor de rendimiento: agente `performance-reviewer` (Opus 5.5 fijado por id, esfuerzo high, [.claude/agents/performance-reviewer.md](../.claude/agents/performance-reviewer.md)). Solo lectura: reporta hallazgos contra las metas de rendimiento, no edita.

## Ciclo por paso

1. Plan en modo plan. Rubén lo aprueba. El plan dice qué revisores corren en el paso o que no corre ninguno (ver Revisores).
2. El arquitecto escribe la especificación: archivos, comportamiento esperado, invariantes, comandos de test y qué queda fuera.
3. El tester escribe los tests desde la especificación, sin leer ni anticipar la implementación, y confirma que fallan por la razón esperada. El arquitecto cierra el paso con `python3 scripts/gate.py red`, o con `lock` si el paso es solo de tests (ver Gate de tests).
4. El coder implementa hasta verde. Nunca edita tests. El arquitecto lo confirma con `python3 scripts/gate.py green`.
5. Si el plan lo pide, los revisores revisan el diff en verde. El arquitecto decide qué hallazgos se corrigen en el paso, cuáles se registran como pendientes en docs/estado.md y cuáles se descartan con motivo. Lo que se corrige vuelve por tester y coder, y cada ronda repite red y green.
6. El arquitecto revisa el diff contra el plan y CLAUDE.md, corre la suite completa y rompe a mano cada invariante nueva para ver el test en rojo.
7. Antes del commit, si el diff lo pide y `code-reviewer` no corrió en el paso 5, el arquitecto corre /code-review sobre el diff a commitear (ver Revisión de corrección antes del commit). Sus hallazgos siguen la misma regla del paso 5: se corrigen vía tester y coder, se registran como pendientes o se descartan con motivo.
8. El arquitecto muestra un resumen, con los hallazgos de los revisores y de la revisión de corrección y qué se hizo con cada uno. Con el OK de Rubén hace el commit, que incluye docs/estado.md si cierra un paso y scripts/gate-baseline.txt si green lo actualizó. Push solo si Rubén lo pide. Cada paso va en su propia rama y entra a main, que está protegida, por PR.

## Revisores

- `code-reviewer` cuando el paso tiene lógica: migraciones con triggers, funciones, políticas o CHECK, acciones, dominio, middleware y tests de invariantes.
- `security-reviewer` cuando el paso toca: tablas nuevas, políticas RLS, GRANT, funciones SECURITY DEFINER, middleware, autenticación, sesión, rutas nuevas, manejo de errores, logs, dependencias nuevas, Dockerfile, compose o CI. Además al cerrar cada bloque, sobre el diff del bloque.
- `performance-reviewer` cuando el paso toca: consultas sobre tablas que crecen (documentos, asientos, líneas, bitácora), índices, bloqueos o transacciones, triggers por fila, jobs masivos, listados o reportes. Además en la medición del bloque 7.
- Ningún revisor corre en pasos solo de docs o de configuración sin impacto en esos temas.
- Contexto limpio: los revisores se invocan por nombre de agente, nunca como fork, porque un fork hereda toda la conversación. A `code-reviewer` se le delega solo "Revisa el diff sin commitear según tu definición", sin plan, especificación ni intención del código. A los otros dos se les puede dar el alcance del paso.
- En paralelo: los revisores pueden correr a la vez en segundo plano. Mientras corren, nadie ejecuta la suite de tests ni migraciones, porque comparten pyme_test y la base de desarrollo. El arquitecto corre la suite completa en verde antes de lanzarlos; los revisores no la corren.
- Prioridad cuando chocan: el orden de [decisiones.md](decisiones.md), sección Trade-offs. Un hallazgo de rendimiento nunca relaja una invariante de CLAUDE.md. Un hallazgo que contradice docs/decisiones.md llega como "decision challenge" y lo decide Rubén.
- Los modelos van fijados por id (claude-opus-5-5) y se actualizan a mano al cambiar de versión. El revisor de código usa un modelo distinto del coder a propósito.

## Revisión de corrección antes del commit

- Todo commit cuyo diff toca algo que se ejecuta pasa por una revisión de corrección: backend/ (código, migraciones, tests y sondas de tests), frontend/, db/, compose.yaml, Dockerfile, configuración de nginx o PHP, .github/ y lockfiles (composer.lock, package-lock.json).
- Quién la hace, una sola por commit:
  - `code-reviewer` si el paso tiene lógica (ver Revisores). Corre en el paso 5 con los demás revisores y cuenta como la revisión antes del commit; no se corre /code-review encima.
  - /code-review en el paso 7 si el diff toca algo que se ejecuta pero no tiene lógica: configuración, infraestructura, CI o lockfiles.
  - Diff mixto: `code-reviewer`, sobre todo el diff.
- No corre ninguna cuando el diff es solo docs: docs/, CLAUDE.md, archivos .md y las definiciones de agentes en .claude/agents/.
- No reemplaza a los revisores de seguridad y rendimiento: ellos revisan su tema contra las invariantes, la revisión de corrección busca bugs y tests que no prueban nada.
- Criterio por tipo de hallazgo, igual para código y para tests:
  - Se corrige siempre, en cualquier ronda: un bug que da un resultado incorrecto, algo que rompe una invariante de CLAUDE.md, un hueco de seguridad con un ataque concreto en este sistema o un test que pasa en falso. Esto nunca se negocia para cortar rondas.
  - Primera ronda: triaje normal (corregir, registrar como pendiente o descartar con motivo), como los hallazgos de los revisores.
  - Desde la segunda ronda, solo se corrige lo de la primera lista. Casos futuros hipotéticos, estilo, limpieza y refactor van a pendientes o se descartan, sin abrir otra ronda.
- Una ronda nueva solo corre si las correcciones cambiaron comportamiento (código, SQL o lógica de un test), con el mismo revisor y solo sobre el delta de esas correcciones, no sobre todo el diff.
- Si tras dos rondas siguen saliendo hallazgos de la primera lista, el arquitecto para y le explica el bloqueo a Rubén, igual que tras 2 rondas del coder sin verde.
- Excepción registrada: en G1 y G4 (hook de roles) Rubén autorizó una tercera ronda, solo con security-reviewer y solo sobre el delta, porque las correcciones de cada ronda abrían huecos nuevos en el mismo mecanismo (la lista permitida de psql y el filtro de .env). Con límite fijado antes de lanzarla: un hueco más en la lista permitida no se parchea, se registra y se reemplaza el psql libre por un script fijo.
- Excepción registrada: en G2 (gate de tests) la corrección de composer.json, que salió en la ronda 2, entró sin ronda 3. Motivo: eran dos patrones nuevos (PROTECTED del gate y CODER_FORBIDDEN del hook) en un mecanismo ya probado por mutaciones, y cada uno se vio en rojo con su propia mutación.

## Reglas

- Mismo árbol de trabajo, un agente que escribe a la vez. Compose monta ./backend y ./db, así que un worktree aparte necesitaría su propio stack. Los revisores no escriben y pueden correr en paralelo según la sección Revisores.
- Escalamiento: si el coder cree que un test está mal, lo reporta y no lo toca. El arquitecto corrige la especificación o pide al tester que corrija. Tras 2 rondas sin verde, el arquitecto para y le explica el bloqueo a Rubén.
- Comandos de los agentes: tests, lint y typecheck vía docker compose o npmd, lectura, git diff y git status. Los agentes no tienen el alias npmd (vive en el ~/.bashrc de Rubén): usan el docker run equivalente de [entorno.md](entorno.md) sin -it. Los revisores no corren tests. Los de seguridad y rendimiento pueden correr composer audit, npm audit y psql como pyme_app con -v ON_ERROR_STOP=1, con todo SQL (también las lecturas) dentro de BEGIN y ROLLBACK, nunca con commit, y solo los meta-comandos \d*, \x y \echo. El SQL que escribe solo va contra pyme_test; pyme queda de solo lectura. El revisor de código solo usa git de lectura y lectura de archivos.
- Solo el arquitecto, con OK de Rubén: git commit y push, docker compose down -v, instalar dependencias (composer require, npm install), cambios de entorno (.env, volúmenes) y compose.yaml o un compose.override, porque deciden qué ejecuta el gate.
- Context7: cada agente consulta la versión del lock antes de usar una API de librería e indica en una línea qué consultó. Si no está, lo dice y marca el código como no verificado.
- Reporte final de cada agente: archivos tocados, comandos con su resultado, lo verificado y lo supuesto. Los revisores además: veredicto y hallazgos con severidad y evidencia.
- Revisión: la hace el arquitecto, con los reportes de los revisores cuando el plan los pide, y la revisión de corrección antes del commit cuando el diff lo pide (`code-reviewer` o /code-review, ver su sección).
- Patrones: el plan y la especificación nombran en MAYÚSCULAS el patrón de cada pieza, por qué y la alternativa descartada, o dicen que no hace falta. El coder aplica solo los que pide la especificación y los nombra en su reporte. La revisión del arquitecto incluye buscar sobreingeniería: una abstracción sin un segundo caso real se quita.
- Las restricciones por rol ya no son solo instrucción: las impone el hook [.claude/hooks/role_guard.py](../.claude/hooks/role_guard.py), un PreToolUse sobre Edit, Write, MultiEdit, NotebookEdit y Bash que decide por agent_type. Para tester y coder es una baranda contra errores (rutas y comandos prohibidos); para los revisores y cualquier otro subagente es barrera, por lista permitida. Cualquier agente con acceso a docker es root en los contenedores, así que el hook no es un sandbox (ver [decisiones.md](decisiones.md), Trade-offs). Si bloquea algo que el paso necesita, el agente se detiene y se lo pide al arquitecto.
- Los revisores declaran `tools` como lista permitida (Read, Grep, Glob, Bash y Context7), igual que tester y coder: no heredan herramientas de red ni MCP de salida.
- Tester y coder no ejecutan scripts/gate.py ni escriben en .gate/ (hook). El gate es del arquitecto.
- La sesión principal no pasa por el hook: sus comandos de riesgo piden confirmación por permissions.ask en [.claude/settings.json](../.claude/settings.json), que además niega leer los .env.

## Gate de tests

[scripts/gate.py](../scripts/gate.py) comprueba sin LLM lo que antes era instrucción: que los tests nuevos nacen en rojo y que nadie los toca hasta el verde.

- Id de un test: `<ruta en el repo>::<nombre>`, sacado del JUnit de Pest y de Vitest. Test nuevo: id que no está en scripts/gate-baseline.txt, la lista del último green.
- Protegidos (se hashean con sha256): backend/tests/, backend/phpunit.xml, backend/composer.json (sus autoload files pueden cargar una clase antes que el helper de tests del mismo nombre), compose.yaml, los *.test.* y frontend/src/test/ del frontend, vite.config y vitest.config, y el baseline. Pest corre con `-f compose.yaml`, así un compose.override.yaml no entra.
- `red`, al final del paso 3: corre las dos suites. Exige al menos un test nuevo o modificado en rojo sin contar los `--expect-pass`, cada test nuevo en rojo (salvo los declarados con `--expect-pass <id>`, como los controles con rollback), ningún test nuevo skipped, cada test borrado declarado con `--allow-removed <id>`, y los existentes en verde salvo los de archivos de test cambiados. Guarda HEAD, hashes, ids nuevos y la primera línea de cada fallo en .gate/red.json, para revisar el motivo del rojo sin releer la salida, e imprime los tests borrados y los archivos protegidos que cambiaron.
- Un archivo de Vitest que no carga, un error no manejado o un beforeAll o afterAll que falla aparecen en el JUnit como testcases; red y lock los rechazan porque no son tests. El rojo de un test de frontend necesita que el archivo cargue: antes de red, un stub que exporte el componente o la función vacía, para que el test falle en la aserción y no en el import.
- `lock`, en vez de red, en los pasos solo de tests que pasan desde el inicio: exige todo en verde y guarda lo mismo. El rojo de esos pasos lo sigue provocando el arquitecto rompiendo producción a mano (paso 6).
- red y lock no pisan un estado que ya existe: en una ronda de correcciones se repiten con `--replace`, y la salida dice qué estado pisaron.
- Si a mitad de paso hace falta una dependencia, Rubén cambia composer.json y el arquitecto corre `red --replace` antes de que el coder siga.
- `green`, al final del paso 4: exige el mismo HEAD (no hay commit entre red y green), los mismos hashes, exactamente los ids esperados (ni uno de más ni uno de menos, así un dataset que crece o encoge por un cambio de producción no pasa) y todo en verde, sin skipped. Si pasa, reescribe el baseline y borra .gate/red.json; si falla, red.json queda para la siguiente ronda. Su salida dice si venía de red o de lock, con el fallo que tenía cada test nuevo en red: un "green after lock" donde se corrió red es una alerta.
- Un helper compartido de tests (datasets, tests/Pest.php, TestCase.php) que cambia puede hacer fallar en red tests de archivos que no cambiaron. El gate no lo distingue: se cambia el helper en un paso propio, o se acepta el falso fallo y se revisa a mano.
- `baseline`: solo al crear el gate o para rehacerlo, sobre un árbol sin cambios en archivos protegidos y con la suite en verde.
- Si green falla por hashes, el coder tocó algo protegido: se revierte y se vuelve a correr green, o, si el cambio era necesario, vuelve por el tester y red.
- Límite aceptado: el coder corre con el usuario de Rubén y podría reescribir .gate/red.json con python3 -c. El gate atrapa el atajo, no a un agente adversario (ver [decisiones.md](decisiones.md), Trade-offs).

## Mutaciones SQL

[scripts/mutants.py](../scripts/mutants.py) comprueba que los tests de base de datos ven un esquema roto: lo que antes era un rojo a mano que se rompía en producción y se revertía.

- Una mutación es una carpeta backend/tests/database/mutations/<nombre>/ con una sola migración 9999_12_31_*.php, que corre después de todas las reales. Los traits la cargan con --path cuando MUTATION=<nombre>; aplicarla antes de la suite no sirve, porque migrate:fresh la borra.
- Cabecera: una línea `// kills: <id del gate>` por cada test que debe fallar con ella, todos. Solo tests de archivos con RefreshDatabaseAsOwner o DatabaseTruncationAsOwner, sin dataset salvo que el id lleve su sufijo. `// control: survives` marca un control: sus tests deben pasar.
- MutationAppliedTest prueba que la mutación se aplicó, así un SQL roto no cuenta como muerte. El runner restaura pyme_test al final.
- Fuera del runner: lo que exige superusuario (GRANT ON PARAMETER, ALTER ROLE de otro rol, ALTER DATABASE), porque las migraciones corren como pyme_owner. Se ve en rojo a mano y queda en estado.md.
- Un test de invariante de base de datos nuevo trae su mutación en el mismo paso. Las mutaciones son tests: protegidas por el gate y prohibidas al coder.

## CI

[.github/workflows/ci.yml](../.github/workflows/ci.yml) corre en cada PR y en cada push a main, con actions e imágenes de herramientas fijadas por SHA o digest:

- backend: build de la imagen, Postgres con el mismo init, composer install, composer audit, Pest y las mutaciones SQL.
- frontend: npm ci, npm audit --audit-level=high, lint, typecheck, test y build.
- guards: tests del hook y del gate, y JSON válido en .claude/settings.json. Son controles y sus tests corren como cualquier otro.
- infra: actionlint sobre los workflows, hadolint sobre docker/php/Dockerfile y docker compose config.