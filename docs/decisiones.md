# Decisiones técnicas y de diseño

## Stack

- Backend: PHP con Laravel y PostgreSQL. Despliegue con PHP-FPM, sin conexiones PDO persistentes. Colas de Laravel para trabajo largo (cierres, reportes, importaciones). Sin Octane salvo decisión explícita.
- Frontend: SPA en React con TypeScript y Vite que consume la API versionada. Autenticación con Sanctum en modo SPA y MFA con Fortify.
- Monorepo: backend/, frontend/, db/, golden/, docs/. Un mismo PR cambia el contrato de la API y los tipos de TypeScript.
- Docker Compose para desarrollo, CI con tests de backend y frontend desde el primer commit.
- Adoptadas: Pest para tests de backend, Vitest y Testing Library para el frontend, oxlint como linter del frontend.
- Por evaluar: brick/math para aritmética decimal, una librería de componentes y otra de manejo de datos del servidor en React, y un generador de OpenAPI desde el código de Laravel (candidato: dedoc/scramble).
- No asumas versiones. Las versiones reales son las de composer.lock y package-lock.json.

## Documentación

- docs/ en el repo es la fuente única de decisiones, estado, entorno y preguntas abiertas. Se versiona con el código y cada cambio de decisión queda en un commit.
- El proyecto de claude.ai sincroniza docs/ y CLAUDE.md desde GitHub. No se mantienen copias editadas a mano en otro lugar.
- Los cambios de criterio contable se registran en docs/preguntas-contadora.md hasta que la contadora responda, y luego pasan a docs/decisiones.md.

## Despliegue

- Imagen de desarrollo: código por bind mount. Capas ordenadas de menos a más cambiante (paquetes del sistema, Composer, configuración).
- Imagen de producción: Dockerfile multi-stage aparte.
  - vendor: copia composer.json y composer.lock, luego composer install --no-dev, luego el código y dump-autoload optimizado.
  - frontend: copia package.json y package-lock.json, luego npm ci, luego el código y npm run build.
  - app: php-fpm con código y vendor, sin Composer, git, cabeceras -dev ni dependencias de desarrollo. Usuario no root. Opcache sin revalidación.
  - web: nginx con el dist del frontend como estáticos.
- Copiar siempre los manifiestos antes del código para aprovechar el caché de capas.
- .dockerignore obligatorio en cuanto se copie código a una imagen: .git, vendor, node_modules, .env, tests.
- Ninguna imagen lleva secretos. Se inyectan en tiempo de ejecución.

## Glosario

- Concepto: hecho de negocio con su regla de contabilización.
- Documento: instancia de un concepto (factura, acta, pago) con estado borrador o contabilizado, origen, identidad externa del soporte y adjunto original. Un reverso es un documento propio.
- Asiento: resultado contable de un documento, con líneas de débito y crédito que cuadran.
- Estrategia: clase de dominio en PHP que implementa la fórmula de un concepto (AIU, anticipos, retegarantía, retenciones). Se versiona con el código.
- Parámetros: valores con vigencia por fecha que usa una estrategia (cuentas, tarifas, bases, UVT), versionados en tablas y validados contra el esquema que declara la estrategia.
- Regla: estrategia más la versión de parámetros vigente en la fecha del documento.
- Detalle tributario: impuestos, retenciones, bases y clasificación fiscal de una operación, enlazados a las líneas del asiento.
- Concepto fiscal: clasificación fiscal interna de una operación, que se mapea a los conceptos DIAN de cada año gravable. No confundir con el concepto de negocio.

## Arquitectura y principios

- Principios SOLID e inyección de dependencias por constructor.
- Capas: dominio (reglas contables, motor de conceptos, cálculos), aplicación (acciones o casos de uso), infraestructura (Eloquent, colas, integraciones) y entrada (controladores de API, comandos, jobs).
- El dominio y las acciones no usan facades, helpers globales (now, auth, config, app, request) ni llamadas estáticas de Eloquent. Dependen de contratos inyectados: repositorios, reloj, contexto de empresa, calculadora de montos.
- El reloj se inyecta siempre, porque las vigencias de los parámetros y los periodos dependen de la fecha.
- Los contratos se enlazan a sus implementaciones en service providers. Las implementaciones con Eloquent viven en infraestructura.
- Los controladores, comandos y jobs son delgados: validan, arman la entrada y llaman a una acción. Nada de lógica contable en controladores, modelos ni componentes de React.
- Los catálogos simples (por ejemplo ciudades o tipos de documento) pueden usar Eloquent directo en la capa de aplicación. El rigor de repositorios es para el libro mayor, las reglas y los documentos.
- No crear interfaces sin una segunda implementación o una necesidad real de test. Si una abstracción no se justifica, dilo.
- Patrones de diseño (GoF y de Fowler: REPOSITORY, TABLE DATA GATEWAY, VALUE OBJECT, UNIT OF WORK) solo cuando resuelven un problema que el código ya tiene. Cada plan nombra en MAYÚSCULAS el patrón de cada pieza, el problema que resuelve y la alternativa descartada; si una pieza no lo necesita, el plan lo dice.
- Sin sobreingeniería: la solución más simple que cumpla las invariantes. Nada de capas, fábricas, interfaces, eventos, configuración o genericidad para casos hipotéticos; se agregan cuando aparece el segundo caso real. Tres líneas repetidas son mejores que una abstracción prematura. Si un patrón agrega más código del que ahorra, no va.
- Monolito modular. Módulos: catálogo y terceros, documentos, motor de conceptos, libro mayor, capa fiscal, reportes. La contabilización es una sola transacción.

## Decisiones de diseño

### Motor de conceptos

- Cada concepto es una estrategia en PHP. Lo que se actualiza sin desplegar son los parámetros. Un cambio de fórmula es un despliegue con la suite golden en verde.
- Se aplica la regla vigente en document_date. El asiento guarda concepto, versión de estrategia, versión de parámetros y documento origen. Un cambio de regla nunca recalcula asientos contabilizados.
- La estrategia valida lo que exige cada operación (beneficiario, base, detalle tributario) y genera el detalle tributario junto con el asiento.
- No se construye un DSL de reglas editable.

