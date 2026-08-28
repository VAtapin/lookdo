<script setup lang="ts">
import LineIcon from '../components/LineIcon.vue';
const props=defineProps<{data:any;t:(key:string)=>string}>();
const emit=defineEmits<{navigate:[section:string]}>();
const time=(value:string)=>new Intl.DateTimeFormat(undefined,{hour:'2-digit',minute:'2-digit'}).format(new Date(value));
</script>
<template><section class="mw-stack">
<header class="mw-page-head"><div><p class="mw-kicker">{{data.today.date}}</p><h1>{{t('todayTitle')}}</h1><p>{{t('todayText')}}</p></div><a class="mw-open" :href="data.tenant.platform_url" target="_blank"><LineIcon name="external"/>{{t('openApp')}}</a></header>
<p v-if="data.access.trial" class="mw-trial">{{t('trialFull')}}</p>
<div class="mw-metrics">
<button @click="emit('navigate','calendar')"><LineIcon name="calendar"/><strong>{{data.today.appointments.length}}</strong><span>{{t('appointmentsToday')}}</span></button>
<button @click="emit('navigate','requests')"><LineIcon name="briefcase"/><strong>{{data.today.requests.length}}</strong><span>{{t('newRequests')}}</span></button>
<button @click="emit('navigate','messages')"><LineIcon name="chat"/><strong>{{data.today.unread}}</strong><span>{{t('unreadMessages')}}</span></button>
<button @click="emit('navigate','calendar')"><LineIcon name="clock"/><strong>{{data.today.free_slots.length}}</strong><span>{{t('freeWindows')}}</span></button>
</div>
<article class="mw-next"><p class="mw-kicker">{{t('nextCustomer')}}</p><template v-if="data.today.appointments[0]"><time>{{time(data.today.appointments[0].starts_at)}}</time><div><h2>{{data.today.appointments[0].customer?.name||data.today.appointments[0].customer?.phone}}</h2><p>{{data.today.appointments[0].service?.name?.[data.tenant.locale]||data.today.appointments[0].service?.name?.de}}</p></div><button @click="emit('navigate','calendar')">→</button></template><p v-else>{{t('noAppointment')}}</p></article>
<div class="mw-two"><article class="mw-panel"><header><h2>{{t('newRequests')}}</h2><button @click="emit('navigate','requests')">{{t('viewAll')}} →</button></header><button v-for="item in data.today.requests.slice(0,4)" :key="item.id" class="mw-list-row" @click="emit('navigate','requests')"><span class="mw-avatar">{{(item.customer?.name||'?').slice(0,1)}}</span><span><b>{{item.customer?.name||item.customer?.phone}}</b><small>{{item.summary||item.number}}</small></span><em>{{t(item.status)}}</em></button><p v-if="!data.today.requests.length" class="mw-empty">{{t('noItems')}}</p></article>
<article class="mw-panel"><header><h2>{{t('appointmentsToday')}}</h2><button @click="emit('navigate','calendar')">{{t('viewAll')}} →</button></header><button v-for="item in data.today.appointments" :key="item.id" class="mw-list-row" @click="emit('navigate','calendar')"><time>{{time(item.starts_at)}}</time><span><b>{{item.customer?.name||item.customer?.phone}}</b><small>{{item.service?.name?.[data.tenant.locale]||item.number}}</small></span><em>{{t(item.status)}}</em></button><p v-if="!data.today.appointments.length" class="mw-empty">{{t('noItems')}}</p></article></div>
<div class="mw-action-grid"><button @click="emit('navigate','requests')"><LineIcon name="chat"/><span>{{t('aiReply')}}</span></button><button @click="emit('navigate','calendar?tab=reminder&type=appointment')"><LineIcon name="bell"/><span>{{t('addReminder')}}</span></button><button @click="emit('navigate','calendar?tab=reminder&type=vacancy')"><LineIcon name="clock"/><span>{{t('freeWindows')}}</span></button><button @click="emit('navigate','work?tab=social')"><LineIcon name="share"/><span>{{t('socialComposer')}}</span></button></div>
<div class="mw-action-grid"><button @click="emit('navigate','customers')"><b>{{data.today.repeat_candidates}}</b><span>{{t('repeatCandidates')}}</span></button><button @click="emit('navigate','work')"><b>{{data.today.unpublished_works}}</b><span>{{t('unpublishedWorks')}}</span></button><button @click="emit('navigate','services')"><LineIcon name="tools"/><span>{{t('services')}}</span></button><button @click="emit('navigate','more')"><LineIcon name="grid"/><span>{{t('more')}}</span></button></div>
</section></template>
