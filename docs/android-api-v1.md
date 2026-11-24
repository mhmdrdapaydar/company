# راهنمای API اندروید — نسخهٔ ۱

این قرارداد در کد برنامه پیاده‌سازی شده است. **تا زمان استقرار همین نسخه روی سرور، نشانی واقعی الزاماً پاسخ جدید نمی‌دهد.** نشانی پایهٔ موردنظر:

```text
https://best-zone.ir/company/
```

تمام درخواست‌های داده به `mobile-v1.php?action=ACTION` در همین پوشه می‌روند. برای نمونه: `https://best-zone.ir/company/mobile-v1.php?action=login`. این API به سرویس بین‌المللی وابستگی اجباری ندارد. تاریخ‌های سررسید در پاسخ به‌صورت میلادی `YYYY-MM-DD` هستند؛ نمایش شمسی به عهدهٔ برنامه یا فیلدهای فارسی موجود در پاسخ است. UTF-8 استفاده کنید.

## پیش‌نیاز راه‌اندازی سرور

- نسخهٔ جدید PHP و پایگاه دادهٔ موجود را مستقر کنید؛ برنامه در نخستین درخواست جداول `mobile_tokens`، `mobile_login_limits` و `mobile_voice_previews` را به‌طور خودکار می‌سازد. از داده‌ها پیش از استقرار پشتیبان بگیرید و مجوز ساخت جدول را بررسی کنید.
- HTTPS معتبر الزامی است. اگر PHP پشت یک reverse proxy مورد اعتماد اجرا می‌شود، فقط پس از اینکه پروکسی هدر `X-Forwarded-Proto` ورودیِ کاربر را حذف و مقدار واقعی را تنظیم می‌کند، `define('MOBILE_TRUST_PROXY', true);` را در `config/config.local.php` سرور قرار دهید. در غیر این صورت این گزینه را فعال نکنید.
- وب‌سرور باید هدر `Authorization` را به PHP برساند (در بعضی پیکربندی‌های Apache/FastCGI یا Nginx تنظیم جداگانه لازم است). مسیرهای `storage`، تنظیمات و دیتابیس را از دسترسی مستقیم وب مسدود کنید.
- تنظیم `MOBILE_ALLOW_INSECURE_HTTP` فقط برای آزمون محلیِ ایزوله در نظر گرفته شده و **نباید روی سرور عملیاتی فعال شود**. اگر پروکسی/HTTPS تنظیم نشده باشد، پاسخ ۴۰۳ برمی‌گردد.

## ورود و توکن

`POST mobile-v1.php?action=login` با `Content-Type: application/json`:

```json
{"identifier":"شماره تلفن یا نام کاربری","password":"رمز عبور"}
```

پاسخ موفق نمونه (مقادیر کوتاه‌شده صرفاً نمایشی):

```json
{"ok":true,"token_type":"Bearer","access_token":"توکن-دریافتی-از-سرور","idle_timeout_seconds":604800,"user":{"id":3,"full_name":"نام کاربر","role":"employee"}}
```

برای درخواست‌های بعدی هدر `Authorization: Bearer <access_token>` را بفرستید؛ توکن را در URL، لاگ یا متن عادی ذخیره نکنید. روی اندروید آن را با رمزگذاری مبتنی بر Android Keystore نگهداری کنید. توکن برای هر ورود جداست؛ در پایگاه داده فقط هش آن ذخیره می‌شود. **پس از هفت روز بدون درخواست معتبر آن دستگاه** منقضی می‌شود؛ درخواست‌های پس‌زمینه نیز فعالیت محسوب می‌شوند. تغییر رمز، غیرفعال شدن یا حذف حساب، توکن را بی‌اعتبار می‌کند. `POST ...?action=logout` فقط توکن دستگاه فعلی را لغو می‌کند. در صورت خطای ۴۰۱ توکن را پاک و دوباره وارد شوید. پاک شدن دادهٔ اپ یا حذف توکن از پایگاه داده نیز نیاز به ورود دوباره دارد.

ورود با شمارهٔ تلفن فقط وقتی ممکن است که شماره میان حساب‌های فعال یکتا باشد. حفاظت از تلاش ورود بر اساس حساب و IP با پنجره‌های ۱۵ دقیقه‌ای اعمال می‌شود؛ پاسخ ۴۲۹ به معنای انتظار و تلاش مجدد پس از بازه است. در معماری چند پروکسی، تنظیم صحیح IP واقعی با حفظ اعتماد فقط به پروکسی الزامی است. از نام کاربری/رمز نمایشی در محیط عملیاتی استفاده نکنید.

