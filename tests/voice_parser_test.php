<?php
require __DIR__ . '/../includes/bootstrap.php';
$users = [
    ['id'=>1,'full_name'=>'محمدرضا پایدار','username'=>'admin'],
    ['id'=>2,'full_name'=>'سارا محمدی','username'=>'sara'],
];
$projects = [['id'=>3,'name'=>'پروژه وب سایت','code'=>'WEB']];
$fail = 0; $count = 0;
function checkVoice($condition, $message) {
    global $fail, $count; $count++;
    if (!$condition) { $fail++; echo "FAIL: $message\n"; }
}
function voice($text) {
    global $users, $projects;
    return PersianVoiceParser::parseMultiple($text, $users, $projects, 1);
}
foreach ([['غیر فوری','low'], ['غیر‌فوری','low'], ['فوری','urgent'], ['با اولویت بالا','high'], ['با اولویت پایین','low']] as [$phrase,$priority]) {
    $t=voice('فردا جلسه '.$phrase)[0] ?? [];
    checkVoice(($t['priority'] ?? null)===$priority, "priority $phrase");
    checkVoice(($t['title'] ?? null)==='جلسه', "priority title $phrase: ".($t['title']??''));
}
foreach ([['هر روز','daily'],['روزانه','daily'],['هر هفته','weekly'],['هفتگی','weekly'],['هر ماه','monthly'],['ماهانه','monthly']] as [$phrase,$recurrence]) {
    $t=voice('فردا جلسه '.$phrase)[0] ?? [];
    checkVoice(($t['recurrence'] ?? null)===$recurrence, "recurrence $phrase");
    checkVoice(($t['title'] ?? null)===(str_starts_with($phrase, 'هر') ? 'جلسه' : 'جلسه '.$phrase), "recurrence title $phrase: ".($t['title']??''));
}
foreach ([['به مدت ۲ ساعت',120],['به مدت یک ساعت و نیم',90],['حدود ۴۵ دقیقه',45]] as [$phrase,$minutes]) {
    $t=voice('فردا جلسه '.$phrase)[0] ?? [];
    checkVoice(($t['estimated_minutes']??null)===$minutes, "duration $phrase");
    checkVoice(($t['title']??null)==='جلسه', "duration title $phrase");
}
$t=voice('فردا ساعت ۱۰ جلسه با سارا محمدی')[0] ?? [];
checkVoice(($t['title']??null)==='جلسه','title');
checkVoice(($t['assigned_to']??null)===2,'assignee');
checkVoice(($t['due_time']??null)==='10:00','time');
checkVoice(($t['due_date']??null)===date('Y-m-d',strtotime('+1 day')),'tomorrow');
checkVoice(count(voice('فردا با سارا محمدی'))===0,'missing subject');
$firstMonth=voice('باید تا اول ماه بعد گزارش فروش رو بفرستم');
checkVoice(count($firstMonth)===1,'first of month is not a numbered list');
checkVoice(($firstMonth[0]['date_reason']??'')==='اول ماه آینده','first of next month date');
checkVoice(count(voice('اول جلسه با تیم فنی، دوم تماس با مشتری، سوم ارسال گزارش'))===3,'numbered tasks');
checkVoice((voice('فردا ارسال گزارش ماهانه')[0]['title']??'')==='ارسال گزارش ماهانه','monthly report title preserved');
checkVoice(count(voice('فردا جلسه با تیم مالی و پس‌فردا بازبینی قرارداد'))===2,'multiple tasks');
$project=voice('فردا بررسی قرارداد برای پروژه وب سایت')[0] ?? [];
checkVoice(($project['project_id']??null)===3,'project identification');
$subs=voice('فردا بررسی قرارداد شامل طراحی فرم، تست و تحویل')[0] ?? [];
checkVoice(($subs['subtasks']??[])===['طراحی فرم','تست','تحویل'], 'explicit subtasks');
checkVoice(($subs['title']??'')==='بررسی قرارداد','subtasks excluded from title');
echo "$count checks, $fail failures\n";
exit($fail ? 1 : 0);
