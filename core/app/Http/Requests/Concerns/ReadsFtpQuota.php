<?php

namespace App\Http\Requests\Concerns;

trait ReadsFtpQuota
{
    /**
     * The quota in MB the request asks for; null when the account is to have
     * no quota of its own. A missing quota and an explicit 0 are the same
     * request.
     */
    public function getQuota(): ?int
    {
        if ($this->boolean('unlimited_quota')) {
            return null;
        }
        $quota = (int) $this->get('quota');

        return $quota > 0 ? $quota : null;
    }
}
