<?php
require 'config.php';

$procedureId = isset($_GET['procedure_id']) ? (int)$_GET['procedure_id'] : 0;
if ($procedureId <= 0) { http_response_code(400); exit('Нужно передать ?procedure_id='); }
$decimals = isset($_GET['dec']) ? max(0, (int)$_GET['dec']) : 2;

$st = $pdo->prepare("SELECT id, title, start_date FROM evaluation_procedures WHERE id=?");
$st->execute([$procedureId]);
$procedure = $st->fetch();
if (!$procedure) exit('Процедура не найдена');
$year = $procedure['start_date'] ? (new DateTime($procedure['start_date']))->format('Y') : date('Y');

$st = $pdo->prepare("
  SELECT c.id, c.name
  FROM procedure_combinations pc
  JOIN combinations c ON c.id = pc.combination_id
  WHERE pc.procedure_id = ?
  ORDER BY c.name
");
$st->execute([$procedureId]);
$competencies = $st->fetchAll(PDO::FETCH_ASSOC);
if (!$competencies) exit('К процедуре не привязаны компетенции.');
$combIds=[];$combNames=[];
foreach ($competencies as $c){$combIds[]=(int)$c['id'];$combNames[(int)$c['id']]=$c['name']??'';}

$st = $pdo->prepare("
  SELECT et.id AS target_id, u.fio
  FROM evaluation_targets et
  JOIN users u ON u.id = et.user_id
  WHERE et.procedure_id = ?
  ORDER BY u.fio
");
$st->execute([$procedureId]);
$targets = $st->fetchAll(PDO::FETCH_ASSOC);
if (!$targets) exit('В процедуре нет участников.');

$normalize=function(?string $s):string{if($s===null)return'';$s=mb_strtolower($s,'UTF-8');$s=preg_replace('/^[0-9.\-\s]+/u','',$s);$s=preg_replace('/\s+/u',' ',trim($s));return $s;};

$map=[];$hasLink=[];
foreach($combIds as $cid){$map[$cid]=[];$hasLink[$cid]=false;}
if($combIds){
  $in=implode(',',array_fill(0,count($combIds),'?'));
  $q=$pdo->prepare("SELECT combination_id, question_id FROM combination_questions WHERE combination_id IN ($in)");
  $q->execute($combIds);
  foreach($q as $row){$map[(int)$row['combination_id']][]=(int)$row['question_id'];$hasLink[(int)$row['combination_id']]=true;}
}
$needFallback=array_values(array_filter($combIds,function($cid)use($hasLink){return !$hasLink[$cid];}));
if($needFallback){
  $allQ=$pdo->query("SELECT id, category FROM questions")->fetchAll(PDO::FETCH_ASSOC);
  $groupCat=[];
  foreach($allQ as $row){$k=$normalize($row['category']);$groupCat[$k][]= (int)$row['id'];}
  $synRaw=[
    'ответственность'=>'ответственность за результат',
    'профессиональные знания замещаемой должности'=>'профессиональные знания ключевой должности',
    'профессиональные знания должностей, смежных к текущей'=>'профессиональные знания должностей, смежных к текущей должности',
  ];
  $syn=[];foreach($synRaw as $k=>$v){$syn[$normalize($k)]=$normalize($v);}
  foreach($needFallback as $cid){
    $combNorm=$normalize($combNames[$cid]);
    $qids=$groupCat[$combNorm]??null;
    if(!$qids && isset($syn[$combNorm])){$alias=$syn[$combNorm];$qids=$groupCat[$alias]??null;}
    if(!$qids){
      $bestKey=null;$bestScore=-1;
      foreach($groupCat as $k=>$ids){
        $score=-1;
        if(mb_strpos($k,$combNorm)!==false || mb_strpos($combNorm,$k)!==false){$score=max(mb_strlen($k,'UTF-8'),mb_strlen($combNorm,'UTF-8'));}
        if($score>$bestScore){$bestScore=$score;$bestKey=$k;}
      }
      if($bestKey!==null && $bestScore>=0)$qids=$groupCat[$bestKey];
    }
    $map[$cid]=$qids?:[];
  }
}

$roles=['manager','colleague','subordinate'];
$roleLabels=['manager'=>'Руководитель','colleague'=>'Коллеги','subordinate'=>'Подчинённые'];
$roleWeights=['manager'=>0.334,'colleague'=>0.333,'subordinate'=>0.333];

$results=[];$details=[];$counts=[];$qCount=[];
foreach($targets as $t){
  $fio=$t['fio'];$tid=(int)$t['target_id'];
  $results[$fio]=[];$details[$fio]=[];$counts[$fio]=[];
  foreach($combIds as $cid){
    $qids=$map[$cid]??[];
    if(!$qids){$results[$fio][$cid]=['M'=>null,'C'=>null,'S'=>null,'W'=>null];$details[$fio][$cid]=[];$counts[$fio][$cid]=['M'=>0,'C'=>0,'S'=>0];$qCount[$cid]=0;continue;}
    $ph=implode(',',array_fill(0,count($qids),'?'));
    $sqlDet="
      SELECT ep.evaluator_id, u.fio AS evaluator_fio, ep.role,
             AVG(a.score) AS eval_avg
      FROM answers a
      JOIN evaluation_participants ep ON ep.id = a.participant_id
      JOIN users u ON u.id = ep.evaluator_id
      WHERE ep.target_id = ?
        AND ep.role IN ('manager','colleague','subordinate')
        AND a.score >= 0
        AND a.question_id IN ($ph)
      GROUP BY ep.evaluator_id, u.fio, ep.role
      ORDER BY ep.role, u.fio
    ";
    $stmt=$pdo->prepare($sqlDet);
    $stmt->execute(array_merge([$tid],$qids));
    $perRoleEvals=['manager'=>[],'colleague'=>[],'subordinate'=>[]];
    $rows=[];
    foreach($stmt as $r){
      $role=$r['role'];$avg=($r['eval_avg']!==null)?(float)$r['eval_avg']:null;
      $rows[]=['fio'=>$r['evaluator_fio'],'role'=>$role,'avg'=>$avg];
      if($avg!==null){$perRoleEvals[$role][]=$avg;}
    }
    $details[$fio][$cid]=$rows;
    $avgByRole=['manager'=>null,'colleague'=>null,'subordinate'=>null];
    $cntByRole=['manager'=>0,'colleague'=>0,'subordinate'=>0];
    foreach($perRoleEvals as $role=>$arr){
      if(!empty($arr)){$avgByRole[$role]=array_sum($arr)/count($arr);$cntByRole[$role]=count($arr);}
    }
    $sumW=0;$sumV=0;
    foreach($roles as $rl){if($avgByRole[$rl]!==null){$sumW+=$roleWeights[$rl];$sumV+=$roleWeights[$rl]*$avgByRole[$rl];}}
    $weighted=($sumW>0)?($sumV/$sumW):null;
    $results[$fio][$cid]=['M'=>$avgByRole['manager'],'C'=>$avgByRole['colleague'],'S'=>$avgByRole['subordinate'],'W'=>$weighted];
    $counts[$fio][$cid]=['M'=>$cntByRole['manager'],'C'=>$cntByRole['colleague'],'S'=>$cntByRole['subordinate']];
    $qCount[$cid]=count($qids);
  }
}

$fmt=($decimals>0)?('0.'.str_repeat('0',$decimals)):'0';
$css="
.num{mso-number-format:'{$fmt}';text-align:center}
.txt{text-align:center}
th{font-weight:bold;text-align:center}
td{vertical-align:top}
.small{font-size:11px}
.det th,.det td{border:1px solid #999;padding:3px}
";

$fname='competency_breakdown_detailed_'.$procedureId.'.xls';
header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="'.$fname.'"');
echo "\xEF\xBB\xBF";
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title><?= htmlspecialchars($procedure['title']) ?></title>
<style><?= $css ?></style>
</head>
<body>

<table border="1" cellspacing="0" cellpadding="4">
  <tr>
    <th colspan="<?= 1 + count($competencies)*4 ?>">Детализация оценки по компетенциям (M/C/S + взвешенный итог) с ФИО оценщиков</th>
  </tr>
  <tr>
    <th colspan="<?= 1 + count($competencies)*4 ?>"><?= htmlspecialchars($procedure['title']) ?> — <?= $year ?> г.</th>
  </tr>

  <tr>
    <th rowspan="2">Сотрудник</th>
    <?php foreach ($competencies as $c): ?>
      <th colspan="4"><?= htmlspecialchars($c['name']) ?></th>
    <?php endforeach; ?>
  </tr>
  <tr>
    <?php foreach ($competencies as $c): ?>
      <th>M</th><th>C</th><th>S</th><th>Итог</th>
    <?php endforeach; ?>
  </tr>

  <?php foreach ($results as $fio => $byCid): ?>
    <tr>
      <td class="txt"><strong><?= htmlspecialchars($fio) ?></strong></td>
      <?php foreach ($competencies as $c):
            $cid=(int)$c['id'];
            $row=$byCid[$cid]??['M'=>null,'C'=>null,'S'=>null,'W'=>null];
            $M=$row['M'];$C=$row['C'];$S=$row['S'];$W=$row['W']; ?>
        <?= ($M===null)?'<td class="txt">-</td>':'<td class="num">'.number_format($M,$decimals,'.','').'</td>' ?>
        <?= ($C===null)?'<td class="txt">-</td>':'<td class="num">'.number_format($C,$decimals,'.','').'</td>' ?>
        <?= ($S===null)?'<td class="txt">-</td>':'<td class="num">'.number_format($S,$decimals,'.','').'</td>' ?>
        <?= ($W===null)?'<td class="txt">-</td>':'<td class="num">'.number_format($W,$decimals,'.','').'</td>' ?>
      <?php endforeach; ?>
    </tr>

    <tr>
      <td class="small" style="background:#f8f9fa;">Детализация оценщиков</td>
      <?php foreach ($competencies as $c):
            $cid=(int)$c['id'];
            $rows=$details[$fio][$cid]??[];
            $cnts=$counts[$fio][$cid]??['M'=>0,'C'=>0,'S'=>0];
            ob_start(); ?>
            <table class="det" cellspacing="0" cellpadding="2" style="width:100%;border-collapse:collapse;">
              <tr>
                <th>ФИО</th>
                <th>Роль</th>
                <th>Средний балл</th>
              </tr>
              <?php if(!$rows): ?>
                <tr><td colspan="3" class="txt">Нет данных</td></tr>
              <?php else: ?>
                <?php foreach($rows as $rr): ?>
                  <tr>
                    <td><?= htmlspecialchars($rr['fio']) ?></td>
                    <td class="txt"><?= htmlspecialchars($roleLabels[$rr['role']] ?? $rr['role']) ?></td>
                    <td class="num"><?= ($rr['avg']===null?'':number_format($rr['avg'],$decimals,'.','')) ?></td>
                  </tr>
                <?php endforeach; ?>
                <tr>
                  <td colspan="3" class="small">Учитывалось вопросов: <?= (int)($qCount[$cid]??0) ?>; оценщиков — M: <?= (int)$cnts['M'] ?>, C: <?= (int)$cnts['C'] ?>, S: <?= (int)$cnts['S'] ?></td>
                </tr>
              <?php endif; ?>
            </table>
            <?php $mini=ob_get_clean(); echo '<td colspan="4">'.$mini.'</td>'; ?>
      <?php endforeach; ?>
    </tr>
  <?php endforeach; ?>
</table>

</body>
</html>