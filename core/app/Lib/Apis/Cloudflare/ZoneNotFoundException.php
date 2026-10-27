<?php

namespace App\Lib\Apis\Cloudflare;

/** Cloudflare answered, and none of the zones the token can see holds the hostname. */
class ZoneNotFoundException extends CloudflareException {}
