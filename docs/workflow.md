# Flujo de trabajo con agentes

Tres roles en Claude Code. Rubén aprueba planes y commits.

## Roles

- Arquitecto: la sesión principal (Opus). Planifica, escribe la especificación de cada paso, coordina, revisa, provoca los rojos de las invariantes, escribe docs y hace los commits.
- Tester: agente `tester` (Sonnet, esfuerzo high, [.claude/agents/tester.md](../.claude/agents/tester.md)). Solo escribe tests.
- Coder: agente `coder` (Sonnet, esfuerzo high, [.claude/agents/coder.md](../.claude/agents/coder.md)). Solo escribe código de producción y configuración.

## Ciclo por paso

1. Plan en modo plan. Rubén lo aprueba.
2. El arquitecto escribe la especificación: archivos, comportamiento esperado, invariantes, comandos de test y qué queda fuera.
3. El tester escribe los tests desde la especificación, sin leer ni anticipar la implementación, y confirma que fallan por la razón esperada.
4. El coder implementa hasta verde. Nunca edita tests.
5. El arquitecto revisa el diff contra el plan y CLAUDE.md, corre la suite completa y rompe a mano cada invariante nueva para ver el test en rojo.
6. El arquitecto muestra un resumen. Con el OK de Rubén hace el commit, que incluye docs/estado.md si cierra un paso. Push solo si Rubén lo pide.

## Reglas

- Mismo árbol de trabajo, un agente a la vez. Compose monta ./backend y ./db, así que un worktree aparte necesitaría su propio stack.
- Escalamiento: si el coder cree que un test está mal, lo reporta y no lo toca. El arquitecto corrige la especificación o pide al tester que corrija. Tras 2 rondas sin verde, el arquitecto para y le explica el bloqueo a Rubén.
- Comandos de los agentes: tests, lint y typecheck vía docker compose o npmd, lectura, git diff y git status.
- Solo el arquitecto, con OK de Rubén: git commit y push, docker compose down -v, instalar dependencias (composer require, npm install) y cambios de entorno (.env, volúmenes).
- Context7: cada agente consulta la versión del lock antes de usar una API de librería e indica en una línea qué consultó. Si no está, lo dice y marca el código como no verificado.
- Reporte final de cada agente: archivos tocados, comandos con su resultado, lo verificado y lo supuesto.
- Revisión: la hace el arquitecto. /code-review solo si Rubén lo pide.
- Las restricciones de comandos de los agentes son por instrucción. Los permisos efectivos son los de la sesión.
