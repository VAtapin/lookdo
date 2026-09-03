<script setup lang="ts">
import { computed, onBeforeUnmount, reactive, ref } from 'vue';
import { api } from '../api';
import AppIcon from './AppIcon.vue';

type Stage = 'capture'|'details'|'success'|'notifications';
type MediaItem = { file:File; slot:string; url:string };

const props = defineProps<{ app:any; copy:any; locale:string; token:string }>();
const emit = defineEmits<{ close:[]; success:[payload:any] }>();
const stage=ref<Stage>('capture');
const files=ref<MediaItem[]>([]);
const activeIndex=ref(0);
const fileInput=ref<HTMLInputElement|null>(null);
const busy=ref(false);
const error=ref('');
const result=ref<any>(null);
const notifying=ref(false);
const notificationStatus=ref('');
const assisting=ref(false);
const assistancePending=ref(false);
const assistantText=ref('');
const aiAssistantOpen=ref(false);
const isbnAbsent=ref(false);
const isbnStatus=ref<'idle'|'analyzing'|'found'|'unreadable'|'not_found'|'absent'>('idle');
let isbnLookupTimer:number|undefined;
const form=reactive<any>({name:'',phone:'',email:'',summary:'',preferred_channel:'push',fields:{}});

const configuredSlots=computed<any[]>(()=>props.app.template.media_slots||[]);
const photosMax=computed(()=>Math.max(configuredSlots.value.length,Number(props.app.template.media?.photos_max||4)));
const visibleSlotCount=computed(()=>Math.min(photosMax.value,Math.max(1,configuredSlots.value.length,files.value.length+1)));
const slots=computed(()=>Array.from({length:visibleSlotCount.value},(_,index)=>configuredSlots.value[index]||{key:`photo_${index+1}`,required:false}));
const current=computed(()=>files.value[activeIndex.value]||files.value.at(-1)||null);
const requestTitle=computed(()=>props.app.template.hero?.title||props.copy.requestTitle);
const requestHint=computed(()=>props.app.template.hero?.subtitle||props.copy.requestHint);
const detailsTitle=computed(()=>props.app.template.hero?.action||props.copy.details);
const canNotify=computed(()=>Boolean(props.app.push?.enabled&&props.app.push?.public_key&&'Notification' in window&&'serviceWorker' in navigator));
const address=computed(()=>[props.app.tenant.contact.street,[props.app.tenant.contact.postal_code,props.app.tenant.contact.city].filter(Boolean).join(' ')].filter(Boolean).join(', '));
const contactName=computed(()=>props.app.tenant.contact.name||props.app.tenant.name);
const fields=computed<any[]>(()=>props.app.template.fields||[]);
const isBookPurchase=computed(()=>props.app.template.variation_code==='purchase.books'||fields.value.some(item=>item.key==='isbn'));
const vehicleBrandField=computed(()=>fields.value.find(item=>item.key==='vehicle_brand'));
const vehicleModelField=computed(()=>fields.value.find(item=>item.key==='vehicle_model'));
const vehicleYearField=computed(()=>fields.value.find(item=>item.key==='vehicle_year'));
const extraFields=computed(()=>fields.value.filter(item=>!['phone','vehicle_brand','vehicle_model','vehicle_year'].includes(item.key)));
const aiAssistant=computed(()=>props.app.template.ai_assistant||{});
const missingRequired=computed(()=>slots.value.filter(slot=>slot.required&&!(isBookPurchase.value&&isbnAbsent.value&&slot.key==='book_isbn')&&!files.value.some(item=>item.slot===slot.key)));
const progress=computed(()=>stage.value==='capture'?1:stage.value==='details'?2:stage.value==='success'?3:4);

function nextSlotIndex(){const index=slots.value.findIndex(slot=>!(isBookPurchase.value&&isbnAbsent.value&&slot.key==='book_isbn')&&!files.value.some(item=>item.slot===slot.key));return index<0?Math.max(0,slots.value.length-1):index;}

