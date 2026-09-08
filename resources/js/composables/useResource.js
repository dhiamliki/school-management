import { ref } from 'vue';
import api from '../lib/api';

function emptyMeta() {
    return { current_page: 1, last_page: 1, per_page: null, total: 0, from: 0, to: 0 };
}

// Accepts both the paginated envelope and a flat array.
export function unwrapList(payload) {
    return Array.isArray(payload) ? payload : (payload?.data ?? []);
}

export function unwrapMeta(payload) {
    if (Array.isArray(payload)) {
        return {
            current_page: 1,
            last_page: 1,
            per_page: payload.length,
            total: payload.length,
            from: payload.length ? 1 : 0,
            to: payload.length,
        };
    }

    const meta = payload?.meta;

    if (!meta) {
        return emptyMeta();
    }

    return {
        current_page: meta.current_page ?? 1,
        last_page: meta.last_page ?? 1,
        per_page: meta.per_page ?? null,
        total: meta.total ?? 0,
        // Laravel sends null for both on an empty page.
        from: meta.from ?? 0,
        to: meta.to ?? 0,
    };
}

// perPage fixes the page size for this instance; omit it for the API default.
export function useResource(endpoint, { perPage = null } = {}) {
    const items = ref([]);
    const loading = ref(false);
    const saving = ref(false);
    const errors = ref({});
    const failure = ref('');
    const meta = ref(emptyMeta());
    const page = ref(1);

    async function load({ page: requestedPage = page.value, retryOutOfRange = true } = {}) {
        loading.value = true;
        failure.value = '';

        try {
            const params = { page: requestedPage };

            if (perPage) {
                params.per_page = perPage;
            }

            const { data } = await api.get(endpoint, { params });
            items.value = unwrapList(data);
            meta.value = unwrapMeta(data);
            page.value = meta.value.current_page;

            // Deleting the last row of the last page lands past the end.
            if (retryOutOfRange && meta.value.last_page >= 1 && requestedPage > meta.value.last_page) {
                return await load({ page: meta.value.last_page, retryOutOfRange: false });
            }
        } catch (error) {
            failure.value = 'Impossible de charger les données.';
        } finally {
            loading.value = false;
        }
    }

    async function goToPage(target) {
        const wanted = Math.min(Math.max(Number(target) || 1, 1), meta.value.last_page || 1);

        if (loading.value || wanted === page.value) {
            return;
        }

        await load({ page: wanted });
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

            // A new row heads page 1; an edit leaves the row where it was.
            await load({ page: id ? page.value : 1 });

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

    return { items, loading, saving, errors, failure, meta, page, load, goToPage, save, destroy };
}
