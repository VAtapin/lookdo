import { createApp } from 'vue';
import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router';
import App from './App.vue';

const HomeView = () => import('./views/HomeView.vue');
const LoginView = () => import('./views/LoginView.vue');
const RegisterView = () => import('./views/RegisterView.vue');
const LegalView = () => import('./views/LegalView.vue');
const TenantView = () => import('./views/TenantView.vue');
const TenantPublicView = () => import('./views/TenantPublicView.vue');
const ControlView = () => import('./views/ControlView.vue');
const NotFoundView = () => import('./views/NotFoundView.vue');

export const isTenantHost = document.documentElement.dataset.tenantHost === 'true';

const platformRoutes: RouteRecordRaw[] = [
    { path: '/', component: HomeView },
    { path: '/:locale(de|en|ru|uk)', component: HomeView },
    { path: '/pricing', component: HomeView, props: { pricingOnly: true } },
    { path: '/:locale(de|en|ru|uk)/pricing', component: HomeView, props: { pricingOnly: true } },
    { path: '/login', component: LoginView },
    { path: '/:locale(de|en|ru|uk)/login', component: LoginView },
    { path: '/register', component: RegisterView },
    { path: '/:locale(de|en|ru|uk)/register', component: RegisterView },
    { path: '/app/:section(overview|business|domain|billing)?', component: TenantView, meta: { private: true } },
    { path: '/control/settings/:settingsGroup?', component: ControlView, meta: { private: true, control: true } },
    { path: '/control/:section(dashboard|tenants|administrators|subscriptions|plans|stripe|sms|templates|ai|classifications|content|backups|audit)?', component: ControlView, meta: { private: true, control: true } },
    { path: '/:key(impressum|datenschutz|agb|kontakt)', component: LegalView },
    { path: '/:locale(de|en|ru|uk)/:key(impressum|datenschutz|agb|kontakt)', component: LegalView },
    { path: '/:pathMatch(.*)*', component: NotFoundView },
];
const tenantRoutes: RouteRecordRaw[] = [{ path: '/:pathMatch(.*)*', component: TenantPublicView }];
const router = createRouter({
    history: createWebHistory(),
    routes: isTenantHost ? tenantRoutes : platformRoutes,
    scrollBehavior: (to) => to.hash ? ({ el: to.hash, top: 78, behavior: 'smooth' }) : ({ top: 0 }),
});

if (isTenantHost && 'serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => undefined);
    });
}

createApp(App).use(router).mount('#app');
