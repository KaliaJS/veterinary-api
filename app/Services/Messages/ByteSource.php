<?php

namespace App\Services\Messages;

/**
 * Les octets d'un enregistrement, lus par position (quatrième vérification,
 * 07.11.2026) : un fichier de 20 Mo se lit par une fenêtre de 256 Ko, sans
 * être chargé ni copié en entier. Une chaîne déjà en mémoire se lit de même.
 */
final class ByteSource
{
    /** La fenêtre lue dans le fichier */
    private const int WINDOW = 262144;

    private string $window = '';

    private int $windowStart = 0;

    /** @param  resource|null  $handle */
    private function __construct(private mixed $handle, private ?string $bytes, private int $length) {}

    public static function fromString(string $bytes): self
    {
        return new self(null, $bytes, strlen($bytes));
    }

    public static function fromFile(string $path): ?self
    {
        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return null;
        }
        $size = @filesize($path);
        if ($size === false) {
            fclose($handle);

            return null;
        }

        return new self($handle, null, $size);
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
        $this->handle = null;
        $this->window = '';
    }

    public function length(): int
    {
        return $this->length;
    }

    /** Au plus `$count` octets depuis `$at` (moins à la fin du fichier) */
    public function read(int $at, int $count): string
    {
        if ($at < 0 || $count <= 0 || $at >= $this->length) {
            return '';
        }
        $count = min($count, $this->length - $at);
        if ($this->bytes !== null) {
            return substr($this->bytes, $at, $count);
        }
        if ($at < $this->windowStart || $at + $count > $this->windowStart + strlen($this->window)) {
            fseek($this->handle, $at);
            $this->window = (string) fread($this->handle, max($count, self::WINDOW));
            $this->windowStart = $at;
        }

        return substr($this->window, $at - $this->windowStart, $count);
    }

    /** La position de `$needle` à partir de `$from`, ou null */
    public function find(string $needle, int $from): ?int
    {
        if ($this->bytes !== null) {
            $found = strpos($this->bytes, $needle, max(0, $from));

            return $found === false ? null : $found;
        }
        $step = self::WINDOW - strlen($needle) + 1;
        for ($at = max(0, $from); $at < $this->length; $at += $step) {
            $found = strpos($this->read($at, self::WINDOW), $needle);
            if ($found !== false) {
                return $at + $found;
            }
        }

        return null;
    }
}
