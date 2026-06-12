<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Str;
use RuntimeException;

class JwtTokenService
{
    public function issue(User $user): string
    {
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $payload = [
            'iss' => config('app.url'),
            'sub' => $user->id,
            'user_id' => $user->id,
            'tenant_id' => $user->tenant_id,
            'role' => $user->roles()->value('code'),
            'iat' => time(),
            'exp' => time() + (60 * 60 * 8),
            'jti' => (string) Str::uuid(),
        ];

        $segments = [
            $this->base64UrlEncode(json_encode($header, JSON_THROW_ON_ERROR)),
            $this->base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR)),
        ];

        $segments[] = $this->sign(implode('.', $segments));

        return implode('.', $segments);
    }

    /** @return array<string, mixed> */
    public function verify(string $token): array
    {
        $segments = explode('.', $token);

        if (count($segments) !== 3) {
            throw new RuntimeException('Malformed token.');
        }

        [$header, $payload, $signature] = $segments;
        $expected = $this->sign($header.'.'.$payload);

        if (! hash_equals($expected, $signature)) {
            throw new RuntimeException('Invalid token signature.');
        }

        $claims = json_decode($this->base64UrlDecode($payload), true, 512, JSON_THROW_ON_ERROR);

        if (($claims['exp'] ?? 0) < time()) {
            throw new RuntimeException('Token expired.');
        }

        return $claims;
    }

    private function sign(string $value): string
    {
        return $this->base64UrlEncode(hash_hmac('sha256', $value, config('app.key'), true));
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        return base64_decode(strtr($value, '-_', '+/'));
    }
}
