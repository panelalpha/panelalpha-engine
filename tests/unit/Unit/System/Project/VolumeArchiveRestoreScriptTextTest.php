<?php

namespace Tests\Unit\System\Project;

use App\System\Project\Dind\VolumeArchive;
use PHPUnit\Framework\TestCase;

/** The restore-beside script for named volumes, byte for byte. */
class VolumeArchiveRestoreScriptTextTest extends TestCase
{
    private const RESTORE = <<<'SH'
set -e
cd /data
if [ -d .panelalpha-aside ]; then
  echo "leftover .panelalpha-aside exists; refusing to overwrite" >&2
  exit 1
fi
rm -rf .panelalpha-incoming
mkdir .panelalpha-incoming
tar -xzf /archive -C .panelalpha-incoming
mkdir .panelalpha-aside
for entry in * .*; do
  [ -e "$entry" ] || continue
  case "$entry" in
    .|..) continue ;;
    .panelalpha-incoming|.panelalpha-aside) continue ;;
  esac
  mv "$entry" .panelalpha-aside/
done
for entry in .panelalpha-incoming/* .panelalpha-incoming/.*; do
  [ -e "$entry" ] || continue
  base=$(basename "$entry")
  case "$base" in
    .|..) continue ;;
  esac
  mv "$entry" .
done
rm -rf .panelalpha-incoming
rm -rf .panelalpha-aside
SH;

    public function test_the_restore_script_is_unchanged(): void
    {
        $this->assertSame(self::RESTORE, VolumeArchive::volumeRestoreScript());
    }

    public function test_the_restore_script_carries_no_carriage_return(): void
    {
        $this->assertStringNotContainsString("\r", VolumeArchive::volumeRestoreScript());
    }
}
