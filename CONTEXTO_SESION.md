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