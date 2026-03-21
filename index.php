<?php
// ============================================================
// cron/weekly_report.php — Informe semanal por correo
// Cron: 0 11 * * 1  (lunes 6am Colombia = 11am UTC)
// ============================================================

require_once dirname(__DIR__) . '/config/config.php';

$tz      = new DateTimeZone('America/Bogota');
$today   = new DateTime('now', $tz);
$desde   = clone $today; $desde->modify('last monday')->setTime(0,0,0);
$hasta   = clone $desde; $hasta->modify('+6 days')->setTime(23,59,59);
$label   = $desde->format('d M') . ' - ' . $hasta->format('d M Y');
$appUrl  = 'https://talento.ital-lent.com/LLAMADAS';

$rf  = dirname(__DIR__) . '/logs/calls_report.json';
$all = file_exists($rf) ? json_decode(file_get_contents($rf), true) : array();
if (!$all) $all = array();

// Filtrar semana vencida
$calls = array();
foreach ($all as $c) {
    $dt = DateTime::createFromFormat('Y-m-d H:i:sP', $c['startdate']);
    if (!$dt) $dt = DateTime::createFromFormat('Y-m-d H:i:s', $c['startdate']);
    if (!$dt) { try { $dt = new DateTime($c['startdate']); } catch(Exception $e) { continue; } }
    $dt->setTimezone($tz);
    if ($dt >= $desde && $dt <= $hasta) $calls[] = $c;
}

