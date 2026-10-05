<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Inference Service Connection
    |--------------------------------------------------------------------------
    |
    | Location of the standalone Python inference service that owns the trained
    | CNN. Laravel never loads the model itself; it sends the image over HTTP
    | and receives a structured prediction back.
    |
    */

    'url' => env('ML_SERVICE_URL', 'http://127.0.0.1:8000'),

    'predict_path' => env('ML_SERVICE_PATH', '/predict'),

    'health_path' => env('ML_SERVICE_HEALTH_PATH', '/health'),

    /*
    |--------------------------------------------------------------------------
    | Timeouts
    |--------------------------------------------------------------------------
    |
    | Inference loads the image and runs a forward pass, so the response
    | timeout must comfortably exceed the connection timeout. Exceeding the
    | response timeout surfaces to the user as a friendly retry message.
    |
    */

    'connect_timeout' => (int) env('ML_SERVICE_CONNECT_TIMEOUT', 5),

    'timeout' => (int) env('ML_SERVICE_TIMEOUT', 30),

    /*
    |--------------------------------------------------------------------------
    | Upload Constraints
    |--------------------------------------------------------------------------
    |
    | Applied by the form request before any bytes leave the application.
    | Extensions are listed without a slash on purpose: that makes Laravel
    | resolve the `mimes` rule, which inspects the file contents instead of
    | trusting the client supplied name or content type header.
    |
    */

    'upload' => [
        'max_kb' => (int) env('ML_MAX_UPLOAD_KB', 10240),

        'max_dimension' => (int) env('ML_MAX_DIMENSION', 4096),

        'allowed_extensions' => ['jpg', 'jpeg', 'png'],

        'allowed_mimes' => ['image/jpeg', 'image/png'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Inference is the most expensive operation this application performs, so
    | the endpoint is throttled per client IP.
    |
    */

    'rate_limit' => (int) env('ML_RATE_LIMIT', 20),

    /*
    |--------------------------------------------------------------------------
    | Classification Classes
    |--------------------------------------------------------------------------
    |
    | The labels this application understands, in the order the inference
    | service returns them. App\Enums\PredictionClass mirrors this list; a
    | unit test asserts both stay in sync. Never assume an index meaning here
    | without changing this file too.
    |
    */

    'classes' => ['NORMAL', 'PNEUMONIA'],

    /*
    |--------------------------------------------------------------------------
    | Model Contract
    |--------------------------------------------------------------------------
    |
    | Values below describe the artifact served by the inference service and
    | were read from the trained model itself. Replace all of them together
    | when swapping in a different network.
    |
    | input_shape    Expected WxHxC of the tensor fed to the CNN.
    | grayscale      Whether the single channel is luminance.
    | normalization  Scaling applied after resizing.
    | output_mode    sigmoid_binary => one scalar for positive_class, the
    |                remaining class is derived as 1 - scalar.
    | positive_class Label the model was trained to emit as 1.
    | tolerance      Accepted drift when the returned probabilities must sum
    |                to 1.
    |
    */

    'model' => [
        'name' => env('ML_MODEL_NAME', 'modelo_neumonia_cnn'),

        'input_shape' => [150, 150, 1],

        'grayscale' => true,

        'normalization' => 'div_255',

        'output_mode' => 'sigmoid_binary',

        'positive_class' => 'PNEUMONIA',

        'tolerance' => (float) env('ML_PROBABILITY_TOLERANCE', 0.01),
    ],

];
