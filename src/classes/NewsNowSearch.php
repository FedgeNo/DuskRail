<?php

declare(strict_types=1);

final class NewsNowSearch extends BrowserSearch {
    protected const ENGINE = 'newsnow';
    protected const EMPTY_RESULTS_HTTP_STATUS = 404;
    protected const LABEL = 'NewsNow';
    protected const HOST = 'newsnow.com';
    protected const PARSER = NewsNowResults::class;
    protected const LOCK_SQL = 'SELECT GET_LOCK(\'duskrail:newsnow-search\', 0)';
    protected const UNLOCK_SQL = 'SELECT RELEASE_LOCK(\'duskrail:newsnow-search\')';

    protected static function searchURL(string $query): string {
        return 'https://www.newsnow.com/us/?' . http_build_query(['search' => $query, 'lang' => 'en', 'searchheadlines' => 1], '', '&', PHP_QUERY_RFC3986);
    }
}