## عملیات

در همهٔ عملیات زیر توکن لازم است. برای `GET`، پارامترها در query string و برای `POST` در JSON ارسال می‌شوند؛ خروجی از الگوی `{"ok":true,...}` یا `{"ok":false,"message":"..."}` پیروی می‌کند. مجوزهای نقش و دامنهٔ دسترسی همان برنامهٔ وب هستند. ارسال کوکی مرورگر جایگزین توکن نیست؛ هیچ کوکی یا CSRF برای API اندروید لازم نیست.

| متد | action | ورودی مهم | خروجی مهم |
|---|---|---|---|
| GET | `me` | — | `user`, `today`, `settings`, `is_manager`, `is_admin` (مقدار `csrf_token` در موبایل تهی است) |
| GET | `tasks_list` | اختیاری: `page`, `scope=mine|assigned|team`, `status=all|not_done|in_progress|done`, `search`, `assigned_to`, `due_from`, `due_to` | حداکثر ۲۰ `tasks`، `count` و `pagination`؛ `team` و فیلتر کارمند برای مدیر است |
| GET | `task_get` | `id` | `task` همراه با جزئیات، نظرها و پیوست‌ها در محدودهٔ مجاز |
| POST | `task_create` | `title`, `assigned_to`، اختیاری `description`, `due_date`, `due_time` | `task`, `ids` |
| POST | `task_update` | `id` و فیلدهای تغییرکرده، از جمله `status=in_progress|done` | `task` |
| POST | `task_delete` | `id` | وضعیت حذف؛ فقط سازنده یا مدیر شرکت |
| GET | `assignable` | — | `users` قابل انتخاب برای کاربر فعلی |
| GET | `calendar_today` | — | `date`, `date_human`, `tasks` |
| GET | `calendar_month` | `jy`, `jm` به اعداد شمسی؛ در نبود آن ماه جاری | `calendar` و روزها و تسک‌ها |
| GET | `announcements_list` | — | `announcements` |
| GET | `notifications_list` | — | `notifications` (حداکثر ۵۰ مورد اخیر) |
| GET | `notifications_unread` | — | `unread`, `last_id` |
| POST | `notification_read` | `id` | وضعیت خوانده‌شدن |
| POST | `notifications_read_all` | `{}` | وضعیت خواندن همه |
| POST | `comment_add` | `task_id`, `body`؛ برای صوت از multipart استفاده کنید | نتیجهٔ ثبت نظر |
| POST | `voice_preview` | `transcript` و/یا فایل `audio` در multipart، اختیاری `voice_duration_sec` | `tasks`, `transcript`, `provider`, `requires_review`, `voice_file` |
| POST | `voice_create` | `tasks` آرایهٔ تأییدشده، اختیاری `voice_file`, `transcript`, `provider`, `voice_duration_sec` | `ids`, `tasks` |
| POST | `logout` | `{}` | لغو توکن فعلی |

### ثبت تسک فردی و گروهی

```json
{"title":"جلسه هماهنگی","assigned_to":3,"due_date":"2026-12-01","due_time":"10:30"}
```

مدیر/مدیر شرکت برای چند نفر به‌جای `assigned_to` از `"assigned_ids":[3,4]` یا برای همهٔ حساب‌های فعال از `"assign_all":true` استفاده می‌کند. برای هر فرد یک تسک و شناسهٔ مستقل ساخته می‌شود؛ کارمند عادی مجاز به ارسال گروهی نیست. انتخاب نامعتبر باعث رد درخواست می‌شود. برای هر فرد دیگر اعلان داخل برنامه ساخته می‌شود؛ مشاهدهٔ اعلان یا تحویل دستگاه تضمین مطلق ندارد.

### صوت و فایل

