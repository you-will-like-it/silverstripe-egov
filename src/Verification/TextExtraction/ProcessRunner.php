<?php

declare(strict_types=1);

namespace YouWillLikeIT\EGov\Verification\TextExtraction;

use RuntimeException;
use SilverStripe\Core\Injector\Injectable;

/**
 * Minimal shell-free process runner (argument array → no shell interpolation).
 */
class ProcessRunner
{
    use Injectable;

    /**
     * @param list<string> $command
     * @return string stdout
     */
    public function run(array $command, int $timeoutSeconds = 60): string
    {
        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = proc_open($command, $descriptors, $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Proces se ne može pokrenuti: ' . $command[0]);
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $deadline = microtime(true) + $timeoutSeconds;
        while (true) {
            $stdout .= (string) stream_get_contents($pipes[1]);
            $stderr .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if (microtime(true) > $deadline) {
                proc_terminate($process, 9);
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
                throw new RuntimeException('Proces je prekoračio vremensko ograničenje: ' . $command[0]);
            }
            usleep(50_000);
        }
        $stdout .= (string) stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exit = $status['exitcode'];
        proc_close($process);

        if ($exit !== 0) {
            throw new RuntimeException(sprintf('%s je završio sa kodom %d: %s', $command[0], $exit, trim($stderr)));
        }
        return $stdout;
    }

    public function binaryAvailable(string $binary): bool
    {
        if (str_contains($binary, '/')) {
            return is_executable($binary);
        }
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            if ($dir !== '' && is_executable($dir . DIRECTORY_SEPARATOR . $binary)) {
                return true;
            }
        }
        return false;
    }
}
