import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import vue from '@vitejs/plugin-vue';
import { VitePWA } from 'vite-plugin-pwa';

export default defineConfig({
    build: {
        target: 'es2020',
        minify: 'oxc',
        cssMinify: 'lightningcss',
        sourcemap: false,
        reportCompressedSize: true,
    },
    plugins: [
        laravel({ input: ['resources/css/app.css', 'resources/js/app.ts'], refresh: true }),
        vue(),
        VitePWA({
            registerType: 'autoUpdate',
            manifest: {
                name: 'LOOKDO',
                short_name: 'LOOKDO',
                theme_color: '#0a0a0b',
                background_color: '#0a0a0b',
                display: 'standalone',
                start_url: '/',
                icons: [
                    { src: '/icons/icon-192.png', sizes: '192x192', type: 'image/png' },
                    { src: '/icons/icon-512.png', sizes: '512x512', type: 'image/png' },
                ],
            },
            workbox: {
                navigateFallbackDenylist: [/^\/api\//, /^\/control\//],
                importScripts: ['/push-sw.js'],
                globPatterns: [
                    'assets/app-*.js',
                    'assets/TenantPublicView-*.js',
                    'assets/TenantPublicView-*.css',
                ],
            },
        }),
    ],
});
