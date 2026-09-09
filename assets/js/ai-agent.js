jQuery(function ($) {

    const button = $("#ai-agent-button");
    const windowChat = $("#ai-agent-window");
    const close = $("#ai-agent-close");
    const send = $("#ai-agent-send");
    const input = $("#ai-agent-input");
    const messages = $("#ai-agent-messages");
    const widget = $("#ai-agent");
    const drawer = $("#ai-agent-drawer");
    const drawerList = $("#ai-agent-drawer-list");
    const suggestionsBox = $("#ai-agent-suggestions");

    const CONFIG = window.ai_agent || {};

    /*
    ============================================
    مدیریت عکس‌های پیوست (Attach Images)

    کاربر با کلیک روی دکمه سنجاق می‌تواند تا حداکثر MAX_IMAGES عکس
    را انتخاب کند. عکس‌ها بلافاصله به base64 (data URI) تبدیل شده
    و در pendingImages نگهداری می‌شوند. پیش‌نمایش آن‌ها به‌صورت
    thumbnail بالای فوتر نمایش داده می‌شود. هنگام ارسال پیام، این
    عکس‌ها در بدنه‌ی درخواست به‌صورت آرایه‌ای از string‌های base64
    (images[]) به اندپوینت /api/v1/chat/messages ارسال می‌شوند و
    هم‌زمان در حباب پیام کاربر به‌صورت گالری + پرامپت زیر آن
    نمایش داده می‌شوند.
    ============================================
    */
    const attachBtn = $("#ai-agent-attach");
    const fileInput = $("#ai-agent-file-input");
    const attachmentsBox = $("#ai-agent-attachments");

    // حداکثر تعداد عکس‌های مجاز در هر پیام
    const MAX_IMAGES = (window.ai_agent && ai_agent.max_images) ? parseInt(ai_agent.max_images, 10) : 4;
    // حداکثر حجم هر عکس (برای جلوگیری از ارسال عکس‌های بسیار بزرگ) — ۵ مگابایت
    const MAX_IMAGE_BYTES = 5 * 1024 * 1024;

    // آرایه‌ی عکس‌های انتخاب‌شده قبل از ارسال
    // هر آیتم: { id: string, name: string, dataUrl: string }
    let pendingImages = [];
    let attachIdCounter = 0;

    /*
    ============================================
    تم

    دیگر انتخابِ بازدیدکننده نیست. آیکون ماه/خورشید داخل هدر حذف شد و
    مقدارِ theme_mode از تنظیمات افزونه می‌آید:

      auto  → از سایت میزبان پیروی کن
      light → همیشه روشن
      dark  → همیشه تاریک

    در حالت auto اول به خود سایت نگاه می‌کنیم، نه به تنظیم سیستم‌عامل:
    یک فروشگاهِ همیشه‌روشن روی گوشی‌ای که دارک‌مود دارد، نباید وسطش یک
    ویجت مشکی داشته باشد. نشانه‌های زیر تقریباً همه‌ی قالب‌ها را پوشش
    می‌دهند؛ اگر هیچ‌کدام نبود، به prefers-color-scheme برمی‌گردیم.
    ============================================
    */
    function detectSiteTheme() {
        const root = document.documentElement;
        const body = document.body;

        // ۱) اعلام صریح خود سایت
        const declared = (root.getAttribute('data-theme') ||
                          root.getAttribute('data-color-scheme') ||
                          body.getAttribute('data-theme') || '').toLowerCase();
        if (declared.indexOf('dark') !== -1) return 'dark';
        if (declared.indexOf('light') !== -1) return 'light';

        // ۲) کلاس‌های مرسوم
        const classes = (root.className + ' ' + body.className).toLowerCase();
        if (/(^|\s|-)dark(-mode|-theme)?(\s|$)/.test(classes)) return 'dark';
        if (/(^|\s|-)light(-mode|-theme)?(\s|$)/.test(classes)) return 'light';

        // ۳) رنگ واقعی پس‌زمینه‌ی صفحه. قابل‌اعتمادترین نشانه است، چون
        //    نتیجه‌ی هر کاری است که قالب واقعاً کرده، نه نامی که گذاشته.
        try {
            const bg = getComputedStyle(body).backgroundColor || '';
            const m = bg.match(/rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/);
            if (m) {
                const alpha = bg.match(/rgba\([^)]+,\s*([\d.]+)\s*\)/);
                // پس‌زمینه‌ی کاملاً شفاف چیزی درباره‌ی تم نمی‌گوید
                if (!alpha || parseFloat(alpha[1]) > 0.1) {
                    const luminance = (0.2126 * (+m[1]) + 0.7152 * (+m[2]) + 0.0722 * (+m[3])) / 255;
                    return luminance < 0.4 ? 'dark' : 'light';
                }
            }
        } catch (e) {
            // getComputedStyle در بعضی محیط‌ها خطا می‌دهد؛ می‌افتیم روی گام بعد
        }

        // ۴) تنظیم سیستم‌عامل
        return (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches)
            ? 'dark' : 'light';
    }

    function applyTheme(theme) {
        widget.attr('data-theme', theme === 'dark' ? 'dark' : 'light');
    }

    function initTheme() {
        const mode = CONFIG.theme_mode || 'auto';
        if (mode === 'light' || mode === 'dark') {
            applyTheme(mode);
            return;
        }
        applyTheme(detectSiteTheme());

        // اگر سایت تمش را عوض کرد (کلید شب/روزِ خود قالب)، ویجت هم دنبالش
        // می‌رود. بدون این، کاربر تم سایت را عوض می‌کرد و چت روی حالت قبلی
        // جا می‌ماند.
        if (window.MutationObserver) {
            const themeWatcher = new MutationObserver(function () {
                applyTheme(detectSiteTheme());
            });
            themeWatcher.observe(document.documentElement, {
                attributes: true,
                attributeFilter: ['class', 'data-theme', 'data-color-scheme'],
            });
            themeWatcher.observe(document.body, {
                attributes: true,
                attributeFilter: ['class', 'data-theme'],
            });
        }
        if (window.matchMedia) {
            const query = window.matchMedia('(prefers-color-scheme: dark)');
            const onChange = function () { applyTheme(detectSiteTheme()); };
            if (query.addEventListener) query.addEventListener('change', onChange);
            else if (query.addListener) query.addListener(onChange);
        }
    }

    initTheme();

    /*
    ============================================
    شناسه‌ی بازدیدکننده

    یک توکن تصادفی که در مرورگر می‌ماند و هر گفت‌وگوی تازه با آن ساخته
    می‌شود. تنها چیزی است که چتِ دومِ یک نفر را به چتِ اولش وصل می‌کند —
    بدون آن، فهرست «گفت‌وگوهای پیشین» ممکن نبود، چون هر مکالمه شناسه‌ی
    تصادفیِ جدا می‌گرفت.

    هیچ‌کس را شناسایی نمی‌کند: نه ایمیل، نه شماره، نه چیزی که بشود از
    آن به آدم رسید. فقط یک عدد تصادفی که مرورگر نگه می‌دارد.
    ============================================
    */
    const VISITOR_STORAGE_KEY = 'ai_agent_visitor_id';
    let visitorId = null;

    function getVisitorId() {
        if (visitorId) return visitorId;
        try {
            visitorId = localStorage.getItem(VISITOR_STORAGE_KEY);
        } catch (e) {
            visitorId = null;
        }
        if (!visitorId || !/^[0-9a-f]{16,64}$/.test(visitorId)) {
            visitorId = randomHex(32);
            try {
                localStorage.setItem(VISITOR_STORAGE_KEY, visitorId);
            } catch (e) {
                // حالت مرور خصوصی: توکن فقط تا پایان همین بازدید زنده است،
                // یعنی تاریخچه در بازدید بعدی خالی خواهد بود. این بهتر از
                // خطا دادن است.
            }
        }
        return visitorId;
    }

    /** ارقام لاتین به فارسی — همه‌ی عددهای داخل ویجت فارسی نوشته می‌شوند. */
    function toFaDigits(value) {
        const fa = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        return String(value).replace(/[0-9]/g, function (d) { return fa[+d]; });
    }

    function randomHex(chars) {
        const bytes = new Uint8Array(chars / 2);
        if (window.crypto && window.crypto.getRandomValues) {
            window.crypto.getRandomValues(bytes);
        } else {
            for (let i = 0; i < bytes.length; i++) {
                bytes[i] = Math.floor(Math.random() * 256);
            }
        }
        return Array.prototype.map.call(bytes, function (b) {
            return ('0' + b.toString(16)).slice(-2);
        }).join('');
    }

    /*
    ============================================
    صفحه‌ی شروع

    به‌جای «چطور می‌تونم کمکتون کنم؟» — جمله‌ای که کاربر را جلوی یک
    فیلد خالی تنها می‌گذاشت — چند پیشنهاد قابل کلیک. متن‌شان در PHP از
    روی همان اطلاعاتی ساخته می‌شود که مدیر در تنظیمات افزونه پر کرده،
    پس روی سایتی که شماره‌ای ثبت نکرده، پیشنهادِ شماره‌ی تماس هم نیست.
    ============================================
    */
    /*
    بدون نشان دانیچَت بالای این جمله؛ خودِ صفحه‌ی شروع، صفحه است، نه یک
    اعلانِ برند. سؤال‌های پیشنهادی هم دیگر دکمه‌ی کادردار با فلش نیستند
    — لینک‌های ریز زیر جمله، شبیه پانویس‌های قابل‌کلیک.
    */
    function renderIntro() {
        const starters = Array.isArray(CONFIG.starters) ? CONFIG.starters : [];

        const $intro = $('<div class="ai-agent-intro"></div>');
        $intro.append(
            $('<div class="ai-agent-intro-title"></div>')
                .text('آماده‌ای یه مکالمه هیجان‌انگیز داشته باشیم؟')
        );

        if (starters.length) {
            const $links = $('<div class="ai-agent-intro-links"></div>');
            starters.forEach(function (item) {
                if (!item || !item.label) return;
                const $link = $('<a href="#" class="ai-agent-intro-link"></a>').text(item.label);
                $link.on('click', function (e) {
                    e.preventDefault();
                    submitPrompt(item.prompt || item.label);
                });
                $links.append($link);
            });
            $intro.append($links);
        }

        messages.empty().append($intro);
    }

    /** پاک کردن صفحه‌ی شروع به‌محض این‌که گفت‌وگو واقعاً شروع شود. */
    function clearIntro() {
        messages.find('.ai-agent-intro').remove();
    }

    /*
    ارسال یک متن آماده (تراشه‌ی شروع یا پیشنهاد ادامه) بدون این‌که کاربر
    مجبور باشد آن را تایپ کند. عمداً فوکوس نمی‌گیرد: روی موبایل باز شدن
    کیبورد بلافاصله بعد از کلیک، پاسخی را که همان لحظه شروع به آمدن
    کرده از دید پنهان می‌کند.
    */
    function submitPrompt(text) {
        if (!text) return;
        input.val(text);
        autoResizeInput();
        updateSendButtonState();
        clearSuggestions();
        sendMessage();
    }

    /*
    ============================================
    پیشنهادهای ادامه‌ی گفت‌وگو

    سرور بعد از هر پاسخ یکی دو سؤال بعدی را می‌فرستد. با شروع پیام
    بعدی پاک می‌شوند — پیشنهادی که به پاسخِ قبلی مربوط است، زیر یک
    گفت‌وگوی جلورفته بی‌ربط می‌شود.
    ============================================
    */
    function renderSuggestions(list) {
        clearSuggestions();
        if (!Array.isArray(list) || !list.length) return;

        list.slice(0, 3).forEach(function (text) {
            if (!text) return;
            const $chip = $('<button type="button" class="ai-agent-chip"></button>').text(text);
            $chip.on('click', function () { submitPrompt(text); });
            suggestionsBox.append($chip);
        });
        suggestionsBox.prop('hidden', false);
        scrollToBottom();
    }

    function scrollToBottom() {
        if (messages.length) messages.scrollTop(messages[0].scrollHeight);
    }

    function clearSuggestions() {
        suggestionsBox.empty().prop('hidden', true);
    }

    /*
    ============================================
    دکمه‌های تماس زیر پاسخ

    وقتی دستیار درباره‌ی راه‌های ارتباطی حرف زده، شماره یا آی‌دی را به
    دکمه تبدیل می‌کنیم: روی گوشی، tel: برنامه‌ی تماس را باز می‌کند و
    لینک‌های تلگرام/اینستاگرام مستقیم به همان اپ می‌روند.

    فقط وقتی نشان داده می‌شوند که متنِ پاسخ واقعاً به آن راه ارتباطی
    اشاره کرده باشد. چسباندن دکمه‌ی تماس زیر هر پاسخی، آن را به تبلیغ
    ثابتِ ته صفحه تبدیل می‌کرد.
    ============================================
    */
    const ACTION_ICONS = {
        phone: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.13.96.36 1.9.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.9.34 1.85.57 2.81.7A2 2 0 0 1 22 16.92z"/></svg>',
        telegram: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>',
        instagram: '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="2" width="20" height="20" rx="5"/><circle cx="12" cy="12" r="4"/><line x1="17.5" y1="6.5" x2="17.5" y2="6.5"/></svg>',
    };

    // نشانه‌های متنی هر راه ارتباطی. اگر پاسخ هیچ‌کدام را نگفته باشد،
    // دکمه‌ای هم ساخته نمی‌شود.
    const ACTION_HINTS = {
        phone: ['تماس', 'تلفن', 'شماره', 'زنگ'],
        telegram: ['تلگرام', 'telegram'],
        instagram: ['اینستاگرام', 'instagram', 'اینستا'],
    };

    function buildContactActions(answerText) {
        const contacts = Array.isArray(CONFIG.contacts) ? CONFIG.contacts : [];
        if (!contacts.length) return null;

        const haystack = String(answerText || '').toLowerCase();
        const matched = contacts.filter(function (c) {
            const hints = ACTION_HINTS[c.type] || [];
            return hints.some(function (h) { return haystack.indexOf(h) !== -1; });
        });
        if (!matched.length) return null;

        const $wrap = $('<div class="ai-agent-actions"></div>');
        matched.slice(0, 3).forEach(function (c) {
            const $a = $('<a class="ai-agent-action" target="_blank" rel="noopener noreferrer"></a>')
                .attr('href', c.url);
            // tel: نباید در تب تازه باز شود؛ روی دسکتاپ یک صفحه‌ی خالی می‌ماند.
            if (c.type === 'phone') $a.removeAttr('target').removeAttr('rel');
            $a.append(ACTION_ICONS[c.type] || '');
            $a.append($('<span></span>').text(c.label));
            $wrap.append($a);
        });
        return $wrap;
    }

    /*
    ============================================
    باز کردن پنجره Browse با کلیک روی دکمه سنجاق
    فایل‌اینپوت مخفی reset می‌شود تا بتوان همان فایل را دوباره انتخاب کرد
    (در غیر این‌صورت change event برای انتخاب مجدد یک فایل fires نمی‌شود).
    ============================================
    */
    attachBtn.on("click", function () {
        fileInput.val(''); // ریست برای امکان انتخاب مجدد همان فایل
        fileInput.trigger("click");
    });

    /*
    ============================================
    هندلر انتخاب فایل از پنجره Browse

    فقط فایل‌های image/* پذیرفته می‌شوند (به‌علاوه‌ی فیلتر سمت سرور).
    تعداد کل عکس‌های انتخاب‌شده نمی‌تواند از MAX_IMAGES بیشتر شود؛
    عکس‌های اضافی نادیده گرفته می‌شوند و یک پیام کوتاه به کاربر نمایش
    داده می‌شود. هر فایل با FileReader.readAsDataURL به base64 تبدیل
    شده و به pendingImages اضافه می‌شود.
    ============================================
    */
    fileInput.on("change", function () {
        const files = this.files;
        if (!files || files.length === 0) return;

        const availableSlots = MAX_IMAGES - pendingImages.length;
        if (availableSlots <= 0) {
            alert('حداکثر ' + MAX_IMAGES + ' عکس می‌توانید اضافه کنید.');
            return;
        }

        let addedCount = 0;
        // فقط عکس‌هایی که به‌خاطر رسیدن به سقف MAX_IMAGES رد شدند (نه به‌خاطر نوع/حجم نامعتبر)
        let limitSkippedCount = 0;
        const filesToProcess = Array.prototype.slice.call(files);

        filesToProcess.forEach(function (file) {
            if (addedCount >= availableSlots) {
                limitSkippedCount++;
                return;
            }
            // فقط فایل‌های عکس پذیرفته می‌شوند
            if (!file.type || file.type.indexOf('image/') !== 0) {
                return;
            }
            // محدودیت حجم
            if (file.size > MAX_IMAGE_BYTES) {
                alert('عکس «' + (file.name || 'نامشخص') + '» بزرگ‌تر از ۵ مگابایت است و اضافه نشد.');
                return;
            }

            const reader = new FileReader();
            reader.onload = function (e) {
                const dataUrl = e.target && e.target.result ? String(e.target.result) : '';
                if (!dataUrl) return;

                pendingImages.push({
                    id: 'att-' + (++attachIdCounter),
                    name: file.name || 'image',
                    dataUrl: dataUrl
                });
                renderAttachments();
            };
            reader.onerror = function () {
                // در صورت خطا در خواندن فایل، بی‌سر و صدا نادیده گرفته می‌شود
            };
            reader.readAsDataURL(file);
            addedCount++;
        });

        // هر زمان که تعدادی از عکس‌ها فقط به‌خاطر رسیدن به سقف ۴ تایی رد شده باشند،
        // هشدار نمایش داده می‌شود؛ چه هیچ عکسی اضافه نشده باشد و چه بخشی از آن‌ها اضافه شده باشند
        if (limitSkippedCount > 0) {
            alert('حداکثر ' + MAX_IMAGES + ' عکس می‌توانید اضافه کنید. ' + limitSkippedCount + ' عکس اضافه به همین دلیل اضافه نشد.');
        }
    });

    /*
    ============================================
    رندر کردن پیش‌نمایش عکس‌های انتخاب‌شده بالای فوتر

    هر عکس به‌صورت یک thumbnail با دکمه حذف (×) نمایش داده می‌شود.
    با کلیک روی خود thumbnail، عکس در لایت‌باکس به اندازه‌ی کامل
    نمایش داده می‌شود. اگر عکسی انتخاب نشده باشد، کل باکس مخفی
    می‌شود و عداد روی دکمه سنجاق هم پنهان می‌شود.
    ============================================
    */
    function renderAttachments() {
        attachmentsBox.empty();

        if (pendingImages.length === 0) {
            attachmentsBox.removeClass('has-items');
            attachBtn.removeClass('has-attachments');
            attachBtn.find('.ai-attach-badge').text('0');
            return;
        }

        attachmentsBox.addClass('has-items');
        attachBtn.addClass('has-attachments');
        attachBtn.find('.ai-attach-badge').text(String(pendingImages.length));

        pendingImages.forEach(function (img) {
            const $thumb = $('<div class="ai-attach-thumb"></div>').attr('data-id', img.id);
            const $img = $('<img alt="" />').attr('src', img.dataUrl);
            // با کلیک روی thumbnail، عکس در لایت‌باکس بزرگ نمایش داده می‌شود
            $img.on('click', function () {
                openLightbox(img.dataUrl);
            });
            const $remove = $('<button type="button" class="ai-attach-thumb-remove" title="حذف">×</button>');
            $remove.on('click', function (e) {
                e.stopPropagation();
                pendingImages = pendingImages.filter(function (p) { return p.id !== img.id; });
                renderAttachments();
            });

            $thumb.append($img).append($remove);
            attachmentsBox.append($thumb);
        });
    }

    /*
    ============================================
    پاک کردن تمام عکس‌های انتخاب‌شده (بعد از ارسال)
    ============================================
    */
    function clearAttachments() {
        pendingImages = [];
        renderAttachments();
    }

    /*
    ============================================
    لایت‌باکس نمایش عکس در اندازه‌ی کامل

    با کلیک روی هر عکس (چه در پیش‌نمایش و چه در گالری پیام کاربر)
    لایت‌باکس باز می‌شود. با کلیک روی هر نقطه از صفحه یا زدن Esc
    بسته می‌شود. فقط یک لایت‌باکس در صفحه وجود دارد و دوباره
    استفاده می‌شود.
    ============================================
    */
    let $lightbox = null;
    function ensureLightbox() {
        if ($lightbox && $lightbox.length) return $lightbox;
        $lightbox = $('<div class="ai-image-lightbox" aria-hidden="true"></div>');
        const $img = $('<img alt="" />');
        $lightbox.append($img);
        // با کلیک روی پس‌زمینه یا خود عکس، لایت‌باکس بسته می‌شود
        $lightbox.on('click', function () {
            closeLightbox();
        });
        $(document).on('keydown.lightbox', function (e) {
            if (e.key === 'Escape') closeLightbox();
        });
        $('body').append($lightbox);
        return $lightbox;
    }
    function openLightbox(src) {
        ensureLightbox();
        $lightbox.find('img').attr('src', src);
        $lightbox.addClass('is-open').attr('aria-hidden', 'false');
    }
    function closeLightbox() {
        if ($lightbox && $lightbox.length) {
            $lightbox.removeClass('is-open').attr('aria-hidden', 'true');
            $lightbox.find('img').attr('src', '');
        }
    }

    /*
    ============================================
    مدیریت Session ID

    session_id از پاسخ API (اندپوینت chat/messages) دریافت و در کوکی مرورگر ذخیره می‌شود.
    اگر کوکی موجود نباشد (اولین بار بازدید)، پیام بدون session_id ارسال می‌شود
    و API یک session جدید ساخته و session_id را در پاسخ برمی‌گرداند.
    ============================================
    */
    function isValidUUID(uuid) {
        return /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(uuid);
    }

    function getSessionId() {
        const cookieName = (window.ai_agent && ai_agent.session_cookie) ? ai_agent.session_cookie : 'ai_agent_session_id';
        const nameEq = cookieName + '=';
        const ca = document.cookie.split(';');
        for (let i = 0; i < ca.length; i++) {
            let c = ca[i].trim();
            if (c.indexOf(nameEq) === 0) {
                const val = c.substring(nameEq.length, c.length);
                if (isValidUUID(val)) return val;
            }
        }
        return null; // کوکی موجود نیست → پاسخ API ساخت session می‌کند
    }

    function setSessionId(id) {
        if (!id || !isValidUUID(id)) return;
        sessionId = id;
        const cookieName = (window.ai_agent && ai_agent.session_cookie) ? ai_agent.session_cookie : 'ai_agent_session_id';
        const expires = new Date(Date.now() + 7 * 24 * 60 * 60 * 1000).toUTCString();
        document.cookie = cookieName + '=' + id + '; expires=' + expires + '; path=/; SameSite=Lax';
    }

    /*
    ============================================
    مدیریت کوکی تعداد پیام‌های دیده‌شده توسط کاربر (ai_agent_msg_count)

    این کوکی تعداد کل پیام‌هایی که کاربر تا الان در ویجت دیده/بارگذاری
    کرده را ذخیره می‌کند. هنگام polling در حالت پشتیبانی، این مقدار با
    message_count از پاسخ API مقایسه می‌شود:
        - اگر message_count > cookie: پیام جدید از طرف پشتیبان آمده
        - اگر message_count <= cookie: پیام جدیدی وجود ندارد
    ============================================
    */
    const MSG_COUNT_COOKIE_NAME = 'ai_agent_msg_count';

    function getMsgCount() {
        const nameEq = MSG_COUNT_COOKIE_NAME + '=';
        const ca = document.cookie.split(';');
        for (let i = 0; i < ca.length; i++) {
            let c = ca[i].trim();
            if (c.indexOf(nameEq) === 0) {
                const val = parseInt(c.substring(nameEq.length, c.length), 10);
                return isNaN(val) ? 0 : val;
            }
        }
        return 0;
    }

    function setMsgCount(n) {
        const val = Math.max(0, parseInt(n, 10) || 0);
        const expires = new Date(Date.now() + 7 * 24 * 60 * 60 * 1000).toUTCString();
        document.cookie = MSG_COUNT_COOKIE_NAME + '=' + val + '; expires=' + expires + '; path=/; SameSite=Lax';
    }

    function clearMsgCount() {
        document.cookie = MSG_COUNT_COOKIE_NAME + '=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/; SameSite=Lax';
    }

    function incrementMsgCount(by) {
        by = by || 1;
        setMsgCount(getMsgCount() + by);
    }

    // پاک کردن کوکی session_id (برای شروع چت جدید)
    // نکته: کوکی ai_agent_escalated_session حذف شد — از همان ai_agent_session_id
    // استفاده می‌شود چون مقدار هر دو یکسان است.
    function clearSessionId() {
        sessionId = null;
        const cookieName = (window.ai_agent && ai_agent.session_cookie) ? ai_agent.session_cookie : 'ai_agent_session_id';
        document.cookie = cookieName + '=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/; SameSite=Lax';
        // پاک کردن کوکی تعداد پیام‌های دیده‌شده
        clearMsgCount();
    }

    let sessionId = getSessionId();





    /*
    ============================================
    گوش‌به‌زنگ هوشمند برای اسکرول خودکار (Mutation Observer)
    ============================================
    */
    const observer = new MutationObserver(function (mutations) {
        messages.stop().animate({ scrollTop: messages[0].scrollHeight }, 300);
    });
    observer.observe(messages[0], { childList: true, subtree: true });

    /*
    ============================================
    باز و بسته کردن چت همراه با اسکرول خودکار

    نکته‌ی موبایل: هنگام باز شدن چت، کلاس ai-agent-chat-open به
    تگ <body> اضافه می‌شود تا در حالت تمام‌صفحه (CSS media query)،
    اسکرول پس‌زمینه‌ی صفحه قفل شود و کاربر نتواند صفحه‌ی پشت ویجت
    را اسکرول کند. هنگام بستن چت، این کلاس حذف می‌شود.

    این مکانیزم فقط در موبایل (عرض <= 768px) فعال می‌شود. در
    دسکتاپ، چت شناور است و صفحه‌ی زیرین همچنان قابل اسکرول است.
    ============================================
    */
    function isMobileViewport() {
        return window.matchMedia('(max-width: 768px)').matches;
    }

    /*
    ============================================
    رفع مشکل نوار پایین سافاری موبایل روی iOS:

    واحد 100vh در سافاری بر اساس بزرگ‌ترین حالت ممکن ویوپورت
    محاسبه می‌شود (یعنی زمانی که نوار آدرس/نوار پایین جمع شده
    باشد)، در حالی که در لحظه‌ی لود صفحه یا هنگام اسکرول، آن نوار
    معمولاً نمایش داده می‌شود. نتیجه: باکس با ارتفاع 100vh رندر
    می‌شود ولی بخشی از پایین آن (دقیقاً همان‌جا که فوتر و اینپوت
    تایپ قرار دارند) زیر نوار مرورگر پنهان می‌ماند.

    این تابع ارتفاع واقعیِ قابل‌نمایش را (با اولویت از
    window.visualViewport که دقیق‌ترین منبع است) اندازه می‌گیرد
    و آن را به‌صورت یک متغیر CSS با واحد ۱٪ در ریشه‌ی سند قرار
    می‌دهد. در CSS از calc(var(--ai-agent-vh) * 100) استفاده شده
    که هم روی سافاری قدیمی (بدون پشتیبانی از 100dvh) کار می‌کند و
    هم لحظه‌ی باز شدن کیبورد را به‌درستی پوشش می‌دهد.
    ============================================
    */
    function updateViewportHeightVar() {
        var vh = (window.visualViewport ? window.visualViewport.height : window.innerHeight) * 0.01;
        document.documentElement.style.setProperty('--ai-agent-vh', vh + 'px');
    }

    /*
    ============================================
    جبران باگ اسکرول خودکار سافاری iOS هنگام فوکوس روی اینپوت:

    وقتی کاربر روی تکست‌باکس داخل ویجت (که position: fixed دارد)
    فوکوس می‌کند، سافاری صفحه را کمی اسکرول می‌کند تا اینپوت را به
    دید بیاورد — این کار حتی با وجود قفل بودن body هم اتفاق می‌افتد.
    نتیجه: visualViewport نسبت به layout viewport یک offsetTop
    پیدا می‌کند، اما چون باکس ما top:0 نسبت به layout viewport
    دارد، دیگر با ناحیه‌ی واقعیِ قابل‌دیدن هم‌تراز نیست و همان
    فاصله‌ی خالی بین کیبورد و باکس ایجاد می‌شود.

    این تابع height و top باکس را مستقیماً از روی visualViewport
    (height واقعی + offsetTop) ست می‌کند تا باکس همیشه دقیقاً چسبیده
    به بالای صفحه‌ی نمایش داده‌شده بماند و کف آن هم دقیقاً روی
    نوار بالای کیبورد بنشیند.
    ============================================
    */
    function syncWindowToVisualViewport() {
        if (!window.visualViewport) return;
        var el = windowChat[0];
        if (!isMobileViewport() || !windowChat.hasClass('ai-agent-open')) {
            // خارج از حالت موبایل تمام‌صفحه: هر مقدار inline قبلی را پاک کن
            el.style.removeProperty('top');
            el.style.removeProperty('height');
            return;
        }
        var vv = window.visualViewport;
        el.style.setProperty('height', vv.height + 'px', 'important');
        el.style.setProperty('top', vv.offsetTop + 'px', 'important');
    }

    function syncViewportGeometry() {
        updateViewportHeightVar();
        syncWindowToVisualViewport();
    }

    syncViewportGeometry();
    window.addEventListener('resize', syncViewportGeometry);
    window.addEventListener('orientationchange', syncViewportGeometry);
    if (window.visualViewport) {
        window.visualViewport.addEventListener('resize', syncViewportGeometry);
        window.visualViewport.addEventListener('scroll', syncViewportGeometry);
    }

    function lockBodyScroll() {
        if (!isMobileViewport()) return;
        // ذخیره‌ی موقعیت اسکرول فعلی صفحه برای بازگرداندن بعد از بستن چت
        if (!document.body.dataset.aiAgentScrollY) {
            document.body.dataset.aiAgentScrollY = String(window.scrollY || 0);
        }
        document.body.classList.add('ai-agent-chat-open');
        document.body.style.top = '-' + (window.scrollY || 0) + 'px';
        document.body.style.position = 'fixed';
        document.body.style.width = '100%';
    }

    function unlockBodyScroll() {
        document.body.classList.remove('ai-agent-chat-open');
        document.body.style.position = '';
        document.body.style.top = '';
        document.body.style.width = '';
        // بازگرداندن موقعیت اسکرول
        var y = parseInt(document.body.dataset.aiAgentScrollY || '0', 10);
        delete document.body.dataset.aiAgentScrollY;
        if (!isNaN(y) && y > 0) {
            window.scrollTo(0, y);
        }
    }

    button.on("click", function () {
        windowChat.toggleClass("ai-agent-open");
        if (windowChat.hasClass("ai-agent-open")) {
            syncViewportGeometry();
            messages.scrollTop(messages[0].scrollHeight);
            input.focus();
            lockBodyScroll();
        } else {
            syncViewportGeometry();
            unlockBodyScroll();
        }
    });

    close.on("click", function () {
        windowChat.removeClass("ai-agent-open");
        syncViewportGeometry();
        unlockBodyScroll();
    });

    /*
    اگر کاربر در حالی چت باز است، اندازه‌ی صفحه را تغییر دهد (مثلاً
    موبایل → دسکتاپ یا برعکس)، body scroll lock را بر اساس viewport
    جدید به‌روز می‌کنیم تا در دسکتاپ قفل اسکرول باقی نماند.
    */
    $(window).on('resize', function () {
        if (!windowChat.hasClass('ai-agent-open')) return;
        if (!isMobileViewport() && document.body.classList.contains('ai-agent-chat-open')) {
            // از موبایل به دسکتاپ تغییر کرده — قفل را بردار
            unlockBodyScroll();
        } else if (isMobileViewport() && !document.body.classList.contains('ai-agent-chat-open')) {
            // از دسکتاپ به موبایل تغییر کرده — قفل را اضافه کن
            lockBodyScroll();
        }
    });

    /*
    ============================================
    شروع چت جدید: پاک کردن کوکی session_id و
    بارگذاری مجدد ویجت از ابتدا (پاک شدن تاریخچه‌ی نمایشی)
    ============================================
    */
    const newChatBtn = $("#ai-agent-new-chat");

    function startNewChat() {
        clearSessionId();

        // ریست وضعیت جلسه به حالت نامشخص (در واقع حالت ربات برای جلسه‌ی جدید)
        currentSessionStatus = '';

        stopPolling();
        setChatDisabled(false);
        unlockChatAfterTransfer();
        loadTransferOptions();
        closeDrawer();
        clearSuggestions();

        // صفحه‌ی شروع، با پیشنهادهای ساخته‌شده از تنظیمات مدیر
        renderIntro();

        input.val('');
        autoResizeInput();
        updateSendButtonState();
        clearAttachments();

        // اگر ضبط صدا در حال اجراست، لغو می‌شود (بدون نوشتن متن ناقص)
        $(document).trigger('ai-agent-chat-reset');
    }

    /*
    «چت تازه» دیگر فوکوس نمی‌گیرد.

    قبلاً input.focus() صدا زده می‌شد و روی موبایل بلافاصله کیبورد بالا
    می‌آمد و نیمی از صفحه را می‌گرفت — در حالی که کاربر تازه یک چت خالی
    باز کرده و اولین کاری که احتمالاً می‌کند نگاه کردن به پیشنهادهاست،
    نه تایپ کردن. اگر بخواهد بنویسد، یک ضربه روی فیلد کافی است.
    */
    newChatBtn.on("click", startNewChat);

    /*
    ============================================
    کشوی گفت‌وگوهای پیشین
    ============================================
    */
    const historyBtn = $("#ai-agent-history");

    function closeDrawer() {
        drawer.prop('hidden', true);
    }

    function openDrawer() {
        drawer.prop('hidden', false);
        loadVisitorSessions();
    }

    historyBtn.on('click', function () {
        if (drawer.prop('hidden')) openDrawer();
        else closeDrawer();
    });

    $("#ai-agent-drawer-close").on('click', closeDrawer);

    function loadVisitorSessions() {
        drawerList.html('<div class="ai-agent-drawer-empty">در حال بارگذاری...</div>');

        $.ajax({
            url: CONFIG.ajax_url,
            method: 'GET',
            data: {
                action: 'ai_agent_visitor_sessions',
                visitor_id: getVisitorId(),
            },
        }).done(function (res) {
            const items = (res && res.success && res.data && Array.isArray(res.data.items))
                ? res.data.items : [];
            renderDrawer(items);
        }).fail(function () {
            drawerList.html(
                '<div class="ai-agent-drawer-empty">فهرست گفت‌وگوها در دسترس نیست.</div>'
            );
        });
    }

    function renderDrawer(items) {
        drawerList.empty();

        if (!items.length) {
            drawerList.html(
                '<div class="ai-agent-drawer-empty">هنوز گفت‌وگویی نداشته‌اید.<br>' +
                'هر گفت‌وگویی که شروع کنید این‌جا ذخیره می‌شود.</div>'
            );
            return;
        }

        const current = getSessionId();

        items.forEach(function (item) {
            const $row = $('<button type="button" class="ai-agent-drawer-item"></button>');
            if (item.id === current) $row.addClass('is-current');

            $row.append($('<span class="ai-agent-drawer-title"></span>').text(item.title || 'گفت‌وگو'));
            $row.append(
                $('<span class="ai-agent-drawer-meta"></span>')
                    .text(toFaDigits(item.message_count || 0) + ' پیام')
            );

            $row.on('click', function () {
                openSession(item.id);
            });
            drawerList.append($row);
        });
    }

    /*
    باز کردن یک گفت‌وگوی قدیمی: شناسه‌اش را جای شناسه‌ی فعلی می‌گذاریم و
    تاریخچه را از همان مسیری می‌خوانیم که هنگام بازکردن دوباره‌ی ویجت
    استفاده می‌شود، تا فقط یک راه برای بازسازی یک گفت‌وگو وجود داشته باشد.
    */
    function openSession(sessionId) {
        if (!sessionId) return;
        setSessionId(sessionId);
        closeDrawer();
        clearSuggestions();
        messages.empty();
        loadChatHistory();
    }

    /*
    ============================================
    افزودن پیام با استایل‌های اختصاصی و متحرک

    پارامتر images (آرایه‌ای از data URL ها) فقط برای پیام‌های کاربر
    استفاده می‌شود. اگر عکسی موجود باشد، یک گالری از thumbnail‌ها
    داخل حباب پیام نمایش داده می‌شود و متن (پرامپت) زیر گالری
    قرار می‌گیرد. با کلیک روی هر عکس، لایت‌باکس اندازه‌ی کامل باز می‌شود.

    پارامتر imageKeys (آرایه‌ای از string) برای پیام‌هایی است که از
    تاریخچه بارگذاری شده‌اند و عکس‌های آن‌ها به‌صورت lazy بارگذاری می‌شوند.
    در این حالت ابتدا یک placeholder خاکستری نمایش داده می‌شود و سپس
    پس از لود شدن عکس از سرور، با آن جایگزین می‌گردد.
    ============================================
    */
    function addMessage(type, text, chatId, images, imageKeys) {
        chatId = chatId || null;
        images = Array.isArray(images) ? images : [];
        imageKeys = Array.isArray(imageKeys) ? imageKeys : [];
        let cls = "";
        if (type === "user") cls = "user-message";
        else if (type === "admin") cls = "admin-message";
        else cls = "ai-message";

        if (type === "user" || type === "admin") {
            let titlePrefix = type === "admin" ? "<strong>پاسخ کارشناس:</strong><br>" : "";

            // ساخت گالری عکس‌ها (فقط برای پیام کاربر که images دارد)
            let galleryHtml = '';
            if (images.length > 0) {
                galleryHtml = '<div class="user-message-gallery">' +
                    images.map(function (img) {
                        // img یک data URL است؛ از آن مستقیماً در src استفاده می‌کنیم
                        return '<a class="user-gallery-item" href="#" rel="noopener noreferrer">' +
                            '<img src="' + img + '" alt="عکس ارسالی کاربر" />' +
                            '</a>';
                    }).join('') +
                    '</div>';
            } else if (imageKeys.length > 0) {
                // حالت lazy: برای هر کلید یک placeholder خاکستری نمایش می‌دهیم
                // که به محض دریافت عکس از سرور، با عکس واقعی جایگزین می‌شود.
                galleryHtml = '<div class="user-message-gallery">' +
                    imageKeys.map(function (k) {
                        return '<a class="user-gallery-item is-loading" href="#" rel="noopener noreferrer" data-image-key="' + escapeAttr(k) + '">' +
                            '<span class="user-gallery-placeholder"></span>' +
                            '</a>';
                    }).join('') +
                    '</div>';
            }

            // پرامپت کاربر؛ اگر فقط عکس ارسال شده و متنی نبود، خالی می‌ماند
            let promptHtml = '';
            if (text && String(text).trim() !== '') {
                promptHtml = '<div class="user-message-prompt">' + text + '</div>';
            }

            const $msg = $('<div class="' + cls + ' fade-in-up"></div>');
            $msg.append(titlePrefix + galleryHtml + promptHtml);

            // هندلر کلیک روی عکس‌های گالری → باز شدن لایت‌باکس
            $msg.find('.user-gallery-item').on('click', function (e) {
                e.preventDefault();
                const src = $(this).find('img').attr('src');
                if (src) openLightbox(src);
            });

            messages.append($msg);

            // اگر imageKeys داشتیم، placeholderها را زیر نظر IntersectionObserver
            // قرار می‌دهیم تا به محض ورود به viewport (یا نزدیک شدن به آن)، عکس
            // مربوطه از سرور به‌صورت یکی‌یکی دریافت شود.
            if (imageKeys.length > 0) {
                const observer = ensureLazyObserver();
                $msg.find('.user-gallery-item.is-loading').each(function () {
                    var $ph = $(this);
                    if (observer) {
                        observer.observe($ph[0]);
                    } else {
                        // اگر IntersectionObserver پشتیبانی نمی‌شد (مرورگرهای قدیمی)،
                        // مستقیماً در صف قرار می‌دهیم تا یکی‌یکی لود شوند.
                        enqueueLazyImage($ph);
                    }
                });
            }
        } else {
            // متن داخل یک بدنه‌ی جدا قرار می‌گیرد تا آواتار CSS کنار کل پیام بنشیند
            messages.append('<div class="' + cls + ' fade-in-up"><div class="ai-message-body">' + text + '</div></div>');
        }
    }

    /*
    ============================================
    Escape برای attribute (مثل data-image-key="...")
    برای جلوگیری از XSS در زمانی که کلید عکس داخل HTML قرار می‌گیرد.
    ============================================
    */
    function escapeAttr(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    /*
    ============================================
    بارگذاری lazy عکس‌های تاریخچه با استفاده از IntersectionObserver

    برای هر placeholder (المان .user-gallery-item.is-loading با data-image-key)
    یک observer ساخته می‌شود. به محض اینکه placeholder وارد viewport شود،
    عکس مربوطه از اندپوینت ai_agent_get_media دریافت شده و placeholder
    با یک <img> واقعی جایگزین می‌شود.

    درخواست‌ها به‌صورت یکی‌یکی (sequential) و نه موازی ارسال می‌شوند تا
    بار اضافی روی سرور ایجاد نشود. یک صف ساده با استفاده از Set پیاده‌سازی
    شده: در هر لحظه حداکثر یک درخواست در حال انجام است و بقیه در صف می‌مانند
    تا نوبتشان برسد.
    ============================================
    */
    let lazyImageQueue = [];
    let lazyImageInFlight = false;
    let lazyImageObserver = null;

    function ensureLazyObserver() {
        if (lazyImageObserver) return lazyImageObserver;
        if (!('IntersectionObserver' in window)) return null;

        lazyImageObserver = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.isIntersecting) {
                    const $ph = $(entry.target);
                    // یک‌بار observer این المان را قطع کن تا دوباره در صف نرود
                    lazyImageObserver.unobserve(entry.target);
                    enqueueLazyImage($ph);
                }
            });
        }, {
            root: messages[0],
            rootMargin: '100px',
            threshold: 0.05
        });

        return lazyImageObserver;
    }

    function enqueueLazyImage($ph) {
        if (!$ph || !$ph.length) return;
        // اگر قبلاً در صف است یا در حال لود است، دوباره اضافه نکن
        if ($ph.data('lazy-queued') || $ph.data('lazy-loading')) return;
        if (!$ph.hasClass('is-loading')) return; // قبلاً لود شده
        $ph.data('lazy-queued', true);
        lazyImageQueue.push($ph);
        processLazyQueue();
    }

    function processLazyQueue() {
        if (lazyImageInFlight) return;
        const $ph = lazyImageQueue.shift();
        if (!$ph || !$ph.length) return;

        // اگر placeholder دیگر در DOM نیست (مثلاً چت پاک شده)، نادیده می‌گیریم
        if (!$.contains(document, $ph[0])) {
            processLazyQueue();
            return;
        }

        const key = $ph.attr('data-image-key');
        if (!key) {
            processLazyQueue();
            return;
        }

        $ph.data('lazy-queued', false);
        $ph.data('lazy-loading', true);
        lazyImageInFlight = true;

        fetchMediaByKey(key, function (ok, dataUrl) {
            lazyImageInFlight = false;
            $ph.data('lazy-loading', false);

            if (ok && dataUrl) {
                // جایگزینی placeholder با <img> واقعی
                $ph.removeClass('is-loading').addClass('is-loaded');
                $ph.find('.user-gallery-placeholder').remove();
                const $img = $('<img alt="عکس پیوست" />').attr('src', dataUrl);
                $ph.prepend($img);

                // مدیریت خطای لود عکس نهایی (مثلاً data URL خراب بود)
                $img.one('error', function () {
                    $ph.addClass('is-error');
                    $ph.attr('title', 'خطا در نمایش عکس');
                });
            } else {
                // خطا در دریافت عکس از سرور
                $ph.removeClass('is-loading').addClass('is-error');
                $ph.find('.user-gallery-placeholder').remove();
                $ph.attr('title', 'خطا در بارگذاری عکس');
                // یک آیکون کوچک خطا نمایش می‌دهیم
                $ph.prepend('<span class="user-gallery-error-icon" aria-hidden="true">⚠</span>');
            }

            // اگر صف خالی نشده، ادامه می‌دهیم
            if (lazyImageQueue.length > 0) {
                // یک تأخیر کوتاه برای جلوگیری از شلوغ شدن شبکه
                setTimeout(processLazyQueue, 50);
            }
        });
    }

    /*
    ============================================
    درخواست AJAX به اندپوینت ai_agent_get_media برای دریافت یک عکس

    پارامترها:
        key      : کلید عکس از image_keys
        callback : function(ok: boolean, dataUrl: string|null)
    ============================================
    */
    function fetchMediaByKey(key, callback) {
        $.ajax({
            url: ai_agent.ajax_url,
            method: 'POST',
            data: {
                action: 'ai_agent_get_media',
                key: key
            },
            dataType: 'json'
        }).done(function (res) {
            if (res && res.success && res.data && res.data.data_url) {
                callback(true, res.data.data_url);
            } else {
                callback(false, null);
            }
        }).fail(function () {
            callback(false, null);
        });
    }

    /*
    ============================================
    نمایش پیام سیستمی «انتقال به پشتیبان انسانی»

    این پیام با ai-message یا admin-message فرق دارد چون از طرف
    مدل هوش مصنوعی یا کارشناس نوشته نشده؛ فقط اعلامی سیستمی است که
    می‌گوید گفتگو به پشتیبان انسانی وصل می‌شود و چرا.
    ============================================
    */
    function addEscalateMessage(reason) {
        const reasonHtml = reason
            ? '<div class="ai-escalate-reason">' + escapeHtml(reason) + '</div>'
            : '';
        messages.append(
            '<div class="ai-escalate-message fade-in-up">' +
                '<span class="ai-escalate-icon">🎧</span>' +
                '<div class="ai-escalate-text">' +
                    '<div class="ai-escalate-title">در حال انتقال گفتگو به پشتیبان انسانی...</div>' +
                    reasonHtml +
                '</div>' +
            '</div>'
        );
    }

    /*
    ============================================
    پیام سیستمی «این گفتگو بسته شده است»

    این پیام زمانی نمایش داده می‌شود که وضعیت جلسه از سمت سرور
    closed برگردانده شود (پشتیبان چت را پایان داده است). کاربر باید
    یک گفتگوی جدید آغاز کند.
    ============================================
    */
    function addClosedMessage() {
        // اگر همین پیام از قبل آخرین پیام است، دوباره اضافه نکن
        if (messages.find('.ai-closed-message').last().length) {
            return;
        }
        messages.append(
            '<div class="ai-closed-message fade-in-up">' +
                '<span class="ai-closed-icon">🔒</span>' +
                '<div class="ai-closed-text">این گفتگو بسته شده است. لطفاً یک گفتگوی جدید ایجاد کنید.</div>' +
            '</div>'
        );
    }

    /*
    ============================================
    پیام سیستمی «در حالت پشتیبانی»

    این پیام پس از ارسال هر پیام کاربر در حالت pending_human یا human
    نمایش داده می‌شود تا به کاربر اطمینان داده شود که پیامش به پشتیبان
    رسیده و در انتظار پاسخ انسانی است (نه ربات).
    ============================================
    */
    function addSupportModeIndicator() {
        messages.append(
            '<div class="ai-support-mode-message fade-in-up">' +
                '<span class="ai-support-mode-icon">🎧</span>' +
                '<div class="ai-support-mode-text">پیام شما برای پشتیبان ارسال شد. لطفاً منتظر پاسخ باشید.</div>' +
            '</div>'
        );
    }

    /*
    ============================================
    فعال/غیرفعال کردن ناحیه ورودی پیام (فوتر)

    هنگامی که گفتگو بسته می‌شود، فیلد متن، دکمه ارسال و دکمه سنجاق
    غیرفعال می‌شوند تا کاربر نتواند پیام جدیدی ارسال کند. این تابع
    هم برای حالت closed (پشتیبان چت را بسته) و هم برای فعال‌سازی مجدد
    پس از شروع چت جدید استفاده می‌شود.
    ============================================
    */
    function setChatDisabled(disabled) {
        if (disabled) {
            input.prop('disabled', true).attr('placeholder', 'این گفتگو بسته شده است...');
            send.prop('disabled', true).addClass('is-empty');
            attachBtn.prop('disabled', true).addClass('is-disabled');
            $('#ai-agent-footer').addClass('is-disabled');
        } else {
            input.prop('disabled', false).attr('placeholder', 'پیام خود را بنویسید...');
            attachBtn.prop('disabled', false).removeClass('is-disabled');
            $('#ai-agent-footer').removeClass('is-disabled');
            // دکمه‌ی ارسال فقط وقتی فعال می‌شود که متنی نوشته شده باشد؛
            // بعد از برداشته‌شدن قفل فوتر، وضعیت بر اساس متن ورودی تعیین می‌شود
            updateSendButtonState();
        }
    }

    /*
    ============================================
    متغیر نگه‌دارنده‌ی وضعیت فعلی جلسه

    این مقدار توسط loadChatHistory (هنگام رفرش صفحه) و checkSessionStatus
    (قبل از هر ارسال پیام) به‌روز می‌شود. مقادیر ممکن:

        ''               →  نامشخص (پیش‌فرض؛ رفتار ربات)
        'bot'            →  حالت ربات (رفتار معمول)
        'assistant'      →  حالت ربات (مترادف با bot)
        'pending_human'  →  در انتظار پشتیبان (حالت پشتیبانی)
        'human'          →  پشتیبان در حال پاسخ‌دهی (حالت پشتیبانی)
        'closed'         →  گفتگو بسته شده است
    ============================================
    */
    let currentSessionStatus = '';

    function isSupportMode(status) {
        return status === 'pending_human' || status === 'human';
    }
    function isBotMode(status) {
        // هر چیزی غیر از pending_human / human / closed به‌عنوان حالت ربات
        // تلقی می‌شود (شامل bot، assistant و حالت نامشخص).
        return !isSupportMode(status) && status !== 'closed';
    }

    /*
    ============================================
    Polling برای بررسی پیام‌های جدید از پشتیبان انسانی

    در حالت پشتیبانی (pending_human یا human)، هر ۱ دقیقه یک درخواست
    به سرور ارسال می‌شود تا بررسی شود آیا پیام جدیدی از طرف پشتیبان
    رسیده است یا خیر. این درخواست فقط زمانی ارسال می‌شود که:
        - session_id معتبر وجود داشته باشد
        - وضعیت جلسه در حالت پشتیبانی باشد (نه ربات و نه بسته‌شده)
        - کاربر در سایت آنلاین باشد (صفحه visible باشد و اینترنت داشته باشد)

    مکانیزم مقایسه:
        - تعداد پیام‌های دیده‌شده در کوکی ai_agent_msg_count ذخیره می‌شود
        - با message_count از پاسخ API مقایسه می‌شود
        - اگر message_count > cookie: پیام‌های جدید نمایش داده می‌شوند
        - در غیر این‌صورت، هیچ کاری انجام نمی‌شود
    ============================================
    */
    let pollingTimer = null;
    const POLLING_INTERVAL_MS = 60000; // ۱ دقیقه

    function shouldPoll() {
        if (!sessionId) return false;
        if (!isSupportMode(currentSessionStatus)) return false;
        if (currentSessionStatus === 'closed') return false;
        // فقط زمانی که کاربر در سایت آنلاین است (صفحه visible باشد)
        if (document.visibilityState && document.visibilityState !== 'visible') return false;
        // بررسی اتصال اینترنت
        if (typeof navigator !== 'undefined' && navigator.onLine === false) return false;
        return true;
    }

    function startPolling() {
        // اگر قبلاً timer فعال است، ابتدا آن را متوقف می‌کنیم تا duplicate نباشد
        stopPolling();
        pollingTimer = setInterval(pollOnce, POLLING_INTERVAL_MS);
    }

    function stopPolling() {
        if (pollingTimer) {
            clearInterval(pollingTimer);
            pollingTimer = null;
        }
    }

    function pollOnce() {
        if (!shouldPoll()) return;

        $.ajax({
            url: ai_agent.ajax_url,
            method: 'POST',
            data: {
                action: 'ai_agent_get_history',
                session_id: sessionId
            },
            dataType: 'json'
        }).done(function (res) {
            if (!res || !res.success || !res.data) return;

            const data = res.data;
            const sessionStatus = data.status || '';
            const msgs = Array.isArray(data.messages) ? data.messages : [];

            // به‌روزرسانی وضعیت جلسه از سرور
            currentSessionStatus = sessionStatus;

            // مقایسه تعداد پیام‌ها با کوکی و نمایش پیام‌های جدید
            const knownCount = getMsgCount();
            if (msgs.length > knownCount) {
                // پیام‌های جدید وجود دارند — فقط پیام‌های جدید را نمایش می‌دهیم
                const newMsgs = msgs.slice(knownCount);
                newMsgs.forEach(renderHistoryMessage);
                // به‌روزرسانی کوکی به تعداد کل پیام‌ها
                setMsgCount(msgs.length);
            }
            // اگر msgs.length <= knownCount، هیچ کاری نمی‌کنیم (پیام جدیدی نیست)

            // اقدامات مبتنی بر وضعیت جدید جلسه
            if (sessionStatus === 'closed') {
                // گفتگو بسته شده — فوتر را قفل کن و polling را متوقف کن
                setChatDisabled(true);
                addClosedMessage();
                stopPolling();
            } else if (!isSupportMode(sessionStatus)) {
                // وضعیت از حالت پشتیبانی خارج شده (مثلاً به bot برگشته)
                // دیگر نیازی به polling نیست
                stopPolling();
            }
            // اگر همچنان در حالت پشتیبانی است، polling ادامه می‌یابد
        }).fail(function () {
            // خطا را بی‌صدا نادیده می‌گیریم — polling در تلاش بعدی دوباره تلاش می‌کند
        });
    }

    /*
    ============================================
    بررسی زنده‌ی وضعیت جلسه از سرور قبل از ارسال هر پیام

    این تابع یک Promise برمی‌گرداند که با وضعیت جلسه (string) resolve
    می‌شود. در صورت خطا، با رشته‌ی خالی resolve می‌شود تا کلاینت بتواند
    با حالت پیش‌فرض (ربات) ادامه دهد.

    کاربرد: قبل از ارسال هر پیام در حالت pending_human / human باید وضعیت
    دوباره چک شود، چون پشتیبان ممکن است چت را به ربات بازگردانده یا آن
    را بسته باشد.
    ============================================
    */
    function checkSessionStatus() {
        return new Promise(function (resolve) {
            if (!sessionId) {
                resolve('');
                return;
            }
            $.ajax({
                url: ai_agent.ajax_url,
                method: 'POST',
                data: {
                    action: 'ai_agent_get_session_status',
                    session_id: sessionId
                },
                dataType: 'json'
            }).done(function (res) {
                if (res && res.success && res.data) {
                    resolve(res.data.session_status || '');
                } else {
                    resolve('');
                }
            }).fail(function () {
                resolve('');
            });
        });
    }

