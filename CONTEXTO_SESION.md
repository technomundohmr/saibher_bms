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

## Round 14 (añadido 4) — Ficha: sin botón de sucursal y sucursales en columna

- Petición: retirar el botón "Añadir sucursal" de la vista full de cliente y
  ordenar la sección Sucursales con la dirección principal arriba y las
  sucursales debajo (habían quedado en dos columnas).
- `ficha-entity.html.twig`: se eliminó el bloque del botón "Añadir sucursal".
  La sección Sucursales ahora muestra primero la tarjeta "Dirección principal"
  (con `field_main_address`) y debajo la lista de sucursales; el aside
  "Contacto" ya no muestra la dirección, solo Teléfono y Correo.
- `web/themes/saibher_bms/css/node-ficha.css`: `.sb-locations` a `1fr` (una
  columna) + clase `.sb-location--main` para distinguir la tarjeta principal
  (fondo `--sb-primary-container`). El primer intento de CSS no se grabó (se
  volvió a leer y reaplicó).
- Verificado en navegador: `/cliente/4` y `/node/5` en columna única, con la
  tarjeta principal arriba y las sucursales debajo.

## Round 15 — Filtros expuestos en Clientes y Proveedores

- Petición: filtros por nombre (contiene), contacto (contiene), documento
  (exacto), teléfono (contiene) y correo (contiene) en las tablas del panel.
- `config/views.view.saibher_customers.yml` y `saibher_suppliers.yml`: el
  filtro `title` que existía ("Buscar") pasa a exponerse como **Nombre**
  (`identifier: nombre`, contiene) y se añaden `field_person_name` (Contacto,
  contiene), `field_document_nunber` (Documento, `operator: '='`),
  `field_phone_customer/supplier` (Teléfono, contiene) y
  `field_email_customer/supplier` (Correo, contiene). Todos con `plugin_id:
  string` (el correo no tiene plugin propio en views: usa `StringFilter` como
  los varchar/text). `table` = `node__field_*`, `field` = `*_value`.
- Aplicado con el workaround estándar: `php:script`
  (`config.storage->write` leyendo de `config.storage.sync`) + `config.factory`
  reset + `cache('config')->deleteAll()` + `ddev drush cr`. Script temporal
  borrado.
- `saibher_bms_core.module` (`form_alter`): para `bms/clientes` y
  `bms/proveedores` los 5 inputs pasan a `sb-input` con placeholder (Nombre,
  Contacto, Documento, Teléfono, Correo), títulos `invisible` y botón
  "Buscar" (`saibher-btn--primary`). El form lleva la clase
  `saibher-exposed-filters`.
- `admin-pages.css`: `.views-exposed-form.saibher-exposed-filters` →
  `flex-wrap: wrap` y `.form-item` con `flex: 1 1 180px; min-width: 170px`
  (5 campos no caben en una línea).
- Verificado en navegador: `/bms/clientes` y `/bms/proveedores` muestran los 5
  inputs con placeholder y botón "Buscar". Pruebas de filtrado: documento
  exacto `901234567-8` → 1 fila; nombre `LOTE` → solo los 20 LOTE; teléfono
  `0202` → Comercializadora Andina; contacto `Ruiz` + correo `cliente.demo` →
  1 fila; proveedor nombre `Central` → Distribuidora Central.
## Round 16 — Formulario de facturación (solo visual) — COMPLETADO

- Petición: formulario de facturación de una sola página SOLO VISUAL (prototipo
  front-end): funciona y se ve (interacciones JS completas con datos reales de
  demo) pero NO persiste nada; "Cerrar e imprimir"/"Imprimir" solo hacen
  `window.print()`. Enlazado desde el botón "Nueva factura" de `/bms/facturacion`
  y desde el ítem de menú "Facturar".
- Permiso `access saibher billing form` (labels, restrict access true) en
  `saibher_bms_core.permissions.yml`; ruta `saibher_bms_core.billing_form`
  (`/bms/facturar`, controller `BillingFormController::page`) usa
  `_permission`. Comentario en routing: acceso libre en desarrollo.
- Permiso concedido a los roles del panel editando
  `config/user.role.authenticated.yml` y `config/user.role.content_editor.yml`
  (admin es `is_admin: true`, lo tiene todo). Aplicado con workaround
  `php:script` (config.storage write) + `cr`.
- `BillingFormController` (nuevo) acumula desde la DB real: clientes con sedes
  (párrafos `location`), prefijos de `document_prefix` con `next` (máx
  `field_document_number` + 1 = 1121), productos con código/SKU/PVP/tax (IVA 19
  por defecto), métodos de pago (`payment_method`), tipos de documento
  (`person_document_type`), colaborador único "Saibher admin". Manda el dataset
  vía `#attached drupalSettings`; cache contexts `user.permissions` +
  `url.query_args`. El tipo de identificación se infiere: doc con "-" → NIT, si
  no Cédula de ciudadanía.
- Template `templates/saibher-billing-form.html.twig` (nuevo): 5 secciones en
  columna principal (Cliente, Datos de la factura, Productos, Acciones) +
  aside sticky "Totales", 2 dialogs (Asignar a colaborador, Pagar) con clases
  `saibher-modal` y un bloque oculto para impresión. Reutiliza átomos del tema
  (`sb-field`, `sb-input`, `sb-select`, `.sb-select-wrap`, `sb-card`) y botones
  `.saibher-btn`. El botón del header del page ahora se renderiza como `<a>`
  cuando llega `action_url` (template base `saibher-bms-core-page.html.twig`).
- `css/billing-form.css` + `js/billing-form.js` (librería `billing-form`,
  deps admin-pages/once/drupal): buscador de cliente por nombre (contains) o
  identificación exacta; al elegir → campos nombre/sede/tipo + número readonly;
  "Cambiar de cliente" resetea; prefijo→consecutivo autocalculado (bloqueado);
  productos por nombre/SKU (contains) o código exacto con fila editable
  (unitario/cantidad) y recálculo en vivo; Totales en vivo; pagos múltiples
  (medio, valor, descripción, lista + "Total abonado", persiste en el aside);
  asignación a colaborador (autocomplete + Asignar/Cancelar); impresión monta
  la factura en el bloque `[data-sb-print-invoice]` y llama `window.print()`.
- ⚠️ Bug corregido en el camino: el behavior consultaba solo el `<form>`
  (`[data-sb-billing]`) pero las modales están FUERA del form → `els.payMethod`
  era null y attach reventaba. Fix: root = `.saibher-billing-form` (sección
  completa) + guard `dataset.sbBillingReady`. Segundo fix: `$`/`$all` se
  habían borrado por error al editar (restaurados) y `paySum` reescribe el
  texto completo ("Total abonado: <strong>…</strong>") para no perder la
  etiqueta.
- Verificado en navegador (`/bms/facturar`): sin errores de consola; cliente
  por nombre y por documento exacto; sede/tipo/número autocompletados (número
  readonly); consecutivo 01121 para FV; producto añadido (3100 → impuesto 589,
  cant. 2 → total 7.378); 2 pagos (5.000 + 2.378 = 7.378) persistidos en
  Totales; asignación a "Saibher admin"; reset "Cambiar de cliente"; impresión
  con `window.print` (FV-01121, fila, totales, pagos, asignación). El botón
  "Nueva factura" de `/bms/facturacion` ahora enlaza a `/bms/facturar` y el
  menú muestra "Facturar".
- No persiste nada: los botones de impresión solo llaman `window.print()`;
  "Listo"/"Asignar" solo cierran el dialog. Pendiente futuro: funcionalidad
  real (guardar documento, movimientos, stock, pagos).

### Round 16 (ajuste de layout) — COMPLETADO
- Petición: "datos de factura en columna a la izquierda del cliente" se aplicó
  con `.sb-billing__head` (grid `minmax(320px,440px) 1fr`): el card "Datos de la
  factura" a la izquierda y "Cliente" a la derecha. Después el usuario pidió los
  campos prefijo/consecutivo "en filas": `.sb-fields--invoice` vuelve a
  `repeat(2, minmax(0,1fr))` (prefijo y consecutivo lado a lado).
- Petición: botones de acción "bajo el total, en fila y más pequeños". La
  Sección 5 se movió al aside (bajo los Totales); el aside pasa a 460px y los
  botones usan la nueva clase compacta `.saibher-btn--sm` (min-height 34px,
  padding 6px 12px, radius sm, nowrap) en retícula 2×2
  (`.sb-billing__side .sb-actions { display:grid; repeat(2,1fr) }`). ⚠️ Aprendizaje:
  con flex-wrap en columna estrecha los 4 botones caían UNO POR LÍNEA (el
  contenedor solo dejaba ~242px útiles) y el texto envolvía (alto 62px); la grilla
  2×2 + aside 460px los deja en 2 filas de 2, únicos 38px. "Pagar"/"Asignar a
  colaborador" siguen en el aside, arriba a la derecha de la tabla de productos.
- Verificado en navegador: sin errores de consola; prefijo/consecutivo lado a
  lado (01121); factura a la izquierda del cliente; 2 filas de 2 botones (38px)
  bajo los Totales; búsqueda/productos/totales intactos.

### Round 16.2 (último ajuste 25/75) — COMPLETADO
- Petición: "que los datos de cliente ocupen el 75% de la pantalla". Con el
  aside a 400px y `.sb-billing__head` `minmax(280px,1fr) 3fr` el card Cliente
  ocupaba 69% del head (663px). Edit final: aside 400→380px y floor del card de
  factura 280→220px (`minmax(220px,1fr) 3fr`) → verificado: 73% (722px) sin
  exprimir el card de factura.

### Round 17 — Rediseño de `/bms/facturar` según https://saibher-flow.lovable.app/factura-nueva — COMPLETADO
- Petición: "ajusta el diseño de esta página con este [referencia Lovable]".
  Se navegó a la referencia (Login: marta@saibher.co / saibher123) y se copió
  su estructura y copy.
- **Layout nuevo**: la columna principal queda con Cliente (arriba) y Productos
  (abajo); el aside pasa a apilar 3 cards en este orden: **Datos de la factura**
  (Prefijo de factura + Consecutivo, nota "Se asigna solo según el prefijo
  elegido."), **Resumen** (Subtotal / Total impuesto / Total + chip de estado
  `data-sb-pay-status`) y **Acciones** (4 botones apilados a ancho completo:
  Cerrar e imprimir, Imprimir, Asignar a colaborador, Pagar (n)). Se quitaron
  los números de paso (`.sb-card__icon--step` eliminado) y el wrapper
  `.sb-billing__head`.