### Catálogo de cuentas

- Plan de cuentas por empresa, con RLS, sembrado desde una plantilla. Con la Ley 1314 de 2009 no hay un PUC único obligatorio para estas empresas (doctrina del CTCP; Supersociedades, Oficio 115-117755, para el Grupo 1). El PUC del Decreto 2650 de 1993 sirve de plantilla. El contenido de la siembra está pendiente de la contadora.
- Jerarquía por parent_id. Solo las cuentas con is_postable reciben líneas. Una cuenta con hijas no es imputable y una cuenta con movimientos no recibe hijas. El CHECK de que el código empieza por el del padre solo se agrega si la contadora confirma estructura PUC.
- normal_balance es informativo: signo de presentación y alerta de saldo contrario. Nunca bloquea un movimiento. Default por clase al sembrar; las contracuentas (depreciación acumulada, deterioro) se configuran por cuenta.
- Una cuenta con movimientos no se borra ni cambia código, normal_balance, is_postable ni flags. Se desactiva con is_active. Sin vigencias en el catálogo: la historia apunta al id y la bitácora guarda los cambios.
- Flags por cuenta requires_third_party, requires_cost_center, requires_construction_project, requires_municipality y requires_tax_detail, validados por trigger sobre las líneas. Los valores de cada cuenta están pendientes de la contadora.
- La clasificación financiera (presentación) y la fiscal (exógena) no se derivan del código de la cuenta.

### Terceros

- Por empresa, con RLS, sin identidad global: una identidad compartida obliga a gobernar quién edita datos que ven varias empresas y expone qué terceros tiene cada una.
- Clave única (company_id, id_type, id_number, country). DV calculado y validado, nunca digitado. Nombres y apellidos separados para personas naturales, razón social para jurídicas. Datos de contacto en tabla aparte, suprimible.
- Tabla third_party (paso 4a del bloque 4): id_type es el código DIAN del tipo de documento con CHECK de forma (dos dígitos), sin lista cerrada porque cambia por año gravable; se cierra contra la resolución de exógena cuando se modele. Que 31 es NIT consta en la Resolución DIAN 15250 de 2006. Un NIT se guarda solo con dígitos, sin DV: el DV no se guarda y lo calculará el dominio con la API (no hay fuente oficial verificada del algoritmo, solo secundarias). country en ISO 3166-1 alfa-2, mapeado a la tabla de países de la DIAN con la exógena. person_type natural o legal, con CHECK de forma de los nombres. Sin atributos fiscales ni datos de contacto todavía.
- Los seudo-NIT de cuantías menores (222222222, tipo de documento 43) se generan al reportar; no son terceros. El consumidor final de facturación es un tercero único.
- El documento guarda en jsonb la condición fiscal del tercero que usó la regla (responsabilidades del RUT, autorretenedor, gran contribuyente). Un cambio posterior del tercero no altera el pasado.

### Documentos y asientos

- Los documentos en borrador se editan. Desde que se contabilizan son inmutables, garantizado con trigger porque la aplicación sí tiene UPDATE sobre documentos. Solo se corrigen por reverso.
- Asiento sellado y estado derivado (paso 5 del bloque 4): journal_entry y journal_entry_line existen solo al contabilizar y pyme_app solo los inserta, sin UPDATE ni DELETE. Un documento está contabilizado si tiene asiento; no hay columna de estado, así que no puede haber un documento contabilizado sin asiento. El contenido del borrador (líneas manuales, parámetros del concepto) vive en el documento y llega con la UI y el motor de conceptos. journal_entry guarda el periodo, accounting_date, el consecutivo (number y number_year, que el trigger deriva del periodo si el tipo reinicia cada año), accounting_framework, posted_at y el id de la transacción que lo creó. document_type_id lo copia un trigger desde el documento.
- El reverso es un documento propio con referencia al original y su propio consecutivo, no un estado del original. UNIQUE(company_id, reverses_document_id): un original se revierte una sola vez, y solo si está contabilizado.
- Dos identidades por documento: el consecutivo interno, asignado al contabilizar, y la identidad externa del soporte (NIT del emisor, prefijo, número, CUFE) con clave única por empresa, para no registrar dos veces la misma factura.
- Fechas: document_date decide regla y parámetros. accounting_date ubica el asiento. period_id es explícito, con CHECK de que accounting_date cae dentro del periodo, porque el periodo 13 comparte fecha con diciembre. posted_at es el timestamp UTC de contabilización. El año gravable se deriva de accounting_date. Si document_date y accounting_date pueden diferir está pendiente de la contadora.
- El asiento lleva columna de marco contable con un único valor por ahora, para no migrar el libro si se separa NIIF y fiscal.
- Cada línea de asiento lleva tercero, centro de costo, obra y municipio de ejecución, obligatorios u opcionales según los flags de la cuenta. Se diseñan desde el módulo 2 para no migrar el libro después.

### Capa fiscal y exógena

- La exógena no se deriva del código de la cuenta: una cuenta mezcla tratamientos fiscales (el formato 1001 separa el pago deducible del no deducible) y una operación toca varias cuentas.
- Detalle tributario en tax_line por documento, enlazado a la línea del asiento: tipo (pago, ingreso, retención, IVA), concepto fiscal, base, tarifa, valor, deducible, tercero, beneficiario y municipio. Lo genera la estrategia; en comprobantes manuales lo exige el flag requires_tax_detail. Es tabla aparte del libro: entra con el motor de conceptos sin migrar las líneas.
- fiscal_concept: catálogo global interno de conceptos fiscales.
- Mapeo concepto fiscal → formato, concepto DIAN y columna, por año gravable, global con override por empresa. El mapeo por cuenta queda solo como respaldo de comprobantes manuales y como control.
- La especificación de cada formato es un dato versionado por año gravable (jsonb), no una tabla por formato.
- reporting_run y reporting_record guardan totales, errores, valores reportados y referencias a los documentos y líneas de origen. Cada run concilia lo reportado contra el libro.
- Los valores reportados son enteros positivos (visto en un anexo antiguo del formato 1001, confirmar en el vigente). El redondeo se hace al generar y su diferencia queda en la conciliación.
- Si una partida es deducible lo define la contadora.
- Normativa de referencia: Resolución DIAN 000227 de 2025 (Única, título 3, información exógena) y sus modificaciones (000233 y 000237 de 2025, que aplican desde el año gravable 2026; 000012 y 000021 de 2026). Se verifica cada año gravable.