/*
============================================
گالری عکس‌های محصولات مرتبط — بالای متن پیام نمایش داده می‌شود
حداکثر ۲ عکس در دید کاربر و بقیه با اسکرول افقی (بدون اسکرول‌بار
قابل‌مشاهده) در دسترس است. زیر گالری، نقطه‌های گرد نشان‌دهنده‌ی
وجود آیتم‌های بیشتر و موقعیت فعلی اسکرول است.
============================================
*/
function buildReferencesGallery(references) {
    if (!Array.isArray(references) || references.length === 0) return null;

    // نوع‌بندی رفرنس‌ها به دو گروه برای نمایش در گالری:
    // ۱) رفرنس‌های متنی که عکس شاخص (post thumbnail) محصول دارند
    //    → عکس شاخص مستقیماً به‌عنوان src تگ <img> استفاده می‌شود
    //    و لینک به url محصول باز می‌شود.
    // ۲) رفرنس‌های تصویری (type=image) که url خودشان یک فایل عکس است
    //    (مثل site_xxx/docs/yyy.jpg) → عکس به‌صورت lazy از اندپوینت
    //    ai_agent_get_media بارگذاری می‌شود. این url دقیقاً همان
    //    فرمت کلید عکس را دارد و در data-image-key قرار می‌گیرد تا
    //    مکانیزم lazy-loading موجود (lazyImageObserver / processLazyQueue)
    //    آن را به‌صورت یکی‌یکی دریافت کرده و placeholder را با <img>
    //    واقعی جایگزین کند.
    const items = [];
    references.forEach(function (ref) {
        if (!ref || !ref.url) return;
        const refType = (ref.type || 'text').toString().toLowerCase();
        if (refType === 'image') {
            items.push({ ref: ref, isImageType: true });
        } else if (ref.image) {
            items.push({ ref: ref, isImageType: false });
        }
    });
    if (items.length === 0) return null;

    const $wrap = $('<div class="ai-references-gallery-wrap"></div>');
    const $gallery = $('<div class="ai-references-gallery"></div>');
    const $dots = $('<div class="ai-gallery-dots"></div>');
    const itemEls = [];
    const lazyTargets = []; // placeholderهای تصویری که باید به observer داده شوند

    items.forEach(function (item) {
        const ref = item.ref;
        let $item;

        if (item.isImageType) {
            // رفرنس تصویری: یک placeholder با data-image-key می‌سازیم که
            // ساختار آن با مکانیزم lazy-loading موجود سازگار است
            // (کلاس is-loading و یک فرزند .user-gallery-placeholder).
            // لینک href موقتاً روی «#» می‌ماند تا کلیک‌های تصادفی به
            // جایی نروند. بعد از بارگذاری، با img و openLightbox کار
            // می‌کند.
            $item = $('<a class="ai-reference-gallery-item is-loading" href="#" rel="noopener noreferrer"></a>')
                .attr('data-image-key', String(ref.url))
                .attr('title', ref.title ? String(ref.title) : '');
            $item.append('<span class="user-gallery-placeholder"></span>');

            // هندلر کلیک: اگر عکس لود شده، لایت‌باکس باز کن؛ در غیر
            // این صورت چیزی باز نکن (لینک href=# است که با preventDefault
            // بلاک می‌شود).
            $item.on('click', function (e) {
                e.preventDefault();
                const src = $(this).find('img').attr('src');
                if (src) openLightbox(src);
            });

            lazyTargets.push($item);
        } else {
            // رفرنس متنی با عکس شاخص محصول: رفتار قدیمی حفظ می‌شود
            $item = $('<a class="ai-reference-gallery-item" target="_blank" rel="noopener noreferrer"></a>')
                .attr('href', ref.url)
                .attr('title', ref.title ? String(ref.title) : '');

            const $img = $('<img loading="lazy" alt="" />').attr('src', ref.image);

            // مدیریت خطای لود عکس: کل آیتم گالری حذف می‌شود
            $img.one('error', function () {
                $item.remove();
                const idx = itemEls.indexOf($item[0]);
                if (idx > -1) itemEls.splice(idx, 1);
                $dots.toggle($gallery.children().length > 2);
            });

            $item.append($img);
        }

        $gallery.append($item);
        itemEls.push($item[0]);
    });

    if ($gallery.children().length === 0) return null;

    // نقطه‌ها فقط وقتی بیشتر از ۲ عکس باشد نمایش داده می‌شوند
    itemEls.forEach(function () {
        $('<span class="ai-gallery-dot"></span>').appendTo($dots);
    });
    $dots.toggle(itemEls.length > 2);
    $dots.children().first().addClass('active');

    // ثبت placeholderهای تصویری در lazy-loading observer
    // (همان observer مشترک که برای عکس‌های پیام کاربر هم استفاده می‌شود).
    if (lazyTargets.length > 0) {
        const observer = ensureLazyObserver();
        lazyTargets.forEach(function ($ph) {
            if (observer) {
                observer.observe($ph[0]);
            } else {
                // مرورگر قدیمی بدون IntersectionObserver → بارگذاری مستقیم
                enqueueLazyImage($ph);
            }
        });
    }

    // اسکرول افقی با چرخ ماوس هنگام هاور (بدون نیاز به Shift)
    $gallery.on('wheel', function (e) {
        const dy = e.originalEvent.deltaY;
        if (dy === 0) return;
        e.preventDefault();
        this.scrollLeft += dy;
    });

    // ----- قابلیت کشیدن (Drag to Scroll) با موس -----
    (function enableDragScroll(galleryEl) {
        let isDown = false;
        let startX = 0;
        let scrollLeftStart = 0;
        let moved = false;

        galleryEl.addEventListener('mousedown', function (e) {
            isDown = true;
            moved = false;
            galleryEl.classList.add('ai-gallery-dragging');
            startX = e.pageX - galleryEl.getBoundingClientRect().left;
            scrollLeftStart = galleryEl.scrollLeft;
        });

        function endDrag() {
            if (!isDown) return;
            isDown = false;
            galleryEl.classList.remove('ai-gallery-dragging');
        }

        document.addEventListener('mouseup', endDrag);
        galleryEl.addEventListener('mouseleave', endDrag);

        galleryEl.addEventListener('mousemove', function (e) {
            if (!isDown) return;
            e.preventDefault();
            const x = e.pageX - galleryEl.getBoundingClientRect().left;
            const walk = x - startX;
            if (Math.abs(walk) > 5) moved = true; // آستانه‌ی تشخیص «کشیدن واقعی» از «کلیک ساده»
            galleryEl.scrollLeft = scrollLeftStart - walk;
        });

        // جلوگیری از باز شدن لینک محصول وقتی کاربر واقعاً در حال کشیدن گالری بوده (نه کلیک ساده)
        galleryEl.addEventListener('click', function (e) {
            if (moved) {
                e.preventDefault();
                e.stopPropagation();
            }
        }, true);
    })($gallery[0]);

    // هماهنگ‌سازی نقطه‌ی فعال با آیتم قابل‌مشاهده در گالری
    if (itemEls.length > 2 && 'IntersectionObserver' in window) {
        const dotEls = $dots.children().toArray();
        const io = new IntersectionObserver(function (entries) {
            entries.forEach(function (entry) {
                if (entry.intersectionRatio <= 0.6) return;
                const idx = itemEls.indexOf(entry.target);
                if (idx === -1 || !dotEls[idx]) return;
                dotEls.forEach(d => d.classList.remove('active'));
                dotEls[idx].classList.add('active');
            });
        }, { root: $gallery[0], threshold: [0.6] });
        itemEls.forEach(el => io.observe(el));
    }

    $wrap.append($gallery).append($dots);
    return $wrap;
}

