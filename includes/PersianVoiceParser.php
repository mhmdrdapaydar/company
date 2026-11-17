<?php
declare(strict_types=1);

/**
 * موتور هوشمند پردازش زبان طبیعی فارسی برای تبدیل متن ویس به تسک ساختاریافته
 * ۱۰۰٪ رایگان، بدون نیاز به کلید API و کاملاً منطبق با تقویم شمسی ایران
 */
final class PersianVoiceParser
{
    private const MONTH_MAP = [
        'فروردین'  => 1,
        'اردیبهشت' => 2,
        'خرداد'    => 3,
        'تیر'      => 4,
        'مرداد'    => 5,
        'امرداد'   => 5,
        'شهریور'   => 6,
        'مهر'      => 7,
        'آبان'     => 8,
        'ابان'     => 8,
        'آذر'      => 9,
        'اذر'      => 9,
        'دی'       => 10,
        'بهمن'     => 11,
        'اسفند'    => 12,
    ];

    private const WORD_NUMBERS = [
        'سی و یکم' => 31, 'سی‌ویکم' => 31, 'سی و یک' => 31, 'سی‌ویک' => 31,
        'سی‌ام' => 30, 'سی ام' => 30, 'سیم' => 30,
        'بیست و نهم' => 29, 'بیست‌ونهم' => 29, 'بیست و نه' => 29, 'بیست‌ونه' => 29,
        'بیست و هشتم' => 28, 'بیست‌وهشتم' => 28, 'بیست و هشت' => 28, 'بیست‌وهشت' => 28,
        'بیست و هفتم' => 27, 'بیست‌وهفتم' => 27, 'بیست و هفت' => 27, 'بیست‌وهفت' => 27,
        'بیست و ششم' => 26, 'بیست‌وششم' => 26, 'بیست و شش' => 26, 'بیست‌وشش' => 26, 'بیست و شیش' => 26,
        'بیست و پنجم' => 25, 'بیست‌وپنجم' => 25, 'بیست و پنج' => 25, 'بیست‌وپنج' => 25,
        'بیست و چهارم' => 24, 'بیست‌وچهارم' => 24, 'بیست و چهار' => 24, 'بیست‌وچهار' => 24,
        'بیست و سوم' => 23, 'بیست‌وسوم' => 23, 'بیست و سه' => 23, 'بیست‌وسه' => 23,
        'بیست و دوم' => 22, 'بیست‌ودوم' => 22, 'بیست و دو' => 22, 'بیست‌ودو' => 22,
        'بیست و یکم' => 21, 'بیست‌ویکم' => 21, 'بیست و یک' => 21, 'بیست‌ویک' => 21,
        'سی' => 30,
        'بیستم' => 20, 'بیست' => 20,
        'نوزدهم' => 19, 'نوزده' => 19,
        'هجدهم' => 18, 'هیجدهم' => 18, 'هجده' => 18, 'هیجده' => 18,
        'هفدهم' => 17, 'هیفدهم' => 17, 'هفده' => 17, 'هیفده' => 17,
        'شانزدهم' => 16, 'شونزدهم' => 16, 'شانزده' => 16, 'شونزده' => 16,
        'پانزدهم' => 15, 'پونزدهم' => 15, 'پانزده' => 15, 'پونزده' => 15,
        'چهاردهم' => 14, 'چهارده' => 14, 'چارده' => 14,
        'سیزدهم' => 13, 'سیزده' => 13,
        'دوازدهم' => 12, 'دوازده' => 12,
        'یازدهم' => 11, 'یازده' => 11,
        'دهم' => 10, 'ده' => 10,
        'نهم' => 9, 'نه' => 9,
        'هشتم' => 8, 'هشت' => 8,
        'هفتم' => 7, 'هفت' => 7,
        'ششم' => 6, 'شیشم' => 6, 'شش' => 6, 'شیش' => 6,
        'پنجم' => 5, 'پنج' => 5,
        'چهارم' => 4, 'چهار' => 4,
        'سوم' => 3, 'سه' => 3,
        'دوم' => 2, 'دو' => 2,
        'اول' => 1, 'یکم' => 1, 'یک' => 1,
    ];

    /**
     * پردازش کامل متن گفتار فارسی و استخراج یک یا چند تسک
     *
     * @param array $users    فهرست کارمندان قابل انتخاب
     * @param array $projects فهرست پروژه‌های فعال شرکت
     * @return array<int, array<string, mixed>>
     */
    /**
     * جداسازی فهرست‌های شماره‌دار فارسی: «اول ... دوم ... سوم ...»
     * یا «۱) ... ۲) ...» یا «یک) ... دو) ...»
     */
    private static function splitNumberedList(array $segments): array
    {
        $out = [];
        foreach ($segments as $seg) {
            $parts = preg_split(
                // ⚠️ نشانگر فهرست باید کلمهٔ مستقل باشد؛ وگرنه «اولویت فوری» با «اول» بریده می‌شود
                // («اولویت فوری تسک تحویل» → «ویت تسک تحویل») و «اولین جلسه» هم خراب می‌شود.
                '/(?:^|\s+)(?:(?:اول|دوم|سوم|چهارم|پنجم|ششم)(?![\x{0600}-\x{06FF}])(?!\s+(?:ماه|هفته|سال|وقت)(?:\s|$))|شماره\s*[\d\x{06F0}-\x{06F9}]+|[\d\x{06F0}-\x{06F9}]+\s*[)\-.،])\s*[:\-)]?\s*/u',
                $seg
            );
            if ($parts === false || count($parts) < 2) {
                $out[] = $seg;
                continue;
            }
            foreach ($parts as $p) {
                $p = trim_fa(trim((string) $p), '.،,؛:');
                if (mb_strlen($p, 'UTF-8') >= 3) {
                    $out[] = $p;
                }
            }
        }
        return $out;
    }

    /**
     * جداسازی جمله‌های پیوندی با «و» که هر کدام «زمان مستقل» دارند.
     * مثال: «فردا ساعت ۱۰ جلسه با تیم مالی و پس‌فردا ساعت ۱۴ بازبینی قرارداد»
     * → ["فردا ساعت ۱۰ جلسه با تیم مالی", "پس‌فردا ساعت ۱۴ بازبینی قرارداد"]
     * ⚠️ فقط وقتی جدا می‌کند که هر دو طرف نشانه زمانی داشته باشند، وگرنه
     * عبارت‌های عادی مثل «جلسه با تیم فنی و مالی» را اشتباه نمی‌شکند.
     */
    private static function splitOnConjunction(string $seg): array
    {
        $timeRe = '(?:فردا|پس\s*فردا|پس‌فردا|امروز|امشب|'
            . 'شنبه|یکشنبه|یک\s+شنبه|دوشنبه|دو\s+شنبه|سه‌شنبه|سه\s+شنبه|چهارشنبه|چهار\s+شنبه|پنجشنبه|پنج\s+شنبه|جمعه|'
            . 'هفته\s*(?:آینده|بعد)|ماه\s*(?:آینده|بعد)|'
            . '(?:دو|سه|چهار|پنج|شش|هفت|ده|\d+)\s*(?:روز|هفته|ماه)\s*(?:دیگه|دیگر|بعد|آینده)|'
            . 'ساعت\s*[\d\x{06F0}-\x{06F9}]+(?::[\d\x{06F0}-\x{06F9}]+)?)';

        // هر جا «و» آمد، اگر بعد از آن یک نشانه زمانی شروع شود، برش بزن
        // برش روی «و» یا ویرگول فارسی وقتی بعدش نشانه زمانی بیاید
        $pattern = '/\s*(?:،|,|\s+و)\s*(?=' . $timeRe . ')/u';
        $parts = preg_split($pattern, $seg);
        if ($parts === false || count($parts) < 2) {
            return [$seg];
        }

        $out = [];
        foreach ($parts as $p) {
            $p = trim_fa(trim((string) $p), '.،,؛:');
            if (mb_strlen($p, 'UTF-8') >= 3) {
                $out[] = $p;
            }
        }
        return count($out) >= 2 ? $out : [$seg];
    }