- **Buscadores por dropdown**: los chips de modo se reemplazan por `<select>`
  con label "Modo de búsqueda" (Por nombre / Por identificación en cliente; Por
  nombre o SKU / Por código en productos), `.sb-search` en 2 columnas
  (`minmax(180px,260px) 1fr`). JS: bind de `change` en vez de `click`, placeholders
  de la referencia ("Escribe parte del nombre…", "Ej. Leche entera o LAC-0021").
- **Copy de referencia**: subtítulo del header ("Formulario de facturación con
  cálculo automático de impuestos y totales."), subtítulos de cards, cabecera
  de tabla "% Imp.", vacío "Aún no has agregado productos a esta factura.".
- **Consecutivo con prefijo**: se muestra `FV-01121` (código del prefijo + número
  padStart 5); el número puro queda en `dataset.numeric` y la impresión usa
  `prefixCode + dataset.numeric` (no duplica el prefijo).
- **Pagar (n)**: el botón muestra el número de pagos registrados
  (`data-sb-pay-count`, `margin-right` none) y el chip de estado pasa a
  "Pagada" (`.is-paid`, verde `--sb-secondary-container`) cuando
  `abonado >= total > 0`; si no "Pendiente de pago" (ámbar
  `--sb-warning-container`).
- **CSS**: removidos `.sb-chip`, `.sb-search__modes`, `.saibher-btn--sm`
  (sin usos restantes); `sb-actions` en grid 1 columna full-width;
  `.sb-pay-status`, `.sb-pay-count`, `.sb-help` nuevos; responsive 720px añade
  `.sb-search` a 1 columna.
- Verificado en navegador (`?x=10/11`): sin errores de consola; aside en orden
  Datos→Resumen→Acciones; cliente por documento exacto autocompleta NIT/sede;
  producto (agua 3.500 → +665 imp = 4.165); 2 pagos (2.000+2.165) → "Pagar (2)",
  chip "Pagada", total abonado 4.165; print con `window.print` stub: FV-01121,
  cliente, fila, totales y pagos correctos. ⚠️ `window.print()` real bloquea el
  renderer en headless (crasheó la pestaña al navegar de nuevo): verificar
  impresión solo con stub `window.print = () => {}`.
- Pendiente de commit (sin confirmar con el usuario): Rounds 14–16 + 17 y la
  ficha.

### Round 17.1 — Retoques de Round 17 — COMPLETADO
- "El botón de cerrar e imprimir es horrible": tenía un icono SVG "+" heredado
  (símbolo de añadir). Se quitó → botón primario limpio a ancho completo sin
  icono (igual que la referencia).
- "Revisa los espacios entre bloques... están pegados": las cards de ambas
  columnas ahora se apilan con `gap: var(--sb-space-md)` (16px) vía
  `.sb-billing__main` y `.sb-billing__side` (display:grid + gap). Verificado:
  los 3 huecos miden 16px exactos.

### Round 18 — Renombrado "Facturación" → "Facturas de venta" — COMPLETADO
- Petición: cambiar el nombre "Facturación" por "Facturas de venta". Renombrado
  en TODAS las superficies visibles: menú (ambos
  `links.menu.yml`: `saibher_bms_core.billing` y `saibher_bms_menu.billing`),
  título de página/ruta (`_title` en routing + `BillingController::page`),
  botón "Volver a Facturación" (template billing-form), mapa de iconos del
  menú (`menu--main.html.twig`: clave 'Facturas de venta' → 'point_of_sale'),
  permiso `access saibher billing` ("Acceder a Facturas de venta") y la vista
  `views.view.saibher_documents.yml` (label, title, block_description) aplicada
  también en la config activa con el workaround `php:script` + `cr`.
- Verificado en navegador: `<title>` "Facturas de venta | Saibher bms", H1
  "Facturas de venta", menú "Facturas de venta" con icono `point_of_sale`,
  botón "Volver a Facturas de venta" en `/bms/facturar`.
- Sin cambios en la URL (`/bms/facturacion`), ni en la marca
  ("Facturación de ventas y compras" en `CONTEXTO_PROYECTO.md` es el módulo de
  producto, no el nombre del ítem).

## Round 19 — Formulario de compra `/bms/comprar` (réplica del de facturar) — COMPLETADO

- Petición: replicar el funcionamiento del formulario de facturación para
  compras, cambiando clientes por proveedores. Sin duplicar: el formulario
  compartido se parametriza por modo (venta/compra).
- `DocumentFormControllerBase.php` (nuevo): centraliza `page()`/`formData()`/
  `sedes()`/`documentTypeLabel()`/`productTax()`/`nextConsecutive()` y la
  selección de prefijos. Cada subclase declara el bundle de la parte
  (`customer`/`supplier`), el campo de tipo de identificación
  (`field_document_type_customer`/`_supplier`), etiquetas (título, subtítulo,
  icono, `partyLabel`, `documentTitle`), palabra de filtrado de prefijos
  (`venta`/`compra`), prefijo por defecto y la ruta/etiqueta "Volver".
- Dataset: `clients` → **`parties`** + `partyLabel`/`partyKey`/
  `documentLabel`/`documentTitle`/`defaultPrefixCode`.
- `BillingFormController` ahora extiende la base (modo venta, sin cambios
  visibles); `PurchaseFormController.php` (nuevo) modo compra (Fuente:
  "Proveedor", "FC").
- Theme hook `saibher_bms_core_billing_form`: nuevas variables con defaults
  de venta (`back_route`, `back_label`, `party_label`, `party_key`,
  `document_label`, `document_title`).
- Ruta nueva `saibher_bms_core.purchase_form` (`/bms/comprar`, mismo permiso
  `access saibher billing form`).
- Plantilla parametrizada: "Cliente" → `party_label`, "Cambiar de
  proveedor", "Buscar/Cambiar/Sede del proveedor", "Datos de la compra",
  "Prefijo de compra", "esta compra", "Compra asignada a", botón Volver con
  `back_route`/`back_label`.
- JS parametrizado: `CONFIG.parties`, `prefixCode` usa `defaultPrefixCode`,
  reset de sedes con `partyKey`, impresión usa `partyLabel`/`documentTitle`.
- Menú: ítem `saibher_bms_menu.purchase_form` "Comprar" (weight 12, route
  `saibher_bms_core.purchase_form`); icono `shopping_cart` en
  `menu--main.html.twig` + `add_circle` para "Facturar".
- Prefijos: `filterPrefixes()` filtra `document_prefix` por
  venta/compra (fallback a todos si no hay match). Término nuevo en la DB
  `FC - Factura de compra` (tid 24) creado vía `ddev drush php:eval` (dato,
  no config). Verificado: venta solo ve "FV", compra solo "FC", consecutivo
  FC-01121.
- Verificado en navegador: `/bms/comprar` proveedor "Distribuidora Central"
  autocompleta sede/tipo NIT/número; print con stub `window.print` →
  header "Proveedor", "FC - Factura de compra", "FC-01121"; `/bms/facturar`
  intacto (Cliente, FV-01121); sin errores de consola; `php -l` SIN errores
  en los 3 controllers; snapshots temporales `.playwright-mcp` borrados.
- Pendiente: sin commit (no se pidió).

## Round 20 — Réplica de https://saibher-flow.lovable.app/configuracion en `/bms/configuracion` — COMPLETADO
- Petición: integrar la vista de configuración existente con el diseño de
  referencia. Sin reescribir la lógica: solo estructura, copy y estilos.
- `PosSettingsForm::buildForm()` rearmado en 4 tarjetas que replican el diseño:
  "Datos básicos de la empresa" (nombre a lo ancho + dirección/teléfono en
  fila), "Configuración de documentos" (subgrupos Facturación/Compras/Tickets
  con prefijo + número inicial), "Opciones generales del sistema" (4
  interruptores con descripción) y "Plantillas de impresión". Botón
  "Guardar cambios" (antes "Guardar configuración") y subtítulo de página
  "Los datos y preferencias de tu negocio."
- Reutilización de componentes del tema en vez de CSS nuevo: las tarjetas usan
  la clase `sb-card`, los pares de campos el contenedor `sb-field-row--2` y los
  interruptores replican el interruptor del diseño (36x20, radio
  `--sb-radius-full`, thumb con `--sb-shadow-soft`) con `appearance: none`
  sobre el input nativo. El CSS propio (bloque "Ajustes · /bms/configuracion"
  al final de `admin-pages.css`) queda limitado a la estructura interna.
- Copy del diseño: "Nombre de la empresa / Razón social", "Número inicial
  (opcional)" con placeholder "Ej. 1001" y la ayuda "Déjalo vacío para
  continuar la numeración automática.", y las 4 descripciones de las opciones.
- Decisión: el prefijo se mantiene como `select` ligado al vocabulario
  `document_prefix` (el diseño lo dibuja como input de texto). Un texto libre
  permitiría guardar prefijos que la facturación nunca ofrece, porque el
  formulario de facturación arma sus prefijos desde el vocabulario. Visualmente
  es equivalente; la flecha se pinta con `background-image` propio porque
  `.sb-select-wrap` no es viable vía `#prefix`/`#suffix` (el template
  `form-element.html.twig` los envuelve en `<span class="field-prefix">`, con
  lo que el wrapper queda vacío).
- ⚠️ Learned: en el Form API, `details` y `container` **no anidan** los valores
  de sus hijos. Los `name` generados son planos (`prefijo_factura`, no
  `documentos[factura][campos][prefijo_factura]`), así que
  `$form_state->getValue($key)` con la clave plana es lo correcto. Se llegó a
  cambiar a rutas anidadas por error y el guardado vació toda la configuración;
  el método `valuePath()` queda centralizando la clave plana con un docblock
  que explica el porqué. Recordar que `getValue($key, $parents)` no existe en
  Drupal 11: el segundo argumento es el valor por defecto.
- Verificado en navegador (1440px y 390px): 4 tarjetas con la escala del diseño
  de referencia (ver "Escala de espaciado" más abajo), filas a 2 columnas que
  colapsan a 1 en móvil y sin desborde horizontal. Guardado probado de punta a
  punta (empresa, dirección, teléfono, prefijo, número inicial y un
  interruptor) y los valores persisten tras recargar; aviso de "numeración
  automática manda" correcto con el prefijo `FV` que ya tiene documentos
  emitidos. Datos de prueba revertidos y configurador limpio.
- Escala de espaciado replicada del diseño (Andrés: "cuida los espacios"), tal
  como se midió en la referencia y se comprobó después con `getComputedStyle`:
  20px entre tarjetas, 20px de padding de tarjeta, ancho máximo 1024px
  (`max-w-5xl`), título de tarjeta 16/24/600, descripción 14/20 separada 16px
  del primer campo, subgrupos en grid de 3 columnas (1 en móvil) con 16px de
  padding, 16px de gap y radio 16px, etiqueta 14px/500 a 6px del control,
  ayuda 12/16 a 6px del campo, filas de interruptor con 12px verticales y
  separador, e interruptor de 36x20 con thumb 16x16. Los controles conservan
  los 44px de alto del tema a propósito: es la única desviación consciente
  frente a los 36px del diseño, por coherencia con el resto del panel.
- ⚠️ Learned: las tarjetas **no son hijas de `.saibher-config-cards`**. Como
  `details` no anida, los 4 `details` quedan como hijos directos de `<form>` y
  el contenedor de la plantilla solo envuelve al formulario. Por eso el
  `display: flex; gap` puesto en `.saibher-config-cards` no se aplicaba a nada
  y las tarjetas acababan a 0px una de otra (se veía como un bloque único).
  El `gap: 20px` va en `.saibher-config-form`; en `.saibher-config-cards` solo
  queda el `max-width: 1024px`. Comprobar siempre el padre real con
  `:scope > details` antes de fiarse del selector de un contenedor.
- Espacio entre grupos de campos (petición de Andrés: "crea un espacio entre
  los grupos de campos"): la separación de bloque pasó de 16px a 24px
  (`--sb-space-lg` del tema) en nombre → dirección/teléfono, descripción →
  contenido, filas de interruptor y entre los tres subgrupos de documentos.
  Se conservan 6px entre etiqueta y control, 6px entre control y ayuda, 16px de
  padding de subtarjeta y 20px entre tarjetas (escala del diseño).
- ⚠️ Learned: el `<label>` que genera el Form API llega como `display: inline`,
  así que un `margin-bottom` en `.form-item > label` **no se aplica** (los
  márgenes verticales no afectan a cajas en línea) y la separación
  etiqueta/control queda en 3px en vez de 6px. Hay que forzar
  `display: block` en la etiqueta. Y para poder estilizar la descripción de una
  tarjeta hay que añadirle clase: `details.html.twig` la envuelve en un `<div>`
  pelado, así que `PosSettingsForm::cardDescription()` la envuelve en un
  `<span class="sb-config-card__description">`.
- ⚠️ Learned: `nombre_empresa` es `#required`, así que el navegador **bloquea
  el envío en silencio** (validación HTML5 nativa, sin petición ni mensaje) si
  se intenta guardar vacío. En pruebas automatizadas eso se confunde con un
  guardado roto: hay que mirar `form.checkValidity()` y `requestSubmit()` no
  lanza error. Para dejar el valor vacío hay que usar `drush config:set`.
- `php -l` sin errores. `phpcs --standard=Drupal,DrupalPractice` en
  `PosSettingsForm.php`: 0 errores y 2 avisos por las llamadas a `\Drupal::` en
  `prefixOptions()` y `printTemplatesRouteExists()`; el módulo no usa patrón de
  inyección en formularios, así que se mantienen. Se añadieron los docblocks
  `{@inheritdoc}` que faltaban y se reformuló una frase del docblock de clase
  que el sniff `@todo` leía como un TODO.
- Pendiente: la ruta `saibher_bms_core.print_templates` sigue sin existir, así
  que la tarjeta de plantillas muestra la nota de disponibilidad en vez del
  enlace "Configurar plantillas de impresión". Cuando se cree la ruta, el
  enlace aparece sin tocar el formulario (ya es condicional).
- Pendiente: sin commit (no se pidió).

## Round 21 — Campo "Tipo de documento" en la taxonomía de prefijos — COMPLETADO
- Petición: el vocabulario `document_prefix` debe tener un campo con tres
  opciones (ticket / venta / compra), obligatorio, y cada selector del
  formulario de configuración debe ofrecer solo los prefijos de su tipo.
- Campo creado: `field_prefix_document_type`, `list_string` en
  `taxonomy_term` para el bundle `document_prefix`, con los valores `venta`,
  `compra` y `ticket`, `required: TRUE`. Se creó con la API de entidades de
  configuración (`FieldStorageConfig` + `FieldConfig`) y no con
  `config:import`, que sigue roto en este proyecto.
- Valores asignados: `FV - Factura de venta` → venta,
  `FC - Factura de compra` → compra. Se creó además el término
  `TK - Ticket de venta` (tid 25) para que el grupo Tickets tenga opciones.
- `PosSettingsForm`: `documentGroups()` ahora declara el `type` de cada grupo
  (factura→venta, compra→compra, ticket→ticket) y `prefixOptions(string $type)`
  filtra por el valor del campo, de modo que cada selector ofrece solo su tipo.
  Se eliminó la variable `$prefixes` calculada una sola vez para todos.
- `DocumentFormControllerBase::filterPrefixes()` deja de fiarse solo de la
  palabra clave en el rótulo: primero usa `field_prefix_document_type` y
  conserva dos degradados (rótulo y, en último caso, todos los prefijos) para
  que el formulario nunca quede vacío.
- ⚠️ Learned: un campo nuevo en un bundle de taxonomía **no aparece** en el
  formulario hasta que existe un `entity_form_display` para ese bundle
  (taxonomy_term no trae uno por defecto). Se creó
  `core.entity_form_display.taxonomy_term.document_prefix.default.yml` con el
  componente `options_select`. Al crearlo con `$storage->create()` hay que
  pasar `targetEntityType` y `mode` (no `entity_type` ni `view_mode`), y las
  dependencias en `taxonomy.vocabulary.document_prefix`, o salta
  "Missing required properties for an EntityDisplay entity".
- Configuración exportada a `config/` con `drush config:export`: añade
  `field.storage.taxonomy_term.field_prefix_document_type.yml`,
  `field.field.taxonomy_term.document_prefix.field_prefix_document_type.yml`,
  `core.entity_form_display.taxonomy_term.document_prefix.default.yml` y
  `saibher_bms_core.settings.yml`. Los 5 archivos de `config/` que aparecen
  modificados (vistas y roles) ya lo estaban antes de este trabajo.
- Verificado: los 3 selectores de `/bms/configuracion` ofrecen solo su tipo
  (FV / FC / TK), guardar `prefijo_ticket: TK` persiste y no vacía el resto,
  `/bms/facturar` solo muestra FV y `/bms/comprar` solo FC. `php -l` sin
  errores y `phpcs` limpio en `DocumentFormControllerBase` (se corrigieron sus
  docblocks, que estaban vacíos) y en `PosSettingsForm` (0 errores, 2 avisos
  por `\Drupal::`).
- Pendiente: sin commit (no se pidió).

## Round 22 — Estilo de alertas del sistema (mensajes de Drupal) — COMPLETADO
- Petición: dar estilo al sistema de mensajes que Drupal ya trae, con el
  reparto de colores de Bootstrap (una severidad por color). No se pidió un
  sistema de alertas nuevo, así que solo se estilizó el existente: mismo bloque
  `system_messages_block`, mismo Messenger, sin JS ni módulo nuevo.
- Diagnóstico previo (importante para no repetirlo): en Drupal 11.3.11 el
  template genérico `core/modules/system/templates/status-messages.html.twig`
  **ya no emite** las clases `messages` / `messages--{type}`. Solo Claro lo
  hace, con su propio override. Además el bloque de mensajes está en la
  región `header`, dentro de `.sb-topbar`, que es una barra fija de 64px: los
  mensajes quedaban aplastados dentro de la barra, con `top` negativo y
  recortados (contenedor medido en `y: -138`, altura 240px dentro de 64px).
  Y el `<h2 class="visually-hidden">` del core se veía porque el tema no
  define esa clase (sí define `.sb-visually-hidden`).
- Solución: override `templates/misc/status-messages.html.twig` (misma ruta
  que usa Claro) que conserva el marcado accesible del core —`role="contentinfo"`,
  `aria-label`, `role="alert"` en errores y encabezado oculto con
  `sb-visually-hidden`— y añade `messages messages--{type}` más
  `sb-alert sb-alert--{modifier}`. El mapa de severidad es
  status→success, warning→warning, error→error, info→info, con iconos
  Material Symbols (`check_circle`, `warning`, `error`, `info`). No hizo falta
  `hook_preprocess` porque se usa `aria-label` en vez de `aria-labelledby`
  (así no se depende de `title_ids`, que solo aporta Claro).
- CSS en `06-cards-badges.css`: se completó el `.sb-alert` que existía pero
  nunca se usó. Reparto tipo Bootstrap —fondo tenue, borde de 1px y barra de
  color a la izquierda— con la barra hecha con
  `box-shadow: inset 3px 0 0 0 currentColor` en vez de `border-left`, porque
  `border-color` de las variantes la pisaría. Se reutilizaron los tokens que
  ya estaban en las cuatro variantes (`--sb-primary-fixed`, `--sb-blue-light`,
  `--sb-blue-dark`, `--sb-green-primary`, `--sb-green-dark`,
  `--sb-warning-container`, `--sb-red-secondary`, `--sb-error-container`,
  `--sb-error`, `--sb-on-error-container`).
- `.sb-topbar .sb-alerts` saca la pila del flujo: `position: absolute`,
  `top: calc(100% + 8px)`, `z-index: var(--sb-z-toast)`. Cae bajo la barra en
  vez de dentro de ella. La barra se mantiene en 64px y el menú de usuario no
  se mueve al haber mensaje.
- ⚠️ Learned: usar `width: min(26rem, calc(100vw - …))` descuadra 15px a la
  derecha porque `100vw` incluye el scrollbar. Correcto es `width: 26rem` +
  `max-width: calc(100% - 2 * var(--sb-space-lg))`, que resuelve contra la
  caja de contenido del topbar y alinea el borde derecho con el contenido
  (1440px: panel en 1401 = borde del contenido; 390px: 24→351, sin overflow).
- Verificado por estilos calculados y geometría, no a ojo: success
  `rgba(131,230,18,0.12)` / texto `#4A8A00`, error `#FFDAD6` / `#93000A` con
  `role="alert"` conservado, warning `#F8E6DF` / `#945341`; radio 8px,
  14px/20px, padding 16px (tokens correctos); icono con
  "Material Symbols Outlined". Sin mensajes **no se renderiza** el
  contenedor, así que no hace falta `:empty` ni guarda de clics.
- ⚠️ Learned: al probar con el formulario de configuración, el POST escribe
  todos los valores, así que `numero_inicial_factura` (que estaba sin definir)
  quedó en 5000. Se revirtió con
  `ddev drush php:eval 'Drupal::configFactory()->getEditable("saibher_bms_core.settings")->clear("numero_inicial_factura")->save();'`
  porque **`config:unset` falla en este proyecto** (igual que `config:import`).
  La configuración activa volvió a su estado original.
- Nota: la configuración exportada en `config/saibher_bms_core.settings.yml`
  sigue desfasada respecto a la base de datos (`nombre_empresa: Saibher` en la
  BD, vacío en el YAML). Preexistente, no lo toqué.
- Pendiente: sin commit (no se pidió). Sigue pendiente la ruta
  `saibher_bms_core.print_templates`.

## Round 23 — Borrado de datos de prueba (facturas y movimientos) — COMPLETADO
- Petición: eliminar todos los datos de facturas y movimientos de prueba.
- ⚠️ Learned: facturas y movimientos **no** son tablas propias, son nodos. Los
  tipos de contenido son `document` (facturas/compras/tickets) y `movement`.
  Los hijos tampoco se borran en cascada porque los párrafos se enlazan por
  campo de referencia y sus columnas `parent_*` quedan a NULL: hay que
  borrarlos a mano o quedan huérfanos.
- Inventario previo (todo con títulos `DEMO`, sin excepción):
  - 21 nodos `document` (nid 6, 15, 24 … 186).
  - 21 nodos `movement` (nid 8, 17, 26 … 188).
  - 21 párrafos `document_item` (líneas de factura), vía
    `field_document_item`.
  - 21 párrafos `payment` (formas de pago), vía `field_payment_document`.
  - Nada apunta a nodos `document` o `movement`, así que no quedan referencias
    colgando en ninguna dirección.
- ⚠️ Learned: `field_customer_document` apuntaba a datos incoherentes de
  demostración (2 `document_item`, 4 `location` y 3 `payment`, no uno por
  factura). Los `location` son compartidos con clientes y proveedores, así
  que **no** se tocan: solo se borran `document_item` y `payment`.
- Respaldo previo: `drush sql:dump --gzip` copiado a
  `/tmp/opencode/saibher-antes-de-borrar-demo.sql.gz` (2,0 MB) e integridad
  verificada con `gzip -t`, por si hay que revertir.
- Borrado con la API de entidades (no SQL directo) para que se limpien
  revisiones, alias e índice: 84 entidades en total. Script temporal en
  `/tmp`, ejecutado con `ddev exec … drush php:script`.
- Verificado: 0 nodos `document` y 0 `movement`; 0 párrafos `document_item` y
  `payment`; sin alias obsoletos; sin entradas huérfanas en `search_index`;
  barrido de las 66 tablas de campo (`node__field_*`, `node_revision__field_*`,
  `paragraph__field_*`, `paragraph_revision__field_*`) sin una sola fila
  huérfana. Caché reconstruida y las cuatro vistas (`/bms/facturacion`,
  `/bms/movimientos`, `/bms/facturar`, `/bms/inventario`) correctas y sin
  errores de consola.
- Intacto (fuera del encargo, los títulos también son DEMO): 21 clientes,
  21 proveedores, 21 carteras (`debtors`), 21 registros de caja (`register`),
  21 tipos de impuesto (`tax_register`), 26 productos y los 42 párrafos
  `location`. Pendiente de decidir si también se limpian.
- ⚠️ Learned: `(int) $db->query($sql)->fetchCol()` **no** convierte el
  resultado: `fetchCol()` devuelve un array y `(int) array("0")` vale **1**,
  no 0. Un barrido con ese cast dio 66tables y "1 huérfano" en cada una, un
  falso positivo. Correcto: `(int) ($db->query($sql)->fetchCol()[0] ?? 0)`.
- ⚠️ Learned: también hay 7 imágenes huérfanas (`test-cat.png` ×5,
  `test-img_2.png`) sin uso en `file_usage`. **Preexistentes**: la tabla
  `file_usage` tenía 3 filas antes del borrado y 3 después, así que no las
  dejó el borrado. No se tocaron.
- Nota: el entity type de párrafos es `paragraph`, no `paragraphs_item`
  (aunque las tablas sí se llamen `paragraphs_item_field_data`).
- Nota: en este proyecto `drush route:list`, `drush search:*` y
  `config:unset` no existen; `config:import` tampoco funciona. Para listar
  rutas hay que leer el `.routing.yml` del módulo.
- Pendiente: sin commit (no se pidió).

## Ronda 24 — Autocompletado de la parte del formulario (cliente/proveedor)

Objetivo pedido por Andrés: que el autocompletado de la parte del formulario
funcione contra el content type y que, al elegir, se completen solos los
campos del cliente. Regla de trabajo: **toda la funcionalidad va en el
servicio del módulo**; el controlador y el JS solo adaptan y pintan.

### Qué se hizo

- Nueva capa de servicios `web/modules/custom/saibher_bms_core/src/Service/`:
  - `PartyDirectoryInterface` — contrato: `bundles()`, `search()`, `get()`.
  - `PartyDirectory` — implementación con `EntityTypeManagerInterface`.
    Todo lo que antes estaba dentro del controlador: consulta de la parte,
    sucursales (párrafos), tipo de identificación y options del select.
- Registro en `saibher_bms_core.services.yml` usando **la interfaz como ID de
  servicio** y la clase concreta en `class:`.
- `src/Controller/PartyAutocompleteController.php` — dos acciones, sin lógica
  de negocio: `search()` y `detail()`. Responde `Cache-Control: no-store,
  private`.
- Rutas nuevas en `saibher_bms_core.routing.yml`:
  - `saibher_bms_core.party_autocomplete` → `/bms/autocompletar/{party}`
  - `saibher_bms_core.party_detail` → `/bms/autocompletar/{party}/{party_id}`
  - Ambas con `_permission: 'access saibher billing form'` y
    `party: 'customer|supplier'` (la ruta ya rechaza otros bundles con 404).
- `DocumentFormControllerBase`: se elimination `parties` y `documentTypes` del
  payload; ahora pasa `party` + `autocomplete.search` + `autocomplete.detail`
  + `autocomplete.minTerm`. Se borraron los métodos `sedes()` y
  `documentTypeLabel()` (ya viven en el servicio) y el abstracto
  `partyDocTypeField()` de las dos subclases.
- `js/billing-form.js`: la búsqueda de la parte ahora es `fetch` con debounce
  de 250 ms, contador de petición para que una respuesta lenta no pise a una
  nueva, y estados de "sin coincidencias" y de error. `applyParty()` completa
  nombre, sede, tipo de identificación y número.

### Decisiones

- El navegador **ya no recibe la lista de clientes**: se consulta por
  escrito. Antes iban 21 clientes completos dentro de `drupalSettings`.
- Un solo servicio para clientes y proveedores: el bundle decide qué campos
  leer (`field_document_type_customer` vs `..._supplier`, etc.).
- El servicio garantiza que el tipo de identificación resuelto esté en las
  options que devuelve; así el select nunca queda vacío aunque el tipo venga
  deducido (`NIT` / `Cédula de ciudadanía`).
- La URL de detalle se genera con el marcador alfanumérico `PARTYID` y el JS
  lo reemplaza. Por eso `party_id` **no** lleva `'\d+'` en el routing (esa
  validación impide generar la URL); el `ctype_digit()` se hace en el
  controlador, que responde 404.
- La búsqueda exacta por identificación normaliza el término **antes** de la
  consulta. Sin eso, un número escrito con espacios no llegaba a candidato en
  la base de datos y devolvía 0 resultados (bug detectado y corregido).

### Verificado

- Servicio: búsqueda por nombre/contacto/número, límite de 10, término corto,
  sin resultados, ID inexistente → `NULL`, bundle inválido →
  `InvalidArgumentException`.
- Endpoints: 200 con `no-store, private`; `product` → 404; ID no numérico → 404.
- Navegador en `/bms/facturar`: autocompletado de los 4 campos, reset con
  "Cambiar de Cliente", estado vacío, y búsqueda por identificación con y sin
  espacios. Sin errores de consola.
- `/bms/comprar` reutiliza el mismo servicio con `party: supplier`.
- `php -l`, `node --check` y `phpcs` (Drupal standard) limpios en los archivos
  tocados. phpcs sigue señalando deuda previa en otros controladores del
  módulo, no relacionada con este cambio.
- Borrado el script temporal de pruebas del repositorio.

### Pendiente

- Sin commit (no se pidió).
- El formulario sigue sin persistir el documento; este cambio es solo la
  parte. Queda por mover a servicios los datos de productos, prefijos,
  medios de pago y colaboradores si se quiere el mismo criterio.

## Ronda 25 — Consecutivo de documentos según el número inicial

Regla pedida por Andrés para elegir el número de factura:

- Si hay **número inicial** en la configuración (`numero_inicial_factura`,
  `_compra` o `_ticket`), se revisa que no exista ninguna factura con ese mismo
  número o superior.
  - Si no existe → se toma el número inicial de la configuración.
  - Si existe → se suma uno para continuar el consecutivo.
- Sin número inicial, manda el consecutivo automático.

Equivale a `max(semilla, mayor emitido + 1)`, implementado con las dos
ramas explícitas para que la regla de negocio se lea en el código.

### Qué se hizo

- Nuevo servicio `src/Service/DocumentNumberingInterface` +
  `DocumentNumbering`, registrado en `saibher_bms_core.services.yml` con
  `config.factory` y `entity_type.manager`.
  - `kinds()` → `venta`, `compra`, `ticket`.
  - `initial($kind)`, `highest($kind)`, `next($kind)`.
- Mapa de tipos: el prefijo declara su tipo en `field_prefix_document_type`
  (`venta`), la configuración lo llama de otra forma
  (`numero_inicial_factura`). El mapa vive en una constante del servicio
  (`SETTINGS`), igual al inverso de `PosSettingsForm::documentGroups()`.
- `highest()` ordena por número descendente y trae un solo nodo, así que no
  carga todos los documentos emitidos.
- `DocumentFormControllerBase`: inyecta el servicio y llama
  `next($this->prefixKeyword())`. Se eliminó el método privado
  `nextConsecutive()`.
- `PosSettingsForm`: el aviso de "número inicial" ahora lo decide el servicio
  y dice cuál será el próximo número, en vez de ignorar el valor cuando
  hubiera cualquier documento con el prefijo.

### Defectos corregidos de paso

- `nextConsecutive()` se llamaba como `$this->nextConsecutive($term)` pero no
  declaraba parámetros: PHP aceptaba el argumento sobrante y lo ignoraba, así
  que **todos los prefijos obtenían el mismo consecutivo**, calculado sobre
  todos los documentos y sin mirar la configuración. Además el argumento se
  desperdiciaba, así que la serie de compra nunca fue independiente de la de
  venta.
- Se eliminó `saibher_bms_core_has_documents_for_prefix()` del `.module`: su
  único llamador era el aviso del formulario, que ahora usa el servicio.
  Quedó como código muerto.
- `catch (\Exception $e)` sin usar la variable en `PosSettingsForm` (phpcs).

### Verificado

Con la serie vacía, con documentos por debajo/igual/por encima de la semilla,
con series de venta y compra independientes, con un documento sin prefijo
(limita a todas las series para no duplicar) y con un tipo inválido. Todo OK.

En el navegador: sin semilla → `FV-00001`; con semilla 1001 → `FV-01001`;
tras crear una factura 1001 → `FV-01002` y aviso coherente al guardar. Datos de
prueba borrados y `numero_inicial_factura` volvió a estar vacía.

`php -l` y `phpcs` limpios en lo tocado.

### Nota

`saibher_bms_core_pos_setting()` sigue sin llamadores en el módulo: es código
muerto previo a este trabajo, no se tocó por estar fuera del alcance.

### Pendiente

- Sin commit (no se pidió).
- El formulario todavía no persiste la factura; el consecutivo se calcula al
  cargar la página. Cuando se persista hay que revisar la concurrencia (dos
  usuarios pueden generar el mismo número).

## Ronda 26 — Autoguardado del borrador en el formulario de factura/compra — COMPLETADO

### Qué se pidió

Que el formulario de `/bms/facturar` y `/bms/comprar` guardara solo, sin
botón: al elegir la parte o al agregar/modificar un ítem se crea un nodo del
tipo `document` y se vuelve a guardar **el mismo** nodo en cada cambio
posterior. También un campo de estado con los valores de la regla de negocio
y que el número quede reservado desde el primer autoguardado.

### Decisiones

- El borrador vive en el content type transaccional `document` con
  `field_document_status = borrador`. El estado es un `list_string` con
  `borrador`, `pendiente_pago`, `pagada` y `cerrada`.
- El número se reserva en la **primera** escritura y no vuelve a pedir
  consecutivo mientras se edita el mismo borrador. Por eso las series de
  venta y compra arrancan en 1 de forma independiente.
- Toda la lógica de negocio va en servicios. El controlador y el script solo
  adaptan, coordinan y presentan; los importes se recalculan en el servidor.
- El script envía una sola petición a la vez: si el servidor responde lento,
  los cambios siguientes se acumulan en vez de apilarse.
- No se guarda nada mientras el formulario esté vacío: hace falta una parte o
  una línea.

### Servicios y endpoint

- `ProductPricingInterface` / `ProductPricing`: regla compartida de PVP e IVA
  que usan el alta de productos y el autoguardado.
- `DocumentDraftInterface` / `DocumentDraft`: crea o actualiza el nodo,
  reserva el consecutivo, sincroniza los párrafos `document_item` y `payment`
  por posición (reordenar actualiza, lo eliminado se borra) y devuelve id,
  código, estado y totales.
- `DocumentDraftController` + ruta `POST /bms/documentos/{party}/borrador`
  con `_csrf_request_header_token`, que devuelve JSON privado y `no-store`.
- `DocumentFormControllerBase` expone en `drupalSettings` la URL y el token
  CSRF, de modo que el navegador nunca recibe listas de clientes ni de
  proveedores.

### Interfaz

- Indicador de estado del borrador en la tarjeta «Datos de la factura»:
  «Guardando el borrador…», «Borrador FV-00001 guardado.» o el aviso de
  error, con los colores del sistema visual.
- Cada fila de ítem lleva el id del producto para que el servidor resuelva
  precio e impuesto.

### Verificación

- En el navegador: elegir cliente creó el borrador `FV-00001`; agregar dos
  productos, cambiar una cantidad a 3 y registrar pagos **no** generaron un
  segundo nodo ni un segundo número. Al quitar una línea el párrafo se borró
  y no quedaron huérfanos.
- El servidor devolvió los totales y la pantalla los adopta, de modo que lo
  que se ve y lo que está guardado nunca discrepan.
- `/bms/comprar` creó `FC-00001` en su propia serie.
- Una petición sin `X-CSRF-Token` responde 403.
- `node --check` y `phpcs` limpios en lo tocado; sin errores de consola.
- Al final se borraron los documentos de prueba y `probar_borrador.php`.

### Incidente de datos

Un script de limpieza sin filtro borró los 132 nodos y 52 párrafos de
demostración, incluidos clientes, proveedores y productos. No hubo respaldo
recuperable. Se regeneró el juego de datos (26 productos, 21 clientes, 21
proveedores, 3 registros y 42 sedes) con un seed en
`/tmp/opencode/sembrar_demo.php`, fuera de la raíz servible.

Durante esa regeneración se descubrió que `field_locations` es
`entity_reference_revisions` y necesita **las dos** claves, `target_id` y
`target_revision_id`: con una sola el valor se considera vacío, la validación
lo rechaza y la sede no aparece en el formulario.

### Pendiente

- Sin commit (no se pidió).
- El campo `field_product` del párrafo sigue siendo `string`: guarda la
  etiqueta del producto, no su relación. Conviene decidir si pasa a
  referencia.
- «Asignar a colaborador» todavía no se persiste: no hay campo para ello en
  el tipo `document`.

## Ronda 27 — Columna de estado en la tabla de facturación — COMPLETADO

### Por qué no se veían los documentos

No era un filtro: la vista `saibher_documents` (en `/bms/facturacion`, no
`/bms/facturas`) solo filtra por tipo de contenido y por el buscador de texto,
así que los borradores **se muestran**. La tabla estaba vacía porque al
terminar la Ronda 26 se borraron los documentos de prueba.

Se comprobó además que cargar `/bms/facturar` **no** crea un borrador por sí
solo: el guardado espera a que haya una parte o una línea.

### Qué se añadió

- Columna **Estado** en la vista `saibher_documents`, entre «Tipo» y «Total».
- Badge de estado en
  `web/themes/saibher_bms/templates/views/views-view-field.html.twig`, el
  mismo override que ya coloreaba los movimientos. Se reutilizan las mismas
  clases `sb-badge-soft` y por tanto los mismos colores:

| Estado del documento | Clase | Color |
| --- | --- | --- |
| Borrador | `sb-badge-soft--neutral` | `#444656` |
| Lista, pendiente de pago | `sb-badge-soft--warning` | `#B45309` |
| Pagada | `sb-badge-soft--success` | `#4A8A00` |
| Cerrada | `sb-badge-soft--info` | `#1242E6` |

Borrador queda en neutro a propósito: todavía no es una transacción
confirmada. El mapeo acepta tanto la clave guardada (`pendiente_pago`) como
la etiqueta del `list_string` («Lista, pendiente de pago»), porque el campo se
renderiza con su etiqueta.

### Verificación

- Con un documento por estado, la columna/badge salió correcta en los cuatro
  casos con los valores de color esperados.
- La tabla de movimientos se comprobó con Entrada, Salida, Ajuste y Pérdida:
  sigue mostrando sus cuatro colores, sin regresión por compartir override.
- Documentos y movimientos de prueba borrados al final.
- Nota: el seed de la Ronda 26 no crea movimientos de inventario, por eso esa
  tabla aparecía vacía; no es un fallo.

## Ronda 28 — «Editar» de una factura abre el formulario del panel — COMPLETADO

### Qué pedía

En `/bms/facturacion`, el action **Editar** de cada fila abría el formulario de
nodo de Drupal dentro de una modal (`data-dialog-edit` →
`saibher_bms_core.node_edit_form`). Ahora debe abrir el formulario propio del
panel con la factura ya cargada: `/bms/facturar?documento=<nid>` (y
`/bms/comprar?documento=<nid>` para facturas de compra).

### Qué se hizo

- `saibher_bms_core_preprocess_views_view_table()`: en la vista
  `saibher_documents` el action **Editar** apunta a la ruta del formulario que
  corresponde según `field_document_type_document` (`Factura de compra` →
  `saibher_bms_core.purchase_form`, resto → `saibher_bms_core.billing_form`) y
  **no** lleva `data-dialog-edit`, así que navega en lugar de abrir la modal. El
  resto de tablas (clientes, proveedores, inventario, categorías, registro
  financiero) conservan la modal.
- `DocumentFormControllerBase`: lee `?documento=<nid>` y añade
  `billingForm.document` a `drupalSettings` con lo guardado (id, número, prefijo,
  parte, líneas y pagos). Solo acepta un documento de esa serie con permiso
  `view` + `update`; cualquier otro caso devuelve `NULL` y el formulario abre
  vacío. Método abstracto nuevo `documentKind()` ('venta' / 'compra') implementado
  en `BillingFormController` y `PurchaseFormController`.
  - **Peculiaridad**: Drupal 11 no inyecta `Request $request` en este tipo de
    controlador de página (llegó `null`); se resolvió inyectando
    `RequestStackInterface` en el constructor vía `create()`.
- `billing-form.js`: bloque «Carga de un documento guardado». Repinta prefijo y
  **consecutivo propio** (no el siguiente de la serie), las líneas (con el
  producto del catálogo emparejado por nombre, que es lo que guarda el párrafo) y
  los pagos; pide la parte por el servicio de autocompletado. El autoguardado se
  silencia mientras carga (`state.hydrating`), así que **abrir una factura no
  escribe nada**; el siguiente cambio actualiza ese mismo nodo, sin duplicados.
  - `addProduct()` acepta una línea guardada para restituir cantidad y valor
    unitario sin robar el foco.
  - El prefijo seleccionado se resuelve por la posición de la opción
    (`CONFIG.prefixes[selectedIndex]`), no por su valor: los prefijos de una
    serie comparten consecutivo y el emparejamiento por valor elegía el
    equivocado.

### Verificación

- Factura de prueba con cliente, 2 líneas (una con IVA 19 %) y un pago: se
  cargó cliente (nombre, sede, tipo y número), ambas líneas con sus importes
  recalculados, el pago y el estado «Editando el borrador FV-00002».
- Al cambiar una cantidad se actualizó **el mismo nodo** (sin crear otro) y los
  totales del servidor coincidieron con los de pantalla.
- Abrir la factura no modificó el nodo (`changed`/revisión intactos).
- Compra: `FC-00001` abrió en `/bms/comprar?documento=<nid>` con proveedor y
  línea; esa misma compra abierta en `/bms/facturar` abre vacía (series
  distintas).
- `?documento=` con un producto, un id inexistente o vacío: formulario vacío,
  sin error.
- `/bms/facturar` y `/bms/comprar` sin parámetro siguen creando un documento
  nuevo (FV-00003 / FC-00001).
- Documentos de prueba eliminados al final; la numeración vuelve a FV-00002.

## Ronda 29 — Separadores numéricos colombianos en todo el panel — COMPLETADO

### Qué pedía

Todos los números del proyecto propio deben mostrarse con **punto** como
separador de miles y **coma** como separador decimal (`6.900,00`), en tablas,
fichas, montos de PHP y formularios.

### Qué se hizo

- **Formatos de Drupal** (32 formateadores `number_decimal` / `number_integer` en
  24 archivos de `config/`): `thousand_separator: '.'` y
  `decimal_separator: ','`, conservando el `scale` por defecto (2 decimales).
  Archivos: `views.view.saibher_documents`, `views.view.saibher_financial_register`,
  `views.view.saibher_inventory`, `views.view.saibher_movements` y todos los
  `core.entity_view_display.*` de `customer`, `debtors`, `document`, `movement`,
  `product`, `register`, `tax_register`, `paragraph.document_item`,
  `paragraph.payment` y `taxonomy_term.tax`.
- **PHP** (`saibher_bms_core.module`): `saibher_bms_core_money()` (montos) y la
  etiqueta `$quantity_label` de `saibher_bms_core_preprocess_views_view_table()`
  (cantidades de la tabla de movimientos) ahora usan
  `number_format($v, $dec, '.', ',')`.
- **Twig**: `node--product--full.html.twig` (stock, mínimo, unidades vendidas,
  costo, pvp, margen y margen %) y `ficha-entity.html.twig` (stock y costo de los
  productos asociados de un cliente/proveedor) usan
  `|number_format(0, ',', '.')`.
- **JS**: `billing-form.js` ya usaba `Intl.NumberFormat('es-CO', {currency: 'COP'})`
  (puntos para miles, comas para decimales); no necesitó cambios.

### Peculiaridades descubiertas (importantes)

1. **El filtro Twig `number_format` tiene el orden invertido frente a PHP**:
   `number_format(numero, decimales, punto_decimal, separador_miles)`. Para
   puntos/comas es `|number_format(0, ',', '.')` — no `|number_format(0, '.', ',')`,
   que produce `4,900`.
2. **El widget `number` de core NO tiene separadores**: siempre renderiza
   `<input type="number">` y sus únicos ajustes son `placeholder`
   (`NumberWidget::formElement()` fija `'#type' => 'number'`). Añadirle
   `thousand_separator`/`decimal_separator` es configuración muerta, así que solo
   setocaron los **formateadores** de vista, nunca los widgets de formulario.
3. **Teléfonos no son montos**: en las fichas el número de contacto se muestra tal
   cual (`310000000`), porque es un identificador, no una cantidad.

### Verificación

- `/bms/inventario`: `30,00`, `9,00`, `6.900,00`, `12.500,00`.
- `/bms/facturacion`: total `12.500,00`.
- Ficha de producto 227: `$ 4.900`, `$ 6.900`, `$ 2.000 (29%)`.
- Ficha de proveedor: `$ 0` en los montos y el teléfono sin formato.
- Modal de edición de producto: sigue funcionando (los campos numéricos son
  `type="number"` con el valor crudo, igual que antes).
- Formulario de facturación: `$ 6.900`, `$ 1.311`, `$ 8.211`.
- YAML validado (sin claves duplicadas) en los 500 archivos de `config/`; se
  sincronizó el conjunto completo a la configuración activa y `ddev drush cr`.
- El borrador que creó la prueba del formulario se eliminó (nodo 294 + párrafo);
  la numeración vuelve a `venta: 2`, `compra: 1`.
- `/bms/movimientos` y el bloque de registro financiero se ven vacíos en este
  entorno (no hay movimientos registrados), así que su formato solo se verificó en
  la configuración.

## Ronda 30 — Inventario en ventas: reserva, cierre real y devoluciones — COMPLETADO

### Qué pedía

Que la factura descuente inventario de verdad: mientras el documento está en
borrador sus movimientos son provisionales, al cerrar quedan definitivos, y el
formulario avisa cuando no hay existencias. Además, que imprimir solo ocurra
después de cerrar y que las devoluciones se registren como movimientos.

### Qué se hizo

- **Servicio de inventario** (`InventoryMovements` +
  `InventoryMovementsInterface`): reconciliación por producto con bloqueo
  (`lockBackend`), transacción propia y `commitOrRelease()`. Reescribe los
  movimientos del borrador (subir, bajar o quitar una línea los ajusta), y sobre
  documentos cerrados calcula la diferencia: más unidades son una salida extra y
  menos unidades una `Entrada por devolución`. Tipos nuevos: `Salida por venta` y
  `Entrada por devolución`.
- **Interruptor de inventario** (`prohibir_venta_sin_inventario` en
  `saibher_bms_core.settings`): encendido impide guardar o cerrar sin
  existencias; apagado permite negativos y devuelve `stockWarnings`.
- **Columna Disponible** en el formulario de factura: compara contra unidades
  libres (`stock + reserved`), ajusta la cantidad al máximo y avisa con «Cantidad
  ajustada al inventario» o «Sin inventario para vender».
- **Cierre**: `DocumentDraft::finalize()` valida productos y cliente antes de
  escribir, calcula el estado (`pagada` / `pendiente_pago`) y la impresión ocurre
  una sola vez, con el formulario bloqueado después.
- **Atomicidad**: documento, párrafos, pagos y movimientos se escriben en una
  sola transacción; un rechazo por inventario revierte todo y limpia la caché del
  nodo.
- **Movimientos** (`saibher_movements`): campos `field_movement_product` y
  `field_movement_document`, columna **Factura** con enlace al documento y badge
  del tipo de movimiento; la ficha de producto excluye los movimientos
  provisionales del borrador.
- **Errores en español**: `DocumentDraftController::close()` responde `400` con
  el motivo y el JS lo muestra en vez de «Revisa la conexión»; el `422` por
  inventario detiene el cierre sin imprimir.
- **Borrado de borradores** (`InventoryMovements::releaseDraft()` +
  `hook_entity_delete`): al eliminar un borrador sus movimientos se borran y las
  unidades reservadas vuelven al almacén. Un documento cerrado conserva sus
  movimientos aunque se borre, porque son historia del inventario.

### Peculiaridades descubiertas

1. **El par inicial/final del movimiento de un borrador es acumulado**: refleja
   el documento completo, no el autoguardado. Con stock 48 y una línea que sube a
   2, el movimiento queda `48 → 46`, así que `inicial - final` siempre cuadra con
   la cantidad.
2. **`field_document_item` y `field_payment_document` son `entity_reference`
   simple**, no `entity_reference_revisions`. Por eso Drupal nunca escribe
   `parent_field_name` y `Paragraph::label()` devolvía «Orphaned Item de
   documento: …» en la vista del nodo (`/facturacion/N`). Guardar los párrafos
   desde el propio guardado del documento **no lo arregla** (Drupal solo rellena
   el padre con el campo de revisiones). Se resolvió por partida doble:
   `DocumentDraft::ownParagraph()` escribe `parent_type`, `parent_field_name` y
   `parent_id` (para eso el documento se guarda una vez antes de sus párrafos,
   dentro de la misma transacción), y el detalle se muestra con el formateador
   propio `saibher_paragraph_summary`, que imprime el resumen del párrafo en vez
   de su etiqueta administrativa.
3. **Borrar un documento no borra lo que cuelga de él**: el movimiento es otro
   nodo y los párrafos son otros nodos más. Sin intervene, borrar un borrador
   desde Documentos perdía mercancía (stock descontado, sin movimiento) y dejaba
   51 párrafos de detalle huérfanos. `hook_entity_delete` ahora borra el detalle
   y devuelve las unidades reservadas; los movimientos de un documento cerrado se
   conservan porque son historia del inventario.
4. **Las vistas no tienen estado vacío**: `/bms/movimientos` sin movimientos
   muestra la tabla con su encabezado y nada más, igual que las demás listas del
   panel. No se inventó un patrón nuevo para esto.
5. **`drush config:import` sigue roto** en este proyecto: la configuración se
   sincroniza con el script de apoyo y luego `ddev drush cr`.

### Verificación

- Suite de servicio (`php:script`, 12 pasos): creación, edición, línea quitada,
  guardado repetido idempotente, interruptor encendido/apagado, cierre con
  estado, devolución de 6 unidades, rechazo de un segundo cierre, borrador corto
  guardado con el interruptor apagado y rechazado al encenderlo, y rechazo sin
  dejar documento huérfano.
- Traza de autoguardados: una sola fila de movimiento por producto,
  idempotente, `48 → 43` con cantidad 5, `48 → 40` con 8 y `48 → 46` con 2.
- Borrado de borrador por API: stock `116 → 120` y movimiento eliminado;
  documento cerrado: stock y movimiento se conservan.
- Borrado de documento por API y por el formulario de confirmación: párrafo y
  movimiento eliminados, stock devuelto (`116 → 120`) y sin párrafos huérfanos.
- Navegador: flujo completo con impresión única y formulario bloqueado, ajuste
  estricto explicado, cierre rechazado con `422` sin imprimir, cierre válido con
  stock final en 0, columna Factura enlazando a `FV-00002`/`FV-00003` y badge
  `Salida por venta`.
- Ficha de un documento: `Item de documento → DEMO - Bebida Gaseosa de cola 1.5 L`
  y `Pagos → Efectivo, total`, sin «Orphaned».
- Entorno limpio al terminar: solo el borrador `FV-00001` (nodo 290), sin
  movimientos, sin párrafos de detalle huérfanos, productos en `120 / 60 / 48` e
  interruptor apagado.

## Ronda 31 — Registros financieros automáticos al cerrar una venta — COMPLETADO

### Qué se pidió

Al cerrar una factura de venta deben quedar guardados en el registro financiero,
por separado y sin depender de que el POS los escriba a mano:

- **Ingresos**: el total con impuestos.
- **Impuestos**: el valor de los impuestos de la factura.
- **Ingreso sin impuestos**: el subtotal de las líneas.
- **Ganancias**: el subtotal menos el costo de los productos vendidos.

Las preguntas que se aclararon antes de escribir código:

1. ¿Ingresos y ganancias juntos o por separado? → **Por separado**, cada uno en su
   propio registro del tipo `register`.
2. ¿Cuántos importes? → **Tres**: total, impuestos y subtotal; más las ganancias,
   cuatro registros en total.
3. ¿Solo al cobrar? → **Al cerrar**, tanto si la factura queda `pagada` como
   `pendiente_pago`. No se crea un flujo aparte para cobrar después.

### Qué quedó hecho

- `FinancialRegisterInterface` + `FinancialRegister` (servicio nuevo). Recibe la
  factura y crea los cuatro registros con `field_register_type`,
  `field_value`, `field_register_document` y el código de la factura en el
  título (`FV-00001 · Ingresos`).
- **Idempotencia**: `recordSale()` busca primero los registros que ya apuntan a
  esa factura (`field_register_document`) y los devuelve sin crear otros. Una
  segunda llamada devuelve los mismos cuatro.
- **Transacción**: `DocumentDraft::finalize()` envuelve el cambio de estado y los
  cuatro registros en la misma transacción; si un registro falla, la factura se
  queda en borrador y no queda ningún registro a medias.
- **Costo real**: `document_item` tiene el campo nuevo `field_product_node`, que
  guarda el producto como referencia y no solo como texto. El costo se toma del
  producto que hay al cerrar (con respaldo por título para las líneas viejas).
- La respuesta del cierre ahora trae `financial` con `income`, `taxes`,
  `netIncome` y `profit`.
- La vista `saibher_financial_register` y su bloque ganaron la columna
  **Factura** y el filtro **Tipo**; el formulario y la ficha del `register`
  muestran el documento.

### Decisiones que conviene no perder

- **El ticket también se registra** (`SELLING_KINDS = ['venta', 'ticket']`), pero
  el inventario sigue moviéndose solo en ventas (`STOCKED_KINDS = ['venta']`).
  Si el ticket debe quedar fuera del registro financiero, es una línea.
- El costo se lee **al cerrar**, no se guarda una foto del costo en la línea: si
  mañana cambia el costo de un producto, las facturas viejas no se recalculan.
- El prefijo de la factura lo manda el POS (`prefixId`). Sin prefijo, el código
  sale vacío y el registro se titula con el nombre de la factura.

### Peculiaridades del entorno (importantes)

1. **`ddev drush cr` no funciona**: al reconstruir caché reinicia el contenedor y
   ahí salta `Circular reference detected for service
   "Drupal\pathauto\Hook\PathautoEntityHooks" (… -> eca.service.token ->
   eca.token_data.current_user)`. El sitio web sí responde (200) y
   `ddev drush php:eval` sí arranca Drupal. Para limpiar caché usar un script:
   `\Drupal::service('cache.discovery')->deleteAll()` y lo mismo con
   `cache.default` y `cache.data`.
2. **`php:script` falla y `php:eval` funciona**: cuando el script toca entidades
   (`FieldStorageConfig::load()`), el comando `php:script` compila el contenedor
   de otra forma y salta la referencia circular. El rodeo que sí funciona es
   `ddev drush php:eval 'require "/tmp/mi-script.php";'`, con el archivo copiado
   al contenedor. Además el archivo debe tener nombre nuevo en cada copia: PHP
   guarda los scripts en opcache y el contenedor no ve el cambio.
3. **`php:eval` no admite varias líneas**: los saltos de línea rompen el comando.
   Todo el código va en un archivo y se carga con `require`.
4. **`field_register_type` no guardaba nada**: su `field.storage` traía
   `custom_storage: true`, así que quedaba fuera del esquema instalado y del mapa
   de tablas: los registros se creaban sin tipo. Se puso
   `custom_storage: false` y se registró la definición con
   `EntityLastInstalledSchemaRepository::setLastInstalledFieldStorageDefinition()`
   (`installFieldStorageDefinition()` revienta: el listener de `field_ui` pide
   `$entity_types['field_config']` cuando aún no está). Quedaron 69 definiciones
   instaladas en `node` y 29 en `paragraph`.
5. **Los pagos se mandan con la clave `value`**, no `amount`: con `amount` la
   factura salía `pendiente_pago` porque el pago se descartaba en silencio. Los
   pagos pendientes ya semapolean en `field_quantity` del párrafo de pago.

### Verificación

- Factura pagada (pago `Efectivo 30000` sobre un total de `29750`): estado
  `pagada`, `financial` = `{"income":29750,"taxes":4750,"netIncome":25000,
  "profit":14200}` y cuatro registros: `Ingresos 29.750`, `Impuestos 4.750`,
  `Ingreso sin impuestos 25.000`, `Ganancias 14.200`.
- Factura sin pago: estado `pendiente_pago` y los mismos cuatro registros.
- Idempotencia: la segunda llamada de `recordSale()` sobre la misma factura
  devuelve 4, no 8.
- Compra cerrada: 0 registros y el total de registros no cambia.
- Líneas con `field_product_node` apuntando al producto 213.
- Vista `saibher_financial_register`: los dos displays se ejecutan sin error y el
  bloque `saibher_financial_register_block` se instancia.
- `php -l` limpio en los tres servicios y `git diff --check` sin nada.
- Entorno limpio al terminar: solo la factura `FV-00001` (nodo 290), sus dos
  movimientos demo (`#399`, `#400`), los tres registros demo (`#208`-`#210`) y
  productos en `120 / 60 / 48`. La serie de facturas vuelve a `FV-00002`.
- Nota: los registros demo `#208`-`#210` quedaron sin tipo porque se crearon
  cuando el campo todavía no guardaba nada. Se les puede poner tipo a mano si
  sirven de ejemplo.

## Ronda 32 — Nequi y Daviplata como medios de pago — COMPLETADO

### Qué se pidió

Agregar **Nequi** y **Daviplata** a los medios de pago del POS, para que el cashier
pueda cobrar por billeteras digitales igual que por efectivo o transferencia.

### Cómo funciona en este proyecto

- Los medios de pago son **términos del vocabulario `payment_method`**
  (`taxonomy.vocabulary.payment_method.yml`).
- `DocumentFormControllerBase::formData()` arma la lista con las etiquetas de los
  términos y la manda al formulario como `paymentMethods`.
- El formulario (`.html.twig` + `js/billing-form.js`) arma un `<option>` por
  método; el `<select data-sb-pay-method>` sale vacío en el HTML a propósito.
- `DocumentDraft::normalisePayments()` resuelve el pago por **etilla exacta**
  (`mb_strtolower(trim($row['method']))`), sin fuzzy: si la etiqueta no existe, el
  pago se guarda sin término.
- El campo `Descripción` del pago (`data-sb-pay-desc`, placeholder «Nota,
  referencia de la transacción…») es justo donde se anota el número de operación
  de Nequi o Daviplata; se guarda en `field_reference` del párrafo de pago. No
  hizo falta tocar la interfaz.

### Qué quedó hecho

- Términos nuevos: **Nequi** (`#26`, peso 2) y **Daviplata** (`#27`, peso 3).
- Se Aprovechó para **ordenar el vocabulario** con peso: Efectivo 0,
  Transferencia bancaria 1, Nequi 2, Daviplata 3, y cada uno con su descripción
  («Se puede anotar el número de la operación» en las billeteras).
- `DocumentFormControllerBase` ahora **ordena por peso y luego por etiqueta**
  (`uasort`). Antes la lista salía en el orden que devolviera la consulta, que no
  está garantizado; con esto el desplegable siempre ofrece el mismo orden.

### Cosas que conviene no perder

1. **Los términos de taxonomía no son entidades de configuración en este
   proyecto**: `TermStorage` con 15 términos en la base y 0 filas
   `taxonomy.term*` en `config`. Por eso los medios de pago viven solo en la base
   y **no** se crean YAML en `config/`; un `taxonomy.term.payment_method.*.yml`
   en el repo sería una fuente de términos duplicados. Si algún día los términos
   pasan a ser config entities, ahí sí se exportan los cuatro.
2. Para dar de alta un medio de pago nuevo basta un término en `payment_method`
   con el nombre exacto que se va a mostrar: no hay listas de métodos escritas a
   mano en el código, en la plantilla ni en el CSS.

### Verificación

- Lista servida al formulario (página renderizada por dentro, respuesta `200`):
  `"paymentMethods":["Efectivo","Transferencia bancaria","Nequi","Daviplata"]`.
- Borrador con pago Nequi y con pago Daviplata: el párrafo de pago queda con su
  término (`Nequi`, `Daviplata`), el valor `5000` y la referencia `OP-1234`.
- Entorno limpio: los dos borradores de prueba se borraron y el stock del
  producto `#213` sigue en `48`, con 1 documento (`FV-00001`), 2 movimientos demo
  y sin párrafos de pago huérfanos.
- `php -l` limpio en el controlador y sitio en `200`.

## Ronda 33 — Total, abonado y restante en la modal de pago — COMPLETADO

### Qué se pidió

Que en la modal «Registrar pago» se vean **el total** de la factura y **el
restante**, restando del total el valor de los pagos que se van agregando.

### Qué quedó hecho

- El pie de la modal de pago pasó de un texto suelto («Total abonado: $ 0») a
  **tres cifras**: `Total`, `Abonado` y `Restante`, en
  `templates/saibher-billing-form.html.twig`.
- `renderPaySummary()` (en `js/billing-form.js`) calcula:
  - `total` = total de la factura (el mismo `[data-sb-total-total]` del resumen).
  - `abonado` = suma de los pagos ya agregados.
  - `restante` = `total − abonado − valor que se está escribiendo`, nunca
    negativo.
- **El restante descuenta también el valor en curso**: mientras el cashier
  escribe el valor del pago, la cifra baja en vivo y se ve el saldo *antes* de
  pulsar «Añadir pago». Cuando el pago cubre lo que falta, el restante marca `$ 0`
  y se pone en verde; si el pago se pasa, la etiqueta cambia a **«A favor»** para
  que el sobrante sea explícito y no parezca un error.
- Se refresca en todos los caminos: al agregar/quitar pago, al escribir el
  valor, al recalcular totales del documento y al abrir la modal.

### Cosas que conviene no perder

1. **El `<select>` de medios de pago sale vacío en el HTML**: las `<option>` las
   arma `js/billing-form.js` con el JSON `paymentMethods`. Para revisar la lista
   hay que mirar ese JSON en la página, no el HTML.
2. `renderPaySummary()` se llama desde `recalcTotals()`, que está definida antes
   en el archivo: solo funciona porque todas las llamadas ocurren dentro de
   manejadores de eventos o de `loadDocument()`, que se invoca al final de
   `attach()`. Si se llega a llamar `recalcTotals()` durante el arranque, la
   constante todavía no existe.
3. Los importes de la interfaz se parsean con `toNum()` sobre texto ya
   formateado (`$ 6.188`): quitar el punto de miles y cambiar la coma por punto.
   Es el mismo truco que usa `updatePayStatus()`.

### Verificación

- Navegador, factura con un producto (total `$ 6.188`):
  - Modal recién abierta: `Total $ 6.188 · Abonado $ 0 · Restante $ 6.188`.
  - Escribiendo `3000`: `Restante $ 3.188` en vivo, sin agregar el pago.
  - Pago agregado: `Abonado $ 3.000 · Restante $ 3.188`; el estado del resumen
    sigue en «Pendiente de pago».
  - Escribiendo `3188`: `Restante $ 0` en verde (`rgb(74, 138, 0)`).
  - Escribiendo `4000` (de más): etiqueta **«A favor»** y `Restante $ 0`.
  - Agregando ese pago: `Abonado $ 7.000`, estado «Pagada»; quitándolo vuelve a
    `Abonado $ 3.000 · Restante $ 3.188`.
  - Desplegable de medios de pago: Efectivo, Transferencia bancaria, Nequi,
    Daviplata.
- Layout: tres figuras en la misma línea (`y = 600`, alto 20), `gap: 16px 24px`,
  sin desborde; el «Restante» en azul primario `rgb(0, 46, 180)`.
- `node --check` limpio, `git diff --check` sin nada y sitio en `200`.
- Entorno limpio: borrados los dos borradores que dejó el navegador (`#478`,
  `#481`) con sus reservas; queda la factura `FV-00001` (`#290`), 3 registros y 2
  movimientos demo, productos en `120 / 60 / 48`.

### Ronda 33 (bis) — El cambio cuando se paga de más — COMPLETADO

Lo que faltaba de la ronda 33: al pagar de más se veía «A favor $ 0», que no le
dice nada al cashier ni al cliente. Ahora la diferencia es **el cambio**.

- `payBalance()` quedó como cálculo único (`total`, `paid`, `change`, `due`) para
  que la modal, el resumen y la factura impresa cuenten lo mismo.
- **Modal de pago**: la tercera cifra cambia de etiqueta y de color según el
  caso — `Restante` en **rojo** (`--sb-error`) cuando se debe algo, `Cambio` con
  el monto exacto en **verde** (`--sb-secondary`) cuando lo abonado se pasa, y
  `Restante $ 0` en **azul** (`--sb-primary`) cuando el saldo queda justo. Los
  tres estados se resuelven en JS con las clases `is-due`, `is-change` e
  `is-clear`. Sigue descontando el valor en curso, así que el color y el monto se
  ven antes de añadir el pago.
- **Resumen de la factura**: fila nueva «Cambio a entregar» que solo aparece
  cuando hay sobrante, con el importe en verde.
- **Factura impresa**: bloque «Cambio» debajo de los pagos, para que el cliente
  se lleve escrito lo que le devolvieron.
- El cambio **no se guarda**: siempre se deriva de `pagos − total`, que ya está
  en el documento. No hizo falta un campo nuevo.

### Verificación

Factura de `$ 6.188`, pago de `$ 7.000`:
- Escribiendo `7000`: `Cambio $ 812` en la modal (rgb(25, 51, 163)) y
  «Cambio a entregar $ 812» en el resumen.
- Pago agregado: `Abonado $ 7.000 · Cambio $ 812`, estado «Pagada».
- Factura impresa: `Total $ 6.188 · Pagos: Efectivo — $ 7.000 · Cambio $ 812`.
- Quitando el pago: vuelve a `Restante $ 6.188` y la fila de cambio se oculta.
- Colores de la tercera cifra (calculados en el navegador):
  | Caso | Valor | Color |
  |---|---|---|
  | Se debe | `Restante $ 6.188` / `$ 3.188` | rojo `rgb(230, 64, 18)` |
  | Justo al total | `Restante $ 0` | azul `rgb(0, 46, 180)` |
  | Cambio | `Cambio $ 812` | verde `rgb(74, 138, 0)` |
- Entorno limpio tras la prueba: `FV-00001` (`#290`), 3 registros, 2 movimientos,
  stock `120 / 60 / 48`.

### Ronda 34 — Reconstrucción de DocumentDraft y ficha del documento — COMPLETADO

**DocumentDraft.php** estaba corrupto: 611.074 líneas, sin `<?php`/`namespace`/
`class`, con `createDebtor()` repetido 26.536 veces. `php -l` pasaba (sin
`<?php` todo es HTML) pero el servicio reventaba en tiempo de ejecución y con él
el autoguardado y el cierre de documentos. **No había respaldo** (el archivo no
está en git, no había stash ni blob recuperable en `git fsck`), así que se
**reconstruyó la clase completa** contra su contrato (`DocumentDraftInterface`,
`DocumentDraftController`, `billing-form.js`) y las rondas 26/30/31/32/33.

Decisiones de la reconstrucción:

- Constructor con los 7 argumentos de `saibher_bms_core.services.yml` (no se
  tocó el wiring: un cambio obliga a recompilar el contenedor y `drush cr` está
  roto). El uid se lee con `\Drupal::currentUser()`.
- `save()`/`finalize()` comparten `persist()`: todo va dentro de una
  transacción (documento, párrafos, movimientos de inventario). `reconcile()`
  corre mientras el documento aún es borrador; solo después se fija el estado
  final, y con él los registros de caja y la cartera.
- Los importes se recalculan de los productos (precio del payload con respaldo
  en `ProductPricing`, IVA del producto), nunca se creen al navegador. Línea:
  `field_total = unit × qty` (sin IVA), `field_total_tax = taxUnit × qty`;
  documento: `subtotal`, `total_tax`, `total = subtotal + total_tax`.
- `createDebtor()` se conservó tal cual y se **limitó a las series de venta**
  (`venta`/`ticket`): el bundle `debtors` es la **cartera del cliente**
  (`field_customer_debtors`), así que una compra impagada no debe crear un
  cobro. Un cierre `pendiente_pago` crea el deudor; `pagada` no.
- Mensajes de cierre exactos: `A document without products cannot be closed.`,
  `A document without a party cannot be closed.`,
  `The party of the document no longer exists.`

**Ficha del documento** (`node--document--full`), réplica de
`saibher-flow.lovable.app/factura/fac-1`: encabezado «Factura <código>» +
subtítulo con el tipo, botón **Imprimir** (`window.print()`), enlace «Volver a
facturación», tarjeta «Tercero» (estado, tercero enlazado a su ficha, fecha,
pago) y tarjeta «Artículos (N unidades)» con la tabla Código/Artículo/Cant./Vr.
unitario/Total y el resumen Subtotal/Impuestos/Total.

- Datos en `saibher_bms_core_build_document_ficha()`; una línea sin
  `field_product_node` (datos viejos) recupera el producto por nombre exacto
  para mostrar código y enlace.
- Theme: `node--document--full.html.twig`, `css/node-document.css`,
  `js/document-ficha.js` y la librería `saibher_bms/document-ficha`. `@media
  print` aísla la ficha (oculta sidebar, topbar, vuelta y botón) y
  `preprocess_html` añade la clase `sb-ficha-documento` para ocultar el
  «page title» duplicado.
- **Corrección de formato de moneda**: `saibher_bms_core_money()` construía los
  separadores al revés y daba formato US (`$ 2,500`); ahora usa punto de miles y
  coma decimal (`$ 2.500`), como el `Intl.NumberFormat('es-CO')` de
  `billing-form.js`, las tablas de producto/proveedor y la referencia. Afecta
  también a las fichas de cliente y proveedor (mejora, no regresión).

### Verificación

- Servicio: borrador de venta con prefijo → `FV-00005`, `total 5.000`,
  inventario reservado y liberado al borrar; línea con IVA 19% → `taxUnit 912`,
  `taxTotal 1.824`, `total 11.424`; cierre pagado → `pagada` + 4 registros de
  caja (`Ingresos`/`Impuestos`/`Ingreso sin impuestos`/`Ganancias`); cierre
  parcial → `pendiente_pago` + deudor `FV-00005 · Ana Maria Restrepo` (5.712);
  compra cerrada → `pagada` y **0** deudores.
- Mensajes de cierre y `AccessDenied` comprobados. Entorno limpio: los 4
  documentos originales (`#290`/`#499`/`#501`/`#502`), stock `120 / 60`, sin
  movimientos, registros ni deudores de prueba.
- Ficha en navegador: `#290` (`FV-00001`, 35 unidades), `#502` (sin prefijo →
  título «Factura de venta», 1 unidad); fecha «27/09/2026, 7:11 p. m.»;
  rejilla tercero:artículos 1:2 en escritorio y apilada en 390 px (tabla con
  scroll interno, sin desborde de página); impresión: sidebar/topbar/vuelta/botón
  ocultos y `main` sin márgenes.
- `php -l` limpio y `phpcs --standard=Drupal` sin errores en `DocumentDraft.php`
  y `saibher_bms_core.module`.

