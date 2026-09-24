# Credits

Lafka Plugin is licensed under the GNU General Public License v2 or later
(see `LICENSE`). It bundles the following third-party software, each under a
GPL-compatible licence:

| Library | Version | Location | Licence |
|---|---|---|---|
| [flatpickr](https://github.com/flatpickr/flatpickr) (date picker + locale files) | 4.6.13 | `assets/js/flatpickr/` | MIT |
| [jQuery Schedule](https://github.com/Yehzuna/jquery-schedule) (weekly opening-hours editor) | 2.1.0 | `assets/js/schedule/`, `assets/css/schedule/` | MIT |
| [Font Awesome Free](https://fontawesome.com) (icon font, used only when the active theme doesn't provide it) | 6.7.2 | `assets/vendor/font-awesome/` | Icons CC BY 4.0, fonts SIL OFL 1.1, code MIT |

The Font Awesome copy ships the WOFF2 webfonts only; the stylesheet's TTF
fallbacks are for browsers without WOFF2 support and are not included.

## First-party minified scripts

Every first-party `*.min.js` ships next to its readable `*.js` source. The
minified files are generated from those sources by `npm run build` (esbuild,
minify only); with `SCRIPT_DEBUG` enabled WordPress loads the sources instead.
