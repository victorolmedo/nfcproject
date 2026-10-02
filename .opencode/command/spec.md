---
description: Crea o actualiza una especificacion de comportamiento del dominio de fidelizacion.
agent: plan
---

Escribe una especificacion para este sistema de fidelizacion y dejala en
`specs/<dominio>.md`.

Dominios ya cubiertos: `ledger`, `earn`, `redeem`, `inventory`, `rewards`,
`tenant-isolation`, `auth`, `pos-api`.

Si `specs/$ARGUMENTS.md` ya existe, **actualizala** en vez de crear otra. Antes de
reescribir, lee la version actual y preserva lo que sigue siendo cierto.

## Que se documenta

Comportamiento observable y sus motivos. Especifica lo que el sistema **hace** y
**por que**, no como esta escrito. Si el codigo y la spec discrepan, el codigo
manda salvo que se este cambiando a proposito: en ese caso, la spec describe el
comportamiento nuevo y el trabajo pendiente queda marcado.

## Reglas

Todo el contenido en espanol y sin acentos. El repositorio sigue esa convencion y
mezclar estilos hace que las specs dejen de leerse como parte del proyecto.

Cada regla que se escriba debe decir **por que importa**. Una regla sin motivo no
explica nada en seis meses y se vuelve a romper igual. Si el motivo es que algo
**ya fallo**, decilo: hay varias aqui, y son las que nadie va a volver a
inventar por intuicion.

Un ejemplo concreto del tono esperado:

> La sesion de PostgreSQL debe estar en UTC. Laravel escribe las columnas
> `timestampTz` sin offset, asi que si la sesion no esta en UTC, PostgreSQL
> interpreta el valor en la zona del servidor y guarda cada instante adelantado.
> Paso: todo quedo 3 horas en futuro y el tope diario se dealineo.

No hace falta escribir "se recomienda" ni "idealmente". Si algo es opcional, no
es una regla: no lo escribas.

## Estructura

```markdown
# <Dominio>

Una frase que diga de que se ocupa.

## Comportamiento

Las reglas, con su motivo. Ejemplos de valores concretos, no descripciones vagas.

## Invariantes

Lo que siempre es cierto, independiente de la operacion. FormatoUtil para lo que
un test debe comprobar tras cualquier operacion de este dominio.

## Fuera de alcance

Lo que este dominio NO hace, para que nadie lo busque aqui ni lo duplique.
```

## Al terminar

Si creaste o cambiaste la spec:

1. Verifica que el comportamiento descrito sigue siendo cierto. Para lo que
   concerne a contabilidad, ejecuta `php artisan test`.
2. Si la spec documenta algo pendiente, marcalo tambien en la seccion
   **Pendientes conocidos** de `AGENTS.md`, si no esta ahi.

Si no cambiaste nada, dilo. No reescribas una spec para que parezca trabajo nuevo.