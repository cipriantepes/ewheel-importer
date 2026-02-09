/**
 * Ewheel Importer Admin JavaScript
 */

(function ($) {
    'use strict';

    var EwheelImporter = {
        pollInterval: null,
        currentProfileId: null,
        currentSyncId: null,

        init: function () {
            this.bindEvents();
            this.checkInitialStatus();
            this.checkQueueStatus();
            this.refreshProductCount(); // Auto-load product count
        },

        bindEvents: function () {
            // Settings tab sync controls
            $('#ewheel-run-sync').on('click', this.runSync.bind(this));
            $('#ewheel-pause-sync').on('click', this.pauseSync.bind(this));
            $('#ewheel-resume-sync').on('click', this.resumeSync.bind(this));
            $('#ewheel-cancel-sync').on('click', this.cancelSync.bind(this));
            $('#ewheel-test-connection').on('click', this.testConnection.bind(this));

            // Profile tab sync controls
            $('#ewheel-run-profile-sync').on('click', this.runProfileSync.bind(this));
            $('#ewheel-pause-profile-sync').on('click', this.pauseProfileSync.bind(this));
            $('#ewheel-resume-profile-sync').on('click', this.resumeProfileSync.bind(this));
            $('#ewheel-cancel-profile-sync').on('click', this.cancelProfileSync.bind(this));

            // Product count
            $('#ewheel-refresh-product-count').on('click', this.refreshProductCount.bind(this));
        },

        checkQueueStatus: function () {
            var $container = $('#ewheel-queue-status-container');
            if ($container.length === 0) return;

            $.ajax({
                url: ewheelImporter.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ewheel_get_queue_status',
                    nonce: ewheelImporter.nonce
                },
                success: function (response) {
                    if (response.success && response.data && response.data.available) {
                        if (response.data.html) {
                            $container.html(response.data.html);
                            // Re-bind clear queue button if it exists
                            $('#ewheel-clear-queue').on('click', function () {
                                if (confirm('Are you sure? This will stop all syncs.')) {
                                    $.post(ewheelImporter.ajaxUrl, {
                                        action: 'ewheel_clear_queue',
                                        nonce: ewheelImporter.nonce
                                    }, function () {
                                        location.reload();
                                    });
                                }
                            });
                        }
                    }
                }
            });
        },

        checkInitialStatus: function () {
            var self = this;
            $.ajax({
                url: ewheelImporter.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ewheel_get_sync_status',
                    nonce: ewheelImporter.nonce
                },
                success: function (response) {
                    if (response.success && response.data && response.data.status) {
                        var data = response.data;
                        var status = data.status;

                        // Store sync_id for cancel operations
                        if (data.id) {
                            self.currentSyncId = data.id;
                        }

                        if (status === 'running' || status === 'pausing') {
                            self.updateSyncUI('running', data);
                            self.startPolling();
                        } else if (status === 'paused') {
                            self.updateSyncUI('paused', data);
                        }
                    }
                }
            });
        },

        updateSyncUI: function (state, data) {
            var $runBtn = $('#ewheel-run-sync');
            var $pauseBtn = $('#ewheel-pause-sync');
            var $resumeBtn = $('#ewheel-resume-sync');
            var $cancelBtn = $('#ewheel-cancel-sync');
            var $status = $('#ewheel-sync-status');

            // Reset all buttons first
            $runBtn.hide().prop('disabled', false);
            $pauseBtn.hide().prop('disabled', false).text('Pause');
            $resumeBtn.hide().prop('disabled', false);
            $cancelBtn.hide().prop('disabled', false);

            switch (state) {
                case 'idle':
                    $runBtn.show();
                    $status.removeClass('syncing success error').text('');
                    break;

                case 'running':
                    $pauseBtn.show();
                    $cancelBtn.show();
                    var msg = 'Processing...';
                    if (data) {
                        msg = 'Page: ' + (data.page || 0) + ' | Products: ' + (data.processed || 0);
                        if (data.created) msg += ' | Created: ' + data.created;
                        if (data.updated) msg += ' | Updated: ' + data.updated;
                        // Show adaptive batch info when reduced from default
                        if (data.batch_size && data.batch_size < 10) {
                            msg += ' | Batch: ' + data.batch_size;
                        }
                        if (data.failure_count && data.failure_count > 0) {
                            msg += ' | Retries: ' + data.failure_count;
                        }
                    }
                    $status.removeClass('success error').addClass('syncing').text(msg);
                    break;

                case 'pausing':
                    $pauseBtn.show().prop('disabled', true).text('Pausing...');
                    $cancelBtn.show();
                    $status.text('Finishing current batch before pausing...');
                    break;

                case 'paused':
                    $resumeBtn.show();
                    $cancelBtn.show();
                    var pauseMsg = 'Paused';
                    if (data) {
                        pauseMsg = 'Paused at page ' + (data.page || 0) + ' (' + (data.processed || 0) + ' products processed)';
                    }
                    $status.removeClass('syncing').text(pauseMsg);
                    break;

                case 'stopping':
                    $cancelBtn.show().prop('disabled', true).text('Cancelling...');
                    $status.text('Finishing current batch before cancelling...');
                    break;

                case 'completed':
                    $runBtn.show();
                    var completeMsg = 'Sync completed!';
                    if (data) {
                        completeMsg += ' Processed: ' + (data.processed || 0);
                    }
                    $status.removeClass('syncing error').addClass('success').text(completeMsg);
                    break;

                case 'stopped':
                    $runBtn.show();
                    $status.removeClass('syncing success').text('Sync cancelled.');
                    break;

                case 'failed':
                    $runBtn.show();
                    $status.removeClass('syncing success').addClass('error').text('Sync failed.');
                    break;
            }
        },

        runSync: function (e) {
            e.preventDefault();
            var self = this;
            var limit = $('#ewheel-sync-limit').val() || 0;

            this.updateSyncUI('running', null);

            $.ajax({
                url: ewheelImporter.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ewheel_run_sync',
                    nonce: ewheelImporter.nonce,
                    limit: limit
                },
                success: function (response) {
                    if (response.success) {
                        self.startPolling();
                    } else {
                        self.updateSyncUI('idle', null);
                        $('#ewheel-sync-status')
                            .removeClass('syncing success')
                            .addClass('error')
                            .text('Error: ' + response.data.message);
                    }
                },
                error: function (xhr, status, error) {
                    self.updateSyncUI('idle', null);
                    $('#ewheel-sync-status')
                        .removeClass('syncing success')
                        .addClass('error')
                        .text('Error: ' + error);
                }
            });
        },

        pauseSync: function (e) {
            e.preventDefault();
            var self = this;

            this.updateSyncUI('pausing', null);

            $.ajax({
                url: ewheelImporter.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ewheel_pause_sync',
                    nonce: ewheelImporter.nonce
                },
                success: function (response) {
                    if (!response.success) {
                        $('#ewheel-sync-status').text('Error pausing: ' + response.data.message);
                    }
                    // Polling will update UI when batch finishes
                }
            });
        },

        resumeSync: function (e) {
            e.preventDefault();
            var self = this;

            this.updateSyncUI('running', null);

            $.ajax({
                url: ewheelImporter.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ewheel_resume_sync',
                    nonce: ewheelImporter.nonce
                },
                success: function (response) {
                    if (response.success) {
                        self.startPolling();
                    } else {
                        self.updateSyncUI('paused', null);
                        $('#ewheel-sync-status').text('Error resuming: ' + response.data.message);
                    }
                },
                error: function (xhr, status, error) {
                    self.updateSyncUI('paused', null);
                    $('#ewheel-sync-status').text('Error resuming: ' + error);
                }
            });
        },

        cancelAttempts: 0,

        cancelSync: function (e) {
            e.preventDefault();
            var self = this;

            // Track cancel attempts - force clear on second click
            this.cancelAttempts++;
            var forceMode = this.cancelAttempts >= 2;

            var confirmMsg = forceMode
                ? 'Force stop and clear all sync data? This will completely reset the sync state.'
                : 'Are you sure you want to cancel this sync?';

            if (!confirm(confirmMsg)) {
                return;
            }

            this.updateSyncUI('stopping', null);
            var $cancelBtn = $('#ewheel-cancel-sync');
            $cancelBtn.text(forceMode ? 'Force stopping...' : 'Cancelling...').prop('disabled', true);

            $.ajax({
                url: ewheelImporter.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ewheel_stop_sync',
                    nonce: ewheelImporter.nonce,
                    sync_id: this.currentSyncId || '',
                    force: forceMode ? 'true' : 'false'
                },
                success: function (response) {
                    if (response.success) {
                        // Stop any active polling
                        if (self.pollInterval) {
                            clearInterval(self.pollInterval);
                            self.pollInterval = null;
                        }

                        // Reset cancel attempts
                        self.cancelAttempts = 0;
                        self.currentSyncId = null;

                        // Update UI to stopped state immediately
                        self.updateSyncUI('stopped', {});

                        // Stop log polling after a delay to get final logs
                        setTimeout(function () {
                            self.stopLogPolling();
                        }, 3000);

                        if (forceMode) {
                            // Force mode - reload after brief delay
                            setTimeout(function () {
                                location.reload();
                            }, 1000);
                        }
                    } else {
                        $('#ewheel-sync-status').text('Error cancelling: ' + response.data.message);
                        $cancelBtn.text('Force Stop').prop('disabled', false);
                    }
                },
                error: function () {
                    $('#ewheel-sync-status').text('Network error. Try Force Stop.');
                    $cancelBtn.text('Force Stop').prop('disabled', false);
                }
            });
        },

        logPollInterval: null,
        logErrorCount: 0,
        statusErrorCount: 0,
        maxConsecutiveErrors: 3,

        startPolling: function () {
            var self = this;

            // Reset error counters
            this.statusErrorCount = 0;

            // Clear any existing intervals
            if (this.pollInterval) {
                clearInterval(this.pollInterval);
            }

            // Start separate log polling
            this.startLogPolling();

            this.pollInterval = setInterval(function () {
                // Skip if a status request is already in flight
                if (self._statusPending) return;
                self._statusPending = true;
                $.ajax({
                    url: ewheelImporter.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'ewheel_get_sync_status',
                        nonce: ewheelImporter.nonce
                    },
                    success: function (response) {
                        self._statusPending = false;
                        // Reset error counter on success
                        self.statusErrorCount = 0;

                        if (response.success && response.data) {
                            var data = response.data;
                            var status = data.status;

                            // Store sync_id for cancel operations
                            if (data.id) {
                                self.currentSyncId = data.id;
                            }

                            // Stop polling if no active sync (empty/idle/undefined status)
                            if (!status || status === 'idle' || status === 'completed' || status === 'failed' || status === 'stopped') {
                                clearInterval(self.pollInterval);
                                self.pollInterval = null;
                                self.currentSyncId = null;

                                // Update UI based on final status
                                if (status === 'completed') {
                                    self.updateSyncUI('completed', data);
                                } else if (status === 'stopped') {
                                    self.updateSyncUI('stopped', data);
                                } else if (status === 'failed') {
                                    self.updateSyncUI('failed', data);
                                } else {
                                    self.updateSyncUI('idle', data);
                                }

                                // Stop log polling after a delay (let final logs come through)
                                setTimeout(function () {
                                    self.stopLogPolling();
                                }, 5000);

                                // Reload page on completion after a delay
                                if (status === 'completed') {
                                    setTimeout(function () {
                                        location.reload();
                                    }, 3000);
                                }
                                return;
                            }

                            // Update UI for active states
                            if (status === 'paused') {
                                clearInterval(self.pollInterval);
                                self.pollInterval = null;
                                self.updateSyncUI('paused', data);
                                // Keep log polling active when paused
                                return;
                            }

                            self.updateSyncUI(status, data);
                        } else {
                            // No data or error - stop polling
                            clearInterval(self.pollInterval);
                            self.pollInterval = null;
                            self.updateSyncUI('idle', {});
                            self.stopLogPolling();
                        }
                    },
                    error: function (xhr, status, error) {
                        self._statusPending = false;
                        self.statusErrorCount++;
                        console.warn('[Ewheel] Status poll error (' + self.statusErrorCount + '/' + self.maxConsecutiveErrors + '): ' + (xhr.status || 'network') + ' ' + error);

                        if (self.statusErrorCount >= self.maxConsecutiveErrors) {
                            clearInterval(self.pollInterval);
                            self.pollInterval = null;
                            self.stopLogPolling();
                            $('#ewheel-sync-status').text('Connection lost. Refresh page to resume.');
                            console.error('[Ewheel] Stopped polling after ' + self.maxConsecutiveErrors + ' consecutive errors');
                        }
                    }
                });
            }, 5000);
        },

        startLogPolling: function () {
            var self = this;

            // Reset error counter
            this.logErrorCount = 0;

            // Clear any existing log interval
            if (this.logPollInterval) {
                clearInterval(this.logPollInterval);
            }

            // Fetch immediately
            this.fetchLogs();

            // Poll every 5 seconds (staggered from status poll to reduce server load)
            this.logPollInterval = setInterval(function () {
                self.fetchLogs();
            }, 5000);
        },

        stopLogPolling: function () {
            if (this.logPollInterval) {
                clearInterval(this.logPollInterval);
                this.logPollInterval = null;
            }
        },

        fetchLogs: function () {
            var self = this;
            var $logConsole = $('#ewheel-activity-log');
            if ($logConsole.length === 0) return;

            $.ajax({
                url: ewheelImporter.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ewheel_get_logs',
                    nonce: ewheelImporter.nonce
                },
                success: function (response) {
                    // Reset error counter on success
                    self.logErrorCount = 0;

                    if (response.success && response.data) {
                        var logs = response.data;
                        var html = '';
                        logs.forEach(function (log) {
                            var color = log.type === 'error' ? 'red' : (log.type === 'success' ? 'green' : 'black');
                            html += '<div style="color:' + color + '; margin-bottom: 2px;">[' + log.time + '] ' + log.message + '</div>';
                        });
                        $logConsole.html(html);
                    }
                },
                error: function (xhr, status, error) {
                    self.logErrorCount++;
                    console.warn('[Ewheel] Log fetch error (' + self.logErrorCount + '/' + self.maxConsecutiveErrors + '): ' + (xhr.status || 'network') + ' ' + error);

                    if (self.logErrorCount >= self.maxConsecutiveErrors) {
                        self.stopLogPolling();
                        console.error('[Ewheel] Stopped log polling after ' + self.maxConsecutiveErrors + ' consecutive errors');
                    }
                }
            });
        },

        // Profile-specific sync methods
        runProfileSync: function (e) {
            e.preventDefault();
            var self = this;
            var profileId = this.currentProfileId;

            if (!profileId) {
                alert('Please select a profile first.');
                return;
            }

            this.updateProfileSyncUI('running', null);

            $.ajax({
                url: ewheelImporter.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ewheel_run_sync',
                    nonce: ewheelImporter.nonce,
                    profile_id: profileId,
                    limit: 0
                },
                success: function (response) {
                    if (response.success) {
                        self.startProfilePolling(profileId);
                    } else {
                        self.updateProfileSyncUI('idle', null);
                        alert('Error: ' + response.data.message);
                    }
                }
            });
        },

        pauseProfileSync: function (e) {
            e.preventDefault();
            var profileId = this.currentProfileId;

            this.updateProfileSyncUI('pausing', null);

            $.ajax({
                url: ewheelImporter.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ewheel_pause_sync',
                    nonce: ewheelImporter.nonce,
                    profile_id: profileId
                }
            });
        },

        resumeProfileSync: function (e) {
            e.preventDefault();
            var self = this;
            var profileId = this.currentProfileId;

            this.updateProfileSyncUI('running', null);

            $.ajax({
                url: ewheelImporter.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ewheel_resume_sync',
                    nonce: ewheelImporter.nonce,
                    profile_id: profileId
                },
                success: function (response) {
                    if (response.success) {
                        self.startProfilePolling(profileId);
                    } else {
                        self.updateProfileSyncUI('paused', null);
                        alert('Error resuming: ' + response.data.message);
                    }
                }
            });
        },

        profileCancelAttempts: 0,

        cancelProfileSync: function (e) {
            e.preventDefault();
            var self = this;
            var profileId = this.currentProfileId;

            // Track cancel attempts - force clear on second click
            this.profileCancelAttempts++;
            var forceMode = this.profileCancelAttempts >= 2;

            var confirmMsg = forceMode
                ? 'Force stop and clear all sync data? This will completely reset the sync state.'
                : 'Are you sure you want to cancel this sync?';

            if (!confirm(confirmMsg)) {
                return;
            }

            this.updateProfileSyncUI('stopping', null);
            var $cancelBtn = $('#ewheel-cancel-profile-sync');
            $cancelBtn.text(forceMode ? 'Force stopping...' : 'Cancelling...').prop('disabled', true);

            $.ajax({
                url: ewheelImporter.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ewheel_stop_sync',
                    nonce: ewheelImporter.nonce,
                    profile_id: profileId,
                    force: forceMode ? 'true' : 'false'
                },
                success: function (response) {
                    if (response.success && forceMode) {
                        self.profileCancelAttempts = 0;
                        location.reload();
                    } else if (!response.success) {
                        $cancelBtn.text('Force Stop').prop('disabled', false);
                    } else {
                        $cancelBtn.text('Force Stop').prop('disabled', false);
                    }
                },
                error: function () {
                    $cancelBtn.text('Force Stop').prop('disabled', false);
                }
            });
        },

        updateProfileSyncUI: function (state, data) {
            var $runBtn = $('#ewheel-run-profile-sync');
            var $pauseBtn = $('#ewheel-pause-profile-sync');
            var $resumeBtn = $('#ewheel-resume-profile-sync');
            var $cancelBtn = $('#ewheel-cancel-profile-sync');
            var $progress = $('#ewheel-profile-sync-progress');
            var $details = $('#ewheel-profile-sync-details');

            // Reset all buttons
            $runBtn.hide().prop('disabled', false);
            $pauseBtn.hide().prop('disabled', false).text('Pause');
            $resumeBtn.hide().prop('disabled', false);
            $cancelBtn.hide().prop('disabled', false).text('Cancel');

            switch (state) {
                case 'idle':
                    $runBtn.show();
                    $progress.hide();
                    break;

                case 'running':
                    $pauseBtn.show();
                    $cancelBtn.show();
                    $progress.show();
                    if (data) {
                        var detailsMsg = 'Page: ' + (data.page || 0) + ' | Products: ' + (data.processed || 0);
                        // Show adaptive batch info when reduced from default
                        if (data.batch_size && data.batch_size < 10) {
                            detailsMsg += ' | Batch: ' + data.batch_size;
                        }
                        if (data.failure_count && data.failure_count > 0) {
                            detailsMsg += ' | Retries: ' + data.failure_count;
                        }
                        $details.text(detailsMsg);
                    }
                    break;

                case 'pausing':
                    $pauseBtn.show().prop('disabled', true).text('Pausing...');
                    $cancelBtn.show();
                    $details.text('Finishing current batch...');
                    break;

                case 'paused':
                    $resumeBtn.show();
                    $cancelBtn.show();
                    $progress.show();
                    if (data) {
                        $details.text('Paused at page ' + (data.page || 0) + ' (' + (data.processed || 0) + ' products)');
                    }
                    break;

                case 'stopping':
                    $cancelBtn.show().prop('disabled', true).text('Cancelling...');
                    $details.text('Finishing current batch...');
                    break;

                case 'completed':
                case 'stopped':
                case 'failed':
                    $runBtn.show();
                    $progress.hide();
                    break;
            }
        },

        profilePollErrorCount: 0,

        startProfilePolling: function (profileId) {
            var self = this;

            // Reset error counter
            this.profilePollErrorCount = 0;

            if (this.profilePollInterval) {
                clearInterval(this.profilePollInterval);
            }

            this.profilePollInterval = setInterval(function () {
                $.ajax({
                    url: ewheelImporter.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'ewheel_get_sync_status',
                        nonce: ewheelImporter.nonce,
                        profile_id: profileId
                    },
                    success: function (response) {
                        // Reset error counter on success
                        self.profilePollErrorCount = 0;

                        if (response.success) {
                            var data = response.data;
                            self.updateProfileSyncUI(data.status, data);

                            if (data.status === 'completed' || data.status === 'failed' || data.status === 'stopped' || data.status === 'paused') {
                                clearInterval(self.profilePollInterval);
                                self.profilePollInterval = null;
                            }
                        }
                    },
                    error: function (xhr, status, error) {
                        self.profilePollErrorCount++;
                        console.warn('[Ewheel] Profile poll error (' + self.profilePollErrorCount + '/' + self.maxConsecutiveErrors + '): ' + (xhr.status || 'network') + ' ' + error);

                        if (self.profilePollErrorCount >= self.maxConsecutiveErrors) {
                            clearInterval(self.profilePollInterval);
                            self.profilePollInterval = null;
                            self.updateProfileSyncUI('idle', {});
                            console.error('[Ewheel] Stopped profile polling after ' + self.maxConsecutiveErrors + ' consecutive errors');
                        }
                    }
                });
            }, 5000);
        },

        testConnection: function (e) {
            e.preventDefault();

            var $button = $('#ewheel-test-connection');
            var $status = $('#ewheel-connection-status');

            $button.prop('disabled', true);
            $status
                .removeClass('success error')
                .addClass('testing')
                .text(ewheelImporter.strings.testing);

            $.ajax({
                url: ewheelImporter.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ewheel_test_connection',
                    nonce: ewheelImporter.nonce
                },
                success: function (response) {
                    $button.prop('disabled', false);

                    if (response.success) {
                        $status
                            .removeClass('testing error')
                            .addClass('success')
                            .text(ewheelImporter.strings.connected);
                    } else {
                        $status
                            .removeClass('testing success')
                            .addClass('error')
                            .text(ewheelImporter.strings.connFailed + ' ' + response.data.message);
                    }
                },
                error: function (xhr, status, error) {
                    $button.prop('disabled', false);
                    $status
                        .removeClass('testing success')
                        .addClass('error')
                        .text(ewheelImporter.strings.connFailed + ' ' + error);
                }
            });
        },

        refreshProductCount: function (e) {
            if (e) e.preventDefault();

            var $button = $('#ewheel-refresh-product-count');
            var $count = $('#ewheel-product-count');

            $button.prop('disabled', true);
            $button.find('.dashicons').addClass('ewheel-spin');
            $count.text('...');

            $.ajax({
                url: ewheelImporter.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ewheel_get_product_count',
                    nonce: ewheelImporter.nonce
                },
                success: function (response) {
                    $button.prop('disabled', false);
                    $button.find('.dashicons').removeClass('ewheel-spin');

                    if (response.success) {
                        $count.text(response.data.formatted);
                    } else {
                        $count.text('—');
                    }
                },
                error: function () {
                    $button.prop('disabled', false);
                    $button.find('.dashicons').removeClass('ewheel-spin');
                    $count.text('—');
                }
            });
        }
    };

    /**
     * OpenRouter Model Selector Module
     */
    var OpenRouterModelSelector = {
        $select: null,
        $hidden: null,
        $refreshBtn: null,
        $status: null,
        $customWrapper: null,
        $customInput: null,
        currentModel: '',

        init: function () {
            this.$select = $('#ewheel_importer_openrouter_model');
            this.$hidden = $('#ewheel_importer_openrouter_model_hidden');
            this.$refreshBtn = $('#ewheel-refresh-openrouter-models');
            this.$status = $('#ewheel-openrouter-model-status');
            this.$customWrapper = $('#ewheel-custom-model-wrapper');
            this.$customInput = $('#ewheel_importer_openrouter_model_custom');

            if (this.$select.length === 0) {
                return;
            }

            // Store current selected model from hidden input (source of truth)
            this.currentModel = this.$hidden.val();

            // Bind events
            this.$refreshBtn.on('click', this.refreshModels.bind(this));

            // Sync select changes to hidden input; toggle custom input
            var self = this;
            this.$select.on('change', function () {
                var val = $(this).val();
                if (val === '__other__') {
                    self.$customWrapper.show();
                    self.$customInput.focus();
                } else {
                    self.$customWrapper.hide();
                    self.$customInput.val('');
                    self.$hidden.val(val);
                }
            });

            // Sync custom text input to hidden input
            this.$customInput.on('input', function () {
                var customVal = $(this).val().trim();
                if (customVal) {
                    self.$hidden.val(customVal);
                }
            });

            // Load models on page load if OpenRouter is selected
            if ($('#ewheel_importer_translation_driver').val() === 'openrouter') {
                this.loadModels(false);
            }

            // Load models when switching to OpenRouter driver
            $('#ewheel_importer_translation_driver').on('change', function () {
                if ($(this).val() === 'openrouter') {
                    self.loadModels(false);
                }
            });
        },

        loadModels: function (forceRefresh) {
            var self = this;
            var action = forceRefresh ? 'ewheel_refresh_openrouter_models' : 'ewheel_get_openrouter_models';

            // Show loading state
            this.$select.prop('disabled', true);
            this.$refreshBtn.prop('disabled', true);
            this.$refreshBtn.find('.dashicons').addClass('ewheel-spin');
            this.$status.text(ewheelImporter.strings.loadingModels || 'Loading models...');

            $.ajax({
                url: ewheelImporter.ajaxUrl,
                type: 'POST',
                data: {
                    action: action,
                    nonce: ewheelImporter.nonce
                },
                success: function (response) {
                    self.$select.prop('disabled', false);
                    self.$refreshBtn.prop('disabled', false);
                    self.$refreshBtn.find('.dashicons').removeClass('ewheel-spin');

                    if (response.success && response.data.models) {
                        self.populateDropdown(response.data.models);
                        var statusText = response.data.from_cache
                            ? (ewheelImporter.strings.modelsFromCache || 'Models loaded from cache.')
                            : (ewheelImporter.strings.modelsFetched || 'Models fetched from OpenRouter.');
                        self.$status.html(statusText + ' ' + response.data.models.length + ' ' + (ewheelImporter.strings.modelsAvailable || 'models available.'));
                    } else {
                        self.$status.html('<span style="color: #d63638;">' + (response.data.message || 'Error loading models') + '</span>');
                    }
                },
                error: function (xhr, status, error) {
                    self.$select.prop('disabled', false);
                    self.$refreshBtn.prop('disabled', false);
                    self.$refreshBtn.find('.dashicons').removeClass('ewheel-spin');
                    self.$status.html('<span style="color: #d63638;">Error: ' + error + '</span>');
                }
            });
        },

        refreshModels: function (e) {
            e.preventDefault();
            this.loadModels(true);
        },

        populateDropdown: function (models) {
            var self = this;
            var currentVal = this.currentModel || this.$select.val();

            // Clear existing options
            this.$select.empty();

            // Add placeholder option
            this.$select.append($('<option>', {
                value: '',
                text: '-- ' + (ewheelImporter.strings.selectModel || 'Select a model') + ' --',
                disabled: true
            }));

            // Recommended models for translation (fast + affordable)
            var recommendedIds = [
                'google/gemini-2.5-flash',
                'google/gemini-2.0-flash-001',
                'google/gemini-2.0-flash-lite-001',
                'meta-llama/llama-3.1-8b-instruct:free'
            ];

            var recommendedModels = models.filter(function (m) {
                return recommendedIds.indexOf(m.id) !== -1;
            });
            // Sort recommended in the order defined above
            recommendedModels.sort(function (a, b) {
                return recommendedIds.indexOf(a.id) - recommendedIds.indexOf(b.id);
            });

            if (recommendedModels.length > 0) {
                var $recGroup = $('<optgroup>', { label: '\u2B50 Recommended for Translation' });
                recommendedModels.forEach(function (model) {
                    $recGroup.append($('<option>', {
                        value: model.id,
                        text: model.display_name,
                        selected: model.id === currentVal
                    }));
                });
                this.$select.append($recGroup);
            }

            // Group remaining models: Free first, then others
            var freeModels = models.filter(function (m) {
                return m.is_free && recommendedIds.indexOf(m.id) === -1;
            });
            var paidModels = models.filter(function (m) {
                return !m.is_free && recommendedIds.indexOf(m.id) === -1;
            });

            if (freeModels.length > 0) {
                var $freeGroup = $('<optgroup>', { label: ewheelImporter.strings.freeModels || 'Free Models' });
                freeModels.forEach(function (model) {
                    $freeGroup.append($('<option>', {
                        value: model.id,
                        text: model.display_name,
                        selected: model.id === currentVal
                    }));
                });
                this.$select.append($freeGroup);
            }

            if (paidModels.length > 0) {
                var $paidGroup = $('<optgroup>', { label: ewheelImporter.strings.paidModels || 'Paid Models' });
                paidModels.forEach(function (model) {
                    $paidGroup.append($('<option>', {
                        value: model.id,
                        text: model.display_name,
                        selected: model.id === currentVal
                    }));
                });
                this.$select.append($paidGroup);
            }

            // Add "Other" option at the end
            this.$select.append($('<option>', {
                value: '__other__',
                text: '— ' + (ewheelImporter.strings.otherModel || 'Other (type model ID)') + ' —'
            }));

            // If current model is not in the list, add it as a custom option
            if (currentVal && this.$select.find('option[value="' + currentVal + '"]').length === 0) {
                this.$select.prepend($('<option>', {
                    value: currentVal,
                    text: currentVal + ' (current)',
                    selected: true
                }));
            }
        }
    };

    $(document).ready(function () {
        EwheelImporter.init();
        OpenRouterModelSelector.init();

        // Store reference to currentProfileId when profile is selected
        $(document).on('click', '.ewheel-profile-item', function () {
            EwheelImporter.currentProfileId = $(this).data('id');
        });

        // Cancel button in sync history table
        $(document).on('click', '.ewheel-history-cancel-btn', function (e) {
            e.preventDefault();

            var $btn = $(this);
            var syncId = $btn.data('sync-id');
            var profileId = $btn.data('profile-id');

            if (!syncId) {
                console.error('No sync ID found on cancel button');
                return;
            }

            if (!confirm(ewheelImporter.strings.confirmCancel || 'Are you sure you want to cancel this sync?')) {
                return;
            }

            $btn.prop('disabled', true).text(ewheelImporter.strings.cancelling || 'Cancelling...');

            $.ajax({
                url: ewheelImporter.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ewheel_stop_sync',
                    nonce: ewheelImporter.nonce,
                    sync_id: syncId,
                    profile_id: profileId || ''
                },
                success: function (response) {
                    if (response.success) {
                        $btn.text('Cancelled').addClass('disabled');
                        // Reload after short delay to show updated status
                        setTimeout(function () {
                            location.reload();
                        }, 1000);
                    } else {
                        alert(response.data?.message || 'Failed to cancel sync');
                        $btn.prop('disabled', false).text('Cancel');
                    }
                },
                error: function () {
                    alert('Failed to cancel sync');
                    $btn.prop('disabled', false).text('Cancel');
                }
            });
        });
    });
})(jQuery);
