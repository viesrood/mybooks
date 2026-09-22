<?php

declare(strict_types=1);

namespace viesrood\mybooks\services;

/**
 * A cover URL that will never give a usable cover: not an image, a
 * placeholder, too large, or pointing somewhere it must not. Unlike a timeout,
 * trying again later would not help.
 */
class CoverRejectedException extends \RuntimeException
{
}
