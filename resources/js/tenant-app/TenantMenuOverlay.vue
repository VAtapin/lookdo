<script setup lang="ts">
import AppIcon from "./AppIcon.vue";

defineProps<{
    app: any;
    copy: Record<string, string>;
    locale: string;
    isBrows: boolean;
}>();
const emit = defineEmits<{
    close: [];
    navigate: [screen: string];
    share: [];
    changeLocale: [locale: string];
}>();

const navigate = (screen: string) => {
    emit("navigate", screen);
    emit("close");
};
</script>

<template>
    <div class="ta-menu-overlay" @click.self="$emit('close')">
        <aside>
            <header>
                <img
                    :src="app.tenant.logo || '/brand/lookdo-mark.webp'"
                    alt=""
                />
                <div>
                    <b>{{ app.tenant.name }}</b
                    ><small>{{ app.template.name }}</small>
                </div>
                <button @click="$emit('close')">
                    <AppIcon name="close" />
                </button>
            </header>
            <nav>
                <button v-if="isBrows" @click="navigate('activity')">
                    <AppIcon name="calendar" />{{ copy.appointments
                    }}<AppIcon name="arrow" />
                </button>
                <button @click="navigate('contacts')">
                    <AppIcon name="phone" />{{ copy.contacts
                    }}<AppIcon name="arrow" />
                </button>
                <button @click="navigate('reviews')">
                    <AppIcon name="star" />{{ copy.reviews
                    }}<AppIcon name="arrow" />
                </button>
                <button @click="$emit('share')">
                    <AppIcon name="share" />{{ copy.share
                    }}<AppIcon name="arrow" />
                </button>
                <button @click="navigate('login')">
                    <AppIcon name="shield" />{{ copy.login
                    }}<AppIcon name="arrow" />
                </button>
            </nav>
            <label>
                {{ copy.language }}
                <select
                    :value="locale"
                    @change="
                        $emit(
                            'changeLocale',
                            ($event.target as HTMLSelectElement).value,
                        )
                    "
                >
                    <option value="de">Deutsch</option>
                    <option value="en">English</option>
                    <option value="ru">Русский</option>
                    <option value="uk">Українська</option>
                </select>
            </label>
            <small>{{ copy.powered }}</small>
        </aside>
    </div>
</template>
