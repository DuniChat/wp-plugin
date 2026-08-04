jQuery(function ($) {

    const button = $("#ai-agent-button");
    const windowChat = $("#ai-agent-window");
    const close = $("#ai-agent-close");
    const send = $("#ai-agent-send");
    const input = $("#ai-agent-input");
    const messages = $("#ai-agent-messages");
    const widget = $("#ai-agent");
    const themeToggle = $(".ai-theme-toggle");

    /*
    ============================================
    مدیریت دستی تم دارک/لایت

    مقدار اولیه از تنظیمات سیستم کاربر خوانده می‌شود؛ اما بعد از آن
    کاربر می‌تواند با کلیک روی آیکون ماه/خورشید، مستقل از تنظیمات
    سیستم، بین دو حالت جابجا شود. انتخاب کاربر در localStorage
    ذخیره می‌شود تا در بازدیدهای بعدی هم حفظ شود.
    ============================================
    */
    const THEME_STORAGE_KEY = 'ai_agent_theme';

    function applyTheme(theme) {
        widget.attr('data-theme', theme);
    }

    function initTheme() {
        let saved = null;
        try {
            saved = localStorage.getItem(THEME_STORAGE_KEY);
        } catch (e) {
            saved = null;
        }
        if (saved === 'dark' || saved === 'light') {
            applyTheme(saved);
            return;
        }
        const prefersDark = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
        applyTheme(prefersDark ? 'dark' : 'light');
    }

    themeToggle.on('click', function () {
        const current = widget.attr('data-theme') === 'dark' ? 'dark' : 'light';
        const next = current === 'dark' ? 'light' : 'dark';
        applyTheme(next);
        try {
            localStorage.setItem(THEME_STORAGE_KEY, next);
        } catch (e) {
            // اگر localStorage در دسترس نبود، فقط برای همین بازدید تم عوض می‌شود
        }
    });

    initTheme();

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

    // پاک کردن کوکی session_id (برای شروع چت جدید)
    function clearSessionId() {
    sessionId = null;
    const cookieName = (window.ai_agent && ai_agent.session_cookie) ? ai_agent.session_cookie : 'ai_agent_session_id';
    document.cookie = cookieName + '=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/; SameSite=Lax';

    const escalatedCookieName = (window.ai_agent && ai_agent.escalated_cookie) ? ai_agent.escalated_cookie : 'ai_agent_escalated_session';
    document.cookie = escalatedCookieName + '=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/; SameSite=Lax';
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
    ============================================
    */
    button.on("click", function () {
        windowChat.toggleClass("ai-agent-open");
        if (windowChat.hasClass("ai-agent-open")) {
            messages.scrollTop(messages[0].scrollHeight);
            input.focus();
        }
    });

    close.on("click", function () {
        windowChat.removeClass("ai-agent-open");
    });

    /*
    ============================================
    شروع چت جدید: پاک کردن کوکی session_id و
    بارگذاری مجدد ویجت از ابتدا (پاک شدن تاریخچه‌ی نمایشی)
    ============================================
    */
    const newChatBtn = $("#ai-agent-new-chat");

    newChatBtn.on("click", function () {
        clearSessionId();

        // پاک کردن پیام‌های فعلی و بازگرداندن پیام خوش‌آمدگویی پیش‌فرض
        messages.empty();
        messages.append(
            '<div class="ai-message"><div class="ai-message-body">سلام 👋 چطور می‌تونم کمکتون کنم؟</div></div>'
        );

        // خالی کردن باکس ورودی و بازگرداندن ارتفاع آن به حالت اولیه
        input.val('');
        autoResizeInput();
        input.focus();
    });

    /*
    ============================================
    افزودن پیام با استایل‌های اختصاصی و متحرک
    ============================================
    */
    function addMessage(type, text, chatId) {
        chatId = chatId || null;
        let cls = "";
        if (type === "user") cls = "user-message";
        else if (type === "admin") cls = "admin-message";
        else cls = "ai-message";

        if (type === "user" || type === "admin") {
            let titlePrefix = type === "admin" ? "<strong>پاسخ کارشناس:</strong><br>" : "";
            messages.append('<div class="' + cls + ' fade-in-up">' + titlePrefix + text + '</div>');
        } else {
            let feedbackHtml = '';
            if (chatId) {
                feedbackHtml = feedbackHtmlFor(chatId);
            }
            // متن و دکمه‌های فیدبک داخل یک بدنه‌ی جدا قرار می‌گیرند تا آواتار CSS کنار کل پیام بنشیند
            messages.append('<div class="' + cls + ' fade-in-up"><div class="ai-message-body">' + text + feedbackHtml + '</div></div>');
        }
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

    function feedbackHtmlFor(chatId) {
        return `
            <div class="ai-feedback-wrapper" data-chat-id="${chatId}">
                <div class="ai-feedback-buttons">
                    <span class="ai-feedback-btn thumb-up" data-type="like" title="مفید بود">👍</span>
                    <span class="ai-feedback-btn thumb-down" data-type="dislike" title="مفید نبود">👎</span>
                </div>
            </div>`;
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

    const withImages = references.filter(ref => ref && ref.url && ref.image);
    if (withImages.length === 0) return null;

    const $wrap = $('<div class="ai-references-gallery-wrap"></div>');
    const $gallery = $('<div class="ai-references-gallery"></div>');
    const $dots = $('<div class="ai-gallery-dots"></div>');
    const itemEls = [];

    withImages.forEach(function (ref) {
        const $item = $('<a class="ai-reference-gallery-item" target="_blank" rel="noopener noreferrer"></a>')
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
        const label = ref.title ? String(ref.title) : String(ref.url);
        const $link = $('<a class="ai-reference-link" target="_blank" rel="noopener noreferrer"></a>')
            .attr('href', ref.url)
            .text(label);
        $list.append($link);
    });

    if ($list.children().length === 0) return null;

    const $box = $('<div class="ai-references-box"></div>');
    const $title = $('<div class="ai-references-title"></div>').text('موارد مرتبط:');
    $box.append($title).append($list);
    return $box;
}
    /*
    ============================================
    ساخت یک پیام AI خالی برای استریم کردن محتوا داخل آن
    برمی‌گرداند: { $wrapper, $content, $loading }
    ============================================
    */
    function addStreamingMessage() {
    const $wrapper = $('<div class="ai-message fade-in-up"></div>');
    const $body = $('<div class="ai-message-body"></div>');
    const $content = $('<span class="ai-streaming-content"></span>');
    const $loading = $(
        '<div class="typing-dots" id="ai-loading-stream"><span></span><span></span><span></span></div>'
    );
    $body.append($content);
    $body.append($loading);
    $wrapper.append($body);
    messages.append($wrapper);
    return { $wrapper, $body, $content, $loading, references: [], rawText: '' }; // <-- rawText اضافه شد
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
    ============================================
    */
    async function sendMessage() {
        let text = input.val().trim();
        if (!text) return;

        addMessage("user", text);
        input.val("");
        autoResizeInput(); // برگشت به ارتفاع پیش‌فرض بعد از ارسال

        // ساخت یک پیام AI خالی که محتوای استریم‌شده داخل آن قرار می‌گیرد
        const stream = addStreamingMessage();


        // ساخت بدنه‌ی درخواست به فرمت x-www-form-urlencoded
        const body = new URLSearchParams();
        body.append('action', 'ai_agent_chat');
        body.append('message', text);
        body.append('session_id', sessionId || '');


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
        stream.rawText += data.content; // ذخیره‌ی متن خام برای پردازش نهایی
        stream.$content.append(escapeHtml(data.content));
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
} else if (data.type === 'session_init' && data.session_id) {
            setSessionId(data.session_id);
        } else if (data.type === 'escalate') {
            stream.$loading.remove();
            stream.$wrapper.remove();
            addEscalateMessage(data.reason);
        } else if (data.type === 'done') {
            stream.$loading.remove();

            // اگر هیچ محتوایی دریافت نشد (مثلاً بعد از انتقال به پشتیبان، تا وقتی پاسخ ندهد)
            // حباب خالی را حذف می‌کنیم تا فضای خالی عجیب نمایش داده نشود
            if (stream.rawText.trim() === '' && (!stream.references || stream.references.length === 0)) {
                stream.$wrapper.remove();
                return;
            }

            if (!stream.referencesRendered && stream.references && stream.references.length) {
    const $gallery = buildReferencesGallery(stream.references);
    if ($gallery) stream.$content.before($gallery);

    const $refList = buildReferencesListBox(stream.references);
    if ($refList) stream.$body.append($refList);
}

            if (data.chat_id) {
                stream.$body.append(feedbackHtmlFor(data.chat_id));
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

    send.on("click", function () {
        sendMessage();
    });

    input.on("keydown", function (e) {
        if (e.key === "Enter" && !e.shiftKey) {
            e.preventDefault();
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

    input.on("input", autoResizeInput);
    autoResizeInput(); // تنظیم ارتفاع اولیه

    /*
    ============================================
    مدیریت فیدبک هوشمند و گیت کارشناس
    ============================================
    */
    $(document).on("click", ".ai-feedback-btn", function () {
        let $btn = $(this);
        let $wrapper = $btn.closest('.ai-feedback-wrapper');
        let chatId = $wrapper.data('chat-id');
        let feedbackType = $btn.data('type');

        if (feedbackType === 'dislike') {
            $wrapper.html('<p style="margin:4px 0 0 0; font-size:12px; color:#64748b; font-weight:500;">ممنون از بازخوردتون، سعی می‌کنیم بهتر بشیم.</p>');
            $.ajax({
                url: ai_agent.ajax_url,
                method: "POST",
                data: { action: "ai_agent_feedback", chat_id: chatId, feedback: "dislike" }
            });
        } else {
            $wrapper.html('<p style="margin:4px 0 0 0; font-size:12px; color:#10b981; font-weight:500; display:flex; align-items:center; gap:4px;">ممنون از نظرتون! شما به ما کمک می‌کنید بهتر بشیم. ❤️</p>');
            $.ajax({
                url: ai_agent.ajax_url,
                method: "POST",
                data: { action: "ai_agent_feedback", chat_id: chatId, feedback: "like" }
            });
        }
    });

/*
    ============================================
    بارگذاری تاریخچه‌ی چت هنگام باز شدن مجدد سایت
    (تا زمانی که کوکی session_id پاک نشده، تاریخچه حفظ می‌شود)
    ============================================
    */
function renderHistoryMessage(msg) {
    if (!msg || !msg.content) return;
    const role = msg.role || 'assistant';

    if (role === 'user') {
        addMessage('user', escapeHtml(msg.content));
    } else {
        addMessage('ai', escapeHtml(msg.content));

        if (Array.isArray(msg.references) && msg.references.length > 0) {
            const $lastBody = messages.children().last().find('.ai-message-body');

            const $gallery = buildReferencesGallery(msg.references);
            if ($gallery) $lastBody.prepend($gallery);

            const $refList = buildReferencesListBox(msg.references);
            if ($refList) $lastBody.append($refList);
        }
    }
}

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
                if (res.success && res.data && Array.isArray(res.data.messages) && res.data.messages.length > 0) {
                    messages.empty(); // پیام خوش‌آمدگویی پیش‌فرض حذف می‌شود
                    res.data.messages.forEach(renderHistoryMessage);
                }
            }
        });
    }

    // تنها اگر session_id در کوکی موجود باشد، تاریخه چت را بارگذاری می‌کنیم
    if (sessionId) {
        loadChatHistory();
    }

});