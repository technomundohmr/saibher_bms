(function (Drupal, once) {
  'use strict';

  // La parte (cliente o proveedor) NO viaja en la página: se pide al servicio
  // del módulo conforme el usuario escribe. CONFIG solo trae las URLs y los
  // datos estáticos del formulario.
  const CONFIG = typeof drupalSettings !== 'undefined'
    && drupalSettings.saibherBms
    && drupalSettings.saibherBms.billingForm
    ? drupalSettings.saibherBms.billingForm
    : {
      party: 'customer',
      autocomplete: {
        search: '',
        detail: '',
        minTerm: { name: 2, doc: 3 },
      },
      prefixes: [],
      products: [],
      collaborators: [],
      paymentMethods: [],
      partyLabel: 'Cliente',
      partyKey: 'cliente',
      documentTitle: 'Factura',
      defaultPrefixCode: 'FV',
      // Con el interruptor «prohibir venta sin inventario» encendido, una línea
      // que no tiene existencias no se puede agregar.
      strictStock: false,
      document: null,
    };

  const money = new Intl.NumberFormat('es-CO', {
    style: 'currency',
    currency: 'COP',
    minimumFractionDigits: 0,
  });

  const units = new Intl.NumberFormat('es-CO', { maximumFractionDigits: 3 });

  // Tolerancia para comparar cantidades: los decimales de las sumas flotantes
  // se comparan con margen para no inventar un movimiento de cero unidades.
  const EPSILON = 0.0005;

  const toNum = (value) => {
    const parsed = parseFloat(String(value).replace(/[$.\s]/g, '').replace(',', '.'));
    return Number.isFinite(parsed) ? parsed : 0;
  };

  Drupal.behaviors.saibherBmsBillingForm = {
    attach(context) {
      // Las modales (asignar, pagar) viven fuera del <form>: se consulta la
      // sección completa, no solo el formulario.
      const root = context.querySelector ? context.querySelector('.saibher-billing-form') : null;
      if (!root || root.dataset.sbBillingReady) {
        return;
      }
      root.dataset.sbBillingReady = '1';

      const state = {
        mode: {
          client: 'name',
          product: 'name',
        },
        selectedClient: null,
        assigned: null,
        payments: [],
        nextRow: 0,
        // Nodo del borrador ya creado; a partir de ahí se actualiza el mismo.
        draftId: 0,
        // Mientras se pinta un documento guardado no se escribe nada: abrir una
        // factura para editarla no puede contar como un cambio.
        hydrating: false,
        // La factura ya está cerrada: el formulario deja de escribir.
        closed: false,
        // Cierre en curso, para que el botón no se pueda pulsar dos veces.
        closing: false,
      };

      const $ = (selector, scope = root) => scope.querySelector(selector);
      const $all = (selector, scope = root) => Array.from(scope.querySelectorAll(selector));

      const els = {
        clientSearch: $('[data-sb-client-search]'),
        clientResults: $('[data-sb-client-results]'),
        clientMode: $('[data-sb-client-mode]'),
        fieldNombre: $('[data-sb-field="nombre"]'),
        fieldSede: $('[data-sb-field="sede"]'),
        fieldTipoDoc: $('[data-sb-field="tipoDoc"]'),
        fieldNumDoc: $('[data-sb-field="numDoc"]'),
        changeClient: $('[data-sb-change-client]'),
        prefix: $('[data-sb-prefix]'),
        consecutivo: $('[data-sb-consecutivo]'),
        productSearch: $('[data-sb-product-search]'),
        productResults: $('[data-sb-product-results]'),
        productMode: $('[data-sb-product-mode]'),
        itemsBody: $('[data-sb-items-body]'),
        itemsEmpty: $('[data-sb-items-empty]'),
        stockAlerts: $('[data-sb-stock-alerts]'),
        totalSubtotal: $('[data-sb-total-subtotal]'),
        totalTax: $('[data-sb-total-tax]'),
        totalTotal: $('[data-sb-total-total]'),
        totalPayments: $('[data-sb-total-payments]'),
        totalPaid: $('[data-sb-total-paid]'),
        assigned: $('[data-sb-assigned]'),
        assignedName: $('[data-sb-assigned-name]'),
        print: $('[data-sb-print]'),
        printClose: $('[data-sb-print-close]'),
        creditClose: $('[data-sb-credit-close]'),
        printInvoice: $('[data-sb-print-invoice]'),
        collabSearch: $('[data-sb-collab-search]'),
        collabResults: $('[data-sb-collab-results]'),
        assignSelection: $('[data-sb-assign-selection]'),
        assignSelectionName: $('[data-sb-assign-selection-name]'),
        assignConfirm: $('[data-sb-assign-confirm]'),
        payMethod: $('[data-sb-pay-method]'),
        payValue: $('[data-sb-pay-value]'),
        payDesc: $('[data-sb-pay-desc]'),
        payAdd: $('[data-sb-pay-add]'),
        paymentsList: $('[data-sb-payments]'),
        paymentsEmpty: $('[data-sb-payments-empty]'),
        paySum: $('[data-sb-pay-sum]'),
        payTotal: $('[data-sb-pay-total]'),
        payPaid: $('[data-sb-pay-paid]'),
        payDue: $('[data-sb-pay-due]'),
        payDueFigure: $('[data-sb-pay-due-figure]'),
        totalChange: $('[data-sb-total-change]'),
        totalChangeRow: $('[data-sb-total-change-row]'),
        payCount: $('[data-sb-pay-count]'),
        payStatus: $('[data-sb-pay-status]'),
        draftState: $('[data-sb-draft-state]'),
        draftIcon: $('[data-sb-draft-icon]'),
        draftText: $('[data-sb-draft-text]'),
      };

      if (!els.prefix) {
        return;
      }

      /* ── Prefijo y consecutivo ─────────────────────────── */
      CONFIG.prefixes.forEach((prefix) => {
        const option = document.createElement('option');
        option.value = prefix.next;
        option.textContent = prefix.label;
        option.dataset.prefixId = String(prefix.id);
        els.prefix.appendChild(option);
      });
      const prefixCode = (prefix) =>
        (prefix && prefix.label) ? prefix.label.split(' - ')[0] : CONFIG.defaultPrefixCode;
      // Los prefijos de un mismo tipo comparten consecutivo, así que el valor
      // de la opción no los distingue: se resuelven por su posición.
      const selectedPrefix = () => CONFIG.prefixes[els.prefix.selectedIndex] || null;
      const setConsecutivo = () => {
        const prefix = selectedPrefix();
        const next = toNum(prefix ? prefix.next : 0);
        const num = next > 0 ? String(next).padStart(5, '0') : '';
        els.consecutivo.value = num ? prefixCode(prefix) + '-' + num : '';
        els.consecutivo.dataset.numeric = num;
      };
      els.prefix.addEventListener('change', setConsecutivo);
      setConsecutivo();

      /* ── Medios de pago ────────────────────────────────── */
      CONFIG.paymentMethods.forEach((method) => {
        const option = document.createElement('option');
        option.value = method;
        option.textContent = method;
        els.payMethod.appendChild(option);
      });

      /* ── Búsqueda de cliente ───────────────────────────── */
      const renderResults = (container, items, emptyText, onPick) => {
        container.innerHTML = '';
        if (items.length === 0) {
          const empty = document.createElement('button');
          empty.type = 'button';
          empty.className = 'sb-suggest__item is-empty';
          empty.textContent = emptyText;
          container.appendChild(empty);
        }
        else {
          items.forEach((item) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'sb-suggest__item';
            const name = document.createElement('span');
            name.className = 'sb-suggest__item-name';
            name.textContent = item.label;
            const suffix = document.createElement('span');
            suffix.className = 'sb-suggest__item-suffix';
            suffix.textContent = item.suffix || '';
            button.append(name, suffix);
            button.addEventListener('click', () => onPick(item.value));
            container.appendChild(button);
          });
        }
        container.hidden = false;
      };

      const hideResults = (container) => {
        container.innerHTML = '';
        container.hidden = true;
      };

      /* ── Búsqueda de parte (cliente o proveedor) ──────── */

      // El servicio del módulo resuelve la búsqueda; el script solo pide,
      // pinta el resultado y completa los campos. No se filtran datos en el
      // navegador porque la lista de partes ya no viene en la página.
      const MIN_TERM = (CONFIG.autocomplete && CONFIG.autocomplete.minTerm) || { name: 2, doc: 3 };

      const getJson = async (url) => {
        const response = await fetch(url, {
          credentials: 'same-origin',
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
        });
        if (!response.ok) {
          throw new Error('HTTP ' + response.status);
        }
        return response.json();
      };

      const searchParties = async (term) => {
        const base = (CONFIG.autocomplete && CONFIG.autocomplete.search) || '';
        if (!base) {
          return [];
        }
        const query = new URLSearchParams({ q: term, mode: state.mode.client });
        const payload = await getJson(base + '?' + query.toString());
        return Array.isArray(payload.results) ? payload.results : [];
      };

      const loadParty = async (id) => {
        const base = (CONFIG.autocomplete && CONFIG.autocomplete.detail) || '';
        if (!base) {
          return null;
        }
        const payload = await getJson(base.replace('PARTYID', encodeURIComponent(id)));
        return payload.party || null;
      };

      // Evita que una respuesta lenta pise a una más reciente.
      let clientRequest = 0;

      const showPartyMatches = async (term) => {
        const requested = ++clientRequest;
        const mode = state.mode.client;
        if (term.trim().length < (MIN_TERM[mode] || 2)) {
          hideResults(els.clientResults);
          return;
        }

        try {
          const results = await searchParties(term);
          if (requested !== clientRequest || els.clientSearch.disabled) {
            return;
          }
          renderResults(
            els.clientResults,
            results.map((match) => ({
              label: match.label,
              suffix: match.suffix,
              value: match.id,
            })),
            mode === 'doc'
              ? 'Sin coincidencias para la identificación buscada.'
              : 'Sin coincidencias para el nombre buscado.',
            pickParty
          );
        }
        catch (error) {
          if (requested !== clientRequest) {
            return;
          }
          renderResults(
            els.clientResults,
            [],
            'No se pudo consultar. Intenta de nuevo.',
            () => {}
          );
        }
      };

      // Espera a que el usuario deje de escribir antes de pedir resultados.
      // Expone cancel() para que Enter no espere al temporizador pendiente.
      const debounce = (callback, delay) => {
        let timer = null;
        const run = (...args) => {
          if (timer !== null) {
            window.clearTimeout(timer);
          }
          timer = window.setTimeout(() => {
            timer = null;
            callback(...args);
          }, delay);
        };
        run.cancel = () => {
          if (timer !== null) {
            window.clearTimeout(timer);
            timer = null;
          }
        };
        return run;
      };

      const showPartyMatchesDebounced = debounce(showPartyMatches, 250);

      const applyParty = (party) => {
        state.selectedClient = party;
        els.fieldNombre.value = party.name || '';
        els.fieldNombre.readOnly = false;

        const branches = (party.branches && party.branches.length)
          ? party.branches
          : [{ name: '', address: party.mainAddress }];
        els.fieldSede.innerHTML = '';
        branches.forEach((branch) => {
          const label = [branch.name, branch.address].filter(Boolean).join(' — ');
          const option = document.createElement('option');
          option.value = label;
          option.textContent = label || party.mainAddress || '';
          els.fieldSede.appendChild(option);
        });
        els.fieldSede.disabled = false;

        // Los tipos válidos los envía el servicio; se reemplazan para que el
        // valor del cliente siempre tenga una opción donde mostrarse.
        els.fieldTipoDoc.innerHTML = '';
        (party.documentTypeOptions || []).forEach((type) => {
          const option = document.createElement('option');
          option.value = type;
          option.textContent = type;
          els.fieldTipoDoc.appendChild(option);
        });
        els.fieldTipoDoc.value = party.documentType || '';
        els.fieldTipoDoc.disabled = false;

        els.fieldNumDoc.value = party.documentNumber || '';
        els.fieldNumDoc.readOnly = true;

        els.changeClient.hidden = false;
        els.clientSearch.disabled = true;
        els.clientSearch.value = '';
        hideResults(els.clientResults);
        if (hasDraftContent()) {
          scheduleDraft();
        }
      };

      const pickParty = async (id) => {
        try {
          const party = await loadParty(id);
          if (party) {
            applyParty(party);
          }
        }
        catch (error) {
          renderResults(els.clientResults, [], 'No se pudo cargar la parte.', () => {});
        }
      };

      const resetClient = () => {
        state.selectedClient = null;
        els.fieldNombre.value = '';
        els.fieldNombre.readOnly = true;
        els.fieldSede.innerHTML = '<option value="">Sede del ' + CONFIG.partyKey + '</option>';
        els.fieldSede.disabled = true;
        els.fieldTipoDoc.innerHTML = '<option value="">Tipo de identificación</option>';
        els.fieldTipoDoc.value = '';
        els.fieldTipoDoc.disabled = true;
        els.fieldNumDoc.value = '';
        els.changeClient.hidden = true;
        els.clientSearch.disabled = false;
        els.clientSearch.focus();
      };

      els.clientMode.addEventListener('change', () => {
        state.mode.client = els.clientMode.value;
        els.clientSearch.placeholder = state.mode.client === 'doc'
          ? 'Buscar por número de identificación (exacto)…'
          : 'Escribe parte del nombre…';
        hideResults(els.clientResults);
      });

      els.clientSearch.addEventListener('input', (event) => {
        showPartyMatchesDebounced(event.target.value);
      });
      els.clientSearch.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
          event.preventDefault();
          showPartyMatchesDebounced.cancel();
          showPartyMatches(els.clientSearch.value);

        }
        if (event.key === 'Escape') {
          hideResults(els.clientResults);
        }
      });
      els.changeClient.addEventListener('click', resetClient);

      /* ── Búsqueda de productos ─────────────────────────── */
      const productMatches = (input) => {
        const term = input.trim().toLowerCase();
        if (term.length < 2) {
          return [];
        }
        if (state.mode.product === 'code') {
          if (term.length < 3) {
            return [];
          }
          return CONFIG.products.filter((product) =>
            product.code && product.code.toLowerCase() === term
          ).map((product) => ({ label: product.name, suffix: product.code, value: product }));
        }
        return CONFIG.products.filter((product) =>
          product.name.toLowerCase().includes(term) ||
          (product.sku && product.sku.toLowerCase().includes(term))
        ).map((product) => ({
          label: product.name,
          suffix: [product.code, product.sku].filter(Boolean).join(' · '),
          value: product,
        }));
      };

      /* ── Inventario ─────────────────────────────────────── */
      // El servidor manda el inventario de cada producto con la página. Aquí solo
      // se compara para avisar (o impedir) al instante; quien decide siempre es
      // el servidor al guardar.
      const productStock = (product) =>
        product && product.stock !== undefined ? toNum(product.stock) : null;

      const rowStock = (row) => {
        const raw = row.dataset.sbItemStock;
        return raw === undefined || raw === '' ? null : toNum(raw);
      };

      const setRowStock = (row, available, reserved) => {
        row.dataset.sbItemStock = available === null ? '' : String(available);
        row.dataset.sbItemReserved = String(reserved || 0);
        recalcRow(row);
      };

      // Las unidades que la línea todavía puede sumar: el disponible ya viene
      // menos lo que este mismo borrador tiene reservado de ese producto.
      const rowFree = (row) => {
        const available = rowStock(row);
        if (available === null) {
          return null;
        }
        return available + (toNum(row.dataset.sbItemReserved) || 0);
      };

      const rowName = (row) => $('td:nth-child(2)', row).textContent.trim();

      const shortageOf = (name, available, requested) => ({
        name: name,
        available: available,
        requested: requested,
        missing: requested - available,
      });

      const stockLines = (shortages) => shortages.map((shortage) =>
        escapeHtml(shortage.name) + ': hay ' + units.format(shortage.available) +
        ' y la línea pide ' + units.format(shortage.requested) + '.'
      );

      // Un solo aviso, el más reciente: el inventario se revisa en cada cambio.
      const renderStockAlerts = (tone, title, lines) => {
        if (!els.stockAlerts) {
          return;
        }
        const icon = tone === 'error' ? 'error' : 'warning';
        els.stockAlerts.innerHTML =
          '<div class="sb-alert sb-alert--' + tone + '">' +
            '<span class="sb-alert__icon material-symbols-outlined" aria-hidden="true">' + icon + '</span>' +
            '<div class="sb-alert__body">' +
              '<p><strong>' + escapeHtml(title) + '</strong></p>' +
              '<ul class="sb-alert__list">' +
                lines.map((line) => '<li>' + line + '</li>').join('') +
              '</ul>' +
            '</div>' +
          '</div>';
        els.stockAlerts.hidden = false;
      };

      const clearStockAlerts = () => {
        if (els.stockAlerts) {
          els.stockAlerts.innerHTML = '';
          els.stockAlerts.hidden = true;
        }
      };

      const renderShortage = (shortage) => {
        if (shortage.missing <= EPSILON) {
          clearStockAlerts();
          return;
        }
        renderStockAlerts(
          CONFIG.strictStock ? 'error' : 'warning',
          CONFIG.strictStock ? 'No hay inventario suficiente' : 'Inventario insuficiente',
          stockLines([shortage])
        );
      };

      // Cuando el inventario obliga a bajar la cantidad, se dice cuál fue y por
      // qué: un número que cambia solo no se explica.
      const renderAdjusted = (name, available) => {
        if (available < 1) {
          renderStockAlerts('error', 'Sin inventario para vender', [
            escapeHtml(name) + ' no tiene unidades disponibles: quita la línea o repón el inventario.',
          ]);
          return;
        }
        renderStockAlerts('warning', 'Cantidad ajustada al inventario', [
          escapeHtml(name) + ': solo hay ' + units.format(available) + ' unidades disponibles.',
        ]);
      };

      // Con el interruptor encendido el disponible manda: la línea no entra.
      const stockAllows = (name, available, quantity) => {
        if (available === null || available + EPSILON >= quantity) {
          return true;
        }
        renderShortage(shortageOf(name, available, quantity));
        return !CONFIG.strictStock;
      };

      const recalcRow = (row) => {
        const unit = toNum($('[data-sb-cell="unit"]', row).value);
        const qty = toNum($('[data-sb-cell="qty"]', row).value);
        const tax = toNum($('[data-sb-cell="taxPct"]', row).dataset.value);
        const unitTax = tax > 0 ? (unit * tax) / 100 : 0;
        $('[data-sb-cell="taxAmt"]', row).textContent = money.format(unitTax);
        $('[data-sb-cell="totalTax"]', row).textContent = money.format(unitTax * qty);
        $('[data-sb-cell="total"]', row).textContent = money.format(unit * qty + unitTax * qty);

        const available = rowStock(row);
        const free = rowFree(row);
        const stockCell = $('[data-sb-cell="stock"]', row);
        if (stockCell) {
          stockCell.textContent = available === null ? '—' : units.format(available);
          stockCell.classList.toggle('is-short', free !== null && qty > free + EPSILON);
        }
      };

      const recalcTotals = () => {
        let subtotal = 0;
        let taxTotal = 0;
        $all('[data-sb-item-row]').forEach((row) => {
          const unit = toNum($('[data-sb-cell="unit"]', row).value);
          const qty = toNum($('[data-sb-cell="qty"]', row).value);
          const tax = toNum($('[data-sb-cell="taxPct"]', row).dataset.value);
          subtotal += unit * qty;
          taxTotal += qty * (tax > 0 ? (unit * tax) / 100 : 0);
        });
        els.totalSubtotal.textContent = money.format(subtotal);
        els.totalTax.textContent = money.format(taxTotal);
        els.totalTotal.textContent = money.format(subtotal + taxTotal);
        els.itemsEmpty.hidden = $all('[data-sb-item-row]').length > 0;
        renderPaySummary();
        updatePayStatus();
      };

      const updatePayStatus = () => {
        if (!els.payStatus) {
          return;
        }
        const paid = state.payments.reduce((sum, payment) => sum + payment.value, 0);
        const total = toNum(els.totalTotal.textContent);
        const isPaid = total > 0 && paid > 0 && paid >= total;
        els.payStatus.textContent = isPaid ? 'Pagada' : 'Pendiente de pago';
        els.payStatus.classList.toggle('is-paid', isPaid);
      };

      // Añade una línea al detalle. Con «line» se repinta una línea ya guardada
      // (mismos valores, sin robar el foco); sin ella es un producto nuevo.
      const addProduct = (product, line) => {
        const restore = Boolean(line);
        const quantity = restore && toNum(line.quantity) > 0 ? toNum(line.quantity) : 1;
        const unitValue = restore ? toNum(line.unitValue) : toNum(product.price);
        const taxPct = restore ? toNum(line.taxPct) : toNum(product.taxPct);
        const available = productStock(product);

        // Una factura que ya está en la mesa se repinta siempre (es lo que el
        // usuario está viendo); una línea nueva tiene que caber en el
        // inventario cuando la configuración lo exige.
        if (!restore && !stockAllows(product.name || '', available, quantity)) {
          return;
        }

        const row = document.createElement('tr');
        row.dataset.sbItemRow = String(state.nextRow++);
        row.dataset.sbItemProduct = String(product.id);
        row.dataset.sbItemStock = available === null ? '' : String(available);
        // Una línea que viene del servidor ya tiene ese movimiento escrito.
        row.dataset.sbItemReserved = restore ? String(quantity) : '0';
        row.innerHTML =
          '<td class="sb-item-code">' + escapeHtml(product.code || '') + '</td>' +
          '<td>' + escapeHtml(product.name || '') + '</td>' +
          '<td><input type="number" min="0" step="10" data-sb-cell="unit" value="' + unitValue + '" aria-label="Valor unitario" /></td>' +
          '<td class="sb-item-tax" data-sb-cell="taxPct" data-value="' + taxPct + '">' + taxPct + '%</td>' +
          '<td class="sb-item-value" data-sb-cell="taxAmt">$ 0</td>' +
          '<td><input type="number" min="1" step="1" data-sb-cell="qty" value="' + quantity + '" aria-label="Cantidad" /></td>' +
          '<td class="sb-item-value" data-sb-cell="totalTax">$ 0</td>' +
          '<td class="sb-item-value" data-sb-cell="total">$ 0</td>' +
          '<td class="sb-item-stock" data-sb-cell="stock">—</td>' +
          '<td class="sb-items__actions"><button type="button" class="sb-items__remove" aria-label="Quitar producto">' +
          '<span class="material-symbols-outlined" aria-hidden="true">close</span></button></td>';
        els.itemsBody.appendChild(row);
        recalcRow(row);
        recalcTotals();
        if (restore) {
          return;
        }
        scheduleDraft();
        const qtyInput = $('[data-sb-cell="qty"]', row);
        qtyInput.focus();
        qtyInput.select();
      };

      function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = value;
        return div.innerHTML;
      }

      els.itemsBody.addEventListener('click', (event) => {
        const remove = event.target.closest('.sb-items__remove');
        if (!remove) {
          return;
        }
        remove.closest('tr').remove();
        recalcTotals();
        if (!$all('[data-sb-item-row].is-short').length) {
          clearStockAlerts();
        }
        scheduleDraft();
      });

      // La cantidad se revisa contra lo que la línea todavía puede sumar
      // (disponible más lo que ya tiene reservado) mientras se escribe, y se
      // normaliza al salir del campo: escribir «12» con 5 libres avisa primero y
      // deja 5 al terminar.
      const reviewStock = (row) => {
        const free = rowFree(row);
        if (free === null) {
          return;
        }
        const quantity = toNum($('[data-sb-cell="qty"]', row).value);
        renderShortage(shortageOf(rowName(row), free, quantity));
      };

      els.itemsBody.addEventListener('input', (event) => {
        const input = event.target.closest('[data-sb-cell]');
        if (!input) {
          return;
        }
        const row = input.closest('[data-sb-item-row]');
        recalcRow(row);
        recalcTotals();
        if (input.dataset.sbCell === 'qty') {
          reviewStock(row);
        }
        scheduleDraft();
      });

      els.itemsBody.addEventListener('change', (event) => {
        const input = event.target.closest('[data-sb-cell="qty"]');
        if (!input) {
          return;
        }
        const row = input.closest('[data-sb-item-row]');
        const free = rowFree(row);
        if (CONFIG.strictStock && free !== null && toNum(input.value) > free + EPSILON) {
          // Nunca por debajo de una unidad: el detalle no admite cantidades
          // negativas y el servidor descartaría la línea.
          input.value = String(Math.max(1, Math.floor(free)));
          recalcRow(row);
          recalcTotals();
          renderAdjusted(rowName(row), free);
        }
        else {
          reviewStock(row);
        }
        scheduleDraft();
      });

      els.productMode.addEventListener('change', () => {
        state.mode.product = els.productMode.value;
        els.productSearch.placeholder = state.mode.product === 'code'
          ? 'Código exacto del producto…'
          : 'Ej. Leche entera o LAC-0021';
        hideResults(els.productResults);
      });

      els.productSearch.addEventListener('input', (event) => {
        renderResults(els.productResults, productMatches(event.target.value),
          state.mode.product === 'code'
            ? 'Escribe el código completo (coincidencia exacta).'
            : 'Sin coincidencias para el producto buscado.',
          (product) => {
            hideResults(els.productResults);
            els.productSearch.value = '';
            addProduct(product);
          });
        if (event.target.value.trim().length < 2) {
          hideResults(els.productResults);
        }
      });
      els.productSearch.addEventListener('keydown', (event) => {
        if (event.key === 'Enter') {
          event.preventDefault();
          renderResults(els.productResults, productMatches(els.productSearch.value),
            'Sin coincidencias para el código buscado.',
            (product) => {
              hideResults(els.productResults);
              els.productSearch.value = '';
              addProduct(product);
            });
        }
        if (event.key === 'Escape') {
          hideResults(els.productResults);
        }
      });

      /* ── Asignar a colaborador ─────────────────────────── */
      const reindexCollabs = () =>
        CONFIG.collaborators.filter((collaborator) =>
          collaborator.name.toLowerCase().includes(els.collabSearch.value.trim().toLowerCase())
        ).map((collaborator) => ({
          label: collaborator.name,
          value: collaborator,
        }));

      let selectedCollaborator = null;

      els.collabSearch.addEventListener('input', (event) => {
        const results = event.target.value.trim().length
          ? reindexCollabs()
          : CONFIG.collaborators.map((c) => ({ label: c.name, value: c }));
        renderResults(els.collabResults, results, 'Sin coincidencias de colaboradores.', (collaborator) => {
          selectedCollaborator = collaborator;
          els.assignConfirm.disabled = false;
          els.assignSelectionName.textContent = collaborator.name;
          els.assignSelection.hidden = false;
          hideResults(els.collabResults);
        });
      });

      els.assignConfirm.addEventListener('click', () => {
        if (!selectedCollaborator) {
          return;
        }
        state.assigned = selectedCollaborator;
        els.assignedName.textContent = selectedCollaborator.name;
        els.assigned.hidden = false;
        els.collabSearch.value = '';
        selectedCollaborator = null;
        els.assignConfirm.disabled = true;
        els.assignSelection.hidden = true;
        const dialog = document.getElementById('sb-assign-modal');
        if (dialog && typeof dialog.close === 'function') {
          dialog.close();
        }
      });

      /* ── Pagos ─────────────────────────────────────────── */
      // Cifras del pie de la modal y del resumen: total, abonado y lo que falta.
      // El resto descuenta también el valor que se está escribiendo, para ver el
      // saldo antes de añadir el pago. Cuando lo abonado se pasa del total, la
      // diferencia no es un error sino el cambio que hay que devolver.
      const payBalance = () => {
        const total = toNum(els.totalTotal.textContent);
        const paid = state.payments.reduce((sum, payment) => sum + payment.value, 0);
        const pending = paid + toNum(els.payValue.value);
        const left = total - pending;
        return {
          total,
          paid,
          change: Math.max(0, -left),
          due: Math.max(0, left),
        };
      };

      const renderPaySummary = () => {
        const balance = payBalance();
        if (els.payTotal) {
          els.payTotal.textContent = money.format(balance.total);
        }
        if (els.payPaid) {
          els.payPaid.textContent = money.format(balance.paid);
        }
        if (els.payDue) {
          els.payDue.textContent = money.format(balance.change > 0 ? balance.change : balance.due);
        }
        if (els.payDueFigure) {
          // Tres estados y tres colores: se debe algo (rojo), hay cambio a favor
          // (verde) o el saldo quedó en su punto (azul).
          const change = balance.change > EPSILON;
          const due = balance.due > EPSILON;
          els.payDueFigure.classList.toggle('is-due', due);
          els.payDueFigure.classList.toggle('is-change', change);
          els.payDueFigure.classList.toggle('is-clear', !due && !change);
          els.payDueFigure.querySelector('.sb-pay-summary__label').textContent = change
            ? 'Cambio'
            : 'Restante';
        }
        if (els.totalChange) {
          els.totalChange.textContent = money.format(balance.change);
        }
        if (els.totalChangeRow) {
          els.totalChangeRow.hidden = balance.change <= EPSILON;
        }
      };

      const renderPayments = () => {
        els.paymentsList.innerHTML = '';
        let paid = 0;
        state.payments.forEach((payment, index) => {
          paid += payment.value;
          const item = document.createElement('li');
          item.className = 'sb-payments__item';
          item.innerHTML =
            '<span class="sb-payments__method">' + escapeHtml(payment.method) + '</span>' +
            '<span class="sb-payments__desc">' + (payment.desc ? escapeHtml(payment.desc) : '') + '</span>' +
            '<span class="sb-payments__value">' + money.format(payment.value) + '</span>' +
            '<button type="button" class="sb-payments__remove" aria-label="Quitar pago" data-index="' + index + '">' +
            '<span class="material-symbols-outlined" aria-hidden="true">close</span></button>';
          els.paymentsList.appendChild(item);
        });
        els.paymentsEmpty.hidden = state.payments.length > 0;
        renderPaySummary();
        els.totalPaid.textContent = money.format(paid);
        els.totalPayments.hidden = paid <= 0;
        if (els.payCount) {
          els.payCount.textContent = '(' + state.payments.length + ')';
        }
        updatePayStatus();
      };

      els.payValue.addEventListener('input', renderPaySummary);
      const payOpen = $('[data-dialog-open="sb-pay-modal"]');
      if (payOpen) {
        payOpen.addEventListener('click', renderPaySummary);
      }

      els.payAdd.addEventListener('click', () => {
        const value = toNum(els.payValue.value);
        if (!els.payMethod.value || value <= 0) {
          els.payValue.focus();
          return;
        }
        state.payments.push({
          method: els.payMethod.value,
          value,
          desc: els.payDesc.value.trim(),
        });
        els.payValue.value = '';
        els.payDesc.value = '';
        renderPayments();
        scheduleDraft();
        els.payValue.focus();
      });

      els.paymentsList.addEventListener('click', (event) => {
        const button = event.target.closest('.sb-payments__remove');
        if (!button) {
          return;
        }
        state.payments.splice(toNum(button.dataset.index), 1);
        renderPayments();
        scheduleDraft();
      });

      /* ── Autoguardado del borrador ─────────────────────── */
      // El borrador vive en «Documento transaccional». La primera escritura
      // crea el nodo y reserva el consecutivo; las siguientes actualizan ese
      // mismo nodo, así el listado de facturas no se llena de duplicados.
      const DRAFT_IDLE_MS = 900;

      const setDraftState = (icon, text, modifier) => {
        if (els.draftIcon) {
          els.draftIcon.textContent = icon;
        }
        if (els.draftText) {
          els.draftText.textContent = text;
        }
        if (els.draftState) {
          els.draftState.classList.toggle('is-saving', modifier === 'saving');
          els.draftState.classList.toggle('is-saved', modifier === 'saved');
          els.draftState.classList.toggle('is-error', modifier === 'error');
        }
      };

      const collectDraft = () => {
        const prefix = selectedPrefix();
        const payload = {
          documentId: state.draftId,
          partyId: state.selectedClient ? state.selectedClient.id : 0,
          prefixId: prefix ? toNum(prefix.id) : 0,
          items: $all('[data-sb-item-row]').map((row) => ({
            product: toNum(row.dataset.sbItemProduct),
            quantity: toNum($('[data-sb-cell="qty"]', row).value),
            unitValue: toNum($('[data-sb-cell="unit"]', row).value),
          })),
          payments: state.payments.map((payment) => ({
            method: payment.method,
            value: payment.value,
            reference: payment.desc || '',
          })),
        };
        if (window && window.__sbBillingCredit === true) {
          payload.creditSale = true;
          payload.payments = [];
        }
        return payload;
      };

      // Una sola peticion en vuelo: si el servidor responde despacio, los
      // cambios siguientes se acumulan en vez de apilar peticiones, que podrian
      // crear borradores distintos.
      let draftTimer = null;
      let draftInFlight = false;
      let draftPending = false;
      let draftFailures = 0;
      // Promesa de la peticion en vuelo, para poder esperarla antes de cerrar.
      let draftRequest = null;

      const requestDraft = async () => {
        setDraftState('sync', 'Guardando el borrador…', 'saving');

        const response = await fetch(CONFIG.draft.url, {
          method: 'POST',
          credentials: 'same-origin',
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-Token': CONFIG.draft.csrfToken,
          },
          body: JSON.stringify(collectDraft()),
        });
        const body = await response.json().catch(() => ({}));

        // 422 no es una caida: el servidor se nego a guardar porque el
        // inventario no alcanza, y dice que producto falta.
        if (response.status === 422) {
          renderStockAlerts('error', 'No hay inventario suficiente', stockLines(body.shortages || []));
          setDraftState('error', body.message || 'No se pudo guardar por inventario.', 'error');
          return null;
        }
        if (!response.ok) {
          throw new Error('HTTP ' + response.status);
        }

        const saved = body;
        state.draftId = saved.id;
        draftFailures = 0;

        // El servidor es la fuente de los importes: se adoptan sus totales
        // para que la pantalla y el nodo nunca discrepen.
        els.totalSubtotal.textContent = money.format(saved.subtotal);
        els.totalTax.textContent = money.format(saved.taxTotal);
        els.totalTotal.textContent = money.format(saved.total);
        updatePayStatus();

        // El disponible de cada linea es el del servidor, ya con lo que este
        // mismo borrador reservo: comparar contra la cifra de antes de guardar
        // daria avisos que no existen.
        if (saved.stock && typeof saved.stock === 'object') {
          $all('[data-sb-item-row]').forEach((row) => {
            const product = toNum(row.dataset.sbItemProduct);
            if (Object.prototype.hasOwnProperty.call(saved.stock, product)) {
              setRowStock(
                row,
                toNum(saved.stock[product]),
                toNum((saved.reserved || {})[product])
              );
            }
          });
          $all('[data-sb-item-row]').forEach(reviewStock);
        }

        // Inventario: el servidor avisa cuando dejo a un producto sin unidades y
        // la configuracion lo permite.
        const shortages = Array.isArray(saved.stockWarnings) ? saved.stockWarnings : [];
        if (shortages.length) {
          renderStockAlerts('warning', 'Inventario insuficiente', stockLines(shortages));
        }
        else if (!CONFIG.strictStock && !$all('[data-sb-item-row].is-short').length) {
          clearStockAlerts();
        }

        setDraftState('check_circle', 'Borrador ' + (saved.code || '') + ' guardado.', 'saved');
        return saved;
      };

      const sendDraft = async () => {
        if (draftInFlight) {
          draftPending = true;
          return null;
        }
        draftInFlight = true;
        draftRequest = requestDraft();

        try {
          return await draftRequest;
        }
        catch (error) {
          draftFailures += 1;
          setDraftState(
            'error',
            draftFailures > 2
              ? 'No se pudo guardar el borrador. Revisa la conexión.'
              : 'Guardando el borrador…',
            'error'
          );
          return null;
        }
        finally {
          draftInFlight = false;
          draftRequest = null;
          if (draftPending) {
            draftPending = false;
            scheduleDraft();
          }
        }
      };

      // Espera a que no quede ningun cambio sin escribir: lo usa el cierre, que
      // no puede aplicarse sobre un borrador a medio guardar.
      const flushDraft = async () => {
        if (draftTimer) {
          clearTimeout(draftTimer);
          draftTimer = null;
        }
        if (draftInFlight && draftRequest) {
          await draftRequest.catch(() => {});
        }
        return sendDraft();
      };

      const scheduleDraft = () => {
        if (state.hydrating || state.closed || !CONFIG.draft || !CONFIG.draft.url) {
          return;
        }
        if (draftTimer) {
          clearTimeout(draftTimer);
        }
        draftTimer = setTimeout(sendDraft, DRAFT_IDLE_MS);
      };

      // No tiene sentido un borrador sin nada: se espera a que haya una parte
      // o una linea antes de escribir en la base de datos.
      const hasDraftContent = () => Boolean(state.selectedClient) || $all('[data-sb-item-row]').length > 0;

      if (CONFIG.draft && CONFIG.draft.url) {
        setDraftState('edit_note', 'Se guarda solo como borrador.');
        els.prefix.addEventListener('change', scheduleDraft);
      }

      /* ── Carga de un documento guardado ──────────────────── */
      // El action «Editar» del listado abre esta misma página con
      // ?documento=<id>: el servidor manda lo guardado y el formulario lo
      // pinta sin escribir nada. El autoguardado solo vuelve a correr cuando el
      // usuario cambia algo, y actualiza ese mismo documento.
      const productByName = (name) => {
        const needle = String(name || '').trim().toLowerCase();
        return CONFIG.products.find((product) =>
          String(product.name).trim().toLowerCase() === needle
        ) || null;
      };

      const loadDocument = async (document_) => {
        state.hydrating = true;
        state.draftId = toNum(document_.id);

        const prefixIndex = CONFIG.prefixes.findIndex((prefix) =>
          toNum(prefix.id) === toNum(document_.prefixId)
        );
        if (prefixIndex >= 0) {
          els.prefix.selectedIndex = prefixIndex;
        }
        setConsecutivo();
        // El consecutivo es el del documento editado, no el siguiente de la
        // serie: abrir una factura no puede cambiarle el número.
        const number = toNum(document_.number);
        if (number > 0) {
          els.consecutivo.value = prefixCode(selectedPrefix())
            + '-' + String(number).padStart(5, '0');
          els.consecutivo.dataset.numeric = String(number);
        }

        (document_.items || []).forEach((line) => {
          // El detalle guarda el producto por nombre; si ya no está en el
          // catálogo se repinta igual, con los importes que tenía guardados.
          addProduct(productByName(line.name) || {
            id: 0,
            name: line.name || '',
            code: '',
            price: toNum(line.unitValue),
            taxPct: toNum(line.taxPct),
          }, line);
        });

        state.payments = (document_.payments || [])
          .filter((payment) => toNum(payment.value) > 0)
          .map((payment) => ({
            method: payment.method || '',
            value: toNum(payment.value),
            desc: payment.reference || '',
          }));
        renderPayments();
        recalcTotals();

        if (toNum(document_.partyId) > 0) {
          try {
            const party = await loadParty(toNum(document_.partyId));
            if (party) {
              applyParty(party);
            }
            else {
              renderResults(els.clientResults, [], 'No se pudo cargar el ' + CONFIG.partyKey + '.', () => {});
            }
          }
          catch (error) {
            renderResults(els.clientResults, [], 'No se pudo cargar el ' + CONFIG.partyKey + '.', () => {});
          }
        }

        state.hydrating = false;
        setDraftState('edit_note', 'Editando el borrador ' + (els.consecutivo.value || 'sin número') + '.');
      };

      if (CONFIG.document) {
        loadDocument(CONFIG.document);
      }

      /* ── Impresión ─────────────────────────────────────── */
      const buildPrintInvoice = () => {
        const prefix = selectedPrefix();
        const invoiceNumber = prefixCode(prefix)
          + '-' + (els.consecutivo.dataset.numeric || els.consecutivo.value || '');
        const rows = $all('[data-sb-item-row]').map((row) => ({
          code: $('.sb-item-code', row).textContent.trim(),
          name: $('td:nth-child(2)', row).textContent.trim(),
          unit: toNum($('[data-sb-cell="unit"]', row).value),
          tax: toNum($('[data-sb-cell="taxPct"]', row).dataset.value),
          qty: toNum($('[data-sb-cell="qty"]', row).value),
          totalEl: $('[data-sb-cell="total"]', row),
        }));

        let totals = '';
        if (rows.length) {
          totals =
            '<div class="sb-print-totales">' +
              '<div><span>Subtotal</span><strong>' + els.totalSubtotal.textContent + '</strong></div>' +
              '<div><span>Impuesto</span><strong>' + els.totalTax.textContent + '</strong></div>' +
              '<div><span>Total</span><strong>' + els.totalTotal.textContent + '</strong></div>' +
            '</div>';
        }

        const pagos = state.payments.length
          ? '<div class="sb-print-pagos"><h2>Pagos</h2>' +
            state.payments.map((payment) =>
              '<p>' + escapeHtml(payment.method) + ' — ' + money.format(payment.value) +
              (payment.desc ? ' (' + escapeHtml(payment.desc) + ')' : '') + '</p>'
            ).join('') +
            '</div>'
          : '';

        // Lo que se pagó de más es el cambio que se le entrega al cliente: en la
        // factura impresa también tiene que quedar escrito.
        const balance = payBalance();
        const cambio = balance.change > EPSILON
          ? '<div class="sb-print-totales"><div><span>Cambio</span><strong>' +
            money.format(balance.change) + '</strong></div></div>'
          : '';

        els.printInvoice.innerHTML =
          '<div class="sb-print-head">' +
            '<div>' +
              '<h1>Saibher BMS</h1>' +
              '<p>' + escapeHtml((prefix && prefix.label) || CONFIG.documentTitle) + '</p>' +
            '</div>' +
            '<div style="text-align:right">' +
              '<h2>' + escapeHtml(invoiceNumber) + '</h2>' +
              '<p>' + new Date().toLocaleDateString('es-CO') + '</p>' +
            '</div>' +
          '</div>' +
          '<div class="sb-print-cliente">' +
            '<h2>' + escapeHtml(CONFIG.partyLabel) + '</h2>' +
            '<p>' + escapeHtml(els.fieldNombre.value || '—') + '</p>' +
            '<p>Sede: ' + escapeHtml(els.fieldSede.value || '—') + '</p>' +
            '<p>' + escapeHtml(els.fieldTipoDoc.value || '') + ': ' + escapeHtml(els.fieldNumDoc.value || '') + '</p>' +
            (state.assigned ? '<p>Asignada a: ' + escapeHtml(state.assigned.name) + '</p>' : '') +
          '</div>' +
          '<table>' +
            '<thead><tr>' +
              '<th>Código</th><th>Nombre</th><th>Vr. Unitario</th><th>%</th><th>Cantidad</th><th>Total</th>' +
            '</tr></thead>' +
            '<tbody>' + rows.map((row) =>
              '<tr>' +
                '<td>' + escapeHtml(row.code) + '</td>' +
                '<td>' + escapeHtml(row.name) + '</td>' +
                '<td>' + money.format(row.unit) + '</td>' +
                '<td>' + row.tax + '%</td>' +
                '<td>' + row.qty + '</td>' +
                '<td>' + row.totalEl.textContent + '</td>' +
              '</tr>'
            ).join('') + '</tbody>' +
          '</table>' +
          totals +
          pagos +
          cambio +
          '<p class="sb-print-foot">Documento generado por Saibher BMS — demostración visual.</p>';
      };

      const doPrint = () => {
        buildPrintInvoice();
        window.print();
      };

      /* ── Cierre ─────────────────────────────────────────── */
      // «Cerrar e imprimir» deja de ser un boton de papel: guarda el estado que
      // se esta viendo, cierra la factura y solo entonces imprime. El estado
      // final lo decide el servidor (pagada o pendiente de pago) y a partir de
      // ahi el formulario deja de escribir.
      const setFormLocked = (locked) => {
        $all('.sb-billing input, .sb-billing select').forEach((field) => {
          field.disabled = locked;
        });
        $all('.sb-billing .sb-items__remove, .sb-billing [data-dialog-open]').forEach((button) => {
          button.disabled = locked;
        });
        els.printClose.disabled = locked;
      };

      const closeDocument = async (options = {}) => {
        const isCredit = options.credit === true;
        if (state.closing || state.closed || !CONFIG.draft || !CONFIG.draft.closeUrl) {
          doPrint();
          return;
        }
        state.closing = true;
        const label = isCredit && els.creditClose ? els.creditClose.textContent : els.printClose.textContent;
        if (els.printClose) els.printClose.disabled = true;
        if (els.creditClose) els.creditClose.disabled = true;
        setDraftState('sync', 'Cerrando la factura…', 'saving');

        try {
          // Lo que se cierra es exactamente lo que se esta viendo.
          const saved = await flushDraft();
          if (!saved) {
            throw new Error('draft');
          }

          if (isCredit) {
            if (window) { window.__sbBillingCredit = true; }
          }
          const payload = collectDraft();
          if (window) { window.__sbBillingCredit = false; }

          const response = await fetch(CONFIG.draft.closeUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
              'Content-Type': 'application/json',
              'X-CSRF-Token': CONFIG.draft.csrfToken,
            },
            body: JSON.stringify(payload),
          });
          const closed = await response.json().catch(() => ({}));
          if (response.status === 422) {
            renderStockAlerts('error', 'No hay inventario suficiente', stockLines(closed.shortages || []));
            throw new Error('stock');
          }
          if (!response.ok) {
            // El servidor explica qué falta: mostrarlo vale mas que un
            // «revisa la conexion» que no ayuda a nadie en el mostrador.
            throw new Error(closed.message || 'HTTP ' + response.status);
          }

          state.closed = true;
          state.draftId = closed.id || state.draftId;
          setFormLocked(true);
          setDraftState(
            'lock',
            'Factura ' + (closed.code || '') + ' cerrada · ' + (closed.statusLabel || 'pendiente de pago'),
            'saved'
          );
          doPrint();
        }
        catch (error) {
          if (error.message === 'stock') {
            setDraftState('error', 'La factura sigue en borrador: no hay inventario suficiente.', 'error');
            return;
          }
          if (error.message === 'draft') {
            setDraftState('error', 'La factura sigue en borrador: revisa el aviso.', 'error');
            return;
          }
          const reason = /^HTTP \d+$/.test(error.message)
            ? 'No se pudo cerrar la factura. Revisa la conexion.'
            : error.message;
          setDraftState('error', 'La factura sigue en borrador: ' + reason, 'error');
        }
        finally {
          state.closing = false;
          if (els.printClose) {
            els.printClose.textContent = 'Cerrar e imprimir';
            els.printClose.disabled = state.closed;
          }
          if (els.creditClose) {
            els.creditClose.textContent = 'Vender a crédito y cerrar';
            els.creditClose.disabled = state.closed;
          }
        }
      };

      els.print.addEventListener('click', doPrint);
      els.printClose.addEventListener('click', function () { closeDocument(); });
      if (els.creditClose) {
        els.creditClose.addEventListener('click', function () { closeDocument({ credit: true }); });
      }
    },
  };
}(Drupal, once));
