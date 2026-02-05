/**
 * Ewheel Importer Admin JavaScript
 */

(function ($) {
    'use strict';

    var EwheelImporter = {
        pollInterval: null,
        currentProfileId: null,

        init: function () {
            this.bindEvents();
            this.checkInitialStatus();
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
                        var status = response.data.status;
                        if (status === 'running' || status === 'pausing') {
                            self.updateSyncUI('running', response.data);
                            self.startPolling();
                        } else if (status === 'paused') {
                            self.updateSyncUI('paused', response.data);
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

        cancelSync: function (e) {
            e.preventDefault();
            var self = this;

            this.updateSyncUI('stopping', null);

            $.ajax({
                url: ewheelImporter.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ewheel_stop_sync',
                    nonce: ewheelImporter.nonce
                },
                success: function (response) {
                    if (!response.success) {
                        $('#ewheel-sync-status').text('Error cancelling: ' + response.data.message);
                    }
                    // Polling will update UI when batch finishes
                }
            });
        },

        startPolling: function () {
            var self = this;

            // Clear any existing interval
            if (this.pollInterval) {
                clearInterval(this.pollInterval);
            }

            this.pollInterval = setInterval(function () {
                $.ajax({
                    url: ewheelImporter.ajaxUrl,
                    type: 'POST',
                    data: {
                        action: 'ewheel_get_sync_status',
                        nonce: ewheelImporter.nonce
                    },
                    success: function (response) {
                        if (response.success) {
                            var data = response.data;
                            var status = data.status;

                            self.updateSyncUI(status, data);

                            // Stop polling on terminal states
                            if (status === 'completed' || status === 'failed' || status === 'stopped' || status === 'paused') {
                                clearInterval(self.pollInterval);
                                self.pollInterval = null;

                                // Reload page on completion after a delay
                                if (status === 'completed') {
                                    setTimeout(function () {
                                        location.reload();
                                    }, 2000);
                                }
                            }
                        }
                    }
                });
                self.fetchLogs();
            }, 3000);
        },

        fetchLogs: function () {
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
                    if (response.success && response.data) {
                        var logs = response.data;
                        var html = '';
                        logs.forEach(function (log) {
                            var color = log.type === 'error' ? 'red' : (log.type === 'success' ? 'green' : 'black');
                            html += '<div style="color:' + color + '; margin-bottom: 2px;">[' + log.time + '] ' + log.message + '</div>';
                        });
                        $logConsole.html(html);
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

        cancelProfileSync: function (e) {
            e.preventDefault();
            var profileId = this.currentProfileId;

            this.updateProfileSyncUI('stopping', null);

            $.ajax({
                url: ewheelImporter.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ewheel_stop_sync',
                    nonce: ewheelImporter.nonce,
                    profile_id: profileId
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

        startProfilePolling: function (profileId) {
            var self = this;

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
                        if (response.success) {
                            var data = response.data;
                            self.updateProfileSyncUI(data.status, data);

                            if (data.status === 'completed' || data.status === 'failed' || data.status === 'stopped' || data.status === 'paused') {
                                clearInterval(self.profilePollInterval);
                                self.profilePollInterval = null;
                            }
                        }
                    }
                });
            }, 3000);
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
        }
    };

    $(document).ready(function () {
        EwheelImporter.init();

        // Store reference to currentProfileId when profile is selected
        $(document).on('click', '.ewheel-profile-item', function () {
            EwheelImporter.currentProfileId = $(this).data('id');
        });
    });
})(jQuery);
