<script setup lang="ts">
import '../../css/tenant-app.css';
import '../../css/tenant-steering.css';
import '../../css/tenant-brows.css';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ApiError, api } from '../api';
import { setLocale } from '../i18n';
import AppIcon from '../tenant-app/AppIcon.vue';
import RequestFlow from '../tenant-app/RequestFlow.vue';
import BookingFlow from '../tenant-app/BookingFlow.vue';
import BeforeAfterSlider from '../tenant-app/BeforeAfterSlider.vue';
import { appCopy, type TenantLocale } from '../tenant-app/copy';

const route=useRoute();const router=useRouter();
const app=ref<any>(null);const error=ref('');const loading=ref(true);
const activity=ref<any>({requests:[],appointments:[]});const activityLoading=ref(false);
const selectedRequest=ref<any>(null);const message=ref('');const sending=ref(false);
const menuOpen=ref(false);const pushPrompt=ref(false);const pushBusy=ref(false);const pushStatus=ref('');
const loginEmail=ref('');const loginPassword=ref('');const loginRemember=ref(true);const loginBusy=ref(false);const loginError=ref('');
const now=ref(new Date());let clock:number|undefined;
const tokenKey='lookdo-client:'+location.hostname;const clientToken=ref(localStorage.getItem(tokenKey)||'');
const localeKey='lookdo-client-locale:'+location.hostname;const savedLocale=localStorage.getItem(localeKey);
const hasSelectedLocale=ref(['de','en','ru','uk'].includes(savedLocale||''));
const locale=ref<TenantLocale>((hasSelectedLocale.value?savedLocale:'de') as TenantLocale);
const copy=computed(()=>appCopy(locale.value));
const screen=computed(()=>{const parts=route.path.split('/').filter(Boolean).filter(part=>!['de','en','ru','uk'].includes(part));return parts[0]||'home';});
const actionScreen=computed(()=>app.value?.template?.engine==='booking'?'book':'request');
const isSteering=computed(()=>app.value?.template?.layout==='steering');
const isBrows=computed(()=>app.value?.template?.layout==='brows');
const theme=computed(()=>({'--ta-primary':isSteering.value?'#e2ad55':app.value?.tenant?.colors?.primary||app.value?.template?.theme?.primary||'#ff6b00','--ta-secondary':isSteering.value?'#07090b':app.value?.tenant?.colors?.secondary||app.value?.template?.theme?.secondary||'#111318','--ta-template-surface':app.value?.template?.theme?.surface||'#fff','--ta-template-text':app.value?.template?.theme?.text||'#111318'}));
const address=computed(()=>[app.value?.tenant?.contact?.street,[app.value?.tenant?.contact?.postal_code,app.value?.tenant?.contact?.city].filter(Boolean).join(' ')].filter(Boolean).join(', '));
const averageRating=computed(()=>{const rows=app.value?.reviews||[];return rows.length?(rows.reduce((sum:number,item:any)=>sum+Number(item.rating||0),0)/rows.length).toFixed(1):'—';});
const navItems=computed(()=>[
  ...(isBrows.value?[
    {key:'home',icon:'home',label:copy.value.home},
    {key:'services',icon:'works',label:copy.value.servicesNav},
    {key:'book',icon:'calendar',label:copy.value.book,central:true},
    {key:'contacts',icon:'phone',label:copy.value.contacts},
  ]:[
  {key:'home',icon:'home',label:copy.value.home},
  {key:'works',icon:'works',label:copy.value.works},
  {key:actionScreen.value,icon:'camera',label:copy.value.action,central:true},
  {key:'activity',icon:'message',label:copy.value.activity},
  ]),
]);
const contactName=computed(()=>app.value?.tenant?.contact?.name||app.value?.tenant?.name);
const rescheduleAppointment=ref<any>(null);

