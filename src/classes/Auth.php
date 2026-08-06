<?php

declare(strict_types=1);

/**
 * Session login for the operator side of the site. There's one operator, not
 * a user table, so "who you are" is a single password hash in .env
 * (AUTH_PASSWORD_HASH, written by bin/install.php) rather than a Users table.
 *
 * Searching is deliberately not gated: this is a search engine, and the search
 * is the product. What's gated is running the crawl - watch.php's live feed,
 * the focus topic, deleting items. api/set-topic.php and api/delete-item.php
 * change state, so those take a CSRF token on top of the session: a session
 * cookie alone would let any other site's page submit to them on behalf of
 * whoever's signed in here.
 */
class Auth
{
    private const AUTHENTICATED_KEY = 'authenticated';
    private const CSRF_TOKEN_KEY = 'csrfToken';

    // Sent by watch.js on every state-changing request, read back here. A
    // header rather than a form field because a cross-origin form post can't
    // set custom headers at all without passing a CORS preflight first.
    private const CSRF_HEADER = 'HTTP_X_CSRF_TOKEN';

    public static function isAuthenticated(): bool
    {
        // A request with no session cookie cannot be signed in, so there's
        // nothing to read and no reason to start a session to find that out.
        // This is the common path now that the search side is public: without
        // it, every anonymous visitor to a public page is handed a session
        // id and leaves a session file behind on the server - unbounded
        // state, created by anyone, from an endpoint that asks for nothing.
        if (session_status() !== PHP_SESSION_ACTIVE && !isset($_COOKIE[session_name()])) {
            return false;
        }

        self::startSession();

        return ($_SESSION[self::AUTHENTICATED_KEY] ?? false) === true;
    }

    /**
     * Checks $password against the configured hash and, if it matches, marks
     * this session authenticated. Fails closed when no hash is configured at
     * all - an install that never set one must not end up letting everyone
     * through, which is exactly what an empty-string comparison would do.
     */
    public static function logIn(string $password): bool
    {
        $config = require ROOT_DIR . '/src/config.php';

        if ($config['authPasswordHash'] === '' || !password_verify($password, $config['authPasswordHash'])) {
            return false;
        }

        self::startSession();

        // The pre-login session id is known to whoever handed the visitor this
        // page; re-issuing it here means the id that ends up carrying the
        // logged-in session was never visible to them (session fixation).
        session_regenerate_id(true);

        $_SESSION[self::AUTHENTICATED_KEY] = true;

        return true;
    }

    public static function logOut(): void
    {
        self::startSession();

        $_SESSION = [];
        session_destroy();
    }

    /**
     * For a page: sends an unauthenticated visitor to the login form and stops
     * there, rather than rendering a shell of the page they can't use.
     */
    public static function requirePage(): void
    {
        if (self::isAuthenticated()) {
            return;
        }

        header('Location: ' . ServerURL::absolute('/login.php'));
        exit;
    }

    /**
     * For a read-only JSON endpoint: 401 and a JSON body, never a redirect -
     * the caller is fetch(), which would silently follow a redirect to the
     * login page and try to parse the HTML as JSON.
     */
    public static function requireAPI(): void
    {
        if (self::isAuthenticated()) {
            return;
        }

        http_response_code(401);
        echo json_encode(['error' => 'authentication required']);
        exit;
    }

    /**
     * For a JSON endpoint that changes something: a valid session AND a CSRF
     * token matching this session's.
     */
    public static function requireWriteAPI(): void
    {
        self::requireAPI();

        if (!hash_equals(self::csrfToken(), (string) ($_SERVER[self::CSRF_HEADER] ?? ''))) {
            http_response_code(403);
            echo json_encode(['error' => 'invalid CSRF token']);
            exit;
        }
    }

    /**
     * This session's CSRF token, minted on first use and stable for the rest
     * of the session so a page rendered once keeps working.
     */
    public static function csrfToken(): string
    {
        self::startSession();

        return $_SESSION[self::CSRF_TOKEN_KEY] ??= bin2hex(random_bytes(32));
    }

    /**
     * The session cookie is locked down here rather than left to php.ini,
     * which this project doesn't own: no JS access (httponly - nothing here
     * reads the cookie from script), not sent on cross-site navigations
     * (samesite), and HTTPS-only whenever the request itself arrived over
     * HTTPS.
     */
    private static function startSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        // Both calls below need the response headers still open. Every gate
        // here runs before a page emits anything, so this is only reachable
        // when something has gone wrong further up - and warning twice about
        // headers, on a request that's already broken, helps nobody.
        if (headers_sent()) {
            return;
        }

        session_set_cookie_params([
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => ($_SERVER['HTTPS'] ?? '') !== '',
        ]);

        session_start();
    }
}
