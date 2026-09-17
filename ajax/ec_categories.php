<?php
/** Email Campaign Manager — managed category vocabulary (create/rename/delete). */
require_once '../config.php';
requireLogin();
require_once '../lib/ec_core.php';
header('Content-Type: application/json');
ec_require_manage();

$c = ec_db();
ec_ensure_schema($c);
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$resp = ['success'=>false,'message'=>''];

try {
    switch ($action) {
        case 'list': {
            $rows=[];
            $res=$c->query("SELECT cat.id, cat.name,
                (SELECT COUNT(*) FROM ten_ec_contacts ct WHERE ct.category=cat.name) AS contacts
                FROM ten_ec_categories cat ORDER BY cat.name");
            while($res && $x=$res->fetch_assoc()) $rows[]=$x;
            $resp=['success'=>true,'rows'=>$rows];
            break;
        }
        case 'create': {
            $name=trim($_POST['name']??''); if($name==='') throw new Exception('Name required');
            $st=$c->prepare("INSERT IGNORE INTO ten_ec_categories (name) VALUES (?)");
            $st->bind_param('s',$name); $st->execute(); $st->close();
            $resp=['success'=>true];
            break;
        }
        case 'rename': {
            $id=(int)($_POST['id']??0); $name=trim($_POST['name']??'');
            if($id<=0 || $name==='') throw new Exception('id and name required');
            $old=$c->query("SELECT name FROM ten_ec_categories WHERE id=$id")->fetch_assoc();
            if(!$old) throw new Exception('Category not found');
            $clash=$c->query("SELECT id FROM ten_ec_categories WHERE name='".$c->real_escape_string($name)."' AND id<>$id LIMIT 1");
            if($clash && $clash->num_rows) throw new Exception('A category with that name already exists');
            $st=$c->prepare("UPDATE ten_ec_categories SET name=? WHERE id=?");
            $st->bind_param('si',$name,$id); $st->execute(); $st->close();
            // Cascade the rename to contacts carrying the old label.
            $u=$c->prepare("UPDATE ten_ec_contacts SET category=? WHERE category=?");
            $u->bind_param('ss',$name,$old['name']); $u->execute(); $u->close();
            $resp=['success'=>true];
            break;
        }
        case 'delete': {
            $id=(int)($_POST['id']??0); if($id<=0) throw new Exception('id required');
            // Removes it from the pick-list only; contacts keep whatever value they had.
            $c->query("DELETE FROM ten_ec_categories WHERE id=$id");
            $resp=['success'=>true];
            break;
        }
        default: throw new Exception('Unknown action: '.$action);
    }
} catch (Throwable $e) { $resp=['success'=>false,'message'=>$e->getMessage()]; }
echo json_encode($resp);
