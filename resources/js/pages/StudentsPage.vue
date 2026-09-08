<script setup>
import { onMounted, ref } from 'vue';
import AppIcon from '../components/AppIcon.vue';
import AppModal from '../components/AppModal.vue';
import EmptyState from '../components/EmptyState.vue';
import FormField from '../components/FormField.vue';
import LoadingState from '../components/LoadingState.vue';
import PaginationControls from '../components/PaginationControls.vue';
import { unwrapList, useResource } from '../composables/useResource';
import api from '../lib/api';

const { items, loading, meta, saving, errors, failure, load, goToPage, save, destroy } =
    useResource('/students');

const schoolClasses = ref([]);
const showForm = ref(false);
const editingId = ref(null);
const form = ref(blankForm());

function blankForm() {
    return { name: '', matricule: '', birth_date: '', school_class_id: '' };
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
        matricule: student.matricule,
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
    return value ? value.slice(0, 10) : '–';
}

/**
 * Attendance at a glance. Under 90% present is worth a second look, under 80%
 * is the band the at-risk report picks up, so the badge is coloured on those
 * two thresholds rather than on the absence count alone.
 */
function attendanceTone(student) {
    const rate = student.attendance ? student.attendance.rate : null;

    if (rate === null || rate === undefined) {
        return 'badge-muted';
    }

    if (rate < 80) {
        return 'badge-bad';
    }

    return rate < 90 ? 'badge-warn' : 'badge-good';
}

function attendanceLabel(student) {
    const summary = student.attendance;

    if (!summary || summary.rate === null || summary.rate === undefined) {
        return '–';
    }

    return `${summary.rate}%`;
}

function attendanceTitle(student) {
    const summary = student.attendance;

    if (!summary || !summary.records) {
        return 'Aucune présence enregistrée';
    }

    return `${summary.absences} absence(s), ${summary.lates} retard(s) sur ${summary.records} relevés`;
}

onMounted(async () => {
    await load();
    const { data } = await api.get('/school-classes', { params: { per_page: 200 } });
    schoolClasses.value = unwrapList(data);
});
</script>

<template>
    <div class="page-header">
        <h1>Élèves</h1>
        <button class="btn btn-primary" @click="openCreate"><AppIcon name="plus" :size="16" />Ajouter un élève</button>
    </div>

    <p v-if="failure" class="alert">{{ failure }}</p>

    <LoadingState v-if="loading && !items.length" :rows="5" />

    <div v-else-if="!items.length" class="card">
        <EmptyState title="Aucun élève." hint="Ajoutez un élève pour commencer." icon="students" />
    </div>

    <div v-else class="table-scroll">
        <table>
            <thead>
                <tr>
                    <th>Nom</th>
                    <th>Matricule</th>
                    <th>Date de naissance</th>
                    <th>Classe</th>
                    <th class="numeric">Présence</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="student in items" :key="student.id">
                    <td>
                        <RouterLink :to="`/students/${student.id}`" class="row-link">{{ student.name }}</RouterLink>
                    </td>
                    <td>{{ student.matricule }}</td>
                    <td>{{ formatDate(student.birth_date) }}</td>
                    <td>{{ student.school_class ? student.school_class.name : '–' }}</td>
                    <td class="numeric">
                        <span class="badge" :class="attendanceTone(student)" :title="attendanceTitle(student)">
                            {{ attendanceLabel(student) }}
                        </span>
                    </td>
                    <td class="actions">
                        <RouterLink :to="`/students/${student.id}`" class="btn-link">Voir</RouterLink>
                        <button class="btn-link" @click="openEdit(student)">Modifier</button>
                        <button class="btn-link danger" @click="remove(student)">Supprimer</button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <PaginationControls :meta="meta" :loading="loading" label="élèves" @change="goToPage" />

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
        <FormField label="Matricule" :error="errors.matricule">
            <input v-model="form.matricule" type="text" placeholder="Attribué automatiquement si vide">
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
