import { createApp } from 'vue';
import { createRouter, createWebHistory } from 'vue-router';
import { registerSW } from 'virtual:pwa-register';
import App from './App.vue';
import HomeView from './views/HomeView.vue';
import LoginView from './views/LoginView.vue';
import RegisterView from './views/RegisterView.vue';
import LegalView from './views/LegalView.vue';
import TenantView from './views/TenantView.vue';
import TenantPublicView from './views/TenantPublicView.vue';
import ControlView from './views/ControlView.vue';

export const isTenantHost = document.documentElement.dataset.tenantHost === 'true';

const routes = [
    { path: '/', component: isTenantHost ? TenantPublicView : HomeView }, { path: '/:locale(de|en|ru|uk)', component: isTenantHost ? TenantPublicView : HomeView },
    { path: '/pricing', component: HomeView, props:{pricingOnly:true} }, { path: '/:locale(de|en|ru|uk)/pricing', component: HomeView, props:{pricingOnly:true} },
    { path: '/login', component: LoginView }, { path: '/:locale(de|en|ru|uk)/login', component: LoginView },
    { path: '/register', component: RegisterView }, { path: '/:locale(de|en|ru|uk)/register', component: RegisterView },
    { path: '/app/:section?', component: TenantView, meta:{private:true} },
    { path: '/control/:section?', component: ControlView, meta:{private:true,control:true} },
    { path: '/:key(impressum|datenschutz|agb|kontakt)', component: LegalView },
    { path: '/:locale(de|en|ru|uk)/:key(impressum|datenschutz|agb|kontakt)', component: LegalView },
];
const router=createRouter({history:createWebHistory(),routes,scrollBehavior:(to)=>to.hash?({el:to.hash,top:78,behavior:'smooth'}):({top:0})});
registerSW({immediate:true});
createApp(App).use(router).mount('#app');