    public static function parseMultiple(string $transcript, array $users, array $projects, int $defaultUserId): array
    {
        $normalized = normalize_persian($transcript, true);
        if ($normalized === '') {
            return [];
        }

        // جداسازی در صورتی که کاربر در یک ویس چند تسک مجزا گفته باشد
        // ۱) مرز جمله‌ها: نقطه، علامت سؤال، تعجب و خط جدید
        $chunks = preg_split('/(?<=[.؟!])\s+|\n+/u', $normalized) ?: [$normalized];
        // ۲) عبارت‌های پیوندی صریح برای تسک بعدی
        $connector = '/\s+(?:و\s+همچنین|همچنین|و\s+بعدش|بعدش|و\s+یه\s+تسک\s+دیگه|و\s+یک\s+تسک\s+دیگر|تسک\s+بعدی|مورد\s+بعدی|جدا\s+از\s+اون|و\s+در\s+ادامه)\s+/u';
        $segments = [];
        foreach ($chunks as $chunk) {
            foreach (preg_split($connector, $chunk) ?: [$chunk] as $part) {
                $part = trim_fa($part, '.،,؛:');
                if ($part === '') {
                    continue;
                }
                $segments[] = $part;
            }
        }

        // ۳) فهرست‌های شماره‌دار: «اول ... دوم ... سوم ...» یا «۱) ... ۲) ...»
        $segments = self::splitNumberedList($segments);

        // ۳.۵) دور ریختن قطعه‌های «مقدمه‌ای» که هیچ موضوع کاری ندارند
        //      («سه تسک برایم» / «برایم بساز» / «می‌خوام چند تا کار ثبت کنم»)
        //      و قطعه‌هایی که فقط نشانهٔ زمان‌اند («پس‌فردا» / «فردا ساعت ۱۰»)
        //      — این‌ها تاریخ تسک بعدی را تعیین می‌کنند، نه یک تسک مستقل.
        $timeOnlyRe = '/^\s*(?:برای|تا|در|روز|ساعت|از)?\s*'
            . '(?:امروز|امشب|فردا|پس[\s\x{200C}]*فردا|دیروز|'
            . 'شنبه|یکشنبه|یک\s+شنبه|دوشنبه|دو\s+شنبه|سه‌شنبه|سه\s+شنبه|چهارشنبه|چهار\s+شنبه|پنجشنبه|پنج\s+شنبه|جمعه|'
            . 'هفته\s*(?:آینده|بعد|پیش|جاری)|ماه\s*(?:آینده|بعد|پیش|جاری)|سال\s*(?:آینده|بعد|پیش)|'
            . '(?:اول|ابتدای|آخر|اخر|پایان|وسط|اواسط)\s+(?:همین\s+|این\s+)?(?:هفته|ماه|سال)|'
            . '[\d\x{06F0}-\x{06F9}]+\s*(?:دقیقه|ساعت|روز|هفته|ماه)\s*(?:دیگه|دیگر|بعد|آینده|پیش)?|'
            . 'ساعت\s*[\d\x{06F0}-\x{06F9}]+(?::[\d\x{06F0}-\x{06F9}]+)?|'
            . 'صبح|عصر|ظهر|شب|بعد\s*از\s*ظهر|نیمه\s*شب)'
            . '(?:\s*(?:ساعت\s*[\d\x{06F0}-\x{06F9}]+(?::[\d\x{06F0}-\x{06F9}]+)?|صبح|عصر|ظهر|شب|بعد\s*از\s*ظهر))?'
            . '\s*$/u';
        $segments = array_values(array_filter($segments, static function (string $seg) use ($timeOnlyRe): bool {
            $clean = trim_fa($seg, '.،,؛:');
            if (mb_strlen($clean, 'UTF-8') < 3) {
                return false;
            }
            if (preg_match($timeOnlyRe, $clean)) {
                return false;  // فقط زمان است، موضوع کاری ندارد
            }
            // اگر بعد از حذف کلمات شمارش/دستوری چیزی نماند، قطعه مقدمه‌ای است
            $stripped = preg_replace('/(?:^|\s)(?:سه|دو|چهار|پنج|چند|یک|یه)?\s*(?:تسک|کار|وظیفه|یادآور)(?:ها|های)?(?:\s+(?:جدید|دیگه|دیگر))?(?:\s+(?:برایم|برام|برای\s+من|بهم))?\s*(?:بساز|بذار|بزار|ثبت\s+کن|ایجاد\s+کن|تعریف\s+کن)?(?:\s|$)/u', ' ', $clean) ?? $clean;
            $stripped = preg_replace('/[\s،,؛:.]+/u', ' ', $stripped) ?? $stripped;
            return mb_strlen(trim($stripped), 'UTF-8') >= 3;
        }));

        // ۴) جمله‌های پیوندی با «و» که هر کدام فعل/زمان مستقل دارند
        //    («فردا جلسه با تیم مالی و پس‌فردا بازبینی قرارداد»)
        $expanded = [];
        foreach ($segments as $seg) {
            foreach (self::splitOnConjunction($seg) as $piece) {
                $expanded[] = $piece;
            }
        }
        $segments = $expanded;
        // اگر تنها یک قطعه به دست آمد، عبارت‌های ربطی کوتاه را نادیده بگیر و یک تسک بساز
        // ⚠️ اما اگر ورودی فقط یک نشانهٔ زمان باشد («فردا»، «پس‌فردا ساعت ۱۰»)
        // موضوع کاری ندارد و نباید تسکی با عنوان «فردا» بسازد.
        if (count($segments) === 0) {
            if (preg_match($timeOnlyRe, trim_fa($normalized, '.،,؛:'))) {
                return [];
            }
            $segments = [$normalized];
        }

        $tasks = [];
        foreach ($segments as $seg) {
            if (mb_strlen($seg, 'UTF-8') < 3) {
                continue;
            }
            $one = self::parseSingle($seg, $users, $projects, $defaultUserId);
            // قطعه‌ای که موضوعی ندارد (فقط زمان/نام) تسک نمی‌شود
            if (trim((string) ($one['title'] ?? '')) !== '') {
                $tasks[] = $one;
            }
        }

        return $tasks;
    }

    /**
     * استخراج دقیق مشخصات یک تسک از جمله گفتاری فارسی
     */
    public static function parseSingle(string $rawText, array $users, array $projects, int $defaultUserId): array
    {
        $text = normalize_persian($rawText, true);
        $working = $text;

        // ۱. تشخیص تاریخ شمسی و روز انجام تسک
        $dateResult = self::extractDate($text);
        foreach ($dateResult['matched_phrases'] as $phrase) {
            if ($phrase !== '') {
                $working = str_replace($phrase, ' ', $working);
            }
        }

        // ۲. تشخیص ساعت انجام تسک
        $timeResult = self::extractTime($text);
        foreach ($timeResult['matched_phrases'] as $phrase) {
            if ($phrase !== '') {
                $working = str_replace($phrase, ' ', $working);
            }
        }

        // ۳. تشخیص مدت زمان تخمینی
        $durationResult = self::extractDuration($text);
        foreach ($durationResult['matched_phrases'] as $phrase) {
            if ($phrase !== '') {
                $working = str_replace($phrase, ' ', $working);
            }
        }

        // ۴. تشخیص تکرار دوره‌ای
        $recurrenceResult = self::extractRecurrence($text);
        // قیدهای «ماهانه/روزانه» می‌توانند بخشی از موضوع باشند (گزارش ماهانه).
        if (str_starts_with($recurrenceResult['matched_phrase'], 'هر')) {
            $working = str_replace($recurrenceResult['matched_phrase'], ' ', $working);
        }

        // ۵. تشخیص اولویت
        $priorityResult = self::extractPriority($text);
        foreach ($priorityResult['matched_phrases'] as $phrase) {
            if ($phrase !== '') {
                $working = str_replace($phrase, ' ', $working);
            }
        }

        // ۶. تشخیص کارمند مسئول تسک
        $assigneeResult = self::extractAssignee($text, $users, $defaultUserId);
        foreach ($assigneeResult['matched_phrases'] as $phrase) {
            if ($phrase !== '') {
                $working = str_replace($phrase, ' ', $working);
            }
        }

        // ۷. تشخیص پروژه مرتبط
        $projectResult = self::extractProject($text, $projects);
        foreach ($projectResult['matched_phrases'] as $phrase) {
            if ($phrase !== '') {
                $working = str_replace($phrase, ' ', $working);
            }
        }

        // ۸. تشخیص زیرتسک‌های گفته‌شده در ویس
        $subtasksResult = self::extractSubtasks($working);
        if ($subtasksResult['matched_phrase'] !== '') {
            $working = str_replace($subtasksResult['matched_phrase'], ' ', $working);
        }

        // ۹. استخراج موضوع و عنوان تمیز تسک
        // همهٔ نام‌های کارمندانی که در متن آمده‌اند (برای حذف از عنوان)
        $knownNames = [];
        foreach ($users as $u) {
            $fn = trim((string) ($u['full_name'] ?? ''));
            if ($fn !== '' && mb_strpos($text, $fn) !== false) {
                $knownNames[] = $fn;
            }
        }
        $title = self::extractCleanTitle($text, $working, $knownNames);

        // ۱۰. تشخیص دسته‌بندی و برچسب‌های هوشمند
        [$category, $tags] = self::inferCategoryAndTags($title . ' ' . $text);

        // اگر کاربر زیرتسک مستقیم نگفته بود، بر اساس دسته‌بندی زیرتسک هوشمند پیشنهاد بده
        $subtasks = $subtasksResult['items'];
        if (empty($subtasks)) {
            $subtasks = self::suggestSubtasks($title, $category);
        }

        $dueGreg   = $dateResult['gregorian'];
        $dueJalali = Jalali::toDateString($dueGreg, false);
        $dueHuman  = Jalali::toHuman($dueGreg, true, true);

        return [
            'title'               => $title,
            'description'         => 'ثبت‌شده از طریق فرمان صوتی: «' . normalize_persian($rawText) . '»',
            'due_date'            => $dueGreg,
            'due_date_jalali'     => $dueJalali,
            'due_date_jalali_fa'  => fa_digits($dueJalali),
            'due_date_human'      => $dueHuman,
            'date_reason'         => $dateResult['reason'],
            'due_time'            => $timeResult['time'],
            'due_time_fa'         => $timeResult['time'] ? fa_digits($timeResult['time']) : '',
            'priority'            => $priorityResult['priority'],
            'priority_label'      => task_priority_label($priorityResult['priority']),
            'assigned_to'         => $assigneeResult['user_id'],
            'assigned_name'       => $assigneeResult['user_name'],
            'project_id'          => $projectResult['project_id'],
            'project_name'        => $projectResult['project_name'],
            'estimated_minutes'   => $durationResult['minutes'],
            'recurrence'          => $recurrenceResult['recurrence'],
            'category'            => $category,
            'tags'                => implode(',', $tags),
            'subtasks'            => $subtasks,
        ];
    }

