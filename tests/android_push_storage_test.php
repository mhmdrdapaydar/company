<?php
declare(strict_types=1);
define('DB_DRIVER','sqlite'); define('SQLITE_DB_PATH',':memory:');
$_ENV['ANDROID_PUSH_ENABLED']='true'; $_ENV['ANDROID_PUSH_WORKER_TOKEN']=str_repeat('w', 40);
require __DIR__ . '/../includes/bootstrap.php';
function acheck(bool $ok, string $m): void { if (!$ok) throw new RuntimeException($m); }
$users=Database::fetchAll('SELECT id FROM users ORDER BY id LIMIT 3'); [$sender,$receiver,$other]=array_map(fn($u)=>(int)$u['id'],$users);
function device(int $user,string $fcm): array { $v=MobileAuth::issue($user); $_SERVER['HTTP_AUTHORIZATION']='Bearer '.$v; $a=MobileAuth::authenticate(); AndroidPush::register($a['id'],$user,$fcm); return $a; }
$one=device($receiver,'fcm-token-device-one-abcdefghijkl');$two=device($receiver,'fcm-token-device-two-abcdefghijkl');$outsider=device($other,'fcm-token-device-other-abcdefghijkl');
$id=create_notification($receiver,$sender,'task_assigned','عنوان','متن','task',55,true);acheck($id>0,'notification created');
$stats=AndroidPush::status($sender,$id,false);acheck($stats['queued']===2,'queue per receiving device');
try { AndroidPush::receipt($outsider['id'],$other,$id); throw new RuntimeException('outsider receipt accepted'); } catch (RuntimeException $e) { acheck($e->getCode()===404,'receipt owner check'); }
$job=AndroidPush::claim();acheck($job && $job['notification_id']===$id,'claimed job');
try {AndroidPush::workerAck($job['id'],str_repeat('0',48),$job['fcm_hash'],'sent');throw new RuntimeException('invalid lease accepted');}catch(RuntimeException $e){acheck($e->getCode()===409,'lease');}
AndroidPush::workerAck($job['id'],$job['lease'],$job['fcm_hash'],'sent');$stats=AndroidPush::status($sender,$id,false);acheck($stats['sent']===1&&$stats['received']===0,'sent is not received');
$did=(int) Database::fetchValue('SELECT token_id FROM android_push_devices WHERE fcm_hash=?',[$job['fcm_hash']]);
acheck(AndroidPush::receipt($did,$receiver,$id)===false,'first receipt');acheck(AndroidPush::receipt($did,$receiver,$id)===true,'idempotent receipt');
acheck(AndroidPush::status($sender,$id,false)['received']===1,'receipt counted');
$job2=AndroidPush::claim();$did2=(int) Database::fetchValue('SELECT token_id FROM android_push_devices WHERE fcm_hash=?',[$job2['fcm_hash']]);
AndroidPush::receipt($did2,$receiver,$id);AndroidPush::workerAck($job2['id'],$job2['lease'],$job2['fcm_hash'],'sent');acheck(AndroidPush::status($sender,$id,false)['received']===2,'receipt racing worker ack');
acheck((int)Database::fetchValue('SELECT is_read FROM notifications WHERE id=?',[$id])===0,'receipt does not mark read');
try {AndroidPush::status($other,$id,false);throw new RuntimeException('status leaked');}catch(RuntimeException $e){acheck($e->getCode()===404,'status permission');}
MobileAuth::revoke($one['id']);acheck(AndroidPush::status($sender,$id,false)['received']===2,'receipt survives logout');
$id2=create_notification($receiver,$sender,'message','پیام','تست','message',null,true);$j=AndroidPush::claim();acheck($j['notification_id']===$id2,'new job');AndroidPush::register($two['id'],$receiver,'fcm-rotated-device-two-abcdefghijkl');AndroidPush::workerAck($j['id'],$j['lease'],$j['fcm_hash'],'gone');acheck((int)Database::fetchValue('SELECT COUNT(*) FROM android_push_devices WHERE token_id=?',[$two['id']])===1,'stale gone cannot delete rotated token');
AndroidPush::unregister($two['id'],$receiver);acheck(AndroidPush::status($sender,$id2,false)['cancelled']===1,'unregister cancels queued work');
// Account deactivation and password changes prevent dispatch from stale devices.
$third=device($receiver,'fcm-device-three-abcdefghijkl');
$id3=create_notification($receiver,$sender,'message','پیام','تست','message',null,true);
Database::execute('UPDATE users SET is_active=0 WHERE id=?', [$receiver]);
acheck(AndroidPush::claim()===null,'deactivated device not dispatched');
acheck(AndroidPush::status($sender,$id3,false)['cancelled']===1,'deactivated work cancelled');
Database::execute('UPDATE users SET is_active=1 WHERE id=?', [$receiver]);
$id4=create_notification($receiver,$sender,'message','پیام','تست','message',null,true);
Database::execute('UPDATE users SET password_hash=? WHERE id=?', [password_hash('changed', PASSWORD_DEFAULT), $receiver]);
acheck(AndroidPush::claim()===null,'password changed device not dispatched');
acheck(AndroidPush::status($sender,$id4,false)['cancelled']===1,'password change cancels work');
echo "Android push queue, lease, per-device receipt, privacy, rotation and logout: OK\n";