۱. `voice_preview` را با `multipart/form-data` و نام فایل `audio` و در صورت وجود `transcript` بفرستید. تبدیل گفتار بدون متن ممکن است به قابلیت مرورگر/موتور گفتار یا سرویس تنظیم‌شده نیاز داشته باشد؛ هنگام قطع اینترنت بین‌الملل موتور داخلی از **متن** استفاده می‌کند و تبدیل صوت به متن خام را تضمین نمی‌کند.
۲. تسک‌ها و مسئولان تشخیص‌داده‌شده را در اپ نمایش دهید تا کاربر اصلاح و تأیید کند. تشخیص چند نام یا «همه» باید در اپ پیاده‌سازی و بررسی شود؛ پاسخ پیش‌نمایش به‌تنهایی انتخاب گروهی را تضمین نمی‌کند.
۳. `voice_create` را با JSON شامل آرایهٔ `tasks`، فیلد `voice_file` دریافتی و متن تأییدشده ارسال کنید. هر آیتم می‌تواند `assigned_to`، `assigned_ids` یا `assign_all` داشته باشد. کل درخواست به‌صورت یک تراکنش ثبت می‌شود. فایل پیش‌نمایش تنها برای همان توکن تا ۲۴ ساعت قابل استفاده است. اپ باید بعد از خطای مبهم شبکه پیش از تکرار ثبت بررسی کند؛ **کلید idempotency و جلوگیری قطعی از تکرار در نسخهٔ ۱ وجود ندارد**.
۴. نشانی `voice_url` / `download_url` نسبی است و باید نسبت به **نشانی پایهٔ پوشهٔ سایت** (`/company/`، نه نسبت به endpoint دیگر) resolve شود. برای دریافت فایل، هدر Bearer را به `media.php` هم بدهید؛ در پلیر اندروید باید data source با هدر احراز هویت تنظیم شود. فقط کاربران مجاز می‌توانند فایل را دریافت کنند.

## نمونهٔ درخواست

```sh
curl -X POST 'https://best-zone.ir/company/mobile-v1.php?action=login' \
  -H 'Content-Type: application/json' \
  -d '{"identifier":"YOUR_IDENTIFIER","password":"YOUR_PASSWORD"}'

curl 'https://best-zone.ir/company/mobile-v1.php?action=calendar_today' \
  -H 'Authorization: Bearer YOUR_ACCESS_TOKEN'
```

برای فایل صوتی:

```sh
curl -X POST 'https://best-zone.ir/company/mobile-v1.php?action=voice_preview' \
  -H 'Authorization: Bearer YOUR_ACCESS_TOKEN' \
  -F 'transcript=فردا جلسه با علی' -F 'audio=@recording.wav;type=audio/wav'
```

به‌صورت معمول: ۴۰۰ دادهٔ نامعتبر، ۴۰۱ نشست نامعتبر، ۴۰۳ عدم مجوز/HTTPS، ۴۰۴ اقدام ناشناخته یا فایل غیرمجاز، ۴۰۵ متد اشتباه، ۴۲۹ محدودیت ورود و ۵۰۰ خطای داخلی است. در اندروید خطای شبکه و پاسخ نامعتبر را جداگانه مدیریت کنید و هرگز به دادهٔ ساختگی به عنوان نتیجهٔ موفق تکیه نکنید.

این API به‌تنهایی اعلان پوش بومی اندروید/FCM، همگام‌سازی آفلاین، مدیریت کامل کاربران یا پروژهٔ اندروید را پیاده‌سازی نمی‌کند. وضعیت اعلان‌ها از endpointهای بالا قابل دریافت است؛ برای اعلان در پس‌زمینهٔ اپ، یک سازوکار تحویل جداگانه و سازگار با محدودیت‌های شبکه لازم است.

---

# راهنمای کامل پیاده‌سازی و مثال‌های عملی

## ۱. شروع سریع

1. از مدیر سرور تأیید بگیرید که نسخهٔ دارای فایل `mobile-v1.php` روی سایت مستقر و HTTPS و هدر Authorization تنظیم شده‌اند. برای آزمایش، از حساب و دادهٔ آزمایشی استفاده کنید.
2. درخواست ورود را بفرستید و `access_token` واقعی پاسخ را در محل امن ذخیره کنید؛ مقادیر نمونه، توکن واقعی نیستند.
3. با همان توکن `me` را بخوانید، سپس فهرست تسک‌ها و اعلان‌ها را بارگذاری کنید.
4. برای فایل صوتی ابتدا پیش‌نمایش، سپس تأیید و ثبت را انجام دهید؛ قبل از تأیید، رکورد تسک ساخته نمی‌شود.
5. هنگام خروج، درخواست `logout` را بفرستید و توکن محلی را حتی در صورت قطع شبکه پاک کنید. در صورت قطع شبکه لغو سمت سرور تضمین نمی‌شود و توکن تا انقضا یا لغو معتبر می‌ماند.

در مثال‌های زیر `TOKEN`، `TASK_ID` و سایر مقادیر جایگزین هستند. نمونه‌های پاسخ برای خوانایی **مختصر** هستند؛ ممکن است فیلدهای بیشتری برگردند و اپ نباید به ترتیب فیلدها یا متن پیام وابسته باشد. از درج رمز یا توکن واقعی در سورس، تاریخچهٔ شِل یا گزارش‌ها خودداری کنید.

