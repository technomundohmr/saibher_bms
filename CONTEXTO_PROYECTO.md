# Contexto permanente del proyecto Saibher BMS

## Regla de trabajo

Para este proyecto, ejecutar directamente las tareas solicitadas sin preguntar
por confirmación en cada paso. Solo hacer preguntas cuando Andrés lo pida
explícitamente o cuando una decisión pueda causar un daño irreversible,
pérdida de datos o un cambio fuera del alcance solicitado.

El entorno local usa DDEV. Para cualquier operación de Drupal/Drush, utilizar
siempre `ddev drush` en lugar de ejecutar `vendor/bin/drush` directamente.
Ejemplos habituales: `ddev drush cr`, `ddev drush cim -y` y `ddev drush status`.

Antes de modificar código:

- Revisar las convenciones existentes y reutilizar la configuración disponible.
- Hacer cambios precisos, completos y compatibles con Drupal 11.
- Mantener el tono, la identidad visual y los patrones de interacción de Saibher.
- Validar los cambios con las herramientas existentes y no alterar trabajo ajeno.

## Fuentes de verdad

Este documento consolida y complementa:

- `design-code.html`: referencia visual principal del proyecto.
- `brief.md`: requisitos funcionales y técnicos del POS.
- `/home/andres/Descargas/CONTEXTO_PROYECTO.md`: contexto base del sistema POS.
- `/home/andres/Descargas/prompt-estrategia-marketing-saibher.md`: criterios de
  marketing y adquisición.
- `/home/andres/Descargas/saibher-brief-marketing-digital.md`: brief comercial.
- `/home/andres/Descargas/saibher-identidad-de-marca.md`: identidad de marca.
- `https://saibher-flow.lovable.app/dashboard`: referencia visual adicional de
  Saibher POS y su experiencia de acceso. La ruta pública puede redirigir al
  inicio de sesión si no existe una sesión autenticada.

Si existe una diferencia, `design-code.html` tiene prioridad para replicar una
interfaz concreta y la identidad de marca tiene prioridad para comunicación,
copy y decisiones de marca.

## Producto

Saibher BMS es un sistema B2B de gestión y punto de venta para pequeñas y
medianas empresas, con usuarios no técnicos. El sistema cubre:

- Dashboard con ventas, alertas de stock y movimientos rápidos.
- Facturación de ventas y compras, búsqueda por producto/SKU, escáner,
  métodos de pago y generación de comprobantes.
- Inventario con SKU, stock, stock mínimo, categorías y proveedores.
- Registro de movimientos de inventario: entradas, salidas, ajustes y pérdidas.
- Clientes, límites de crédito, cartera, deuda e historial.
- Proveedores, contactos, productos asociados y estado.
- Estadísticas de ventas, productos, márgenes y rotación.
- Catálogo o tienda web generado automáticamente desde el inventario.
- Usuarios y roles: Administrador, Cajero, Bodega y Solo lectura.

## Arquitectura técnica

- Drupal 11 como backend headless mediante REST/JSON API.
- Servicio externo Go/Gin para autenticación JWT.
- Frontend React + TypeScript.
- Tailwind CSS con patrones Radix UI/shadcn.
- Recharts para gráficos.
- Material Symbols o Lucide para iconografía, de forma consistente.
- Composer, Drush y gestión de configuración YAML.
- El tema Drupal actual es `web/themes/saibher_bms`.

## Sistema visual

### Paleta

| Token | Hex | Uso |
|---|---|---|
| Azul principal | `#1242E6` | Botones, acciones primarias, estados activos |
| Azul oscuro | `#052699` | Navegación lateral y fondos profundos |
| Azul claro | `#C5CFFA` | Hover, fondos suaves y divisores |
| Verde principal | `#83E612` | Acciones positivas y estados correctos |
| Verde oscuro | `#4A8A00` | Texto sobre fondos verdes |
| Rojo principal | `#E64012` | Errores, alertas y acciones destructivas |
| Blanco | `#FFFFFF` | Superficies principales |
| Gris claro | `#F4F6FF` | Fondo general y filas alternas |
| Texto oscuro | `#0A1A5C` | Encabezados de alto contraste |
| Texto de cuerpo | `#2D3A6B` | Párrafos y descripciones |

Nunca usar rojo y verde como único contraste para comunicar estados.

### Tipografía

- Encabezados: **Plus Jakarta Sans**.
- Cuerpo: **Fira Sans** cuando se replique fielmente `design-code.html`.
- Cuerpo oficial de identidad: **Figtree** para nuevas piezas donde no sea
  necesario mantener la referencia exacta.
