import { ref } from 'vue';
import api from '../lib/api';

/**
 * The shape the index endpoints answer with when no page has been loaded yet.
 */
function emptyMeta() {
    return { current_page: 1, last_page: 1, per_page: null, total: 0, from: 0, to: 0 };
}

/**
 * Index endpoints answer with Laravel's paginated envelope
 * ({ data, links, meta }). Older callers passed the flat array straight
 * through, so both shapes are accepted here.
 */
export function unwrapList(payload) {
    return Array.isArray(payload) ? payload : (payload?.data ?? []);
}

/**
 * Pull the pagination block out of a paginated response, falling back to a
 * single-page description for a flat array.
 */
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

/**
 * Shared list/create/update/delete behaviour for a single API endpoint.
 *
 * `perPage` fixes the page size for every request from this instance. Leave
 * it out to take the API default (15); pass a large value for a view that
 * has to render a whole bounded dataset at once, such as the timetable grid.
 */
export function useResource(endpoint, { perPage = null } = {}) {
    const items = ref([]);
    const loading = ref(false);
    const saving = ref(false);
    const errors = ref({});
    const failure = ref('');
    const meta = ref(emptyMeta());
    const page = ref(1);

    /**
     * Load one page of the collection, keeping whatever page is current
     * unless a specific one is asked for.
     */
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

            // Deleting the last row of the last page leaves the current page
            // past the end of the collection: land on the new last page
            // rather than showing an empty table.
            if (retryOutOfRange && meta.value.last_page >= 1 && requestedPage > meta.value.last_page) {
                return await load({ page: meta.value.last_page, retryOutOfRange: false });
            }
        } catch (error) {
            failure.value = 'Impossible de charger les données.';
        } finally {
            loading.value = false;
        }
    }

    /**
     * Move to another page, ignoring anything out of range or already shown.
     */
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

            // An edit leaves the row where it was, so stay put. A new row is
            // newest-first and therefore heads page 1: staying on page 2+ would
            // only tick the total up with nothing visibly added.
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
