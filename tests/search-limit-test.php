<?php

declare(strict_types=1);

assert_same('public search query cap is explicit', 256, SearchResults::MAX_QUERY_LENGTH);