function tenantLocale(value:unknown):TenantLocale|null{return typeof value==='string'&&['de','en','ru','uk'].includes(value)?value as TenantLocale:null;}
function applyTenantLocale(value:unknown){const next=tenantLocale(value);if(!next)return;locale.value=next;setLocale(next);}
function go(target:string){menuOpen.value=false;router.push(target==='home'?'/':'/'+target);}
async function load(){
  loading.value=true;error.value='';
  try{
    const headers:any={'X-Locale':hasSelectedLocale.value?locale.value:''};if(clientToken.value)headers['X-Lookdo-Client-Token']=clientToken.value;
    app.value=await api('/tenant-app/bootstrap',{headers});applyTenantLocale(app.value.tenant.locale||'de');
    if(screen.value==='activity')await loadActivity();
    if(app.value.session?.known&&shouldAskPush())pushPrompt.value=true;
  }catch(e:any){if(e instanceof ApiError)applyTenantLocale(e.payload.locale);error.value=e.message;}finally{loading.value=false;}
}
function shouldAskPush(){return Boolean(app.value?.push?.enabled&&clientToken.value&&'Notification' in window&&Notification.permission==='default'&&!localStorage.getItem('lookdo-push-later:'+location.hostname));}
async function loadActivity(){
  if(!clientToken.value){activity.value={requests:[],appointments:[]};return;}
  activityLoading.value=true;
  try{activity.value=await api('/tenant-app/activity',{headers:{'X-Lookdo-Client-Token':clientToken.value}});if(selectedRequest.value)selectedRequest.value=activity.value.requests.find((item:any)=>item.id===selectedRequest.value.id)||null;}
  catch(e:any){error.value=e.message;}finally{activityLoading.value=false;}
}
function flowSuccess(payload:any){if(payload.token){clientToken.value=payload.token;localStorage.setItem(tokenKey,payload.token);}loadActivity();}
async function cancelAppointment(item:any){if(!confirm(copy.value.cancelConfirm))return;try{await api('/tenant-app/appointments/'+item.id,{method:'DELETE',headers:{'X-Lookdo-Client-Token':clientToken.value}});await loadActivity();}catch(e:any){error.value=e.message;}}
async function changeLocale(value:string){locale.value=value as TenantLocale;hasSelectedLocale.value=true;localStorage.setItem(localeKey,locale.value);setLocale(locale.value);await load();}
async function sendMessage(){
  if(!message.value.trim()||!selectedRequest.value)return;sending.value=true;
  try{await api('/tenant-app/requests/'+selectedRequest.value.id+'/messages',{method:'POST',headers:{'X-Lookdo-Client-Token':clientToken.value},body:JSON.stringify({body:message.value})});message.value='';await loadActivity();}
  catch(e:any){error.value=e.message;}finally{sending.value=false;}
}
function statusLabel(status:string){if(['completed','done','closed'].includes(status))return copy.value.statusDone;if(['pending','in_progress'].includes(status))return copy.value.statusPending;return copy.value.statusNew;}
async function share(){const data={title:app.value.tenant.name,text:app.value.template.hero.text,url:location.origin};if(navigator.share)await navigator.share(data);else await navigator.clipboard.writeText(data.url);}
function applicationServerKey(value:string):Uint8Array{const padding='='.repeat((4-value.length%4)%4);const raw=atob((value+padding).replace(/-/g,'+').replace(/_/g,'/'));return Uint8Array.from([...raw].map(char=>char.charCodeAt(0)));}
async function enablePush(){
  pushBusy.value=true;pushStatus.value='';
  try{
    const permission=await Notification.requestPermission();if(permission!=='granted'){pushStatus.value=copy.value.notificationDenied;return;}
    const registration=await navigator.serviceWorker.ready;let subscription=await registration.pushManager.getSubscription();
    if(!subscription)subscription=await registration.pushManager.subscribe({userVisibleOnly:true,applicationServerKey:applicationServerKey(app.value.push.public_key) as BufferSource});
    const value=subscription.toJSON();await api('/tenant-app/push-subscriptions',{method:'POST',headers:{'X-Lookdo-Client-Token':clientToken.value},body:JSON.stringify({endpoint:value.endpoint,keys:value.keys})});
    pushStatus.value=copy.value.notificationEnabled;window.setTimeout(()=>pushPrompt.value=false,700);
  }catch(e:any){pushStatus.value=e.message;}finally{pushBusy.value=false;}
}
function dismissPush(){localStorage.setItem('lookdo-push-later:'+location.hostname,'1');pushPrompt.value=false;}
async function login(){
  loginBusy.value=true;loginError.value='';
  try{
    const result=await api('/login',{method:'POST',body:JSON.stringify({email:loginEmail.value,password:loginPassword.value,remember:loginRemember.value})});
    const labels=location.hostname.split('.');const platform=labels.length>2?labels.slice(-2).join('.'):location.hostname;
    location.href=location.protocol+'//'+platform+(result.user?.is_super_admin?'/control':'/app');
  }catch(e:any){loginError.value=e.message||copy.value.loginError;}finally{loginBusy.value=false;}
}
watch(screen,async value=>{selectedRequest.value=null;if(value==='activity')await loadActivity();});
onMounted(()=>{clock=window.setInterval(()=>now.value=new Date(),30000);load();});
onBeforeUnmount(()=>{if(clock)window.clearInterval(clock);});
</script>

