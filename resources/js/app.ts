import { createApp } from 'vue';
import '../css/tenant-app.css';
import { createRouter, createWebHistory } from 'vue-router';

import App from './App.vue';
import HomeView from './views/HomeView.vue';
import LoginView from './views/LoginView.vue';
import RegisterView from './views/RegisterView.vue';
import LegalView from './views/LegalView.vue';
import TenantView from './views/TenantView.vue';
import TenantPublicView from './views/TenantPublicView.vue';
import ControlView from './views/ControlView.vue';

export const isTenantHost = document.documentElement.dataset.tenantHost === 'true';

const platformRoutes = [
    { path: '/', component: HomeView }, { path: '/:locale(de|en|ru|uk)', component: HomeView },
    { path: '/pricing', component: HomeView, props:{pricingOnly:true} }, { path: '/:locale(de|en|ru|uk)/pricing', component: HomeView, props:{pricingOnly:true} },
    { path: '/login', component: LoginView }, { path: '/:locale(de|en|ru|uk)/login', component: LoginView },
    { path: '/register', component: RegisterView }, { path: '/:locale(de|en|ru|uk)/register', component: RegisterView },
    { path: '/app/:section?', component: TenantView, meta:{private:true} },
    { path: '/control/settings/:settingsGroup?', component: ControlView, meta:{private:true,control:true} },
    { path: '/control/:section?', component: ControlView, meta:{private:true,control:true} },
    { path: '/:key(impressum|datenschutz|agb|kontakt)', component: LegalView },
    { path: '/:locale(de|en|ru|uk)/:key(impressum|datenschutz|agb|kontakt)', component: LegalView },
];
const tenantRoutes = [{ path: '/:pathMatch(.*)*', component: TenantPublicView }];
const routes = isTenantHost ? tenantRoutes : platformRoutes;const router=createRouter({history:createWebHistory(),routes,scrollBehavior:(to)=>to.hash?({el:to.hash,top:78,behavior:'smooth'}):({top:0})});
if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js', { scope: '/' }).catch(() => undefined);
    });
}
createApp(App).use(router).mount('#app');