### Integridad en Postgres

- Partida doble con constraint triggers diferidos al commit. Un CHECK no sirve porque abarca varias filas. La regla está en una sola función, journal_entry_assert_balanced: al menos 2 líneas (un asiento sin líneas suma cero y pasaría el cuadre) y suma de débitos igual a suma de créditos, en numeric. La llaman dos triggers.
  - journal_entry_balance, sobre journal_entry, atrapa el asiento que nunca recibe líneas.
  - journal_entry_line_balance, sobre las líneas, valida una vez por asiento: solo cuando no hay una línea visible de id mayor (NOT EXISTS, no NEW.id = max(id), que con RLS ocultando las líneas da NULL y saltaba la validación). Atrapa una línea agregada después de SET CONSTRAINTS ALL IMMEDIATE. Antes revalida el sellado en cada línea, porque al commit todo lo confirmado es visible.
  - Sin marcas de set_config, por dos motivos: la aplicación puede fijar app.* y falsificarlas, y una marca es estado que puede filtrarse entre transacciones u olvidarse al revertir un savepoint.
  - La regla del id mayor depende de que los ids crezcan dentro del asiento. Eso se cumple porque el sellado deja todas sus líneas en una sola transacción y porque pyme_app no inserta id ni tiene UPDATE sobre la secuencia (no puede hacer setval). Ambas cosas las exige un test.
  - Una línea de otra transacción que se cuela en la carrera del sellado puede tener un id menor. Por eso la revalidación del sellado corre en cada línea y antes de la regla del id mayor.
  - Los triggers leen con RLS: si al commit falta el contexto, no ven líneas y rechazan, así que fallan cerrados.
- Sellado: las líneas solo entran en la transacción que creó el asiento. Lo controla un trigger BEFORE INSERT sobre las líneas, que compara journal_entry.created_txid (DEFAULT pg_current_xact_id(), sin INSERT para pyme_app) con pg_current_xact_id().
  - No compara con xmin: un asiento insertado dentro de un savepoint lleva en xmin el xid de la subtransacción, mientras que pg_current_xact_id() devuelve el de nivel superior.
  - Si falta el contexto o el asiento no se ve, deja responder a RLS (42501) o a la FK (23503).
  - El sellado es lo único que impide agregar un par cuadrado de líneas a un asiento ya confirmado.
  - En READ COMMITTED, el SELECT del BEFORE puede no ver un asiento que otra transacción confirma antes del chequeo de la FK de la misma sentencia. Por eso el trigger diferido de líneas revalida el sellado al commit. Un test lo simula apagando el BEFORE como dueño.
- Línea: CHECK de débito y crédito mayores o iguales a cero y exactamente uno mayor que cero, sin excepciones.
- Trigger sobre las líneas: cuenta imputable, activa y con las dimensiones que exigen sus flags.
- Trigger sobre el documento: periodo abierto, accounting_date dentro del periodo, tipo de documento permitido en el periodo 13. Es la red bajo los bloqueos de la acción Contabilizar.
- Bitácora de cambios de datos (maestros, borradores, parámetros) con trigger genérico: valor anterior y nuevo en jsonb, usuario tomado de set_config. Aprobaciones, cierres y reaperturas se registran como eventos explícitos en la misma tabla.
- audit_log: pyme_app solo SELECT. Escriben dos funciones SECURITY DEFINER de pyme_owner: audit_log_capture (trigger AFTER ROW en cada tabla de negocio, salvo excepciones declaradas en el test de catálogo; un UPDATE sin cambios no deja fila) y audit_log_event (eventos, EXECUTE solo para pyme_app). Sin app.user_id fallan con 42501. Políticas de INSERT y SELECT para pyme_owner atadas al contexto de empresa (SELECT porque INSERT ... RETURNING lo exige) y ninguna de UPDATE o DELETE: con FORCE ni el dueño modifica filas. La aplicación no puede fabricar filas de cambio de datos; sí puede registrar eventos y fijar el usuario, porque app.user_id lo pone ella. Encadenamiento por hash pendiente, con un job de sellado.
- Toda FK de una tabla de empresa tiene un índice cuyas primeras columnas son las de la FK (parcial WHERE col IS NOT NULL en las dimensiones opcionales de las líneas), exigido por catálogo. Solo cuentan índices btree, y un parcial solo si su predicado es col IS NOT NULL sobre una columna de la FK: el chequeo de RI podría usar otras formas que su igualdad implica, pero la regla acepta solo esa, conservadora a propósito. Excepciones declaradas: audit_log.user_id (un usuario con historia no se borra y el índice se pagaría en cada escritura de la bitácora) y journal_entry_line.municipality_id (solo pyme_owner borra o cambia un municipio, en migraciones, y el índice se pagaría en cada línea; un reporte de ICA por municipio traerá su propio índice). Toda FK hacia una tabla de empresa es compuesta con company_id; una FK simple solo apunta a una tabla global de una lista explícita en el test (companies, users, municipality).
- document, journal_entry y journal_entry_line no tienen FK directa a companies: sus FK compuestas (hacia document_type y document) ya implican la empresa. Es una verificación menos por fila. audit_log conserva su FK a companies, así que cada escritura de la bitácora sigue tomando FOR KEY SHARE sobre la fila de la empresa; es compatible consigo mismo y su costo (multixacts) se mide en el bloque 7.
- journal_entry y journal_entry_line no se auditan con audit_log_capture: son de solo inserción e inmutables, y auditarlos duplicaría cada línea. El evento de contabilización lo registra la acción con audit_log_event.
- Toda función y procedimiento de public o de pyme_owner, SECURITY DEFINER o no, con search_path fijo exactamente en pg_catalog, public, pg_temp: si pg_temp no aparece, Postgres busca primero en el esquema temporal de la sesión, y un rol con TEMP podría sombrear una tabla. Los triggers INVOKER también, porque corren con el search_path de quien llama y son la red de integridad. Las SECURITY DEFINER además sin EXECUTE para PUBLIC. pyme_app sin TEMP ni CREATE en las bases. Todo exigido por tests de catálogo. Toda función se crea con DROP FUNCTION IF EXISTS y CREATE FUNCTION, nunca OR REPLACE: migrate:fresh no borra funciones y OR REPLACE conserva el ACL anterior, lo que escondía un REVOKE faltante.

