<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { api } from '../api';
import { setLocale } from '../i18n';
import AppIcon from '../tenant-app/AppIcon.vue';
import RequestFlow from '../tenant-app/RequestFlow.vue';
import BookingFlow from '../tenant-app/BookingFlow.vue';
import { appCopy, type TenantLocale } from '../tenant-app/copy';

const route=useRoute(); const router=useRouter(); const app=ref<any>(null); const error=ref(''); const loading=ref(true); const activity=ref<any>({requests:[],appointments:[]}); const activityLoading=ref(false); const selectedRequest=ref<any>(null); const message=ref(''); const sending=ref(false); const installEvent=ref<any>(null);
const tokenKey=`lookdo-client:${location.hostname}`; const clientToken=ref(localStorage.getItem(tokenKey)||'');
const localeKey=`lookdo-client-locale:${location.hostname}`; const savedTenantLocale=localStorage.getItem(localeKey); const hasSelectedLocale=ref(['de','en','ru','uk'].includes(savedTenantLocale||''));
const locale=ref<TenantLocale>((hasSelectedLocale.value?savedTenantLocale:'de') as TenantLocale);
const copy=computed(()=>appCopy(locale.value));
const screen=computed(()=>{const parts=route.path.split('/').filter(Boolean).filter(part=>!['de','en','ru','uk'].includes(part));return parts[0]||'home';});
const theme=computed(()=>({'--ta-primary':app.value?.tenant?.colors?.primary||'#ff6b00','--ta-secondary':app.value?.tenant?.colors?.secondary||'#111318'}));
const actionScreen=computed(()=>app.value?.template?.engine==='booking'?'book':'request');
const navItems=computed(()=>[
  {key:'home',icon:'home',label:copy.value.home},{key:'works',icon:'works',label:copy.value.works},{key:actionScreen.value,icon:'plus',label:app.value?.template?.engine==='booking'?copy.value.book:copy.value.action,central:true},{key:'activity',icon:'message',label:copy.value.activity},{key:'profile',icon:'profile',label:copy.value.profile},
]);
const address=computed(()=>[app.value?.tenant?.contact?.street,[app.value?.tenant?.contact?.postal_code,app.value?.tenant?.contact?.city].filter(Boolean).join(' ')].filter(Boolean).join(', '));

async function load(){loading.value=true;error.value='';try{const headers:any={'X-Locale':hasSelectedLocale.value?locale.value:''};if(clientToken.value)headers['X-Lookdo-Client-Token']=clientToken.value;app.value=await api('/tenant-app/bootstrap',{headers});locale.value=(app.value.tenant.locale||'de') as TenantLocale;setLocale(locale.value);if(screen.value==='activity')await loadActivity();}catch(e:any){error.value=e.message;}finally{loading.value=false;}}
async function loadActivity(){if(!clientToken.value){activity.value={requests:[],appointments:[]};return;}activityLoading.value=true;try{activity.value=await api('/tenant-app/activity',{headers:{'X-Lookdo-Client-Token':clientToken.value}});if(selectedRequest.value)selectedRequest.value=activity.value.requests.find((item:any)=>item.id===selectedRequest.value.id)||null;}catch(e:any){error.value=e.message;}finally{activityLoading.value=false;}}
function go(target:string){router.push(target==='home'?'/':`/${target}`);}
function flowSuccess(payload:any){if(payload.token){clientToken.value=payload.token;localStorage.setItem(tokenKey,payload.token);}loadActivity();}
async function closeFlow(){await loadActivity();go('activity');}
async function changeLocale(value:string){locale.value=value as TenantLocale;hasSelectedLocale.value=true;localStorage.setItem(localeKey,locale.value);setLocale(locale.value);await load();}
async function sendMessage(){if(!message.value.trim()||!selectedRequest.value)return;sending.value=true;try{await api(`/tenant-app/requests/${selectedRequest.value.id}/messages`,{method:'POST',headers:{'X-Lookdo-Client-Token':clientToken.value},body:JSON.stringify({body:message.value})});message.value='';await loadActivity();}catch(e:any){error.value=e.message;}finally{sending.value=false;}}
function statusLabel(status:string){if(['completed','done','closed'].includes(status))return copy.value.statusDone;if(['pending','in_progress'].includes(status))return copy.value.statusPending;return copy.value.statusNew;}
async function share(){const data={title:app.value.tenant.name,text:app.value.template.hero.text,url:location.origin};if(navigator.share)await navigator.share(data);else await navigator.clipboard.writeText(data.url);}
async function install(){if(installEvent.value){installEvent.value.prompt();await installEvent.value.userChoice;installEvent.value=null;}else{alert(copy.value.install);}}
function beforeInstall(event:any){event.preventDefault();installEvent.value=event;}
watch(screen,async(value)=>{if(value==='activity')await loadActivity();});
onMounted(()=>{window.addEventListener('beforeinstallprompt',beforeInstall);load();});onBeforeUnmount(()=>window.removeEventListener('beforeinstallprompt',beforeInstall));
</script>

