<?php

declare(strict_types=1);

namespace Kestello\Cms\Api\Endpoints\Forms;

use Kestello\Cms\Api\ApiProblem;
use Kestello\Cms\Api\ApiRequest;
use Kestello\Cms\Api\ApiRoute;
use Kestello\Framework\Http\RateLimiter;

/**
 * Public: send a form (multipart for file uploads, or JSON). No session
 * token: the request must come from this site, carry a recent form token
 * and pass the honeypot, the form's per-IP limit and its captcha.
 */
final class SubmitForm extends FormEndpoint
{
    public function route(): ApiRoute
    {
        return ApiRoute::post('/forms/{id}/submissions')->where('id', '\d{1,18}');
    }
    public function isPublic(): bool
    {
        return true;
    }
    public function capability(): ?string
    {
        return null;
    }
    public function scopes(): array
    {
        return [];
    }
    public function sameOriginOnly(): bool
    {
        return true;
    }
    public function rateLimit(): ?array
    {
        return [30, 60];
    }
    public function summary(): string
    {
        return 'Send a form';
    }

    public function handle(ApiRequest $request): mixed
    {
        $id = $request->intParam('id');
        $form = self::attempt(fn() => self::forms()->find($id));
        $ip = $request->http->ip();     // honours trusted proxies, like every other limit
        $c = self::cms()->container;
        $limiter = $c->has(RateLimiter::class) ? $c->make(RateLimiter::class)
            : ($c->has(\Kestello\Contracts\Cache\Store::class) ? new RateLimiter($c->make(\Kestello\Contracts\Cache\Store::class)) : null);
        if ($limiter !== null) {
            $limit = $limiter->hit('form:' . $id . ':' . $ip, (int) $form['definition']['per_ip_hour'], 3600);
            if (!$limit->allowed) {
                throw ApiProblem::tooManyRequests($limit->resetIn);
            }
        }
        $referer = $request->http->header('Referer');
        $page = $referer !== '' ? (string) (parse_url($referer, PHP_URL_PATH) ?? '') : '';

        return self::attempt(fn() => array_diff_key(self::forms()->submit($id, $request->http->post, $request->http->files, [
            'ip' => $ip,
            'user_agent' => $request->http->header('User-Agent'),
            'page' => $page,
            'user_id' => $request->userId(),
        ]), ['spam' => true]));
    }
}
