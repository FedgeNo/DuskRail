<?php

declare(strict_types=1);

assert_same('Empty egress configuration preserves default proxy', [], BrowserEgress::addresses(''));
foreach (['127.0.0.1', '10.0.0.1', '::1', 'not-an-address', '1.1.1.1,', '1.1.1.1,1.1.1.1'] as $setting) {
    try {
        BrowserEgress::addresses($setting);
        assert_true('Invalid egress setting rejected: ' . $setting, false);
    } catch (InvalidArgumentException) {
        assert_true('Invalid egress setting rejected: ' . $setting, true);
    }
}
$state_file = tempnam(sys_get_temp_dir(), 'egress-test-');
try {
    $selected = [];
    for ($index = 0; $index < 5; $index++) $selected[] = BrowserEgress::nextAddress('1.1.1.1, 8.8.8.8', $state_file);
    assert_same('Egress round robin persists and wraps', ['1.1.1.1', '8.8.8.8', '1.1.1.1', '8.8.8.8', '1.1.1.1'], $selected);
    assert_same('Disabled rotation returns null', null, BrowserEgress::nextAddress('', $state_file));
    assert_same('Disabled rotation leaves counter unchanged', '1', file_get_contents($state_file));
    file_put_contents($state_file, 'invalid');
    try {
        BrowserEgress::nextAddress('1.1.1.1', $state_file);
        assert_true('Corrupt egress state fails explicitly', false);
    } catch (RuntimeException) {
        assert_true('Corrupt egress state fails explicitly', true);
    }
} finally {
    unlink($state_file);
}
