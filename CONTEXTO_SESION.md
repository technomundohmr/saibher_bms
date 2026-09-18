# CONTEXTO_SESION.md — Traspaso de contexto de la sesión de edición y categorías

> Este documento es la transferencia del contexto de la sesión de opencode que se
> ejecutó por error en el directorio `saibher_bms_views` (hoy casi vacío: solo
> `tui.json` + `.playwright-mcp/`). El proyecto real siempre fue
> `saibher-bms/`. Aquí se guarda lo que no debía perderse: decisiones, qué se
> hizo, peculiaridades del entorno y qué falta. Para el contexto permanente y el
> sistema visual, ver `CONTEXTO_PROYECTO.md` y `AGENTS.md`.

## Estado general

El trabajo de esa sesión **ya está aplicado y verificado** en este repo
(commits pendientes, ver `git status`). El menú lateral ya no tiene "Inicio" y
el ítem "Categorías" funciona igual que Inventario pero sobre la taxonomía
`category`. La modal de edición de nodos/términos está precargada, guarda el
mismo elemento (sin duplicados), y los campos de estado/metadatos se retiraron
para `product`, `customer` y `supplier`.

## Entorno y URL

- Proyecto real: `/home/andres/saibher/saibher-bms/` (DDEV).
  `saibher_bms_views` está vacío y NO se usa.
- URL local: `https://saibher-bms.ddev.site:8443`.
- Login: generar link fresco con `ddev drush uli` (expira).
- Comandos: siempre `ddev drush` (nunca `vendor/bin/drush`). Añadir rutas,
  menús o views nuevas exige `ddev drush cr`.

## Navegador (solo para depurar con el MCP de opencode)

- Playwright de opencode usa Chrome sin sudo vía wrapper:
  `/home/andres/.local/share/google-chrome/chrome-nosandbox`
  (Chrome 152 extraído del `.deb`; `--no-sandbox` porque el sandbox SUID no es
  root:4755). Configurado en `~/.config/opencode/opencode.jsonc` con
  `--executable-path` apuntando al wrapper.
- Los uploads de imagen del MCP solo aceptan rutas bajo
  `saibher_bms_views/` (el "." de trabajo del MCP), p.ej.
  `saibher_bms_views/.playwright-mcp/test-img.png`.
- Los clics sobre botones "Editar" de fila fallan por hit-testing (header
  sticky `th.saibher-table-actions-header` intercepta): usar
  `el.click()` vía `evaluate`.
- Cuidado con el selector `#saibher-modal form`: matchea el form de CREAR de
  la página, no el de edición. Para edición usar
  `#saibher-modal [data-saibher-modal-edit] form`.

## Peculiaridades del proyecto (¡importantes!)

- **`drush config:import` siempre falla en validación** en este proyecto:
  `config/` contiene cientos de configs con `dependencies.module: core`
  (artefacto de la gestión custom; p.ej.
  `field.storage.taxonomy_term.field_percentage`). NO usar `cim` para importar
  configs nuevas.
  Workaround verificado: escribir en el storage activo vía
  `\Drupal::service('config.storage')->write($name, $data)` +
  `configFactory()->reset()` + `\Drupal::cache('config')->deleteAll()` +
  `ddev drush cr`. Los nuevos configs se copian también a `config/`.
- `config_sync_directory` = `../config` → el dir gestionado `config/` ES la
  fuente de sync real (no `web/sites/default/files/sync`).
- La DB activa tenía `core.entity_form_display.taxonomy_term.category.default`
  con `dependencies.module` como STRING `'media_library'`; se reescribió desde
  `config/`. Mantener ese fix (ahora es array).
- `ddev drush php:eval` sufre escaping con comillas/`$var`: usar scripts con
  `ddev drush php:script web/script.php` (copiar a `web/`, borrar tras usar)
  o `ddev drush php:script /var/www/html/web/<script>.php`.
- El menú lateral = bloque `system_menu_block:main`; los ítems vienen de
  plugins de enlace (`saibher_bms_menu.links.menu.yml` + `standard.front_page`),
  NO de `menu_link_content` (tablas vacías).
- "Inicio" del menú lateral era el plugin `standard.front_page` (se quitó vía
  `saibher_bms_core_menu_links_discovered_alter`). "Dashboard" =
  `saibher_bms_menu.dashboard` (`/node`, no tocar). El enlace del logo
  (aria-label "Inicio", branding) y el breadcrumb NO se tocan.
- Columnas Views correctas para términos: `parent_target_id` (tabla
  `taxonomy_term__parent`) y `description__value` (tabla
  `taxonomy_term_field_data`). Usar `field: parent`/`description` produce
  handler `Broken`.
