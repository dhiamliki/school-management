<script setup>
import { onMounted, ref } from 'vue';
import AppModal from '../components/AppModal.vue';
import FormField from '../components/FormField.vue';
import { useResource } from '../composables/useResource';

const { items, loading, saving, errors, failure, load, save, destroy } = useResource('/school-classes');

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
        <button class="btn btn-primary" @click="openCreate">Ajouter une classe</button>
    </div>

    <p v-if="failure" class="alert">{{ failure }}</p>

    <div v-if="loading" class="state">Chargement…</div>

    <table v-else>
        <thead>
            <tr>
                <th>Nom</th>
                <th>Niveau</th>
                <th>Capacité</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <tr v-if="!items.length">
                <td colspan="4" class="muted">Aucune classe.</td>
            </tr>
            <tr v-for="schoolClass in items" :key="schoolClass.id">
                <td>{{ schoolClass.name }}</td>
                <td>{{ schoolClass.level || '—' }}</td>
                <td>{{ schoolClass.capacity ?? '—' }}</td>
                <td class="actions">
                    <button class="btn-link" @click="openEdit(schoolClass)">Modifier</button>
                    <button class="btn-link danger" @click="remove(schoolClass)">Supprimer</button>
                </td>
            </tr>
        </tbody>
    </table>

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
