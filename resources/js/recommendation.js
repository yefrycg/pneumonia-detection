/**
 * Owns the recommendation card: requests the LLM text after a prediction,
 * renders the RL insight, and records one thumbs-up/down per recommendation.
 *
 * The server is the only source of truth. Text is inserted with textContent,
 * never innerHTML, because it is model-generated prose.
 */

function setText(element, value) {
    if (element !== null) {
        element.textContent = value;
    }
}

function formatQ(value) {
    const number = Number(value);

    return Number.isFinite(number) ? number.toFixed(3) : '—';
}

function formatQLocalized(value) {
    if (window.analyzerLocales?.numberFormatter) {
        return window.analyzerLocales.numberFormatter.format(value);
    }
    return formatQ(value);
}

function readRecommendation(payload) {
    const recommendation = payload?.recommendation;

    if (payload?.success !== true || recommendation === undefined || typeof recommendation.text !== 'string') {
        return null;
    }

    return recommendation;
}

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content ?? '';
}

/**
 * Gets a friendly state label from the server-provided state string.
 * The server sends state like "PNEUMONIA:high" - we convert to friendly label.
 */
function getFriendlyStateLabel(state, locale) {
    if (!state || typeof state !== 'string') return state ?? '—';

    const [className, band] = state.split(':');
    if (!className || !band) return state;

    const translations = {
        en: {
            normal_high: 'NORMAL · high confidence',
            normal_medium: 'NORMAL · medium confidence',
            normal_low: 'NORMAL · low confidence',
            pneumonia_high: 'PNEUMONIA · high confidence',
            pneumonia_medium: 'PNEUMONIA · medium confidence',
            pneumonia_low: 'PNEUMONIA · low confidence',
        },
        es: {
            normal_high: 'NORMAL · confianza alta',
            normal_medium: 'NORMAL · confianza media',
            normal_low: 'NORMAL · confianza baja',
            pneumonia_high: 'NEUMONÍA · confianza alta',
            pneumonia_medium: 'NEUMONÍA · confianza media',
            pneumonia_low: 'NEUMONÍA · confianza baja',
        },
    };
    const lang = locale === 'es' ? 'es' : 'en';
    const key = `${className.toLowerCase()}_${band}`;
    return translations[lang][key] ?? state;
}

/**
 * Gets a friendly action label from the action ID.
 */
function getFriendlyActionLabel(actionId, locale) {
    const translations = {
        en: {
            seek_medical_evaluation: 'Seek medical evaluation',
            warning_signs: 'Watch for warning signs',
            monitoring: 'Continue monitoring',
            preventive_advice: 'Preventive advice',
        },
        es: {
            seek_medical_evaluation: 'Buscar valoración médica',
            warning_signs: 'Vigilar signos de alarma',
            monitoring: 'Continuar monitorización',
            preventive_advice: 'Consejos preventivos',
        },
    };
    const lang = locale === 'es' ? 'es' : 'en';
    return translations[lang][actionId] ?? actionId;
}

/**
 * Gets a friendly mode label.
 */
function getFriendlyModeLabel(isExploration, locale) {
    return isExploration
        ? (locale === 'es' ? 'Exploración' : 'Exploration')
        : (locale === 'es' ? 'Explotación' : 'Exploitation');
}