function isExt($dstname) {
    return preg_match('/Ext[\s]*\d+/i', $dstname);
}
function getExtNum($dstname) {
    if (preg_match('/Ext[\s]*(\d+)/i', $dstname, $m)) return 'Ext ' . $m[1];
    return null;
}
function fmtD($s) {
    $s = intval($s); $m = floor($s/60); $sec = $s % 60;
    return $m > 0 ? $m.'m '.$sec.'s' : $sec.'s';
}
function calcMetrics($subset) {
    $r = array('total'=>0,'contest'=>0,'noresp'=>0,'busy'=>0,'dur'=>0,'ent'=>0,'sal'=>0);
    foreach ($subset as $c) {
        $r['total']++;
        if ($c['status']==='answer')    $r['contest']++;
        if ($c['status']==='no answer') $r['noresp']++;
        if ($c['status']==='busy')      $r['busy']++;
        $r['dur'] += intval($c['duration']);
        $isEnt = !preg_match('/Ext/i', $c['src']);
        if ($isEnt) $r['ent']++; else $r['sal']++;
    }
    $r['ef'] = $r['total']>0 ? round($r['contest']/$r['total']*100) : 0;
    return $r;
}
function buildAgentRows($byAgent) {
    $rows = '';
    foreach ($byAgent as $ag => $a) {
        $ef  = $a['total']>0 ? round($a['answer']/$a['total']*100) : 0;
        $col = $ef>=70?'#10b981':($ef>=40?'#f59e0b':'#ef4444');
        $rows .= "<tr style='border-bottom:1px solid #f8fafc;'>
          <td style='padding:8px 12px;font-size:12px;font-weight:600;'>".htmlspecialchars($ag)."</td>
          <td style='padding:8px 12px;font-size:12px;text-align:center;'>".$a['total']."</td>
          <td style='padding:8px 12px;font-size:12px;text-align:center;color:#10b981;font-weight:700;'>".$a['answer']."</td>
          <td style='padding:8px 12px;font-size:12px;text-align:center;color:#ef4444;'>".$a['noresp']."</td>
          <td style='padding:8px 12px;font-size:12px;text-align:center;'>".fmtD($a['dur'])."</td>
          <td style='padding:8px 12px;font-size:12px;text-align:center;font-weight:700;color:".$col.";'>".$ef."%</td>
        </tr>";
    }
    return $rows;
}
function buildEmail($label, $m, $agentRows, $titulo, $appUrl) {
    $ef = $m['ef'];
    $efCol = $ef>=70?'#10b981':($ef>=40?'#f59e0b':'#ef4444');
    return "<!DOCTYPE html><html><head><meta charset='UTF-8'></head>
<body style='margin:0;padding:0;background:#f1f5f9;font-family:Arial,sans-serif;'>
<div style='max-width:600px;margin:0 auto;padding:20px 12px;'>
  <div style='background:linear-gradient(135deg,#1a3154,#2d7ef0);border-radius:14px 14px 0 0;padding:24px;text-align:center;'>
    <img src='https://ital-lent.com/wp-content/uploads/2025/02/cropped-logo-3.png' height='28' style='filter:brightness(0) invert(1);opacity:.95;display:block;margin:0 auto 12px;' alt='ITAL LENT'>
    <div style='color:white;font-size:17px;font-weight:700;'>$titulo</div>
    <div style='color:rgba(255,255,255,.65);font-size:12px;margin-top:4px;'>$label</div>
  </div>
  <div style='height:3px;background:linear-gradient(90deg,#60a5fa,#3b82f6);margin-bottom:16px;'></div>

  <table width='100%' cellpadding='0' cellspacing='6' style='margin-bottom:16px;'>
    <tr>
      <td style='background:white;border-radius:10px;padding:14px;text-align:center;border:1px solid #e2e8f0;vertical-align:top;'>
        <div style='font-size:30px;font-weight:700;color:#1d5fbe;line-height:1;'>{$m['total']}</div>
        <div style='font-size:10px;color:#94a3b8;text-transform:uppercase;margin-top:4px;'>Total</div>
      </td>
      <td width='8'></td>
      <td style='background:white;border-radius:10px;padding:14px;text-align:center;border:1px solid #e2e8f0;vertical-align:top;'>
        <div style='font-size:30px;font-weight:700;color:#10b981;line-height:1;'>{$m['contest']}</div>
        <div style='font-size:10px;color:#94a3b8;text-transform:uppercase;margin-top:4px;'>Contestadas</div>
      </td>
      <td width='8'></td>
      <td style='background:white;border-radius:10px;padding:14px;text-align:center;border:1px solid #e2e8f0;vertical-align:top;'>
        <div style='font-size:30px;font-weight:700;color:$efCol;line-height:1;'>$ef%</div>
        <div style='font-size:10px;color:#94a3b8;text-transform:uppercase;margin-top:4px;'>Efectividad</div>
      </td>
    </tr>
  </table>

  <div style='background:white;border-radius:12px;padding:14px 16px;border:1px solid #e2e8f0;margin-bottom:14px;font-size:12px;'>
    <table width='100%' cellpadding='0' cellspacing='0'>
      <tr><td style='padding:4px 0;color:#64748b;'>📥 Entrantes</td><td style='padding:4px 0;font-weight:700;text-align:right;'>{$m['ent']}</td></tr>
      <tr><td style='padding:4px 0;color:#64748b;'>📤 Salientes</td><td style='padding:4px 0;font-weight:700;text-align:right;'>{$m['sal']}</td></tr>
      <tr><td style='padding:4px 0;color:#64748b;'>📵 Sin respuesta</td><td style='padding:4px 0;font-weight:700;color:#ef4444;text-align:right;'>{$m['noresp']}</td></tr>
      <tr><td style='padding:4px 0;color:#64748b;'>⏱ Duración total</td><td style='padding:4px 0;font-weight:700;text-align:right;'>".fmtD($m['dur'])."</td></tr>
    </table>
  </div>

  <div style='background:white;border-radius:12px;border:1px solid #e2e8f0;overflow:hidden;margin-bottom:16px;'>
    <div style='padding:12px 14px;background:#f8fafc;border-bottom:1px solid #f1f5f9;font-size:10px;font-weight:700;color:#94a3b8;text-transform:uppercase;letter-spacing:.4px;'>Por agente</div>
    <table width='100%' cellpadding='0' cellspacing='0'>
      <tr style='background:#f8fafc;'>
        <th style='padding:7px 12px;font-size:10px;color:#94a3b8;text-align:left;font-weight:700;text-transform:uppercase;'>Agente</th>
        <th style='padding:7px 12px;font-size:10px;color:#94a3b8;text-align:center;font-weight:700;text-transform:uppercase;'>Total</th>
        <th style='padding:7px 12px;font-size:10px;color:#94a3b8;text-align:center;font-weight:700;text-transform:uppercase;'>Contest.</th>
        <th style='padding:7px 12px;font-size:10px;color:#94a3b8;text-align:center;font-weight:700;text-transform:uppercase;'>Sin resp.</th>
        <th style='padding:7px 12px;font-size:10px;color:#94a3b8;text-align:center;font-weight:700;text-transform:uppercase;'>Durac.</th>
        <th style='padding:7px 12px;font-size:10px;color:#94a3b8;text-align:center;font-weight:700;text-transform:uppercase;'>Efect.</th>
      </tr>
      $agentRows
    </table>
  </div>

  <div style='text-align:center;margin-bottom:16px;'>
    <a href='$appUrl/informe.php' style='background:#2d7ef0;color:white;text-decoration:none;padding:12px 28px;border-radius:10px;font-size:13px;font-weight:700;display:inline-block;'>📊 Ver informe completo</a>
  </div>

  <div style='text-align:center;color:#94a3b8;font-size:10px;border-top:1px solid #e2e8f0;padding-top:12px;'>
    Central de Llamadas · ITAL LENT · talento.ital-lent.com<br>
    Informe automático · Lunes 6:00 AM Colombia
  </div>
</div></body></html>";
}

