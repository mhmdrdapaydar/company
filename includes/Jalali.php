<?php
declare(strict_types=1);

/**
 * موتور کامل تقویم هجری شمسی (خورشیدی / جلالی)
 * تبدیل دقیق دوطرفه میلادی <-> شمسی، سال کبیسه، نام روزها و ماه‌های فارسی و زمان نسبی
 */
final class Jalali
{
    public const MONTH_NAMES = [
        1  => 'فروردین',
        2  => 'اردیبهشت',
        3  => 'خرداد',
        4  => 'تیر',
        5  => 'مرداد',
        6  => 'شهریور',
        7  => 'مهر',
        8  => 'آبان',
        9  => 'آذر',
        10 => 'دی',
        11 => 'بهمن',
        12 => 'اسفند',
    ];

    /**
     * اندیس روز هفته در تقویم ایرانی: ۰ = شنبه ... ۶ = جمعه
     */
    public const WEEKDAY_NAMES = [
        0 => 'شنبه',
        1 => 'یکشنبه',
        2 => 'دوشنبه',
        3 => 'سه‌شنبه',
        4 => 'چهارشنبه',
        5 => 'پنجشنبه',
        6 => 'جمعه',
    ];

    public const SEASONS = [
        1 => 'بهار', 2 => 'بهار', 3 => 'بهار',
        4 => 'تابستان', 5 => 'تابستان', 6 => 'تابستان',
        7 => 'پاییز', 8 => 'پاییز', 9 => 'پاییز',
        10 => 'زمستان', 11 => 'زمستان', 12 => 'زمستان',
    ];

    /**
     * تبدیل تاریخ میلادی به شمسی [سال، ماه، روز]
     * @return array{0:int, 1:int, 2:int}
     */
    public static function toJalali(int $gy, int $gm, int $gd): array
    {
        $gdm  = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        $gy2  = ($gm > 2) ? ($gy + 1) : $gy;
        $days = 355666
            + (365 * $gy)
            + intdiv($gy2 + 3, 4)
            - intdiv($gy2 + 99, 100)
            + intdiv($gy2 + 399, 400)
            + $gd
            + ($gdm[$gm - 1] ?? 0);

        $jy = -1595 + (33 * intdiv($days, 12053));
        $days %= 12053;

        $jy += 4 * intdiv($days, 1461);
        $days %= 1461;

        if ($days > 365) {
            $jy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }

        if ($days < 186) {
            $jm = 1 + intdiv($days, 31);
            $jd = 1 + ($days % 31);
        } else {
            $jm = 7 + intdiv($days - 186, 30);
            $jd = 1 + (($days - 186) % 30);
        }

        return [$jy, $jm, $jd];
    }

    /**
     * تبدیل تاریخ شمسی به میلادی [سال، ماه، روز]
     * @return array{0:int, 1:int, 2:int}
     */
    public static function toGregorian(int $jy, int $jm, int $jd): array
    {
        $jy += 1595;
        $days = -355668
            + (365 * $jy)
            + (intdiv($jy, 33) * 8)
            + intdiv(($jy % 33) + 3, 4)
            + $jd
            + (($jm < 7) ? (($jm - 1) * 31) : ((($jm - 7) * 30) + 186));

        $gy = 400 * intdiv($days, 146097);
        $days %= 146097;

        if ($days > 36524) {
            $gy += 100 * intdiv(--$days, 36524);
            $days %= 36524;
            if ($days >= 365) {
                $days++;
            }
        }

        $gy += 4 * intdiv($days, 1461);
        $days %= 1461;

        if ($days > 365) {
            $gy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }

        $gd = $days + 1;
        $isGregLeap = (($gy % 4 === 0 && $gy % 100 !== 0) || ($gy % 400 === 0));
        $monthDays = [0, 31, $isGregLeap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];

        $gm = 1;
        while ($gm <= 12 && $gd > $monthDays[$gm]) {
            $gd -= $monthDays[$gm];
            $gm++;
        }

        return [$gy, $gm, $gd];
    }

    /**
     * بررسی سال کبیسه شمسی
     */
    public static function isLeap(int $jy): bool
    {
        $g = self::toGregorian($jy, 12, 30);
        $back = self::toJalali($g[0], $g[1], $g[2]);
        return $back[0] === $jy && $back[1] === 12 && $back[2] === 30;
    }

