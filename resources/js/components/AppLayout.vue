<script setup>
const links = [
    { to: '/dashboard', label: 'Tableau de bord' },
    { to: '/classes', label: 'Classes' },
    { to: '/students', label: 'Élèves' },
    { to: '/teachers', label: 'Enseignants' },
    { to: '/lessons', label: 'Cours' },
    { to: '/timetable', label: 'Emploi du temps' },
];
</script>

<template>
    <aside class="sidebar">
        <p class="sidebar-title">Gestion Scolaire</p>
        <nav class="sidebar-nav">
            <RouterLink v-for="link in links" :key="link.to" :to="link.to">
                {{ link.label }}
            </RouterLink>
        </nav>
    </aside>

    <main class="content">
        <RouterView />
    </main>
</template>
