<?php

declare(strict_types=1);

/**
 * The one-line "what's in here" shown under the search box when there's no
 * query yet - a search page with an empty middle says nothing about whether
 * the engine holds ten pages or ten million, and that's the first thing a
 * visitor wonders.
 *
 * CrawlStatistics owns and caches the catalogue scan shared with the admin
 * view, so the two summaries cannot drift or multiply the database work.
 */
class IndexStats extends HTMLObject
{
    public ?string $class = 'IndexStats mt-3';

    public int $pages = 0;
    public int $images = 0;

    public function __construct()
    {
        parent::__construct();

        $statistics = new CrawlStatistics();
        $this -> pages = $statistics -> pages;
        $this -> images = $statistics -> images;
    }

    public function toDOM(): \DOMElement
    {
        $this -> addContent('Searching ' . number_format($this -> pages) . ' pages and ' . number_format($this -> images) . ' images.');

        return parent::toDOM();
    }
}
