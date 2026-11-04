<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Monitor del servidor</title>
    <style>
        body {
            font-family: system-ui, sans-serif;
            background: #f4f5f7;
            margin: 0;
            padding: 24px;
        }
        h1 { margin-top: 0; }
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
        }
        .card {
            background: #fff;
            border-radius: 8px;
            padding: 16px;
            box-shadow: 0 1px 3px rgba(0,0,0,.1);
        }
        .label { color: #666; font-size: 14px; }
        .value { font-size: 24px; font-weight: 600; margin-top: 4px; }
    </style>
</head>
<body>
    <h1>Monitor del servidor</h1>
    <p>Última lectura: <span id="at">{{ $data['at'] }}</span></p>

    <div class="grid">
        <div class="card">
            <div class="label">Nombre del equipo</div>
            <div class="value">{{ $data['hostname'] }}</div>
        </div>
        <div class="card">
            <div class="label">Sistema operativo</div>
            <div class="value">{{ $data['os'] }}</div>
        </div>
        <div class="card">
            <div class="label">Versión de PHP</div>
            <div class="value">{{ $data['php'] }}</div>
        </div>
        <div class="card">
            <div class="label">Disco usado (%)</div>
            <div class="value" id="disk">{{ $data['disk']['used_pct'] }}</div>
        </div>
        <div class="card">
            <div class="label">Memoria usada (%)</div>
            <div class="value" id="memory">{{ $data['memory']['used_pct'] ?? 'No disponible' }}</div>
        </div>
        <div class="card">
            <div class="label">Uso de CPU (%)</div>
            <div class="value" id="cpu">{{ $data['cpu_pct'] ?? 'No disponible' }}</div>
        </div>
        <div class="card">
            <div class="label">Temperatura CPU (°C)</div>
            <div class="value" id="temp">{{ $data['cpu_temp_c'] ?? 'No disponible' }}</div>
        </div>
        <div class="card">
            <div class="label">Encendido hace (segundos)</div>
            <div class="value" id="uptime">{{ $data['uptime_s'] ?? 'No disponible' }}</div>
        </div>
    </div>

    <script>
        async function actualizar() {
            const res = await fetch('/api/metrics');
            const d = await res.json();

            document.getElementById('at').textContent = d.at;
            document.getElementById('disk').textContent = d.disk.used_pct;
            document.getElementById('memory').textContent = d.memory?.used_pct ?? 'No disponible';
            document.getElementById('cpu').textContent = d.cpu_pct ?? 'No disponible';
            document.getElementById('temp').textContent = d.cpu_temp_c ?? 'No disponible';
            document.getElementById('uptime').textContent = d.uptime_s ?? 'No disponible';
        }

        setInterval(actualizar, 5000); // cada 5 segundos
    </script>
</body>
</html>
