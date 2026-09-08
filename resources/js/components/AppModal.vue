<script setup>
import { onBeforeUnmount, onMounted, ref, useId } from 'vue';

defineProps({
    title: { type: String, required: true },
    saving: { type: Boolean, default: false },
});

const emit = defineEmits(['close', 'submit']);

/**
 * Ties the dialog to its own heading, so assistive technology announces the
 * modal by name instead of as an unlabelled dialog.
 */
const titleId = useId();

const dialog = ref(null);

/**
 * What had focus when the modal opened, so it can be handed back on close.
 * Read before the dialog mounts and takes focus for itself.
 */
const opener = typeof document !== 'undefined' ? document.activeElement : null;

/**
 * Everything inside the dialog a keyboard can reach, in document order.
 *
 * Recomputed on every Tab rather than cached: the form fields are slotted in
 * by the caller, and a validation error appearing or a field being disabled
 * while saving changes what is focusable partway through the modal's life.
 */
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

/**
 * Keep Tab inside the dialog. Without this the focus ring walks out into the
 * page behind the backdrop, which is still there and still clickable.
 */
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

    // Wrap at whichever end the user is walking off, and pull focus back in if
    // it somehow escaped already.
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

/**
 * The application root, which is everything the modal covers. The dialog is
 * teleported out to the body so this can be marked inert without the modal,
 * which otherwise lives inside it, going inert along with the page.
 */
function appRoot() {
    return document.getElementById('app');
}

onMounted(() => {
    // Hides the page behind the backdrop from the keyboard, the pointer and
    // the accessibility tree in one attribute. The Tab handling above still
    // earns its place: inert is what a screen reader reads, the trap is what
    // keeps focus cycling sensibly inside the dialog.
    appRoot()?.setAttribute('inert', '');

    // The first field, so typing can start straight away. The dialog itself is
    // the fallback for a modal that somehow has nothing focusable in it.
    const [first] = focusables();
    (first ?? dialog.value)?.focus();

    document.addEventListener('keydown', onKeydown);
});

onBeforeUnmount(() => {
    document.removeEventListener('keydown', onKeydown);
    appRoot()?.removeAttribute('inert');

    // Send focus back where it came from, so closing a modal with the keyboard
    // leaves the caret on the button that opened it rather than at the top of
    // the document. Restored after the page stops being inert, which would
    // otherwise refuse the focus.
    if (opener instanceof HTMLElement && document.contains(opener)) {
        opener.focus();
    }
});
</script>

<template>
    <!--
        Teleported to the body so the backdrop covers the whole page and, more
        to the point, so the dialog sits outside the #app element that goes
        inert while it is open. Every modal style is global, so the card looks
        the same from here as it did nested in the page.
    -->
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
/* The spinner sits on the accent fill, so it needs its own contrast. */
.spinner-on-accent {
    width: 13px;
    height: 13px;
    border-color: rgba(255, 255, 255, 0.4);
    border-top-color: #ffffff;
}

/* The dialog takes focus when it opens and when it holds nothing focusable.
   That is a programmatic move, not a keyboard one, so it needs no ring. */
.modal:focus {
    outline: none;
}
</style>
