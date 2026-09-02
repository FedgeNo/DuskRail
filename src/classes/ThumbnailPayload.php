<?php

declare(strict_types=1);

/** A thumbnail response backed by either a cached file or transient bytes. */
final class ThumbnailPayload
{
    public function __construct(private ?string $file, private ?string $bytes)
    {
        if (($this -> file === null) === ($this -> bytes === null)) {
            throw new \InvalidArgumentException('A thumbnail payload requires exactly one source.');
        }
    }

    public function file(): ?string
    {
        return $this -> file;
    }

    public function length(): ?int
    {
        if ($this -> bytes !== null) {
            return strlen($this -> bytes);
        }

        $length = filesize((string) $this -> file);

        return $length === false ? null : $length;
    }

    public function send(): bool
    {
        if ($this -> bytes !== null) {
            echo $this -> bytes;

            return true;
        }

        return readfile((string) $this -> file) !== false;
    }
}
