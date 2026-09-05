(function (Drupal, once) {
  Drupal.behaviors.saibherBmsDialogs = {
    attach(context) {
      once('saibher-bms-dialogs', '[data-dialog-open]', context).forEach((button) => {
        const dialog = document.getElementById(button.dataset.dialogOpen);
        if (!dialog) {
          return;
        }

        button.addEventListener('click', () => {
          if (typeof dialog.showModal === 'function') {
            dialog.showModal();
          } else {
            dialog.removeAttribute('hidden');
            dialog.classList.add('is-open');
          }
        });
      });

      once('saibher-bms-dialog-close', '[data-dialog-close]', context).forEach((button) => {
        button.addEventListener('click', () => {
          const dialog = button.closest('dialog');
          if (dialog && typeof dialog.close === 'function') {
            dialog.close();
          } else if (dialog) {
            dialog.setAttribute('hidden', 'hidden');
            dialog.classList.remove('is-open');
          }
        });
      });
    },
  };
})(Drupal, once);

