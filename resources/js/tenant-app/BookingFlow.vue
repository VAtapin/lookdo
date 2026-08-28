<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue';
import { api } from '../api';
import AppIcon from './AppIcon.vue';

const props=defineProps<{app:any;copy:any;locale:string;token:string;appointment?:any}>();
const emit=defineEmits<{close:[];success:[payload:any]}>();
const stage=ref<'service'|'date'|'contact'|'success'>('service');
const selectedService=ref<any>(null);const selectedDate=ref('');const selectedSlot=ref<any>(null);const slots=ref<any[]>([]);
const monthOffset=ref(0);const busy=ref(false);const error=ref('');const result=ref<any>(null);const privacy=ref(false);
const form=reactive({name:'',phone:'',email:'',comment:'',preferred_channel:'whatsapp'});
const isReschedule=computed(()=>Boolean(props.appointment));
const step=computed(()=>stage.value==='service'?1:stage.value==='date'?2:3);
const monthDate=computed(()=>{const date=new Date();date.setDate(1);date.setMonth(date.getMonth()+monthOffset.value);return date;});
const monthLabel=computed(()=>monthDate.value.toLocaleDateString(props.locale,{month:'long',year:'numeric'}));
const calendarDays=computed(()=>{
  const first=new Date(monthDate.value);const mondayOffset=(first.getDay()+6)%7;const start=new Date(first);start.setDate(first.getDate()-mondayOffset);
  return Array.from({length:42},(_,index)=>{const date=new Date(start);date.setDate(start.getDate()+index);const iso=localIso(date);const today=localIso(new Date());return{iso,number:date.getDate(),current:date.getMonth()===monthDate.value.getMonth(),past:iso<today,weekend:[0,6].includes(date.getDay())};});
});
const weekDays=computed(()=>{const monday=new Date(2026,0,5);return Array.from({length:7},(_,index)=>{const date=new Date(monday);date.setDate(monday.getDate()+index);return date.toLocaleDateString(props.locale,{weekday:'short'}).replace('.','');});});
const appointmentResult=computed(()=>result.value?.appointment||result.value||props.appointment);

function localIso(date:Date){const year=date.getFullYear();const month=String(date.getMonth()+1).padStart(2,'0');const day=String(date.getDate()).padStart(2,'0');return `${year}-${month}-${day}`;}
function chooseService(service:any){selectedService.value=service;}
function continueService(){if(!selectedService.value)return;stage.value='date';selectedDate.value='';selectedSlot.value=null;slots.value=[];}
async function selectDay(day:any){if(day.past||!day.current)return;selectedDate.value=day.iso;await loadSlots();}
async function loadSlots(){if(!selectedService.value||!selectedDate.value)return;busy.value=true;error.value='';try{const query=new URLSearchParams({service_id:String(selectedService.value.id),date:selectedDate.value});if(props.appointment?.id)query.set('appointment_id',String(props.appointment.id));const response=await api(`/tenant-app/availability?${query}`,{headers:props.token?{'X-Lookdo-Client-Token':props.token}:{}});slots.value=response.slots;selectedSlot.value=null;}catch(e:any){error.value=e.message;}finally{busy.value=false;}}
async function continueDate(){if(!selectedSlot.value)return;if(isReschedule.value){await submitReschedule();return;}stage.value='contact';}
async function submitReschedule(){busy.value=true;error.value='';try{result.value=await api(`/tenant-app/appointments/${props.appointment.id}`,{method:'PATCH',headers:{'X-Lookdo-Client-Token':props.token},body:JSON.stringify({starts_at:selectedSlot.value.starts_at})});stage.value='success';emit('success',result.value);}catch(e:any){error.value=e.message;}finally{busy.value=false;}}
async function book(){if(!form.phone){error.value=props.copy.phone;return;}if(!privacy.value){error.value=props.copy.acceptPrivacy;return;}busy.value=true;error.value='';try{result.value=await api('/tenant-app/appointments',{method:'POST',headers:props.token?{'X-Lookdo-Client-Token':props.token}:{},body:JSON.stringify({...form,service_id:selectedService.value.id,starts_at:selectedSlot.value.starts_at})});stage.value='success';emit('success',result.value);}catch(e:any){error.value=e.message;}finally{busy.value=false;}}
function back(){if(stage.value==='contact')stage.value='date';else if(stage.value==='date'&&!isReschedule.value)stage.value='service';else emit('close');}
function price(service:any){if(service.price===null||service.price===undefined||service.price==='')return props.copy.priceOnRequest;return new Intl.NumberFormat(props.locale,{style:'currency',currency:service.currency||'EUR'}).format(Number(service.price));}
function addCalendar(){const item=appointmentResult.value;if(!item)return;const compact=(value:string)=>new Date(value).toISOString().replace(/[-:]/g,'').replace(/\.\d{3}/,'');const lines=['BEGIN:VCALENDAR','VERSION:2.0','PRODID:-//LOOKDO//Appointment//EN','BEGIN:VEVENT',`UID:${item.number}@lookdo.app`,`DTSTAMP:${compact(new Date().toISOString())}`,`DTSTART:${compact(item.starts_at)}`,`DTEND:${compact(item.ends_at)}`,`SUMMARY:${item.service?.name||props.app.template.name}`,'END:VEVENT','END:VCALENDAR'];const url=URL.createObjectURL(new Blob([lines.join('\r\n')],{type:'text/calendar'}));const link=document.createElement('a');link.href=url;link.download='lookdo-appointment.ics';link.click();URL.revokeObjectURL(url);}

