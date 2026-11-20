<?php

namespace App\Services;

use App\Models\BackupRun;
use Illuminate\Support\Facades\Log;

/**
 * Production-readiness checks.
 *
 * Each check reports pass, warn, or fail. The artisan command
 * `security:check` and the `GET /security/check` endpoint share
 * this service, so the command line and the dashboard can never
 * disagree. A warning is a problem worth fixing but does not
 * fail the run; a failure does (the command exits 1).
 *
 * Nothing here claims the app is secure: these are the
 * configuration mistakes that are cheap to catch automatically.
 * A penetration test and a dependency audit are still open items
 * in docs/SECURITY.md.
 */
class SecurityCheckService
{
    /**
     * Run every check.
     *
     * @return array{overall: string, checks: array<int, array{id: string, label: string, status: string, detail: string}>}
     */
    public function run(): array
    {
        $checks = [
            $this->checkAppDebug(),
            $this->checkAppKey(),
            $this->checkAppUrl(),
            $this->checkSessionCookies(),
            $this->checkSanctumExpiration(),
            $this->checkEvidenceDisk(),
            $this->checkCorsOrigin(),
            $this->checkBackupFreshness(),
            $this->checkMailDriver(),
            $this->checkQueueDriver(),
        ];

        $overall = 'pass';

        foreach ($checks as $check) {
            if ($check['status'] === 'fail') {
                $overall = 'fail';

                break;
            }

            if ($check['status'] === 'warn') {
                $overall = 'warn';
            }
        }

        return ['overall' => $overall, 'checks' => $checks];
    }

    /**
     * Whether any check failed.
     */
    public function hasFailures(array $result): bool
    {
        return $result['overall'] === 'fail';
    }

    /**
     * Whether the app is running in production.
     *
     * Reads config('app.env') rather than app()->environment():
     * the two agree in a real deployment, and the config value
     * is the one tests can override.
     */
    protected function isProduction(): bool
    {
        return config('app.env') === 'production';
    }

    protected function checkAppDebug(): array
    {
        $debug = (bool) config('app.debug');

        if ($this->isProduction() && $debug) {
            return $this->result('app_debug', 'APP_DEBUG is off', 'fail',
                'APP_DEBUG is true in production: error pages can leak internals.');
        }

        return $this->result('app_debug', 'APP_DEBUG is off', 'pass',
            $debug ? 'APP_DEBUG is true, but the environment is not production.' : 'APP_DEBUG is false.');
    }

    protected function checkAppKey(): array
    {
        $key = (string) config('app.key');

        if ($key === '' || $key === 'base64:') {
            return $this->result('app_key', 'APP_KEY is set', 'fail',
                'APP_KEY is empty: encrypted values and signed URLs cannot be trusted.');
        }

        return $this->result('app_key', 'APP_KEY is set', 'pass',
            'APP_KEY is set.');
    }

    protected function checkAppUrl(): array
    {
        $url = (string) config('app.url');

        if ($this->isProduction() && ! str_starts_with($url, 'https://')) {
            return $this->result('app_url', 'APP_URL uses HTTPS', 'fail',
                'APP_URL is "'.$url.'": production must be served over HTTPS.');
        }

        return $this->result('app_url', 'APP_URL uses HTTPS', 'pass',
            str_starts_with($url, 'https://') ? 'APP_URL is HTTPS.' : 'APP_URL is HTTP, but the environment is not production.');
    }

    protected function checkSessionCookies(): array
    {
        $secure = (bool) config('session.secure');
        $sameSite = (string) config('session.same_site', 'lax');

        if ($this->isProduction() && ! $secure) {
            return $this->result('session_cookies', 'Session cookies are secure', 'fail',
                'SESSION_SECURE_COOKIE is off in production: session cookies can travel over plain HTTP.');
        }

        if ($sameSite === 'none' && ! $secure) {
            return $this->result('session_cookies', 'Session cookies are secure', 'fail',
                'SameSite=None requires the Secure flag, which is off.');
        }

        return $this->result('session_cookies', 'Session cookies are secure', 'pass',
            'Secure is '.($secure ? 'on' : 'off (not production)').', SameSite is '.$sameSite.'.');
    }

    protected function checkSanctumExpiration(): array
    {
        $expiration = config('sanctum.expiration');

        if ($expiration === null) {
            return $this->result('sanctum_expiration', 'API tokens expire', 'warn',
                'SANCTUM_TOKEN_EXPIRATION is not set: issued tokens never expire. Set it before production.');
        }

        return $this->result('sanctum_expiration', 'API tokens expire', 'pass',
            'Tokens expire after '.$expiration.' minutes.');
    }

    protected function checkEvidenceDisk(): array
    {
        $disk = (string) config('evidence.disk');

        if ($disk === 'public') {
            return $this->result('evidence_disk', 'Evidence files are on a private disk', 'fail',
                'EVIDENCE_DISK is "public": evidence would be reachable by URL without authorization.');
        }

        return $this->result('evidence_disk', 'Evidence files are on a private disk', 'pass',
            'Evidence disk is "'.$disk.'".');
    }

    protected function checkCorsOrigin(): array
    {
        $origins = (array) config('cors.allowed_origins', []);

        if (in_array('*', $origins, true)) {
            return $this->result('cors_origin', 'CORS allows one origin', 'fail',
                'CORS allows "*": any site could call the API from a browser.');
        }

        return $this->result('cors_origin', 'CORS allows one origin', 'pass',
            'CORS allows: '.implode(', ', $origins).'.');
    }

    protected function checkBackupFreshness(): array
    {
        try {
            $fresh = BackupRun::withoutGlobalScope('organization')
                ->where('status', BackupRun::STATUS_COMPLETED)
                ->where('created_at', '>=', now()->subDays(7))
                ->exists();
        } catch (\Throwable $e) {
            Log::warning('Security check could not read backup runs.', ['error' => $e->getMessage()]);

            return $this->result('backup_freshness', 'A backup completed in the last 7 days', 'warn',
                'Could not check backup runs.');
        }

        if (! $fresh) {
            return $this->result('backup_freshness', 'A backup completed in the last 7 days', 'warn',
                'No completed backup in the last 7 days. Run backup:create (it is scheduled daily).');
        }

        return $this->result('backup_freshness', 'A backup completed in the last 7 days', 'pass',
            'A completed backup exists from the last 7 days.');
    }

    protected function checkMailDriver(): array
    {
        $mailer = (string) config('mail.default');

        if ($this->isProduction() && in_array($mailer, ['log', 'array'], true)) {
            return $this->result('mail_driver', 'Mail is delivered by a real mailer', 'warn',
                'Mailer is "'.$mailer.'" in production: notification emails are not delivered.');
        }

        return $this->result('mail_driver', 'Mail is delivered by a real mailer', 'pass',
            'Mailer is "'.$mailer.'".');
    }

    protected function checkQueueDriver(): array
    {
        $queue = (string) config('queue.default');

        if ($this->isProduction() && $queue === 'sync') {
            return $this->result('queue_driver', 'Queue work runs asynchronously', 'warn',
                'Queue driver is "sync" in production: package generation and emails run inside requests.');
        }

        return $this->result('queue_driver', 'Queue work runs asynchronously', 'pass',
            'Queue driver is "'.$queue.'".');
    }

    /**
     * One check result row.
     *
     * @return array{id: string, label: string, status: string, detail: string}
     */
    protected function result(string $id, string $label, string $status, string $detail): array
    {
        return ['id' => $id, 'label' => $label, 'status' => $status, 'detail' => $detail];
    }
}
