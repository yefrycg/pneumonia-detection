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
 * Turns the server state ("PNEUMONIA:high") into a sentence-ready phrase.
 */
function getStateDescription(state, locale) {
    if (!state || typeof state !== 'string') return '—';

    const [className, band] = state.split(':');
    if (!className || !band) return state;

    const translations = {
        en: {
            normal: 'NORMAL',
            pneumonia: 'PNEUMONIA',
            low: 'low',
            medium: 'medium',
            high: 'high',
        },
        es: {
            normal: 'NORMAL',
            pneumonia: 'NEUMONÍA',
            low: 'baja',
            medium: 'media',
            high: 'alta',
        },
    };
    const lang = locale === 'es' ? 'es' : 'en';
    const classLabel = translations[lang][className.toLowerCase()] ?? className;
    const bandLabel = translations[lang][band] ?? band;

    return lang === 'es'
        ? `${classLabel} con confianza ${bandLabel}`
        : `${classLabel} with ${bandLabel} confidence`;
}

/**
 * Gets a friendly action label from the action ID.
 */
function getFriendlyActionLabel(actionId, locale) {
    const translations = {
        en: {
            seek_medical_evaluation: 'Seek medical evaluation',
            warning_signs: 'Watch for warning signs',
            supportive_care_while_waiting: 'Supportive care while waiting',
            follow_medical_treatment: 'Follow the medical treatment',
            prevent_spread: 'Prevent spread',
            routine_prevention: 'Everyday prevention',
            healthy_habits: 'Healthy habits',
            symptom_watch: 'Watch symptoms',
            respiratory_care: 'Respiratory care',
        },
        es: {
            seek_medical_evaluation: 'Buscar valoración médica',
            warning_signs: 'Vigilar signos de alarma',
            supportive_care_while_waiting: 'Cuidados de apoyo mientras esperas',
            follow_medical_treatment: 'Seguir el tratamiento indicado',
            prevent_spread: 'Evitar contagios',
            routine_prevention: 'Prevención cotidiana',
            healthy_habits: 'Hábitos saludables',
            symptom_watch: 'Vigilar los síntomas',
            respiratory_care: 'Cuidado respiratorio',
        },
    };
    const lang = locale === 'es' ? 'es' : 'en';

    return translations[lang][actionId] ?? actionId;
}

/**
 * Explains, in plain language, what each agent emphasis adds to the advice.
 */
function getActionDescription(actionId, locale) {
    const translations = {
        en: {
            seek_medical_evaluation: 'It prioritises an early medical assessment and avoids downplaying what the X-ray shows.',
            warning_signs: 'It focuses on respiratory warning signs and on when to seek urgent care.',
            supportive_care_while_waiting: 'It suggests rest, hydration and basic care while waiting for an assessment, without recommending medication.',
            follow_medical_treatment: 'It reminds the reader to follow a professional\'s instructions and not to self-prescribe.',
            prevent_spread: 'It adds hygiene habits and responsible isolation when symptoms are present.',
            routine_prevention: 'It reinforces everyday respiratory prevention and hygiene measures.',
            healthy_habits: 'It connects the result with rest, hydration and general health habits.',
            symptom_watch: 'It explains which symptoms are worth watching and when to consult a professional.',
            respiratory_care: 'It suggests general respiratory care and avoiding smoke or tobacco.',
            fallback: 'It is a cautious way to frame the advice for this result.',
        },
        es: {
            seek_medical_evaluation: 'Prioriza una valoración médica temprana y evita quitar importancia a lo que muestra la radiografía.',
            warning_signs: 'Se centra en las señales de alarma respiratoria y en cuándo conviene acudir a urgencias.',
            supportive_care_while_waiting: 'Aconseja descanso, hidratación y cuidados básicos mientras llega la valoración, sin sugerir medicamentos.',
            follow_medical_treatment: 'Recuerda seguir las indicaciones de un profesional y no autoprescribirse.',
            prevent_spread: 'Añade hábitos de higiene y aislamiento responsable cuando hay síntomas.',
            routine_prevention: 'Refuerza la prevención respiratoria diaria y las medidas de higiene.',
            healthy_habits: 'Relaciona el resultado con descanso, hidratación y hábitos generales de salud.',
            symptom_watch: 'Explica qué síntomas conviene vigilar y cuándo consultar a un profesional.',
            respiratory_care: 'Sugiere cuidado respiratorio general y evitar el humo o el tabaco.',
            fallback: 'Es una forma prudente de enfocar el consejo para este resultado.',
        },
    };
    const lang = locale === 'es' ? 'es' : 'en';

    return translations[lang][actionId] ?? translations[lang].fallback;
}

/**
 * Explains whether the agent exploited what it knows or explored an alternative.
 */
