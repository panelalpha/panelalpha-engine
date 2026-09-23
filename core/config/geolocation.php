<?php

return [
    // Catalog adapter that provisions a local City MMDB and looks up visitor
    // IPs from that file. This value only selects who fetches the file.
    'driver' => env('GEOLOCATION_DRIVER', 'dbip'),
];
