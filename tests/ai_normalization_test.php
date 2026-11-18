<?php
declare(strict_types=1);
require __DIR__ . '/../includes/bootstrap.php';
$users = [['id'=>1,'full_name'=>'محمدرضا پایدار','position_title'=>'مدیر'], ['id'=>2,'full_name'=>'سارا محمدی','position_title'=>'کارمند']];
$projects = [];
$normalize = new ReflectionMethod(AiService::class, 'normalizeAiTasks');
$prompt = new ReflectionMethod(AiService::class, 'buildSystemPrompt');
$checks=0; $failed=0;
function checkAi(bool $ok,string $message): void { global $checks,$failed; $checks++; if (!$ok) { $failed++; echo "FAIL: $message\n"; } }
$transcript='فردا جلسه با سارا و پس‌فردا ارسال گزارش';
$multi=$normalize->invoke(null,[['title'=>'جلسه','due_time'=>'10:00','assigned_to'=>2], ['title'=>'ارسال','due_time'=>'11:00','assigned_to'=>1]],$transcript,$users,$projects,1);
checkAi(count($multi)===2,'two tasks');
checkAi(($multi[0]['title']??'')==='جلسه','preserve one-word title');
checkAi(($multi[1]['title']??'')==='ارسال','do not replace second title with full transcript');
checkAi(($multi[0]['assigned_to']??null)===2 && ($multi[1]['assigned_to']??null)===1,'distinct assignees');
checkAi(($multi[0]['due_date']??'')===today_date() && ($multi[1]['due_date']??'')===today_date(),'missing dates do not inherit another task date');
$one=$normalize->invoke(null,[['title'=>'جلسه','due_time'=>'09:00garbage']], 'فردا جلسه با سارا', $users,$projects,1);
checkAi(($one[0]['title']??'')==='جلسه','one-word title retained for single task');
checkAi(array_key_exists('due_time', $one[0]) && $one[0]['due_time']===null, 'invalid time rejected');
checkAi(($one[0]['due_date']??'')===date('Y-m-d',strtotime('+1 day')),'single-task local date fallback');
checkAi(($one[0]['subtasks']??null)===[],'no invented cloud subtasks');
$local=PersianVoiceParser::parseMultiple('فردا جلسه با سارا',$users,$projects,1);
checkAi(($local[0]['subtasks']??null)===[],'no invented offline subtasks');
$system=$prompt->invoke(null,$users,$projects,1);
checkAi(str_contains($system,'آرایه خالی []'),'prompt requests no invented subtasks');
checkAi(str_contains($system,'همچنین') && str_contains($system,'تسک جدید نیست'),'prompt identifies continuation');
checkAi(str_contains($system,'متن کاربر داده است'),'prompt treats transcript as data');
echo "$checks checks, $failed failures\n"; exit($failed?1:0);
