<?php declare(strict_types=1);

namespace Shadow\Framework\Exception;

use LogicException;

/**
 * Thrown when the framework encounters an issue while working with
 * a configuration file, such as /config/app.php or /config/authentication.php.
 */
class ConfigurationException extends LogicException
{}
