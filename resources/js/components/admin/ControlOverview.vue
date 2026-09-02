<script setup lang="ts">
import AdminSettings from "./AdminSettings.vue";

const props = defineProps<{ ctx: any }>();
const ctx = props.ctx;
const { section, data, metricLabels, formatDate, busy, syncAllPlans } = ctx;
const money = (value: any, currency = "USD") => `${Number(value || 0).toFixed(4)} ${currency.toUpperCase()}`;
const integer = (value: any) => new Intl.NumberFormat("de-DE").format(Number(value || 0));
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
                        : metric.key.includes("spend")
                          ? metric.value === null ? "Nicht verbunden" : money(metric.value)
                        : metric.value
                }}</strong
                ><small>Öffnen →</small></RouterLink
            >
        </div>
        <section class="admin-dashboard-panel ai-cost-panel">
            <div class="dashboard-panel-head">
                <div><p class="eyebrow">KI-KONTROLLE · DIESER MONAT</p><h2>LOOKDO und OpenAI im Abgleich</h2></div>
                <a :href="data.ai_usage.openai.dashboard_url" target="_blank" rel="noopener">OpenAI Usage ↗</a>
            </div>
            <div class="ai-cost-summary">
                <article><span>LOOKDO intern</span><strong>{{ money(data.ai_usage.local.month_cost) }}</strong><small>{{ integer(data.ai_usage.local.requests) }} Anfragen · {{ integer(data.ai_usage.local.input_tokens + data.ai_usage.local.output_tokens) }} Tokens · {{ integer(data.ai_usage.local.images) }} Bilder</small><progress :value="data.ai_usage.local.month_cost" :max="Math.max(data.ai_usage.local.budget, 1)"></progress><em>Budget {{ money(data.ai_usage.local.budget) }}</em></article>
                <article :class="`status-${data.ai_usage.openai.status}`"><span>OpenAI-Abrechnung</span><template v-if="data.ai_usage.openai.status === 'connected'"><strong>{{ money(data.ai_usage.openai.month_cost, data.ai_usage.openai.currency) }}</strong><small>{{ integer(data.ai_usage.openai.requests) }} Anfragen · {{ integer(data.ai_usage.openai.input_tokens + data.ai_usage.openai.output_tokens) }} Tokens · {{ integer(data.ai_usage.openai.images) }} Bilder</small><div v-if="data.ai_usage.openai.line_items.length" class="ai-line-items"><span v-for="item in data.ai_usage.openai.line_items" :key="item.name"><b>{{ item.name }}</b><em>{{ money(item.cost, data.ai_usage.openai.currency) }}</em></span></div><em>Synchronisiert {{ formatDate(data.ai_usage.openai.synced_at) }}</em></template><template v-else><strong>{{ data.ai_usage.openai.configured ? 'Synchronisierung fehlgeschlagen' : 'Noch nicht verbunden' }}</strong><small>{{ data.ai_usage.openai.error || 'Admin Key hinterlegen, um die offizielle OpenAI-Abrechnung zu sehen.' }}</small><RouterLink to="/control/settings/openai">OpenAI verbinden →</RouterLink></template></article>
            </div>
            <div v-if="data.ai_usage.local.by_tenant.length" class="ai-tenant-usage"><h3>Interner Verbrauch nach Kunde</h3><div><span v-for="tenant in data.ai_usage.local.by_tenant" :key="tenant.tenant_id || 'platform'"><b>{{ tenant.tenant_name }}</b><small>{{ integer(tenant.requests) }} Anfragen · {{ integer(tenant.tokens) }} Tokens</small><em>{{ money(tenant.cost) }}</em></span></div></div>
        </section>
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
