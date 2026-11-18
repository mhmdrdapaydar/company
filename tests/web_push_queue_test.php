<?php
declare(strict_types=1);
// This suite always uses a disposable, in-memory database.
define('DB_DRIVER', 'sqlite');
define('SQLITE_DB_PATH', ':memory:');
putenv('WEB_PUSH_ENABLED=true'); putenv('WEB_PUSH_PUBLIC_KEY=B'.str_repeat('A',86)); putenv('WEB_PUSH_WORKER_TOKEN='.str_repeat('a',32));
$_ENV['WEB_PUSH_ENABLED']='true';
$_ENV['WEB_PUSH_PUBLIC_KEY']='B'.str_repeat('A',86);
$_ENV['WEB_PUSH_WORKER_TOKEN']=str_repeat('a',32);
require __DIR__.'/../includes/bootstrap.php';
function assertPush(bool $value,string $message): void {if(!$value){fwrite(STDERR,"FAIL: $message\n");exit(1);}}
function b64(string $s): string{return rtrim(strtr(base64_encode($s),'+/','-_'),'=');}
assertPush(PushService::enabled(),'config');
assertPush(PushService::allowedEndpoint('https://fcm.googleapis.com/fcm/send/test'),'valid endpoint');
foreach(['http://fcm.googleapis.com/a','https://fcm.googleapis.com.evil.test/a','https://localhost/a','https://127.0.0.1/a','https://user@fcm.googleapis.com/a','https://fcm.googleapis.com:8443/a'] as $url) assertPush(!PushService::allowedEndpoint($url),'reject '.$url);
$user=(int)Database::fetchValue('SELECT id FROM users ORDER BY id LIMIT 1');
$sub=['endpoint'=>'https://fcm.googleapis.com/fcm/send/example','keys'=>['p256dh'=>b64("\x04".str_repeat('a',64)),'auth'=>b64(str_repeat('b',16))]];
$baseline=(int)Database::fetchValue('SELECT COUNT(*) FROM notifications WHERE user_id = ?',[$user]);
PushService::subscribe($user,$sub);PushService::subscribe($user,$sub);
assertPush((int)Database::fetchValue('SELECT COUNT(*) FROM push_subscriptions WHERE user_id = ?',[$user])===1,'idempotent subscribe');
create_notification($user,null,'task','تست','تست','task',null,true);
assertPush((int)Database::fetchValue('SELECT COUNT(*) FROM notifications WHERE user_id = ?',[$user])===$baseline+1,'notification persisted');
assertPush((int)Database::fetchValue('SELECT COUNT(*) FROM push_jobs')===1,'queued');
$job=PushService::claim();assertPush(is_array($job)&&$job['attempts']===1,'claim');assertPush(PushService::claim()===null,'lease excludes second worker');
PushService::acknowledge($job['id'],$job['lease'],'retry');assertPush(PushService::claim()===null,'retry delay');
Database::execute('UPDATE push_jobs SET available_at = ?', [date('Y-m-d H:i:s',time()-5)]);
$job2=PushService::claim();assertPush($job2['attempts']===2 && $job2['lease']!==$job['lease'],'retry gets fresh lease');
$rejected=false;try{PushService::acknowledge($job['id'],$job['lease'],'sent');}catch(Throwable){$rejected=true;}assertPush($rejected,'old lease rejected');
PushService::acknowledge($job2['id'],$job2['lease'],'sent');assertPush((int)Database::fetchValue('SELECT COUNT(*) FROM push_jobs')===0,'success removed');
create_notification($user,null,'task','تست','تست','task',null,true);$job3=PushService::claim();PushService::acknowledge($job3['id'],$job3['lease'],'gone');assertPush((int)Database::fetchValue('SELECT COUNT(*) FROM push_subscriptions')===0,'expired subscription removed');
assertPush(PushService::verifyWorker(str_repeat('a',32)) && !PushService::verifyWorker(str_repeat('b',32)), 'worker authentication');
echo "Push queue, retry, leases, endpoint validation and authentication: OK\n";
