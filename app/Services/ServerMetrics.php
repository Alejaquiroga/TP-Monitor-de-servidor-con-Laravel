<?php

namespace App\Services;

class ServerMetrics
{
    public function snapshot(): array
    {
        return [
            'hostname' => gethostname(),
            'os' => php_uname('s') . ' ' . php_uname('r'),
            'php' => PHP_VERSION,
            'disk' => $this->disk(),
            'memory' => $this->memory(),
            'cpu_pct' => $this->cpuUsage(),
            'uptime_s' => $this->uptime(),
            'cpu_temp_c' => $this->temperature(),
            'at' => now()->toIso8601String(),
        ];
    }

    private function disk(): array
    {
        $path = base_path();
        $total = disk_total_space($path);
        $free = disk_free_space($path);

        return [
            'total_gb' => round($total / 1e9, 1),
            'free_gb' => round($free / 1e9, 1),
            'used_pct' => $total > 0
                ? round(100 * ($total - $free) / $total, 1)
                : null,
        ];
    }

    private function memory(): ?array
    {
        // En Linux los datos de memoria están en /proc/meminfo.
        $contents = @file_get_contents('/proc/meminfo');

        if ($contents === false) {
            return null;
        }

        preg_match('/^MemTotal:\s+(\d+)/m', $contents, $total);
        preg_match('/^MemAvailable:\s+(\d+)/m', $contents, $available);

        if (
            !isset($total[1], $available[1]) ||
            (int) $total[1] <= 0
        ) {
            return null;
        }

        $totalKb = (int) $total[1];
        $availableKb = (int) $available[1];

        return [
            'total_mb' => intdiv($totalKb, 1024),
            'available_mb' => intdiv($availableKb, 1024),
            'used_pct' => round(
                100 * ($totalKb - $availableKb) / $totalKb,
                1
            ),
        ];
    }

    private function cpuUsage(): ?float
    {
        $readCpu = function (): ?array {
            $contents = @file_get_contents('/proc/stat');

            if (
                $contents === false ||
                !preg_match('/^cpu\s+(.+)$/m', $contents, $matches)
            ) {
                return null;
            }

            $values = array_map(
                'intval',
                preg_split('/\s+/', trim($matches[1]))
            );

            $idle = ($values[3] ?? 0) + ($values[4] ?? 0);
            $total = array_sum($values);

            return [$total, $idle];
        };

        $first = $readCpu();

        if ($first === null) {
            return null;
        }

        // Espera breve para comparar dos lecturas de CPU.
        usleep(200000);

        $second = $readCpu();

        if ($second === null) {
            return null;
        }

        $totalDelta = $second[0] - $first[0];
        $idleDelta = $second[1] - $first[1];

        if ($totalDelta <= 0) {
            return null;
        }

        return round(
            100 * ($totalDelta - $idleDelta) / $totalDelta,
            1
        );
    }

    private function uptime(): ?int
    {
        $contents = @file_get_contents('/proc/uptime');

        if ($contents === false) {
            return null;
        }

        $parts = preg_split('/\s+/', trim($contents));

        return isset($parts[0]) ? (int) $parts[0] : null;
    }

    private function temperature(): ?float
    {
        $zones = glob('/sys/class/thermal/thermal_zone*/temp') ?: [];

        foreach ($zones as $zone) {
            $raw = @file_get_contents($zone);

            if ($raw === false || !is_numeric(trim($raw))) {
                continue;
            }

            $temperature = (float) trim($raw);

            // Linux suele expresar la temperatura en milésimas de °C.
            if ($temperature >= 1000) {
                $temperature /= 1000;
            }

            if ($temperature >= 0 && $temperature <= 120) {
                return round($temperature, 1);
            }
        }

        return null;
    }
}