Dónde se hace cumplir cada regla:

| Regla | Base de datos | Dominio | Concurrencia |
|---|---|---|---|
| Partida doble | Constraint trigger diferido, mínimo 2 líneas | AsientoPropuesto | Se valida al commit |
| Periodo cerrado | Trigger en el documento | Contabilizar | FOR SHARE del periodo frente a FOR UPDATE del cierre |
| Inmutabilidad | Sin UPDATE ni DELETE, trigger en documentos | n/a | n/a |
| Reverso único | UNIQUE(company_id, reverses_document_id) | Acción de reverso | El UNIQUE resuelve la carrera |
| Aislamiento por empresa | RLS, FK compuestas | Global scope | n/a |
| Idempotencia | UNIQUE de identidad externa con company_id | Estado del documento | El UNIQUE resuelve la carrera |
| Consecutivos | Tabla de contadores | Contabilizar | FOR UPDATE; sin anulación después de numerar |
| Cuenta imputable y dimensiones | Trigger en las líneas | Mensaje amigable | Atributos congelados al tener movimientos |
| Conciliaciones | n/a | Job y alertas | n/a |
| Segregación de funciones | Roles de base de datos | Políticas (fase 3) | n/a |
| Auditoría | Triggers de bitácora | Eventos explícitos | n/a |

### Montos y fechas

- Montos en NUMERIC en Postgres. En PHP nunca se opera con float: el cast decimal de Eloquent devuelve string y toda aritmética va con brick/math. Cuando se pueda, los totales se calculan en SQL.
- Importes en NUMERIC(18,2). Tarifas y porcentajes con escala mayor, que se fija al modelar los parámetros.
- El redondeo lo aplica la estrategia en el cálculo, no la presentación. El asiento guarda el valor realmente practicado y la contrapartida absorbe la diferencia, así el asiento cuadra. Lo que viene de un soporte (factura electrónica) se registra tal cual. Los prorrateos asignan el residuo para que la suma sea exacta. Si se redondea por línea o por documento está pendiente.
- Reglas verificadas, a confirmar con la contadora: las declaraciones y los recibos de pago se aproximan al múltiplo de mil (ET, art. 577 y 802); las retenciones al peso (Decreto 2026 de 1983, art. 19, citado en el Concepto DIAN 65791 de 2013; confirmar su compilación en el DUR 1625 de 2016); el IVA cobrado puede aproximarse al múltiplo de 10 (DUR 1625 de 2016, art. 1.3.1.1.1, según el Oficio DIAN 3550 de 2026).
- Redondear cada línea de un estado financiero rompe los totales: línea de ajuste por redondeo o totales calculados aparte.
- La API envía montos como string. El frontend no hace aritmética contable con number de JavaScript: muestra y formatea, y la previsualización del asiento la calcula el backend.
- Fecha contable como DATE derivada en America/Bogota, separada del timestamp de registro en UTC.
- Laravel, PHP y Postgres corren en UTC. America/Bogota solo se aplica explícitamente en dominio, con el reloj inyectado, para derivar fechas contables, y en la presentación. Nunca se cambia app.timezone.
- Moneda única COP. Multimoneda fuera de alcance. Si aparece una operación en moneda extranjera, el documento guarda moneda original y TRM como datos y el libro solo registra COP (pendiente de la contadora).

### Consecutivos, periodos y saldos

- Consecutivo por empresa y tipo de documento, asignado al contabilizar con una tabla de contadores y bloqueo de fila en la misma transacción, nunca con secuencias de Postgres porque dejan huecos. Sin saltos es obligatorio. El reverso consume número. Reinicio anual y formato configurables. Un número asignado no se anula: se revierte.
- Periodos por empresa y año: 0 de apertura (saldos iniciales), 1 a 12 regulares y 13 de ajustes, con tipo y estado. UNIQUE(company_id, year, number). Si usan el 13 está pendiente; el modelo lo soporta sin cambios. El periodo 13 solo admite tipos de documento de ajuste y cierre.
- El cierre de las cuentas de resultado es un documento propio generado por el sistema, revertible por reverso al reabrir, que deja en cero las cuentas de resultado. Los reportes permiten excluir los documentos de cierre.
- Contabilizar toma FOR SHARE sobre la fila del periodo y cerrar toma FOR UPDATE. Orden fijo de bloqueo: periodo, luego contador.
- Sin tabla de saldos por todas las dimensiones. Los periodos abiertos se consultan desde las líneas con índice cubriente (company_id, account_id, third_party_id, date) INCLUDE (debit, credit). Al cerrar un periodo se guarda una foto de saldos que solo se recalcula al reabrir. Si la medición con datos sintéticos no alcanza la meta, se agregan saldos por cuenta y periodo.

### Idempotencia

- Contabilizar es idempotente por el estado del documento y la clave única por empresa y documento origen. La creación queda protegida por la identidad externa. La tabla genérica de claves de idempotencia en la API se pospone.
- Saldos iniciales y migración de histórico se diseñan desde el módulo 2, con tercero y dimensiones, porque la exógena del primer año los necesita.

