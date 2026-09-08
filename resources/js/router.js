import { createRouter, createWebHistory } from 'vue-router';
import { useAuth } from './composables/useAuth';
import AttendancePage from './pages/AttendancePage.vue';
import ChatbotPage from './pages/ChatbotPage.vue';
import ClassProfilePage from './pages/ClassProfilePage.vue';
import ClassesPage from './pages/ClassesPage.vue';
import DashboardPage from './pages/DashboardPage.vue';
import LessonsPage from './pages/LessonsPage.vue';
import LoginPage from './pages/LoginPage.vue';
import NotFoundPage from './pages/NotFoundPage.vue';
import StudentProfilePage from './pages/StudentProfilePage.vue';
import StudentsPage from './pages/StudentsPage.vue';
import TeacherProfilePage from './pages/TeacherProfilePage.vue';
import TeachersPage from './pages/TeachersPage.vue';
import TimetablePage from './pages/TimetablePage.vue';

const routes = [
    { path: '/', redirect: '/dashboard' },
    { path: '/login', name: 'login', component: LoginPage, meta: { public: true } },
    { path: '/dashboard', name: 'dashboard', component: DashboardPage },
    { path: '/classes', name: 'classes', component: ClassesPage },
    { path: '/classes/:id', name: 'class-profile', component: ClassProfilePage },
    { path: '/students', name: 'students', component: StudentsPage },
    { path: '/students/:id', name: 'student-profile', component: StudentProfilePage },
    { path: '/teachers', name: 'teachers', component: TeachersPage },
    { path: '/teachers/:id', name: 'teacher-profile', component: TeacherProfilePage },
    { path: '/lessons', name: 'lessons', component: LessonsPage },
    { path: '/timetable', name: 'timetable', component: TimetablePage },
    { path: '/attendance', name: 'attendance', component: AttendancePage },
    { path: '/chatbot', name: 'chatbot', component: ChatbotPage },
    // Last, so it only catches what nothing above matched. Left behind the
    // auth guard like every other page: an unknown path while signed out
    // belongs at the login screen, not at a 404.
    { path: '/:pathMatch(.*)*', name: 'not-found', component: NotFoundPage },
];

const router = createRouter({
    history: createWebHistory(),
    routes,
});

router.beforeEach(async (to) => {
    const { isAuthenticated, isResolved, fetchUser } = useAuth();

    // On a cold load we do not know yet whether a session cookie is valid.
    if (!isResolved.value) {
        await fetchUser();
    }

    if (to.meta.public) {
        return isAuthenticated.value ? { path: '/dashboard' } : true;
    }

    if (!isAuthenticated.value) {
        return { path: '/login', query: to.fullPath === '/' ? {} : { redirect: to.fullPath } };
    }

    return true;
});

export default router;
