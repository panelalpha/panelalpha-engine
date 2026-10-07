<?php

namespace App\Lib\Lighthouse;

/** A Lighthouse run that produced no report; the message is what the API answers with. */
final class LighthouseFailed extends \RuntimeException
{
}