// ── EMAIL 1: DANIELG — todas las extensiones ─────────────
$byAgentAll = array();
foreach ($calls as $c) {
    if (!isExt($c['dstname'])) continue;
    $ag = getExtNum($c['dstname']); if (!$ag) continue;
    if (!isset($byAgentAll[$ag])) $byAgentAll[$ag] = array('total'=>0,'answer'=>0,'noresp'=>0,'dur'=>0);
    $byAgentAll[$ag]['total']++;
    if ($c['status']==='answer')    $byAgentAll[$ag]['answer']++;
    if ($c['status']==='no answer') $byAgentAll[$ag]['noresp']++;
    $byAgentAll[$ag]['dur'] += intval($c['duration']);
}
arsort($byAgentAll);

$mAll  = calcMetrics($calls);
$html1 = buildEmail($label, $mAll, buildAgentRows($byAgentAll), 'Informe Semanal · Todas las Extensiones', $appUrl);

// ── EMAIL 2: SAC — ext 100, 101, 102, 103 ────────────────
$sacExts  = array('Ext 100', 'Ext 101', 'Ext 102', 'Ext 103');
$sacNames = array('Ext 100'=>'JASMIN','Ext 101'=>'NINI','Ext 102'=>'LUZ','Ext 103'=>'POSVENTA');

$sacCalls = array();
foreach ($calls as $c) {
    $en = getExtNum($c['dstname']);
    if ($en && in_array($en, $sacExts)) $sacCalls[] = $c;
}

$byAgentSac = array();
foreach ($sacExts as $ext) $byAgentSac[$ext] = array('total'=>0,'answer'=>0,'noresp'=>0,'dur'=>0);
foreach ($sacCalls as $c) {
    $en = getExtNum($c['dstname']);
    if ($en && isset($byAgentSac[$en])) {
        $byAgentSac[$en]['total']++;
        if ($c['status']==='answer')    $byAgentSac[$en]['answer']++;
        if ($c['status']==='no answer') $byAgentSac[$en]['noresp']++;
        $byAgentSac[$en]['dur'] += intval($c['duration']);
    }
}

// Agregar nombres a la tabla SAC
$sacRowsData = array();
foreach ($byAgentSac as $ext => $a) {
    $name = isset($sacNames[$ext]) ? $ext . ' · ' . $sacNames[$ext] : $ext;
    $sacRowsData[$name] = $a;
}

$mSac  = calcMetrics($sacCalls);
$html2 = buildEmail($label, $mSac, buildAgentRows($sacRowsData), 'Informe Semanal · SAC &amp; Pedidos', $appUrl);

// ── Enviar emails ─────────────────────────────────────────
$headers = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: Central Llamadas <no-reply@ital-lent.com>\r\n";

// Email 1 — solo a DANIELG (todas las extensiones)
$sent1 = mail('d.guevara@ital-lent.com',
    'Informe semanal llamadas ITAL LENT - ' . $label,
    $html1, $headers) ? 1 : 0;

// Email 2 — a DANIELG y LIDER SAC (solo SAC 100-103)
$sent2 = 0;
foreach (array('d.guevara@ital-lent.com','c.restrepo@ital-lent.com') as $email) {
    if (mail($email, 'Informe SAC · Pedidos - ' . $label, $html2, $headers)) $sent2++;
}

// Log
$log = dirname(__DIR__) . '/logs/email_report.log';
file_put_contents($log,
    date('Y-m-d H:i:s') . " General enviado: $sent1/1 | SAC enviado: $sent2/2 | " . count($calls) . " llamadas | $label\n",
    FILE_APPEND
);

echo "General: $sent1/1 | SAC: $sent2/2\n";