### Errores y logs

- La API no filtra información interna: nunca envía clase, archivo, traza, SQL ni el mensaje interno de una excepción, sin importar APP_DEBUG. En desarrollo la traza se ve en los logs. Verificado en Laravel 13.38.0: con debug activo el JSON trae excepción, archivo, línea y traza; sin debug, una excepción HTTP pasa su mensaje tal cual (ModelNotFoundException revela clase e id).
- Contrato de error: Problem Details (RFC 9457, application/problem+json) con las extensiones code (estable, en inglés, el frontend decide por él), request_id y errors (validación por campo). detail es un texto seguro en español, desde lang/es. title en español por status. Sin type (equivale a about:blank). La aplicación corre en locale es con fallback en; lang/es/validation.php se escribe a mano con las reglas en uso hasta que haya formularios grandes (candidato: laravel-lang).
- El kind de un error de dominio (ErrorKind) lo declara el propio error y la capa de entrada lo traduce a status. errorCode y kind son constantes de la clase. Un test de catálogo exige mensaje en lang/es para todo DomainError concreto.
- Errores de dominio: excepción base con code() y contexto solo para logs. El dominio no conoce HTTP; la capa de entrada mapea cada error a status y mensaje. La infraestructura traduce los SQLSTATE esperados (UNIQUE de identidad externa, periodo cerrado) a errores de dominio. Lo inesperado responde 500 genérico con request_id.
- nginx con server_tokens off; PHP ya tiene expose_php Off.
- Logs en JSON a stderr (Monolog). El agregador y la herramienta de seguimiento de errores se eligen con el hosting.
- Correlación: request_id por request (o el de nginx), devuelto en X-Request-Id y cargado con Context de Laravel en la capa de entrada, que lo propaga a los jobs (verificado en vendor). Todo log lleva request_id, company_id y user_id, y el job cuando aplica.
- Niveles: un rechazo de negocio esperado es info o no se registra; un 500 es error con la excepción; los eventos de seguridad (403 de empresa, login fallido) son warning.
- Sin datos personales en los logs: ids, no nombres, NIT ni cuerpos de request. Un processor redacta claves sensibles. Una QueryException se registra sin valores: bindings enmascarados y mensaje de Postgres sin DETAIL. Residuo aceptado: el texto principal de algunos errores de Postgres trae el valor (por ejemplo invalid input syntax for type bigint: "abc"); la validación de entrada debe atraparlos antes. Trazas sin argumentos (zend.exception_ignore_args).
- Los logs son operativos y rotan. La evidencia legal es audit_log, no los logs.

## Multi-tenant