    /**
     * تعداد روزهای یک ماه شمسی
     */
    public static function daysInMonth(int $jy, int $jm): int
    {
        if ($jm >= 1 && $jm <= 6) {
            return 31;
        }
        if ($jm >= 7 && $jm <= 11) {
            return 30;
        }
        if ($jm === 12) {
            return self::isLeap($jy) ? 30 : 29;
        }
        return 30;
    }

    /**
     * اعتبارسنجی تاریخ شمسی
     */
    public static function isValidDate(int $jy, int $jm, int $jd): bool
    {
        if ($jy < 1200 || $jy > 1600 || $jm < 1 || $jm > 12 || $jd < 1) {
            return false;
        }
        return $jd <= self::daysInMonth($jy, $jm);
    }

    /**
     * اندیس روز هفته ایرانی برای تاریخ میلادی (۰=شنبه ... ۶=جمعه)
     */
    public static function weekdayIndex(string|int $dateOrTimestamp): int
    {
        $ts = is_int($dateOrTimestamp) ? $dateOrTimestamp : strtotime($dateOrTimestamp);
        if ($ts === false) {
            $ts = time();
        }
        // در PHP تابع date('w'): ۰=یکشنبه ... ۶=شنبه
        $phpW = (int) date('w', $ts);
        return ($phpW + 1) % 7;
    }

    public static function weekdayName(string|int $dateOrTimestamp): string
    {
        return self::WEEKDAY_NAMES[self::weekdayIndex($dateOrTimestamp)];
    }

    public static function monthName(int $jm): string
    {
        return self::MONTH_NAMES[$jm] ?? '';
    }

    /**
     * تاریخ امروز شمسی به صورت آرایه [سال، ماه، روز]
     * @return array{0:int, 1:int, 2:int}
     */
    public static function todayArray(): array
    {
        return self::toJalali((int) date('Y'), (int) date('n'), (int) date('j'));
    }

    /**
     * تبدیل رشته تاریخ میلادی (YYYY-MM-DD یا YYYY-MM-DD HH:MM:SS) به رشته شمسی YYYY/MM/DD
     */
    public static function toDateString(?string $gregorianDate, bool $persianDigits = true): string
    {
        if ($gregorianDate === null || trim($gregorianDate) === '') {
            return '';
        }
        $ts = strtotime($gregorianDate);
        if ($ts === false) {
            return '';
        }
        [$jy, $jm, $jd] = self::toJalali((int) date('Y', $ts), (int) date('n', $ts), (int) date('j', $ts));
        $formatted = sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
        return $persianDigits ? fa_digits($formatted) : $formatted;
    }

    /**
     * نمایش خوانای تاریخ شمسی مثل «سه‌شنبه ۲۶ آبان ۱۴۰۵» یا «۲۶ آبان ۱۴۰۵»
     */
    public static function toHuman(?string $gregorianDate, bool $withWeekday = true, bool $withYear = true): string
    {
        if ($gregorianDate === null || trim($gregorianDate) === '') {
            return 'بدون تاریخ';
        }
        $ts = strtotime($gregorianDate);
        if ($ts === false) {
            return '';
        }
        [$jy, $jm, $jd] = self::toJalali((int) date('Y', $ts), (int) date('n', $ts), (int) date('j', $ts));
        $parts = [];
        if ($withWeekday) {
            $parts[] = self::weekdayName($ts);
        }
        $parts[] = fa_digits($jd);
        $parts[] = self::monthName($jm);
        if ($withYear) {
            $parts[] = fa_digits($jy);
        }
        return implode(' ', $parts);
    }

    /**
     * نمایش تاریخ و ساعت شمسی کامل مثل «۲۶ آبان ۱۴۰۵ - ساعت ۱۴:۳۰»
     */
    public static function toDateTimeHuman(?string $gregorianDateTime, bool $withWeekday = false): string
    {
        if ($gregorianDateTime === null || trim($gregorianDateTime) === '') {
            return '';
        }
        $ts = strtotime($gregorianDateTime);
        if ($ts === false) {
            return '';
        }
        $datePart = self::toHuman($gregorianDateTime, $withWeekday, true);
        $timePart = fa_digits(date('H:i', $ts));
        return $datePart . ' ساعت ' . $timePart;
    }

