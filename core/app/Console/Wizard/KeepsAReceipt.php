<?php

namespace App\Console\Wizard;

/**
 * The few lines a section wants to survive the wizard exiting.
 *
 * Every screen is drawn over the last, so anything not recorded here is gone
 * once `pae configure` returns. {@see Section::receipt()}
 */
trait KeepsAReceipt
{
    /** @var array<int, string> */
    protected array $receipt = [];

    /** @return array<int, string> */
    public function receipt(): array
    {
        return $this->receipt;
    }
}
