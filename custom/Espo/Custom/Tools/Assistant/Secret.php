<?php

namespace Espo\Custom\Tools\Assistant;

/**
 * The API key as a value that cannot leak by accident: it prints as "[key]" in string
 * conversion, var_dump and json_encode, and `scrub()` removes it from any text before that text
 * reaches a log, an error message or an HTTP answer.
 */
final class Secret implements \JsonSerializable
{
    public function __construct(private string $value)
    {}

    public function reveal(): string
    {
        return $this->value;
    }

    public function isSet(): bool
    {
        return $this->value !== '';
    }

    public function __toString(): string
    {
        return '[key]';
    }

    public function jsonSerialize(): string
    {
        return '[key]';
    }

    public function __debugInfo(): array
    {
        return ['value' => '[key]'];
    }

    public function scrub(string $text): string
    {
        if ($this->value === '' || strlen($this->value) < 8) {
            return $text;
        }

        return str_replace($this->value, '[key]', $text);
    }
}
