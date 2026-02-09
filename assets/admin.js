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
            this.loadCachedProductCount(); // Load cached count without hitting API
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
            var resumeFromLast = $('#ewheel-resume-from-last').is(':checked') ? 1 : 0;

            this.updateSyncUI('running', null);

            $.ajax({
                url: ewheelImporter.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ewheel_run_sync',
                    nonce: ewheelImporter.nonce,
                    limit: limit,
                    resume_from_last: resumeFromLast
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
                        self.stopPolling();

                        // Reset cancel attempts
                        self.cancelAttempts = 0;
                        self.currentSyncId = null;

                        // Update UI to stopped state immediately
                        self.updateSyncUI('stopped', {});

                        // Final log update after delay
                        setTimeout(function () {
                            $.post(ewheelImporter.ajaxUrl, {
                                action: 'ewheel_get_logs',
                                nonce: ewheelImporter.nonce
                            }, function (logResponse) {
                                if (logResponse.success && logResponse.data) {
                                    self._updateLogConsole(logResponse.data);
                                }
                            });
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

        _pollErrorCount: 0,
        maxConsecutiveErrors: 10,

        // Combined polling configuration (status + logs in single request)
        _pollInterval: 8000,
        _pollIntervalBase: 8000,
        _maxInterval: 60000,
        _pollTimer: null,
        _pollPending: false,
        _pollActive: false,
        _visibilityHandler: null,

        _restartPollTimer: function () {
            var self = this;
            if (this._pollTimer) clearInterval(this._pollTimer);
            this._pollTimer = setInterval(function () {
                self._pollCombined();
            }, this._pollInterval);
        },

        startPolling: function () {
            var self = this;

            // Reset error counter and interval
            this._pollErrorCount = 0;
            this._pollInterval = this._pollIntervalBase;

            // Clear any existing timer
            if (this._pollTimer) {
                clearInterval(this._pollTimer);
            }

            // Immediate first poll
            this._pollCombined();
            this._restartPollTimer();

            // Pause when tab is hidden, resume when visible
            this._visibilityHandler = function () {
                if (document.visibilityState === 'hidden') {
                    if (self._pollTimer) {
                        clearInterval(self._pollTimer);
                        self._pollTimer = null;
                    }
                } else {
                    // Tab visible again - poll immediately and restart timer
                    if (self._pollTimer === null && self._pollActive) {
                        self._pollCombined();
                        self._restartPollTimer();
                    }
                }
            };
            document.addEventListener('visibilitychange', this._visibilityHandler);
            this._pollActive = true;
        },

        stopPolling: function () {
            this._pollActive = false;
            if (this._pollTimer) {
                clearInterval(this._pollTimer);
                this._pollTimer = null;
            }
            if (this._visibilityHandler) {
                document.removeEventListener('visibilitychange', this._visibilityHandler);
                this._visibilityHandler = null;
            }
        },

        _pollCombined: function () {
            var self = this;

            // In-flight guard
            if (self._pollPending) return;
            self._pollPending = true;

            $.ajax({
                url: ewheelImporter.ajaxUrl,
                type: 'POST',
                timeout: 15000,
                data: {
                    action: 'ewheel_get_sync_combined',
                    nonce: ewheelImporter.nonce
                },
                success: function (response) {
                    self._pollPending = false;

                    // Restore normal interval on success after backoff
                    if (self._pollErrorCount > 0) {
                        self._pollInterval = self._pollIntervalBase;
                        self._restartPollTimer();
                    }
                    self._pollErrorCount = 0;

                    if (response.success && response.data) {
                        // Update status UI from response.data.status
                        var statusData = response.data.status;
                        var status = statusData.status;

                        // Store sync_id for cancel operations
                        if (statusData.id) {
                            self.currentSyncId = statusData.id;
                        }

                        // Update log console from response.data.logs
                        self._updateLogConsole(response.data.logs);

                        // Stop polling if no active sync
                        if (!status || status === 'idle' || status === 'completed' || status === 'failed' || status === 'stopped') {
                            self.stopPolling();
                            self.currentSyncId = null;

                            // Update UI based on final status
                            if (status === 'completed') {
                                self.updateSyncUI('completed', statusData);
                            } else if (status === 'stopped') {
                                self.updateSyncUI('stopped', statusData);
                            } else if (status === 'failed') {
                                self.updateSyncUI('failed', statusData);
                            } else {
                                self.updateSyncUI('idle', statusData);
                            }

                            // Final log update after delay
                            setTimeout(function () {
                                $.post(ewheelImporter.ajaxUrl, {
                                    action: 'ewheel_get_logs',
                                    nonce: ewheelImporter.nonce
                                }, function (logResponse) {
                                    if (logResponse.success && logResponse.data) {
                                        self._updateLogConsole(logResponse.data);
                                    }
                                });
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
                            self.stopPolling();
                            self.updateSyncUI('paused', statusData);
                            return;
                        }

                        self.updateSyncUI(status, statusData);
                    } else {
                        // No data or error - stop polling
                        self.stopPolling();
                        self.updateSyncUI('idle', {});
                    }
                },
                error: function (xhr, status, error) {
                    self._pollPending = false;
                    self._pollErrorCount++;

                    var httpStatus = xhr.status || 0;
                    console.warn('[Ewheel] Combined poll error (' + self._pollErrorCount + '/' + self.maxConsecutiveErrors + '): ' + httpStatus + ' ' + error);

                    // On 503/5xx - backoff instead of stopping
                    if (httpStatus >= 500 || httpStatus === 0) {
                        self._pollInterval = Math.min(self._pollInterval * 2, self._maxInterval);
                        self._restartPollTimer();
                        console.info('[Ewheel] Combined poll backing off to ' + (self._pollInterval / 1000) + 's');
                    }

                    if (self._pollErrorCount >= self.maxConsecutiveErrors) {
                        self.stopPolling();
                        $('#ewheel-sync-status').text('Connection lost. Refresh page to resume.');
                        console.error('[Ewheel] Stopped polling after ' + self.maxConsecutiveErrors + ' consecutive errors');
                    }
                }
            });
        },

        _updateLogConsole: function (logs) {
            var $logConsole = $('#ewheel-activity-log');
            if ($logConsole.length === 0) return;

            if (logs && logs.length > 0) {
                var html = '';
                logs.forEach(function (log) {
                    var color = log.type === 'error' ? 'red' : (log.type === 'success' ? 'green' : 'black');
                    html += '<div style="color:' + color + '; margin-bottom: 2px;">[' + log.time + '] ' + log.message + '</div>';
                });
                $logConsole.html(html);
            }
        },

        // Stub methods for backwards compatibility
        startLogPolling: function () {
            // No-op - now handled by combined polling
        },

        stopLogPolling: function () {
            // No-op - now handled by stopPolling
        },

        fetchLogs: function () {
            // No-op - now handled by combined polling
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
        _profileInterval: 5000,
        _profileIntervalBase: 5000,

        startProfilePolling: function (profileId) {
            var self = this;

            // Reset error counter and interval
            this.profilePollErrorCount = 0;
            this._profileInterval = this._profileIntervalBase;

            if (this.profilePollInterval) {
                clearInterval(this.profilePollInterval);
            }

            this._restartProfilePoll(profileId);
        },

        _restartProfilePoll: function (profileId) {
            var self = this;
            if (this.profilePollInterval) clearInterval(this.profilePollInterval);
            this._currentProfilePollId = profileId;
            this.profilePollInterval = setInterval(function () {
                self._pollProfile(profileId);
            }, this._profileInterval);
        },

        _pollProfile: function (profileId) {
            var self = this;

            // In-flight guard
            if (self._profilePending) return;
            self._profilePending = true;

            $.ajax({
                url: ewheelImporter.ajaxUrl,
                type: 'POST',
                timeout: 15000,
                data: {
                    action: 'ewheel_get_sync_status',
                    nonce: ewheelImporter.nonce,
                    profile_id: profileId
                },
                success: function (response) {
                    self._profilePending = false;

                    // Restore normal interval on success after backoff
                    if (self.profilePollErrorCount > 0) {
                        self._profileInterval = self._profileIntervalBase;
                        self._restartProfilePoll(profileId);
                    }
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
                    self._profilePending = false;
                    self.profilePollErrorCount++;

                    var httpStatus = xhr.status || 0;
                    console.warn('[Ewheel] Profile poll error (' + self.profilePollErrorCount + '/' + self.maxConsecutiveErrors + '): ' + httpStatus + ' ' + error);

                    // On 503/5xx — backoff instead of stopping
                    if (httpStatus >= 500 || httpStatus === 0) {
                        self._profileInterval = Math.min(self._profileInterval * 2, self._maxInterval);
                        self._restartProfilePoll(profileId);
                        console.info('[Ewheel] Profile poll backing off to ' + (self._profileInterval / 1000) + 's');
                    }

                    if (self.profilePollErrorCount >= self.maxConsecutiveErrors) {
                        clearInterval(self.profilePollInterval);
                        self.profilePollInterval = null;
                        self.updateProfileSyncUI('idle', {});
                        console.error('[Ewheel] Stopped profile polling after ' + self.maxConsecutiveErrors + ' consecutive errors');
                    }
                }
            });
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

        loadCachedProductCount: function () {
            var $count = $('#ewheel-product-count');
            $.ajax({
                url: ewheelImporter.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ewheel_get_product_count',
                    nonce: ewheelImporter.nonce
                },
                success: function (response) {
                    if (response.success) {
                        $count.text(response.data.formatted);
                    }
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
                    nonce: ewheelImporter.nonce,
                    force: 1
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