function getModeNote(isExploration, feedbackCount, locale) {
    const count = Number.isInteger(feedbackCount) && feedbackCount > 0 ? feedbackCount : 0;

    if (locale === 'es') {
        if (count === 0) {
            return isExploration
                ? 'Todavía no hay valoraciones registradas, así que probó un enfoque distinto para empezar a reunir señal.'
                : 'Todavía no hay valoraciones registradas; partió de un criterio conservador en lugar de dejar la elección al azar.';
        }

        const scale = count === 1 ? '1 valoración' : `${count} valoraciones`;

        return isExploration
            ? `Probó un enfoque distinto del mejor valorado para seguir aprendiendo. El valor Q se ha ajustado con ${scale}.`
            : `Repitió el enfoque que mejor ha funcionado hasta ahora. El valor Q se ha ajustado con ${scale}.`;
    }

    if (count === 0) {
        return isExploration
            ? 'There are no ratings yet, so it tried a different emphasis to start gathering signal.'
            : 'There are no ratings yet, so it started from a conservative criterion instead of leaving the choice to chance.';
    }

    const scale = count === 1 ? '1 rating' : `${count} ratings`;

    return isExploration
        ? `It tried an emphasis other than the best-rated one to keep learning. The Q value has been adjusted with ${scale}.`
        : `It repeated the emphasis that has worked best so far. The Q value has been adjusted with ${scale}.`;
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
    const rlSummary = section.querySelector('[data-rl-summary]');
    const rlAction = section.querySelector('[data-rl-action]');
    const rlHint = section.querySelector('[data-rl-hint]');
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
    let currentRl = null;
    let controller = null;
    let voting = false;

    const setFeedbackEnabled = (enabled) => {
        if (feedbackUp !== null) {
            feedbackUp.disabled = !enabled;
        }

        if (feedbackDown !== null) {
            feedbackDown.disabled = !enabled;
        }
    };

    const clearRl = () => {
        currentRl = null;
        setText(rlSummary, '');
        setText(rlAction, '—');
        setText(rlHint, '');

        rlRanking?.replaceChildren();
    };

    const renderRl = () => {
        if (currentRl === null) {
            clearRl();

            return;
        }

        const stateDescription = getStateDescription(currentRl.state, locale);
        const actionLabel = currentRl.actionLabel !== ''
            ? currentRl.actionLabel
            : getFriendlyActionLabel(currentRl.actionId, locale);
        const qLabel = formatQLocalized(currentRl.qValue);
        const modeNote = getModeNote(currentRl.isExploration, currentRl.feedbackCount, locale);

        setText(
            rlSummary,
            locale === 'es'
                ? `El estado era ${stateDescription}. El agente eligió «${actionLabel}», con un valor Q de ${qLabel}. ${modeNote}`
                : `The state was ${stateDescription}. The agent chose "${actionLabel}", with a Q value of ${qLabel}. ${modeNote}`,
        );

        setText(rlAction, actionLabel);
        setText(rlHint, getActionDescription(currentRl.actionId, locale));

        rlRanking?.replaceChildren();

        const ranked = [...currentRl.rankedActions].sort((left, right) => right.qValue - left.qValue);

        ranked.forEach((entry, index) => {
            const item = document.createElement('li');
            item.className = 'rounded-lg border border-border bg-surface p-3';

            if (entry.id === selectedActionId) {
                item.classList.add('ring-1', 'ring-accent/50');
            }

            const header = document.createElement('div');
            header.className = 'flex items-start justify-between gap-3';

            const copy = document.createElement('div');
            copy.className = 'flex min-w-0 flex-col gap-1';

            const title = document.createElement('p');
            title.className = 'flex flex-wrap items-center gap-2 text-sm font-medium text-ink';

            const rank = document.createElement('span');
            rank.className = 'text-xs font-medium tabular-nums text-ink-subtle';
            rank.textContent = `${index + 1}.`;

            const label = document.createElement('span');
            label.className = 'min-w-0';
            label.textContent = entry.label !== '' ? entry.label : getFriendlyActionLabel(entry.id, locale);

            title.append(rank, label);

            if (entry.id === selectedActionId) {
                const badge = document.createElement('span');
                badge.className = 'rounded-full bg-accent/10 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-accent';
                badge.textContent = locale === 'es' ? 'Elegido' : 'Chosen';
                title.append(badge);
            }

            const hint = document.createElement('p');
            hint.className = 'text-xs leading-relaxed text-ink-muted';
            hint.textContent = getActionDescription(entry.id, locale);

            copy.append(title, hint);

            const value = document.createElement('span');
            value.className = 'shrink-0 pt-0.5 text-sm font-medium tabular-nums text-ink';
            value.textContent = formatQLocalized(entry.qValue);

            header.append(copy, value);
            item.append(header);
            rlRanking?.append(item);
        });
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

        currentRl = {
            state: typeof recommendation.state === 'string' ? recommendation.state : '',
            actionId: typeof recommendation.action?.id === 'string' ? recommendation.action.id : '',
            actionLabel: typeof recommendation.action?.label === 'string' ? recommendation.action.label : '',
            qValue: Number(rl.q_value) || 0,
            isExploration: rl.is_exploration === true,
            feedbackCount: Number.isInteger(rl.feedback_count) ? rl.feedback_count : 0,
            rankedActions: (Array.isArray(rl.ranked_actions) ? rl.ranked_actions : []).map((entry) => ({
                id: typeof entry.id === 'string' ? entry.id : '',
                qValue: Number(entry.q_value) || 0,
                label: typeof entry.label === 'string' ? entry.label : '',
            })),
        };

        renderRl();

        // Ensure the agent panel is closed by default on a new recommendation.
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

            if (currentRl !== null) {
                if (Number.isFinite(Number(result.new_q))) {
                    currentRl.qValue = Number(result.new_q);
                    currentRl.rankedActions = currentRl.rankedActions.map((entry) => (
                        entry.id === selectedActionId ? { ...entry, qValue: Number(result.new_q) } : entry
                    ));
                }

                if (Number.isInteger(result.feedback_count)) {
                    currentRl.feedbackCount = result.feedback_count;
                }

                renderRl();
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