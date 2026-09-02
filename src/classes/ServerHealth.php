<?php

declare(strict_types=1);

/** Lightweight host measurements safe to expose only behind admin auth. */
class ServerHealth
{
    public int $diskFreeBytes;
    public int $diskTotalBytes;
    public int $memoryUsedBytes;
    public int $memoryTotalBytes;
    public float $cpuLoad;
    public int $cpuCount;

    public function __construct()
    {
        $this -> diskFreeBytes = (int) disk_free_space(ROOT_DIR);
        $this -> diskTotalBytes = (int) disk_total_space(ROOT_DIR);

        $memory = $this -> memoryInformation();
        $this -> memoryTotalBytes = $memory['MemTotal'] * 1024;
        $this -> memoryUsedBytes = ($memory['MemTotal'] - $memory['MemAvailable']) * 1024;

        $load = sys_getloadavg();
        $this -> cpuLoad = (float) ($load[0] ?? 0);
        $this -> cpuCount = max(1, preg_match_all('/^processor\s*:/m', file_get_contents('/proc/cpuinfo')));
    }

    private function memoryInformation(): array
    {
        $information = [];
        preg_match_all('/^(MemTotal|MemAvailable):\s+(\d+)\s+kB$/m', file_get_contents('/proc/meminfo'), $matches, PREG_SET_ORDER);

        foreach ($matches as $match) {
            $information[$match[1]] = (int) $match[2];
        }

        return $information;
    }

    public function toJSON(): array
    {
        return [
            'diskFreeBytes' => $this -> diskFreeBytes,
            'diskTotalBytes' => $this -> diskTotalBytes,
            'memoryUsedBytes' => $this -> memoryUsedBytes,
            'memoryTotalBytes' => $this -> memoryTotalBytes,
            'cpuLoad' => $this -> cpuLoad,
            'cpuCount' => $this -> cpuCount,
        ];
    }
}
