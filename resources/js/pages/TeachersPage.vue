<script setup>
import { onMounted, ref } from 'vue';
import AppModal from '../components/AppModal.vue';
import FormField from '../components/FormField.vue';
import { useResource } from '../composables/useResource';

const { items, loading, saving, errors, failure, load, save, destroy } = useResource('/teachers');

const showForm = ref(false);
const editingId = ref(null);
const form = ref(blankForm());

function blankForm() {
    return { name: '', email: '', phone: '', subject: '' };
}

function openCreate() {
    editingId.value = null;
    form.value = blankForm();
    errors.value = {};
    showForm.value = true;
}

function openEdit(teacher) {
    editingId.value = teacher.id;
    form.value = {
        name: teacher.name,
        email: teacher.email,
        phone: teacher.phone ?? '',
        subject: teacher.subject ?? '',
    };
    errors.value = {};
    showForm.value = true;
}

async function submit() {
    if (await save(form.value, editingId.value)) {
        showForm.value = false;
    }
}

function remove(teacher) {
    if (window.confirm(`Supprimer ${teacher.name} ?`)) {
        destroy(teacher.id);
    }
}

onMounted(load);
</script>

<template>
    <div class="page-header">
        <h1>Enseignants</h1>
        <button class="btn btn-primary" @click="openCreate">Ajouter un enseignant</button>
    </div>

    <p v-if="failure" class="alert">{{ failure }}</p>

    <div v-if="loading" class="state">Chargement…</div>

    <table v-else>
        <thead>
            <tr>
                <th>Nom</th>
                <th>Email</th>
                <th>Téléphone</th>
                <th>Matière</th>
                <th></th>
            </tr>
        </thead>
        <tbody>
            <tr v-if="!items.length">
                <td colspan="5" class="muted">Aucun enseignant.</td>
            </tr>
            <tr v-for="teacher in items" :key="teacher.id">
                <td>{{ teacher.name }}</td>
                <td>{{ teacher.email }}</td>
                <td>{{ teacher.phone || '—' }}</td>
                <td>{{ teacher.subject || '—' }}</td>
                <td class="actions">
                    <button class="btn-link" @click="openEdit(teacher)">Modifier</button>
                    <button class="btn-link danger" @click="remove(teacher)">Supprimer</button>
                </td>
            </tr>
        </tbody>
    </table>

    <AppModal
        v-if="showForm"
        :title="editingId ? 'Modifier l\'enseignant' : 'Nouvel enseignant'"
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
        <FormField label="Téléphone" :error="errors.phone">
            <input v-model="form.phone" type="text">
        </FormField>
        <FormField label="Matière" :error="errors.subject">
            <input v-model="form.subject" type="text">
        </FormField>
    </AppModal>
</template>
