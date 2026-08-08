<?php

namespace App\Services\ReportCard;

/**
 * Raised when a school-supplied report card template is malformed, unsafe, or
 * exceeds a rendering limit. Always surfaced to the importing admin verbatim —
 * the person fixing the template is the person reading the message.
 */
class TemplateException extends \RuntimeException
{
}
