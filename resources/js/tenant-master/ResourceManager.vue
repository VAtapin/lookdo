<script setup lang="ts">
import { reactive, ref } from "vue";
import { api } from "../api";

const props = defineProps<{
    tenantId: number;
    resources: any[];
    t: (key: string) => string;
}>();
const emit = defineEmits<{ changed: [] }>();
const busy = ref(false);
const error = ref("");
const resource = reactive<any>({
    id: null,
    name: "",
    kind: "staff",
    color: "#ff6b00",
    active: true,
    sort_order: 0,
});

function resetResource() {
    Object.assign(resource, {
        id: null,
        name: "",
        kind: "staff",
        color: "#ff6b00",
        active: true,
        sort_order: 0,
    });
}

function editResource(item: any) {
    Object.assign(resource, { ...item });
}

async function saveResource() {
    busy.value = true;
    error.value = "";
    try {
        const url = resource.id
            ? `/tenant/${props.tenantId}/workspace/resources/${resource.id}`
            : `/tenant/${props.tenantId}/workspace/resources`;
        await api(url, {
            method: resource.id ? "PUT" : "POST",
            body: JSON.stringify(resource),
        });
        resetResource();
        emit("changed");
    } catch (exception: any) {
        error.value = exception.message;
    } finally {
        busy.value = false;
    }
}

async function deleteResource() {
    if (!resource.id || !confirm(props.t("confirmDelete"))) return;
    busy.value = true;
    error.value = "";
    try {
        await api(
            `/tenant/${props.tenantId}/workspace/resources/${resource.id}`,
            { method: "DELETE" },
        );
        resetResource();
        emit("changed");
    } catch (exception: any) {
        error.value = exception.message;
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="mw-two">
        <article class="mw-panel">
            <h2>{{ t("resources") }}</h2>
            <p class="mw-muted">{{ t("resourcesHint") }}</p>
            <button
                v-for="item in resources"
                :key="item.id"
                type="button"
                class="mw-service-row mw-resource-row"
                @click="editResource(item)"
            >
                <i
                    class="mw-resource-color"
                    :style="{ background: item.color || '#ff6b00' }"
                ></i>
                <span
                    ><b>{{ item.name }}</b
                    ><small>{{ t(item.kind) }}</small></span
                >
                <em>{{ item.active ? t("active") : t("cancelled") }}</em>
            </button>
            <p v-if="!resources.length" class="mw-empty">
                {{ t("noResources") }}
            </p>
        </article>
        <form class="mw-form mw-panel" @submit.prevent="saveResource">
            <h2>{{ t(resource.id ? "editResource" : "addResource") }}</h2>
            <p v-if="error" class="mw-error">{{ error }}</p>
            <label
                >{{ t("name")
                }}<input v-model="resource.name" required maxlength="120"
            /></label>
            <label
                >{{ t("resourceType")
                }}<select v-model="resource.kind">
                    <option value="staff">{{ t("staff") }}</option>
                    <option value="room">{{ t("room") }}</option>
                    <option value="equipment">{{ t("equipment") }}</option>
                </select></label
            >
            <label
                >{{ t("color") }}<input v-model="resource.color" type="color"
            /></label>
            <label
                >{{ t("sortOrder")
                }}<input
                    v-model.number="resource.sort_order"
                    type="number"
                    min="0"
            /></label>
            <label class="mw-check"
                ><input v-model="resource.active" type="checkbox" />{{
                    t("active")
                }}</label
            >
            <button class="mw-primary" :disabled="busy">{{ t("save") }}</button>
            <button
                v-if="resource.id"
                type="button"
                class="mw-danger"
                :disabled="busy"
                @click="deleteResource"
            >
                {{ t("delete") }}
            </button>
            <button
                v-if="resource.id"
                type="button"
                class="mw-secondary"
                :disabled="busy"
                @click="resetResource"
            >
                {{ t("close") }}
            </button>
        </form>
    </div>
</template>
