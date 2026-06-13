<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

$host   = getenv('MYSQLHOST')     ?: 'localhost';
$dbname = getenv('MYSQLDATABASE') ?: 'songho_db';
$user   = getenv('MYSQLUSER')     ?: 'root';
$pass   = getenv('MYSQLPASSWORD') ?: '';
$port   = getenv('MYSQLPORT')     ?: '3306';

function getDB() {
    global $host, $dbname, $user, $pass;
    try {
        $pdo = new PDO("mysql:host=$host;port=$port;dbname=$dbname;charset=utf8", $user, $pass);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch (PDOException $e) {
        die(json_encode(['error' => 'DB: ' . $e->getMessage()]));
    }
}

function initDB() {
    $pdo = getDB();
    $pdo->exec("CREATE TABLE IF NOT EXISTS parties (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        code         VARCHAR(10) UNIQUE NOT NULL,
        etat         ENUM('attente','en_cours','termine') DEFAULT 'attente',
        tour         ENUM('Nord','Sud') DEFAULT 'Sud',
        cases_nord   TEXT NOT NULL,
        cases_sud    TEXT NOT NULL,
        score_nord   INT DEFAULT 0,
        score_sud    INT DEFAULT 0,
        dernier_coup TEXT DEFAULT '',
        historique   TEXT DEFAULT '[]',
        created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8;");
}
initDB();

function genCode() {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $code  = '';
    for ($i = 0; $i < 8; $i++) $code .= $chars[random_int(0, strlen($chars)-1)];
    return $code;
}

function semer($nord, $sud, $camp, $case_idx) {
    $graines = ($camp === 'Sud') ? $sud[$case_idx] : $nord[$case_idx];
    if ($graines === 0) return null;

    if ($camp === 'Sud') $sud[$case_idx]  = 0;
    else                 $nord[$case_idx] = 0;

    // Position de départ dans le tour circulaire 0-13
    // 0=S7,1=S6,2=S5,3=S4,4=S3,5=S2,6=S1,7=N1,8=N2,9=N3,10=N4,11=N5,12=N6,13=N7
    $start   = ($camp === 'Sud') ? (6 - $case_idx) : (7 + $case_idx);
    $grenier = $graines > 13;
    $pos     = $start;
    $complet = false;
    $reste   = $graines;

    while ($reste > 0) {
        $pos = ($pos + 1) % 14;

        if ($grenier && !$complet && $pos === $start) {
            $complet = true; continue;
        }
        if ($grenier && $complet) {
            if ($camp === 'Sud'  && $pos < 7)  continue;
            if ($camp === 'Nord' && $pos >= 7) continue;
        }

        if ($pos < 7) { $sud[6 - $pos]++;       }
        else          { $nord[$pos - 7]++;       }
        $reste--;
    }

    $fc   = ($pos < 7) ? 'Sud' : 'Nord';
    $fi   = ($pos < 7) ? (6 - $pos) : ($pos - 7);
    $cg   = false;
    if ($grenier) {
        $pp = ($camp === 'Sud') ? 7 : 6;
        if ($pos === $pp) $cg = true;
    }

    return ['nord' => $nord, 'sud' => $sud,
            'finale_camp' => $fc, 'finale_idx' => $fi,
            'grenier' => $grenier, 'capture_grenier' => $cg];
}

function effectuerPrises($nord, $sud, $camp, $fc, $fi) {
    $adv = ($camp === 'Sud') ? 'Nord' : 'Sud';
    if ($fc !== $adv) return ['nord' => $nord, 'sud' => $sud, 'captures' => 0];

    $val = ($fc === 'Nord') ? $nord[$fi] : $sud[$fi];
    if ($val < 2 || $val > 4) return ['nord' => $nord, 'sud' => $sud, 'captures' => 0];

    $ns = $nord; $ss = $sud; $cap = 0; $i = $fi;
    while (true) {
        $v = ($adv === 'Nord') ? $ns[$i] : $ss[$i];
        if ($v >= 2 && $v <= 4) {
            $cap += $v;
            if ($adv === 'Nord') $ns[$i] = 0; else $ss[$i] = 0;
        } else break;
        if ($i === 0) break;
        $i--;
    }
    if ($cap === 0) return ['nord' => $nord, 'sud' => $sud, 'captures' => 0];

    // Interdiction d'affamer
    if (array_sum($adv === 'Nord' ? $ns : $ss) === 0)
        return ['nord' => $nord, 'sud' => $sud, 'captures' => 0];

    return ['nord' => $ns, 'sud' => $ss, 'captures' => $cap];
}

function verifierFin($nord, $sud, $sn, $ss, $tour) {
    if ($sn >= 40) return ['fin' => true, 'gagnant' => 'Nord'];
    if ($ss >= 40) return ['fin' => true, 'gagnant' => 'Sud'];

    $total = array_sum($nord) + array_sum($sud);
    if ($total < 10) {
        $sn += array_sum($nord); $ss += array_sum($sud);
        return ['fin' => true, 'gagnant' => $sn > $ss ? 'Nord' : ($ss > $sn ? 'Sud' : 'Egalite'),
                'ramassage' => true, 'sn' => $sn, 'ss' => $ss];
    }

    $cases = ($tour === 'Sud') ? $sud : $nord;
    foreach ($cases as $v) if ($v > 0) return ['fin' => false];

    $sn += array_sum($nord); $ss += array_sum($sud);
    return ['fin' => true, 'gagnant' => $sn > $ss ? 'Nord' : ($ss > $sn ? 'Sud' : 'Egalite')];
}

// ── Routing ──────────────────────────────────────────────────
$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {

case 'creer':
    $pdo = getDB();
    $c   = json_encode([5,5,5,5,5,5,5]);
    do { $code = genCode(); } while ($pdo->query("SELECT id FROM parties WHERE code='$code'")->fetch());
    $pdo->prepare("INSERT INTO parties (code,cases_nord,cases_sud) VALUES (?,?,?)")->execute([$code,$c,$c]);
    echo json_encode(['success'=>true,'code'=>$code,'camp'=>'Sud']);
    break;

case 'rejoindre':
    $code   = strtoupper(trim($_POST['code'] ?? ''));
    $pdo    = getDB();
    $partie = $pdo->query("SELECT * FROM parties WHERE code='$code'")->fetch(PDO::FETCH_ASSOC);
    if (!$partie)                       { echo json_encode(['error'=>'Partie introuvable']); break; }
    if ($partie['etat']==='en_cours')   { echo json_encode(['error'=>'Partie déjà en cours']); break; }
    if ($partie['etat']==='termine')    { echo json_encode(['error'=>'Partie terminée']); break; }
    $pdo->exec("UPDATE parties SET etat='en_cours' WHERE code='$code'");
    echo json_encode(['success'=>true,'camp'=>'Nord','code'=>$code,'etat'=>'en_cours']);
    break;

case 'etat':
    $code   = strtoupper(trim($_GET['code'] ?? $_POST['code'] ?? ''));
    $pdo    = getDB();
    $partie = $pdo->query("SELECT * FROM parties WHERE code='$code'")->fetch(PDO::FETCH_ASSOC);
    if (!$partie) { echo json_encode(['error'=>'Partie introuvable']); break; }
    echo json_encode([
        'success'      => true,
        'etat'         => $partie['etat'],
        'tour'         => $partie['tour'],
        'cases_nord'   => json_decode($partie['cases_nord']),
        'cases_sud'    => json_decode($partie['cases_sud']),
        'score_nord'   => (int)$partie['score_nord'],
        'score_sud'    => (int)$partie['score_sud'],
        'dernier_coup' => $partie['dernier_coup'],
        'historique'   => json_decode($partie['historique']),
    ]);
    break;

case 'jouer':
    $code     = strtoupper(trim($_POST['code'] ?? ''));
    $camp     = $_POST['camp'] ?? '';
    $case_idx = (int)($_POST['case_idx'] ?? -1);
    $pdo      = getDB();
    $partie   = $pdo->query("SELECT * FROM parties WHERE code='$code'")->fetch(PDO::FETCH_ASSOC);
    if (!$partie)                       { echo json_encode(['error'=>'Partie introuvable']); break; }
    if ($partie['etat']!=='en_cours')   { echo json_encode(['error'=>'Partie non active']); break; }
    if ($partie['tour']!==$camp)        { echo json_encode(['error'=>'Ce n\'est pas votre tour']); break; }

    $nord = json_decode($partie['cases_nord'], true);
    $sud  = json_decode($partie['cases_sud'],  true);

    $graine = ($camp==='Sud') ? $sud[$case_idx] : $nord[$case_idx];
    if ($graine===0) { echo json_encode(['error'=>'Case vide']); break; }

    $sem = semer($nord, $sud, $camp, $case_idx);
    if (!$sem) { echo json_encode(['error'=>'Coup invalide']); break; }

    $nn = $sem['nord']; $sn_arr = $sem['sud']; $cap = 0;

    if ($sem['capture_grenier']) {
        $adv = ($camp==='Sud') ? 'Nord' : 'Sud';
        if ($adv==='Nord') { $cap=$nn[0]; $nn[0]=0; }
        else               { $cap=$sn_arr[0]; $sn_arr[0]=0; }
    } else {
        $pr = effectuerPrises($nn, $sn_arr, $camp, $sem['finale_camp'], $sem['finale_idx']);
        $nn=$pr['nord']; $sn_arr=$pr['sud']; $cap=$pr['captures'];
    }

    $score_nord=(int)$partie['score_nord']; $score_sud=(int)$partie['score_sud'];
    if ($camp==='Sud') $score_sud+=$cap; else $score_nord+=$cap;

    $hist    = json_decode($partie['historique'],true) ?: [];
    $num     = count($hist)+1;
    $cn      = ($camp==='Sud') ? ('S'.(7-$case_idx)) : ('N'.($case_idx+1));
    $fc_nom  = $sem['finale_camp']==='Nord' ? 'N'.($sem['finale_idx']+1) : 'S'.(7-$sem['finale_idx']);
    $last    = "$camp joue $cn, derniere graine en $fc_nom, $cap graine(s) capturee(s). Score Sud: $score_sud / Nord: $score_nord.";
    array_unshift($hist, "Tour $num : $camp joue $cn, capture $cap.");
    if (count($hist)>50) array_pop($hist);

    $next = ($camp==='Sud')?'Nord':'Sud';
    $fin  = verifierFin($nn, $sn_arr, $score_nord, $score_sud, $next);

    if ($fin['fin']) {
        if (!empty($fin['ramassage'])) {
            $score_nord=$fin['sn']; $score_sud=$fin['ss'];
            $nn=[0,0,0,0,0,0,0]; $sn_arr=[0,0,0,0,0,0,0];
        }
        $pdo->prepare("UPDATE parties SET etat='termine',cases_nord=?,cases_sud=?,score_nord=?,score_sud=?,dernier_coup=?,historique=? WHERE code=?")
            ->execute([json_encode($nn),json_encode($sn_arr),$score_nord,$score_sud,$last,json_encode($hist),$code]);
        echo json_encode(['success'=>true,'fin'=>true,'gagnant'=>$fin['gagnant'],
            'cases_nord'=>$nn,'cases_sud'=>$sn_arr,
            'score_nord'=>$score_nord,'score_sud'=>$score_sud,
            'dernier_coup'=>$last,'historique'=>$hist]);
        break;
    }

    $pdo->prepare("UPDATE parties SET tour=?,cases_nord=?,cases_sud=?,score_nord=?,score_sud=?,dernier_coup=?,historique=? WHERE code=?")
        ->execute([$next,json_encode($nn),json_encode($sn_arr),$score_nord,$score_sud,$last,json_encode($hist),$code]);
    echo json_encode(['success'=>true,'fin'=>false,'tour'=>$next,
        'cases_nord'=>$nn,'cases_sud'=>$sn_arr,
        'score_nord'=>$score_nord,'score_sud'=>$score_sud,
        'captures'=>$cap,'dernier_coup'=>$last,'historique'=>$hist]);
    break;

default:
    echo json_encode(['error'=>'Action inconnue']);
}
?>