```bash
BASE='https://best-zone.ir/company'
TOKEN='YOUR_ACCESS_TOKEN'
# هدر مشترک همهٔ عملیات پس از ورود:
curl "$BASE/mobile-v1.php?action=me" -H "Authorization: Bearer $TOKEN"
```

## ۲. قواعد درخواست و پاسخ

- `action` همیشه در query string است؛ فیلدهای GET نیز در query string و فیلدهای POST معمولاً در بدنهٔ JSON قرار می‌گیرند. برای کار با فایل از multipart استفاده کنید و اجازه دهید کتابخانه boundary را تعیین کند.
- به `ok` و کد HTTP هر دو توجه کنید. پاسخ موفق معمولاً HTTP 200 دارد و ممکن است `message` و داده‌های اختصاصی عملیات را هم داشته باشد. نمونهٔ خطا:

```json
{"ok":false,"message":"به این تسک دسترسی ندارید."}
```

- `id`ها شناسهٔ عددی سرور هستند؛ مقادیر مثال واقعی نیستند. تاریخ‌های ذخیره‌شده میلادی، تاریخ‌های نمایشی مانند `due_human` شمسی و ساعت در قالب `HH:mm` است. تاریخ جاری تقویم بر اساس تنظیمات زمانی برنامه است، نه لزوماً ساعت دستگاه.
- داده‌های `null`، رشتهٔ خالی و آرایهٔ خالی ممکن‌اند؛ مقادیر فارسی را UTF-8 و پارامترهای query را URL-encode کنید. همهٔ فیلدها در تمام پاسخ‌ها حضور ندارند.
- HTTP 400 ورودی نامعتبر، 401 توکن نامعتبر/منقضی، 403 عدم اجازه یا نبود HTTPS، 404 منبع/عملیات ناموجود، 405 متد نادرست، 429 محدودیت ورود، 500 خطای داخلی و 503 عدم دسترسی پایگاه داده است. برای 429 به `Retry-After` توجه کنید؛ پس از خطای شبکه، عملیات نوشتن را بی‌بررسی خودکار تکرار نکنید.

## ۳. احراز هویت و خروج

### ورود

```bash
curl -X POST "$BASE/mobile-v1.php?action=login" \
  -H 'Content-Type: application/json' \
  --data '{"identifier":"USERNAME_OR_MOBILE","password":"PASSWORD"}'
```

`identifier` می‌تواند نام کاربری، شماره همراه، ایمیل یا کد پرسنلی ثبت‌شده باشد. برای ورود با شماره همراه، شماره باید در حساب‌های فعال یکتا باشد. نمونهٔ پاسخ کوتاه‌شده:

```json
{"ok":true,"token_type":"Bearer","access_token":"TOKEN_FROM_SERVER","idle_timeout_seconds":604800,"user":{"id":3,"full_name":"علی رضایی","role":"employee"}}
```

برای اطلاعات تازهٔ کاربر `GET ...?action=me` را صدا بزنید. پاسخ علاوه بر `user` شامل اطلاعات تاریخ، تنظیمات قابل مشاهده و پرچم‌های نقش است. هر بار ورود توکن جدید می‌دهد؛ چند دستگاه مستقل‌اند. محدودیت ورود: حداکثر ۸ تلاش در هر پنجرهٔ ۱۵ دقیقه‌ای برای هر شناسه و ۱۰۰ تلاش برای هر IP؛ تلاش‌های موفق هم شمارش می‌شوند.

### خروج

```bash
curl -X POST "$BASE/mobile-v1.php?action=logout" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -d '{}'
```

پاسخ موفق `{"ok":true,"message":"خروج انجام شد."}` است. توکن فعلی دیگر قابل استفاده نیست؛ توکن دستگاه‌های دیگر دست‌نخورده می‌ماند.

## ۴. تسک‌ها و مجوزها

`GET ...?action=assignable` فهرست `users` مجاز برای انتخاب مسئول را برمی‌گرداند. کارمند معمولی مطابق تنظیمات سایت می‌تواند برای خود یا در صورت فعال بودن مجوز، همکار تسک بسازد؛ انتخاب گروهی فقط برای مدیر و مدیر شرکت است. شناسه‌ها باید مربوط به کاربران فعال باشند.

جزئیات تسک برای مدیر شرکت، سازنده، مسئول و مدیر هم‌واحد قابل مشاهده است. در فهرست/تقویم، دامنه مطابق عملکرد فعلی برنامه اعمال می‌شود: کارمند تسک‌های ساخته یا محول‌شده به خود و مدیر فهرست تیمی/تقویم را می‌بیند؛ دیدن یک مورد در فهرست لزوماً به معنی اجازهٔ مشاهدهٔ جزئیات آن نیست. ویرایش مطابق مجوز دسترسی تسک بررسی می‌شود و حذف فقط برای سازنده یا مدیر شرکت است. مجوز را در برنامهٔ اندروید فقط از روی نقش حدس نزنید؛ پاسخ سرور ملاک است.

