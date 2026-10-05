# Laravel: Estructura y responsabilidades

## Rutas (`routes/web.php`)
- `GET /` → `AnalyzerController` (render página)
- `POST /analyze` → `PredictionController` + throttle `analysis`
- `GET /locale/{locale}` → `LocaleController`

## Controladores
- `AnalyzerController@index()` pasa `classes`, `maxUploadKb`, `acceptedFormats` a vista
- `PredictionController` orquesta validación + servicio + respuesta JSON
- `LocaleController` guarda locale en sesión/cookie

## Validación
- `AnalyzeImageRequest`: `mimes:jpg,jpeg,png`, `max:10240`, `dimensions:max_width=4096,max_height=4096`, + `ReadableImage::fromContainer()`
- `ReadableImage` usa `ImageInspector` (GD) para leer imagen válida
- `ImageInspector` inyectado con `max_dimension` desde config

## Cliente HTTP
- `HttpInferenceClient` usa `Illuminate\Http\Client\Factory`
- Adjunta stream sin cerrar antes de enviar; timeout connect/response
- Traduce excepciones a domain: `InferenceServiceUnavailableException`, `InferenceTimeoutException`, `UnreadableImageException`, `InvalidInferenceResponseException`

## DTOs
- `ClassProbability` → `{class, probability, percentage}`
- `PredictionResult::fromArray()` valida esquema, clases configuradas, suma ~1.0 (tolerancia)

## Config
- `config/inference.php` centraliza URL, timeouts, límites, clases, contrato modelo

## Ejemplo: flujo `/analyze`
```php
$request->validate([...]);
$result = $service->classify($request->file('image'));
return response()->json(['success'=>true, 'prediction'=>$result->toArray()]);
```
