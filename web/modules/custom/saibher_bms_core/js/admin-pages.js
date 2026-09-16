(function (Drupal, once) {
  function openDialog(dialog) {
    if (typeof dialog.show === 'function') {
      // Usamos show() en lugar de showModal() para NO entrar en la "top layer":
      // así los diálogos jQuery de Drupal (p. ej. la Media Library, que se
      // insertan al final de body) quedan por encima del modal y son utilizables.
      // El fondo oscurecido se logra con el estilo .saibher-modal[open] como
      // overlay.
      dialog.show();
    } else {
      dialog.removeAttribute('hidden');
      dialog.classList.add('is-open');
    }
  }

  function closeDialog(dialog) {
    if (typeof dialog.close === 'function') {
      dialog.close();
    } else {
      dialog.setAttribute('hidden', 'hidden');
      dialog.classList.remove('is-open');
    }
  }

  Drupal.behaviors.saibherBmsDialogs = {
    attach(context) {
      once('saibher-bms-dialogs', '[data-dialog-open]', context).forEach((button) => {
        const dialog = document.getElementById(button.dataset.dialogOpen);
        if (!dialog) {
          return;
        }

        button.addEventListener('click', () => {
          // La modal compartida puede mostrar el formulario de edición cargado
          // por AJAX; al abrir desde "Crear" se restaura el de alta.
          const createSlot = dialog.querySelector('[data-saibher-modal-create]');
          const editSlot = dialog.querySelector('[data-saibher-modal-edit]');
          if (createSlot) {
            createSlot.hidden = false;
          }
          if (editSlot) {
            editSlot.hidden = true;
          }
          if (button.dataset.dialogTitle) {
            const titleEl = dialog.querySelector('.saibher-modal__title');
            if (titleEl) {
              titleEl.textContent = button.dataset.dialogTitle;
            }
          }
          openDialog(dialog);
        });
      });

      once('saibher-bms-dialog-close', '[data-dialog-close]', context).forEach((button) => {
        button.addEventListener('click', () => {
          const dialog = button.closest('dialog');
          if (dialog) {
            closeDialog(dialog);
          }
        });
      });
    },
  };

  Drupal.behaviors.saibherBmsDialogEdit = {
    attach(context) {
      once('saibher-bms-dialog-edit', '[data-dialog-edit]', context).forEach((button) => {
        button.addEventListener('click', (event) => {
          const dialog = document.getElementById('saibher-modal');
          const editSlot = dialog ? dialog.querySelector('[data-saibher-modal-edit]') : null;
          if (!dialog || !editSlot) {
            // En páginas sin la modal del panel se deja actuar al enlace de
            // respaldo hacia el formulario de edición estándar.
            return;
          }

          event.preventDefault();

          const url = button.dataset.dialogEdit;
          const title = button.dataset.dialogTitle || '';

          const ajax = Drupal.ajax({
            url,
            progress: { type: 'throbber', message: Drupal.t('Cargando…') },
          });

          const originalSuccess = Drupal.Ajax.prototype.success;
          ajax.success = async function (response, status) {
            // En Drupal 11 el success por defecto es asíncrono (procesa los
            // comandos mediante una cola y devuelve un Promise). Hay que esperar
            // a que el comando "insert" termine de reemplazar el slot, porque el
            // nodo nuevo llega con el atributo hidden y sustituye al que tocamos.
            await originalSuccess.call(this, response, status);

            const createSlot = dialog.querySelector('[data-saibher-modal-create]');
            const loadedEditSlot = dialog.querySelector('[data-saibher-modal-edit]');
            if (createSlot) {
              createSlot.hidden = true;
            }
            if (loadedEditSlot) {
              loadedEditSlot.hidden = false;
              // El formulario entra por ReplaceCommand sin comando "settings":
              // se re-ejecutan los behaviors de Drupal para que el nuevo form
              // quede tan inicializado como el de creación (pestañas verticales,
              // widget de imagen, selección de fecha, autocompletado, etc.).
              Drupal.attachBehaviors(loadedEditSlot);
            }
            if (title) {
              const titleEl = dialog.querySelector('.saibher-modal__title');
              if (titleEl) {
                titleEl.textContent = title;
              }
            }
            openDialog(dialog);
          };

          ajax.execute();
        });
      });
    },
  };

  // El diálogo jQuery de la Media Library se inserta al final de body sin
  // saber nada de nuestro modal (z-index 900): lo elevamos por encima para
  // que quede operativo sobre el formulario de categoría.
  Drupal.behaviors.saibherBmsMediaLibraryStacking = {
    attach() {
      const raise = () => {
        document.querySelectorAll('.ui-dialog.media-library-widget-modal').forEach((dialog) => {
          dialog.style.zIndex = '2000';
        });
      };

      raise();
      const observer = new MutationObserver(raise);
      observer.observe(document.body, { childList: true });
      this.observer = observer;
    },
    detach() {
      if (this.observer) {
        this.observer.disconnect();
      }
    },
  };
// Los grupos field_group renderizan los campos como hijos directos de
  // <details>, y Chrome NO aplica grid/flex sobre <details>: los pares se
  // envuelven en .sb-form-row (un <div> plano) para poder mostrarlos en una
  // fila de dos columnas vía CSS. Los campos largos (título, categoría,
  // impuesto, descripción, imagen) ocupan su propia fila a lo ancho completo.
  Drupal.behaviors.saibherBmsFormRows = {
    attach(context) {
      if (!context.querySelectorAll) {
        return;
      }
      context.querySelectorAll('details.field-group-details').forEach((details) => {
        if (details.dataset.sbRows) {
          return;
        }
        details.dataset.sbRows = '1';

        const isLong = (el) =>
          el.classList.contains('field--name-title') ||
          el.classList.contains('field--name-field-category') ||
          el.classList.contains('field--name-field-tax') ||
          el.classList.contains('field--name-field-description') ||
          el.classList.contains('field--name-field-image');

        let buffer = [];
        const flush = () => {
          if (!buffer.length) {
            return;
          }
          const row = document.createElement('div');
          row.className = 'sb-form-row' + (buffer.length === 1 ? ' sb-form-row--full' : '');
          buffer.forEach((el) => row.appendChild(el));
          details.appendChild(row);
          buffer = [];
        };

        Array.from(details.children)
          .filter((el) => el.tagName !== 'SUMMARY')
          .forEach((el) => {
            if (isLong(el)) {
              flush();
              const row = document.createElement('div');
              row.className = 'sb-form-row sb-form-row--full';
              row.appendChild(el);
              details.appendChild(row);
            } else {
              buffer.push(el);
              if (buffer.length === 2) {
                flush();
              }
            }
          });
        flush();
      });
    },
  };
})(Drupal, once);