    /**
     * تشخیص دقیق تاریخ شمسی از گفتار فارسی
     */
    public static function extractDate(string $text): array
    {
        $todayTs = strtotime(date('Y-m-d') . ' 12:00:00');
        [$curJy, $curJm, $curJd] = Jalali::todayArray();
        $phrases = [];

        // الف) تاریخ شمسی کامل عددی: ۱۴۰۵/۰۸/۳۰ یا 1405-9-5
        if (preg_match('/((?:در\s+|برای\s+|تا\s+|روز\s+|تاریخ\s+)*(1[34]\d{2})[\/\-\.](\d{1,2})[\/\-\.](\d{1,2}))/u', $text, $m)) {
            $jy = (int) $m[2];
            $jm = (int) $m[3];
            $jd = (int) $m[4];
            if (Jalali::isValidDate($jy, $jm, $jd)) {
                $g = Jalali::jalaliToGregorianString($jy, $jm, $jd);
                return [
                    'gregorian'       => $g,
                    'reason'          => 'تاریخ صریح شمسی (' . Jalali::toHuman($g, true, true) . ')',
                    'matched_phrases' => [$m[1]],
                ];
            }
        }

        // ب) تاریخ شمسی عددی با نام ماه: «۲۸ آبان»، «۵ آذر ۱۴۰۵»، «روز ۳۰ آبان ماه»
        $monthPattern = implode('|', array_keys(self::MONTH_MAP));
        $regexNumMonth = '/((?:در\s+|برای\s+|تا\s+)?(?:روز\s+|تاریخ\s+)?(\d{1,2})(?:ام|‌ام|م)?\s+(' . $monthPattern . ')(?:\s*ماه)?(?:\s+(1[34]\d{2}))?)/u';
        if (preg_match($regexNumMonth, $text, $m)) {
            $jd = (int) $m[2];
            $jm = self::MONTH_MAP[$m[3]] ?? $curJm;
            $jy = !empty($m[4]) ? (int) $m[4] : $curJy;
            // اگر سال ذکر نشده و آن روز در سال جاری گذشته است، سال آینده شمسی را در نظر بگیر
            if (empty($m[4]) && ($jm < $curJm || ($jm === $curJm && $jd < $curJd))) {
                $jy++;
            }
            if ($jd >= 1 && $jd <= 31) {
                $g = Jalali::jalaliToGregorianString($jy, $jm, $jd);
                return [
                    'gregorian'       => $g,
                    'reason'          => 'روز و ماه شمسی (' . Jalali::toHuman($g, true, true) . ')',
                    'matched_phrases' => [$m[1]],
                ];
            }
        }

        // ج) تاریخ شمسی حروفی با نام ماه: «بیست و هشتم آبان»، «اول دی»، «پانزدهم آذر ۱۴۰۵»
        $wordKeys = array_map(static fn(string $w): string => preg_quote($w, '/'), array_keys(self::WORD_NUMBERS));
        $wordPattern = implode('|', $wordKeys);
        $regexWordMonth = '/((?:در\s+|برای\s+|تا\s+)?(?:روز\s+|تاریخ\s+)?(' . $wordPattern . ')\s+(' . $monthPattern . ')(?:\s*ماه)?(?:\s+(1[34]\d{2}))?)/u';
        if (preg_match($regexWordMonth, $text, $m)) {
            $jd = self::WORD_NUMBERS[$m[2]] ?? 1;
            $jm = self::MONTH_MAP[$m[3]] ?? $curJm;
            $jy = !empty($m[4]) ? (int) $m[4] : $curJy;
            if (empty($m[4]) && ($jm < $curJm || ($jm === $curJm && $jd < $curJd))) {
                $jy++;
            }
            $g = Jalali::jalaliToGregorianString($jy, $jm, $jd);
            return [
                'gregorian'       => $g,
                'reason'          => 'تاریخ گفتاری شمسی (' . Jalali::toHuman($g, true, true) . ')',
                'matched_phrases' => [$m[1]],
            ];
        }

        // د) پس‌فردا / پس فردا (باید قبل از «فردا» بررسی شود)
        if (preg_match('/((?:برای\s+|تا\s+|در\s+|روز\s+)?پس[\s\x{200C}]*فردا)/u', $text, $m)) {
            $g = date('Y-m-d', $todayTs + 2 * 86400);
            return ['gregorian' => $g, 'reason' => 'پس‌فردا', 'matched_phrases' => [$m[1]]];
        }

        // هـ) فردا
        if (preg_match('/((?:برای\s+|تا\s+|در\s+|روز\s+)?فردا)/u', $text, $m)) {
            $g = date('Y-m-d', $todayTs + 86400);
            return ['gregorian' => $g, 'reason' => 'فردا', 'matched_phrases' => [$m[1]]];
        }

        // و) امروز / امشب
        if (preg_match('/((?:برای\s+|تا\s+|در\s+)?(?:امروز|امشب))/u', $text, $m)) {
            $g = date('Y-m-d', $todayTs);
            return ['gregorian' => $g, 'reason' => 'امروز', 'matched_phrases' => [$m[1]]];
        }

        // ز) N روز دیگر / بعد / آینده (عددی و حروفی)
        if (preg_match('/((?:برای\s+|تا\s+)?(\d{1,3})\s*روز\s*(?:دیگه|دیگر|بعد|آینده|اینده))/u', $text, $m)) {
            $days = min(365, max(1, (int) $m[2]));
            $g = date('Y-m-d', $todayTs + $days * 86400);
            return ['gregorian' => $g, 'reason' => fa_digits($days) . ' روز دیگر', 'matched_phrases' => [$m[1]]];
        }
        if (preg_match('/((?:برای\s+|تا\s+)?(' . $wordPattern . ')\s+روز\s*(?:دیگه|دیگر|بعد|آینده|اینده))/u', $text, $m)) {
            $days = self::WORD_NUMBERS[$m[2]] ?? 1;
            $g = date('Y-m-d', $todayTs + $days * 86400);
            return ['gregorian' => $g, 'reason' => fa_digits($days) . ' روز دیگر', 'matched_phrases' => [$m[1]]];
        }

        // ح) N هفته دیگر / بعد
        if (preg_match('/((?:برای\s+|تا\s+)?(\d{1,2}|دو|سه|چهار)\s+هفته\s*(?:دیگه|دیگر|بعد|آینده|اینده))/u', $text, $m)) {
            $w = is_numeric($m[2]) ? (int) $m[2] : (self::WORD_NUMBERS[$m[2]] ?? 2);
            $g = date('Y-m-d', $todayTs + ($w * 7 * 86400));
            return ['gregorian' => $g, 'reason' => fa_digits($w) . ' هفته دیگر', 'matched_phrases' => [$m[1]]];
        }

        // ط) روزهای هفته ایرانی (پنجشنبه، چهارشنبه، سه‌شنبه، دوشنبه، یکشنبه، شنبه، جمعه)
        // ترتیب مهم است تا «یکشنبه/دوشنبه/سه‌شنبه/چهارشنبه/پنجشنبه» پیش از «شنبه» تطبیق داده شوند!
        $weekdays = [
            ['pattern' => 'پنج[\s\x{200C}]*شنبه',  'idx' => 5, 'label' => 'پنجشنبه'],
            ['pattern' => 'چهار[\s\x{200C}]*شنبه', 'idx' => 4, 'label' => 'چهارشنبه'],
            ['pattern' => 'سه[\s\x{200C}]*شنبه',   'idx' => 3, 'label' => 'سه‌شنبه'],
            ['pattern' => 'دو[\s\x{200C}]*شنبه',   'idx' => 2, 'label' => 'دوشنبه'],
            ['pattern' => 'یک[\s\x{200C}]*شنبه',   'idx' => 1, 'label' => 'یکشنبه'],
            ['pattern' => 'جمعه',                   'idx' => 6, 'label' => 'جمعه'],
            ['pattern' => 'شنبه',                   'idx' => 0, 'label' => 'شنبه'],
        ];
        $todayW = Jalali::weekdayIndex($todayTs); // 0=شنبه ... 6=جمعه
        foreach ($weekdays as $wd) {
            $rx = '/((?:برای\s+|تا\s+|در\s+)?(?:روز\s+|این\s+)?(' . $wd['pattern'] . ')(?:ی)?(?:\s+(هفته\s*(?:بعد|آینده|اینده|دیگه|دیگر)|آینده|اینده|بعدی|بعد|دیگه|دیگر))?)/u';
            if (preg_match($rx, $text, $m)) {
                $targetIdx = $wd['idx'];
                $diff = ($targetIdx - $todayW + 7) % 7;
                $modifier = $m[3] ?? '';
                if ($diff === 0) {
                    // اگر امروز همان روز است و گفته «شنبه آینده/هفته بعد» یا صرفاً «شنبه»، ۷ روز بعد؛ مگر «این شنبه»
                    $isExplicitThis = (bool) preg_match('/این\s+' . $wd['pattern'] . '/u', $m[1]);
                    $diff = $isExplicitThis ? 0 : 7;
                } elseif ($modifier !== '' && preg_match('/هفته\s*(?:بعد|آینده|اینده|دیگه|دیگر)/u', $modifier)) {
                    // اگر گفته «چهارشنبه هفته بعد» و چهارشنبه هنوز در همین هفته ایرانی پیش روست، به هفته بعد ببر
                    if ($targetIdx > $todayW) {
                        $diff += 7;
                    }
                }
                $g = date('Y-m-d', $todayTs + ($diff * 86400));
                return [
                    'gregorian'       => $g,
                    'reason'          => $wd['label'] . ' (' . Jalali::toHuman($g, false, true) . ')',
                    'matched_phrases' => [$m[1]],
                ];
            }
        }

        // ی) عبارات تقویمی پرکاربرد فارسی: اول ماه بعد، آخر ماه، اول هفته آینده، آخر هفته، هفته آینده، ماه آینده
        if (preg_match('/((?:برای\s+|تا\s+)?(?:اول|ابتدای)\s+ماه\s*(?:بعد|آینده|اینده|جدید))/u', $text, $m)) {
            $ny = $curJm === 12 ? $curJy + 1 : $curJy;
            $nm = $curJm === 12 ? 1 : $curJm + 1;
            $g = Jalali::jalaliToGregorianString($ny, $nm, 1);
            return ['gregorian' => $g, 'reason' => 'اول ماه آینده', 'matched_phrases' => [$m[1]]];
        }

        if (preg_match('/((?:برای\s+|تا\s+)?(?:آخر|اخر|پایان)\s+(?:همین\s+|این\s+)?ماه)/u', $text, $m)) {
            $lastD = Jalali::daysInMonth($curJy, $curJm);
            $g = Jalali::jalaliToGregorianString($curJy, $curJm, $lastD);
            return ['gregorian' => $g, 'reason' => 'پایان ماه جاری', 'matched_phrases' => [$m[1]]];
        }

        // «آخرین روز کاری این ماه» / «آخرین روز کاری ماه» → آخرین روز غیرتعطیل (جمعه نیست)
        if (preg_match('/((?:تا|برای)?\s*(?:آخرین|اخرین)\s+روز\s+(?:کاری|اداری)(?:\s+(?:همین\s+|این\s+)?ماه)?)/u', $text, $m)) {
            $lastD = Jalali::daysInMonth($curJy, $curJm);
            $g = Jalali::jalaliToGregorianString($curJy, $curJm, $lastD);
            // عقب بردن تا اولین روزی که جمعه نباشد
            $guard = 0;
            while (Jalali::weekdayIndex(strtotime($g . ' 12:00:00')) === 6 && $guard < 7) {
                $g = date('Y-m-d', strtotime($g . ' -1 day'));
                $guard++;
            }
            return [
                'gregorian'       => $g,
                'reason'          => 'آخرین روز کاری ماه (' . Jalali::toHuman($g, true, true) . ')',
                'matched_phrases' => [$m[1]],
            ];
        }

        // «آخرین روز ماه» / «آخر ماه» بدون قید «کاری»
        if (preg_match('/((?:تا|برای)?\s*(?:آخرین|اخرین)\s+روز\s+(?:همین\s+|این\s+)?ماه)/u', $text, $m)) {
            $lastD = Jalali::daysInMonth($curJy, $curJm);
            $g = Jalali::jalaliToGregorianString($curJy, $curJm, $lastD);
            return ['gregorian' => $g, 'reason' => 'آخرین روز ماه جاری', 'matched_phrases' => [$m[1]]];
        }

        // «آخر هفتهٔ همین ماه» / «آخر همین هفته»
        if (preg_match('/((?:تا|برای)?\s*(?:آخرین|اخرین)\s+هفته)/u', $text, $m)) {
            $diff = (5 - $todayW + 7) % 7;
            if ($diff === 0) {
                $diff = 7;
            }
            $g = date('Y-m-d', $todayTs + $diff * 86400);
            return ['gregorian' => $g, 'reason' => 'آخرین هفته (پنجشنبه)', 'matched_phrases' => [$m[1]]];
        }

        if (preg_match('/((?:برای\s+|تا\s+)?(?:وسط|اواسط)\s+(?:همین\s+|این\s+)?ماه)/u', $text, $m)) {
            $ny = $curJy;
            $nm = $curJm;
            if ($curJd > 15) {
                $nm = $curJm === 12 ? 1 : $curJm + 1;
                $ny = $curJm === 12 ? $curJy + 1 : $curJy;
            }
            $g = Jalali::jalaliToGregorianString($ny, $nm, 15);
            return ['gregorian' => $g, 'reason' => 'پانزدهم ماه', 'matched_phrases' => [$m[1]]];
        }

        if (preg_match('/((?:برای\s+|تا\s+)?(?:اول|ابتدای)\s+هفته(?:\s*(?:بعد|آینده|اینده|جدید))?)/u', $text, $m)) {
            $daysToSat = (7 - $todayW) % 7;
            if ($daysToSat === 0) {
                $daysToSat = 7;
            }
            $g = date('Y-m-d', $todayTs + $daysToSat * 86400);
            return ['gregorian' => $g, 'reason' => 'اول هفته آینده (شنبه)', 'matched_phrases' => [$m[1]]];
        }

        if (preg_match('/((?:برای\s+|تا\s+)?(?:آخر|اخر|پایان)\s+هفته)/u', $text, $m)) {
            // پنجشنبه پیش رو (اندیس ۵)
            $diff = (5 - $todayW + 7) % 7;
            if ($diff === 0 && (int) date('H') >= 16) {
                $diff = 7;
            }
            $g = date('Y-m-d', $todayTs + $diff * 86400);
            return ['gregorian' => $g, 'reason' => 'آخر هفته (پنجشنبه)', 'matched_phrases' => [$m[1]]];
        }

        if (preg_match('/((?:برای\s+|تا\s+|در\s+)?هفته\s*(?:بعد|آینده|اینده|دیگه|دیگر))/u', $text, $m)) {
            $g = date('Y-m-d', $todayTs + 7 * 86400);
            return ['gregorian' => $g, 'reason' => 'هفته آینده', 'matched_phrases' => [$m[1]]];
        }

        if (preg_match('/((?:برای\s+|تا\s+|در\s+)?ماه\s*(?:بعد|آینده|اینده|دیگه|دیگر))/u', $text, $m)) {
            $ny = $curJm === 12 ? $curJy + 1 : $curJy;
            $nm = $curJm === 12 ? 1 : $curJm + 1;
            $g = Jalali::jalaliToGregorianString($ny, $nm, $curJd);
            return ['gregorian' => $g, 'reason' => 'ماه آینده', 'matched_phrases' => [$m[1]]];
        }

        // ک) فقط شماره روز ماه جاری: «روز بیست و نهم» یا «تاریخ ۳۰ ام»
        if (preg_match('/((?:برای\s+|تا\s+)?(?:روز|تاریخ)\s+(\d{1,2})\s*(?:ام|‌ام|م|این\s+ماه|ماه))/u', $text, $m)) {
            $jd = (int) $m[2];
            if ($jd >= 1 && $jd <= 31) {
                $ny = $curJy;
                $nm = $curJm;
                if ($jd < $curJd) {
                    $nm = $curJm === 12 ? 1 : $curJm + 1;
                    $ny = $curJm === 12 ? $curJy + 1 : $curJy;
                }
                $g = Jalali::jalaliToGregorianString($ny, $nm, $jd);
                return ['gregorian' => $g, 'reason' => Jalali::toHuman($g, true, true), 'matched_phrases' => [$m[1]]];
            }
        }

        // پیش‌فرض هوشمند: امروز
        return [
            'gregorian'       => date('Y-m-d', $todayTs),
            'reason'          => 'امروز (پیش‌فرض)',
            'matched_phrases' => $phrases,
        ];
    }