    /**
     * نمایش زمان نسبی فارسی («لحظاتی پیش»، «۱۰ دقیقه پیش»، «دیروز»...)
     */
    public static function timeAgo(?string $gregorianDateTime): string
    {
        if ($gregorianDateTime === null || trim($gregorianDateTime) === '') {
            return '';
        }
        $ts = strtotime($gregorianDateTime);
        if ($ts === false) {
            return '';
        }
        $diff = time() - $ts;
        if ($diff < 60) {
            return 'لحظاتی پیش';
        }
        if ($diff < 3600) {
            return fa_digits(intdiv($diff, 60)) . ' دقیقه پیش';
        }
        if ($diff < 86400) {
            return fa_digits(intdiv($diff, 3600)) . ' ساعت پیش';
        }
        if ($diff < 172800) {
            return 'دیروز ساعت ' . fa_digits(date('H:i', $ts));
        }
        if ($diff < 604800) {
            return fa_digits(intdiv($diff, 86400)) . ' روز پیش';
        }
        return self::toHuman($gregorianDateTime, false, true);
    }

    /**
     * برچسب هوشمند سررسید تسک نسبت به امروز («امروز»، «فردا»، «۳ روز مانده»، «۲ روز تأخیر»)
     */
    public static function dueBadge(?string $gregorianDueDate, string $status = 'todo'): array
    {
        if ($gregorianDueDate === null || trim($gregorianDueDate) === '') {
            return ['text' => 'بدون سررسید', 'state' => 'none'];
        }
        $todayTs = strtotime(date('Y-m-d') . ' 00:00:00');
        $dueTs   = strtotime(substr($gregorianDueDate, 0, 10) . ' 00:00:00');
        if ($dueTs === false || $todayTs === false) {
            return ['text' => '', 'state' => 'none'];
        }
        $diffDays = (int) round(($dueTs - $todayTs) / 86400);

        if ($status === 'done') {
            return ['text' => self::toHuman($gregorianDueDate, false, false), 'state' => 'done'];
        }
        if ($diffDays < 0) {
            $abs = abs($diffDays);
            return [
                'text'  => $abs === 1 ? 'دیروز (عقب‌افتاده)' : fa_digits($abs) . ' روز تأخیر',
                'state' => 'overdue',
            ];
        }
        if ($diffDays === 0) {
            return ['text' => 'امروز', 'state' => 'today'];
        }
        if ($diffDays === 1) {
            return ['text' => 'فردا', 'state' => 'tomorrow'];
        }
        if ($diffDays === 2) {
            return ['text' => 'پس‌فردا', 'state' => 'soon'];
        }
        if ($diffDays <= 7) {
            return ['text' => self::weekdayName($dueTs) . ' (' . fa_digits($diffDays) . ' روز دیگر)', 'state' => 'soon'];
        }
        return ['text' => self::toHuman($gregorianDueDate, false, true), 'state' => 'future'];
    }

    /**
     * تبدیل ورودی تاریخ شمسی یا میلادی کاربر به فرمت استاندارد میلادی YYYY-MM-DD برای ذخیره در SQL
     * پشتیبانی از: ۱۴۰۵/۰۸/۲۶ ، 1405-8-26 ، 2026-11-17
     */
    public static function parseToGregorian(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }
        $clean = trim(en_digits($input));
        if ($clean === '') {
            return null;
        }

        if (preg_match('/^(\d{4})[\/\-\.](\d{1,2})[\/\-\.](\d{1,2})$/', $clean, $m)) {
            $y = (int) $m[1];
            $mo = (int) $m[2];
            $d = (int) $m[3];

            // تاریخ شمسی (سال بین ۱۳۰۰ تا ۱۶۰۰)
            if ($y >= 1300 && $y <= 1600) {
                if (!self::isValidDate($y, $mo, $d)) {
                    return null;
                }
                [$gy, $gm, $gd] = self::toGregorian($y, $mo, $d);
                return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
            }

            // تاریخ میلادی استاندارد (سال بین ۱۹۵۰ تا ۲۲۰۰)
            if ($y >= 1950 && $y <= 2200 && checkdate($mo, $d, $y)) {
                return sprintf('%04d-%02d-%02d', $y, $mo, $d);
            }
        }

