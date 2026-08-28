<script setup lang="ts">
import {computed,reactive,ref} from 'vue';
import {api} from '../api';
import LineIcon from '../components/LineIcon.vue';

const props=defineProps<{tenantId:number;account:any;locale:string;t:(key:string)=>string;onboarding?:boolean}>();
const emit=defineEmits<{reload:[];complete:[]}>();
const existing=props.account?.tenant?.profile?.branding||{};
const form=reactive({
  business_description:props.account?.tenant?.business_description||'',
  services:existing.services||'',
  customers:existing.customers||'',
  style:existing.style||'',
  avoid:existing.avoid||'',
  tagline:existing.tagline||'',
  vk_url:existing.vk_url||'',
  working_hours:existing.working_hours||'',
});
const busy=ref(false),error=ref(''),success=ref('');
const logoUrl=ref(props.account?.tenant?.profile?.logo_url||'');
const heroUrl=ref(props.account?.tenant?.profile?.hero_image_url||'');
const promptOpen=ref(false),prompt=ref(''),promptAsset=ref<'logo'|'hero'>('hero');
const canConfirm=computed(()=>Boolean(form.business_description.trim()&&logoUrl.value&&heroUrl.value));

async function save(confirmed?:boolean){
  busy.value=true;error.value='';success.value='';
  try{
    const payload:any={...form};
    if(typeof confirmed==='boolean')payload.confirmed=confirmed;
    await api(`/tenant/${props.tenantId}/branding`,{method:'PUT',body:JSON.stringify(payload)});
    success.value=props.t('saved');
    emit('reload');
    if(confirmed)emit('complete');
  }catch(e:any){error.value=e.message}finally{busy.value=false}
}
async function upload(asset:'logo'|'hero',event:Event){
  const input=event.target as HTMLInputElement,file=input.files?.[0];if(!file)return;
  busy.value=true;error.value='';success.value='';
  try{
    const body=new FormData();body.append('asset',asset);body.append('image',file);
    const result:any=await api(`/tenant/${props.tenantId}/branding/assets`,{method:'POST',body});
    if(asset==='logo')logoUrl.value=result.url;else heroUrl.value=result.url;
    success.value=props.t('uploaded');emit('reload');
  }catch(e:any){error.value=e.message}finally{busy.value=false;input.value=''}
}
async function prepare(asset:'logo'|'hero'){
  busy.value=true;error.value='';success.value='';
  try{
    await api(`/tenant/${props.tenantId}/branding`,{method:'PUT',body:JSON.stringify(form)});
    const result:any=await api(`/tenant/${props.tenantId}/branding/prompt`,{method:'POST',body:JSON.stringify({asset})});
    promptAsset.value=asset;prompt.value=result.prompt;promptOpen.value=true;
  }catch(e:any){error.value=e.message}finally{busy.value=false}
}
async function generate(){
  busy.value=true;error.value='';
  try{
    const result:any=await api(`/tenant/${props.tenantId}/branding/generate`,{method:'POST',body:JSON.stringify({asset:promptAsset.value,prompt:prompt.value})});
    if(promptAsset.value==='logo')logoUrl.value=result.url;else heroUrl.value=result.url;
    promptOpen.value=false;success.value=props.t('generated');emit('reload');
  }catch(e:any){error.value=e.message}finally{busy.value=false}
}
</script>

<template>
<section class="mw-stack mw-branding">
  <header class="mw-page-head"><div><p class="mw-kicker">LOOKDO APP</p><h1>{{t('branding')}}</h1><p>{{t(onboarding?'brandingOnboarding':'brandingIntro')}}</p></div></header>
  <p v-if="error" class="mw-error">{{error}}</p><p v-if="success" class="mw-success">{{success}}</p>
  <div class="mw-branding-layout">
    <form class="mw-panel mw-branding-form" @submit.prevent="save()">
      <h2>{{t('questionnaire')}}</h2>
      <label>{{t('businessDescription')}}<textarea v-model="form.business_description" rows="4" required></textarea></label>
      <label>{{t('services')}}<textarea v-model="form.services" rows="3"></textarea></label>
      <label>{{t('customers')}}<textarea v-model="form.customers" rows="3"></textarea></label>
      <label>{{t('visualStyle')}}<input v-model="form.style"></label>
      <label>{{t('avoid')}}<input v-model="form.avoid"></label>
      <label>{{t('tagline')}}<input v-model="form.tagline"></label>
      <div class="mw-field-pair"><label>VK<input v-model="form.vk_url" type="url"></label><label>{{t('workingHours')}}<input v-model="form.working_hours"></label></div>
      <button class="mw-secondary" :disabled="busy">{{t('saveDraft')}}</button>
    </form>
    <div class="mw-stack">
      <article class="mw-panel mw-brand-assets">
        <h2>{{t('logo')}}</h2>
        <div class="mw-logo-preview"><img v-if="logoUrl" :src="logoUrl" alt=""><LineIcon v-else name="photo"/></div>
        <div class="mw-asset-actions"><label class="mw-secondary">{{t('upload')}}<input hidden type="file" accept="image/jpeg,image/png,image/webp" @change="upload('logo',$event)"></label><button class="mw-primary" :disabled="busy" @click="prepare('logo')">{{t('generateAi')}}</button></div>
      </article>
      <article class="mw-panel mw-brand-assets">
        <h2>{{t('heroImage')}}</h2>
        <div class="mw-hero-preview"><img v-if="heroUrl" :src="heroUrl" alt=""><LineIcon v-else name="photo"/></div>
        <div class="mw-asset-actions"><label class="mw-secondary">{{t('upload')}}<input hidden type="file" accept="image/jpeg,image/png,image/webp" @change="upload('hero',$event)"></label><button class="mw-primary" :disabled="busy" @click="prepare('hero')">{{t('generateAi')}}</button></div>
      </article>
    </div>
  </div>
  <article class="mw-brand-preview" :style="heroUrl?{backgroundImage:`linear-gradient(180deg,transparent,#050607 88%),url('${heroUrl}')`}:{}">
    <header><img v-if="logoUrl" :src="logoUrl" alt=""><b>{{account.tenant.name}}</b></header>
    <div><small>{{t('preview')}}</small><h2>{{form.tagline||account.tenant.name}}</h2><p>{{form.business_description}}</p></div>
  </article>
  <p v-if="!canConfirm" class="mw-warning">{{t('brandingRequired')}}</p>
  <button v-if="onboarding" class="mw-primary mw-confirm-branding" :disabled="busy||!canConfirm" @click="save(true)">{{t('looksGood')}}</button>

  <div v-if="promptOpen" class="mw-brand-modal" @click.self="promptOpen=false">
    <form class="mw-panel" @submit.prevent="generate">
      <header><div><p class="mw-kicker">{{t('generateAi')}}</p><h2>{{t('checkPrompt')}}</h2></div><button type="button" @click="promptOpen=false">×</button></header>
      <textarea v-model="prompt" rows="12" required minlength="40"></textarea>
      <p>{{t('promptWarning')}}</p>
      <div><button type="button" class="mw-secondary" @click="promptOpen=false">{{t('close')}}</button><button class="mw-primary" :disabled="busy">{{busy?t('generating'):t('generate')}}</button></div>
    </form>
  </div>
</section>
</template>