### فهرست و جزئیات

```bash
curl -G "$BASE/mobile-v1.php" -H "Authorization: Bearer $TOKEN" \
  --data-urlencode 'action=tasks_list' --data-urlencode 'scope=mine' \
  --data-urlencode 'status=in_progress' --data-urlencode 'search=جلسه'
curl -G "$BASE/mobile-v1.php" -H "Authorization: Bearer $TOKEN" \
  --data-urlencode 'action=task_get' --data-urlencode 'id=123'
curl "$BASE/mobile-v1.php?action=assignable" -H "Authorization: Bearer $TOKEN"
```

فیلترهای اختیاری `tasks_list`: `status`، `priority`، `scope`، `search`، `assigned_to`، `project_id`، `due_from`، `due_to` و `archived=1`. دامنهٔ پیش‌فرض `mine` است (مسئول یا سازنده). `team` برای مدیر همهٔ تسک‌ها و `assigned` برای مدیر فقط تسک‌های محول به خود را برمی‌گرداند؛ کارمند به دامنهٔ خودش محدود می‌ماند. فیلتر مسئول فقط برای مدیر اعمال می‌شود. در هر درخواست حداکثر ۲۰ تسک برمی‌گردد؛ برای نتیجه‌های بیشتر بخش صفحه‌بندی زیر را ببینید. وضعیت یا محدودهٔ نامعتبر خطای ۴۰۰ می‌دهد؛ اولویت یا تاریخ نامعتبر ممکن است نادیده گرفته شود، پس قبل از ارسال اعتبارسنجی کنید.

پاسخ فهرست نمونه:

```json
{"ok":true,"tasks":[{"id":123,"title":"جلسه هماهنگی","status":"in_progress","priority":"medium","assigned_to":3,"created_by":2,"due_date":"2026-12-01","due_time":"10:30","voice_url":null}],"count":1,"pagination":{"page":3,"per_page":20,"total":41,"total_pages":3,"has_more":false}}
```

### صفحه‌بندی و فیلتر وضعیت `tasks_list`

**تغییر مهم قرارداد:** از این نسخه، درخواست بدون `page` فقط صفحهٔ اول را می‌دهد، نه همهٔ تسک‌ها. برنامه‌های قبلی که صرفاً `tasks` را می‌خوانند باید برای دریافت بقیه صفحه‌ها به‌روزرسانی شوند. این تغییر پس از استقرار نسخهٔ جدید روی سرور فعال می‌شود؛ هم endpoint موبایل و هم API پنل از همین رفتار استفاده می‌کنند.

| پارامتر | مفهوم |
|---|---|
| `page` | شمارهٔ صفحه از ۱ تا ۹۹۹۹۹۹؛ پیش‌فرض ۱. صفحهٔ نامعتبر HTTP 400 دارد. هر صفحه ثابت و حداکثر ۲۰ مورد است. |
| `status=all` یا بدون `status` | همهٔ وضعیت‌ها در محدودهٔ دسترسی کاربر |
| `status=in_progress` | در حال انجام؛ رکوردهای قدیمی `todo` و `review` نیز در این گروه‌اند |
| `status=done` | تکمیل‌شده |
| `status=not_done` | همهٔ تسک‌های تکمیل‌نشده؛ معادل در حال انجام |

در فیلد `pagination`، `page` صفحهٔ فعلی، `per_page` عدد ثابت ۲۰، `total` تعداد کل نتایج **پس از اعمال فیلترها و مجوزها**، `total_pages` تعداد صفحه‌ها و `has_more` مشخص‌کنندهٔ وجود صفحهٔ بعد است. `count` فقط تعداد آیتم‌های همین صفحه است، نه کل. برای نتیجهٔ خالی `total_pages` صفر و `has_more` برابر false است؛ صفحهٔ بزرگ‌تر از آخرین صفحه نیز آرایهٔ خالی برمی‌گرداند. فیلترهای قدیمی `status=todo` و `status=review` صرفاً برای سازگاری مانند `in_progress` رفتار می‌کنند و در پاسخ وضعیت آن‌ها `in_progress` است؛ بنابراین رفتار `todo` نسبت به نسخهٔ قبل تغییر کرده است.

