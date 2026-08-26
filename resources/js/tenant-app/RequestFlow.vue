<script setup lang="ts">
import { computed, nextTick, reactive, ref } from 'vue';
import { api } from '../api';
import AppIcon from './AppIcon.vue';

const props = defineProps<{ app: any; copy: any; locale: string; token: string }>();
const emit = defineEmits<{ close: []; success: [payload:any] }>();
const stage = ref<'media'|'details'|'success'>('media');
const files = ref<{ file:File; slot:string; url:string }[]>([]);
const currentSlot = ref('overall');
const cameraInput = ref<HTMLInputElement|null>(null);
const galleryInput = ref<HTMLInputElement|null>(null);
const busy = ref(false);
const error = ref('');
const result = ref<any>(null);
const notifying = ref(false);
const notificationStatus = ref('');
const form = reactive<any>({ name:'', phone:'', email:'', summary:'', preferred_channel:'phone', fields:{} });

const rawSlots = computed(() => props.app.template.media_slots || []);
const slots = computed(() => rawSlots.value.length ? rawSlots.value : [{key:'overall', required:true}]);
const accent = computed(() => props.app.tenant.colors.primary);
const mediaAccept = computed(() => props.app.entitlements?.video ? 'image/*,video/*' : 'image/*');
const canNotify = computed(() => Boolean(props.app.push?.enabled && props.app.push?.public_key && 'Notification' in window && 'serviceWorker' in navigator));
const slotCopy:any = {
  overall:{de:['Lenkrad komplett','Fotografieren Sie das Lenkrad gerade von vorn.'],en:['Whole steering wheel','Photograph the full steering wheel from the front.'],ru:['Руль целиком','Сфотографируйте руль прямо спереди.'],uk:['Кермо повністю','Сфотографуйте кермо прямо спереду.']},
  top:{de:['Oberer Bereich','Zeigen Sie Material und Nähte näher.'],en:['Top section','Show the material and stitching close up.'],ru:['Верх руля','Покажите материал и швы крупно.'],uk:['Верх керма','Покажіть матеріал і шви зблизька.']},
  left:{de:['Linke Seite','Zeigen Sie Griffbereich und Speiche.'],en:['Left side','Show the grip and spoke.'],ru:['Левая сторона','Покажите место хвата и спицу.'],uk:['Ліва сторона','Покажіть місце хвату та спицю.']},
  right:{de:['Rechte Seite','Zeigen Sie Griffbereich und Speiche.'],en:['Right side','Show the grip and spoke.'],ru:['Правая сторона','Покажите место хвата и спицу.'],uk:['Права сторона','Покажіть місце хвату та спицю.']},
  damage:{de:['Schaden im Detail','Zeigen Sie Risse oder Abrieb näher.'],en:['Damage detail','Show cracks or wear close up.'],ru:['Повреждение крупно','Покажите трещины или потёртости.'],uk:['Пошкодження зблизька','Покажіть тріщини або потертості.']},
  opening_overall:{de:['Öffnung komplett','Fotografieren Sie die ganze Öffnung gerade von vorn.'],en:['Whole doorway','Photograph the whole doorway from the front.'],ru:['Проём целиком','Сфотографируйте проём прямо спереди.'],uk:['Отвір повністю','Сфотографуйте отвір прямо спереду.']},
  opening_left:{de:['Linke Seite','Zeigen Sie die linke Kante von Boden bis oben.'],en:['Left side','Show the left edge from floor to top.'],ru:['Левая сторона','Покажите левый край от пола доверху.'],uk:['Ліва сторона','Покажіть лівий край від підлоги догори.']},
  opening_right:{de:['Rechte Seite','Zeigen Sie die rechte Kante von Boden bis oben.'],en:['Right side','Show the right edge from floor to top.'],ru:['Правая сторона','Покажите правый край от пола доверху.'],uk:['Права сторона','Покажіть правий край від підлоги догори.']},
  opening_top:{de:['Oberer Bereich','Zeigen Sie beide oberen Ecken.'],en:['Top section','Show both top corners.'],ru:['Верх проёма','Покажите оба верхних угла.'],uk:['Верх отвору','Покажіть обидва верхні кути.']},
  floor_threshold:{de:['Boden und Schwelle','Zeigen Sie Bodenhöhen und Schwelle.'],en:['Floor and threshold','Show floor levels and threshold.'],ru:['Пол и порог','Покажите уровни пола и порог.'],uk:['Підлога й поріг','Покажіть рівні підлоги та поріг.']},
};
const fieldCopy:any = {
  vehicle_brand:{de:'Automarke',en:'Vehicle brand',ru:'Марка автомобиля',uk:'Марка автомобіля'}, vehicle_model:{de:'Modell',en:'Model',ru:'Модель',uk:'Модель'}, vehicle_year:{de:'Baujahr',en:'Year',ru:'Год',uk:'Рік'},
  material_preference:{de:'Materialwunsch',en:'Material preference',ru:'Пожелания по материалу',uk:'Побажання щодо матеріалу'}, stitch_preference:{de:'Nahtwunsch',en:'Stitch preference',ru:'Пожелания по строчке',uk:'Побажання щодо строчки'}, shape_preference:{de:'Form oder Dicke ändern?',en:'Change shape or thickness?',ru:'Изменить форму или толщину?',uk:'Змінити форму або товщину?'},
  opening_width_mm:{de:'Breite der Öffnung (mm)',en:'Opening width (mm)',ru:'Ширина проёма (мм)',uk:'Ширина отвору (мм)'}, opening_height_mm:{de:'Höhe der Öffnung (mm)',en:'Opening height (mm)',ru:'Высота проёма (мм)',uk:'Висота отвору (мм)'}, wall_thickness_mm:{de:'Wandstärke (mm)',en:'Wall thickness (mm)',ru:'Толщина стены (мм)',uk:'Товщина стіни (мм)'}, door_request_type:{de:'Was soll gemacht werden?',en:'What should be done?',ru:'Что нужно сделать?',uk:'Що потрібно зробити?'}, door_type:{de:'Türart',en:'Door type',ru:'Тип двери',uk:'Тип дверей'}, comment:{de:'Ihre Wünsche',en:'Your notes',ru:'Ваши пожелания',uk:'Ваші побажання'},
};
function slotText(slot:any){ const hit=slotCopy[slot.key]?.[props.locale]; return { title:hit?.[0] || slot.title || slot.label || props.copy.other, hint:hit?.[1] || slot.instruction || slot.hint || '' }; }
function label(field:any){ return fieldCopy[field.key]?.[props.locale] || field.label || field.key; }
function existing(slot:string){ return files.value.find(item=>item.slot===slot); }
function choose(slot:string, camera:boolean){ currentSlot.value=slot; nextTick(()=> (camera ? cameraInput.value : galleryInput.value)?.click()); }
function selected(event:Event){ const input=event.target as HTMLInputElement; const file=input.files?.[0]; if(!file)return; const old=existing(currentSlot.value); if(old) URL.revokeObjectURL(old.url); files.value=files.value.filter(item=>item.slot!==currentSlot.value); files.value.push({file,slot:currentSlot.value,url:URL.createObjectURL(file)}); input.value=''; }
function remove(slot:string){ const item=existing(slot); if(item)URL.revokeObjectURL(item.url); files.value=files.value.filter(value=>value.slot!==slot); }
function applicationServerKey(value:string):Uint8Array {
  const padding='='.repeat((4-value.length%4)%4); const base64=(value+padding).replace(/-/g,'+').replace(/_/g,'/'); const raw=atob(base64);
  return Uint8Array.from([...raw].map(char=>char.charCodeAt(0)));
}
async function enableNotifications(){
  if(!canNotify.value || !result.value?.token)return;
  notifying.value=true; notificationStatus.value='';
  try{
    const permission=await Notification.requestPermission();
    if(permission!=='granted'){ notificationStatus.value=props.copy.notificationDenied; return; }
    const registration=await navigator.serviceWorker.ready;
    const subscription=await registration.pushManager.subscribe({userVisibleOnly:true,applicationServerKey:applicationServerKey(props.app.push.public_key) as BufferSource});
    const value=subscription.toJSON();
    await api('/tenant-app/push-subscriptions',{method:'POST',headers:{'X-Lookdo-Client-Token':result.value.token},body:JSON.stringify({endpoint:value.endpoint,keys:value.keys})});
    notificationStatus.value=props.copy.notificationEnabled;
  }catch(e:any){ notificationStatus.value=e.message; }finally{ notifying.value=false; }
}
async function submit(){
  if(!form.phone){ error.value=props.copy.phone; return; }
  busy.value=true; error.value='';
  try{
    const body=new FormData();
    for(const key of ['name','phone','email','summary','preferred_channel']) body.append(key,form[key] || '');
    body.append('fields',JSON.stringify(form.fields)); body.append('media_slots',JSON.stringify(files.value.map(item=>item.slot)));
    files.value.forEach(item=>body.append('media[]',item.file));
    result.value=await api('/tenant-app/requests',{method:'POST',body,headers:props.token?{'X-Lookdo-Client-Token':props.token}:{}});
    stage.value='success'; emit('success',result.value);
  }catch(e:any){ error.value=e.message; }finally{busy.value=false;}
}
</script>

