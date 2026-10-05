<?php

return [
    'title' => 'Chest X-Ray Analyzer',
    'tagline' => 'Experimental classification powered by artificial intelligence',
    'description' => 'Upload a chest X-ray and an experimentally trained convolutional neural network will return the estimated probability for each category.',
    'model' => [
        'label' => 'Trained model',
        'experimental_badge' => 'Experimental',
    ],
    'upload' => [
        'heading' => 'Select an image',
        'dropzone' => 'Drag and drop an image here',
        'or' => 'or',
        'choose' => 'Select image',
        'replace' => 'Change image',
        'instructions' => 'Allowed formats: :formats',
        'max_size' => 'Maximum size: :size',
        'hint' => 'The image is processed temporarily and deleted once the analysis finishes.',
        'filename' => 'File',
        'filesize' => 'Size',
        'dimensions' => 'Dimensions',
        'preview_alt' => 'Preview of the selected X-ray',
    ],
    'analyze' => 'Analyze image',
    'analyzing' => 'Analyzing…',
    'result' => [
        'heading' => 'Model result',
        'predicted_class' => 'Predicted class',
        'statement' => 'The model classified the image as :class.',
        'confidence' => 'Estimated confidence',
        'model_name' => 'Model',
        'probabilities' => 'Probability per category',
        'again' => 'Analyze another image',
        'in_process' => 'Processing the image with the model…',
        'completed' => 'Analysis complete. The model classified the image as :class.',
    ],
    'disclaimer' => [
        'heading' => 'Note',
        'body' => 'Experimental result produced by an artificial intelligence model. It does not constitute a medical diagnosis and does not replace the assessment of a healthcare professional.',
        'short' => 'Experimental tool. Not a medical diagnosis.',
    ],
    'fields' => [
        'image' => 'X-ray',
    ],
    'validation' => [
        'unreadable_image' => 'We could not read that image. Make sure it is a valid JPG or PNG.',
    ],
    'client_errors' => [
        'wrong_type' => 'Select a %FORMATS% file.',
        'too_large' => 'The image exceeds the maximum size of %SIZE%.',
        'unreadable' => 'We could not read that image. Make sure it is a valid JPG or PNG.',
        'missing_file' => 'Select an image before analyzing it.',
    ],
    'preview' => [
        'unknown_dimensions' => 'Unavailable',
        'in_process' => 'Processing the image with the model…',
        'completed' => 'Analysis complete. The model classified the image as %CLASS%.',
    ],
    'theme' => [
        'toggle' => 'Switch between light and dark theme',
        'light' => 'Light theme',
        'dark' => 'Dark theme',
    ],
    'language' => [
        'toggle' => 'Change language',
        'es' => 'Spanish',
        'en' => 'English',
    ],
    'footer' => [
        'disclaimer' => 'Experimental use. Do not use this tool to diagnose.',
    ],
    'accessibility' => [
        'skip_to_content' => 'Skip to main content',
        'status_region' => 'Analysis status',
    ],
    'progress' => [
        'label' => ':class, :percentage percent',
    ],
];
