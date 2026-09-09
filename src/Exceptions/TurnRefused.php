<?php

namespace Packstub\Agents\Exceptions;

use RuntimeException;

/**
 * A middleware stopped the turn before it reached the provider (a budget
 * spent, a guard that said no). The message is what the person reads under
 * their question; nothing is billed and nothing is stored as an answer.
 */
class TurnRefused extends RuntimeException {}
