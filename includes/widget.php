<?php

if (!defined('ABSPATH')) exit;

function ai_agent_widget(){

    $settings = ai_agent_get_settings();

    /*
    مسیر تصاویر. پس‌زمینه‌ی چت دیگر عکس نیست: یک رنگ ساده است که مدیر
    در تنظیمات افزونه انتخاب می‌کند (فیلدهای پس‌زمینه‌ی چت در حالت
    روشن/تاریک).
    */
    $ai_agent_logo    = esc_url(AI_AGENT_URL . 'assets/images/logo.png');
    $ai_agent_favicon = esc_url(AI_AGENT_URL . 'assets/images/favicon.png');

    $bg_light = isset($settings['chat_bg_light']) ? $settings['chat_bg_light'] : '#FAF9F5';
    $bg_dark  = isset($settings['chat_bg_dark'])  ? $settings['chat_bg_dark']  : '#1F1E1D';

    $org_name = !empty($settings['organization_name'])
        ? $settings['organization_name']
        : 'دانیچَت';
?>
<div id="ai-agent"
     style="--ai-agent-favicon:url('<?php echo $ai_agent_favicon; ?>');
            --ai-agent-chat-bg-light:<?php echo esc_attr($bg_light); ?>;
            --ai-agent-chat-bg-dark:<?php echo esc_attr($bg_dark); ?>;">

    <div id="ai-agent-button" title="پشتیبانی هوشمند">
        <img src="<?php echo $ai_agent_logo;?>" alt="AI Logo">
    </div>

    <div id="ai-agent-window">
        <?php
        /*
        ============================================
        هدر

        دکمه‌ها همه یک SVG در یک دکمه‌ی ۳۲ در ۳۲ هستند.

        آیکون ماه/خورشید حذف شد. تم دیگر انتخابِ بازدیدکننده نیست —
        از تنظیمات افزونه (theme_mode) و از تم خود سایت گرفته می‌شود.
        ============================================
        */ ?>
        <div id="ai-agent-header">
            <?php
            /*
           دکمه‌ی گفت‌وگوهای پیشین، سمت ابتدای هدر (سمت راست در RTL).
           قرارگیری‌اش این‌طوری است که عنوان دقیقاً وسط بماند — سمت
           مقابل هم دکمه‌های هم‌اندازه دارد.
           */ ?>
            <div class="ai-agent-header-actions">
                <button type="button" id="ai-agent-history" class="ai-agent-icon-btn" title="گفت‌وگوهای پیشین" aria-label="گفت‌وگوهای پیشین">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                        <line x1="4" y1="7" x2="20" y2="7"/>
                        <line x1="4" y1="12" x2="20" y2="12"/>
                        <line x1="4" y1="17" x2="14" y2="17"/>
                    </svg>
                </button>
            </div>

            <div class="ai-agent-header-title"><?php echo esc_html($org_name); ?></div>

            <div class="ai-agent-header-actions">
                <?php
                /*
                ادامه‌ی گفت‌وگو در بله.

                در HTML همیشه هست ولی تا وقتی سایت رباتی وصل نکرده باشد
                مخفی می‌ماند — JS با پرسیدن از سرور (اندپوینت
                ai_agent_transfer_options که خودش از
                GET /chat/transfer-options می‌پرسد) تصمیم می‌گیرد.
                دکمه‌ای که به هیچ رباتی نمی‌رسد، فقط یک بن‌بست است.

                آیکون، خودِ نشان بله است و نه یک هواپیمای کاغذی عمومی:
                کاربر باید از روی دکمه بفهمد قرار است کجا برود.
                */ ?>
                <button type="button" id="ai-agent-transfer" class="ai-agent-icon-btn" hidden
                        title="ادامه در بله" aria-label="ادامه‌ی گفت‌وگو در بله">
                    <img src="<?php echo esc_url(AI_AGENT_URL . 'assets/images/bale.svg'); ?>" alt="" />
                </button>
                <button type="button" id="ai-agent-new-chat" class="ai-agent-icon-btn" title="گفت‌وگوی تازه" aria-label="گفت‌وگوی تازه">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                        <line x1="12" y1="5" x2="12" y2="19"/>
                        <line x1="5" y1="12" x2="19" y2="12"/>
                    </svg>
                </button>
                <button type="button" id="ai-agent-close" class="ai-agent-icon-btn" title="بستن" aria-label="بستن گفت‌وگو">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                        <line x1="6" y1="6" x2="18" y2="18"/>
                        <line x1="18" y1="6" x2="6" y2="18"/>
                    </svg>
                </button>
            </div>
        </div>

        <?php
        /*
        ============================================
        کشوی گفت‌وگوهای پیشین

        روی خودِ پنجره‌ی چت باز می‌شود، نه کنارش: پنجره در موبایل
        تمام‌صفحه است و جایی برای ستون دوم وجود ندارد. محتوایش را
        JS از اندپوینت ai_agent_visitor_sessions می‌گیرد — گفت‌وگوهای
        همین مرورگر، با توکنی که خود مرورگر نگه می‌دارد.
        ============================================
        */ ?>
        <?php
        /*
        دیالوگ تأیید انتقال به پیام‌رسان بله. متن و نشانی ربات را JS پر
        می‌کند، چون تا وقتی از سرور نپرسیده‌ایم نمی‌دانیم رباتی متصل
        هست یا نه (اندپوینت ai_agent_transfer_options).
        */ ?>
        <div id="ai-agent-transfer-dialog" class="ai-agent-modal" hidden>
            <div class="ai-agent-modal-card" role="dialog" aria-modal="true" aria-labelledby="ai-agent-transfer-title">
                <h3 id="ai-agent-transfer-title">
                    <img class="ai-agent-bale-mark" src="<?php echo esc_url(AI_AGENT_URL . 'assets/images/bale.svg'); ?>" alt="" />
                    ادامه‌ی گفت‌وگو در بله
                </h3>
                <p id="ai-agent-transfer-text"></p>
                <div id="ai-agent-transfer-options" class="ai-agent-transfer-options"></div>
                <div class="ai-agent-modal-actions">
                    <button type="button" id="ai-agent-transfer-cancel" class="ai-agent-modal-btn">بی‌خیال</button>
                    <button type="button" id="ai-agent-transfer-confirm" class="ai-agent-modal-btn is-primary">بریم</button>
                </div>
            </div>
        </div>

        <div id="ai-agent-drawer" class="ai-agent-drawer" hidden>
            <div class="ai-agent-drawer-head">
                <span>گفت‌وگوهای پیشین</span>
                <button type="button" id="ai-agent-drawer-close" class="ai-agent-icon-btn" aria-label="بستن فهرست">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true">
                        <line x1="6" y1="6" x2="18" y2="18"/>
                        <line x1="18" y1="6" x2="6" y2="18"/>
                    </svg>
                </button>
            </div>
            <div id="ai-agent-drawer-list" class="ai-agent-drawer-list"></div>
        </div>

        <div id="ai-agent-messages">
            <div class="ai-message">
                <div class="ai-message-body">
                    سلام 👋 چطور می‌تونم کمکتون کنم؟
                </div>
            </div>
        </div>

        <div id="ai-agent-footer">
            <?php
            /*
            ناحیه پیش‌نمایش عکس‌های انتخاب‌شده توسط کاربر.
            به‌صورت پیش‌فرض مخفی است و با انتخاب حداقل یک عکس کلاس
            has-items می‌گیرد.
            */ ?>
            <div id="ai-agent-attachments" aria-label="عکس‌های پیوست"></div>

            <div class="ai-agent-footer-row">
                <?php
                /*
                دکمه سنجاق (Attach): فایل‌اینپوت مخفی پایین را باز می‌کند.
                فقط عکس می‌پذیرد و چندتایی است؛ سقف تعداد در JS اعمال می‌شود.
                */ ?>
                <button id="ai-agent-attach" title="افزودن عکس" type="button">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <line x1="12" y1="5" x2="12" y2="19"/>
                        <line x1="5" y1="12" x2="19" y2="12"/>
                    </svg>
                    <span class="ai-attach-badge">0</span>
                </button>

                <?php
                /*
                دکمه‌ی ضبط صدا، کنار سنجاق.

                برخلاف نسخه‌های قبل که به Web Speech API مرورگر وابسته بود
                و روی موبایل عمداً خاموش می‌ماند، حالا صدا با MediaRecorder
                ضبط و برای تبدیل به متن به سرور دانی‌چت فرستاده می‌شود
                (اندپوینت POST /speech/transcribe مستندات API)؛ پس این
                دکمه روی موبایل هم کار می‌کند — همان‌جایی که بیشترین
                کاربرِ ویس هست.

                کلاس voice-not-supported را جاوااسکریپت فقط وقتی اضافه
                می‌کند که مرورگر واقعاً MediaRecorder نداشته باشد.
                */ ?>
                <button id="ai-agent-voice" title="ضبط پیام صوتی" type="button" aria-label="ضبط پیام صوتی">
                    <svg class="ai-voice-icon-mic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/>
                        <path d="M19 10v2a7 7 0 0 1-14 0v-2"/>
                        <line x1="12" y1="19" x2="12" y2="23"/>
                        <line x1="8" y1="23" x2="16" y2="23"/>
                    </svg>
                    <svg class="ai-voice-icon-stop" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
                        <rect x="6" y="6" width="12" height="12" rx="2"/>
                    </svg>
                </button>

                <textarea id="ai-agent-input" rows="1" placeholder="پیام خود را بنویسید..."></textarea>

                <?php
                /*
                نوار ضبط صدا: در حالت عادی مخفی است و با شروع ضبط جای‌گزین
                textarea می‌شود (نقطه‌ی پالسی، موج صدا، شمارنده‌ی زمان).
                */ ?>
                <div id="ai-agent-recording-bar" class="ai-agent-recording-bar" aria-hidden="true">
                    <span class="ai-recording-dot" aria-hidden="true"></span>
                    <span class="ai-recording-waveform" aria-hidden="true">
                        <span></span><span></span><span></span><span></span><span></span>
                    </span>
                    <span class="ai-recording-label">در حال ضبط صدا...</span>
                    <span id="ai-agent-recording-timer" class="ai-recording-timer">00:00</span>
                </div>

                <button id="ai-agent-send" title="ارسال پیام" type="button" aria-label="ارسال پیام">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <line x1="12" y1="19" x2="12" y2="5"/>
                        <polyline points="5 12 12 5 19 12"/>
                    </svg>
                </button>
            </div>

            <?php
            /*
            فایل‌اینپوت مخفی: فقط عکس، چندتایی. کلیک روی دکمه‌ی سنجاق
            کلیکِ این المان را trigger می‌کند تا پنجره‌ی Browse باز شود.
            */ ?>
            <input type="file" id="ai-agent-file-input" accept="image/*" multiple hidden />

            <?php
            /*
            جای فیلد پیام، بعد از انتقال گفت‌وگو به بله.

            فیلد فقط غیرفعال نمی‌شود؛ کلاً برداشته می‌شود و این نوار
            جایش می‌نشیند. یک فیلد خاکستر‌شده هنوز دعوت به نوشتن است،
            و پیامی که این‌جا نوشته شود دیگر هیچ‌کس نمی‌خواندش.
            */ ?>
            <div id="ai-agent-transferred-bar" class="ai-agent-transferred-bar" hidden>
                <span>ادامه‌ی گفت‌وگو به بله منتقل شد. برای شروع گفت‌وگوی تازه، دکمه‌ی + بالا را بزنید.</span>
            </div>

            <?php
            /*
            معرفی دانیچَت: یک خط ریز و وسط‌چین زیر فیلدِ پیام، همیشه.
            تبلیغ نیست، فقط امضای کوچک همان چیزی که این پنجره را ساخته.
            */ ?>
            <p id="ai-agent-footer-credit">
                چت‌بات هوشمند پشتیبان خودت رو بساز —
                <a href="https://dunichat.ir" target="_blank" rel="noopener">dunichat.ir</a>
            </p>
        </div>
    </div>
</div>

<?php
}
add_action('wp_footer','ai_agent_widget');
