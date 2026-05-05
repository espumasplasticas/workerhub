<?php

namespace App\Support;

use Epsalibrary\Contracts\FlatFileWriterInterface;

class NullFlatFileWriter implements FlatFileWriterInterface
{
    public function open(): void
    {
    }

    public function writeLine(string $line): void
    {
    }

    public function close(): void
    {
    }

    public function getFilePath(): string
    {
        return '';
    }

    public function getFile(): string
    {
        return '';
    }
}
