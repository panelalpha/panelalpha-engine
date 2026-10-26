<?php

namespace App\Lib\Hub;

use RuntimeException;

/** Something an operator has to read: the message is printed as it is. */
class HubException extends RuntimeException {}