    /**
     * تشخیص ساعت انجام از گفتار فارسی (مثلاً «ساعت ۱۰ و نیم صبح»، «ساعت ۱۴:۳۰»، «ساعت ۴ عصر»)
     */
    public static function extractTime(string $text): array
    {
        // ۱. فرمت عددی دقیق: ساعت ۱۴:۳۰ یا 9:15 صبح
        if (preg_match('/((?:راس\s+|رأس\s+|در\s+|تا\s+)?(?:ساعت\s+)?(\d{1,2})\s*[:٫]\s*(\d{2})(?:\s*(صبح|ظهر|بعد[\s\x{200C}]*از[\s\x{200C}]*ظهر|عصر|شب|بامداد))?)/u', $text, $m)) {
            $h = (int) $m[2];
            $min = (int) $m[3];
            $period = $m[4] ?? '';
            $h = self::adjustHourForPeriod($h, $period);
            if ($h >= 0 && $h <= 23 && $min >= 0 && $min <= 59) {
                return ['time' => sprintf('%02d:%02d', $h, $min), 'matched_phrases' => [$m[1]]];
            }
        }

        // ۱.۵ شکل تفریقی فارسی: «یک ربع به پنج عصر»، «بیست دقیقه به ده»، «ده به پنج»
        //     یعنی ۱۵/۲۰/... دقیقه مانده به ساعتِ گفته‌شده.
        //     ⚠️ باید قبل از الگوی «ساعت X» بررسی شود، وگرنه «به پنج» نادیده گرفته می‌شود.
        $hwAll = [
            'بیست و سه' => 23, 'بیست و دو' => 22, 'بیست و یک' => 21, 'بیست' => 20,
            'نوزده' => 19, 'هجده' => 18, 'هیجده' => 18, 'هفده' => 17, 'شانزده' => 16, 'شونزده' => 16,
            'پانزده' => 15, 'پونزده' => 15, 'چهارده' => 14, 'سیزده' => 13, 'دوازده' => 12,
            'یازده' => 11, 'ده' => 10, 'نه' => 9, 'هشت' => 8, 'هفت' => 7,
            'شش' => 6, 'شیش' => 6, 'پنج' => 5, 'چهار' => 4, 'سه' => 3, 'دو' => 2, 'یک' => 1,
        ];
        $hwAllPat = implode('|', array_keys($hwAll));
        $numPat   = '\d{1,2}|' . $hwAllPat;
        $subtractPatterns = [
            // «یک ربع به پنج» / «ربع به پنج»
            '/((?:ساعت\s+)?(?:یک\s+)?ربع\s+(?:به|به‌)\s+(' . $numPat . ')(?:\s*(صبح|ظهر|بعد[\s\x{200C}]*از[\s\x{200C}]*ظهر|عصر|شب|بامداد))?)/u' => 15,
            // «بیست دقیقه به ده» / «ده دقیقه به هفت»
            '/((?:ساعت\s+)?(' . $numPat . ')\s*دقیقه\s+(?:به|به‌)\s+(' . $numPat . ')(?:\s*(صبح|ظهر|بعد[\s\x{200C}]*از[\s\x{200C}]*ظهر|عصر|شب|بامداد))?)/u' => null,
            // «ده به پنج» (بدون «دقیقه»/«ربع») — فقط وقتی «ساعت» یا واژه زمانی همراه باشد
            '/((?:ساعت\s+)(?:(\d{1,2}|' . $hwAllPat . '))\s+(?:به|به‌)\s+(' . $numPat . ')(?:\s*(صبح|ظهر|بعد[\s\x{200C}]*از[\s\x{200C}]*ظهر|عصر|شب|بامداد))?)/u' => null,
        ];
        foreach ($subtractPatterns as $rx => $fixedMin) {
            if (!preg_match($rx, $text, $m)) {
                continue;
            }
            // تعیین «دقیقه مانده» و «ساعت هدف»
            if ($fixedMin === 15) {
                $minsBefore = 15;
                $targetRaw  = $m[2] ?? '';
                $period     = $m[3] ?? '';
            } else {
                // الگوی «X دقیقه به Y» → گروه‌ها: 1=کل، 2=X، 3=Y، 4=دوره
                // الگوی «ساعت X به Y»   → گروه‌ها: 1=کل، 2=X، 3=Y، 4=دوره
                $minsBefore = self::faNumberToInt((string) ($m[2] ?? ''));
                $targetRaw  = $m[3] ?? '';
                $period     = $m[4] ?? '';
            }
            $target = self::faNumberToInt((string) $targetRaw);
            if ($target === null || $minsBefore === null || $minsBefore < 0 || $minsBefore > 59) {
                continue;
            }
            $h = self::adjustHourForPeriod($target, $period);
            $total = $h * 60 - $minsBefore;
            if ($total < 0) {
                $total += 24 * 60;
            }
            if ($total >= 0 && $total < 24 * 60) {
                return [
                    'time'            => sprintf('%02d:%02d', intdiv($total, 60), $total % 60),
                    'matched_phrases' => [$m[1]],
                ];
            }
        }

        // ۲. ساعت عددی همراه نیم/ربع/دقیقه: «ساعت ۱۰ و نیم صبح»، «ساعت ۳ عصر»، «ساعت ۸ و ربع»
        if (preg_match('/((?:راس\s+|رأس\s+|تا\s+)?ساعت\s+(\d{1,2})(?:\s+و\s+(نیم|ربع|\d{1,2}\s*دقیقه))?(?:\s*(صبح|ظهر|بعد[\s\x{200C}]*از[\s\x{200C}]*ظهر|عصر|شب|بامداد))?)/u', $text, $m)) {
            $h = (int) $m[2];
            $minPart = $m[3] ?? '';
            $period = $m[4] ?? '';
            $min = 0;
            if ($minPart === 'نیم') {
                $min = 30;
            } elseif ($minPart === 'ربع') {
                $min = 15;
            } elseif (preg_match('/(\d{1,2})/', $minPart, $mm)) {
                $min = min(59, (int) $mm[1]);
            }
            $h = self::adjustHourForPeriod($h, $period);
            if ($h >= 0 && $h <= 23) {
                return ['time' => sprintf('%02d:%02d', $h, $min), 'matched_phrases' => [$m[1]]];
            }
        }

        // ۳. ساعت حروفی: «ساعت ده و نیم صبح»، «ساعت چهار عصر»
        $hourWords = [
            'بیست و سه' => 23, 'بیست و دو' => 22, 'بیست و یک' => 21, 'بیست' => 20,
            'نوزده' => 19, 'هجده' => 18, 'هیجده' => 18, 'هفده' => 17, 'شانزده' => 16, 'شونزده' => 16,
            'پانزده' => 15, 'پونزده' => 15, 'چهارده' => 14, 'سیزده' => 13, 'دوازده' => 12,
            'یازده' => 11, 'ده' => 10, 'نه' => 9, 'هشت' => 8, 'هفت' => 7,
            'شش' => 6, 'شیش' => 6, 'پنج' => 5, 'چهار' => 4, 'سه' => 3, 'دو' => 2, 'یک' => 1,
        ];
        $hwPattern = implode('|', array_keys($hourWords));
        if (preg_match('/((?:راس\s+|رأس\s+|تا\s+)?ساعت\s+(' . $hwPattern . ')(?:\s+و\s+(نیم|ربع|بیست\s*دقیقه|ده\s*دقیقه|سی\s*دقیقه|چهل\s*و\s*پنج\s*دقیقه))?(?:\s*(صبح|ظهر|بعد[\s\x{200C}]*از[\s\x{200C}]*ظهر|عصر|شب|بامداد))?)/u', $text, $m)) {
            $h = $hourWords[$m[2]] ?? 9;
            $minPart = $m[3] ?? '';
            $period = $m[4] ?? '';
            $min = match (true) {
                $minPart === 'نیم' || str_contains($minPart, 'سی') => 30,
                $minPart === 'ربع' => 15,
                str_contains($minPart, 'چهل') => 45,
                str_contains($minPart, 'بیست') => 20,
                str_contains($minPart, 'ده') => 10,
                default => 0,
            };
            $h = self::adjustHourForPeriod($h, $period);
            return ['time' => sprintf('%02d:%02d', $h, $min), 'matched_phrases' => [$m[1]]];
        }

        // ۴. زمان‌های کلی روز کاری
        if (preg_match('/(اول\s+وقت(?:\s+اداری|\s+صبح)?)/u', $text, $m)) {
            return ['time' => '08:30', 'matched_phrases' => [$m[1]]];
        }
        if (preg_match('/((?:آخر|اخر)\s+وقت(?:\s+اداری)?)/u', $text, $m)) {
            return ['time' => '16:30', 'matched_phrases' => [$m[1]]];
        }
        if (preg_match('/(سر\s+ظهر|تا\s+ظهر)/u', $text, $m)) {
            return ['time' => '12:00', 'matched_phrases' => [$m[1]]];
        }

        return ['time' => null, 'matched_phrases' => []];
    }

