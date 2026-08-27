<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { api } from '../api';
import LineIcon from '../components/LineIcon.vue';
import { locale, tr } from '../i18n';

defineProps<{ pricingOnly?: boolean }>();
const plans = ref<any[]>([]);
const demoVideo = ref<{ source: 'none' | 'upload' | 'youtube'; url: string }>({ source: 'none', url: '' });
const demoOpen = ref(false);
const pricingCurrency = ref('EUR');
const pricingCycle = ref('monthly');
const pricingCurrencyEdited = ref(false);
const phoneTime = ref('9:41');
let phoneClockTimer: number | undefined;
let planRequest = 0;
function updatePhoneTime() {
    const now = new Date();
    phoneTime.value = `${String(now.getHours()).padStart(2, '0')}:${String(now.getMinutes()).padStart(2, '0')}`;
}
onMounted(() => {
    updatePhoneTime();
    phoneClockTimer = window.setInterval(updatePhoneTime, 30_000);
});
onBeforeUnmount(() => {
    if (phoneClockTimer !== undefined) window.clearInterval(phoneClockTimer);
});
watch(locale, async () => { if (!pricingCurrencyEdited.value) pricingCurrency.value = locale.value === 'ru' ? 'RUB' : locale.value === 'uk' ? 'UAH' : 'EUR'; const request = ++planRequest; const response = await api<any>('/platform'); if (request === planRequest) { plans.value = response.plans; demoVideo.value = response.demo_video || { source: 'none', url: '' }; } }, { immediate: true });
const workflow = [['photo', 'howShow'], ['phone', 'howPhone'], ['bell', 'howReceive'], ['chat', 'howReply']];
const audiences = [['car', 'audienceAuto'], ['tools', 'audienceConstruction'], ['washer', 'audienceAppliance'], ['sofa', 'audienceFurniture'], ['leaf', 'audienceGarden'], ['service', 'audienceCleaning'], ['star', 'audienceBeauty']];
const phoneServices = [['/brand/service-door.webp', 'audienceConstruction'], ['/brand/service-appliance.webp', 'audienceAppliance'], ['/brand/service-furniture.webp', 'audienceFurniture'], ['/brand/service-brows.webp', 'audienceBeauty'], ['/brand/service-renovation.webp', 'audienceConstruction'], ['', 'moreActivities']];
const phoneRequests = [['/brand/service-door.webp', 'audienceConstruction'], ['/brand/service-appliance.webp', 'audienceAppliance'], ['/brand/service-furniture.webp', 'audienceFurniture'], ['/brand/service-brows.webp', 'audienceBeauty']];
const benefits = [['globe', 'benefitDomainTitle', 'linkDomain'], ['photo', 'benefitMediaTitle', 'photosVideos'], ['bell', 'benefitPushTitle', 'messagesPush'], ['star', 'benefitReviewTitle', 'reviews'], ['calendar', 'bookingTitle', 'bookingText'], ['chat', 'socialTitle', 'socialText']];
const faqs = {
    de: [['Müssen Kunden etwas installieren?', 'Nein. Die App öffnet sich direkt über einen Link oder QR-Code im Browser. Ein Kundenkonto ist für eine Anfrage nicht nötig.'], ['Kann ich meine Domain nutzen?', 'Ja. Jede App erhält sofort eine LOOKDO Adresse; eine eigene Domain kann zusätzlich verbunden werden.'], ['Wie schnell ist meine App erreichbar?', 'Die Plattformadresse wird direkt bei der Registrierung reserviert. Logo, Farben, Kontaktdaten und Inhalte können Sie anschließend in Ruhe ergänzen.'], ['Was passiert, wenn meine Tätigkeit nicht exakt erkannt wird?', 'Die Registrierung stoppt nicht. LOOKDO verwendet eine universelle Vorlage, die später durch eine passendere Vorlage ersetzt werden kann.'], ['Kann ich Tarif oder Funktionen später ändern?', 'Ja. Tarif, Domain und aktivierte Funktionen werden zentral verwaltet und können mit dem Betrieb wachsen.'], ['Wem gehören meine Inhalte und Kundendaten?', 'Ihre Betriebsinhalte bleiben Ihrem Betrieb zugeordnet. LOOKDO verarbeitet Daten nur für den Betrieb der gebuchten Funktionen und nach den Angaben der Datenschutzerklärung.']],
    en: [['Do customers install anything?', 'No. The app opens directly from a link or QR code in the browser. Customers do not need an account to send a request.'], ['Can I use my domain?', 'Yes. Every app receives a LOOKDO address immediately; a custom domain can also be connected.'], ['How quickly is my app available?', 'The platform address is reserved during registration. You can then add your logo, colours, contact details and content at your own pace.'], ['What if my activity is not recognised exactly?', 'Registration never stops. LOOKDO uses a universal template that can later be replaced with a more suitable one.'], ['Can I change my plan or features later?', 'Yes. Your plan, domain and enabled features are managed centrally and can grow with your business.'], ['Who owns my content and customer data?', 'Your business content remains assigned to your business. LOOKDO processes data only to provide the booked functions and as described in the privacy policy.']],
    ru: [['Клиенту нужно что-то устанавливать?', 'Нет. Приложение открывается в браузере по ссылке или QR-коду. Для отправки заявки клиенту не нужен аккаунт.'], ['Можно использовать свой домен?', 'Да. Приложение сразу получает адрес LOOKDO, а собственный домен можно подключить дополнительно.'], ['Как быстро приложение станет доступно?', 'Адрес на платформе резервируется при регистрации. Логотип, цвета, контакты и содержимое можно спокойно добавить после этого.'], ['Что будет, если моя деятельность не определилась точно?', 'Регистрация не остановится. LOOKDO подключит универсальный шаблон, который позднее можно заменить более подходящим.'], ['Можно позднее сменить тариф или функции?', 'Да. Тариф, домен и включённые функции управляются централизованно и могут развиваться вместе с бизнесом.'], ['Кому принадлежат контент и данные клиентов?', 'Материалы бизнеса остаются привязаны к вашему бизнесу. LOOKDO обрабатывает данные только для работы подключённых функций и согласно политике конфиденциальности.']],
    uk: [['Клієнту потрібно щось встановлювати?', 'Ні. Застосунок відкривається в браузері за посиланням або QR-кодом. Для надсилання заявки обліковий запис не потрібен.'], ['Можна використовувати власний домен?', 'Так. Застосунок одразу отримує адресу LOOKDO, а власний домен можна підключити додатково.'], ['Як швидко застосунок стане доступним?', 'Адреса платформи резервується під час реєстрації. Логотип, кольори, контакти й вміст можна додати після цього.'], ['Що буде, якщо діяльність не визначена точно?', 'Реєстрація не зупиниться. LOOKDO підключить універсальний шаблон, який пізніше можна замінити відповіднішим.'], ['Чи можна пізніше змінити тариф або функції?', 'Так. Тариф, домен та ввімкнені функції керуються централізовано й можуть зростати разом із бізнесом.'], ['Кому належать контент і дані клієнтів?', 'Матеріали залишаються пов’язаними з вашим бізнесом. LOOKDO обробляє дані лише для роботи підключених функцій і відповідно до політики конфіденційності.']],
};
function planPrice(plan:any){return Number(plan.prices?.[pricingCurrency.value]?.[pricingCycle.value] ?? (pricingCurrency.value===plan.currency?(pricingCycle.value==='yearly'?plan.price_yearly:plan.price_monthly):0))}
function formatPlanPrice(plan:any){const value=planPrice(plan);const numberLocale=locale.value==='uk'?'uk-UA':locale.value==='ru'?'ru-RU':locale.value==='de'?'de-DE':'en-GB';return new Intl.NumberFormat(numberLocale,{style:'currency',currency:pricingCurrency.value,maximumFractionDigits:value%1?2:0}).format(value)}
function planSaving(plan:any){const monthly=Number(plan.prices?.[pricingCurrency.value]?.monthly||0);const yearly=Number(plan.prices?.[pricingCurrency.value]?.yearly||0);return monthly&&yearly?Math.max(0,Math.round((1-yearly/(monthly*12))*100)):0}
function youtubeEmbedUrl(value:string){
    if(!value)return '';
    try{
        const url=new URL(value.startsWith('http')?value:'https://'+value);
        const host=url.hostname.replace(/^www\./,'');
        const id=host==='youtu.be'?url.pathname.slice(1):['youtube.com','m.youtube.com'].includes(host)?url.searchParams.get('v'):'';
        return id?'https://www.youtube-nocookie.com/embed/'+encodeURIComponent(id)+'?autoplay=1&rel=0':'';
    }catch{return ''}
}
const demoEmbed=computed(()=>demoVideo.value.source==='youtube'?youtubeEmbedUrl(demoVideo.value.url):'');
function showDemo(){
    document.querySelector('#how')?.scrollIntoView({behavior:'smooth',block:'start'});
    if(demoVideo.value.source!=='none'&&demoVideo.value.url)window.setTimeout(()=>demoOpen.value=true,360);
}
</script>

