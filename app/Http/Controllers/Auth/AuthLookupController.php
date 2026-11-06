<?php

namespace App\Http\Controllers\Auth;

use App\Enums\OAuthProvider;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Identifier-first branch endpoint for the combined sign-in page.
 *
 * Answers "what does this email need?" so the client can show the password
 * step, the OAuth-only hint, or the sign-up form. The response carries the
 * same account-existence information the register form's unique:users
 * validation already exposes (accepted tradeoff); the dedicated
 * throttle:auth-lookup limiter keeps it non-harvestable.
 */
class AuthLookupController extends Controller
{
    /** @return array<string, string> */
    private const string STATUS_PASSWORD = 'password';

    private const string STATUS_OAUTH = 'oauth';

    private const string STATUS_NEW = 'new';

    public function __invoke(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255'],
        ]);

        $user = User::query()
            ->where('email', $data['email'])
            ->first();

        if ($user === null) {
            return response()->json(['status' => self::STATUS_NEW]);
        }

        // OAuth-only accounts (password null) never get the password step —
        // pointing them at it would just produce a dead "credentials" error.
        // Deliberately the raw column, not hasPasswordSet(): that helper
        // also demands password_set_at, and legacy password users without
        // the timestamp can absolutely sign in with their password.
        if (blank($user->password)) {
            // The relation applies the OAuthProviderCast, so value() hands
            // back the enum (never a raw string in practice — the string
            // arm is defensive for a hypothetical cast change).
            $provider = $user->linkedAccounts()
                ->orderByDesc('created_at')
                ->value('provider');

            $providerValue = match (true) {
                $provider instanceof OAuthProvider => $provider->value,
                is_string($provider) && $provider !== '' => $provider,
                default => null,
            };

            return response()->json([
                'status' => self::STATUS_OAUTH,
                'provider' => $providerValue,
            ]);
        }

        return response()->json(['status' => self::STATUS_PASSWORD]);
    }
}
