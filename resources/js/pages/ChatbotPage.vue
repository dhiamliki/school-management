<script setup>
import { nextTick, ref, watch } from 'vue';
import { useAIChat } from '../composables/useAIChat';

const { messages, loading, sendMessage, reset } = useAIChat();

const draft = ref('');
const thread = ref(null);

const suggestions = [
    'Combien y a-t-il d’élèves par classe ?',
    'Qui enseigne les mathématiques ?',
    'Quels cours ont lieu le mardi matin ?',
];

async function scrollToLatest() {
    await nextTick();

    if (thread.value) {
        thread.value.scrollTop = thread.value.scrollHeight;
    }
}

// A new bubble or the typing indicator appearing both grow the thread.
watch([messages, loading], scrollToLatest, { deep: true });

async function submit() {
    const text = draft.value;

    if (!text.trim() || loading.value) {
        return;
    }

    draft.value = '';
    await sendMessage(text);
}

function ask(question) {
    draft.value = question;
    submit();
}

/**
 * Enter sends; Shift+Enter starts a new line.
 */
function onKeydown(event) {
    if (event.key === 'Enter' && !event.shiftKey) {
        event.preventDefault();
        submit();
    }
}
</script>

<template>
    <div class="page-header">
        <h1>Assistant IA</h1>
        <button v-if="messages.length" type="button" class="btn" @click="reset">
            Nouvelle conversation
        </button>
    </div>

    <div class="card chat">
        <div ref="thread" class="chat-thread">
            <div v-if="!messages.length" class="chat-empty">
                <p class="muted">
                    Posez une question sur les classes, les élèves, les enseignants,
                    les cours ou l’emploi du temps. L’assistant ne répond qu’à partir
                    des données de l’école.
                </p>
                <ul class="chat-suggestions">
                    <li v-for="suggestion in suggestions" :key="suggestion">
                        <button type="button" class="btn" @click="ask(suggestion)">
                            {{ suggestion }}
                        </button>
                    </li>
                </ul>
            </div>

            <div
                v-for="(message, index) in messages"
                :key="index"
                class="chat-row"
                :class="`chat-row-${message.role}`"
            >
                <p class="chat-bubble" :class="{ 'chat-bubble-error': message.failed }">
                    {{ message.content }}
                </p>
            </div>

            <div v-if="loading" class="chat-row chat-row-assistant">
                <p class="chat-bubble chat-bubble-typing">
                    <span class="chat-dot" />
                    <span class="chat-dot" />
                    <span class="chat-dot" />
                </p>
            </div>
        </div>

        <form class="chat-form" @submit.prevent="submit">
            <textarea
                v-model="draft"
                class="chat-input"
                rows="2"
                maxlength="2000"
                placeholder="Votre question…"
                :disabled="loading"
                @keydown="onKeydown"
            />
            <button type="submit" class="btn btn-primary" :disabled="loading || !draft.trim()">
                {{ loading ? 'Envoi…' : 'Envoyer' }}
            </button>
        </form>
    </div>
</template>

<style scoped>
.chat {
    display: flex;
    flex-direction: column;
    height: calc(100vh - 132px);
    overflow: hidden;
}

.chat-thread {
    flex: 1;
    padding: var(--space-5);
    overflow-y: auto;
}

.chat-empty {
    max-width: 460px;
    margin: 0 auto;
    padding: var(--space-6) 0;
    text-align: center;
}

.chat-suggestions {
    display: flex;
    flex-direction: column;
    gap: var(--space-2);
    margin: var(--space-5) 0 0;
    padding: 0;
    list-style: none;
}

.chat-row {
    display: flex;
    margin-bottom: var(--space-3);
}

.chat-row-user {
    justify-content: flex-end;
}

.chat-row-assistant {
    justify-content: flex-start;
}

.chat-bubble {
    max-width: 68%;
    margin: 0;
    padding: var(--space-3) var(--space-4);
    font-size: var(--text-base);
    line-height: 1.5;
    white-space: pre-wrap;
    background: var(--canvas);
    border: 1px solid var(--border);
    border-radius: 12px;
}

.chat-row-user .chat-bubble {
    color: var(--surface);
    background: var(--accent);
    border-color: var(--accent);
    border-bottom-right-radius: 4px;
}

.chat-row-assistant .chat-bubble {
    border-bottom-left-radius: 4px;
}

.chat-bubble-error {
    color: var(--danger-ink);
    background: var(--danger-soft);
    border-color: var(--danger-line);
}

.chat-bubble-typing {
    display: flex;
    gap: var(--space-1);
    padding: var(--space-4);
}

.chat-dot {
    width: 6px;
    height: 6px;
    background: var(--muted);
    border-radius: 50%;
    animation: chat-blink 1.2s infinite ease-in-out;
}

.chat-dot:nth-child(2) {
    animation-delay: 0.2s;
}

.chat-dot:nth-child(3) {
    animation-delay: 0.4s;
}

@keyframes chat-blink {
    0%,
    80%,
    100% {
        opacity: 0.25;
    }

    40% {
        opacity: 1;
    }
}

@media (prefers-reduced-motion: reduce) {
    .chat-dot {
        animation: none;
        opacity: 0.6;
    }
}

.chat-form {
    display: flex;
    gap: var(--space-3);
    align-items: flex-end;
    padding: var(--space-4) var(--space-5);
    border-top: 1px solid var(--border);
}

.chat-input {
    flex: 1;
    padding: var(--space-2) var(--space-3);
    font: inherit;
    font-size: var(--text-base);
    color: var(--text);
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 5px;
    resize: none;
}

.chat-input:focus {
    outline: none;
    border-color: var(--accent);
}
</style>
