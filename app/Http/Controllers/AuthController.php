<?php

namespace App\Http\Controllers;

use App\Jobs\GenerateTenantAppCustomization;
use App\Models\BusinessClassification;
use App\Models\BusinessVariation;
use App\Models\Plan;
use App\Models\PlatformPage;
use App\Models\RequestTemplate;
use App\Models\SystemSetting;
use App\Models\Tenant;
use App\Models\User;
use App\Notifications\ConfirmAccountEmailChange;
use App\Services\AuditService;
use App\Services\BusinessClassifier;
use App\Services\OpenAiBudgetService;
use App\Services\OpenAiService;
use App\Services\StripeService;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password as PasswordRule;
use Throwable;

class AuthController extends Controller
{
    public function classify(Request $request, BusinessClassifier $classifier): JsonResponse
    {
        $data = $request->validate(['description' => 'required|string|min:3|max:1000', 'locale' => ['nullable', Rule::in(['de', 'en', 'ru', 'uk'])]]);
        $result = $classifier->classify($data['description'], $data['locale'] ?? app()->getLocale());

        return response()->json($result);
    }

    public function transcribeBusiness(Request $request, OpenAiService $openAi, OpenAiBudgetService $budget): JsonResponse
    {
        $data = $request->validate([
            'audio' => 'required|file|max:15360|mimetypes:audio/mpeg,audio/mp4,audio/x-m4a,audio/wav,audio/webm,audio/ogg,video/webm,video/mp4',
            'locale' => ['nullable', Rule::in(['de', 'en', 'ru', 'uk'])],
        ]);
        if (! $openAi->configured()) {
            return response()->json(['message' => $this->registrationMessage('transcription_unavailable')], 503);
        }

        try {
            $budget->ensureAvailable();
            $result = $openAi->transcribe($request->file('audio'), $data['locale'] ?? app()->getLocale());
            $budget->recordTranscription('registration_business_transcription', $result['model'], $result['input_tokens'], $result['output_tokens']);

            return response()->json(['text' => $result['text']]);
        } catch (Throwable $exception) {
            report($exception);

            return response()->json(['message' => $this->registrationMessage('transcription_failed')], 503);
        }
    }

    public function availability(Request $request): JsonResponse
    {
        $email = trim((string) $request->input('email'));
        $requestedSlug = trim((string) $request->input('slug'));
        $businessName = trim((string) $request->input('business_name'));
        $slug = $this->slugBase($requestedSlug !== '' ? $requestedSlug : $businessName);
        $emailValid = $email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
        $emailAvailable = $emailValid && ($email === '' || ! User::where('email', $email)->exists());
        $slugValid = $requestedSlug === '' || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $requestedSlug) === 1;
        $slugAvailable = $slugValid && ! in_array($slug, config('tenancy.reserved_slugs'), true) && ! Tenant::where('slug', $slug)->exists();
        $suggestedSlug = $slugAvailable ? $slug : $this->uniqueSlug($slug);

