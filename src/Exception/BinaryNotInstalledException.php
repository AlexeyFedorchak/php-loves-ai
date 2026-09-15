<?php

declare(strict_types=1);

namespace PhpLovesAi\Exception;

use PhpLovesAi\Binary\Tool;

final class BinaryNotInstalledException extends \RuntimeException implements PhpLovesAiException
{
    public function __construct(public readonly Tool $tool)
    {
        parent::__construct("The {$tool->label()} is not installed yet.");
    }
}