        return null;
    }

    /**
     * تبدیل تاریخ شمسی مشخص به رشته میلادی YYYY-MM-DD
     */
    public static function jalaliToGregorianString(int $jy, int $jm, int $jd): string
    {
        $maxDay = self::daysInMonth($jy, $jm);
        if ($jd > $maxDay) {
            $jd = $maxDay;
        }
        if ($jd < 1) {
            $jd = 1;
        }
        [$gy, $gm, $gd] = self::toGregorian($jy, $jm, $jd);
        return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
    }

    /**
     * تولید ساختار کامل تقویم یک ماه شمسی (برای نمایش در نمای تقویم)
     */
    public static function buildMonthMatrix(int $jy, int $jm): array
    {
        if ($jm < 1) {
            $jm = 12;
            $jy--;
        } elseif ($jm > 12) {
            $jm = 1;
            $jy++;
        }

        $daysCount = self::daysInMonth($jy, $jm);
        $firstGreg = self::jalaliToGregorianString($jy, $jm, 1);
        $lastGreg  = self::jalaliToGregorianString($jy, $jm, $daysCount);
        $startWeekday = self::weekdayIndex($firstGreg); // 0=شنبه ... 6=جمعه
        $todayGreg = date('Y-m-d');

        $days = [];
        for ($d = 1; $d <= $daysCount; $d++) {
            $gDate = self::jalaliToGregorianString($jy, $jm, $d);
            $wIdx  = ($startWeekday + $d - 1) % 7;
            $days[] = [
                'day'           => $d,
                'day_fa'        => fa_digits($d),
                'jalali'        => sprintf('%04d/%02d/%02d', $jy, $jm, $d),
                'jalali_fa'     => fa_digits(sprintf('%04d/%02d/%02d', $jy, $jm, $d)),
                'human'         => self::WEEKDAY_NAMES[$wIdx] . ' ' . fa_digits($d) . ' ' . self::monthName($jm) . ' ' . fa_digits($jy),
                'gregorian'     => $gDate,
                'weekday_index' => $wIdx,
                'weekday_name'  => self::WEEKDAY_NAMES[$wIdx],
                'is_friday'     => ($wIdx === 6),
                'is_today'      => ($gDate === $todayGreg),
            ];
        }

        $prevY = $jm === 1 ? $jy - 1 : $jy;
        $prevM = $jm === 1 ? 12 : $jm - 1;
        $nextY = $jm === 12 ? $jy + 1 : $jy;
        $nextM = $jm === 12 ? 1 : $jm + 1;

        return [
            'year'          => $jy,
            'year_fa'       => fa_digits($jy),
            'month'         => $jm,
            'month_fa'      => fa_digits($jm),
            'month_name'    => self::monthName($jm),
            'season'        => self::SEASONS[$jm] ?? '',
            'title'         => self::monthName($jm) . ' ' . fa_digits($jy),
            'days_count'    => $daysCount,
            'start_weekday' => $startWeekday,
            'first_greg'    => $firstGreg,
            'last_greg'     => $lastGreg,
            'prev'          => ['year' => $prevY, 'month' => $prevM, 'label' => self::monthName($prevM) . ' ' . fa_digits($prevY)],
            'next'          => ['year' => $nextY, 'month' => $nextM, 'label' => self::monthName($nextM) . ' ' . fa_digits($nextY)],
            'days'          => $days,
        ];
    }

    /**
     * تولید جدول ۱۴ روز آینده به شمسی (برای ارسال به پرامپت هوش مصنوعی جهت تشخیص ۱۰۰٪ دقیق روزها)
     */
    public static function upcomingDaysSchedule(int $count = 14): array
    {
        $schedule = [];
        $baseTs = strtotime(date('Y-m-d') . ' 12:00:00');
        for ($i = 0; $i <= $count; $i++) {
            $ts = $baseTs + ($i * 86400);
            $g  = date('Y-m-d', $ts);
            [$jy, $jm, $jd] = self::toJalali((int) date('Y', $ts), (int) date('n', $ts), (int) date('j', $ts));
            $rel = match ($i) {
                0 => 'امروز',
                1 => 'فردا',
                2 => 'پس‌فردا',
                default => $i . ' روز دیگر',
            };
            $schedule[] = [
                'offset'    => $i,
                'relative'  => $rel,
                'weekday'   => self::weekdayName($ts),
                'jalali'    => sprintf('%04d/%02d/%02d', $jy, $jm, $jd),
                'jalali_fa' => fa_digits(sprintf('%04d/%02d/%02d', $jy, $jm, $jd)),
                'human'     => self::weekdayName($ts) . ' ' . $jd . ' ' . self::monthName($jm) . ' ' . $jy,
                'gregorian' => $g,
            ];
        }
        return $schedule;
    }
}
