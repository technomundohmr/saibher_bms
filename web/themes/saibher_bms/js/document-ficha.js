/**
 * Ficha de un documento (factura, ticket u orden).
 *
 * El botón "Imprimir" abre el diálogo del navegador sobre la misma página: las
 * reglas @media print de node-document.css dejan solo el documento visible.
 */
(function (Drupal, once) {
  Drupal.behaviors.saibherDocumentPrint = {
    attach(context) {
      once('saibher-document-print', '[data-sb-print]', context).forEach((button) => {
        button.addEventListener('click', () => {
          window.print();
        });
      });
    },
  };
})(Drupal, once);
