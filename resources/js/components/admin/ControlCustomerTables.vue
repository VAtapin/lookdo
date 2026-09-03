<script setup lang="ts">
const props = defineProps<{ ctx: any }>();
const {
    section,
    rows,
    openTenant,
    openSubscription,
    tenantAccessClass,
    tenantAccessLabel,
    formatDate,
    subscriptionAccessClass,
    subscriptionAccessLabel,
    activeEntitlements,
    editPlan,
    syncPlan,
} = props.ctx;
</script>
<template>
    <thead v-if="section === 'tenants'">
        <tr>
            <th>Kunde</th>
            <th>Inhaber</th>
            <th>Vorlage</th>
            <th>Domain</th>
            <th>Tarif</th>
            <th>Zugang</th>
            <th>Konto</th>
        </tr>
    </thead>
    <tbody v-if="section === 'tenants'">
        <tr
            v-for="item in rows"
            :key="item.id"
            class="clickable"
            @click="openTenant(item)"
        >
            <td>
                <b>{{ item.name }}</b
                ><small>{{ item.slug }}</small>
            </td>
            <td>
                <b>{{ item.users?.[0]?.name || "—" }}</b
                ><small>{{ item.users?.[0]?.email || "—" }}</small>
            </td>
            <td>
                {{
                    item.business_profile?.variation?.name?.de ||
                    item.business_profile?.variation?.code ||
                    "Standard"
                }}
            </td>
            <td>
                {{ item.primary_domain?.domain || "—" }}
            </td>
            <td>
                {{
                    item.current_subscription?.plan?.name?.de ||
                    item.current_subscription?.plan?.code ||
                    "—"
                }}
            </td>
            <td>
                <span class="table-status" :class="tenantAccessClass(item)">{{
                    tenantAccessLabel(item)
                }}</span>
            </td>
            <td>
                <span class="table-status" :class="item.status">{{
                    item.status === "active" ? "technisch aktiv" : item.status
                }}</span>
            </td>
        </tr>
    </tbody>

    <thead v-if="section === 'administrators'">
        <tr>
            <th>Administrator</th>
            <th>Rolle</th>
            <th>Letzte Anmeldung</th>
            <th>Seit</th>
            <th>Status</th>
        </tr>
    </thead>
    <tbody v-if="section === 'administrators'">
        <tr v-for="item in rows" :key="item.id">
            <td>
                <b>{{ item.name }}</b
                ><small>{{ item.email }}</small>
            </td>
            <td>Super Administrator</td>
            <td>
                {{ formatDate(item.last_login_at) }}
            </td>
            <td>{{ formatDate(item.created_at) }}</td>
            <td>
                {{ item.is_active ? "aktiv" : "gesperrt" }}
            </td>
        </tr>
    </tbody>
    <thead v-if="section === 'subscriptions'">
        <tr>
            <th>Kunde</th>
            <th>Tarif</th>
            <th>Anbieter</th>
            <th>Status</th>
            <th>Periodenende</th>
            <th>Rabatt</th>
            <th>Provider-ID</th>
            <th>Dokumente</th>
            <th>Aktionen</th>
        </tr>
    </thead>
    <tbody v-if="section === 'subscriptions'">
        <tr v-for="item in rows" :key="item.id" class="clickable" @click="openSubscription(item)">
            <td>
                <b>{{ item.tenant?.name }}</b
                ><small>{{ item.tenant?.slug }}</small>
            </td>
            <td>
                {{ item.plan?.name?.de || item.plan?.code }}
            </td>
            <td>
                {{ item.provider === "manual" ? "Manuell" : item.provider }}
            </td>
            <td>
                <span
                    class="table-status"
                    :class="subscriptionAccessClass(item)"
                    >{{ subscriptionAccessLabel(item) }}</span
                >
            </td>
            <td>
                {{ formatDate(item.current_period_end) }}
            </td>
            <td>{{ item.discount_percent }}%</td>
            <td>
                <small>{{ item.provider_subscription_id || "—" }}</small>
            </td>
            <td><small>{{ item.invoices_count || 0 }} Rechnungen · {{ item.payments_count || 0 }} Zahlungen</small></td>
            <td class="table-actions">
                <button type="button" @click.stop="openSubscription(item, 'details')">Details</button>
                <button type="button" @click.stop="openSubscription(item, 'invoice')">Rechnung</button>
                <button type="button" @click.stop="openSubscription(item, 'payment')">Barzahlung</button>
            </td>
        </tr>
    </tbody>
    <thead v-if="section === 'plans'">
        <tr>
            <th>Tarif</th>
            <th>Monat</th>
            <th>Jahr</th>
            <th>Module</th>
            <th>Kunden</th>
            <th>Sichtbarkeit</th>
            <th>Stripe</th>
            <th>Aktionen</th>
        </tr>
    </thead>
    <tbody v-if="section === 'plans'">
        <tr v-for="item in rows" :key="item.id">
            <td>
                <span class="plan-table-name"
                    ><img
                        loading="lazy"
                        decoding="async"
                        v-if="item.image_url"
                        :src="item.image_url"
                        alt=""
                    /><span
                        ><b>{{ item.name?.de || item.code }}</b
                        ><small>{{ item.code }}</small></span
                    ></span
                >
            </td>
            <td>
                {{ item.price_monthly }}
                {{ item.currency }}
            </td>
            <td>
                {{ item.price_yearly || "—" }}
                {{ item.currency }}
            </td>
            <td>{{ activeEntitlements(item) }} aktiv</td>
            <td>{{ item.subscriptions_count }}</td>
            <td>
                {{
                    item.is_active
                        ? item.is_public
                            ? "öffentlich"
                            : "intern"
                        : "archiviert"
                }}
            </td>
            <td>
                {{
                    item.stripe_synced_at
                        ? "synchron"
                        : item.stripe_sync_error || "offen"
                }}
            </td>
            <td class="table-actions">
                <button @click="editPlan(item)">Bearbeiten</button
                ><button @click="syncPlan(item)">Stripe</button>
            </td>
        </tr>
    </tbody>
</template>
