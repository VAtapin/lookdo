<script setup lang="ts">
import AppIcon from "./AppIcon.vue";

const props = defineProps<{ ctx: any }>();
defineEmits<{ home: [] }>();
const {
    app,
    copy,
    contactName,
    loginEmail,
    loginPassword,
    loginRemember,
    loginError,
    loginBusy,
    login,
} = props.ctx;
</script>

<template>
    <section class="ta-login-screen">
        <button class="ta-login-back" @click="$emit('home')">
            <AppIcon name="back" />{{ copy.back }}
        </button>
        <img
            class="ta-login-logo"
            :src="app.tenant.logo || '/brand/lookdo-mark.webp'"
            alt=""
        />
        <h1>{{ copy.login }}</h1>
        <p>{{ copy.welcome }}, {{ contactName }}</p>
        <form @submit.prevent="login">
            <label>
                <span>{{ copy.emailOrLogin }}</span>
                <div>
                    <AppIcon name="user" />
                    <input
                        v-model="loginEmail"
                        type="email"
                        autocomplete="email"
                        required
                    />
                </div>
            </label>
            <label>
                <span>{{ copy.password }}</span>
                <div>
                    <AppIcon name="shield" />
                    <input
                        v-model="loginPassword"
                        type="password"
                        autocomplete="current-password"
                        required
                    />
                </div>
            </label>
            <div class="ta-login-options">
                <label>
                    <input v-model="loginRemember" type="checkbox" />
                    {{ copy.remember }}
                </label>
                <button type="button">{{ copy.forgot }}</button>
            </div>
            <p v-if="loginError" class="ta-error">{{ loginError }}</p>
            <button class="ta-gold-button" :disabled="loginBusy">
                {{ loginBusy ? "…" : copy.signIn }}
            </button>
        </form>
        <article>
            <AppIcon name="shield" :size="34" />
            <div>
                <h2>{{ copy.adminOnly }}</h2>
                <p>{{ copy.adminOnlyText }}</p>
            </div>
        </article>
        <small>{{ copy.powered }}</small>
    </section>
</template>