function back(){
  if(stage.value==='details')stage.value='capture';
  else if(stage.value==='notifications')stage.value='success';
  else emit('close');
}
function choose(index:number){activeIndex.value=index;fileInput.value?.click();}
function selected(event:Event){
  const input=event.target as HTMLInputElement; const file=input.files?.[0]; if(!file)return;
  const slot=slots.value[activeIndex.value]?.key||'overall';
  const existing=files.value.find(item=>item.slot===slot); if(existing)URL.revokeObjectURL(existing.url);
  files.value=files.value.filter(item=>item.slot!==slot);
  files.value.push({file,slot,url:URL.createObjectURL(file)});
  activeIndex.value=nextSlotIndex(); input.value='';
  if(isBookPurchase.value&&(isbnAbsent.value||slot==='book_isbn'||files.value.some(item=>item.slot==='book_isbn')))window.setTimeout(()=>void assistForm(true),0);
}
function remove(item:MediaItem){URL.revokeObjectURL(item.url);files.value=files.value.filter(value=>value!==item);activeIndex.value=0;}
function slotItem(index:number){const slot=slots.value[index]?.key;return files.value.find(item=>item.slot===slot);}
function fieldLabel(field:any){return field.label||field.key;}
function applicationServerKey(value:string):Uint8Array{
  const padding='='.repeat((4-value.length%4)%4);const raw=atob((value+padding).replace(/-/g,'+').replace(/_/g,'/'));
  return Uint8Array.from([...raw].map(char=>char.charCodeAt(0)));
}
async function enableNotifications(){
  if(!canNotify.value||!result.value?.token){notificationStatus.value=props.copy.notificationDenied;return;}
  notifying.value=true;notificationStatus.value='';
  try{
    const permission=await Notification.requestPermission();
    if(permission!=='granted'){notificationStatus.value=props.copy.notificationDenied;return;}
    const registration=await navigator.serviceWorker.ready;
    let subscription=await registration.pushManager.getSubscription();
    if(!subscription)subscription=await registration.pushManager.subscribe({userVisibleOnly:true,applicationServerKey:applicationServerKey(props.app.push.public_key) as BufferSource});
    const value=subscription.toJSON();
    await api('/tenant-app/push-subscriptions',{method:'POST',headers:{'X-Lookdo-Client-Token':result.value.token},body:JSON.stringify({endpoint:value.endpoint,keys:value.keys})});
    notificationStatus.value=props.copy.notificationEnabled;
    window.setTimeout(()=>emit('close'),700);
  }catch(e:any){
    notificationStatus.value=/applicationServerKey|P-256|public key/i.test(String(e?.message||''))?props.copy.notificationConfigurationError:props.copy.notificationDenied;
  }finally{notifying.value=false;}
}
function normalizeIsbn(value:string){return String(value||'').toUpperCase().replace(/[^0-9X]/g,'');}
function validIsbn(value:string){
  const isbn=normalizeIsbn(value);
  if(isbn.length===10){let sum=0;for(let index=0;index<10;index++){const digit=isbn[index]==='X'&&index===9?10:Number(isbn[index]);if(!Number.isFinite(digit))return false;sum+=digit*(10-index);}return sum%11===0;}
  if(isbn.length===13&&/^\d+$/.test(isbn)){let sum=0;for(let index=0;index<13;index++)sum+=Number(isbn[index])*(index%2===0?1:3);return sum%10===0;}
  return false;
}
function isbnChanged(){
  form.fields.isbn=normalizeIsbn(form.fields.isbn||'');
  if(isbnLookupTimer)window.clearTimeout(isbnLookupTimer);
  const valid=validIsbn(form.fields.isbn);
  isbnStatus.value=valid?'analyzing':'unreadable';
  if(valid&&files.value.length)isbnLookupTimer=window.setTimeout(()=>void assistForm(true),450);
}
function toggleIsbnAbsent(){
  if(isbnAbsent.value){form.fields.isbn='';isbnStatus.value='absent';}
  else isbnStatus.value='idle';
  if(files.value.length)void assistForm(true);
}
async function assistForm(automatic=false){
  if(!assistantText.value.trim()&&!files.value.length)return;
  if(assisting.value){if(automatic)assistancePending.value=true;return;}
  assisting.value=true;error.value='';
  if(isBookPurchase.value)isbnStatus.value='analyzing';
  try{
    const body=new FormData();body.append('text',assistantText.value.trim());
    if(aiAssistant.value.accepts_media!==false)files.value.slice(0,6).forEach(item=>body.append('media[]',item.file));
    body.append('media_slots',JSON.stringify(files.value.slice(0,6).map(item=>item.slot)));
    body.append('current_fields',JSON.stringify(Object.fromEntries(fields.value.map(field=>[field.key,form.fields[field.key]??'']))));
    body.append('isbn_absent',isbnAbsent.value?'1':'0');
    const response=await api('/tenant-app/request-assistance',{method:'POST',body});
    const values=response.values||{};
    if(values.summary)form.summary=values.summary;
    for(const field of fields.value)if(field.key!=='phone'&&values[field.key])form.fields[field.key]=values[field.key];
    if(isBookPurchase.value){
      isbnStatus.value=response.book?.isbn_status||'unreadable';
      form.fields._ai_assessment=values._ai_assessment||'';
      form.fields._book_catalog=values._book_catalog||{};
      form.fields._recommended_purchase_price=values._recommended_purchase_price||'';
      form.fields._book_condition_grade=values._book_condition_grade||'unknown';
      form.fields._book_price_anchored=Boolean(values._book_price_anchored);
    }
  }catch(e:any){error.value=props.copy.aiAssistError;isbnStatus.value='idle';}finally{
    assisting.value=false;
    if(assistancePending.value){assistancePending.value=false;window.setTimeout(()=>void assistForm(true),0);}
  }
}
async function submit(){
  if(!form.phone.trim()){error.value=props.copy.phone;return;}
  if(isBookPurchase.value&&!isbnAbsent.value&&!validIsbn(form.fields.isbn||'')){error.value=props.copy.isbnUnreadable;isbnStatus.value='unreadable';return;}
  if(missingRequired.value.length){stage.value='capture';error.value=missingRequired.value.map(slot=>slot.title||slot.label||slot.key).join(', ');return;}
  if(!files.value.length){stage.value='capture';error.value=requestHint.value;return;}
  busy.value=true;error.value='';
  try{
    const body=new FormData();
    for(const key of ['name','phone','email','summary','preferred_channel'])body.append(key,form[key]||'');
    body.append('fields',JSON.stringify(form.fields));
    body.append('media_slots',JSON.stringify(files.value.map(item=>item.slot)));
    body.append('isbn_absent',isbnAbsent.value?'1':'0');
    files.value.forEach(item=>body.append('media[]',item.file));
    result.value=await api('/tenant-app/requests',{method:'POST',body,headers:props.token?{'X-Lookdo-Client-Token':props.token}:{}});
    stage.value='success';emit('success',result.value);
  }catch(e:any){error.value=e.message;}finally{busy.value=false;}
}
function continueToDetails(){if(missingRequired.value.length){error.value=missingRequired.value.map(slot=>slot.title||slot.label||slot.key).join(', ');return;}error.value='';stage.value='details';}
async function shareRequest(){
  const data={title:props.copy.sent,text:(result.value?.request?.number?props.copy.requestNumber+' '+result.value.request.number:'')+' — '+props.app.tenant.name,url:location.origin+'/activity'};
  if(navigator.share)await navigator.share(data);else await navigator.clipboard.writeText(data.url);
}
function finishSuccess(){
  if(canNotify.value){
    stage.value='notifications';
    return;
  }
  emit('close');
}
onBeforeUnmount(()=>{files.value.forEach(item=>URL.revokeObjectURL(item.url));if(isbnLookupTimer)window.clearTimeout(isbnLookupTimer);});
</script>

