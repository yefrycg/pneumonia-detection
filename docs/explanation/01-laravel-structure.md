# Laravel: Estructura y responsabilidades

## Rutas (`routes/web.php`)
- `GET /` → `AnalyzerController` (render página)
- `POST /analyze` → `PredictionController` + throttle `analysis`
- `POST /recommend` → `RecommendationController` + throttle `recommendation`
- `POST /recommendation/feedback` → `RecommendationFeedbackController` + throttle `feedback`
- `GET /locale/{locale}` → `LocaleController`

Las tres rutas POST están en el grupo `web`: sesión, CSRF y cookies. El
frontend envía `X-CSRF-TOKEN` en las llamadas de recomendación y feedback.

## Controladores
- `AnalyzerController@index()` pasa `classes`, `maxUploadKb`, `acceptedFormats` a la vista
- `PredictionController` orquesta validación + servicio + respuesta JSON
- `RecommendationController` valida `class`, `confidence`, `locale` y devuelve
  `{success, recommendation}`
- `RecommendationFeedbackController` devuelve `{success, feedback}`
- `LocaleController` guarda locale en sesión/cookie

## Validación
- `AnalyzeImageRequest`: `mimes:jpg,jpeg,png`, `max:10240`, `dimensions:max_width=4096,max_height=4096`, + `ReadableImage::fromContainer()`
- `ReadableImage` usa `ImageInspector` (GD) para leer imagen válida
- `ImageInspector` inyectado con `max_dimension` desde config
- `RecommendationRequest`: `class` en `config('inference.classes')`,
  `confidence` entre 0 y 1, `locale` en `config('app.supported_locales')`
- `RecommendationFeedbackRequest`: `recommendation_id` UUID y `reward` en `1,-1`

## Cliente HTTP
- `HttpInferenceClient` implementa `InferenceClient` con `classify()`,
  `recommend()` y `feedback()`
- Usa `Illuminate\Http\Client\Factory`; adjunta el stream de la imagen sin
  cerrarlo antes de enviar
- Timeouts separados: predicción (30 s), recomendación (90 s), feedback (15 s)
- Traduce excepciones a dominio: `InferenceServiceUnavailableException`,
  `InferenceTimeoutException`, `UnreadableImageException`,
  `InvalidInferenceResponseException` y `RecommendationFeedbackException`
- Solo confía en el código de error del servicio; descarta mensajes internos

## DTOs
- `ClassProbability` → `{class, probability, percentage}`
- `PredictionResult::fromArray()` valida esquema, clases configuradas, suma ~1.0 (tolerancia)
- `Recommendation::fromArray()` valida UUID, `source` (`llm`/`fallback`), texto
  mínimo, estado, acción y bloque RL con ranking no vacío
- `RankedAction` y `FeedbackResult` validan los tipos y valores del agente
- `toArray()` produce la forma exacta que consume `recommendation.js`

## Servicios
- `PredictionService` mantiene el flujo CNN original
- `RecommendationService` une transporte + validación de DTO, igual que el flujo
  de clasificación, sin meter lógica en los controladores

## Config
- `config/inference.php` centraliza URL, paths, timeouts, límites, clases,
  contrato del modelo, throttles (`recommendation_rate_limit`, `feedback_rate_limit`)
  y `recommendation.min_chars`
- Las variables nuevas están documentadas en `.env.example`

## Ejemplo: flujo `/analyze`
```php
$request->validate([...]);
$result = $service->classify($request->file('image'));
return response()->json(['success'=>true, 'prediction'=>$result->toArray()]);
```

## Ejemplo: flujo `/recommend`
```php
$recommendation = $service->recommend(
    PredictionClass::from($request->validated('class')),
    (float) $request->validated('confidence'),
    (string) $request->validated('locale'),
);

return response()->json([
    'success' => true,
    'recommendation' => $recommendation->toArray(),
]);
```

Los errores de inferencia se renderizan como JSON seguro en `bootstrap/app.php`
para `analyze`, `recommend` y `recommendation/*`, con códigos estables y mensajes
traducidos.
