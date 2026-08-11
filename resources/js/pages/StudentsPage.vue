<script setup>
import { onMounted, ref } from 'vue';
import AppModal from '../components/AppModal.vue';
import FormField from '../components/FormField.vue';
import { useResource } from '../composables/useResource';
import api from '../lib/api';

const { items, loading, saving, errors, failure, load, save, destroy } = useResource('/students');

const schoolClasses = ref([]);
const showForm = ref(false);
const editingId = ref(null);
const form = ref(blankForm());

function blankForm() {
    return { name: '', email: '', birth_date: '', school_class_id: '' };
}

function openCreate() {
    editingId.value = null;
    form.value = blankForm();
    errors.value = {};
    showForm.value = true;
}

function openEdit(student) {
    editingId.value = student.id;
    form.value = {
        name: student.name,
        email: student.email,
        birth_date: student.birth_date ? student.birth_date.slice(0, 10) : '',
        school_class_id: student.school_class_id ?? '',
    };
    errors.value = {};
    showForm.value = true;
}

async function submit() {
    if (await save(form.value, editingId.value)) {
        showForm.value = false;
    }
}

function remove(student) {
    if (window.confirm(`Supprimer ${student.name} ?`)) {
        destroy(student.id);
    }
}

function formatDate(value) {
    return value ? value.slice(0, 10) : '—';
}

onMounted(async () => {
    await load();
    const { data } = await api.get('/school-classes');
    schoolClasses.value = data;
});
</script>

<template>
    <div class="page-header">
        <h1>Élèves</h1>
        <button class="btn btn-primary" @click="openCreate">Ajouter un élève</button>
    </div>

    <p v-if="failure" class="alert">{{ failure }}</p>

    <div v-if="loading" class="state">Chargement…</div>

    <table v-else>
        <thead>
            <tr>
                <th>Nom</th>
                <th>Email</th>
                <th>Date de naissance</th>
                <th>Classe</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <tr v-if="!items.length">
                <td colspan="5" class="muted">Aucun élève.</td>
            </tr>
            <tr v-for="student in items" :key="student.id">
                <td>{{ student.name }}</td>
                <td>{{ student.email }}</td>
                <td>{{ formatDate(student.birth_date) }}</td>
                <td>{{ student.school_class ? student.school_class.name : '—' }}</td>
                <td class="actions">
                    <button class="btn-link" @click="openEdit(student)">Modifier</button>
                    <button class="btn-link danger" @click="remove(student)">Supprimer</button>
                </td>
            </tr>
        </tbody>
    </table>

    <AppModal
        v-if="showForm"
        :title="editingId ? 'Modifier l\'élève' : 'Nouvel élève'"
        :saving="saving"
        @close="showForm = false"
        @submit="submit"
    >
        <FormField label="Nom" :error="errors.name">
            <input v-model="form.name" type="text" required>
        </FormField>
        <FormField label="Email" :error="errors.email">
            <input v-model="form.email" type="email" required>
        </FormField>
        <FormField label="Date de naissance" :error="errors.birth_date">
            <input v-model="form.birth_date" type="date">
        </FormField>
        <FormField label="Classe" :error="errors.school_class_id">
            <select v-model="form.school_class_id">
                <option value="">Aucune</option>
                <option v-for="schoolClass in schoolClasses" :key="schoolClass.id" :value="schoolClass.id">
                    {{ schoolClass.name }}
                </option>
            </select>
        </FormField>
    </AppModal>
</template>
