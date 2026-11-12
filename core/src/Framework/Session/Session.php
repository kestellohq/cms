<?php

declare(strict_types=1);

namespace Kestello\Framework\Session;

use Kestello\Contracts\Session\SessionStore;

/**
 * Session manager.
 *
 * Wraps the native PHP session with convenience helpers for flash data,
 * CSRF token management, and secure default settings.
 */
final class Session
{
    /** Session key used to store flash data. */
    /** The session cookie's name, unless the options give another. */
    public const string NAME = 'kestello_session';

    private const string FLASH_KEY = '_flash';

    /** Session key used to store the CSRF token. */
    private const string CSRF_KEY = '_csrf_token';

    /** Whether the session has been started by this instance. */
    private bool $started = false;

    private bool $lazy = false;

    /**
     * @param array{
     *     name?:      string,
     *     lifetime?:  int,
     *     path?:      string,
     *     domain?:    string,
     *     secure?:    bool,
     *     httponly?:   bool,
     *     samesite?:  string,
     * } $options Session configuration overrides.
     */
    public function __construct(
        private readonly array $options = [],
        private ?SessionStore $store = null,
    ) {}

    /** Use $store for this session (before start()). */
    public function setStore(SessionStore $store): void
    {
        if ($this->started) {
            throw new \LogicException('Cannot change the session store after the session started.');
        }
        $this->store = $store;
    }

    public function store(): ?SessionStore
    {
        return $this->store;
    }

    /**
     * Start the session on the first write instead of now (Kernel, for GET requests without a
     * session cookie). Reading an unstarted session finds nothing, so guests browsing the site
     * get no cookie and their pages stay cacheable; signing in, a flash message or a CSRF token starts it.
     */
    public function startOnWrite(): void
    {
        $this->lazy = true;
    }


    private function ensureStarted(): void
    {
        if ($this->lazy && !$this->started && session_status() !== PHP_SESSION_ACTIVE) {
            $this->start();
        }
    }

    public function isStarted(): bool
    {
        return $this->started;
    }

    /** The current session id hashed as stores keep it (device list "this device"). */
    public function hashedId(): ?string
    {
        $id = session_id();

        return $this->started && $id !== '' && $id !== false ? SessionMetadata::hashId($id) : null;
    }

    /** The session cookie name. */
    public function name(): string
    {
        return $this->options['name'] ?? self::NAME;
    }

    /**
     * Start the session with secure defaults.
     *
     * @return void
     */
    public function start(): void
    {
        if ($this->started || session_status() === PHP_SESSION_ACTIVE) {
            $this->started = true;
            $this->ageFlash();
            return;
        }

        $name     = $this->name();
        $lifetime = $this->options['lifetime']  ?? 7200;
        $path     = $this->options['path']      ?? '/';
        $domain   = $this->options['domain']    ?? '';
        $secure   = $this->options['secure']    ?? true;
        $httponly  = $this->options['httponly']  ?? true;
        $samesite = $this->options['samesite']  ?? 'Lax';

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', $httponly ? '1' : '0');
        ini_set('session.cookie_secure', $secure ? '1' : '0');
        ini_set('session.cookie_samesite', $samesite);
        ini_set('session.gc_maxlifetime', (string) $lifetime);
        ini_set('session.lazy_write', '1');

        if ($this->store !== null) {
            session_set_save_handler($this->store, true);
        }

        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path'     => $path,
            'domain'   => $domain,
            'secure'   => $secure,
            'httponly'  => $httponly,
            'samesite' => $samesite,
        ]);

        session_name($name);
        session_start();

        $this->started = true;
        $this->ageFlash();
    }

    /**
     * Get a value from the session.
     *
     * @param string $key     Session key.
     * @param mixed  $default Returned when the key does not exist.
     *
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    /**
     * Store a value in the session.
     *
     * @param string $key   Session key.
     * @param mixed  $value Value to store.
     *
     * @return void
     */
    public function set(string $key, mixed $value): void
    {
        $this->ensureStarted();
        $_SESSION[$key] = $value;
    }

    /**
     * Determine whether a key exists in the session.
     *
     * @param string $key Session key.
     *
     * @return bool
     */
    public function has(string $key): bool
    {
        return array_key_exists($key, $_SESSION ?? []);
    }

    /**
     * Remove a key from the session.
     *
     * @param string $key Session key.
     *
     * @return void
     */
    public function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /**
     * Destroy the session entirely.
     *
     * @return void
     */
    public function destroy(): void
    {
        $_SESSION = [];

        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name() ?: $this->name(),
                '',
                [
                    'expires'  => time() - 42000,
                    'path'     => $params['path'],
                    'domain'   => $params['domain'],
                    'secure'   => $params['secure'],
                    'httponly'  => $params['httponly'],
                    'samesite' => $params['samesite'] ?? 'Lax',
                ],
            );
        }

        session_destroy();
        $this->started = false;
    }

    /**
     * Regenerate the session ID.
     *
     * Useful after authentication events to prevent session fixation attacks.
     *
     * @return void
     */
    public function regenerate(): void
    {
        $this->ensureStarted();
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    /**
     * Flash a value so it is available only during the next request.
     *
     * @param string $key   Flash key.
     * @param mixed  $value Flash value.
     *
     * @return void
     */
    public function flash(string $key, mixed $value): void
    {
        $this->ensureStarted();
        $_SESSION[self::FLASH_KEY]['new'][$key] = $value;
    }

    /**
     * Retrieve a flashed value.
     *
     * @param string $key     Flash key.
     * @param mixed  $default Returned when the key does not exist.
     *
     * @return mixed
     */
    public function getFlash(string $key, mixed $default = null): mixed
    {
        return $_SESSION[self::FLASH_KEY]['current'][$key] ?? $default;
    }

    /**
     * Return the CSRF token for the current session, generating one if needed.
     *
     * @return string
     */
    public function token(): string
    {
        $this->ensureStarted();
        if (!isset($_SESSION[self::CSRF_KEY])) {
            $_SESSION[self::CSRF_KEY] = bin2hex(random_bytes(32));
        }

        return $_SESSION[self::CSRF_KEY];
    }

    /**
     * Validate a submitted CSRF token against the session token.
     *
     * @param string $token Token from the request.
     *
     * @return bool
     */
    public function validateToken(#[\SensitiveParameter] string $token): bool
    {
        $sessionToken = $_SESSION[self::CSRF_KEY] ?? '';

        // An empty session token must never validate: hash_equals('', '')
        // is true, which would let token-less requests through for any
        // session that hasn't rendered a form yet.
        if ($sessionToken === '' || $token === '') {
            return false;
        }

        return hash_equals($sessionToken, $token);
    }

    /**
     * Age flash data: move "new" entries to "current" and discard the previous "current".
     *
     * @return void
     */
    private function ageFlash(): void
    {
        $flash = $_SESSION[self::FLASH_KEY] ?? [];

        $_SESSION[self::FLASH_KEY] = [
            'current' => $flash['new'] ?? [],
            'new'     => [],
        ];
    }
}
