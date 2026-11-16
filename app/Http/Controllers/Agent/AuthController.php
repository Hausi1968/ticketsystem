<?php

namespace App\Http\Controllers\Agent;

use App\Agent\AgentPresenter;
use App\Models\AgentToken;
use App\Models\User;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/** Anmeldung der Mitarbeiter-App: E-Mail + Passwort, danach Code aus der Authenticator-App (oder Wiederherstellungscode). */
class AuthController
{
    private const MFA_TTL_MINUTES = 5;

    public function login(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'device' => ['nullable', 'string', 'max:100'],
        ]);

        $throttleKey = 'agent-login:'.mb_strtolower($data['email']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            return $this->error('too_many_attempts', 'Zu viele Versuche. Bitte in '.RateLimiter::availableIn($throttleKey).' Sekunden erneut versuchen.', 429);
        }

        $user = User::query()->where('email', mb_strtolower(trim($data['email'])))->first();
        if (! $user || ! Hash::check($data['password'], $user->password)) {
            RateLimiter::hit($throttleKey, 300);

            return $this->error('invalid_credentials', 'E-Mail-Adresse oder Passwort ist falsch.', 422);
        }
        if (! $user->active) {
            return $this->error('inactive', 'Dieses Konto ist deaktiviert.', 403);
        }
        RateLimiter::clear($throttleKey);

        if ($user->getAppAuthenticationSecret()) {
            $challenge = Str::random(48);
            Cache::put('agent-mfa:'.hash('sha256', $challenge), ['user' => $user->getKey(), 'device' => $data['device'] ?? null, 'tries' => 0],
                now()->addMinutes(self::MFA_TTL_MINUTES));

            return response()->json(['mfa_required' => true, 'mfa_token' => $challenge]);
        }

        if (config('anliego.require_mfa')) {
            return $this->error('mfa_setup', 'Bitte richte zuerst im Browser die Zwei-Faktor-Anmeldung ein (Profil → Authenticator-App). Danach kannst du dich in der App anmelden.', 403);
        }

        return $this->issue($user, $data['device'] ?? null);
    }

    public function verifyMfa(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mfa_token' => ['required', 'string'],
            'code' => ['required', 'string', 'max:64'],
        ]);

        $key = 'agent-mfa:'.hash('sha256', $data['mfa_token']);
        $pending = Cache::get($key);
        if (! $pending) {
            return $this->error('mfa_expired', 'Die Anmeldung ist abgelaufen. Bitte noch einmal mit Passwort anmelden.', 422);
        }
        $user = User::query()->find($pending['user']);
        if (! $user?->active) {
            Cache::forget($key);

            return $this->error('inactive', 'Dieses Konto ist deaktiviert.', 403);
        }

        $code = preg_replace('/\s+/', '', $data['code']);
        $auth = AppAuthentication::make()->recoverable();
        $valid = preg_match('/^\d{6}$/', $code)
            ? $auth->verifyCode($code, $user->getAppAuthenticationSecret(), shouldPreventCodeReuse: true)
            : $auth->verifyRecoveryCode($code, $user);

        if (! $valid) {
            $pending['tries']++;
            $pending['tries'] >= 5 ? Cache::forget($key) : Cache::put($key, $pending, now()->addMinutes(self::MFA_TTL_MINUTES));

            return $this->error('invalid_code', 'Der Code stimmt nicht.', 422);
        }

        Cache::forget($key);

        return $this->issue($user, $pending['device']);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->attributes->get('agent_token')?->delete();

        return response()->json(['ok' => true]);
    }

    public function me(Request $request): JsonResponse
    {
        return response()->json(AgentPresenter::user($request->user()));
    }

    /** Push-Adresse des Geräts (Expo) speichern oder mit null entfernen */
    public function pushToken(Request $request): JsonResponse
    {
        $data = $request->validate(['push_token' => ['nullable', 'string', 'max:255', 'regex:/^(ExponentPushToken|ExpoPushToken)\[[^\]]+\]$/']]);
        $request->attributes->get('agent_token')->forceFill(['push_token' => $data['push_token'] ?? null])->save();

        return response()->json(['ok' => true]);
    }

    private function issue(User $user, ?string $device): JsonResponse
    {
        [, $plain] = AgentToken::issue($user, $device ?: 'App');

        return response()->json(['token' => $plain] + AgentPresenter::user($user));
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json(['error' => $code, 'message' => $message], $status);
    }
}
