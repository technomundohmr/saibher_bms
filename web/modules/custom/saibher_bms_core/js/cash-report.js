/**
 * @file
 * Comportamiento mínimo de la página Caja y finanzas.
 *
 * La impresión usa la hoja de estilos de impresión de la propia página, que
 * esconde filtros y deja solo las tablas del periodo.
 */
(function (Drupal, once) {
  'use strict';

  Drupal.behaviors.saibherCashReport = {
    attach: function (context) {
      once('sbCashPrint', '[data-sb-cash-print]', context).forEach((button) => {
        button.addEventListener('click', () => {
          window.print();
        });
      });
    },
  };
})(Drupal, once);