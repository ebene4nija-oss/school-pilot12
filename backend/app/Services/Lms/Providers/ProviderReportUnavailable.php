<?php

namespace App\Services\Lms\Providers;

use RuntimeException;

/**
 * The provider has no participant report for this meeting (yet). Zoom and
 * Google publish reports some minutes after a meeting ends, so the sync
 * tries again later rather than recording everyone absent.
 */
class ProviderReportUnavailable extends RuntimeException
{
}