export function createRecommendation(section) {
    if (section === null) {
        return { load: () => {}, reset: () => {} };
    }

    const loading = section.querySelector('[data-recommendation-loading]');
    const content = section.querySelector('[data-recommendation-content]');
    const error = section.querySelector('[data-recommendation-error]');
    const errorMessage = section.querySelector('[data-recommendation-error-message]');
    const sourceBadge = section.querySelector('[data-source-badge]');
    const text = section.querySelector('[data-recommendation-text]');
    const feedbackBlock = section.querySelector('[data-feedback-block]');
    const feedbackUp = section.querySelector('[data-feedback-up]');
    const feedbackDown = section.querySelector('[data-feedback-down]');
    const feedbackStatus = section.querySelector('[data-feedback-status]');
    const rlState = section.querySelector('[data-rl-state]');
    const rlAction = section.querySelector('[data-rl-action]');
    const rlQ = section.querySelector('[data-rl-q]');
    const rlMode = section.querySelector('[data-rl-mode]');
    const rlCount = section.querySelector('[data-rl-count]');
    const rlRanking = section.querySelector('[data-rl-ranking]');
    const rlDetails = section.querySelector('[data-rl-details]');

    const messages = {
        loading: section.dataset.messageLoading ?? '',
        error: section.dataset.messageError ?? '',
        feedbackError: section.dataset.messageFeedbackError ?? '',
        feedbackThanks: section.dataset.messageFeedbackThanks ?? '',
        feedbackAlready: section.dataset.messageFeedbackAlready ?? '',
        sourceLlm: section.dataset.sourceLlm ?? '',
        sourceFallback: section.dataset.sourceFallback ?? '',
        modeExploration: section.dataset.modeExploration ?? '',
        modeExploitation: section.dataset.modeExploitation ?? '',
    };

    const locale = section.dataset.locale ?? document.documentElement.lang ?? 'en';

    let recommendationId = null;
    let selectedActionId = null;
    let controller = null;
    let voting = false;

    const sortRanking = () => {
        if (rlRanking === null) {
            return;
        }

        Array.from(rlRanking.children)
            .sort((left, right) => Number(right.dataset.qValue ?? 0) - Number(left.dataset.qValue ?? 0))
            .forEach((item) => rlRanking.append(item));
    };

    const setFeedbackEnabled = (enabled) => {
        if (feedbackUp !== null) {
            feedbackUp.disabled = !enabled;
        }

        if (feedbackDown !== null) {
            feedbackDown.disabled = !enabled;
        }
    };

    const clearRl = () => {
        setText(rlState, '—');
        setText(rlAction, '—');
        setText(rlQ, '—');
        setText(rlMode, '—');
        setText(rlCount, '—');

        rlRanking?.replaceChildren();
    };

    const showError = (message) => {
        if (controller !== null) {
            controller.abort();
            controller = null;
        }

        recommendationId = null;
        selectedActionId = null;
        voting = false;

        section.hidden = false;
        loading.hidden = true;
        content.hidden = true;
        error.hidden = false;
        sourceBadge.hidden = true;

        setText(errorMessage, message === '' ? messages.error : message);
        setFeedbackEnabled(false);
    };

    const render = (recommendation) => {
        recommendationId = typeof recommendation.id === 'string' ? recommendation.id : null;
        selectedActionId = typeof recommendation.action?.id === 'string' ? recommendation.action.id : null;
        voting = false;

        section.hidden = false;
        loading.hidden = true;
        error.hidden = true;
        content.hidden = false;

        sourceBadge.hidden = false;
        sourceBadge.dataset.source = recommendation.source === 'llm' ? 'llm' : 'fallback';
        setText(
            sourceBadge,
            recommendation.source === 'llm' ? messages.sourceLlm : messages.sourceFallback,
        );

        setText(text, recommendation.text);

        feedbackBlock.hidden = recommendationId === null;
        setFeedbackEnabled(recommendationId !== null);
        setText(feedbackStatus, '');

        const rl = recommendation.rl ?? {};

        // Friendly state label
        setText(rlState, getFriendlyStateLabel(recommendation.state, locale));

        // Friendly action label
        setText(rlAction, getFriendlyActionLabel(recommendation.action?.id, locale));

        // Localized Q value
        setText(rlQ, formatQLocalized(rl.q_value));

        // Friendly mode label
        setText(rlMode, getFriendlyModeLabel(rl.is_exploration, locale));

        setText(rlCount, Number.isInteger(rl.feedback_count) ? String(rl.feedback_count) : '—');

        rlRanking?.replaceChildren();

        for (const entry of Array.isArray(rl.ranked_actions) ? rl.ranked_actions : []) {
            const item = document.createElement('li');
            item.className = 'flex items-center justify-between gap-3 rounded-lg border border-border bg-surface p-2.5';
            item.dataset.actionId = typeof entry.id === 'string' ? entry.id : '';
            item.dataset.qValue = String(Number(entry.q_value) || 0);

            const labelWrapper = document.createElement('div');
            labelWrapper.className = 'flex items-center gap-2 flex-1 min-w-0';

            const label = document.createElement('span');
            label.className = 'truncate font-medium text-ink';
            label.textContent = getFriendlyActionLabel(entry.id, locale);

            // Highlight selected action
            if (entry.id === selectedActionId) {
                const indicator = document.createElement('span');
                indicator.className = 'shrink-0 size-1.5 rounded-full bg-accent';
                indicator.setAttribute('aria-hidden', 'true');
                labelWrapper.prepend(indicator);
                item.classList.add('ring-1', 'ring-accent/50');
            }

            const value = document.createElement('span');
            value.className = 'shrink-0 font-medium tabular-nums text-ink';
            value.dataset.rankingQ = '';
            value.textContent = formatQLocalized(entry.q_value);

            // Mini progress bar for Q value visualization
            const barWrapper = document.createElement('div');
            barWrapper.className = 'shrink-0 w-24 h-1.5 bg-surface-sunken rounded-full overflow-hidden';
            const barFill = document.createElement('div');
            const qValue = Number(entry.q_value) || 0;
            // Normalize Q value for display (assuming range roughly -1 to 1)
            const barWidth = Math.min(100, Math.max(0, (qValue + 1) * 50));
            barFill.style.width = `${barWidth}%`;
            barFill.className = 'h-full rounded-full bg-accent/50 transition-[width]';
            barWrapper.append(barFill);
            barWrapper.title = `${entry.id}: ${formatQLocalized(qValue)}`;

            labelWrapper.append(label);
            item.append(labelWrapper, barWrapper, value);
            rlRanking?.append(item);
        }

        // Ensure agent panel is closed by default
        if (rlDetails !== null) {
            rlDetails.open = false;
        }
    };

    const reset = () => {
        if (controller !== null) {
            controller.abort();
            controller = null;
        }

        recommendationId = null;
        selectedActionId = null;
        voting = false;

        section.hidden = true;
        loading.hidden = true;
        content.hidden = true;
        error.hidden = true;
        sourceBadge.hidden = true;

        setText(text, '');
        setText(feedbackStatus, '');
        setFeedbackEnabled(false);
        clearRl();

        if (rlDetails !== null) {
            rlDetails.open = false;
        }
    };

    const load = async (prediction) => {
        reset();

        section.hidden = false;
        loading.hidden = false;
        content.hidden = true;
        error.hidden = true;
        sourceBadge.hidden = true;
        setText(feedbackStatus, '');

        controller = new AbortController();

        try {
            const response = await fetch(section.dataset.recommendEndpoint ?? '', {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({
                    class: prediction.class,
                    confidence: prediction.confidence,
                    locale: section.dataset.locale ?? document.documentElement.lang ?? 'en',
                }),
                signal: controller.signal,
            });

            const body = await response.json().catch(() => null);

            if (!response.ok) {
                showError(body?.error?.message ?? messages.error);

                return;
            }

            const recommendation = readRecommendation(body);

            if (recommendation === null) {
                showError(messages.error);

                return;
            }

            render(recommendation);
        } catch (fetchError) {
            if (fetchError?.name !== 'AbortError') {
                showError(messages.error);
            }
        } finally {
            controller = null;
        }
    };

    const sendFeedback = async (reward) => {
        if (recommendationId === null || voting) {
            return;
        }

        voting = true;
        setFeedbackEnabled(false);
        setText(feedbackStatus, '');

        // Update aria-pressed immediately for perceived performance
        if (feedbackUp !== null) feedbackUp.setAttribute('aria-pressed', reward === 1 ? 'true' : 'false');
        if (feedbackDown !== null) feedbackDown.setAttribute('aria-pressed', reward === -1 ? 'true' : 'false');

        try {
            const response = await fetch(section.dataset.feedbackEndpoint ?? '', {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest',
                    'X-CSRF-TOKEN': csrfToken(),
                },
                body: JSON.stringify({
                    recommendation_id: recommendationId,
                    reward,
                }),
            });

            const body = await response.json().catch(() => null);

            if (!response.ok) {
                const code = body?.error?.code ?? '';
                const alreadyRecorded = code === 'FEEDBACK_ALREADY_RECORDED' || code === 'RECOMMENDATION_NOT_FOUND';

                setText(
                    feedbackStatus,
                    alreadyRecorded
                        ? (code === 'FEEDBACK_ALREADY_RECORDED' ? messages.feedbackAlready : body?.error?.message ?? messages.feedbackError)
                        : body?.error?.message ?? messages.feedbackError,
                );

                voting = false;
                setFeedbackEnabled(!alreadyRecorded);

                // Reset aria-pressed on error
                if (feedbackUp !== null) feedbackUp.setAttribute('aria-pressed', 'false');
                if (feedbackDown !== null) feedbackDown.setAttribute('aria-pressed', 'false');

                return;
            }

            const result = body?.feedback ?? {};

            setText(rlQ, formatQLocalized(result.new_q));
            setText(rlCount, Number.isInteger(result.feedback_count) ? String(result.feedback_count) : rlCount?.textContent ?? '—');

            // Keep the ranking and the headline Q value consistent after a vote.
            const selected = Array.from(rlRanking?.children ?? []).find(
                (item) => item.dataset.actionId === selectedActionId,
            );

            if (selected !== undefined && Number.isFinite(Number(result.new_q))) {
                selected.dataset.qValue = String(Number(result.new_q));
                setText(selected.querySelector('[data-ranking-q]'), formatQLocalized(result.new_q));
                sortRanking();
            }

            setText(feedbackStatus, messages.feedbackThanks);
            setFeedbackEnabled(false);
        } catch {
            voting = false;
            setFeedbackEnabled(true);
            setText(feedbackStatus, messages.feedbackError);

            // Reset aria-pressed on error
            if (feedbackUp !== null) feedbackUp.setAttribute('aria-pressed', 'false');
            if (feedbackDown !== null) feedbackDown.setAttribute('aria-pressed', 'false');
        }
    };

    feedbackUp?.addEventListener('click', () => {
        void sendFeedback(1);
    });

    feedbackDown?.addEventListener('click', () => {
        void sendFeedback(-1);
    });

    reset();

    return { load, reset };
}