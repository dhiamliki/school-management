import { createRouter, createWebHistory } from 'vue-router';
import ClassesPage from './pages/ClassesPage.vue';
import DashboardPage from './pages/DashboardPage.vue';
import LessonsPage from './pages/LessonsPage.vue';
import StudentsPage from './pages/StudentsPage.vue';
import TeachersPage from './pages/TeachersPage.vue';
import TimetablePage from './pages/TimetablePage.vue';

const routes = [
    { path: '/', redirect: '/dashboard' },
    { path: '/dashboard', name: 'dashboard', component: DashboardPage },
    { path: '/classes', name: 'classes', component: ClassesPage },
    { path: '/students', name: 'students', component: StudentsPage },
    { path: '/teachers', name: 'teachers', component: TeachersPage },
    { path: '/lessons', name: 'lessons', component: LessonsPage },
    { path: '/timetable', name: 'timetable', component: TimetablePage },
];

export default createRouter({
    history: createWebHistory(),
    routes,
});
