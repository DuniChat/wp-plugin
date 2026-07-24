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
        return { $wrapper: $wrapper, $body: $body, $content: $content, $loading: $loading };
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
            // اولین چانک: لودینگ را حذف می‌کنیم
            stream.$loading.remove();
            // محتوا را escape کرده و اضافه می‌کنیم
            stream.$content.append(escapeHtml(data.content));
        } else if (data.type === 'session_init' && data.session_id) {
            // ذخیره session_id دریافتی از API در کوکی
            setSessionId(data.session_id);
        } else if (data.type === 'escalate') {
            // در این حالت مدل هیچ متنی ننوشته؛ حباب استریم خالی را حذف
            // و یک پیام سیستمی جدا برای اعلام انتقال به پشتیبان نمایش می‌دهیم
            stream.$loading.remove();
            stream.$wrapper.remove();
            addEscalateMessage(data.reason);
        } else if (data.type === 'done') {
            stream.$loading.remove();
            // اضافه‌ کردن UI فیدبک (لایک/دیسلایک) به انتهای پیام
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
            $wrapper.html(`
                <div class="ai-support-gate fade-in-up">
                    <p style="margin:0 0 8px 0; font-size:12px; color:#64748b; line-height:1.5;">پاسخ مناسب نبود؟ مایلید این موضوع به کارشناسان ما ارجاع داده شود؟</p>
                    <textarea class="ai-support-msg" placeholder="توضیح کوتاه یا شماره تماس (اختیاری)..."></textarea>
                    <button class="ai-submit-support-btn" style="background:${ai_agent.color || '#2563eb'}">انتقال به پشتیبان</button>
                </div>
            `);

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
    ثبت نهایی فرم گیت کارشناسان پشتیبانی
    ============================================
    */
    $(document).on("click", ".ai-submit-support-btn", function () {
        let $btn = $(this);
        let $gate = $btn.closest('.ai-support-gate');
        let $wrapper = $btn.closest('.ai-feedback-wrapper');
        let chatId = $wrapper.data('chat-id');
        let userMsg = $gate.find('.ai-support-msg').val();

        $btn.prop('disabled', true).text('در حال ثبت...');

        $.ajax({
            url: ai_agent.ajax_url,
            method: "POST",
            data: {
                action: "ai_agent_submit_support",
                session_id: sessionId,
                chat_id: chatId,
                message: userMsg
            },
            success: function (res) {
                if (res.success) {
                    $gate.html('<p style="margin:5px 0 0 0; font-size:12px; color:#10b981; font-weight:500;">درخواست شما به کارشناسان ارجاع داده شد. پاسخ ادمین همین‌جا برای شما ظاهر می‌شود.</p>');
                } else {
                    $btn.prop('disabled', false).text('خطا در ارسال مجدد');
                }
            }
        });
    });

    /*
    ============================================
    سیستم زمان‌بندی Polling برای واکشی پاسخ ادمین
    ============================================
    */
    setInterval(function () {
        if (windowChat.hasClass("ai-agent-open")) {
            $.ajax({
                url: ai_agent.ajax_url,
                method: "POST",
                data: {
                    action: "ai_agent_poll_support",
                    session_id: sessionId
                },
                success: function (response) {
                    if (response.success && response.data && response.data.length > 0) {
                        response.data.forEach(function (ticket) {
                            addMessage("admin", ticket.admin_reply);
                        });
                    }
                }
            });
        }
    }, 5000);
    
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
            // پیام‌های تاریخچه فاقد chat_id محلی هستند، پس دکمه‌ی فیدبک نمایش داده نمی‌شود
            addMessage('ai', escapeHtml(msg.content));
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