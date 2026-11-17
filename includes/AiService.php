<?php
declare(strict_types=1);

/**
 * سرویس یکپارچه هوش مصنوعی رایگان برای پردازش ویس (صدا) و ایجاد هوشمند تسک
 *
 * لایه‌های رایگان پشتیبانی‌شده:
 *  1) Groq Free Tier (Whisper-Large-v3-Turbo برای تبدیل دقیق ویس فارسی به متن + Llama 3.3 70B رایگان)
 *  2) Google Gemini Free Tier (Gemini 2.5 Flash با پردازش مستقیم فایل صوتی فارسی و متن - کلید رایگان AI Studio)
 *  3) Pollinations AI Free (مدل زبانی کاملاً رایگان و بدون هیچ کلید API)
 *  4) موتور پردازش زبان طبیعی فارسی داخلی (PersianVoiceParser - ۱۰۰٪ رایگان، آنی و بدون محدودیت)
 *  + در سمت مرورگر نیز Web Speech API رایگان (fa-IR) هم‌زمان با ضبط ویس متن گفتار را استخراج می‌کند.
 */
final class AiService
{
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

        // گام ۱: اگر کلید رایگان Groq تنظیم شده و فایل صوتی داریم، با Whisper Large v3 صدای فارسی را دقیق پیاده‌سازی کن
        if (
            in_array($preferred, ['auto', 'groq'], true)
            && $groqKey !== ''
            && $savedVoice !== null
            && !empty($savedVoice['full_path'])
            && is_file($savedVoice['full_path'])
        ) {
            try {
                $whisperText = self::transcribeWithGroqWhisper($savedVoice['full_path'], $savedVoice['mime_type'], $groqKey);
                if ($whisperText !== '') {
                    $transcript = normalize_persian($whisperText);
                    $usedEngine = 'Groq Whisper v3 (رایگان)';
                }
            } catch (Throwable $e) {
                $notes[] = 'Whisper: ' . $e->getMessage();
            }
        }

        // گام ۲: اگر کلید رایگان Google Gemini فعال است (می‌تواند مستقیم فایل صوتی فارسی را بدون نیاز به متن هم بفهمد!)
        if (
            in_array($preferred, ['auto', 'gemini'], true)
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

        // اگر نه سرویس صوتی ابری متنی برگرداند و نه مرورگر متنی ثبت کرد
        if ($transcript === '') {
            throw new RuntimeException(
                'متن گفتار تشخیص داده نشد. لطفاً هنگام ضبط با مرورگر Chrome/Edge واضح به فارسی صحبت کنید، یا متن فرمان را در کادر بنویسید، یا کلید رایگان Groq / Gemini را در تنظیمات وارد نمایید.'
            );
        }

        // گام ۳: تحلیل متن گفتار با Groq Llama 3.3 رایگان (در صورت وجود کلید رایگان)
        if (in_array($preferred, ['auto', 'groq'], true) && $groqKey !== '') {
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
        if (in_array($preferred, ['auto', 'pollinations'], true) && function_exists('curl_init')) {
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
اگر نام کارمند خاصی ذکر نشد، assigned_to را برابر {$currentUserId} بگذار. اگر روزی ذکر نشد، تاریخ امروز ({$todayJ}) را قرار بده.
PROMPT;
    }

    /**
     * تبدیل فایل صوتی فارسی به متن با Groq Whisper Large v3 Turbo (رایگان)
     */
    private static function transcribeWithGroqWhisper(string $filePath, string $mimeType, string $apiKey): string
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('افزونه cURL فعال نیست.');
        }

        $cfile = new CURLFile($filePath, $mimeType, basename($filePath));
        $postFields = [
            'file'            => $cfile,
            'model'           => 'whisper-large-v3-turbo',
            'language'        => 'fa',
            'response_format' => 'json',
            'temperature'     => '0',
        ];

        $ch = curl_init('https://api.groq.com/openai/v1/audio/transcriptions');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $postFields,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 25,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $apiKey,
            ],
        ]);
        $raw  = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($raw === false || $code >= 400) {
            throw new RuntimeException($err !== '' ? $err : "HTTP {$code}");
        }
        $data = json_decode((string) $raw, true);
        return trim((string) ($data['text'] ?? ''));
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