- Usuarios globales sin RLS. Membresías company_user con roles. Un usuario puede pertenecer a varias empresas.
- companies y company_user son globales, sin RLS: la empresa es el tenant y la membresía se consulta antes de que haya empresa activa. company_user tiene PK (company_id, user_id) y role con CHECK mínimo ('admin', 'accountant'), que la segregación de funciones de la fase 3 amplía. FK sin cascada: una empresa o un usuario con membresías no se borra en silencio. La aplicación no tiene DELETE sobre companies.
- Empresa activa en sesión. El request solo pide cambiar de empresa, el backend valida la membresía y la guarda en sesión. Todo lo demás toma company_id de la sesión, inyectado como contrato. Nunca es asignable en masa.
- company_id en todas las tablas de negocio, con DEFAULT current_setting('app.company_id')::bigint para que ningún código lo asigne, y políticas RLS con USING y WITH CHECK que fallan cerrado si no hay contexto.
- Doble barrera: RLS en Postgres y global scope de Eloquent como barrera de lectura. El scope es App\Infrastructure\Tenancy\CompanyScope, declarado con #[ScopedBy] en cada modelo de tabla de empresa: compara la columna company_id calificada con la tabla contra la misma expresión de las políticas, sin leer DatabaseCompanyContext ni la sesión de Laravel (sin consulta extra y falla cerrado igual). Lee el mismo setting que RLS: protege si RLS queda mal configurada o apagada, no contra un contexto equivocado. Cubre las consultas del builder (query, find, where, route binding), no las lecturas internas de Eloquent sin global scopes: fresh, refresh, increment y decrement, las claves de save y delete, y la restauración de modelos en jobs (SerializesModels). Ahí solo queda RLS. Por eso los jobs de negocio llevan ids, no modelos, y releen con una consulta con scope; las consultas con join seleccionan account.* (u otra tabla de empresa) para no hidratar el id de la tabla unida. Los modelos de empresa nunca tienen id ni company_id en fillable, y la base niega además el privilegio de columna. TenantModelCatalogTest lo exige por catálogo para todo modelo de app/Models cuya tabla tenga company_id y no sea global, y además que su fillable sea exactamente la lista de columnas con INSERT de pyme_app, para que el modelo y los GRANT no se desalineen. AccountModelTest prueba el scope con RLS apagado en una transacción del dueño. Sin trait ni clase base para el scope mientras sea una sola línea por modelo; se reabre cuando aparezca una segunda conducta común.
- Patrón de RLS de tabla de negocio (fijado primero con una tabla sonda de tests, borrada en el paso 3b del bloque 4; la tabla de referencia de TenantIsolationTest es account): company_id NOT NULL con DEFAULT nullif(current_setting('app.company_id', true), '')::bigint, ENABLE y FORCE ROW LEVEL SECURITY, y una política FOR ALL TO pyme_app con USING y WITH CHECK sobre esa misma expresión. Sin contexto la expresión es NULL: no se lee nada y el insert falla con 42501 (Postgres evalúa RLS antes que NOT NULL, que queda como segunda barrera). Con INSERT y UPDATE por columna, pyme_app no puede escribir company_id (42501 por permiso), así que el WITH CHECK solo es alcanzable sin contexto: lo fijan el test de catálogo de políticas exactas y el insert sin contexto, que exige el mensaje de RLS (con WITH CHECK (true) caería en NOT NULL, 23502). Queda como tercera barrera si algún día se concede UPDATE sobre company_id. Tests de catálogo exigen RLS, FORCE, política, NOT NULL y DEFAULT en toda tabla con company_id, salvo las globales declaradas.
- Con FORCE y la política solo para pyme_app, pyme_owner no ve ni escribe filas de negocio. Una migración de datos en el bloque 4 necesitará su propia política o fijar el contexto; se decide entonces.
- El contexto lo fija App\Infrastructure\Tenancy\DatabaseCompanyContext con set_config a nivel de sesión de la conexión: empresa en app.company_id y usuario en app.user_id, siempre los dos (enter(companyId, userId)). Quien llama a enter() llama a leave() en un finally. La bitácora toma el usuario de app.user_id y falla sin él, igual que RLS sin empresa.
- Dos usuarios de base de datos: el dueño de las tablas, solo para migraciones, y el de la aplicación, que no es dueño, no tiene BYPASSRLS ni UPDATE o DELETE sobre asientos, líneas y bitácora. Tablas con FORCE ROW LEVEL SECURITY.
- Las migraciones corren con la conexión pgsql_owner y la aplicación con pgsql (pyme_app). Cada migración da GRANT explícito a pyme_app, solo los privilegios que la tabla necesita. Nunca default privileges ni TRUNCATE, REFERENCES o TRIGGER para la aplicación. Si en una tabla solo cambian ciertas columnas (estado del periodo, número del contador), UPDATE va por columna en vez de un trigger de inmutabilidad, y AppPermissionsTest fija esas columnas. En las tablas de negocio INSERT también va por columna, sin id ni company_id: los dos salen de su DEFAULT, así que la aplicación no puede asignarlos y un id explícito no sirve de oráculo de ids de otras empresas (la PK es global). AppPermissionsTest lo exige por catálogo para toda tabla con company_id, salvo excepciones declaradas (hoy solo company_user, que es global).
- Propuesta para producción: solo el job de migraciones recibe la credencial del dueño. Los procesos web y worker no la tienen. En desarrollo, compose la pasa a php y worker por comodidad.
- Sanctum solo en modo SPA con cookie de sesión: sin tabla personal_access_tokens, y AppServiceProvider anula la lectura de tokens, así que un Bearer da 401. La API vive bajo /api/v1 con statefulApi().
- POST /api/v1/active-company valida la membresía (acción SwitchActiveCompany) y guarda active_company_id en la sesión. Sin membresía o con empresa inexistente responde el mismo 403. El middleware company.context (SetCompanyContext) responde 409 si no hay empresa activa, revalida la membresía en cada request (403 y borra la clave si se revocó) y fija el contexto con enter() y leave() en un finally.
- Contexto de empresa con set_config al inicio de cada request. En jobs, el company_id y el usuario que originó el job viajan en el payload y el contexto se fija y se limpia por job, porque el worker es un proceso largo. Si algún día hay pooler en modo transacción u Octane, el contexto pasa a ser transaccional.
- Jobs de negocio: declaran en middleware() el job middleware WithCompanyContext con el company_id y el user_id de su payload, que hace enter() y leave() en un finally. Red de seguridad: Queue::before llama a leave() antes de cada job, así un job nunca hereda el contexto de otro, ni siquiera si un proceso murió a mitad de un job.
- Catálogos globales de referencia (hoy municipality): sin company_id, sin RLS y sin auditoría, porque solo los escribe pyme_owner en migraciones y la traza es git. pyme_app tiene solo SELECT, sin privilegio sobre la secuencia: un catálogo global escribible dejaría a una empresa cambiar datos que ven todas. TenantCatalog::TABLES_WITHOUT_COMPANY_ID declara toda tabla de public sin company_id y TenantIsolationTest exige que coincida exactamente, así que una tabla de negocio que olvide company_id falla en vez de quedar global en silencio. La siembra va en la migración que crea la tabla (dato que toda base necesita, también pyme_test) y DatabaseTruncationAsOwner la excluye del truncado.
- municipality se siembra desde el dataset oficial "DIVIPOLA - Códigos municipios" de DANE en datos.gov.co (gdxc-w37w), corte 30 de diciembre de 2024, descargado el 2026-10-25 y versionado sin modificar en backend/database/data/divipola.csv (licencia CC BY-SA 4.0, atribución DANE). 1.122 filas: municipios, áreas no municipalizadas e isla. Se cargan código, nombre y departamento; tipo y coordenadas no, por falta de consumidor. Una actualización de DIVIPOLA es otra migración con upsert por código. Ningún código DANE se escribe a mano, tampoco en tests.
- Claves únicas y FK compuestas con company_id. Toda UNIQUE o índice único de una tabla de empresa, salvo la PK, incluye company_id, exigido por catálogo en TenantIsolationTest: sin él, un 23505 revelaría que el valor existe en otra empresa.
- Pruebas automáticas de fuga entre empresas en la API y en los jobs.

## Requisitos no funcionales (metas iniciales, ajustables con la contadora y el cliente)

### Integridad y auditoría

- Ningún asiento contabilizado descuadrado puede persistir.
- Bitácora append-only de datos maestros, borradores, parámetros, aprobaciones, cierres y reaperturas, con usuario, fecha, valor anterior y nuevo. El usuario de la aplicación no puede modificarla. Encadenamiento por hash deseable.
- Cierre mensual y anual separados. La reapertura exige permiso especial, motivo y traza.
- Integraciones externas (factura electrónica, WhatsApp) con outbox y reintentos idempotentes.

### Cumplimiento

