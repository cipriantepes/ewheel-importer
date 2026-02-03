/**
 * Ewheel Importer Admin JavaScript
 */

(function ($) {
    'use strict';

    var EwheelImporter = {
        pollInterval: null,

        init: function () {
            this.bindEvents();
        },

        bindEvents: function () {
            $('#ewheel-run-sync').on('click', this.runSync.bind(this));
            $('#ewheel-stop-sync').on('click', this.stopSync.bind(this));
            $('#ewheel-test-connection').on('click', this.testConnection.bind(this));
        },

        runSync: function (e) {
            e.preventDefault();

            var $runButton = $('#ewheel-run-sync');
            var $stopButton = $('#ewheel-stop-sync');
            var $status = $('#ewheel-sync-status');
            var self = this;

            $runButton.prop('disabled', true);
            $stopButton.show();

            $status
                .removeClass('success error')
                .addClass('syncing')
                .text(ewheelImporter.strings.syncing);

            $.ajax({
                url: ewheelImporter.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ewheel_run_sync',
                    nonce: ewheelImporter.nonce
                },
                success: function (response) {
                    if (response.success) {
                        $status.text('Sync started... Waiting for updates.');
                        self.startPolling($status, $runButton, $stopButton);
                    } else {
                        $runButton.prop('disabled', false);
                        $stopButton.hide();
                        $status
                            .removeClass('syncing success')
                            .addClass('error')
                            .text(ewheelImporter.strings.error + ' ' + response.data.message);
                    }
                },
                error: function (xhr, status, error) {
                    $runButton.prop('disabled', false);
                    $stopButton.hide();
                    $status
                        .removeClass('syncing success')
                        .addClass('error')
                        .text(ewheelImporter.strings.error + ' ' + error);
                }
            });
        },

        stopSync: function (e) {
            e.preventDefault();
            var $stopButton = $('#ewheel-stop-sync');
            var $status = $('#ewheel-sync-status');

            $stopButton.prop('disabled', true).text('Stopping...');
            $status.text('Requesting stop...');

            $.ajax({
                url: ewheelImporter.ajaxUrl,
                type: 'POST',
                data: {
                    action: 'ewheel_stop_sync',
                    nonce: ewheelImporter.nonce
                },
                success: function (response) {
                    if (response.success) {
                        $status.text(response.data.message);
                    } else {
                        $status.text('Error stopping: ' + response.data.message);
                    }
                }
            });
        },

        startPolling: function ($status, $runButton, $stopButton) {
            var self = this;
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

                            if (data.status === 'completed') {
                                clearInterval(self.pollInterval);
                                $runButton.prop('disabled', false);
                                $stopButton.hide();
                                $status
                                    .removeClass('syncing error')
                                    .addClass('success')
                                    .text(ewheelImporter.strings.success + ' Processed: ' + data.processed);

                                setTimeout(function () {
                                    location.reload();
                                }, 2000);

                            } else if (data.status === 'failed') {
                                clearInterval(self.pollInterval);
                                $runButton.prop('disabled', false);
                                $stopButton.hide();
                                $status
                                    .removeClass('syncing success')
                                    .addClass('error')
                                    .text('Sync Failed.');

                            } else if (data.status === 'stopping') {
                                $status.text('Finishing current batch before stopping...');

                            } else {
                                // Still running
                                var msg = 'Processing... Page: ' + data.page + ' | Products: ' + data.processed;
                                $status.text(msg);
                            }
                        }
                    }
                });
            }, 3000); // Poll every 3 seconds
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
    });

})(jQuery);
