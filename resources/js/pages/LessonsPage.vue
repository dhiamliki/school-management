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
    useResource('/lessons');

const teachers = ref([]);
const schoolClasses = ref([]);
// Separate from the list's own failure: the table can load while these do not.
const optionsFailure = ref('');
const showForm = ref(false);
const editingId = ref(null);
const form = ref(blankForm());

function blankForm() {
    return { title: '', subject: '', teacher_id: '', school_class_id: '' };
}

function openCreate() {
    editingId.value = null;
    form.value = blankForm();
    errors.value = {};
    showForm.value = true;
}

function openEdit(lesson) {
    editingId.value = lesson.id;
    form.value = {
        title: lesson.title,
        subject: lesson.subject ?? '',
        teacher_id: lesson.teacher_id ?? '',
        school_class_id: lesson.school_class_id ?? '',
    };
    errors.value = {};
    showForm.value = true;
}

async function submit() {
    if (await save(form.value, editingId.value)) {
        showForm.value = false;
    }
}

function remove(lesson) {
    if (window.confirm(`Supprimer le cours ${lesson.title} ?`)) {
        destroy(lesson.id);
    }
}

onMounted(async () => {
    await load();

    try {
        const [teacherResponse, classResponse] = await Promise.all([
            api.get('/teachers', { params: { per_page: 100 } }),
            api.get('/school-classes', { params: { per_page: 100 } }),
        ]);
        teachers.value = unwrapList(teacherResponse.data);
        schoolClasses.value = unwrapList(classResponse.data);
    } catch (error) {
        optionsFailure.value =
            "Impossible de charger les enseignants et les classes. Rechargez la page avant d'ajouter un cours.";
    }
});
</script>

<template>
    <div class="page-header">
        <h1>Cours</h1>
        <button class="btn btn-primary" @click="openCreate"><AppIcon name="plus" :size="16" />Ajouter un cours</button>
    </div>

    <p v-if="failure" class="alert">{{ failure }}</p>
    <p v-if="optionsFailure" class="alert">{{ optionsFailure }}</p>

    <LoadingState v-if="loading && !items.length" :rows="5" />

    <div v-else-if="!items.length" class="card">
        <EmptyState title="Aucun cours." hint="Créez un cours pour commencer." icon="lessons" />
    </div>

    <div v-else class="table-scroll">
        <table>
            <thead>
                <tr>
                    <th>Titre</th>
                    <th>Matière</th>
                    <th>Enseignant</th>
                    <th>Classe</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <tr v-for="lesson in items" :key="lesson.id">
                    <td>{{ lesson.title }}</td>
                    <td>{{ lesson.subject || '–' }}</td>
                    <td>{{ lesson.teacher ? lesson.teacher.name : '–' }}</td>
                    <td>{{ lesson.school_class ? lesson.school_class.name : '–' }}</td>
                    <td class="actions">
                        <button class="btn-link" @click="openEdit(lesson)">Modifier</button>
                        <button class="btn-link danger" @click="remove(lesson)">Supprimer</button>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>

    <PaginationControls :meta="meta" :loading="loading" label="cours" @change="goToPage" />

    <AppModal
        v-if="showForm"
        :title="editingId ? 'Modifier le cours' : 'Nouveau cours'"
        :saving="saving"
        @close="showForm = false"
        @submit="submit"
    >
        <FormField label="Titre" :error="errors.title">
            <input v-model="form.title" type="text" required>
        </FormField>
        <FormField label="Matière" :error="errors.subject">
            <input v-model="form.subject" type="text">
        </FormField>
        <FormField label="Enseignant" :error="errors.teacher_id">
            <select v-model="form.teacher_id">
                <option value="">Aucun</option>
                <option v-for="teacher in teachers" :key="teacher.id" :value="teacher.id">
                    {{ teacher.name }}
                </option>
            </select>
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
