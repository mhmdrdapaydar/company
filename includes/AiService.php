<?php
declare(strict_types=1);

/**
 * سرویس یکپارچه هوش مصنوعی رایگان برای پردازش ویس (صدا) و ایجاد هوشمند تسک
 *
 * زنجیره تشخیص گفتار (STT) — همه لایه‌ها رایگان:
 *  ۱) Groq Whisper Large v3 Turbo  ← سریع‌ترین و دقیق‌ترین (کلید رایگان Groq)
 *  ۲) Google Gemini 2.5 Flash      ← صدا را مستقیم می‌فهمد (کلید رایگان AI Studio)
 *  ۳) Pollinations Whisper         ← بدون هیچ کلید (پیش‌فرض صوتی رایگان)
 *  ۴) Puter.js در مرورگر           ← بدون کلید، اگر سرور به بیرون دسترسی نداشته باشد
 *  ۵) Web Speech API مرورگر (fa-IR) ← آنی و رایگان
 * سپس متن استخراج‌شده با Groq Llama / Pollinations / موتور NLP فارسی داخلی به تسک تبدیل می‌شود.
 */
final class AiService
{
    /** کش نتیجهٔ بررسی اتصال بین‌المللی در همین درخواست */
    private static ?bool $intlReachable = null;

    /**
     * آیا سرور به سرویس‌های بین‌المللی (Groq/Gemini/Pollinations) دسترسی دارد؟
     *
     * 🔌 این تابع قلب «حالت ایران/آفلاین» است: اگر اینترنت بین‌الملل قطع باشد،
     * به‌جای منتظر ماندن ۴۵ ثانیه‌ای برای هر سرویس، در کمتر از ۲ ثانیه
     * تشخیص می‌دهیم و مستقیم سراغ موتور فارسی داخلی می‌رویم.
     */
    public static function internationalReachable(): bool
    {
        if (self::$intlReachable !== null) {
            return self::$intlReachable;
        }
        // اگر مدیر صریحاً حالت آفلاین را روشن کرده باشد، هیچ تلاشی نمی‌کنیم
        if (get_setting('ai_offline_mode', '0') === '1') {
            self::$intlReachable = false;
            return false;
        }
        if (!function_exists('curl_init')) {
            self::$intlReachable = false;
            return false;
        }
        // پروب سبک: فقط برقراری اتصال TCP/TLS (بدون دانلود محتوا)
        $ch = curl_init('https://api.groq.com/');
        curl_setopt_array($ch, [
            CURLOPT_NOBODY         => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 2,       // کوتاه: کاربر نباید معطل بماند
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        // هر پاسخ HTTP (حتی 401/403/404) یعنی «اینترنت بین‌الملل وصل است».
        // خطای شبکه/SSL یعنی قطع است.
        self::$intlReachable = ($code > 0) || ($err === '');
        return self::$intlReachable;
    }

    /**
     * پردازش کامل ویس و/یا متن گفتار و بازگرداندن تسک‌های استخراج‌شده با تاریخ دقیق شمسی
     */
    public static function processVoiceCommand(
        ?array $savedVoice,
        string $browserTranscript,
        array $assignableUsers,
        array $projects,
        int $currentUserId
    ): array {
        $preferred = strtolower(trim(get_setting('ai_provider', AI_PROVIDER)));
        $groqKey   = trim(get_setting('groq_api_key', GROQ_API_KEY));
        $geminiKey = trim(get_setting('gemini_api_key', GEMINI_API_KEY));

        $transcript = normalize_persian($browserTranscript);
        $usedEngine = '';
        $notes      = [];

        // 🔌 حالت ایران/آفلاین: اگر اینترنت بین‌الملل قطع است،
        // هیچ سرویس ابری امتحان نمی‌شود (نه Groq، نه Gemini، نه Pollinations).
        $online = self::internationalReachable();
        if (!$online) {
            $notes[] = 'حالت آفلاین: سرویس‌های بین‌المللی در دسترس نیستند؛ موتور فارسی داخلی استفاده می‌شود.';
            // اگر هیچ متنی نداریم و فقط صدا داریم، نمی‌توانیم آنلاین تبدیل کنیم
            if ($transcript === '' && $savedVoice !== null) {
                $notes[] = 'برای تبدیل صدا به متن بدون اینترنت: از دکمهٔ «🎧 تشخیص گفتار در مرورگر» استفاده کنید یا متن را دستی بنویسید.';
                throw new RuntimeException(implode("\n", $notes));
            }
        }


        // گام ۲: اگر کلید رایگان Google Gemini فعال است (می‌تواند مستقیم فایل صوتی فارسی را بدون نیاز به متن هم بفهمد!)
        if ($online
            && in_array($preferred, ['auto', 'gemini'], true)
            && $geminiKey !== ''
            && ($groqKey === '' || $preferred === 'gemini')
            && ($transcript !== '' || ($savedVoice !== null && is_file($savedVoice['full_path'] ?? '')))
        ) {
            try {
                $gemResult = self::extractWithGeminiFree(
                    $savedVoice,
                    $transcript,
                    $assignableUsers,
                    $projects,
                    $currentUserId,
                    $geminiKey
                );
                if (!empty($gemResult['tasks'])) {
                    return [
                        'transcript' => $gemResult['transcript'] !== '' ? $gemResult['transcript'] : $transcript,
                        'provider'   => 'Google Gemini Flash (نسخه رایگان)',
                        'tasks'      => self::normalizeAiTasks($gemResult['tasks'], $transcript, $assignableUsers, $projects, $currentUserId),
                        'notes'      => $notes,
                    ];
                }
            } catch (Throwable $e) {
                $notes[] = 'Gemini: ' . $e->getMessage();
            }
        }

        $hasAudio = $savedVoice !== null
            && !empty($savedVoice['full_path'])
            && is_file($savedVoice['full_path'])
            && (int) ($savedVoice['size'] ?? 0) > 0;

        // ────────────────────────────────────────────────────────────────
        // مرحله تشخیص گفتار (STT): اگر متن از مرورگر نرسیده باشد، صدا را روی سرور پیاده‌سازی می‌کنیم.
        // ترتیب: Groq → Gemini → Pollinations (بدون کلید) و در آخر گزارش شفاف خطاها
        // ────────────────────────────────────────────────────────────────
        if ($hasAudio && $transcript === '') {
            $audioPath = (string) $savedVoice['full_path'];
            $audioMime = (string) ($savedVoice['mime_type'] ?? 'audio/webm');

            // ۱) Groq Whisper (رایگان، دقیق‌ترین گزینه برای فارسی)
            if ($online && in_array($preferred, ['auto', 'groq', 'pollinations'], true) && $groqKey !== '') {
                try {
                    $t = self::transcribeWithGroqWhisper($audioPath, $audioMime, $groqKey);
                    if ($t !== '') {
                        $transcript = normalize_persian($t);
                        $usedEngine = 'Groq Whisper v3 (رایگان)';
                        $notes[] = 'متن گفتار با Groq Whisper استخراج شد.';
                    }
                } catch (Throwable $e) {
                    $notes[] = 'Groq Whisper ناموفق: ' . $e->getMessage();
                }
            }

            // ۲) Pollinations Whisper — بدون کلید و همیشه در دسترس (اگر Groq کلید نداشت یا خطا داد)
            if ($online && $transcript === '' && in_array($preferred, ['auto', 'pollinations', 'groq'], true)) {
                try {
                    $t = self::transcribeWithPollinationsWhisper($audioPath, $audioMime);
                    if ($t !== '') {
                        $transcript = normalize_persian($t);
                        $usedEngine = 'Pollinations Whisper (رایگان بدون کلید)';
                        $notes[] = 'متن گفتار با Pollinations Whisper استخراج شد.';
                    }
                } catch (Throwable $e) {
                    $notes[] = 'Pollinations Whisper ناموفق: ' . $e->getMessage();
                }
            }

            // ۳) هیچ موتور سروری موفق نشد → پیام فارسی روشن با علت‌های واقعی
            if ($transcript === '') {
                throw new RuntimeException(self::sttFailureMessage($notes));
            }
        }

        // اگر نه سرویس صوتی ابری متنی برگرداند و نه مرورگر متنی ثبت کرد
        if ($transcript === '') {
            throw new RuntimeException(self::sttFailureMessage($notes));
        }

        // گام ۳: تحلیل متن گفتار با Groq Llama 3.3 رایگان (در صورت وجود کلید رایگان)
        if ($online && in_array($preferred, ['auto', 'groq'], true) && $groqKey !== '') {
            try {
                $llmTasks = self::extractWithGroqLlama($transcript, $assignableUsers, $projects, $currentUserId, $groqKey);
                if (!empty($llmTasks)) {
                    return [
                        'transcript' => $transcript,
                        'provider'   => 'Groq Whisper + Llama 3.3 (رایگان)',
                        'tasks'      => self::normalizeAiTasks($llmTasks, $transcript, $assignableUsers, $projects, $currentUserId),
                        'notes'      => $notes,
                    ];
                }
            } catch (Throwable $e) {
                $notes[] = 'Groq LLM: ' . $e->getMessage();
            }
        }

        // گام ۴: سرویس کاملاً رایگان و بدون کلید Pollinations AI
        if ($online && in_array($preferred, ['auto', 'pollinations'], true) && function_exists('curl_init')) {
            try {
                $pollTasks = self::extractWithPollinationsFree($transcript, $assignableUsers, $projects, $currentUserId);
                if (!empty($pollTasks)) {
                    return [
                        'transcript' => $transcript,
                        'provider'   => 'هوش مصنوعی ابری رایگان (Pollinations)',
                        'tasks'      => self::normalizeAiTasks($pollTasks, $transcript, $assignableUsers, $projects, $currentUserId),
                        'notes'      => $notes,
                    ];
                }
            } catch (Throwable $e) {
                $notes[] = 'Pollinations: ' . $e->getMessage();
            }
        }

        // گام ۵: موتور پردازش زبان طبیعی فارسی داخلی (همیشه در دسترس، ۱۰۰٪ رایگان، دقیق و بدون خطا)
        $localTasks = PersianVoiceParser::parseMultiple($transcript, $assignableUsers, $projects, $currentUserId);
        return [
            'transcript' => $transcript,
            'provider'   => $usedEngine !== '' ? ($usedEngine . ' + پردازشگر هوشمند فارسی') : 'دستیار هوش مصنوعی فارسی (رایگان داخلی)',
            'tasks'      => $localTasks,
            'notes'      => $notes,
        ];
    }

    /**
     * ساخت پرامپت دقیق فارسی با جدول تقویم شمسی واقعی امروز، کارمندان و پروژه‌ها
     */
    private static function buildSystemPrompt(array $users, array $projects, int $currentUserId): string
    {
        $todayG = today_date();
        $todayJ = Jalali::toDateString($todayG, false);
        $todayH = Jalali::toHuman($todayG, true, true);
        $nowTime = date('H:i');

        $scheduleLines = [];
        foreach (Jalali::upcomingDaysSchedule(14) as $d) {
            $scheduleLines[] = "- {$d['relative']} = {$d['weekday']} {$d['jalali']} (میلادی: {$d['gregorian']})";
        }
        $scheduleText = implode("\n", $scheduleLines);

        $userLines = [];
        foreach ($users as $u) {
            $mark = ((int) $u['id'] === $currentUserId) ? ' [گوینده فعلی / پیش‌فرض]' : '';
            $userLines[] = "- id={$u['id']} | نام: {$u['full_name']} | سمت: {$u['position_title']}{$mark}";
        }
        $usersText = implode("\n", $userLines);

        $projLines = [];
        foreach ($projects as $p) {
            $projLines[] = "- id={$p['id']} | پروژه: {$p['name']}";
        }
        $projectsText = empty($projLines) ? '- بدون پروژه' : implode("\n", $projLines);

        return <<<PROMPT
تو دستیار هوشمند مدیریت تسک یک شرکت ایرانی هستی. پیام صوتی کاربر به زبان فارسی را دقیق تحلیل کن و تسک(ها) را استخراج کن.

اطلاعات زمانی دقیق امروز (منطقه زمانی تهران):
- امروز: {$todayH} برابر با {$todayJ} شمسی (میلادی: {$todayG}) - ساعت فعلی: {$nowTime}
- جدول دقیق ۱۴ روز آینده برای تبدیل بی‌نقص روزهای هفته و عبارات نسبی:
{$scheduleText}

کارمندان مجاز برای واگذاری تسک:
{$usersText}

پروژه‌های فعال شرکت:
{$projectsText}

خروجی را فقط و فقط به صورت JSON معتبر (بدون هیچ توضیح اضافه یا markdown) با این ساختار برگردان:
{
  "transcript": "متن دقیق گفتار فارسی",
  "tasks": [
    {
      "title": "عنوان کوتاه، تمیز و رسمی تسک به فارسی (بدون کلمات اضافی مثل ثبت کن یا یادم بنداز)",
      "description": "توضیح کامل و مرتب تسک به فارسی",
      "due_date_jalali": "YYYY/MM/DD به شمسی با ارقام انگلیسی",
      "due_date": "YYYY-MM-DD به میلادی",
      "due_time": "HH:MM با فرمت ۲۴ ساعته یا null",
      "priority": "low | medium | high | urgent",
      "assigned_to": {$currentUserId},
      "project_id": null,
      "estimated_minutes": 60,
      "recurrence": "none | daily | weekly | monthly",
      "category": "دسته‌بندی فارسی کوتاه",
      "tags": ["برچسب۱", "برچسب۲"],
      "subtasks": ["زیرتسک پیشنهادی ۱", "زیرتسک پیشنهادی ۲", "زیرتسک پیشنهادی ۳"]
    }
  ]
}
قواعد الزامی (دقیقاً رعایت کن):
۱) عنوان باید فقط «موضوع کار» باشد؛ هیچ کلمه زمانی (فردا، امروز، هفته آینده، ماه بعد، ساعت ۱۰) و هیچ فعل دستوری (بساز، بذار، ثبت کن، یادم بنداز) در عنوان نیاید. عنوان باید مستقل و قابل‌فهم باشد، نه یک کلمه ناقص.
   نمونه درست: «ارسال گزارش مالی ماهانه» / نمونه غلط: «ارسال» یا «هفته آینده ارسال».
۲) «هفته آینده پنجشنبه» یعنی پنجشنبهٔ هفتهٔ بعد (۷ روز بعد از پنجشنبهٔ همین هفته)، نه پنجشنبهٔ جاری.
۳) «تا اول ماه بعد» یعنی روز اول ماه شمسی بعد؛ «آخر هفته» یعنی پنجشنبهٔ پیش رو؛ «آخر ماه» یعنی آخرین روز ماه جاری.
۴) اعداد و ماه‌های حروفی فارسی را دقیق تبدیل کن: «بیست و هشتم آبان» = ۲۸ آبان، «پنجم دی» = ۵ دی.
۵) اگر بازه زمانی گفته شد («تا آخر هفته»، «تا پنجشنبه»)، همان را due_date بگذار.
۶) اگر نام پروژه‌ای در فهرست بالا صریحاً گفته شد، project_id همان پروژه را بگذار؛ در غیر این صورت null. موضوع تسک را حذف نکن حتی اگر شبیه نام پروژه باشد.
۷) اگر نام کارمند خاصی ذکر نشد، assigned_to را برابر {$currentUserId} بگذار. اگر روزی ذکر نشد، تاریخ امروز ({$todayJ}) را قرار بده.
PROMPT;
    }

    /**
     * بررسی سریع دسترسی سرور به یک سرویس بیرونی (برای صفحه عیب‌یابی)
     * فقط کد وضعیت HTTP یا علت خطا را برمی‌گرداند و هیچ داده‌ای نمی‌فرستد.
     */
    public static function pingHost(string $url): array
    {
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'detail' => 'cURL فعال نیست'];
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_NOBODY         => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 8,
            CURLOPT_CONNECTTIMEOUT => 4,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        // روی برخی هاست‌های اشتراکی بسته گواهی CA نصب نیست؛ یک‌بار بدون بررسی گواهی تلاش می‌کنیم
        if ($err !== '' && self::isCertError($err)) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_NOBODY         => true,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 8,
                CURLOPT_CONNECTTIMEOUT => 4,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
            ]);
            curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $err2 = curl_error($ch);
            curl_close($ch);
            if ($err2 === '') {
                return ['ok' => $code > 0, 'status' => $code, 'detail' => $code > 0 ? ('HTTP ' . $code . ' (بدون بررسی گواهی)') : 'بدون پاسخ'];
            }
            $err = $err2;
        }
        if ($err !== '') {
            return ['ok' => false, 'status' => $code, 'detail' => $err];
        }
        return ['ok' => $code > 0, 'status' => $code, 'detail' => $code > 0 ? ('HTTP ' . $code) : 'بدون پاسخ'];
    }

    /**
     * آیا خطای cURL مربوط به نبود/نامعتبر بودن بسته گواهی CA است؟
     * (روی هاست‌های اشتراکی رایج است و ربطی به تنظیمات برنامه ندارد)
     */
    private static function isCertError(string $error): bool
    {
        $e = strtolower($error);
        return str_contains($e, 'certificate')
            || str_contains($e, 'ca bundle')
            || str_contains($e, 'ssl certificate problem')
            || str_contains($e, 'unable to get local issuer');
    }

    /**
     * توصیه متنی برای صفحه تنظیمات: چه چیزی برای «صدا → متن» لازم است
     */
    public static function sttSetupAdvice(bool $hasGroq, bool $hasGemini, array $reach): string
    {
        $parts = [];
        // 🔌 اگر حالت آفلاین/ایران فعال است، پیام شفاف بده
        if (get_setting('ai_offline_mode', '0') === '1' || !self::internationalReachable()) {
            $parts[] = '🔌 حالت آفلاین (سازگار با ایران): هیچ درخواستی به سرویس‌های بین‌المللی فرستاده نمی‌شود.';
            $parts[] = '🧠 همهٔ امکانات برنامه — تسک، کانبان، اعلان، تقویم شمسی و پردازش جملهٔ فارسی (تاریخ، ساعت، مسئول، عنوان) — روی سرور خودتان و بدون اینترنت کار می‌کند.';
            $parts[] = '✍️ تشخیص گفتار → متن: مطمئن‌ترین راه بدون اینترنت، نوشتن متن در کادر است (دکمهٔ «پردازش هوشمند»).';
            $parts[] = '🎧 «تشخیص گفتار مرورگر» رایگان است اما در کروم به سرویس ابری گوگل تکیه دارد و با قطعی اینترنت بین‌الملل ممکن است پاسخ ندهد؛ در آن صورت متن را تایپ کنید.';
            return implode(' ', $parts);
        }
        if ($hasGroq) {
            $parts[] = '✅ کلید Groq تنظیم شده است — دقیق‌ترین و سریع‌ترین حالت تشخیص گفتار فارسی فعال است.';
        } elseif ($hasGemini) {
            $parts[] = '✅ کلید Gemini تنظیم شده است — صدا مستقیم توسط Gemini پردازش می‌شود.';
        } else {
            $parts[] = '⚠️ هیچ کلید رایگانی تنظیم نشده است.';
            $poll = $reach['pollinations']['ok'] ?? false;
            if ($poll) {
                $parts[] = '🔁 سرور به Pollinations دسترسی دارد؛ صدا با Whisper رایگان و بدون کلید تبدیل می‌شود (کندتر از Groq).';
            } else {
                $parts[] = '❌ سرور به سرویس‌های بیرونی دسترسی ندارد؛ صدا را در مرورگر («تبدیل صدا به متن بدون کلید») یا با تایپ متن پردازش کنید.';
            }
            $parts[] = '💡 برای فارسی دقیق‌تر، یک کلید رایگان از console.groq.com بگیرید (بدون کارت بانکی).';
        }
        if (!($reach['groq']['ok'] ?? false) && $hasGroq) {
            $parts[] = '⚠️ کلید Groq ثبت شده اما سرور به api.groq.com دسترسی ندارد: ' . ($reach['groq']['detail'] ?? '—');
        }
        return implode(' ', $parts);
    }

    /**
     * پیام خطای شفاف و راهنما وقتی هیچ موتور تشخیص گفتاری متن تولید نکرد
     * (به‌جای پیام عمومی مبهم، علت هر لایه را نشان می‌دهد)
     */
    public static function sttFailureMessage(array $notes = []): string
    {
        $technical = [];
        foreach ($notes as $note) {
            if (stripos($note, 'ناموفق') !== false) {
                $technical[] = $note;
            }
        }
        $msg = '🎧 صدا به‌درستی ضبط شد، اما هیچ موتور تشخیص گفتاری نتوانست آن را به متن تبدیل کند.';
        $msg .= "\n\nچه کاری انجام دهیم؟";
        $msg .= "\n۱) در کادر «متن فرمان صوتی» همان جمله‌ای را که گفتید تایپ کنید (سریع‌ترین راه).";
        $msg .= "\n۲) یک کلید رایگان Groq از console.groq.com بگیرید و در «تنظیمات ← کلید رایگان Groq» وارد کنید — دقیق‌ترین گزینه برای فارسی.";
        $msg .= "\n۳) یا کلید رایگان Gemini از aistudio.google.com در تنظیمات وارد کنید (صدا را مستقیم می‌فهمد).";
        $msg .= "\n۴) مطمئن شوید سرور به اینترنت دسترسی دارد (اگر پشت فایروال/هاست محلی هستید، سرویس‌های ابری در دسترس نیستند).";
        if (!empty($technical)) {
            $msg .= "\n\nجزئیات فنی: " . implode(' | ', array_slice($technical, 0, 4));
        }
        return $msg;
    }

    /**
     * تشخیص گفتار فارسی با Pollinations Whisper — کاملاً رایگان و بدون کلید API
     * از endpoint سازگار با OpenAI استفاده می‌کند: /v1/audio/transcriptions
     */
    private static function transcribeWithPollinationsWhisper(string $filePath, string $mimeType): string
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('افزونه cURL روی سرور فعال نیست.');
        }
        if (!is_file($filePath)) {
            throw new RuntimeException('فایل صوتی روی سرور پیدا نشد.');
        }

        $endpoints = [
            'https://gen.pollinations.ai/v1/audio/transcriptions',
            'https://text.pollinations.ai/v1/audio/transcriptions',
        ];
        $lastError = '';

        foreach ($endpoints as $url) {
            foreach (['openai/whisper-large-v3', 'whisper-large-v3', 'whisper-1'] as $model) {
                $postFields = [
                    'file'            => new CURLFile($filePath, $mimeType ?: 'audio/webm', basename($filePath)),
                    'model'           => $model,
                    'language'        => 'fa',
                    'response_format' => 'json',
                    'temperature'     => '0',
                ];

                $raw = false;
                $code = 0;
                $err = '';
                foreach ([true, false] as $verify) {
                    $postFields['file'] = new CURLFile($filePath, $mimeType ?: 'audio/webm', basename($filePath));
                    $ch = curl_init($url);
                    curl_setopt_array($ch, [
                        CURLOPT_POST           => true,
                        CURLOPT_POSTFIELDS     => $postFields,
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_TIMEOUT        => 45,
                        CURLOPT_CONNECTTIMEOUT => 10,
                        CURLOPT_FOLLOWLOCATION => true,
                        CURLOPT_USERAGENT      => 'HambkarPlus/2.0',
                        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
                        CURLOPT_SSL_VERIFYPEER => $verify,
                        CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
                    ]);
                    $raw  = curl_exec($ch);
                    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                    $err  = curl_error($ch);
                    curl_close($ch);
                    if (!($raw === false && self::isCertError($err))) {
                        break;
                    }
                }

                if ($raw === false) {
                    $lastError = $err !== '' ? $err : 'اتصال برقرار نشد';
                    continue; // دامنه بعدی را امتحان کن
                }
                if ($code >= 400) {
                    $lastError = "HTTP {$code}";
                    continue;
                }

                $text = '';
                $decoded = json_decode((string) $raw, true);
                if (is_array($decoded)) {
                    $text = trim((string) ($decoded['text'] ?? $decoded['transcription'] ?? ''));
                }
                if ($text === '') {
                    $plain = trim((string) $raw);
                    if ($plain !== '' && !str_starts_with($plain, '{') && !str_starts_with($plain, '<')) {
                        $text = $plain;
                    }
                }
                if ($text !== '') {
                    return $text;
                }
                $lastError = 'پاسخ خالی از سرویس';
            }
        }

        throw new RuntimeException($lastError !== '' ? $lastError : 'سرویس تشخیص گفتار پاسخ نداد');
    }

    /**
     * تبدیل فایل صوتی فارسی به متن با Groq Whisper Large v3 Turbo (رایگان)
     */
    private static function transcribeWithGroqWhisper(string $filePath, string $mimeType, string $apiKey): string
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('افزونه cURL فعال نیست.');
        }

        // پیام راهنما (prompt) دقت فارسی را به‌طور محسوس بالا می‌برد:
        // Whisper از آن هم برای زبان و هم برای واژگان تخصصی (تسک، اولویت، تاریخ شمسی) استفاده می‌کند.
        $promptHint = 'این یک فرمان صوتی فارسی برای ساخت تسک در یک شرکت ایرانی است. '
            . 'واژه‌های رایج: تسک، جلسه، گزارش، اولویت فوری، اولویت بالا، فردا، پس‌فردا، '
            . 'هفته آینده، شنبه، یکشنبه، دوشنبه، سه‌شنبه، چهارشنبه، پنجشنبه، جمعه، '
            . 'فروردین، اردیبهشت، خرداد، تیر، مرداد، شهریور، مهر، آبان، آذر، دی، بهمن، اسفند.';

        // مدل‌ها به ترتیب کیفیت — اگر یکی در دسترس نبود، بعدی امتحان می‌شود
        $models   = ['whisper-large-v3', 'whisper-large-v3-turbo'];
        $lastErr  = '';

        foreach ($models as $model) {
            $postFields = [
                'model'           => $model,
                'language'        => 'fa',
                'response_format' => 'json',
                'temperature'     => '0',
                'prompt'          => $promptHint,
            ];

            $raw = false;
            $code = 0;
            $err = '';
            // دو تلاش: اول با بررسی کامل گواهی، و اگر بسته CA روی هاست نبود، بدون بررسی گواهی
            foreach ([true, false] as $verify) {
                $postFields['file'] = new CURLFile($filePath, $mimeType ?: 'audio/webm', basename($filePath));
                $ch = curl_init('https://api.groq.com/openai/v1/audio/transcriptions');
                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $postFields,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 45,
                    CURLOPT_CONNECTTIMEOUT => 10,
                    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $apiKey],
                    CURLOPT_SSL_VERIFYPEER => $verify,
                    CURLOPT_SSL_VERIFYHOST => $verify ? 2 : 0,
                ]);
                $raw  = curl_exec($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $err  = curl_error($ch);
                curl_close($ch);
                if (!($raw === false && self::isCertError($err))) {
                    break; // خطای گواهی بود؟ یک‌بار دیگر بدون بررسی تلاش کن
                }
            }

            if ($raw !== false && $code < 400) {
                $data = json_decode((string) $raw, true);
                $text = trim((string) ($data['text'] ?? ''));
                if ($text !== '') {
                    return $text;
                }
            }
            $lastErr = $err !== '' ? $err : "HTTP {$code} ({$model})";
            // اگر خطای احراز هویت یا درخواست نامعتبر بود، تلاش مدل بعدی بی‌فایده است
            if (in_array($code, [400, 401, 403, 404], true)) {
                break;
            }
        }

        throw new RuntimeException($lastErr !== '' ? $lastErr : 'تشخیص گفتار Groq ناموفق بود');
    }

    /**
     * استخراج تسک با مدل رایگان Llama 3.3 70B در Groq
     */
    private static function extractWithGroqLlama(
        string $transcript,
        array $users,
        array $projects,
        int $currentUserId,
        string $apiKey
    ): array {
        $payload = [
            'model'           => 'llama-3.3-70b-versatile',
            'temperature'     => 0.1,
            'response_format' => ['type' => 'json_object'],
            'messages'        => [
                ['role' => 'system', 'content' => self::buildSystemPrompt($users, $projects, $currentUserId)],
                ['role' => 'user',   'content' => 'پیام صوتی کاربر: «' . $transcript . '»'],
            ],
        ];

        $res = self::httpPostJson(
            'https://api.groq.com/openai/v1/chat/completions',
            $payload,
            ['Authorization: Bearer ' . $apiKey],
            18
        );
        $content = (string) ($res['choices'][0]['message']['content'] ?? '');
        $parsed  = self::decodeJsonFromText($content);
        return is_array($parsed['tasks'] ?? null) ? $parsed['tasks'] : [];
    }

    /**
     * پردازش مستقیم ویس فارسی + متن با نسخه رایگان Google Gemini Flash
     */
    private static function extractWithGeminiFree(
        ?array $savedVoice,
        string $transcript,
        array $users,
        array $projects,
        int $currentUserId,
        string $apiKey
    ): array {
        $parts = [];
        $parts[] = ['text' => self::buildSystemPrompt($users, $projects, $currentUserId)];

        if ($savedVoice !== null && !empty($savedVoice['full_path']) && is_file($savedVoice['full_path']) && (int) $savedVoice['size'] <= 15 * 1024 * 1024) {
            $audioBytes = file_get_contents($savedVoice['full_path']);
            if (is_string($audioBytes) && $audioBytes !== '') {
                $parts[] = [
                    'inline_data' => [
                        'mime_type' => $savedVoice['mime_type'] ?: 'audio/webm',
                        'data'      => base64_encode($audioBytes),
                    ],
                ];
            }
        }

        if ($transcript !== '') {
            $parts[] = ['text' => 'متن کمکی گفتار کاربر: «' . $transcript . '»'];
        } else {
            $parts[] = ['text' => 'فایل صوتی فارسی بالا را گوش بده، متن دقیق آن را در transcript بنویس و تسک‌ها را استخراج کن.'];
        }

        $payload = [
            'contents' => [['parts' => $parts]],
            'generationConfig' => [
                'temperature'      => 0.1,
                'responseMimeType' => 'application/json',
            ],
        ];

        $url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=' . rawurlencode($apiKey);
        $res = self::httpPostJson($url, $payload, [], 25);
        $content = (string) ($res['candidates'][0]['content']['parts'][0]['text'] ?? '');
        $parsed  = self::decodeJsonFromText($content);

        return [
            'transcript' => normalize_persian((string) ($parsed['transcript'] ?? $transcript)),
            'tasks'      => is_array($parsed['tasks'] ?? null) ? $parsed['tasks'] : [],
        ];
    }

    /**
     * استخراج تسک با سرویس کاملاً رایگان و بدون کلید Pollinations AI
     */
    private static function extractWithPollinationsFree(
        string $transcript,
        array $users,
        array $projects,
        int $currentUserId
    ): array {
        $payload = [
            'model'       => 'openai',
            'temperature' => 0.1,
            'jsonMode'    => true,
            'messages'    => [
                ['role' => 'system', 'content' => self::buildSystemPrompt($users, $projects, $currentUserId)],
                ['role' => 'user',   'content' => 'پیام صوتی کاربر: «' . $transcript . '»'],
            ],
        ];

        $res = self::httpPostJson('https://text.pollinations.ai/openai', $payload, [], 7, 3);
        $content = (string) ($res['choices'][0]['message']['content'] ?? '');
        $parsed  = self::decodeJsonFromText($content);
        return is_array($parsed['tasks'] ?? null) ? $parsed['tasks'] : [];
    }

    /**
     * اعتبارسنجی، تکمیل و استانداردسازی خروجی مدل‌های هوش مصنوعی با تقویم شمسی و لیست کارمندان مجاز
     */
    /**
     * آیا عنوان تولیدشده توسط مدل «ضعیف» است؟
     * نشانه‌ها: خیلی کوتاه، یا آلوده به کلمات زمانی/دستوری که نباید در عنوان باشند.
     */
    private static function isWeakTitle(string $title): bool
    {
        $t = trim($title);
        if ($t === '') {
            return true;
        }
        // عنوان‌های تک‌کلمه‌ای معمولاً ناقص‌اند (مثل «ارسال»)
        $wordCount = count(preg_split('/\s+/u', $t) ?: []);
        if ($wordCount < 2 && mb_strlen($t, 'UTF-8') < 8) {
            return true;
        }
        // نشت عبارت‌های زمانی/دستوری به داخل عنوان
        $leak = '/\b(?:فردا|امروز|امشب|پس‌فردا|هفته\s+آینده|هفته\s+بعد|ماه\s+بعد|ماه\s+آینده|سال\s+آینده|ساعت\s+\S+|بساز|بذار|ثبت\s+کن|ایجاد\s+کن|یادم\s+بنداز|لطفاً|لطفا)\b/u';
        if (preg_match($leak, $t)) {
            return true;
        }
        return false;
    }

    private static function normalizeAiTasks(
        array $rawTasks,
        string $fallbackTranscript,
        array $users,
        array $projects,
        int $currentUserId
    ): array {
        $userMap = [];
        foreach ($users as $u) {
            $userMap[(int) $u['id']] = (string) $u['full_name'];
        }
        $projMap = [];
        foreach ($projects as $p) {
            $projMap[(int) $p['id']] = (string) $p['name'];
        }

        $localFallback = $fallbackTranscript !== ''
            ? PersianVoiceParser::parseSingle($fallbackTranscript, $users, $projects, $currentUserId)
            : null;

        $normalized = [];
        foreach ($rawTasks as $item) {
            if (!is_array($item)) {
                continue;
            }
            $title = clean_line($item['title'] ?? '', 180);
            if ($title === '') {
                continue;
            }

            // 🎯 اعتبارسنجی کیفیت عنوان: اگر مدل عنوان ناقص/آلوده به کلمات زمانی برگرداند،
            // عنوان پارسر محلی (که در این پروژه دقیق‌تر است) جایگزین می‌شود.
            if ($localFallback !== null && self::isWeakTitle($title)) {
                $better = (string) ($localFallback['title'] ?? '');
                if ($better !== '' && !self::isWeakTitle($better)) {
                    $title = $better;
                }
            }

            // ترجیح تاریخ شمسی مدل در صورت معتبر بودن، سپس تاریخ میلادی، سپس موتور فارسی داخلی
            $dueGreg = null;
            if (!empty($item['due_date_jalali'])) {
                $dueGreg = Jalali::parseToGregorian((string) $item['due_date_jalali']);
            }
            if ($dueGreg === null && !empty($item['due_date'])) {
                $dueGreg = Jalali::parseToGregorian((string) $item['due_date']);
            }
            if ($dueGreg === null) {
                $dueGreg = $localFallback['due_date'] ?? today_date();
            }

            $dueTime = null;
            if (!empty($item['due_time'])) {
                $tClean = en_digits(trim((string) $item['due_time']));
                if (preg_match('/^(\d{1,2}):(\d{2})/', $tClean, $tm)) {
                    $h = (int) $tm[1];
                    $m = (int) $tm[2];
                    if ($h >= 0 && $h <= 23 && $m >= 0 && $m <= 59) {
                        $dueTime = sprintf('%02d:%02d', $h, $m);
                    }
                }
            }

            $prio = strtolower(trim((string) ($item['priority'] ?? 'medium')));
            if (!in_array($prio, ['low', 'medium', 'high', 'urgent'], true)) {
                $prio = $localFallback['priority'] ?? 'medium';
            }

            $assignedTo = (int) ($item['assigned_to'] ?? $currentUserId);
            if (!isset($userMap[$assignedTo])) {
                $assignedTo = $localFallback['assigned_to'] ?? $currentUserId;
            }
            if (!isset($userMap[$assignedTo])) {
                $assignedTo = $currentUserId;
            }

            $projId = !empty($item['project_id']) ? (int) $item['project_id'] : null;
            if ($projId !== null && !isset($projMap[$projId])) {
                $projId = null;
            }

            $rec = strtolower(trim((string) ($item['recurrence'] ?? 'none')));
            if (!in_array($rec, ['none', 'daily', 'weekly', 'monthly'], true)) {
                $rec = 'none';
            }

            $tags = $item['tags'] ?? [];
            if (is_array($tags)) {
                $tagsStr = implode(',', array_slice(array_map(static fn($t) => clean_line($t, 30), $tags), 0, 5));
            } else {
                $tagsStr = clean_line((string) $tags, 200);
            }

            $subs = [];
            if (is_array($item['subtasks'] ?? null)) {
                foreach (array_slice($item['subtasks'], 0, 6) as $st) {
                    $stLine = clean_line($st, 180);
                    if ($stLine !== '') {
                        $subs[] = $stLine;
                    }
                }
            }

            $dueJalali = Jalali::toDateString($dueGreg, false);
            $normalized[] = [
                'title'              => fa_digits($title),
                'description'        => clean_text($item['description'] ?? ($localFallback['description'] ?? ''), 3000),
                'due_date'           => $dueGreg,
                'due_date_jalali'    => $dueJalali,
                'due_date_jalali_fa' => fa_digits($dueJalali),
                'due_date_human'     => Jalali::toHuman($dueGreg, true, true),
                'date_reason'        => Jalali::toHuman($dueGreg, true, true),
                'due_time'           => $dueTime,
                'due_time_fa'        => $dueTime ? fa_digits($dueTime) : '',
                'priority'           => $prio,
                'priority_label'     => task_priority_label($prio),
                'assigned_to'        => $assignedTo,
                'assigned_name'      => $userMap[$assignedTo] ?? '',
                'project_id'         => $projId,
                'project_name'       => $projId !== null ? ($projMap[$projId] ?? '') : '',
                'estimated_minutes'  => max(0, min(1440, (int) ($item['estimated_minutes'] ?? 60))),
                'recurrence'         => $rec,
                'category'           => clean_line($item['category'] ?? 'عمومی', 60),
                'tags'               => $tagsStr,
                'subtasks'           => $subs,
            ];
        }

        return $normalized;
    }

    private static function httpPostJson(string $url, array $payload, array $extraHeaders = [], int $timeout = 15, int $connectTimeout = 5): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('افزونه cURL در سرور فعال نیست.');
        }

        $headers = array_merge([
            'Content-Type: application/json; charset=UTF-8',
            'Accept: application/json',
        ], $extraHeaders);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $code >= 400) {
            throw new RuntimeException($err !== '' ? $err : "HTTP {$code}");
        }
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('پاسخ نامعتبر از سرویس هوش مصنوعی');
        }
        return $decoded;
    }

    private static function decodeJsonFromText(string $text): array
    {
        $trimmed = trim($text);
        if ($trimmed === '') {
            return [];
        }
        // حذف بلوک‌های ```json در صورت وجود
        $trimmed = preg_replace('/^```(?:json)?\s*|\s*```$/i', '', $trimmed) ?? $trimmed;
        $decoded = json_decode($trimmed, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        $start = strpos($trimmed, '{');
        $end   = strrpos($trimmed, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $sub = substr($trimmed, $start, $end - $start + 1);
            $decoded = json_decode($sub, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        return [];
    }
}
