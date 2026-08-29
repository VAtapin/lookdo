<script setup lang="ts">
import AdminPagination from "./AdminPagination.vue";
import ControlCustomerTables from "./ControlCustomerTables.vue";
import ControlOperationsTables from "./ControlOperationsTables.vue";
import ControlTemplateTables from "./ControlTemplateTables.vue";
import RegistryToolbar from "./RegistryToolbar.vue";

const props = defineProps<{ ctx: any }>();
const ctx = props.ctx;
const {
    section,
    data,
    filters,
    pager,
    addLabels,
    busy,
    openAdd,
    load,
    statusOptions,
    sortOptions,
    backupAction,
    changePage,
} = ctx;
</script>
<template>
    <section class="admin-section registry-page">
        <div v-if="section === 'sms'" class="sms-summary">
            <article>
                <span>Versendet im Monat</span
                ><strong>{{ data.summary?.sent || 0 }}</strong>
            </article>
            <article>
                <span>Zugestellt</span
                ><strong>{{ data.summary?.delivered || 0 }}</strong>
            </article>
            <article>
                <span>Fehlgeschlagen</span
                ><strong>{{ data.summary?.failed || 0 }}</strong>
            </article>
            <article>
                <span>Kosten im Monat</span
                ><strong
                    >{{ Number(data.summary?.cost || 0).toFixed(2) }}
                    {{ data.summary?.currency || "EUR" }}</strong
                >
            </article>
        </div>
        <RegistryToolbar
            v-model:search="filters.search"
            :total="pager.total"
            :add-label="addLabels[section]"
            :busy="busy"
            @add="openAdd"
            @refresh="load"
        >
            <select v-if="section === 'templates'" v-model="filters.secondary">
                <option value="">Alle Typen</option>
                <option value="category">Kategorien</option>
                <option value="variation">Varianten</option>
                <option value="template">Vorlagen</option>
            </select>
            <select
                v-else-if="section === 'subscriptions'"
                v-model="filters.secondary"
            >
                <option value="">Alle Anbieter</option>
                <option value="stripe">Stripe</option>
                <option value="lookdo">LOOKDO-Testphase</option></select
            ><select v-else-if="section === 'ai'" v-model="filters.secondary">
                <option value="">Alle Sprachen</option>
                <option value="de">Deutsch</option>
                <option value="en">Englisch</option>
                <option value="ru">Russisch</option>
                <option value="uk">Ukrainisch</option></select
            ><select
                v-else-if="section === 'classifications'"
                v-model="filters.secondary"
            >
                <option value="">Alle Quellen</option>
                <option value="dictionary">Wörterbuch</option>
                <option value="fuzzy">Ähnlichkeit</option>
                <option value="ai">KI</option>
                <option value="fallback">Standard</option>
            </select>
            <select v-if="statusOptions[section]" v-model="filters.status">
                <option value="">Alle Status</option>
                <option
                    v-for="option in statusOptions[section]"
                    :key="option[0]"
                    :value="option[0]"
                >
                    {{ option[1] }}
                </option></select
            ><select v-model="filters.sort">
                <option
                    v-for="option in sortOptions[section] || []"
                    :key="option[0]"
                    :value="option[0]"
                >
                    {{ option[1] }}
                </option></select
            ><button
                type="button"
                class="sort-direction"
                @click="
                    filters.direction =
                        filters.direction === 'asc' ? 'desc' : 'asc'
                "
            >
                {{ filters.direction === "asc" ? "↑" : "↓" }}</button
            ><select v-model.number="filters.per_page">
                <option :value="10">10</option>
                <option :value="25">25</option>
                <option :value="50">50</option>
                <option :value="100">100</option>
            </select></RegistryToolbar
        >
        <div class="admin-table-wrap">
            <table>
                <ControlCustomerTables :ctx="ctx" />

                <ControlTemplateTables :ctx="ctx" />
                <ControlOperationsTables :ctx="ctx" />
            </table>
        </div>
        <div v-if="section === 'administrators'" class="registry-context">
            <span
                >Super-Administratoren werden aus Sicherheitsgründen
                ausschließlich über den geschützten Serverbefehl angelegt.
                Kundenbenutzer können hier nicht zu Administratoren gemacht
                werden.</span
            >
        </div>
        <div v-if="section === 'backups'" class="registry-context">
            <span>Speicherort: {{ data.path }}</span
            ><span>Aufbewahrung: {{ data.keep }}</span
            ><button
                class="button"
                :disabled="busy"
                @click="backupAction('create')"
            >
                ＋ Backup erstellen
            </button>
        </div>
        <AdminPagination
            :current="pager.current"
            :last="pager.last"
            :from="pager.from"
            :to="pager.to"
            :total="pager.total"
            @change="changePage"
        />
    </section>
</template>