/*
============================================
لیست متنی هایپرلینک‌های رفرنس‌ها — همان‌جای قبلی (انتهای پیام)
============================================
*/
function buildReferencesListBox(references) {
    if (!Array.isArray(references) || references.length === 0) return null;

    const $list = $('<div class="ai-references-list"></div>');
    references.forEach(function (ref) {
        if (!ref || !ref.url) return;
        // رفرنس‌های تصویری (type=image) در فهرست متنی «موارد مرتبط»
        // نمایش داده نمی‌شوند؛ چون url آن‌ها به یک فایل عکس اشاره
        // می‌کند (مثل site_xxx/docs/yyy.jpg) و به‌جای لینک متنی،
        // خودِ عکس در گالری بالای پیام نمایش داده می‌شود.
        const refType = (ref.type || 'text').toString().toLowerCase();
        if (refType === 'image') return;

        const label = ref.title ? String(ref.title) : String(ref.url);
        const $link = $('<a class="ai-reference-link" target="_blank" rel="noopener noreferrer"></a>')
            .attr('href', ref.url)
            .text(label);
        $list.append($link);
    });

    if ($list.children().length === 0) return null;

    // به‌صورت پیش‌فرض بسته (collapsed) است؛ با کلیک روی عنوان «موارد مرتبط» باز/بسته می‌شود
    $list.hide();

    const $box = $('<div class="ai-references-box"></div>');
    const $title = $('<button type="button" class="ai-references-title ai-references-toggle"></button>');
    $title.append($('<span class="ai-references-title-text"></span>').text('موارد مرتبط:'));
    $title.append($('<span class="ai-references-arrow">&#9662;</span>'));

    $title.on('click', function () {
        $list.slideToggle(180);
        $title.toggleClass('is-open');
    });

    $box.append($title).append($list);
    return $box;
}
    /*
    ============================================
    ساخت یک پیام AI خالی برای استریم کردن محتوا داخل آن
    برمی‌گرداند: { $wrapper, $content, $loading }
    ============================================
    */
    /*
    نشانگر انتظار: یک برچسبِ متنی با نبض ملایم، نه سه نقطه‌ی جهنده.

    نقطه‌ها می‌گفتند «چیزی دارد تایپ می‌شود»، در حالی که مدل ممکن است
    چند ثانیه مشغول جست‌وجو در محتوای سایت باشد و هنوز یک کلمه هم
    ننوشته باشد. متن، همان چیزی را می‌گوید که واقعاً دارد اتفاق می‌افتد،
    و رویداد tool_call در حین کار جمله را دقیق‌تر می‌کند.
    */
    function buildThinkingIndicator() {
        return $(
            '<div class="ai-agent-thinking" id="ai-loading-stream">' +
            '<span class="ai-agent-thinking-dot" aria-hidden="true"></span>' +
            '<span class="ai-agent-thinking-label">دارم فکر می‌کنم...</span>' +
            '</div>'
        );
    }

    function addStreamingMessage() {
        const $wrapper = $('<div class="ai-message fade-in-up"></div>');
        const $body = $('<div class="ai-message-body"></div>');
        const $content = $('<span class="ai-streaming-content"></span>');
        const $loading = buildThinkingIndicator();

        $body.append($content);
        $body.append($loading);
        $wrapper.append($body);
        messages.append($wrapper);

        return { $wrapper, $body, $content, $loading, references: [], rawText: '', suggestions: [] };
    }

    function removeLoading() {
        $("#ai-loading, #ai-loading-stream").remove();
    }

    /*
    ============================================
    Escape HTML برای جلوگیری از injection هنگام استریم
    ============================================
    */
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    /*
    ============================================
    تبدیل ساده و امن مارک‌داون به HTML برای متن پیام‌های مدل

    فقط دو حالت پشتیبانی می‌شود (چون فقط همین دو مورد از سمت مدل
    استفاده می‌شود):
        **متن پررنگ**              →  <strong>متن پررنگ</strong>
        [عنوان لینک](https://...)  →  <a href="...">عنوان لینک</a>

    برای جلوگیری از XSS، ابتدا کل متن با escapeHtml امن می‌شود و
    سپس الگوهای بالا روی متنِ امن‌شده اعمال می‌گردند (بنابراین
    خروجی نهایی هیچ‌وقت شامل تگ HTML خام از سمت مدل نخواهد بود).
    ============================================
    */
    function renderInlineMarkdown(rawText) {
        if (!rawText) return '';

        let html = escapeHtml(rawText);

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
    استخراج لینک‌های مارک‌داون [عنوان](URL) که مدل ممکن است
    مستقیماً داخل متن پیام نوشته باشد (به‌جای رویداد references)
    و جدا کردن آن‌ها از متن اصلی پیام
    ============================================
    */
    function extractMarkdownReferences(rawText) {
        const linkRegex = /\[([^\[\]]+)\]\((https?:\/\/[^\s()]+)\)/g;
        const refs = [];
        let match;
        let firstMatchIndex = -1;

        while ((match = linkRegex.exec(rawText)) !== null) {
            if (firstMatchIndex === -1) firstMatchIndex = match.index;
            refs.push({ title: match[1].trim(), url: match[2].trim() });
        }

        if (refs.length === 0) {
            return { cleanedText: rawText, references: [] };
        }

        // متنی که قبل از اولین لینک آمده، متن واقعی پاسخ است
        let cleanedText = rawText.slice(0, firstMatchIndex);

        // حذف عنوان‌های رایجی مثل «موارد مرتبط:» که معمولاً قبل از لینک‌ها می‌آید
        cleanedText = cleanedText.replace(/(موارد\s*مرتبط|منابع|رفرنس‌ها)\s*:?\s*$/i, '').trimEnd();

        // حذف رفرنس‌های تکراری (URL یکسان)
        const seen = new Set();
        const uniqueRefs = refs.filter(r => {
            if (seen.has(r.url)) return false;
            seen.add(r.url);
            return true;
        });

        return { cleanedText, references: uniqueRefs };
    }
    /*
    ============================================
    ارسال پیام به سمت سرور و استریم پاسخ

    به‌جای $.ajax از fetch + ReadableStream استفاده می‌کنیم تا
    بتوانیم رویدادهای SSE را تکه به تکه بخوانیم و به‌صورت زنده
    در پنجره‌ی چت نمایش دهیم.

    نکته‌ی مهم — بررسی وضعیت جلسه قبل از ارسال:
    قبل از ارسال هر پیام، وضعیت جلسه از سرور بررسی می‌شود تا اگر
    پشتیبان چت را به ربات بازگردانده یا بسته است، رفتار متناسب
    انجام دهیم:

        - closed         →  نمایش پیام «این گفتگو بسته شده است»
                            و عدم ارسال پیام
        - pending_human / human  →  ارسال پیام (تا پشتیبان ببیند) ولی
                                     بدون انیمیشن انتظار ربات و بدون
                                     نمایش خطای نبود پاسخ ربات
        - bot / assistant / ''   →  رفتار معمول چت با ربات (استریم)
    ============================================
    */
    async function sendMessage() {
        let text = input.val().trim();
        // کپی از عکس‌های انتخاب‌شده قبل از پاک شدن
        let imagesToSend = pendingImages.map(function (img) { return img.dataUrl; });

        /*
        ارسال فقط با وجود متن انجام می‌شود. عکسِ به‌تنهایی «خالی» محسوب
        می‌شود و برای ارسال پیام حتماً باید متنی توسط کاربر نوشته شده
        باشد (مطابق سیاست غیرفعال‌سازی دکمه‌ی ارسال).
        */
        if (!text) return;

        // -------------------------------------------------------------
        // ۱) بررسی زنده‌ی وضعیت جلسه قبل از ارسال
        //    (فقط اگر session_id داریم — برای جلسه‌ی جدید این چک اجرا
        //     نمی‌شود و مستقیم به حالت ربات می‌رویم.)
        // -------------------------------------------------------------
        let status = currentSessionStatus;
        if (sessionId) {
            try {
                status = await checkSessionStatus();
                currentSessionStatus = status;
            } catch (e) {
                // در صورت خطا، از آخرین وضعیت شناخته‌شده استفاده می‌کنیم
            }
        }

        // حالت «بسته‌شده»: پیام کاربر را نمایش می‌دهیم ولی ارسال نمی‌کنیم
        // و به‌جای آن پیام سیستمی «این گفتگو بسته شده است» را نشان می‌دهیم.
        if (status === 'closed') {
            addMessage("user", escapeHtml(text), null, imagesToSend);
            input.val("");
            autoResizeInput();
            updateSendButtonState(); // ورودی خالی شد → دکمه‌ی ارسال غیرفعال می‌شود
            clearAttachments();
            addClosedMessage();
            // قفل کردن فوتر برای جلوگیری از ارسال پیام جدید
            setChatDisabled(true);
            return;
        }

        const supportMode = isSupportMode(status);

        // -------------------------------------------------------------
        // ۲) نمایش پیام کاربر
        // -------------------------------------------------------------
        // صفحه‌ی شروع و پیشنهادهای پاسخ قبلی جای خود را به گفت‌وگو می‌دهند.
        // پیشنهادی که به پاسخِ قبلی مربوط بود، زیر یک گفت‌وگوی جلورفته
        // بی‌ربط می‌شود.
        clearIntro();
        clearSuggestions();

        addMessage("user", escapeHtml(text), null, imagesToSend);
        input.val("");
        autoResizeInput(); // برگشت به ارتفاع پیش‌فرض بعد از ارسال
        updateSendButtonState(); // ورودی خالی شد → دکمه‌ی ارسال غیرفعال می‌شود

        // پاک کردن عکس‌های انتخاب‌شده (نمایش آن‌ها در حباب کاربر کافی است)
        clearAttachments();

        // به‌روزرسانی کوکی تعداد پیام‌های دیده‌شده (پیام کاربر به مکالمه اضافه شد)
        incrementMsgCount(1);

        // -------------------------------------------------------------
        // ۳) در حالت پشتیبانی:
        //    - انیمیشن انتظار ربات (typing dots) نمایش داده نمی‌شود
        //    - پیام به API ارسال می‌شود تا پشتیبان ببیند
        //    - خطای نبود پاسخ ربات نمایش داده نمی‌شود
        //    - یک اندیکاتور «در حالت پشتیبانی» نشان داده می‌شود
        // -------------------------------------------------------------
        if (supportMode) {
            // اندیکاتور حالت پشتیبانی
            addSupportModeIndicator();

            // شروع polling برای بررسی پیام‌های جدید از پشتیبان
            startPolling();

            // ارسال پیام به API (بدون استریم و بدون نمایش خطا)
            // این کار به‌صورت silent انجام می‌شود تا تجربه‌ی کاربر خراب نشود.
            const body = new URLSearchParams();
            body.append('action', 'ai_agent_chat');
            body.append('message', text);
            body.append('session_id', sessionId || '');
            // فقط هنگام ساخت گفت‌وگوی تازه به کار می‌آید، ولی همیشه فرستاده
            // می‌شود: کلاینت نمی‌داند سرور جلسه‌ی فعلی را هنوز دارد یا نه.
            body.append('visitor_id', getVisitorId());

            if (imagesToSend.length > 0) {
                imagesToSend.forEach(function (dataUrl, i) {
                    body.append('images[' + i + ']', dataUrl);
                });
            }

            try {
                await fetch(ai_agent.ajax_url, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=utf-8' },
                    body: body.toString(),
                    credentials: 'same-origin'
                });
                // پاسخ SSE را نادیده می‌گیریم — پشتیبان از طریق پنل جداگانه
                // پاسخ خواهد داد و کاربر با رفرش صفحه آن را خواهد دید.
            } catch (e) {
                // خطا را به‌صورت silent نادیده می‌گیریم (مطابق درخواست کاربر)
            }
            return;
        }

        // -------------------------------------------------------------
        // ۴) حالت ربات: رفتار معمول با استریم SSE
        // -------------------------------------------------------------
        // ساخت یک پیام AI خالی که محتوای استریم‌شده داخل آن قرار می‌گیرد
        const stream = addStreamingMessage();


        // ساخت بدنه‌ی درخواست به فرمت x-www-form-urlencoded
        const body = new URLSearchParams();
        body.append('action', 'ai_agent_chat');
        body.append('message', text);
        body.append('session_id', sessionId || '');
        // فقط هنگام ساخت گفت‌وگوی تازه به کار می‌آید، ولی همیشه فرستاده
        // می‌شود: کلاینت نمی‌داند سرور جلسه‌ی فعلی را هنوز دارد یا نه.
        // بدون این، گفت‌وگو به visitor_id مرورگر وصل نمی‌شود و در فهرست
        // «گفت‌وگوهای پیشین» ظاهر نمی‌شود (و چون هیچ‌وقت پیدا نمی‌شود،
        // انگار عنوانی هم برایش ساخته نشده).
        body.append('visitor_id', getVisitorId());

        // افزودن عکس‌ها به‌صورت آرایه (images[])؛ هر آیتم یک data URL (base64) است
        // که در سمت سرور (ajax.php) به آرایه‌ی images در بدنه‌ی JSON به اندپوینت
        // /api/v1/chat/messages منتقل می‌شود.
        if (imagesToSend.length > 0) {
            imagesToSend.forEach(function (dataUrl, i) {
                body.append('images[' + i + ']', dataUrl);
            });
        }


        // AbortController برای اعمال timeout سمت کلاینت
        const controller = new AbortController();
        const clientTimeoutMs = (window.ai_agent && ai_agent.timeout) ? ai_agent.timeout : 15000;
        const timeoutId = setTimeout(() => controller.abort(), clientTimeoutMs);

        try {
            const response = await fetch(ai_agent.ajax_url, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=utf-8' },
                body: body.toString(),
                signal: controller.signal,
                credentials: 'same-origin'
            });

            if (!response.ok) {
                throw new Error('Network response was not ok');
            }

            // خواندن استریم به‌صورت چانک به چانک
            const reader = response.body.getReader();
            const decoder = new TextDecoder('utf-8');
            let buffer = '';
            let chatId = null;
            let firstByteReceived = false;

            while (true) {
                const { done, value } = await reader.read();
                if (done) break;

                firstByteReceived = true;
                clearTimeout(timeoutId); // اولین پاسخ رسید؛ timeout کلاینت لغو می‌شود

                buffer += decoder.decode(value, { stream: true });

                // رویدادهای SSE با "\n\n" از هم جدا می‌شوند
                const events = buffer.split('\n\n');
                buffer = events.pop(); // آخرین قطعه‌ی ناقص در بافر می‌ماند

                for (const evt of events) {
                    processSSEEvent(evt, stream, function (id) {
                        chatId = id;
                    });
                }
            }

            // پردازش بافر باقیمانده
            if (buffer.trim()) {
                processSSEEvent(buffer, stream, function (id) {
                    chatId = id;
                });
            }

            // اگر هیچ محتوایی دریافت نشده بود، پیام خطا نمایش می‌دهیم
            if (!firstByteReceived) {
                stream.$loading.remove();
                stream.$content.html('ارتباط با سرور برقرار نشد.');
            }

        } catch (err) {
            clearTimeout(timeoutId);
            stream.$loading.remove();
            if (err && err.name === 'AbortError') {
                stream.$content.html('ارتباط با سرور برقرار نشد.');
            } else {
                stream.$content.html('ارتباط با سرور برقرار نشد.');
            }
        }
    }

    /*
    ============================================
    پردازش یک رویداد SSE دریافتی از سرور

    هر رویداد ممکن است چند خط داشته باشد. خطوطی که با "data:" شروع
    می‌شوند، payload رویداد هستند. این payload یک JSON است که شامل
    کلید type است و می‌تواند یکی از مقادیر chunk / done / error باشد.

    evt        : متن خام یک رویداد SSE
    stream     : شیء شامل $wrapper، $content و $loading
    onDone     : callback برای گرفتن chat_id در رویداد done
    ============================================
    */
    function processSSEEvent(evt, stream, onDone) {
        const lines = evt.split('\n');
        let payload = '';

        for (const line of lines) {
            const trimmed = line.trim();
            if (trimmed.indexOf('data:') === 0) {
                payload += trimmed.slice(5).trim();
            }
            // سایر خطوط (event:, id:, retry:, comment) را نادیده می‌گیریم
        }

        if (!payload) return;

        let data;
        try {
            data = JSON.parse(payload);
        } catch (e) {
            // payload معتبر نیست؛ نادیده گرفته می‌شود
            return;
        }

        if (data.type === 'chunk' && typeof data.content !== 'undefined') {
        stream.$loading.remove();
        stream.rawText += data.content; // ذخیره‌ی متن خام برای رندر لحظه‌ای و پردازش نهایی

        // به‌جای append کردن متن خامِ escape شده، در هر chunk کل متنِ
        // جمع‌شده تا این لحظه را با renderInlineMarkdown دوباره رندر
        // می‌کنیم. این کار باعث می‌شود مارک‌داون (بولد **متن** و لینک
        // [عنوان](URL)) هم‌زمان با تایپ شدن توسط مدل (runtime) به HTML
        // تبدیل شود، نه فقط در پایان استریم (رویداد done).
        // چون هر بار کل rawText از نو escape و پردازش می‌شود، الگوهای
        // ناقص (مثلاً «**» بدون بسته شدن) تا تکمیل نشدن، به‌صورت خام
        // نمایش داده می‌شوند و از فلش زدن تگ نصفه‌ونیمه جلوگیری می‌شود.
        stream.$content.html(renderInlineMarkdown(stream.rawText));
        } else if (data.type === 'references' && Array.isArray(data.references)) {
    stream.references = data.references;

    // گالری عکس‌ها بالای متن (قبل از $content)
    const $gallery = buildReferencesGallery(stream.references);
    if ($gallery) {
        $gallery.addClass('fade-in-up');
        stream.$content.before($gallery);
    }

    // باکس لینک‌های متنی ته پیام
    const $refList = buildReferencesListBox(stream.references);
    if ($refList) {
        $refList.addClass('fade-in-up');
        stream.$body.append($refList);
        stream.referencesRendered = true;
    }
} else if (data.type === 'tool_call') {
            // تا وقتی هیچ متنی نیامده، برچسب انتظار دقیق‌تر می‌شود: کاربر
            // می‌بیند که دستیار در حال گشتن در محتوای سایت است، نه این‌که
            // بی‌دلیل معطل مانده.
            if (!stream.rawText) {
                stream.$loading.find('.ai-agent-thinking-label')
                    .text(data.name === 'escalate' ? 'در حال ارجاع به پشتیبان...' : 'دارم توی سایت می‌گردم...');
            }
        } else if (data.type === 'suggestions') {
            // پیشنهادهای ادامه‌ی گفت‌وگو. عمداً بعد از رسیدنِ کاملِ پاسخ
            // نمایش داده می‌شوند، نه هم‌زمان با آن.
            stream.suggestions = Array.isArray(data.suggestions) ? data.suggestions : [];
        } else if (data.type === 'session_init' && data.session_id) {
            setSessionId(data.session_id);
        } else if (data.type === 'escalate') {
            stream.$loading.remove();
            stream.$wrapper.remove();
            addEscalateMessage(data.reason);
            // به‌روزرسانی وضعیت جلسه به حالت پشتیبانی و شروع polling
            currentSessionStatus = 'pending_human';
            startPolling();
        } else if (data.type === 'done') {
            stream.$loading.remove();

            // اگر هیچ محتوایی دریافت نشد (مثلاً بعد از انتقال به پشتیبان، تا وقتی پاسخ ندهد)
            // حباب خالی را حذف می‌کنیم تا فضای خالی عجیب نمایش داده نشود
            if (stream.rawText.trim() === '' && (!stream.references || stream.references.length === 0)) {
                stream.$wrapper.remove();
                return;
            }

            // پس از پایان استریم، متن نهایی را با پشتیبانی از مارک‌داون
            // (بولد و لینک) دوباره رندر می‌کنیم؛ در حین استریم فقط متن
            // خام escape شده نمایش داده می‌شد تا حس تایپ زنده حفظ شود
            stream.$content.html(renderInlineMarkdown(stream.rawText));

            if (!stream.referencesRendered && stream.references && stream.references.length) {
    const $gallery = buildReferencesGallery(stream.references);
    if ($gallery) stream.$content.before($gallery);

    const $refList = buildReferencesListBox(stream.references);
    if ($refList) stream.$body.append($refList);
}

            // دکمه‌های تماس، فقط وقتی پاسخ واقعاً درباره‌ی راه ارتباطی
            // حرف زده باشد.
            const $actions = buildContactActions(stream.rawText);
            if ($actions) stream.$body.append($actions);

            renderSuggestions(stream.suggestions);

            if (data.chat_id) {
                if (typeof onDone === 'function') onDone(data.chat_id);
            }
        } else if (data.type === 'error') {
            stream.$loading.remove();
            const msg = data.message || 'خطایی در دریافت پاسخ رخ داد.';
            // اگر قبلاً محتوایی استریم شده بود، پیام خطا را در خط بعد می‌نویسیم
            if (stream.$content.text().trim() !== '') {
                stream.$content.append('<br><em style="color:#ef4444;">' + escapeHtml(msg) + '</em>');
            } else {
                stream.$content.html(escapeHtml(msg));
            }
        }
    }

    /*
    ============================================
    مدیریت وضعیت فعال/غیرفعال دکمه‌ی ارسال

    دکمه‌ی ارسال فقط زمانی فعال است که کاربر متنی (حتی یک کاراکتر)
    نوشته باشد. عکسِ به‌تنهایی «خالی» محسوب می‌شود و باعث فعال شدن
    دکمه نمی‌شود. اگر فوتر قفل باشد (گفتگو بسته شده)، دکمه در هر
    حالتی غیرفعال می‌ماند.

    این تابع باید پس از هر تغییری که در متن ورودی رخ می‌دهد فراخوانی
    شود (تایپ، ارسال، چت جدید، پایان ضبط صدا و ...).
    ============================================
    */
    /*
    در حال ضبط صدا؟ ماژول صوتی این را نگه می‌دارد تا دکمه‌ی ارسال بداند
    که «فیلد خالی است» در آن لحظه دلیل غیرفعال‌بودن نیست: کاربر دارد حرف
    می‌زند و متنش هنوز نوشته نشده.
    */
    let voiceIsRecording = false;

    function updateSendButtonState() {
        // اگر فوتر قفل است (چت بسته شده)، دکمه باید غیرفعال بماند
        if ($("#ai-agent-footer").hasClass("is-disabled")) {
            send.prop('disabled', true).addClass('is-empty');
            return;
        }
        // وجود متن شرط فعال بودن دکمه است (عکس به‌تنهایی کافی نیست) — مگر
        // وسط ضبط صدا، که زدنِ ارسال یعنی «تمامش کن و بفرست».
        const hasText = $.trim(input.val() || '').length > 0 || voiceIsRecording;
        send.prop('disabled', !hasText);
        send.toggleClass('is-empty', !hasText);
    }

    send.on("click", function () {
        // دکمه‌ی غیرفعال به‌هرحال کلیک نمی‌گیرد؛ این گارد صرفاً محافظ است
        if (send.prop('disabled')) return;
        if (requestSend()) return;
        sendMessage();
    });

    /*
    «ارسال» همیشه به‌معنای فرستادنِ فوریِ متنِ داخل فیلد نیست: اگر ضبط
    صدا در جریان باشد، ماژول صوتی این قصد را برمی‌دارد، ضبط را تمام
    می‌کند و بعد از آماده‌شدنِ متن خودش ارسال را انجام می‌دهد.

    برگشتی true یعنی «کسی این کار را به عهده گرفت، تو ادامه نده».
    */
    function requestSend() {
        const intent = { handled: false };
        $(document).trigger("ai-agent-send-intent", [intent]);
        return intent.handled;
    }

    // Enter هم همان قصد ارسال است و باید همان‌طور رفتار کند.
    input.on("keydown", function (e) {
        if (e.key === "Enter" && !e.shiftKey) {
            e.preventDefault();
            // اگر دکمه‌ی ارسال غیرفعال است (متن خالی)، ارسال انجام نمی‌شود
            if (send.prop('disabled')) return;
            if (requestSend()) return;
            sendMessage();
        }
    });

    /*
    ============================================
    رشد پویای ارتفاع تکست‌باکس هنگام تایپ

    ارتفاع به‌صورت صریح بین یک حداقل (تک‌خطی) و یک حداکثر
    (AI_AGENT_INPUT_MAX_HEIGHT) کلمپ می‌شود؛ این مقادیر مستقل
    از هر CSS خارجی اعمال می‌شوند تا تضمین شود ارتفاع هیچ‌وقت
    بیش از حد بزرگ نمی‌شود.
    ============================================
    */
    const AI_AGENT_INPUT_MIN_HEIGHT = 38; // تک‌خطی
    const AI_AGENT_INPUT_MAX_HEIGHT = 90; // سقف رشد ارتفاع

    function autoResizeInput() {
        const el = input[0];
        el.style.height = AI_AGENT_INPUT_MIN_HEIGHT + 'px'; // ابتدا ریست می‌شود تا scrollHeight واقعی محاسبه شود
        const contentHeight = el.scrollHeight;
        const newHeight = Math.min(Math.max(contentHeight, AI_AGENT_INPUT_MIN_HEIGHT), AI_AGENT_INPUT_MAX_HEIGHT);
        el.style.height = newHeight + 'px';
        el.style.overflowY = contentHeight > AI_AGENT_INPUT_MAX_HEIGHT ? 'auto' : 'hidden';
    }

    input.on("input", function () {
        autoResizeInput();
        updateSendButtonState(); // با هر تغییر متن، وضعیت دکمه‌ی ارسال به‌روز می‌شود
    });
    autoResizeInput(); // تنظیم ارتفاع اولیه
    updateSendButtonState(); // وضعیت اولیه: با ورودی خالی، دکمه‌ی ارسال غیرفعال است

    /*
    ============================================
    ورودی صوتی: ضبط در مرورگر، تبدیل به متن روی سرور

    نسخه‌ی قبلی از Web Speech API خودِ مرورگر استفاده می‌کرد. آن API
    فقط روی کروم دسکتاپ کار می‌کرد و روی موبایل عمداً خاموش بود —
    یعنی دقیقاً روی دستگاهی که بیشترین کاربرِ ویس را دارد، دکمه‌ی
    میکروفون وجود نداشت. حالا صدا با MediaRecorder ضبط و برای تبدیل
    به متن به سرور دانی‌چت فرستاده می‌شود (Whisper)، که هم روی همه‌ی
    مرورگرهای امروزی کار می‌کند و هم فارسی را به‌مراتب بهتر می‌فهمد.

    جریان کار:
      ۱. کاربر روی میکروفون می‌زند → مجوز میکروفون → ضبط شروع می‌شود.
      ۲. در حین ضبط، به‌جای فیلد متن، نوار ضبط دیده می‌شود: میله‌های
         فرکانسی که واقعاً از صدای کاربر می‌آیند (AnalyserNode)، نه
         یک انیمیشن تزئینی.
      ۳. کاربر یا روی «توقف» می‌زند، یا مستقیم روی «ارسال» — دومی
         ضبط را تمام می‌کند و پیام را بدون مکث می‌فرستد، چون کسی که
         حرفش تمام شده و دستش روی ارسال است، منظورش همین است.
      ۴. صدا به سرور می‌رود، متن برمی‌گردد و داخل فیلد می‌نشیند تا
         کاربر پیش از ارسال ببیندش و در صورت لزوم اصلاح کند.

    چرا متن اول نشان داده می‌شود و مستقیم ارسال نمی‌شود: تشخیص گفتار
    گاهی اشتباه می‌کند، و اصلاحِ یک کلمه خیلی بهتر از این است که ربات
    با اطمینان به سوالی جواب بدهد که کسی نپرسیده.
    ============================================
    */
    const voiceBtn = $("#ai-agent-voice");
    const recordingBar = $("#ai-agent-recording-bar");
    const recordingTimerEl = $("#ai-agent-recording-timer");
    const recordingLabelEl = $("#ai-agent-recording-bar .ai-recording-label");
    const waveformEl = $("#ai-agent-recording-bar .ai-recording-waveform");

    const RECORDING_LABEL_ACTIVE = "در حال ضبط…";
    const RECORDING_LABEL_PROCESSING = "در حال تبدیل به متن…";
    const RECORDING_LABEL_PREPARING = "در حال آماده‌سازی میکروفون…";

    // بیشترین طول ضبط. سرور هم سقف حجم دارد؛ این‌جا قطعش می‌کنیم تا
    // کاربر بعد از دو دقیقه حرف زدن با خطای «فایل بزرگ است» روبه‌رو نشود.
    const MAX_RECORDING_MS = 120000;

    const AI_AGENT_RECORDER_SUPPORTED =
        typeof window.MediaRecorder !== "undefined" &&
        !!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia);

    if (!AI_AGENT_RECORDER_SUPPORTED) {
        // مرورگر ضبط صدا ندارد (خیلی قدیمی، یا صفحه روی http بدون TLS
        // باز شده که getUserMedia در آن اصلاً وجود ندارد).
        voiceBtn.addClass("voice-not-supported");
    } else {
        let mediaRecorder = null;
        let mediaStream = null;
        let chunks = [];
        let isRecording = false;
        let isPreparing = false;
        let isProcessing = false;
        // وقتی کاربر به‌جای «توقف»، «ارسال» را زده باشد: بعد از آمدن
        // متن، پیام بلافاصله فرستاده می‌شود.
        let sendWhenReady = false;

        let recordingStartTime = 0;
        let recordingTickTimer = null;
        let maxLengthTimer = null;

        // Web Audio، فقط برای میله‌های فرکانسی
        let audioContext = null;
        let analyser = null;
        let analyserSource = null;
        let waveformFrame = null;
        let waveformBars = [];

        /* ---------- نمایش زمان ---------- */

        function formatDuration(ms) {
            const total = Math.floor(ms / 1000);
            const minutes = String(Math.floor(total / 60)).padStart(2, "0");
            const seconds = String(total % 60).padStart(2, "0");
            return minutes + ":" + seconds;
        }

        function tickTimer() {
            recordingTimerEl.text(formatDuration(Date.now() - recordingStartTime));
        }

        /* ---------- میله‌های فرکانسی ---------- */

        /*
        میله‌ها از روی خودِ صدا حرکت می‌کنند، نه با یک انیمیشن CSS.
        تفاوتش را کاربر بلافاصله می‌فهمد: وقتی حرف نمی‌زند میله‌ها
        می‌خوابند، و همین تنها نشانه‌ای است که به او می‌گوید میکروفون
        واقعاً صدایش را می‌شنود.
        */
        // حالا که برچسب متنی از نوار برداشته شده، موج تمام عرض را دارد؛
        // با تعداد کم، میله‌ها با فاصله‌های بزرگ پخش می‌شدند به‌جای اینکه
        // فضا را پر کنند.
        const WAVEFORM_BAR_COUNT = 44;

        function buildWaveformBars() {
            waveformEl.empty();
            waveformBars = [];
            for (let i = 0; i < WAVEFORM_BAR_COUNT; i++) {
                const bar = document.createElement("span");
                waveformEl[0].appendChild(bar);
                waveformBars.push(bar);
            }
        }

        function startWaveform(stream) {
            const AudioContextImpl = window.AudioContext || window.webkitAudioContext;
            if (!AudioContextImpl) {
                buildWaveformBars();
                recordingBar.addClass("no-analyser");
                return;
            }

            try {
                audioContext = new AudioContextImpl();
                analyserSource = audioContext.createMediaStreamSource(stream);
                analyser = audioContext.createAnalyser();
                // 64 خانه‌ی فرکانسی برای ۲۸ میله کافی است و روی موبایل‌های
                // ضعیف هم هر فریم به‌موقع تمام می‌شود.
                analyser.fftSize = 64;
                analyser.smoothingTimeConstant = 0.7;
                analyserSource.connect(analyser);
            } catch (err) {
                // Web Audio در دسترس نیست (سافاریِ قدیمی، یا سقف تعداد
                // AudioContext). ضبط سر جایش است؛ فقط میله‌ها به‌جای
                // دنبال‌کردن صدا، یک انیمیشن ساده می‌گیرند تا نوار مرده
                // به نظر نرسد.
                analyser = null;
                buildWaveformBars();
                recordingBar.addClass("no-analyser");
                return;
            }

            recordingBar.removeClass("no-analyser");
            buildWaveformBars();
            const data = new Uint8Array(analyser.frequencyBinCount);

            function draw() {
                if (!analyser) return;
                analyser.getByteFrequencyData(data);

                for (let i = 0; i < waveformBars.length; i++) {
                    // نگاشت میله‌ها روی خانه‌های فرکانسی؛ بم‌ها سمت
                    // چپ، زیرها سمت راست.
                    const value = data[Math.floor((i / waveformBars.length) * data.length)] || 0;
                    // کف ۱۵٪ تا وقتی سکوت است هم نوار «زنده» به نظر برسد
                    // و شبیه یک خط مرده نباشد.
                    const height = 15 + (value / 255) * 85;
                    waveformBars[i].style.height = height + "%";
                }

                waveformFrame = window.requestAnimationFrame(draw);
            }

            draw();
        }

        function stopWaveform() {
            if (waveformFrame) {
                window.cancelAnimationFrame(waveformFrame);
                waveformFrame = null;
            }
            if (analyserSource) {
                try { analyserSource.disconnect(); } catch (err) { /* قبلاً بسته شده */ }
                analyserSource = null;
            }
            analyser = null;
            if (audioContext) {
                // close() پرامیس برمی‌گرداند؛ نتیجه‌اش برای ما مهم نیست،
                // ولی رهاکردن AudioContext روی سافاری بعد از چند ضبط به
                // سقف تعداد کانتکست‌ها می‌خورد.
                try { audioContext.close(); } catch (err) { /* بی‌اهمیت */ }
                audioContext = null;
            }
        }

        /* ---------- وضعیت ظاهری ---------- */

        function showRecordingBar(label) {
            recordingLabelEl.text(label || RECORDING_LABEL_ACTIVE);
            recordingBar.addClass("is-active").removeClass("is-processing").attr("aria-hidden", "false");
            recordingTimerEl.text("00:00");
            input.attr("hidden", true);
        }

        function showProcessingBar() {
            recordingLabelEl.text(RECORDING_LABEL_PROCESSING);
            recordingBar.addClass("is-active is-processing").attr("aria-hidden", "false");
        }

        function hideRecordingBar() {
            recordingBar.removeClass("is-active is-processing no-analyser").attr("aria-hidden", "true");
            input.removeAttr("hidden");
        }

        function setVoiceButtonState() {
            voiceBtn.toggleClass("is-recording", isRecording);
            voiceBtn.attr(
                "aria-label",
                isRecording ? "توقف ضبط" : "ضبط پیام صوتی"
            );
            voiceBtn.attr("title", isRecording ? "توقف ضبط" : "ضبط پیام صوتی");
        }

        function clearTimers() {
            if (recordingTickTimer) {
                clearInterval(recordingTickTimer);
                recordingTickTimer = null;
            }
            if (maxLengthTimer) {
                clearTimeout(maxLengthTimer);
                maxLengthTimer = null;
            }
        }

        function releaseStream() {
            if (mediaStream) {
                mediaStream.getTracks().forEach(function (track) {
                    try { track.stop(); } catch (err) { /* قبلاً متوقف شده */ }
                });
                mediaStream = null;
            }
        }

        /*
        پایان کامل: هر منبعی که گرفته شده آزاد می‌شود. اگر میکروفون آزاد
        نشود، چراغ ضبطِ مرورگر روشن می‌ماند و کاربر حق دارد فکر کند
        سایت دارد بی‌اجازه به او گوش می‌دهد.
        */
        function teardown() {
            clearTimers();
            stopWaveform();
            releaseStream();
            isRecording = false;
            isPreparing = false;
            voiceIsRecording = false;
            setVoiceButtonState();
            updateSendButtonState();
        }

        /* ---------- انتخاب فرمت ---------- */

        /*
        هر مرورگر فرمت متفاوتی می‌دهد: کروم و فایرفاکس webm/opus،
        سافاری mp4/aac. هر سه را Whisper می‌خواند، پس فقط اولین
        فرمتی که مرورگر واقعاً پشتیبانی می‌کند انتخاب می‌شود.
        */
        function pickMimeType() {
            const candidates = [
                "audio/webm;codecs=opus",
                "audio/webm",
                "audio/ogg;codecs=opus",
                "audio/mp4",
            ];
            for (let i = 0; i < candidates.length; i++) {
                if (window.MediaRecorder.isTypeSupported &&
                    window.MediaRecorder.isTypeSupported(candidates[i])) {
                    return candidates[i];
                }
            }
            return "";
        }

        function extensionFor(mimeType) {
            if (mimeType.indexOf("mp4") !== -1) return "m4a";
            if (mimeType.indexOf("ogg") !== -1) return "ogg";
            return "webm";
        }

        /* ---------- ضبط ---------- */

        async function startRecording() {
            if (isRecording || isPreparing || isProcessing) return;

            isPreparing = true;
            showRecordingBar(RECORDING_LABEL_PREPARING);

            let stream;
            try {
                stream = await navigator.mediaDevices.getUserMedia({
                    audio: {
                        echoCancellation: true,
                        noiseSuppression: true,
                        autoGainControl: true,
                    },
                });
            } catch (err) {
                isPreparing = false;
                hideRecordingBar();
                // رد کردن مجوز و «میکروفونی وجود ندارد» دو مشکل کاملاً
                // متفاوت‌اند و راه‌حلشان هم فرق دارد.
                const denied = err && (err.name === "NotAllowedError" || err.name === "SecurityError");
                addMessage(
                    "bot",
                    denied
                        ? "برای ضبط صدا باید دسترسی میکروفون را به این سایت بدهید. از نوار آدرس مرورگر اجازه‌ی میکروفون را روشن کنید و دوباره امتحان کنید."
                        : "میکروفونی پیدا نشد. اگر میکروفون دارید، اتصالش را بررسی کنید — یا سوالتان را تایپ کنید."
                );
                return;
            }

            mediaStream = stream;
            const mimeType = pickMimeType();

            try {
                mediaRecorder = mimeType
                    ? new window.MediaRecorder(stream, { mimeType: mimeType })
                    : new window.MediaRecorder(stream);
            } catch (err) {
                isPreparing = false;
                hideRecordingBar();
                releaseStream();
                addMessage("bot", "ضبط صدا در این مرورگر ممکن نشد. لطفاً پیامتان را بنویسید.");
                return;
            }

            chunks = [];
            mediaRecorder.addEventListener("dataavailable", function (event) {
                if (event.data && event.data.size > 0) chunks.push(event.data);
            });
            mediaRecorder.addEventListener("stop", function () {
                const blob = new Blob(chunks, { type: mediaRecorder.mimeType || "audio/webm" });
                const seconds = (Date.now() - recordingStartTime) / 1000;
                teardown();
                uploadRecording(blob, extensionFor(mediaRecorder.mimeType || ""), seconds);
            });

            mediaRecorder.start();
            isPreparing = false;
            isRecording = true;
            voiceIsRecording = true;
            recordingStartTime = Date.now();

            showRecordingBar(RECORDING_LABEL_ACTIVE);
            setVoiceButtonState();
            startWaveform(stream);

            recordingTickTimer = setInterval(tickTimer, 250);
            // ضبطِ فراموش‌شده نباید تا ابد ادامه پیدا کند.
            maxLengthTimer = setTimeout(function () {
                if (isRecording) stopRecording();
            }, MAX_RECORDING_MS);

            // در حال ضبط، «ارسال» یعنی «تمام کن و بفرست».
            updateSendButtonState();
        }

        function stopRecording() {
            if (!isRecording || !mediaRecorder) return;
            // ضبط‌های خیلی کوتاه معمولاً کلیک اشتباهی‌اند و چیزی در
            // آن‌ها نیست؛ فرستادنشان فقط هزینه و یک پاسخ گیج‌کننده دارد.
            const tooShort = Date.now() - recordingStartTime < 500;
            if (tooShort) {
                sendWhenReady = false;
                chunks = [];
            }
            try {
                mediaRecorder.stop();
            } catch (err) {
                teardown();
                hideRecordingBar();
            }
        }

        /* ---------- تبدیل به متن ---------- */

        function uploadRecording(blob, extension, seconds) {
            if (!blob || blob.size === 0) {
                hideRecordingBar();
                sendWhenReady = false;
                return;
            }

            isProcessing = true;
            showProcessingBar();

            const form = new FormData();
            form.append("action", "ai_agent_transcribe");
            form.append("nonce", ai_agent.transfer_nonce);
            form.append("duration_seconds", String(Math.round(seconds * 10) / 10));
            form.append("audio", blob, "voice." + extension);

            $.ajax({
                url: ai_agent.ajax_url,
                method: "POST",
                data: form,
                processData: false,
                contentType: false,
            }).done(function (response) {
                isProcessing = false;
                hideRecordingBar();

                if (!response || !response.success || !response.data) {
                    sendWhenReady = false;
                    // پیام نمایش‌داده‌شده به کاربر عمومی است؛ خطای واقعی این‌جا
                    // ثبت می‌شود تا بشود مشکل را در کنسول مرورگر دید (سرور هم
                    // خودش را در لاگ PHP با پیشوند AI_AGENT_DEBUG ثبت می‌کند).
                    console.error("ai-agent: voice transcription failed", response);
                    addMessage(
                        "bot",
                        (response && response.data && response.data.message) ||
                            "تبدیل صدا به متن انجام نشد. دوباره تلاش کنید یا پیام را تایپ کنید."
                    );
                    return;
                }

                const text = (response.data.text || "").trim();
                if (!text) {
                    sendWhenReady = false;
                    addMessage("bot", "صدای پیام واضح نبود و چیزی متوجه نشدم. یک بار دیگر بفرستید یا بنویسید.");
                    return;
                }

                // متن به آنچه کاربر از قبل نوشته اضافه می‌شود، نه
                // جایگزینش: ممکن است نصف سوالش را تایپ کرده باشد.
                const existing = input.val().trim();
                input.val(existing ? existing + " " + text : text);
                autoResizeInput();
                updateSendButtonState();

                if (sendWhenReady) {
                    sendWhenReady = false;
                    sendMessage();
                } else {
                    input.trigger("focus");
                }
            }).fail(function (jqXHR, textStatus, errorThrown) {
                isProcessing = false;
                hideRecordingBar();
                sendWhenReady = false;
                console.error(
                    "ai-agent: voice transcription request failed",
                    textStatus, errorThrown, jqXHR && jqXHR.responseText
                );
                addMessage("bot", "ارتباط با سرور برای تبدیل صدا برقرار نشد. دوباره تلاش کنید.");
            });
        }

        /* ---------- اتصال به رابط ---------- */

        voiceBtn.on("click", function () {
            if (isProcessing) return;
            if (isRecording) {
                stopRecording();
            } else {
                startRecording();
            }
        });

        /*
        زدن «ارسال» وسط ضبط: ضبط تمام می‌شود و به‌محض آماده‌شدنِ متن،
        پیام می‌رود. کسی که حرفش تمام شده و دستش روی ارسال است، منظورش
        همین است و نباید مجبور شود دو دکمه را پشت‌سرهم بزند.
        */
        $(document).on("ai-agent-send-intent", function (event, intent) {
            if (isRecording) {
                intent.handled = true;
                sendWhenReady = true;
                stopRecording();
            }
        });

        // چت جدید یا انتقال گفت‌وگو: ضبطِ نیمه‌کاره باید تمیز تمام شود،
        // نه اینکه در پس‌زمینه رها بماند.
        $(document).on("ai-agent-chat-reset", function () {
            if (isRecording) {
                sendWhenReady = false;
                stopRecording();
            }
        });

        /*
        رفتن صفحه به پس‌زمینه روی موبایل، ضبط را از دست سیستم‌عامل
        می‌گیرد. تمامش می‌کنیم تا چیزی که ضبط شده از دست نرود و
        میکروفون هم آزاد شود.
        */
        document.addEventListener("visibilitychange", function () {
            if (document.hidden && isRecording) stopRecording();
        });
    }

    /*
    ============================================
    مدیریت فیدبک هوشمند و گیت کارشناس
    ============================================
    */
