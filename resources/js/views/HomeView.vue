<script setup lang="ts">
import { computed, ref, watch } from 'vue';
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
let planRequest = 0;
watch(locale, async () => { if (!pricingCurrencyEdited.value) pricingCurrency.value = locale.value === 'ru' ? 'RUB' : locale.value === 'uk' ? 'UAH' : 'EUR'; const request = ++planRequest; const response = await api<any>('/platform'); if (request === planRequest) { plans.value = response.plans; demoVideo.value = response.demo_video || { source: 'none', url: '' }; } }, { immediate: true });
const workflow = [['photo', 'howShow'], ['phone', 'howPhone'], ['bell', 'howReceive'], ['chat', 'howReply']];
const audiences = [['car', 'audienceAuto'], ['tools', 'audienceConstruction'], ['washer', 'audienceAppliance'], ['sofa', 'audienceFurniture'], ['leaf', 'audienceGarden'], ['service', 'audienceCleaning'], ['star', 'audienceBeauty']];
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
          <div class="phone-island"></div><div class="phone-status">9:41 <span>● ◒</span></div>
          <div class="phone-screen"><strong>{{ tr('howShow') }}</strong><small>{{ tr('describeWork') }}</small>
            <div class="photo-tiles"><span><LineIcon name="door"/></span><span><LineIcon name="service"/></span><span><LineIcon name="washer"/></span><span><LineIcon name="sofa"/></span></div>
            <button class="upload-box"><LineIcon name="photo"/>{{ tr('photosVideos') }}</button><label>{{ tr('yourActivity') }}<i>{{ tr('activityPlaceholder') }}</i></label><label>{{ tr('phone') }}<i>+49 151 234 56 78</i></label><button class="phone-submit">{{ tr('howReceive') }}</button>
          </div>
        </div>
        <div class="app-phone list-phone">
          <div class="phone-island"></div><div class="phone-status">9:41 <span>● ◒</span></div>
          <div class="phone-screen"><strong>{{ tr('messagesPush') }}</strong><div class="phone-tabs"><b>12</b><span>3</span><span>5</span></div>
            <article v-for="(icon, index) in ['door','washer','sofa']" :key="icon"><div><b>#12{{ 7-index }}</b><em>{{ index ? tr('howReply') : tr('howReceive') }}</em></div><p>{{ [tr('audienceDoors'),tr('audienceAppliance'),tr('audienceFurniture')][index] }}</p><span><LineIcon :name="icon"/></span></article>
            <nav><span>▣<small>{{ tr('activity') }}</small></span><span>□<small>{{ tr('messagesPush') }}</small></span><span>♙<small>{{ tr('account') }}</small></span></nav>
          </div>
        </div>
      </div>
    </section>

    <section v-if="!pricingOnly" id="how" class="compact-section how-section"><h2>{{ tr('how') }}</h2><div class="how-grid"><article v-for="(item, index) in workflow" :key="item[1]"><b>{{ index + 1 }}</b><span><LineIcon :name="item[0]"/></span><h3>{{ tr(item[1]) }}</h3></article></div></section>

    <section v-if="!pricingOnly" id="audience" class="compact-section audience-section"><h2>{{ tr('forWhom') }}</h2><div class="audience-grid"><article v-for="item in audiences" :key="item[1]"><LineIcon :name="item[0]"/><b>{{ tr(item[1]) }}</b></article></div></section>

    <section v-if="!pricingOnly" id="features" class="compact-section benefits-section"><h2>{{ tr('whyTitle') }}</h2><div class="benefits-grid"><article v-for="item in benefits" :key="item[1]"><LineIcon :name="item[0]"/><div><b>{{ tr(item[1]) }}</b><small>{{ tr(item[2]) }}</small></div></article></div></section>

    <section id="pricing" class="compact-section public-pricing"><h2>{{ tr('pricing') }}</h2><div class="public-billing-controls"><select v-model="pricingCurrency" @change="pricingCurrencyEdited=true"><option value="EUR">EUR — €</option><option value="RUB">RUB — ₽</option><option value="UAH">UAH — ₴</option></select><div class="cycle"><button type="button" :class="{active:pricingCycle==='monthly'}" @click="pricingCycle='monthly'">{{tr('monthly')}}</button><button type="button" :class="{active:pricingCycle==='yearly'}" @click="pricingCycle='yearly'">{{tr('yearly')}}</button></div></div><div class="pricing-grid"><article v-for="plan in plans" :key="plan.id" class="price-card" :class="{ featured: plan.badge }"><span v-if="plan.badge" class="badge">{{ plan.badge }}</span><h3>{{ plan.name }}</h3><p>{{ plan.description }}</p><div class="price"><strong>{{ formatPlanPrice(plan) }}</strong><span>{{ pricingCycle==='yearly'?tr('perYear'):tr('perMonth') }}</span><em v-if="pricingCycle==='yearly'&&planSaving(plan)">{{tr('save')}} {{planSaving(plan)}}%</em></div><ul class="plan-feature-list"><li v-for="feature in plan.features" :key="feature.key" :class="{ disabled: !feature.included }"><span>{{ feature.included ? '✓' : '—' }}</span>{{ feature.label }}</li></ul><RouterLink class="button full" :to="`/${locale}/register?plan=${plan.id}`">{{ tr('choose') }}</RouterLink></article></div></section>

    <section v-if="!pricingOnly" class="compact-section public-faq"><h2>{{ tr('faqTitle') }}</h2><div><details v-for="item in faqs[locale]" :key="item[0]"><summary>{{ item[0] }}<span>＋</span></summary><p>{{ item[1] }}</p></details></div></section>
    <div v-if="demoOpen" class="demo-video-backdrop" role="dialog" aria-modal="true" :aria-label="tr('demo')" @click.self="demoOpen=false">
      <div class="demo-video-modal"><header><b>{{tr('demo')}}</b><button type="button" :aria-label="tr('close')" @click="demoOpen=false">×</button></header><video v-if="demoVideo.source==='upload'" :src="demoVideo.url" controls autoplay playsinline></video><iframe v-else-if="demoEmbed" :src="demoEmbed" :title="tr('demo')" allow="autoplay; encrypted-media; picture-in-picture" allowfullscreen></iframe></div>
    </div>
  </div>
</template>
