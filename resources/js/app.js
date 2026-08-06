/**
 * Gold Bot front-end behaviour.
 *
 * Loaded before Alpine's CSP build, which is used instead of the standard
 * build because the standard one evaluates every `x-` expression with
 * `new Function()` — and the Content-Security-Policy in SecurityHeaders
 * deliberately withholds 'unsafe-eval'. Relaxing the policy to suit a UI
 * library would undo the protection it exists to provide.
 *
 * The trade is that components must be registered here as named data objects.
 * Templates may only reference property and method NAMES — no inline
 * expressions, no `x-data="{ open: false }"`.
 */
document.addEventListener('alpine:init', () => {

    /**
     * Dashboard shell: the off-canvas navigation drawer.
     *
     * Below `lg` the sidebar is a drawer; from `lg` up it is a fixed rail and
     * `navOpen` is irrelevant because `lg:translate-x-0` wins.
     */
    Alpine.data('shell', () => ({
        navOpen: false,

        open() {
            this.navOpen = true;
        },

        close() {
            this.navOpen = false;
        },

        /** Escape closes the drawer — expected of any modal-ish overlay. */
        onKeydown(event) {
            if (event.key === 'Escape') {
                this.navOpen = false;
            }
        },

        get panelClass() {
            return this.navOpen ? 'translate-x-0' : '-translate-x-full';
        },
    }));
});
