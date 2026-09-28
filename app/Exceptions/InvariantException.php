<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when a financial operation would violate the invariant
 * SUM(account.balance) == SUM(caisse.balance).
 */
class InvariantException extends RuntimeException {}
