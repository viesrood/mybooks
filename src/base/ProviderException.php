<?php

declare(strict_types=1);

namespace viesrood\mybooks\base;

/**
 * Anything that went wrong while talking to a book service.
 *
 * The message is meant for the control panel (it ends up in the reader's
 * "last error"), so it is short, translated and never contains a token.
 */
class ProviderException extends \RuntimeException
{
}
