<?php
declare(strict_types=1);

namespace Sugar\Core\Compiler;

/**
 * Template compiler interface
 */
interface CompilerInterface
{
    /**
     * Compile template source to executable PHP code
     *
     * @param string $source Template source code
     * @return string Compiled PHP code
     */
    public function compile(string $source): string;
}
