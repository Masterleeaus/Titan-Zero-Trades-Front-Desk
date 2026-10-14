# Readable source for the minified and built JavaScript in Titan Zero Trades: Front Desk

The plugin ships a few JavaScript files that are minified or were built by a tool. This folder holds the
readable source for each of them, and the commands that rebuild or re-format them. The plugin's readme.txt
points here.

Files that are not listed below are already readable (hand-written source, or unminified third-party code
with its own license header) and are shipped as they are.

## Minified files and their readable source

| Shipped file (in `js/` or `tzfd-calendar/js/`) | What it is | Readable source | How to rebuild or check |
|---|---|---|---|
| `js/tzfdform-public.js` | Public booking-form bundle. Minified. Enqueued on the front end. | `js/src/tzfdform-public.src.js` (formatted, not minified). It is the output of `js/tzfdform-loader-public.php`: `js/jQuery.stringify.js`, `js/jquery.validate.js`, `js/tzfd-public-accessibility.js`, `js/tzfd-calendar.js`, `js/tzfdform-core-public.jquery.js` and every file in `js/tzfdform-public-fields/` (sorted by file name), in that order. The same readable code is also served unminified at `?tzfd_resources=public`. | `cd js/src && npm ci && npm run build:public`. This writes `tzfdform-public.src.js` and `../tzfdform-public.js`. Needs the PHP CLI and Node.js 18 or later. Pinned build tools: terser 5.51.2 and prettier 3.9.10 (in `package.json`). To check that the shipped file matches its source, run the build and then `git diff --exit-code -- ../tzfdform-public.js`. |
| `js/chart.js` | Chart.js 4.5.1, `dist/chart.umd.js` from the npm package. Minified upstream build. License: MIT (header kept in the file). | `js/src/chart.src.js` (formatted copy of the same file, not the upstream ES source). Upstream source: https://github.com/chartjs/Chart.js/tree/v4.5.1/src | Re-download with `npm pack chart.js@4.5.1`, then copy `package/dist/chart.umd.js` to `js/chart.js`. Readable copy: `cd js/src && npx prettier@3.9.10 --parser babel ../chart.js > chart.src.js` (with the header line from the existing file). |
| `js/tzfdform-admin-fields/00_jquery.parse.min.js` | Papa Parse v5.7.0 (CSV import for the admin builder), upstream minified build. Minified. Loaded by the admin loader and enqueued for the builder. | `js/src/00_jquery.parse.src.js` (upstream unminified papaparse.js 5.7.0, unmodified). Upstream project: https://github.com/mholt/PapaParse | Readable copy is the upstream source; no build step. |

## Admin builder bundle: readable as shipped, not generated

`js/tzfdform-admin.js` is the admin form builder. It is enqueued by default (`tzfd-main-class.inc.php`), and it is
formatted, not minified. It has no build step. Its readable source is the file itself, so edit it directly.

It is not a generated copy of the files in `js/tzfdform-admin-fields/` and `js/tzfdform-core-admin.jquery.js`.
Those files are loaded only by the admin loader (`js/tzfdform-loader-admin.php`, served at `?tzfd_resources=admin`).
That loader runs when `TZFD_DYNAMIC_LOADING` is defined (it is off by default) or when the bundle did not load.
Checked on 2026-10-14: the bundle and the loader output contain different strings and identifiers, so a change to
one is not seen by the other. If you change the admin builder, change the bundle, and change the source files too
if the loader path matters.

## Files that are readable as shipped

These are not minified. They are listed so that the reviewer can see where their source lives.

| File | Notes |
|---|---|
| `tzfd-calendar/js/rrule.js` | rrule 2.8.1 (BSD-3-Clause), webpack UMD build, not minified. Upstream source: the rrule project, https://github.com/jakubroztocil/rrule (npm package `rrule@2.8.1`, `dist/es5` and `src`). |
| `tzfd-calendar/js/jquery.calendar.js` | Calendar widget source, not minified. |
| `js/jquery.validate.js` | jQuery Validation plugin, modified copy, not minified. |
| `js/jQuery.stringify.js`, `js/tzfdform-core-*.jquery.js`, `js/tzfdform-public-fields/*.js`, `js/tzfdform-admin-fields/*.js` (except the .min.js file above) | Hand-written source. |

## Notes

- `tzfdform-public.src.js` and `chart.src.js` are generated. Do not edit them by hand. Change the source files
  listed above, then run the build. Any change to a file the public loader reads (the files listed in the first
  table row, and anything added to `js/tzfdform-public-fields/`) needs a rebuild, or the shipped bundle is stale.
- Checked on 2026-10-14 in a copy of `js/` outside the plugin: `npm ci` and the build ran, and the rebuild reproduced
  the shipped `js/tzfdform-public.js` and `js/src/tzfdform-public.src.js` byte for byte (before that day's edits to
  the public sources; after them the bundle was rebuilt and a second build gave the same bytes). `npm pack chart.js@4.5.1` gives a `dist/chart.umd.js` that is byte-identical to `js/chart.js`.
- `node_modules/` is not shipped. Remove it before packaging if it was installed here.
