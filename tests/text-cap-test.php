<?php

declare(strict_types=1);

/**
 * Text::capRepeatedTerms() - the bound on how far repeating a word can carry
 * a page. MariaDB's relevance is linear in term frequency with no saturation,
 * so without this the top result for any query is whoever repeated it most.
 */

$nl = chr(10);

// Ordinary prose is returned untouched.
$prose = 'The quick brown fox jumps over the lazy dog.';
assert_same('normal text is unchanged', $prose, Text::capRepeatedTerms($prose));

$paragraphs = 'First paragraph here.' . $nl . $nl . 'Second paragraph here.';
assert_same('paragraph breaks survive', $paragraphs, Text::capRepeatedTerms($paragraphs));

// A word repeated past the cap keeps exactly the cap's worth.
$stuffed = Text::capRepeatedTerms(trim(str_repeat('widgets ', 500)));
assert_same('repetition is capped', 50, substr_count($stuffed, 'widgets'));

// The cap is per distinct word, not a budget shared across the page.
$two = Text::capRepeatedTerms(trim(str_repeat('widgets gadgets ', 500)));
assert_same('first term capped independently', 50, substr_count($two, 'widgets'));
assert_same('second term capped independently', 50, substr_count($two, 'gadgets'));

// A page under the cap loses nothing at all - the honest article about
// widgets that says "widgets" thirty times still says it thirty times.
$honest = trim(str_repeat('widgets ', 30));
assert_same('under the cap is untouched', 30, substr_count(Text::capRepeatedTerms($honest), 'widgets'));

// Real prose surrounding a stuffed block is kept, and kept first, so the
// snippet cut around the first match still reads like the page.
$mixed = 'Widgets are useful tools for people.' . $nl . trim(str_repeat('widgets ', 400));
$capped = Text::capRepeatedTerms($mixed);
assert_true('leading prose survives stuffing', str_starts_with($capped, 'Widgets are useful tools for people.'));

// Punctuation and case don't create separate terms the cap would miss - the
// index doesn't see them as different words either.
$punctuated = Text::capRepeatedTerms(trim(str_repeat('widgets, Widgets. WIDGETS ', 100)));
assert_same('case and punctuation count as one term', 50, substr_count(mb_strtolower($punctuated), 'widgets'));

// Words too short for the index to hold are left alone - capping them would
// mangle the text for no ranking effect whatever.
$shortWords = trim(str_repeat('an ox is up ', 100));
assert_same('sub-token-length words are untouched', $shortWords, Text::capRepeatedTerms($shortWords));

// The index splits tokens on every non-alphanumeric character, so joining
// repetitions with punctuation is one "word" to a whitespace split and
// hundreds of indexed occurrences to MariaDB. Counting the way the index
// tokenizes is what closes that.
$hyphenated = Text::capRepeatedTerms(trim(str_repeat('widgets-', 400), '-'));
assert_same('punctuation-joined stuffing is caught', 0, substr_count($hyphenated, 'widgets'));

$dotted = Text::capRepeatedTerms(trim(str_repeat('widgets.', 400), '.'));
assert_same('any separator is caught, not just hyphens', 0, substr_count($dotted, 'widgets'));

// Ordinary hyphenated words are not stuffing and must survive intact.
$compound = 'A state-of-the-art widgets guide, well-written and up-to-date.';
assert_same('hyphenated prose is untouched', $compound, Text::capRepeatedTerms($compound));

// Stopwords are never indexed, so capping them protects nothing and would
// strip the commonest words out of the back half of every long article.
$longProse = trim(str_repeat('the widget and the gadget in the box ', 60));
$cappedTokens = explode(' ', Text::capRepeatedTerms($longProse));
assert_same('stopwords are never dropped', 60 * 3, count(array_keys($cappedTokens, 'the', true)));
assert_same('content words in the same text still cap', 50, count(array_keys($cappedTokens, 'widget', true)));