/*
    ============================================
    بارگذاری تاریخچه‌ی چت هنگام باز شدن مجدد سایت
    (تا زمانی که کوکی session_id پاک نشده، تاریخچه حفظ می‌شود)

    هر پیام از سمت API می‌تواند شامل این فیلدها باشد:
        - role        : user | assistant | support | system
        - content     : متن پیام
        - references  : آرایه‌ای از { title, url }
        - image_keys  : آرایه‌ای از کلیدهای عکس (برای پیام کاربر)

    برای پیام‌های کاربر، image_keys به addMessage پاس داده می‌شود تا
    عکس‌ها به‌صورت lazy و یکی‌یکی از اندپوینت ai_agent_get_media دریافت
    شوند و در گالری همان پیام نمایش داده شوند. پیام‌های دیگر معمولاً
    image_keys ندارند اما در صورت وجود، نادیده گرفته می‌شوند (چون گالری
    عکس فقط برای پیام کاربر تعریف شده است).
    ============================================
    */
function renderHistoryMessage(msg) {
    if (!msg || (!msg.content && !(Array.isArray(msg.image_keys) && msg.image_keys.length))) return;
    const role = msg.role || 'assistant';
    const content = msg.content || '';
    const imageKeys = Array.isArray(msg.image_keys) ? msg.image_keys : [];

    if (role === 'user') {
        // پیام کاربر: متن + گالری عکس‌های lazy از image_keys
        // اگر محتوای متنی نبود ولی image_keys بود، باز هم حباب کاربر با گالری نمایش داده می‌شود
        addMessage('user', escapeHtml(content), null, [], imageKeys);
    } else if (role === 'support') {
        // پیام پشتیبان انسانی
        addMessage('admin', renderInlineMarkdown(content));
    } else {
        // assistant / system / سایر
        addMessage('ai', renderInlineMarkdown(content));

        if (Array.isArray(msg.references) && msg.references.length > 0) {
            const $lastBody = messages.children().last().find('.ai-message-body');

            const $gallery = buildReferencesGallery(msg.references);
            if ($gallery) $lastBody.prepend($gallery);

            const $refList = buildReferencesListBox(msg.references);
            if ($refList) $lastBody.append($refList);
        }
    }
}

    /*
    ============================================
    بارگذاری تاریخچه‌ی چت هنگام باز شدن مجدد سایت
    (تا زمانی که کوکی session_id پاک نشده، تاریخچه حفظ می‌شود)

    هر پیام از سمت API می‌تواند شامل این فیلدها باشد:
        - role        : user | assistant | support | system
        - content     : متن پیام
        - references  : آرایه‌ای از { title, url }
        - image_keys  : آرایه‌ای از کلیدهای عکس (برای پیام کاربر)

    علاوه بر پیام‌ها، پاسخ شامل فیلدهای زیر در سطح بالاست:
        - status            : bot | pending_human | human | closed
        - last_message_role : user | assistant | support | system

    بر اساس وضعیت جلسه (status) رفتار متفاوتی انجام می‌شود:
        - closed         →  پیام‌ها نمایش داده می‌شوند + پیام سیستمی
                            «این گفتگو بسته شده است»
        - pending_human / human  →  پیام‌ها نمایش داده می‌شوند + اندیکاتور
                                     «در حالت پشتیبانی» (بدون انیمیشن انتظار ربات)
        - bot / assistant / ''   →  پیام‌ها نمایش داده می‌شوند (رفتار معمول)

    برای پیام‌های کاربر، image_keys به addMessage پاس داده می‌شود تا
    عکس‌ها به‌صورت lazy و یکی‌یکی از اندپوینت ai_agent_get_media دریافت
    شوند و در گالری همان پیام نمایش داده شوند. پیام‌های دیگر معمولاً
    image_keys ندارند اما در صورت وجود، نادیده گرفته می‌شوند (چون گالری
    عکس فقط برای پیام کاربر تعریف شده است).
    ============================================
    */
    function loadChatHistory() {
        if (!sessionId) return;
        $.ajax({
            url: ai_agent.ajax_url,
            method: 'POST',
            data: {
                action: 'ai_agent_get_history',
                session_id: sessionId
            },
            success: function (res) {
                if (!res.success || !res.data) return;

                const data = res.data;
                const sessionStatus = data.status || '';
                // ذخیره‌ی وضعیت برای استفاده‌ی بعدی در sendMessage
                currentSessionStatus = sessionStatus;

                const msgs = Array.isArray(data.messages) ? data.messages : [];

                if (msgs.length > 0) {
                    messages.empty();
                    msgs.forEach(renderHistoryMessage);
                    scrollToBottom();
                } else {
                    // جلسه‌ای که هیچ پیامی ندارد (مثلاً ویجت باز شده و
                    // چیزی نوشته نشده) باید صفحه‌ی شروع را نشان دهد، نه
                    // یک صفحه‌ی کاملاً خالی.
                    renderIntro();
                }

                // به‌روزرسانی کوکی تعداد پیام‌های دیده‌شده با تعداد کل پیام‌های جلسه
                setMsgCount(msgs.length);

                /*
                گفت‌وگویی که به پیام‌رسان منتقل شده، بعد از رفرش هم باید
                بسته بماند. قبل از بررسی وضعیت چک می‌شود چون وضعیتش
                ممکن است هنوز pending_human باشد — کاربر منتظر پشتیبان
                است، فقط نه این‌جا.
                */
                if (data.transferred) {
                    lockChatForTransfer();
                    return;
                }

                // بر اساس وضعیت جلسه، پیام سیستمی مناسب نمایش می‌دهیم
                if (sessionStatus === 'closed') {
                    addClosedMessage();
                    // قفل کردن فوتر چون گفتگو بسته شده است
                    setChatDisabled(true);
                } else if (isSupportMode(sessionStatus)) {
                    // در حالت پشتیبانی، اندیکاتور «در حالت پشتیبانی» را نمایش می‌دهیم
                    // (بدون انیمیشن انتظار ربات — پشتیبان قرار است پاسخ دهد)
                    addSupportModeIndicator();
                    // شروع polling برای بررسی پیام‌های جدید از پشتیبان
                    startPolling();
                }
                // در حالت ربات (bot / assistant / '') هیچ پیام اضافه‌ای نمایش داده نمی‌شود
            }
        });
    }


    /*
    ============================================
    ادامه‌ی گفت‌وگو در بله

    چرا اصلاً وجود دارد: کاربر پشتیبان انسانی خواسته و پشتیبان آن لحظه
    آنلاین نیست. نگه‌داشتنش پای یک تب باز تا وقتی کسی جواب بدهد بدترین
    کار ممکن است؛ به‌جایش یک کد شش‌رقمی می‌گیرد، در ربات سایت واردش
    می‌کند، و جواب — هر وقت آمد — روی گوشی‌اش می‌رسد.

    کد را سرور می‌سازد و متن راهنما را هم سرور می‌نویسد، تا چیزی که
    این‌جا نوشته می‌شود دقیقاً همان چیزی باشد که ربات قبولش دارد.
    ============================================
    */
    const transferBtn      = $("#ai-agent-transfer");
    const transferDialog   = $("#ai-agent-transfer-dialog");
    const transferOptions  = $("#ai-agent-transfer-options");
    const transferText     = $("#ai-agent-transfer-text");
    const transferConfirm  = $("#ai-agent-transfer-confirm");
    const transferredBar   = $("#ai-agent-transferred-bar");

    let transferChoices = [];

    const PLATFORM_LINK_LABEL = {
        bale: 'بله'
    };

    function loadTransferOptions() {
        if (!transferBtn.length) return;

        $.post(ai_agent.ajax_url, {
            action: 'ai_agent_transfer_options',
            nonce: ai_agent.transfer_nonce
        }).done(function (response) {
            const options = (response && response.success && response.data && response.data.options) || [];
            transferChoices = options;
            // بدون ربات، دکمه اصلاً نمی‌آید. دکمه‌ای که به بن‌بست ختم
            // می‌شود بدتر از نبودنش است.
            if (options.length) {
                transferBtn.removeAttr('hidden');
            } else {
                transferBtn.attr('hidden', true);
            }
        });
    }

    function openTransferDialog() {
        if (!transferChoices.length) return;

        const names = transferChoices.map(function (option) {
            return (PLATFORM_LINK_LABEL[option.platform] || option.platform) + ' (@' + option.bot_username + ')';
        }).join(' یا ');

        transferText.text(
            'می‌تونی ادامه‌ی همین گفت‌وگو رو توی ' + names + ' داشته باشی. ' +
            'این‌طوری لازم نیست این صفحه رو باز نگه داری — یه کد بهت می‌دیم، ' +
            'توی ربات واردش می‌کنی و از همون‌جا ادامه می‌دیم.'
        );

        transferOptions.empty();
        transferChoices.forEach(function (option) {
            transferOptions.append(
                $('<a class="ai-agent-transfer-option" target="_blank" rel="noopener"></a>')
                    .attr('href', option.bot_link)
                    .text((PLATFORM_LINK_LABEL[option.platform] || option.platform) + ' · @' + option.bot_username)
            );
        });

        transferDialog.removeAttr('hidden');
    }

    function closeTransferDialog() {
        transferDialog.attr('hidden', true);
    }

    /*
    بعد از انتقال، فیلد پیام برداشته می‌شود و یک نوار توضیح جایش
    می‌نشیند — نه اینکه فقط خاکستر شود. یک فیلد غیرفعال هنوز دعوت به
    نوشتن است، و پیامی که این‌جا نوشته شود دیگر به دست هیچ‌کس نمی‌رسد.
    */
    function lockChatForTransfer() {
        $('#ai-agent-footer .ai-agent-footer-row').attr('hidden', true);
        transferredBar.removeAttr('hidden');
        transferBtn.attr('hidden', true);
        stopPolling();
    }

    function unlockChatAfterTransfer() {
        $('#ai-agent-footer .ai-agent-footer-row').removeAttr('hidden');
        transferredBar.attr('hidden', true);
    }

    transferBtn.on('click', openTransferDialog);
    $('#ai-agent-transfer-cancel').on('click', closeTransferDialog);
    transferDialog.on('click', function (event) {
        if (event.target === this) closeTransferDialog();
    });

    transferConfirm.on('click', function () {
        if (!sessionId) {
            // هنوز حرفی زده نشده، پس گفت‌وگویی هم نیست که منتقل شود.
            closeTransferDialog();
            addMessage('bot', 'اول یه پیام بفرست تا گفت‌وگو شروع بشه، بعد می‌تونیم ببریمش توی پیام‌رسان.');
            return;
        }

        transferConfirm.prop('disabled', true).text('یه لحظه…');

        $.post(ai_agent.ajax_url, {
            action: 'ai_agent_transfer_session',
            nonce: ai_agent.transfer_nonce,
            session_id: sessionId
        }).done(function (response) {
            transferConfirm.prop('disabled', false).text('بریم');
            closeTransferDialog();

            if (!response || !response.success) {
                const message = (response && response.data && response.data.message)
                    || 'انتقال گفت‌وگو انجام نشد. دوباره تلاش کن.';
                addMessage('bot', message);
                return;
            }

            // همان دو پیامی که سرور در تاریخچه‌ی گفت‌وگو ثبت کرده، این‌جا
            // هم نشان داده می‌شوند تا کاربر بعد از رفرش صفحه همان چیزی را
            // ببیند که الان می‌بیند.
            addMessage('user', 'بیا ادامه‌ی گفت‌وگو را در پیام‌رسان ادامه بدهیم.');
            addMessage('bot', response.data.message || '');
            lockChatForTransfer();
        }).fail(function () {
            transferConfirm.prop('disabled', false).text('بریم');
            closeTransferDialog();
            addMessage('bot', 'ارتباط با سرور برقرار نشد. یه بار دیگه امتحان کن.');
        });
    });

    loadTransferOptions();

    /*
    شروع: اگر گفت‌وگوی قبلی وجود دارد ادامه‌اش را نشان می‌دهیم، وگرنه
    صفحه‌ی شروع با پیشنهادهای مدیر. پیام خوش‌آمدگویی ثابتِ قبلی
    («سلام، چطور می‌تونم کمکتون کنم؟») حذف شد: کاربر را جلوی یک فیلد
    خالی تنها می‌گذاشت و هیچ راهی نشان نمی‌داد.
    */
    if (sessionId) {
        loadChatHistory();
    } else {
        renderIntro();
    }

});