<template>
  <section class="ta-flow ta-dark-flow">
    <template v-if="stage==='capture'">
      <header class="ta-flow-title">
        <button class="ta-back" @click="back"><AppIcon name="back"/><span>{{copy.back}}</span></button>
        <div><h1>{{requestTitle}}</h1><p>{{copy.requestSubtitle}}</p></div>
        <div class="ta-contact-shortcuts"><a v-if="app.tenant.contact.phone" :href="'tel:'+app.tenant.contact.phone"><AppIcon name="phone"/></a><a v-if="app.tenant.contact.vk_url" :href="app.tenant.contact.vk_url" target="_blank">VK</a></div>
      </header>
      <div class="ta-flow-steps"><span v-for="number in 4" :key="number" :class="{active:number===1}">{{number}}</span></div>
      <div class="ta-flow-scroll">
        <article class="ta-capture-instructions">
          <span class="ta-round-icon"><AppIcon name="camera" :size="36"/></span>
          <div><h2>{{app.template.hero?.title||copy.captureTitle}}</h2><p>{{app.template.hero?.subtitle||copy.captureList}}</p></div>
          <div class="ta-wheel-map" :class="{'is-steering':app.template.layout==='steering'}"><AppIcon :name="app.template.layout==='steering'?'steering':'image'" :size="64"/><b v-for="(_,index) in slots.slice(0,4)" :key="index">{{index+1}}</b></div>
        </article>
        <button class="ta-live-camera" @click="choose(nextSlotIndex())">
          <img v-if="current" :src="current.url" alt="">
          <div v-else><AppIcon name="camera" :size="62"/><strong>{{copy.camera}}</strong><span>{{requestHint}}</span></div>
          <small>1×</small>
        </button>
        <p class="ta-camera-hint">{{requestHint}}</p>
        <div class="ta-camera-controls">
          <div><img v-if="files.length" :src="files[files.length-1].url" alt=""><AppIcon v-else name="image"/><small>{{copy.lastPhoto}}</small></div>
          <button @click="choose(nextSlotIndex())"><AppIcon name="camera" :size="34"/></button>
          <div><AppIcon name="rotate"/><small>{{copy.turnCamera}}</small></div>
        </div>
        <h2 class="ta-added-title">{{copy.addedPhotos}} ({{files.length}} / {{photosMax}})</h2>
        <div class="ta-photo-slots" :style="{'--slot-count':Math.min(slots.length,4)}">
          <button v-for="(_,index) in slots" :key="index" :class="{filled:slotItem(index)}" @click="choose(index)">
            <img v-if="slotItem(index)" :src="slotItem(index)?.url" alt="">
            <template v-else><AppIcon name="plus"/><span>{{slots[index]?.title||copy.addPhoto}}<em v-if="slots[index]?.required">*</em></span></template>
            <i v-if="slotItem(index)" @click.stop="remove(slotItem(index)!)"><AppIcon name="close" :size="14"/></i>
          </button>
        </div>
        <label v-if="isBookPurchase" class="ta-isbn-absent"><input v-model="isbnAbsent" type="checkbox" @change="toggleIsbnAbsent"><span><b>{{copy.noIsbn}}</b><small>{{copy.noIsbnHint}}</small></span></label>
        <p v-if="isBookPurchase&&isbnStatus!=='idle'" class="ta-book-analysis" :class="isbnStatus"><span></span>{{isbnStatus==='analyzing'?copy.bookAnalyzing:isbnStatus==='found'?copy.bookFound:isbnStatus==='not_found'?copy.bookNotFound:isbnStatus==='absent'?copy.noIsbnSelected:copy.isbnUnreadable}}</p>
        <p v-if="error" class="ta-error">{{error}}</p>
        <button class="ta-gold-button" :disabled="!files.length" @click="continueToDetails">{{copy.usePhoto}}</button>
        <button class="ta-outline-button ta-add-photo-button" :disabled="files.length>=photosMax" @click="choose(nextSlotIndex())"><AppIcon name="plus"/>{{copy.addPhoto}}</button>
      </div>
      <input ref="fileInput" hidden type="file" accept="image/*" capture="environment" @change="selected">
    </template>

    <template v-else-if="stage==='details'">
      <header class="ta-flow-title compact">
        <button class="ta-back icon-only" @click="back"><AppIcon name="back"/></button>
        <div><h1>{{detailsTitle}}</h1><p>{{copy.detailsSubtitle}}</p></div>
        <button class="ta-help"><AppIcon name="info"/><span>{{copy.how}}</span></button>
      </header>
      <div class="ta-flow-scroll ta-detail-form">
        <button class="ta-ai-assistant-toggle" :class="{active:aiAssistantOpen}" @click="aiAssistantOpen=!aiAssistantOpen"><AppIcon name="star" :size="18"/>{{copy.aiAssistShort}}</button>
        <section v-if="aiAssistantOpen" class="ta-dark-card ta-ai-assistant">
          <header><h2><AppIcon name="star"/> {{copy.aiAssistTitle}}</h2><button :aria-label="copy.close" @click="aiAssistantOpen=false"><AppIcon name="close" :size="18"/></button></header>
          <p>{{aiAssistant.text||copy.aiAssistText}}</p>
          <textarea v-model="assistantText" rows="3" maxlength="2000" :placeholder="copy.aiAssistPlaceholder"></textarea>
          <small v-if="aiAssistant.accepts_media!==false&&files.length">{{files.length}} {{copy.photos.toLowerCase()}}</small>
          <button class="ta-outline-button" :disabled="assisting||(!assistantText.trim()&&!files.length)" @click="assistForm(false)">{{assisting?copy.aiAssisting:(aiAssistant.title||copy.aiAssistButton)}}</button>
        </section>
        <section class="ta-dark-card">
          <h2>1. {{copy.photos}} <em>*</em></h2><p>{{requestHint}}</p>
          <div class="ta-detail-photos"><button v-for="(_,index) in slots" :key="index" @click="choose(index)"><img v-if="slotItem(index)" :src="slotItem(index)?.url" alt=""><template v-else><AppIcon name="camera"/><span>{{copy.addPhoto}}</span></template></button></div>
        </section>
        <section v-if="vehicleBrandField||vehicleModelField||vehicleYearField" class="ta-dark-card">
          <h2>2. {{copy.vehicle}}</h2>
          <div class="ta-field-grid">
            <label v-if="vehicleBrandField"><span>{{fieldLabel(vehicleBrandField)}}</span><input v-model="form.fields.vehicle_brand" :placeholder="vehicleBrandField.placeholder"></label>
            <label v-if="vehicleModelField"><span>{{copy.vehicleModel}}</span><input v-model="form.fields.vehicle_model" :placeholder="vehicleModelField.placeholder"></label>
            <label v-if="vehicleYearField"><span>{{copy.vehicleYear}}</span><input v-model="form.fields.vehicle_year" inputmode="numeric" :placeholder="vehicleYearField.placeholder"></label>
          </div>
        </section>
        <section class="ta-dark-card">
          <h2>3. {{copy.whatToDo}}</h2>
          <textarea v-model="form.summary" rows="4" maxlength="500" :placeholder="copy.summary"></textarea>
          <div v-for="field in extraFields" :key="field.key" class="ta-extra-field" :class="{'ta-isbn-field':field.key==='isbn'}">
            <label><span>{{fieldLabel(field)}}</span>
              <select v-if="field.type==='select'" v-model="form.fields[field.key]"><option value="">—</option><option v-for="option in field.options" :key="option" :value="option">{{option}}</option></select>
              <textarea v-else-if="field.type==='textarea'" v-model="form.fields[field.key]" rows="3"></textarea>
              <input v-else v-model="form.fields[field.key]" :type="field.type==='number'?'number':'text'" :disabled="field.key==='isbn'&&isbnAbsent" :class="{'is-invalid':field.key==='isbn'&&isbnStatus==='unreadable'}" @input="field.key==='isbn'&&isbnChanged()">
            </label>
            <label v-if="field.key==='isbn'" class="ta-isbn-absent compact"><input v-model="isbnAbsent" type="checkbox" @change="toggleIsbnAbsent"><span><b>{{copy.noIsbn}}</b><small>{{copy.noIsbnHint}}</small></span></label>
            <p v-if="field.key==='isbn'&&isbnStatus!=='idle'" class="ta-book-analysis" :class="isbnStatus"><span></span>{{isbnStatus==='analyzing'?copy.bookAnalyzing:isbnStatus==='found'?copy.bookFound:isbnStatus==='not_found'?copy.bookNotFound:isbnStatus==='absent'?copy.noIsbnSelected:copy.isbnUnreadable}}</p>
          </div>
        </section>
        <section class="ta-dark-card">
          <h2>4. {{copy.phone}} <em>*</em></h2>
          <input v-model="form.phone" type="tel" autocomplete="tel" inputmode="tel" :placeholder="copy.phone">
          <div class="ta-field-grid secondary-fields"><label><span>{{copy.name}}</span><input v-model="form.name" autocomplete="name"></label><label><span>{{copy.email}}</span><input v-model="form.email" type="email" autocomplete="email"></label></div>
        </section>
        <section class="ta-dark-card">
          <h2>5. {{copy.replyMethod}}</h2>
          <div class="ta-channel-grid">
            <button :class="{active:form.preferred_channel==='push'}" @click="form.preferred_channel='push'"><AppIcon name="bell"/><span>{{copy.onSite}}</span></button>
            <button v-if="app.tenant.contact.vk_url" :class="{active:form.preferred_channel==='vk'}" @click="form.preferred_channel='vk'"><b>VK</b><span>VK</span></button>
            <button :class="{active:form.preferred_channel==='sms'}" @click="form.preferred_channel='sms'"><AppIcon name="phone"/><span>{{copy.byPhone}}</span></button>
          </div>
        </section>
        <p class="ta-privacy"><AppIcon name="shield"/>{{copy.privacyNote}}</p>
        <p v-if="error" class="ta-error">{{error}}</p>
        <button class="ta-gold-button" :disabled="busy" @click="submit"><AppIcon name="send"/>{{busy?copy.sending:copy.send}}</button>
      </div>
      <input ref="fileInput" hidden type="file" accept="image/*" capture="environment" @change="selected">
    </template>

    <template v-else-if="stage==='success'">
      <div class="ta-flow-scroll ta-success-dark">
        <header><h1>{{copy.sent}}</h1><div class="ta-contact-shortcuts"><a v-if="app.tenant.contact.phone" :href="'tel:'+app.tenant.contact.phone"><AppIcon name="phone"/></a><a v-if="app.tenant.contact.vk_url" :href="app.tenant.contact.vk_url" target="_blank">VK</a></div></header>
        <div class="ta-success-orbit"><AppIcon name="check" :size="66"/></div>
        <h2>{{copy.receivedTitle}}<br>{{copy.requestNumber}} №{{result?.request?.number}}</h2>
        <p>{{copy.receivedText}}</p>
        <article class="ta-request-summary">
          <div><AppIcon name="clock"/><span><small>{{copy.today}}</small><b>{{new Date().toLocaleString(locale,{dateStyle:'long',timeStyle:'short'})}}</b></span></div>
          <div><AppIcon name="message"/><span><small>{{copy.whatToDo}}</small><b>{{form.summary||app.template.hero.action}}</b></span><img v-if="files[0]" :src="files[0].url" alt=""></div>
        </article>
        <p>{{copy.savedConversation}}</p>
        <article class="ta-master-card"><img :src="app.tenant.logo||'/brand/lookdo-mark.webp'" alt=""><div><h3>{{contactName}}</h3><p>{{copy.specialist}}</p><a v-if="app.tenant.contact.phone" :href="'tel:'+app.tenant.contact.phone">{{app.tenant.contact.phone}}</a><span v-if="address">{{address}}</span></div></article>
        <article class="ta-push-card"><AppIcon name="bell" :size="38"/><div><h3>{{copy.doNotMiss}}</h3><p>{{copy.doNotMissText}}</p></div><button @click="stage='notifications'"><span></span></button></article>
        <button class="ta-gold-button" @click="finishSuccess">{{copy.continue}}</button>
        <button class="ta-outline-button" @click="shareRequest"><AppIcon name="share"/>{{copy.shareRequest}}</button>
      </div>
    </template>

    <template v-else>
      <header class="ta-flow-title compact">
        <button class="ta-back" @click="back"><AppIcon name="back"/><span>{{copy.back}}</span></button>
        <div><h1>{{copy.notificationTitle}}</h1></div>
        <div class="ta-contact-shortcuts"><a v-if="app.tenant.contact.phone" :href="'tel:'+app.tenant.contact.phone"><AppIcon name="phone"/></a></div>
      </header>
      <div class="ta-flow-scroll ta-notification-screen">
        <div class="ta-bell-orbit"><AppIcon name="bell" :size="92"/><b>1</b></div>
        <h2>{{copy.notificationHeadline}}</h2><p>{{copy.notificationText}}</p>
        <div class="ta-notification-benefits">
          <article><span><AppIcon name="message"/></span><div><h3>{{copy.notificationBenefit1}}</h3><p>{{copy.notificationBenefit1Text}}</p></div></article>
          <article><span><AppIcon name="bell"/></span><div><h3>{{copy.notificationBenefit2}}</h3><p>{{copy.notificationBenefit2Text}}</p></div></article>
          <article><span><AppIcon name="shield"/></span><div><h3>{{copy.notificationBenefit3}}</h3><p>{{copy.notificationBenefit3Text}}</p></div></article>
        </div>
        <article class="ta-message-preview"><img :src="app.tenant.logo||'/brand/lookdo-mark.webp'" alt=""><div><b>{{contactName}}</b><p>{{copy.receivedText}}</p></div><small>{{copy.today}}</small></article>
        <p v-if="notificationStatus" class="ta-notification-status">{{notificationStatus}}</p>
        <button class="ta-gold-button" :disabled="notifying" @click="enableNotifications"><AppIcon name="bell"/>{{notifying?copy.sending:copy.notifications}}</button>
        <button class="ta-outline-button" @click="emit('close')">{{copy.later}}</button>
      </div>
    </template>

    <div v-if="stage!=='capture'&&stage!=='details'" class="ta-mini-nav"><button @click="emit('close')"><AppIcon name="home"/><span>{{copy.home}}</span></button><button class="central" @click="stage='capture'"><AppIcon name="camera"/><span>{{copy.action}}</span></button><button @click="emit('close')"><AppIcon name="message"/><span>{{copy.messages}}</span></button></div>
  </section>
