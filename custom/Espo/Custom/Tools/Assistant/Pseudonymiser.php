<?php

namespace Espo\Custom\Tools\Assistant;

/**
 * Private customers reach the model as "K-<n>", never by name: the server keeps the map
 * K-n -> customer id (in the journal row, so follow-up questions keep the same numbers) and puts
 * the real names back into the text the OPERATOR reads — checking, at that moment, that the
 * operator's role may see that customer. Companies keep their names (not personal data).
 */
class Pseudonymiser
{
    /** @var array<string, string> alias => customer id */
    private array $map = [];
    /** @var array<string, string> customer id => alias */
    private array $rev = [];
    private int $next = 1;

    /** @param array<string, string> $seed */
    public function __construct(array $seed = [])
    {
        foreach ($seed as $alias => $id) {
            if (preg_match('/^K-(\d{1,6})$/', (string) $alias, $m) && is_string($id) && $id !== '') {
                $this->map[$alias] = $id;
                $this->rev[$id] = $alias;
                $this->next = max($this->next, (int) $m[1] + 1);
            }
        }
    }

    public function alias(string $customerId): string
    {
        if (!isset($this->rev[$customerId])) {
            $a = 'K-' . $this->next++;
            $this->rev[$customerId] = $a;
            $this->map[$a] = $customerId;
        }

        return $this->rev[$customerId];
    }

    public function idOf(string $alias): ?string
    {
        return $this->map[strtoupper(trim($alias))] ?? null;
    }

    /** @return array<string, string> */
    public function map(): array
    {
        return $this->map;
    }

    /**
     * @param callable(string): ?string $nameOf  customer id => display name, or null when the user may not see it
     */
    public function resolve(string $text, callable $nameOf): string
    {
        return (string) preg_replace_callback('/\bK-(\d{1,6})\b/', function ($m) use ($nameOf) {
            $id = $this->map[$m[0]] ?? null;
            $name = $id ? $nameOf($id) : null;

            return $name !== null && $name !== '' ? $name : $m[0];
        }, $text);
    }
}
