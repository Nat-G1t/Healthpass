<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by App\Actions\CancelBatch when the row it locked may no longer be
 * cancelled (FR-ADM-11, D-92, D-129). The message is already worded for the
 * person who pressed Cancel, so a controller shows it as-is.
 */
final class BatchNotCancellable extends RuntimeException {}