    /**
     * تبدیل عدد فارسی (رقمی یا حروفی) به عدد صحیح
     * «۵» / «پنج» / «بیست» / «بیست و سه» → عدد
     */
    private static function faNumberToInt(string $raw): ?int
    {
        $v = trim(str_replace(['‌', '\x{200C}'], [' ', ' '], $raw));
        $v = preg_replace('/\s+/u', ' ', $v) ?? $v;
        if ($v === '') {
            return null;
        }
        $digits = en_digits($v);
        if (preg_match('/^\d{1,3}$/', $digits)) {
            return (int) $digits;
        }
        // حروفی: مستقیم از جدول، سپس نسخهٔ بدون نیم‌فاصله
        if (isset(self::WORD_NUMBERS[$v])) {
            return self::WORD_NUMBERS[$v];
        }
        foreach (self::WORD_NUMBERS as $k => $num) {
            if (str_replace(' ', '', $k) === str_replace(' ', '', $v)) {
                return $num;
            }
        }
        return null;
    }

    private static function adjustHourForPeriod(int $hour, string $period): int
    {
        if ($period !== '') {
            if (preg_match('/(عصر|شب|بعد[\s\x{200C}]*از[\s\x{200C}]*ظهر)/u', $period) && $hour >= 1 && $hour <= 11) {
                return $hour + 12;
            }
            if ($period === 'بامداد' && $hour === 12) {
                return 0;
            }
            return $hour;
        }
        // در ساعات اداری ایران اگر گفته شود «ساعت ۲ / ۳ / ۴ / ۵» بدون ذکر صبح/بامداد، معمولاً منظور ۱۴ تا ۱۷ بعدازظهر است
        if ($hour >= 1 && $hour <= 5) {
            return $hour + 12;
        }
        return $hour;
    }

    /**
     * تشخیص مدت زمان تخمینی کار
     */
    private static function extractDuration(string $text): array
    {
        if (preg_match('/((?:به\s+مدت|حدود|تخمین)\s+(\d{1,2}|یک|دو|سه|چهار)\s+ساعت(?:\s+و\s+نیم)?)/u', $text, $m)) {
            $h = is_numeric($m[2]) ? (int) $m[2] : (self::WORD_NUMBERS[$m[2]] ?? 1);
            $mins = $h * 60 + (str_contains($m[1], 'نیم') ? 30 : 0);
            return ['minutes' => $mins, 'matched_phrases' => [$m[1]]];
        }
        if (preg_match('/((?:به\s+مدت|حدود)\s+(\d{1,3})\s+دقیقه)/u', $text, $m)) {
            return ['minutes' => min(600, max(5, (int) $m[2])), 'matched_phrases' => [$m[1]]];
        }
        return ['minutes' => 60, 'matched_phrases' => []];
    }

    /**
     * تشخیص تکرار تسک
     */
    private static function extractRecurrence(string $text): array
    {
        foreach (['daily' => 'هر\s*روز|روزانه', 'weekly' => 'هر\s*هفته|هفتگی', 'monthly' => 'هر\s*ماه|ماهانه|ماهیانه'] as $recurrence => $pattern) {
            if (preg_match('/(?<![\p{L}\x{200C}])(?:' . $pattern . ')(?![\p{L}\x{200C}])/u', $text, $match)) {
                return ['recurrence' => $recurrence, 'matched_phrase' => $match[0]];
            }
        }
        return ['recurrence' => 'none', 'matched_phrase' => ''];
    }

    /**
     * تشخیص اولویت تسک از کلمات کلیدی گفتاری
     */
    private static function extractPriority(string $text): array
    {
        // عبارت منفی باید پیش از «فوری» بررسی شود تا بخشی از آن جدا نشود.
        if (preg_match('/((?:با\s+)?(?:اولویت\s+)?غیر[\s\x{200C}]*فوری)(?![\p{L}])/u', $text, $m)) {
            return ['priority' => 'low', 'matched_phrases' => [$m[1]]];
        }
        $rules = [
            'urgent' => '/((?:با\s+)?(?:اولویت\s+)?(?:خیلی\s+فوری|فوری|اضطراری|اورژانسی|خیلی\s+مهم|بسیار\s+مهم|حیاتی|آنی))/u',
            'high'   => '/((?:با\s+)?(?:اولویت\s+بالا|اولویت\s+زیاد|خیلی\s+جدی|مهم))/u',
            'low'    => '/((?:با\s+)?(?:اولویت\s+پایین|اولویت\s+کم|کم[\s\x{200C}]*اهمیت|سر\s+فرصت|عجله[\s\x{200C}]*ای\s+نیست|غیر[\s\x{200C}]*فوری))/u',
            'medium' => '/((?:با\s+)?اولویت\s+(?:متوسط|معمولی|عادی))/u',
        ];
        foreach ($rules as $prio => $rx) {
            if (preg_match($rx, $text, $m)) {
                return ['priority' => $prio, 'matched_phrases' => [$m[1]]];
            }
        }
        return ['priority' => 'medium', 'matched_phrases' => []];
    }

