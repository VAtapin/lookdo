<script setup lang="ts">
import {computed,onMounted,ref} from 'vue';
import {useRoute,useRouter} from 'vue-router';
import {api} from '../api';
import {setLocale} from '../i18n';
import LineIcon from '../components/LineIcon.vue';
import MasterToday from '../tenant-master/MasterToday.vue';
import MasterRequests from '../tenant-master/MasterRequests.vue';
import MasterCalendar from '../tenant-master/MasterCalendar.vue';
import MasterCustomers from '../tenant-master/MasterCustomers.vue';
import MasterWork from '../tenant-master/MasterWork.vue';
import MasterAccount from '../tenant-master/MasterAccount.vue';
import MasterBranding from '../tenant-master/MasterBranding.vue';
import {masterText} from '../tenant-master/copy';

const route=useRoute(),router=useRouter();
const me=ref<any>(null),account=ref<any>(null),workspace=ref<any>(null),plans=ref<any[]>([]);
const error=ref(''),loading=ref(true);
const section=computed(()=>String(route.params.section||'today'));
const tenantId=computed(()=>me.value?.tenants?.[0]?.id);
const locale=computed(()=>String(account.value?.tenant?.locale||workspace.value?.tenant?.locale||'de'));
const brandingRequired=computed(()=>Boolean(account.value&&!account.value?.tenant?.profile?.branding?.confirmed_at));
const t=(key:string)=>masterText(locale.value,key);
const desktopNav=computed(()=>[['today','home'],['requests','briefcase'],['calendar','calendar'],['messages','chat'],['customers','user'],['work','photo'],['services','tools'],['more','grid']]);
const mobileNav=computed(()=>[['today','home'],['requests','briefcase'],['calendar','calendar'],['messages','chat'],['more','grid']]);
const accountSections=['business','branding','billing','domain','team','settings'];
const mobileMoreSections=['customers','work','services',...accountSections];

async function load(){
  loading.value=true;error.value='';
  try{
    me.value=await api('/me');
    if(!me.value.user)return router.push('/login');
    if(me.value.user.is_super_admin&&!me.value.impersonating)return router.push('/control');
    if(!tenantId.value)throw new Error('No tenant');
    [account.value,workspace.value]=await Promise.all([api(`/tenant/${tenantId.value}`),api(`/tenant/${tenantId.value}/workspace`)]);
    setLocale(account.value.tenant.locale);
    const platform:any=await api('/platform');plans.value=platform.plans||[];
  }catch(e:any){
    if(String(e.message).includes('Unauthenticated'))router.push('/login');else error.value=e.message;
  }finally{loading.value=false}
}
function go(target:string){router.push(`/app/${target}`)}
async function logout(){await api('/logout',{method:'POST'});router.push('/login')}
async function stop(){await api('/impersonation/stop',{method:'POST'});location.href='/control/tenants'}
onMounted(load);
</script>

<template>
<div class="master-shell">
  <aside class="master-sidebar">
    <div class="master-brand"><img :src="'/brand/lookdo-mark.webp'" alt="LOOKDO"><span>LOOKDO<small>MASTER</small></span></div>
    <div v-if="workspace" class="master-business"><span>{{workspace.tenant.name.slice(0,2).toUpperCase()}}</span><div><b>{{workspace.tenant.name}}</b><small>{{workspace.tenant.slug}}.lookdo.app</small></div></div>
    <nav>
      <RouterLink v-for="item in desktopNav" :key="item[0]" :to="`/app/${item[0]}`" :class="{active:section===item[0]||(item[0]==='more'&&accountSections.includes(section))}">
        <LineIcon :name="item[1]"/><span>{{t(item[0]==='work'?'works':item[0])}}</span>
        <em v-if="item[0]==='requests'&&workspace?.counts.new_requests">{{workspace.counts.new_requests}}</em>
        <em v-if="item[0]==='messages'&&workspace?.counts.messages">{{workspace.counts.messages}}</em>
      </RouterLink>
    </nav>
    <button v-if="me?.impersonating" class="master-support" @click="stop">← Control</button>
    <button class="master-logout" @click="logout">{{t('logout')}}</button>
  </aside>
  <main class="master-main">
    <header class="master-mobile-head"><div class="master-brand"><img :src="'/brand/lookdo-mark.webp'" alt="LOOKDO"><span>{{workspace?.tenant.name}}</span></div><a v-if="workspace" :href="workspace.tenant.platform_url" target="_blank"><LineIcon name="external"/></a></header>
    <div v-if="loading" class="mw-loading">{{t('loading')}}</div>
    <div v-else-if="error" class="mw-error">{{error}}</div>
    <template v-else-if="workspace&&account">
      <MasterBranding v-if="brandingRequired||section==='branding'" :tenant-id="tenantId" :account="account" :locale="locale" :t="t" :onboarding="brandingRequired" @reload="load" @complete="load"/>
      <MasterToday v-else-if="section==='today'" :data="workspace" :t="t" @navigate="go"/>
      <MasterRequests v-else-if="section==='requests'||section==='messages'" :tenant-id="tenantId" :mode="section as 'requests'|'messages'" :locale="locale" :entitlements="workspace.access.entitlements" :t="t"/>
      <MasterCalendar v-else-if="section==='calendar'||section==='services'" :key="section" :tenant-id="tenantId" :locale="locale" :initial-tab="section==='services'?'services':'calendar'" :t="t"/>
      <MasterCustomers v-else-if="section==='customers'" :tenant-id="tenantId" :locale="locale" :t="t"/>
      <MasterWork v-else-if="section==='work'" :tenant-id="tenantId" :locale="locale" :t="t"/>
      <MasterAccount v-else :tenant-id="tenantId" :account="account" :workspace="workspace" :plans="plans" :section="section" :locale="locale" :t="t" @reload="load" @navigate="go"/>
    </template>
  </main>
  <nav class="master-bottom-nav">
    <RouterLink v-for="item in mobileNav" :key="item[0]" :to="`/app/${item[0]}`" :class="{active:section===item[0]||(item[0]==='more'&&mobileMoreSections.includes(section))}">
      <span><LineIcon :name="item[1]"/><em v-if="item[0]==='requests'&&workspace?.counts.new_requests">{{workspace.counts.new_requests}}</em><em v-if="item[0]==='messages'&&workspace?.counts.messages">{{workspace.counts.messages}}</em></span>
      <small>{{t(item[0])}}</small>
    </RouterLink>
  </nav>
</div>
</template>