نمونهٔ دریافت صفحهٔ اول و دومِ تسک‌های تکمیل‌نشده؛ هنگام رفتن به صفحهٔ بعد **همهٔ فیلترهای قبلی را ثابت نگه دارید**:

```bash
curl -G "$BASE/mobile-v1.php" -H "Authorization: Bearer $TOKEN" \
  --data-urlencode 'action=tasks_list' --data-urlencode 'scope=mine' \
  --data-urlencode 'status=not_done' --data-urlencode 'page=1'
curl -G "$BASE/mobile-v1.php" -H "Authorization: Bearer $TOKEN" \
  --data-urlencode 'action=tasks_list' --data-urlencode 'scope=mine' \
  --data-urlencode 'status=not_done' --data-urlencode 'page=2'
```

برای تسک‌های در حال انجام یا تکمیل‌شده به‌ترتیب از `status=in_progress` یا `status=done` استفاده کنید. در پنل، با نزدیک‌شدن به انتهای نتایج صفحهٔ بعد درخواست می‌شود و دکمهٔ «نمایش تسک‌های بیشتر» نیز در دسترس است. در اندروید هم می‌توانید هنگام نزدیک‌شدن به انتهای RecyclerView، اگر `has_more` درست و درخواست دیگری در حال اجرا نیست، `page + 1` را بخوانید؛ در تغییر جست‌وجو/فیلتر فهرست را پاک و از صفحهٔ ۱ شروع کنید. از افزودن پاسخ‌های قدیمی بعد از تغییر فیلتر جلوگیری کنید.

مرتب‌سازی به‌ترتیب وضعیت (در حال انجام، تکمیل‌شده)، سررسید و شناسه است. صفحه‌بندی بر اساس offset است، نه snapshot: اگر هم‌زمان تسکی ایجاد، حذف یا ویرایش شود، ممکن است آیتمی بین صفحه‌ها جابه‌جا، تکرار یا موقتاً جا بیفتد. شناسه‌ها را هنگام افزودن تکراری نکنید و برای مشاهدهٔ دادهٔ قطعی/تازه از صفحهٔ اول دوباره بارگذاری کنید. این API endpoint دریافت نامحدود همهٔ تسک‌ها در یک پاسخ ندارد.

`task_get` یک `task` با اطلاعات تکمیلی و آرایه‌های `subtasks`، `comments`، `attachments` و `time_logs` برمی‌گرداند. برای فایل از URLهای برگردانده‌شده و توکن استفاده کنید.

### ساخت و به‌روزرسانی

```bash
curl -X POST "$BASE/mobile-v1.php?action=task_create" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"title":"جلسه هماهنگی","description":"بررسی برنامه","assigned_to":3,"due_date":"2026-12-01","due_time":"10:30","priority":"high","subtasks":["آماده‌سازی","ارسال دستور جلسه"]}'

curl -X POST "$BASE/mobile-v1.php?action=task_update" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"id":123,"status":"in_progress","progress":50}'
```

در ساخت، `title` اجباری است و اگر مسئول مشخص نشود کاربر جاری در نظر گرفته می‌شود. فیلدهای اختیاری شامل `description`، `start_date`، `due_date`، `due_time`، `status`، `priority`، `progress`، `estimated_minutes`، `recurrence`، `category`، `tags` و `subtasks` هستند. وضعیت‌ها `in_progress` و `done` هستند و مقدار پیش‌فرض `in_progress` است؛ اولویت‌ها `low`، `medium`، `high`، `urgent` و تکرارها `none`، `daily`، `weekly`، `monthly` هستند. پیشرفت بین صفر و صد محدود می‌شود و وضعیت `done` آن را ۱۰۰ می‌کند. حداکثر ۱۲ زیرتسک هنگام ساخت ثبت می‌شود. مقادیر نامعتبر وضعیت و اولویت هنگام ساخت به مقدار پیش‌فرض تبدیل می‌شوند؛ تاریخ و ساعت نامعتبر خطا می‌دهند.

برای ایجاد گروهی مدیر می‌تواند `"assigned_ids":[3,4]` یا `"assign_all":true` را جایگزین `assigned_to` کند. پاسخ `ids` شناسهٔ همهٔ تسک‌های مستقل و `task` جزئیات اولین مورد را دارد. به‌روزرسانی برای هر شناسه جداست؛ ارسال `assigned_ids` در ویرایش، عملیات گروهی نیست. برای پاک کردن تاریخ/ساعت در ویرایش می‌توان رشتهٔ خالی فرستاد.

```bash
curl -X POST "$BASE/mobile-v1.php?action=task_delete" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -d '{"id":123}'
```

