<?php

declare(strict_types=1);

/** The dense live crawl and server summary at the top of the watch page. */
class CrawlStatisticsTable extends HTMLObject
{
    public string $tagName = 'table';
    public ?string $class = 'CrawlStatisticsTable';

    private const ROWS = [
        [
            'found' => 'Found',
            'indexed' => 'Indexed',
            'searchable' => 'Searchable',
            'queued' => 'Queued',
        ],
        [
            'pages' => 'Pages',
            'images' => 'Images',
            'hosts' => 'Hosts',
            'dead' => 'Dead',
        ],
        [
            'disk-free' => 'Disk Free',
            'disk-used' => 'Disk Used',
            'memory' => 'Memory',
            'cpu-load' => 'CPU Load',
        ],
    ];

    public function toDOM(): \DOMElement
    {
        $table = parent::toDOM();
        $table -> setAttribute('aria-label', 'Live crawl and server statistics');
        $body = self::currentDocument() -> createElement('tbody');

        foreach (self::ROWS as $statistics) {
            $heading_row = self::currentDocument() -> createElement('tr');
            $heading_row -> setAttribute('class', 'CrawlStatisticsHeadings');
            $value_row = self::currentDocument() -> createElement('tr');
            $value_row -> setAttribute('class', 'CrawlStatisticsValues');

            foreach ($statistics as $id => $label) {
                $heading = self::currentDocument() -> createElement('th');
                $heading -> setAttribute('scope', 'col');
                $heading -> textContent = $label;
                $heading_row -> appendChild($heading);

                $value = self::currentDocument() -> createElement('td');
                $value -> setAttribute('id', 'stat-' . $id);
                $value -> textContent = '…';
                $value_row -> appendChild($value);
            }

            $body -> appendChild($heading_row);
            $body -> appendChild($value_row);
        }

        $table -> appendChild($body);

        return $table;
    }
}
