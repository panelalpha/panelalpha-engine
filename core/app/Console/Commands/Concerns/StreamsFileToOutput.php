<?php

namespace App\Console\Commands\Concerns;

trait StreamsFileToOutput
{
    /** Copy a file to stdout, or to $out with a note of where it went. */
    protected function streamFile(string $source, ?string $out): int
    {
        if ($out === null) {
            // php://output, not the console output: the bytes go out unchanged.
            $this->copyFile($source, 'php://output');

            return 0;
        }

        $handle = fopen($out, 'wb');
        if ($handle === false) {
            $this->error("Could not open {$out} for writing");
            return 1;
        }
        try {
            $this->copyFile($source, $handle);
        } finally {
            fclose($handle);
        }

        $this->info(sprintf('Saved to %s (%s bytes)', $out, number_format((int) filesize($out))));
        return 0;
    }

    /** @param string|resource $to */
    private function copyFile(string $source, $to): void
    {
        $in = fopen($source, 'r');
        $target = is_string($to) ? fopen($to, 'wb') : $to;
        try {
            stream_copy_to_stream($in, $target);
        } finally {
            fclose($in);
            if (is_string($to)) {
                fclose($target);
            }
        }
    }
}
