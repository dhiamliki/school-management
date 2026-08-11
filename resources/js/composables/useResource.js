import { ref } from 'vue';
import api from '../lib/api';

/**
 * Shared list/create/update/delete behaviour for a single API endpoint.
 */
export function useResource(endpoint) {
    const items = ref([]);
    const loading = ref(false);
    const saving = ref(false);
    const errors = ref({});
    const failure = ref('');

    async function load() {
        loading.value = true;
        failure.value = '';

        try {
            const { data } = await api.get(endpoint);
            items.value = data;
        } catch (error) {
            failure.value = 'Impossible de charger les données.';
        } finally {
            loading.value = false;
        }
    }

    async function save(payload, id = null) {
        saving.value = true;
        errors.value = {};
        failure.value = '';

        try {
            if (id) {
                await api.put(`${endpoint}/${id}`, payload);
            } else {
                await api.post(endpoint, payload);
            }

            await load();

            return true;
        } catch (error) {
            if (error.response && error.response.status === 422) {
                errors.value = error.response.data.errors;
            } else {
                failure.value = "Impossible d'enregistrer.";
            }

            return false;
        } finally {
            saving.value = false;
        }
    }

    async function destroy(id) {
        failure.value = '';

        try {
            await api.delete(`${endpoint}/${id}`);
            await load();
        } catch (error) {
            failure.value = 'Impossible de supprimer.';
        }
    }

    return { items, loading, saving, errors, failure, load, save, destroy };
}