</template>

<style scoped>
.ta-wheel-map {
  width: 116px;
  height: 116px;
  justify-self: center;
  align-self: center;
}

.ta-wheel-map :deep(svg) {
  display: block;
}

.ta-wheel-map.is-steering :deep(svg) {
  color: var(--ta-gold-soft, #f2c977);
  filter: drop-shadow(0 0 10px rgba(224, 170, 80, 0.18));
}

.ta-wheel-map b:nth-of-type(1) {
  top: 0;
  left: 50%;
  transform: translateX(-50%);
}

.ta-wheel-map b:nth-of-type(2) {
  top: 50%;
  left: 0;
  transform: translateY(-50%);
}

.ta-wheel-map b:nth-of-type(3) {
  top: 50%;
  right: 0;
  transform: translateY(-50%);
}

.ta-wheel-map b:nth-of-type(4) {
  bottom: 0;
  left: 50%;
  transform: translateX(-50%);
}

.ta-add-photo-button {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 9px;
  min-height: 52px;
  padding: 0 20px;
  border: 1px solid color-mix(in srgb, var(--ta-primary, #e0aa50) 72%, white);
  border-radius: 14px;
  background: #171a1d;
  color: #fff;
  font: inherit;
  font-weight: 800;
  line-height: 1;
}

.ta-add-photo-button :deep(svg) {
  display: block;
  flex: 0 0 auto;
}

.ta-isbn-absent {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  margin: 14px 0;
  padding: 14px 16px;
  border: 1px solid var(--ta-dark-line, #34383d);
  border-radius: 14px;
  background: #171a1d;
  color: #fff;
  cursor: pointer;
}

.ta-isbn-absent.compact {
  margin: 9px 0 0;
  padding: 11px 13px;
}

.ta-isbn-absent input {
  width: 20px;
  height: 20px;
  margin: 1px 0 0;
  flex: 0 0 auto;
  accent-color: var(--ta-primary, #e0aa50);
}

.ta-isbn-absent span,
.ta-isbn-absent b,
.ta-isbn-absent small {
  display: block;
}

.ta-isbn-absent small {
  margin-top: 4px;
  color: #a9adb2;
  font-weight: 500;
  line-height: 1.4;
}

.ta-book-analysis {
  display: flex;
  align-items: center;
  gap: 9px;
  margin: 12px 0;
  padding: 11px 13px;
  border: 1px solid #444a50;
  border-radius: 12px;
  background: #171a1d;
  color: #d2d5d8;
  font-size: 12px;
  line-height: 1.4;
}

.ta-book-analysis span {
  width: 9px;
  height: 9px;
  flex: 0 0 auto;
  border-radius: 50%;
  background: #959ba1;
}

.ta-book-analysis.analyzing span {
  background: var(--ta-primary, #e0aa50);
  animation: lookdoPulse 1s infinite;
}

.ta-book-analysis.found {
  border-color: #34795a;
  color: #9ee0bd;
}

.ta-book-analysis.found span {
  background: #54bc86;
}

.ta-book-analysis.unreadable {
  border-color: #a7464c;
  color: #ffb0b5;
}

.ta-book-analysis.unreadable span {
  background: #df6976;
}

.ta-isbn-field input.is-invalid {
  border-color: #df6976 !important;
  background: rgba(223, 105, 118, 0.1) !important;
  box-shadow: 0 0 0 3px rgba(223, 105, 118, 0.13);
}
</style>