<template>
<div class="tenant-app-viewport" :style="theme">
  <div v-if="loading" class="ta-splash"><img :src="'/brand/lookdo-mark.png'" alt=""><span>LOOKDO</span></div>
  <div v-else-if="error&&!app" class="ta-unavailable"><img :src="'/brand/lookdo-mark.png'" alt=""><h1>{{ copy.unavailable }}</h1><p>{{ error }}</p><button class="ta-primary" @click="load">{{ copy.retry }}</button></div>
  <div v-else-if="app" class="tenant-app-desktop">
    <main class="tenant-app-shell">
      <RequestFlow v-if="screen==='request'" :app="app" :copy="copy" :locale="locale" :token="clientToken" @close="go('home')" @success="flowSuccess"/>
      <BookingFlow v-else-if="screen==='book'" :app="app" :copy="copy" :locale="locale" :token="clientToken" @close="go('home')" @success="flowSuccess"/>
      <template v-else>
        <header class="ta-topbar">
          <button class="ta-brand" @click="go('home')"><img :src="app.tenant.logo||'/brand/lookdo-mark.png'" :alt="app.tenant.name"><span>{{ app.tenant.name }}</span></button>
          <div class="ta-top-actions"><select :value="locale" aria-label="Language" @change="changeLocale(($event.target as HTMLSelectElement).value)"><option value="de">DE</option><option value="en">EN</option><option value="ru">RU</option><option value="uk">UK</option></select><button class="ta-icon-button" @click="go('activity')"><AppIcon name="bell"/></button></div>
        </header>

        <div class="ta-scroll-area">
          <section v-if="screen==='home'" class="ta-home-screen">
            <article class="ta-hero" :class="`layout-${app.template.layout}`">
              <img class="ta-hero-image" :src="app.template.hero.image" :alt="app.template.hero.title">
              <div class="ta-hero-shade"></div><div class="ta-hero-content"><small>{{ app.template.hero.eyebrow }}</small><h1>{{ app.template.hero.title }}</h1><p>{{ app.template.hero.text }}</p><button class="ta-hero-action" @click="go(actionScreen)"><AppIcon :name="app.template.engine==='booking'?'calendar':'camera'"/>{{ app.template.hero.action }}</button></div>
            </article>

            <section v-if="app.portfolio.length" class="ta-section"><div class="ta-section-head"><h2>{{ copy.featured }}</h2><button @click="go('works')">{{ copy.all }} <AppIcon name="arrow" :size="17"/></button></div><div class="ta-work-strip"><button v-for="item in app.portfolio.slice(0,4)" :key="item.id" @click="go('works')"><img :src="item.image" :alt="item.title"><span>{{ item.title }}</span></button></div></section>

            <section class="ta-how"><h2>{{ copy.how }}</h2><div><article><span><AppIcon name="camera"/></span><b>1</b><p>{{ copy.step1 }}</p></article><i></i><article><span><AppIcon name="user"/></span><b>2</b><p>{{ copy.step2 }}</p></article><i></i><article><span><AppIcon name="check"/></span><b>3</b><p>{{ copy.step3 }}</p></article></div></section>

            <section v-if="app.template.trust.length" class="ta-trust"><article v-for="item in app.template.trust" :key="item.label"><span><AppIcon :name="item.icon"/></span><p>{{ item.label }}</p></article></section>
            <section v-if="app.portfolio.length" class="ta-section ta-recent"><div class="ta-section-head"><h2>{{ copy.recent }}</h2><button @click="go('works')">{{ copy.all }} <AppIcon name="arrow" :size="17"/></button></div><div class="ta-work-grid"><article v-for="item in app.portfolio" :key="item.id"><img :src="item.image" :alt="item.title"><h3>{{ item.title }}</h3><p>{{ item.description }}</p></article></div></section>
          </section>

          <section v-else-if="screen==='works'" class="ta-page"><div class="ta-page-title"><small>{{ app.template.name }}</small><h1>{{ copy.works }}</h1><p>{{ app.tenant.description }}</p></div><div v-if="app.portfolio.length" class="ta-portfolio-grid"><article v-for="item in app.portfolio" :key="item.id" :class="{featured:item.featured}"><div class="ta-before-after" v-if="item.before_image&&item.after_image"><img :src="item.before_image"><img :src="item.after_image"></div><img v-else :src="item.image"><div><h2>{{ item.title }}</h2><p>{{ item.description }}</p></div></article></div><div v-else class="ta-empty"><AppIcon name="works" :size="40"/><p>{{ copy.noActivity }}</p></div><button class="ta-floating-cta" @click="go(actionScreen)"><AppIcon :name="app.template.engine==='booking'?'calendar':'camera'"/>{{ app.template.hero.action }}</button></section>

          <section v-else-if="screen==='activity'" class="ta-page ta-activity-page">
            <div class="ta-page-title"><small>{{ app.tenant.name }}</small><h1>{{ copy.activity }}</h1></div>
            <div v-if="activityLoading" class="ta-loading-line"></div>
            <div v-else-if="!activity.requests.length&&!activity.appointments.length" class="ta-empty"><span><AppIcon name="message" :size="42"/></span><h2>{{ copy.noActivity }}</h2><p>{{ copy.noActivityText }}</p><button class="ta-primary" @click="go(actionScreen)">{{ app.template.hero.action }}</button></div>
            <template v-else>
              <div class="ta-activity-list"><button v-for="item in activity.requests" :key="`r${item.id}`" @click="selectedRequest=item"><span class="ta-activity-icon"><AppIcon name="camera"/></span><span><b>#{{ item.number }}</b><small>{{ new Date(item.created_at).toLocaleString(locale,{dateStyle:'medium',timeStyle:'short'}) }}</small><em>{{ item.messages.at(-1)?.body }}</em></span><i>{{ statusLabel(item.status) }}</i></button><article v-for="item in activity.appointments" :key="`a${item.id}`"><span class="ta-activity-icon"><AppIcon name="calendar"/></span><span><b>{{ item.service?.name }}</b><small>{{ new Date(item.starts_at).toLocaleString(locale,{dateStyle:'long',timeStyle:'short'}) }}</small></span><i>{{ statusLabel(item.status) }}</i></article></div>
              <div v-if="selectedRequest" class="ta-thread"><header><button class="ta-icon-button" @click="selectedRequest=null"><AppIcon name="back"/></button><span><b>#{{ selectedRequest.number }}</b><small>{{ copy.messages }}</small></span></header><div class="ta-thread-messages"><p v-for="item in selectedRequest.messages" :key="item.id" :class="item.sender"><span>{{ item.body }}</span><small>{{ new Date(item.created_at).toLocaleTimeString(locale,{hour:'2-digit',minute:'2-digit'}) }}</small></p></div><form @submit.prevent="sendMessage"><input v-model="message" :placeholder="copy.messagePlaceholder"><button :disabled="sending||!message.trim()"><AppIcon name="arrow"/></button></form></div>
            </template>
          </section>

          <section v-else-if="screen==='profile'" class="ta-page"><div class="ta-profile-hero"><img :src="app.tenant.logo||'/brand/lookdo-mark.png'" :alt="app.tenant.name"><div><small>{{ app.template.name }}</small><h1>{{ app.tenant.name }}</h1><p>{{ app.tenant.description }}</p></div></div><div class="ta-profile-actions"><a v-if="app.tenant.contact.phone" :href="`tel:${app.tenant.contact.phone}`"><AppIcon name="phone"/><span>{{ copy.call }}</span></a><a v-if="app.tenant.contact.email" :href="`mailto:${app.tenant.contact.email}`"><AppIcon name="message"/><span>{{ copy.write }}</span></a><button @click="share"><AppIcon name="share"/><span>{{ copy.share }}</span></button></div><section class="ta-profile-card"><h2>{{ copy.businessInfo }}</h2><p v-if="address"><AppIcon name="home"/><span><b>{{ copy.address }}</b>{{ address }}</span></p><p v-if="app.tenant.contact.phone"><AppIcon name="phone"/><span><b>{{ copy.phone }}</b>{{ app.tenant.contact.phone }}</span></p><p v-if="app.tenant.contact.email"><AppIcon name="message"/><span><b>{{ copy.email }}</b>{{ app.tenant.contact.email }}</span></p><p v-if="!address&&!app.tenant.contact.phone&&!app.tenant.contact.email">{{ copy.profileEmpty }}</p></section><button class="ta-install" @click="install"><img :src="'/brand/lookdo-mark.png'" alt=""><span><b>{{ copy.install }}</b><small>{{ copy.powered }}</small></span><AppIcon name="arrow"/></button></section>
          <section v-else class="ta-page ta-empty"><h1>404</h1><button class="ta-primary" @click="go('home')">{{ copy.home }}</button></section>
        </div>

        <nav class="ta-bottom-nav" aria-label="App navigation"><button v-for="item in navItems" :key="item.key" :class="{active:screen===item.key,central:item.central}" @click="go(item.key)"><span><AppIcon :name="item.icon"/></span><small>{{ item.label }}</small></button></nav>
      </template>
    </main>
    <aside class="ta-desktop-aside"><img :src="'/brand/lookdo-mark.png'" alt="LOOKDO"><small>{{ app.tenant.name }}</small><h2>{{ copy.desktopTitle }}</h2><p>{{ copy.desktopText }}</p><div><span><AppIcon name="camera"/></span><span><AppIcon name="message"/></span><span><AppIcon name="calendar"/></span></div><button @click="share"><AppIcon name="share"/>{{ copy.share }}</button><em>{{ copy.powered }}</em></aside>
  </div>
</div>
</template>