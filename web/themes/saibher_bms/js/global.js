/**
 * Interacción global del panel: sidebar retractil en escritorio y móvil.
 */
(function (Drupal, once) {
  Drupal.behaviors.saibherSidebar = {
    attach(context) {
      once('saibher-sidebar', '[aria-controls="sideNavBar"]', context).forEach((toggle) => {
        const sidebar = document.getElementById('sideNavBar');
        const backdrop = document.querySelector('[data-sidebar-backdrop]');
        const toggles = document.querySelectorAll('[aria-controls="sideNavBar"]');

        if (!sidebar) {
          return;
        }

        const isMobile = () => window.matchMedia('(max-width: 767px)').matches;
        const setExpanded = (expanded) => {
          document.documentElement.classList.toggle('sb-sidebar-is-collapsed', !expanded && !isMobile());
          document.documentElement.classList.toggle('sb-sidebar-is-open', expanded && isMobile());
          toggles.forEach((button) => {
            button.setAttribute('aria-expanded', String(expanded));
            const icon = button.querySelector('.material-symbols-outlined');
            if (icon) {
              icon.textContent = isMobile()
                ? (expanded ? 'close' : 'menu')
                : (expanded ? 'menu_open' : 'menu');
            }
          });
          if (backdrop) {
            backdrop.classList.toggle('is-visible', expanded && isMobile());
          }
        };

        toggle.addEventListener('click', () => {
          const expanded = toggle.getAttribute('aria-expanded') === 'true';
          setExpanded(!expanded);
        });

        if (backdrop) {
          backdrop.addEventListener('click', () => setExpanded(false));
        }

        document.addEventListener('keydown', (event) => {
          if (event.key === 'Escape' && isMobile()) {
            setExpanded(false);
          }
        });

        window.addEventListener('resize', () => {
          setExpanded(!isMobile());
        });

        setExpanded(!isMobile());
      });
    },
  };
})(Drupal, once);