### ثبت نظر متنی یا صوتی

```bash
curl -X POST "$BASE/mobile-v1.php?action=comment_add" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"task_id":123,"body":"کار انجام شد."}'

curl -X POST "$BASE/mobile-v1.php?action=comment_add" \
  -H "Authorization: Bearer $TOKEN" -F 'task_id=123' \
  -F 'body=توضیح صوتی' -F 'voice_duration_sec=10' -F 'voice=@recording.wav;type=audio/wav'
```

حداقل یکی از متن یا فایل صوتی لازم است. پاسخ موفق شناسهٔ نظر را در `id` برمی‌گرداند؛ سپس جزئیات تسک را دوباره دریافت کنید.

## ۵. تقویم، اطلاعیه و اعلان

```bash
curl "$BASE/mobile-v1.php?action=calendar_today" -H "Authorization: Bearer $TOKEN"
curl "$BASE/mobile-v1.php?action=calendar_month&jy=1405&jm=9" -H "Authorization: Bearer $TOKEN"
curl "$BASE/mobile-v1.php?action=announcements_list" -H "Authorization: Bearer $TOKEN"
curl "$BASE/mobile-v1.php?action=notifications_unread" -H "Authorization: Bearer $TOKEN"
curl "$BASE/mobile-v1.php?action=notifications_list" -H "Authorization: Bearer $TOKEN"
```

تقویم امروز `date` میلادی، `date_human` و `tasks` دارد. تقویم ماه `calendar.days` و آرایهٔ تسک هر روز را برمی‌گرداند؛ `jy` و `jm` سال و ماه شمسی‌اند. اطلاعیه‌ها به اطلاعیهٔ عمومی و واحد کاربر محدود و حداکثر ۱۰۰ مورد هستند. اعلان‌های کاربر حداکثر ۵۰ مورد اخیرند؛ شمارندهٔ خوانده‌نشده کل اعلان‌های خوانده‌نشده را می‌شمارد.

```bash
curl -X POST "$BASE/mobile-v1.php?action=notification_read" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -d '{"id":45}'
curl -X POST "$BASE/mobile-v1.php?action=notifications_read_all" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' -d '{}'
```

برای اعلان تکی، `already_read` مشخص می‌کند از قبل خوانده شده بود یا نه. اعلان متعلق به کاربر دیگر قابل تغییر نیست. این API به‌تنهایی push اندروید را فعال نمی‌کند؛ polling را با توجه به مصرف باتری و سیاست‌های اندروید طراحی کنید.

## ۶. مثال کامل ثبت صوت

۱. فایل/متن را به `voice_preview` بفرستید؛ در صورت ارسال فایل، نام فیلد `audio` است:

```bash
curl -X POST "$BASE/mobile-v1.php?action=voice_preview" \
  -H "Authorization: Bearer $TOKEN" -F 'transcript=فردا جلسه با علی' \
  -F 'voice_duration_sec=10' -F 'audio=@recording.wav;type=audio/wav'
```

پسوندهای معمول قابل دریافت: `webm`, `ogg`, `mp3`, `wav`, `m4a`, `mp4`, `aac`, `opus`, `flac`؛ حجم پیش‌فرض حداکثر ۲۰ مگابایت و ممکن است محدودیت وب‌سرور/PHP کمتر باشد. اگر فقط متن دارید می‌توانید JSON شامل `transcript` بفرستید. پاسخ پیش‌نمایش `tasks`، `transcript`، `provider`، `requires_review` و در صورت ارسال فایل `voice_file` و `voice_url` دارد.

۲. کاربر عنوان، تاریخ و مسئولان را تأیید یا اصلاح کند. سپس مقادیر واقعی پاسخ را در نمونهٔ زیر جایگزین کنید:

```bash
curl -X POST "$BASE/mobile-v1.php?action=voice_create" \
  -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
  -d '{"tasks":[{"title":"جلسه با علی","assigned_to":3,"due_date":"2026-12-01"}],"voice_file":"VOICE_FILE_FROM_PREVIEW","transcript":"فردا جلسه با علی","provider":"PROVIDER_FROM_PREVIEW","voice_duration_sec":10}'
```

آرایهٔ `tasks` باید ۱ تا ۱۰۰ آیتم داشته باشد و مجموع تسک‌های ساخته‌شده بعد از گسترش مسئولان حداکثر ۱۰۰۰ است. پاسخ شامل `ids` و `tasks` ساخته‌شده است. نام فایل اختیاری است، اما اگر ارائه شود باید فایل پیش‌نمایش متعلق به همان توکن و مربوط به کمتر از ۲۴ ساعت قبل باشد. هر دسته به‌صورت کامل ثبت یا رد می‌شود. برای دسترسی به صوت مشترک، مجوز هر یک از تسک‌های مرتبط بررسی می‌شود.

