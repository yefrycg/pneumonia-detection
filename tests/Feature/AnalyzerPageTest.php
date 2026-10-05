<?php

it('renders the analyzer page', function () {
    $this->get(route('analyzer.index'))
        ->assertOk()
        ->assertSee(__('analyzer.title'))
        ->assertSee(__('analyzer.disclaimer.body'))
        ->assertSee(__('analyzer.analyze'));
});

it('states plainly that the result is not a diagnosis', function () {
    $this->get(route('analyzer.index'))
        ->assertOk()
        ->assertSee(__('analyzer.disclaimer.body'));
});

it('exposes a CSRF token and an multipart upload field', function () {
    $this->get(route('analyzer.index'))
        ->assertOk()
        ->assertSee('name="_token"', escape: false)
        ->assertSee('name="image"', escape: false)
        ->assertSee('enctype="multipart/form-data"', escape: false);
});

it('offers only the formats the server accepts', function () {
    $this->get(route('analyzer.index'))
        ->assertOk()
        ->assertSee('accept=".jpg,.jpeg,.png"', escape: false);
});

it('renders one probability row per configured class', function () {
    $this->get(route('analyzer.index'))
        ->assertOk()
        ->assertSee('data-probability="NORMAL"', escape: false)
        ->assertSee('data-probability="PNEUMONIA"', escape: false);
});

it('renders in the configured default locale', function () {
    $this->get(route('analyzer.index'))
        ->assertOk()
        ->assertSee('<html lang="'.config('app.locale').'"', escape: false);
});

it('switches the interface language and remembers it', function () {
    $this->get(route('locale.set', 'es'))
        ->assertRedirect(route('analyzer.index'));

    $this->get(route('analyzer.index'))
        ->assertOk()
        ->assertSee('<html lang="es"', escape: false)
        ->assertSee(__('analyzer.disclaimer.body', locale: 'es'));
});

it('rejects an unsupported locale', function () {
    $this->get(route('locale.set', 'de'))->assertNotFound();
});

it('keeps the analyze endpoint out of the html flow by requiring json', function () {
    $this->post(route('analyzer.analyze'), [])
        ->assertStatus(422)
        ->assertHeader('content-type', 'application/json');
});

it('never exposes the inference service URL to the browser', function () {
    $response = $this->get(route('analyzer.index'))->assertOk();

    expect($response->getContent())->not->toContain((string) config('inference.url'));
});

it('keeps every element the analyzer script looks up inside the analyzer root', function () {
    $html = $this->get(route('analyzer.index'))->assertOk()->getContent();

    $document = new DOMDocument;
    libxml_use_internal_errors(true);
    $document->loadHTML($html);
    libxml_clear_errors();

    $xpath = new DOMXPath($document);
    $root = $xpath->query('//*[@data-analyzer]')->item(0);

    expect($root)->not->toBeNull();

    $hooks = [
        'data-form',
        'data-uploader',
        'data-submit',
        'data-submit-label',
        'data-spinner',
        'data-alert',
        'data-alert-message',
        'data-status',
        'data-result',
        'data-verdict',
        'data-class-label',
        'data-confidence',
        'data-model',
        'data-analyze-again',
    ];

    foreach ($hooks as $hook) {
        expect($xpath->query('.//*[@'.$hook.']', $root)->length)
            ->toBeGreaterThan(0, "The analyzer script queries [{$hook}] but it is not inside [data-analyzer].");
    }
});