onMounted(()=>{
  if(props.app.session?.customer){form.name=props.app.session.customer.name||'';form.phone=props.app.session.customer.phone||'';form.email=props.app.session.customer.email||'';}
  if(props.appointment){selectedService.value=props.app.services.find((service:any)=>service.id===props.appointment.service?.id)||props.appointment.service;stage.value='date';}
});
</script>

<template>
<section class="ta-flow ta-booking-flow" :class="{'is-reschedule':isReschedule}">
  <header class="ta-flow-head ta-booking-head"><button class="ta-icon-button" @click="back"><AppIcon name="back"/></button><div><b>{{isReschedule?copy.reschedule:copy.bookAppointment}}</b><span v-if="stage!=='success'">{{copy.step}} {{step}} {{copy.of}} 3</span></div><a v-if="app.tenant.contact.phone" class="ta-icon-button" :href="'tel:'+app.tenant.contact.phone"><AppIcon name="phone"/></a><button v-else class="ta-icon-button" @click="emit('close')"><AppIcon name="close"/></button></header>
  <div v-if="stage!=='success'" class="ta-booking-progress"><i v-for="number in [1,2,3]" :key="number" :class="{active:number<=step}">{{number}}</i></div>

  <div v-if="stage==='service'" class="ta-flow-body ta-booking-service-step">
    <div class="ta-flow-intro"><h1>{{copy.chooseProcedure}}</h1><p>{{copy.chooseProcedureText}}</p></div>
    <div class="ta-service-list"><button v-for="service in app.services" :key="service.id" class="ta-service-card" :class="{active:selectedService?.id===service.id}" @click="chooseService(service)"><img v-if="service.image" :src="service.image" :alt="service.name"><span><b>{{service.name}}</b><small>{{service.description}}</small><em>{{service.duration}} {{copy.duration}}</em></span><strong>{{price(service)}}</strong><i><AppIcon :name="selectedService?.id===service.id?'check':'arrow'"/></i></button></div>
    <a class="ta-booking-help" :href="app.tenant.contact.phone?'https://wa.me/'+app.tenant.contact.phone.replace(/\D/g,''):'#'" target="_blank"><span><AppIcon name="message"/></span><div><b>{{copy.notSure}}</b><small>{{copy.askMaster}}</small></div><AppIcon name="arrow"/></a>
    <div class="ta-sticky-action"><button class="ta-primary" :disabled="!selectedService" @click="continueService">{{copy.continue}} <AppIcon name="arrow" :size="20"/></button></div>
  </div>

  <div v-else-if="stage==='date'" class="ta-flow-body ta-booking-date-step">
    <div class="ta-selected-service"><img v-if="selectedService.image" :src="selectedService.image" :alt="selectedService.name"><AppIcon v-else name="calendar"/><span><small>{{copy.selectedService}}</small><b>{{selectedService.name}}</b><em>{{selectedService.duration}} {{copy.duration}} · {{price(selectedService)}}</em></span></div>
    <div class="ta-calendar-title"><button :disabled="monthOffset===0" @click="monthOffset--"><AppIcon name="back"/></button><h2>{{monthLabel}}</h2><button @click="monthOffset++"><AppIcon name="arrow"/></button></div>
    <div class="ta-month-calendar"><b v-for="day in weekDays" :key="day">{{day}}</b><button v-for="day in calendarDays" :key="day.iso" :class="{outside:!day.current,disabled:day.past,weekend:day.weekend,active:selectedDate===day.iso}" :disabled="day.past||!day.current" @click="selectDay(day)">{{day.number}}</button></div>
    <p v-if="selectedDate" class="ta-selected-date"><AppIcon name="calendar"/>{{new Date(selectedDate+'T12:00:00').toLocaleDateString(locale,{weekday:'long',day:'numeric',month:'long'})}}</p>
    <h2>{{copy.availableTimes}}</h2><div v-if="busy" class="ta-loading-line"></div><div v-else-if="selectedDate" class="ta-time-grid"><button v-for="slot in slots" :key="slot.starts_at" :class="{active:selectedSlot?.starts_at===slot.starts_at}" @click="selectedSlot=slot">{{slot.label}}</button><p v-if="!slots.length" class="ta-no-slots">{{copy.noTimes}}</p></div><p v-else class="ta-calendar-hint">{{copy.chooseDateHint}}</p>
    <p v-if="error" class="ta-error">{{error}}</p><div class="ta-sticky-action"><button class="ta-primary" :disabled="!selectedSlot||busy" @click="continueDate">{{isReschedule?copy.confirmReschedule:copy.continue}} <AppIcon name="arrow" :size="20"/></button></div>
  </div>

  <div v-else-if="stage==='contact'" class="ta-flow-body ta-form-screen ta-booking-contact-step">
    <h1>{{copy.contactDetails}}</h1><p>{{copy.contactDetailsText}}</p>
    <div class="ta-fields"><label><span>{{copy.name}} *</span><input v-model="form.name" autocomplete="name"></label><label><span>{{copy.phone}} *</span><input v-model="form.phone" type="tel" inputmode="tel" autocomplete="tel"></label><label><span>{{copy.emailOptional}}</span><input v-model="form.email" type="email" autocomplete="email"></label></div>
    <h2>{{copy.preferredChannel}}</h2><div class="ta-channel-grid"><button v-for="channel in [{key:'whatsapp',label:'WhatsApp'},{key:'viber',label:'Viber'},{key:'telegram',label:'Telegram'}]" :key="channel.key" :class="{active:form.preferred_channel===channel.key}" @click="form.preferred_channel=channel.key">{{channel.label}}<AppIcon v-if="form.preferred_channel===channel.key" name="check"/></button></div>
    <div class="ta-final-summary"><b>{{copy.yourBooking}}</b><p><span>{{selectedService.name}}</span><strong>{{new Date(selectedSlot.starts_at).toLocaleString(locale,{dateStyle:'long',timeStyle:'short'})}}</strong></p></div>
    <label class="ta-privacy-check"><input v-model="privacy" type="checkbox"><span>{{copy.acceptPrivacy}}</span></label><p v-if="error" class="ta-error">{{error}}</p>
    <div class="ta-sticky-action"><button class="ta-primary" :disabled="busy" @click="book">{{busy?copy.booking:copy.confirmBooking}} <AppIcon name="arrow" :size="20"/></button></div>
  </div>

  <div v-else class="ta-success-screen ta-booking-success"><div class="ta-success-mark"><AppIcon name="check" :size="46"/></div><h1>{{isReschedule?copy.rescheduled:copy.appointmentConfirmed}}</h1><p>{{copy.confirmationText}}</p><article><AppIcon name="calendar"/><div><b>{{appointmentResult.service?.name}}</b><span>{{new Date(appointmentResult.starts_at).toLocaleString(locale,{dateStyle:'long',timeStyle:'short'})}}</span><small>#{{appointmentResult.number}}</small></div></article><button class="ta-primary" @click="addCalendar"><AppIcon name="calendar"/>{{copy.addCalendar}}</button><button class="ta-outline-button" @click="emit('close')">{{copy.goHome}}</button></div>
</section>
</template>