        return response()->json([
            'email' => [
                'valid' => $emailValid,
                'available' => $emailAvailable,
                'message' => ! $emailValid ? $this->registrationMessage('email_invalid') : ($emailAvailable ? $this->registrationMessage('email_available') : $this->registrationMessage('email_taken')),
            ],
            'slug' => [
                'valid' => $slugValid,
                'available' => $slugAvailable,
                'normalized' => $slug,
                'suggested' => $suggestedSlug,
                'message' => ! $slugValid ? $this->registrationMessage('slug_invalid') : ($slugAvailable ? $this->registrationMessage('slug_available') : $this->registrationMessage('slug_taken')),
            ],
        ]);
    }

    public function register(Request $request, StripeService $stripe, AuditService $audit, BusinessClassifier $classifier): JsonResponse
    {
        abort_unless((bool) SystemSetting::read('registration_enabled', true), 403, 'Registration is disabled.');
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'confirmed', PasswordRule::min(10)->letters()->numbers()],
            'business_name' => 'required|string|max:160',
            'slug' => ['nullable', 'string', 'max:63', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/', Rule::notIn(config('tenancy.reserved_slugs')), Rule::unique('tenants', 'slug')],
            'country' => 'required|string|size:2', 'locale' => ['required', Rule::in(['de', 'en', 'ru', 'uk'])],
            'business_description' => 'required|string|max:1000', 'classification_id' => 'nullable|exists:business_classifications,id', 'variation_id' => 'nullable|exists:business_variations,id', 'plan_id' => 'required|exists:plans,id',
            'template_confirmed' => 'nullable|boolean',
            'billing_cycle' => ['required', Rule::in(['monthly', 'yearly'])], 'currency' => ['nullable', Rule::in(['EUR', 'RUB', 'UAH'])],
            'confirm_business_customer' => 'accepted', 'accept_terms' => 'accepted', 'accept_privacy' => 'accepted',
        ], [
            'email.unique' => $this->registrationMessage('email_taken'),
            'email.email' => $this->registrationMessage('email_invalid'),
            'password.required' => $this->registrationMessage('password_required'),
            'password.confirmed' => $this->registrationMessage('password_mismatch'),
            'password.password.min' => $this->registrationMessage('password_too_short'),
            'password.password.letters' => $this->registrationMessage('password_needs_letter'),
            'password.password.numbers' => $this->registrationMessage('password_needs_number'),
            'slug.unique' => $this->registrationMessage('slug_taken'),
            'slug.regex' => $this->registrationMessage('slug_invalid'),
            'slug.not_in' => $this->registrationMessage('slug_taken'),
            'confirm_business_customer.accepted' => $this->registrationMessage('business_confirmation_required'),
        ]);
        $plan = Plan::where('is_active', true)->findOrFail($data['plan_id']);
        $currency = $data['currency'] ?? match ($data['locale']) {
            'ru' => 'RUB', 'uk' => 'UAH', default => 'EUR'
        };
        $unitAmount = $plan->priceFor($currency, $data['billing_cycle']);
        abort_if($unitAmount === null, 422, $this->registrationMessage('price_unavailable'));
        $classification = ! empty($data['classification_id'])
            ? BusinessClassification::find($data['classification_id'])
            : $classifier->classify($data['business_description'], $data['locale']);
        $variationId = $data['variation_id'] ?? $classification?->variation_id;
        $variation = $variationId
            ? BusinessVariation::with('category')->where('enabled', true)->find($variationId)
            : null;
        if ($variation && ! RequestTemplate::where('code', $variation->template_code)->where('enabled', true)->exists()) {
            $variation = null;
        }
        $variation ??= $classifier->defaultVariation();
        $slug = filled($data['slug'] ?? null) ? (string) $data['slug'] : $this->uniqueSlug($data['business_name']);
        [$user,$tenant,$subscription] = DB::transaction(function () use ($data, $plan, $variation, $classification, $slug, $request, $unitAmount, $currency) {
            $user = User::create(['name' => $data['name'], 'email' => $data['email'], 'password' => $data['password'], 'locale' => $data['locale'], 'is_active' => true]);
            $tenant = Tenant::create(['name' => $data['business_name'], 'slug' => $slug, 'country' => strtoupper($data['country']), 'locale' => $data['locale'], 'business_description' => $data['business_description'], 'status' => 'active']);
            $tenant->users()->attach($user, ['role' => 'owner']);
            $profileContent = ! empty($data['template_confirmed']) ? [
                'ai_customization' => ['status' => 'pending', 'requested_at' => now()->toIso8601String()],
                'branding' => $this->initialBranding(
                    $data['business_name'],
                    $data['business_description'],
                    $data['locale'],
                ),
            ] : [];
            $tenant->profile()->create(['contact_name' => $data['name'], 'email' => $data['email'], 'content' => $profileContent]);
            $domain = $tenant->domains()->create(['domain' => $slug.'.'.config('tenancy.platform_domain'), 'type' => 'platform', 'is_primary' => true, 'status' => 'active', 'verified_at' => now(), 'ssl_status' => 'active', 'ssl_issued_at' => now()]);
            $tenant->update(['primary_domain_id' => $domain->id]);
            $template = RequestTemplate::where('code', $variation->template_code)->where('enabled', true)->first();
            $tenant->businessProfile()->create(['category_id' => $variation->category_id, 'variation_id' => $variation->id, 'request_template_id' => $template?->id, 'original_description' => $data['business_description']]);
            $classification?->update(['tenant_id' => $tenant->id, 'category_id' => $variation->category_id, 'variation_id' => $variation->id, 'confirmed_by_user_at' => now()]);
            $trial = $plan->trial_days > 0;
            $subscription = $tenant->subscriptions()->create(['plan_id' => $plan->id, 'provider' => $trial ? 'lookdo' : 'stripe', 'status' => $trial ? 'trialing' : 'incomplete', 'billing_cycle' => $data['billing_cycle'], 'currency' => $currency, 'unit_amount' => $unitAmount, 'started_at' => now(), 'current_period_start' => now(), 'current_period_end' => $trial ? now()->addDays($plan->trial_days) : null]);
            DB::table('legal_acceptances')->insert([
                'user_id' => $user->id, 'tenant_id' => $tenant->id,
                'terms_version_at' => PlatformPage::where('key', 'agb')->value('updated_at'),
                'privacy_version_at' => PlatformPage::where('key', 'datenschutz')->value('updated_at'),
                'business_customer_confirmed_at' => now(),
                'accepted_at' => now(), 'ip_address' => $request->ip(), 'user_agent' => substr((string) $request->userAgent(), 0, 1000),
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return [$user, $tenant, $subscription];
        });
        Auth::login($user);
        $request->session()->regenerate();
        $audit->log('tenant.registered', $tenant, null, $tenant->toArray(), $tenant->id);
        if (! empty($data['template_confirmed'])) {
            GenerateTenantAppCustomization::dispatchAfterResponse($tenant->id);
        }
        $checkoutUrl = null;
        $paymentRequired = $subscription->status === 'incomplete';
        if ($subscription->status === 'incomplete') {
            try {
                $checkoutUrl = $stripe->checkout($tenant, $plan, $user->email, $data['billing_cycle'], $currency);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return response()->json(['user' => $user, 'tenant' => $tenant, 'checkout_url' => $checkoutUrl, 'payment_required' => $paymentRequired], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => 'required|email', 'password' => 'required|string', 'remember' => 'boolean']);
        if (! Auth::attempt(['email' => $data['email'], 'password' => $data['password'], 'is_active' => true], $data['remember'] ?? false)) {
            return response()->json(['message' => __('auth.failed')], 422);
        } $request->session()->regenerate();
        $request->user()->update(['last_login_at' => now()]);

        return response()->json(['user' => $request->user()]);
    }

    public function logout(Request $request): JsonResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['ok' => true]);
    }

    public function forgot(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email']);
        Password::sendResetLink($request->only('email'));

        return response()->json(['message' => __('passwords.sent')]);
    }

    public function reset(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => 'required', 'email' => 'required|email', 'password' => ['required', 'confirmed', PasswordRule::min(10)->letters()->numbers()]]);
        $status = Password::reset($data, function (User $user, string $password) {
            $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
            event(new PasswordReset($user));
        });

        return $status === Password::PASSWORD_RESET ? response()->json(['message' => __($status)]) : response()->json(['message' => __($status)], 422);
    }

    public function me(Request $request): JsonResponse
    {
        $user = $request->user();

        return response()->json(['user' => $user, 'tenants' => $user?->tenants()->with(['profile', 'primaryDomain', 'currentSubscription.plan', 'businessProfile.category', 'businessProfile.variation'])->get() ?? [], 'impersonating' => (bool) $request->session()->get('impersonator_id')]);
    }

    public function updateAccount(Request $request, AuditService $audit): JsonResponse
    {
        $user = $request->user();
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'current_password' => ['nullable', 'required_with:password', 'current_password'],
            'password' => ['nullable', 'confirmed', PasswordRule::min(10)],
        ]);
        $before = $user->only(['name', 'email']);
        $user->name = $data['name'];
        $emailChanged = mb_strtolower($data['email']) !== mb_strtolower($user->email);
        if ($emailChanged) {
            $token = Str::random(48);
            $user->pending_email = mb_strtolower($data['email']);
            $user->pending_email_token = hash('sha256', $token);
        }
        if (! empty($data['password'])) {
            $user->password = $data['password'];
        }
        $user->save();
        if ($emailChanged) {
            $url = URL::temporarySignedRoute('account.email-change.confirm', now()->addHour(), ['user' => $user->id, 'token' => $token]);
            Notification::route('mail', $user->pending_email)->notify(new ConfirmAccountEmailChange($url));
        }
        $audit->log('account.updated', $user, $before, $user->only(['name', 'email']), $user->tenants()->value('tenants.id'));

        return response()->json(['user' => $user->fresh(), 'email_pending' => $emailChanged]);
    }

    public function confirmEmailChange(Request $request, User $user): RedirectResponse
    {
        abort_unless($request->hasValidSignature() && filled($user->pending_email), 403);
        abort_unless(hash_equals((string) $user->pending_email_token, hash('sha256', (string) $request->query('token'))), 403);
        abort_if(User::where('email', $user->pending_email)->whereKeyNot($user->id)->exists(), 422, 'EMAIL_ALREADY_USED');
        $user->forceFill(['email' => $user->pending_email, 'pending_email' => null, 'pending_email_token' => null, 'email_verified_at' => now()])->save();

        return redirect('/app/account?email=verified');
    }

    private function uniqueSlug(string $value): string
    {
        $base = $this->slugBase($value);
        if (in_array($base, config('tenancy.reserved_slugs'), true)) {
            $base .= '-business';
        } $slug = $base;
        $i = 2;
        while (Tenant::where('slug', $slug)->exists() || in_array($slug, config('tenancy.reserved_slugs'), true)) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    private function slugBase(string $value): string
    {
        $base = Str::slug(Str::ascii($value)) ?: 'business';

        return substr(trim($base, '-'), 0, 50);
    }

    /** @return array<string, mixed> */
    private function initialBranding(string $businessName, string $description, string $locale): array
    {
        $defaults = [
            'de' => [
                'customers' => 'Kundinnen und Kunden, die diese Leistungen suchen.',
                'style' => 'Professionell, verständlich und vertrauenswürdig.',
                'avoid' => 'Unpassende Bilder, irreführende Details und unrealistische Versprechen.',
            ],
            'en' => [
                'customers' => 'Customers looking for these services.',
                'style' => 'Professional, clear and trustworthy.',
                'avoid' => 'Irrelevant images, misleading details and unrealistic promises.',
            ],
            'ru' => [
                'customers' => 'Клиенты, которым нужны эти услуги.',
                'style' => 'Профессиональный, понятный и вызывающий доверие стиль.',
                'avoid' => 'Нерелевантные изображения, вводящие в заблуждение детали и нереалистичные обещания.',
            ],
            'uk' => [
                'customers' => 'Клієнти, яким потрібні ці послуги.',
                'style' => 'Професійний, зрозумілий стиль, що викликає довіру.',
                'avoid' => 'Недоречні зображення, оманливі деталі та нереалістичні обіцянки.',
            ],
        ];
        $copy = $defaults[$locale] ?? $defaults['en'];
        $seed = [
            'description_translations' => [$locale => $description],
            'tagline_translations' => [$locale => $businessName],
            'tagline' => $businessName,
            'services' => $description,
            'customers' => $copy['customers'],
            'style' => $copy['style'],
            'avoid' => $copy['avoid'],
        ];

        return array_merge($seed, [
            'registration_seed' => $seed,
            'generated_from_registration' => false,
        ]);
    }

    private function registrationMessage(string $key): string
    {
        $passwordMessages = [
            'de' => [
                'password_required' => 'Bitte geben Sie ein Passwort ein.',
                'password_mismatch' => 'Die Passwörter stimmen nicht überein.',
                'password_too_short' => 'Das Passwort muss mindestens 10 Zeichen enthalten.',
                'password_needs_letter' => 'Das Passwort muss mindestens einen Buchstaben enthalten.',
                'password_needs_number' => 'Das Passwort muss mindestens eine Zahl enthalten.',
            ],
            'en' => [
                'password_required' => 'Enter a password.',
                'password_mismatch' => 'The passwords do not match.',
                'password_too_short' => 'The password must contain at least 10 characters.',
                'password_needs_letter' => 'The password must contain at least one letter.',
                'password_needs_number' => 'The password must contain at least one number.',
            ],
            'ru' => [
                'password_required' => 'Введите пароль.',
                'password_mismatch' => 'Пароли не совпадают.',
                'password_too_short' => 'Пароль должен содержать не менее 10 символов.',
                'password_needs_letter' => 'Пароль должен содержать хотя бы одну букву.',
                'password_needs_number' => 'Пароль должен содержать хотя бы одну цифру.',
            ],
            'uk' => [
                'password_required' => 'Введіть пароль.',
                'password_mismatch' => 'Паролі не збігаються.',
                'password_too_short' => 'Пароль має містити щонайменше 10 символів.',
                'password_needs_letter' => 'Пароль має містити щонайменше одну літеру.',
                'password_needs_number' => 'Пароль має містити щонайменше одну цифру.',
            ],
        ];
        $selectedLocale = app()->getLocale();
        if (isset($passwordMessages[$selectedLocale][$key]) || isset($passwordMessages['en'][$key])) {
            return $passwordMessages[$selectedLocale][$key] ?? $passwordMessages['en'][$key];
        }

        $messages = [
            'de' => ['email_invalid' => 'Bitte geben Sie eine gültige E-Mail-Adresse ein.', 'email_available' => 'Diese E-Mail-Adresse kann verwendet werden.', 'email_taken' => 'Diese E-Mail-Adresse ist bereits registriert. Bitte melden Sie sich an oder verwenden Sie eine andere Adresse.', 'slug_invalid' => 'Die App-Adresse darf nur Kleinbuchstaben, Zahlen und Bindestriche enthalten.', 'slug_available' => 'Diese App-Adresse ist verfügbar.', 'slug_taken' => 'Diese App-Adresse ist bereits vergeben. Wir haben eine freie Alternative vorbereitet.', 'price_unavailable' => 'Für diese Währung ist noch kein Preis hinterlegt.', 'business_confirmation_required' => 'Bitte bestätigen Sie, dass Sie als Unternehmen oder selbstständige Person handeln.', 'transcription_unavailable' => 'Die Spracheingabe ist derzeit nicht eingerichtet.', 'transcription_failed' => 'Die Aufnahme konnte nicht erkannt werden. Bitte versuchen Sie es erneut oder geben Sie den Text ein.'],
            'en' => ['email_invalid' => 'Enter a valid email address.', 'email_available' => 'This email address is available.', 'email_taken' => 'This email address is already registered. Sign in or use another address.', 'slug_invalid' => 'The app address may contain lowercase letters, numbers and hyphens only.', 'slug_available' => 'This app address is available.', 'slug_taken' => 'This app address is already taken. We prepared an available alternative.', 'price_unavailable' => 'No price has been configured for this currency yet.', 'business_confirmation_required' => 'Confirm that you are acting as a business or self-employed professional.', 'transcription_unavailable' => 'Voice input is not configured right now.', 'transcription_failed' => 'The recording could not be transcribed. Try again or enter the text.'],
            'ru' => ['email_invalid' => 'Укажите корректный адрес электронной почты.', 'email_available' => 'Этот email можно использовать.', 'email_taken' => 'Этот email уже зарегистрирован. Войдите в аккаунт или укажите другой адрес.', 'slug_invalid' => 'В адресе приложения допустимы только латинские строчные буквы, цифры и дефисы.', 'slug_available' => 'Этот адрес приложения свободен.', 'slug_taken' => 'Этот адрес приложения уже занят. Мы подготовили свободный вариант.', 'price_unavailable' => 'Для выбранной валюты цена пока не настроена.', 'business_confirmation_required' => 'Подтвердите, что вы действуете как фирма или предприниматель.', 'transcription_unavailable' => 'Голосовой ввод сейчас не настроен.', 'transcription_failed' => 'Не удалось распознать запись. Попробуйте ещё раз или введите текст.'],
            'uk' => ['email_invalid' => 'Укажіть коректну адресу електронної пошти.', 'email_available' => 'Цю електронну адресу можна використовувати.', 'email_taken' => 'Цю електронну адресу вже зареєстровано. Увійдіть або вкажіть іншу адресу.', 'slug_invalid' => 'В адресі застосунку дозволені лише латинські малі літери, цифри та дефіси.', 'slug_available' => 'Ця адреса застосунку вільна.', 'slug_taken' => 'Ця адреса застосунку вже зайнята. Ми підготували вільний варіант.', 'price_unavailable' => 'Для вибраної валюти ціну ще не налаштовано.', 'business_confirmation_required' => 'Підтвердьте, що ви дієте як компанія або підприємець.', 'transcription_unavailable' => 'Голосове введення зараз не налаштовано.', 'transcription_failed' => 'Не вдалося розпізнати запис. Спробуйте ще раз або введіть текст.'],
        ];

        return $messages[app()->getLocale()][$key] ?? $messages['en'][$key];
    }
}