<template>
<div class="tenant-app-viewport" :class="{'theme-steering':isSteering,'theme-brows':isBrows}" :style="theme">
  <div v-if="loading" class="ta-splash"><img :src="'/brand/lookdo-mark.webp'" alt=""><span>LOOKDO</span></div>
  <div v-else-if="error&&!app" class="ta-unavailable"><img :src="'/brand/lookdo-mark.webp'" alt=""><h1>{{copy.unavailable}}</h1><p>{{error}}</p><button class="ta-primary" @click="load">{{copy.retry}}</button></div>
  <div v-else-if="app" class="tenant-app-desktop">
    <main class="tenant-app-shell">
      <BookingFlow v-if="rescheduleAppointment" :app="app" :copy="copy" :locale="locale" :token="clientToken" :appointment="rescheduleAppointment" @close="rescheduleAppointment=null" @success="flowSuccess"/>
      <RequestFlow v-else-if="screen==='request'" :app="app" :copy="copy" :locale="locale" :token="clientToken" @close="go('home')" @success="flowSuccess"/>
      <BookingFlow v-else-if="screen==='book'" :app="app" :copy="copy" :locale="locale" :token="clientToken" @close="go('home')" @success="flowSuccess"/>
      <section v-else-if="screen==='login'" class="ta-login-screen">
        <div class="ta-statusbar"><b>{{now.toLocaleTimeString(locale,{hour:'2-digit',minute:'2-digit'})}}</b><span class="ta-device-icons"><i class="signal"></i><i class="wifi"></i><i class="battery"></i></span></div>
        <button class="ta-login-back" @click="go('home')"><AppIcon name="back"/>{{copy.back}}</button>
        <img class="ta-login-logo" :src="app.tenant.logo||'/brand/lookdo-mark.webp'" alt="">
        <h1>{{copy.login}}</h1><p>{{copy.welcome}}, {{contactName}}</p>
        <form @submit.prevent="login">
          <label><span>{{copy.emailOrLogin}}</span><div><AppIcon name="user"/><input v-model="loginEmail" type="email" autocomplete="email" required></div></label>
          <label><span>{{copy.password}}</span><div><AppIcon name="shield"/><input v-model="loginPassword" type="password" autocomplete="current-password" required></div></label>
          <div class="ta-login-options"><label><input v-model="loginRemember" type="checkbox">{{copy.remember}}</label><button type="button">{{copy.forgot}}</button></div>
          <p v-if="loginError" class="ta-error">{{loginError}}</p>
          <button class="ta-gold-button" :disabled="loginBusy">{{loginBusy?'…':copy.signIn}}</button>
        </form>
        <article><AppIcon name="shield" :size="34"/><div><h2>{{copy.adminOnly}}</h2><p>{{copy.adminOnlyText}}</p></div></article>
        <small>{{copy.powered}}</small>
      </section>
      <template v-else>
        <div class="ta-statusbar"><b>{{now.toLocaleTimeString(locale,{hour:'2-digit',minute:'2-digit'})}}</b><span class="ta-device-icons"><i class="signal"></i><i class="wifi"></i><i class="battery"></i></span></div>
        <div class="ta-scroll-area">
          <section v-if="screen==='home'&&isBrows" class="ta-home-screen ta-brows-home">
            <header class="ta-brows-header"><button class="ta-brand" @click="go('home')"><img :src="app.tenant.logo||'/brand/lookdo-mark.webp'" :alt="app.tenant.name"><span>{{app.tenant.name}}</span></button><div><a v-if="app.tenant.contact.phone" :href="'https://wa.me/'+app.tenant.contact.phone.replace(/\D/g,'')" target="_blank"><AppIcon name="message"/></a><button @click="menuOpen=true"><AppIcon name="menu"/></button></div></header>
            <article class="ta-brows-hero"><img :src="app.template.hero.image||'/brand/service-brows.webp'" :alt="app.template.hero.title"><div></div><section><small>{{app.template.hero.eyebrow}}</small><h1>{{app.tenant.branding?.tagline||app.template.hero.title}}</h1><p>{{app.template.hero.text}}</p><button class="ta-primary" @click="go('book')"><AppIcon name="calendar"/>{{app.template.hero.action}}</button></section></article>
            <section class="ta-section ta-featured"><div class="ta-section-head"><h2>{{copy.featured}}</h2><button @click="go('works')">{{copy.all}} <AppIcon name="arrow" :size="17"/></button></div><div v-if="app.portfolio.length" class="ta-work-strip"><button v-for="item in app.portfolio.slice(0,4)" :key="item.id" @click="go('works')"><BeforeAfterSlider v-if="item.before_image&&item.after_image" :before="item.before_image" :after="item.after_image" :before-label="copy.before" :after-label="copy.after" :alt="item.title"/><img v-else :src="item.image||item.after_image||item.before_image" :alt="item.title"><span>{{item.title}}</span></button></div><div v-else class="ta-section-empty"><AppIcon name="image"/><p>{{copy.noActivity}}</p></div></section>
            <section v-if="app.template.trust.length" class="ta-brows-trust"><article v-for="item in app.template.trust" :key="item.label"><span><AppIcon :name="item.icon"/></span><p>{{item.label}}</p></article></section>
            <section class="ta-section ta-recent"><div class="ta-section-head"><h2>{{copy.recent}}</h2><button @click="go('works')">{{copy.all}} <AppIcon name="arrow" :size="17"/></button></div><div class="ta-work-grid"><article v-for="item in app.portfolio.slice(0,8)" :key="item.id"><img :src="item.image||item.after_image||item.before_image" :alt="item.title"><h3>{{item.title}}</h3><p>{{item.description}}</p></article></div></section>
          </section>

          <section v-else-if="screen==='home'" class="ta-home-screen">
            <article class="ta-hero">
              <img class="ta-hero-image" :src="app.template.hero.image||'/brand/steering-wheel-placeholder.svg'" :alt="app.template.hero.eyebrow">
              <div class="ta-hero-shade"></div>
              <header class="ta-hero-header">
                <button class="ta-brand" @click="go('home')"><img :src="app.tenant.logo||'/brand/lookdo-mark.webp'" :alt="app.tenant.name"><span>{{app.tenant.name}}</span></button>
                <span class="ta-service-name">{{app.template.hero.eyebrow}}</span>
                <div><a v-if="app.tenant.contact.vk_url" :href="app.tenant.contact.vk_url" target="_blank">VK</a><button @click="menuOpen=true"><AppIcon name="menu"/></button></div>
              </header>
              <div class="ta-hero-content">
                <h1>{{app.template.hero.eyebrow}}</h1>
                <p class="gold">{{app.tenant.branding?.tagline||app.template.hero.title}}</p>
                <p>{{app.template.hero.text}}</p>
                <button class="ta-gold-button" @click="go(actionScreen)"><AppIcon :name="app.template.engine==='booking'?'calendar':'camera'"/>{{app.template.hero.action}}</button>
              </div>
            </article>
            <section class="ta-section ta-featured"><div class="ta-section-head"><h2>{{copy.featured}}</h2><button @click="go('works')">{{copy.all}} <AppIcon name="arrow" :size="17"/></button></div>
              <div v-if="app.portfolio.length" class="ta-work-strip"><button v-for="item in app.portfolio.slice(0,4)" :key="item.id" @click="go('works')"><BeforeAfterSlider v-if="item.before_image&&item.after_image" :before="item.before_image" :after="item.after_image" :before-label="copy.before" :after-label="copy.after" :alt="item.title"/><img v-else :src="item.image||item.after_image||item.before_image" :alt="item.title"><span>{{item.title}}</span></button></div>
              <div v-else class="ta-section-empty"><AppIcon name="image"/><p>{{copy.noActivity}}</p></div>
            </section>
            <section v-if="app.template.trust.length" class="ta-trust"><article v-for="item in app.template.trust" :key="item.label"><span><AppIcon :name="item.icon"/></span><p>{{item.label}}</p></article></section>
            <section class="ta-section ta-recent"><div class="ta-section-head"><h2>{{copy.recent}}</h2><button @click="go('works')">{{copy.all}} <AppIcon name="arrow" :size="17"/></button></div><div class="ta-work-grid"><article v-for="item in app.portfolio.slice(0,8)" :key="item.id"><img :src="item.image||item.after_image||item.before_image" :alt="item.title"><h3>{{item.title}}</h3><p>{{item.description}}</p></article></div></section>
          </section>

          <section v-else-if="screen==='services'" class="ta-page ta-brows-services-page">
            <header class="ta-simple-header"><button @click="go('home')"><AppIcon name="back"/>{{copy.back}}</button><h1>{{copy.servicesNav}}</h1><button class="ta-icon-button" @click="menuOpen=true"><AppIcon name="menu"/></button></header>
            <p class="ta-centered">{{app.template.hero.text}}</p>
            <div class="ta-brows-service-catalog"><article v-for="service in app.services" :key="service.id"><img v-if="service.image" :src="service.image" :alt="service.name"><div><h2>{{service.name}}</h2><p>{{service.description}}</p><span>{{service.duration}} {{copy.duration}}</span></div><button @click="go('book')">{{copy.bookNow}}<AppIcon name="arrow"/></button></article></div>
          </section>

          <section v-else-if="screen==='works'" class="ta-page ta-works-page">
            <header class="ta-page-header"><button @click="go('home')"><img :src="app.tenant.logo||'/brand/lookdo-mark.webp'" alt=""><span>{{app.tenant.name}}</span></button><div><a v-if="app.tenant.contact.vk_url" :href="app.tenant.contact.vk_url" target="_blank">VK</a><a v-if="app.tenant.contact.phone" :href="'tel:'+app.tenant.contact.phone"><AppIcon name="phone"/></a></div></header>
            <div class="ta-page-title"><h1>{{copy.works}}</h1><p>{{app.tenant.description}}</p></div>
            <div class="ta-filter-row"><button class="active">{{copy.all}}</button><button>{{copy.featured}}</button><button><AppIcon name="menu"/></button></div>
            <div v-if="app.portfolio.length" class="ta-portfolio-list"><article v-for="item in app.portfolio" :key="item.id"><BeforeAfterSlider v-if="item.before_image&&item.after_image" :before="item.before_image" :after="item.after_image" :before-label="copy.before" :after-label="copy.after" :alt="item.title"/><img v-else :src="item.image||item.after_image||item.before_image" :alt="item.title"><div><header><h2>{{item.title}}</h2><button><AppIcon name="heart"/></button></header><p>{{item.description}}</p></div></article></div>
            <div v-else class="ta-empty"><AppIcon name="works" :size="46"/><h2>{{copy.noActivity}}</h2></div>
          </section>

          <section v-else-if="screen==='activity'" class="ta-page ta-activity-page">
            <div class="ta-page-title"><small>{{app.tenant.name}}</small><h1>{{copy.activity}}</h1></div>
            <div v-if="activityLoading" class="ta-loading-line"></div>
            <div v-else-if="!activity.requests.length&&!activity.appointments.length" class="ta-empty"><span><AppIcon name="message" :size="42"/></span><h2>{{copy.noActivity}}</h2><p>{{copy.noActivityText}}</p><button class="ta-gold-button" @click="go(actionScreen)">{{app.template.hero.action}}</button></div>
            <template v-else>
              <div class="ta-activity-list"><button v-for="item in activity.requests" :key="'r'+item.id" @click="selectedRequest=item"><span class="ta-activity-icon"><AppIcon name="camera"/></span><span><b>#{{item.number}}</b><small>{{new Date(item.created_at).toLocaleString(locale,{dateStyle:'medium',timeStyle:'short'})}}</small><em>{{item.messages.at(-1)?.body}}</em></span><i>{{statusLabel(item.status)}}</i></button><article v-for="item in activity.appointments" :key="'a'+item.id" class="ta-appointment-card"><span class="ta-activity-icon"><AppIcon name="calendar"/></span><span><b>{{item.service?.name}}</b><small>{{new Date(item.starts_at).toLocaleString(locale,{dateStyle:'long',timeStyle:'short'})}}</small><em>#{{item.number}}</em></span><i>{{statusLabel(item.status)}}</i><footer v-if="!['cancelled','completed','no_show'].includes(item.status)"><button @click="rescheduleAppointment=item">{{copy.reschedule}}</button><button @click="cancelAppointment(item)">{{copy.cancelAppointment}}</button></footer></article></div>
              <div v-if="selectedRequest" class="ta-thread"><header><button @click="selectedRequest=null"><AppIcon name="back"/></button><span><b>#{{selectedRequest.number}}</b><small>{{copy.messages}}</small></span></header><div class="ta-thread-messages"><p v-for="item in selectedRequest.messages" :key="item.id" :class="item.sender"><span>{{item.body}}</span><small>{{new Date(item.created_at).toLocaleTimeString(locale,{hour:'2-digit',minute:'2-digit'})}}</small></p></div><form @submit.prevent="sendMessage"><input v-model="message" :placeholder="copy.messagePlaceholder"><button :disabled="sending||!message.trim()"><AppIcon name="send"/></button></form></div>
            </template>
          </section>

          <section v-else-if="screen==='reviews'" class="ta-page ta-reviews-page">
            <header class="ta-simple-header"><button @click="go('home')"><AppIcon name="back"/>{{copy.back}}</button><h1>{{copy.reviews}}</h1><div class="ta-contact-shortcuts"><a v-if="app.tenant.contact.phone" :href="'tel:'+app.tenant.contact.phone"><AppIcon name="phone"/></a></div></header>
            <p class="ta-centered">{{copy.reviewsSubtitle}}</p>
            <article class="ta-rating-summary"><div><strong>{{averageRating}}</strong><span>★★★★★</span><small>{{copy.basedOn}} {{app.reviews.length}}</small></div><div><p v-for="n in [5,4,3,2,1]" :key="n"><b>{{n}} ★</b><i><span :style="{width:(app.reviews.length?app.reviews.filter((r:any)=>Math.round(r.rating)===n).length/app.reviews.length*100:0)+'%'}"></span></i><em>{{app.reviews.filter((r:any)=>Math.round(r.rating)===n).length}}</em></p></div></article>
            <div class="ta-review-list"><article v-for="review in app.reviews" :key="review.id"><header><img :src="'/brand/lookdo-mark.webp'" alt=""><div><h2>{{review.author||app.tenant.name}}</h2><span>{{'★'.repeat(Math.round(review.rating))}}</span></div><time>{{review.received_at?new Date(review.received_at).toLocaleDateString(locale):''}}</time></header><p>{{review.body}}</p></article></div>
            <button class="ta-outline-button">{{copy.leaveReview}}</button>
          </section>

          <section v-else-if="screen==='contacts'" class="ta-page ta-contact-background">
            <div class="ta-contacts-sheet"><header><h1>{{copy.contacts}}</h1><button @click="go('home')"><AppIcon name="close"/></button></header><p>{{copy.contactText}}</p>
              <a v-if="app.tenant.contact.phone" :href="'tel:'+app.tenant.contact.phone"><span><AppIcon name="phone"/></span><div><b>{{copy.call}} {{contactName}}</b><em>{{app.tenant.contact.phone}}</em></div><AppIcon name="arrow"/></a>
              <a v-if="app.tenant.contact.vk_url" :href="app.tenant.contact.vk_url" target="_blank"><span><b>VK</b></span><div><b>{{copy.socialContact}}</b><em>{{app.tenant.contact.vk_url}}</em></div><AppIcon name="arrow"/></a>
              <a v-if="address" :href="'https://www.google.com/maps/search/?api=1&query='+encodeURIComponent(address)" target="_blank"><span><AppIcon name="map"/></span><div><b>{{copy.workshopAddress}}</b><em>{{address}}</em></div><AppIcon name="arrow"/></a>
              <article v-if="app.tenant.contact.working_hours"><AppIcon name="clock"/><div><b>{{copy.workingHours}}</b><p>{{app.tenant.contact.working_hours}}</p></div></article>
              <button class="ta-outline-button" @click="go('home')">{{copy.back}}</button>
            </div>
          </section>
          <section v-else class="ta-page ta-empty"><h1>404</h1><button class="ta-gold-button" @click="go('home')">{{copy.home}}</button></section>
        </div>

        <nav class="ta-bottom-nav" :aria-label="copy.navigation"><button v-for="item in navItems" :key="item.key" :class="{active:screen===item.key,central:item.central}" @click="go(item.key)"><span><AppIcon :name="item.icon"/></span><small>{{item.label}}</small></button></nav>
        <div v-if="menuOpen" class="ta-menu-overlay" @click.self="menuOpen=false"><aside><header><img :src="app.tenant.logo||'/brand/lookdo-mark.webp'" alt=""><div><b>{{app.tenant.name}}</b><small>{{app.template.name}}</small></div><button @click="menuOpen=false"><AppIcon name="close"/></button></header><nav><button v-if="isBrows" @click="go('activity')"><AppIcon name="calendar"/>{{copy.appointments}}<AppIcon name="arrow"/></button><button @click="go('contacts')"><AppIcon name="phone"/>{{copy.contacts}}<AppIcon name="arrow"/></button><button @click="go('reviews')"><AppIcon name="star"/>{{copy.reviews}}<AppIcon name="arrow"/></button><button @click="share"><AppIcon name="share"/>{{copy.share}}<AppIcon name="arrow"/></button><button @click="go('login')"><AppIcon name="shield"/>{{copy.login}}<AppIcon name="arrow"/></button></nav><label>{{copy.language}}<select :value="locale" @change="changeLocale(($event.target as HTMLSelectElement).value)"><option value="de">Deutsch</option><option value="en">English</option><option value="ru">Русский</option><option value="uk">Українська</option></select></label><small>{{copy.powered}}</small></aside></div>
        <div v-if="pushPrompt" class="ta-menu-overlay ta-push-overlay" @click.self="dismissPush"><aside><div class="ta-bell-orbit"><AppIcon name="bell" :size="80"/><b>1</b></div><h2>{{copy.notificationHeadline}}</h2><p>{{copy.notificationText}}</p><p v-if="pushStatus" class="ta-notification-status">{{pushStatus}}</p><button class="ta-gold-button" :disabled="pushBusy" @click="enablePush"><AppIcon name="bell"/>{{pushBusy?copy.sending:copy.notifications}}</button><button class="ta-outline-button" @click="dismissPush">{{copy.later}}</button></aside></div>
      </template>
    </main>
    <aside class="ta-desktop-aside"><img :src="app.tenant.logo||'/brand/lookdo-mark.webp'" alt=""><small>{{app.tenant.name}}</small><h2>{{copy.desktopTitle}}</h2><p>{{copy.desktopText}}</p><div><span><AppIcon name="camera"/></span><span><AppIcon name="message"/></span><span><AppIcon name="calendar"/></span></div><button @click="share"><AppIcon name="share"/>{{copy.share}}</button><em>{{copy.powered}}</em></aside>
  </div>
</div>
</template>
