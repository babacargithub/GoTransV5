<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when an account debit would bring the balance below zero.
 */
class InsufficientAccountBalanceException extends RuntimeException {}