<template>
  <div class="public-home">
    <section v-if="!pricingOnly" class="public-hero">
      <div class="public-hero-copy">
        <h1><b>LOOK.</b> <span>DO.</span></h1>
        <h2>{{ tr('heroTagline') }}</h2>
        <h3>{{ tr('heroProduct') }}</h3>
        <p>{{ tr('heroText') }}</p>
        <div class="hero-actions"><RouterLink class="button" :to="`/${locale}/register`">{{ tr('create') }}</RouterLink><button type="button" class="button ghost" @click="showDemo"><span class="play-dot">▶</span>{{ tr('demo') }}</button></div>
        <div class="hero-benefits"><span><LineIcon name="shield"/>{{ tr('heroBenefitBrand') }}</span><span><LineIcon name="bolt"/>{{ tr('heroBenefitFast') }}</span><span><LineIcon name="lock"/>{{ tr('heroBenefitSafe') }}</span></div>
      </div>
      <div id="demo" class="public-phone-stage">
        <div class="phone-photo-backdrop" aria-hidden="true"></div>
        <div class="app-phone request-phone">
          <div class="phone-island"></div><div class="phone-status"><time>{{ phoneTime }}</time><span class="phone-system-icons" aria-hidden="true"><svg class="phone-signal" viewBox="0 0 16 11"><rect x="0" y="8" width="2.3" height="3" rx="1"/><rect x="4.4" y="6" width="2.3" height="5" rx="1"/><rect x="8.8" y="3" width="2.3" height="8" rx="1"/><rect x="13.2" y="0" width="2.3" height="11" rx="1"/></svg><svg class="phone-wifi" viewBox="0 0 16 12"><path d="M1 3.5C5.2.2 10.8.2 15 3.5M3.6 6.4a7 7 0 0 1 8.8 0M6.3 9a2.8 2.8 0 0 1 3.4 0"/><circle cx="8" cy="11" r="1"/></svg><span class="phone-battery"><i></i></span></span></div>
          <div class="phone-screen customer-app-screen">
            <header class="phone-app-header"><span class="phone-mini-logo"><img decoding="async" :src="'/brand/lookdo-mark.png'" alt="" aria-hidden="true"></span><b>LOOK.<em>DO.</em></b><span>♧</span></header>
            <section class="customer-phone-intro"><strong>{{ tr('customerAppTitle') }}</strong><small>{{ tr('customerAppText') }}</small></section>
            <div class="customer-service-grid"><div v-for="(item,index) in phoneServices" :key="`${item[1]}-${index}`" class="customer-service-card" :class="{more:!item[0]}"><img decoding="async" v-if="item[0]" :src="item[0]" alt=""><span v-else>•••</span><b>{{ tr(item[1]) }}</b></div></div>
            <div class="customer-how"><b>{{ tr('how') }}</b><div><span><LineIcon name="photo"/><small>1</small></span><i>→</i><span><LineIcon name="phone"/><small>2</small></span><i>→</i><span><LineIcon name="shield"/><small>3</small></span></div></div>
            <button class="phone-camera-cta"><LineIcon name="photo"/><span><b>{{ tr('sendPhotoVideo') }}</b><small>{{ tr('describeWork') }}</small></span></button>
            <nav class="phone-app-nav"><span><LineIcon name="home"/><small>{{ tr('phoneHome') }}</small></span><span><LineIcon name="grid"/><small>{{ tr('activity') }}</small></span><span class="camera-main" aria-label="Create">+</span><span><LineIcon name="chat"/><small>{{ tr('phoneMessages') }}</small></span><span><LineIcon name="user"/><small>{{ tr('account') }}</small></span></nav>
          </div>
        </div>
        <div class="app-phone list-phone">
          <div class="phone-island"></div><div class="phone-status"><time>{{ phoneTime }}</time><span class="phone-system-icons" aria-hidden="true"><svg class="phone-signal" viewBox="0 0 16 11"><rect x="0" y="8" width="2.3" height="3" rx="1"/><rect x="4.4" y="6" width="2.3" height="5" rx="1"/><rect x="8.8" y="3" width="2.3" height="8" rx="1"/><rect x="13.2" y="0" width="2.3" height="11" rx="1"/></svg><svg class="phone-wifi" viewBox="0 0 16 12"><path d="M1 3.5C5.2.2 10.8.2 15 3.5M3.6 6.4a7 7 0 0 1 8.8 0M6.3 9a2.8 2.8 0 0 1 3.4 0"/><circle cx="8" cy="11" r="1"/></svg><span class="phone-battery"><i></i></span></span></div>
          <div class="phone-screen master-app-screen">
            <header class="master-phone-head"><strong>{{ tr('masterWorkspace') }}</strong><span>♧</span></header>
            <div class="master-stats"><span><b>12</b><small>{{ tr('newRequests') }}</small></span><span><b>7</b><small>{{ tr('inProgress') }}</small></span><span><b>24</b><small>{{ tr('completed') }}</small></span></div>
            <div class="phone-section-title"><b>{{ tr('incomingRequests') }}</b><em>{{ tr('viewAll') }}</em></div>
            <div class="master-request-list"><div v-for="(item,index) in phoneRequests" :key="item[1]" class="master-request-card"><img decoding="async" :src="item[0]" alt=""><span><b>#12{{ 7-index }}</b><small>{{ tr(item[1]) }}</small></span><em>{{ tr('newRequests') }}</em></div></div>
            <div class="phone-section-title"><b>{{ tr('phoneMessages') }}</b><em>{{ tr('viewAll') }}</em></div>
            <div class="master-message"><i>L</i><span><b>Leonid</b><small>{{ tr('howReply') }}</small></span><time>10:32</time></div>
            <div class="master-message"><i>M</i><span><b>Maria</b><small>{{ tr('howReceive') }}</small></span><time>09:48</time></div>
            <nav class="phone-app-nav master-nav"><span><LineIcon name="home"/><small>{{ tr('phoneHome') }}</small></span><span><LineIcon name="grid"/><small>{{ tr('activity') }}</small></span><span><LineIcon name="chat"/><small>{{ tr('phoneMessages') }}</small></span><span><LineIcon name="user"/><small>{{ tr('account') }}</small></span></nav>
          </div>
        </div>
      </div>
    </section>
    <section v-if="!pricingOnly" id="how" class="compact-section how-section"><h2>{{ tr('how') }}</h2><div class="how-grid"><article v-for="(item, index) in workflow" :key="item[1]"><b>{{ index + 1 }}</b><span><LineIcon :name="item[0]"/></span><h3>{{ tr(item[1]) }}</h3></article></div></section>

    <section v-if="!pricingOnly" id="audience" class="compact-section audience-section"><h2>{{ tr('forWhom') }}</h2><div class="audience-grid"><article v-for="item in audiences" :key="item[1]"><LineIcon :name="item[0]"/><b>{{ tr(item[1]) }}</b></article></div></section>

    <section v-if="!pricingOnly" id="features" class="compact-section benefits-section"><h2>{{ tr('whyTitle') }}</h2><div class="benefits-grid"><article v-for="item in benefits" :key="item[1]"><LineIcon :name="item[0]"/><div><b>{{ tr(item[1]) }}</b><small>{{ tr(item[2]) }}</small></div></article></div></section>

    <section id="pricing" class="compact-section public-pricing"><h2>{{ tr('pricing') }}</h2><div class="public-billing-controls"><select v-model="pricingCurrency" @change="pricingCurrencyEdited=true"><option value="EUR">EUR — €</option><option value="RUB">RUB — ₽</option><option value="UAH">UAH — ₴</option></select><div class="cycle"><button type="button" :class="{active:pricingCycle==='monthly'}" @click="pricingCycle='monthly'">{{tr('monthly')}}</button><button type="button" :class="{active:pricingCycle==='yearly'}" @click="pricingCycle='yearly'">{{tr('yearly')}}</button></div></div><div class="pricing-grid"><article v-for="plan in plans" :key="plan.id" class="price-card" :class="{ featured: plan.badge }"><div v-if="plan.image_url" class="plan-card-cover"><img loading="lazy" decoding="async" :src="plan.image_url" :alt="plan.name"></div><span v-if="plan.badge" class="badge">{{ plan.badge }}</span><h3>{{ plan.name }}</h3><p>{{ plan.description }}</p><div class="price"><strong>{{ formatPlanPrice(plan) }}</strong><span>{{ pricingCycle==='yearly'?tr('perYear'):tr('perMonth') }}</span><em v-if="pricingCycle==='yearly'&&planSaving(plan)">{{tr('save')}} {{planSaving(plan)}}%</em></div><ul class="plan-feature-list"><li v-for="feature in plan.features" :key="feature.key" :class="{ disabled: !feature.included }"><span>{{ feature.included ? '✓' : '—' }}</span>{{ feature.label }}</li></ul><RouterLink class="button full" :to="`/${locale}/register?plan=${plan.id}`">{{ tr('choose') }}</RouterLink></article></div></section>

    <section v-if="!pricingOnly" class="compact-section public-faq"><h2>{{ tr('faqTitle') }}</h2><div><details v-for="item in faqs[locale]" :key="item[0]"><summary>{{ item[0] }}<span>＋</span></summary><p>{{ item[1] }}</p></details></div></section>
    <div v-if="demoOpen" class="demo-video-backdrop" role="dialog" aria-modal="true" :aria-label="tr('demo')" @click.self="demoOpen=false">
      <div class="demo-video-modal"><header><b>{{tr('demo')}}</b><button type="button" :aria-label="tr('close')" @click="demoOpen=false">×</button></header><video v-if="demoVideo.source==='upload'" :src="demoVideo.url" controls autoplay playsinline></video><iframe v-else-if="demoEmbed" :src="demoEmbed" :title="tr('demo')" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen></iframe></div>
    </div>
  </div>
</template>
