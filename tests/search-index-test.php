<?php

declare(strict_types=1);

assert_same('bare search terms keep any-word matching', 'alpha | beta', SearchIndex::matchExpression('alpha beta'));
assert_same('quoted search terms stay a phrase', '"alpha beta"', SearchIndex::matchExpression('"alpha beta"'));
assert_same('phrases and bare terms can coexist', 'alpha | "beta gamma"', SearchIndex::matchExpression('alpha "beta gamma"'));
assert_same('query operators cannot survive as operators', 'alpha | beta | gamma', SearchIndex::matchExpression('+alpha -beta @gamma'));
assert_same('punctuation separates index terms', 'alpha | beta', SearchIndex::matchExpression('alpha—beta'));
assert_same('operator-only input is not searchable', '', SearchIndex::matchExpression('+-@!'));
