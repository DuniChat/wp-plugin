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

        // فرمت‌بندی مبلغ ریالی با جداکننده‌ی هزارگان (مثال: 150000 → 150,000 ریال)
        function aiAgentFormatIrr(amount) {
            var n = Number(amount);
            if (isNaN(n)) return String(amount) + ' ریال';
            return n.toLocaleString('en-US') + ' ریال';
        }

        function aiAgentRenderModels(models) {
            var $list = $('#ai-agent-models-list');
            $list.empty();

            if (!models || !models.length) {
                $list.append('<div class="ai-agent-combobox-empty">موردی یافت نشد</div>');
                aiAgentComboboxOpen();
                return;
            }

            $.each(models, function(i, model) {
                var value    = aiAgentGetModelValue(model);
                var label    = aiAgentGetModelLabel(model);
                var provider = (model && typeof model === 'object' && model.provider) ? model.provider : '';

                // استایل‌ها به‌طور کامل از SettingsStyles.css استفاده می‌کنند؛ این‌جا فقط
                // ساختار DOM ساخته می‌شود تا هم نمایش یکدست باشد و هم hover از طریق CSS.
                var $item = $('<div class="ai-agent-model-item"></div>').attr('data-value', value);
                $item.append($('<div></div>').text(label));
                $item.append($('<div></div>').text(value + (provider ? ' · ' + provider : '')));

                // نمایش قیمت ورودی و خروجی مدل (به ازای هر ۱ میلیون توکن) تا کاربر بهتر انتخاب کند
                if (model && typeof model === 'object') {
                    var hasInPrice  = typeof model.system_input_price_irr_per_1m !== 'undefined' && model.system_input_price_irr_per_1m !== null;
                    var hasOutPrice = typeof model.system_output_price_irr_per_1m !== 'undefined' && model.system_output_price_irr_per_1m !== null;

                    if (hasInPrice || hasOutPrice) {
                        var priceParts = [];
                        if (hasInPrice)  priceParts.push('ورودی: ' + aiAgentFormatIrr(model.system_input_price_irr_per_1m));
                        if (hasOutPrice) priceParts.push('خروجی: ' + aiAgentFormatIrr(model.system_output_price_irr_per_1m));

                        $item.append($('<div></div>').text(priceParts.join(' · ') + ' (به ازای هر ۱ میلیون توکن)'));
                    }
                }

                $list.append($item);
            });

            // «بارگذاری بیشتر» به‌صورت یک ردیف داخل خودِ لیست کشویی (کومبوباکس) نمایش داده می‌شود؛
            // نه به‌عنوان یک دکمه‌ی جدا بیرون از لیست. اگر تعداد نتایج به سقف limit فعلی رسیده باشد،
            // یعنی احتمالاً نتایج بیشتری هم وجود دارد.
            if (models.length >= aiAgentModelsLimit) {
                var $loadMore = $('<div class="ai-agent-model-item ai-agent-model-loadmore"></div>').text('بارگذاری بیشتر...');
                $list.append($loadMore);
            }

            aiAgentComboboxOpen();
        }

        // ----- کنترل باز/بسته شدن کومبوباکس -----
        // این توابع کلاس‌های is-open را روی کنترلر و لیست اضافه/حذف می‌کنند تا
        // هم فلش دکمه‌ی کشویی بچرخد و هم لیست نمایش داده شود.
        function aiAgentComboboxOpen() {
            $('#ai-agent-combobox').addClass('is-open');
            $('#ai-agent-models-list').addClass('is-open');
        }
        function aiAgentComboboxClose() {
            $('#ai-agent-combobox').removeClass('is-open');
            $('#ai-agent-models-list').removeClass('is-open');
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
                    } else {
                        $('#ai-agent-models-list').empty().append('<div class="ai-agent-combobox-error">' + (response.data && response.data.message ? response.data.message : 'خطا در دریافت لیست مدل‌ها') + '</div>');
                        aiAgentComboboxOpen();
                    }
                },
                error: function(jqXHR, textStatus) {
                    if (textStatus === 'abort') return; // درخواست عمداً لغو شده، خطا نیست
                    if (reqId !== aiAgentModelsReqId) return;
                    $('#ai-agent-models-list').empty().append('<div class="ai-agent-combobox-error">خطا در برقراری ارتباط با سرور</div>');
                    aiAgentComboboxOpen();
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

        // دکمه‌ی کشویی (فلش): باز/بسته کردن لیست به‌صورت toggle.
        // این کار حس یک کومبوباکس واقعی را به کاربر می‌دهد: می‌تواند روی دکمه کلیک
        // کند تا منوی کشویی باز شود یا مستقیماً داخل فیلد تایپ کند تا جستجو اجرا شود.
        $('#ai-agent-combobox-toggle').on('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            if ($('#ai-agent-models-list').hasClass('is-open')) {
                aiAgentComboboxClose();
            } else {
                if ($('#ai-agent-models-list').children().length > 0) {
                    aiAgentComboboxOpen();
                } else {
                    aiAgentModelsQuery = $('#ai_agent_model_search').val();
                    aiAgentModelsLimit = 10;
                    aiAgentLoadModels();
                }
                $('#ai_agent_model_search').focus();
            }
        });

        // با فوکوس روی باکس: اگر لیست از قبل بارگذاری شده، همان را نشان بده (بدون کوئری مجدد)
        // و فقط اگر خالی است، یک‌بار بارگذاری کن. این از ریست شدن لیست هنگام اسکرول/فوکوس مجدد جلوگیری می‌کند
        $('#ai_agent_model_search').on('focus', function() {
            if ($('#ai-agent-models-list').children().length > 0) {
                aiAgentComboboxOpen();
            } else {
                aiAgentModelsQuery = $(this).val();
                aiAgentModelsLimit = 10;
                aiAgentLoadModels();
            }
        });

        // ردیف «بارگذاری بیشتر» حالا داخل خودِ لیست کشویی است؛ کلیک روی آن نباید
        // به‌عنوان انتخاب مدل تلقی شود و نباید باعث بسته شدن لیست شود (چون خودش هم
        // درون #ai-agent-models-list است).
        $(document).on('click', '.ai-agent-model-loadmore', function(e) {
            e.preventDefault();
            e.stopPropagation();
            aiAgentModelsLimit += 10;
            aiAgentLoadModels();
        });

        $(document).on('click', '.ai-agent-model-item:not(.ai-agent-model-loadmore)', function() {
            var value = $(this).attr('data-value');
            $('#ai_agent_model').val(value);
            $('#ai_agent_model_search').val(value);
            $('#ai-agent-model-current').text(value);
            aiAgentComboboxClose();
        });

        // بستن لیست با کلیک بیرون از کومبوباکس (شامل ردیف «بارگذاری بیشتر» که حالا داخل خودِ لیست است)
        $(document).on('click', function(e) {
            if (!$(e.target).closest('#ai-agent-combobox').length) {
                aiAgentComboboxClose();
            }
        });

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

        // اجرای خودکار هنگام باز شدن صفحه‌ی تنظیمات (اگر این بخش در صفحه موجود باشد)
        if ($('#ai-agent-wallet-balance-value').length) {
            aiAgentLoadWalletBalance(false);
        }

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
        $('#ai-agent-sync-all-btn').on('click', function(e) {
            e.preventDefault();

            // تأیید کاربر قبل از انجام سینک کامل (چون ممکن است حجم بالایی ارسال شود)
            if (!confirm('آیا مطمئن هستید؟ این عملیات بدون توجه به سینک قبلی، تمام محتوای تیک‌خورده را از ابتدا به سرور ارسال می‌کند. برای فروشگاه‌های با محتوای زیاد ممکن است زمان‌بر باشد.')) {
                return;
            }

            var $btn = $(this);
            var $status = $('#ai-agent-sync-all-status');
            var token = $('#ai_agent_sync_all_nonce_field').val();

            $btn.prop('disabled', true).addClass('is-loading');
            $status.text('در حال ارسال تمام محتوا به سرور...');

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
                        var summary = d.new_count + ' مورد با موفقیت به سرور ارسال شد';
                        if (d.total_count) {
                            summary += ' (از مجموع ' + d.total_count + ' مورد)';
                        }
                        $status.html('<strong>' + summary + '</strong><br><small style="color:#666;font-weight:normal;">' + d.message + '</small>');
                        if (d.last_sync_time) {
                            $status.append('<br><small style="color:#888;font-weight:normal;">زمان سینک کامل: ' + d.last_sync_time + '</small>');
                            // به‌روزرسانی فیلد «آخرین سینک کامل» در بلوک آخرین همگام‌سازی
                            $('.ai-agent-last-sync-item').last().find('.ai-agent-last-sync-value').text(d.last_sync_time);
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
                        $statusEl.text('وضعیت با موفقیت به‌روزرسانی شد.');
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
            msgPage: 1,
            msgPageSize: 10,
            msgTotal: 0,
            msgHasNext: false,
            msgLoading: false,

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

                // بارگذاری اولیه
                self.loadSessions();
            },

            syncPageUI: function() {
                // اطلاعات صفحه
                $('#ai-agent-sessions-page-info').text(
                    this.total > 0
                        ? 'صفحه ' + this.currentPage + ' از ' + Math.ceil(this.total / this.pageSize)
                        : ''
                );
                $('#ai-agent-sessions-total-info').text(
                    this.total > 0 ? 'مجموع: ' + this.total + ' جلسه' : ''
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
                    $list.html('<div class="ai-agent-sessions-empty">هیچ جلسه‌ای یافت نشد.</div>');
                    return;
                }

                for (var i = 0; i < items.length; i++) {
                    (function(item) {
                        var created = item.created_at || '';
                        // تبدیل تاریخ به فرمت قابل نمایش
                        if (created) {
                            var dt = new Date(created);
                            if (!isNaN(dt.getTime())) {
                                var jalali = self.toJalali(dt.getFullYear(), dt.getMonth() + 1, dt.getDate());
                                created = jalali + ' ' +
                                    String(dt.getHours()).padStart(2, '0') + ':' +
                                    String(dt.getMinutes()).padStart(2, '0');
                            }
                        }

                        var statusLabel = self.getStatusLabel(item.status);

                        var $item = $('<div class="ai-agent-session-item"></div>');
                        var $header = $('<div class="ai-agent-session-header"></div>');
                        var $arrow = $('<span class="ai-agent-session-arrow">&#9654;</span>');
                        var $idSpan = $('<code class="ai-agent-session-id"></code>').text(item.id);
                        var $dateSpan = $('<span class="ai-agent-session-date"></span>').text(created);
                        // رنگ بج وضعیت اکنون از طریق CSS و ویژگی data-status اعمال می‌شود
                        var $statusBadge = $('<span class="ai-agent-session-status-badge"></span>')
                            .attr('data-status', item.status || '')
                            .text(statusLabel);

                        $header.append($arrow).append(' ').append($idSpan).append(' ').append($dateSpan).append(' ').append($statusBadge);

                        var $body = $('<div class="ai-agent-session-body" style="display:none;"></div>');

                        $header.on('click', function() {
                            if ($body.is(':visible')) {
                                // بستن آکاردئون
                                $body.slideUp(250);
                                $arrow.html('&#9654;');
                                $item.removeClass('ai-agent-session-open');
                                self.openSessionId = null;
                            } else {
                                // بستن تمام آکاردئون‌های باز
                                $list.find('.ai-agent-session-body:visible').slideUp(250);
                                $list.find('.ai-agent-session-arrow').html('&#9654;');
                                $list.find('.ai-agent-session-item').removeClass('ai-agent-session-open');

                                // باز کردن این مورد
                                $body.slideDown(250);
                                $arrow.html('&#9660;');
                                $item.addClass('ai-agent-session-open');
                                self.openSessionId = item.id;
                                self.loadMessages(item.id, $body, item.status);
                            }
                        });

                        $item.append($header).append($body);
                        $list.append($item);
                    })(items[i]);
                }
            },

            loadMessages: function(sessionId, $container, sessionStatus) {
                var self = this;
                if (self.msgLoading) return;
                self.msgLoading = true;
                self.msgPage = 1;

                $container.html('<div class="ai-agent-msg-loading">در حال بارگذاری پیام‌ها...</div>');

                $.ajax({
                    url: ajaxurl,
                    method: 'GET',
                    data: {
                        action: 'ai_agent_get_session_messages',
                        nonce: $('#ai_agent_chat_sessions_nonce_field').val(),
                        session_id: sessionId,
                        page: self.msgPage,
                        page_size: self.msgPageSize
                    },
                    success: function(response) {
                        self.msgLoading = false;
                        if (response.success) {
                            var d = response.data;
                            self.msgTotal = d.total || 0;
                            self.msgHasNext = d.has_next || false;
                            self.renderMessages($container, d.items || [], sessionId, sessionStatus);
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
                    $container.html('<div class="ai-agent-sessions-empty">پیامی یافت نشد.</div>');
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

                // صفحه‌بندی پیام‌ها
                if (self.msgHasNext) {
                    var $msgNav = $('<div class="ai-agent-msg-nav" style="margin-top:10px;text-align:center;"></div>');
                    var $nextBtn = $('<button type="button" class="button button-secondary button-small"></button>').text('مشاهده پیام‌های قدیمی‌تر');
                    $nextBtn.on('click', function() {
                        self.msgPage++;
                        self.loadMoreMessages(sessionId, $container);
                    });
                    $msgNav.append($nextBtn);
                    $container.append($msgNav);
                }

                var $totalInfo = $('<div class="ai-agent-msg-total" style="margin-top:6px;text-align:center;"></div>');
                $totalInfo.text(self.msgTotal + ' پیام');
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

            این متد از هر دو renderMessages و loadMoreMessages استفاده می‌شود
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
                    var dt = new Date(created);
                    if (!isNaN(dt.getTime())) {
                        timeStr = String(dt.getHours()).padStart(2, '0') + ':' +
                                  String(dt.getMinutes()).padStart(2, '0') + ':' +
                                  String(dt.getSeconds()).padStart(2, '0');
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

                var $msgContent = $('<div class="ai-agent-msg-content"></div>').text(content);

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
                var $sendBtn = $('<button type="button" class="button button-primary ai-agent-session-send-btn">ارسال پاسخ</button>');
                var $returnBotBtn = $('<button type="button" class="button button-secondary ai-agent-session-return-bot-btn">بازگردانی چت به ربات</button>');
                var $closeBtn = $('<button type="button" class="button button-secondary ai-agent-session-close-btn">پایان چت</button>');
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
                                var $content = $('<div class="ai-agent-msg-content"></div>').text(text);
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
                    بدنه: {"additionalProp1": {}}

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

            loadMoreMessages: function(sessionId, $container) {
                var self = this;
                if (self.msgLoading) return;
                self.msgLoading = true;

                // حذف دکمه‌ی صفحه‌بندی قبلی
                $container.find('.ai-agent-msg-nav').remove();

                // indicator
                var $indicator = $('<div class="ai-agent-msg-loading">در حال بارگذاری...</div>');
                $container.find('.ai-agent-msg-total').before($indicator);

                $.ajax({
                    url: ajaxurl,
                    method: 'GET',
                    data: {
                        action: 'ai_agent_get_session_messages',
                        nonce: $('#ai_agent_chat_sessions_nonce_field').val(),
                        session_id: sessionId,
                        page: self.msgPage,
                        page_size: self.msgPageSize
                    },
                    success: function(response) {
                        self.msgLoading = false;
                        $indicator.remove();
                        if (response.success) {
                            var d = response.data;
                            self.msgHasNext = d.has_next || false;
                            var newMessages = d.items || [];
                            var $chatArea = $container.find('.ai-agent-chat-messages');

                            // جمع‌آوری placeholderهای جدید برای lazy load بعدی
                            var $newPlaceholders = $();

                            // اضافه کردن پیام‌های جدید به ابتدا (پیام‌های قدیمی‌تر)
                            for (var i = newMessages.length - 1; i >= 0; i--) {
                                var msg = newMessages[i];
                                var $bubble = self.buildMessageBubble(msg);
                                $chatArea.prepend($bubble);
                                $newPlaceholders = $newPlaceholders.add($bubble.find('.ai-agent-msg-image-placeholder.is-loading'));
                            }

                            // راه‌اندازی lazy load برای placeholderهای تازه‌اضافه‌شده
                            if ($newPlaceholders.length > 0) {
                                self.startLazyImageLoadingFor($newPlaceholders, $chatArea);
                            }

                            // صفحه‌بندی جدید اگر.hasNext
                            if (self.msgHasNext) {
                                var $msgNav = $('<div class="ai-agent-msg-nav" style="margin-top:10px;text-align:center;"></div>');
                                var $nextBtn = $('<button type="button" class="button button-secondary button-small"></button>').text('مشاهده پیام‌های قدیمی‌تر');
                                $nextBtn.on('click', function() {
                                    self.msgPage++;
                                    self.loadMoreMessages(sessionId, $container);
                                });
                                $msgNav.append($nextBtn);
                                $container.find('.ai-agent-msg-total').before($msgNav);
                            }
                        }
                    },
                    error: function() {
                        self.msgLoading = false;
                        $indicator.html('<span style="color:#b91c1c;">خطا در بارگذاری.</span>');
                    }
                });
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

            // تبدیل میلادی به شمسی ساده (بدون نیاز به کتابخانه‌ی خارجی)
            toJalali: function(gy, gm, gd) {
                var g_d_m, jy, jm, jd, gy2, days;
                gy2 = (gm > 2) ? (gy + 1) : gy;
                days = 355666 + (365 * gy) + (Math.floor((gy2 + 3) / 4)) - (Math.floor((gy2 + 99) / 100)) + (Math.floor((gy2 + 399) / 400)) + gd + ((gm < 3) ? (gm - 1) * 31 : ((gm - 2) * 30 + ((gm > 6) ? 6 : 0)));
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