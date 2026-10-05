/**
 * Drives the analysis form: submits the selected radiograph to the Laravel
 * endpoint and renders the returned prediction.
 *
 * The server response is the only source of truth. Everything rendered here is
 * filled from the validated payload, and any error the endpoint reports is
 * surfaced verbatim rather than being replaced by a generic message.
 */

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
    const againButton = root.querySelector('[data-analyze-again]');

    if (form === null || container === null || submitButton === null) {
        return;
    }

    const messages = readMessages(root);
    const tones = parseTones(root.dataset.tones);

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
            spinner.hidden = ! isBusy;
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

        result.querySelector('[data-verdict]').dataset.tone = tone;

        const classLabel = result.querySelector('[data-class-label]');
        classLabel.textContent = prediction.class;

        const statement = result.querySelector('[data-statement]');
        statement.textContent = (statement.dataset.template ?? '').replace('%CLASS%', prediction.class);

        const confidenceEl = result.querySelector('[data-confidence]');
        if (confidenceEl !== null) {
            const confidencePercent = Math.round(prediction.confidence * 1000) / 10;
            confidenceEl.textContent = `${confidencePercent}%`;
        }
        result.querySelector('[data-model]').textContent = prediction.model;

        for (const probability of prediction.probabilities) {
            const row = result.querySelector(`[data-probability="${probability.class}"]`);

            if (row === null) {
                continue;
            }

            row.querySelector('[data-percentage]').textContent = probability.percentage;

            const fill = row.querySelector('[data-fill]');
            fill.style.width = `${probability.percentage}%`;
            fill.dataset.tone = tones[probability.class] ?? 'neutral';

            const bar = row.querySelector('[data-bar]');
            bar.setAttribute('aria-valuenow', probability.percentage);
            bar.setAttribute('aria-valuetext', `${probability.class}: ${probability.percentage}%`);
        }

        result.hidden = false;
        setStatus(messages.completed.replace('%CLASS%', prediction.class));
    };

    const resetResult = () => {
        result.hidden = true;

        const verdict = result.querySelector('[data-verdict]');
        verdict.dataset.tone = 'neutral';

        result.querySelector('[data-class-label]').textContent = '—';
        result.querySelector('[data-statement]').textContent = '';
        result.querySelector('[data-confidence]').textContent = '—';
        result.querySelector('[data-model]').textContent = '—';

        for (const row of result.querySelectorAll('[data-probability]')) {
            row.querySelector('[data-percentage]').textContent = '—';

            const fill = row.querySelector('[data-fill]');
            fill.style.width = '0%';
            fill.dataset.tone = 'neutral';

            const bar = row.querySelector('[data-bar]');
            bar.setAttribute('aria-valuenow', '0');
            bar.removeAttribute('aria-valuetext');
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

            if (! response.ok) {
                showAlert(body?.error?.message ?? messages.generic);

                return;
            }

            const parsed = readPrediction(body);

            if (parsed.error !== undefined) {
                showAlert(parsed.error ?? messages.generic);

                return;
            }

            renderPrediction(parsed.prediction);
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