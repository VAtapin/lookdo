<script setup lang="ts">
import { nextTick, ref, watch } from 'vue';
import { api } from '../../api';

const props = defineProps<{ modelValue: string }>();
const emit = defineEmits<{ 'update:modelValue': [value: string] }>();
const editor = ref<HTMLElement | null>(null);
const uploading = ref(false);
watch(() => props.modelValue, async (value) => {
    await nextTick();
    if (editor.value && editor.value.innerHTML !== (value || '')) editor.value.innerHTML = value || '';
}, { immediate: true });
function format(command: string, value?: string) {
    editor.value?.focus();
    document.execCommand(command, false, value);
    emit('update:modelValue', editor.value?.innerHTML || '');
}
function addLink() {
    const url = window.prompt('Link-Adresse');
    if (url) format('createLink', url);
}
async function upload(event: Event) {
    const input = event.target as HTMLInputElement;
    const file = input.files?.[0];
    if (!file) return;
    uploading.value = true;
    try {
        const body = new FormData(); body.append('file', file);
        const media = await api<any>('/control/content-media', { method: 'POST', body });
        const html = media.mime?.startsWith('video/')
            ? `<video controls src="${media.url}"></video>`
            : `<img decoding="async" src="${media.url}" alt="${media.name || ''}">`;
        editor.value?.focus(); document.execCommand('insertHTML', false, html);
        emit('update:modelValue', editor.value?.innerHTML || '');
    } finally { uploading.value = false; input.value = ''; }
}
</script>

<template>
    <div class="rich-editor">
        <div class="rich-toolbar">
            <button type="button" title="Fett" @click="format('bold')"><b>B</b></button>
            <button type="button" title="Kursiv" @click="format('italic')"><i>I</i></button>
            <button type="button" @click="format('formatBlock', 'h2')">H2</button>
            <button type="button" @click="format('formatBlock', 'p')">Text</button>
            <button type="button" @click="format('insertUnorderedList')">Liste</button>
            <button type="button" @click="addLink">Link</button>
            <label class="rich-upload"><input type="file" accept="image/*,video/mp4,video/webm,video/quicktime" @change="upload">{{ uploading ? 'Lädt…' : 'Foto / Video' }}</label>
        </div>
        <div ref="editor" class="rich-canvas" contenteditable="true" data-placeholder="Inhalt eingeben…" @input="emit('update:modelValue', ($event.target as HTMLElement).innerHTML)"></div>
    </div>
</template>
