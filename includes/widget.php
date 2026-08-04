<?php

if (!defined('ABSPATH')) exit;

function ai_agent_widget(){
?>

<?php
    // مسیر تصاویر (فاویکون فقط برای آواتار پیام‌های ربات، لوگو برای دکمه شناور، و بک‌گراند‌های دارک/لایت مود)
    $ai_agent_logo     = esc_url(AI_AGENT_URL . 'assets/images/logo.png');
    $ai_agent_favicon  = esc_url(AI_AGENT_URL . 'assets/images/favicon.png');
    $ai_agent_bg_light = esc_url(AI_AGENT_URL . 'assets/images/light-chat.jpg');
    $ai_agent_bg_dark  = esc_url(AI_AGENT_URL . 'assets/images/dark-chat.jpg');
?>
<div id="ai-agent" style="--ai-agent-favicon:url('<?php echo $ai_agent_favicon; ?>'); --ai-agent-bg-light:url('<?php echo $ai_agent_bg_light; ?>'); --ai-agent-bg-dark:url('<?php echo $ai_agent_bg_dark; ?>');">
    <div id="ai-agent-button" title="پشتیبانی هوشمند">
        <img src="<?php echo $ai_agent_logo;?>" alt="AI Logo">
    </div>

    <div id="ai-agent-window">
        <div id="ai-agent-header">
            <div class="ai-agent-header-title">
                <span class="ai-theme-toggle" title="تغییر حالت شب/روز">
                    <span class="ai-theme-icon ai-theme-icon-moon">
                        <svg viewBox="0 0 24 24" fill="currentColor"><path d="M20 14.5A8.5 8.5 0 1 1 9.5 4a7 7 0 1 0 10.5 10.5z"/></svg>
                    </span>
                    <span class="ai-theme-icon ai-theme-icon-sun">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
                            <circle cx="12" cy="12" r="5" fill="currentColor" stroke="none"/>
                            <line x1="12" y1="1" x2="12" y2="4"/>
                            <line x1="12" y1="20" x2="12" y2="23"/>
                            <line x1="4.2" y1="4.2" x2="6.3" y2="6.3"/>
                            <line x1="17.7" y1="17.7" x2="19.8" y2="19.8"/>
                            <line x1="1" y1="12" x2="4" y2="12"/>
                            <line x1="20" y1="12" x2="23" y2="12"/>
                            <line x1="4.2" y1="19.8" x2="6.3" y2="17.7"/>
                            <line x1="17.7" y1="6.3" x2="19.8" y2="4.2"/>
                        </svg>
                    </span>
                </span>
                دانیچَت
            </div>
            <div class="ai-agent-header-actions">
                <span id="ai-agent-new-chat" title="چت جدید">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="12" y1="5" x2="12" y2="19"/>
                        <line x1="5" y1="12" x2="19" y2="12"/>
                    </svg>
                </span>
                <span id="ai-agent-close">✕</span>
            </div>
        </div>

        <div id="ai-agent-messages">
            <div class="ai-message">
                <div class="ai-message-body">
                    سلام 👋 چطور می‌تونم کمکتون کنم؟
                </div>
            </div>
        </div>

        <div id="ai-agent-footer">
            <textarea id="ai-agent-input" placeholder="پیام خود را بنویسید..."></textarea>
            <button id="ai-agent-send" title="ارسال پیام" type="button">
                <img src="<?php echo esc_url(AI_AGENT_URL . 'assets/images/send.svg'); ?>" alt="ارسال" />
            </button>
        </div>
    </div>
</div>

<?php
}
add_action('wp_footer','ai_agent_widget');