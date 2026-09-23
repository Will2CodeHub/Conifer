<?php
/** Email Campaign Manager — managed pick-lists (type / industry / country / category).
 *  One table (ten_ec_vocab) keyed by `kind`; rename cascades to the matching
 *  ten_ec_contacts column; delete removes from the list only. */
require_once '../config.php';
requireLogin();
require_once '../lib/ec_core.php';
header('Content-Type: application/json');
ec_require_manage();

$c = ec_db();
ec_ensure_schema($c);
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$resp = ['success'=>false,'message'=>''];

/** kind => the ten_ec_contacts column it manages. */
function ec_vocab_col(string $kind): string {
    $map = ['type'=>'contact_type', 'industry'=>'industry', 'country'=>'country', 'category'=>'category'];
    if (!isset($map[$kind])) throw new Exception('Unknown list: '.$kind);
    return $map[$kind];
}

try {
    $kind = preg_replace('/[^a-z]/','', strtolower($_POST['kind'] ?? $_GET['kind'] ?? ''));
    $col  = ec_vocab_col($kind);
    switch ($action) {
        case 'list': {
            // Each option + how many contacts currently use it.
            $rows=[]; $ke=$c->real_escape_string($kind);
            $res=$c->query("SELECT v.id, v.name,
                (SELECT COUNT(*) FROM ten_ec_contacts ct WHERE ct.$col=v.name) AS contacts
                FROM ten_ec_vocab v WHERE v.kind='$ke' ORDER BY v.name");
            while($res && $x=$res->fetch_assoc()) $rows[]=$x;
            $resp=['success'=>true,'rows'=>$rows];
            break;
        }
        case 'create': {
            $name=trim($_POST['name']??''); if($name==='') throw new Exception('Name required');
            $st=$c->prepare("INSERT IGNORE INTO ten_ec_vocab (kind,name) VALUES (?,?)");
            $st->bind_param('ss',$kind,$name); $st->execute(); $st->close();
            $resp=['success'=>true];
            break;
        }
        case 'rename': {
            $id=(int)($_POST['id']??0); $name=trim($_POST['name']??'');
            if($id<=0 || $name==='') throw new Exception('id and name required');
            $old=$c->query("SELECT name FROM ten_ec_vocab WHERE id=$id AND kind='".$c->real_escape_string($kind)."'")->fetch_assoc();
            if(!$old) throw new Exception('Item not found');
            $clash=$c->query("SELECT id FROM ten_ec_vocab WHERE kind='".$c->real_escape_string($kind)."' AND name='".$c->real_escape_string($name)."' AND id<>$id LIMIT 1");
            if($clash && $clash->num_rows) throw new Exception('That name already exists in this list');
            $st=$c->prepare("UPDATE ten_ec_vocab SET name=? WHERE id=?");
            $st->bind_param('si',$name,$id); $st->execute(); $st->close();
            // Cascade the rename to every contact carrying the old value.
            $u=$c->prepare("UPDATE ten_ec_contacts SET $col=? WHERE $col=?");
            $u->bind_param('ss',$name,$old['name']); $u->execute(); $u->close();
            $resp=['success'=>true];
            break;
        }
        case 'delete': {
            $id=(int)($_POST['id']??0); if($id<=0) throw new Exception('id required');
            // Removes it from the pick-list only; contacts keep whatever value they had.
            $c->query("DELETE FROM ten_ec_vocab WHERE id=$id AND kind='".$c->real_escape_string($kind)."'");
            $resp=['success'=>true];
            break;
        }
        default: throw new Exception('Unknown action: '.$action);
    }
} catch (Throwable $e) { $resp=['success'=>false,'message'=>$e->getMessage()]; }
echo json_encode($resp);