۳. برای دریافت صوت، URL نسبی برگشتی را با پایهٔ `https://best-zone.ir/company/` ترکیب و هدر Bearer ارسال کنید؛ فایل را در پوشهٔ عمومی یا لاگ قرار ندهید:

```bash
curl -H "Authorization: Bearer $TOKEN" \
  "$BASE/media.php?type=voice&file=VOICE_FILE_FROM_RESPONSE" -o recording.wav
```

## ۷. نمونهٔ اندروید با Kotlin و OkHttp

نمونه صرفاً برای نشان‌دادن شکل درخواست است؛ فراخوانی شبکه باید روی رشتهٔ پس‌زمینه/Coroutine انجام شود، وابستگی OkHttp به پروژه اضافه شود و مجوز `android.permission.INTERNET` در manifest تعریف شود. رمز و توکن واقعی را در کد قرار ندهید.

```kotlin
val client = OkHttpClient()
val url = "https://best-zone.ir/company/mobile-v1.php?action=login"
val json = JSONObject()
    .put("identifier", identifier) // از ورودی کاربر
    .put("password", password)
val body = json.toString().toRequestBody("application/json; charset=utf-8".toMediaType())
val request = Request.Builder().url(url).post(body).build()
client.newCall(request).execute().use { response ->
    val text = response.body?.string() ?: error("پاسخ خالی")
    val result = JSONObject(text)
    if (!response.isSuccessful || !result.optBoolean("ok")) {
        error(result.optString("message", "خطای سرور"))
    }
    val token = result.getString("access_token")
    // token را با ذخیره‌سازی رمزگذاری‌شده و Android Keystore نگه دارید.
}
```

برای فراخوانی بعدی، درخواست را با `.header("Authorization", "Bearer $token")` بسازید. از importهای `okhttp3.*`، `okhttp3.MediaType.Companion.toMediaType`، `okhttp3.RequestBody.Companion.toRequestBody` و `org.json.JSONObject` استفاده کنید. در ۴۰۱ نشست محلی را پاک کنید؛ در خطاهای دیگر، پیام امن و امکان تلاش مجدد مناسب نمایش دهید. برای فایل‌ها هم از data source دارای همین هدر استفاده کنید.

## ۸. چک‌لیست تحویل و عیب‌یابی

- اگر پاسخ ۴۰۳ قبل از ورود دریافت می‌شود، HTTPS و تشخیص آن در PHP و تنظیم پروکسی را بررسی کنید؛ هدر ارسالی کاربر را بدون پروکسی مورد اعتماد مبنای اعتماد قرار ندهید.
- اگر ورود موفق ولی درخواست بعدی ۴۰۱ است، تحویل هدر Authorization به PHP، انقضای هفت‌روزه، تغییر رمز و فعال‌بودن حساب را بررسی کنید. از ارسال `Bearer` در query خودداری کنید.
- اگر نصب ۵۰۳ می‌دهد، اتصال پایگاه داده، مجوز ایجاد جدول و لاگ سرور را بررسی کنید. داده یا خطای اتصال داخلی نباید در اپ نمایش داده شود.
- اگر فایل باز نمی‌شود، نشانی را نسبت به `/company/` resolve کنید، هدر را برای خود فایل هم بفرستید و مجوز تسک/انقضای پیش‌نمایش را بررسی کنید.
- مسیرهای ثبت تکرارناپذیری تضمین‌شده ندارند؛ در timeout قبل از تکرار، داده‌های اخیر را بررسی و از کاربر تأیید بگیرید.
- قبل از انتشار، ورود، خروج، محدودیت ورود، حساب غیرفعال، مجوز نقش‌ها، تسک تکی و گروهی، تقویم، اعلان و صوت را در محیط staging با کاربران واقعی آزمایشی و وب‌سرور عملیاتی آزمایش کنید. مخزن و نمونه‌های این سند اثبات استقرار یا سلامت سایت اصلی نیستند.

**سازگاری وضعیت قدیمی:** وضعیت‌های قبلی `todo` و `review` در خروجی و فیلتر به `in_progress` نگاشت می‌شوند؛ ارسال این مقادیر قدیمی برای ساخت یا ویرایش نیز `in_progress` ذخیره می‌کند. رکوردهای قبلی بدون حذف متادیتا نگهداری می‌شوند.
