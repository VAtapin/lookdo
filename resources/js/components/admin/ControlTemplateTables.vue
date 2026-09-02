<script setup lang="ts">
const props = defineProps<{ ctx: any }>();
const {
    section,
    rows,
    editTemplate,
    hasEditableTemplate,
    toggleCatalog,
    togglePhrase,
    formatDate,
    editPage,
} = props.ctx;
</script>
<template>
    <thead v-if="section === 'templates'">
        <tr>
            <th>Typ</th>
            <th>Name</th>
            <th>Code</th>
            <th>Kategorie / Eltern</th>
            <th>Priorität / Version</th>
            <th>Status</th>
            <th>Aktionen</th>
        </tr>
    </thead>
    <tbody v-if="section === 'templates'">
        <tr
            v-for="item in rows"
            :key="`${item.kind}-${item.id}`"
            :class="{
                clickable: hasEditableTemplate(item),
            }"
            @click="hasEditableTemplate(item) && editTemplate(item)"
        >
            <td>
                {{
                    item.kind === "category"
                        ? "Kategorie"
                        : item.kind === "variation"
                          ? "Variante"
                          : "Vorlage"
                }}
            </td>
            <td>
                <b>{{ item.label }}</b>
            </td>
            <td>
                <code>{{ item.code }}</code>
            </td>
            <td>
                {{ item.categoryLabel || item.parent_code || "—" }}
            </td>
            <td>
                {{
                    item.kind === "template"
                        ? `v${item.version}`
                        : (item.priority ?? item.sort_order)
                }}
            </td>
            <td>
                {{ item.enabled ? "aktiv" : "inaktiv" }}
            </td>
            <td class="table-actions">
                <button
                    v-if="hasEditableTemplate(item)"
                    @click.stop="editTemplate(item)"
                >
                    {{ item.kind === "template" ? "Bearbeiten" : "App bearbeiten" }}</button
                ><button @click.stop="toggleCatalog(item)">
                    {{ item.enabled ? "Deaktivieren" : "Aktivieren" }}
                </button>
            </td>
        </tr>
    </tbody>
    <thead v-if="section === 'ai'">
        <tr>
            <th>Begriff</th>
            <th>Sprache</th>
            <th>Kategorie</th>
            <th>Variante</th>
            <th>Gewichtung</th>
            <th>Status</th>
            <th>Aktion</th>
        </tr>
    </thead>
    <tbody v-if="section === 'ai'">
        <tr v-for="item in rows" :key="item.id">
            <td>
                <b>{{ item.phrase }}</b
                ><small>{{ item.normalized_phrase }}</small>
            </td>
            <td>{{ item.locale.toUpperCase() }}</td>
            <td>
                {{ item.category?.name?.de || item.category?.code }}
            </td>
            <td>
                {{
                    item.variation?.name?.de ||
                    item.variation?.code ||
                    "Standard"
                }}
            </td>
            <td>{{ item.weight }}</td>
            <td>
                {{ item.enabled ? "aktiv" : "inaktiv" }}
            </td>
            <td class="table-actions">
                <button @click="togglePhrase(item)">
                    {{ item.enabled ? "Deaktivieren" : "Aktivieren" }}
                </button>
            </td>
        </tr>
    </tbody>
    <thead v-if="section === 'classifications'">
        <tr>
            <th>Datum</th>
            <th>Eingabe</th>
            <th>Kunde</th>
            <th>Ergebnis</th>
            <th>Sicherheit</th>
            <th>Quelle</th>
            <th>Bestätigt</th>
        </tr>
    </thead>
    <tbody v-if="section === 'classifications'">
        <tr v-for="item in rows" :key="item.id">
            <td>{{ formatDate(item.created_at) }}</td>
            <td>
                <b>{{ item.original_text }}</b
                ><small>{{ item.normalized_text }}</small>
            </td>
            <td>
                {{ item.tenant?.name || "Registrierung" }}
            </td>
            <td>
                {{ item.category?.name?.de || item.category?.code || "Standard"
                }}<small>{{
                    item.variation?.name?.de ||
                    item.variation?.code ||
                    "allgemein"
                }}</small>
            </td>
            <td>{{ Math.round(Number(item.confidence) * 100) }}%</td>
            <td>
                {{ item.source }}<small>{{ item.ai_model }}</small>
            </td>
            <td>
                {{ item.confirmed_by_user_at ? "ja" : "nein" }}
            </td>
        </tr>
    </tbody>
    <thead v-if="section === 'content'">
        <tr>
            <th>Seite</th>
            <th>URL</th>
            <th>Status</th>
            <th>Geändert</th>
            <th>Aktion</th>
        </tr>
    </thead>
    <tbody v-if="section === 'content'">
        <tr v-for="item in rows" :key="item.id">
            <td>
                <b>{{ item.label }}</b
                ><small>{{ item.title?.ru || item.title?.en }}</small>
            </td>
            <td>
                <code>/{{ item.key }}</code>
            </td>
            <td>
                {{ item.is_published ? "veröffentlicht" : "Entwurf" }}
            </td>
            <td>{{ formatDate(item.updated_at) }}</td>
            <td class="table-actions">
                <button @click="editPage(item)">Bearbeiten</button>
            </td>
        </tr>
    </tbody>
</template>
