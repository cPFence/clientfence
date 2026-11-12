# ClientFence branding

The ClientFence logo (by cPFence), with a transparent background:

| File | Use |
| --- | --- |
| `clientfence-logo-sheet.png` | the designer's sheet (2026-10-18): every variant below |
| `clientfence-logo.png` | horizontal logo for light backgrounds |
| `clientfence-logo-dark.png` | horizontal logo for dark backgrounds |
| `clientfence-logo-stacked.png` | stacked logo (icon above the name) for light backgrounds |
| `clientfence-logo-stacked-dark.png` | stacked logo for dark backgrounds |
| `clientfence-icon.png` | the C icon alone (square) |
| `clientfence-banner.png` | the general banner (1600 × 900) at the top of the project README |

The variants are cut from the sheet, each with a 14 px margin for its soft shadow. The
application's default branding is made from them: `src/public/branding/logo.png`,
`logo-dark.png` (720 × 200), `icon.png` (512 × 512) and `favicon.ico` (16–64 px). A
portal's own logos are set in Settings → Company and kept in `public/branding/site/`.

The icon on the sheet is about 230 × 260 px, so the 512 px `icon.png` is enlarged from it.
When a larger icon or an SVG arrives, replace `clientfence-icon.png` and regenerate the
small files.
