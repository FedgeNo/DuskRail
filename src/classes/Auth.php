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

    // Sent by watch.js on every state-changing JSON request. A header rather
    // than a form field means a cross-origin form cannot imitate an API write;
    // ordinary forms such as logout carry the same token in _csrf instead.
    private const CSRF_HEADER = 'HTTP_X_CSRF_TOKEN';
    private static ?bool $authenticationResult = null;

    public static function isAuthenticated(): bool
    {
        if (self::$authenticationResult !== null) {
            return self::$authenticationResult;
        }

        // A request with no session cookie cannot be signed in, so there's
        // nothing to read and no reason to start a session to find that out.
        // This is the common path now that the search side is public: without
        // it, every anonymous visitor to a public page is handed a session
        // id and leaves a session file behind on the server - unbounded
        // state, created by anyone, from an endpoint that asks for nothing.
        if (session_status() !== PHP_SESSION_ACTIVE && !isset($_COOKIE[session_name()])) {
            return self::$authenticationResult = false;
        }

        if (!self::startSession()) {
            return self::$authenticationResult = false;
        }

        $authenticated = ($_SESSION[self::AUTHENTICATED_KEY] ?? false) === true;

        if (!$authenticated) {
            // An arbitrary cookie must not leave a new session file or a
            // replacement session id behind. Strict mode may mint a fresh id
            // for an unknown one, so explicitly destroy anonymous state and
            // expire whatever cookie PHP was about to return.
            self::discardSession();
        }

        return self::$authenticationResult = $authenticated;
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

        if (!self::startSession()) {
            return false;
        }

        // The pre-login session id is known to whoever handed the visitor this
        // page; re-issuing it here means the id that ends up carrying the
        // logged-in session was never visible to them (session fixation).
        session_regenerate_id(true);

        $_SESSION[self::AUTHENTICATED_KEY] = true;
        self::$authenticationResult = true;

        return true;
    }

    public static function logOut(): void
    {
        self::startSession();

        $_SESSION = [];
        self::discardSession();
        self::$authenticationResult = false;
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

    /** Require an authenticated, CSRF-protected ordinary form submission. */
    public static function requireWritePage(): void
    {
        self::requirePage();

        if (!hash_equals(self::csrfToken(), (string) ($_POST['_csrf'] ?? ''))) {
            http_response_code(403);
            echo 'Invalid CSRF token.';
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
    private static function startSession(): bool
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return true;
        }

        // Both calls below need the response headers still open. Every gate
        // here runs before a page emits anything, so this is only reachable
        // when something has gone wrong further up - and warning twice about
        // headers, on a request that's already broken, helps nobody.
        if (headers_sent()) {
            return false;
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');

        session_set_cookie_params([
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => ($_SERVER['HTTPS'] ?? '') !== '',
        ]);

        return session_start();
    }

    /** Destroy server state and expire the browser cookie with matching scope. */
    private static function discardSession(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION = [];
            session_destroy();
        }

        if (headers_sent()) {
            return;
        }

        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 3600,
            'path' => $params['path'],
            'domain' => $params['domain'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'] ?? 'Lax',
        ]);
    }
}
