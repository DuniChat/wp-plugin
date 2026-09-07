<?php

if (!defined('ABSPATH')) exit;

function ai_agent_widget(){

    $settings = ai_agent_get_settings();

    /*
    مسیر تصاویر. عکس‌های پس‌زمینه‌ی چت (light-chat.jpg / dark-chat.jpg)
    دیگر استفاده نمی‌شوند: یک عکس پشت متنِ گفت‌وگو، خواندن را سخت‌تر
    می‌کرد و هیچ اطلاعاتی اضافه نمی‌کرد. پس‌زمینه حالا یک رنگ ساده است
    که مدیر در تنظیمات افزونه انتخاب می‌کند.
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

        سه دکمه، هر سه هم‌اندازه. قبلاً ضربدرِ بستن یک کاراکتر متنی بود و
        کنار دکمه‌های SVG بزرگ‌تر و ناهم‌تراز می‌نشست؛ حالا هر سه یک SVG
        در یک دکمه‌ی ۳۲ در ۳۲ هستند.

        آیکون ماه/خورشید حذف شد. تم دیگر انتخابِ بازدیدکننده نیست — از
        سایت گرفته می‌شود و مدیر در تنظیمات افزونه قفلش می‌کند.
        ============================================
        */ ?>
        <div id="ai-agent-header">
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
        تمام‌صفحه است و جایی برای ستون دوم وجود ندارد.
        ============================================
        */ ?>
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

        <div id="ai-agent-messages"></div>

        <div id="ai-agent-footer">
            <?php
            /*
            پیشنهادهای ادامه‌ی گفت‌وگو. بعد از هر پاسخ، سرور یکی دو سؤال
            بعدی را می‌فرستد و این‌جا به‌صورت تراشه نمایش داده می‌شوند؛
            کلیک روی هرکدام همان متن را می‌فرستد.
            */ ?>
            <div id="ai-agent-suggestions" class="ai-agent-suggestions" hidden></div>

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
                دکمه‌ی ورودی صوتی فعلاً از رابط کاربری برداشته شده (کدش
                کامنت شده، نه حذف — دوباره فعالش می‌کنیم). کد جاوااسکریپت
                مربوطه در ai-agent.js دست‌نخورده مانده و به‌محض نبودِ
                #ai-agent-voice در صفحه، بی‌اثر باقی می‌ماند.

                <button id="ai-agent-voice" title="ورودی صوتی" type="button" aria-label="ورودی صوتی">
                    <svg class="ai-voice-icon-mic" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                        <path d="M12 1a3 3 0 0 0-3 3v8a3 3 0 0 0 6 0V4a3 3 0 0 0-3-3z"/>
                        <path d="M19 10v2a7 7 0 0 1-14 0v-2"/>
                        <line x1="12" y1="19" x2="12" y2="23"/>
                        <line x1="8" y1="23" x2="16" y2="23"/>
                    </svg>
                    <svg class="ai-voice-icon-stop" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" style="display:none;">
                        <rect x="6" y="6" width="12" height="12" rx="2"/>
                    </svg>
                </button>
                */
                ?>

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
            معرفی دانیچَت: یک خط ریز و وسط‌چین زیر فیلدِ پیام، همیشه —
            نه فقط در صفحه‌ی شروع. تبلیغ نیست، فقط امضای کوچک همان چیزی
            که این پنجره را ساخته.
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
