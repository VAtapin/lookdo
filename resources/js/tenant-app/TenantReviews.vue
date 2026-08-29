<script setup lang="ts">
import AppIcon from "./AppIcon.vue";

const props = defineProps<{
    app: any;
    copy: Record<string, string>;
    locale: string;
    averageRating: string | number;
    notice: string;
    open: boolean;
    busy: boolean;
    form: { rating: number; body: string };
}>();
const emit = defineEmits<{
    close: [];
    submit: [];
    "update:open": [value: boolean];
}>();

const ratingCount = (rating: number) =>
    props.app.reviews.filter(
        (review: any) => Math.round(review.rating) === rating,
    ).length;
const ratingWidth = (rating: number) =>
    props.app.reviews.length
        ? (ratingCount(rating) / props.app.reviews.length) * 100
        : 0;
</script>

<template>
    <section class="ta-page ta-reviews-page">
        <header class="ta-simple-header">
            <button @click="$emit('close')">
                <AppIcon name="back" />{{ copy.back }}
            </button>
            <h1>{{ copy.reviews }}</h1>
            <div class="ta-contact-shortcuts">
                <a
                    v-if="app.tenant.contact.phone"
                    :href="'tel:' + app.tenant.contact.phone"
                >
                    <AppIcon name="phone" />
                </a>
            </div>
        </header>
        <p class="ta-centered">{{ copy.reviewsSubtitle }}</p>
        <article class="ta-rating-summary">
            <div>
                <strong>{{ averageRating }}</strong
                ><span>★★★★★</span>
                <small>{{ copy.basedOn }} {{ app.reviews.length }}</small>
            </div>
            <div>
                <p v-for="n in [5, 4, 3, 2, 1]" :key="n">
                    <b>{{ n }} ★</b
                    ><i
                        ><span
                            :style="{ width: ratingWidth(n) + '%' }"
                        ></span></i
                    ><em>{{ ratingCount(n) }}</em>
                </p>
            </div>
        </article>
        <div class="ta-review-list">
            <article v-for="review in app.reviews" :key="review.id">
                <header>
                    <img :src="'/brand/lookdo-mark.webp'" alt="" />
                    <div>
                        <h2>{{ review.author || app.tenant.name }}</h2>
                        <span>{{ "★".repeat(Math.round(review.rating)) }}</span>
                    </div>
                    <time>{{
                        review.received_at
                            ? new Date(review.received_at).toLocaleDateString(
                                  locale,
                              )
                            : ""
                    }}</time>
                </header>
                <p>{{ review.body }}</p>
                <blockquote v-if="review.master_reply">
                    <strong>{{ copy.masterReply }}</strong
                    ><span>{{ review.master_reply }}</span>
                </blockquote>
            </article>
        </div>
        <p v-if="notice" class="ta-review-notice">{{ notice }}</p>
        <button
            v-if="app.session?.known && !open"
            class="ta-outline-button"
            @click="$emit('update:open', true)"
        >
            {{ copy.leaveReview }}
        </button>
        <article v-else-if="app.session?.known" class="ta-review-form">
            <h2>{{ copy.leaveReview }}</h2>
            <label>
                <span>{{ copy.reviewRating }}</span>
                <select v-model.number="form.rating">
                    <option v-for="n in [5, 4, 3, 2, 1]" :key="n" :value="n">
                        {{ n }} ★
                    </option>
                </select>
            </label>
            <label>
                <span>{{ copy.reviewText }}</span>
                <textarea
                    v-model="form.body"
                    rows="5"
                    maxlength="3000"
                ></textarea>
            </label>
            <div>
                <button
                    class="ta-outline-button"
                    @click="$emit('update:open', false)"
                >
                    {{ copy.later }}
                </button>
                <button
                    class="ta-gold-button"
                    :disabled="busy || !form.body.trim()"
                    @click="$emit('submit')"
                >
                    {{ copy.reviewSend }}
                </button>
            </div>
        </article>
        <p v-else class="ta-centered">{{ copy.reviewLoginRequired }}</p>
    </section>
</template>
