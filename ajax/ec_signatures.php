<?php
/** Email Campaign Manager — signatures (reusable HTML/plain sign-offs inserted into templates). */
require_once '../config.php';
requireLogin();
require_once '../lib/ec_core.php';
header('Content-Type: application/json');
ec_require_manage();

$action = $_POST['action'] ?? $_GET['action'] ?? '';
$c = ec_db();
ec_ensure_schema($c); // make sure ten_ec_signatures exists
$resp = ['success'=>false,'message'=>''];

try {
    switch ($action) {
        case 'list': {
            // Return everything the editor's "insert signature" dropdown needs (both bodies + flag).
            $rows=[];
            $res=$c->query("SELECT id,name,is_plain,html_body,text_body,updated_at FROM ten_ec_signatures ORDER BY name ASC, id DESC");
            while($res && $x=$res->fetch_assoc()) $rows[]=$x;
            $resp=['success'=>true,'rows'=>$rows];
            break;
        }
        case 'get': {
            $id=(int)($_POST['id']??0);
            $row=$c->query("SELECT * FROM ten_ec_signatures WHERE id=$id")->fetch_assoc();
            $resp=['success'=>(bool)$row,'signature'=>$row];
            break;
        }
        case 'save': {
            $id=(int)($_POST['id']??0);
            $name=trim($_POST['name']??'');
            $isPlain=(int)(!empty($_POST['is_plain']));
            $html=$_POST['html_body']??''; $text=trim($_POST['text_body']??'');
            if($name==='') throw new Exception('Name is required');
            if($isPlain){
                if($text==='') throw new Exception('Enter the plain-text signature');
                $html='';
            } else {
                if(trim(strip_tags($html))==='') throw new Exception('Enter the signature HTML');
                // Keep a plain-text version so plain-text templates can use this signature.
                if($text==='') $text = trim(preg_replace('/\s+/',' ', strip_tags($html)));
            }
            if($id>0){
                $st=$c->prepare("UPDATE ten_ec_signatures SET name=?,is_plain=?,html_body=?,text_body=? WHERE id=?");
                $st->bind_param('sissi',$name,$isPlain,$html,$text,$id); $st->execute(); $st->close();
            } else {
                $uid=(int)($_SESSION['ten_user_id']??0);
                $st=$c->prepare("INSERT INTO ten_ec_signatures (name,is_plain,html_body,text_body,created_by) VALUES (?,?,?,?,?)");
                $st->bind_param('sissi',$name,$isPlain,$html,$text,$uid); $st->execute(); $id=(int)$c->insert_id; $st->close();
            }
            $resp=['success'=>true,'id'=>$id];
            break;
        }
        case 'delete': {
            $id=(int)($_POST['id']??0);
            $c->query("DELETE FROM ten_ec_signatures WHERE id=$id");
            $resp=['success'=>true];
            break;
        }
        default: throw new Exception('Unknown action: '.$action);
    }
} catch (Throwable $e) { $resp=['success'=>false,'message'=>$e->getMessage()]; }
echo json_encode($resp);
