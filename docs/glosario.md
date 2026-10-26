# Glosario

El código va en inglés (ver [CLAUDE.md](../CLAUDE.md)). Esta tabla fija el nombre en código de cada término del dominio. Es una convención de nombres, no normativa contable. Las siglas sin traducción (PUC, NIT, DIAN) se quedan igual.

Los términos marcados "revisar" se confirman cuando se modelen.

| Español | Código | Nota |
|---|---|---|
| empresa | company | |
| membresía empresa-usuario | company_user | |
| documento | document | Borrador si no tiene asiento, contabilizado si lo tiene. |
| asiento | journal_entry | |
| línea de asiento | journal_entry_line | |
| cuenta (PUC) | account | |
| débito | debit | |
| crédito | credit | |
| naturaleza de la cuenta | normal_balance | Enum NormalBalance (debit, credit). |
| tercero | third_party | |
| centro de costo | cost_center | |
| tipo de documento de identidad | id_type | Código DIAN de dos dígitos (31 es NIT). |
| número de identificación | id_number | Sin DV. |
| tipo de persona | person_type | Enum PersonType (natural, legal). |
| razón social | business_name | |
| obra | construction_project | |
| municipio | municipality | |
| concepto | concept | revisar |
| consecutivo | document_number | revisar. La tabla de contadores es document_counter. |
| contabilizar | post | |
| reverso | reversal | |
| periodo | period | |
| cierre de periodo | period_close | |
| bitácora | audit_log | |
| fecha contable | accounting_date | |
| fecha del documento | document_date | |
| marco contable | accounting_framework | |
