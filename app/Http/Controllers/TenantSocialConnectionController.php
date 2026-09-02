<?php

namespace App\Http\Controllers;

use App\Http\Controllers\Concerns\AuthorizesTenantWorkspace;
use App\Models\Tenant;
use App\Models\TenantSocialDraft;
use App\Services\SocialPublishingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class TenantSocialConnectionController extends Controller
{
    use AuthorizesTenantWorkspace;

    public function authorizeProvider(Request $request, Tenant $tenant, string $provider): JsonResponse
    {
        $this->authorizeWorkspace($request, $tenant);
        abort_unless(in_array($provider, SocialPublishingService::DIRECT_PROVIDERS, true), 404);
        abort_unless($this->providerConfigured($tenant, $provider), 503, 'SOCIAL_PROVIDER_NOT_CONFIGURED');
        if ($provider === 'telegram') {
            return $this->connectTelegramTarget($request, $tenant);
        }
        // Telegram limits deep-link payloads to 64 characters. The prefix plus
        // this state stays within that limit while retaining ample entropy.
        $state = Str::random(48);
        Cache::put('social-oauth:'.hash('sha256', $state), ['tenant_id' => $tenant->id, 'user_id' => $request->user()->id, 'provider' => $provider], now()->addMinutes(15));

        return response()->json(['authorization_url' => $this->authorizationUrl($tenant, $provider, $state)]);
    }

    public function saveProviderConfig(Request $request, Tenant $tenant, string $provider): JsonResponse
    {
        $this->authorizeWorkspace($request, $tenant);
        abort_unless(in_array($provider, SocialPublishingService::DIRECT_PROVIDERS, true), 404);
        $existing = (array) $tenant->socialProviderConfigs()->where('provider', $provider)->first()?->credentials;
        $rules = $provider === 'telegram'
            ? ['bot_token' => ['nullable', 'string', 'max:255']]
            : ['client_id' => ['required', 'string', 'max:255'], 'client_secret' => ['nullable', 'string', 'max:1000']];
        $data = $request->validate($rules);
        foreach ($provider === 'telegram' ? ['bot_token'] : ['client_secret'] as $secret) {
            if (blank($data[$secret] ?? null)) {
                $data[$secret] = $existing[$secret] ?? null;
            }
            abort_if(blank($data[$secret]), 422, 'SOCIAL_PROVIDER_SECRET_REQUIRED');
        }
        $tenant->socialProviderConfigs()->updateOrCreate(['provider' => $provider], ['credentials' => $data]);

        return response()->json(['configured' => true]);
    }

    public function callback(Request $request, string $provider): RedirectResponse
    {
        $state = (string) $request->query('state');
        $context = Cache::pull('social-oauth:'.hash('sha256', $state));
        abort_unless(is_array($context) && ($context['provider'] ?? null) === $provider, 419);
        $tenant = Tenant::findOrFail($context['tenant_id']);

        try {
            $attributes = match ($provider) {
                'facebook', 'instagram' => $this->connectMeta($request, $tenant, $provider),
                'vk' => $this->connectVk($request, $tenant),
                default => throw new RuntimeException('Unsupported OAuth provider.'),
            };
            $tenant->socialConnections()->updateOrCreate(['provider' => $provider], array_merge($attributes, [
                'connected_by_user_id' => $context['user_id'], 'status' => 'active', 'last_validated_at' => now(), 'last_error' => null,
            ]));
            $result = 'connected';
        } catch (Throwable $exception) {
            report($exception);
            $result = 'error';
        }

        return redirect('https://'.$tenant->slug.'.'.config('tenancy.platform_domain').'/app/work?tab=social&social='.$result.'&provider='.$provider);
    }

    public function disconnect(Request $request, Tenant $tenant, string $provider): JsonResponse
    {
        $this->authorizeWorkspace($request, $tenant);
        $tenant->socialConnections()->where('provider', $provider)->delete();

        return response()->json(['disconnected' => true]);
    }

    public function publish(Request $request, Tenant $tenant, TenantSocialDraft $draft, SocialPublishingService $publisher): JsonResponse
    {
        $this->authorizeWorkspace($request, $tenant);
        abort_unless($draft->tenant_id === $tenant->id, 404);
        $connection = $tenant->socialConnections()->where('provider', $draft->channel)->where('status', 'active')->first();
        abort_unless($connection, 409, 'SOCIAL_ACCOUNT_NOT_CONNECTED');
        $draft->forceFill(['publish_attempted_at' => now(), 'publish_error' => null])->save();
        try {
            $result = $publisher->publish($draft, $connection);
        } catch (Throwable $exception) {
            $draft->forceFill(['status' => 'failed', 'publish_error' => Str::limit($exception->getMessage(), 2000)])->save();
            $connection->forceFill(['last_error' => Str::limit($exception->getMessage(), 2000)])->save();

            return response()->json(['message' => $exception->getMessage(), 'draft' => $draft->fresh()], 422);
        }
        $draft->forceFill([
            'social_connection_id' => $connection->id, 'status' => 'published', 'published_at' => now(),
            'external_post_id' => $result['id'], 'external_post_url' => $result['url'] ?? null, 'publish_error' => null,
        ])->save();

        return response()->json(['draft' => $draft->fresh(), 'publication' => $result]);
    }

    public function telegramWebhook(Request $request): JsonResponse
    {
        $secret = (string) config('services.telegram.webhook_secret');
        abort_unless($secret !== '' && hash_equals($secret, (string) $request->header('X-Telegram-Bot-Api-Secret-Token')), 401);
        $message = (array) ($request->input('message') ?: $request->input('channel_post') ?: []);
        $text = (string) ($message['text'] ?? '');
        if (! preg_match('/^\/start\s+lookdo_([A-Za-z0-9]+)$/', $text, $match)) {
            return response()->json(['ok' => true]);
        }
        $context = Cache::pull('social-oauth:'.hash('sha256', $match[1]));
        if (! is_array($context) || ($context['provider'] ?? null) !== 'telegram') {
            return response()->json(['ok' => true]);
        }
        $chat = (array) ($message['chat'] ?? []);
        Tenant::findOrFail($context['tenant_id'])->socialConnections()->updateOrCreate(['provider' => 'telegram'], [
            'connected_by_user_id' => $context['user_id'], 'status' => 'active', 'external_account_id' => (string) ($chat['id'] ?? ''),
            'account_name' => $chat['title'] ?? $chat['username'] ?? trim(($chat['first_name'] ?? '').' '.($chat['last_name'] ?? '')),
            'credentials' => ['username' => $chat['username'] ?? null], 'scopes' => ['sendMessage', 'sendPhoto'], 'last_validated_at' => now(),
        ]);

        return response()->json(['ok' => true]);
    }

    private function authorizationUrl(Tenant $tenant, string $provider, string $state): string
    {
        if ($provider === 'telegram') {
            $username = ltrim((string) config('services.telegram.bot_username'), '@');
            abort_if($username === '', 503, 'TELEGRAM_NOT_CONFIGURED');

            return "https://t.me/{$username}?start=lookdo_{$state}";
        }
        $redirect = $this->callbackUrl($tenant, $provider);
        if (in_array($provider, ['facebook', 'instagram'], true)) {
            $credentials = $this->providerCredentials($tenant, $provider);
            $query = http_build_query([
                'client_id' => $credentials['client_id'], 'redirect_uri' => $redirect, 'state' => $state,
                'scope' => 'pages_show_list,pages_read_engagement,pages_manage_posts,instagram_basic,instagram_content_publish,business_management',
                'response_type' => 'code',
            ]);

            return 'https://www.facebook.com/'.config('services.social.meta.version').'/dialog/oauth?'.$query;
        }
        $credentials = $this->providerCredentials($tenant, $provider);
        $query = http_build_query([
            'client_id' => $credentials['client_id'], 'redirect_uri' => $redirect, 'display' => 'page', 'state' => $state,
            'scope' => 'wall,photos,groups,offline', 'response_type' => 'code', 'v' => config('services.social.vk.version'),
        ]);

        return 'https://oauth.vk.com/authorize?'.$query;
    }

    private function providerConfigured(Tenant $tenant, string $provider): bool
    {
        $credentials = $this->providerCredentials($tenant, $provider);

        return match ($provider) {
            'facebook', 'instagram', 'vk' => filled($credentials['client_id'] ?? null) && filled($credentials['client_secret'] ?? null),
            'telegram' => filled($credentials['bot_token'] ?? null),
            default => false,
        };
    }

    private function providerCredentials(Tenant $tenant, string $provider): array
    {
        return (array) $tenant->socialProviderConfigs()->where('provider', $provider)->first()?->credentials;
    }

    private function callbackUrl(Tenant $tenant, string $provider): string
    {
        return 'https://'.$tenant->slug.'.'.config('tenancy.platform_domain').'/api/social/oauth/'.$provider.'/callback';
    }

    private function connectMeta(Request $request, Tenant $tenant, string $provider): array
    {
        $credentials = $this->providerCredentials($tenant, $provider);
        $redirect = $this->callbackUrl($tenant, $provider);
        $shortLivedToken = Http::get('https://graph.facebook.com/'.config('services.social.meta.version').'/oauth/access_token', [
            'client_id' => $credentials['client_id'], 'client_secret' => $credentials['client_secret'],
            'redirect_uri' => $redirect, 'code' => $request->query('code'),
        ])->throw()->json('access_token');
        if (! is_string($shortLivedToken) || $shortLivedToken === '') {
            throw new RuntimeException('META_ACCESS_TOKEN_MISSING');
        }
        $longLived = Http::get('https://graph.facebook.com/'.config('services.social.meta.version').'/oauth/access_token', [
            'grant_type' => 'fb_exchange_token',
            'client_id' => $credentials['client_id'],
            'client_secret' => $credentials['client_secret'],
            'fb_exchange_token' => $shortLivedToken,
        ])->throw()->json();
        $token = (string) ($longLived['access_token'] ?? $shortLivedToken);
        $accounts = Http::withToken($token)->get('https://graph.facebook.com/'.config('services.social.meta.version').'/me/accounts', [
            'fields' => 'id,name,access_token,instagram_business_account{id,username}', 'limit' => 100,
        ])->throw()->json('data', []);
        $page = collect($accounts)->first(fn ($item) => $provider === 'facebook' || filled(data_get($item, 'instagram_business_account.id')));
        if (! $page) {
            throw new RuntimeException($provider === 'instagram' ? 'INSTAGRAM_BUSINESS_ACCOUNT_NOT_FOUND' : 'FACEBOOK_PAGE_NOT_FOUND');
        }
        $isInstagram = $provider === 'instagram';

        return [
            'external_account_id' => (string) ($isInstagram ? data_get($page, 'instagram_business_account.id') : $page['id']),
            'account_name' => (string) ($isInstagram ? data_get($page, 'instagram_business_account.username') : $page['name']),
            'credentials' => ['access_token' => $page['access_token'], 'page_id' => $page['id']],
            'scopes' => $isInstagram ? ['instagram_basic', 'instagram_content_publish'] : ['pages_manage_posts'],
            'expires_at' => ((int) ($longLived['expires_in'] ?? 0)) > 0 ? now()->addSeconds((int) $longLived['expires_in']) : null,
        ];
    }

    private function connectVk(Request $request, Tenant $tenant): array
    {
        $credentials = $this->providerCredentials($tenant, 'vk');
        $redirect = $this->callbackUrl($tenant, 'vk');
        $oauth = Http::get('https://oauth.vk.com/access_token', [
            'client_id' => $credentials['client_id'], 'client_secret' => $credentials['client_secret'],
            'redirect_uri' => $redirect, 'code' => $request->query('code'),
        ])->throw()->json();
        $groups = Http::asForm()->post('https://api.vk.com/method/groups.get', [
            'access_token' => $oauth['access_token'], 'filter' => 'admin', 'extended' => 1, 'v' => config('services.social.vk.version'),
        ])->throw()->json('response.items', []);
        $group = $groups[0] ?? null;

        return [
            'external_account_id' => (string) ($group ? -((int) $group['id']) : (int) $oauth['user_id']),
            'account_name' => (string) ($group['name'] ?? 'VK'), 'credentials' => ['access_token' => $oauth['access_token']],
            'scopes' => ['wall', 'photos', 'groups', 'offline'], 'expires_at' => ($oauth['expires_in'] ?? 0) > 0 ? now()->addSeconds($oauth['expires_in']) : null,
        ];
    }

    private function connectTelegramTarget(Request $request, Tenant $tenant): JsonResponse
    {
        $data = $request->validate([
            'target' => ['required', 'string', 'max:128', 'regex:/^(?:-?\d+|@[A-Za-z0-9_]{5,})$/'],
        ]);
        $token = (string) ($this->providerCredentials($tenant, 'telegram')['bot_token'] ?? '');
        $chat = Http::timeout(30)
            ->post("https://api.telegram.org/bot{$token}/getChat", ['chat_id' => $data['target']])
            ->throw()
            ->json('result');
        if (! is_array($chat) || blank($chat['id'] ?? null)) {
            throw new RuntimeException('TELEGRAM_CHANNEL_NOT_AVAILABLE');
        }

        $connection = $tenant->socialConnections()->updateOrCreate(['provider' => 'telegram'], [
            'connected_by_user_id' => $request->user()->id,
            'status' => 'active',
            'external_account_id' => (string) $chat['id'],
            'account_name' => (string) ($chat['title'] ?? $chat['username'] ?? $data['target']),
            'credentials' => ['username' => $chat['username'] ?? ltrim($data['target'], '@')],
            'scopes' => ['sendMessage', 'sendPhoto'],
            'last_validated_at' => now(),
            'last_error' => null,
        ]);

        return response()->json(['connected' => true, 'connection' => $connection->only(['id', 'provider', 'status', 'account_name'])]);
    }
}