- En Drupal 11 `Drupal.Ajax.prototype.success` es asíncrono
  (`core/misc/ajax.js`: `commandExecutionQueue` → Promise). Cualquier override
  debe hacer `await originalSuccess.call(this, response, status)`.
- Solo `product` tiene campo de imagen (`field_image`); clientes y proveedores
  no tienen campo de imagen.

## Lo hecho en esa sesión (resumen)

### Round 1 — Edición de contenido vía modal (inventario, clientes, proveedores, facturación)
- Nueva ruta `saibher_bms_core.node_edit_form` (`/bms/nodo/{node}/editar`,
  `_access: 'TRUE'`, parámetro `entity:node`).
- `NodeFormController.php` (nuevo): sirve el form de edición vía
  `AjaxResponse` + `ReplaceCommand('#saibher-modal [data-saibher-modal-edit]', ...)`.
- Acción "Editar" en cada fila de las tablas (preprocess) con fallback a
  `entity.node.edit_form` + `data-dialog-edit`/`data-dialog-title`.
- Permisos `edit saibher <área>` declarados en `permissions.yml` pero NO
  forzados (`_access: 'TRUE'`) — requisito explícito del proyecto.
- `admin-pages.js`: fix `async` + `await originalSuccess` (causa de la "modal
  vacía") y `Drupal.attachBehaviors` sobre el form inyectado por AJAX.
- `NodeFormController.php:33-41`: retirada de `$form['advanced']`/`$form['meta']`
  (barra "Estado / Guardado por última vez / Autor", pestañas verticales) para
  `product`, `customer`, `supplier`. Facturación y movimientos quedan intactos
  (movimientos es read-only por diseño).
- Verificado: guardado actualiza el nodo existente; subida de imagen funciona
  en crear y editar.

### Round 2 — Menú: quitar "Inicio", crear "Categorías" (taxonomía `category`)
- `saibher_bms_core_menu_links_discovered_alter`: unset `standard.front_page`.
- `saibher_bms_menu.links.menu.yml`: ítem `saibher_bms_menu.categories` (route
  `saibher_bms_core.categories`, menu main, weight 25); icono `category` en
  `menu--main.html.twig`.
- Rutas `saibher_bms_core.categories` (`/bms/categorias`) y
  `saibher_bms_core.term_edit_form` (`/bms/termino/{taxonomy_term}/editar`,
  `entity:taxonomy_term`).
- `CategoriesController.php` (nuevo) y `TaxonomyTermFormController.php` (nuevo,
  edit ajax, bundle `category`, `unset($form['revision'])`).
- `OperationalPageControllerBase::buildOperationalPage`: nuevo parámetro
  `?string $modalVocabulary` para el form de término como modal principal.
- Preprocess de tablas: whitelist `saibher_categories`, rama `taxonomy_term`
  (ver/editar/eliminar + modal de edición).
- `saibher_bms_core_category_redirect_submit`: redirect por referer
  (inventory/categories; fallback inventory). Verificado en create y edit:
  permanece en `/bms/categorias`, sin duplicados (mismo tid).
- Bug de duplicados en edición resuelto: el form inyectado por AJAX no fijaba
  `#action`; Drupal lo reemplazaba con `window.location` → el POST iba a la URL
  de la página y creaba duplicados. Fix: fijar `#action` correcta en los
  controladores (nodo y término). En términos además hubo que añadir `#submit`
  tras `TermForm::save` para no caer en el canonical.
- View `saibher_categories` (UUID `4c5f6f54-c978-495a-9aad-5effbdcec60c`):
  YAML en `config/views.view.saibher_categories.yml` y copia en activo.
  Columnas: name (Categoría), parent_target_id (Categoría padre),
  description__value (Descripción), acciones; filtro vid=category; filtro
  expuesto name; sort weight+name; pager 25; table sticky.

## Pendientes / limpieza

- La sesión terminó verificada y limpia: términos de prueba borrados (se
  conservan 1 "Bebidas de prueba" y 2 "Aseo de prueba"), `tmp-cfg/` eliminado,
  consola sin warnings.
- Quedan cambios SIN commit en el repo (ver `git status`), incluidos los
  archivos nuevos de Round 1 y Round 2 y `AGENTS.md`. No se hizo commit porque
  no se pidió.

## Round 3 — Ficha de producto (`/node/3`) — COMPLETADO

- **Causa raíz de la ficha vacía**: `saibher_bms_core_preprocess_node()`
  importaba `Drupal\Core\Entity\NodeInterface` (inexistente) → `instanceof`
  siempre `false` → early return. Fix: `use Drupal\node\NodeInterface;`.
- Media de `field_image` es bundle **`file`**: `image_url` usa
  `$media->getFileUri()` directo.
- Descripción es `Markup` de `check_markup` (trae `<p>` interno): la plantilla
  la envuelve en `<div class="sb-product__desc">`; CSS
  `.sb-product__desc p { margin: 0; }`.
- h1 duplicado: `saibher_bms_core_preprocess_html()` añade class de body
  `sb-ficha-producto`; `node-product.css` oculta el bloque
  `block-saibher-bms-page-title` (`main.main-content > div > div:first-child`).
- Verificado `/node/3`: header (h1 + "SKU BEB-500-DEMO · Bebidas de prueba"),
  badges "activo"/"Stock saludable", KPIs
  `48 | 12 | 0 | $1.800 | $3.500 | $1.700 (49%)`, tabla movimientos
  (`02/09/2026 | Entrada | 18 | OC-DEMO-001 | Anónimo`). Sin supplier-card
  (no hay campo proveedor); debug eliminado.

## Round 4 — Categorías: buscador + nombre plano — COMPLETADO

- "Ver" eliminada de las acciones de fila SOLO para la vista `saibher_categories`
  en `saibher_bms_core_preprocess_views_view_table()` (Inventario conserva Ver).
- Nombre de fila ya no es enlace: `config/views.view.saibher_categories.yml`
  `name.settings.link_to_entity: false`. OJO: `ddev drush config:set` NO
  persiste en este proyecto; usar `configFactory()->getEditable(...)->save()`
  (script `php:script`). Se dejó `system.site.name` intacto ("Saibher bms").
- Filtro expuesto de categorías estilado vía `saibher_bms_core_form_alter()`
  (form id `views_exposed_form` + `#action` contiene `bms/categorias`):
  placeholder "Buscar por nombre", botón `saibher-btn saibher-btn--primary`.

## Round 5 — Inventario: filtros expuestos — COMPLETADO

- `config/views.view.saibher_inventory.yml`: filtros expuestos
  - `nombre` (title, `string`, `contains`) — placeholder "Nombre".
  - `codigo` (`node__field_sku.field_sku_value`, `string`, `operator: '='`) —
    placeholder "Código", coincidencia EXACTA.
  - `categoria` (`node__field_category.field_category_target_id`,
    `plugin_id: taxonomy_index_tid`, `operator: or`, `type: select`,
    `limit: TRUE`, `vid: category`) — select único; opción vacía "Categoría";
    `limit: true` restringe opciones al vocabulario (sin él listaba TODAS las
    vocabularios).
  - OJO plugeos: `node__field_X.field_X` da handler "broken"; usar la columna
    real (`field_sku_value`, `field_category_target_id`). Para entity-ref de
    taxonomía el plugin correcto es `taxonomy_index_tid` (no `entity_reference`).
  - Cada filtro necesita `exposed: true` a nivel superior (el filtro `title`
    viejo NO lo tenía → por eso nunca se mostró el form en inventario).
- `form_alter` (mismo form id `views_exposed_form`; `#action` contiene
  `bms/inventario`): `nombre`/`codigo` → `sb-input` + placeholder + título
  invisible; `categoria` → `sb-select` envuelto en `.sb-select-wrap` (flecha) +
  opción `All` → "Categoría"; checkbox custom `bajo_stock` (`sb-checkbox`,
  `#default_value` desde query); submit `#value` = "Buscar" +
  `saibher-btn saibher-btn--primary`.
- `saibher_bms_core_views_query_alter()`: si `?bajo_stock=1` añade
  `ensureTable('node__field_stock')` / `ensureTable('node__field_min_stock')`
  y `addWhereExpression(0, "{alias}.field_stock_value < {alias}.field_min_stock_value")`.
  ⚠️ `ensureTable()` devuelve STRING (alias), no objeto. `getTableQueue()` en
  este hook NO contiene aún las tablas de datos → hay que `ensureTable`.
- CSS en `admin-pages.css`: `.saibher-operational-page .views-exposed-form`
  flex `nowrap` 100% (los items `flex 1 1 200px`, min 150px), checkbox
  `flex 0 0 auto` + `align-self: center`.
- Verificado: nombre contains ("agua"→1), código exacto ("BEB-500-DEMO"→1,
  "BEB"→0), categoría (23=Harinas→solo productos con esa cat; node 3 tiene
  Bebidas+Harinas), bajo_stock (`?bajo_stock=1` deja solo stock<min; se usó un
  nodo temporal FILTROTEST id 192, BORRADO tras probar).

## Familiaridad con DDEV/Drupal (nuevo)

- `ddev drush wd-show --type=php` para errores PHP.
- `ddev drush php:script web/script.php` (mensaje en CONTEXTO_SESION: evitar
  comillas/$ en `php:eval`; con heredoc usar `php:script`).

## Round 6 — Toggle "Bajo stock" + 5 productos seed — COMPLETADO

- Label acortado de "Solo stock bajo el mínimo" a **"Bajo stock"**.
- Checkbox convertido a **toggle estilo app móvil** reutilizando `.sb-toggle`
  del tema (`04-forms.css`): `.sb-toggle__track` 44×24 + `.sb-toggle__thumb`
  + texto `.sb-toggle__label`.
- ⚠️ Aprendizaje clave: en Drupal 11 el tema `checkbox` NO renderiza
  `#prefix`/`#suffix`, y `#type => 'markup'` con string plano pasa por
  `Xss::filterAdmin()` que ELIMINA `<label>` y `<input>`. Fix: render del
  toggle completo como `Markup::create(...)` (input homónimo `name=bajo_stock`
  value=1 viaja en el GET). El estado marcado se repone leyendo
  `\Drupal::request()->query->get('bajo_stock')`.
- CSS añadido en `admin-pages.css`: `.sb-toggle { align-self: center; }` y
  `.sb-toggle__label` (margin-left, fuente/secondary, nowrap, pointer).
- Se crearon 5 productos seed (ids 193–197, todos con stock < mínimo) para
  probar el filtro:
  - 193 DEMO - Harina de trigo 1kg (HAR-001, 4/10, Harinas)
  - 194 DEMO - Desinfectante multiusos 1L (ASE-102, 2/8, Aseo)
  - 195 DEMO - Refresco cola 2L (BEB-042, 6/12, Bebidas)
  - 196 DEMO - Jabón líquido 500ml (ASE-214, 1/6, Aseo)
  - 197 DEMO - Azúcar morena 1kg (HAR-088, 5/15, Harinas)
  - Script temporal `seeds-lowstock.php` borrado tras ejecutar.
- Verificado: `?bajo_stock=1` → exactamente esos 5; track azul primary
  (rgb(18,66,230)) y thumb trasladado 20px al activar; clic en el track marca/
  desmarca el checkbox nativo y el GET llega al Buscar; captura
  `.playwright-mcp/inventario-toggle-bajo-stock.png`.

## Round 7 — Filtro "Código" apunta a `field_code` (no SKU) — COMPLETADO

- ⚠️ Los productos tienen DOS identificadores distintos: `field_sku` (SKU, ej.
  `HAR-088`) y `field_code` (Código, ej. `880`). Antes, el filtro expuesto
  "Código" buscaba sobre `field_sku`; escribir `880` NO encontraba nada.
- Fix en `config/views.view.saibher_inventory.yml`:
  - Filtro `field_sku` → **`field_code`** (`node__field_code.field_code_value`,
    `plugin_id: string`, `operator: '='`, identifier `codigo`, label Código).
  - Se añadió la columna `field_code` (label "Código") tras SKU, dependency
    `field.storage.node.field_code`, y entrada en `style.options.columns` +
    sortable.
- Aplicar al activo: leer el yaml desde `/var/www/html/config/...` (¡el CWD de
  `php:script` NO es el repo! rutas relativas fallan) →
  `configFactory()->getEditable(...)->setData()->save()` + cache delete + `cr`.
  ⚠️ NO llamar `$view->save()` después: re-valida los filtros y revienta en
  `TaxonomyIndexTid` (loadMultiple con string). El save de la config es
  suficiente.
- Verificado: `?codigo=880` → "DEMO - Azúcar morena 1kg" (SKU HAR-088).
  Captura `.playwright-mcp/inventario-filtro-codigo-880.png`.

## Round 8 — Estilos del formulario de producto (modal) — COMPLETADO

- **"Detalles" abierto por defecto**: en
  `config/core.entity_form_display.node.product.default.yml`,
  `group_producto_detalles.format_settings.open: true` (aplicado al activo con
  configFactory->save + cache delete + cr). Además, en
  `saibher_bms_core_form_alter` se fuerza `#open = TRUE` en cualquier elemento
  `#type === 'details'` de los forms `node_product_form`,
  `node_product_edit_form` y `taxonomy_term_category_*_form` (recorrido
  recursivo con `Drupal\Core\Render\Element::children`). TODOS los grupos vienen
  abiertos por defecto.
- **Iconos de abrir/cerrar en los grupos**: CSS en `admin-pages.css` para
  `details.field-group-details > summary`: `list-style: none` +
  `::-webkit-details-marker { display:none }`; `display: flex` con chevron
  propio `::before` (10×10, rotado -45° cerrado / 45° abierto, `border-color`
  primary al abrir, transición) y `.details-wrapper` con padding. Verificado por
  computación: transform del chevron cambia entre estados y el clic en summary
  abre/cierra.
- **Icono de autocompletar visible** en `.saibher-modal .node-form
  input.form-autocomplete.ui-autocomplete-input` → lupa SVG en
  `background-image` (derecha 12px, 18px) + `padding-right: 40px`. Se aplica a
  Categoría e Impuesto (entity_reference_autocomplete). ⚠️ Precedencia: la regla
  base `.saibher-modal .node-form input.form-autocomplete` usa `background:`
  shorthand → mi regla debe tener MAYOR especificidad (`.saibher-operational-page
  .saibher-modal .node-form input.form-autocomplete.ui-autocomplete-input`).
- ⚠️ Cache del navegador: tras editar un CSS, la versión servida puede quedar
  vieja (mismo `?tlfrid`); usar cache-buster al verificar.
- LOS autocompletar del form de producto están en "Identificación" (Categoría,
  Impuesto); "Inventario y stock" solo tiene stock/mínimo (números, sin
  autocompletar).

## Round 9 — Layout del formulario de producto (modal) — COMPLETADO

- Modal más grande: `width: min(100% - 32px, 80vw)` y
  `height: min(100vh - 32px, 80vh)` (antes 560px de ancho); el body hace scroll.
- Reorganización del form display (`config/core.entity_form_display...yml`):
  - Identificación: el orden lo marca el WEIGHT (no el array `children` de
    field_group): `field_code` -7, `field_sku` -6, `title` -5, `field_category`
    -4 → Código+SKU quedan ARRIBA del Título.
  - Nuevo grupo **Impuestos** (`field_tax` sale de Identificación), peso 1.
- ⚠️ **Hallazgo clave**: Chrome NO aplica `display: grid`/`flex` sobre `<details>`
  (los hijos colapsan a lo ancho de una columna y se apilan; probado con
  grid/flex, en vivo y en clon limpio → mismo fallo). SOLUCIÓN: behavior
  `saibherBmsFormRows` en `admin-pages.js` envuelve los campos de cada grupo en
  `<div class="sb-form-row">` (plano, donde grid SÍ funciona): pares →
  `grid-template-columns: repeat(2, minmax(0, 1fr))`; campos largos (título,
  categoría, impuesto, descripción, imagen) → `.sb-form-row--full` (1fr).
  Corre en attach y tras el replace del slot de edición (`attachBehaviors`);
  idempotente vía `dataset.sbRows`.
  ✓ Verificado: code|sku, pvp|cost y stock|min en la misma fila (dos columnas);
  title/category/tax/image/description a 100% (sin salir del modal).
- El `<details>` queda como tarjeta bloque (sin props de grid/flex).
- Captura `.playwright-mcp/producto-form-layout-2cols.png`.

## Round 10 — Sin "Vista previa" ni eliminar-nodo en los forms de producto — COMPLETADO

- Contexto: en las modales del panel los forms ya NO mostraban esos botones
  (solo "Guardar"); aparecerían en las páginas estándar de nodo (403 para el
  usuario de test). Se retiran a nivel de form para cualquier superficie.
- `saibher_bms_core_form_alter`: para `node_product_form` y
  `node_product_edit_form` → `unset($form['actions']['preview'])` y
  `unset($form['actions']['delete'])`.
- CSS de red de seguridad en `admin-pages.css` (selectores PRECISOS):
  `input[data-drupal-selector="edit-preview"]` y `edit-delete`.
- ⚠️ No tocar los "Eliminar" de los widgets (imagen/filas de multimarca):
  usan selectores distintos y quedan operativos.
- Verificado: actions de edit y create = solo "Guardar".

## Round 11 — Resaltar filas con stock bajo el mínimo en Inventario — COMPLETADO

- Petición: resaltar en rojo los ítems con bajo stock.
- `saibher_bms_core_preprocess_views_view_table()`: para `saibher_inventory`,
  si stock < stock mínimo → `$row['attributes']->addClass('sb-row--low-stock')`
  (no depende del filtro `bajo_stock`; se marca siempre que se cumpla).
- CSS en `admin-pages.css`: `.saibher-table-scroll tr.sb-row--low-stock` →
  fondo `var(--sb-error-container)` (#FFDAD6), hover igual, celdas de stock en
  `var(--sb-error)` (negrita). ⚠️ El contenedor real de la tabla es
  `.saibher-table-scroll`, NO `.views-element-container` (primera versión del
  selector no aplicaba). Verificado por estilos computados: bg
  `rgb(255,218,214)`, stock `rgb(230,64,18)`; 5 productos demo marcados.
  Captura `.playwright-mcp/bajo-stock-resaltado.png`.

## Round 12 — Filtros en la tabla de Movimientos — COMPLETADO

- Petición: filtros en la vista de movimientos: SKU/código (contains),
  producto (contains), tipo de movimiento (select) y referencia (contains).
- `config/views.view.saibher_movements.yml`: 4 filtros expuestos agregados al
  display default (después del filtro bundle `type`):
  - `field_product_code` → `string` contains, identifier `sku_codigo`, label
    "SKU / código".
  - `field_product` → `string` contains, identifier `producto`, label Producto.
  - `field_movement_type` → `string` con `is_grouped: true` (widget select) y
    3 `group_items` (Entrada, Salida, Ajuste), identifier `tipo`. ⚠️ Para un
    string libre no hay handler nativo de select; la agrupación (grouped
    filter) es la forma de exponerlo como `<select>`.
  - `field_reference` → `string` contains, identifier `referencia`, label
    Referencia.
  - Columnas usadas: `*_value` de cada tabla `node__*` (p. ej.
    `field_movement_type_value`).
  - Aplicado con el workaround: `config.storage->write` + `configFactory->reset`
    + `cache('config')->deleteAll` + `cr` (script temporal borrado).
- `saibher_bms_core_form_alter`: nueva rama `bms/movimientos` del exposed form,
  espejo de la de inventario: placeholders en sku_codigo/producto/referencia
  (`.sb-input`), `tipo` envuelto en `.sb-select-wrap` con `.sb-select` y
  primera opción rotulada "Tipo de movimiento", botón submit → "Buscar"
  (`.saibher-btn--primary`).
- Verificado en navegador: form con 4 controles + Buscar; `sku_codigo=BEB-LOTE`
  → 20 movimientos; `tipo` select Salida → solo salidas; `referencia=OC-DEMO`
  → 1; estilos coinciden con inventario. Nota: con `empty_table` las búsquedas
  sin resultados muestran una fila vacía (no mensaje explícito).

## Round 13 — Tabla de Movimientos: columnas reordenadas y solo lectura — COMPLETADO

- Petición: ver la tabla como Fecha/hora, SKU, Producto, Cantidad inicial,
  Cantidad, Cantidad final, Tipo, Referencia; quitar todos los botones de
  acción (tabla de solo lectura); la cantidad y la referencia venían "muy
  pegadas" (sin aire lateral).
- `config/views.view.saibher_movements.yml`:
  - Añadidos a `fields` los campos `field_initial_quantity` y
    `field_final_quantity` (tipo float, `number_decimal`).
  - `style.options.columns` en el orden pedido (created, field_product_code,
    field_product, field_initial_quantity, field_quantity, field_final_quantity,
    field_movement_type, field_reference); `info` con sortable en todas.
  - Aplicado con workaround config.storage (script temporal borrado).
- `saibher_bms_core_preprocess_views_view_table`: raíz movements devuelve
  temprano (sin columna de acciones → tabla read-only) pero antes añade la
  clase `saibher-movements-table` a la tabla.
- `admin-pages.css`: ⚠️ hallazgo — las tablas del panel NO se renderizan
  dentro de `.views-element-container`, por eso el theme les aplica
  `padding: 10px 0` (pegadas). Para `table.saibher-movements-table` se da
  `padding-left/right: 16px`, columnas numéricas a la derecha
  (`tabular-nums`) y campo referencia en mono.
- Verificado: headers correctos; acciones ausentes; `padding: 10px 16px`;
  cantidad a la derecha.

## Round 14 — Fichas internas de clientes y proveedores — COMPLETADO

Réplica de las plantillas `https://saibher-flow.lovable.app/cliente/cli-4`
(ficha de cliente) y `.../proveedor/prov-1` (ficha de proveedor) sobre los
canonical de los bundles `customer` y `supplier`.

- **Plantillas**: `node--customer--full.html.twig` y
  `node--supplier--full.html.twig` incluyen el layout compartido
  `templates/node/ficha-entity.html.twig` (referenciado como
  `@saibher_bms/node/ficha-entity.html.twig`).
- **CSS**: `css/node-ficha.css` + librería `saibher_bms/entity-ficha`
  en `saibher_bms.libraries.yml`. Reutiliza page-header, stat-card y
  `sb-badge-soft`; oculta el page title duplicado vía body classes
  `sb-ficha-cliente` / `sb-ficha-proveedor` (mismo patrón que producto).
- **`saibher_bms_core_preprocess_node`** ahora despacha a builders por
  bundle: `saibher_bms_core_build_product_ficha()` (código previo intacto),
  `saibher_bms_core_build_customer_ficha()` y
  `saibher_bms_core_build_supplier_ficha()`.
- Datos de cliente (solo reales, nunca inventados): cartera = suma de
  nodos `debtors` (campo `field_customer_debtors`), compras = nodos
  `document` con `field_customer_document` = cliente (folio = código del
  prefijo `document_prefix` + número, quitando la descripción tras " - "),
  estado de cada compra = "Pagada" si la suma de pagos (paragraph payment)
  >= total, si no "Pendiente". Cupo de crédito/disponible no tienen campo →
  se muestran "—".
- Proveedor: estado = `status` del nodo (publicado → Activo), KPIs de
  productos/valor/compras en 0 con empty states (todavía no existe relación
  producto→proveedor ni compras→proveedor en el modelo).
- Sucursales = entidad referenciada `locations` (name/address/phone/
  encargado) recorrida con `saibher_bms_core_collect_locations()`.
- "Añadir sucursal" enlaza a `node.add` (`/node/add/locations`); el form de
  `locations` entró en el recorte del panel: `form_alter` retira
  status/promote/etc. y los botones "Vista previa/Eliminar" (igual que
  product).
- Extraídos helpers `saibher_bms_core_money()` (moneda `$ 8.330` o "—") y
  `saibher_bms_core_collect_locations()`.
- `preprocess_html`: body classes por bundle (producto/cliente/proveedor).
- Verificado en navegador (nodos demo 4 y 5 + listados): grid 2fr/1fr en
  escritorio, título duplicado oculto, folio "FV-1001", badge de cartera
  warning y estado Pagada success, empty states de proveedor, navegación
  desde `/bms/clientes` y `/bms/proveedores` al canonical. Ojo: los clics
  de fila del listado fallan por el header sticky (ver arriba); usar
  `el.click()` vía evaluate.

## Round 14 (añadido) — Campo "Cupo de crédito" en clientes

- Nuevo campo `field_credit_limit` (float) en el bundle `customer`
  (`field.storage.node.field_credit_limit` + `field.field.node.customer.field_credit_limit`).
- Form display: widget `number` dentro del group "Pagos" (junto a
  `field_payment_customer`); view display default: `number_decimal`.
- Aplicado creando las entidades vía API (FieldStorageConfig/FieldConfig y
  EntityFormDisplay/EntityViewDisplay) con los YAML de `config/` como fuente;
  así se crean las tablas (`node__field_credit_limit`) — no escribir solo con
  `config.storage->write` para campos NUEVOS (no crea esquema sin eventos).
- `saibher_bms_core_build_customer_ficha()`: lee el cupo, y
  `credit_available = cupo − balance` (cartera); si no hay cupo → ambos "—".
- Verificado: cliente 4 con cupo 5.000.000 → Cupo 5.000.000 / Saldo 8.330 /
  Disponible 4.991.670; cliente 13 sin cupo → "—".
- También se retiró el botón "Editar" del page-header en las fichas full
  (cliente/proveedor en `ficha-entity.html.twig` y producto en
  `node--product--full.html.twig`): solo queda el enlace de regreso.
- `field_payment_customer` (campo "Pagos") se ocultó del formulario de cliente
  (los pagos se gestionan desde documentos): fuera de `content`, en `hidden`;
  el group "Pagos" se renombró a "Cupo de crédito" (único campo que contiene).
   El proveedor no tiene campo de pagos, no aplica.

## Round 14 (añadido 2) — Sucursales como párrafo inline (cliente y proveedor)

- Petición: crear sucursales con un formulario dentro del propio formulario
  del cliente/proveedor (no con el buscador autocomplete de nodos `locations`).
  Decisión del usuario: **solo párrafo nuevo, sin migrar** (empezar de cero).
- Nuevo tipo de párrafo `paragraphs.paragraphs_type.location` ("Sucursal") con:
  `field_name` (Nombre), `field_address` (Dirección),
  `field_phone_locations` (Teléfono), `field_email_locations` (Correo
  electrónico) y `field_person_name` (Persona encargada). Incluye
  `core.entity_form_display/view_display.paragraph.location.default`.
- `field_locations` (bundles `customer` y `supplier`): de `entity_reference` a
  nodos `locations` → `entity_reference_revisions` a párrafos `location`
  (`field.storage.node.field_locations` + `field.field.node.*.field_locations`).
  Widget `paragraphs`: título "Sucursal"/"Sucursales", `edit_mode: open`,
  `add_mode: button`, `default_paragraph_type: location`, `features:`
  `duplicate` + `collapse_edit_all`.
- ⚠️ Drupal NO permite cambiar el tipo de un field storage existente:
  `FieldStorageConfig::save()` lanza "Cannot change the field type for an
  existing field storage". Hay que **eliminar el storage y recrearlo** (los
  field configs se borran en cascada y se vuelven a crear). Los campos nuevos
  se aplican con `php:script` vía API de entidades (no `config.storage->write`,
  que no crea esquema).
- Datos: antes del cambio se limpiaron las referencias antiguas de **42 nodos**
  (21 clientes + 21 proveedores); si no, los `target_id` de nodos habrían
  apuntado a párrafos por coincidencia de id. Los 21 nodos `locations` quedan
  sin uso (no borrar) y dejan de mostrarse.
- `saibher_bms_core_collect_locations()` valida ahora `ParagraphInterface`
  (mismos nombres de campo). El botón "Añadir sucursal" de la ficha apunta a
  `entity.node.edit_form` del propio cliente/proveedor (la sucursal se crea
  inline en la edición).
- Verificado en navegador: `/node/4/edit` y `/node/5/edit` muestran el widget
  "Añadir Sucursal" con los 5 campos inline; al guardar, `/cliente/4` muestra
  la sucursal (DEMO - Sede Chapinero / Calle 63 # 11-20, Bogotá / +57 310 555
  9999 / Encargado: Laura Gómez).
- Nota: `drush config:export` (cex) SÍ funciona (a diferencia de cim); se usó
  para reconciliar los YAML con el estado activo (Drupal normaliza
  dependencias/settings) y para quitar el residuo `hidden.field_locations` de
  los form displays de cliente/proveedor.
- Archivos nuevos: `config/paragraphs.paragraphs_type.location.yml`,
  `config/field.storage.paragraph.field_{name,address,phone_locations,email_locations,person_name}.yml`,
  `config/field.field.paragraph.location.*.yml`,
  `config/core.entity_{form,view}_display.paragraph.location.default.yml`.
  Modificados: `field.storage.node.field_locations.yml`,
  `field.field.node.{customer,supplier}.field_locations.yml`,
  `core.entity_form_display.node.{customer,supplier}.default.yml`,
  `saibher_bms_core.module`, `ficha-entity.html.twig`.

## Round 14 (añadido 3) — Eliminado el content type `locations` + datos de prueba

- Petición: eliminar el tipo de contenido `locations` (ya sustituido por el
  párrafo "Sucursal") y dejar los nodos de cliente/proveedor con datos de
  prueba. Sustituye la nota anterior "los 21 nodos locations quedan sin uso".
- Eliminado vía API de entidades en este orden: 21 nodos `locations`, displays
  `node.locations.default` (form y view), 6 field configs del bundle,
  `node.type.locations` y los storages huérfanos `node.field_name`,
  `node.field_address`, `node.field_email_locations`,
  `node.field_phone_locations` y `node.field_customer_locations` (solo los usaba
  `locations`; se conserva `node.field_person_name`, compartido con
  customer/supplier). ⚠️ Borrar un content type por API **no** borra nodos ni
  campos (`NodeType::postDelete()` solo resetea caché): hay que hacerlo a mano.
  Al borrar el último field config, el bundle listener purgó los storages.
- Borrados sus YAML de `config/`; `saibher_bms_core.module`: `form_alter` ya no
  contempla `node_locations_form`/`node_locations_edit_form` ni el bundle
  `locations`; docblock de `collect_locations()` actualizado a "párrafos".
  `/node/add/locations` → 404; tablas `node__field_name` etc. eliminadas.
- Datos de prueba: 1 sucursal ("Sucursal principal") en cada cliente y
  proveedor que no tuviera, reutilizando sus propios campos
  (`field_main_address`, `field_phone_customer/supplier`,
  `field_email_customer/supplier`, `field_person_name`) — sin inventar valores.
  Resultado: 42 nodos (21+21) con 1 sucursal; 42 párrafos `location`. El nodo 4
  conserva "DEMO - Sede Chapinero".
- Reconciliación: 4 configs con drift del cupo de crédito (view displays de
  customer/supplier + field config/storage de `field_credit_limit`) copiados
  desde `config:export`, y quitado `field_credit_limit` duplicado en `hidden`
  del view display de customer. `diff -rq config <export>` = 0 diferencias
  (salvo `.htaccess`).
- Verificado en navegador: `/cliente/4` (Cupo 5.000.000 / Saldo 8.330 /
  Disponible 4.991.670 + sucursal), `/node/13` y `/node/5` con "Sucursal
  principal" y dirección/teléfono/encargado.