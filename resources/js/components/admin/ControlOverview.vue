<script setup lang="ts">
import AdminSettings from "./AdminSettings.vue";

const props = defineProps<{ ctx: any }>();
const ctx = props.ctx;
const { section, data, metricLabels, formatDate, busy, syncAllPlans } = ctx;
</script>
<template>
    <section v-if="section === 'dashboard'" class="admin-dashboard">
        <div class="admin-metrics">
            <RouterLink
                v-for="metric in data.metrics"
                :key="metric.key"
                :to="metric.to"
                ><span>{{ metricLabels[metric.key] || metric.key }}</span
                ><strong>{{
                    metric.key === "mrr"
                        ? `${Number(metric.value).toFixed(2)} €`
                        : metric.value
                }}</strong
                ><small>Öffnen →</small></RouterLink
            >
        </div>
        <div class="admin-dashboard-columns">
            <section class="admin-dashboard-panel">
                <div class="dashboard-panel-head">
                    <div>
                        <p class="eyebrow">HEUTE</p>
                        <h2>Zu erledigen</h2>
                    </div>
                    <span>{{ data.tasks.length }}</span>
                </div>
                <div v-if="data.tasks.length" class="admin-task-list">
                    <RouterLink
                        v-for="task in data.tasks"
                        :key="task.key"
                        :to="task.to"
                        :class="`severity-${task.severity}`"
                        ><i>{{ task.count }}</i
                        ><span
                            ><b>{{ task.title }}</b
                            ><small>{{ task.description }}</small></span
                        ><em>→</em></RouterLink
                    >
                </div>
                <div v-else class="dashboard-empty">
                    Keine offenen Pflichtaufgaben. Die Plattform ist vollständig
                    eingerichtet.
                </div>
            </section>
            <section class="admin-dashboard-panel">
                <div class="dashboard-panel-head">
                    <div>
                        <p class="eyebrow">AKTUELL</p>
                        <h2>Neueste Aktivitäten</h2>
                    </div>
                    <RouterLink to="/control/audit">Alle →</RouterLink>
                </div>
                <div class="admin-activity-list">
                    <RouterLink
                        v-for="activity in data.recent"
                        :key="activity.id"
                        :to="activity.to"
                        ><span
                            ><b>{{ activity.title }}</b
                            ><small>{{ activity.description }}</small></span
                        ><time>{{
                            formatDate(activity.created_at)
                        }}</time></RouterLink
                    >
                </div>
            </section>
        </div>
    </section>
    <AdminSettings v-else-if="section === 'settings'" :data="data" />
    <section v-else-if="section === 'stripe'" class="stripe-status">
        <article>
            <p class="eyebrow">VERBINDUNG</p>
            <h2>
                {{
                    data.configured
                        ? "Stripe verbunden"
                        : "Stripe nicht konfiguriert"
                }}
            </h2>
            <p v-if="data.account">
                {{ data.account.id }} ·
                {{ data.account.livemode ? "Live-Modus" : "Testmodus" }}
                · {{ data.account.country }}
            </p>
        </article>
        <article>
            <p class="eyebrow">WEBHOOK</p>
            <h2>
                {{ data.webhook_configured ? "Bereit" : "Fehlt" }}
            </h2>
            <p>{{ data.plans_pending }} Tarife warten auf Synchronisierung.</p>
        </article>
        <button
            class="button"
            :disabled="busy || !data.configured"
            @click="syncAllPlans"
        >
            Tarife synchronisieren
        </button>
    </section>
</template>
