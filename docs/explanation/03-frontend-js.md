# Frontend: Blade + JS

## Blade
- `layouts/app.blade.php` – layout base, anti-FOUC (tema/idioma), componentes
- `analyzer/index.blade.php` – página principal con `data-*` para JS
- Componentes: `upload-dropzone`, `prediction-result`, `theme-toggle`, `language-selector`, `disclaimer`

## JS
- `app.js` importa theme/uploader/analyzer
- `theme.js` – toggle claro/oscuro + persistencia
- `uploader.js` – drag&drop, validación cliente, preview, normaliza `.jpeg`
- `analyzer.js` – submit AJAX (FormData), render resultado (confianza en %), manejo errores

## Datos `data-*` (analyzer)
Config endpoint, límites, formatos, mensajes i18n, tonos (NORMAL→positive, PNEUMONIA→critical)

## Ejemplo render confianza
```js
const confidencePercent = Math.round(prediction.confidence * 1000)/10;
confidenceEl.textContent = `${confidencePercent}%`;
```

## CSS
Tailwind v4 CSS-first, tokens oklch en `:root/.dark`, variante `dark`, utilidades, `prefers-reduced-motion`
