<?php

namespace App\Integrations\GeoLocation;

interface AcceptsDatabaseTerms
{
    public function acceptTerms(): void;
}
