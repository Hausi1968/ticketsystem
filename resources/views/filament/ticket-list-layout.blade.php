{{--
    Ticketliste kompakt (nur auf /tickets eingebunden, siehe AppPanelProvider):
    - Seitenkopf (Brotkrumen, Überschrift, Reiter) braucht deutlich weniger Höhe.
    - Die Tabelle scrollt in einem eigenen Bereich, der bis zum Fensterende reicht.
      Dadurch bleiben Suche/Filter/Spaltenauswahl und der Tabellenkopf stehen,
      und der waagerechte Scrollbalken ist immer am unteren Fensterrand sichtbar.
--}}
<style>
    .fi-resource-tickets.fi-resource-list-records-page .fi-page-header-main-ctn {
        padding-block: .75rem;
        row-gap: .75rem;
    }

    .fi-resource-tickets.fi-resource-list-records-page .fi-page-content {
        row-gap: .75rem;
    }

    .fi-resource-tickets.fi-resource-list-records-page .fi-header {
        align-items: center;
    }

    .fi-resource-tickets.fi-resource-list-records-page .fi-header .fi-breadcrumbs {
        display: none;
    }

    .fi-resource-tickets.fi-resource-list-records-page .fi-header-heading {
        font-size: 1.25rem;
        line-height: 1.75rem;
    }

    .fi-resource-tickets.fi-resource-list-records-page .fi-tabs {
        padding-block: .25rem;
    }

    .fi-resource-tickets.fi-resource-list-records-page .fi-ta-header-toolbar {
        padding-block: .5rem;
    }

    @media (min-width: 640px) {
        /* Eigener Scrollbereich; die Höhe setzt das Skript unten (bis zum Fensterende) */
        .fi-resource-tickets.fi-resource-list-records-page .fi-ta-content-ctn {
            max-height: var(--anliego-ticketliste-hoehe, calc(100dvh - 15rem));
            overflow: auto;
            overscroll-behavior: contain;
        }

        /* Scrollbalken immer anzeigen (macOS blendet sie sonst aus, bis man scrollt) */
        .fi-resource-tickets.fi-resource-list-records-page .fi-ta-content-ctn::-webkit-scrollbar {
            width: 10px;
            height: 10px;
        }

        .fi-resource-tickets.fi-resource-list-records-page .fi-ta-content-ctn::-webkit-scrollbar-track {
            background: var(--gray-100);
        }

        .fi-resource-tickets.fi-resource-list-records-page .fi-ta-content-ctn::-webkit-scrollbar-thumb {
            background: var(--gray-400);
            border-radius: 9999px;
            border: 2px solid var(--gray-100);
        }

        .fi-resource-tickets.fi-resource-list-records-page .fi-ta-content-ctn::-webkit-scrollbar-corner {
            background: transparent;
        }

        .dark .fi-resource-tickets.fi-resource-list-records-page .fi-ta-content-ctn::-webkit-scrollbar-track {
            background: var(--gray-800);
        }

        .dark .fi-resource-tickets.fi-resource-list-records-page .fi-ta-content-ctn::-webkit-scrollbar-thumb {
            background: var(--gray-500);
            border-color: var(--gray-800);
        }

        @supports not selector(::-webkit-scrollbar) {
            .fi-resource-tickets.fi-resource-list-records-page .fi-ta-content-ctn {
                scrollbar-width: thin;
            }
        }

        /* Tabellenkopf bleibt beim Scrollen oben stehen */
        .fi-resource-tickets.fi-resource-list-records-page .fi-ta-table > thead > tr > th {
            position: sticky;
            top: 0;
            z-index: 5;
            background-color: var(--gray-50);
            box-shadow: inset 0 -1px 0 var(--gray-200);
        }

        .dark .fi-resource-tickets.fi-resource-list-records-page .fi-ta-table > thead > tr > th {
            background-color: color-mix(in oklab, var(--gray-900), white 5%);
            box-shadow: inset 0 -1px 0 color-mix(in oklab, var(--gray-900), white 10%);
        }
    }
</style>

<script>
    (() => {
        const breit = window.matchMedia('(min-width: 640px)');

        // Höhe des Tabellenbereichs so wählen, dass die Seite selbst nicht mehr scrollt
        const anpassen = () => {
            const bereich = document.querySelector('.fi-resource-tickets.fi-resource-list-records-page .fi-ta-content-ctn');
            if (! bereich) return;

            if (! breit.matches) {
                bereich.style.removeProperty('--anliego-ticketliste-hoehe');
                return;
            }

            // ohne Begrenzung messen, was oberhalb und unterhalb der Tabelle liegt
            bereich.style.maxHeight = 'none';
            const oben = bereich.getBoundingClientRect().top + window.scrollY;
            const darunter = document.documentElement.scrollHeight - (oben + bereich.offsetHeight);
            bereich.style.removeProperty('max-height');

            const hoehe = Math.max(240, Math.floor(window.innerHeight - oben - darunter));
            bereich.style.setProperty('--anliego-ticketliste-hoehe', hoehe + 'px');
        };

        const spaeter = () => requestAnimationFrame(anpassen);

        document.addEventListener('DOMContentLoaded', spaeter);
        window.addEventListener('load', spaeter);
        window.addEventListener('resize', spaeter);
        breit.addEventListener('change', spaeter);
        document.addEventListener('livewire:navigated', spaeter);
        document.addEventListener('livewire:init', () => {
            Livewire.hook('commit', ({ succeed }) => succeed(() => setTimeout(spaeter, 0)));
        });
    })();
</script>