<template>
  <section class="ta-flow">
    <header class="ta-flow-head">
      <button class="ta-icon-button" @click="stage==='details' ? stage='media' : emit('close')"><AppIcon name="back" /></button>
      <div><span>{{ app.template.name }}</span><b>{{ stage==='media' ? copy.requestTitle : stage==='details' ? copy.details : copy.sent }}</b></div>
      <button class="ta-icon-button" @click="emit('close')"><AppIcon name="close" /></button>
    </header>

    <div v-if="stage==='media'" class="ta-flow-body">
      <div class="ta-flow-intro"><h1>{{ app.template.hero.action }}</h1><p>{{ copy.requestHint }}</p></div>
      <div class="ta-capture-list">
        <article v-for="slot in slots" :key="slot.key" class="ta-capture-card" :class="{filled:existing(slot.key)}">
          <div v-if="existing(slot.key)" class="ta-capture-preview"><img v-if="existing(slot.key)?.file.type.startsWith('image/')" :src="existing(slot.key)?.url"><video v-else :src="existing(slot.key)?.url" muted playsinline/></div>
          <div class="ta-capture-copy"><small>{{ slot.required ? copy.required : copy.optional }}</small><h3>{{ slotText(slot).title }}</h3><p>{{ slotText(slot).hint }}</p></div>
          <div class="ta-capture-actions">
            <button v-if="existing(slot.key)" class="ta-link" @click="remove(slot.key)">{{ copy.remove }}</button>
            <template v-else><button class="ta-mini-action" @click="choose(slot.key,true)"><AppIcon name="camera" :size="20"/>{{ copy.camera }}</button><button class="ta-mini-action secondary" @click="choose(slot.key,false)"><AppIcon name="grid" :size="20"/>{{ copy.gallery }}</button></template>
          </div>
        </article>
      </div>
      <input ref="cameraInput" hidden type="file" :accept="mediaAccept" capture="environment" @change="selected"><input ref="galleryInput" hidden type="file" :accept="mediaAccept" @change="selected">
      <div class="ta-sticky-action"><button class="ta-primary" :disabled="!files.length" @click="stage='details'">{{ copy.continue }} <AppIcon name="arrow" :size="20"/></button></div>
    </div>

    <div v-else-if="stage==='details'" class="ta-flow-body ta-form-screen">
      <h1>{{ copy.details }}</h1>
      <div class="ta-fields">
        <template v-for="field in app.template.fields.filter((item:any)=>item.key!=='phone')" :key="field.key">
          <label><span>{{ label(field) }} <i v-if="!field.required">{{ copy.optional }}</i></span>
            <textarea v-if="field.type==='textarea'" v-model="form.fields[field.key]" rows="3" :placeholder="field.placeholder"/>
            <select v-else-if="field.type==='select'" v-model="form.fields[field.key]"><option value="">—</option><option v-for="option in field.options" :key="option" :value="option">{{ option }}</option></select>
            <input v-else v-model="form.fields[field.key]" :type="field.type==='number'?'number':'text'" :placeholder="field.placeholder">
          </label>
        </template>
        <label><span>{{ copy.summary }} <i>{{ copy.optional }}</i></span><textarea v-model="form.summary" rows="3"/></label>
      </div>
      <h2>{{ copy.contact }}</h2>
      <div class="ta-fields two"><label><span>{{ copy.name }}</span><input v-model="form.name" autocomplete="name"></label><label><span>{{ copy.phone }} *</span><input v-model="form.phone" type="tel" autocomplete="tel" inputmode="tel"></label><label><span>{{ copy.email }}</span><input v-model="form.email" type="email" autocomplete="email"></label></div>
      <p v-if="error" class="ta-error">{{ error }}</p>
      <div class="ta-sticky-action"><button class="ta-primary" :disabled="busy" @click="submit">{{ busy ? copy.sending : copy.send }} <AppIcon name="arrow" :size="20"/></button></div>
    </div>

    <div v-else class="ta-success-screen">
      <div class="ta-success-mark"><AppIcon name="check" :size="42"/></div><h1>{{ result?.success?.title || copy.sent }}</h1><p>{{ result?.success?.text }}</p><strong>{{ copy.requestNumber }}: {{ result?.request?.number }}</strong>
      <button v-if="canNotify" class="ta-secondary-action" :disabled="notifying" @click="enableNotifications"><AppIcon name="bell" :size="20"/>{{ notifying ? copy.sending : copy.notifications }}</button>
      <p v-if="notificationStatus" class="ta-notification-status">{{ notificationStatus }}</p>
      <button class="ta-primary" @click="emit('close')">{{ copy.activity }} <AppIcon name="arrow" :size="20"/></button>
    </div>
  </section>
</template>