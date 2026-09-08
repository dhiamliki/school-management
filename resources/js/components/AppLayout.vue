<script setup>
import { computed } from 'vue';
import { useRouter } from 'vue-router';
import { useAuth } from '../composables/useAuth';
import AppIcon from './AppIcon.vue';
import GlobalSearch from './GlobalSearch.vue';

const router = useRouter();
const { user, isAuthenticated, logout } = useAuth();

const links = [
    { to: '/dashboard', label: 'Tableau de bord', icon: 'dashboard' },
    { to: '/classes', label: 'Classes', icon: 'classes' },
    { to: '/students', label: 'Élèves', icon: 'students' },
    { to: '/teachers', label: 'Enseignants', icon: 'teachers' },
    { to: '/lessons', label: 'Cours', icon: 'lessons' },
    { to: '/timetable', label: 'Emploi du temps', icon: 'timetable' },
    { to: '/attendance', label: 'Présences', icon: 'attendance' },
    { to: '/chatbot', label: 'Assistant IA', icon: 'chatbot' },
];

async function signOut() {
    await logout();
    router.replace('/login');
}

/**
 * What the header calls the signed-in user: their name if the account has
 * one, otherwise the address they signed in with.
 */
const displayName = computed(() => user.value?.name || user.value?.email || '');

/**
 * The label under the name in the header. The column holds a role slug; this
 * is the French word for it, and anything unrecognised falls back to the slug
 * rather than to a blank line.
 */
const ROLE_LABELS = {
    admin: 'Administrateur',
    teacher: 'Enseignant',
};

const roleLabel = computed(() => {
    const role = user.value?.role;
    const label = role ? (ROLE_LABELS[role] ?? role) : '';

    // The seeded administrator is literally called "Administrateur", and
    // printing that twice over reads as a rendering fault rather than as a
    // name above a role.
    return label && label.toLowerCase() === displayName.value.toLowerCase() ? '' : label;
});

/**
 * Initials for the avatar: first letters of the first two words of the name,
 * falling back to the address when no name is recorded.
 */
const initials = computed(() => {
    return displayName.value
        .split(/[\s.@_-]+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((word) => word[0].toUpperCase())
        .join('');
});
</script>

<template>
    <aside v-if="isAuthenticated" class="sidebar">
        <p class="sidebar-title">Gestion Scolaire</p>
        <nav class="sidebar-nav">
            <RouterLink v-for="link in links" :key="link.to" :to="link.to" :title="link.label">
                <AppIcon :name="link.icon" />
                <span>{{ link.label }}</span>
            </RouterLink>
        </nav>
        <div class="sidebar-footer">
            <p class="sidebar-user">{{ user?.email }}</p>
            <button type="button" class="sidebar-logout" title="Déconnexion" @click="signOut">
                <AppIcon name="logout" :size="16" />
                <span>Déconnexion</span>
            </button>
        </div>
    </aside>

    <main :class="isAuthenticated ? 'content' : 'content content-full'">
        <header v-if="isAuthenticated" class="topbar">
            <GlobalSearch />

            <div class="topbar-user">
                <span class="topbar-identity">
                    <span class="topbar-name">{{ displayName }}</span>
                    <span v-if="roleLabel" class="topbar-role">{{ roleLabel }}</span>
                </span>
                <span class="topbar-avatar" aria-hidden="true">{{ initials }}</span>
            </div>
        </header>

        <RouterView v-slot="{ Component, route }">
            <!--
                The wrapper div is load-bearing, not decoration.

                Every page component has several root nodes (a header, a table,
                a modal...), so it renders as a fragment. <Transition> can only
                drive a single root element: given a fragment it never receives
                the leave-completed callback, and with mode="out-in" it waits
                for that callback before mounting the next page - so the route
                changed but nothing ever rendered. Wrapping in one real element
                gives the transition something to animate, and keying that
                element on the path still remounts the page on every route.
            -->
            <Transition name="route-fade" mode="out-in">
                <div :key="route.path" class="route-view">
                    <component :is="Component" />
                </div>
            </Transition>
        </RouterView>
    </main>
</template>
