<?php

namespace Tests\Integration\System\Privileged;

use App\System\Privileged;
use PHPUnit\Framework\TestCase;
use Tests\Support\LocalRoot;

final class RootFilesBrokerTest extends TestCase
{
    public function test_a_home_path_is_one_remove_if_exists_takes_and_remove_refuses(): void
    {
        $staged = '/home/u/.panelalpha-volumes/project_db/_data.incoming';

        $this->assertSame(['rm', '-f', '--', $staged], LocalRoot::checked(Privileged::files()->removeIfExists($staged)));
        $this->expectExceptionMessage("refused: '{$staged}' is not below /opt/panelalpha/shared-hosting");
        LocalRoot::checked(Privileged::files()->remove($staged));
    }
}
