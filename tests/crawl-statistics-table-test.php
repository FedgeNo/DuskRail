<?php

declare(strict_types=1);

$document = new DOMDocument();
$document -> loadHTML((new CrawlStatisticsTable()) -> render(), LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
$xpath = new DOMXPath($document);

assert_same('statistics table has four compact rows', 4, $xpath -> query('//table/tbody/tr') -> length);
assert_same('statistics table alternates heading rows', 2, $xpath -> query('//table/tbody/tr[@class="CrawlStatisticsHeadings"]') -> length);
assert_same('statistics table alternates value rows', 2, $xpath -> query('//table/tbody/tr[@class="CrawlStatisticsValues"]') -> length);
assert_same('statistics table has twelve headings', 12, $xpath -> query('//table/tbody/tr/th[@scope="col"]') -> length);
assert_same('statistics table has twelve live values', 12, $xpath -> query('//table/tbody/tr/td[starts-with(@id, "stat-")]') -> length);
assert_same('statistics table labels indexed count', 1, $xpath -> query('//th[text()="Indexed"]') -> length);
assert_same('statistics table labels disk space', 1, $xpath -> query('//th[text()="Disk Free"]') -> length);
