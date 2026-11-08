/**
 * Drives the analysis form: submits the selected radiograph to the Laravel
 * endpoint and renders the returned prediction.
 *
 * The server response is the only source of truth. Everything rendered here is
 * filled from the validated payload, and any error the endpoint reports is
 * surfaced verbatim rather than being replaced by a generic message.
 */

import { createRecommendation } from './recommendation';
import { createUploader } from './uploader';

function readMessages(root) {
    return {
        generic: root.dataset.errorGeneric ?? '',
        wrongType: root.dataset.messageWrongType ?? '',
        tooLarge: root.dataset.messageTooLarge ?? '',
        missingFile: root.dataset.messageMissingFile ?? '',
        inProcess: root.dataset.messageInProcess ?? '',
        completed: root.dataset.messageCompleted ?? '',
        unknownDimensions: root.dataset.labelUnknownDimensions ?? '',
        labelAnalyze: root.dataset.labelAnalyze ?? '',
        labelAnalyzing: root.dataset.labelAnalyzing ?? '',
    };
}

function readConfig(root) {
    return {
        maxBytes: Number(root.dataset.maxBytes ?? 0),
        maxSizeLabel: root.dataset.maxSizeLabel ?? '',
        formatsLabel: root.dataset.formatsLabel ?? '',
        acceptedExtensions: (root.dataset.acceptedExtensions ?? '').split(',').filter(Boolean),
    };
}

function parseTones(raw) {
    try {
        return JSON.parse(raw ?? '{}');
    } catch {
        return {};
    }
}

/**
 * Turns the endpoint answer into the shape the renderer expects, or an error
 * message when the payload is not something we can draw.
 */
function readPrediction(payload) {
    const prediction = payload?.prediction;

    if (payload?.success !== true || prediction === undefined || !Array.isArray(prediction.probabilities)) {
        return { error: payload?.error?.message ?? null };
    }

    return { prediction };
}

/**
 * Formats a percentage for the current locale.
 */
function formatPercent(locale, value) {
    if (window.analyzerLocales?.percentFormatter) {
        return window.analyzerLocales.percentFormatter.format(value / 100);
    }
    return `${value.toFixed(1)}%`;
}

