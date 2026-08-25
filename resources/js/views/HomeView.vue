<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { api } from '../api';
import LineIcon from '../components/LineIcon.vue';
import { locale, tr } from '../i18n';

defineProps<{ pricingOnly?: boolean }>();
const plans = ref<any[]>([]);
onMounted(async () => { plans.value = (await api('/platform')).plans; });
const workflow = [['photo', 'howShow'], ['phone', 'howPhone'], ['bell', 'howReceive'], ['chat', 'howReply']];
const audiences = [['car', 'audienceAuto'], ['door', 'audienceDoors'], ['washer', 'audienceAppliance'], ['sofa', 'audienceFurniture'], ['leaf', 'audienceGarden'], ['service', 'audienceCleaning']];
const benefits = [['globe', 'benefitDomainTitle', 'linkDomain'], ['photo', 'benefitMediaTitle', 'photosVideos'], ['bell', 'benefitPushTitle', 'messagesPush'], ['star', 'benefitReviewTitle', 'reviews']];
const faqs = {
    de: [['Müssen Kunden etwas installieren?', 'Nein. Die App öffnet sich direkt über einen Link.'], ['Kann ich meine Domain nutzen?', 'Ja. Jede App erhält sofort eine LOOKDO Adresse; eine eigene Domain kann zusätzlich verbunden werden.']],
    en: [['Do customers install anything?', 'No. The app opens directly from a link.'], ['Can I use my domain?', 'Yes. Every app receives a LOOKDO address immediately; a custom domain can also be connected.']],
    ru: [['Клиенту нужно что-то устанавливать?', 'Нет. Приложение сразу открывается по ссылке.'], ['Можно использовать свой домен?', 'Да. Приложение сразу получает адрес LOOKDO, а собственный домен можно подключить дополнительно.']],
};
</script>

<template>
  <div class="public-home">
    <section v-if="!pricingOnly" class="public-hero">
      <div class="public-hero-copy">
        <h1><b>LOOK.</b> <span>DO.</span></h1>
        <h2>{{ tr('heroTagline') }}</h2>
        <h3>{{ tr('heroProduct') }}</h3>
        <p>{{ tr('heroText') }}</p>
        <div class="hero-actions"><RouterLink class="button" :to="`/${locale}/register`">{{ tr('create') }}</RouterLink><a class="button ghost" href="#demo"><span class="play-dot">▶</span>{{ tr('demo') }}</a></div>
        <div class="hero-benefits"><span><LineIcon name="shield"/>{{ tr('heroBenefitBrand') }}</span><span><LineIcon name="bolt"/>{{ tr('heroBenefitFast') }}</span><span><LineIcon name="lock"/>{{ tr('heroBenefitSafe') }}</span></div>
      </div>
      <div id="demo" class="public-phone-stage">
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

    <section v-if="!pricingOnly" id="features" class="compact-section benefits-section"><h2>{{ tr('whyTitle').split('.')[0] }} LOOKDO</h2><div class="benefits-grid"><article v-for="item in benefits" :key="item[1]"><LineIcon :name="item[0]"/><div><b>{{ tr(item[1]) }}</b><small>{{ tr(item[2]) }}</small></div></article></div></section>

    <section id="pricing" class="compact-section public-pricing"><h2>{{ tr('pricing') }}</h2><div class="pricing-grid"><article v-for="plan in plans" :key="plan.id" class="price-card" :class="{ featured: plan.badge }"><span v-if="plan.badge" class="badge">{{ plan.badge }}</span><h3>{{ plan.name }}</h3><p>{{ plan.description }}</p><div class="price"><strong>{{ plan.price_monthly }} €</strong><span>{{ tr('perMonth') }}</span></div><ul><li>✓ {{ tr('photosVideos') }}</li><li>✓ {{ tr('messagesPush') }}</li><li>✓ {{ tr('brandDomain') }}</li></ul><RouterLink class="button full" :to="`/${locale}/register?plan=${plan.id}`">{{ tr('choose') }}</RouterLink></article></div></section>

    <section v-if="!pricingOnly" class="compact-section public-faq"><h2>{{ tr('faqTitle') }}</h2><div><details v-for="item in faqs[locale]" :key="item[0]"><summary>{{ item[0] }}<span>＋</span></summary><p>{{ item[1] }}</p></details></div></section>
  </div>
</template>
