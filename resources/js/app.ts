import { createApp } from 'vue';
import { createRouter, createWebHistory } from 'vue-router';
import { registerSW } from 'virtual:pwa-register';
import App from './App.vue';
import HomeView from './views/HomeView.vue';
import LoginView from './views/LoginView.vue';
import RegisterView from './views/RegisterView.vue';
import LegalView from './views/LegalView.vue';
import TenantView from './views/TenantView.vue';
import ControlView from './views/ControlView.vue';

const routes = [
    { path: '/', component: HomeView }, { path: '/:locale(de|en|ru)', component: HomeView },
    { path: '/pricing', component: HomeView, props:{pricingOnly:true} }, { path: '/:locale(de|en|ru)/pricing', component: HomeView, props:{pricingOnly:true} },
    { path: '/login', component: LoginView }, { path: '/:locale(de|en|ru)/login', component: LoginView },
    { path: '/register', component: RegisterView }, { path: '/:locale(de|en|ru)/register', component: RegisterView },
    { path: '/app/:section?', component: TenantView, meta:{private:true} },
    { path: '/control/:section?', component: ControlView, meta:{private:true,control:true} },
    { path: '/:key(impressum|datenschutz|agb|widerruf|kontakt)', component: LegalView },
    { path: '/:locale(de|en|ru)/:key(impressum|datenschutz|agb|widerruf|kontakt)', component: LegalView },
];
const router=createRouter({history:createWebHistory(),routes,scrollBehavior:()=>({top:0})});
registerSW({immediate:true});
createApp(App).use(router).mount('#app');
