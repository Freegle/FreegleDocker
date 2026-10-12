<?php

namespace App\Services\Ux;

/**
 * Loki refused one metric query (HTTP 4xx, in practice max_query_series). That is a property of
 * the query, not of Loki being down, so the caller splits it or skips it.
 */
class UxSeriesLimit extends \RuntimeException
{
}
