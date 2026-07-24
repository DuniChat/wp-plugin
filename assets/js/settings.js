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
                    $list.html('<div class="ai-agent-sessions-empty" style="padding:20px;text-align:center;color:#888;">هیچ جلسه‌ای یافت نشد.</div>');
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
                        var $header = $('<div class="ai-agent-session-header" style="cursor:pointer;"></div>');
                        var $arrow = $('<span class="ai-agent-session-arrow">&#9654;</span>');
                        var $idSpan = $('<code class="ai-agent-session-id"></code>').text(item.id);
                        var $dateSpan = $('<span class="ai-agent-session-date"></span>').text(created);
                        var $statusBadge = $('<span class="ai-agent-session-status-badge"></span>').text(statusLabel);

                        // رنگ بج وضعیت — inline
                        switch (item.status) {
                            case 'pending_human':
                                $statusBadge.css({background:'#fff3cd', color:'#856404'});
                                break;
                            case 'bot':
                                $statusBadge.css({background:'#dbeafe', color:'#1e40af'});
                                break;
                            case 'human':
                                $statusBadge.css({background:'#d4edda', color:'#155724'});
                                break;
                            case 'closed':
                                $statusBadge.css({background:'#f3f4f6', color:'#6b7280'});
                                break;
                            default:
                                $statusBadge.css({background:'#f3f4f6', color:'#6b7280'});
                        }

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

                $container.html('<div class="ai-agent-msg-loading" style="padding:12px;text-align:center;color:#666;">در حال بارگذاری پیام‌ها...</div>');

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
                            $container.html('<div style="padding:12px;color:#b91c1c;">' + msg + '</div>');
                        }
                    },
                    error: function() {
                        self.msgLoading = false;
                        $container.html('<div style="padding:12px;color:#b91c1c;">خطای غیرمنتظره در ارتباط با سرور.</div>');
                    }
                });
            },

            renderMessages: function($container, messages, sessionId, sessionStatus) {
                var self = this;
                $container.empty();

                if (!messages || messages.length === 0) {
                    $container.html('<div style="padding:12px;text-align:center;color:#888;">پیامی یافت نشد.</div>');
                    if (sessionStatus === 'pending_human' || sessionStatus === 'human') {
                        $container.append(self.buildReplyBox(sessionId));
                    }
                    return;
                }

                var $chatArea = $('<div class="ai-agent-chat-messages"></div>');

                for (var i = 0; i < messages.length; i++) {
                    var msg = messages[i];
                    var role = (msg.role || 'user').toLowerCase();
                    var content = msg.content || '';
                    var created = msg.created_at || '';

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

                    $msgBubble.append($msgHeader).append($msgContent);
                    $chatArea.append($msgBubble);
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
                var $closeBtn = $('<button type="button" class="button button-secondary ai-agent-session-close-btn">پایان چت</button>');
                var $statusSpan = $('<span class="ai-agent-session-reply-status"></span>');

                $actionsRow.append($sendBtn).append($closeBtn).append($statusSpan);
                $wrap.append($textarea).append($actionsRow);

                function sendReply() {
                    var text = $textarea.val().trim();
                    if (!text) {
                        $textarea.trigger('focus');
                        return;
                    }

                    $sendBtn.prop('disabled', true).text('در حال ارسال...');
                    $statusSpan.css('color', '#666').text('');

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
                                $statusSpan.css('color', 'green').text('پاسخ با موفقیت ارسال شد.');
                            } else {
                                var msg = (response.data && response.data.message) ? response.data.message : 'خطا در ارسال پاسخ.';
                                $statusSpan.css('color', '#b91c1c').text(msg);
                            }
                        },
                        error: function() {
                            $sendBtn.prop('disabled', false).text('ارسال پاسخ');
                            $statusSpan.css('color', '#b91c1c').text('خطای غیرمنتظره در ارتباط با سرور.');
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
                    $statusSpan.css('color', '#666').text('');

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
                                $statusSpan.css('color', 'green').text('چت با موفقیت بسته شد.');
                                $textarea.prop('disabled', true);
                                $closeBtn.text('چت بسته شد');
                                $sendBtn.prop('disabled', true);

                                // به‌روزرسانی بج وضعیت در هدر آکاردئون بدون نیاز به رفرش کل لیست
                                var $badge = $wrap.closest('.ai-agent-session-item').find('.ai-agent-session-status-badge');
                                $badge.text(self.getStatusLabel('closed')).css({background:'#f3f4f6', color:'#6b7280'});
                            } else {
                                $closeBtn.prop('disabled', false).text('پایان چت');
                                $sendBtn.prop('disabled', false);
                                var msg = (response.data && response.data.message) ? response.data.message : 'خطا در بستن چت.';
                                $statusSpan.css('color', '#b91c1c').text(msg);
                            }
                        },
                        error: function() {
                            $closeBtn.prop('disabled', false).text('پایان چت');
                            $sendBtn.prop('disabled', false);
                            $statusSpan.css('color', '#b91c1c').text('خطای غیرمنتظره در ارتباط با سرور.');
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
                var $indicator = $('<div class="ai-agent-msg-loading" style="padding:8px;text-align:center;color:#666;">در حال بارگذاری...</div>');
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

                            // اضافه کردن پیام‌های جدید به ابتدا (پیام‌های قدیمی‌تر)
                            for (var i = newMessages.length - 1; i >= 0; i--) {
                                var msg = newMessages[i];
                                var role = (msg.role || 'user').toLowerCase();
                                var content = msg.content || '';
                                var created = msg.created_at || '';

                                var timeStr = '';
                                if (created) {
                                    var dt = new Date(created);
                                    if (!isNaN(dt.getTime())) {
                                        timeStr = String(dt.getHours()).padStart(2, '0') + ':' +
                                                  String(dt.getMinutes()).padStart(2, '0') + ':' +
                                                  String(dt.getSeconds()).padStart(2, '0');
                                    }
                                }

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
                                $msgBubble.append($msgHeader).append($msgContent);
                                $chatArea.prepend($msgBubble);
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