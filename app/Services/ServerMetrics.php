<?php

namespace App\Services;

use Throwable;

class ServerMetrics
{
    /**
     * @return array{
     *     hostname: string|null,
     *     os: string,
     *     php: string,
     *     disk: array{total_gb: float, free_gb: float, used_pct: float|null},
     *     memory: array{total_mb: int, available_mb: int, used_pct: float}|null,
     *     cpu_pct: float|null,
     *     uptime_s: int|null,
     *     cpu_temp_c: float|null,
     *     at: string
     * }
     */
    public function snapshot(): array
    {
        $os = $this->readOperatingSystem();

        return [
            'hostname' => gethostname() ?: null,
            'os' => php_uname('s').' '.php_uname('r'),
            'php' => PHP_VERSION,
            'disk' => $this->disk(),
            'memory' => $this->memory($os),
            'cpu_pct' => isset($os['cpu_pct']) ? round((float) $os['cpu_pct'], 1) : null,
            'uptime_s' => isset($os['uptime_s']) ? (int) $os['uptime_s'] : null,
            'cpu_temp_c' => isset($os['temp_c']) && is_numeric($os['temp_c'])
                ? round((float) $os['temp_c'], 1)
                : null,
            'at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array{total_gb: float, free_gb: float, used_pct: float|null}
     */
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

    /**
     * @return array{total_mb: int, available_mb: int, used_pct: float}|null
     */
    private function memory(?array $os): ?array
    {
        if (empty($os['mem_total_kb'])) {
            return null;
        }

        if (! isset($os['mem_free_kb']) || ! is_numeric($os['mem_free_kb'])) {
            return null;
        }

        $totalKb = (int) $os['mem_total_kb'];
        $availableKb = (int) $os['mem_free_kb'];

        if ($totalKb <= 0 || $availableKb < 0 || $availableKb > $totalKb) {
            return null;
        }

        return [
            'total_mb' => intdiv($totalKb, 1024),
            'available_mb' => intdiv($availableKb, 1024),
            'used_pct' => round(100 * ($totalKb - $availableKb) / $totalKb, 1),
        ];
    }

    /**
     * Lee el SO actual: Windows vía PowerShell/WMI, Linux vía /proc y /sys.
     *
     * @return array{
     *     mem_total_kb?: int|string|null,
     *     mem_free_kb?: int|string|null,
     *     cpu_pct?: int|float|string|null,
     *     uptime_s?: int|string|null,
     *     temp_c?: int|float|string|null
     * }|null
     */
    private function readOperatingSystem(): ?array
    {
        if ($this->isWindows()) {
            return $this->readWindows();
        }

        return $this->readLinux();
    }

    private function isWindows(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }

    /**
     * Ejecuta en Windows el script de PowerShell indicado por la consigna.
     *
     * El script es fijo (nunca se interpola entrada del usuario) y viaja en
     * Base64 (-EncodedCommand) para que PowerShell no reinterprete la línea.
     * Devuelve mem_total_kb/mem_free_kb en KB, cpu_pct (promedio de
     * LoadPercentage), uptime_s en segundos y temp_c en Celsius.
     *
     * @return array<string, mixed>|null
     */
    private function readWindows(): ?array
    {
        if (! function_exists('shell_exec')) {
            return null;
        }

        $script = <<<'PS'
$os = Get-CimInstance Win32_OperatingSystem
$cpu = (Get-CimInstance Win32_Processor |
    Measure-Object -Property LoadPercentage -Average).Average
$temp = $null
try {
    $ns = 'root/wmi'
    $cls = 'MSAcpi_ThermalZoneTemperature'
    $t = Get-CimInstance -Namespace $ns -ClassName $cls -ErrorAction Stop |
        Select-Object -First 1
    if ($t) { $temp = [math]::Round($t.CurrentTemperature / 10 - 273.15, 1) }
} catch {}
[pscustomobject]@{
    mem_total_kb = [int64]$os.TotalVisibleMemorySize
    mem_free_kb = [int64]$os.FreePhysicalMemory
    cpu_pct = $cpu
    uptime_s = [int64]((Get-Date) - $os.LastBootUpTime).TotalSeconds
    temp_c = $temp
} | ConvertTo-Json -Compress
PS;

        $encoded = base64_encode(mb_convert_encoding($script, 'UTF-16LE', 'UTF-8'));

        try {
            $output = @shell_exec("powershell -NoProfile -NonInteractive -EncodedCommand $encoded");
        } catch (Throwable) {
            return null;
        }

        if (! is_string($output) || trim($output) === '') {
            return null;
        }

        $decoded = json_decode(trim($output), true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Lee los mismos datos en Linux desde los archivos del sistema y devuelve
     * exactamente las mismas claves que el script de Windows.
     *
     * @return array{
     *     mem_total_kb: int|null,
     *     mem_free_kb: int|null,
     *     cpu_pct: float|null,
     *     uptime_s: int|null,
     *     temp_c: float|null
     * }
     */
    private function readLinux(): array
    {
        [$memTotalKb, $memAvailableKb] = $this->linuxMemoryKb();

        return [
            'mem_total_kb' => $memTotalKb,
            'mem_free_kb' => $memAvailableKb,
            'cpu_pct' => $this->linuxCpuUsage(),
            'uptime_s' => $this->linuxUptime(),
            'temp_c' => $this->linuxTemperature(),
        ];
    }

    /**
     * @return array{0: int|null, 1: int|null} [MemTotal en KB, MemAvailable (o MemFree) en KB]
     */
    private function linuxMemoryKb(): array
    {
        $contents = @file_get_contents('/proc/meminfo');

        if ($contents === false) {
            return [null, null];
        }

        preg_match('/^MemTotal:\s+(\d+)/m', $contents, $total);
        preg_match('/^MemAvailable:\s+(\d+)/m', $contents, $available);

        // MemFree queda como alternativa si el kernel no expone MemAvailable.
        if (! isset($available[1])) {
            preg_match('/^MemFree:\s+(\d+)/m', $contents, $available);
        }

        return [
            isset($total[1]) ? (int) $total[1] : null,
            isset($available[1]) ? (int) $available[1] : null,
        ];
    }

    private function linuxCpuUsage(): ?float
    {
        $readCpu = function (): ?array {
            $contents = @file_get_contents('/proc/stat');

            if (
                $contents === false ||
                ! preg_match('/^cpu\s+(.+)$/m', $contents, $matches)
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

        // Espera breve para comparar dos lecturas de /proc/stat.
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

        return round(100 * ($totalDelta - $idleDelta) / $totalDelta, 1);
    }

    private function linuxUptime(): ?int
    {
        $contents = @file_get_contents('/proc/uptime');

        if ($contents === false) {
            return null;
        }

        $parts = preg_split('/\s+/', trim($contents));

        return isset($parts[0]) && is_numeric($parts[0])
            ? (int) ((float) $parts[0])
            : null;
    }

    private function linuxTemperature(): ?float
    {
        // La ruta exacta de la consigna; luego otras zonas térmicas y hwmon.
        $candidates = ['/sys/class/thermal/thermal_zone0/temp'];

        foreach (glob('/sys/class/thermal/thermal_zone*/temp') ?: [] as $path) {
            $candidates[] = $path;
        }

        foreach (glob('/sys/class/hwmon/hwmon*/temp*_input') ?: [] as $path) {
            $candidates[] = $path;
        }

        foreach (array_unique($candidates) as $path) {
            $raw = @file_get_contents($path);

            if ($raw === false || ! is_numeric(trim($raw))) {
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
