<?php

declare(strict_types=1);

final class MarginaliaSearch extends BrowserSearch {
    protected const ENGINE = 'marginalia';
    protected const LABEL = 'Marginalia';
    protected const HOST = 'marginalia-search.com';
    protected const PARSER = MarginaliaResults::class;
    protected const LOCK_SQL = 'SELECT GET_LOCK(\'duskrail:marginalia-search\', 0)';
    protected const UNLOCK_SQL = 'SELECT RELEASE_LOCK(\'duskrail:marginalia-search\')';

    protected static function searchURL(string $query): string {
        return 'https://marginalia-search.com/search?' . http_build_query(['query' => $query], '', '&', PHP_QUERY_RFC3986);
    }
}