- Parámetros fiscales (UVT, tarifas, bases mínimas, ICA por municipio, retenciones) en tablas con vigencia, actualizables sin desplegar. Activar una versión de parámetros exige aprobación y la suite golden en verde.
- Libros y soportes se conservan mínimo 10 años (reportado como Ley 962 de 2005, art. 28, confirmar con la contadora) y se pueden exportar en formato abierto aunque termine la relación con el cliente.
- Libros oficiales generables en el formato que pida la contadora.
- El soporte válido de una compra es la factura electrónica validada por la DIAN o el documento soporte electrónico. La foto de WhatsApp es solo insumo de captura.
- Datos de terceros: lo que la contabilidad debe conservar (identificación, nombre, operaciones) se conserva el plazo legal. Los datos de contacto van separados y se pueden suprimir. Los backups tienen un plazo de retención definido. Habeas Data (Ley 1581 de 2012) se revisa con un abogado.
- Exógena en fase posterior. El diseño está en "Capa fiscal y exógena" y el modelo ya lo soporta desde el libro.

### Seguridad

- Roles con segregación de funciones configurable por empresa. Si una persona acumula roles, control compensatorio con revisión posterior y alerta.
- MFA para roles administrativos y de contadora. Cifrado en tránsito y en reposo. Secretos fuera del código, claves con KMS y rotación.
- SPA con cookies de sesión de Sanctum y protección CSRF. SPA y API en el mismo origen detrás de un único nginx (/ para la SPA, /api y /sanctum para Laravel), por lo que no hay CORS. Si algún día se separan, CORS restringido al dominio del frontend. Nada de tokens en localStorage.
- Las rutas de Fortify van bajo prefijo (por ejemplo /api/auth) para no chocar con rutas de la SPA.
- Auditoría de accesos y de exportaciones.

### Disponibilidad y recuperación (las decide el cliente, estas son propuestas)

- RPO 15 min con PITR, RTO 4 h, disponibilidad 99,5% en horario laboral por definir, con ventana de mantenimiento definida.
- Postgres administrado, backups cifrados, restauración probada periódicamente con runbook escrito.

### Rendimiento

- Supuesto: 1 millón de líneas de asiento por empresa, por convertir en años de operación.
- Balance de prueba y auxiliares por tercero o centro de costo en menos de 3 s, contabilizar un documento en menos de 1 s. Medido con datos sintéticos, sobre todo el auxiliar por rango de fechas. La medición decide si hace falta tabla de saldos. Listados paginados en la API. Vigilar contención en contadores.

### Mantenibilidad y observabilidad

- Suite golden validada por la contadora. Migraciones siempre hacia adelante (expand y contract). Excepción hasta el primer despliegue: las migraciones se editan en sitio y la base local se rehace con migrate:fresh; desde el primer despliegue, solo hacia adelante. migrate:fresh no borra funciones: si una edición en sitio renombra o quita una función, la vieja se borra a mano en pyme y pyme_test (los tests de catálogo de funciones fallan con ella). API versionada con contrato OpenAPI generado desde el código y tipos de TypeScript generados a partir de él.
- Logs estructurados con company_id y correlación por documento. Alertas de asientos rechazados, cierres fallidos y diferencias entre la foto de saldos de cierre y las líneas.

### Usabilidad

- Importar y exportar Excel, PDF de reportes, exportación para exógena.

## Trade-offs

Los requisitos no funcionales fijan metas. Esta sección registra qué se pagó por cada decisión estructural y qué señal obliga a reabrirla. Una decisión nueva con costo real entra aquí con las mismas columnas.

### Prioridad cuando dos objetivos chocan

1. Integridad contable: las invariantes de CLAUDE.md.
2. Aislamiento entre empresas y seguridad.
3. Cumplimiento y conservación.
4. Rendimiento dentro de las metas.
5. Simplicidad y costo de operación.
6. Comodidad de uso.

Un objetivo de menor prioridad nunca relaja uno de mayor. Si una meta de rendimiento no se alcanza sin tocar una invariante, se revisa la meta con el cliente, no la invariante. Los agentes revisores usan este orden para desempatar.

### Decisiones y su costo

