<?php

namespace Espo\Custom\Tools\Accounting\Connectors;

use Espo\Custom\Tools\Accounting\Cfg;

/** The list of connectors. Adding a program = one class implementing Connector + one line here. */
class Registry
{
    public const CODES = ['merit', 'smartaccounts'];

    public function __construct(private Cfg $cfg)
    {}

    /** @return array<string, Connector> */
    public function all(): array
    {
        return [
            'merit' => new MeritConnector($this->cfg),
            'smartaccounts' => new SmartAccountsConnector($this->cfg),
        ];
    }

    public function get(string $code): ?Connector
    {
        return $this->all()[$code] ?? null;
    }

    /** The connector chosen in the settings, if any. */
    public function active(): ?Connector
    {
        return $this->get($this->cfg->provider());
    }
}
