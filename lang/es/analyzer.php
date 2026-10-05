<?php

return [
    'title' => 'Analizador de radiografías de tórax',
    'tagline' => 'Clasificación experimental mediante inteligencia artificial',
    'description' => 'Sube una radiografía de tórax y un modelo de redes convolucionales entrenado experimentalmente devolverá la probabilidad estimada para cada categoría.',
    'model' => [
        'label' => 'Modelo entrenado',
        'experimental_badge' => 'Experimental',
    ],
    'upload' => [
        'heading' => 'Selecciona una imagen',
        'dropzone' => 'Arrastra y suelta una imagen aquí',
        'or' => 'o',
        'choose' => 'Seleccionar imagen',
        'replace' => 'Cambiar imagen',
        'instructions' => 'Formatos permitidos: :formats',
        'max_size' => 'Tamaño máximo: :size',
        'hint' => 'La imagen se procesa de forma temporal y se elimina al terminar el análisis.',
        'filename' => 'Archivo',
        'filesize' => 'Tamaño',
        'dimensions' => 'Dimensiones',
        'preview_alt' => 'Vista previa de la radiografía seleccionada',
    ],
    'analyze' => 'Analizar imagen',
    'analyzing' => 'Analizando…',
    'result' => [
        'heading' => 'Resultado del modelo',
        'predicted_class' => 'Clase predicha',
        'statement' => 'El modelo clasificó la imagen como :class.',
        'confidence' => 'Confianza estimada',
        'model_name' => 'Modelo',
        'probabilities' => 'Probabilidades por categoría',
        'again' => 'Analizar otra imagen',
        'in_process' => 'Procesando la imagen con el modelo…',
        'completed' => 'Análisis completado. El modelo clasificó la imagen como :class.',
    ],
    'disclaimer' => [
        'heading' => 'Nota',
        'body' => 'Resultado experimental generado por un modelo de inteligencia artificial. No constituye un diagnóstico médico y no reemplaza la evaluación de un profesional de salud.',
        'short' => 'Herramienta experimental. No es un diagnóstico médico.',
    ],
    'fields' => [
        'image' => 'Radiografía',
    ],
    'validation' => [
        'unreadable_image' => 'No pudimos leer esa imagen. Asegúrate de que sea un JPG o PNG válido.',
    ],
    'client_errors' => [
        'wrong_type' => 'Selecciona un archivo %FORMATS%.',
        'too_large' => 'La imagen supera el tamaño máximo de %SIZE%.',
        'unreadable' => 'No pudimos leer esa imagen. Asegúrate de que sea un JPG o PNG válido.',
        'missing_file' => 'Selecciona una imagen antes de analizarla.',
    ],
    'preview' => [
        'unknown_dimensions' => 'No disponible',
        'in_process' => 'Procesando la imagen con el modelo…',
        'completed' => 'Análisis completado. El modelo clasificó la imagen como %CLASS%.',
    ],
    'theme' => [
        'toggle' => 'Cambiar entre tema claro y oscuro',
        'light' => 'Tema claro',
        'dark' => 'Tema oscuro',
    ],
    'language' => [
        'toggle' => 'Cambiar idioma',
        'es' => 'Español',
        'en' => 'Inglés',
    ],
    'footer' => [
        'disclaimer' => 'Uso experimental. No utilices esta herramienta para diagnosticar.',
    ],
    'accessibility' => [
        'skip_to_content' => 'Ir al contenido principal',
        'status_region' => 'Estado del análisis',
    ],
    'progress' => [
        'label' => ':class, :percentage por ciento',
    ],
];
