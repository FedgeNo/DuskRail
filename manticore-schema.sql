-- DuskRail's derived full-text indexes in the shared Manticore service.
-- MariaDB remains authoritative. Only presentable searchable Items are
-- copied; a row without any searchable text is omitted too. Crawl state,
-- URLs, HTML, hashes, and uncrawled rows stay in MariaDB.

CREATE TABLE IF NOT EXISTS duskrail_items (
    id bigint,
    inc bigint,
    isimage bool,
    title text,
    description text,
    fulltext text
) rt_mem_limit='536870912';

-- Every discovered edge is present because focused crawling searches anchor
-- text even before its child has been crawled. external is precomputed from
-- the two registrable domains; public result ranking counts distinct domain
-- values only where external=1.
CREATE TABLE IF NOT EXISTS duskrail_links (
    id bigint,
    parentid bigint,
    childid bigint,
    domain string attribute,
    external bool,
    description text
) rt_mem_limit='268435456';