| Decisión | Ganamos | Pagamos | Se revisa si |
|---|---|---|---|
| Contador con bloqueo de fila | Consecutivos sin huecos | Contabilizar se serializa por empresa y tipo de documento | La medición supera 1 s por contención en el contador |
| Sin tabla de saldos | Una sola fuente, sin invalidación ni conciliación interna | Los reportes leen las líneas | Auxiliar o balance tardan más de 3 s con 1 millón de líneas |
| Invariantes en triggers y en dominio | La red no depende del código de la aplicación | Doble mantenimiento, SQLSTATE que traducir, cargas masivas más lentas | Importación, saldos iniciales o cierre no caben en una ventana razonable |
| Contexto con set_config de sesión | Simple y correcto con PHP-FPM sin conexiones persistentes | Impide pooler en modo transacción y Octane | El hosting o la carga exigen pooler |
| Bitácora por trigger con SECURITY DEFINER | Evidencia que la aplicación no puede fabricar | Más escrituras por cada cambio, audit_log crece sin límite | Latencia de escritura o tamaño de audit_log |
| Estrategias en código, sin DSL | Testeables, versionadas, suite golden | Cada cambio de fórmula es un despliegue y depende del desarrollador | La contadora pide cambios de fórmula con frecuencia, no solo de parámetros |
| Cuentas y terceros por empresa | Aislamiento, nadie gobierna datos compartidos | Duplicación entre empresas de la misma contadora, sin consolidación | La contadora necesita consolidar o reutilizar terceros entre empresas |
| Moneda única COP | Libro simple | Operaciones en moneda extranjera solo como dato del documento | La contadora confirma operaciones en divisas con diferencia en cambio |
| Sin tabla genérica de idempotencia en la API | Menos piezas | Un reintento sin identidad externa puede crear un borrador duplicado | Llegan integraciones (factura electrónica, WhatsApp) |
| Conservación legal con contacto suprimible aparte | Cumplir conservación y Habeas Data a la vez | Dos regímenes de datos por tercero; la supresión no alcanza los backups hasta que vencen | Concepto del abogado |
| Segregación configurable con control compensatorio | Usable cuando una sola persona lleva todo | El control es posterior, no preventivo | Crece el equipo del cliente o lo exige una auditoría |
| Avanzar sin la contadora en lo que no depende de ella | No bloquear el desarrollo | Retrabajo si sus respuestas cambian el modelo | Cada respuesta de docs/preguntas-contadora.md |
| Desarrollador único y monolito modular | Velocidad y coherencia | Si el desarrollador para, el proyecto para; mitigado con docs/ y exportación en formato abierto | Entra un segundo desarrollador |
| Revisores con Opus solo por disparadores | Revisión profunda donde el riesgo es alto, costo acotado | Los pasos sin disparador no tienen revisión especializada | Un hallazgo se escapa en un paso sin revisor |
| Hook de roles para subagentes (.claude/hooks/role_guard.py) | Los errores de tester y coder se frenan antes de ejecutarse; los revisores quedan en una lista permitida de comandos | Es heurístico. Los subagentes corren con el usuario de Rubén en WSL: el hook cierra las vías directas (Write, Edit y los comandos que reconoce) y no cubre intérpretes (python3 -c) ni docker, donde cualquier agente es root en los contenedores. Para tester y coder es baranda, no barrera; solo para los revisores es barrera, por lista permitida. Las mitigaciones reales están fuera del hook: main protegida sin bypass, la llave SSH con passphrase y los push hechos por Rubén. Mantener listas y tests, y falsos positivos que obligan a pedirle al arquitecto | Los falsos positivos frenan pasos con frecuencia. G2 (scripts/gate.py) ya cubre los tests por hashes |
| Gate de tests por hashes y JUnit (scripts/gate.py) | Rojo antes del código y tests intactos hasta el verde, comprobados sin LLM; los ids exactos detectan datasets que cambian por código de producción | El estado vive en .gate/red.json, que el coder podría reescribir con python3 -c: atrapa el atajo, no a un agente adversario. Las factories (backend/database/factories) son entradas de los tests y quedan fuera de los hashes y del lado del coder, porque una columna NOT NULL nueva rompería en red tests de archivos que no cambiaron: un estado por defecto o un afterCreating que haga verdadera la precondición de un test solo se ve leyendo el diff. Una corrida completa de ambas suites en red y otra en green | Un agente reescribe el estado, o la suite tarda tanto que red y green frenan el ciclo. Alternativa anotada: un commit rojo en la rama verificado por CI, infalsificable pero choca con "commit solo en verde" |
| Paquetes apt sin versión en la imagen PHP (hadolint DL3008 ignorado con motivo) | Builds que no se rompen cuando bookworm retira versiones superadas, y los parches de seguridad entran al reconstruir | Dos builds en fechas distintas pueden traer paquetes distintos; la reproducibilidad depende de fijar la imagen base por digest (pendiente) | Un build falla o cambia de comportamiento por un paquete del sistema |

## UI de validación (React)

- Desde el inicio, para que la contadora vea el avance y valide: selector de empresa activa, plan de cuentas, registro de un concepto con previsualización del asiento antes de contabilizar, libro auxiliar, balance de prueba y casos golden con aprobar o rechazar y comentario, que queda en la bitácora con fecha y usuario.
- Entorno de pruebas con datos de ejemplo y rol de contadora.
- Diseño funcional, no pulido, sobre una librería de componentes existente. Tablas con filtros por periodo, tercero, centro de costo y obra.
- Los componentes no contienen reglas de negocio. Validaciones de formulario solo de forma. Las validaciones contables las responde la API.

## Tests

- Tests primero sobre las invariantes: cuadre, inmutabilidad, aislamiento entre empresas, consecutivos bajo concurrencia, cierre contra contabilización, permisos de los usuarios de base de datos.
- Tests unitarios del dominio y de las estrategias con dobles de los contratos inyectados (reloj, repositorios, contexto de empresa), sin base de datos ni facades.
- Las invariantes que viven en Postgres (trigger de cuadre, inmutabilidad, RLS, permisos, consecutivos) se prueban con integración contra la base real, porque un mock no las prueba.
- Cuadre dentro de RefreshDatabase: se inserta normal y al final se ejecuta SET CONSTRAINTS ALL IMMEDIATE, que dispara en ese momento los chequeos diferidos pendientes. Nunca al inicio del test, porque haría fallar asientos válidos insertados en varias sentencias.
- Concurrencia (consecutivos, cierre contra contabilización) con DatabaseTruncation y conexiones separadas que hacen commit real.
- Si la sentencia de B tiene que seguir esperando mientras A hace commit (por ejemplo, para ver que B bloquea antes de decidir), B corre en un proceso PHP aparte y la espera se confirma con pg_blocking_pids, porque PDO es síncrono y en el mismo proceso B solo puede rendirse por lock_timeout y reintentar.
- Mientras la contadora no entregue el catálogo, los tests usan un catálogo ficticio mínimo que no pretende ser el PUC.
- Los casos golden viven como archivos de datos versionados (entrada, asiento esperado, estado de aprobación) que la UI muestra a la contadora.
- Frontend: tests de componentes de los flujos de validación y de la previsualización del asiento.
- Todo test que protege una invariante se ve fallar al menos una vez (rompiendo la condición a propósito) antes de darlo por bueno.

## Ejemplos (cifras ficticias)

- Retención de ICA a 10 por mil sobre un pago de 10.000.000: débito costo de obra 10.000.000, crédito ICA retenido por pagar 100.000, crédito cuentas por pagar proveedor 9.900.000.
- Acta AIU con costo directo de 100.000.000, A 10%, I 5%, U 5% e IVA 19% solo sobre la U: débito cuentas por cobrar 120.950.000, crédito ingresos por contratos 120.000.000, crédito IVA generado 950.000. Faltan las retenciones que le practica el cliente.
- Acta de subcontratista de 50.000.000 con amortización de anticipo de 10.000.000 y retegarantía de 5%: si la base se toma antes o después de amortizar es pregunta abierta.