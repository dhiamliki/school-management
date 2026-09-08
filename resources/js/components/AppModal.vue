<script setup>
import { onBeforeUnmount, onMounted, ref, useId } from 'vue';

defineProps({
    title: { type: String, required: true },
    saving: { type: Boolean, default: false },
});

const emit = defineEmits(['close', 'submit']);

// Names the dialog by its own heading.
const titleId = useId();

const dialog = ref(null);

// Read before the dialog mounts and takes focus for itself.
const opener = typeof document !== 'undefined' ? document.activeElement : null;

// Recomputed on every Tab: slotted fields change as errors appear and as
// saving disables the submit.
function focusables() {
    if (!dialog.value) {
        return [];
    }

    const selector = [
        'a[href]',
        'button:not([disabled])',
        'input:not([disabled])',
        'select:not([disabled])',
        'textarea:not([disabled])',
        '[tabindex]:not([tabindex="-1"])',
    ].join(', ');

    return Array.from(dialog.value.querySelectorAll(selector)).filter(
        (element) => element.offsetParent !== null || element === document.activeElement,
    );
}

// Keep Tab inside the dialog.
function trapTab(event) {
    const reachable = focusables();

    if (!reachable.length) {
        event.preventDefault();
        dialog.value?.focus();

        return;
    }

    const first = reachable[0];
    const last = reachable[reachable.length - 1];
    const active = document.activeElement;

    if (event.shiftKey && (active === first || !dialog.value.contains(active))) {
        event.preventDefault();
        last.focus();
    } else if (!event.shiftKey && (active === last || !dialog.value.contains(active))) {
        event.preventDefault();
        first.focus();
    }
}

function onKeydown(event) {
    if (event.key === 'Escape') {
        event.preventDefault();
        emit('close');

        return;
    }

    if (event.key === 'Tab') {
        trapTab(event);
    }
}

// The dialog is teleported to the body so this can go inert without it.
function appRoot() {
    return document.getElementById('app');
}

onMounted(() => {
    // inert is what a screen reader honours; the Tab trap keeps focus cycling.
    appRoot()?.setAttribute('inert', '');

    const [first] = focusables();
    (first ?? dialog.value)?.focus();

    document.addEventListener('keydown', onKeydown);
});

onBeforeUnmount(() => {
    document.removeEventListener('keydown', onKeydown);
    appRoot()?.removeAttribute('inert');

    // After the page stops being inert, which would otherwise refuse the focus.
    if (opener instanceof HTMLElement && document.contains(opener)) {
        opener.focus();
    }
});
</script>

<template>
    <!-- Teleported so the dialog sits outside the #app element that goes inert. -->
    <Teleport to="body">
    <Transition name="modal-fade" appear>
        <div class="modal-backdrop" @click.self="$emit('close')">
            <div
                ref="dialog"
                class="modal"
                role="dialog"
                aria-modal="true"
                :aria-labelledby="titleId"
                tabindex="-1"
            >
                <h2 :id="titleId">{{ title }}</h2>
                <form @submit.prevent="$emit('submit')">
                    <slot />
                    <div class="modal-actions">
                        <button type="button" class="btn" @click="$emit('close')">Annuler</button>
                        <button type="submit" class="btn btn-primary" :disabled="saving">
                            <span v-if="saving" class="spinner spinner-on-accent"></span>
                            {{ saving ? 'Enregistrement…' : 'Enregistrer' }}
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </Transition>
    </Teleport>
</template>

<style scoped>
.spinner-on-accent {
    width: 13px;
    height: 13px;
    border-color: rgba(255, 255, 255, 0.4);
    border-top-color: #ffffff;
}

/* Programmatic focus, so it needs no ring. */
.modal:focus {
    outline: none;
}
</style>
