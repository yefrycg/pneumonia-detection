# Frontend: Blade + JS

## Blade
- `layouts/app.blade.php` – layout base, anti-FOUC (tema/idioma), componentes
- `analyzer/index.blade.php` – página principal con `data-*` para JS
- Componentes: `upload-dropzone`, `prediction-result`, `recommendation`,
  `theme-toggle`, `language-selector`, `disclaimer`

La tarjeta `x-recommendation` vive dentro de `[data-analyzer]`, ocupa las dos
columnas en escritorio y arranca oculta. Recibe por `data-*`: endpoints de
recomendación y feedback, locale y todos los textos ES/EN.

## JS
- `app.js` importa theme/uploader/analyzer
- `theme.js` – toggle claro/oscuro + persistencia
- `uploader.js` – drag&drop, validación cliente, preview, normaliza `.jpeg`
- `analyzer.js` – submit AJAX (FormData), render resultado (confianza en %),
  manejo errores; tras pintar la predicción llama a `recommendation.load()`
- `recommendation.js` – carga independiente, estados de carga/error, badge de
  origen, voto 👍/👎 y panel “Aprendizaje del agente”

## Flujo de la tarjeta
1. `analyzer.js` recibe la predicción y muestra el resultado de inmediato.
2. `recommendation.load(prediction)` hace `POST /recommend` con `AbortController`.
3. Mientras espera, muestra spinner con `role="status"` y `aria-live="polite"`.
4. Al responder, pinta el texto con `textContent` (nunca `innerHTML`), el badge
   `llm`/`fallback`, el disclaimer y los botones de feedback.
5. Un error de recomendación solo afecta a la tarjeta; la predicción sigue visible.
6. El panel colapsable muestra estado (`PNEUMONIA:high`), acción, Q-value, modo
   (`exploración`/`explotación`), contador de feedback y ranking de acciones.
7. Un voto hace `POST /recommendation/feedback`; al terminar, ambos botones se
   deshabilitan y se muestra el agradecimiento (o el error correspondiente).

## Datos `data-*` (analyzer)
Config endpoint, límites, formatos, mensajes i18n, tonos (NORMAL→positive, PNEUMONIA→critical).
La tarjeta de recomendación tiene sus propios `data-recommend-endpoint`,
`data-feedback-endpoint`, `data-locale`, `data-source-*` y mensajes; no depende
del root del analyzer para esos valores.

## Accesibilidad y estados
- `aria-live` para carga y feedback; `role="alert"` para errores.
- Botones nativos, foco visible y `disabled` real durante el envío.
- `prefers-reduced-motion` ya cubierto por el CSS global.
- Tema oscuro mediante tokens existentes (`bg-surface-raised`, `text-ink`, etc.);
  el badge de fallback usa tonos `warning`, el de LLM usa `accent`.

## Ejemplo render confianza
```js
const confidencePercent = Math.round(prediction.confidence * 1000)/10;
confidenceEl.textContent = `${confidencePercent}%`;
```

## Ejemplo Q-value
```js
const formatQ = (value) => {
    const number = Number(value);
    return Number.isFinite(number) ? number.toFixed(3) : '—';
};
```

## CSS
Tailwind v4 CSS-first, tokens oklch en `:root/.dark`, variante `dark`, utilidades, `prefers-reduced-motion`