- Código, SKU, NIT e identificadores: **JetBrains Mono**.

### Layout y componentes

- Sidebar fija de aproximadamente 280 px, azul oscuro, colapsable y responsive.
- El sidebar debe comportarse como un menú móvil retractil: colapsable en
  escritorio y deslizable sobre el contenido en móvil, con botón accesible,
  backdrop y cierre mediante `Escape`.
- Barra superior de 64 px con búsqueda, notificaciones, ayuda, acción de nueva
  venta y perfil.
- Canvas principal claro con espaciado consistente.
- Tarjetas `rounded-xl`, controles `rounded-lg` y sombras suaves azuladas.
- Tablas con filtros, ordenamiento, paginación, filas alternas y scroll
  horizontal en pantallas pequeñas.
- Drawers laterales o modales para crear y editar sin perder el contexto.
- Estados expresados mediante color, texto e icono; nunca solo mediante color.
- Interfaz desktop-first, pero usable en móvil.
- Lenguaje de la interfaz en español y no técnico.

## Pantallas de referencia en `design-code.html`

El archivo contiene ejemplos visuales de:

1. Inicio de sesión.
2. Inventario.
3. Facturación/POS.
4. Proveedores con drawer de alta.
5. Movimientos de inventario.
6. Estadísticas avanzadas.
7. Clientes con cartera y drawer de alta.
8. Usuarios y configuración del negocio.

Los patrones de navegación, tablas, formularios, filtros, badges, drawers,
botones, paginación y estados deben reutilizarse antes de crear variantes.

## Contenido funcional disponible

Los bundles administrativos de Drupal incluyen, entre otros:

- `product`: productos, SKU, categoría, precio, costo, stock, mínimo, impuesto
  y estado.
- `customer`: cliente, documento, contacto, teléfono, correo y pagos.
- `supplier`: proveedor, documento, contacto, teléfono, correo y ubicación.
- `movement`: producto, SKU, tipo, cantidad, cantidades inicial/final y
  referencia.
- `register`: registros financieros, tipo, valor y referencia.
- `document`: tickets, facturas y cotizaciones.

Reutilizar los nombres de máquina existentes; no crear campos duplicados por
diferencias de traducción o rotulación.

## Identidad y comunicación

- Slogan: **Digitaliza tu negocio sin complicaciones**.
- Tagline: **Tecnología cercana para comercios y negocios reales**.
- Misión: hacer la tecnología simple, accesible y cercana para comercios y
  medianas empresas.
- Público inicial: negocios de habla hispana, con foco en Colombia y
  Latinoamérica, especialmente dueños sin conocimientos técnicos.
- Servicios: desarrollo web, software de gestión personalizado, POS, Drupal y
  gestión con página web/tienda automática.
- Contacto: `hola@saibher.com`; WhatsApp `+57 313 773 2634`.
- Valores: cercanía de verdad, simple por diseño, confianza ganada, tecnología
  para todos, impacto antes que escala y mejora continua.

La comunicación debe ser cercana, directa, humana y honesta. Evitar venta
agresiva, urgencia artificial y frases genéricas. Usar promesas concretas y
“adicional” en lugar de “opcional” al describir servicios extra.

## Marketing

La estrategia prioriza presupuesto bajo o nulo:

- Contenido orgánico, SEO y posicionamiento local.
- WhatsApp Business, Google Business Profile y comunidades locales.
- Instagram, LinkedIn y los canales que puedan sostenerse con consistencia.
- Referidos, alianzas con cámaras de comercio y prospección directa respetuosa.
- Prueba social transparente: distinguir la experiencia personal del fundador
  de los proyectos facturados directamente por Saibher.
- Medición económica con Analytics, herramientas nativas de redes y una hoja de
  cálculo cuando no exista CRM.

El producto diferenciador prioritario es el software de gestión que genera una
página web o tienda desde el inventario.

## Criterios de implementación

- No inventar credenciales, métricas, clientes, precios definitivos o casos de
  éxito atribuidos a Saibher.
- Mantener configuración, traducciones, accesibilidad y permisos coherentes.
- Propagar los cambios a todas las superficies relacionadas: configuración,
  rutas, menús, plantillas, CSS y documentación directamente afectada.
- Preferir helpers y componentes existentes frente a duplicar lógica.
- No introducir dependencias o herramientas nuevas sin necesidad.
- No ejecutar comandos destructivos ni revertir cambios existentes del usuario.