export function initAnalyzer(root) {
    const form = root.querySelector('[data-form]');
    const container = root.querySelector('[data-uploader]');
    const submitButton = root.querySelector('[data-submit]');
    const submitLabel = root.querySelector('[data-submit-label]');
    const spinner = root.querySelector('[data-spinner]');
    const alert = root.querySelector('[data-alert]');
    const alertMessage = root.querySelector('[data-alert-message]');
    const status = root.querySelector('[data-status]');
    const result = root.querySelector('[data-result]');
    const resultPlaceholder = root.querySelector('[data-result-placeholder]');
    const againButton = root.querySelector('[data-analyze-again]');
    const locale = document.documentElement.lang ?? 'en';

    if (form === null || container === null || submitButton === null) {
        return;
    }

    const messages = readMessages(root);
    const tones = parseTones(root.dataset.tones);
    const recommendation = createRecommendation(root.querySelector('[data-recommendation]'));

    let busy = false;

    const showAlert = (message) => {
        if (alert === null || alertMessage === null) {
            return;
        }

        alertMessage.textContent = message;
        alert.hidden = message === '';
    };

    const setStatus = (message) => {
        if (status !== null) {
            status.textContent = message;
        }
    };

    const setBusy = (isBusy) => {
        submitButton.disabled = isBusy;
        form.setAttribute('aria-busy', isBusy ? 'true' : 'false');

        if (spinner !== null) {
            spinner.hidden = !isBusy;
        }

        if (submitLabel !== null) {
            submitLabel.textContent = isBusy ? messages.labelAnalyzing : messages.labelAnalyze;
        }
    };

    const uploader = createUploader({
        container,
        config: readConfig(root),
        messages,
        onChange: (file) => {
            submitButton.disabled = file === null || busy;

            if (file !== null) {
                showAlert('');
            }
        },
    });

    const renderPrediction = (prediction) => {
        const tone = tones[prediction.class] ?? 'neutral';

        const verdict = result.querySelector('[data-verdict]');
        verdict.dataset.tone = tone;

        const classLabel = result.querySelector('[data-class-label]');
        classLabel.textContent = prediction.class;

        // Context text based on class
        const contextEl = result.querySelector('[data-context]');
        const contextKey = prediction.class === 'NORMAL' ? 'context_normal' : 'context_pneumonia';
        // We'll fetch the localized text from the blade template via a data attribute
        contextEl.textContent = verdict.dataset[contextKey] ?? '';

        // Confidence with locale formatting
        const confidenceEl = result.querySelector('[data-confidence]');
        if (confidenceEl !== null) {
            const confidencePercent = Math.round(prediction.confidence * 1000) / 10;
            confidenceEl.textContent = formatPercent(locale, confidencePercent);
        }

        // Confidence meter
        const confidenceFill = result.querySelector('[data-confidence-fill]');
        const confidenceMeter = result.querySelector('[data-confidence-meter]');
        if (confidenceFill !== null && confidenceMeter !== null) {
            const confidenceValue = Math.round(prediction.confidence * 100);
            confidenceFill.style.width = `${confidenceValue}%`;
            confidenceFill.dataset.tone = tone;
            confidenceMeter.setAttribute('aria-valuenow', confidenceValue);
            confidenceMeter.setAttribute('aria-valuetext', formatPercent(locale, confidenceValue));
        }

        result.querySelector('[data-model]').textContent = prediction.model;

        if (resultPlaceholder !== null) {
            resultPlaceholder.hidden = true;
        }

        result.hidden = false;
        setStatus(messages.completed.replace('%CLASS%', prediction.class));
    };

    const resetResult = () => {
        result.hidden = true;

        if (resultPlaceholder !== null) {
            resultPlaceholder.hidden = false;
        }

        recommendation.reset();

        const verdict = result.querySelector('[data-verdict]');
        verdict.dataset.tone = 'neutral';

        result.querySelector('[data-class-label]').textContent = '—';
        result.querySelector('[data-context]').textContent = '';
        result.querySelector('[data-confidence]').textContent = '—';
        result.querySelector('[data-model]').textContent = '—';

        const confidenceFill = result.querySelector('[data-confidence-fill]');
        const confidenceMeter = result.querySelector('[data-confidence-meter]');
        if (confidenceFill !== null) {
            confidenceFill.style.width = '0%';
            confidenceFill.dataset.tone = 'neutral';
        }
        if (confidenceMeter !== null) {
            confidenceMeter.setAttribute('aria-valuenow', '0');
            confidenceMeter.removeAttribute('aria-valuetext');
        }
    };

    form.addEventListener('submit', async (event) => {
        event.preventDefault();

        if (busy) {
            return;
        }

        const file = uploader.file();

        if (file === null) {
            showAlert(messages.missingFile);

            return;
        }

        busy = true;

        const payload = new FormData();
        payload.append('image', file, file.name);

        try {
            setBusy(true);
            showAlert('');
            resetResult();
            setStatus(messages.inProcess);

            const response = await fetch(form.action, {
                method: 'POST',
                body: payload,
                headers: {
                    Accept: 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
                },
            });

            const body = await response.json().catch(() => null);

            if (!response.ok) {
                showAlert(body?.error?.message ?? messages.generic);

                return;
            }

            const parsed = readPrediction(body);

            if (parsed.error !== undefined) {
                showAlert(parsed.error ?? messages.generic);

                return;
            }

            renderPrediction(parsed.prediction);

            // The prediction is already on screen; the slower LLM call updates
            // the recommendation card independently.
            void recommendation.load(parsed.prediction);
        } catch {
            showAlert(messages.generic);
        } finally {
            busy = false;
            setBusy(false);
        }
    });

    againButton?.addEventListener('click', () => {
        uploader.reset();
        resetResult();
        showAlert('');
        setStatus('');
        submitButton.focus();
    });
}