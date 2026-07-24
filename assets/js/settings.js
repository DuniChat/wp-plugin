/*
============================================
اسکریپت پنل تنظیمات افزونه هم‌گفتار (AI Agent)
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
        $('.ai-agent-color-field').wpColorPicker();

        // ----- دکمه نمایش/مخفی کردن API Key -----
        $('#ai-agent-toggle-api-key').on('click', function(){
            var $input = $('#ai_agent_api_key');
            if ($input.attr('type') === 'password') {
                $input.attr('type', 'text');
                $(this).text('مخفی');
            } else {
                $input.attr('type', 'password');
                $(this).text('نمایش');
            }
        });

        // ----- جستجو و انتخاب مدل هوش مصنوعی از سرور اختصاصی -----
        var aiAgentModelsLimit = 10;
        var aiAgentModelsQuery = '';
        var aiAgentModelsTimer = null;
        var aiAgentModelsXhr = null;
        var aiAgentModelsReqId = 0;

        function aiAgentGetModelLabel(model) {
            if (typeof model === 'string') return model;
            if (model && typeof model === 'object') {
                return model.name || model.id || model.title || JSON.stringify(model);
            }
            return String(model);
        }

        function aiAgentGetModelValue(model) {
            if (typeof model === 'string') return model;
            if (model && typeof model === 'object') {
                return model.id || model.name || model.title || '';
            }
            return String(model);
        }

        function aiAgentRenderModels(models) {
            var $list = $('#ai-agent-models-list');
            $list.empty();

            if (!models || !models.length) {
                $list.append('<div style="padding:8px 10px;color:#888;">موردی یافت نشد</div>');
                $list.show();
                return;
            }

            $.each(models, function(i, model) {
                var value    = aiAgentGetModelValue(model);
                var label    = aiAgentGetModelLabel(model);
                var provider = (model && typeof model === 'object' && model.provider) ? model.provider : '';

                var $item = $('<div class="ai-agent-model-item" style="padding:8px 10px;cursor:pointer;border-bottom:1px solid #f0f0f1;"></div>').attr('data-value', value);
                $item.append($('<div></div>').css({fontWeight:'600'}).text(label));
                $item.append($('<div></div>').css({fontSize:'11px', color:'#888', marginTop:'2px', direction:'ltr', textAlign:'right'}).text(value + (provider ? ' · ' + provider : '')));

                $item.on('mouseenter', function(){ $(this).css('background', '#f0f6fc'); });
                $item.on('mouseleave', function(){ $(this).css('background', '#fff'); });
                $list.append($item);
            });
            $list.show();
        }

        function aiAgentLoadModels() {
            // درخواست قبلی که هنوز در حال اجراست را لغو کن تا پاسخ‌های دیرهنگام، لیست جدید را خراب نکنند
            if (aiAgentModelsXhr && aiAgentModelsXhr.readyState !== 4) {
                aiAgentModelsXhr.abort();
            }
            var reqId = ++aiAgentModelsReqId;

            aiAgentModelsXhr = $.ajax({
                url: ajaxurl,
                method: 'GET',
                data: {
                    action: 'ai_agent_search_models',
                    nonce: $('#ai_agent_models_nonce_field').val(),
                    q: aiAgentModelsQuery,
                    limit: aiAgentModelsLimit
                },
                success: function(response) {
                    if (reqId !== aiAgentModelsReqId) return; // یک پاسخ قدیمی‌تر است، نادیده بگیر
                    if (response.success) {
                        var models = response.data.models || [];
                        aiAgentRenderModels(models);
                        $('#ai-agent-load-more').toggle(models.length >= aiAgentModelsLimit);
                    } else {
                        $('#ai-agent-models-list').empty().append('<div style="padding:8px 10px;color:#b91c1c;">' + (response.data && response.data.message ? response.data.message : 'خطا در دریافت لیست مدل‌ها') + '</div>').show();
                        $('#ai-agent-load-more').hide();
                    }
                },
                error: function(jqXHR, textStatus) {
                    if (textStatus === 'abort') return; // درخواست عمداً لغو شده، خطا نیست
                    if (reqId !== aiAgentModelsReqId) return;
                    $('#ai-agent-models-list').empty().append('<div style="padding:8px 10px;color:#b91c1c;">خطا در برقراری ارتباط با سرور</div>').show();
                    $('#ai-agent-load-more').hide();
                }
            });
        }

        // فقط هنگام تایپ واقعی، جستجوی جدید را با تاخیر (debounce) اجرا کن
        $('#ai_agent_model_search').on('input', function() {
            aiAgentModelsQuery = $(this).val();
            aiAgentModelsLimit = 10;
            clearTimeout(aiAgentModelsTimer);
            aiAgentModelsTimer = setTimeout(aiAgentLoadModels, 300);
        });

        // با فوکوس روی باکس: اگر لیست از قبل بارگذاری شده، همان را نشان بده (بدون کوئری مجدد)
        // و فقط اگر خالی است، یک‌بار بارگذاری کن. این از ریست شدن لیست هنگام اسکرول/فوکوس مجدد جلوگیری می‌کند
        $('#ai_agent_model_search').on('focus', function() {
            if ($('#ai-agent-models-list').children().length > 0) {
                $('#ai-agent-models-list').show();
            } else {
                aiAgentModelsQuery = $(this).val();
                aiAgentModelsLimit = 10;
                aiAgentLoadModels();
            }
        });

        $('#ai-agent-load-more').on('click', function(e) {
            e.preventDefault();
            aiAgentModelsLimit += 10;
            aiAgentLoadModels();
        });

        $(document).on('click', '.ai-agent-model-item', function() {
            var value = $(this).attr('data-value');
            $('#ai_agent_model').val(value);
            $('#ai_agent_model_search').val(value);
            $('#ai-agent-model-current').text(value);
            $('#ai-agent-models-list').hide();
        });

        // بستن لیست با کلیک بیرون از باکس (دکمه «بارگذاری بیشتر» نباید باعث بسته شدن لیست شود)
        $(document).on('click', function(e) {
            if (!$(e.target).closest('#ai_agent_model_search, #ai-agent-models-list, #ai-agent-load-more').length) {
                $('#ai-agent-models-list').hide();
            }
        });

        // ----- دکمه «بارگذاری اطلاعات از سرور» (بازخوانی تنظیمات، نه سینک داده‌های امبدینگ) -----
        $('#ai-agent-reload-settings-btn').on('click', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var $status = $('#ai-agent-reload-settings-status');
            var token = $('#ai_agent_reload_settings_nonce_field').val();

            $btn.prop('disabled', true).text('در حال بارگذاری...');
            $status.css('color', '#16a34a').text('در حال دریافت آخرین مقادیر از سرور...');

            $.ajax({
                url: ajaxurl,
                method: 'POST',
                data: {
                    action: 'ai_agent_reload_settings',
                    nonce: token
                },
                success: function(response) {
                    if (response.success) {
                        $status.css('color', 'green').text('با موفقیت بازخوانی شد؛ در حال بارگذاری مجدد صفحه...');
                        // بارگذاری مجدد صفحه تا تمام فیلدهای فرم (از جمله بخش‌های فقط‌خواندنی
                        // مثل سقف پیام روزانه و وضعیت‌های مجاز) با مقادیر تازه از سرور نمایش داده شوند
                        setTimeout(function(){ window.location.reload(); }, 700);
                    } else {
                        $btn.prop('disabled', false).text('بارگذاری اطلاعات از سرور');
                        $status.css('color', '#b91c1c').text((response.data && response.data.message) ? response.data.message : 'خطا در بازخوانی تنظیمات از سرور.');
                    }
                },
                error: function() {
                    $btn.prop('disabled', false).text('بارگذاری اطلاعات از سرور');
                    $status.css('color', '#b91c1c').text('خطای غیرمنتظره در ارتباط با پردازشگر محلی وردپرس رخ داد.');
                }
            });
        });

        $('#ai-agent-sync-btn').on('click', function(e) {
            e.preventDefault();
            var $btn = $(this);
            var $status = $('#ai-agent-sync-status');
            var token = $('#ai_agent_sync_nonce_field').val();

            $btn.prop('disabled', true).text('در حال همگام‌سازی...');
            $status.css('color', '#dc2626').text('لطفاً شکیبا باشید؛ در حال بررسی محتوای جدید و ارسال به سرور همگام‌سازی...');

            $.ajax({
                url: ajaxurl,
                method: 'POST',
                data: {
                    action: 'ai_agent_sync_data',
                    nonce: token
                },
                success: function(response) {
                    $btn.prop('disabled', false).text('همگام‌سازی اطلاعات (Sync Now)');
                    if (response.success) {
                        var d = response.data;
                        // ساخت پیام خلاصه با جزئیات دقیق
                        var summary = '';
                        if (d.new_count > 0 && d.deleted_count > 0) {
                            summary = d.new_count + ' مورد جدید اضافه و ' + d.deleted_count + ' مورد حذف شد';
                        } else if (d.new_count > 0) {
                            summary = d.new_count + ' مورد جدید اضافه شد';
                        } else if (d.deleted_count > 0) {
                            summary = d.deleted_count + ' مورد حذف شد';
                        } else {
                            summary = 'هیچ محتوای جدیدی یافت نشد';
                        }
                        if (d.total_count) {
                            summary += ' (مجموع محتوای فعلی: ' + d.total_count + ' مورد)';
                        }
                        $status.css('color', 'green').html('<strong>' + summary + '</strong><br><small style="color:#666;font-weight:normal;">' + d.message + '</small>');
                        // به‌روزرسانی تاریخ آخرین سینک در صفحه بدون رفرش
                        if (d.last_sync_time) {
                            // فقط نمایش را به‌روز می‌کنیم؛ برای اطمینان کامل کاربر می‌تواند صفحه را رفرش کند
                            $status.append('<br><small style="color:#888;font-weight:normal;">زمان سینک: ' + d.last_sync_time + '</small>');
                        }
                    } else {
                        var msg = (response.data && response.data.message) ? response.data.message : 'خطا در همگام‌سازی.';
                        $status.css('color', '#b91c1c').text(msg);
                    }
                },
                error: function() {
                    $btn.prop('disabled', false).text('همگام‌سازی اطلاعات (Sync Now)');
                    $status.css('color', '#b91c1c').text('خطای غیرمنتظره در ارتباط با پردازشگر محلی وردپرس رخ داد.');
                }
            });
        });

        // ----- دکمه‌ی «سینک تمامی محتوا» (Sync All) -----
        $('#ai-agent-sync-all-btn').on('click', function(e) {
            e.preventDefault();

            // تأیید کاربر قبل از انجام سینک کامل (چون ممکن است حجم بالایی ارسال شود)
            if (!confirm('آیا مطمئن هستید؟ این عملیات بدون توجه به سینک قبلی، تمام محتوای تیک‌خورده را از ابتدا به سرور ارسال می‌کند. برای فروشگاه‌های با محتوای زیاد ممکن است زمان‌بر باشد.')) {
                return;
            }

            var $btn = $(this);
            var $status = $('#ai-agent-sync-all-status');
            var token = $('#ai_agent_sync_all_nonce_field').val();

            $btn.prop('disabled', true).text('در حال سینک کامل...');
            $status.css('color', '#dc2626').text('لطفاً شکیبا باشید؛ در حال ارسال تمام محتوا به سرور همگام‌سازی...');

            $.ajax({
                url: ajaxurl,
                method: 'POST',
                data: {
                    action: 'ai_agent_sync_all_data',
                    nonce: token
                },
                success: function(response) {
                    $btn.prop('disabled', false).text('سینک تمامی محتوا');
                    if (response.success) {
                        var d = response.data;
                        var summary = d.new_count + ' مورد با موفقیت به سرور ارسال شد';
                        if (d.total_count) {
                            summary += ' (از مجموع ' + d.total_count + ' مورد)';
                        }
                        $status.css('color', 'green').html('<strong>' + summary + '</strong><br><small style="color:#666;font-weight:normal;">' + d.message + '</small>');
                        if (d.last_sync_time) {
                            $status.append('<br><small style="color:#888;font-weight:normal;">زمان سینک کامل: ' + d.last_sync_time + '</small>');
                        }
                    } else {
                        var msg = (response.data && response.data.message) ? response.data.message : 'خطا در سینک کامل.';
                        $status.css('color', '#b91c1c').text(msg);
                    }
                },
                error: function() {
                    $btn.prop('disabled', false).text('سینک تمامی محتوا');
                    $status.css('color', '#b91c1c').text('خطای غیرمنتظره در ارتباط با پردازشگر محلی وردپرس رخ داد.');
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
            var colors = ['#f59e0b', '#3b82f6', '#16a34a', '#dc2626', '#6b7280'];

            var ctx = document.getElementById('ai-agent-status-chart');
            if (!ctx) return;

            if (aiAgentStatusChart) {
                aiAgentStatusChart.data.datasets[0].data = dataVals;
                aiAgentStatusChart.options.plugins.title.text = 'وضعیت ارسال‌های سینک‌شده (مجموع: ' + (summary.total || 0) + ')';
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
                        borderWidth: 1
                    }]
                },
                options: {
                    responsive: true,
                    plugins: {
                        legend: {
                            position: 'bottom',
                            rtl: true,
                            labels: { font: { family: 'Tahoma', size: 12 } }
                        },
                        title: {
                            display: true,
                            text: 'وضعیت ارسال‌های سینک‌شده (مجموع: ' + (summary.total || 0) + ')',
                            font: { family: 'Tahoma', size: 13 }
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
                $btn.prop('disabled', true).text('در حال استعلام...');
            }
            $statusEl.css('color', '#7c3aed').text('در حال دریافت وضعیت از سرور...');

            $.ajax({
                url: ajaxurl,
                method: 'POST',
                data: {
                    action: 'ai_agent_check_sync_status',
                    nonce: token
                },
                success: function(response) {
                    $btn.prop('disabled', false).text('استعلام وضعیت');
                    if (response.success) {
                        var d = response.data;
                        $statusEl.css('color', 'green').text('وضعیت با موفقیت به‌روزرسانی شد.');
                        if (d.summary) {
                            aiAgentRenderStatusChart(d.summary);
                        }
                    } else {
                        var msg = (response.data && response.data.message) ? response.data.message : 'خطا در دریافت وضعیت.';
                        $statusEl.css('color', '#b91c1c').text(msg);
                    }
                },
                error: function() {
                    $btn.prop('disabled', false).text('استعلام وضعیت');
                    $statusEl.css('color', '#b91c1c').text('خطای غیرمنتظره در ارتباط با پردازشگر محلی وردپرس رخ داد.');
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
    });