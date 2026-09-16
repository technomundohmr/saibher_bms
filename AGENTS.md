# AGENTS.md — Contexto del proyecto Saibher BMS

Repositorio del proyecto **Saibher BMS** (sistema B2B de gestión y POS):
Drupal 11 headless, tema `web/themes/saibher_bms`. Entorno local con DDEV.

> Este archivo solo apunta a las fuentes de verdad; **no duplica su contenido**.
> Antes de trabajar, leer lo indicado abajo.

## Lectura obligatoria (fuentes de verdad en el repo)

- `CONTEXTO_PROYECTO.md` — **contexto permanente y fuente primaria**: reglas de
  trabajo, fuentes de verdad, producto, arquitectura, sistema visual
  (§ Sistema visual) y criterios de implementación.
- `design-code.html` — referencia visual principal. Tiene prioridad para
  replicar una interfaz concreta.
- `brief.md` — requisitos funcionales y técnicos del POS.
- `README.md`, `CHANGELOG.md` — operación y cambios del proyecto.
- `CONTEXTO_SESION.md` — traspaso de contexto de la sesión de edición/categorías:
  qué se hizo, peculiaridades del entorno (p. ej. `config:import` roto y su
  workaround) y pendientes. Leerlo antes de trabajar si el contexto lo requiere.

## Fuentes externas referenciadas

- `/home/andres/Descargas/CONTEXTO_PROYECTO.md`
- `/home/andres/Descargas/prompt-estrategia-marketing-saibher.md`
- `/home/andres/Descargas/saibher-brief-marketing-digital.md`
- `/home/andres/Descargas/saibher-identidad-de-marca.md`
- `/home/andres/Descargas/saibher-design-system/`
- `https://saibher-flow.lovable.app/dashboard`

Ante diferencias: `design-code.html` manda para replicar interfaz; la identidad
de marca manda para comunicación, copy y decisiones de marca.

## Reglas de trabajo (resumen; detalle en `CONTEXTO_PROYECTO.md`)

- Ejecutar directamente las tareas solicitadas; preguntar solo si Andrés lo pide
  o si una decisión puede causar daño irreversible, pérdida de datos o un cambio
  fuera del alcance.
- Usar siempre `ddev drush` (nunca `vendor/bin/drush`).
- Revisar convenciones existentes y reutilizar configuración, componentes y
  helpers antes de crear variantes.
- Cambios precisos y compatibles con Drupal 11; propagarlos a todas las
  superficies afectadas: configuración, rutas, menús, plantillas, CSS y
  documentación.
- Mantener el tono, la identidad visual y los patrones de interacción de
  Saibher; interfaz en español y no técnica.
- No inventar credenciales, métricas, clientes, precios ni casos de éxito.
- No introducir dependencias nuevas sin necesidad; no ejecutar comandos
  destructivos ni revertir cambios del usuario.

## Estilos e interfaz

El **sistema visual completo** (paleta, tipografía, layout y componentes) está
en `CONTEXTO_PROYECTO.md` (§ Sistema visual) y en `design-code.html`. Seguir
esos documentos y reutilizar los patrones existentes en lugar de crear
variantes. No detallar aquí la paleta ni la tipografía: consultar la fuente.

## Comandos frecuentes

- `ddev drush cr` — reconstruir caché.
- `ddev drush cim -y` — importar configuración (roto en este proyecto; ver
  workaround en `CONTEXTO_SESION.md`).
- `ddev drush status` — estado del entorno.
