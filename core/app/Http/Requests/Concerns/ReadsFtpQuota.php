<?php

namespace App\Http\Requests\Concerns;

trait ReadsFtpQuota
{
    /**
     * The quota in MB the request asks for; null when it asks for an unlimited
     * one. A missing quota and an explicit 0 are both unlimited: pure-ftpd
     * reads a 0 max size as no limit.
     */
    public function getQuota(): ?int
    {
        if (!empty($this->get('unlimited_quota'))) {
            return null;
        }
        $quota = (int) $this->get('quota');

        return $quota > 0 ? $quota : null;
    }
}
