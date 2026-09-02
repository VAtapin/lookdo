<?php

namespace App\Services;

use App\Models\TenantSocialConnection;
use App\Models\TenantSocialDraft;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class SocialPublishingService
{
    public const DIRECT_PROVIDERS = ['facebook', 'instagram', 'vk', 'telegram'];

    public function publish(TenantSocialDraft $draft, TenantSocialConnection $connection): array
    {
        if ($connection->status !== 'active' || $connection->provider !== $draft->channel) {
            throw new RuntimeException('SOCIAL_ACCOUNT_NOT_CONNECTED');
        }

        $imageUrl = $this->imageUrl($draft);
        $result = match ($connection->provider) {
            'facebook' => $this->facebook($draft, $connection, $imageUrl),
            'instagram' => $this->instagram($draft, $connection, $imageUrl),
            'vk' => $this->vk($draft, $connection, $imageUrl),
            'telegram' => $this->telegram($draft, $connection, $imageUrl),
            default => throw new RuntimeException('SOCIAL_PROVIDER_NOT_SUPPORTED'),
        };

        $connection->forceFill(['last_validated_at' => now(), 'last_error' => null])->save();

        return $result;
    }

    private function facebook(TenantSocialDraft $draft, TenantSocialConnection $connection, ?string $imageUrl): array
    {
        $credentials = (array) $connection->credentials;
        $endpoint = $imageUrl ? 'photos' : 'feed';
        $payload = $imageUrl
            ? ['url' => $imageUrl, 'caption' => $this->caption($draft), 'published' => true]
            : ['message' => $this->caption($draft)];
        $json = $this->graph($credentials['access_token'] ?? '')->post('/'.$connection->external_account_id.'/'.$endpoint, $payload)->throw()->json();
        $id = (string) ($json['post_id'] ?? $json['id'] ?? '');
        if ($id === '') {
            throw new RuntimeException('FACEBOOK_PUBLISH_FAILED');
        }

        return ['id' => $id, 'url' => 'https://www.facebook.com/'.str_replace('_', '/posts/', $id)];
    }

    private function instagram(TenantSocialDraft $draft, TenantSocialConnection $connection, ?string $imageUrl): array
    {
        if (! $imageUrl) {
            throw new RuntimeException('INSTAGRAM_IMAGE_REQUIRED');
        }
        $token = (string) data_get($connection->credentials, 'access_token');
        $container = $this->graph($token)->post('/'.$connection->external_account_id.'/media', [
            'image_url' => $imageUrl,
            'caption' => $this->caption($draft),
        ])->throw()->json('id');
        if (! $container) {
            throw new RuntimeException('INSTAGRAM_CONTAINER_FAILED');
        }
        $id = (string) $this->graph($token)->post('/'.$connection->external_account_id.'/media_publish', ['creation_id' => $container])->throw()->json('id');
        if ($id === '') {
            throw new RuntimeException('INSTAGRAM_PUBLISH_FAILED');
        }
        $url = $this->graph($token)->get('/'.$id, ['fields' => 'permalink'])->throw()->json('permalink');

        return ['id' => $id, 'url' => $url];
    }

    private function vk(TenantSocialDraft $draft, TenantSocialConnection $connection, ?string $imageUrl): array
    {
        $token = (string) data_get($connection->credentials, 'access_token');
        $ownerId = (int) $connection->external_account_id;
        $payload = ['owner_id' => $ownerId, 'from_group' => $ownerId < 0 ? 1 : 0, 'message' => $this->caption($draft), 'v' => config('services.social.vk.version')];
        if ($imageUrl) {
            $groupId = $ownerId < 0 ? abs($ownerId) : null;
            $server = $this->vkRequest($token, 'photos.getWallUploadServer', array_filter(['group_id' => $groupId]));
            $upload = Http::timeout(60)->attach('photo', Storage::disk('public')->get($draft->image_path), basename($draft->image_path))
                ->post($server['upload_url'])->throw()->json();
            $saved = $this->vkRequest($token, 'photos.saveWallPhoto', array_filter([
                'group_id' => $groupId,
                'photo' => $upload['photo'] ?? null,
                'server' => $upload['server'] ?? null,
                'hash' => $upload['hash'] ?? null,
            ]));
            $photo = $saved[0] ?? null;
            if ($photo) {
                $payload['attachments'] = 'photo'.$photo['owner_id'].'_'.$photo['id'];
            }
        }
        $result = $this->vkRequest($token, 'wall.post', $payload);
        $id = (string) ($result['post_id'] ?? '');
        if ($id === '') {
            throw new RuntimeException('VK_PUBLISH_FAILED');
        }

        return ['id' => $id, 'url' => 'https://vk.com/wall'.$ownerId.'_'.$id];
    }

    private function telegram(TenantSocialDraft $draft, TenantSocialConnection $connection, ?string $imageUrl): array
    {
        $providerConfig = $connection->tenant->socialProviderConfigs()->where('provider', 'telegram')->first();
        $token = (string) data_get($providerConfig?->credentials, 'bot_token');
        if ($token === '') {
            throw new RuntimeException('TELEGRAM_NOT_CONFIGURED');
        }
        $method = $imageUrl ? 'sendPhoto' : 'sendMessage';
        $payload = $imageUrl
            ? ['chat_id' => $connection->external_account_id, 'photo' => $imageUrl, 'caption' => $this->caption($draft)]
            : ['chat_id' => $connection->external_account_id, 'text' => $this->caption($draft)];
        $message = Http::timeout(30)->post("https://api.telegram.org/bot{$token}/{$method}", $payload)->throw()->json('result');
        if (! $message) {
            throw new RuntimeException('TELEGRAM_PUBLISH_FAILED');
        }

        $username = data_get($connection->credentials, 'username');
        $url = $username ? 'https://t.me/'.ltrim($username, '@').'/'.$message['message_id'] : null;

        return ['id' => (string) $message['message_id'], 'url' => $url];
    }

    private function graph(string $token): PendingRequest
    {
        if ($token === '') {
            throw new RuntimeException('SOCIAL_ACCESS_TOKEN_MISSING');
        }

        return Http::baseUrl('https://graph.facebook.com/'.config('services.social.meta.version'))->timeout(45)->withToken($token)->asForm();
    }

    private function vkRequest(string $token, string $method, array $payload): array
    {
        $json = Http::asForm()->timeout(45)->post('https://api.vk.com/method/'.$method, array_merge($payload, [
            'access_token' => $token,
            'v' => config('services.social.vk.version'),
        ]))->throw()->json();
        if (isset($json['error'])) {
            throw new RuntimeException('VK_API_ERROR: '.($json['error']['error_msg'] ?? 'Unknown error'));
        }

        return (array) ($json['response'] ?? []);
    }

    private function caption(TenantSocialDraft $draft): string
    {
        return trim(collect([$draft->caption, $draft->booking_url])->filter()->implode("\n\n"));
    }

    private function imageUrl(TenantSocialDraft $draft): ?string
    {
        if (! $draft->image_path) {
            return null;
        }
        $url = Storage::disk('public')->url($draft->image_path);

        return str_starts_with($url, 'http') ? $url : rtrim(config('app.url'), '/').'/'.ltrim($url, '/');
    }
}
