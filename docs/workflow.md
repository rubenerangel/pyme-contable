# Flujo de trabajo con agentes

Tres roles de construcción y dos revisores en Claude Code. Rubén aprueba planes y commits.

## Roles

- Arquitecto: la sesión principal (Opus). Planifica, escribe la especificación de cada paso, coordina, revisa, provoca los rojos de las invariantes, escribe docs y hace los commits.
- Tester: agente `tester` (Sonnet, esfuerzo high, [.claude/agents/tester.md](../.claude/agents/tester.md)). Solo escribe tests.
- Coder: agente `coder` (Sonnet, esfuerzo high, [.claude/agents/coder.md](../.claude/agents/coder.md)). Solo escribe código de producción y configuración.
- Revisor de seguridad: agente `security-reviewer` (Opus 5.5 fijado por id, esfuerzo high, [.claude/agents/security-reviewer.md](../.claude/agents/security-reviewer.md)). Solo lectura: reporta hallazgos con evidencia, no edita.
- Revisor de rendimiento: agente `performance-reviewer` (Opus 5.5 fijado por id, esfuerzo high, [.claude/agents/performance-reviewer.md](../.claude/agents/performance-reviewer.md)). Solo lectura: reporta hallazgos contra las metas de rendimiento, no edita.

## Ciclo por paso

1. Plan en modo plan. Rubén lo aprueba. El plan dice si el paso pasa por revisor de seguridad, de rendimiento o ninguno (ver Revisores).
2. El arquitecto escribe la especificación: archivos, comportamiento esperado, invariantes, comandos de test y qué queda fuera.
3. El tester escribe los tests desde la especificación, sin leer ni anticipar la implementación, y confirma que fallan por la razón esperada.
4. El coder implementa hasta verde. Nunca edita tests.
5. Si el plan lo pide, los revisores revisan el diff en verde. El arquitecto decide qué hallazgos se corrigen en el paso, cuáles se registran como pendientes en docs/estado.md y cuáles se descartan con motivo. Lo que se corrige vuelve por tester y coder.
6. El arquitecto revisa el diff contra el plan y CLAUDE.md, corre la suite completa y rompe a mano cada invariante nueva para ver el test en rojo.
7. El arquitecto muestra un resumen, con los hallazgos de los revisores y qué se hizo con cada uno. Con el OK de Rubén hace el commit, que incluye docs/estado.md si cierra un paso. Push solo si Rubén lo pide.

## Revisores

- `security-reviewer` cuando el paso toca: tablas nuevas, políticas RLS, GRANT, funciones SECURITY DEFINER, middleware, autenticación, sesión, rutas nuevas, manejo de errores, logs, dependencias nuevas, Dockerfile, compose o CI. Además al cerrar cada bloque, sobre el diff del bloque.
- `performance-reviewer` cuando el paso toca: consultas sobre tablas que crecen (documentos, asientos, líneas, bitácora), índices, bloqueos o transacciones, triggers por fila, jobs masivos, listados o reportes. Además en la medición del bloque 7.
- Ningún revisor corre en pasos solo de docs o de configuración sin impacto en esos temas.
- Prioridad cuando chocan: el orden de [decisiones.md](decisiones.md), sección Trade-offs. Un hallazgo de rendimiento nunca relaja una invariante de CLAUDE.md. Un hallazgo que contradice docs/decisiones.md llega como "decision challenge" y lo decide Rubén.
- El modelo va fijado por id (claude-opus-5-5) y se actualiza a mano al cambiar de versión.

## Reglas

- Mismo árbol de trabajo, un agente a la vez. Compose monta ./backend y ./db, así que un worktree aparte necesitaría su propio stack.
- Escalamiento: si el coder cree que un test está mal, lo reporta y no lo toca. El arquitecto corrige la especificación o pide al tester que corrija. Tras 2 rondas sin verde, el arquitecto para y le explica el bloqueo a Rubén.
- Comandos de los agentes: tests, lint y typecheck vía docker compose o npmd, lectura, git diff y git status. Los agentes no tienen el alias npmd (vive en el ~/.bashrc de Rubén): usan el docker run equivalente de [entorno.md](entorno.md) sin -it. Los revisores además pueden correr composer audit, npm audit, consultas de catálogo y sondas como pyme_app dentro de BEGIN y ROLLBACK, nunca con commit.
- Solo el arquitecto, con OK de Rubén: git commit y push, docker compose down -v, instalar dependencias (composer require, npm install) y cambios de entorno (.env, volúmenes).
- Context7: cada agente consulta la versión del lock antes de usar una API de librería e indica en una línea qué consultó. Si no está, lo dice y marca el código como no verificado.
- Reporte final de cada agente: archivos tocados, comandos con su resultado, lo verificado y lo supuesto. Los revisores además: veredicto y hallazgos con severidad y evidencia.
- Revisión: la hace el arquitecto, con los reportes de los revisores cuando el plan los pide. /code-review solo si Rubén lo pide.
- Patrones: el plan y la especificación nombran en MAYÚSCULAS el patrón de cada pieza, por qué y la alternativa descartada, o dicen que no hace falta. El coder aplica solo los que pide la especificación y los nombra en su reporte. La revisión del arquitecto incluye buscar sobreingeniería: una abstracción sin un segundo caso real se quita.
- Las restricciones de comandos de los agentes son por instrucción. Los permisos efectivos son los de la sesión: disallowedTools quita Edit y Write a los revisores, pero Bash sigue disponible.