    /**
     * تشخیص کارمند مسئول انجام تسک با تطبیق هوشمند نام و نام خانوادگی کارمندان شرکت
     */
    private static function extractAssignee(string $text, array $users, int $defaultUserId): array
    {
        $defaultName = '';
        foreach ($users as $u) {
            if ((int) $u['id'] === $defaultUserId) {
                $defaultName = (string) $u['full_name'];
                break;
            }
        }

        // اگر صراحتاً گفته «برای خودم»
        if (preg_match('/(برای\s+خودم|تسک\s+شخصی(?:\s+خودم)?)/u', $text, $m)) {
            return [
                'user_id'         => $defaultUserId,
                'user_name'       => $defaultName,
                'matched_phrases' => [$m[1]],
            ];
        }

        // ۱. تطبیق نام کامل کارمند (دقیق‌ترین حالت)
        foreach ($users as $u) {
            $full = normalize_persian((string) $u['full_name']);
            if ($full === '') {
                continue;
            }
            $quoted = preg_quote($full, '/');
            $rc = "(?:برای|به|با[\\s\\x{200C}]+|مسئولش[\\s\\x{200C}]*|مسئول[\\s\\x{200C}]+|توسط[\\s\\x{200C}]+|همکار[\\s\\x{200C}]+|واگذار[\\s\\x{200C}]+به[\\s\\x{200C}]+|بسپار[\\s\\x{200C}]+به[\\s\\x{200C}]+)";
            $rx = '/((?:' . $rc . ')?(?:آقای|اقای|خانم|مهندس|دکتر)?[\\s\\x{200C}]*' . $quoted . '(?:[\\s\\x{200C}]+(?:بسپار|بذار|بزار|بده|اختصاص[\\s\\x{200C}]+بده|واگذار[\\s\\x{200C}]+کن))?)/u';
            if (preg_match($rx, $text, $m)) {
                return [
                    'user_id'         => (int) $u['id'],
                    'user_name'       => (string) $u['full_name'],
                    'matched_phrases' => [trim_fa($m[1], '،,؛:')],
                ];
            }
        }

        // ۲. تطبیق نام کوچک یا نام خانوادگی همراه با حروف اضافه («برای سارا»، «آقای حسینی»، «به علی بسپار»)
        foreach ($users as $u) {
            $full = normalize_persian((string) $u['full_name']);
            $parts = array_values(array_filter(
                preg_split('/\s+/u', $full) ?: [],
                static fn(string $p): bool => mb_strlen($p, 'UTF-8') >= 3
            ));
            foreach ($parts as $part) {
                $qp = preg_quote($part, '/');
                $rc = "(?:برای|به|با|مسئولش[\\s\\x{200C}]*|مسئول|توسط|همکار|واگذار[\\s\\x{200C}]+به)";
                $rx = '/((?:(?:' . $rc . ')[\\s\\x{200C}]+(?:آقای|اقای|خانم|مهندس|دکتر)?[\\s\\x{200C}]*' . $qp . '|(?:آقای|اقای|خانم|مهندس)[\\s\\x{200C}]+' . $qp . '))(?![\\p{L}])/u';
                if (preg_match($rx, $text, $m)) {
                    return [
                        'user_id'         => (int) $u['id'],
                        'user_name'       => (string) $u['full_name'],
                        'matched_phrases' => [trim_fa($m[1], '،,؛:')],
                    ];
                }
            }
        }

        // ۳. نام کوچک بدون حرف اضافه («فردا سارا» / «فردا جلسه سارا»)
        //    فقط وقتی که با حرف اضافه تشخیص داده نشده باشد.
        foreach ($users as $u) {
            $full = normalize_persian((string) $u['full_name']);
            $parts = array_values(array_filter(
                preg_split('/\s+/u', $full) ?: [],
                static fn(string $p): bool => mb_strlen($p, 'UTF-8') >= 3
            ));
            foreach ($parts as $part) {
                $qp = preg_quote($part, '/');
                // نام باید «کلمهٔ مستقل» باشد، نه بخشی از کلمهٔ دیگر
                if (preg_match('/(?<![\p{L}\x{200C}])' . $qp . '(?![\p{L}\x{200C}])/u', $text, $m)) {
                    return [
                        'user_id'         => (int) $u['id'],
                        'user_name'       => (string) $u['full_name'],
                        'matched_phrases' => [$m[0]],
                    ];
                }
            }
        }

        return [
            'user_id'         => $defaultUserId,
            'user_name'       => $defaultName,
            'matched_phrases' => [],
        ];
    }

