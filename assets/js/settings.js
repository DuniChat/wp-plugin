/*
============================================
اسکریپت پنل تنظیمات افزونه دانیچَت (AI Agent)
این فایل قبلاً به‌صورت inline (wp_add_inline_script) داخل settings.php
قرار داشت و بدون هیچ تغییری در عملکرد، فقط به این فایل مجزا منتقل شده
تا با ساختار استاندارد پلاگین‌های وردپرس هماهنگ باشد.

وابستگی‌ها (باید قبل از این فایل enqueue شوند):
  - jquery
  - wp-color-picker
  - ai-agent-chartjs (Chart.js)

متغیرهای مورد استفاده در این فایل (ajaxurl) به‌صورت خودکار توسط
خود وردپرس در پیشخوان تعریف می‌شوند و نیازی به localize کردن ندارند.
============================================
*/

    jQuery(function($){

        /*
        ارقام فارسی و تبدیل ریال به تومان.

        در سطح بالای فایل تعریف شده‌اند چون هم بخش مدل‌ها و هم فهرست
        گفت‌وگوها به آن‌ها نیاز دارند، و دو پیاده‌سازی جدا یعنی دو جای
        متفاوت برای گروه‌بندی متفاوتِ همان عدد.
        */
        function aiAgentFaDigits(value) {
            return String(value).replace(/[0-9]/g, function (d) {
                return '۰۱۲۳۴۵۶۷۸۹'[d];
            });
        }

        function aiAgentToman(amountIrr) {
            var value = Math.round(Number(amountIrr) / 10);
            if (!isFinite(value)) return '';
            return aiAgentFaDigits(String(value).replace(/\B(?=(\d{3})+(?!\d))/g, '٬'));
        }

        /*
        ============================================
        تبدیل رشته‌ی تاریخِ سرور به شیء Date — نسخه‌ی سازگار با همه‌ی مرورگرها

        قبلاً مستقیماً new Date(created_at) صدا زده می‌شد. دو مشکل داشت:
            ۱) در سافاری، رشته‌ی با جداکننده‌ی فاصله مثل
               «2026-10-02 07:15:30» غلطِ Invalid می‌دهد و تاریخِ خام
               سرور نمایش داده می‌شد.
            ۲) میکروثانیه‌ی شش‌رقمی (خروجی مرسوم سرور) در بعضی مرورگرها
               parse نمی‌شد.

        این تابع رشته را نرمال‌سازی می‌کند (فاصله → T، میکروثانیه →
        میلی‌ثانیه) و اگر تاریخ معتبر نبود null برمی‌گرداند تا فراخوانی
        بتواند به نمایش خام برگردد. رشته‌های دارای منطقه‌ی زمانی (Z یا
        ‎±hh:mm) توسط خود مرورگر به وقتِ محلی تبدیل می‌شوند و رشته‌های
        بدون منطقه‌ی زمانی مثل قبل به وقتِ محلیِ مرورگر تفسیر می‌شوند.
        ============================================
        */
        function aiAgentParseServerDate(value) {
            if (!value) return null;
            var s = String(value).trim();
            if (!s) return null;

            var m = s.match(/^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2})(?::(\d{2}))?(?:\.(\d+))?(Z|[+-]\d{2}:?\d{2})?$/);
            if (!m) {
                var fallback = new Date(s);
                return isNaN(fallback.getTime()) ? null : fallback;
            }

            // «2026-10-02 07:15:30.123456+00:00» → «2026-10-02T07:15:30.123+00:00»
            var iso = m[1] + '-' + m[2] + '-' + m[3] + 'T' + m[4] + ':' + m[5] + ':' + (m[6] || '00');
            if (m[7]) {
                iso += '.' + ('000' + m[7]).slice(0, 3); // سه رقم اول (میلی‌ثانیه)
            }
            iso += m[8] || '';

            var dt = new Date(iso);
            return isNaN(dt.getTime()) ? null : dt;
        }

        /*
        ============================================
        Escape HTML و تبدیل ساده و امن مارک‌داون به HTML
        (نسخه‌ی مشابه ai-agent.js، برای استفاده در پیش‌نمایش
        تاریخچه‌ی چت داخل پنل تنظیمات — تب «بارگذاری چت»)

        فقط دو حالت پشتیبانی می‌شود چون فقط همین دو مورد از سمت
        مدل استفاده می‌شود:
            **متن پررنگ**              →  <strong>متن پررنگ</strong>
            [عنوان لینک](https://...)  →  <a href="...">عنوان لینک</a>

        برای جلوگیری از XSS، ابتدا کل متن با escapeHtml امن می‌شود و
        سپس الگوهای بالا روی متنِ امن‌شده اعمال می‌گردند.
        ============================================
        */
        function aiAgentEscapeHtml(text) {
            var div = document.createElement('div');
            div.textContent = text == null ? '' : String(text);
            return div.innerHTML;
        }

        function aiAgentRenderInlineMarkdown(rawText) {
            if (!rawText) return '';

            var html = aiAgentEscapeHtml(rawText);

            // بولد: **متن**
            html = html.replace(/\*\*([^\*\n]+)\*\*/g, '<strong>$1</strong>');

            // لینک: [عنوان](URL) — فقط http/https پذیرفته می‌شود
            html = html.replace(
                /\[([^\[\]]+)\]\((https?:\/\/[^\s()]+)\)/g,
                '<a href="$2" target="_blank" rel="noopener noreferrer">$1</a>'
            );

            // شکستن خط
            html = html.replace(/\n/g, '<br>');

            return html;
        }

        /*
        ============================================
        کنترل‌های ساخته‌شده در PHP: سگمنت‌ها، کاشی‌ها، سوییچ‌های رنگ

        همه‌ی این‌ها یک input رادیویی یا چک‌باکسِ پنهان دارند و ظاهرشان
        از کلاس is-active می‌آید. مرورگر خودش وضعیت input را عوض می‌کند؛
        این‌جا فقط کلاس با آن هم‌گام می‌شود تا فرم و ظاهر یکی بمانند.
        ============================================
        */
        function aiAgentSyncRadioGroup($input) {
            var name = $input.attr('name');
            if (!name) return;
            $('input[name="' + name + '"]').each(function () {
                $(this).closest('.ai-agent-segment').toggleClass('is-active', this.checked);
            });
        }

        $('.ai-agent-segment input[type="radio"]').on('change', function () {
            aiAgentSyncRadioGroup($(this));
        });

        $('.ai-agent-tile input[type="checkbox"]').on('change', function () {
            $(this).closest('.ai-agent-tile').toggleClass('is-active', this.checked);
        });

        /*
        ============================================
        سوییچ‌های رنگ پس‌زمینه‌ی چت

        مقدار در یک input مخفی می‌نشیند و همان است که ذخیره می‌شود؛
        دایره‌ها فقط راهِ انتخابش هستند. change روی input مخفی دستی
        شلیک می‌شود، وگرنه ذخیره‌ی خودکار متوجه تغییر نمی‌شود.
        ============================================
        */
        $('.ai-agent-swatches[data-swatch-group]').each(function () {
            var $group = $(this);
            var name   = $group.attr('data-swatch-group');
            /*
            پالت رنگ اصلی روی همان فیلد کد رنگ می‌نویسد (که خودش هم
            دستی قابل تایپ است)؛ بقیه‌ی پالت‌ها input مخفی خودشان را
            دارند. هر دو با همین یک شناسه پیدا می‌شوند.
            */
            var $value = $('#ai_agent_' + name);
            if (!$value.length) return;

            $group.on('click', '.ai-agent-swatch', function (e) {
                e.preventDefault();
                var hex = $(this).attr('data-hex');
                if (!hex) return;
                $group.find('.ai-agent-swatch').removeClass('is-active');
                $(this).addClass('is-active');
                $value.val(hex.toUpperCase()).trigger('change');
            });
        });

        /*
        ============================================
        فیلد توکن سایت — چشمِ نمایش/مخفی + دکمه‌ی حذف توکن

        - چشم داخلِ خود اینپوت، سمت راست، با همان زبان بصری آیکون‌های
          خطی افزونه (SVG با stroke=currentColor) است. کلیک روی آن
          نوع فیلد را بین password و text عوض می‌کند تا توکن
          نمایش/مخفی شود؛ آیکون هم بین چشم و چشم‌خط‌خورده جابه‌جا
          می‌شود.
        - متن توکن خودش چپ‌چین و LTR است (کلاس dc-ltr + lang="en"
          روی اینپوت در سمت PHP).
        - دکمه‌ی «حذف توکن» با تأیید کاربر، فیلد را خالی و پرچمِ
          مخفیِ api_key_delete را ۱ می‌کند؛ بعد از کلیک روی «ذخیره
          تنظیمات»، کلید ذخیره‌شده در دیتابیس هم به‌طور کامل پاک
          می‌شود (سمت PHP). اگر کاربر قبل از ذخیره توکن جدیدی تایپ
          کند، پرچم دوباره صفر می‌شود — یعنی توکن جدید جایگزین می‌شود
          نه اینکه حذف شود.
        ============================================
        */
        (function aiAgentTokenField() {
            var $input = $('#ai_agent_api_key');
            if (!$input.length) return;

            var $eye    = $('#ai-agent-token-eye');
            var $eyeOn  = $eye.find('.ai-agent-token-eye-on');   // چشم (وقتی توکن مخفی است)
            var $eyeOff = $eye.find('.ai-agent-token-eye-off');  // چشم‌خط‌خورده (وقتی توکن نمایان است)
            var $delete = $('#ai-agent-token-delete');
            var $deleteFlag = $('#ai_agent_api_key_delete');

            function setEyeVisible(visible) {
                $input.attr('type', visible ? 'text' : 'password');
                $eyeOn.css('display', visible ? 'none' : 'block');
                $eyeOff.css('display', visible ? 'block' : 'none');
                var label = visible ? 'مخفی کردن توکن' : 'نمایش توکن';
                $eye.attr('title', label).attr('aria-label', label);
            }

            $eye.on('click', function () {
                var willShow = ($input.attr('type') === 'password');
                setEyeVisible(willShow);
                $input.trigger('focus');
            });

            // اگر توکن جدیدی تایپ شد، درخواستِ «حذف» لغو می‌شود
            $input.on('input change', function () {
                if (String($(this).val() || '') !== '' && $deleteFlag.length) {
                    $deleteFlag.val('0');
                }
            });

            if ($delete.length && $deleteFlag.length) {
                $delete.on('click', function () {
                    var confirmed = window.confirm(
                        'آیا مطمئن هستید که می‌خواهید توکن را حذف کنید؟\n\n' +
                        'فیلد توکن خالی می‌شود و بعد از کلیک روی «ذخیره تنظیمات»، ' +
                        'توکن ذخیره‌شده به‌طور کامل از دیتابیس پاک می‌شود.'
                    );
                    if (!confirmed) return;

                    $input.val('');
                    $deleteFlag.val('1');
                    setEyeVisible(false); // برگشت به حالت password
                });
            }
        })();

        /*
        ============================================
        دو نمای صفحه — تنظیمات و گفت‌وگوها

        قبلاً دو آدرس جدا بودند و هر رفت‌وبرگشت یعنی بارگذاری کامل صفحه
        و یک کال دوباره به سرور همگام‌سازی. حالا فقط کلاس عوض می‌شود.
        نمای گفت‌وگوها اولین بار که باز شود، فهرستش را می‌گیرد — نه
        هنگام لود صفحه، چون بیشتر بازدیدها اصلاً سراغش نمی‌روند.
        ============================================
        */
        (function aiAgentViews() {
            var $tabs = $('.ai-agent-tab[data-view]');
            if (!$tabs.length) return;
            var historyLoaded = false;

            $tabs.on('click', function () {
                var view = $(this).attr('data-view');
                if (!view) return;

                $tabs.removeClass('is-active').attr('aria-selected', 'false');
                $(this).addClass('is-active').attr('aria-selected', 'true');

                $('.ai-agent-view').removeClass('is-active');
                $('.ai-agent-view[data-view-panel="' + view + '"]').addClass('is-active');

                if (view === 'history' && !historyLoaded) {
                    historyLoaded = true;
                    if (typeof aiAgentSessions === 'object' && aiAgentSessions && typeof aiAgentSessions.load === 'function') {
                        aiAgentSessions.load();
                    }
                }
            });
        })();

        /*
        ============================================
        رنگ پرایمری چت‌بات: دکمه‌های رنگ‌های سایت + پالت آماده + کد
        دستی + پالتِ ساخت رنگ (کلیک روی دایره‌ی رنگ) و پیش‌نمایش زنده‌ی
        رنگ حالت تاریک (که فقط نمایش داده می‌شود، خودِ کاربر آن را
        دستی عوض نمی‌کند — همان الگوریتمی که سرور برای ذخیره‌سازی
        استفاده می‌کند، این‌جا هم برای پیش‌نمایش فوری تکرار شده است).

        نسخه‌ی جدید: رنگ حالت تاریک هم یک picker کامل دارد (پالت آماده،
        دایره‌ی رنگ و کد هگز). یک فیلد مخفی color_dark_custom نشان
        می‌دهد آیا کاربر رنگ تاریک را دستی عوض کرده (1) یا هنوز روی
        پیشنهاد خودکار است (0). در حالتِ auto، با هر تغییرِ رنگِ روشن،
        رنگِ تاریک هم به‌صورت خودکار به پیشنهادِ جدید به‌روز می‌شود.
        در حالتِ custom، رنگِ تاریکِ کاربر دست‌نخورده باقی می‌ماند.
        کاربر می‌تواند با کلیک روی «استفاده از این پیشنهاد» به حالتِ auto
        برگردد.
        ============================================
        */
        (function aiAgentAssistantColor() {
            var $light = $('#ai_agent_color_light');
            if (!$light.length) return;

            // عناصرِ سمتِ روشن
            var $lightDot = $('#ai-agent-color-light-dot');
            var $picker   = $('#ai-agent-color-picker');

            // عناصرِ سمتِ تاریک
            var $dark           = $('#ai_agent_color_dark');
            var $darkDot        = $('#ai-agent-color-dark-dot-btn');
            var $darkPicker     = $('#ai-agent-color-dark-picker');
            var $darkCustom     = $('#ai_agent_color_dark_custom');
            var $suggestionDot  = $('#ai-agent-dark-suggestion-dot');
            var $suggestionValue = $('#ai-agent-dark-suggestion-value');
            var $useSuggestionBtn = $('#ai-agent-dark-use-suggestion');

            /*
            همان فرمول سرور (ai_agent_lighten_hex با AI_AGENT_DARK_LIFT)
            تا پیش‌نمایشِ این‌جا با چیزی که بعد از ذخیره روی سایت می‌نشیند
            یکی باشد.
            */
            function autoDark(hex) {
                var m = /^#([0-9a-f]{6})$/i.exec(hex || '');
                if (!m) return null;
                var n = parseInt(m[1], 16);
                var amount = (typeof aiAgentAdmin === 'object' && aiAgentAdmin && aiAgentAdmin.darkLift)
                    ? parseFloat(aiAgentAdmin.darkLift) : 0.28;
                var lift = function(c) { return Math.round(c + (255 - c) * amount); };
                var r = lift((n >> 16) & 255), g = lift((n >> 8) & 255), b = lift(n & 255);
                return '#' + [r, g, b].map(function(v) { return v.toString(16).padStart(2, '0'); }).join('').toUpperCase();
            }

            function isHex(s) {
                return typeof s === 'string' && /^#[0-9a-fA-F]{6}$/.test(s);
            }

            // به‌روزرسانیِ پیش‌نمایشِ پیشنهادِ خودکار
            function refreshSuggestion() {
                var suggested = autoDark($light.val());
                if (!suggested) return;
                $suggestionDot.css('background', suggested);
                $suggestionValue.text(suggested);
            }

            // به‌روزرسانیِ پیش‌نمایشِ رنگِ روشن (دایره + پالت‌ها + picker)
            function refreshLightPreview() {
                var hex = String($light.val() || '').toUpperCase();
                if (!isHex(hex)) return;
                $lightDot.css('background', hex);
                $('.ai-agent-site-color-btn').each(function () {
                    $(this).toggleClass('is-active', String($(this).attr('data-hex')).toUpperCase() === hex);
                });
                $('.ai-agent-swatches[data-swatch-group="color_light"] .ai-agent-swatch').each(function () {
                    $(this).toggleClass('is-active', String($(this).attr('data-hex')).toUpperCase() === hex);
                });
                if ($picker.length) $picker.val(hex);
            }

            // به‌روزرسانیِ پیش‌نمایشِ رنگِ تاریک (دایره + پالت‌ها + picker)
            function refreshDarkPreview() {
                var hex = String($dark.val() || '').toUpperCase();
                if (isHex(hex)) {
                    $darkDot.css('background', hex);
                    if ($darkPicker.length) $darkPicker.val(hex);
                } else {
                    // اگر کاربر فیلد را خالی کرد، روی پیشنهاد خودکار نشان بده
                    var suggested = autoDark($light.val());
                    if (suggested) $darkDot.css('background', suggested);
                }
                $('.ai-agent-swatches[data-swatch-group="color_dark"] .ai-agent-swatch').each(function () {
                    var swatchHex = String($(this).attr('data-hex')).toUpperCase();
                    $(this).toggleClass('is-active', isHex(hex) && swatchHex === hex);
                });
            }

            /*
            به‌روزرسانیِ کاملِ صفحه: ابتدا پیشنهاد خودکار از رنگِ روشن
            ساخته می‌شود، بعد اگر کاربر هنوز روی حالتِ auto است، فیلدِ
            تاریک به‌صورت خودکار با پیشنهادِ جدید پر می‌شود. در غیر
            این‌صورت، رنگِ تاریکِ کاربر دست‌نخورده باقی می‌ماند.
            */
            function refreshAll() {
                refreshLightPreview();
                refreshSuggestion();

                if ($dark.length && $darkCustom.length && $darkCustom.val() === '0') {
                    var suggested = autoDark($light.val());
                    if (suggested) {
                        $dark.val(suggested);
                        refreshDarkPreview();
                    }
                }
            }

            // رنگِ روشن: هر تغییر باید پیشنهادِ تاریک را هم بسازد
            $light.on('input change', refreshAll);

            // رنگِ تاریک: هر تغییرِ کاربر یعنی حالتِ custom
            $dark.on('input change', function () {
                var val = $dark.val();
                if (isHex(val)) {
                    $darkCustom.val('1');
                } else {
                    // فیلد خالی/نامعتبر → حالتِ auto
                    $darkCustom.val('0');
                }
                refreshDarkPreview();
            });

            refreshAll();

            // کارت رنگ سایت: با یک کلیک همان رنگ، رنگ پرایمری می‌شود.
            $('.ai-agent-site-color-btn').on('click', function() {
                var hex = $(this).attr('data-hex');
                if (!hex) return;
                $light.val(hex).trigger('change');
                $('.ai-agent-site-color-btn').removeClass('is-active');
                $(this).addClass('is-active');
            });

            /*
            پالتِ ساخت رنگِ روشن: کلیک روی دایره‌ی رنگ، پالت رنگ مرورگر
            را باز می‌کند؛ رنگی که کاربر همان‌جا می‌سازد بلافاصله در فیلد
            کد و پیش‌نمایش‌ها می‌نشیند. انتخاب رنگ اجباری نیست — هر چه
            باشد، تایپ دستی کد هگز هم همچنان کار می‌کند.
            */
            if ($picker.length && $lightDot.length) {
                $lightDot.on('click', function () {
                    $picker.trigger('click');
                });
                $picker.on('input change', function () {
                    var hex = String($(this).val() || '').toUpperCase();
                    if (isHex(hex)) {
                        $light.val(hex).trigger('change');
                    }
                });
            }

            /*
            پالتِ آماده برای حالت تاریک: کلیک روی هر swatch آن رنگ را
            در فیلدِ تاریک می‌نویسد و حالتِ custom را فعال می‌کند.
            */
            $('.ai-agent-swatches[data-swatch-group="color_dark"]').on('click', '.ai-agent-swatch', function (e) {
                e.preventDefault();
                var hex = $(this).attr('data-hex');
                if (!hex || !isHex(hex)) return;
                $('.ai-agent-swatches[data-swatch-group="color_dark"] .ai-agent-swatch').removeClass('is-active');
                $(this).addClass('is-active');
                $dark.val(hex).trigger('change');
            });

            /*
            پالتِ ساخت رنگِ تاریک: کلیک روی دایره‌ی تاریک، پالت رنگ
            مرورگر را باز می‌کند؛ رنگِ ساخته‌شده در فیلدِ تاریک و دایره
            می‌نشیند.
            */
            if ($darkPicker.length && $darkDot.length) {
                $darkDot.on('click', function () {
                    $darkPicker.trigger('click');
                });
                $darkPicker.on('input change', function () {
                    var hex = String($(this).val() || '').toUpperCase();
                    if (isHex(hex)) {
                        $dark.val(hex).trigger('change');
                    }
                });
            }

            /*
            دکمه‌ی «استفاده از این پیشنهاد»: رنگِ تاریک را به پیشنهادِ
            خودکارِ فعلی (بر اساس رنگِ روشن) برمی‌گرداند و حالتِ auto
            را فعال می‌کند. این‌طوری کاربر هر وقت پشیمان شد از سفارشی‌سازی
            می‌تواند به‌راحتی برگردد.
            */
            if ($useSuggestionBtn.length) {
                $useSuggestionBtn.on('click', function () {
                    var suggested = autoDark($light.val());
                    if (!suggested) return;
                    $darkCustom.val('0');
                    $dark.val(suggested);
                    refreshDarkPreview();
                });
            }
        })();
        /*
        ============================================
        تب‌های دستگاه (موبایل / تبلت / دسکتاپ) — بخش «موقعیت آیکون افزونه»

        با کلیک روی هر آیکون، پنل تنظیمات همان دستگاه (سمت قرارگیری +
        جابجایی عمودی) نمایش داده می‌شود؛ هر دستگاه می‌تواند مقادیری
        مستقل و متفاوت داشته باشد. پنل‌های دیگر با CSS مخفی می‌شوند
        اما در DOM باقی می‌مانند تا مقادیر هر سه دستگاه هنگام ذخیره‌ی
        فرم ارسال شوند.
        ============================================
        */
        var $deviceTabs = $('#ai-agent-device-tabs');
        if ($deviceTabs.length) {
            $deviceTabs.on('click', '.ai-agent-device-tab', function () {
                var device = $(this).data('device');
                if (!device) return;

                // فعال‌کردن تب کلیک‌شده و غیرفعال‌کردن بقیه
                $deviceTabs.find('.ai-agent-device-tab')
                    .removeClass('is-active')
                    .attr('aria-selected', 'false');
                $(this).addClass('is-active').attr('aria-selected', 'true');

                // نمایش پنل تنظیمات همان دستگاه
                $('.ai-agent-device-panel').removeClass('is-active');
                $('.ai-agent-device-panel[data-device-panel="' + device + '"]').addClass('is-active');
            });
        }

        /*
        ============================================
        ردیف‌های شماره‌ی پشتیبانی: افزودن/حذف، حداکثر ۵ ردیف
        ============================================

        /*
        ============================================
        ذخیره‌ی تنظیمات — فقط با دکمه‌ی «ذخیره تنظیمات»

        برخلاف طراحیِ جدید که هر فیلد را لحظه‌ای ذخیره می‌کرد، این‌جا
        منطق نسخه‌ی فعلی افزونه حفظ شده است: هیچ ذخیره‌ی خودکاری در
        کار نیست و کل فرم با ارسال استاندارد وردپرس (options.php) فقط
        با کلیک کاربر روی «ذخیره تنظیمات» ثبت می‌شود.
        ============================================
        */

        // فرمت‌بندی مبلغ ریالی با جداکننده‌ی هزارگان (مثال: 150000 → 150,000 ریال)
        function aiAgentFormatIrr(amount) {
            var n = Number(amount);
            if (isNaN(n)) return String(amount) + ' ریال';
            return n.toLocaleString('en-US') + ' ریال';
        }


        // ----- موجودی کیف پول -----
        // پاسخ اندپوینتِ نسخه‌ی فعلی افزونه فقط balance_irr می‌دهد؛ پس همان
        // فرمت‌کننده‌ی قبلی استفاده می‌شود. بارگذاری خودکار هنگام باز شدن صفحه
        // و بعد هر ۲۰ ثانیه یک‌بار (بدون چشمک‌زدنِ متنِ «در حال دریافت»).
        // ----- موجودی کیف پول -----
        // دکمه‌ی بروزرسانی موجودی اکنون یک ایکون دایره‌ای سینک است (نه دکمه‌ی متنی).
        // به جای تغییر متن دکمه، کلاس is-loading روی آن toggle می‌شود که باعث می‌شود
        // ایکون سینک به چرخش درآید. متن دکمه هرگز نمایش داده نمی‌شود، فقط ایکون.
        function aiAgentLoadWalletBalance(showLoadingUI) {
            var $valueEl  = $('#ai-agent-wallet-balance-value');
            var $statusEl = $('#ai-agent-wallet-balance-status');
            var $btn      = $('#ai-agent-wallet-balance-refresh-btn');
            var token     = $('#ai_agent_wallet_balance_nonce_field').val();

            if (!token || !$valueEl.length) return; // یعنی این بخش در صفحه وجود ندارد

            if (showLoadingUI) {
                $btn.prop('disabled', true).addClass('is-loading');
            }
            $statusEl.text('در حال دریافت موجودی...');

            $.ajax({
                url: ajaxurl,
                method: 'POST',
                data: {
                    action: 'ai_agent_get_wallet_balance',
                    nonce: token
                },
                success: function(response) {
                    $btn.prop('disabled', false).removeClass('is-loading');
                    if (response.success) {
                        $valueEl.text(aiAgentFormatIrr(response.data.balance_irr));
                        $statusEl.text('');
                    } else {
                        var msg = (response.data && response.data.message) ? response.data.message : 'خطا در دریافت موجودی کیف پول.';
                        $statusEl.text(msg);
                    }
                },
                error: function() {
                    $btn.prop('disabled', false).removeClass('is-loading');
                    $statusEl.text('خطای غیرمنتظره در ارتباط با پردازشگر محلی وردپرس رخ داد.');
                }
            });
        }

        $('#ai-agent-wallet-balance-refresh-btn').on('click', function(e) {
            e.preventDefault();
            aiAgentLoadWalletBalance(true);
        });

        // اجرای خودکار هنگام باز شدن صفحه‌ی تنظیمات، و بعد از آن هر ۲۰ ثانیه یک‌بار
        if ($('#ai-agent-wallet-balance-value').length) {
            aiAgentLoadWalletBalance(false);
            window.setInterval(function() { aiAgentLoadWalletBalance(false); }, 20000);
        }

        // نوار اعلان‌ها حذف شد — یک کارت که فقط یک آیکون بلندگو داشت و
        // معلوم نبود چیست؛ اعلان‌های واقعی جای بهتری برای رسیدن به کاربر دارند.

        // ----- انتخاب موقعیت آیکون با کشیدن -----
        // هر دستگاه یک ماکت است و آیکون داخلش کشیدنی. کشیدن، هم سمت و هم فاصله
        // را تعیین می‌کند؛ فیلدهای عددی همان مقادیر را نگه می‌دارند تا فرم بدون
        // تغییرِ ساختار ارسال شود و اگر جاوااسکریپت اجرا نشد، بخش «تنظیم دقیق»
        // همچنان کار کند.
        (function aiAgentPositionPicker() {
            var $stages = $('.ai-agent-stage');
            if (!$stages.length) return;

            // The offset is stored in real page pixels, but the mock is much
            // smaller than a phone, so it is scaled for display. Without this a
            // 200px offset would push the handle clean out of the mock.
            var STAGE_RANGE = { mobile: 400, tablet: 500, desktop: 600 };

            function clamp(value, min, max) {
                return Math.min(max, Math.max(min, value));
            }

            function apply($stage, side, offset, writeInputs) {
                var device = $stage.data('stage');
                var range  = STAGE_RANGE[device] || 400;
                offset = clamp(Math.round(offset), -range, range);

                $stage.attr('data-side', side).attr('data-offset', offset);

                var $handle = $stage.find('.ai-agent-stage-handle');
                var height  = $stage.height() || 1;
                // Bottom-anchored, matching how the widget itself is placed.
                var bottomPx = clamp((height * 0.08) + (offset / range) * (height * 0.7), 6, height - 46);

                $handle.css({
                    bottom: bottomPx + 'px',
                    left:   side === 'left' ? '10px' : 'auto',
                    right:  side === 'right' ? '10px' : 'auto'
                });

                $stage.closest('.ai-agent-device-panel')
                      .find('[data-stage-readout]')
                      .text(
                          (side === 'left' ? 'سمت چپ' : 'سمت راست') +
                          ' — جابه‌جایی عمودی ' + aiAgentFaDigits(offset) + ' پیکسل'
                      );

                if (writeInputs) {
                    var $panel = $stage.closest('.ai-agent-device-panel');
                    // فیلدهای مخفیِ این دستگاه با مقدار تازه به‌روز می‌شوند
                    // تا هنگام ذخیره‌ی فرم، سمت و آفست درست ارسال شود.
                    $panel.find('input[data-position-side="' + device + '"]').val(side);
                    $panel.find('input[data-position-offset="' + device + '"]').val(offset);
                }
            }

            $stages.each(function() {
                var $stage = $(this);
                apply($stage, $stage.attr('data-side'), parseInt($stage.attr('data-offset'), 10) || 0, false);
            });

            // Dragging. Pointer events cover mouse, touch and pen in one path,
            // and setPointerCapture keeps the drag alive when the cursor leaves
            // the small mock -- which it constantly does.
            $stages.each(function() {
                var stage = this;
                var $stage = $(stage);
                var handle = $stage.find('.ai-agent-stage-handle')[0];
                if (!handle) return;

                var dragging = false;

                handle.addEventListener('pointerdown', function(e) {
                    dragging = true;
                    if (handle.setPointerCapture) handle.setPointerCapture(e.pointerId);
                    $stage.addClass('is-dragging');
                    e.preventDefault();
                });

                handle.addEventListener('pointermove', function(e) {
                    if (!dragging) return;
                    var rect   = stage.getBoundingClientRect();
                    var device = $stage.data('stage');
                    var range  = STAGE_RANGE[device] || 400;

                    var side = (e.clientX - rect.left) < rect.width / 2 ? 'left' : 'right';
                    var bottomPx = rect.bottom - e.clientY;
                    var offset = ((bottomPx - rect.height * 0.08) / (rect.height * 0.7)) * range;

                    apply($stage, side, offset, true);
                });

                function end(e) {
                    if (!dragging) return;
                    dragging = false;
                    $stage.removeClass('is-dragging');
                    if (e && e.pointerId != null && handle.hasPointerCapture && handle.hasPointerCapture(e.pointerId)) {
                        handle.releasePointerCapture(e.pointerId);
                    }
                }
                handle.addEventListener('pointerup', end);
                handle.addEventListener('pointercancel', end);

                // Keyboard: the handle is a real button, so arrows have to work
                // for anyone who cannot drag.
                handle.addEventListener('keydown', function(e) {
                    var device = $stage.data('stage');
                    var step   = e.shiftKey ? 50 : 10;
                    var side   = $stage.attr('data-side');
                    var offset = parseInt($stage.attr('data-offset'), 10) || 0;

                    if (e.key === 'ArrowUp')         { apply($stage, side, offset + step, true); }
                    else if (e.key === 'ArrowDown')  { apply($stage, side, offset - step, true); }
                    else if (e.key === 'ArrowLeft')  { apply($stage, 'left', offset, true); }
                    else if (e.key === 'ArrowRight') { apply($stage, 'right', offset, true); }
                    else { return; }
                    e.preventDefault();
                });
            });

            // The numeric fields stay authoritative: editing one moves the mock.
            $('[data-position-offset]').on('input change', function() {
                var device = $(this).data('position-offset');
                var $stage = $('.ai-agent-stage[data-stage="' + device + '"]');
                apply($stage, $stage.attr('data-side'), parseInt($(this).val(), 10) || 0, false);
            });
            $('[data-position-side]').on('change', function() {
                var device = $(this).data('position-side');
                var $stage = $('.ai-agent-stage[data-stage="' + device + '"]');
                apply($stage, $(this).val(), parseInt($stage.attr('data-offset'), 10) || 0, false);
            });
        })();
        // ----- دکمه «بارگذاری اطلاعات از سرور» (بازخوانی تنظیمات، نه سینک داده‌های امبدینگ) -----
        // منطق نسخه‌ی فعلی افزونه حفظ شده است: دکمه‌ی «بارگذاری از سرور» سرِ جایش است
        // و همان اندپوینت قبلی را صدا می‌زند؛ فقط ظاهرش با دیزاین‌سیستم جدید هماهنگ است.
        // ----- دکمه «بارگذاری اطلاعات از سرور» (بازخوانی تنظیمات، نه سینک داده‌های امبدینگ) -----
        // دکمه‌ها اکنون SVG + متن دارند؛ برای حفظ SVG، به جای .text() از کلاس is-loading
        // استفاده می‌کنیم و فقط در صورت نیاز متن label داخل دکمه را با jQuery .find().last()
        // به‌روزرسانی می‌کنیم. در این‌جا فقط disabled و is-loading toggle می‌شود.
        $('#ai-agent-reload-settings-btn').on('click', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var $status = $('#ai-agent-reload-settings-status');
            var token = $('#ai_agent_reload_settings_nonce_field').val();

            $btn.prop('disabled', true).addClass('is-loading');
            $status.text('در حال دریافت آخرین مقادیر از سرور...');

            $.ajax({
                url: ajaxurl,
                method: 'POST',
                data: {
                    action: 'ai_agent_reload_settings',
                    nonce: token
                },
                success: function(response) {
                    if (response.success) {
                        $status.text('با موفقیت بازخوانی شد؛ در حال بارگذاری مجدد صفحه...');
                        // بارگذاری مجدد صفحه تا تمام فیلدهای فرم (از جمله بخش‌های فقط‌خواندنی
                        // مثل سقف پیام روزانه و وضعیت‌های مجاز) با مقادیر تازه از سرور نمایش داده شوند
                        setTimeout(function(){ window.location.reload(); }, 700);
                    } else {
                        $btn.prop('disabled', false).removeClass('is-loading');
                        $status.text((response.data && response.data.message) ? response.data.message : 'خطا در بازخوانی تنظیمات از سرور.');
                    }
                },
                error: function() {
                    $btn.prop('disabled', false).removeClass('is-loading');
                    $status.text('خطای غیرمنتظره در ارتباط با پردازشگر محلی وردپرس رخ داد.');
                }
            });
        });

        // صفحه انجام می‌شود (رجوع کنید به ai_agent_settings_page در settings.php).

        $('#ai-agent-sync-btn').on('click', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var $status = $('#ai-agent-sync-status');
            var token = $('#ai_agent_sync_nonce_field').val();

            $btn.prop('disabled', true).addClass('is-loading');
            $status.text('در حال بررسی محتوای جدید و ارسال به سرور...');

            $.ajax({
                url: ajaxurl,
                method: 'POST',
                data: {
                    action: 'ai_agent_sync_data',
                    nonce: token
                },
                success: function(response) {
                    $btn.prop('disabled', false).removeClass('is-loading');
                    if (response.success) {
                        var d = response.data;
                        // ساخت پیام خلاصه با جزئیات دقیق
                        var summary = '';
                        var trulyNew = (typeof d.new_truly_new_count !== 'undefined') ? d.new_truly_new_count : d.new_count;
                        var failedResent = (typeof d.failed_resent_count !== 'undefined') ? d.failed_resent_count : 0;

                        if (trulyNew > 0 && failedResent > 0 && d.deleted_count > 0) {
                            summary = trulyNew + ' مورد جدید اضافه، ' + failedResent + ' مورد ناموفق مجدداً ارسال و ' + d.deleted_count + ' مورد حذف شد';
                        } else if (trulyNew > 0 && failedResent > 0) {
                            summary = trulyNew + ' مورد جدید اضافه و ' + failedResent + ' مورد ناموفق مجدداً ارسال شد';
                        } else if (failedResent > 0 && d.deleted_count > 0) {
                            summary = failedResent + ' مورد ناموفق مجدداً ارسال و ' + d.deleted_count + ' مورد حذف شد';
                        } else if (trulyNew > 0 && d.deleted_count > 0) {
                            summary = trulyNew + ' مورد جدید اضافه و ' + d.deleted_count + ' مورد حذف شد';
                        } else if (trulyNew > 0) {
                            summary = trulyNew + ' مورد جدید اضافه شد';
                        } else if (failedResent > 0) {
                            summary = failedResent + ' مورد ناموفق مجدداً ارسال شد';
                        } else if (d.deleted_count > 0) {
                            summary = d.deleted_count + ' مورد حذف شد';
                        } else {
                            summary = 'هیچ محتوای جدیدی یافت نشد';
                        }
                        if (d.total_count) {
                            summary += ' (مجموع محتوای فعلی: ' + d.total_count + ' مورد)';
                        }
                        $status.html('<strong>' + summary + '</strong><br><small style="color:#666;font-weight:normal;">' + d.message + '</small>');
                        // به‌روزرسانی تاریخ آخرین سینک در صفحه بدون رفرش
                        if (d.last_sync_time) {
                            // فقط نمایش را به‌روز می‌کنیم؛ برای اطمینان کامل کاربر می‌تواند صفحه را رفرش کند
                            $status.append('<br><small style="color:#888;font-weight:normal;">زمان سینک: ' + d.last_sync_time + '</small>');
                        }
                        // به‌روزرسانی فیلد «آخرین سینک افزایشی» در بلوک آخرین همگام‌سازی
                        if (d.last_sync_time) {
                            $('.ai-agent-last-sync-item').first().find('.ai-agent-last-sync-value').text(d.last_sync_time);
                        }
                    } else {
                        var msg = (response.data && response.data.message) ? response.data.message : 'خطا در همگام‌سازی.';
                        $status.text(msg);
                    }
                },
                error: function() {
                    $btn.prop('disabled', false).removeClass('is-loading');
                    $status.text('خطای غیرمنتظره در ارتباط با پردازشگر محلی وردپرس رخ داد.');
                }
            });
        });

        // ----- دکمه‌ی «سینک تمامی محتوا» (Sync All) -----
        // رفتار: ابتدا تمام source_id های ذخیره‌شده در جدول با /sync/delete
        // از سرور حذف می‌شوند، سپس تمام محتوای تیک‌خورده از ابتدا با
        // /sync/content مجدداً ارسال می‌شود.
        $('#ai-agent-sync-all-btn').on('click', function(e) {
            e.preventDefault();

            // تأیید کاربر قبل از انجام سینک کامل (چون ابتدا محتوای قبلی از
            // سرور حذف و سپس کل محتوا دوباره ارسال می‌شود)
            if (!confirm('آیا مطمئن هستید؟ این عملیات ابتدا تمام محتوای قبلیِ همگام‌شده را از سرور حذف می‌کند و سپس تمام محتوای تیک‌خورده را از ابتدا دوباره ارسال می‌کند. برای فروشگاه‌های با محتوای زیاد ممکن است زمان‌بر باشد.')) {
                return;
            }

            var $btn = $(this);
            var $status = $('#ai-agent-sync-all-status');
            var token = $('#ai_agent_sync_all_nonce_field').val();

            $btn.prop('disabled', true).addClass('is-loading');
            $status.text('در حال حذف محتوای قبلی از سرور و ارسال مجدد تمام محتوا...');

            $.ajax({
                url: ajaxurl,
                method: 'POST',
                data: {
                    action: 'ai_agent_sync_all_data',
                    nonce: token
                },
                success: function(response) {
                    $btn.prop('disabled', false).removeClass('is-loading');
                    if (response.success) {
                        var d = response.data;
                        // ساخت پیام خلاصه با ذکر تعداد حذفی‌ها و ارسالی‌ها
                        var deletedCount = (typeof d.deleted_count !== 'undefined') ? parseInt(d.deleted_count, 10) : 0;
                        var summary = '';
                        if (deletedCount > 0) {
                            summary = deletedCount + ' مورد قبلی از سرور حذف و ' + d.new_count + ' مورد جدید ارسال شد';
                        } else {
                            summary = d.new_count + ' مورد با موفقیت به سرور ارسال شد';
                        }
                        if (d.total_count) {
                            summary += ' (از مجموع ' + d.total_count + ' مورد)';
                        }
                        $status.html('<strong>' + summary + '</strong><br><small style="color:#666;font-weight:normal;">' + d.message + '</small>');
                        if (d.last_sync_time) {
                            $status.append('<br><small style="color:#888;font-weight:normal;">زمان سینک کامل: ' + d.last_sync_time + '</small>');
                            // به‌روزرسانی فیلد «آخرین سینک کامل» در بلوک آخرین همگام‌سازی
                            $('.ai-agent-last-sync-item').last().find('.ai-agent-last-sync-value').text(d.last_sync_time);
                        }
                        // به‌روزرسانی نمودار وضعیت پس از سینک کامل (اگر موجود باشد)
                        if (typeof aiAgentCheckSyncStatus === 'function') {
                            aiAgentCheckSyncStatus(false);
                        }
                    } else {
                        var msg = (response.data && response.data.message) ? response.data.message : 'خطا در سینک کامل.';
                        $status.text(msg);
                    }
                },
                error: function() {
                    $btn.prop('disabled', false).removeClass('is-loading');
                    $status.text('خطای غیرمنتظره در ارتباط با پردازشگر محلی وردپرس رخ داد.');
                }
            });
        });

        // ----- نمودار وضعیت ارسال‌ها (Job Status) -----
        var aiAgentStatusChart = null;

        function aiAgentRenderStatusChart(summary) {
            if (typeof Chart === 'undefined' || !summary) return;

            var labels = ['در صف', 'در حال پردازش', 'تکمیل‌شده', 'ناموفق', 'یافت‌نشده'];
            var dataVals = [
                summary.queued || 0,
                summary.processing || 0,
                summary.completed || 0,
                summary.failed || 0,
                summary.not_found || 0
            ];
            var colors = ['#f59e0b', '#3b82f6', '#16a34a', '#dc2626', '#9ca3af'];

            var ctx = document.getElementById('ai-agent-status-chart');
            if (!ctx) return;

            if (aiAgentStatusChart) {
                aiAgentStatusChart.data.datasets[0].data = dataVals;
                aiAgentStatusChart.options.plugins.title.text = 'وضعیت ارسال‌ها (مجموع: ' + (summary.total || 0) + ')';
                aiAgentStatusChart.update();
                return;
            }

            aiAgentStatusChart = new Chart(ctx, {
                type: 'doughnut',
                data: {
                    labels: labels,
                    datasets: [{
                        data: dataVals,
                        backgroundColor: colors,
                        borderWidth: 2,
                        borderColor: '#ffffff',
                        hoverOffset: 8
                    }]
                },
                options: {
                    responsive: true,
                    cutout: '62%',
                    plugins: {
                        legend: {
                            position: 'bottom',
                            rtl: true,
                            labels: {
                                font: { family: 'Tahoma, sans-serif', size: 11 },
                                padding: 10,
                                usePointStyle: true,
                                pointStyle: 'circle'
                            }
                        },
                        title: {
                            display: true,
                            text: 'وضعیت ارسال‌ها (مجموع: ' + (summary.total || 0) + ')',
                            font: { family: 'Tahoma, sans-serif', size: 12, weight: 'bold' },
                            padding: { top: 4, bottom: 8 }
                        }
                    }
                }
            });
        }

        function aiAgentCheckSyncStatus(showLoadingUI) {
            var $statusEl = $('#ai-agent-check-status-status');
            var $btn = $('#ai-agent-check-status-btn');
            var token = $('#ai_agent_sync_status_nonce_field').val();

            if (!token) return; // یعنی دکمه/فیلد در صفحه وجود ندارد

            if (showLoadingUI) {
                $btn.prop('disabled', true).addClass('is-loading');
            }
            $statusEl.text('در حال دریافت وضعیت از سرور...');

            $.ajax({
                url: ajaxurl,
                method: 'POST',
                data: {
                    action: 'ai_agent_check_sync_status',
                    nonce: token
                },
                success: function(response) {
                    $btn.prop('disabled', false).removeClass('is-loading');
                    if (response.success) {
                        var d = response.data;
                        // نمایش تعداد رکوردهای به‌روزرسانی‌شده در جدول + وضعیت کلی
                        var updatedCount = (typeof d.updated_count !== 'undefined') ? d.updated_count : null;
                        if (updatedCount !== null) {
                            $statusEl.text('وضعیت با موفقیت به‌روزرسانی شد (' + updatedCount + ' رکورد در جدول بروز شد).');
                        } else {
                            $statusEl.text('وضعیت با موفقیت به‌روزرسانی شد.');
                        }
                        if (d.summary) {
                            aiAgentRenderStatusChart(d.summary);
                        }
                    } else {
                        var msg = (response.data && response.data.message) ? response.data.message : 'خطا در دریافت وضعیت.';
                        $statusEl.text(msg);
                    }
                },
                error: function() {
                    $btn.prop('disabled', false).removeClass('is-loading');
                    $statusEl.text('خطای غیرمنتظره در ارتباط با پردازشگر محلی وردپرس رخ داد.');
                }
            });
        }

        $('#ai-agent-check-status-btn').on('click', function(e) {
            e.preventDefault();
            aiAgentCheckSyncStatus(true);
        });

        // اجرای خودکار هنگام باز شدن صفحه‌ی تنظیمات (اگر دکمه در صفحه موجود باشد)
        if ($('#ai-agent-check-status-btn').length) {
            aiAgentCheckSyncStatus(false);
        }

        // =====================================================================
        // تاریخچه چت‌ها — لیست آکاردئونی جلسات با صفحه‌بندی
        // =====================================================================
        var aiAgentSessions = {
            currentPage: 1,
            pageSize: 10,
            total: 0,
            hasNext: false,
            loading: false,
            statusFilter: '',      // فیلتر وضعیت فعلی ('' یعنی همه)
            openSessionId: null,   // شناسه‌ی جلسه‌ای که آکاردئونش باز است
            // پیام‌ها دیگر صفحه‌بندی نمی‌شوند؛ همه‌ی پیام‌های یک جلسه یکجا بارگذاری می‌شوند.
            msgLoading: false,
            countsLoading: false,
            countsTimer: null,

            init: function() {
                var self = this;
                // فقط اگر تب history فعال باشد
                if (!$('#ai-agent-sessions-list').length) return;

                // تعداد در هر صفحه — بالا
                $('#ai-agent-sessions-per-page').on('change', function() {
                    self.pageSize = parseInt($(this).val(), 10) || 10;
                    $('#ai-agent-sessions-per-page-bottom').val(self.pageSize);
                    self.currentPage = 1;
                    self.loadSessions();
                });
                // تعداد در هر صفحه — پایین
                $('#ai-agent-sessions-per-page-bottom').on('change', function() {
                    self.pageSize = parseInt($(this).val(), 10) || 10;
                    $('#ai-agent-sessions-per-page').val(self.pageSize);
                    self.currentPage = 1;
                    self.loadSessions();
                });

                // صفحه‌بندی — بالا
                $('#ai-agent-sessions-prev-btn').on('click', function() {
                    if (self.currentPage > 1) {
                        self.currentPage--;
                        self.loadSessions();
                    }
                });
                $('#ai-agent-sessions-next-btn').on('click', function() {
                    if (self.hasNext) {
                        self.currentPage++;
                        self.loadSessions();
                    }
                });

                // صفحه‌بندی — پایین
                $('#ai-agent-sessions-prev-btn-bottom').on('click', function() {
                    if (self.currentPage > 1) {
                        self.currentPage--;
                        self.loadSessions();
                    }
                });
                $('#ai-agent-sessions-next-btn-bottom').on('click', function() {
                    if (self.hasNext) {
                        self.currentPage++;
                        self.loadSessions();
                    }
                });

                // دکمه‌های شناور فیلتر وضعیت
                $('#ai-agent-status-filters').on('click', '.ai-agent-filter-btn', function() {
                    var $btn = $(this);
                    if ($btn.hasClass('is-active')) return;

                    $('#ai-agent-status-filters .ai-agent-filter-btn').removeClass('is-active');
                    $btn.addClass('is-active');

                    self.statusFilter = $btn.data('status') || '';
                    self.currentPage = 1;
                    self.loadSessions();
                });

                /*
                فهرست این‌جا گرفته نمی‌شود. نمای گفت‌وگوها همیشه در DOM
                هست ولی پیش‌فرض بسته است، و بیشتر کسانی که این صفحه را
                باز می‌کنند سراغش نمی‌روند؛ گرفتن فهرست هنگام لود یعنی
                یک کال به سرور برای چیزی که دیده نمی‌شود. تب که باز شد،
                load() صدا زده می‌شود.
                */
            },

            /** اولین باز شدن نمای گفت‌وگوها. */
            load: function() {
                if (!$('#ai-agent-sessions-list').length) return;
                this.loadSessions();
                this.loadStatusCounts();
            },

            /*
            ============================================
            بارگذاری تعداد جلسات به تفکیک وضعیت برای به‌روزرسانی
            badge‌های قرمز کوچک بالای هر دکمه‌ی فیلتر.
            ============================================
            */
            loadStatusCounts: function() {
                var self = this;
                if (self.countsLoading) return;
                self.countsLoading = true;

                // نمایش حالت loading روی همه‌ی badge‌ها
                $('#ai-agent-status-filters .ai-agent-filter-count').addClass('is-loading');

                $.ajax({
                    url: ajaxurl,
                    method: 'GET',
                    data: {
                        action: 'ai_agent_get_session_status_counts',
                        nonce: $('#ai_agent_chat_sessions_nonce_field').val()
                    },
                    success: function(response) {
                        self.countsLoading = false;
                        $('#ai-agent-status-filters .ai-agent-filter-count').removeClass('is-loading');

                        if (response.success) {
                            var counts = (response.data && response.data.counts) ? response.data.counts : {};
                            $('#ai-agent-status-filters .ai-agent-filter-count').each(function() {
                                var st = $(this).attr('data-count-status');
                                if (typeof st === 'undefined') return;
                                var c = (typeof counts[st] !== 'undefined') ? counts[st] : 0;

                                // یک badge قرمز روی هر فیلتر که عدد صفر را
                                // نشان می‌داد، پنج نشانه‌ی هشدار می‌ساخت برای
                                // چیزی که خبری در آن نبود. badge فقط وقتی
                                // معنا دارد که واقعاً چیزی منتظر است.
                                if (c > 0) {
                                    $(this).text(aiAgentFaDigits(c)).removeAttr('hidden');
                                } else {
                                    $(this).text('').attr('hidden', 'hidden');
                                }
                            });
                        }
                    },
                    error: function() {
                        self.countsLoading = false;
                        $('#ai-agent-status-filters .ai-agent-filter-count').removeClass('is-loading');
                    }
                });
            },

            syncPageUI: function() {
                // اطلاعات صفحه
                $('#ai-agent-sessions-page-info').text(
                    this.total > 0
                        ? 'صفحه ' + aiAgentFaDigits(this.currentPage) +
                          ' از ' + aiAgentFaDigits(Math.ceil(this.total / this.pageSize))
                        : ''
                );
                $('#ai-agent-sessions-total-info').text(
                    this.total > 0 ? 'مجموع: ' + aiAgentFaDigits(this.total) + ' جلسه' : ''
                );

                // دکمه‌های بالا
                $('#ai-agent-sessions-prev-btn').prop('disabled', this.currentPage <= 1);
                $('#ai-agent-sessions-next-btn').prop('disabled', !this.hasNext);

                // دکمه‌های پایین
                $('#ai-agent-sessions-prev-btn-bottom').prop('disabled', this.currentPage <= 1);
                $('#ai-agent-sessions-next-btn-bottom').prop('disabled', !this.hasNext);
            },

            loadSessions: function() {
                var self = this;
                if (self.loading) return;
                self.loading = true;

                var $list = $('#ai-agent-sessions-list');
                var $loading = $('#ai-agent-sessions-loading');
                var $error = $('#ai-agent-sessions-error');

                $list.empty();
                $loading.show();
                $error.hide();
                self.syncPageUI();

                $.ajax({
                    url: ajaxurl,
                    method: 'GET',
                    data: {
                        action: 'ai_agent_get_chat_sessions',
                        nonce: $('#ai_agent_chat_sessions_nonce_field').val(),
                        page: self.currentPage,
                        page_size: self.pageSize,
                        status_filter: self.statusFilter
                    },
                    success: function(response) {
                        self.loading = false;
                        $loading.hide();

                        if (response.success) {
                            var d = response.data;
                            self.total = d.total || 0;
                            self.hasNext = d.has_next || false;
                            self.currentPage = d.page || 1;
                            self.syncPageUI();
                            self.renderSessions(d.items || []);
                            // به‌روزرسانی badge‌های شمارش پس از هر بارگذاری
                            self.loadStatusCounts();
                        } else {
                            var msg = (response.data && response.data.message) ? response.data.message : 'خطا در دریافت لیست جلسات.';
                            $error.text(msg).css('color', '#b91c1c').show();
                        }
                    },
                    error: function() {
                        self.loading = false;
                        $loading.hide();
                        $error.text('خطای غیرمنتظره در ارتباط با سرور.').css('color', '#b91c1c').show();
                    }
                });
            },

            renderSessions: function(items) {
                var self = this;
                var $list = $('#ai-agent-sessions-list');

                if (!items || items.length === 0) {
                    $list.html('<div class="ai-agent-empty">هیچ جلسه‌ای یافت نشد.</div>');
                    return;
                }

                for (var i = 0; i < items.length; i++) {
                    (function(item) {
                        var created = item.created_at || '';
                        // تبدیل تاریخ به فرمت قابل نمایش (شمسی + ساعت)
                        if (created) {
                            var dt = aiAgentParseServerDate(created);
                            if (dt) {
                                var jalali = self.toJalali(dt.getFullYear(), dt.getMonth() + 1, dt.getDate());
                                created = aiAgentFaDigits(jalali + ' ' +
                                    String(dt.getHours()).padStart(2, '0') + ':' +
                                    String(dt.getMinutes()).padStart(2, '0'));
                            }
                        }

                        var statusLabel = self.getStatusLabel(item.status);

                        var $item = $('<div class="ai-agent-session-item"></div>');
                        var $header = $('<div class="ai-agent-session-head"></div>');
                        /*
                        آیکون همیشه یک کاراکتر ثابت (► رو به راست) است و فقط
                        با CSS می‌چرخد (۹۰ درجه ساعتگرد ⇒ رو به پایین).
                        قبلاً هم کاراکتر عوض می‌شد (► → ▼) و هم CSS ۱۸۰
                        درجه می‌چرخاند؛ یعنی ▼ چرخیده = ▲ رو به بالا، و
                        کاربر انگار آیکون ۲۷۰ درجه برمی‌گشت. حالا فقط یکی
                        از این دو مکانیزم استفاده می‌شود: چرخش ۹۰ درجه.
                        */
                        var $arrow = $('<span class="ai-agent-session-arrow">&#9654;</span>');
                        var $idSpan = $('<code class="ai-agent-session-id"></code>').text(item.id);
                        var $dateSpan = $('<span class="ai-agent-session-date"></span>').text(created);
                        // رنگ بج وضعیت اکنون از طریق CSS و ویژگی data-status اعمال می‌شود
                        var $statusBadge = $('<span class="ai-agent-session-status-badge"></span>')
                            .attr('data-status', item.status || '')
                            .text(statusLabel);

                        $header.append($arrow).append(' ').append($idSpan).append(' ').append($dateSpan).append(' ').append($statusBadge);

                        /*
                        هزینه‌ی همین گفت‌وگو، کنار خودش.

                        صاحب سایت به‌ازای توکن پول می‌دهد؛ تا وقتی فقط یک عدد
                        کلی کیف‌پول می‌دید، معلوم نبود کدام گفت‌وگو گران درآمده.
                        گفت‌وگویی که هنوز چیزی خرج نکرده بج نمی‌گیرد تا ردیف
                        شلوغ نشود.
                        */
                        var costIrr = Number(item.total_cost_irr || 0);
                        if (costIrr > 0) {
                            var tokensIn  = Number(item.total_tokens_input || 0);
                            var tokensOut = Number(item.total_tokens_output || 0);
                            $header.append(' ').append(
                                $('<span class="ai-agent-session-cost"></span>')
                                    .attr('title', 'توکن ورودی: ' + aiAgentFaDigits(tokensIn) +
                                                   ' — توکن خروجی: ' + aiAgentFaDigits(tokensOut))
                                    .text(aiAgentToman(costIrr) + ' تومان')
                            );
                        }

                        var $body = $('<div class="ai-agent-session-body" style="display:none;"></div>');

                        $header.on('click', function() {
                            if ($body.is(':visible')) {
                                // بستن آکاردئون — آیکون با حذف is-open به حالت
                                // رو به راست برمی‌گردد (چرخش با CSS)
                                $body.slideUp(250);
                                $item.removeClass('is-open');
                                self.openSessionId = null;
                            } else {
                                // بستن تمام آکاردئون‌های باز
                                $list.find('.ai-agent-session-body:visible').slideUp(250);
                                $list.find('.ai-agent-session-item').removeClass('is-open');

                                // باز کردن این مورد — آیکون با is-open می‌چرخد
                                // و رو به پایین می‌ایستد (transform: rotate(90deg))
                                $body.slideDown(250);
                                $item.addClass('is-open');
                                self.openSessionId = item.id;
                                self.loadMessages(item.id, $body, item.status);
                            }
                        });

                        $item.append($header).append($body);
                        $list.append($item);
                    })(items[i]);
                }
            },

            /*
            ============================================
            بارگذاری تمام پیام‌های یک جلسه در یک درخواست.

            منطق دکمه‌ی «مشاهده پیام‌های قدیمی‌تر» حذف شده است و
            همه‌ی پیام‌ها با page_size بزرگی (۱۰۰۰۰) یکجا بارگذاری می‌شوند.
            ============================================
            */
            loadMessages: function(sessionId, $container, sessionStatus) {
                var self = this;
                if (self.msgLoading) return;
                self.msgLoading = true;

                $container.html('<div class="ai-agent-msg-loading">در حال بارگذاری پیام‌ها...</div>');

                $.ajax({
                    url: ajaxurl,
                    method: 'GET',
                    data: {
                        action: 'ai_agent_get_session_messages',
                        nonce: $('#ai_agent_chat_sessions_nonce_field').val(),
                        session_id: sessionId,
                        page: 1,
                        page_size: 10000  // بارگذاری همه‌ی پیام‌ها در یک درخواست
                    },
                    success: function(response) {
                        self.msgLoading = false;
                        if (response.success) {
                            var d = response.data;
                            var allMessages = d.items || [];
                            // مرتب‌سازی پیام‌ها از قدیم به جدید بر اساس created_at
                            // (با همان پارسر سازگار با سافاری، تا رشته‌های با
                            // جداکننده‌ی فاصله هم درست مرتب شوند)
                            allMessages.sort(function(a, b) {
                                var da = a && a.created_at ? aiAgentParseServerDate(a.created_at) : null;
                                var db = b && b.created_at ? aiAgentParseServerDate(b.created_at) : null;
                                var ta = da ? da.getTime() : 0;
                                var tb = db ? db.getTime() : 0;
                                return ta - tb;
                            });
                            self.renderMessages($container, allMessages, sessionId, sessionStatus);
                        } else {
                            var msg = (response.data && response.data.message) ? response.data.message : 'خطا در دریافت پیام‌ها.';
                            $container.html('<div class="ai-agent-sessions-error">' + msg + '</div>');
                        }
                    },
                    error: function() {
                        self.msgLoading = false;
                        $container.html('<div class="ai-agent-sessions-error">خطای غیرمنتظره در ارتباط با سرور.</div>');
                    }
                });
            },

            renderMessages: function($container, messages, sessionId, sessionStatus) {
                var self = this;
                $container.empty();

                if (!messages || messages.length === 0) {
                    $container.html('<div class="ai-agent-empty">پیامی یافت نشد.</div>');
                    if (sessionStatus === 'pending_human' || sessionStatus === 'human') {
                        $container.append(self.buildReplyBox(sessionId));
                    }
                    return;
                }

                var $chatArea = $('<div class="ai-agent-chat-messages"></div>');

                for (var i = 0; i < messages.length; i++) {
                    var msg = messages[i];
                    var $bubble = self.buildMessageBubble(msg);
                    $chatArea.append($bubble);
                }

                $container.append($chatArea);

                // نمایش تعداد کل پیام‌ها در پایین (بدون دکمه‌ی بارگذاری بیشتر)
                var $totalInfo = $('<div class="ai-agent-msg-total" style="margin-top:6px;text-align:center;"></div>');
                $totalInfo.text(messages.length + ' پیام');
                $container.append($totalInfo);

                // باکس پاسخ پشتیبان + دکمه‌ی پایان چت
                // فقط برای جلسات «در انتظار پشتیبان» یا «پشتیبان» نمایش داده می‌شود
                if (sessionStatus === 'pending_human' || sessionStatus === 'human') {
                    $container.append(self.buildReplyBox(sessionId));
                }

                // شروع بارگذاری lazy عکس‌ها — یکی‌یکی، پس از رندر پیام‌ها
                self.startLazyImageLoading($chatArea);
            },

            /*
            ============================================
            ساخت حباب پیام یکتا بر اساس msg

            این متد از renderMessages استفاده می‌شود
            تا منطق ساخت پیام تکرار نشود. هر حباب شامل:
              - هدر (نقش + زمان)
              - محتوای متنی
              - گالری عکس‌های lazy (اگر پیام image_keys داشته باشد)

            placeholderهای عکس با کلاس ai-agent-msg-image-placeholder و
            data-image-key ساخته می‌شوند و سپس توسط startLazyImageLoading
            به‌صورت یکی‌یکی از اندپوینت ai_agent_get_media دریافت و جایگزین می‌شوند.
            ============================================
            */
            buildMessageBubble: function(msg) {
                var role = (msg.role || 'user').toLowerCase();
                var content = msg.content || '';
                var created = msg.created_at || '';
                var imageKeys = Array.isArray(msg.image_keys) ? msg.image_keys : [];

                // فرمت‌بندی تاریخ پیام
                var timeStr = '';
                if (created) {
                    var dt = aiAgentParseServerDate(created);
                    if (dt) {
                        timeStr = aiAgentFaDigits(
                            String(dt.getHours()).padStart(2, '0') + ':' +
                            String(dt.getMinutes()).padStart(2, '0') + ':' +
                            String(dt.getSeconds()).padStart(2, '0')
                        );
                    }
                }

                // نگاشت role به کلاس و لیبل
                var roleClass = 'ai-agent-msg-' + role;
                var roleLabel = '';
                switch (role) {
                    case 'user': roleLabel = 'کاربر'; break;
                    case 'assistant': roleLabel = 'دستیار'; break;
                    case 'support': roleLabel = 'پشتیبان'; break;
                    case 'system': roleLabel = 'سیستم'; break;
                    default: roleLabel = role;
                }

                var $msgBubble = $('<div class="ai-agent-msg-bubble ' + roleClass + '"></div>');
                var $msgHeader = $('<div class="ai-agent-msg-header"></div>');
                var $roleSpan = $('<span class="ai-agent-msg-role"></span>').text(roleLabel);
                var $timeSpan = $('<span class="ai-agent-msg-time"></span>').text(timeStr);
                $msgHeader.append($roleSpan).append($timeSpan);

                // محتوای پیام با پشتیبانی از مارک‌داون (بولد و لینک) رندر می‌شود
                // (مشابه رفتار ویجت عمومی در ai-agent.js)
                var $msgContent = $('<div class="ai-agent-msg-content"></div>').html(aiAgentRenderInlineMarkdown(content));

                // گالری عکس‌های lazy — فقط برای پیام‌هایی که image_keys دارند
                // (معمولاً پیام کاربر، اما اگر API برای نقش‌های دیگر هم فرستاد، نمایش می‌دهیم)
                //
                // ترتیب چیدمان داخل حباب پیام:
                //     header  →  gallery  →  content
                // یعنی عکس‌ها روی همان پیام و بالای متن نمایش داده می‌شوند،
                // دقیقاً مشابه رفتار ویجت عمومی (ai-agent.js) که گالری بالای
                // پرامپت متنی کاربر قرار می‌گیرد.
                var $gallery = null;
                if (imageKeys.length > 0) {
                    $gallery = $('<div class="ai-agent-msg-image-gallery"></div>');
                    for (var k = 0; k < imageKeys.length; k++) {
                        var $ph = $('<div class="ai-agent-msg-image-placeholder is-loading"></div>')
                            .attr('data-image-key', String(imageKeys[k]));
                        // یک اسپینر کوچک داخل placeholder
                        $ph.append('<span class="ai-agent-msg-image-spinner" aria-hidden="true"></span>');
                        $gallery.append($ph);
                    }
                }

                // چیدمان نهایی: header → gallery (در صورت وجود) → content
                $msgBubble.append($msgHeader);
                if ($gallery) {
                    $msgBubble.append($gallery);
                }
                $msgBubble.append($msgContent);

                return $msgBubble;
            },

            /*
            ============================================
            بارگذاری lazy عکس‌های یک chat area با استفاده از IntersectionObserver

            placeholderها (با کلاس ai-agent-msg-image-placeholder و data-image-key)
            زیر نظر IntersectionObserver قرار می‌گیرند. به محض ورود به viewport،
            عکس مربوطه از اندپوینت ai_agent_get_media دریافت و جایگزین می‌شود.

            درخواست‌ها به‌صورت یکی‌یکی (sequential) ارسال می‌شوند تا بار روی سرور
            افزایش نکند. یک صف ساده نگه داشته می‌شود که در هر لحظه فقط یک
            درخواست در حال انجام است.
            ============================================
            */
            _lazyQueue: [],
            _lazyInFlight: false,
            _lazyObserver: null,

            startLazyImageLoading: function($chatArea) {
                var self = this;
                if (!$chatArea || !$chatArea.length) return;

                var $placeholders = $chatArea.find('.ai-agent-msg-image-placeholder.is-loading');

                if ($placeholders.length === 0) return;

                // اگر IntersectionObserver پشتیبانی می‌شود، از آن استفاده می‌کنیم
                if ('IntersectionObserver' in window) {
                    if (!self._lazyObserver) {
                        self._lazyObserver = new IntersectionObserver(function(entries) {
                            entries.forEach(function(entry) {
                                if (entry.isIntersecting) {
                                    self._lazyObserver.unobserve(entry.target);
                                    self._enqueueLazyImage($(entry.target));
                                }
                            });
                        }, {
                            // از $chatArea به‌عنوان root استفاده می‌کنیم تا فقط
                            // placeholderهای داخل همین ناحیه‌ی قابل اسکرول تشخیص داده شوند
                            root: $chatArea[0],
                            rootMargin: '100px',
                            threshold: 0.05
                        });
                    }
                    $placeholders.each(function() {
                        self._lazyObserver.observe(this);
                    });
                } else {
                    // مرورگرهای قدیمی: مستقیماً همه را در صف می‌گذاریم
                    $placeholders.each(function() {
                        self._enqueueLazyImage($(this));
                    });
                }
            },

            _enqueueLazyImage: function($ph) {
                var self = this;
                if (!$ph || !$ph.length) return;
                if ($ph.data('lazy-queued') || $ph.data('lazy-loading')) return;
                if (!$ph.hasClass('is-loading')) return; // قبلاً لود شده
                $ph.data('lazy-queued', true);
                self._lazyQueue.push($ph);
                self._processLazyQueue();
            },

            _processLazyQueue: function() {
                var self = this;
                if (self._lazyInFlight) return;
                var $ph = self._lazyQueue.shift();
                if (!$ph || !$ph.length) return;

                // اگر placeholder دیگر در DOM نیست (مثلاً آکاردئون بسته شده)، رد می‌کنیم
                if (!$.contains(document, $ph[0])) {
                    self._processLazyQueue();
                    return;
                }

                var key = $ph.attr('data-image-key');
                if (!key) {
                    self._processLazyQueue();
                    return;
                }

                $ph.data('lazy-queued', false);
                $ph.data('lazy-loading', true);
                self._lazyInFlight = true;

                self._fetchMediaByKey(key, function(ok, dataUrl) {
                    self._lazyInFlight = false;
                    $ph.data('lazy-loading', false);

                    if (ok && dataUrl) {
                        $ph.removeClass('is-loading').addClass('is-loaded');
                        $ph.find('.ai-agent-msg-image-spinner').remove();
                        var $img = $('<img class="ai-agent-msg-image" alt="عکس پیوست" />').attr('src', dataUrl);
                        $ph.append($img);

                        // کلیک روی عکس → باز شدن در تب جدید با data URL
                        $ph.addClass('is-clickable');
                        $ph.on('click', function() {
                            if (dataUrl) {
                                var w = window.open('');
                                if (w) {
                                    w.document.write('<title>عکس پیوست</title><img src="' + dataUrl + '" style="max-width:100%;height:auto;" />');
                                    w.document.close();
                                }
                            }
                        });

                        $img.one('error', function() {
                            $ph.addClass('is-error');
                        });
                    } else {
                        $ph.removeClass('is-loading').addClass('is-error');
                        $ph.find('.ai-agent-msg-image-spinner').remove();
                        $ph.append('<span class="ai-agent-msg-image-error" aria-hidden="true">⚠</span>');
                        $ph.attr('title', 'خطا در بارگذاری عکس');
                    }

                    if (self._lazyQueue.length > 0) {
                        setTimeout(function() { self._processLazyQueue(); }, 50);
                    }
                });
            },

            /*
            ============================================
            درخواست AJAX به اندپوینت ai_agent_get_media برای دریافت یک عکس
            در پنل تنظیمات (admin) — از ajaxurl و nonce مربوط به chat sessions
            استفاده می‌کند (هندلر sمت سرور nonce لازم ندارد، ولی برای سازگاری
            با نسخه‌های آینده nonce نیز ارسال می‌شود).
            ============================================
            */
            _fetchMediaByKey: function(key, callback) {
                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: {
                        action: 'ai_agent_get_media',
                        nonce: $('#ai_agent_chat_sessions_nonce_field').val(),
                        key: key
                    },
                    dataType: 'json'
                }).done(function(res) {
                    if (res && res.success && res.data && res.data.data_url) {
                        callback(true, res.data.data_url);
                    } else {
                        callback(false, null);
                    }
                }).fail(function() {
                    callback(false, null);
                });
            },

            /*
            ============================================
            ساخت باکس پاسخ پشتیبان: یک تکست‌باکس + دکمه‌ی «ارسال پاسخ»
            + دکمه‌ی «پایان چت». این باکس در انتهای پیام‌های هر جلسه‌ی
            «در انتظار پشتیبان» یا «پشتیبان» نمایش داده می‌شود.

            ارسال پاسخ:
                POST /api/v1/chat/sessions/{session_id}/reply
                هدر: X-API-Key, session-id
                بدنه: { "message": "..." }

            پایان چت:
                POST /api/v1/chat/sessions/{session_id}/close
                هدر: X-API-Key, session-id
                بدون بدنه
            ============================================
            */
            buildReplyBox: function(sessionId) {
                var self = this;
                var nonce = $('#ai_agent_chat_sessions_nonce_field').val();

                var $wrap = $('<div class="ai-agent-session-reply-box"></div>');
                var $textarea = $('<textarea class="ai-agent-session-reply-input" placeholder="پاسخ خود را برای کاربر بنویسید..."></textarea>');
                var $actionsRow = $('<div class="ai-agent-session-reply-actions"></div>');
                var $sendBtn = $('<button type="button" class="ai-agent-btn ai-agent-btn-primary ai-agent-session-send-btn">ارسال پاسخ</button>');
                var $returnBotBtn = $('<button type="button" class="ai-agent-btn ai-agent-session-return-bot-btn">بازگردانی چت به ربات</button>');
                var $closeBtn = $('<button type="button" class="ai-agent-btn ai-agent-session-close-btn">پایان چت</button>');
                var $statusSpan = $('<span class="ai-agent-session-reply-status"></span>');

                $actionsRow.append($sendBtn).append($returnBotBtn).append($closeBtn).append($statusSpan);
                $wrap.append($textarea).append($actionsRow);

                function sendReply() {
                    var text = $textarea.val().trim();
                    if (!text) {
                        $textarea.trigger('focus');
                        return;
                    }

                    $sendBtn.prop('disabled', true).text('در حال ارسال...');
                    $statusSpan.text('');

                    $.ajax({
                        url: ajaxurl,
                        method: 'POST',
                        data: {
                            action: 'ai_agent_session_reply',
                            nonce: nonce,
                            session_id: sessionId,
                            message: text
                        },
                        success: function(response) {
                            $sendBtn.prop('disabled', false).text('ارسال پاسخ');
                            if (response.success) {
                                // افزودن پیام پشتیبان به انتهای همان لیست پیام‌ها، بدون نیاز به رفرش
                                var $chatArea = $wrap.closest('.ai-agent-session-body').find('.ai-agent-chat-messages');
                                var now = new Date();
                                var timeStr = String(now.getHours()).padStart(2, '0') + ':' +
                                              String(now.getMinutes()).padStart(2, '0') + ':' +
                                              String(now.getSeconds()).padStart(2, '0');

                                var $bubble = $('<div class="ai-agent-msg-bubble ai-agent-msg-support"></div>');
                                var $header = $('<div class="ai-agent-msg-header"></div>');
                                $header.append($('<span class="ai-agent-msg-role"></span>').text('پشتیبان'));
                                $header.append($('<span class="ai-agent-msg-time"></span>').text(timeStr));
                                var $content = $('<div class="ai-agent-msg-content"></div>').html(aiAgentRenderInlineMarkdown(text));
                                $bubble.append($header).append($content);

                                if ($chatArea.length) {
                                    $chatArea.append($bubble);
                                    $chatArea.scrollTop($chatArea[0].scrollHeight);
                                }

                                $textarea.val('');
                                $statusSpan.text('پاسخ با موفقیت ارسال شد.');
                            } else {
                                var msg = (response.data && response.data.message) ? response.data.message : 'خطا در ارسال پاسخ.';
                                $statusSpan.text(msg);
                            }
                        },
                        error: function() {
                            $sendBtn.prop('disabled', false).text('ارسال پاسخ');
                            $statusSpan.text('خطای غیرمنتظره در ارتباط با سرور.');
                        }
                    });
                }

                $sendBtn.on('click', sendReply);

                // ارسال با کلید Enter (بدون Shift) — مشابه ویجت چت اصلی
                $textarea.on('keydown', function(e) {
                    if (e.key === 'Enter' && !e.shiftKey) {
                        e.preventDefault();
                        sendReply();
                    }
                });

                $closeBtn.on('click', function() {
                    if (!confirm('آیا از پایان دادن به این چت مطمئن هستید؟ این عملیات قابل بازگشت نیست.')) {
                        return;
                    }

                    $closeBtn.prop('disabled', true).text('در حال بستن...');
                    $sendBtn.prop('disabled', true);
                    $statusSpan.text('');

                    $.ajax({
                        url: ajaxurl,
                        method: 'POST',
                        data: {
                            action: 'ai_agent_session_close',
                            nonce: nonce,
                            session_id: sessionId
                        },
                        success: function(response) {
                            if (response.success) {
                                $statusSpan.text('چت با موفقیت بسته شد.');
                                $textarea.prop('disabled', true);
                                $closeBtn.text('چت بسته شد');
                                $sendBtn.prop('disabled', true);

                                // به‌روزرسانی بج وضعیت در هدر آکاردئون بدون نیاز به رفرش کل لیست
                                // رنگ‌ها اکنون از طریق CSS و ویژگی data-status اعمال می‌شوند
                                var $badge = $wrap.closest('.ai-agent-session-item').find('.ai-agent-session-status-badge');
                                $badge.attr('data-status', 'closed').text(self.getStatusLabel('closed'));
                            } else {
                                $closeBtn.prop('disabled', false).text('پایان چت');
                                $sendBtn.prop('disabled', false);
                                var msg = (response.data && response.data.message) ? response.data.message : 'خطا در بستن چت.';
                                $statusSpan.text(msg);
                            }
                        },
                        error: function() {
                            $closeBtn.prop('disabled', false).text('پایان چت');
                            $sendBtn.prop('disabled', false);
                            $statusSpan.text('خطای غیرمنتظره در ارتباط با سرور.');
                        }
                    });
                });

                /*
                ============================================
                هندلر دکمه «بازگردانی چت به ربات»

                این دکمه فقط برای جلسات «در انتظار پشتیبان» یا «پشتیبان»
                نمایش داده می‌شود و به پشتیبان اجازه می‌دهد گفتگو را در هر
                لحظه دوباره به حالت ربات بازگرداند تا کاربر پاسخ خودکار ربات
                را دریافت کند.

                اندپوینت بالادستی:
                    POST /api/v1/chat/sessions/{session_id}/return-to-bot
                    هدرها: X-API-Key, session-id
                    بدنه: ندارد (طبق API جدید)

                پس از موفقیت:
                    - بج وضعیت جلسه در هدر آکاردئون به «ربات» به‌روز می‌شود
                    - باکس پاسخ (textarea + دکمه‌ها) غیرفعال می‌شود چون دیگر
                      پشتیبان نباید پاسخی ارسال کند
                    - پیام موفقیت نمایش داده می‌شود
                ============================================
                */
                $returnBotBtn.on('click', function() {
                    if (!confirm('آیا از بازگرداندن این چت به حالت ربات مطمئن هستید؟ پس از این عملیات، کاربر پاسخ‌های خودکار ربات را دریافت خواهد کرد.')) {
                        return;
                    }

                    $returnBotBtn.prop('disabled', true).text('در حال بازگردانی...');
                    $sendBtn.prop('disabled', true);
                    $closeBtn.prop('disabled', true);
                    $statusSpan.text('');

                    $.ajax({
                        url: ajaxurl,
                        method: 'POST',
                        data: {
                            action: 'ai_agent_session_return_to_bot',
                            nonce: nonce,
                            session_id: sessionId
                        },
                        success: function(response) {
                            if (response.success) {
                                $statusSpan.text('چت به حالت ربات بازگردانده شد.');
                                $textarea.prop('disabled', true);
                                $returnBotBtn.text('بازگردانده شد');
                                $returnBotBtn.prop('disabled', true);
                                $sendBtn.prop('disabled', true);
                                $closeBtn.prop('disabled', true);

                                // به‌روزرسانی بج وضعیت در هدر آکاردئون بدون نیاز به رفرش کل لیست
                                var $badge = $wrap.closest('.ai-agent-session-item').find('.ai-agent-session-status-badge');
                                $badge.attr('data-status', 'bot').text(self.getStatusLabel('bot'));
                            } else {
                                $returnBotBtn.prop('disabled', false).text('بازگردانی چت به ربات');
                                $sendBtn.prop('disabled', false);
                                $closeBtn.prop('disabled', false);
                                var msg = (response.data && response.data.message) ? response.data.message : 'خطا در بازگردانی چت به ربات.';
                                $statusSpan.text(msg);
                            }
                        },
                        error: function() {
                            $returnBotBtn.prop('disabled', false).text('بازگردانی چت به ربات');
                            $sendBtn.prop('disabled', false);
                            $closeBtn.prop('disabled', false);
                            $statusSpan.text('خطای غیرمنتظره در ارتباط با سرور.');
                        }
                    });
                });

                return $wrap;
            },

            /*
            ============================================
            راه‌اندازی lazy load برای یک مجموعه‌ی مشخص از placeholderها
            (نسخه‌ی گرفته‌شده از startLazyImageLoading که به‌جای پیدا کردن
            placeholderها داخل $chatArea، خودِ آن‌ها را مستقیماً می‌گیرد)
            ============================================
            */
            startLazyImageLoadingFor: function($placeholders, $chatArea) {
                var self = this;
                if (!$placeholders || !$placeholders.length) return;

                if ('IntersectionObserver' in window) {
                    if (!self._lazyObserver) {
                        self._lazyObserver = new IntersectionObserver(function(entries) {
                            entries.forEach(function(entry) {
                                if (entry.isIntersecting) {
                                    self._lazyObserver.unobserve(entry.target);
                                    self._enqueueLazyImage($(entry.target));
                                }
                            });
                        }, {
                            root: $chatArea[0],
                            rootMargin: '100px',
                            threshold: 0.05
                        });
                    }
                    $placeholders.each(function() {
                        self._lazyObserver.observe(this);
                    });
                } else {
                    $placeholders.each(function() {
                        self._enqueueLazyImage($(this));
                    });
                }
            },

            getStatusLabel: function(status) {
                switch (status) {
                    case 'pending_human': return 'در انتظار پشتیبان';
                    case 'bot': return 'ربات';
                    case 'closed': return 'بسته‌شده';
                    case 'human': return 'پشتیبان';
                    default: return status || 'نامشخص';
                }
            },

            // تبدیل میلادی به شمسی (الگوریتم استاندارد، دقیقاً هماهنگ با
            // نسخه‌ی PHP در includes/format.php)
            /*
            فیکس باگ تاریخ‌های تب «گفت‌وگوها و پشتیبانی»:
            نسخه‌ی قبلی به‌جای جدول تجمعیِ روزهای ماه‌های میلادی (g_d_m)
            از فرمول نادرست «(gm-2)*30 + (gm>6 ? 6 : 0)» استفاده می‌کرد
            که برای ماه‌های ۳ به بعد حدوداً ۲۹ روز کم محاسبه می‌کرد؛
            نتیجه این بود که هر گفت‌وگویی حدوداً یک ماه قدیمی‌تر از
            تاریخ واقعی‌اش نمایش داده می‌شد (مثلاً ۱۴۰۵/۰۷/۱۰ به‌جای
            نمایش درست، ۱۴۰۵/۰۶/۱۴ می‌شد). الگوریتم زیر همان نسخه‌ی
            استاندارد و تست‌شده‌ای است که در format.php هم استفاده شده.
            */
            toJalali: function(gy, gm, gd) {
                var g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
                var jy, jm, jd, gy2, days;
                gy2 = (gm > 2) ? (gy + 1) : gy;
                days = 355666 + (365 * gy) + (Math.floor((gy2 + 3) / 4)) - (Math.floor((gy2 + 99) / 100)) + (Math.floor((gy2 + 399) / 400)) + gd + g_d_m[gm - 1];
                jy = -1595 + (33 * Math.floor(days / 12053));
                days %= 12053;
                jy += 4 * Math.floor(days / 1461);
                days %= 1461;
                if (days > 365) {
                    jy += Math.floor((days - 1) / 365);
                    days = (days - 1) % 365;
                }
                if (days < 186) {
                    jm = 1 + Math.floor(days / 31);
                    jd = 1 + (days % 31);
                } else {
                    jm = 7 + Math.floor((days - 186) / 30);
                    jd = 1 + ((days - 186) % 30);
                }
                return jy + '/' + String(jm).padStart(2, '0') + '/' + String(jd).padStart(2, '0');
            }
        };


        // راه‌اندازی ماژول جلسات
        aiAgentSessions.init();
    });