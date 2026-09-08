<script setup>
import { ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import AppIcon from '../components/AppIcon.vue';
import FormField from '../components/FormField.vue';
import { useAuth } from '../composables/useAuth';

const router = useRouter();
const route = useRoute();
const { login, loading, errors, failure } = useAuth();

const form = ref({ email: '', password: '' });

/**
 * What the product actually does, shown beside the form. Static copy, so it
 * needs no data and cannot fail to load on the one screen that must always
 * work.
 */
const highlights = [
    { icon: 'students', text: 'Élèves, classes et enseignants au même endroit' },
    { icon: 'attendance', text: 'Présences relevées jour par jour' },
    { icon: 'timetable', text: 'Emploi du temps sans conflit' },
];

async function submit() {
    if (await login(form.value)) {
        const redirect = typeof route.query.redirect === 'string' ? route.query.redirect : '/dashboard';

        router.replace(redirect);
    }
}
</script>

<template>
    <div class="auth-page">
        <!-- Brand side. Hidden on narrow screens, where the form is all that
             matters and the panel would only push it below the fold. -->
        <aside class="auth-brand">
            <div class="auth-brand-inner">
                <p class="auth-wordmark">Gestion Scolaire</p>
                <p class="auth-tagline">
                    La gestion quotidienne de votre école primaire, réunie dans une seule
                    interface.
                </p>

                <ul class="auth-highlights">
                    <li v-for="item in highlights" :key="item.icon">
                        <span class="auth-highlight-icon"><AppIcon :name="item.icon" :size="16" /></span>
                        {{ item.text }}
                    </li>
                </ul>
            </div>
        </aside>

        <div class="auth-form-side">
            <form class="auth-card" @submit.prevent="submit">
                <h1 class="auth-title">Gestion Scolaire</h1>
                <p class="auth-subtitle">Connectez-vous pour continuer.</p>

                <p v-if="failure" class="alert"><AppIcon name="error" :size="16" />{{ failure }}</p>

                <FormField label="Email" :error="errors.email">
                    <input v-model="form.email" type="email" autocomplete="username" required autofocus>
                </FormField>

                <FormField label="Mot de passe" :error="errors.password">
                    <input v-model="form.password" type="password" autocomplete="current-password" required>
                </FormField>

                <button type="submit" class="btn btn-primary auth-submit" :disabled="loading">
                    <span v-if="loading" class="spinner spinner-on-accent"></span>
                    {{ loading ? 'Connexion…' : 'Se connecter' }}
                </button>
            </form>
        </div>
    </div>
</template>

<style scoped>
.spinner-on-accent {
    width: 13px;
    height: 13px;
    border-color: rgba(255, 255, 255, 0.4);
    border-top-color: #ffffff;
}
</style>
