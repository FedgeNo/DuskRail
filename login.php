<?php

declare(strict_types=1);

require __DIR__ . '/init.php';

// No CSRF token on this form: a token is bound to a session, and the whole
// point of this page is that the visitor doesn't have an authenticated one
// yet. The thing a token would protect - being logged in as someone else
// against your will - needs the attacker to already know the password here,
// at which point they don't need your browser.
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $retryAfter = RateLimit::loginRetryAfter();

    if ($retryAfter !== null) {
        $error = 'Too many attempts. Try again in ' . $retryAfter . ' second' . ($retryAfter === 1 ? '' : 's') . '.';
    } elseif (Auth::logIn((string) ($_POST['password'] ?? ''))) {
        header('Location: ' . ServerURL::absolute('/'));
        exit;
    } else {
        $error = 'That password isn\'t right.';
    }
}

if (Auth::isAuthenticated()) {
    header('Location: ' . ServerURL::absolute('/'));
    exit;
}

$page = Page::create('Sign In');

$container = new Div();
$container -> class = 'LoginPage';

$form = new Form();
$form -> method = 'post';
$form -> action = ServerURL::absolute('/login.php');

$fieldset = new Fieldset();
$fieldset -> addContent(new Legend('Sign in'));

if ($error !== null) {
    $errorLine = new Div();
    $errorLine -> class = 'LoginError';
    $errorLine -> addContent($error);
    $fieldset -> addContent($errorLine);
}

$passwordLabel = new Label();
$passwordLabel -> for = 'password-input';
$passwordLabel -> class = 'form-label';
$passwordLabel -> addContent('Password');
$fieldset -> addContent($passwordLabel);

$passwordInput = new Input();
$passwordInput -> id = 'password-input';
$passwordInput -> type = 'password';
$passwordInput -> name = 'password';
$passwordInput -> autocomplete = 'current-password';
$passwordInput -> autofocus = true;
$passwordInput -> class = 'form-control';
$fieldset -> addContent($passwordInput);

$submit = new Button('Sign In');
$submit -> type = 'submit';
$submit -> class = 'btn btn-primary mt-3';
$fieldset -> addContent($submit);

$form -> addContent($fieldset);
$container -> addContent($form);
$page -> addContent($container);

$page -> send();
