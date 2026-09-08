<script setup>
import { onMounted, ref } from 'vue';
import AppIcon from '../components/AppIcon.vue';
import AppModal from '../components/AppModal.vue';
import EmptyState from '../components/EmptyState.vue';
import FormField from '../components/FormField.vue';
import LoadingState from '../components/LoadingState.vue';
import PaginationControls from '../components/PaginationControls.vue';
import { useResource } from '../composables/useResource';

const { items, loading, meta, saving, errors, failure, load, goToPage, save, destroy } =
    useResource('/school-classes');

const showForm = ref(false);
const editingId = ref(null);
const form = ref(blankForm());

function blankForm() {
    return { name: '', level: '', capacity: '' };
}

function openCreate() {
    editingId.value = null;
    form.value = blankForm();
    errors.value = {};
    showForm.value = true;
}

function openEdit(schoolClass) {
    editingId.value = schoolClass.id;
    form.value = {
        name: schoolClass.name,
        level: schoolClass.level ?? '',
        capacity: schoolClass.capacity ?? '',
    };
    errors.value = {};
    showForm.value = true;
}

async function submit() {
    if (await save(form.value, editingId.value)) {
        showForm.value = false;
    }
}

function remove(schoolClass) {
    if (window.confirm(`Supprimer la classe ${schoolClass.name} ?`)) {
        destroy(schoolClass.id);
    }
}

onMounted(load);
</script>

<template>
    <div class="page-header">
        <h1>Classes</h1>
        <button class="btn btn-primary" @click="openCreate"><AppIcon name="plus" :size="16" />Ajouter une classe</button>
    </div>

    <p v-if="failure" class="alert">{{ failure }}</p>

    <LoadingState v-if="loading && !items.length" :rows="5" />

    <div v-else-if="!items.length" class="card">
        <EmptyState title="Aucune classe." hint="Ajoutez une classe pour commencer." icon="classes" />
    </div>

    <div v-else class="table-scroll">
        <table>
            <thead>
                <tr>
                    <th>Nom</th>
                    <th>Niveau</th>
                    <th class="numeric">Capacité</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="schoolClass in items" :key="schoolClass.id">
                    <td>
                        <RouterLink :to="`/classes/${schoolClass.id}`" class="row-link">{{ schoolClass.name }}</RouterLink>
                    </td>
                    <td>{{ schoolClass.level || '–' }}</td>
                    <td class="numeric">{{ schoolClass.capacity ?? '–' }}</td>
                    <td class="actions">
                        <RouterLink :to="`/classes/${schoolClass.id}`" class="btn-link">Voir</RouterLink>
                        <button class="btn-link" @click="openEdit(schoolClass)">Modifier</button>
                        <button class="btn-link danger" @click="remove(schoolClass)">Supprimer</button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <PaginationControls :meta="meta" :loading="loading" label="classes" @change="goToPage" />

    <AppModal
        v-if="showForm"
        :title="editingId ? 'Modifier la classe' : 'Nouvelle classe'"
        :saving="saving"
        @close="showForm = false"
        @submit="submit"
    >
        <FormField label="Nom" :error="errors.name">
            <input v-model="form.name" type="text" required>
        </FormField>
        <FormField label="Niveau" :error="errors.level">
            <input v-model="form.level" type="text">
        </FormField>
        <FormField label="Capacité" :error="errors.capacity">
            <input v-model="form.capacity" type="number" min="1">
        </FormField>
    </AppModal>
</template>
