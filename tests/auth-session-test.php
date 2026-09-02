<?php

declare(strict_types=1);

// A caller-selected anonymous id may be opened briefly to discover it is not
// authenticated, but must not survive the request as server-side state.
$sessionDirectory = sys_get_temp_dir() . '/duskrail-session-test-' . bin2hex(random_bytes(6));
mkdir($sessionDirectory, 0700);
session_name('DuskRailSessionTest');
session_save_path($sessionDirectory);
$_COOKIE[session_name()] = '0123456789abcdef0123456789abcdef';

assert_false('bogus session is not authenticated', Auth::isAuthenticated());
assert_same('bogus session leaves no file', [], glob($sessionDirectory . '/sess_*') ?: []);
assert_same('bogus session is closed', PHP_SESSION_NONE, session_status());

unset($_COOKIE[session_name()]);
rmdir($sessionDirectory);
