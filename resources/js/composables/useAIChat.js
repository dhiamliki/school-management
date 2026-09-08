import { ref } from 'vue';
import api from '../lib/api';

/**
 * How many past messages are replayed to the model as context. The backend
 * trims to its own limit as well; this only keeps the request small.
 */
const HISTORY_LENGTH = 20;

/**
 * Chat thread against POST /api/ai/chat.
 *
 * Each message is { role: 'user' | 'assistant', content, failed }. A failed
 * flag marks an error that is shown inside the thread instead of being
 * swallowed, and those messages are never replayed as context.
 */
export function useAIChat() {
    const messages = ref([]);
    const loading = ref(false);

    function push(role, content, failed = false) {
        messages.value.push({ role, content, failed });
    }

    /**
     * The turns worth sending back: real exchanges only, most recent last.
     */
    function history() {
        return messages.value
            .filter((message) => !message.failed)
            .slice(-HISTORY_LENGTH)
            .map(({ role, content }) => ({ role, content }));
    }

    async function sendMessage(text) {
        const message = text.trim();

        if (!message || loading.value) {
            return false;
        }

        // Snapshot the context before the new question joins the thread.
        const context = history();

        push('user', message);
        loading.value = true;

        try {
            const { data } = await api.post('/ai/chat', { message, history: context });
            push('assistant', data.reply);

            return true;
        } catch (error) {
            push('assistant', errorMessage(error), true);

            return false;
        } finally {
            loading.value = false;
        }
    }

    function reset() {
        messages.value = [];
    }

    return { messages, loading, sendMessage, reset };
}

/**
 * The controller answers failures with { message }; 422 and 429 are shaped by
 * Laravel itself. Anything else falls back to a generic line.
 */
function errorMessage(error) {
    const response = error.response;

    if (!response) {
        return 'Connexion impossible. Vérifiez votre réseau et réessayez.';
    }

    if (response.status === 429) {
        return 'Trop de questions en peu de temps. Patientez une minute avant de réessayer.';
    }

    if (response.status === 422) {
        const errors = response.data?.errors ?? {};

        return Object.values(errors).flat()[0] ?? 'Message invalide.';
    }

    return response.data?.message ?? "L'assistant est indisponible pour le moment.";
}
