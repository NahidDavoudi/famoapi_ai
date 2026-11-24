<?php

declare(strict_types=1);

namespace App\Modules\Sms;

/**
 * Thrown for invalid caller input (purpose/receptor/text/ref/tokens).
 * Always thrown BEFORE any DB row is written or the provider is called.
 */
final class SmsInputException extends SmsException
{
}
