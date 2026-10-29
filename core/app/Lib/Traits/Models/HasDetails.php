<?php

namespace App\Lib\Traits\Models;

/**
 * @property ?array $details
 */
trait HasDetails
{
    /**
     * @return array
     */
    public function getDetails()
    {
        return is_null($this->details) ? [] : $this->details;
    }

    /**
     * @param  array  $details
     * @return array
     */
    public function setDetails($details)
    {
        $this->details = array_merge(
            $this->getDetails(),
            $details,
        );

        return $this->details;
    }
}
