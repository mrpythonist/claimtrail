<?php

namespace App\Support;

/**
 * Thrown when a backup cannot be created, verified, or restored.
 *
 * The message is written to the backup run row and shown to the
 * operator, so it must say what is wrong and what to do about it,
 * never a stack trace.
 */
class BackupException extends \RuntimeException {}
