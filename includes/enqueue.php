<?php

if (!defined('ABSPATH')) exit;


/*
============================================
بارگذاری استایل و اسکریپت ویجت چت و پاس‌دادن تنظیمات به JavaScript
============================================
*/
function ai_agent_enqueue(){

    $settings = ai_agent_get_settings();

    wp_enqueue_style(
        'ai-agent-css',
        AI_AGENT_URL.'assets/css/ai-agent.css'
    );

    wp_enqueue_script(
        'ai-agent-js',
        AI_AGENT_URL.'assets/js/ai-agent.js',
        array('jquery'),
        null,
        true
    );

    wp_localize_script(
    'ai-agent-js',
    'ai_agent',
    array(
        'ajax_url'         => admin_url('admin-ajax.php'),
        'timeout'          => intval($settings['timeout']) * 1000,
        'color'            => $settings['color'],
        'session_cookie'   => AI_AGENT_SESSION_COOKIE,
        'escalated_cookie' => AI_AGENT_ESCALATED_COOKIE,
    )
);

    // اعمال رنگ انتخابی کاربر روی ویجت
    $color = esc_attr($settings['color']);

    $custom_css = "
        #ai-agent-button{ background:{$color}; }
        #ai-agent-header{ background:{$color}; }
        #ai-agent-send{ background:{$color}; }
        .user-message{ background:{$color}; }
    ";

    wp_add_inline_style('ai-agent-css', $custom_css);

}

add_action('wp_enqueue_scripts','ai_agent_enqueue');