    /**
     * تشخیص پروژه مرتبط از نام پروژه‌های فعال شرکت
     */
    private static function extractProject(string $text, array $projects): array
    {
        // ابتدا تطبیق کامل نام پروژه
        foreach ($projects as $p) {
            $pName = normalize_persian((string) $p['name']);
            if ($pName === '') {
                continue;
            }
            $qp = preg_quote($pName, '/');
            if (preg_match('/((?:در|برای|واسه|واسه)?\s*(?:پروژه|پروژهٔ|طرح)\s*' . $qp . '|' . $qp . ')/u', $text, $m)) {
                return [
                    'project_id'      => (int) $p['id'],
                    'project_name'    => (string) $p['name'],
                    'matched_phrases' => [trim_fa($m[1], '،,؛:')],
                ];
            }
        }
        // سپس تطبیق نسبی: اگر بخش قابل‌توجهی از کلمات نام پروژه در متن آمده باشد
        $bestId = null;
        $bestName = '';
        $bestPhrase = '';
        $bestScore = 0;
        $normText = ' ' . normalize_persian($text) . ' ';
        foreach ($projects as $p) {
            $pName = normalize_persian((string) $p['name']);
            $words = array_values(array_filter(preg_split('/[\s\x{200C}]+/u', $pName) ?: [], static fn(string $w): bool => mb_strlen($w, 'UTF-8') >= 3));
            if (count($words) < 2) {
                continue;
            }
            // جستجوی بلندترین دنباله پیوسته از کلمات نام پروژه در متن
            $found = '';
            for ($len = count($words); $len >= 2; $len--) {
                for ($start = 0; $start + $len <= count($words); $start++) {
                    $chunk = implode(' ', array_slice($words, $start, $len));
                    if (mb_strpos($normText, ' ' . $chunk) !== false || mb_strpos($normText, $chunk . ' ') !== false) {
                        $found = $chunk;
                        break 2;
                    }
                }
            }
            if ($found === '') {
                continue;
            }
            $score = mb_strlen($found, 'UTF-8');
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestId = (int) $p['id'];
                $bestName = (string) $p['name'];
                $bestPhrase = $found;
            }
        }
        if ($bestId !== null) {
            // تطبیق فازی تنها فیلد «پروژه» را پر می‌کند؛ اگر از عنوان حذف شود
            // موضوع اصلی تسک هم می‌رود («باید گزارش مالی رو بفرستم» → «رو بفرستم»).
            $isExplicit = (bool) preg_match('/(?:پروژه|طرح)\s*' . preg_quote($bestPhrase, '/') . '/u', (string) $normText);
            $stripPhrase = $isExplicit ? $bestPhrase : '';
            return [
                'project_id'      => $bestId,
                'project_name'    => $bestName,
                'matched_phrases' => $stripPhrase !== '' ? [$stripPhrase] : [],
            ];
        }
        return ['project_id' => null, 'project_name' => '', 'matched_phrases' => []];
    }

    /**
     * استخراج زیرتسک‌های صریح گفته‌شده (مثلاً «شامل طراحی فرم، تست و تحویل»)
     */
    private static function extractSubtasks(string $text): array
    {
        if (preg_match('/((?:شامل|مواردش|زیرتسک[\s\x{200C}]*هاش)\s*:?\s*(.+?))(?:\s*(?:رو|را)?\s*(?:ثبت|ایجاد|اضافه|بذار|بزار)\s*کن|$)/u', $text, $m)) {
            $rawItems = preg_split('/\s*(?:،|,|و\s+(?=.{4,}))\s*/u', trim_fa($m[2], '.،؛')) ?: [];
            $items = [];
            foreach ($rawItems as $it) {
                $clean = trim_fa($it, ".،؛:«»\"'");
                if (mb_strlen($clean, 'UTF-8') >= 3 && mb_strlen($clean, 'UTF-8') <= 140) {
                    $items[] = $clean;
                }
            }
            if (count($items) >= 2) {
                return ['items' => array_slice($items, 0, 6), 'matched_phrase' => $m[1]];
            }
        }
        return ['items' => [], 'matched_phrase' => ''];
    }

    /**
     * استخراج عنوان تمیز و دقیق موضوع تسک
     */
    private static function extractCleanTitle(string $originalText, string $strippedText, array $knownNames = []): string
    {
        // حالت ۱: کاربر صراحتاً گفته «با موضوع ...» یا «با عنوان ...»
        if (preg_match('/(?:با\s+موضوع|با\s+عنوان|تحت\s+عنوان|به\s+نام)\s*[«"\']?(.+?)[»"\']?(?:\s+(?:برای|به|در\s+روز|در\s+تاریخ|ساعت|با\s+اولویت|رو\s+ثبت|را\s+ثبت|ثبت\s+کن|ایجاد\s+کن|بذار|بزار|بساز|اضافه\s+کن)|$)/u', $originalText, $m)) {
            // ⚠️ در این حالت هم باید کلمات زمانی/دستوری از عنوان حذف شوند،
            // وگرنه عنوانی مثل «بازبینی قراردادهای سال آینده فردا» ساخته می‌شود.
            $candidate = self::stripTimeAndFiller($m[1]);
            if (mb_strlen($candidate, 'UTF-8') >= 3) {
                return mb_substr($candidate, 0, 180, 'UTF-8');
            }
        }

        // حالت ۲: پاکسازی افعال دستوری و عبارات اضافه از جمله باقی‌مانده
        $t = $strippedText;

        // حذف نام کارمندی که به‌عنوان «مسئول» تشخیص داده شده اما در عنوان مانده
        // («هماهنگی با سارا محمدی و علی رضایی برای دمو» → «هماهنگی برای دمو»)
        if (!empty($knownNames)) {
            // نام همهٔ کارمندانی که در متن آمده‌اند حذف می‌شود (نه فقط مسئول اصلی)
            // «هماهنگی با سارا محمدی و علی رضایی برای دمو» → «هماهنگی برای دمو»
            foreach ($knownNames as $nm) {
                $nm = trim((string) $nm);
                if ($nm === '') {
                    continue;
                }
                $full = preg_quote($nm, '/');
                $t = preg_replace('/\s*(?:و|با|از|برای|به)?\s*' . $full . '\s*/u', ' ', $t) ?? $t;
            }
            $t = preg_replace('/\s+/u', ' ', trim($t)) ?? $t;
            // پاک‌سازی «و»/«با» سرگردان در ابتدا/انتهای عبارت
            // («فردا با سارا محمدی» → «فردا با» → «فردا»)
            $t = preg_replace('/^(?:و|با)\s+/u', '', $t) ?? $t;
            $t = preg_replace('/\s+(?:و|با)\s*$/u', '', $t) ?? $t;
            // حرف اضافهٔ سرگردان در انتها (وقتی فقط نام حذف شده و موضوعی نمانده)
            // («فردا برای» / «فردا به» → «فردا»)
            $t = preg_replace('/\s+(?:برای|به|با|از|توسط)\s*$/u', '', $t) ?? $t;
            $t = preg_replace('/^(?:برای|به)\s+/u', '', $t) ?? $t;
            // «و» سرگردان در وسط («جلسه و با» → «جلسه»)
            $t = preg_replace('/\s+(?:و|با)\s+(?=$)/u', '', $t) ?? $t;
            $t = preg_replace('/\s{2,}/u', ' ', $t) ?? $t;
            $t = trim($t);
        }

        // حذف عبارات زمانی نسبی که در فیلد تاریخ ثبت شده‌اند ولی از متن حذف نشده‌اند
        // (مثل «هفته آینده»، «ماه بعد»، «آخر هفته»، «دو هفته دیگر»)
        $timeRefPatterns = [
            '/\b(?:این\s+هفته|هفته\s+آینده|هفته\s+بعد|هفته\s+گذشته|هفته\s+پیش|آخر\s+هفته|اول\s+هفته)\b\s*/u',
            '/\b(?:این\s+ماه|ماه\s+آینده|ماه\s+بعد|ماه\s+گذشته|ماه\s+پیش)\b\s*/u',
            '/\b(?:این\s+سال|سال\s+آینده|سال\s+بعد|سال\s+گذشته)\b\s*/u',
            '/\b(?:دو|سه|چهار|پنج|شش|هفت|ده|چند)\s+(?:هفته|ماه|سال|روز)\s+(?:آینده|بعد|دیگر|پیش)\b\s*/u',
            // «تا اول ماه بعد»، «تا آخر هفته»، «تا پایان ماه» — کل عبارت زمان‌دار حذف شود
            '/\b(?:تا|برای)\s+(?:اول|ابتدای|آخر|اخر|پایان|وسط|اواسط)\s+(?:همین\s+|این\s+)?(?:هفته|ماه|سال|ماه\s*(?:بعد|آینده)|هفته\s*(?:بعد|آینده))\b\s*/u',
            '/\b(?:اول|ابتدای|آخر|اخر|پایان|وسط|اواسط)\s+(?:همین\s+|این\s+)?(?:هفته|ماه|سال)(?:\s*(?:بعد|آینده|جاری|جدید))?\b\s*/u',
        ];
        foreach ($timeRefPatterns as $rx) {
            $t = preg_replace($rx, ' ', $t) ?? $t;
        }
        $t = preg_replace('/\s+/u', ' ', trim($t)) ?? $t;

        // حذف عبارات آغازین محاوره‌ای و رسمی
        $leadingPatterns = [
            '/^(?:سلام|وقت\s+بخیر|خسته\s+نباشید|لطفاً|لطفا|بی[\s\x{200C}]*زحمت|ممنون)\s*[،,]?\s*/u',
            '/^(?:یادم\s+بنداز|یادم\s+بیار|یادآوری\s+کن|یادداشت\s+کن)(?:\s+که)?\s+/u',
            '/^(?:می[\s\x{200C}]*خوام|میخوام|برام|برای\s+من)\s+/u',
            '/^(?:یه|یک)?\s*(?:تسک|کار|وظیفه|برنامه|یادآور)(?:\s+جدید)?\s*(?:برام|برای\s+من|بهم)?\s*(?:بساز|بذار|بزار|ثبت\s+کن|ایجاد\s+کن|تعریف\s+کن|اضافه\s+کن|بسازید)?(?:\s+که|\s+برای|\s+در\s+مورد|\s+با\s+موضوع|\s+با\s+عنوان|:)?\s+/u',
            // «سه تسک: ...» / «۲ کار، ...» — شمارندهٔ ابتدای جمله
            '/^(?:سه|دو|چهار|پنج|شش|هفت|هشت|نه|ده|چند|یک|یه|[\d\x{06F0}-\x{06F9}]+)\s*(?:تا\s+)?(?:تسک|کار|وظیفه|یادآور)(?:ها|های)?(?:\s+(?:جدید|دیگه|دیگر))?\s*[:،,]?\s*/u',
            '/^(?:برام|برای\s+من|بهم)?\s*(?:بساز|بذار|بزار|ثبت\s+کن|ایجاد\s+کن)(?:\s+که)?\s+/u',
            '/^(?:با\s+موضوع|با\s+عنوان|موضوعش|موضوع)\s+/u',
            '/^(?:باید|لازمه|قراره)(?:\s+که)?\s+/u',
            '/^(?:صبح|عصر|ظهر|شب|اول\s+صبح|آخر\s+شب)\s+/u',
            // «ظهر» چسبیده به انتهای عنوان («... سرور ظهر»)
            '/\s+(?:صبح|عصر|ظهر|شب)\s*$/u',
            // حذف «این کار» / «این کار رو» که موضوع واقعی نیست
            '/^(?:این\s+)?(?:کار|تسک|وظیفه)(?:\s+(?:رو|را))?\s+/u',
        ];
        for ($i = 0; $i < 3; $i++) {
            foreach ($leadingPatterns as $rx) {
                $t = preg_replace($rx, '', trim($t)) ?? $t;
            }
        }

        // «... رو بفرستم» / «... رو بفرست» / «... را انجام بدم» → «...»
        // ⚠️ حذف «رو/را» فقط وقتی که فعل صریحاً بعدش آمده باشد (یک فاصله حفظ می‌شود).
        $t = preg_replace('/\s+(?:رو|را)\s+(?=(?:بفرستم|بفرست|بفرستید|انجام\s+بدم|انجام\s+بدهم|بدم|بده|بدید|تحویل\s+بدم|تحویل\s+بده|بفرستیم|ارسال\s+کنم|ارسال\s+کن)\b)/u', ' ', $t) ?? $t;
        $t = preg_replace('/\s+/u', ' ', trim($t)) ?? $t;

        // حذف افعال پایانی دستوری
        $trailingPatterns = [
            '/\s+(?:رو|را)?\s*(?:یه\s+تسک|تسک|کار|یادآور)?\s*(?:ثبت\s+کن(?:ید)?|ایجاد\s+کن(?:ید)?|بساز(?:ید)?|اضافه\s+کن(?:ید)?|تعریف\s+کن(?:ید)?|قرار\s+بده|قرار\s+بدید|بذار(?:ید)?|بزار(?:ید)?|تنظیم\s+کن(?:ید)?|بنویس(?:ید)?|یادداشت\s+کن(?:ید)?|بسپار(?:ید)?|واگذار\s+کن(?:ید)?|انجام\s+بدم|انجام\s+بده|انجام\s+بدیم|انجام\s+شود|انجام\s+بشه|باشه|است|هست)\s*[.؟!،]*$/u',
            '/\s+(?:رو|را|هم|که|در|به|برای|با|تا)\s*$/u',
        ];
        for ($i = 0; $i < 3; $i++) {
            foreach ($trailingPatterns as $rx) {
                $t = preg_replace($rx, '', trim($t)) ?? $t;
            }
        }

        $t = self::polishTitle($t);

        if (mb_strlen($t, 'UTF-8') < 3) {
            // ⚠️ fallback نباید به «متن خام» برگردد: متن خام نام کارمند
            // («سارا محمدی») و کلمهٔ تاریخ («فردا») را هم دارد و عنوانی مثل
            // «فردا با سارا محمدی» می‌سازد که موضوع تسک نیست.
            $t = self::polishTitle($strippedText);
        }
        if (mb_strlen($t, 'UTF-8') < 3 && !empty($knownNames)) {
            // آخرین تلاش: متن خام بدون نام کارمندان و بدون زمان
            $withoutNames = $originalText;
            foreach ($knownNames as $nm) {
                $full = preg_quote(trim((string) $nm), '/');
                $withoutNames = preg_replace('/\s*(?:و|با|از|برای|به)?\s*' . $full . '\s*/u', ' ', $withoutNames) ?? $withoutNames;
            }
            $t = self::stripTimeAndFiller($withoutNames);
        }

        // اگر هنوز موضوعی نداریم، عنوان نساز — بگذار لایهٔ بالاتر پیام روشن بدهد
        // (بهتر از تسکی با عنوان «تسک صوتی جدید» که کاربر باید پاکش کند)
        if (mb_strlen(self::polishTitle($t), 'UTF-8') < 3) {
            return '';
        }

        return mb_substr($t, 0, 180, 'UTF-8');
    }

    /**
     * حذف عبارات زمانی و پرکننده‌های گفتاری از یک متن عنوان
     * (هم در مسیر عادی و هم در مسیر «با موضوع ...» استفاده می‌شود)
     */
    private static function stripTimeAndFiller(string $t): string
    {
        $t = (string) $t;
        $patterns = [
            '/\b(?:این\s+هفته|هفته\s+آینده|هفته\s+بعد|هفته\s+گذشته|هفته\s+پیش|آخر\s+هفته|اول\s+هفته)\b\s*/u',
            '/\b(?:این\s+ماه|ماه\s+آینده|ماه\s+بعد|ماه\s+گذشته|ماه\s+پیش)\b\s*/u',
            '/\b(?:این\s+سال|سال\s+آینده|سال\s+بعد|سال\s+گذشته)\b\s*/u',
            '/\b(?:دو|سه|چهار|پنج|شش|هفت|ده|چند)\s+(?:هفته|ماه|سال|روز)\s+(?:آینده|بعد|دیگر|پیش)\b\s*/u',
            '/\b(?:تا|برای)\s+(?:اول|ابتدای|آخر|اخر|پایان|وسط|اواسط)\s+(?:همین\s+|این\s+)?(?:هفته|ماه|سال|ماه\s*(?:بعد|آینده)|هفته\s*(?:بعد|آینده))\b\s*/u',
            '/\b(?:اول|ابتدای|آخر|اخر|پایان|وسط|اواسط)\s+(?:همین\s+|این\s+)?(?:هفته|ماه|سال)(?:\s*(?:بعد|آینده|جاری|جدید))?\b\s*/u',
            '/\s*\b(?:فردا|امروز|امشب|پس\s*فردا|پس‌فردا)\b\s*$/u',
            '/\s*\b(?:شنبه|یکشنبه|یک\s+شنبه|دوشنبه|دو\s+شنبه|سه‌شنبه|سه\s+شنبه|چهارشنبه|چهار\s+شنبه|پنجشنبه|پنج\s+شنبه|جمعه)\b\s*$/u',
        ];
        foreach ($patterns as $rx) {
            $t = preg_replace($rx, ' ', $t) ?? $t;
        }
        $t = preg_replace('/\s+/u', ' ', trim($t)) ?? $t;
        return self::polishTitle($t);
    }

    private static function polishTitle(string $s): string
    {
        $s = preg_replace('/\s+/u', ' ', (string) $s) ?? (string) $s;
        $s = preg_replace('/\s*(?:،|,|؛|;)\s*(?:،|,|؛|;)\s*/u', '، ', $s) ?? $s;
        for ($i = 0; $i < 4; $i++) {
            $before = $s;
            $s = trim_fa($s, ".،,؛:«»\"'()-‌");
            // ⚠️ فقط فاصله معمولی جداکننده است؛ نیم‌فاصله بخشی از خود کلمه است
            // («به‌روزرسانی» نباید به «روزرسانی» تبدیل شود).
            $s = preg_replace('/^(?:که|و|برای\s+اینکه|در\s+تاریخ|در\s+روز|روز|با|به|در)[\s]+/u', '', $s) ?? $s;
            $s = preg_replace('/[\s]+(?:رو|را|و|به|در|از|با|تا)$/u', '', $s) ?? $s;
            // حذف اشاره‌های انتهایی به پروژه/دسته که در فیلد خودشان ثبت شده‌اند.
            // ⚠️ باید فقط تا اولین «حرف اضافه»ی بعدی برود، وگرنه کل عنوان را می‌بلعد
            // (مثلاً «ارائه پروژه به مدیرعامل» → «ارائه»).
            $s = preg_replace('/[\s\x{200C}]+(?:برای|در|واسه)?[\s\x{200C}]*(?:پروژه|طرح)(?:[\s\x{200C}]+(?!به|برای|با|تا|در|از|رو|را)[^\s،,؛]+){1,4}[\s\x{200C}]*$/u', '', $s) ?? $s;
            $s = trim_fa($s, ".،,؛:«»\"'()-‌");
            if ($s === $before) {
                break;
            }
        }
        $s = preg_replace('/\s{2,}/u', ' ', $s) ?? $s;
        return fa_digits(trim_fa($s, ".،,؛:"));
    }

    /**
     * تشخیص هوشمند دسته‌بندی و برچسب‌ها بر اساس موضوع تسک
     * @return array{0:string, 1:string[]}
     */
    private static function inferCategoryAndTags(string $text): array
    {
        $map = [
            'جلسه و هماهنگی' => ['جلسه', 'میتینگ', 'هماهنگی', 'مذاکره', 'معارفه', 'نشست'],
            'مالی و حسابداری' => ['فاکتور', 'پیش‌فاکتور', 'پیش فاکتور', 'حسابداری', 'مالی', 'بیمه', 'مالیات', 'حقوق', 'پرداخت', 'واریز', 'تسویه', 'چک', 'بانک'],
            'فروش و قرارداد'  => ['قرارداد', 'مشتری', 'فروش', 'بازاریابی', 'تبلیغات', 'کمپین', 'پیشنهاد قیمت', 'دمو'],
            'فنی و توسعه'     => ['سایت', 'سرور', 'برنامه', 'کدنویسی', 'دیتابیس', 'باگ', 'آپدیت', 'تست', 'طراحی', 'اپلیکیشن', 'پورتال', 'بکاپ', 'امنیت', 'api'],
            'گزارش و مستندات' => ['گزارش', 'مستند', 'اکسل', 'فایل', 'ارائه', 'پاورپوینت', 'تحلیل', 'آمار'],
            'پشتیبانی و تماس' => ['تماس', 'تلفن', 'زنگ', 'پیگیری', 'پشتیبانی', 'تیکت', 'پاسخگویی', 'ارسال', 'ایمیل'],
            'اداری و تدارکات' => ['خرید', 'سفارش', 'اداری', 'نامه', 'مرخصی', 'استخدام', 'مصاحبه', 'انبار', 'ارسال بسته'],
        ];

        $detectedCategory = 'عمومی';
        $tags = ['ویس هوشمند'];

        foreach ($map as $cat => $keywords) {
            foreach ($keywords as $kw) {
                if (mb_strpos($text, $kw, 0, 'UTF-8') !== false) {
                    if ($detectedCategory === 'عمومی') {
                        $detectedCategory = $cat;
                    }
                    if (count($tags) < 4 && !in_array($kw, $tags, true)) {
                        $tags[] = $kw;
                    }
                }
            }
        }

        return [$detectedCategory, $tags];
    }

    /**
     * پیشنهاد هوشمند زیرتسک متناسب با موضوع تسک
     * @return string[]
     */
    public static function suggestSubtasks(string $title, string $category = ''): array
    {
        $combined = normalize_persian($title . ' ' . $category);

        if (str_contains($combined, 'جلسه') || str_contains($combined, 'مذاکره')) {
            return [
                'هماهنگی زمان و اطلاع‌رسانی به شرکت‌کنندگان',
                'آماده‌سازی دستور جلسه و مستندات لازم',
                'ثبت صورت‌جلسه و پیگیری مصوبات',
            ];
        }
        if (str_contains($combined, 'قرارداد')) {
            return [
                'آماده‌سازی و بازبینی بندهای پیش‌نویس قرارداد',
                'بررسی شرایط مالی و زمان‌بندی تحویل',
                'اخذ تأیید نهایی و بایگانی نسخه امضاشده',
            ];
        }
        if (str_contains($combined, 'فاکتور') || str_contains($combined, 'مالی') || str_contains($combined, 'بیمه') || str_contains($combined, 'پرداخت')) {
            return [
                'بررسی و تطبیق مبالغ و مدارک مثبته',
                'ثبت سند در سیستم مالی و اخذ تأیید مدیریت',
                'ارسال رسید نهایی و بایگانی مستندات',
            ];
        }
        if (str_contains($combined, 'گزارش') || str_contains($combined, 'تحلیل')) {
            return [
                'جمع‌آوری داده‌ها و آمار دوره موردنظر',
                'تنظیم و دسته‌بندی گزارش نهایی',
                'ارائه گزارش به مدیر و ثبت بازخوردها',
            ];
        }
        if (str_contains($combined, 'سایت') || str_contains($combined, 'باگ') || str_contains($combined, 'فنی') || str_contains($combined, 'طراحی') || str_contains($combined, 'کد')) {
            return [
                'بررسی نیازمندی و طراحی راه‌حل فنی',
                'پیاده‌سازی و انجام تست عملکردی',
                'تحویل نهایی و ثبت مستندات تغییرات',
            ];
        }
        if (str_contains($combined, 'تماس') || str_contains($combined, 'پیگیری') || str_contains($combined, 'مشتری')) {
            return [
                'بررسی سوابق قبلی و آماده‌سازی نکات تماس',
                'برقراری تماس و ثبت نتیجه گفتگو',
                'تعیین گام بعدی پیگیری در پنل',
            ];
        }
        return [
            'بررسی جزئیات و آماده‌سازی مقدمات انجام کار',
            'اجرای کامل تسک طبق زمان‌بندی تعیین‌شده',
            'کنترل نهایی و ثبت نتیجه انجام در سامانه',
        ];
    }
}
